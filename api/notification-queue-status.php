<?php
/**
 * Notification Queue Status API
 * 
 * Get queue statistics and status
 * 
 * GET /api/notification-queue-status.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/NotificationQueue.php';
require_once __DIR__ . '/../classes/NotificationBatcher.php';

try {
    $db = Database::getInstance()->getConnection();
    $queue = new NotificationQueue($db);
    $batcher = new NotificationBatcher($db);
    
    // Get queue stats
    $queueStats = $queue->getStats();
    
    // Get batch stats
    $stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM odoo_notification_batch_groups
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY status
    ");
    $batchStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent notifications
    $stmt = $db->query("
        SELECT 
            status,
            COUNT(*) as count,
            MAX(sent_at) as last_sent
        FROM odoo_notification_log
        WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        GROUP BY status
    ");
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get pending count
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM odoo_notification_queue
        WHERE status = 'pending'
          AND scheduled_at <= NOW()
    ");
    $pendingCount = $stmt->fetchColumn();
    
    // Get failed count
    $stmt = $db->query("
        SELECT COUNT(*) as count
        FROM odoo_notification_queue
        WHERE status = 'failed'
    ");
    $failedCount = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'timestamp' => date('c'),
        'queue' => [
            'pending' => $pendingCount,
            'failed' => $failedCount,
            'stats' => $queueStats
        ],
        'batches' => $batchStats,
        'recent_logs' => $recentLogs,
        'health' => [
            'status' => $failedCount > 100 ? 'warning' : 'ok',
            'message' => $failedCount > 100 ? 'High failure rate detected' : 'System healthy'
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
