<?php
/**
 * GeminiChat - AI Chat Response using Google Gemini
 * Version 3.0 - Professional AI Pharmacist with Info Extraction
 * อัปเกรด: ระบบวิเคราะห์บริบท (Context Extraction) เพื่อการซักประวัติที่เป็นมืออาชีพและไม่ถามซ้ำ
 */

class GeminiChat
{
    private $db;
    private $apiKey;
    private $model;
    private $settings;
    private $lineAccountId;
    
    const DEFAULT_MODEL = 'gemini-2.0-flash';
    const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->loadSettings();
    }
    
    /**
     * โหลดการตั้งค่าจากฐานข้อมูล
     */
    private function loadSettings()
    {
        $this->settings = [
            'is_enabled' => false,
            'model' => self::DEFAULT_MODEL,
            'system_prompt' => '',
            'temperature' => 0.4, // ต่ำเพื่อให้คำตอบแม่นยำและเป็นวิชาการ
            'max_tokens' => 500,
            'response_style' => 'professional',
            'fallback_message' => 'ขออภัยค่ะ ไม่เข้าใจคำถาม กรุณาติดต่อเจ้าหน้าที่',
            'business_info' => '',
            'product_knowledge' => ''
        ];
        
        try {
            if ($this->lineAccountId) {
                $stmt = $this->db->prepare("SELECT * FROM ai_chat_settings WHERE line_account_id = ?");
                $stmt->execute([$this->lineAccountId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    $this->settings = array_merge($this->settings, $result);
                    $this->apiKey = $result['gemini_api_key'] ?? '';
                    $this->model = $result['model'] ?? self::DEFAULT_MODEL;
                    return;
                }
            }
            
            // Fallback: ตาราง ai_settings เดิม
            $stmt = $this->db->prepare("SELECT setting_value FROM ai_settings WHERE setting_key = 'gemini_api_key' AND line_account_id = ?");
            $stmt->execute([$this->lineAccountId]);
            $this->apiKey = $stmt->fetchColumn() ?: '';
            
            $stmt = $this->db->prepare("SELECT setting_value FROM ai_settings WHERE setting_key = 'ai_enabled' AND line_account_id = ?");
            $stmt->execute([$this->lineAccountId]);
            $enabled = $stmt->fetchColumn();
            $this->settings['is_enabled'] = ($enabled === '1');
            
            $stmt = $this->db->prepare("SELECT setting_value FROM ai_settings WHERE setting_key = 'system_prompt' AND line_account_id = ?");
            $stmt->execute([$this->lineAccountId]);
            $this->settings['system_prompt'] = $stmt->fetchColumn() ?: '';
            
        } catch (Exception $e) {
            error_log("GeminiChat loadSettings error: " . $e->getMessage());
        }
    }
    
    public function isEnabled()
    {
        return $this->settings['is_enabled'] && !empty($this->apiKey);
    }
    
    /**
     * สร้างคำตอบโดยใช้การวิเคราะห์ประวัติการสนทนา
     */
    public function generateResponse($userMessage, $userId = null, $conversationHistory = [])
    {
        if (!$this->isEnabled()) {
            return null;
        }
        
        try {
            $startTime = microtime(true);
            
            // สร้าง Full Prompt พร้อมข้อมูลที่วิเคราะห์ได้
            $prompt = $this->buildPrompt($userMessage, $userId, $conversationHistory);
            
            // ส่งประวัติการคุยเพื่อให้ AI ทราบบริบทต่อเนื่อง
            $fullHistory = $conversationHistory;
            $fullHistory[] = ['role' => 'user', 'content' => $userMessage];
            
            $response = $this->callGeminiAPI($prompt, $fullHistory);
            
            $responseTimeMs = round((microtime(true) - $startTime) * 1000);
            
            if ($response['success']) {
                $this->logResponse($userId, $userMessage, $response['text'], $responseTimeMs);
                return $response['text'];
            } else {
                return $this->settings['fallback_message'];
            }
            
        } catch (Exception $e) {
            error_log("GeminiChat Error: " . $e->getMessage());
            return $this->settings['fallback_message'];
        }
    }
    
    /**
     * สร้าง Prompt พร้อมวิเคราะห์ข้อมูลเชิงลึก
     */
    private function buildPrompt($userMessage, $userId = null, $conversationHistory = [])
    {
        $parts = [];
        
        // 1. รวมข้อความล่าสุดเข้ากับประวัติเพื่อการวิเคราะห์
        $fullHistory = $conversationHistory;
        $fullHistory[] = ['role' => 'user', 'content' => $userMessage];
        
        // 2. วิเคราะห์ข้อมูลที่ลูกค้าเคยบอกแล้ว
        $extractedInfo = $this->extractInfoFromHistory($fullHistory);
        
        // 3. กฎเหล็ก: เภสัชกรวิชาชีพ (Professional Pharmacist Rules)
        $proRules = "
[โปรโตคอล: เภสัชกรวิชาชีพ (Professional Pharmacist)]
- บุคลิก: มีความน่าเชื่อถือ สุภาพ เป็นทางการ (แทนตัวเองว่า 'เภสัชกร' หรือ 'ทางเรา')
- การตอบ: สั้น กระชับ 1-2 ประโยค ห้ามใช้ตัวเลขหรือ Bullet points ระหว่างซักประวัติ
- Flow: แสดงความเห็นอกเห็นใจ (Empathy) -> ถามสิ่งที่ขาดทีละอย่าง -> สรุปและแนะนำยา OTC เมื่อข้อมูลครบ
- **สำคัญ**: ห้ามถามซ้ำข้อมูลที่ปรากฏใน [ข้อมูลที่ทราบแล้ว] ด้านล่าง
- หากลูกค้าตอบ 'ไม่มี/ไม่แพ้' ให้ยอมรับทันที ห้ามถามย้ำ
";
        $parts[] = $proRules;
        
        // 4. แสดงข้อมูลที่ระบบวิเคราะห์ได้ (เพื่อให้ AI ไม่ถามซ้ำ)
        if (!empty($extractedInfo)) {
            $infoSection = "\n[ข้อมูลที่ทราบแล้ว - ห้ามถามซ้ำ!]\n";
            foreach ($extractedInfo as $key => $value) {
                $infoSection .= "- {$key}: {$value}\n";
            }
            $parts[] = $infoSection;
        }
        
        // 5. บทบาทหลัก (System Prompt)
        $systemPrompt = $this->settings['system_prompt'] ?: $this->getDefaultSystemPrompt();
        $parts[] = "บทบาทหลักของคุณ:\n" . $systemPrompt;
        
        // 6. ข้อมูลสินค้าและธุรกิจ
        if (!empty($this->settings['business_info'])) {
            $parts[] = "ข้อมูลร้านยา:\n" . $this->settings['business_info'];
        }
        
        $products = $this->settings['product_knowledge'] ?: $this->getTopProducts();
        if ($products) {
            $parts[] = "ยาสามัญประจำบ้าน (OTC) ที่มีในคลัง:\n" . $products;
        }
        
        // 7. ข้อมูลคนไข้จากฐานข้อมูล
        if ($userId) {
            $medicalInfo = $this->getUserMedicalInfo($userId);
            if ($medicalInfo) {
                $parts[] = "ระเบียนคนไข้:\n- แพ้ยา: {$medicalInfo['drug_allergies']}\n- โรคประจำตัว: {$medicalInfo['medical_conditions']}";
            }
        }
        
        // 8. กำหนดเป้าหมายถัดไป
        $missing = [];
        if (!isset($extractedInfo['อาการ'])) $missing[] = 'อาการหลัก';
        if (!isset($extractedInfo['ระยะเวลา'])) $missing[] = 'เป็นมานานกี่วัน';
        if (!isset($extractedInfo['อาการร่วม'])) $missing[] = 'อาการร่วมอื่นๆ';
        if (!isset($extractedInfo['แพ้ยา'])) $missing[] = 'ประวัติแพ้ยา';
        if (!isset($extractedInfo['โรคประจำตัว'])) $missing[] = 'โรคประจำตัว';
        
        if (empty($missing)) {
            $parts[] = "\n[คำสั่งสุดท้าย]: ข้อมูลครบถ้วนแล้ว ให้สรุปอาการทั้งหมด แนะนำยา OTC และบอกให้รอเภสัชกรยืนยัน";
        } else {
            $next = $missing[0];
            $parts[] = "\n[คำสั่งสุดท้าย]: ข้อมูลที่ยังขาด: " . implode(', ', $missing) . " - ให้เลือกถามเรื่อง '{$next}' เพียงประโยคเดียวสั้นๆ";
        }
        
        return implode("\n\n", $parts);
    }
    
    private function getDefaultSystemPrompt()
    {
        return 'คุณคือเภสัชกรวิชาชีพ ให้คำปรึกษาด้านสุขภาพเบื้องต้นอย่างถูกต้อง สุภาพ และคำนึงถึงความปลอดภัยของผู้ป่วยเป็นอันดับแรก';
    }
    
    /**
     * ระบบสกัดข้อมูลจากประวัติการคุย (Information Extraction)
     */
    private function extractInfoFromHistory($conversationHistory)
    {
        $info = [];
        $fullText = '';
        foreach ($conversationHistory as $msg) { $fullText .= $msg['content'] . ' '; }
        $fullText = mb_strtolower($fullText);
        
        // 1. ค้นหาระยะเวลา (วัน)
        if (preg_match('/(\d+)\s*วัน|(\d+)วัน|อาทิตย์|สัปดาห์/u', $fullText, $m)) {
            $info['ระยะเวลา'] = $m[0];
        }
        
        // 2. ค้นหาอาการที่พบบ่อย
        $patterns = ['ปวดหัว', 'ปวดคอ', 'ปวดหลัง', 'ไข้', 'ไอ', 'เจ็บคอ', 'น้ำมูก', 'ท้องเสีย', 'ชา', 'หายใจไม่สะดวก'];
        $foundSymptoms = [];
        foreach ($patterns as $p) {
            if (mb_strpos($fullText, $p) !== false) $foundSymptoms[] = $p;
        }
        if (!empty($foundSymptoms)) $info['อาการ'] = implode(', ', $foundSymptoms);
        
        // 3. วิเคราะห์คำถามและคำตอบ (QA Pairs)
        $lastQuestion = '';
        foreach ($conversationHistory as $msg) {
            if ($msg['role'] === 'assistant') {
                $lastQuestion = mb_strtolower($msg['content']);
            } else {
                $answer = mb_strtolower(trim($msg['content']));
                $isNegative = preg_match('/^(ไม่มี|ไม่|เปล่า|ไม่ครับ|ไม่ค่ะ|ไม่แพ้)/u', $answer);
                
                if ($isNegative && $lastQuestion) {
                    if (mb_strpos($lastQuestion, 'แพ้ยา') !== false) $info['แพ้ยา'] = 'ไม่มี';
                    if (mb_strpos($lastQuestion, 'โรคประจำตัว') !== false) $info['โรคประจำตัว'] = 'ไม่มี';
                    if (mb_strpos($lastQuestion, 'อาการอื่น') !== false || mb_strpos($lastQuestion, 'ร่วม') !== false) $info['อาการร่วม'] = 'ไม่มี';
                }
            }
        }
        
        // 4. ค้นหาคีย์เวิร์ดปฏิเสธโดยตรง
        if (!isset($info['แพ้ยา']) && mb_strpos($fullText, 'ไม่แพ้ยา') !== false) $info['แพ้ยา'] = 'ไม่มี';
        if (!isset($info['โรคประจำตัว']) && mb_strpos($fullText, 'ไม่มีโรค') !== false) $info['โรคประจำตัว'] = 'ไม่มี';
        
        return $info;
    }
    
    /**
     * เรียกใช้งาน Gemini API (Multi-turn Support)
     */
    private function callGeminiAPI($systemPrompt, $conversationHistory = [])
    {
        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apiKey;
        
        $contents = [];
        // ใส่คำสั่งหลักเป็น User Message แรก
        $contents[] = ['role' => 'user', 'parts' => [['text' => $systemPrompt]]];
        // ให้ AI ยอมรับเงื่อนไข
        $contents[] = ['role' => 'model', 'parts' => [['text' => 'รับทราบข้อกำหนดของเภสัชกรวิชาชีพ จะตอบสั้นๆ และอ้างอิงข้อมูลที่ได้รับมาค่ะ']]];
        
        // ใส่ประวัติการคุยจริง
        foreach ($conversationHistory as $msg) {
            $contents[] = [
                'role' => ($msg['role'] === 'user' ? 'user' : 'model'),
                'parts' => [['text' => $msg['content']]]
            ];
        }
        
        $data = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => floatval($this->settings['temperature']),
                'maxOutputTokens' => intval($this->settings['max_tokens']),
                'topP' => 0.9,
                'topK' => 30
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $result = json_decode($response, true);
        if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $text = trim($result['candidates'][0]['content']['parts'][0]['text']);
            $text = preg_replace('/^\*\*|\*\*$/m', '', $text);
            return ['success' => true, 'text' => $text];
        }
        
        return ['success' => false];
    }

    private function logResponse($userId, $userMessage, $aiResponse, $responseTimeMs)
    {
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS ai_chat_logs (id INT AUTO_INCREMENT PRIMARY KEY, line_account_id INT, user_id INT, user_message TEXT, ai_response TEXT, response_time_ms INT, model_used VARCHAR(50), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
            $stmt = $this->db->prepare("INSERT INTO ai_chat_logs (line_account_id, user_id, user_message, ai_response, response_time_ms, model_used) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$this->lineAccountId, $userId, $userMessage, $aiResponse, $responseTimeMs, $this->model]);
        } catch (Exception $e) {}
    }
    
    public function getConversationHistory($userId, $limit = 10)
    {
        try {
            $stmt = $this->db->prepare("SELECT CASE WHEN direction = 'incoming' THEN 'user' ELSE 'assistant' END as role, content FROM messages WHERE user_id = ? AND message_type = 'text' AND content != '' ORDER BY created_at DESC LIMIT ?");
            $stmt->execute([$userId, $limit]);
            return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { return []; }
    }
    
    private function getTopProducts($limit = 10)
    {
        try {
            $stmt = $this->db->prepare("SELECT name, price FROM business_items WHERE is_active = 1 AND (line_account_id = ? OR line_account_id IS NULL) LIMIT ?");
            $stmt->execute([$this->lineAccountId, $limit]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $text = "";
            foreach ($products as $p) { $text .= "- {$p['name']} ({$p['price']} บ.)\n"; }
            return $text;
        } catch (Exception $e) { return null; }
    }
    
    public function getUserMedicalInfo($userId)
    {
        try {
            $stmt = $this->db->prepare("SELECT medical_conditions, drug_allergies FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return null; }
    }
}