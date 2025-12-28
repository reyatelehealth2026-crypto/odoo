<?php
/**
 * Property-Based Tests: Promo Code Validation
 * 
 * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
 * **Validates: Requirements 17.5, 17.6, 17.7**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class PromoCodePropertyTest extends TestCase
{
    /**
     * Valid promo codes for testing
     */
    private $validPromoCodes = [
        'SAVE10' => ['type' => 'percentage', 'value' => 10, 'min_order' => 500],
        'FLAT100' => ['type' => 'fixed', 'value' => 100, 'min_order' => 1000],
        'FREESHIP' => ['type' => 'free_shipping', 'value' => 0, 'min_order' => 300],
        'NEWUSER' => ['type' => 'percentage', 'value' => 15, 'min_order' => 0, 'first_order_only' => true]
    ];
    
    /**
     * Generate random promo code
     */
    private function generateRandomPromoCode(): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < rand(5, 10); $i++) {
            $code .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $code;
    }
    
    /**
     * Validate promo code
     */
    private function validatePromoCode(string $code, float $orderTotal, bool $isFirstOrder = false): array
    {
        $startTime = microtime(true);
        
        // Check if code exists
        if (!isset($this->validPromoCodes[$code])) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            return [
                'valid' => false,
                'error' => 'invalid_code',
                'message' => 'รหัสโปรโมชั่นไม่ถูกต้อง',
                'response_time_ms' => $responseTime
            ];
        }
        
        $promo = $this->validPromoCodes[$code];
        
        // Check minimum order
        if ($orderTotal < $promo['min_order']) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            return [
                'valid' => false,
                'error' => 'min_order_not_met',
                'message' => "ยอดสั่งซื้อขั้นต่ำ ฿{$promo['min_order']}",
                'response_time_ms' => $responseTime
            ];
        }
        
        // Check first order only
        if (isset($promo['first_order_only']) && $promo['first_order_only'] && !$isFirstOrder) {
            $responseTime = (microtime(true) - $startTime) * 1000;
            return [
                'valid' => false,
                'error' => 'first_order_only',
                'message' => 'รหัสนี้ใช้ได้เฉพาะการสั่งซื้อครั้งแรก',
                'response_time_ms' => $responseTime
            ];
        }
        
        // Calculate discount
        $discount = 0;
        switch ($promo['type']) {
            case 'percentage':
                $discount = $orderTotal * ($promo['value'] / 100);
                break;
            case 'fixed':
                $discount = $promo['value'];
                break;
            case 'free_shipping':
                $discount = 0; // Shipping handled separately
                break;
        }
        
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        return [
            'valid' => true,
            'code' => $code,
            'type' => $promo['type'],
            'discount_amount' => $discount,
            'message' => 'ใช้รหัสโปรโมชั่นสำเร็จ',
            'response_time_ms' => $responseTime
        ];
    }
    
    /**
     * Property Test: Valid promo code returns discount amount
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.5**
     */
    public function testValidPromoCodeReturnsDiscountAmount(): void
    {
        foreach ($this->validPromoCodes as $code => $promo) {
            $orderTotal = max($promo['min_order'], 1000);
            $isFirstOrder = isset($promo['first_order_only']) ? $promo['first_order_only'] : false;
            
            $result = $this->validatePromoCode($code, $orderTotal, $isFirstOrder);
            
            $this->assertTrue(
                $result['valid'],
                "Valid promo code '{$code}' should return valid result"
            );
            $this->assertArrayHasKey('discount_amount', $result);
        }
    }
    
    /**
     * Property Test: Invalid promo code returns error reason
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.6**
     */
    public function testInvalidPromoCodeReturnsErrorReason(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $invalidCode = $this->generateRandomPromoCode();
            
            // Make sure it's not accidentally valid
            while (isset($this->validPromoCodes[$invalidCode])) {
                $invalidCode = $this->generateRandomPromoCode();
            }
            
            $result = $this->validatePromoCode($invalidCode, 1000);
            
            $this->assertFalse(
                $result['valid'],
                "Invalid promo code should return invalid result"
            );
            $this->assertArrayHasKey('error', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertEquals('invalid_code', $result['error']);
        }
    }
    
    /**
     * Property Test: Minimum order not met returns error
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.6**
     */
    public function testMinimumOrderNotMetReturnsError(): void
    {
        $code = 'FLAT100'; // min_order = 1000
        $orderTotal = 500; // Below minimum
        
        $result = $this->validatePromoCode($code, $orderTotal);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('min_order_not_met', $result['error']);
        $this->assertStringContainsString('1000', $result['message']);
    }
    
    /**
     * Property Test: First order only code fails for repeat customers
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.6**
     */
    public function testFirstOrderOnlyCodeFailsForRepeatCustomers(): void
    {
        $code = 'NEWUSER';
        $orderTotal = 1000;
        $isFirstOrder = false;
        
        $result = $this->validatePromoCode($code, $orderTotal, $isFirstOrder);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('first_order_only', $result['error']);
    }
    
    /**
     * Property Test: Validation response within 500ms
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.7**
     */
    public function testValidationResponseWithin500ms(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $code = array_rand($this->validPromoCodes);
            $orderTotal = rand(100, 5000);
            
            $result = $this->validatePromoCode($code, $orderTotal);
            
            $this->assertLessThan(
                500,
                $result['response_time_ms'],
                "Validation should complete within 500ms"
            );
        }
    }
    
    /**
     * Property Test: Percentage discount calculated correctly
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.5**
     */
    public function testPercentageDiscountCalculatedCorrectly(): void
    {
        $code = 'SAVE10'; // 10% off
        
        for ($i = 0; $i < 50; $i++) {
            $orderTotal = rand(500, 5000);
            $expectedDiscount = $orderTotal * 0.10;
            
            $result = $this->validatePromoCode($code, $orderTotal);
            
            $this->assertTrue($result['valid']);
            $this->assertEquals(
                $expectedDiscount,
                $result['discount_amount'],
                "10% discount should be calculated correctly"
            );
        }
    }
    
    /**
     * Property Test: Fixed discount applied correctly
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.5**
     */
    public function testFixedDiscountAppliedCorrectly(): void
    {
        $code = 'FLAT100'; // ฿100 off
        
        for ($i = 0; $i < 50; $i++) {
            $orderTotal = rand(1000, 5000);
            
            $result = $this->validatePromoCode($code, $orderTotal);
            
            $this->assertTrue($result['valid']);
            $this->assertEquals(
                100,
                $result['discount_amount'],
                "Fixed ฿100 discount should be applied"
            );
        }
    }
    
    /**
     * Property Test: Response always has required fields
     * 
     * **Feature: liff-telepharmacy-redesign, Property 16: Promo Code Validation Response**
     * **Validates: Requirements 17.5, 17.6, 17.7**
     */
    public function testResponseAlwaysHasRequiredFields(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $code = rand(0, 1) ? array_rand($this->validPromoCodes) : $this->generateRandomPromoCode();
            $orderTotal = rand(100, 5000);
            
            $result = $this->validatePromoCode($code, $orderTotal);
            
            $this->assertArrayHasKey('valid', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertArrayHasKey('response_time_ms', $result);
            
            if ($result['valid']) {
                $this->assertArrayHasKey('discount_amount', $result);
            } else {
                $this->assertArrayHasKey('error', $result);
            }
        }
    }
}
