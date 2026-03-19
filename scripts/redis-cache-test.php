<?php
/**
 * Universal Cache Test Script
 * ทดสอบ Redis, Native Redis, หรือ File Cache
 */

require_once __DIR__ . '/../classes/OdooRedisCache.php';

header('Content-Type: text/plain; charset=utf-8');

echo "═══════════════════════════════════════════════════════\n";
echo "Universal Cache Test Script\n";
echo "═══════════════════════════════════════════════════════\n\n";

$cache = OdooRedisCache::getInstance();

// 1. Check which cache type is being used
echo "1. Cache Type Detection\n";
echo "   Type: " . $cache->getType() . "\n";
echo "   Enabled: " . ($cache->isEnabled() ? 'Yes' : 'No') . "\n\n";

// 2. Test basic operations
echo "2. Testing Basic Operations\n";

// Set
$testData = [
    'test' => true,
    'time' => date('c'),
    'random' => rand(1, 1000)
];

$cache->set('test:basic', $testData, 60);
echo "   ✅ Set cache\n";

// Get
$value = $cache->get('test:basic');
if ($value && $value['test'] === true) {
    echo "   ✅ Get cache: " . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "   ❌ Get cache failed\n";
}

// Delete
$cache->delete('test:basic');
$deleted = $cache->get('test:basic');
if ($deleted === null) {
    echo "   ✅ Delete cache\n";
} else {
    echo "   ❌ Delete failed\n";
}
echo "\n";

// 3. Test Remember Pattern
echo "3. Testing Remember Pattern\n";
$callCount = 0;

$result = $cache->remember('test:remember', 60, function() use (&$callCount) {
    $callCount++;
    return [
        'computed' => true,
        'value' => rand(1, 100),
        'timestamp' => time()
    ];
});
echo "   First call: " . json_encode($result) . "\n";
echo "   Call count: $callCount\n";

// Second call should use cache
$result2 = $cache->remember('test:remember', 60, function() use (&$callCount) {
    $callCount++;
    return ['computed' => true, 'value' => rand(1, 100)];
});
echo "   Second call (cached): " . json_encode($result2) . "\n";
echo "   Call count: $callCount (should be 1 if cache works)\n";

if ($callCount === 1) {
    echo "   ✅ Cache working correctly!\n";
} else {
    echo "   ⚠️  Cache might not be working\n";
}
echo "\n";

// 4. Test Cache Key Generation
echo "4. Testing Cache Key Generation\n";
$keys = [
    OdooRedisCache::key('overview', 1),
    OdooRedisCache::key('stats', 1, 'today'),
    OdooRedisCache::key('orders', 2, 'p1l50'),
];
foreach ($keys as $key) {
    echo "   ✅ $key\n";
}
echo "\n";

// 5. Performance Test
echo "5. Performance Test\n";
$iterations = 100;

// Warm up cache
$cache->set('perf:test', ['data' => 'test'], 60);

// With cache
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $cache->get('perf:test');
}
$withCache = (microtime(true) - $start) * 1000;
echo "   $iterations reads with cache: " . round($withCache, 2) . " ms\n";
echo "   Average per read: " . round($withCache / $iterations, 4) . " ms\n";

if ($withCache < 100) {
    echo "   ✅ Excellent performance!\n";
} elseif ($withCache < 500) {
    echo "   ✅ Good performance\n";
} else {
    echo "   ⚠️  Performance could be improved\n";
}
echo "\n";

// 6. Stats
echo "6. Cache Statistics\n";
$stats = $cache->getStats();
echo "   Enabled: " . ($stats['enabled'] ? 'Yes' : 'No') . "\n";
echo "   Type: " . $stats['type'] . "\n";
echo "   Hits: " . $stats['hits'] . "\n";
echo "   Misses: " . $stats['misses'] . "\n";
echo "   Hit Rate: " . $stats['hit_rate'] . "%\n\n";

// 7. Cleanup
echo "7. Cleanup\n";
$cache->delete('test:remember');
$cache->delete('perf:test');
echo "   ✅ Test data cleaned up\n\n";

// Summary
echo "═══════════════════════════════════════════════════════\n";
if ($cache->isEnabled()) {
    echo "✅ Cache is working!\n";
    echo "Type: " . $cache->getType() . "\n";
    if ($cache->getType() === 'file') {
        echo "\n⚠️  Using file-based cache (slower than Redis)\n";
        echo "To enable Redis:\n";
        echo "1. Install php-redis: sudo apt-get install php-redis\n";
        echo "2. Or install Predis: bash scripts/install-predis.sh\n";
    }
} else {
    echo "❌ No caching available\n";
    echo "Install php-redis or Predis to enable caching\n";
}
echo "═══════════════════════════════════════════════════════\n";
