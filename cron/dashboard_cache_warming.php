<?php
/**
 * Dashboard Cache Warming Cron Job
 * 
 * Warms critical dashboard cache data to ensure optimal performance
 * Runs every 5 minutes for critical data, hourly for historical data
 * 
 * Requirements: BR-1.4 (Cache hit rate >85%), NFR-1.4 (Multi-layer caching)
 * 
 * @version 1.0.0
 * @created 2026-01-23
 * @spec odoo-dashboard-modernization
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/DashboardCacheService.php';

// Set timezone
date_default_timezone_set('Asia/Bangkok');

// Initialize logging
$logFile = __DIR__ . '/../logs/cache_warming_' . date('Y-m-d') . '.log';
$startTime = microtime(true);

function logMessage($message, $level = 'INFO') {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    echo $logEntry;
}

try {
    logMessage("Starting dashboard cache warming job");
    
    // Get database connection
    $db = Database::getInstance()->getConnection();
    
    // Initialize cache service
    $cacheService = new DashboardCacheService($db);
    
    // Get current time and determine what to warm
    $currentMinute = (int)date('i');
    $currentHour = (int)date('H');
    
    // Critical data: Every 5 minutes
    if ($currentMinute % 5 === 0) {
        logMessage("Warming critical dashboard data");
        await warmCriticalData($cacheService);
    }
    
    // Real-time metrics: Every minute
    logMessage("Warming real-time metrics");
    await warmRealTimeMetrics($cacheService);
    
    // Historical data: Every hour
    if ($currentMinute === 0) {
        logMessage("Warming historical data");
        await warmHistoricalData($cacheService);
    }
    
    // Daily cleanup: At 2 AM
    if ($currentHour === 2 && $currentMinute === 0) {
        logMessage("Running daily cache cleanup");
        await cleanupExpiredCache($cacheService);
    }
    
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    logMessage("Cache warming completed successfully in {$executionTime}ms");
    
} catch (Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    logMessage("Cache warming failed after {$executionTime}ms: " . $e->getMessage(), 'ERROR');
    
    // Log to dev_logs table for monitoring
    try {
        $stmt = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'error',
            'cache_warming_cron',
            'Cache warming job failed',
            json_encode([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'execution_time_ms' => $executionTime
            ])
        ]);
    } catch (Exception $logError) {
        logMessage("Failed to log error to database: " . $logError->getMessage(), 'ERROR');
    }
    
    exit(1);
}

/**
 * Warm critical dashboard data for all active accounts
 */
async function warmCriticalData($cacheService) {
    try {
        // Get all active LINE accounts
        $accounts = getActiveLineAccounts();
        
        $warmedCount = 0;
        foreach ($accounts as $account) {
            // Warm main dashboard metrics
            await $cacheService->warmDashboardMetrics($account['id']);
            
            // Warm today's data
            $today = date('Y-m-d');
            await $cacheService->warmMetricsForDate($account['id'], $today);
            
            $warmedCount++;
        }
        
        logMessage("Warmed critical data for {$warmedCount} accounts");
        
    } catch (Exception $e) {
        logMessage("Failed to warm critical data: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * Warm real-time metrics that change frequently
 */
async function warmRealTimeMetrics($cacheService) {
    try {
        $accounts = getActiveLineAccounts();
        
        foreach ($accounts as $account) {
            // Warm current order counts
            await $cacheService->warmOrderCounts($account['id']);
            
            // Warm pending payment counts
            await $cacheService->warmPaymentCounts($account['id']);
            
            // Warm webhook statistics
            await $cacheService->warmWebhookStats($account['id']);
        }
        
        logMessage("Warmed real-time metrics for " . count($accounts) . " accounts");
        
    } catch (Exception $e) {
        logMessage("Failed to warm real-time metrics: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * Warm historical data for common date ranges
 */
async function warmHistoricalData($cacheService) {
    try {
        $accounts = getActiveLineAccounts();
        $dateRanges = getCommonDateRanges();
        
        $totalWarmed = 0;
        foreach ($accounts as $account) {
            foreach ($dateRanges as $range) {
                await $cacheService->warmMetricsForDateRange(
                    $account['id'], 
                    $range['from'], 
                    $range['to']
                );
                $totalWarmed++;
            }
        }
        
        logMessage("Warmed historical data: {$totalWarmed} cache entries");
        
    } catch (Exception $e) {
        logMessage("Failed to warm historical data: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * Clean up expired cache entries
 */
async function cleanupExpiredCache($cacheService) {
    try {
        $cleanedCount = await $cacheService->cleanupExpiredEntries();
        logMessage("Cleaned up {$cleanedCount} expired cache entries");
        
        // Update cache statistics
        await $cacheService->updateCacheStatistics();
        logMessage("Updated cache statistics");
        
    } catch (Exception $e) {
        logMessage("Failed to cleanup expired cache: " . $e->getMessage(), 'ERROR');
        throw $e;
    }
}

/**
 * Get all active LINE accounts
 */
function getActiveLineAccounts() {
    global $db;
    
    $stmt = $db->prepare("
        SELECT id, name, channel_id 
        FROM line_accounts 
        WHERE is_active = 1 
        ORDER BY id
    ");
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get common date ranges for cache warming
 */
function getCommonDateRanges() {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));
    $monthStart = date('Y-m-01');
    
    return [
        ['from' => $yesterday, 'to' => $yesterday, 'label' => 'yesterday'],
        ['from' => $weekAgo, 'to' => $today, 'label' => 'last_7_days'],
        ['from' => $monthAgo, 'to' => $today, 'label' => 'last_30_days'],
        ['from' => $monthStart, 'to' => $today, 'label' => 'this_month'],
    ];
}

/**
 * Async function wrapper for PHP (simulated)
 */
function await($promise) {
    // In a real implementation with ReactPHP or Swoole, this would handle async operations
    // For now, we'll execute synchronously
    return $promise;
}