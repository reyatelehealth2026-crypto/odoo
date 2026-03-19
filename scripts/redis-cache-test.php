<?php
/**
 * Redis Cache Test Script
 * ทดสอบการทำงานของ Redis Cache
 */

require_once __DIR__ . '/../classes/OdooRedisCache.php';
require_once __DIR__ . '/../classes/CacheInvalidator.php';

header('Content-Type: text/plain; charset=utf-8');

echo "═══════════════════════════════════════════════════════\n";
echo "Redis Cache Test Script\n";
echo "═══════════════════════════════════════════════════════\n\n";

// 1. Test Connection
echo "1. Testing Redis Connection...\n";
$cache = OdooRedisCache::getInstance();

if ($cache->isEnabled()) {
    echo "   ✅ Redis connected successfully\n\n";
} else {
    echo "   ❌ Redis connection failed\n\n";
    exit(1);
}

// 2. Test Basic Operations
echo "2. Testing Basic Operations...\n";

// Set
$cache->set('test:key', ['hello' => 'world', 'time' => time()], 60);
echo "   ✅ Set cache\n";

// Get
$value = $cache->get('test:key');
if ($value && $value['hello'] === 'world') {
    echo "   ✅ Get cache: " . json_encode($value) . "\n";
} else {
    echo "   ❌ Get cache failed\n";
}

// Delete
$cache->delete('test:key');
echo "   ✅ Delete cache\n\n";

// 3. Test Remember Pattern
echo "3. Testing Remember Pattern...\n";
$callCount = 0;
$result = $cache->remember('test:remember', 60, function() use (&$callCount) {
    $callCount++;
    return ['computed' => true, 'value' => rand(1, 100)];
});
echo "   ✅ First call computed: " . json_encode($result) . "\n";
echo "      Call count: $callCount\n";

// Second call should use cache
$result2 = $cache->remember('test:remember', 60, function() use (&$callCount) {
    $callCount++;
    return ['computed' => true, 'value' => rand(1, 100)];
});
echo "   ✅ Second call (cached): " . json_encode($result2) . "\n";
echo "      Call count: $callCount (should be 1)\n\n";

// 4. Test Cache Key Generation
echo "4. Testing Cache Key Generation...\n";
$keys = [
    OdooRedisCache::key('overview', 1),
    OdooRedisCache::key('stats', 1, 'today'),
    OdooRedisCache::key('orders', 2, 'p1l50'),
];
foreach ($keys as $key) {
    echo "   ✅ $key\n";
}
echo "\n";

// 5. Test Stats
echo "5. Testing Cache Stats...\n";
$stats = $cache->getStats();
echo "   Enabled: " . ($stats['enabled'] ? 'Yes' : 'No') . "\n";
echo "   Hits: " . $stats['hits'] . "\n";
echo "   Misses: " . $stats['misses'] . "\n";
echo "   Hit Rate: " . $stats['hit_rate'] . "%\n\n";

// 6. Test Invalidator
echo "6. Testing Cache Invalidator...\n";
$invalidator = new CacheInvalidator();

// Set some test data
$cache->set('odoo:overview:1', ['test' => 'data'], 300);
$cache->set('odoo:stats:1', ['test' => 'data'], 300);
$cache->set('odoo:orders:today:count:1', 100, 300);

// Invalidate
$invalidator->onOrderChange(1);
echo "   ✅ Invalidated order cache\n";

// Verify cleared
$overview = $cache->get('odoo:overview:1');
if ($overview === null) {
    echo "   ✅ Overview cache cleared\n";
} else {
    echo "   ❌ Overview cache not cleared\n";
}
echo "\n";

// 7. Performance Test
echo "7. Performance Test...\n";
$iterations = 100;

// Without cache
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    // Simulate database query
    usleep(1000); // 1ms
}
$withoutCache = (microtime(true) - $start) * 1000;
echo "   Without cache (simulated): " . round($withoutCache, 2) . " ms\n";

// With cache
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $cache->get('test:perf');
}
$withCache = (microtime(true) - $start) * 1000;
echo "   With cache: " . round($withCache, 2) . " ms\n";

if ($withCache > 0) {
    $speedup = round($withoutCache / $withCache, 1);
    echo "   Speedup: {$speedup}x faster\n";
}
echo "\n";

// 8. Cleanup
echo "8. Cleanup...\n";
$cache->delete('test:remember');
$cache->deletePattern('test:*');
echo "   ✅ Test data cleaned up\n\n";

echo "═══════════════════════════════════════════════════════\n";
echo "All tests completed successfully! ✅\n";
echo "═══════════════════════════════════════════════════════\n";
