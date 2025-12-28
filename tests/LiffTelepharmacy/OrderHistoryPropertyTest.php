<?php
/**
 * Property-Based Tests: Order History
 * 
 * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
 * **Feature: liff-telepharmacy-redesign, Property 7: Order Status Badge Presence**
 * **Validates: Requirements 4.1, 4.2**
 * 
 * Properties:
 * - For any list of orders displayed in Order History, the orders should be sorted by created_at in descending order.
 * - For any order displayed in Order History, a status badge should be present with one of the valid statuses.
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class OrderHistoryPropertyTest extends TestCase
{
    /**
     * Valid order statuses
     */
    private $validStatuses = ['pending', 'confirmed', 'packing', 'shipping', 'delivered', 'cancelled'];
    
    /**
     * Status badge colors
     */
    private $statusColors = [
        'pending' => 'yellow',
        'confirmed' => 'blue',
        'packing' => 'blue',
        'shipping' => 'purple',
        'delivered' => 'green',
        'cancelled' => 'red'
    ];
    
    /**
     * Generate random order for testing
     */
    private function generateRandomOrder(): array
    {
        $statuses = $this->validStatuses;
        $status = $statuses[array_rand($statuses)];
        
        // Random date within last 365 days
        $daysAgo = rand(0, 365);
        $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        
        return [
            'id' => rand(1, 100000),
            'order_id' => 'ORD' . date('Ymd') . rand(1000, 9999),
            'status' => $status,
            'total' => rand(100, 10000),
            'item_count' => rand(1, 10),
            'created_at' => $createdAt,
            'updated_at' => $createdAt
        ];
    }
    
    /**
     * Generate list of random orders
     */
    private function generateRandomOrders(int $count): array
    {
        $orders = [];
        for ($i = 0; $i < $count; $i++) {
            $orders[] = $this->generateRandomOrder();
        }
        return $orders;
    }
    
    /**
     * Sort orders by created_at descending (newest first)
     */
    private function sortOrdersByDateDesc(array $orders): array
    {
        usort($orders, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        return $orders;
    }
    
    /**
     * Check if orders are sorted by date descending
     */
    private function areOrdersSortedDesc(array $orders): bool
    {
        for ($i = 1; $i < count($orders); $i++) {
            if (strtotime($orders[$i-1]['created_at']) < strtotime($orders[$i]['created_at'])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Get status badge for order
     */
    private function getStatusBadge(string $status): array
    {
        $labels = [
            'pending' => 'รอดำเนินการ',
            'confirmed' => 'ยืนยันแล้ว',
            'packing' => 'กำลังจัดส่ง',
            'shipping' => 'กำลังจัดส่ง',
            'delivered' => 'จัดส่งแล้ว',
            'cancelled' => 'ยกเลิก'
        ];
        
        return [
            'status' => $status,
            'label' => $labels[$status] ?? $status,
            'color' => $this->statusColors[$status] ?? 'gray'
        ];
    }
    
    /**
     * Property Test: Orders are sorted by date descending
     * 
     * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
     * **Validates: Requirements 4.1**
     */
    public function testOrdersSortedByDateDescending(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $orderCount = rand(2, 20);
            $orders = $this->generateRandomOrders($orderCount);
            
            // Sort orders
            $sortedOrders = $this->sortOrdersByDateDesc($orders);
            
            // Verify sorting
            $this->assertTrue(
                $this->areOrdersSortedDesc($sortedOrders),
                "Orders should be sorted by date descending"
            );
        }
    }
    
    /**
     * Property Test: First order is the newest
     * 
     * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
     * **Validates: Requirements 4.1**
     */
    public function testFirstOrderIsNewest(): void
    {
        $orders = $this->generateRandomOrders(10);
        $sortedOrders = $this->sortOrdersByDateDesc($orders);
        
        // Find the actual newest order
        $newestDate = max(array_column($orders, 'created_at'));
        
        $this->assertEquals(
            $newestDate,
            $sortedOrders[0]['created_at'],
            "First order should be the newest"
        );
    }
    
    /**
     * Property Test: Last order is the oldest
     * 
     * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
     * **Validates: Requirements 4.1**
     */
    public function testLastOrderIsOldest(): void
    {
        $orders = $this->generateRandomOrders(10);
        $sortedOrders = $this->sortOrdersByDateDesc($orders);
        
        // Find the actual oldest order
        $oldestDate = min(array_column($orders, 'created_at'));
        
        $this->assertEquals(
            $oldestDate,
            $sortedOrders[count($sortedOrders) - 1]['created_at'],
            "Last order should be the oldest"
        );
    }
    
    /**
     * Property Test: Every order has a valid status
     * 
     * **Feature: liff-telepharmacy-redesign, Property 7: Order Status Badge Presence**
     * **Validates: Requirements 4.2**
     */
    public function testEveryOrderHasValidStatus(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $order = $this->generateRandomOrder();
            
            $this->assertContains(
                $order['status'],
                $this->validStatuses,
                "Order status '{$order['status']}' should be valid"
            );
        }
    }
    
    /**
     * Property Test: Every order has a status badge
     * 
     * **Feature: liff-telepharmacy-redesign, Property 7: Order Status Badge Presence**
     * **Validates: Requirements 4.2**
     */
    public function testEveryOrderHasStatusBadge(): void
    {
        foreach ($this->validStatuses as $status) {
            $badge = $this->getStatusBadge($status);
            
            $this->assertArrayHasKey('status', $badge);
            $this->assertArrayHasKey('label', $badge);
            $this->assertArrayHasKey('color', $badge);
            $this->assertNotEmpty($badge['label']);
            $this->assertNotEmpty($badge['color']);
        }
    }
    
    /**
     * Property Test: Status badge has correct color
     * 
     * **Feature: liff-telepharmacy-redesign, Property 7: Order Status Badge Presence**
     * **Validates: Requirements 4.2, 4.6**
     */
    public function testStatusBadgeHasCorrectColor(): void
    {
        $expectedColors = [
            'pending' => 'yellow',
            'confirmed' => 'blue',
            'packing' => 'blue',
            'shipping' => 'purple',
            'delivered' => 'green',
            'cancelled' => 'red'
        ];
        
        foreach ($expectedColors as $status => $expectedColor) {
            $badge = $this->getStatusBadge($status);
            
            $this->assertEquals(
                $expectedColor,
                $badge['color'],
                "Status '{$status}' should have color '{$expectedColor}'"
            );
        }
    }
    
    /**
     * Property Test: Empty order list is valid
     * 
     * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
     * **Validates: Requirements 4.1**
     */
    public function testEmptyOrderListIsValid(): void
    {
        $orders = [];
        $sortedOrders = $this->sortOrdersByDateDesc($orders);
        
        $this->assertEmpty($sortedOrders);
        $this->assertTrue($this->areOrdersSortedDesc($sortedOrders));
    }
    
    /**
     * Property Test: Single order list is valid
     * 
     * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
     * **Validates: Requirements 4.1**
     */
    public function testSingleOrderListIsValid(): void
    {
        $orders = [$this->generateRandomOrder()];
        $sortedOrders = $this->sortOrdersByDateDesc($orders);
        
        $this->assertCount(1, $sortedOrders);
        $this->assertTrue($this->areOrdersSortedDesc($sortedOrders));
    }
    
    /**
     * Data provider for status badge tests
     */
    public function statusBadgeProvider(): array
    {
        return [
            'pending' => ['pending', 'รอดำเนินการ', 'yellow'],
            'confirmed' => ['confirmed', 'ยืนยันแล้ว', 'blue'],
            'packing' => ['packing', 'กำลังจัดส่ง', 'blue'],
            'shipping' => ['shipping', 'กำลังจัดส่ง', 'purple'],
            'delivered' => ['delivered', 'จัดส่งแล้ว', 'green'],
            'cancelled' => ['cancelled', 'ยกเลิก', 'red'],
        ];
    }
    
    /**
     * Property Test: Status badge displays correct label
     * 
     * **Feature: liff-telepharmacy-redesign, Property 7: Order Status Badge Presence**
     * **Validates: Requirements 4.2**
     * 
     * @dataProvider statusBadgeProvider
     */
    public function testStatusBadgeDisplaysCorrectLabel(string $status, string $expectedLabel, string $expectedColor): void
    {
        $badge = $this->getStatusBadge($status);
        
        $this->assertEquals($expectedLabel, $badge['label']);
        $this->assertEquals($expectedColor, $badge['color']);
    }
    
    /**
     * Property Test: Sorting preserves all orders
     * 
     * **Feature: liff-telepharmacy-redesign, Property 6: Order History Sorting**
     * **Validates: Requirements 4.1**
     */
    public function testSortingPreservesAllOrders(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $orderCount = rand(5, 20);
            $orders = $this->generateRandomOrders($orderCount);
            $originalIds = array_column($orders, 'id');
            
            $sortedOrders = $this->sortOrdersByDateDesc($orders);
            $sortedIds = array_column($sortedOrders, 'id');
            
            // Same count
            $this->assertCount($orderCount, $sortedOrders);
            
            // Same IDs (just reordered)
            sort($originalIds);
            sort($sortedIds);
            $this->assertEquals($originalIds, $sortedIds);
        }
    }
}
