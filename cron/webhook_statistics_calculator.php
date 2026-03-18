<?php
/**
 * Webhook Statistics Calculator
 * 
 * Calculates and caches webhook statistics for dashboard performance.
 * This cron job should run every hour to update statistics.
 * 
 * Schedule: 0 * * * * (every hour)
 * 
 * @version 1.0.0
 * @created 2026-01-23
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Prevent multiple instances
$lockFile = __DIR__ . '/../tmp/webhook_stats_calculator.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 3600) {
    echo "Another instance is already running. Exiting.\n";
    exit(0);
}
file_put_contents($lockFile, getmypid());

// Cleanup lock file on exit
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

try {
    $db = Database::getInstance()->getConnection();
    $startTime = microtime(true);
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting webhook statistics calculation...\n";
    
    // Calculate statistics for the last 24 hours
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $today = date('Y-m-d');
    
    $dates = [$yesterday, $today];
    $totalCalculated = 0;
    
    foreach ($dates as $date) {
        echo "Calculating statistics for {$date}...\n";
        
        // Get all line account IDs
        $stmt = $db->prepare("
            SELECT DISTINCT line_account_id 
            FROM odoo_webhooks_log 
            WHERE DATE(created_at) = ? 
            AND line_account_id IS NOT NULL
        ");
        $stmt->execute([$date]);
        $accountIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Add null for global statistics
        $accountIds[] = null;
        
        foreach ($accountIds as $accountId) {
            $calculated = calculateStatisticsForDate($db, $date, $accountId);
            $totalCalculated += $calculated;
            
            if ($accountId) {
                echo "  - Account {$accountId}: {$calculated} webhook types processed\n";
            } else {
                echo "  - Global: {$calculated} webhook types processed\n";
            }
        }
    }
    
    // Calculate hourly statistics for today
    echo "Calculating hourly statistics for today...\n";
    $hourlyCalculated = calculateHourlyStatistics($db, $today);
    echo "  - {$hourlyCalculated} hourly statistics calculated\n";
    
    // Check and create performance alerts
    echo "Checking performance alerts...\n";
    $alertsCreated = checkPerformanceAlerts($db);
    echo "  - {$alertsCreated} alerts processed\n";
    
    // Clean up old statistics
    echo "Cleaning up old statistics...\n";
    $cleanedUp = cleanupOldStatistics($db);
    echo "  - {$cleanedUp} old statistics removed\n";
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "\nStatistics calculation completed:\n";
    echo "  - Daily statistics: {$totalCalculated}\n";
    echo "  - Hourly statistics: {$hourlyCalculated}\n";
    echo "  - Alerts processed: {$alertsCreated}\n";
    echo "  - Old stats cleaned: {$cleanedUp}\n";
    echo "  - Duration: {$duration}ms\n";
    
    // Log summary to database
    $stmt = $db->prepare("
        INSERT INTO dev_logs (log_type, source, message, data, created_at) 
        VALUES ('info', 'webhook_stats_calculator', ?, ?, NOW())
    ");
    
    $stmt->execute([
        'Webhook statistics calculation completed',
        json_encode([
            'daily_calculated' => $totalCalculated,
            'hourly_calculated' => $hourlyCalculated,
            'alerts_processed' => $alertsCreated,
            'old_stats_cleaned' => $cleanedUp,
            'duration_ms' => $duration
        ], JSON_UNESCAPED_UNICODE)
    ]);
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    
    // Log error to database
    try {
        $stmt = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at) 
            VALUES ('error', 'webhook_stats_calculator', ?, ?, NOW())
        ");
        
        $stmt->execute([
            'Webhook statistics calculator failed: ' . $e->getMessage(),
            json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $logError) {
        error_log("Failed to log webhook stats calculator error: " . $logError->getMessage());
    }
    
    exit(1);
}

/**
 * Calculate statistics for a specific date and account
 */
function calculateStatisticsForDate($db, $date, $accountId)
{
    // Get all webhook types for this date and account
    $whereConditions = ['DATE(created_at) = ?'];
    $params = [$date];
    
    if ($accountId) {
        $whereConditions[] = 'line_account_id = ?';
        $params[] = $accountId;
    } else {
        $whereConditions[] = 'line_account_id IS NULL';
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $stmt = $db->prepare("
        SELECT DISTINCT webhook_type 
        FROM odoo_webhooks_log 
        {$whereClause}
        AND webhook_type IS NOT NULL
    ");
    $stmt->execute($params);
    $webhookTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $calculated = 0;
    
    foreach ($webhookTypes as $webhookType) {
        // Calculate daily statistics
        $stmt = $db->prepare("
            INSERT INTO webhook_statistics_cache (
                line_account_id, webhook_type, stat_date, stat_hour,
                total_count, processed_count, failed_count, retry_count, dlq_count, duplicate_count,
                avg_processing_time_ms, max_processing_time_ms, min_processing_time_ms,
                success_rate, most_common_event
            )
            SELECT 
                ?,
                ?,
                ?,
                NULL, -- Daily stats
                COUNT(*) as total_count,
                COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed_count,
                COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed_count,
                COUNT(CASE WHEN status = 'RETRY' THEN 1 END) as retry_count,
                COUNT(CASE WHEN status = 'DLQ' THEN 1 END) as dlq_count,
                COUNT(CASE WHEN status = 'DUPLICATE' THEN 1 END) as duplicate_count,
                AVG(process_latency_ms) as avg_processing_time_ms,
                MAX(process_latency_ms) as max_processing_time_ms,
                MIN(process_latency_ms) as min_processing_time_ms,
                CASE 
                    WHEN COUNT(*) > 0 THEN ROUND((COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) / COUNT(*)) * 100, 2)
                    ELSE 0 
                END as success_rate,
                (
                    SELECT event_type 
                    FROM odoo_webhooks_log w2 
                    WHERE w2.webhook_type = ? 
                    AND DATE(w2.created_at) = ?
                    " . ($accountId ? "AND w2.line_account_id = {$accountId}" : "AND w2.line_account_id IS NULL") . "
                    GROUP BY event_type 
                    ORDER BY COUNT(*) DESC 
                    LIMIT 1
                ) as most_common_event
            FROM odoo_webhooks_log w
            WHERE w.webhook_type = ? 
            AND DATE(w.created_at) = ?
            " . ($accountId ? "AND w.line_account_id = {$accountId}" : "AND w.line_account_id IS NULL") . "
            ON DUPLICATE KEY UPDATE
                total_count = VALUES(total_count),
                processed_count = VALUES(processed_count),
                failed_count = VALUES(failed_count),
                retry_count = VALUES(retry_count),
                dlq_count = VALUES(dlq_count),
                duplicate_count = VALUES(duplicate_count),
                avg_processing_time_ms = VALUES(avg_processing_time_ms),
                max_processing_time_ms = VALUES(max_processing_time_ms),
                min_processing_time_ms = VALUES(min_processing_time_ms),
                success_rate = VALUES(success_rate),
                most_common_event = VALUES(most_common_event),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            $accountId,
            $webhookType,
            $date,
            $webhookType,
            $date,
            $webhookType,
            $date
        ]);
        
        $calculated++;
    }
    
    return $calculated;
}

/**
 * Calculate hourly statistics for today
 */
function calculateHourlyStatistics($db, $date)
{
    $calculated = 0;
    
    // Get all webhook types and hours that have data
    $stmt = $db->prepare("
        SELECT DISTINCT webhook_type, HOUR(created_at) as hour
        FROM odoo_webhooks_log 
        WHERE DATE(created_at) = ?
        AND webhook_type IS NOT NULL
        ORDER BY webhook_type, hour
    ");
    $stmt->execute([$date]);
    $combinations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($combinations as $combo) {
        $webhookType = $combo['webhook_type'];
        $hour = (int) $combo['hour'];
        
        // Calculate hourly statistics (global only for now)
        $stmt = $db->prepare("
            INSERT INTO webhook_statistics_cache (
                line_account_id, webhook_type, stat_date, stat_hour,
                total_count, processed_count, failed_count, retry_count, dlq_count, duplicate_count,
                avg_processing_time_ms, max_processing_time_ms, min_processing_time_ms,
                success_rate, most_common_event
            )
            SELECT 
                NULL, -- Global hourly stats
                ?,
                ?,
                ?,
                COUNT(*) as total_count,
                COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed_count,
                COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed_count,
                COUNT(CASE WHEN status = 'RETRY' THEN 1 END) as retry_count,
                COUNT(CASE WHEN status = 'DLQ' THEN 1 END) as dlq_count,
                COUNT(CASE WHEN status = 'DUPLICATE' THEN 1 END) as duplicate_count,
                AVG(process_latency_ms) as avg_processing_time_ms,
                MAX(process_latency_ms) as max_processing_time_ms,
                MIN(process_latency_ms) as min_processing_time_ms,
                CASE 
                    WHEN COUNT(*) > 0 THEN ROUND((COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) / COUNT(*)) * 100, 2)
                    ELSE 0 
                END as success_rate,
                (
                    SELECT event_type 
                    FROM odoo_webhooks_log w2 
                    WHERE w2.webhook_type = ? 
                    AND DATE(w2.created_at) = ?
                    AND HOUR(w2.created_at) = ?
                    GROUP BY event_type 
                    ORDER BY COUNT(*) DESC 
                    LIMIT 1
                ) as most_common_event
            FROM odoo_webhooks_log w
            WHERE w.webhook_type = ? 
            AND DATE(w.created_at) = ?
            AND HOUR(w.created_at) = ?
            ON DUPLICATE KEY UPDATE
                total_count = VALUES(total_count),
                processed_count = VALUES(processed_count),
                failed_count = VALUES(failed_count),
                retry_count = VALUES(retry_count),
                dlq_count = VALUES(dlq_count),
                duplicate_count = VALUES(duplicate_count),
                avg_processing_time_ms = VALUES(avg_processing_time_ms),
                max_processing_time_ms = VALUES(max_processing_time_ms),
                min_processing_time_ms = VALUES(min_processing_time_ms),
                success_rate = VALUES(success_rate),
                most_common_event = VALUES(most_common_event),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            $webhookType,
            $date,
            $hour,
            $webhookType,
            $date,
            $hour,
            $webhookType,
            $date,
            $hour
        ]);
        
        $calculated++;
    }
    
    return $calculated;
}

/**
 * Check and create performance alerts
 */
function checkPerformanceAlerts($db)
{
    $alertsProcessed = 0;
    
    // Check failure rate (last 24 hours)
    $stmt = $db->prepare("
        SELECT 
            line_account_id,
            CASE 
                WHEN COUNT(*) > 0 THEN ROUND((COUNT(CASE WHEN status = 'FAILED' THEN 1 END) / COUNT(*)) * 100, 2)
                ELSE 0 
            END as failure_rate,
            COUNT(*) as total_webhooks
        FROM odoo_webhooks_log 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY line_account_id
        HAVING failure_rate > 10 OR total_webhooks > 1000
    ");
    $stmt->execute();
    $failureRates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($failureRates as $rate) {
        $lineAccountId = $rate['line_account_id'];
        $failureRate = (float) $rate['failure_rate'];
        
        if ($failureRate > 10) {
            $severity = $failureRate > 25 ? 'critical' : ($failureRate > 20 ? 'high' : 'medium');
            
            $stmt = $db->prepare("
                INSERT INTO webhook_performance_alerts (
                    line_account_id, alert_type, threshold_value, current_value, 
                    alert_message, severity
                ) VALUES (?, 'high_failure_rate', 10.00, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    current_value = VALUES(current_value),
                    alert_message = VALUES(alert_message),
                    severity = VALUES(severity),
                    is_resolved = FALSE,
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                $lineAccountId,
                $failureRate,
                "High webhook failure rate detected: {$failureRate}%",
                $severity
            ]);
            
            $alertsProcessed++;
        }
    }
    
    // Check average processing time (last 24 hours)
    $stmt = $db->prepare("
        SELECT 
            line_account_id,
            AVG(process_latency_ms) as avg_processing_time
        FROM odoo_webhooks_log 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND process_latency_ms IS NOT NULL
        GROUP BY line_account_id
        HAVING avg_processing_time > 5000
    ");
    $stmt->execute();
    $processingTimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($processingTimes as $time) {
        $lineAccountId = $time['line_account_id'];
        $avgTime = (float) $time['avg_processing_time'];
        
        $severity = $avgTime > 15000 ? 'critical' : ($avgTime > 10000 ? 'high' : 'medium');
        
        $stmt = $db->prepare("
            INSERT INTO webhook_performance_alerts (
                line_account_id, alert_type, threshold_value, current_value, 
                alert_message, severity
            ) VALUES (?, 'slow_processing', 5000.00, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                current_value = VALUES(current_value),
                alert_message = VALUES(alert_message),
                severity = VALUES(severity),
                is_resolved = FALSE,
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            $lineAccountId,
            $avgTime,
            "Slow webhook processing detected: " . round($avgTime, 2) . "ms average",
            $severity
        ]);
        
        $alertsProcessed++;
    }
    
    // Check DLQ count (last 24 hours)
    $stmt = $db->prepare("
        SELECT 
            line_account_id,
            COUNT(*) as dlq_count
        FROM odoo_webhooks_log 
        WHERE status = 'DLQ'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY line_account_id
        HAVING dlq_count > 50
    ");
    $stmt->execute();
    $dlqCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($dlqCounts as $dlq) {
        $lineAccountId = $dlq['line_account_id'];
        $dlqCount = (int) $dlq['dlq_count'];
        
        $severity = $dlqCount > 200 ? 'critical' : ($dlqCount > 100 ? 'high' : 'medium');
        
        $stmt = $db->prepare("
            INSERT INTO webhook_performance_alerts (
                line_account_id, alert_type, threshold_value, current_value, 
                alert_message, severity
            ) VALUES (?, 'dlq_threshold', 50.00, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                current_value = VALUES(current_value),
                alert_message = VALUES(alert_message),
                severity = VALUES(severity),
                is_resolved = FALSE,
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute([
            $lineAccountId,
            $dlqCount,
            "High number of webhooks in DLQ: {$dlqCount} items",
            $severity
        ]);
        
        $alertsProcessed++;
    }
    
    return $alertsProcessed;
}

/**
 * Clean up old statistics
 */
function cleanupOldStatistics($db)
{
    // Remove statistics older than 90 days
    $stmt = $db->prepare("
        DELETE FROM webhook_statistics_cache 
        WHERE stat_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    ");
    $stmt->execute();
    
    return $stmt->rowCount();
}