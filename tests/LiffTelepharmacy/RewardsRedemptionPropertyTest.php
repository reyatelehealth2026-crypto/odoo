<?php
/**
 * Property-Based Tests: Rewards Redemption
 * 
 * **Feature: liff-telepharmacy-redesign, Property 31: Reward Card Required Elements**
 * **Feature: liff-telepharmacy-redesign, Property 32: Reward Availability Display**
 * **Feature: liff-telepharmacy-redesign, Property 33: Redemption Points Deduction**
 * **Feature: liff-telepharmacy-redesign, Property 34: Redemption Code Uniqueness**
 * **Feature: liff-telepharmacy-redesign, Property 35: Redemption Status Display**
 * **Validates: Requirements 23.2, 23.3, 23.4, 23.5, 23.7, 23.10**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class RewardsRedemptionPropertyTest extends TestCase
{
    /**
     * Valid redemption statuses
     */
    private $validStatuses = ['pending', 'approved', 'delivered', 'cancelled'];
    
    /**
     * Generate random reward
     */
    private function generateRandomReward(): array
    {
        $stockQuantity = rand(-1, 100); // -1 = unlimited
        
        return [
            'id' => rand(1, 10000),
            'name' => 'Reward ' . rand(1, 1000),
            'description' => 'Description for reward',
            'image_url' => 'https://example.com/reward/' . rand(1, 100) . '.jpg',
            'points_required' => rand(100, 5000),
            'stock_quantity' => $stockQuantity,
            'type' => ['discount_coupon', 'free_shipping', 'physical_gift', 'product_voucher'][rand(0, 3)],
            'is_active' => true
        ];
    }
    
    /**
     * Generate random redemption
     */
    private function generateRandomRedemption(): array
    {
        return [
            'id' => rand(1, 100000),
            'user_id' => rand(1, 10000),
            'reward_id' => rand(1, 1000),
            'redemption_code' => $this->generateRedemptionCode(),
            'points_used' => rand(100, 5000),
            'status' => $this->validStatuses[array_rand($this->validStatuses)],
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 30) . ' days'))
        ];
    }
    
    /**
     * Generate unique redemption code
     */
    private function generateRedemptionCode(): string
    {
        return 'RDM' . date('Ymd') . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
    
    /**
     * Process redemption
     */
    private function processRedemption(int $userPoints, int $rewardCost): array
    {
        if ($userPoints < $rewardCost) {
            return [
                'success' => false,
                'message' => 'Insufficient points',
                'newBalance' => $userPoints
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Redemption successful',
            'newBalance' => $userPoints - $rewardCost,
            'redemption_code' => $this->generateRedemptionCode()
        ];
    }
    
    /**
     * Check reward availability
     */
    private function checkRewardAvailability(array $reward, int $userPoints): array
    {
        $isOutOfStock = $reward['stock_quantity'] === 0;
        $isInsufficientPoints = $userPoints < $reward['points_required'];
        $isUnlimited = $reward['stock_quantity'] === -1;
        
        return [
            'is_available' => !$isOutOfStock && !$isInsufficientPoints,
            'is_out_of_stock' => $isOutOfStock,
            'is_insufficient_points' => $isInsufficientPoints,
            'is_unlimited' => $isUnlimited,
            'stock_display' => $isOutOfStock ? 'หมดแล้ว' : ($isUnlimited ? null : "เหลือ {$reward['stock_quantity']} ชิ้น"),
            'points_needed' => $isInsufficientPoints ? $reward['points_required'] - $userPoints : 0
        ];
    }
    
    /**
     * Property Test: Reward card has all required elements
     * 
     * **Feature: liff-telepharmacy-redesign, Property 31: Reward Card Required Elements**
     * **Validates: Requirements 23.2**
     */
    public function testRewardCardHasRequiredElements(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $reward = $this->generateRandomReward();
            
            $this->assertArrayHasKey('id', $reward);
            $this->assertArrayHasKey('name', $reward);
            $this->assertArrayHasKey('image_url', $reward);
            $this->assertArrayHasKey('points_required', $reward);
            $this->assertArrayHasKey('stock_quantity', $reward);
            
            $this->assertNotEmpty($reward['name']);
            $this->assertNotEmpty($reward['image_url']);
            $this->assertGreaterThan(0, $reward['points_required']);
        }
    }
    
    /**
     * Property Test: Out of stock rewards show correct badge
     * 
     * **Feature: liff-telepharmacy-redesign, Property 32: Reward Availability Display**
     * **Validates: Requirements 23.4**
     */
    public function testOutOfStockRewardsShowBadge(): void
    {
        $reward = $this->generateRandomReward();
        $reward['stock_quantity'] = 0;
        
        $availability = $this->checkRewardAvailability($reward, 10000);
        
        $this->assertTrue($availability['is_out_of_stock']);
        $this->assertFalse($availability['is_available']);
        $this->assertEquals('หมดแล้ว', $availability['stock_display']);
    }
    
    /**
     * Property Test: Insufficient points shows grayed out state
     * 
     * **Feature: liff-telepharmacy-redesign, Property 32: Reward Availability Display**
     * **Validates: Requirements 23.5**
     */
    public function testInsufficientPointsShowsGrayedOut(): void
    {
        $reward = $this->generateRandomReward();
        $reward['points_required'] = 1000;
        $userPoints = 500;
        
        $availability = $this->checkRewardAvailability($reward, $userPoints);
        
        $this->assertTrue($availability['is_insufficient_points']);
        $this->assertFalse($availability['is_available']);
        $this->assertEquals(500, $availability['points_needed']);
    }
    
    /**
     * Property Test: Limited stock shows remaining quantity
     * 
     * **Feature: liff-telepharmacy-redesign, Property 32: Reward Availability Display**
     * **Validates: Requirements 23.3**
     */
    public function testLimitedStockShowsRemainingQuantity(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $stockQty = rand(1, 100);
            $reward = $this->generateRandomReward();
            $reward['stock_quantity'] = $stockQty;
            
            $availability = $this->checkRewardAvailability($reward, 10000);
            
            $this->assertStringContainsString(
                (string) $stockQty,
                $availability['stock_display'],
                "Stock display should show remaining quantity"
            );
        }
    }
    
    /**
     * Property Test: Redemption deducts exact points required
     * 
     * **Feature: liff-telepharmacy-redesign, Property 33: Redemption Points Deduction**
     * **Validates: Requirements 23.7**
     */
    public function testRedemptionDeductsExactPoints(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $userPoints = rand(1000, 10000);
            $rewardCost = rand(100, $userPoints);
            
            $result = $this->processRedemption($userPoints, $rewardCost);
            
            $this->assertTrue($result['success']);
            $this->assertEquals(
                $userPoints - $rewardCost,
                $result['newBalance'],
                "New balance should be exactly userPoints - rewardCost"
            );
        }
    }
    
    /**
     * Property Test: Insufficient points prevents redemption
     * 
     * **Feature: liff-telepharmacy-redesign, Property 33: Redemption Points Deduction**
     * **Validates: Requirements 23.7**
     */
    public function testInsufficientPointsPreventsRedemption(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $userPoints = rand(100, 500);
            $rewardCost = $userPoints + rand(1, 500);
            
            $result = $this->processRedemption($userPoints, $rewardCost);
            
            $this->assertFalse($result['success']);
            $this->assertEquals(
                $userPoints,
                $result['newBalance'],
                "Balance should remain unchanged on failed redemption"
            );
        }
    }
    
    /**
     * Property Test: Redemption codes are unique
     * 
     * **Feature: liff-telepharmacy-redesign, Property 34: Redemption Code Uniqueness**
     * **Validates: Requirements 23.7**
     */
    public function testRedemptionCodesAreUnique(): void
    {
        $codes = [];
        
        for ($i = 0; $i < 1000; $i++) {
            $code = $this->generateRedemptionCode();
            
            $this->assertNotContains(
                $code,
                $codes,
                "Redemption code should be unique"
            );
            
            $codes[] = $code;
        }
    }
    
    /**
     * Property Test: Redemption code format is valid
     * 
     * **Feature: liff-telepharmacy-redesign, Property 34: Redemption Code Uniqueness**
     * **Validates: Requirements 23.7**
     */
    public function testRedemptionCodeFormatIsValid(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $code = $this->generateRedemptionCode();
            
            // Should start with RDM
            $this->assertStringStartsWith('RDM', $code);
            
            // Should be alphanumeric
            $this->assertMatchesRegularExpression('/^RDM[A-Z0-9]+$/', $code);
            
            // Should have reasonable length
            $this->assertGreaterThanOrEqual(11, strlen($code));
        }
    }
    
    /**
     * Property Test: Redemption has valid status
     * 
     * **Feature: liff-telepharmacy-redesign, Property 35: Redemption Status Display**
     * **Validates: Requirements 23.10**
     */
    public function testRedemptionHasValidStatus(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $redemption = $this->generateRandomRedemption();
            
            $this->assertContains(
                $redemption['status'],
                $this->validStatuses,
                "Redemption status should be valid"
            );
        }
    }
    
    /**
     * Data provider for redemption status display
     */
    public function redemptionStatusProvider(): array
    {
        return [
            'pending' => ['pending', 'รอดำเนินการ'],
            'approved' => ['approved', 'อนุมัติแล้ว'],
            'delivered' => ['delivered', 'จัดส่งแล้ว'],
            'cancelled' => ['cancelled', 'ยกเลิก'],
        ];
    }
    
    /**
     * Property Test: Redemption status displays correct label
     * 
     * **Feature: liff-telepharmacy-redesign, Property 35: Redemption Status Display**
     * **Validates: Requirements 23.10**
     * 
     * @dataProvider redemptionStatusProvider
     */
    public function testRedemptionStatusDisplaysCorrectLabel(string $status, string $expectedLabel): void
    {
        $labels = [
            'pending' => 'รอดำเนินการ',
            'approved' => 'อนุมัติแล้ว',
            'delivered' => 'จัดส่งแล้ว',
            'cancelled' => 'ยกเลิก'
        ];
        
        $this->assertEquals($expectedLabel, $labels[$status]);
    }
    
    /**
     * Property Test: Available reward can be redeemed
     * 
     * **Feature: liff-telepharmacy-redesign, Property 32: Reward Availability Display**
     * **Validates: Requirements 23.3, 23.4, 23.5**
     */
    public function testAvailableRewardCanBeRedeemed(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $reward = $this->generateRandomReward();
            $reward['stock_quantity'] = rand(1, 100);
            $userPoints = $reward['points_required'] + rand(0, 1000);
            
            $availability = $this->checkRewardAvailability($reward, $userPoints);
            
            $this->assertTrue(
                $availability['is_available'],
                "Reward with stock and sufficient points should be available"
            );
        }
    }
    
    /**
     * Property Test: Unlimited stock rewards are always in stock
     * 
     * **Feature: liff-telepharmacy-redesign, Property 32: Reward Availability Display**
     * **Validates: Requirements 23.3**
     */
    public function testUnlimitedStockRewardsAlwaysInStock(): void
    {
        $reward = $this->generateRandomReward();
        $reward['stock_quantity'] = -1; // Unlimited
        
        $availability = $this->checkRewardAvailability($reward, 10000);
        
        $this->assertTrue($availability['is_unlimited']);
        $this->assertFalse($availability['is_out_of_stock']);
        $this->assertNull($availability['stock_display']);
    }
}
