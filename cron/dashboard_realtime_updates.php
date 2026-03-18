<?php
/**
 * Dashboard Real-time Updates Cron Job
 * 
 * Periodically broadcasts dashboard metrics updates to connected WebSocket clients.
 * This complements the WebSocket server's built-in periodic updates with PHP-side data.
 * 
 * Schedule: Every 30 seconds
 * Command: php cron/dashboard_realtime_updates.php
 * 
 * Requirements: FR-1.4, BR-3.3
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Load the dashboard WebSocket notifier if available
if (file_exists(__DIR__ . '/../classes/DashboardWebSocketNotifier.php')) {
    require_once __DIR__ . '/../classes/DashboardWebSocketNotifier.php';
} else {
    error_log("Dashboard WebSocket notifier not available");
    exit(1);
}

/**
 * Get dashboard metrics for a specific LINE account
 */
function getDashboardMetrics($db, $lineAccountId) {
    try {
        $today = date('Y-m-d');
        
        // Get order metrics from cache tables
        $orderQuery = $db->prepare("
            SELECT 
                COUNT(*) as today_count,
                COALESCE(SUM(amount_total), 0) as today_total,
                COUNT(CASE WHEN state IN ('draft', 'sent') THEN 1 END) as pending_count,
                COUNT(CASE WHEN state = 'sale' THEN 1 END) as completed_count
            FROM odoo_orders 
            WHERE line_account_id = ? 
            AND DATE(date_order) = ?
        ");
        $orderQuery->execute([$lineAccountId, $today]);
        $orderMetrics = $orderQuery->fetch(PDO::FETCH_ASSOC);

        // Get payment metrics
        $paymentQuery = $db->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_slips,
                COUNT(CASE WHEN status = 'matched' AND DATE(created_at) = ? THEN 1 END) as processed_today,
                COALESCE(SUM(CASE WHEN status = 'matched' AND DATE(created_at) = ? THEN amount END), 0) as total_amount
            FROM odoo_slip_uploads 
            WHERE line_account_id = ?
        ");
        $paymentQuery->execute([$today, $today, $lineAccountId]);
        $paymentMetrics = $paymentQuery->fetch(PDO::FETCH_ASSOC);

        // Get webhook metrics
        $webhookQuery = $db->prepare("
            SELECT 
                COUNT(*) as today_count,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as success_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                AVG(CASE WHEN response_time IS NOT NULL THEN response_time END) as avg_response_time
            FROM odoo_webhooks_log 
            WHERE line_account_id = ? 
            AND DATE(created_at) = ?
        ");
        $webhookQuery->execute([$lineAccountId, $today]);
        $webhookMetrics = $webhookQuery->fetch(PDO::FETCH_ASSOC);

        // Get customer metrics
        $customerQuery = $db->prepare("
            SELECT 
                COUNT(DISTINCT partner_id) as total_active,
                COUNT(DISTINCT CASE WHEN DATE(create_date) = ? THEN partner_id END) as new_today,
                COUNT(DISTINCT CASE WHEN line_user_id IS NOT NULL THEN partner_id END) as line_connected
            FROM odoo_orders 
            WHERE line_account_id = ?
        ");
        $customerQuery->execute([$today, $lineAccountId]);
        $customerMetrics = $customerQuery->fetch(PDO::FETCH_ASSOC);

        $orders = $orderMetrics ?: [];
        $payments = $paymentMetrics ?: [];
        $webhooks = $webhookMetrics ?: [];
        $customers = $customerMetrics ?: [];

        return [
            'orders' => [
                'todayCount' => (int)($orders['today_count'] ?? 0),
                'todayTotal' => (float)($orders['today_total'] ?? 0),
                'pendingCount' => (int)($orders['pending_count'] ?? 0),
                'completedCount' => (int)($orders['completed_count'] ?? 0),
                'averageOrderValue' => $orders['today_count'] > 0 ? 
                    ($orders['today_total'] / $orders['today_count']) : 0
            ],
            'payments' => [
                'pendingSlips' => (int)($payments['pending_slips'] ?? 0),
                'processedToday' => (int)($payments['processed_today'] ?? 0),
                'matchingRate' => 95, // This would be calculated based on actual matching logic
                'totalAmount' => (float)($payments['total_amount'] ?? 0),
                'averageProcessingTime' => 15 // This would be calculated from actual processing times
            ],
            'webhooks' => [
                'todayCount' => (int)($webhooks['today_count'] ?? 0),
                'successRate' => $webhooks['today_count'] > 0 ? 
                    round(($webhooks['success_count'] / $webhooks['today_count']) * 100) : 100,
                'failedCount' => (int)($webhooks['failed_count'] ?? 0),
                'averageResponseTime' => round($webhooks['avg_response_time'] ?? 0)
            ],
            'customers' => [
                'totalActive' => (int)($customers['total_active'] ?? 0),
                'newToday' => (int)($customers['new_today'] ?? 0),
                'lineConnected' => (int)($customers['line_connected'] ?? 0),
                'averageOrdersPerCustomer' => $customers['total_active'] > 0 ? 
                    round($orders['today_count'] / $customers['total_active'] * 10) / 10 : 0
            ],
            'updatedAt' => date('c')
        ];
    } catch (Exception $e) {
        error_log("Error fetching dashboard metrics for account {$lineAccountId}: " . $e->getMessage());
        return null;
    }
}

/**
 * Main execution
 */
function main() {
    $startTime = microtime(true);
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Get all active LINE accounts
        $accountQuery = $db->query("
            SELECT DISTINCT line_account_id 
            FROM admin_users 
            WHERE is_active = 1 
            AND line_account_id IS NOT NULL
        ");
        
        $accounts = $accountQuery->fetchAll(PDO::FETCH_COLUMN);
        $updatedAccounts = 0;
        $errors = 0;
        
        foreach ($accounts as $lineAccountId) {
            try {
                // Get dashboard metrics
                $metrics = getDashboardMetrics($db, $lineAccountId);
                
                if ($metrics) {
                    // Create WebSocket notifier for this account
                    $notifier = new DashboardWebSocketNotifier($lineAccountId);
                    
                    // Test connection first
                    if ($notifier->testConnection()) {
                        // Broadcast metrics update
                        $success = $notifier->broadcastMetricsUpdate($metrics);
                        
                        if ($success) {
                            $updatedAccounts++;
                            echo "✓ Updated metrics for account: {$lineAccountId}\n";
                        } else {
                            $errors++;
                            echo "✗ Failed to broadcast metrics for account: {$lineAccountId}\n";
                        }
                    } else {
                        echo "⚠ Redis connection failed for account: {$lineAccountId}\n";
                    }
                } else {
                    echo "⚠ No metrics data for account: {$lineAccountId}\n";
                }
            } catch (Exception $e) {
                $errors++;
                error_log("Error processing account {$lineAccountId}: " . $e->getMessage());
                echo "✗ Error processing account {$lineAccountId}: " . $e->getMessage() . "\n";
            }
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // Log summary
        $summary = [
            'total_accounts' => count($accounts),
            'updated_accounts' => $updatedAccounts,
            'errors' => $errors,
            'execution_time_ms' => $executionTime,
            'timestamp' => date('c')
        ];
        
        // Log to dev_logs table
        $logQuery = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $logQuery->execute([
            'cron_dashboard_updates',
            'dashboard_realtime_updates.php',
            "Dashboard updates completed: {$updatedAccounts}/{" . count($accounts) . "} accounts updated",
            json_encode($summary)
        ]);
        
        echo "\n=== Dashboard Real-time Updates Summary ===\n";
        echo "Total accounts: " . count($accounts) . "\n";
        echo "Updated accounts: {$updatedAccounts}\n";
        echo "Errors: {$errors}\n";
        echo "Execution time: {$executionTime}ms\n";
        echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
        
        // Exit with appropriate code
        exit($errors > 0 ? 1 : 0);
        
    } catch (Exception $e) {
        error_log("Dashboard real-time updates cron failed: " . $e->getMessage());
        echo "✗ Fatal error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Run only if called directly (not included)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    main();
}
?>