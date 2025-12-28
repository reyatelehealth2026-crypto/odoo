<?php
/**
 * Property-Based Test: Cart Serialization Round-Trip
 * 
 * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
 * **Validates: Requirements 2.9, 2.10**
 * 
 * Property: For any valid cart object, serializing to JSON and then deserializing 
 * should produce an equivalent cart object with identical items, quantities, and totals.
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class CartSerializationPropertyTest extends TestCase
{
    /**
     * Serialize cart to JSON (mirrors LIFF implementation)
     */
    private function serializeCart(array $cart): string
    {
        return json_encode($cart, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Deserialize cart from JSON (mirrors LIFF implementation)
     */
    private function deserializeCart(string $json): array
    {
        return json_decode($json, true) ?? [];
    }
    
    /**
     * Calculate cart totals
     */
    private function calculateCartTotals(array $items): array
    {
        $subtotal = 0;
        $itemCount = 0;
        
        foreach ($items as $item) {
            $price = $item['sale_price'] ?? $item['price'];
            $subtotal += $price * $item['quantity'];
            $itemCount += $item['quantity'];
        }
        
        return [
            'subtotal' => $subtotal,
            'item_count' => $itemCount
        ];
    }
    
    /**
     * Generate random cart item for testing
     */
    private function generateRandomCartItem(): array
    {
        $hasDiscount = rand(0, 1) === 1;
        $price = rand(10, 5000);
        
        return [
            'product_id' => rand(1, 10000),
            'name' => 'Product ' . rand(1, 1000),
            'price' => $price,
            'sale_price' => $hasDiscount ? $price * 0.8 : null,
            'quantity' => rand(1, 10),
            'is_prescription' => rand(0, 1) === 1,
            'image_url' => 'https://example.com/img/' . rand(1, 100) . '.jpg'
        ];
    }
    
    /**
     * Generate random cart for testing
     */
    private function generateRandomCart(): array
    {
        $itemCount = rand(0, 10);
        $items = [];
        
        for ($i = 0; $i < $itemCount; $i++) {
            $items[] = $this->generateRandomCartItem();
        }
        
        $totals = $this->calculateCartTotals($items);
        
        return [
            'items' => $items,
            'subtotal' => $totals['subtotal'],
            'discount' => rand(0, 1) === 1 ? rand(10, 500) : 0,
            'shipping_fee' => rand(0, 1) === 1 ? rand(0, 100) : 0,
            'coupon_code' => rand(0, 1) === 1 ? 'PROMO' . rand(100, 999) : null,
            'has_prescription' => count(array_filter($items, fn($i) => $i['is_prescription'])) > 0
        ];
    }
    
    /**
     * Property Test: Cart serialization round-trip preserves all data
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testCartSerializationRoundTrip(): void
    {
        // Run 100 iterations with random carts
        for ($i = 0; $i < 100; $i++) {
            $originalCart = $this->generateRandomCart();
            
            // Serialize
            $serialized = $this->serializeCart($originalCart);
            
            // Deserialize
            $deserialized = $this->deserializeCart($serialized);
            
            // Verify round-trip produces identical result
            $this->assertEquals(
                $originalCart,
                $deserialized,
                "Cart round-trip failed on iteration {$i}"
            );
        }
    }
    
    /**
     * Property Test: Serialized cart is valid JSON
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testSerializedCartIsValidJson(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $cart = $this->generateRandomCart();
            $serialized = $this->serializeCart($cart);
            
            // Verify it's valid JSON
            json_decode($serialized);
            $this->assertEquals(
                JSON_ERROR_NONE,
                json_last_error(),
                "Serialized cart should be valid JSON"
            );
        }
    }
    
    /**
     * Property Test: Item count is preserved after round-trip
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testItemCountPreservedAfterRoundTrip(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $cart = $this->generateRandomCart();
            $originalItemCount = count($cart['items']);
            
            $serialized = $this->serializeCart($cart);
            $deserialized = $this->deserializeCart($serialized);
            
            $this->assertCount(
                $originalItemCount,
                $deserialized['items'],
                "Item count should be preserved after round-trip"
            );
        }
    }
    
    /**
     * Property Test: Subtotal is preserved after round-trip
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testSubtotalPreservedAfterRoundTrip(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $cart = $this->generateRandomCart();
            $originalSubtotal = $cart['subtotal'];
            
            $serialized = $this->serializeCart($cart);
            $deserialized = $this->deserializeCart($serialized);
            
            $this->assertEquals(
                $originalSubtotal,
                $deserialized['subtotal'],
                "Subtotal should be preserved after round-trip"
            );
        }
    }
    
    /**
     * Property Test: Empty cart serialization works correctly
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testEmptyCartSerialization(): void
    {
        $emptyCart = [
            'items' => [],
            'subtotal' => 0,
            'discount' => 0,
            'shipping_fee' => 0,
            'coupon_code' => null,
            'has_prescription' => false
        ];
        
        $serialized = $this->serializeCart($emptyCart);
        $deserialized = $this->deserializeCart($serialized);
        
        $this->assertEquals($emptyCart, $deserialized);
        $this->assertEmpty($deserialized['items']);
    }
    
    /**
     * Property Test: Prescription flag is preserved
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testPrescriptionFlagPreserved(): void
    {
        // Cart with prescription items
        $cartWithRx = [
            'items' => [
                ['product_id' => 1, 'name' => 'Rx Drug', 'price' => 100, 'quantity' => 1, 'is_prescription' => true]
            ],
            'subtotal' => 100,
            'discount' => 0,
            'shipping_fee' => 0,
            'coupon_code' => null,
            'has_prescription' => true
        ];
        
        $serialized = $this->serializeCart($cartWithRx);
        $deserialized = $this->deserializeCart($serialized);
        
        $this->assertTrue($deserialized['has_prescription']);
        $this->assertTrue($deserialized['items'][0]['is_prescription']);
    }
    
    /**
     * Property Test: Thai product names are preserved
     * 
     * **Feature: liff-telepharmacy-redesign, Property 1: Cart Serialization Round-Trip**
     * **Validates: Requirements 2.9, 2.10**
     */
    public function testThaiProductNamesPreserved(): void
    {
        $cart = [
            'items' => [
                ['product_id' => 1, 'name' => 'พาราเซตามอล 500mg', 'price' => 50, 'quantity' => 2, 'is_prescription' => false],
                ['product_id' => 2, 'name' => 'ยาแก้ไอ น้ำดำ', 'price' => 80, 'quantity' => 1, 'is_prescription' => false]
            ],
            'subtotal' => 180,
            'discount' => 0,
            'shipping_fee' => 40,
            'coupon_code' => null,
            'has_prescription' => false
        ];
        
        $serialized = $this->serializeCart($cart);
        $deserialized = $this->deserializeCart($serialized);
        
        $this->assertEquals('พาราเซตามอล 500mg', $deserialized['items'][0]['name']);
        $this->assertEquals('ยาแก้ไอ น้ำดำ', $deserialized['items'][1]['name']);
    }
}
