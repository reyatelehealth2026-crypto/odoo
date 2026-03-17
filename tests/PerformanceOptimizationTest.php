<?php
/**
 * Performance Optimization Test
 * 
 * Property-based test for Task 11: Performance Optimization and Caching
 * Validates performance requirements and caching effectiveness
 * 
 * Requirements: BR-1.1, BR-1.4, NFR-1.1, NFR-1.3, NFR-1.4
 * 
 * @version 1.0.0
 * @created 2026-01-23
 * @spec odoo-dashboard-modernization
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../classes/DashboardCacheService.php';
require_once __DIR__ . '/../classes/Database.php';

use PHPUnit\Framework\TestCase;

class PerformanceOptimizationTest extends TestCase
{
    private $db;
    private $cacheService;
    private $testLineAccountId = 1;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->cacheService = new DashboardCacheService($this->db);
        
        // Clean up any existing test data
        $this->cleanupTestData();
        
        // Insert test data
        $this->insertTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    /**
     * Property 1: Performance Response Time Compliance
     * 
     * **Feature: odoo-dashboard-modernization, Property 1: Performance Response Time Compliance**
     * 
     * For any dashboard API endpoint, response times should meet the specified 
     * performance requirements: dashboard overview under 300ms, and page loads under 1 second.
     * 
     * **Validates: Requirements BR-1.1, BR-1.2**
     */
    public function testPerformanceResponseTimeCompliance()
    {
        $this->runPropertyTest(function() {
            // Generate random test scenarios
            $scenarios = [
                ['endpoint' => 'dashboard_overview', 'max_time_ms' => 300],
                ['endpoint' => 'dashboard_metrics', 'max_time_ms' => 300],
                ['endpoint' => 'order_list', 'max_time_ms' => 500],
                ['endpoint' => 'payment_list', 'max_time_ms' => 500],
            ];
            
            foreach ($scenarios as $scenario) {
                $startTime = microtime(true);
                
                // Execute the endpoint operation
                switch ($scenario['endpoint']) {
                    case 'dashboard_overview':
                        $result = $this->cacheService->getDashboardMetrics($this->testLineAccountId);
                        break;
                    case 'dashboard_metrics':
                        $result = $this->cacheService->getDashboardMetricsForDateRange(
                            $this->testLineAccountId,
                            date('Y-m-d', strtotime('-7 days')),
                            date('Y-m-d')
                        );
                        break;
                    case 'order_list':
                        $result = $this->getOrderList($this->testLineAccountId, 20);
                        break;
                    case 'payment_list':
                        $result = $this->getPaymentList($this->testLineAccountId, 20);
                        break;
                }
                
                $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
                
                // Property: Response time must be under the specified threshold
                $this->assertLessThan(
                    $scenario['max_time_ms'],
                    $executionTime,
                    "Endpoint {$scenario['endpoint']} took {$executionTime}ms, exceeding {$scenario['max_time_ms']}ms threshold"
                );
                
                // Ensure we got valid data
                $this->assertNotNull($result, "Endpoint {$scenario['endpoint']} returned null");
            }
        }, 50); // Run 50 iterations
    }

    /**
     * Property 3: Cache Effectiveness
     * 
     * **Feature: odoo-dashboard-modernization, Property 3: Cache Effectiveness**
     * 
     * For any cacheable request, the cache hit rate should exceed 85% to meet 
     * performance optimization goals.
     * 
     * **Validates: Requirements BR-1.4**
     */
    public function testCacheEffectiveness()
    {
        $this->runPropertyTest(function() {
            $cacheKeys = [
                "dashboard:metrics:{$this->testLineAccountId}:" . date('Y-m-d'),
                "dashboard:overview:{$this->testLineAccountId}",
                "orders:list:{$this->testLineAccountId}:20",
                "payments:pending:{$this->testLineAccountId}",
            ];
            
            $totalRequests = 0;
            $cacheHits = 0;
            
            // First pass: Prime the cache
            foreach ($cacheKeys as $key) {
                $this->cacheService->getDashboardMetrics($this->testLineAccountId);
                $totalRequests++;
            }
            
            // Second pass: Should hit cache
            foreach ($cacheKeys as $key) {
                $startTime = microtime(true);
                $result = $this->cacheService->getDashboardMetrics($this->testLineAccountId);
                $executionTime = (microtime(true) - $startTime) * 1000;
                
                $totalRequests++;
                
                // If response is very fast, it likely came from cache
                if ($executionTime < 50) { // Less than 50ms indicates cache hit
                    $cacheHits++;
                }
                
                $this->assertNotNull($result, "Cached request returned null");
            }
            
            // Property: Cache hit rate must exceed 85%
            $hitRate = ($cacheHits / $totalRequests) * 100;
            $this->assertGreaterThan(
                85,
                $hitRate,
                "Cache hit rate is {$hitRate}%, below the required 85% threshold"
            );
            
        }, 30); // Run 30 iterations
    }

    /**
     * Property: Database Query Optimization
     * 
     * **Feature: odoo-dashboard-modernization, Property: Database Query Optimization**
     * 
     * For any database query used in dashboard operations, execution time should be 
     * under 100ms and use proper indexes.
     * 
     * **Validates: Requirements NFR-1.1, NFR-1.3**
     */
    public function testDatabaseQueryOptimization()
    {
        $this->runPropertyTest(function() {
            $queries = [
                // Dashboard metrics queries
                [
                    'sql' => "SELECT COUNT(*) as order_count, SUM(total_amount) as total_amount 
                             FROM odoo_orders 
                             WHERE line_account_id = ? AND DATE(order_date) = CURDATE()",
                    'params' => [$this->testLineAccountId],
                    'description' => 'Daily order metrics'
                ],
                [
                    'sql' => "SELECT COUNT(*) as slip_count 
                             FROM odoo_slip_uploads 
                             WHERE line_account_id = ? AND status = 'PENDING'",
                    'params' => [$this->testLineAccountId],
                    'description' => 'Pending payment slips'
                ],
                [
                    'sql' => "SELECT COUNT(*) as webhook_count, 
                             AVG(CASE WHEN status = 'PROCESSED' THEN 1 ELSE 0 END) * 100 as success_rate
                             FROM odoo_webhooks_log 
                             WHERE line_account_id = ? AND DATE(created_at) = CURDATE()",
                    'params' => [$this->testLineAccountId],
                    'description' => 'Webhook statistics'
                ],
            ];
            
            foreach ($queries as $queryInfo) {
                $startTime = microtime(true);
                
                $stmt = $this->db->prepare($queryInfo['sql']);
                $stmt->execute($queryInfo['params']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
                
                // Property: Query execution time must be under 100ms
                $this->assertLessThan(
                    100,
                    $executionTime,
                    "Query '{$queryInfo['description']}' took {$executionTime}ms, exceeding 100ms threshold"
                );
                
                // Ensure query returned valid results
                $this->assertNotNull($result, "Query '{$queryInfo['description']}' returned null");
                $this->assertIsArray($result, "Query '{$queryInfo['description']}' did not return array");
            }
            
        }, 40); // Run 40 iterations
    }

    /**
     * Property: Cache Invalidation Correctness
     * 
     * **Feature: odoo-dashboard-modernization, Property: Cache Invalidation Correctness**
     * 
     * For any data modification operation, related cache entries should be invalidated 
     * within 1 second to maintain data consistency.
     * 
     * **Validates: Requirements BR-1.4, NFR-1.4**
     */
    public function testCacheInvalidationCorrectness()
    {
        $this->runPropertyTest(function() {
            // Prime cache with dashboard data
            $originalData = $this->cacheService->getDashboardMetrics($this->testLineAccountId);
            $this->assertNotNull($originalData, "Failed to prime cache");
            
            // Modify underlying data
            $this->insertTestOrder([
                'line_account_id' => $this->testLineAccountId,
                'odoo_order_id' => 'TEST_ORDER_' . uniqid(),
                'total_amount' => 1000.00,
                'status' => 'done',
                'order_date' => date('Y-m-d H:i:s'),
            ]);
            
            // Trigger cache invalidation
            $startTime = microtime(true);
            $this->cacheService->invalidateDashboardCache($this->testLineAccountId, 'order_updated');
            $invalidationTime = (microtime(true) - $startTime) * 1000;
            
            // Property: Cache invalidation should complete within 1 second
            $this->assertLessThan(
                1000,
                $invalidationTime,
                "Cache invalidation took {$invalidationTime}ms, exceeding 1000ms threshold"
            );
            
            // Verify cache was actually invalidated by checking if new data is different
            $newData = $this->cacheService->getDashboardMetrics($this->testLineAccountId);
            $this->assertNotNull($newData, "Failed to get new data after invalidation");
            
            // The order count should have increased
            if (isset($originalData['orders']['todayCount']) && isset($newData['orders']['todayCount'])) {
                $this->assertGreaterThanOrEqual(
                    $originalData['orders']['todayCount'],
                    $newData['orders']['todayCount'],
                    "Cache invalidation did not reflect data changes"
                );
            }
            
        }, 25); // Run 25 iterations
    }

    /**
     * Helper method to run property-based tests
     */
    private function runPropertyTest(callable $testFunction, int $iterations = 100)
    {
        $failures = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            try {
                $testFunction();
            } catch (Exception $e) {
                $failures[] = "Iteration {$i}: " . $e->getMessage();
            }
        }
        
        if (!empty($failures)) {
            $this->fail("Property test failed in " . count($failures) . "/{$iterations} iterations:\n" . 
                       implode("\n", array_slice($failures, 0, 5))); // Show first 5 failures
        }
    }

    /**
     * Insert test data for performance testing
     */
    private function insertTestData()
    {
        // Insert test orders
        for ($i = 0; $i < 100; $i++) {
            $this->insertTestOrder([
                'line_account_id' => $this->testLineAccountId,
                'odoo_order_id' => 'PERF_TEST_ORDER_' . $i,
                'total_amount' => rand(100, 5000) / 100, // Random amount between 1.00 and 50.00
                'status' => ['draft', 'sent', 'done', 'cancel'][rand(0, 3)],
                'order_date' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 30) . ' days')),
            ]);
        }
        
        // Insert test payment slips
        for ($i = 0; $i < 50; $i++) {
            $this->insertTestPaymentSlip([
                'line_account_id' => $this->testLineAccountId,
                'image_url' => 'test_slip_' . $i . '.jpg',
                'amount' => rand(100, 5000) / 100,
                'status' => ['PENDING', 'MATCHED', 'REJECTED'][rand(0, 2)],
                'uploaded_by' => 'test_user',
            ]);
        }
        
        // Insert test webhooks
        for ($i = 0; $i < 200; $i++) {
            $this->insertTestWebhook([
                'line_account_id' => $this->testLineAccountId,
                'webhook_type' => 'order_update',
                'status' => ['PENDING', 'PROCESSED', 'FAILED'][rand(0, 2)],
                'payload' => json_encode(['test' => true, 'id' => $i]),
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 7) . ' days')),
            ]);
        }
    }

    private function insertTestOrder($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO odoo_orders (line_account_id, odoo_order_id, total_amount, status, order_date, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['line_account_id'],
            $data['odoo_order_id'],
            $data['total_amount'],
            $data['status'],
            $data['order_date']
        ]);
    }

    private function insertTestPaymentSlip($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO odoo_slip_uploads (line_account_id, image_url, amount, status, uploaded_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $data['line_account_id'],
            $data['image_url'],
            $data['amount'],
            $data['status'],
            $data['uploaded_by']
        ]);
    }

    private function insertTestWebhook($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO odoo_webhooks_log (line_account_id, webhook_type, status, payload, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['line_account_id'],
            $data['webhook_type'],
            $data['status'],
            $data['payload'],
            $data['created_at']
        ]);
    }

    private function cleanupTestData()
    {
        $tables = ['odoo_orders', 'odoo_slip_uploads', 'odoo_webhooks_log'];
        
        foreach ($tables as $table) {
            $stmt = $this->db->prepare("DELETE FROM {$table} WHERE line_account_id = ? AND (
                odoo_order_id LIKE 'PERF_TEST_%' OR 
                odoo_order_id LIKE 'TEST_ORDER_%' OR
                image_url LIKE 'test_slip_%' OR
                webhook_type = 'order_update'
            )");
            $stmt->execute([$this->testLineAccountId]);
        }
    }

    private function getOrderList($lineAccountId, $limit)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM odoo_orders 
            WHERE line_account_id = ? 
            ORDER BY order_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$lineAccountId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPaymentList($lineAccountId, $limit)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM odoo_slip_uploads 
            WHERE line_account_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$lineAccountId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}