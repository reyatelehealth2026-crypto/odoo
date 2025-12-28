<?php
/**
 * PharmacyAI Adapter v4.0
 * AI เภสัชกรออนไลน์ - แนะนำยาแบบละเอียด (สรรพคุณ, ตัวยา, ข้อบ่งใช้)
 * 
 * ใช้ Gemini API + RAG + Function Calling
 */

namespace Modules\AIChat\Adapters;

require_once __DIR__ . '/../Autoloader.php';
loadAIChatModule();

use Modules\AIChat\Services\RedFlagDetector;
use Modules\AIChat\Services\PharmacyRAG;
use Modules\AIChat\Templates\ProductFlexTemplates;
use Modules\Core\Database;

class PharmacyAIAdapter
{
    private $db;
    private ?int $lineAccountId;
    private ?int $userId = null;
    private ?string $apiKey = null;
    private string $model = 'gemini-2.0-flash';
    private RedFlagDetector $redFlagDetector;
    private PharmacyRAG $rag;
    private ProductFlexTemplates $flexTemplates;
    private array $lastFoundProducts = [];
    
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    public function __construct($db, ?int $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->redFlagDetector = new RedFlagDetector();
        $this->rag = new PharmacyRAG($db, $lineAccountId);
        $this->flexTemplates = new ProductFlexTemplates();
        $this->loadApiKey();
    }
    
    private function loadApiKey(): void
    {
        if ($this->lineAccountId) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT gemini_api_key FROM ai_chat_settings WHERE line_account_id = ? AND is_enabled = 1"
                );
                $stmt->execute([$this->lineAccountId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row && !empty($row['gemini_api_key'])) {
                    $this->apiKey = $row['gemini_api_key'];
                    return;
                }
            } catch (\Exception $e) {
                error_log("loadApiKey error: " . $e->getMessage());
            }
        }
        
        try {
            $stmt = $this->db->prepare(
                "SELECT gemini_api_key FROM ai_chat_settings WHERE is_enabled = 1 AND gemini_api_key IS NOT NULL LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['gemini_api_key'])) {
                $this->apiKey = $row['gemini_api_key'];
                return;
            }
        } catch (\Exception $e) {}
        
        if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
            $this->apiKey = GEMINI_API_KEY;
        }
    }
    
    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function isEnabled(): bool
    {
        return !empty($this->apiKey);
    }
    
    public function getModel(): string
    {
        return $this->model;
    }
    
    /**
     * ประมวลผลข้อความ - Entry Point หลัก
     */
    public function processMessage(string $message): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'AI is not enabled'];
        }
        
        // ===== ใช้ Gemini AI สำหรับทุกข้อความ (มี conversation history) =====
        $redFlags = $this->redFlagDetector->detect($message);
        
        if ($this->redFlagDetector->isCritical($redFlags)) {
            return $this->handleCriticalRedFlag($redFlags);
        }
        
        return $this->processWithGemini($message, $redFlags);
    }
    
    /**
     * ตรวจสอบว่ามี active triage session หรือไม่
     */
    private function hasActiveTriageSession(): bool
    {
        if (!$this->userId) return false;
        
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM triage_sessions WHERE user_id = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$this->userId]);
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * ประมวลผลด้วย TriageEngine (ซักประวัติเป็นขั้นตอน)
     */
    private function processWithTriage(string $message): array
    {
        try {
            require_once __DIR__ . '/../Services/TriageEngine.php';
            
            $triage = new \Modules\AIChat\Services\TriageEngine($this->lineAccountId, $this->userId);
            $result = $triage->process($message);
            
            $responseText = $result['text'] ?? $result['message'] ?? 'ขออภัยค่ะ เกิดข้อผิดพลาด';
            
            // สร้าง LINE Message
            $lineMessage = [
                'type' => 'text',
                'text' => $responseText,
                'sender' => [
                    'name' => '💊 เภสัชกร AI',
                    'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/3774/3774299.png'
                ]
            ];
            
            // เพิ่ม Quick Reply ถ้ามี
            if (!empty($result['quickReplies'])) {
                $lineMessage['quickReply'] = ['items' => $result['quickReplies']];
            }
            
            return [
                'success' => true,
                'response' => $responseText,
                'message' => $lineMessage,
                'state' => $result['state'] ?? 'triage',
                'mode' => 'triage'
            ];
            
        } catch (\Exception $e) {
            error_log("processWithTriage error: " . $e->getMessage());
            return [
                'success' => false,
                'response' => 'ขออภัยค่ะ ระบบซักประวัติขัดข้อง กรุณาลองใหม่',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * ประมวลผลด้วย Gemini API + RAG + Function Calling
     */
    private function processWithGemini(string $message, array $redFlags): array
    {
        try {
            $customerInfo = $this->getCustomerInfo();
            $history = $this->getConversationHistory();
            
            $ragResults = $this->rag->search($message, 15);
            $ragContext = $this->rag->formatForAI($ragResults);
            $this->lastFoundProducts = $ragResults['products'] ?? [];
            
            $systemPrompt = $this->buildPharmacySystemPrompt($customerInfo, $ragContext, $redFlags);
            $result = $this->callGeminiAPI($systemPrompt, $history, $message);
            
            if (!$result['success']) {
                return [
                    'success' => false,
                    'response' => 'ขออภัยค่ะ ระบบขัดข้อง กรุณาลองใหม่อีกครั้ง',
                    'error' => $result['error']
                ];
            }
            
            $responseText = $result['text'];
            if (!empty($redFlags)) {
                $warningText = $this->redFlagDetector->buildWarningMessage($redFlags);
                $responseText = $warningText . "\n\n" . $responseText;
            }
            
            $this->saveConversation($message, $responseText);
            
            return [
                'success' => true,
                'response' => $responseText,
                'message' => $this->buildLINEMessage($responseText),
                'products' => $this->lastFoundProducts,
                'state' => 'chat'
            ];
            
        } catch (\Exception $e) {
            error_log("PharmacyAI error: " . $e->getMessage());
            return [
                'success' => false,
                'response' => 'ขออภัยค่ะ เกิดข้อผิดพลาด กรุณาลองใหม่',
                'error' => $e->getMessage()
            ];
        }
    }

    
    /**
     * สร้าง System Prompt - เน้นแนะนำยาแบบละเอียด
     */
    private function buildPharmacySystemPrompt(?array $customerInfo, ?string $ragContext, array $redFlags): string
    {
        $totalProducts = $this->getTotalProductCount();
        
        $prompt = <<<PROMPT
คุณคือ "เภสัชกร AI" ผู้ช่วยเภสัชกรออนไลน์ของร้านขายยา CNY Pharmacy

## กฎสำคัญที่สุด - การสนทนาต่อเนื่อง:
**คุณต้องอ่านประวัติการสนทนาก่อนหน้าอย่างละเอียด!**
- ถ้าคุณเพิ่งถามคำถาม และลูกค้าตอบมา → ให้ตอบรับคำตอบนั้นและถามคำถามถัดไป
- ห้ามทักทายใหม่ ("สวัสดีค่ะ") ถ้าเป็นการสนทนาต่อเนื่อง
- ถ้าลูกค้าตอบเป็นตัวเลข (เช่น "9", "7") → นั่นคือคำตอบของคำถามก่อนหน้า
- ถ้าลูกค้าตอบเป็นระยะเวลา (เช่น "7 วัน", "2 สัปดาห์") → นั่นคือคำตอบเรื่องระยะเวลา

## ตัวอย่างการสนทนาที่ถูกต้อง:
AI: "อาการรุนแรงแค่ไหนคะ (1-10)?"
User: "9"
AI: "รับทราบค่ะ ความรุนแรงระดับ 9 ถือว่าค่อนข้างมากนะคะ 😟 มีอาการอื่นร่วมด้วยไหมคะ?"

## ตัวอย่างที่ผิด (ห้ามทำ):
AI: "อาการรุนแรงแค่ไหนคะ (1-10)?"
User: "9"
AI: "สวัสดีค่ะ มีอาการอะไรให้ช่วยคะ?" ❌ ผิด! ห้ามทักทายใหม่!

## ขั้นตอนการซักประวัติ (Triage):
1. อาการหลัก (symptoms) - ถามว่ามีอาการอะไร
2. ระยะเวลา (duration) - เป็นมานานแค่ไหน
3. ความรุนแรง (severity) - รุนแรงแค่ไหน 1-10
4. อาการร่วม (associated_symptoms) - มีอาการอื่นร่วมด้วยไหม
5. ยาที่แพ้ (allergies) - แพ้ยาอะไรไหม (ถ้าไม่แพ้ตอบ "ไม่แพ้")
6. โรคประจำตัว (medical_conditions) - มีโรคประจำตัวไหม

**เมื่อได้ข้อมูลครบ 4 ข้อแรก ให้เรียก saveTriageAssessment() แล้วแนะนำยา!**

## Functions ที่ใช้ได้:
1. searchProducts(query) - ค้นหายาในร้าน
2. saveTriageAssessment(symptoms, duration, severity, severity_level, associated_symptoms, ai_assessment, recommended_action) - บันทึกผลซักประวัติ

## การประเมิน severity_level:
- "low": อาการเล็กน้อย (1-3)
- "medium": ปานกลาง (4-6)
- "high": รุนแรง (7-8)
- "critical": ฉุกเฉิน (9-10 หรือมี red flags)

## กฎอื่นๆ:
- ตอบเป็นภาษาไทย สุภาพ
- ใช้ emoji บ้าง 😊💊
- ถามทีละข้อ ไม่ถามหลายข้อพร้อมกัน

PROMPT;

        if ($customerInfo) {
            $prompt .= "\n## ข้อมูลลูกค้า:\n";
            if (!empty($customerInfo['display_name'])) {
                $prompt .= "- ชื่อ: {$customerInfo['display_name']}\n";
            }
            if (!empty($customerInfo['drug_allergies'])) {
                $prompt .= "- ⚠️ แพ้ยา: {$customerInfo['drug_allergies']} (ห้ามแนะนำยานี้!)\n";
            }
            if (!empty($customerInfo['medical_conditions'])) {
                $prompt .= "- โรคประจำตัว: {$customerInfo['medical_conditions']}\n";
            }
        }
        
        if (!empty($redFlags)) {
            $prompt .= "\n## ⚠️ พบอาการที่ต้องระวัง:\n";
            foreach ($redFlags as $flag) {
                $prompt .= "- {$flag['message']}\n";
            }
        }
        
        if ($ragContext) {
            $prompt .= "\n" . $ragContext;
        }
        
        return $prompt;
    }
    
    /**
     * เรียก Gemini API พร้อม Function Calling
     */
    private function callGeminiAPI(string $systemPrompt, array $history, string $userMessage): array
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $contents = [];
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        $contents[] = ['role' => 'model', 'parts' => [['text' => 'เข้าใจแล้วค่ะ พร้อมให้บริการเป็นเภสัชกร AI ค่ะ 😊']]];
        
        foreach ($history as $msg) {
            $role = $msg['role'] === 'user' ? 'user' : 'model';
            $contents[] = ['role' => $role, 'parts' => [['text' => $msg['content']]]];
        }
        
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];
        
        $tools = [[
            'functionDeclarations' => [
                [
                    'name' => 'searchProducts',
                    'description' => 'ค้นหาสินค้ายาในฐานข้อมูลร้าน',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => 'คำค้นหา เช่น ชื่อยา อาการ หรือตัวยา'],
                            'category' => ['type' => 'string', 'description' => 'หมวดหมู่ (optional)'],
                            'limit' => ['type' => 'integer', 'description' => 'จำนวนผลลัพธ์ (default: 5)']
                        ],
                        'required' => ['query']
                    ]
                ],
                [
                    'name' => 'getProductDetails',
                    'description' => 'ดึงรายละเอียดสินค้าด้วย SKU',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string', 'description' => 'รหัส SKU']
                        ],
                        'required' => ['sku']
                    ]
                ],
                [
                    'name' => 'getProductsByCategory',
                    'description' => 'ดึงสินค้าตามหมวดหมู่',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'category' => ['type' => 'string', 'description' => 'หมวดหมู่'],
                            'limit' => ['type' => 'integer', 'description' => 'จำนวน (default: 5)']
                        ],
                        'required' => ['category']
                    ]
                ],
                [
                    'name' => 'saveTriageAssessment',
                    'description' => 'บันทึกผลการซักประวัติอาการ เรียกเมื่อได้ข้อมูลอาการครบถ้วน (อย่างน้อย 4 ข้อ)',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'symptoms' => ['type' => 'string', 'description' => 'อาการหลักที่ลูกค้าบอก'],
                            'duration' => ['type' => 'string', 'description' => 'ระยะเวลาที่เป็น เช่น 2 วัน, 1 สัปดาห์'],
                            'severity' => ['type' => 'integer', 'description' => 'ความรุนแรง 1-10'],
                            'severity_level' => ['type' => 'string', 'description' => 'ระดับความรุนแรง: low, medium, high, critical'],
                            'associated_symptoms' => ['type' => 'string', 'description' => 'อาการร่วมอื่นๆ'],
                            'allergies' => ['type' => 'string', 'description' => 'ยาที่แพ้'],
                            'medical_conditions' => ['type' => 'string', 'description' => 'โรคประจำตัว'],
                            'current_medications' => ['type' => 'string', 'description' => 'ยาที่ทานอยู่'],
                            'ai_assessment' => ['type' => 'string', 'description' => 'การวินิจฉัยเบื้องต้นของ AI'],
                            'recommended_action' => ['type' => 'string', 'description' => 'คำแนะนำ: self_care, consult_pharmacist, see_doctor, emergency']
                        ],
                        'required' => ['symptoms', 'severity_level', 'ai_assessment', 'recommended_action']
                    ]
                ]
            ]
        ]];
        
        $data = [
            'contents' => $contents,
            'tools' => $tools,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 800,
                'topP' => 0.9
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH']
            ]
        ];
        
        $maxTurns = 3;
        for ($turn = 0; $turn < $maxTurns; $turn++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) return ['success' => false, 'error' => $error];
            
            $result = json_decode($response, true);
            if ($httpCode !== 200) {
                return ['success' => false, 'error' => $result['error']['message'] ?? 'HTTP ' . $httpCode];
            }
            
            $parts = $result['candidates'][0]['content']['parts'] ?? [];
            $functionCall = null;
            $textResponse = null;
            
            foreach ($parts as $part) {
                if (isset($part['functionCall'])) $functionCall = $part['functionCall'];
                elseif (isset($part['text'])) $textResponse = $part['text'];
            }
            
            if ($functionCall) {
                $functionResult = $this->handleFunctionCall($functionCall);
                $data['contents'][] = ['role' => 'model', 'parts' => [['functionCall' => $functionCall]]];
                $data['contents'][] = ['role' => 'user', 'parts' => [['functionResponse' => ['name' => $functionCall['name'], 'response' => $functionResult]]]];
                continue;
            }
            
            if ($textResponse) {
                $textResponse = trim($textResponse);
                $textResponse = preg_replace('/\*\*([^*]+)\*\*/', '$1', $textResponse);
                return ['success' => true, 'text' => $textResponse];
            }
        }
        
        return ['success' => false, 'error' => 'No response'];
    }

    
    /**
     * ประมวลผล Function Call จาก AI
     */
    private function handleFunctionCall(array $functionCall): array
    {
        $name = $functionCall['name'] ?? '';
        $args = $functionCall['args'] ?? [];
        
        switch ($name) {
            case 'searchProducts':
                $query = $args['query'] ?? '';
                $category = $args['category'] ?? null;
                $limit = intval($args['limit'] ?? 5);
                $products = $this->rag->searchProductsForAI($query, $category, $limit);
                if (!empty($products)) {
                    $this->lastFoundProducts = array_merge($this->lastFoundProducts, $products);
                }
                return ['success' => true, 'count' => count($products), 'products' => $products];
                
            case 'getProductDetails':
                $sku = $args['sku'] ?? '';
                $product = $this->rag->getProductDetailsBySku($sku);
                if ($product) {
                    $this->lastFoundProducts[] = $product;
                    return ['success' => true, 'product' => $product];
                }
                return ['success' => false, 'message' => "ไม่พบสินค้า SKU: {$sku}"];
                
            case 'getProductsByCategory':
                $category = $args['category'] ?? '';
                $limit = intval($args['limit'] ?? 5);
                $products = $this->rag->getProductsByCategory($category, $limit);
                if (!empty($products)) {
                    $this->lastFoundProducts = array_merge($this->lastFoundProducts, $products);
                }
                return ['success' => true, 'category' => $category, 'count' => count($products), 'products' => $products];
            
            case 'saveTriageAssessment':
                return $this->saveTriageAssessment($args);
                
            default:
                return ['success' => false, 'error' => "Unknown function: {$name}"];
        }
    }
    
    /**
     * บันทึกผลการซักประวัติและแจ้งเตือนถ้าจำเป็น
     */
    private function saveTriageAssessment(array $data): array
    {
        if (!$this->userId) {
            return ['success' => false, 'error' => 'No user ID'];
        }
        
        try {
            // Create table if not exists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ai_triage_assessments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    line_account_id INT NULL,
                    symptoms TEXT,
                    duration VARCHAR(100),
                    severity INT,
                    severity_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
                    associated_symptoms TEXT,
                    allergies TEXT,
                    medical_conditions TEXT,
                    current_medications TEXT,
                    ai_assessment TEXT,
                    recommended_action ENUM('self_care', 'consult_pharmacist', 'see_doctor', 'emergency') DEFAULT 'self_care',
                    pharmacist_notified TINYINT(1) DEFAULT 0,
                    pharmacist_response TEXT,
                    status ENUM('pending', 'reviewed', 'completed') DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_severity (severity_level),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Insert assessment
            $stmt = $this->db->prepare("
                INSERT INTO ai_triage_assessments 
                (user_id, line_account_id, symptoms, duration, severity, severity_level, 
                 associated_symptoms, allergies, medical_conditions, current_medications,
                 ai_assessment, recommended_action)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $this->userId,
                $this->lineAccountId,
                $data['symptoms'] ?? '',
                $data['duration'] ?? '',
                intval($data['severity'] ?? 5),
                $data['severity_level'] ?? 'low',
                $data['associated_symptoms'] ?? '',
                $data['allergies'] ?? '',
                $data['medical_conditions'] ?? '',
                $data['current_medications'] ?? '',
                $data['ai_assessment'] ?? '',
                $data['recommended_action'] ?? 'self_care'
            ]);
            
            $assessmentId = $this->db->lastInsertId();
            
            // Notify pharmacist if severity is high or critical
            $severityLevel = $data['severity_level'] ?? 'low';
            $notified = false;
            
            if (in_array($severityLevel, ['high', 'critical'])) {
                $notified = $this->notifyPharmacist($assessmentId, $data);
            }
            
            error_log("saveTriageAssessment: Saved assessment #{$assessmentId}, severity={$severityLevel}, notified={$notified}");
            
            return [
                'success' => true,
                'assessment_id' => $assessmentId,
                'severity_level' => $severityLevel,
                'pharmacist_notified' => $notified,
                'message' => $notified ? 'บันทึกแล้ว และแจ้งเภสัชกรเรียบร้อย' : 'บันทึกการประเมินเรียบร้อย'
            ];
            
        } catch (\Exception $e) {
            error_log("saveTriageAssessment error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * แจ้งเตือนเภสัชกร
     */
    private function notifyPharmacist(int $assessmentId, array $data): bool
    {
        try {
            // Get user info
            $stmt = $this->db->prepare("SELECT display_name, line_user_id FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Create notification
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS pharmacist_notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    line_account_id INT NULL,
                    type VARCHAR(50) DEFAULT 'triage_alert',
                    title VARCHAR(255),
                    message TEXT,
                    reference_id INT,
                    reference_type VARCHAR(50),
                    user_id INT,
                    is_read TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_line_account (line_account_id),
                    INDEX idx_is_read (is_read)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $severityLevel = $data['severity_level'] ?? 'high';
            $severityText = $severityLevel === 'critical' ? '🚨 ฉุกเฉิน' : '⚠️ รุนแรง';
            
            $stmt = $this->db->prepare("
                INSERT INTO pharmacist_notifications 
                (line_account_id, type, title, message, reference_id, reference_type, user_id)
                VALUES (?, 'triage_alert', ?, ?, ?, 'triage_assessment', ?)
            ");
            
            $title = "{$severityText} - ลูกค้าต้องการความช่วยเหลือ";
            $message = "ลูกค้า: " . ($user['display_name'] ?? 'ไม่ระบุชื่อ') . "\n";
            $message .= "อาการ: " . ($data['symptoms'] ?? '-') . "\n";
            $message .= "ระยะเวลา: " . ($data['duration'] ?? '-') . "\n";
            $message .= "การประเมิน: " . ($data['ai_assessment'] ?? '-');
            
            $stmt->execute([
                $this->lineAccountId,
                $title,
                $message,
                $assessmentId,
                $this->userId
            ]);
            
            // Update assessment as notified
            $stmt = $this->db->prepare("UPDATE ai_triage_assessments SET pharmacist_notified = 1 WHERE id = ?");
            $stmt->execute([$assessmentId]);
            
            error_log("notifyPharmacist: Created notification for assessment #{$assessmentId}");
            
            return true;
            
        } catch (\Exception $e) {
            error_log("notifyPharmacist error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle Critical Red Flag
     */
    private function handleCriticalRedFlag(array $redFlags): array
    {
        $warningText = $this->redFlagDetector->buildWarningMessage($redFlags);
        $contacts = $this->redFlagDetector->getEmergencyContacts();
        
        $text = $warningText;
        $text .= "\n\n📞 เบอร์ฉุกเฉิน:\n";
        foreach (array_slice($contacts, 0, 2) as $contact) {
            $text .= "• {$contact['name']}: {$contact['number']}\n";
        }
        
        return [
            'success' => true,
            'response' => $text,
            'message' => $this->buildLINEMessage($text),
            'is_critical' => true,
            'red_flags' => $redFlags,
            'state' => 'emergency'
        ];
    }
    
    /**
     * Build LINE Message
     */
    private function buildLINEMessage(string $text): array
    {
        $message = [
            'type' => 'text',
            'text' => $text,
            'sender' => [
                'name' => '💊 เภสัชกร AI',
                'iconUrl' => 'https://cdn-icons-png.flaticon.com/512/3774/3774299.png'
            ]
        ];
        
        $quickReplyItems = [
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '💊 แนะนำยา', 'text' => 'แนะนำยา']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🩺 ปรึกษาอาการ', 'text' => 'ปรึกษาอาการ']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '👨‍⚕️ ปรึกษาเภสัชกร', 'text' => 'ปรึกษาเภสัชกร']],
            ['type' => 'action', 'action' => ['type' => 'message', 'label' => '🛒 ดูร้านค้า', 'text' => 'ร้านค้า']]
        ];
        
        $message['quickReply'] = ['items' => $quickReplyItems];
        
        return $message;
    }
    
    /**
     * ดึงข้อมูลลูกค้า
     */
    private function getCustomerInfo(): ?array
    {
        if (!$this->userId) return null;
        
        try {
            $stmt = $this->db->prepare(
                "SELECT u.display_name, m.drug_allergies, m.medical_conditions, m.current_medications
                 FROM users u
                 LEFT JOIN user_medical_history m ON u.id = m.user_id
                 WHERE u.id = ?"
            );
            $stmt->execute([$this->userId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * ดึงประวัติการสนทนา
     */
    private function getConversationHistory(): array
    {
        if (!$this->userId) return [];
        
        try {
            $stmt = $this->db->prepare(
                "SELECT role, content FROM ai_conversation_history 
                 WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
            );
            $stmt->execute([$this->userId]);
            return array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * บันทึกประวัติการสนทนา
     */
    private function saveConversation(string $userMessage, string $aiResponse): void
    {
        if (!$this->userId) return;
        
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO ai_conversation_history (user_id, line_account_id, role, content) VALUES (?, ?, 'user', ?)"
            );
            $stmt->execute([$this->userId, $this->lineAccountId, $userMessage]);
            
            $stmt = $this->db->prepare(
                "INSERT INTO ai_conversation_history (user_id, line_account_id, role, content) VALUES (?, ?, 'assistant', ?)"
            );
            $stmt->execute([$this->userId, $this->lineAccountId, $aiResponse]);
        } catch (\Exception $e) {
            error_log("saveConversation error: " . $e->getMessage());
        }
    }
    
    /**
     * นับจำนวนสินค้าทั้งหมด
     */
    public function getTotalProductCount(): int
    {
        try {
            // ใช้ business_items table
            $stmt = $this->db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 1");
            return intval($stmt->fetchColumn() ?: 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get last found products
     */
    public function getLastFoundProducts(): array
    {
        return $this->lastFoundProducts;
    }
    
    /**
     * Search products (public method)
     */
    public function searchProducts(string $query, int $limit = 10): array
    {
        return $this->rag->findByName($query, $limit);
    }
    
    /**
     * Find product by SKU (public method)
     */
    public function findProductBySku(string $sku): ?array
    {
        return $this->rag->findBySku($sku);
    }
}
