<?php
/**
 * Property-Based Test: Drug Interaction Detection
 * 
 * **Feature: vibe-selling-os-v2, Property 9: Drug Interaction Detection**
 * **Validates: Requirements 1.4, 7.2**
 * 
 * Property: For any set of drugs being recommended, the system should check for 
 * interactions with customer's current medications and flag any dangerous combinations.
 */

namespace Tests\VibeSelling;

use PHPUnit\Framework\TestCase;
use PDO;

class DrugInteractionDetectionPropertyTest extends TestCase
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
        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY, line_account_id INTEGER, name VARCHAR(255),
            drug_allergies TEXT, current_medications TEXT
        )");
        
        $this->db->exec("CREATE TABLE business_items (
            id INTEGER PRIMARY KEY, line_account_id INTEGER, name VARCHAR(255),
            sku VARCHAR(100), description TEXT, price DECIMAL(10,2) DEFAULT 0,
            stock INTEGER DEFAULT 100, is_active TINYINT(1) DEFAULT 1, category_id INTEGER
        )");
        
        $this->db->exec("CREATE TABLE item_categories (id INTEGER PRIMARY KEY, name VARCHAR(255))");

        $this->db->exec("CREATE TABLE drug_interactions (
            id INTEGER PRIMARY KEY, drug1_name VARCHAR(255), drug1_generic VARCHAR(255),
            drug2_name VARCHAR(255), drug2_generic VARCHAR(255), severity VARCHAR(50),
            description TEXT, recommendation TEXT
        )");
    }
    
    private function seedTestData(): void
    {
        // Add known drug interactions
        $interactions = [
            ['Warfarin', 'warfarin', 'Aspirin', 'aspirin', 'severe', 'เพิ่มความเสี่ยงเลือดออก', 'หลีกเลี่ยงการใช้ร่วมกัน'],
            ['Warfarin', 'warfarin', 'Ibuprofen', 'ibuprofen', 'severe', 'เพิ่มความเสี่ยงเลือดออก', 'หลีกเลี่ยงการใช้ร่วมกัน'],
            ['Metformin', 'metformin', 'Alcohol', 'alcohol', 'moderate', 'เพิ่มความเสี่ยง lactic acidosis', 'หลีกเลี่ยงแอลกอฮอล์'],
            ['Simvastatin', 'simvastatin', 'Grapefruit', 'grapefruit', 'moderate', 'เพิ่มระดับยาในเลือด', 'หลีกเลี่ยงเกรปฟรุต'],
            ['Lisinopril', 'lisinopril', 'Potassium', 'potassium', 'moderate', 'เพิ่มระดับโพแทสเซียม', 'ตรวจระดับโพแทสเซียม'],
        ];
        
        $stmt = $this->db->prepare("INSERT INTO drug_interactions 
            (drug1_name, drug1_generic, drug2_name, drug2_generic, severity, description, recommendation)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($interactions as $i) { $stmt->execute($i); }
        
        // Add test drugs
        $drugs = [
            ['Aspirin 300mg', 'aspirin', 35.00], ['Ibuprofen 400mg', 'ibuprofen nsaid', 45.00],
            ['Paracetamol 500mg', 'paracetamol', 30.00], ['Vitamin C', 'vitamin c', 80.00],
        ];
        $stmt = $this->db->prepare("INSERT INTO business_items 
            (line_account_id, name, description, price, stock, is_active) VALUES (?, ?, ?, ?, 100, 1)");
        foreach ($drugs as $d) { $stmt->execute([$this->lineAccountId, $d[0], $d[1], $d[2]]); }
    }
    
    private function createUserWithMedications(array $medications): int
    {
        $stmt = $this->db->prepare("INSERT INTO users (line_account_id, name, drug_allergies, current_medications)
            VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->lineAccountId, 'Test User', '[]', json_encode($medications)]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Property Test: Warfarin User Gets Interaction Warning for Aspirin
     * **Feature: vibe-selling-os-v2, Property 9** **Validates: Requirements 1.4, 7.2**
     */
    public function testWarfarinUserGetsInteractionWarningForAspirin(): void
    {
        $userId = $this->createUserWithMedications(['Warfarin']);
        
        // Check interaction with Aspirin (drug ID 1)
        $result = $this->service->checkInteractions([1], $userId);
        
        $this->assertTrue($result['hasInteractions'], "Should detect Warfarin-Aspirin interaction");
        $this->assertEquals('severe', $result['severity'], "Warfarin-Aspirin interaction should be severe");
        $this->assertNotEmpty($result['interactions']);
    }

    /**
     * Property Test: User Without Interacting Medications Gets No Warning
     */
    public function testUserWithoutInteractingMedicationsGetsNoWarning(): void
    {
        $userId = $this->createUserWithMedications(['Vitamin D']);
        
        // Check Paracetamol (drug ID 3) - no known interactions with Vitamin D
        $result = $this->service->checkInteractions([3], $userId);
        
        $this->assertFalse($result['hasInteractions'], "Should not detect interaction for safe combination");
    }

    /**
     * Property Test: Multiple Drug Interactions All Detected
     */
    public function testMultipleDrugInteractionsAllDetected(): void
    {
        $userId = $this->createUserWithMedications(['Warfarin']);
        
        // Check both Aspirin (1) and Ibuprofen (2) - both interact with Warfarin
        $result = $this->service->checkInteractions([1, 2], $userId);
        
        $this->assertTrue($result['hasInteractions']);
        $this->assertGreaterThanOrEqual(2, count($result['interactions']), 
            "Should detect multiple interactions with Warfarin");
    }

    /**
     * Property Test: Interaction Check Returns Required Fields
     */
    public function testInteractionCheckReturnsRequiredFields(): void
    {
        $userId = $this->createUserWithMedications(['Warfarin']);
        $result = $this->service->checkInteractions([1], $userId);
        
        $this->assertArrayHasKey('hasInteractions', $result);
        $this->assertArrayHasKey('interactions', $result);
        $this->assertArrayHasKey('severity', $result);
        $this->assertArrayHasKey('checkedDrugs', $result);
        $this->assertArrayHasKey('currentMedications', $result);
    }

    /**
     * Property Test: Severity Correctly Identified
     */
    public function testSeverityCorrectlyIdentified(): void
    {
        $userId = $this->createUserWithMedications(['Warfarin']);
        $result = $this->service->checkInteractions([1], $userId);
        
        $this->assertContains($result['severity'], ['mild', 'moderate', 'severe', 'contraindicated'],
            "Severity should be a valid level");
    }
}
