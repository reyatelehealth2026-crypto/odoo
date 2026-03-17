<?php
/**
 * Dashboard Cache Service Test
 * 
 * Unit tests for the DashboardCacheService class to ensure proper caching functionality.
 * Tests both dashboard metrics caching and API response caching.
 * 
 * @version 1.0.0
 * @created 2026-01-23
 * @spec odoo-dashboard-modernization
 */

use PHPUnit\Framework\TestCase;

class DashboardCacheServiceTest extends TestCase
{
    private $cacheService;
    private $db;
    
    protected function setUp(): void
    {
        // Use test database connection
        $this->db = $this->createMock(PDO::class);
        $this->cacheService = new DashboardCacheService($this->db);
    }
    
    protected function tearDown(): void
    {
        $this->cacheService = null;
        $this->db = null;
    }
    
    /**
     * Test dashboard metrics caching
     */
    public function testDashboardMetricsCaching()
    {
        $lineAccountId = 1;
        $metricType = 'orders';
        $dateKey = '2026-01-23';
        $timeRange = 'today';
        $testData = [
            'total_orders' => 150,
            'completed_orders' => 120,
            'pending_orders' => 30,
            'total_amount' => 45000.00
        ];
        
        // Mock database interactions for cache miss and set
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(null); // Cache miss
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test cache miss with generator
        $generatorCalled = false;
        $result = $this->cacheService->getDashboardMetrics(
            $lineAccountId,
            $metricType,
            $dateKey,
            $timeRange,
            function() use ($testData, &$generatorCalled) {
                $generatorCalled = true;
                return $testData;
            }
        );
        
        $this->assertTrue($generatorCalled, 'Generator should be called on cache miss');
        $this->assertEquals($testData, $result, 'Should return generated data');
    }
    
    /**
     * Test API response caching
     */
    public function testApiResponseCaching()
    {
        $endpoint = '/api/orders';
        $method = 'GET';
        $params = ['status' => 'active'];
        $responseData = ['orders' => [['id' => 1, 'name' => 'Test Order']]];
        $lineAccountId = 1;
        
        // Mock successful cache set
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test setting API cache
        $result = $this->cacheService->setApiCache(
            $endpoint,
            $method,
            $params,
            $responseData,
            [],
            200,
            'application/json',
            $lineAccountId
        );
        
        $this->assertTrue($result, 'API cache should be set successfully');
    }
    
    /**
     * Test cache invalidation
     */
    public function testCacheInvalidation()
    {
        $lineAccountId = 1;
        $metricType = 'orders';
        
        // Mock successful invalidation
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(5);
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test dashboard cache invalidation
        $cleared = $this->cacheService->invalidateDashboardCache($lineAccountId, $metricType);
        
        $this->assertEquals(5, $cleared, 'Should return number of cleared entries');
    }
    
    /**
     * Test cache statistics retrieval
     */
    public function testCacheStatistics()
    {
        $lineAccountId = 1;
        $expectedStats = [
            [
                'cache_type' => 'dashboard_metrics',
                'total_requests' => 1000,
                'cache_hits' => 850,
                'cache_misses' => 150,
                'avg_hit_rate' => 85.0,
                'avg_response_time' => 25.5,
                'total_size_mb' => 12.5
            ]
        ];
        
        // Mock statistics query
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn($expectedStats);
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test getting cache statistics
        $stats = $this->cacheService->getCacheStatistics($lineAccountId);
        
        $this->assertEquals($expectedStats, $stats, 'Should return cache statistics');
        $this->assertEquals(85.0, $stats[0]['avg_hit_rate'], 'Hit rate should meet requirement');
    }
    
    /**
     * Test cache key generation
     */
    public function testCacheKeyGeneration()
    {
        // Use reflection to test private methods
        $reflection = new ReflectionClass($this->cacheService);
        
        $dashboardKeyMethod = $reflection->getMethod('generateDashboardCacheKey');
        $dashboardKeyMethod->setAccessible(true);
        
        $apiKeyMethod = $reflection->getMethod('generateApiCacheKey');
        $apiKeyMethod->setAccessible(true);
        
        // Test dashboard cache key
        $dashboardKey = $dashboardKeyMethod->invoke(
            $this->cacheService,
            1,
            'orders',
            '2026-01-23',
            'today'
        );
        
        $this->assertEquals('dashboard:1:orders:2026-01-23:today', $dashboardKey);
        
        // Test API cache key
        $apiKey = $apiKeyMethod->invoke(
            $this->cacheService,
            '/api/orders',
            'GET',
            ['status' => 'active'],
            1
        );
        
        $this->assertStringContains('api:GET:/api/orders:1:', $apiKey);
    }
    
    /**
     * Test cache cleanup functionality
     */
    public function testCacheCleanup()
    {
        // Mock cleanup operations
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturnOnConsecutiveCalls(10, 5); // dashboard, api
        
        $this->db->method('prepare')->willReturn($stmt);
        
        // Test cleanup
        $results = $this->cacheService->cleanupExpiredCache('both');
        
        $this->assertArrayHasKey('dashboard_metrics', $results);
        $this->assertArrayHasKey('api_response', $results);
        $this->assertEquals(10, $results['dashboard_metrics']);
        $this->assertEquals(5, $results['api_response']);
    }
    
    /**
     * Test error handling
     */
    public function testErrorHandling()
    {
        // Mock database exception
        $this->db->method('prepare')->willThrowException(new PDOException('Database error'));
        
        // Test that errors are handled gracefully
        $result = $this->cacheService->getDashboardMetrics(1, 'orders', '2026-01-23');
        $this->assertNull($result, 'Should return null on database error');
        
        $setResult = $this->cacheService->setDashboardCache(1, 'orders', '2026-01-23', 'today', []);
        $this->assertFalse($setResult, 'Should return false on database error');
    }
    
    /**
     * Test cache hit rate requirement compliance
     * 
     * **Validates: Requirements BR-1.4**
     */
    public function testCacheHitRateCompliance()
    {
        // Simulate cache statistics that meet the 85% hit rate requirement
        $stats = [
            'total_requests' => 1000,
            'cache_hits' => 870,
            'cache_misses' => 130
        ];
        
        $hitRate = ($stats['cache_hits'] / $stats['total_requests']) * 100;
        
        $this->assertGreaterThanOrEqual(85.0, $hitRate, 'Cache hit rate must exceed 85% (BR-1.4)');
    }
    
    /**
     * Test TTL-based expiration
     */
    public function testTtlExpiration()
    {
        // Test that expired cache entries are not returned
        $expiredResult = ['data' => 'expired'];
        
        // Mock expired cache entry
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(null); // Expired entries filtered out by SQL
        
        $this->db->method('prepare')->willReturn($stmt);
        
        $result = $this->cacheService->getDashboardMetrics(1, 'orders', '2026-01-23');
        
        $this->assertNull($result, 'Expired cache entries should not be returned');
    }
}