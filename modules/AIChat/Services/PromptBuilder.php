<?php
/**
 * Service: Prompt Builder
 * สร้าง System Prompt - แนะนำยาทันทีเมื่อลูกค้าบอกอาการ
 */

namespace Modules\AIChat\Services;

use Modules\AIChat\Models\AISettings;
use Modules\Core\Database;

class PromptBuilder
{
    private AISettings $settings;
    private ContextAnalyzer $contextAnalyzer;
    private ?string $lastUserMessage = null;
    
    public function __construct(AISettings $settings)
    {
        $this->settings = $settings;
        $this->contextAnalyzer = new ContextAnalyzer();
    }
    
    /**
     * สร้าง System Prompt สำหรับ AI
     */
    public function build(array $conversationHistory, ?array $customerInfo = null): string
    {
        $parts = [];
        
        // เก็บข้อความล่าสุดของ user
        $this->lastUserMessage = '';
        foreach (array_reverse($conversationHistory) as $msg) {
            if ($msg['role'] === 'user') {
                $this->lastUserMessage = $msg['content'];
                break;
            }
        }
        
        // วิเคราะห์อาการจากข้อความ
        $extractedInfo = $this->contextAnalyzer->analyze($conversationHistory);
        
        // 1. กฎหลัก - แนะนำยาทันที
        $parts[] = $this->buildRules();
        
        // 2. บทบาท
        $parts[] = $this->buildRole();
        
        // 3. สินค้าในคลัง (สำคัญมาก!)
        $products = $this->getAllProducts();
        if ($products) {
            $parts[] = "[สินค้าในคลัง - ใช้แนะนำลูกค้า]\n" . $products;
        }
        
        // 4. สินค้าที่เกี่ยวข้องกับอาการ
        if (!empty($extractedInfo['อาการ'])) {
            $symptoms = explode(', ', $extractedInfo['อาการ']);
            $relatedProducts = $this->searchProductsBySymptom($symptoms);
            if ($relatedProducts) {
                $parts[] = $relatedProducts;
            }
        }
        
        // 5. ข้อมูลลูกค้า
        if ($customerInfo) {
            $parts[] = $this->buildCustomerInfo($customerInfo);
        }
        
        // 6. คำสั่งสำหรับการตอบ
        $parts[] = $this->buildInstruction($extractedInfo);
        
        return implode("\n\n", $parts);
    }
    
    private function buildRules(): string
    {
        return "
[กฎหลัก - ทำตามทุกข้อ]

1. เมื่อลูกค้าบอกอาการ → แนะนำยาจากคลังทันที พร้อมราคา
2. ตอบสั้นๆ 2-3 ประโยค เป็นธรรมชาติ
3. ห้ามใช้ bullet points (*), ตัวเลข (1. 2.), หรือ **ตัวหนา**
4. ห้ามถามซ้ำๆ ถ้าลูกค้าบอกอาการแล้ว ให้แนะนำยาเลย
5. ถ้าไม่มียาในคลังที่ตรงกับอาการ บอกว่า \"ขออภัยค่ะ ไม่มียาสำหรับอาการนี้ในคลัง\"

[Flow การตอบ]
ลูกค้าบอกอาการ → แนะนำยา 1-2 ตัวพร้อมราคา → ถามว่าสนใจไหม → รอเภสัชกรยืนยัน
";
    }
    
    private function buildRole(): string
    {
        $systemPrompt = $this->settings->getSystemPrompt();
        
        if (empty($systemPrompt)) {
            $systemPrompt = 'คุณคือผู้ช่วยร้านขายยา ช่วยแนะนำยาให้ลูกค้าตามอาการ ตอบสั้นๆ เป็นกันเอง';
        }
        
        return "บทบาท: " . $systemPrompt;
    }
    
    private function buildCustomerInfo(array $info): string
    {
        $text = "ข้อมูลลูกค้า:\n";
        
        if (!empty($info['display_name'])) {
            $text .= "- ชื่อ: {$info['display_name']}\n";
        }
        if (!empty($info['drug_allergies'])) {
            $text .= "- แพ้ยา: {$info['drug_allergies']} (ห้ามแนะนำยานี้!)\n";
        }
        
        return $text;
    }
    
    private function buildInstruction(array $extractedInfo): string
    {
        // ถ้ามีอาการ → แนะนำยาทันที
        if (!empty($extractedInfo['อาการ'])) {
            return "[คำสั่ง]: ลูกค้ามีอาการ \"{$extractedInfo['อาการ']}\" - แนะนำยาจากรายการด้านบนที่เหมาะกับอาการนี้ พร้อมราคา ตอบเป็นประโยคธรรมดา";
        }
        
        return "[คำสั่ง]: ถามลูกค้าว่ามีอาการอะไร หรือต้องการยาอะไร";
    }
    
    /**
     * ดึงสินค้าทั้งหมดจาก database
     */
    private function getAllProducts(): ?string
    {
        try {
            $db = Database::getInstance();
            
            // ไม่ filter ตาม line_account_id - แสดงสินค้าทั้งหมด
            $products = $db->fetchAll("
                SELECT name, price, generic_name, description
                FROM business_items 
                WHERE is_active = 1
                ORDER BY name ASC 
                LIMIT 50
            ");
            
            if (empty($products)) return null;
            
            $text = '';
            foreach ($products as $p) {
                $text .= "- {$p['name']}: {$p['price']} บาท";
                if (!empty($p['generic_name'])) {
                    $text .= " ({$p['generic_name']})";
                }
                $text .= "\n";
            }
            
            return $text;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * ค้นหาสินค้าตามอาการ
     */
    private function searchProductsBySymptom(array $symptoms, int $limit = 5): ?string
    {
        if (empty($symptoms)) return null;
        
        try {
            $db = Database::getInstance();
            
            // Map อาการ → keywords สินค้า
            $keywordMap = [
                'ปวดหัว' => ['พาราเซตามอล', 'paracetamol', 'ไทลินอล', 'แก้ปวด', 'sara'],
                'ปวดกล้ามเนื้อ' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'mydocalm', 'tolperisone', 'แก้ปวด'],
                'ปวดหลัง' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'แก้ปวด', 'ibuprofen'],
                'ปวดคอ' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'แก้ปวด'],
                'ปวดเมื่อย' => ['คลายกล้ามเนื้อ', 'มายโดคาล์ม', 'แก้ปวด'],
                'ไข้' => ['พาราเซตามอล', 'ลดไข้', 'ทิฟฟี่', 'tiffy'],
                'หวัด' => ['ทิฟฟี่', 'tiffy', 'decolgen', 'แก้หวัด'],
                'ไอ' => ['แก้ไอ', 'ไซรัป', 'ทิฟฟี่', 'cough'],
                'เจ็บคอ' => ['แก้เจ็บคอ', 'ลูกอม', 'strepsils', 'difflam'],
                'แพ้' => ['แก้แพ้', 'ซีร์เทค', 'cetirizine', 'loratadine'],
                'คัน' => ['แก้แพ้', 'ซีร์เทค', 'ยาทา', 'calamine'],
                'ท้องเสีย' => ['ท้องเสีย', 'smecta', 'ผงเกลือแร่', 'ors'],
                'ท้องผูก' => ['ยาระบาย', 'dulcolax', 'senokot'],
                'กรดไหลย้อน' => ['ลดกรด', 'antacid', 'omeprazole'],
                'ปวดท้อง' => ['buscopan', 'แก้ปวดท้อง', 'antacid'],
            ];
            
            $searchTerms = [];
            foreach ($symptoms as $symptom) {
                $symptom = mb_strtolower(trim($symptom));
                foreach ($keywordMap as $key => $terms) {
                    if (mb_strpos($symptom, $key) !== false) {
                        $searchTerms = array_merge($searchTerms, $terms);
                    }
                }
            }
            
            if (empty($searchTerms)) return null;
            
            // Build search query - ไม่ filter ตาม line_account_id
            $conditions = [];
            $params = [];
            foreach (array_unique($searchTerms) as $term) {
                $conditions[] = "(name LIKE ? OR description LIKE ? OR generic_name LIKE ?)";
                $params[] = "%{$term}%";
                $params[] = "%{$term}%";
                $params[] = "%{$term}%";
            }
            $params[] = $limit;
            
            $sql = "SELECT name, price, generic_name 
                    FROM business_items 
                    WHERE is_active = 1 
                    AND (" . implode(' OR ', $conditions) . ")
                    LIMIT ?";
            
            $products = $db->fetchAll($sql, $params);
            
            if (empty($products)) return null;
            
            $text = "[ยาที่แนะนำสำหรับอาการนี้ - ใช้ข้อมูลนี้ตอบลูกค้า!]\n";
            foreach ($products as $p) {
                $text .= "• {$p['name']}: {$p['price']} บาท";
                if (!empty($p['generic_name'])) {
                    $text .= " (ตัวยา: {$p['generic_name']})";
                }
                $text .= "\n";
            }
            
            return $text;
        } catch (\Exception $e) {
            return null;
        }
    }
}
