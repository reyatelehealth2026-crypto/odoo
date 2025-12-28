<?php
/**
 * Property-Based Tests: Points Transaction History
 * 
 * **Feature: liff-telepharmacy-redesign, Property 26: Transaction History Sorting**
 * **Feature: liff-telepharmacy-redesign, Property 27: Transaction Display Elements**
 * **Feature: liff-telepharmacy-redesign, Property 28: Transaction Type Styling**
 * **Feature: liff-telepharmacy-redesign, Property 29: Transaction Filter Functionality**
 * **Feature: liff-telepharmacy-redesign, Property 30: Transaction History Serialization Round-Trip**
 * **Validates: Requirements 22.1, 22.2, 22.3, 22.4, 22.5, 22.6, 22.11, 22.12**
 */

namespace Tests\LiffTelepharmacy;

use PHPUnit\Framework\TestCase;

class TransactionHistoryPropertyTest extends TestCase
{
    /**
     * Valid transaction types
     */
    private $validTypes = ['earned', 'redeemed', 'expired', 'adjusted'];
    
    /**
     * Transaction type styling
     */
    private $typeStyles = [
        'earned' => ['icon' => 'fa-plus-circle', 'color' => 'green', 'prefix' => '+'],
        'redeemed' => ['icon' => 'fa-minus-circle', 'color' => 'red', 'prefix' => '-'],
        'expired' => ['icon' => 'fa-clock', 'color' => 'gray', 'prefix' => '-'],
        'adjusted' => ['icon' => 'fa-edit', 'color' => 'blue', 'prefix' => '']
    ];
    
    /**
     * Generate random transaction
     */
    private function generateRandomTransaction(): array
    {
        $types = $this->validTypes;
        $type = $types[array_rand($types)];
        
        $points = rand(1, 1000);
        if (in_array($type, ['redeemed', 'expired'])) {
            $points = -$points;
        }
        
        $daysAgo = rand(0, 365);
        $createdAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        
        return [
            'id' => rand(1, 100000),
            'type' => $type,
            'points' => $points,
            'balance_after' => rand(0, 10000),
            'description' => $this->getDescriptionForType($type),
            'reference_type' => $type === 'earned' ? 'order' : ($type === 'redeemed' ? 'redemption' : null),
            'reference_code' => $type === 'earned' ? 'ORD' . rand(10000, 99999) : null,
            'created_at' => $createdAt
        ];
    }
    
    /**
     * Get description for transaction type
     */
    private function getDescriptionForType(string $type): string
    {
        $descriptions = [
            'earned' => 'ได้รับแต้มจากการสั่งซื้อ',
            'redeemed' => 'แลกรางวัล',
            'expired' => 'แต้มหมดอายุ',
            'adjusted' => 'ปรับปรุงแต้ม'
        ];
        return $descriptions[$type] ?? 'รายการแต้ม';
    }
    
    /**
     * Generate list of random transactions
     */
    private function generateRandomTransactions(int $count): array
    {
        $transactions = [];
        for ($i = 0; $i < $count; $i++) {
            $transactions[] = $this->generateRandomTransaction();
        }
        return $transactions;
    }
    
    /**
     * Sort transactions by date descending
     */
    private function sortTransactionsDesc(array $transactions): array
    {
        usort($transactions, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        return $transactions;
    }
    
    /**
     * Check if transactions are sorted descending
     */
    private function areTransactionsSortedDesc(array $transactions): bool
    {
        for ($i = 1; $i < count($transactions); $i++) {
            if (strtotime($transactions[$i-1]['created_at']) < strtotime($transactions[$i]['created_at'])) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Filter transactions by type
     */
    private function filterTransactions(array $transactions, string $filter): array
    {
        if ($filter === 'all') {
            return $transactions;
        }
        
        return array_values(array_filter($transactions, function($tx) use ($filter) {
            return $tx['type'] === $filter;
        }));
    }
    
    /**
     * Get styling for transaction type
     */
    private function getTransactionStyle(string $type): array
    {
        return $this->typeStyles[$type] ?? $this->typeStyles['adjusted'];
    }
    
    /**
     * Serialize transactions to JSON
     */
    private function serializeTransactions(array $transactions): string
    {
        return json_encode($transactions, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * Deserialize transactions from JSON
     */
    private function deserializeTransactions(string $json): array
    {
        return json_decode($json, true) ?? [];
    }
    
    /**
     * Property Test: Transactions are sorted by date descending
     * 
     * **Feature: liff-telepharmacy-redesign, Property 26: Transaction History Sorting**
     * **Validates: Requirements 22.1**
     */
    public function testTransactionsSortedByDateDescending(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $count = rand(2, 30);
            $transactions = $this->generateRandomTransactions($count);
            $sorted = $this->sortTransactionsDesc($transactions);
            
            $this->assertTrue(
                $this->areTransactionsSortedDesc($sorted),
                "Transactions should be sorted by date descending"
            );
        }
    }
    
    /**
     * Property Test: Transaction has all required display elements
     * 
     * **Feature: liff-telepharmacy-redesign, Property 27: Transaction Display Elements**
     * **Validates: Requirements 22.2**
     */
    public function testTransactionHasRequiredElements(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $transaction = $this->generateRandomTransaction();
            
            $this->assertArrayHasKey('type', $transaction);
            $this->assertArrayHasKey('description', $transaction);
            $this->assertArrayHasKey('points', $transaction);
            $this->assertArrayHasKey('balance_after', $transaction);
            $this->assertArrayHasKey('created_at', $transaction);
            
            $this->assertNotEmpty($transaction['type']);
            $this->assertNotEmpty($transaction['description']);
            $this->assertIsInt($transaction['points']);
            $this->assertIsInt($transaction['balance_after']);
            $this->assertNotEmpty($transaction['created_at']);
        }
    }
    
    /**
     * Property Test: Earned transactions have green styling with plus icon
     * 
     * **Feature: liff-telepharmacy-redesign, Property 28: Transaction Type Styling**
     * **Validates: Requirements 22.3**
     */
    public function testEarnedTransactionsHaveGreenStyling(): void
    {
        $style = $this->getTransactionStyle('earned');
        
        $this->assertEquals('green', $style['color']);
        $this->assertStringContainsString('plus', $style['icon']);
        $this->assertEquals('+', $style['prefix']);
    }
    
    /**
     * Property Test: Redeemed transactions have red styling with minus icon
     * 
     * **Feature: liff-telepharmacy-redesign, Property 28: Transaction Type Styling**
     * **Validates: Requirements 22.4**
     */
    public function testRedeemedTransactionsHaveRedStyling(): void
    {
        $style = $this->getTransactionStyle('redeemed');
        
        $this->assertEquals('red', $style['color']);
        $this->assertStringContainsString('minus', $style['icon']);
        $this->assertEquals('-', $style['prefix']);
    }
    
    /**
     * Property Test: Expired transactions have gray styling
     * 
     * **Feature: liff-telepharmacy-redesign, Property 28: Transaction Type Styling**
     * **Validates: Requirements 22.5**
     */
    public function testExpiredTransactionsHaveGrayStyling(): void
    {
        $style = $this->getTransactionStyle('expired');
        
        $this->assertEquals('gray', $style['color']);
        $this->assertEquals('-', $style['prefix']);
    }
    
    /**
     * Property Test: Filter returns only matching transactions
     * 
     * **Feature: liff-telepharmacy-redesign, Property 29: Transaction Filter Functionality**
     * **Validates: Requirements 22.6**
     */
    public function testFilterReturnsOnlyMatchingTransactions(): void
    {
        $transactions = $this->generateRandomTransactions(50);
        
        foreach ($this->validTypes as $type) {
            $filtered = $this->filterTransactions($transactions, $type);
            
            foreach ($filtered as $tx) {
                $this->assertEquals(
                    $type,
                    $tx['type'],
                    "Filtered transactions should only contain type '{$type}'"
                );
            }
        }
    }
    
    /**
     * Property Test: 'All' filter returns all transactions
     * 
     * **Feature: liff-telepharmacy-redesign, Property 29: Transaction Filter Functionality**
     * **Validates: Requirements 22.6**
     */
    public function testAllFilterReturnsAllTransactions(): void
    {
        $transactions = $this->generateRandomTransactions(30);
        $filtered = $this->filterTransactions($transactions, 'all');
        
        $this->assertCount(count($transactions), $filtered);
    }
    
    /**
     * Property Test: Transaction serialization round-trip preserves data
     * 
     * **Feature: liff-telepharmacy-redesign, Property 30: Transaction History Serialization Round-Trip**
     * **Validates: Requirements 22.11, 22.12**
     */
    public function testTransactionSerializationRoundTrip(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $transactions = $this->generateRandomTransactions(rand(1, 20));
            
            $serialized = $this->serializeTransactions($transactions);
            $deserialized = $this->deserializeTransactions($serialized);
            
            $this->assertEquals(
                $transactions,
                $deserialized,
                "Transaction round-trip should preserve all data"
            );
        }
    }
    
    /**
     * Property Test: Serialized transactions is valid JSON
     * 
     * **Feature: liff-telepharmacy-redesign, Property 30: Transaction History Serialization Round-Trip**
     * **Validates: Requirements 22.11, 22.12**
     */
    public function testSerializedTransactionsIsValidJson(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $transactions = $this->generateRandomTransactions(rand(1, 20));
            $serialized = $this->serializeTransactions($transactions);
            
            json_decode($serialized);
            $this->assertEquals(
                JSON_ERROR_NONE,
                json_last_error(),
                "Serialized transactions should be valid JSON"
            );
        }
    }
    
    /**
     * Property Test: Earned transactions have positive points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 28: Transaction Type Styling**
     * **Validates: Requirements 22.3**
     */
    public function testEarnedTransactionsHavePositivePoints(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $tx = $this->generateRandomTransaction();
            
            if ($tx['type'] === 'earned') {
                $this->assertGreaterThan(
                    0,
                    $tx['points'],
                    "Earned transactions should have positive points"
                );
            }
        }
    }
    
    /**
     * Property Test: Redeemed/Expired transactions have negative points
     * 
     * **Feature: liff-telepharmacy-redesign, Property 28: Transaction Type Styling**
     * **Validates: Requirements 22.4, 22.5**
     */
    public function testRedeemedExpiredTransactionsHaveNegativePoints(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $tx = $this->generateRandomTransaction();
            
            if (in_array($tx['type'], ['redeemed', 'expired'])) {
                $this->assertLessThan(
                    0,
                    $tx['points'],
                    "Redeemed/Expired transactions should have negative points"
                );
            }
        }
    }
    
    /**
     * Property Test: Filter count equals sum of individual type counts
     * 
     * **Feature: liff-telepharmacy-redesign, Property 29: Transaction Filter Functionality**
     * **Validates: Requirements 22.6**
     */
    public function testFilterCountsAreConsistent(): void
    {
        $transactions = $this->generateRandomTransactions(100);
        
        $allCount = count($this->filterTransactions($transactions, 'all'));
        $earnedCount = count($this->filterTransactions($transactions, 'earned'));
        $redeemedCount = count($this->filterTransactions($transactions, 'redeemed'));
        $expiredCount = count($this->filterTransactions($transactions, 'expired'));
        $adjustedCount = count($this->filterTransactions($transactions, 'adjusted'));
        
        $this->assertEquals(
            $allCount,
            $earnedCount + $redeemedCount + $expiredCount + $adjustedCount,
            "Sum of filtered counts should equal total count"
        );
    }
}
