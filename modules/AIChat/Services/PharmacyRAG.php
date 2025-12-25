<?php
/**
 * PharmacyRAG - Retrieval-Augmented Generation สำหรับ AI เภสัช
 * 
 * ค้นหาข้อมูลยาและสินค้าที่เกี่ยวข้องกับคำถามของลูกค้า
 * ใช้ทั้ง keyword search และ semantic matching
 */

namespace Modules\AIChat\Services;

class PharmacyRAG
{
    private $db;
    private $lineAccountId;
    
    // Drug categories mapping
    private $symptomDrugMap = [
        // อาการปวด
        'ปวดหัว' => ['paracetamol', 'ibuprofen', 'aspirin', 'tylenol', 'sara'],
        'ปวดฟัน' => ['ibuprofen', 'paracetamol', 'mefenamic', 'ponstan'],
        'ปวดท้อง' => ['buscopan', 'antacid', 'omeprazole'],
        'ปวดกล้ามเนื้อ' => ['ibuprofen', 'diclofenac', 'muscle relaxant', 'counterpain'],
        'ปวดประจำเดือน' => ['ibuprofen', 'mefenamic', 'ponstan', 'midol'],
        
        // ไข้หวัด
        'ไข้' => ['paracetamol', 'ibuprofen', 'tylenol'],
        'หวัด' => ['tiffy', 'decolgen', 'coldacin', 'rhinotapp'],
        'ไอ' => ['dextromethorphan', 'bromhexine', 'ambroxol', 'benadryl'],
        'เจ็บคอ' => ['strepsils', 'difflam', 'betadine gargle', 'kamillosan'],
        'น้ำมูก' => ['pseudoephedrine', 'phenylephrine', 'cetirizine'],
        'คัดจมูก' => ['oxymetazoline', 'xylometazoline', 'nasal spray'],
        
        // ระบบทางเดินอาหาร
        'ท้องเสีย' => ['smecta', 'imodium', 'loperamide', 'ors', 'เกลือแร่'],
        'ท้องผูก' => ['dulcolax', 'senokot', 'lactulose', 'milk of magnesia'],
        'คลื่นไส้' => ['domperidone', 'metoclopramide', 'dimenhydrinate'],
        'อาเจียน' => ['domperidone', 'ondansetron', 'dimenhydrinate'],
        'กรดไหลย้อน' => ['omeprazole', 'antacid', 'gaviscon', 'famotidine'],
        'แสบท้อง' => ['antacid', 'omeprazole', 'ranitidine', 'gaviscon'],
        'จุกเสียด' => ['simethicone', 'activated charcoal', 'antacid'],
        
        // ผิวหนัง
        'ผื่น' => ['calamine', 'hydrocortisone', 'cetirizine', 'loratadine'],
        'คัน' => ['cetirizine', 'loratadine', 'calamine', 'menthol'],
        'สิว' => ['benzoyl peroxide', 'salicylic acid', 'adapalene', 'acne'],
        'แผล' => ['betadine', 'povidone iodine', 'antiseptic', 'พลาสเตอร์'],
        'แมลงกัด' => ['calamine', 'hydrocortisone', 'antihistamine cream'],
        'ลมพิษ' => ['cetirizine', 'loratadine', 'chlorpheniramine'],
        
        // ตา
        'ตาแดง' => ['eye drop', 'artificial tears', 'naphazoline'],
        'ตาแห้ง' => ['artificial tears', 'eye lubricant', 'refresh'],
        'ตาอักเสบ' => ['antibiotic eye drop', 'tobramycin', 'chloramphenicol'],
        
        // แพ้
        'แพ้' => ['cetirizine', 'loratadine', 'chlorpheniramine', 'fexofenadine'],
        'แพ้อากาศ' => ['cetirizine', 'loratadine', 'nasal spray'],
        
        // อื่นๆ
        'นอนไม่หลับ' => ['diphenhydramine', 'melatonin', 'valerian'],
        'เมารถ' => ['dimenhydrinate', 'dramamine', 'scopolamine'],
        'เหน็บชา' => ['vitamin b', 'neurobion', 'methylcobalamin'],
        'อ่อนเพลีย' => ['vitamin b', 'multivitamin', 'iron supplement'],
    ];
    
    // Common drug names mapping (Thai -> English)
    private $drugNameMap = [
        'พาราเซตามอล' => 'paracetamol',
        'ไอบูโพรเฟน' => 'ibuprofen',
        'แอสไพริน' => 'aspirin',
        'ยาแก้แพ้' => 'antihistamine',
        'ยาแก้ไอ' => 'cough',
        'ยาลดกรด' => 'antacid',
        'ยาแก้ท้องเสีย' => 'antidiarrheal',
        'ยาหยอดตา' => 'eye drop',
        'ยาทาแผล' => 'antiseptic',
        'วิตามิน' => 'vitamin',
        'เกลือแร่' => 'ors',
        'ยาดม' => 'inhaler',
        'ยาหม่อง' => 'balm',
    ];
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
    
    /**
     * ค้นหาสินค้าที่เกี่ยวข้องกับข้อความ
     */
    public function search(string $message, int $limit = 15): array
    {
        $results = [
            'products' => [],
            'knowledge' => [],
            'suggestions' => []
        ];
        
        // 1. วิเคราะห์ข้อความ
        $analysis = $this->analyzeMessage($message);
        
        // 2. ค้นหาสินค้าจาก keywords
        $products = $this->searchProducts($analysis['keywords'], $limit);
        $results['products'] = $products;
        
        // 3. ดึงความรู้เกี่ยวกับยา
        $results['knowledge'] = $this->getDrugKnowledge($analysis);
        
        // 4. สร้างคำแนะนำ
        $results['suggestions'] = $analysis['suggestions'];
        
        return $results;
    }
    
    /**
     * วิเคราะห์ข้อความเพื่อหา keywords และอาการ
     */
    private function analyzeMessage(string $message): array
    {
        $result = [
            'keywords' => [],
            'symptoms' => [],
            'drug_names' => [],
            'sku_codes' => [],
            'suggestions' => []
        ];
        
        $messageLower = mb_strtolower($message);
        
        // 1. หา SKU/Barcode
        if (preg_match_all('/\b(\d{4,})\b/', $message, $matches)) {
            $result['sku_codes'] = $matches[1];
            $result['keywords'] = array_merge($result['keywords'], $matches[1]);
        }
        
        // 2. หาอาการ
        foreach ($this->symptomDrugMap as $symptom => $drugs) {
            if (mb_strpos($messageLower, $symptom) !== false) {
                $result['symptoms'][] = $symptom;
                $result['keywords'] = array_merge($result['keywords'], $drugs);
                $result['suggestions'][] = "พบอาการ: {$symptom}";
            }
        }
        
        // 2.5 หากลุ่มเป้าหมายพิเศษ (ตั้งครรภ์, เด็ก, ผู้สูงอายุ)
        $targetGroups = [
            'ตั้งครรภ์' => ['ตั้งครรภ์', 'pregnant', 'prenatal', 'folic', 'โฟลิก', 'บำรุงครรภ์'],
            'ท้อง' => ['ตั้งครรภ์', 'pregnant', 'prenatal'],
            'เด็ก' => ['เด็ก', 'children', 'kids', 'pediatric', 'baby', 'infant'],
            'ทารก' => ['baby', 'infant', 'newborn', 'ทารก'],
            'ผู้สูงอายุ' => ['elderly', 'senior', 'ผู้สูงอายุ', 'calcium', 'แคลเซียม'],
            'ให้นมบุตร' => ['breastfeeding', 'nursing', 'ให้นม'],
            'เบาหวาน' => ['diabetes', 'diabetic', 'เบาหวาน', 'sugar free'],
            'ความดัน' => ['hypertension', 'blood pressure', 'ความดัน'],
        ];
        
        foreach ($targetGroups as $group => $keywords) {
            if (mb_strpos($messageLower, $group) !== false) {
                $result['keywords'] = array_merge($result['keywords'], $keywords);
                $result['suggestions'][] = "กลุ่มเป้าหมาย: {$group}";
            }
        }
        
        // 2.6 หาหมวดหมู่สินค้า
        $categories = [
            'วิตามิน' => ['vitamin', 'วิตามิน', 'supplement', 'อาหารเสริม'],
            'เวชสำอาง' => ['cosmetic', 'skincare', 'ครีม', 'โลชั่น', 'lotion', 'cream'],
            'อุปกรณ์' => ['equipment', 'device', 'อุปกรณ์', 'เครื่องมือ'],
            'ยาสามัญ' => ['otc', 'ยาสามัญ', 'common'],
            'สมุนไพร' => ['herbal', 'สมุนไพร', 'natural', 'ธรรมชาติ'],
        ];
        
        foreach ($categories as $cat => $keywords) {
            if (mb_strpos($messageLower, $cat) !== false) {
                $result['keywords'] = array_merge($result['keywords'], $keywords);
            }
        }
        
        // 3. หาชื่อยา (ไทย)
        foreach ($this->drugNameMap as $thai => $eng) {
            if (mb_strpos($messageLower, $thai) !== false) {
                $result['drug_names'][] = $thai;
                $result['keywords'][] = $eng;
                $result['keywords'][] = $thai;
            }
        }
        
        // 4. หาชื่อยา (อังกฤษ)
        $commonDrugs = [
            'paracetamol', 'ibuprofen', 'aspirin', 'tylenol', 'advil',
            'cetirizine', 'loratadine', 'chlorpheniramine',
            'omeprazole', 'antacid', 'gaviscon',
            'tiffy', 'decolgen', 'sara', 'ponstan',
            'betadine', 'nexcare', 'band-aid',
            'vitamin', 'centrum', 'blackmores',
            'ors', 'smecta', 'imodium'
        ];
        
        foreach ($commonDrugs as $drug) {
            if (stripos($message, $drug) !== false) {
                $result['drug_names'][] = $drug;
                $result['keywords'][] = $drug;
            }
        }
        
        // 5. หาคำทั่วไปที่อาจเป็นชื่อสินค้า
        if (preg_match_all('/[a-zA-Z]{3,}/i', $message, $matches)) {
            $result['keywords'] = array_merge($result['keywords'], $matches[0]);
        }
        
        // 6. เพิ่มคำจากข้อความโดยตรง (สำหรับค้นหาใน description)
        $importantWords = preg_split('/\s+/', $message);
        foreach ($importantWords as $word) {
            $word = trim($word);
            if (mb_strlen($word) >= 3) {
                $result['keywords'][] = $word;
            }
        }
        
        // Remove duplicates
        $result['keywords'] = array_unique($result['keywords']);
        $result['keywords'] = array_slice($result['keywords'], 0, 15);
        
        return $result;
    }
    
    /**
     * ค้นหาสินค้าจาก database
     */
    private function searchProducts(array $keywords, int $limit): array
    {
        if (empty($keywords)) {
            return $this->getPopularProducts($limit);
        }
        
        try {
            // สร้าง SQL conditions สำหรับ LIKE search
            $conditions = [];
            $likeParams = [];
            
            foreach ($keywords as $keyword) {
                $conditions[] = "(
                    name LIKE ? OR 
                    generic_name LIKE ? OR 
                    sku LIKE ? OR 
                    barcode LIKE ? OR 
                    description LIKE ? OR
                    usage_instructions LIKE ?
                )";
                $likeParam = "%{$keyword}%";
                $likeParams = array_merge($likeParams, [$likeParam, $likeParam, $likeParam, $likeParam, $likeParam, $likeParam]);
            }
            
            $whereClause = implode(' OR ', $conditions);
            
            // ใช้ business_items table - ไม่ filter ตาม line_account_id
            $sql = "SELECT 
                        id, name, price, sale_price, generic_name, usage_instructions, 
                        sku, barcode, description, stock, unit, image_url
                    FROM business_items 
                    WHERE is_active = 1 AND ({$whereClause})
                    ORDER BY stock DESC, name
                    LIMIT ?";
            
            $params = array_merge($likeParams, [$limit]);
            
            error_log("PharmacyRAG SQL: " . $sql);
            error_log("PharmacyRAG keywords: " . implode(', ', $keywords));
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            error_log("PharmacyRAG: Found " . count($products) . " products for keywords: " . implode(', ', $keywords));
            
            if (empty($products)) {
                return $this->getPopularProducts($limit);
            }
            
            return $products;
            
        } catch (\Exception $e) {
            error_log("PharmacyRAG searchProducts error: " . $e->getMessage());
            return $this->getPopularProducts($limit);
        }
    }
    
    /**
     * ดึงสินค้ายอดนิยม
     */
    private function getPopularProducts(int $limit): array
    {
        try {
            // ใช้ business_items table - ไม่ filter ตาม line_account_id
            $stmt = $this->db->prepare(
                "SELECT id, name, price, sale_price, generic_name, usage_instructions, 
                        sku, barcode, stock, unit, image_url
                 FROM business_items 
                 WHERE is_active = 1 AND stock > 0
                 ORDER BY stock DESC, name
                 LIMIT ?"
            );
            $stmt->execute([$limit]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("PharmacyRAG getPopularProducts error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * ดึงความรู้เกี่ยวกับยา
     */
    private function getDrugKnowledge(array $analysis): array
    {
        $knowledge = [];
        
        // ความรู้เกี่ยวกับอาการ
        foreach ($analysis['symptoms'] as $symptom) {
            $knowledge[] = $this->getSymptomKnowledge($symptom);
        }
        
        return array_filter($knowledge);
    }
    
    /**
     * ดึงความรู้เกี่ยวกับอาการ
     */
    private function getSymptomKnowledge(string $symptom): ?array
    {
        $knowledgeBase = [
            'ปวดหัว' => [
                'symptom' => 'ปวดหัว',
                'common_causes' => 'เครียด, นอนไม่พอ, ขาดน้ำ, ไมเกรน',
                'first_aid' => 'พักผ่อน, ดื่มน้ำ, ทานยาแก้ปวด',
                'warning_signs' => 'ปวดรุนแรงมาก, มีไข้สูง, คอแข็ง, ตาพร่ามัว',
                'recommended_drugs' => ['paracetamol 500mg', 'ibuprofen 400mg'],
                'dosage' => 'ทุก 4-6 ชม. ไม่เกิน 4 ครั้ง/วัน'
            ],
            'ไข้' => [
                'symptom' => 'ไข้',
                'common_causes' => 'ติดเชื้อ, หวัด, ไข้หวัดใหญ่',
                'first_aid' => 'เช็ดตัว, ดื่มน้ำเยอะ, พักผ่อน',
                'warning_signs' => 'ไข้สูงเกิน 39°C, ชัก, ซึม, หายใจลำบาก',
                'recommended_drugs' => ['paracetamol'],
                'dosage' => '10-15 mg/kg ทุก 4-6 ชม.'
            ],
            'ท้องเสีย' => [
                'symptom' => 'ท้องเสีย',
                'common_causes' => 'อาหารเป็นพิษ, ติดเชื้อ, กินอาหารไม่สะอาด',
                'first_aid' => 'ดื่มน้ำเกลือแร่, งดอาหารรสจัด',
                'warning_signs' => 'ถ่ายเป็นเลือด, ไข้สูง, ขาดน้ำรุนแรง',
                'recommended_drugs' => ['ORS เกลือแร่', 'Smecta', 'Imodium'],
                'dosage' => 'ORS ดื่มบ่อยๆ ทดแทนน้ำที่เสียไป'
            ],
            'หวัด' => [
                'symptom' => 'หวัด',
                'common_causes' => 'ติดเชื้อไวรัส, อากาศเปลี่ยน',
                'first_aid' => 'พักผ่อน, ดื่มน้ำอุ่น, กินอาหารอ่อน',
                'warning_signs' => 'ไข้สูงเกิน 3 วัน, หายใจลำบาก, เจ็บหน้าอก',
                'recommended_drugs' => ['Tiffy', 'Decolgen', 'Coldacin'],
                'dosage' => 'ตามฉลากยา วันละ 3-4 ครั้ง'
            ],
            'แพ้' => [
                'symptom' => 'แพ้/ลมพิษ',
                'common_causes' => 'แพ้อาหาร, แพ้ยา, แพ้ฝุ่น, แพ้อากาศ',
                'first_aid' => 'หลีกเลี่ยงสารก่อภูมิแพ้, ทายาแก้คัน',
                'warning_signs' => 'หายใจลำบาก, บวมที่ใบหน้า/ลำคอ, ช็อก',
                'recommended_drugs' => ['Cetirizine', 'Loratadine', 'Chlorpheniramine'],
                'dosage' => 'วันละ 1 เม็ด (Cetirizine/Loratadine)'
            ],
        ];
        
        return $knowledgeBase[$symptom] ?? null;
    }
    
    /**
     * Format ผลลัพธ์เป็น context สำหรับ AI
     */
    public function formatForAI(array $results): string
    {
        $context = "";
        
        // Products
        if (!empty($results['products'])) {
            $context .= "## สินค้ายาในร้านที่เกี่ยวข้อง:\n";
            foreach ($results['products'] as $p) {
                $sku = $p['sku'] ?? $p['barcode'] ?? '';
                $context .= "- {$p['name']}";
                if ($sku) $context .= " [SKU:{$sku}]";
                $context .= " ราคา {$p['price']} บาท";
                if (!empty($p['generic_name'])) {
                    $context .= " (ตัวยา: {$p['generic_name']})";
                }
                if (!empty($p['stock']) && $p['stock'] > 0) {
                    $context .= " มีสต็อก";
                }
                $context .= "\n";
                
                if (!empty($p['usage_instructions'])) {
                    $usage = mb_substr($p['usage_instructions'], 0, 100);
                    $context .= "  วิธีใช้: {$usage}\n";
                }
            }
        }
        
        // Knowledge
        if (!empty($results['knowledge'])) {
            $context .= "\n## ความรู้เกี่ยวกับอาการ:\n";
            foreach ($results['knowledge'] as $k) {
                if (!$k) continue;
                $context .= "อาการ: {$k['symptom']}\n";
                $context .= "- สาเหตุทั่วไป: {$k['common_causes']}\n";
                $context .= "- การดูแลเบื้องต้น: {$k['first_aid']}\n";
                $context .= "- ยาแนะนำ: " . implode(', ', $k['recommended_drugs']) . "\n";
                $context .= "- ขนาดยา: {$k['dosage']}\n";
                $context .= "- ⚠️ อาการที่ต้องพบแพทย์: {$k['warning_signs']}\n\n";
            }
        }
        
        return $context;
    }
    
    /**
     * ค้นหาสินค้าด้วย SKU โดยตรง
     */
    public function findBySku(string $sku): ?array
    {
        try {
            // ใช้ business_items table
            // ลองค้นหาตรงๆ ก่อน
            $stmt = $this->db->prepare(
                "SELECT * FROM business_items WHERE (sku = ? OR barcode = ?) AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$sku, $sku]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("PharmacyRAG findBySku: Found exact match for SKU {$sku}");
                return $result;
            }
            
            // ลองค้นหาแบบ LIKE (รองรับ SKU ที่มี leading zeros)
            $stmt = $this->db->prepare(
                "SELECT * FROM business_items WHERE (sku LIKE ? OR barcode LIKE ?) AND is_active = 1 LIMIT 1"
            );
            $stmt->execute(["%{$sku}%", "%{$sku}%"]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($result) {
                error_log("PharmacyRAG findBySku: Found LIKE match for SKU {$sku}");
                return $result;
            }
            
            // ลองค้นหาแบบตัดเลข 0 นำหน้า
            $skuNoZero = ltrim($sku, '0');
            if ($skuNoZero !== $sku) {
                $stmt = $this->db->prepare(
                    "SELECT * FROM business_items WHERE (sku LIKE ? OR barcode LIKE ?) AND is_active = 1 LIMIT 1"
                );
                $stmt->execute(["%{$skuNoZero}", "%{$skuNoZero}"]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($result) {
                    error_log("PharmacyRAG findBySku: Found match without leading zeros for SKU {$sku}");
                    return $result;
                }
            }
            
            error_log("PharmacyRAG findBySku: No match found for SKU {$sku}");
            return null;
        } catch (\Exception $e) {
            error_log("PharmacyRAG findBySku error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * ค้นหาสินค้าด้วยชื่อ - ปรับปรุงให้แม่นยำขึ้น
     */
    public function findByName(string $name, int $limit = 5): array
    {
        if (empty(trim($name))) return [];
        
        try {
            $searchName = trim($name);
            error_log("PharmacyRAG findByName: Searching for '{$searchName}'");
            
            // ใช้ business_items table
            // 1. ลองค้นหาตรงๆ ก่อน (exact match)
            $stmt = $this->db->prepare(
                "SELECT * FROM business_items 
                 WHERE is_active = 1 AND (name = ? OR generic_name = ?)
                 LIMIT 1"
            );
            $stmt->execute([$searchName, $searchName]);
            $exactMatch = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($exactMatch) {
                error_log("PharmacyRAG findByName: Found exact match: " . $exactMatch['name']);
                return [$exactMatch];
            }
            
            // 2. ค้นหาแบบขึ้นต้นด้วย (starts with) - แม่นยำกว่า LIKE %x%
            $stmt = $this->db->prepare(
                "SELECT *, 
                    CASE 
                        WHEN name LIKE ? THEN 1
                        WHEN name LIKE ? THEN 2
                        WHEN generic_name LIKE ? THEN 3
                        ELSE 4
                    END as relevance
                 FROM business_items 
                 WHERE is_active = 1 AND (
                    name LIKE ? OR 
                    name LIKE ? OR 
                    generic_name LIKE ? OR
                    generic_name LIKE ?
                 )
                 ORDER BY relevance, name
                 LIMIT ?"
            );
            $startsWithParam = $searchName . '%';
            $containsParam = '%' . $searchName . '%';
            $stmt->execute([
                $startsWithParam,  // relevance 1: name starts with
                $containsParam,    // relevance 2: name contains
                $startsWithParam,  // relevance 3: generic starts with
                $startsWithParam,  // WHERE: name starts with
                $containsParam,    // WHERE: name contains
                $startsWithParam,  // WHERE: generic starts with
                $containsParam,    // WHERE: generic contains
                $limit
            ]);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            error_log("PharmacyRAG findByName: Found " . count($results) . " results for '{$searchName}'");
            
            if (!empty($results)) {
                error_log("PharmacyRAG findByName: Best match: " . $results[0]['name']);
            }
            
            return $results;
        } catch (\Exception $e) {
            error_log("PharmacyRAG findByName error: " . $e->getMessage());
            return [];
        }
    }
    
    // ========== PUBLIC METHODS FOR FUNCTION CALLING ==========
    
    /**
     * ค้นหาสินค้าสำหรับ Function Calling
     * @param string $query คำค้นหา (ชื่อ, อาการ, หมวดหมู่)
     * @param string|null $category หมวดหมู่ (optional)
     * @param int $limit จำนวนผลลัพธ์
     * @return array
     */
    public function searchProductsForAI(string $query, ?string $category = null, int $limit = 10): array
    {
        error_log("PharmacyRAG searchProductsForAI: query='{$query}', category='{$category}', limit={$limit}");
        
        try {
            // ใช้ business_items table - ไม่ filter ตาม line_account_id
            // ถ้ามี category ให้ค้นหาตาม category ด้วย
            if ($category) {
                $stmt = $this->db->prepare(
                    "SELECT id, name, price, sale_price, generic_name, usage_instructions, 
                            sku, barcode, description, stock, unit, image_url, category_id
                     FROM business_items 
                     WHERE is_active = 1 
                       AND (name LIKE ? OR generic_name LIKE ? OR description LIKE ?)
                     ORDER BY stock DESC, name
                     LIMIT ?"
                );
                $searchParam = "%{$query}%";
                $stmt->execute([$searchParam, $searchParam, $searchParam, $limit]);
            } else {
                // ค้นหาปกติ
                $stmt = $this->db->prepare(
                    "SELECT id, name, price, sale_price, generic_name, usage_instructions, 
                            sku, barcode, description, stock, unit, image_url, category_id
                     FROM business_items 
                     WHERE is_active = 1 
                       AND (name LIKE ? OR generic_name LIKE ? OR description LIKE ? OR sku LIKE ?)
                     ORDER BY 
                        CASE 
                            WHEN name LIKE ? THEN 1
                            WHEN name LIKE ? THEN 2
                            WHEN generic_name LIKE ? THEN 3
                            ELSE 4
                        END,
                        stock DESC, name
                     LIMIT ?"
                );
                $exactParam = $query;
                $startsParam = $query . '%';
                $containsParam = '%' . $query . '%';
                $stmt->execute([
                    $containsParam, $containsParam, $containsParam, $containsParam,
                    $exactParam, $startsParam, $startsParam,
                    $limit
                ]);
            }
            
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            error_log("PharmacyRAG searchProductsForAI: Found " . count($products) . " products");
            
            // Format สำหรับ AI
            $result = [];
            foreach ($products as $p) {
                $result[] = [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'sku' => $p['sku'] ?? $p['barcode'] ?? '',
                    'price' => floatval($p['sale_price'] ?? $p['price']),
                    'original_price' => floatval($p['price']),
                    'generic_name' => $p['generic_name'] ?? '',
                    'usage' => $p['usage_instructions'] ?? '',
                    'stock' => intval($p['stock'] ?? 0),
                    'unit' => $p['unit'] ?? 'ชิ้น',
                    'in_stock' => intval($p['stock'] ?? 0) > 0
                ];
            }
            
            return $result;
            
        } catch (\Exception $e) {
            error_log("PharmacyRAG searchProductsForAI error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * ดึงรายละเอียดสินค้าด้วย SKU สำหรับ Function Calling
     */
    public function getProductDetailsBySku(string $sku): ?array
    {
        error_log("PharmacyRAG getProductDetailsBySku: sku='{$sku}'");
        
        $product = $this->findBySku($sku);
        
        if (!$product) {
            return null;
        }
        
        return [
            'id' => $product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'] ?? $product['barcode'] ?? '',
            'price' => floatval($product['sale_price'] ?? $product['price']),
            'original_price' => floatval($product['price']),
            'generic_name' => $product['generic_name'] ?? '',
            'usage' => $product['usage_instructions'] ?? '',
            'description' => $product['description'] ?? '',
            'stock' => intval($product['stock'] ?? 0),
            'unit' => $product['unit'] ?? 'ชิ้น',
            'in_stock' => intval($product['stock'] ?? 0) > 0,
            'image_url' => $product['image_url'] ?? ''
        ];
    }
    
    /**
     * ดึงสินค้าตามหมวดหมู่สำหรับ Function Calling
     */
    public function getProductsByCategory(string $category, int $limit = 10): array
    {
        error_log("PharmacyRAG getProductsByCategory: category='{$category}', limit={$limit}");
        
        try {
            // Map หมวดหมู่ภาษาไทยเป็น keywords
            $categoryKeywords = [
                'ยาแก้ปวด' => ['paracetamol', 'ibuprofen', 'aspirin', 'pain', 'ปวด'],
                'ยาแก้หวัด' => ['cold', 'flu', 'tiffy', 'decolgen', 'หวัด'],
                'ยาแก้ไอ' => ['cough', 'ไอ', 'bromhexine', 'dextromethorphan'],
                'ยาลดกรด' => ['antacid', 'omeprazole', 'กรด', 'gaviscon'],
                'วิตามิน' => ['vitamin', 'วิตามิน', 'supplement', 'อาหารเสริม'],
                'เวชสำอาง' => ['cosmetic', 'skincare', 'ครีม', 'โลชั่น', 'lotion'],
                'ยาแก้แพ้' => ['antihistamine', 'cetirizine', 'loratadine', 'แพ้'],
                'ยาทาภายนอก' => ['cream', 'ointment', 'ทา', 'balm'],
                'อุปกรณ์การแพทย์' => ['equipment', 'device', 'อุปกรณ์', 'พลาสเตอร์', 'ผ้าพันแผล'],
            ];
            
            $keywords = $categoryKeywords[$category] ?? [$category];
            
            // สร้าง SQL conditions
            $conditions = [];
            $params = [];
            foreach ($keywords as $kw) {
                $conditions[] = "(name LIKE ? OR generic_name LIKE ? OR description LIKE ?)";
                $kwParam = "%{$kw}%";
                $params = array_merge($params, [$kwParam, $kwParam, $kwParam]);
            }
            
            $whereClause = implode(' OR ', $conditions);
            $params[] = $limit;
            
            // ใช้ business_items table
            $sql = "SELECT id, name, price, sale_price, generic_name, usage_instructions, 
                           sku, barcode, stock, unit, image_url
                    FROM business_items 
                    WHERE is_active = 1 AND ({$whereClause})
                    ORDER BY stock DESC, name
                    LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            error_log("PharmacyRAG getProductsByCategory: Found " . count($products) . " products for '{$category}'");
            
            // Format สำหรับ AI
            $result = [];
            foreach ($products as $p) {
                $result[] = [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'sku' => $p['sku'] ?? $p['barcode'] ?? '',
                    'price' => floatval($p['sale_price'] ?? $p['price']),
                    'stock' => intval($p['stock'] ?? 0),
                    'unit' => $p['unit'] ?? 'ชิ้น',
                    'in_stock' => intval($p['stock'] ?? 0) > 0
                ];
            }
            
            return $result;
            
        } catch (\Exception $e) {
            error_log("PharmacyRAG getProductsByCategory error: " . $e->getMessage());
            return [];
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
            error_log("PharmacyRAG getTotalProductCount error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * ดึงหมวดหมู่สินค้าทั้งหมด
     */
    public function getAvailableCategories(): array
    {
        return [
            'ยาแก้ปวด',
            'ยาแก้หวัด', 
            'ยาแก้ไอ',
            'ยาลดกรด',
            'วิตามิน',
            'เวชสำอาง',
            'ยาแก้แพ้',
            'ยาทาภายนอก',
            'อุปกรณ์การแพทย์'
        ];
    }
}
