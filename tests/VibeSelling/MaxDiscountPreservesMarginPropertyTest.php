<?php
/**
 * Property-Based Test: Maximum Discount Preserves Minimum Margin
 * 
 * **Feature: vibe-selling-os-v2, Property 2: Maximum Discount Preserves Minimum Margin**
 * **Validates: Requirements 3.2**
 * 
 * Property: For any drug and minimum margin threshold, the calculated maximum discount 
 * should result in a final price that maintains at least the minimum margin percentage.
 */

namespace Tests\VibeSelling;

use PHPUnit\Framework\TestCase;
use PDO;

class MaxDiscountPreservesMarginPropertyTest extends TestCase
{
    private $db;
    private $lineAccountId = 1;
    private $service;
    
    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
        
        require_once __DIR__ . '/../../classes/DrugPricingEngineService.php';
        $this->service = new \DrugPricingEngineService($this->db, $this->lineAccountId);
    }
    
    private function createTables(): void
    {
        $this->db->exec("
            CREATE TABLE business_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_account_id INTEGER,
                name VARCHAR(255),
                sku VARCHAR(100),
                price DECIMAL(10,2) DEFAULT 0,
                sale_price DECIMAL(10,2),
                cost_price DECIMAL(10,2) DEFAULT 0,
                stock INTEGER DEFAULT 0
            )
        ");
    }

    private function createTestDrug(float $costPrice, float $sellingPrice): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO business_items (line_account_id, name, sku, price, cost_price, stock)
            VALUES (?, ?, ?, ?, ?, 100)
        ");
        $stmt->execute([
            $this->lineAccountId,
            'Test Drug ' . uniqid(),
            'SKU-' . rand(1000, 9999),
            $sellingPrice,
            $costPrice
        ]);
        return (int)$this->db->lastInsertId();
    }
    
    private function generateRandomPricing(): array
    {
        $cost = round(rand(100, 100000) / 100, 2);
        $marginMultiplier = 1 + (rand(20, 200) / 100);
        $price = round($cost * $marginMultiplier, 2);
        return ['cost' => $cost, 'price' => $price];
    }

    /**
     * Property Test: Maximum Discount Preserves Minimum Margin
     * 
     * **Feature: vibe-selling-os-v2, Property 2**
     * **Validates: Requirements 3.2**
     */
    public function testMaxDiscountPreservesMinimumMargin(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $pricing = $this->generateRandomPricing();
            $cost = $pricing['cost'];
            $price = $pricing['price'];
            
            // Random minimum margin between 5% and 30%
            $minMarginPercent = rand(5, 30);
            
            $drugId = $this->createTestDrug($cost, $price);
            
            // Act: Get max discount
            $result = $this->service->getMaxDiscount($drugId, $minMarginPercent);
            
            // Calculate final price after max discount
            $finalPrice = $result['currentPrice'] - $result['maxDiscount'];
            
            // Calculate actual margin at final price
            $actualMarginPercent = $finalPrice > 0 
                ? (($finalPrice - $cost) / $finalPrice) * 100 
                : 0;
            
            // Assert: Final price should maintain at least minimum margin
            $this->assertGreaterThanOrEqual(
                $minMarginPercent - 0.01, // Allow tiny floating point tolerance
                $actualMarginPercent,
                "Max discount should preserve minimum margin of {$minMarginPercent}% on iteration {$i}. " .
                "Cost: {$cost}, Price: {$price}, MaxDiscount: {$result['maxDiscount']}, " .
                "FinalPrice: {$finalPrice}, ActualMargin: " . round($actualMarginPercent, 2) . "%"
            );
            
            // Assert: Floor price should equal final price
            $this->assertEqualsWithDelta(
                $result['floorPrice'],
                $finalPrice,
                0.01,
                "Floor price should equal price after max discount on iteration {$i}"
            );
        }
    }

    /**
     * Property Test: Max Discount Never Exceeds Price Minus Floor
     */
    public function testMaxDiscountNeverExceedsPriceMinusFloor(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $pricing = $this->generateRandomPricing();
            $drugId = $this->createTestDrug($pricing['cost'], $pricing['price']);
            
            $minMarginPercent = rand(5, 25);
            $result = $this->service->getMaxDiscount($drugId, $minMarginPercent);
            
            $expectedMaxDiscount = max(0, $result['currentPrice'] - $result['floorPrice']);
            
            $this->assertEqualsWithDelta(
                $expectedMaxDiscount,
                $result['maxDiscount'],
                0.01,
                "Max discount should equal currentPrice - floorPrice on iteration {$i}"
            );
        }
    }

    /**
     * Property Test: Zero Margin Drug Has Zero Max Discount
     */
    public function testZeroMarginDrugHasZeroMaxDiscount(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $cost = round(rand(100, 10000) / 100, 2);
            $price = $cost; // Zero margin
            
            $drugId = $this->createTestDrug($cost, $price);
            $result = $this->service->getMaxDiscount($drugId, 10.0);
            
            // When price equals cost, no discount is possible while maintaining margin
            $this->assertEquals(
                0.0,
                $result['maxDiscount'],
                "Zero margin drug should have zero max discount on iteration {$i}"
            );
        }
    }

    /**
     * Property Test: High Margin Drug Allows Higher Discount
     */
    public function testHighMarginDrugAllowsHigherDiscount(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $cost = round(rand(100, 5000) / 100, 2);
            
            // Low margin drug (20% markup)
            $lowMarginPrice = round($cost * 1.2, 2);
            $lowMarginDrugId = $this->createTestDrug($cost, $lowMarginPrice);
            
            // High margin drug (100% markup)
            $highMarginPrice = round($cost * 2.0, 2);
            $highMarginDrugId = $this->createTestDrug($cost, $highMarginPrice);
            
            $minMargin = 10.0;
            
            $lowResult = $this->service->getMaxDiscount($lowMarginDrugId, $minMargin);
            $highResult = $this->service->getMaxDiscount($highMarginDrugId, $minMargin);
            
            // High margin drug should allow higher absolute discount
            $this->assertGreaterThanOrEqual(
                $lowResult['maxDiscount'],
                $highResult['maxDiscount'],
                "High margin drug should allow >= discount than low margin drug on iteration {$i}"
            );
        }
    }
}
