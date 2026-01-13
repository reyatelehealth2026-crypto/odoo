<?php
/**
 * Property-Based Test: Allergy Check Before Recommendation
 * 
 * **Feature: vibe-selling-os-v2, Property 8: Allergy Check Before Recommendation**
 * **Validates: Requirements 2.6, 7.2**
 * 
 * Property: For any drug recommendation, the system should check against customer's 
 * allergy list and exclude drugs that match any allergy.
 */

namespace Tests\VibeSelling;

use PHPUnit\Framework\TestCase;
use PDO;

class AllergyCheckBeforeRecommendationPropertyTest extends TestCase
{
    private $db;
    private $lineAccountId = 1;
    private $service;
    
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
        $this->seedTestData();
        
        require_once __DIR__ . '/../../classes/DrugRecommendEngineService.php';
        $this->service = new \DrugRecommendEngineService($this->db, $this->lineAccountId);
    }
    
    private function createTables(): void
    {
        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                name VARCHAR(255),
                drug_allergies TEXT,
                current_medications TEXT
            )
        ");
        
        $this->db->exec("
            CREATE TABLE business_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                name VARCHAR(255),
                sku VARCHAR(100),
                description TEXT,
                price DECIMAL(10,2) DEFAULT 0,
                sale_price DECIMAL(10,2),
                cost_price DECIMAL(10,2) DEFAULT 0,
                stock INTEGER DEFAULT 100,
                is_active TINYINT(1) DEFAULT 1,
                category_id INTEGER
            )
        ");
        
        $this->db->exec("
            CREATE TABLE item_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255)
            )
        ");
        
        $this->db->exec("
            CREATE TABLE drug_interactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                drug1_name VARCHAR(255),
                drug1_generic VARCHAR(255),
                drug2_name VARCHAR(255),
                drug2_generic VARCHAR(255),
                severity VARCHAR(50),
                description TEXT,
                recommendation TEXT
            )
        ");
    }
    
    private function seedTestData(): void
    {
        // Add categories
        $this->db->exec("INSERT INTO item_categories (name) VALUES ('ยาแก้ปวด'), ('ยาแก้แพ้'), ('วิตามิน')");
        
        // Add test drugs
        $drugs = [
            ['Paracetamol 500mg', 'paracetamol', 'ยาแก้ปวดลดไข้ paracetamol', 35.00, 100],
            ['Ibuprofen 400mg', 'ibuprofen nsaid', 'ยาแก้ปวด ibuprofen', 45.00, 50],
            ['Aspirin 300mg', 'aspirin nsaid', 'ยาแก้ปวด aspirin', 25.00, 80],
            ['Loratadine 10mg', 'loratadine antihistamine', 'ยาแก้แพ้ loratadine', 55.00, 60],
            ['Cetirizine 10mg', 'cetirizine antihistamine', 'ยาแก้แพ้ cetirizine', 40.00, 70],
            ['Vitamin C 1000mg', 'vitamin c ascorbic', 'วิตามินซี', 120.00, 200],
            ['Amoxicillin 500mg', 'amoxicillin penicillin', 'ยาปฏิชีวนะ amoxicillin', 85.00, 40],
            ['Diclofenac 50mg', 'diclofenac nsaid', 'ยาแก้ปวด diclofenac', 65.00, 30],
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO business_items (line_account_id, name, sku, description, price, stock, is_active, category_id)
            VALUES (?, ?, ?, ?, ?, ?, 1, 1)
        ");
        
        foreach ($drugs as $i => $drug) {
            $stmt->execute([$this->lineAccountId, $drug[0], 'SKU-' . ($i + 1), $drug[2], $drug[3], $drug[4]]);
        }
    }
    
    private function createUserWithAllergies(array $allergies): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (line_account_id, name, drug_allergies, current_medications)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->lineAccountId,
            'Test User ' . uniqid(),
            json_encode($allergies),
            json_encode([])
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Property Test: Recommendations Exclude Allergic Drugs
     * 
     * **Feature: vibe-selling-os-v2, Property 8**
     * **Validates: Requirements 2.6, 7.2**
     */
    public function testRecommendationsExcludeAllergicDrugs(): void
    {
        // Test with NSAID allergy
        $userId = $this->createUserWithAllergies(['nsaid', 'ibuprofen']);
        
        $result = $this->service->getForSymptoms(['ปวดหัว'], $userId, 10);
        
        // Assert: No NSAID drugs should be recommended
        foreach ($result['recommendations'] as $drug) {
            $drugNameLower = strtolower($drug['name']);
            $this->assertStringNotContainsStringIgnoringCase(
                'ibuprofen',
                $drugNameLower,
                "Ibuprofen should not be recommended to user allergic to NSAIDs"
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'aspirin',
                $drugNameLower,
                "Aspirin should not be recommended to user allergic to NSAIDs"
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'diclofenac',
                $drugNameLower,
                "Diclofenac should not be recommended to user allergic to NSAIDs"
            );
        }
    }

    /**
     * Property Test: Penicillin Allergy Excludes Amoxicillin
     */
    public function testPenicillinAllergyExcludesAmoxicillin(): void
    {
        $userId = $this->createUserWithAllergies(['penicillin', 'amoxicillin']);
        
        $result = $this->service->getForSymptoms(['ติดเชื้อ', 'เจ็บคอ'], $userId, 10);
        
        foreach ($result['recommendations'] as $drug) {
            $this->assertStringNotContainsStringIgnoringCase(
                'amoxicillin',
                $drug['name'],
                "Amoxicillin should not be recommended to user allergic to penicillin"
            );
        }
    }

    /**
     * Property Test: User Without Allergies Gets Full Recommendations
     */
    public function testUserWithoutAllergiesGetsFullRecommendations(): void
    {
        $userId = $this->createUserWithAllergies([]);
        
        $result = $this->service->getForSymptoms(['ปวดหัว'], $userId, 10);
        
        // Should get recommendations (may include NSAIDs)
        $this->assertNotEmpty(
            $result['recommendations'],
            "User without allergies should get recommendations"
        );
    }

    /**
     * Property Test: Multiple Allergies All Checked
     */
    public function testMultipleAllergiesAllChecked(): void
    {
        // User allergic to multiple drug classes
        $userId = $this->createUserWithAllergies(['nsaid', 'penicillin', 'aspirin']);
        
        $result = $this->service->getForSymptoms(['ปวดหัว', 'ไข้'], $userId, 10);
        
        foreach ($result['recommendations'] as $drug) {
            $drugNameLower = strtolower($drug['name']);
            $drugDesc = strtolower($drug['genericName'] ?? '');
            
            // None of the allergic drugs should appear
            $this->assertStringNotContainsStringIgnoringCase('aspirin', $drugNameLower);
            $this->assertStringNotContainsStringIgnoringCase('ibuprofen', $drugNameLower);
            $this->assertStringNotContainsStringIgnoringCase('amoxicillin', $drugNameLower);
        }
    }

    /**
     * Property Test: Allergy Check Count Reported
     */
    public function testAllergyCheckCountReported(): void
    {
        $allergies = ['nsaid', 'penicillin', 'sulfa'];
        $userId = $this->createUserWithAllergies($allergies);
        
        $result = $this->service->getForSymptoms(['ปวดหัว'], $userId, 5);
        
        // Result should report how many allergies were checked
        $this->assertArrayHasKey('allergiesChecked', $result);
        $this->assertEquals(
            count($allergies),
            $result['allergiesChecked'],
            "Should report correct number of allergies checked"
        );
    }

    /**
     * Property Test: Safe Alternatives Still Recommended
     */
    public function testSafeAlternativesStillRecommended(): void
    {
        // User allergic to NSAIDs
        $userId = $this->createUserWithAllergies(['nsaid', 'ibuprofen', 'aspirin']);
        
        $result = $this->service->getForSymptoms(['ปวดหัว', 'ไข้'], $userId, 10);
        
        // Paracetamol should still be recommended (not an NSAID)
        $hasParacetamol = false;
        foreach ($result['recommendations'] as $drug) {
            if (stripos($drug['name'], 'paracetamol') !== false) {
                $hasParacetamol = true;
                break;
            }
        }
        
        $this->assertTrue(
            $hasParacetamol,
            "Paracetamol (safe alternative) should be recommended to NSAID-allergic user"
        );
    }
}
