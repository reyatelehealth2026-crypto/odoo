<?php
/**
 * OnboardingAssistant - Main AI Onboarding Assistant Class
 */

namespace Modules\Onboarding;

require_once __DIR__ . '/SetupStatusChecker.php';
require_once __DIR__ . '/SystemKnowledgeBase.php';
require_once __DIR__ . '/OnboardingPromptBuilder.php';
require_once __DIR__ . '/QuickActionExecutor.php';

class OnboardingAssistant {
    
    private $db;
    private $lineAccountId;
    private $adminUserId;
    private $statusChecker;
    private $knowledgeBase;
    private $promptBuilder;
    private $actionExecutor;
    private $geminiApiKey;
    private $sessionId;
    
    public function __construct($db, $lineAccountId, $adminUserId) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->adminUserId = $adminUserId;
        
        $this->statusChecker = new SetupStatusChecker($db, $lineAccountId);
        $this->knowledgeBase = new SystemKnowledgeBase();
        $this->promptBuilder = new OnboardingPromptBuilder();
        $this->actionExecutor = new QuickActionExecutor($db, $lineAccountId);
        
        $this->loadGeminiApiKey();
        $this->loadOrCreateSession();
    }
    
    /**
     * Load Gemini API Key from settings
     */
    private function loadGeminiApiKey(): void {
        try {
            // Try line account specific key first
            $stmt = $this->db->prepare("
                SELECT gemini_api_key FROM ai_settings WHERE line_account_id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!empty($result['gemini_api_key'])) {
                $this->geminiApiKey = $result['gemini_api_key'];
                return;
            }
            
            // Try global config
            if (defined('GEMINI_API_KEY')) {
                $this->geminiApiKey = GEMINI_API_KEY;
            }
        } catch (\Exception $e) {
            // Ignore errors
        }
    }
    
    /**
     * Load or create session
     */
    private function loadOrCreateSession(): void {
        try {
            $stmt = $this->db->prepare("
                SELECT id, conversation_history, current_topic, business_type 
                FROM onboarding_sessions 
                WHERE line_account_id = ? AND admin_user_id = ?
                ORDER BY last_activity DESC
                LIMIT 1
            ");
            $stmt->execute([$this->lineAccountId, $this->adminUserId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($session) {
                $this->sessionId = $session['id'];
            } else {
                $this->createSession();
            }
        } catch (\Exception $e) {
            // Table might not exist yet
            $this->sessionId = null;
        }
    }
    
    /**
     * Create new session
     */
    private function createSession(): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO onboarding_sessions (line_account_id, admin_user_id, conversation_history, setup_progress)
                VALUES (?, ?, '[]', '{}')
            ");
            $stmt->execute([$this->lineAccountId, $this->adminUserId]);
            $this->sessionId = $this->db->lastInsertId();
        } catch (\Exception $e) {
            $this->sessionId = null;
        }
    }
    
    /**
     * Main chat interface
     */
    public function chat(string $message, array $context = []): array {
        // Get setup status
        $setupStatus = $this->statusChecker->checkAll();
        
        // Extract intent and get relevant knowledge
        $intent = $this->promptBuilder->extractIntent($message);
        $relevantKnowledge = $this->promptBuilder->getRelevantKnowledge($message);
        
        // Build prompts
        $systemPrompt = $this->promptBuilder->buildSystemPrompt($setupStatus, $context);
        $userPrompt = $this->promptBuilder->buildUserPrompt($message, $relevantKnowledge);
        
        // Call Gemini AI
        $aiResult = $this->callGeminiAI($systemPrompt, $userPrompt);
        
        // Get suggested actions
        $suggestedActions = $this->actionExecutor->getSuggestedActions($setupStatus);
        
        // Save to conversation history
        $this->saveConversation($message, $aiResult['message']);
        
        return [
            'success' => true,
            'message' => $aiResult['message'],
            'ai_source' => $aiResult['source'], // 'gemini' or 'fallback'
            'intent' => $intent,
            'suggested_actions' => $suggestedActions,
            'setup_status' => $setupStatus,
            'completion_percent' => $this->statusChecker->getCompletionPercentage()
        ];
    }
    
    /**
     * Call Gemini AI
     */
    private function callGeminiAI(string $systemPrompt, string $userPrompt): array {
        if (empty($this->geminiApiKey)) {
            return [
                'message' => $this->getFallbackResponse($userPrompt),
                'source' => 'fallback'
            ];
        }
        
        try {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $this->geminiApiKey;
            
            $data = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $systemPrompt . "\n\n---\n\nUser: " . $userPrompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1024
                ]
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $aiMessage = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
                
                if ($aiMessage) {
                    return [
                        'message' => $aiMessage,
                        'source' => 'gemini'
                    ];
                }
            }
            
            return [
                'message' => $this->getFallbackResponse($userPrompt),
                'source' => 'fallback'
            ];
        } catch (\Exception $e) {
            return [
                'message' => $this->getFallbackResponse($userPrompt),
                'source' => 'fallback'
            ];
        }
    }
    
    /**
     * Get fallback response when AI is not available
     */
    private function getFallbackResponse(string $message): string {
        $message = mb_strtolower($message);
        
        // Check for specific topics in message
        $topicResponses = [
            'member_card' => "**บัตรสมาชิกดิจิทัล (Member Card)** 🎫\n\nบัตรสมาชิกช่วยให้ลูกค้าดูข้อมูลสมาชิก แต้มสะสม และสิทธิพิเศษได้ใน LINE\n\n**วิธีตั้งค่า:**\n1. ไปที่ LIFF Settings\n2. สร้าง LIFF App สำหรับ Member Card\n3. คัดลอก LIFF ID มาใส่ในช่อง 'LIFF Member ID'\n\n👉 [ไปตั้งค่า LIFF](/liff-settings.php)\n👉 [จัดการสมาชิก](/members.php)",
            
            'shop_info' => "**ข้อมูลร้านค้า** 🏪\n\nตั้งค่าข้อมูลพื้นฐานของร้านเพื่อแสดงให้ลูกค้าเห็น\n\n**หน้าตั้งค่าร้านค้า (shop/settings.php):**\n• ชื่อร้าน, โลโก้\n• ที่อยู่, เบอร์ติดต่อ, อีเมล\n• ค่าจัดส่ง, ส่งฟรีขั้นต่ำ\n• COD (เก็บเงินปลายทาง)\n• บัญชีธนาคาร, PromptPay\n• LINE ID, Facebook, Instagram\n\n**หน้าตั้งค่า LIFF Shop:**\n• การแสดงผลหน้าร้าน\n• หมวดหมู่, แบนเนอร์\n\n👉 [ตั้งค่าร้านค้า](/shop/settings.php)\n👉 [ตั้งค่า LIFF Shop](/shop/liff-shop-settings.php)",
            
            'products' => "**การจัดการสินค้า** 📦\n\n**วิธีเพิ่มสินค้า:**\n1. ไปที่ Shop > Products\n2. กด 'เพิ่มสินค้า'\n3. กรอกข้อมูล: ชื่อ, ราคา, รูปภาพ\n4. เลือกหมวดหมู่\n5. กดบันทึก\n\n**Tips:**\n• ใส่รูปภาพคุณภาพดี\n• เขียนรายละเอียดให้ครบ\n\n👉 [ไปจัดการสินค้า](/shop/products.php)",
            
            'webhook' => "**การตั้งค่า Webhook** 🔗\n\nWebhook ใช้รับข้อความจาก LINE\n\n**วิธีตั้งค่า:**\n1. ไปที่ LINE Developers Console\n2. เลือก Channel > Messaging API\n3. ในส่วน Webhook settings:\n   - เปิด 'Use webhook'\n   - ใส่ Webhook URL ของระบบ\n   - กด Verify\n\n👉 [ไปตั้งค่า LINE Account](/line-accounts.php)",
            
            'liff_shop' => "**LIFF Shop** 🛒\n\nร้านค้าออนไลน์ที่เปิดใน LINE App\n\n**วิธีตั้งค่า:**\n1. สร้าง LIFF App ใน LINE Console\n2. ตั้ง Endpoint URL เป็น URL ของระบบ\n3. คัดลอก LIFF ID มาใส่\n\n👉 [ไปตั้งค่า LIFF](/liff-settings.php)",
            
            'payment' => "**การชำระเงิน** 💳\n\n**ช่องทางที่รองรับ:**\n• โอนเงินผ่านธนาคาร\n• PromptPay\n\n**วิธีตั้งค่า:**\n1. ไปที่ Shop Settings\n2. เพิ่มบัญชีธนาคาร หรือ\n3. ใส่เบอร์ PromptPay\n\n👉 [ไปตั้งค่าการชำระเงิน](/shop/liff-shop-settings.php)",
            
            'rich_menu' => "**Rich Menu** 📱\n\nเมนูลัดที่แสดงด้านล่างห้องแชท\n\n**วิธีสร้าง:**\n1. ไปที่ Rich Menu\n2. กด 'สร้าง Rich Menu'\n3. อัพโหลดรูปภาพ (2500x1686 px)\n4. กำหนด Action สำหรับแต่ละปุ่ม\n5. เปิดใช้งาน\n\n👉 [ไปสร้าง Rich Menu](/rich-menu.php)",
            
            'auto_reply' => "**ข้อความตอบกลับอัตโนมัติ** 🤖\n\nตอบกลับอัตโนมัติเมื่อลูกค้าส่งข้อความที่ตรงกับ keyword\n\n**วิธีตั้งค่า:**\n1. ไปที่ Auto Reply\n2. กด 'เพิ่มข้อความตอบกลับ'\n3. กำหนด Keyword\n4. กำหนดข้อความตอบกลับ\n5. เปิดใช้งาน\n\n👉 [ไปตั้งค่า Auto Reply](/auto-reply.php)",
            
            'ai_chat' => "**AI Chat** 🧠\n\nใช้ AI ตอบคำถามลูกค้าอัตโนมัติ\n\n**วิธีเปิดใช้งาน:**\n1. ไปที่ AI Settings\n2. ใส่ Gemini API Key\n3. เปิดใช้งาน AI Chat\n4. ตั้งค่า Prompt\n\n**การขอ API Key:**\nไปที่ Google AI Studio > สร้าง API Key\n\n👉 [ไปตั้งค่า AI](/ai-settings.php)",
            
            'broadcast' => "**Broadcast** 📢\n\nส่งข้อความถึงลูกค้าหลายคนพร้อมกัน\n\n**วิธีส่ง:**\n1. ไปที่ Broadcast\n2. กด 'สร้าง Broadcast'\n3. เลือกกลุ่มเป้าหมาย\n4. สร้างข้อความ\n5. ส่งทันทีหรือตั้งเวลา\n\n👉 [ไปส่ง Broadcast](/broadcast.php)",
            
            'loyalty' => "**ระบบแต้มสะสม & รางวัล** 🪙\n\nให้ลูกค้าสะสมแต้มเมื่อซื้อสินค้า และแลกรางวัลผ่าน LINE LIFF\n\n**สำหรับ Admin:**\n• จัดการรางวัล - loyalty-rewards.php\n• ดูประวัติการแลก\n• ตั้งค่าระบบแต้ม\n\n**สำหรับลูกค้า (LIFF):**\n• ดูแต้มสะสม - liff-points-history.php\n• แลกแต้ม - liff-redeem-points.php\n• บัตรสมาชิก - liff-member-card.php\n\n**วิธีตั้งค่า:**\n1. ไปที่ รางวัลแลกแต้ม\n2. เพิ่มรางวัลที่ต้องการ\n3. ตั้งค่าแต้มที่ใช้แลก\n4. เปิดใช้งาน\n\n👉 [จัดการรางวัล (Admin)](/loyalty-rewards.php)\n👉 [แลกแต้ม (LIFF)](/liff-redeem-points.php)",
            
            'line_connection' => "**การเชื่อมต่อ LINE OA** 💚\n\n**วิธีเชื่อมต่อ:**\n1. ไปที่ LINE Developers Console\n2. เลือก Provider และ Channel\n3. ไปที่ Messaging API settings\n4. คัดลอก Channel Access Token\n5. คัดลอก Channel Secret\n6. นำมาใส่ในระบบ\n\n👉 [ไปตั้งค่า LINE Account](/line-accounts.php)",
            
            // === Advanced Marketing Features ===
            
            'drip_campaign' => "**Drip Campaign (แคมเปญอัตโนมัติ)** 💧\n\nส่งข้อความอัตโนมัติตามลำดับเวลาที่กำหนด เหมาะสำหรับ:\n• Welcome Series - ต้อนรับสมาชิกใหม่\n• Nurture Campaign - ดูแลลูกค้าต่อเนื่อง\n• Re-engagement - ดึงลูกค้าเก่ากลับมา\n\n**วิธีสร้าง Drip Campaign:**\n1. ไปที่ Drip Campaigns\n2. กด 'สร้างแคมเปญใหม่'\n3. ตั้งชื่อและเลือก Trigger (เช่น สมัครสมาชิก)\n4. เพิ่ม Steps (ข้อความ + ระยะเวลา)\n5. เปิดใช้งาน\n\n**ตัวอย่าง Welcome Series:**\n• Day 0: ยินดีต้อนรับ + แนะนำร้าน\n• Day 3: แนะนำสินค้าขายดี\n• Day 7: ส่งคูปองส่วนลด\n\n👉 [ไปสร้าง Drip Campaign](/drip-campaigns.php)",
            
            'user_tags' => "**การติดแท็กลูกค้า** 🏷️\n\nจัดกลุ่มลูกค้าด้วย Tags เพื่อส่งข้อความตรงกลุ่มเป้าหมาย\n\n**ประเภท Tags:**\n• **Manual Tags** - ติดเอง เช่น VIP, ลูกค้าประจำ\n• **Auto Tags** - ติดอัตโนมัติตามพฤติกรรม\n\n**วิธีติด Tags:**\n1. ไปที่หน้า Users หรือ User Detail\n2. เลือกลูกค้า\n3. กด 'เพิ่ม Tag'\n4. เลือกหรือสร้าง Tag ใหม่\n\n**Auto Tag Rules:**\nตั้งกฎให้ติด Tag อัตโนมัติ เช่น:\n• ซื้อครบ 3 ครั้ง → ติด 'ลูกค้าประจำ'\n• ยอดซื้อ > 5000 → ติด 'VIP'\n• ไม่ซื้อ 30 วัน → ติด 'Inactive'\n\n👉 [จัดการ Tags](/user-tags.php)\n👉 [ตั้งค่า Auto Tag](/auto-tag-rules.php)",
            
            'scheduled_broadcast' => "**การตั้งเวลาส่ง Broadcast** ⏰\n\nตั้งเวลาส่งข้อความล่วงหน้า\n\n**วิธีตั้งเวลา:**\n1. ไปที่ Broadcast\n2. สร้างข้อความ\n3. เลือก 'ตั้งเวลาส่ง' แทน 'ส่งทันที'\n4. เลือกวันที่และเวลา\n5. กดบันทึก\n\n**Tips:**\n• ส่งช่วง 10:00-12:00 หรือ 18:00-20:00 มี Open Rate สูง\n• หลีกเลี่ยงส่งดึกเกินไป\n• ตรวจสอบ Preview ก่อนตั้งเวลา\n\n👉 [ไปตั้งเวลา Broadcast](/broadcast.php)",
            
            'customer_segments' => "**Customer Segments (กลุ่มลูกค้า)** 👥\n\nสร้างกลุ่มลูกค้าตามเงื่อนไขที่กำหนด\n\n**ตัวอย่าง Segments:**\n• **New Customers** - สมัครภายใน 7 วัน\n• **Active Buyers** - ซื้อภายใน 30 วัน\n• **High Value** - ยอดซื้อรวม > 10,000\n• **At Risk** - ไม่ซื้อ 60 วัน\n• **VIP** - ซื้อ > 5 ครั้ง + ยอด > 20,000\n\n**วิธีสร้าง Segment:**\n1. ไปที่ Customer Segments\n2. กด 'สร้าง Segment'\n3. ตั้งชื่อและเงื่อนไข\n4. Preview จำนวนลูกค้า\n5. บันทึก\n\n**ใช้งาน:**\n• เลือก Segment ตอนส่ง Broadcast\n• ใช้เป็น Trigger ใน Drip Campaign\n\n👉 [จัดการ Segments](/customer-segments.php)",
            
            'link_tracking' => "**Link Tracking (ติดตามลิงก์)** 🔗\n\nวัดผลว่าลูกค้าคลิกลิงก์ไหนบ้าง\n\n**วิธีใช้:**\n1. ไปที่ Link Tracking\n2. สร้าง Tracked Link\n3. ใส่ URL ปลายทาง\n4. คัดลอก Tracking URL ไปใช้\n\n**ข้อมูลที่ได้:**\n• จำนวนคลิก\n• Unique clicks\n• เวลาที่คลิก\n• ใครคลิกบ้าง\n\n**ใช้ใน Broadcast:**\nระบบจะสร้าง Tracking Link อัตโนมัติ\n\n👉 [ไป Link Tracking](/link-tracking.php)",
            
            'broadcast_analytics' => "**Broadcast Analytics (วิเคราะห์ผล)** 📊\n\nดูผลลัพธ์การส่ง Broadcast\n\n**Metrics สำคัญ:**\n• **Sent** - ส่งสำเร็จกี่คน\n• **Delivered** - ส่งถึงกี่คน\n• **Read** - อ่านกี่คน (Open Rate)\n• **Clicked** - คลิกลิงก์กี่คน (CTR)\n\n**วิธีดู:**\n1. ไปที่ Broadcast Stats\n2. เลือก Broadcast ที่ต้องการ\n3. ดูรายละเอียด\n\n**Tips:**\n• Open Rate ดี = 60%+\n• CTR ดี = 5%+\n• ทดสอบ A/B เพื่อปรับปรุง\n\n👉 [ดู Broadcast Stats](/broadcast-stats.php)",
            
            'flex_builder' => "**Flex Message Builder** 🎨\n\nสร้างข้อความ Flex สวยๆ แบบ Drag & Drop\n\n**ประเภท Flex:**\n• **Bubble** - การ์ดเดี่ยว\n• **Carousel** - การ์ดหลายใบเลื่อนได้\n\n**วิธีใช้:**\n1. ไปที่ Flex Builder\n2. เลือก Template หรือสร้างใหม่\n3. ลาก Components มาวาง\n4. ปรับแต่งสี ขนาด ข้อความ\n5. Preview และบันทึก\n\n**ใช้งาน:**\n• ใช้ใน Broadcast\n• ใช้ใน Auto Reply\n• ใช้ใน Drip Campaign\n\n👉 [ไป Flex Builder](/flex-builder.php)",
            
            'scheduled_reports' => "**Scheduled Reports (รายงานอัตโนมัติ)** 📈\n\nตั้งเวลาส่งรายงานอัตโนมัติ\n\n**ประเภทรายงาน:**\n• Daily Summary - สรุปรายวัน\n• Weekly Report - สรุปรายสัปดาห์\n• Monthly Report - สรุปรายเดือน\n\n**ข้อมูลในรายงาน:**\n• ยอดขาย\n• จำนวนออเดอร์\n• สมาชิกใหม่\n• ข้อความที่ได้รับ\n\n**วิธีตั้งค่า:**\n1. ไปที่ Scheduled Reports\n2. เลือกประเภทรายงาน\n3. ตั้งเวลาส่ง\n4. เลือกช่องทาง (Email/LINE)\n5. เปิดใช้งาน\n\n👉 [ตั้งค่า Reports](/scheduled-reports.php)",
            
            'promotions' => "**โปรโมชั่นและคูปอง** 🎁\n\nสร้างโปรโมชั่นดึงดูดลูกค้า\n\n**ประเภทโปรโมชั่น:**\n• **ส่วนลดเปอร์เซ็นต์** - ลด 10%, 20%\n• **ส่วนลดบาท** - ลด 100, 200 บาท\n• **ส่งฟรี** - ฟรีค่าส่ง\n• **ซื้อ X แถม Y** - ซื้อ 2 แถม 1\n\n**วิธีสร้าง:**\n1. ไปที่ Promotions\n2. กด 'สร้างโปรโมชั่น'\n3. เลือกประเภท\n4. ตั้งเงื่อนไข (ขั้นต่ำ, วันหมดอายุ)\n5. สร้างโค้ดคูปอง\n6. เปิดใช้งาน\n\n👉 [จัดการโปรโมชั่น](/shop/promotions.php)",
            
            'crm_analytics' => "**CRM Analytics** 📊\n\nวิเคราะห์ข้อมูลลูกค้าเชิงลึก\n\n**Dashboard:**\n• **Overview** - ภาพรวมลูกค้า\n• **Acquisition** - ลูกค้าใหม่\n• **Engagement** - การมีส่วนร่วม\n• **Revenue** - รายได้\n\n**Metrics:**\n• Customer Lifetime Value (CLV)\n• Retention Rate\n• Churn Rate\n• Average Order Value\n\n👉 [ดู CRM Analytics](/crm-analytics.php)\n👉 [Executive Dashboard](/executive-dashboard.php)",
            
            'bug_report' => "**รายงานปัญหา/บัค** 🐛\n\nขอบคุณที่แจ้งปัญหาครับ! กรุณาบอกรายละเอียดเพิ่มเติม:\n\n1. **หน้าที่เกิดปัญหา**: URL หรือชื่อหน้า\n2. **อาการ**: เกิดอะไรขึ้น (error 500, หน้าว่าง, ข้อมูลไม่แสดง)\n3. **ขั้นตอน**: ทำอะไรก่อนเกิดปัญหา\n4. **Error message**: ถ้ามี\n\nผมจะช่วยวิเคราะห์และแนะนำวิธีแก้ไขครับ"
        ];
        
        // Check for bug report keywords
        if (mb_strpos($message, 'บัค') !== false || mb_strpos($message, 'bug') !== false || 
            mb_strpos($message, 'error') !== false || mb_strpos($message, 'ผิดพลาด') !== false ||
            mb_strpos($message, '500') !== false || mb_strpos($message, 'ไม่ทำงาน') !== false ||
            mb_strpos($message, 'พัง') !== false || mb_strpos($message, 'ปัญหา') !== false ||
            mb_strpos($message, 'หน้าว่าง') !== false || mb_strpos($message, 'ไม่แสดง') !== false) {
            return $this->analyzeBugReport($message);
        }
        
        // Check each topic
        foreach ($topicResponses as $topic => $response) {
            $keywords = explode('_', $topic);
            foreach ($keywords as $keyword) {
                if (mb_strpos($message, $keyword) !== false) {
                    return $response;
                }
            }
        }
        
        // Additional keyword checks
        if (mb_strpos($message, 'สมาชิก') !== false || mb_strpos($message, 'member') !== false) {
            return $topicResponses['member_card'];
        }
        if (mb_strpos($message, 'ร้าน') !== false || mb_strpos($message, 'shop') !== false) {
            return $topicResponses['shop_info'];
        }
        if (mb_strpos($message, 'สินค้า') !== false || mb_strpos($message, 'product') !== false) {
            return $topicResponses['products'];
        }
        if (mb_strpos($message, 'แต้ม') !== false || mb_strpos($message, 'point') !== false) {
            return $topicResponses['loyalty'];
        }
        if (mb_strpos($message, 'เมนู') !== false || mb_strpos($message, 'menu') !== false) {
            return $topicResponses['rich_menu'];
        }
        if (mb_strpos($message, 'ตอบ') !== false || mb_strpos($message, 'reply') !== false) {
            return $topicResponses['auto_reply'];
        }
        if (mb_strpos($message, 'ai') !== false || mb_strpos($message, 'เอไอ') !== false) {
            return $topicResponses['ai_chat'];
        }
        if (mb_strpos($message, 'จ่าย') !== false || mb_strpos($message, 'ชำระ') !== false) {
            return $topicResponses['payment'];
        }
        
        // Advanced Marketing keyword checks
        if (mb_strpos($message, 'drip') !== false || mb_strpos($message, 'ดริป') !== false || 
            mb_strpos($message, 'แคมเปญ') !== false || mb_strpos($message, 'campaign') !== false ||
            mb_strpos($message, 'อัตโนมัติ') !== false || mb_strpos($message, 'automation') !== false) {
            return $topicResponses['drip_campaign'];
        }
        if (mb_strpos($message, 'tag') !== false || mb_strpos($message, 'แท็ก') !== false || 
            mb_strpos($message, 'ติดแท็ก') !== false || mb_strpos($message, 'จัดกลุ่ม') !== false) {
            return $topicResponses['user_tags'];
        }
        if (mb_strpos($message, 'ตั้งเวลา') !== false || mb_strpos($message, 'schedule') !== false ||
            mb_strpos($message, 'ล่วงหน้า') !== false) {
            return $topicResponses['scheduled_broadcast'];
        }
        if (mb_strpos($message, 'segment') !== false || mb_strpos($message, 'เซกเมนต์') !== false ||
            mb_strpos($message, 'กลุ่มลูกค้า') !== false) {
            return $topicResponses['customer_segments'];
        }
        if (mb_strpos($message, 'tracking') !== false || mb_strpos($message, 'ติดตาม') !== false ||
            mb_strpos($message, 'วัดผล') !== false || mb_strpos($message, 'คลิก') !== false) {
            return $topicResponses['link_tracking'];
        }
        if (mb_strpos($message, 'analytics') !== false || mb_strpos($message, 'วิเคราะห์') !== false ||
            mb_strpos($message, 'สถิติ') !== false || mb_strpos($message, 'stats') !== false) {
            return $topicResponses['broadcast_analytics'];
        }
        if (mb_strpos($message, 'flex') !== false || mb_strpos($message, 'builder') !== false ||
            mb_strpos($message, 'สร้างข้อความ') !== false || mb_strpos($message, 'การ์ด') !== false) {
            return $topicResponses['flex_builder'];
        }
        if (mb_strpos($message, 'report') !== false || mb_strpos($message, 'รายงาน') !== false) {
            return $topicResponses['scheduled_reports'];
        }
        if (mb_strpos($message, 'โปรโมชั่น') !== false || mb_strpos($message, 'promotion') !== false ||
            mb_strpos($message, 'คูปอง') !== false || mb_strpos($message, 'coupon') !== false ||
            mb_strpos($message, 'ส่วนลด') !== false || mb_strpos($message, 'discount') !== false) {
            return $topicResponses['promotions'];
        }
        if (mb_strpos($message, 'crm') !== false || mb_strpos($message, 'dashboard') !== false ||
            mb_strpos($message, 'executive') !== false) {
            return $topicResponses['crm_analytics'];
        }
        
        // Check for casual/unclear messages - provide helpful menu
        $casualKeywords = ['โหลด', 'ลอง', 'ทดสอบ', 'test', 'ดู', 'อะไร', 'ยังไง', 'อย่างไร', 'ทำไง', 'ช่วย', 'help'];
        foreach ($casualKeywords as $keyword) {
            if (mb_strpos($message, $keyword) !== false) {
                return "ผมช่วยคุณได้หลายอย่างครับ! 🚀\n\n**📌 ตั้งค่าพื้นฐาน:**\n• วิธีเชื่อมต่อ LINE OA\n• วิธีตั้งค่าร้านค้า\n• วิธีเพิ่มสินค้า\n• วิธีสร้าง Rich Menu\n\n**🚀 การตลาดขั้นสูง:**\n• Drip Campaign (แคมเปญอัตโนมัติ)\n• การติดแท็กลูกค้า\n• Customer Segments\n• ตั้งเวลาส่ง Broadcast\n• สร้างโปรโมชั่น/คูปอง\n\n**ลองพิมพ์คำถามเช่น:**\n• \"วิธีเชื่อมต่อ LINE\"\n• \"วิธีสร้าง Drip Campaign\"\n• \"วิธีติดแท็กลูกค้า\"\n\nหรือกดปุ่มคำถามด้านขวามือได้เลยครับ 👉";
            }
        }
        
        // Use intent-based fallback
        $intent = $this->promptBuilder->extractIntent($message);
        $primaryIntent = $intent['primary_intent'];
        
        $responses = [
            'greeting' => "สวัสดีครับ! 👋 RE-YA Assistant พร้อมช่วยคุณตั้งค่าและใช้งานระบบ LINE CRM ครับ\n\nถามผมได้เลยว่าต้องการทำอะไร หรือดูรายการตั้งค่าที่แนะนำได้ที่ Checklist ด้านข้างครับ",
            'help' => "ผมช่วยคุณได้หลายอย่างครับ:\n\n**📌 ตั้งค่าพื้นฐาน:**\n• ตั้งค่าการเชื่อมต่อ LINE\n• ตั้งค่าร้านค้าและสินค้า\n• ตั้งค่า LIFF Apps\n• สร้าง Rich Menu\n• ตั้งค่า Auto Reply\n• เปิดใช้ AI Chat\n\n**🚀 การตลาดขั้นสูง:**\n• Drip Campaign\n• Customer Segments\n• การติดแท็ก\n• โปรโมชั่น/คูปอง\n\nบอกผมได้เลยว่าต้องการทำอะไรครับ",
            'feature_info' => "ฟีเจอร์หลักของระบบ:\n\n**📱 พื้นฐาน:**\n• **Inbox** - จัดการข้อความลูกค้า\n• **Shop** - ร้านค้าออนไลน์\n• **Broadcast** - ส่งข้อความหาลูกค้า\n• **Rich Menu** - เมนูลัดใน LINE\n• **Auto Reply** - ตอบกลับอัตโนมัติ\n• **AI Chat** - AI ตอบแชท\n• **Loyalty** - ระบบแต้มสะสม\n\n**🚀 ขั้นสูง:**\n• **Drip Campaign** - แคมเปญอัตโนมัติ\n• **Segments** - กลุ่มลูกค้า\n• **Tags** - ติดแท็กลูกค้า\n• **Promotions** - โปรโมชั่น/คูปอง\n\nสนใจฟีเจอร์ไหนเป็นพิเศษครับ?",
            'status' => "ดูสถานะการตั้งค่าได้ที่ Checklist ด้านข้างครับ หรือกดปุ่ม 'ตรวจสอบสถานะระบบ' เพื่อ Health Check",
            'general' => "ผมช่วยคุณได้หลายอย่างครับ! 🚀\n\n**📌 ตั้งค่าพื้นฐาน:**\n• วิธีเชื่อมต่อ LINE OA\n• วิธีตั้งค่าร้านค้า\n• วิธีเพิ่มสินค้า\n• วิธีสร้าง Rich Menu\n\n**🚀 การตลาดขั้นสูง:**\n• Drip Campaign (แคมเปญอัตโนมัติ)\n• การติดแท็กลูกค้า\n• Customer Segments\n• ตั้งเวลาส่ง Broadcast\n• สร้างโปรโมชั่น/คูปอง\n\n**ลองพิมพ์คำถามเช่น:**\n• \"วิธีเชื่อมต่อ LINE\"\n• \"วิธีสร้าง Drip Campaign\"\n• \"วิธีติดแท็กลูกค้า\"\n\nหรือกดปุ่มคำถามด้านขวามือได้เลยครับ 👉"
        ];
        
        return $responses[$primaryIntent] ?? $responses['general'];
    }
    
    /**
     * Save conversation to history
     */
    private function saveConversation(string $userMessage, string $aiResponse): void {
        if (!$this->sessionId) return;
        
        try {
            $stmt = $this->db->prepare("
                SELECT conversation_history FROM onboarding_sessions WHERE id = ?
            ");
            $stmt->execute([$this->sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $history = json_decode($session['conversation_history'] ?? '[]', true);
            $history[] = [
                'role' => 'user',
                'content' => $userMessage,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $history[] = [
                'role' => 'assistant',
                'content' => $aiResponse,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            // Keep only last 20 messages
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            
            $stmt = $this->db->prepare("
                UPDATE onboarding_sessions 
                SET conversation_history = ?, last_activity = NOW()
                WHERE id = ?
            ");
            $stmt->execute([json_encode($history), $this->sessionId]);
        } catch (\Exception $e) {
            // Ignore errors
        }
    }
    
    /**
     * Get current setup status
     */
    public function getSetupStatus(): array {
        return $this->statusChecker->checkAll();
    }
    
    /**
     * Get setup checklist with progress
     */
    public function getChecklist(): array {
        $status = $this->statusChecker->checkAll();
        $completion = $this->statusChecker->getCompletionPercentage();
        $nextAction = $this->statusChecker->getNextRecommendedAction();
        
        return [
            'status' => $status,
            'completion_percent' => $completion,
            'next_action' => $nextAction,
            'checklist_definition' => SetupStatusChecker::SETUP_CHECKLIST
        ];
    }
    
    /**
     * Execute quick action
     */
    public function executeAction(string $action, array $params = []): array {
        return $this->actionExecutor->execute($action, $params);
    }
    
    /**
     * Run health check
     */
    public function runHealthCheck(): array {
        return $this->actionExecutor->execute('run_health_check');
    }
    
    /**
     * Get contextual suggestions
     */
    public function getSuggestions(string $currentPage = null): array {
        $setupStatus = $this->statusChecker->checkAll();
        $suggestions = $this->actionExecutor->getSuggestedActions($setupStatus);
        
        // Add page-specific suggestions
        if ($currentPage) {
            $pageSuggestions = $this->getPageSpecificSuggestions($currentPage);
            $suggestions = array_merge($pageSuggestions, $suggestions);
        }
        
        return array_slice($suggestions, 0, 5);
    }
    
    /**
     * Get page-specific suggestions
     */
    private function getPageSpecificSuggestions(string $currentPage): array {
        $suggestions = [];
        
        $pageMap = [
            'line-accounts' => [
                'tip' => 'ตรวจสอบว่า Channel Access Token และ Channel Secret ถูกต้อง',
                'action' => 'test_line_connection'
            ],
            'shop/products' => [
                'tip' => 'เพิ่มรูปภาพสินค้าที่สวยงามเพื่อดึงดูดลูกค้า',
                'action' => null
            ],
            'rich-menu' => [
                'tip' => 'ใช้รูปภาพขนาด 2500x1686 หรือ 2500x843 pixels',
                'action' => null
            ]
        ];
        
        if (isset($pageMap[$currentPage])) {
            $suggestions['page_tip'] = $pageMap[$currentPage];
        }
        
        return $suggestions;
    }
    
    /**
     * Get welcome message
     */
    public function getWelcomeMessage(string $userName = 'User'): string {
        $completion = $this->statusChecker->getCompletionPercentage();
        $nextAction = $this->statusChecker->getNextRecommendedAction();
        
        return $this->promptBuilder->buildWelcomeMessage($userName, $completion, $nextAction);
    }
    
    /**
     * Get conversation history
     */
    public function getConversationHistory(): array {
        if (!$this->sessionId) return [];
        
        try {
            $stmt = $this->db->prepare("
                SELECT conversation_history FROM onboarding_sessions WHERE id = ?
            ");
            $stmt->execute([$this->sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return json_decode($session['conversation_history'] ?? '[]', true);
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Check if Gemini AI is available
     */
    public function isAiAvailable(): bool {
        return !empty($this->geminiApiKey);
    }
    
    /**
     * Clear conversation history
     */
    public function clearHistory(): bool {
        if (!$this->sessionId) return false;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE onboarding_sessions 
                SET conversation_history = '[]'
                WHERE id = ?
            ");
            return $stmt->execute([$this->sessionId]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
