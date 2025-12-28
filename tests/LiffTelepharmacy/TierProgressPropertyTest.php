<?php
/**
 * Property-Based Tests: Tier Progress and Threshold
 * 
 * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
 * **Feature: liff-telepharmacy-redesign, Property 38: Tier Threshold Progression**
 * **Validates: Requirements 5.4, 25.8**
 * 
 * Properties:
 * - For any tier progress bar displayed, the progress percentage should be between 0 and 100 inclusive.
 * - For any tier configuration, the thresholds should be in ascending order.
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class TierProgressPropertyTest extends TestCase
{
    /**
     * Default tier configuration
     */
    private $defaultTiers = [
        ['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0],
        ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5],
        ['name' => 'Platinum', 'min_points' => 5000, 'multiplier' => 2.0],
    ];
    
    /**
     * Calculate tier progress percentage
     */
    private function calculateTierProgress(int $currentPoints, array $tiers): float
    {
        // Find current tier and next tier
        $currentTier = null;
        $nextTier = null;
        
        for ($i = count($tiers) - 1; $i >= 0; $i--) {
            if ($currentPoints >= $tiers[$i]['min_points']) {
                $currentTier = $tiers[$i];
                $nextTier = $tiers[$i + 1] ?? null;
                break;
            }
        }
        
        // If at highest tier, return 100%
        if ($nextTier === null) {
            return 100.0;
        }
        
        // Calculate progress to next tier
        $pointsInCurrentTier = $currentPoints - $currentTier['min_points'];
        $pointsNeededForNextTier = $nextTier['min_points'] - $currentTier['min_points'];
        
        if ($pointsNeededForNextTier <= 0) {
            return 100.0;
        }
        
        $progress = ($pointsInCurrentTier / $pointsNeededForNextTier) * 100;
        
        // Clamp between 0 and 100
        return max(0, min(100, $progress));
    }
    
    /**
     * Validate tier thresholds are in ascending order
     */
    private function validateTierThresholds(array $tiers): bool
    {
        $prevPoints = -1;
        
        foreach ($tiers as $tier) {
            if ($tier['min_points'] <= $prevPoints && $prevPoints >= 0) {
                return false;
            }
            $prevPoints = $tier['min_points'];
        }
        
        return true;
    }
    
    /**
     * Get user's current tier based on points
     */
    private function getCurrentTier(int $points, array $tiers): array
    {
        $currentTier = $tiers[0];
        
        foreach ($tiers as $tier) {
            if ($points >= $tier['min_points']) {
                $currentTier = $tier;
            }
        }
        
        return $currentTier;
    }
    
    /**
     * Property Test: Tier progress is always between 0 and 100
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     */
    public function testTierProgressBounds(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $points = rand(0, 100000);
            $progress = $this->calculateTierProgress($points, $this->defaultTiers);
            
            $this->assertGreaterThanOrEqual(
                0,
                $progress,
                "Progress should be >= 0 for {$points} points"
            );
            
            $this->assertLessThanOrEqual(
                100,
                $progress,
                "Progress should be <= 100 for {$points} points"
            );
        }
    }
    
    /**
     * Property Test: Progress at tier boundary is 0%
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     */
    public function testProgressAtTierBoundaryIsZero(): void
    {
        // At Silver (0 points)
        $progress = $this->calculateTierProgress(0, $this->defaultTiers);
        $this->assertEquals(0, $progress);
        
        // At Gold (2000 points)
        $progress = $this->calculateTierProgress(2000, $this->defaultTiers);
        $this->assertEquals(0, $progress);
        
        // At Platinum (5000 points) - highest tier, should be 100%
        $progress = $this->calculateTierProgress(5000, $this->defaultTiers);
        $this->assertEquals(100, $progress);
    }
    
    /**
     * Property Test: Progress increases as points increase within tier
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     */
    public function testProgressIncreasesWithPoints(): void
    {
        // Test within Silver tier (0-1999)
        $progress500 = $this->calculateTierProgress(500, $this->defaultTiers);
        $progress1000 = $this->calculateTierProgress(1000, $this->defaultTiers);
        $progress1500 = $this->calculateTierProgress(1500, $this->defaultTiers);
        
        $this->assertLessThan($progress1000, $progress500);
        $this->assertLessThan($progress1500, $progress1000);
    }
    
    /**
     * Property Test: Highest tier always shows 100% progress
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     */
    public function testHighestTierShowsFullProgress(): void
    {
        // Any points at or above Platinum threshold
        for ($i = 0; $i < 50; $i++) {
            $points = 5000 + rand(0, 100000);
            $progress = $this->calculateTierProgress($points, $this->defaultTiers);
            
            $this->assertEquals(
                100,
                $progress,
                "Highest tier should show 100% progress"
            );
        }
    }
    
    /**
     * Property Test: Default tier thresholds are in ascending order
     * 
     * **Feature: liff-telepharmacy-redesign, Property 38: Tier Threshold Progression**
     * **Validates: Requirements 25.8**
     */
    public function testDefaultTierThresholdsAscending(): void
    {
        $this->assertTrue(
            $this->validateTierThresholds($this->defaultTiers),
            "Default tier thresholds should be in ascending order"
        );
    }
    
    /**
     * Property Test: Valid tier configurations have ascending thresholds
     * 
     * **Feature: liff-telepharmacy-redesign, Property 38: Tier Threshold Progression**
     * **Validates: Requirements 25.8**
     */
    public function testValidTierConfigurationsAscending(): void
    {
        // Generate random valid tier configurations
        for ($i = 0; $i < 50; $i++) {
            $tiers = [];
            $prevPoints = 0;
            $tierCount = rand(2, 5);
            
            for ($j = 0; $j < $tierCount; $j++) {
                $minPoints = $prevPoints + rand(100, 5000);
                $tiers[] = [
                    'name' => "Tier {$j}",
                    'min_points' => $j === 0 ? 0 : $minPoints,
                    'multiplier' => 1.0 + ($j * 0.5)
                ];
                $prevPoints = $minPoints;
            }
            
            $this->assertTrue(
                $this->validateTierThresholds($tiers),
                "Generated tier configuration should be valid"
            );
        }
    }
    
    /**
     * Property Test: Invalid tier configurations are detected
     * 
     * **Feature: liff-telepharmacy-redesign, Property 38: Tier Threshold Progression**
     * **Validates: Requirements 25.8**
     */
    public function testInvalidTierConfigurationsDetected(): void
    {
        // Descending order - invalid
        $invalidTiers1 = [
            ['name' => 'Platinum', 'min_points' => 5000, 'multiplier' => 2.0],
            ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5],
            ['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0],
        ];
        
        $this->assertFalse(
            $this->validateTierThresholds($invalidTiers1),
            "Descending tier thresholds should be invalid"
        );
        
        // Duplicate thresholds - invalid
        $invalidTiers2 = [
            ['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0],
            ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5],
            ['name' => 'Platinum', 'min_points' => 2000, 'multiplier' => 2.0],
        ];
        
        $this->assertFalse(
            $this->validateTierThresholds($invalidTiers2),
            "Duplicate tier thresholds should be invalid"
        );
    }
    
    /**
     * Property Test: First tier should start at 0 points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 38: Tier Threshold Progression**
     * **Validates: Requirements 25.8**
     */
    public function testFirstTierStartsAtZero(): void
    {
        $this->assertEquals(
            0,
            $this->defaultTiers[0]['min_points'],
            "First tier should start at 0 points"
        );
    }
    
    /**
     * Property Test: User is always assigned to a tier
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     */
    public function testUserAlwaysHasTier(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $points = rand(0, 100000);
            $tier = $this->getCurrentTier($points, $this->defaultTiers);
            
            $this->assertNotNull($tier, "User should always have a tier");
            $this->assertArrayHasKey('name', $tier);
            $this->assertArrayHasKey('min_points', $tier);
            $this->assertArrayHasKey('multiplier', $tier);
        }
    }
    
    /**
     * Property Test: Tier assignment is correct based on points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     */
    public function testTierAssignmentCorrect(): void
    {
        // Silver tier (0-1999)
        $this->assertEquals('Silver', $this->getCurrentTier(0, $this->defaultTiers)['name']);
        $this->assertEquals('Silver', $this->getCurrentTier(1999, $this->defaultTiers)['name']);
        
        // Gold tier (2000-4999)
        $this->assertEquals('Gold', $this->getCurrentTier(2000, $this->defaultTiers)['name']);
        $this->assertEquals('Gold', $this->getCurrentTier(4999, $this->defaultTiers)['name']);
        
        // Platinum tier (5000+)
        $this->assertEquals('Platinum', $this->getCurrentTier(5000, $this->defaultTiers)['name']);
        $this->assertEquals('Platinum', $this->getCurrentTier(100000, $this->defaultTiers)['name']);
    }
    
    /**
     * Data provider for progress calculation
     */
    public function progressCalculationProvider(): array
    {
        return [
            // [points, expectedProgress]
            'silver_start' => [0, 0],
            'silver_quarter' => [500, 25],
            'silver_half' => [1000, 50],
            'silver_three_quarter' => [1500, 75],
            'gold_start' => [2000, 0],
            'gold_half' => [3500, 50],
            'platinum_start' => [5000, 100],
            'platinum_high' => [10000, 100],
        ];
    }
    
    /**
     * Property Test: Progress calculation is accurate
     * 
     * **Feature: liff-telepharmacy-redesign, Property 9: Tier Progress Bounds**
     * **Validates: Requirements 5.4**
     * 
     * @dataProvider progressCalculationProvider
     */
    public function testProgressCalculationAccuracy(int $points, float $expectedProgress): void
    {
        $progress = $this->calculateTierProgress($points, $this->defaultTiers);
        
        $this->assertEquals(
            $expectedProgress,
            $progress,
            "Progress for {$points} points should be {$expectedProgress}%"
        );
    }
}
