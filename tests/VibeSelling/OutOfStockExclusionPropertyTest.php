<?php
/**
 * Property-Based Test: Recommendations Exclude Out-of-Stock Drugs
 * 
 * **Feature: vibe-selling-os-v2, Property 10: Recommendations Exclude Out-of-Stock Drugs**
 * **Validates: Requirements 10.2**
 * 
 * Property: For any drug recommendation, the recommended drugs should have 
 * stock quantity greater than zero.
 */

namespace Tests\VibeSelling;

use PHPUnit\Framework\TestCase;
use PDO;

class OutOfStockExclusionPropertyTest extends TestCase
{
    private $db;
    private $lineAccountId = 1;
    private $service;
    
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
        
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
            stock INTEGER DEFAULT 0, is_active TINYINT(1) DEFAULT 1, category_id INTEGER
        )");
        $this->db->exec("CREATE TABLE item_categories (id INTEGER PRIMARY KEY, name VARCHAR(255))");
        $this->db->exec("CREATE TABLE drug_interactions (
            id INTEGER PRIMARY KEY, drug1_name VARCHAR(255), drug1_generic VARCHAR(255),
            drug2_name VARCHAR(255), drug2_generic VARCHAR(255), severity VARCHAR(50),
            description TEXT, recommendation TEXT
        )");
    }

    private function createTestUser(): int
    {
        $stmt = $this->db->prepare("INSERT INTO users (line_account_id, name, drug_allergies, current_medications)
            VALUES (?, 'Test User', '[]', '[]')");
        $stmt->execute([$this->lineAccountId]);
        return (int)$this->db->lastInsertId();
    }
    
    private function createDrug(string $name, string $desc, int $stock): int
    {
        $stmt = $this->db->prepare("INSERT INTO business_items 
            (line_account_id, name, description, price, stock, is_active) VALUES (?, ?, ?, 50.00, ?, 1)");
        $stmt->execute([$this->lineAccountId, $name, $desc, $stock]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Property Test: Out-of-Stock Drugs Not Recommended
     * **Feature: vibe-selling-os-v2, Property 10** **Validates: Requirements 10.2**
     */
    public function testOutOfStockDrugsNotRecommended(): void
    {
        // Create drugs with varying stock levels
        $this->createDrug('Paracetamol 500mg (Out)', 'paracetamol ยาแก้ปวด', 0);
        $this->createDrug('Paracetamol 500mg (In Stock)', 'paracetamol ยาแก้ปวด', 100);
        $this->createDrug('Ibuprofen 400mg (Out)', 'ibuprofen ยาแก้ปวด', 0);
        $this->createDrug('Ibuprofen 400mg (In Stock)', 'ibuprofen ยาแก้ปวด', 50);
        
        $userId = $this->createTestUser();
        $result = $this->service->getForSymptoms(['ปวดหัว'], $userId, 10);
        
        // Assert: All recommended drugs should have stock > 0
        foreach ($result['recommendations'] as $drug) {
            $this->assertGreaterThan(0, $drug['stock'],
                "Recommended drug '{$drug['name']}' should have stock > 0");
            $this->assertStringNotContainsString('(Out)', $drug['name'],
                "Out-of-stock drugs should not be recommended");
        }
    }

    /**
     * Property Test: In-Stock Drugs Are Recommended
     */
    public function testInStockDrugsAreRecommended(): void
    {
        $this->createDrug('Vitamin C 1000mg', 'vitamin c วิตามิน', 200);
        
        $userId = $this->createTestUser();
        $result = $this->service->getForSymptoms(['วิตามิน'], $userId, 5);
        
        $this->assertNotEmpty($result['recommendations'], "In-stock drugs should be recommended");
        
        foreach ($result['recommendations'] as $drug) {
            $this->assertGreaterThan(0, $drug['stock']);
        }
    }

    /**
     * Property Test: All Out-of-Stock Returns Empty Recommendations
     */
    public function testAllOutOfStockReturnsEmptyRecommendations(): void
    {
        // Create only out-of-stock drugs
        $this->createDrug('Paracetamol 500mg', 'paracetamol ยาแก้ปวด', 0);
        $this->createDrug('Ibuprofen 400mg', 'ibuprofen ยาแก้ปวด', 0);
        
        $userId = $this->createTestUser();
        $result = $this->service->getForSymptoms(['ปวดหัว'], $userId, 10);
        
        // Should return empty or only in-stock items
        foreach ($result['recommendations'] as $drug) {
            $this->assertGreaterThan(0, $drug['stock'],
                "Should never recommend out-of-stock drugs");
        }
    }

    /**
     * Property Test: Stock Level Correctly Reported
     */
    public function testStockLevelCorrectlyReported(): void
    {
        $expectedStock = rand(10, 500);
        $this->createDrug('Test Drug', 'paracetamol test', $expectedStock);
        
        $userId = $this->createTestUser();
        $result = $this->service->getForSymptoms(['paracetamol'], $userId, 5);
        
        if (!empty($result['recommendations'])) {
            $drug = $result['recommendations'][0];
            $this->assertEquals($expectedStock, $drug['stock'],
                "Stock level should be correctly reported");
        }
    }

    /**
     * Property Test: Random Stock Levels Property
     */
    public function testRandomStockLevelsProperty(): void
    {
        // Create drugs with random stock levels
        for ($i = 0; $i < 20; $i++) {
            $stock = rand(0, 100);
            $this->createDrug("Drug {$i}", "paracetamol test drug {$i}", $stock);
        }
        
        $userId = $this->createTestUser();
        $result = $this->service->getForSymptoms(['paracetamol'], $userId, 50);
        
        // Property: ALL recommendations must have stock > 0
        foreach ($result['recommendations'] as $drug) {
            $this->assertGreaterThan(0, $drug['stock'],
                "Property violated: Drug '{$drug['name']}' has stock {$drug['stock']} <= 0");
        }
    }
}
