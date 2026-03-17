<?php
/**
 * Error Handling System Maintenance Cron Job
 * Performs cleanup and maintenance tasks for the error handling system
 * Run every hour: 0 * * * * php cron/error_handling_maintenance.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Load error handling bridge
if (file_exists(__DIR__ . '/../classes/ErrorHandlingBridge.php')) {
    require_once __DIR__ . '/../classes/ErrorHandlingBridge.php';
}

try {
    $db = Database::getInstance()->getConnection();
    $errorHandler = new ErrorHandlingBridge($db);
    $startTime = microtime(true);
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting error handling maintenance...\n";
    
    // 1. Clean up old error logs (older than 30 days)
    $cleanupResult = cleanupOldErrorLogs($db);
    echo "Cleaned up {$cleanupResult['deleted_count']} old error log entries\n";
    
    // 2. Clean up resolved dead letter queue messages (older than 7 days)
    $dlqCleanup = cleanupResolvedDLQMessages($db);
    echo "Cleaned up {$dlqCleanup['deleted_count']} resolved DLQ messages\n";
    
    // 3. Reset service health for services that haven't had errors recently
    $healthReset = resetHealthyServices($db);
    echo "Reset health status for {$healthReset['reset_count']} services\n";
    
    // 4. Generate error statistics summary
    $stats = generateErrorStatistics($db);
    echo "Generated error statistics: {$stats['total_errors']} errors in last 24h\n";
    
    // 5. Check for services with high error rates and send alerts
    $alerts = checkErrorThresholds($db, $errorHandler);
    echo "Sent {$alerts['alert_count']} threshold alerts\n";
    
    // 6. Optimize database tables
    $optimization = optimizeTables($db);
    echo "Optimized {$optimization['optimized_count']} tables\n";
    
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Log maintenance completion
    $errorHandler->logError(
        'MAINTENANCE_COMPLETED',
        'Error handling maintenance completed successfully',
        [
            'execution_time_ms' => $executionTime,
            'cleanup_results' => $cleanupResult,
            'dlq_cleanup' => $dlqCleanup,
            'health_reset' => $healthReset,
            'statistics' => $stats,
            'alerts' => $alerts,
            'optimization' => $optimization
        ],
        'maintenance_' . date('YmdHis')
    );
    
    echo "[" . date('Y-m-d H:i:s') . "] Maintenance completed in {$executionTime}ms\n";
    
} catch (Exception $e) {
    echo "[ERROR] " . date('Y-m-d H:i:s') . " Maintenance failed: " . $e->getMessage() . "\n";
    
    // Log maintenance failure
    if (isset($errorHandler)) {
        $errorHandler->logError(
            'MAINTENANCE_FAILED',
            'Error handling maintenance failed: ' . $e->getMessage(),
            [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'maintenance_error_' . date('YmdHis')
        );
    }
    
    exit(1);
}

/**
 * Clean up old error log entries
 */
function cleanupOldErrorLogs($db)
{
    try {
        $stmt = $db->prepare("
            DELETE FROM error_logs 
            WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        
        return [
            'deleted_count' => $stmt->rowCount(),
            'success' => true
        ];
    } catch (Exception $e) {
        return [
            'deleted_count' => 0,
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Clean up resolved dead letter queue messages
 */
function cleanupResolvedDLQMessages($db)
{
    try {
        $stmt = $db->prepare("
            DELETE FROM dead_letter_queue 
            WHERE status = 'resolved' 
            AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        
        return [
            'deleted_count' => $stmt->rowCount(),
            'success' => true
        ];
    } catch (Exception $e) {
        return [
            'deleted_count' => 0,
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Reset health status for services that haven't had errors recently
 */
function resetHealthyServices($db)
{
    try {
        $stmt = $db->prepare("
            UPDATE service_health 
            SET 
                healthy = TRUE,
                error_count = GREATEST(error_count - 1, 0),
                degradation_level = CASE 
                    WHEN error_count <= 1 THEN 'none'
                    WHEN error_count <= 5 THEN 'partial'
                    ELSE degradation_level
                END,
                updated_at = NOW()
            WHERE last_check < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND error_count > 0
        ");
        $stmt->execute();
        
        return [
            'reset_count' => $stmt->rowCount(),
            'success' => true
        ];
    } catch (Exception $e) {
        return [
            'reset_count' => 0,
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Generate error statistics for the last 24 hours
 */
function generateErrorStatistics($db)
{
    try {
        // Get total errors in last 24 hours
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_errors,
                COUNT(CASE WHEN level = 'critical' THEN 1 END) as critical_errors,
                COUNT(CASE WHEN level = 'high' THEN 1 END) as high_errors,
                COUNT(CASE WHEN level = 'medium' THEN 1 END) as medium_errors,
                COUNT(CASE WHEN level = 'low' THEN 1 END) as low_errors
            FROM error_logs 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get top error codes
        $stmt = $db->prepare("
            SELECT code, COUNT(*) as count
            FROM error_logs 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY code
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute();
        $topErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'total_errors' => (int)$stats['total_errors'],
            'critical_errors' => (int)$stats['critical_errors'],
            'high_errors' => (int)$stats['high_errors'],
            'medium_errors' => (int)$stats['medium_errors'],
            'low_errors' => (int)$stats['low_errors'],
            'top_error_codes' => $topErrors,
            'success' => true
        ];
    } catch (Exception $e) {
        return [
            'total_errors' => 0,
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check error thresholds and send alerts if needed
 */
function checkErrorThresholds($db, $errorHandler)
{
    $alertCount = 0;
    
    try {
        // Check for error codes that exceed thresholds in the last hour
        $stmt = $db->prepare("
            SELECT 
                code,
                level,
                COUNT(*) as error_count,
                MAX(timestamp) as latest_error
            FROM error_logs 
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY code, level
            HAVING error_count >= CASE 
                WHEN level = 'critical' THEN 3
                WHEN level = 'high' THEN 5
                WHEN level = 'medium' THEN 10
                ELSE 20
            END
        ");
        $stmt->execute();
        $thresholdExceeded = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($thresholdExceeded as $error) {
            // Send alert (this would integrate with your notification system)
            $alertMessage = "Error threshold exceeded: {$error['code']} ({$error['level']}) - {$error['error_count']} occurrences in last hour";
            
            // Log the alert
            $errorHandler->logError(
                'ERROR_THRESHOLD_ALERT',
                $alertMessage,
                [
                    'error_code' => $error['code'],
                    'level' => $error['level'],
                    'count' => $error['error_count'],
                    'latest_error' => $error['latest_error']
                ],
                'threshold_alert_' . date('YmdHis')
            );
            
            $alertCount++;
        }
        
        // Check for services with high degradation
        $stmt = $db->prepare("
            SELECT service_name, degradation_level, error_count
            FROM service_health 
            WHERE degradation_level IN ('partial', 'full')
        ");
        $stmt->execute();
        $degradedServices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($degradedServices as $service) {
            $alertMessage = "Service degradation detected: {$service['service_name']} - {$service['degradation_level']} degradation";
            
            $errorHandler->logError(
                'SERVICE_DEGRADATION_ALERT',
                $alertMessage,
                [
                    'service_name' => $service['service_name'],
                    'degradation_level' => $service['degradation_level'],
                    'error_count' => $service['error_count']
                ],
                'degradation_alert_' . date('YmdHis')
            );
            
            $alertCount++;
        }
        
        return [
            'alert_count' => $alertCount,
            'threshold_exceeded' => $thresholdExceeded,
            'degraded_services' => $degradedServices,
            'success' => true
        ];
        
    } catch (Exception $e) {
        return [
            'alert_count' => 0,
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Optimize database tables for better performance
 */
function optimizeTables($db)
{
    $tables = [
        'error_logs',
        'dead_letter_queue',
        'service_health',
        'circuit_breaker_state',
        'retry_attempts',
        'performance_metrics'
    ];
    
    $optimizedCount = 0;
    
    foreach ($tables as $table) {
        try {
            $stmt = $db->prepare("OPTIMIZE TABLE `{$table}`");
            $stmt->execute();
            $optimizedCount++;
        } catch (Exception $e) {
            echo "Warning: Failed to optimize table {$table}: " . $e->getMessage() . "\n";
        }
    }
    
    return [
        'optimized_count' => $optimizedCount,
        'total_tables' => count($tables),
        'success' => true
    ];
}
?>