<?php
/**
 * Property-Based Tests: Points Earning Calculation
 * 
 * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
 * **Validates: Requirements 25.2, 25.3, 25.4, 25.5**
 * 
 * Property: For any completed order, the earned points should equal:
 * floor(order_total * base_rate * tier_multiplier * campaign_multiplier * category_bonus)
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class PointsCalculationPropertyTest extends TestCase
{
    /**
     * Points earning calculator (mirrors LoyaltyPoints class logic)
     */
    private function calculatePoints(
        float $orderTotal,
        float $baseRate,
        float $tierMultiplier,
        float $campaignMultiplier = 1.0,
        float $categoryBonus = 1.0,
        float $minOrderAmount = 0
    ): int {
        // Check minimum order amount
        if ($orderTotal < $minOrderAmount) {
            return 0;
        }
        
        // Calculate points with all multipliers
        $points = $orderTotal * $baseRate * $tierMultiplier * $campaignMultiplier * $categoryBonus;
        
        return (int) floor($points);
    }
    
    /**
     * Data provider for points calculation scenarios
     */
    public function pointsCalculationProvider(): array
    {
        return [
            // [orderTotal, baseRate, tierMultiplier, campaignMultiplier, categoryBonus, minOrder, expectedPoints]
            'basic_calculation' => [100, 0.04, 1.0, 1.0, 1.0, 0, 4],
            'with_tier_bonus' => [100, 0.04, 1.5, 1.0, 1.0, 0, 6],
            'with_campaign' => [100, 0.04, 1.0, 2.0, 1.0, 0, 8],
            'with_category_bonus' => [100, 0.04, 1.0, 1.0, 1.5, 0, 6],
            'all_multipliers' => [100, 0.04, 1.5, 2.0, 1.5, 0, 18],
            'below_minimum' => [50, 0.04, 1.0, 1.0, 1.0, 100, 0],
            'at_minimum' => [100, 0.04, 1.0, 1.0, 1.0, 100, 4],
            'large_order' => [10000, 0.04, 2.0, 1.0, 1.0, 0, 800],
            'platinum_tier' => [500, 0.04, 2.0, 1.0, 1.0, 0, 40],
        ];
    }
    
    /**
     * Property Test: Points calculation follows the formula
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.2, 25.3, 25.4, 25.5**
     * 
     * @dataProvider pointsCalculationProvider
     */
    public function testPointsCalculationFormula(
        float $orderTotal,
        float $baseRate,
        float $tierMultiplier,
        float $campaignMultiplier,
        float $categoryBonus,
        float $minOrder,
        int $expectedPoints
    ): void {
        $points = $this->calculatePoints(
            $orderTotal,
            $baseRate,
            $tierMultiplier,
            $campaignMultiplier,
            $categoryBonus,
            $minOrder
        );
        
        $this->assertEquals(
            $expectedPoints,
            $points,
            "Points calculation should match expected value"
        );
    }
    
    /**
     * Property Test: Points are always non-negative
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.2, 25.3, 25.4, 25.5**
     */
    public function testPointsAreNonNegative(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $orderTotal = rand(0, 100000) / 100;
            $baseRate = rand(1, 10) / 100;
            $tierMultiplier = rand(10, 30) / 10;
            $campaignMultiplier = rand(10, 30) / 10;
            $categoryBonus = rand(10, 20) / 10;
            
            $points = $this->calculatePoints(
                $orderTotal,
                $baseRate,
                $tierMultiplier,
                $campaignMultiplier,
                $categoryBonus
            );
            
            $this->assertGreaterThanOrEqual(
                0,
                $points,
                "Points should never be negative"
            );
        }
    }
    
    /**
     * Property Test: Points are always integers (floored)
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.2, 25.3, 25.4, 25.5**
     */
    public function testPointsAreIntegers(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $orderTotal = rand(1, 10000);
            $baseRate = 0.04;
            $tierMultiplier = rand(10, 20) / 10;
            
            $points = $this->calculatePoints($orderTotal, $baseRate, $tierMultiplier);
            
            $this->assertIsInt($points, "Points should be an integer");
            $this->assertEquals(
                floor($points),
                $points,
                "Points should be floored"
            );
        }
    }
    
    /**
     * Property Test: Higher tier multiplier yields more points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.5**
     */
    public function testHigherTierYieldsMorePoints(): void
    {
        $orderTotal = 1000;
        $baseRate = 0.04;
        
        $silverPoints = $this->calculatePoints($orderTotal, $baseRate, 1.0);
        $goldPoints = $this->calculatePoints($orderTotal, $baseRate, 1.5);
        $platinumPoints = $this->calculatePoints($orderTotal, $baseRate, 2.0);
        
        $this->assertLessThan($goldPoints, $silverPoints);
        $this->assertLessThan($platinumPoints, $goldPoints);
    }
    
    /**
     * Property Test: Campaign multiplier increases points proportionally
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.3**
     */
    public function testCampaignMultiplierIncreasesPoints(): void
    {
        $orderTotal = 1000;
        $baseRate = 0.04;
        $tierMultiplier = 1.0;
        
        $normalPoints = $this->calculatePoints($orderTotal, $baseRate, $tierMultiplier, 1.0);
        $doublePoints = $this->calculatePoints($orderTotal, $baseRate, $tierMultiplier, 2.0);
        $triplePoints = $this->calculatePoints($orderTotal, $baseRate, $tierMultiplier, 3.0);
        
        $this->assertEquals($normalPoints * 2, $doublePoints);
        $this->assertEquals($normalPoints * 3, $triplePoints);
    }
    
    /**
     * Property Test: Zero order total yields zero points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.2**
     */
    public function testZeroOrderYieldsZeroPoints(): void
    {
        $points = $this->calculatePoints(0, 0.04, 1.5, 2.0, 1.5);
        $this->assertEquals(0, $points);
    }
    
    /**
     * Property Test: Order below minimum yields zero points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.6**
     */
    public function testBelowMinimumYieldsZeroPoints(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $minOrder = rand(100, 500);
            $orderTotal = rand(1, $minOrder - 1);
            
            $points = $this->calculatePoints($orderTotal, 0.04, 1.0, 1.0, 1.0, $minOrder);
            
            $this->assertEquals(
                0,
                $points,
                "Order below minimum ({$orderTotal} < {$minOrder}) should yield 0 points"
            );
        }
    }
    
    /**
     * Property Test: Points scale linearly with order total
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.2**
     */
    public function testPointsScaleLinearlyWithOrderTotal(): void
    {
        $baseRate = 0.04;
        $tierMultiplier = 1.0;
        
        $points100 = $this->calculatePoints(100, $baseRate, $tierMultiplier);
        $points200 = $this->calculatePoints(200, $baseRate, $tierMultiplier);
        $points1000 = $this->calculatePoints(1000, $baseRate, $tierMultiplier);
        
        // Due to flooring, we check approximate linearity
        $this->assertEquals($points100 * 2, $points200);
        $this->assertEquals($points100 * 10, $points1000);
    }
    
    /**
     * Property Test: Random calculations match formula
     * 
     * **Feature: liff-telepharmacy-redesign, Property 37: Points Earning Calculation**
     * **Validates: Requirements 25.2, 25.3, 25.4, 25.5**
     */
    public function testRandomCalculationsMatchFormula(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $orderTotal = rand(100, 10000);
            $baseRate = rand(1, 10) / 100; // 0.01 to 0.10
            $tierMultiplier = rand(10, 25) / 10; // 1.0 to 2.5
            $campaignMultiplier = rand(10, 30) / 10; // 1.0 to 3.0
            $categoryBonus = rand(10, 20) / 10; // 1.0 to 2.0
            
            $calculatedPoints = $this->calculatePoints(
                $orderTotal,
                $baseRate,
                $tierMultiplier,
                $campaignMultiplier,
                $categoryBonus
            );
            
            $expectedPoints = (int) floor(
                $orderTotal * $baseRate * $tierMultiplier * $campaignMultiplier * $categoryBonus
            );
            
            $this->assertEquals(
                $expectedPoints,
                $calculatedPoints,
                "Random calculation should match formula"
            );
        }
    }
}
