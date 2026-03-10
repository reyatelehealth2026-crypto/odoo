<?php
/**
 * Cron: Cleanup Dead Letter Queue
 * 
 * Purges resolved DLQ items older than 30 days.
 * Alerts administrators when DLQ exceeds 1000 pending items.
 * 
 * Schedule: Daily at 04:00 AM
 * 
 * Usage: php cron/cleanup-dlq.php
 * 
 * @version 1.0.0
 */

set_time_limit(120);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$startTime = microtime(true);
$log = function ($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
    error_log("[cleanup-dlq] {$msg}");
};

$log('Starting DLQ cleanup...');

try {
    $db = Database::getInstance()->getConnection();

    // Check if DLQ table exists
    try {
        $db->query("SELECT 1 FROM odoo_webhook_dlq LIMIT 1");
    } catch (Exception $e) {
        $log('DLQ table does not exist — nothing to clean up.');
        exit(0);
    }

    // 1. Purge resolved items older than 30 days
    $purgeStmt = $db->prepare("
        DELETE FROM odoo_webhook_dlq
        WHERE status = 'resolved'
          AND resolved_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $purgeStmt->execute();
    $purgedResolved = $purgeStmt->rowCount();
    $log("Purged {$purgedResolved} resolved DLQ items older than 30 days.");

    // 2. Purge abandoned items older than 90 days
    $purgeAbandonedStmt = $db->prepare("
        DELETE FROM odoo_webhook_dlq
        WHERE status = 'abandoned'
          AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $purgeAbandonedStmt->execute();
    $purgedAbandoned = $purgeAbandonedStmt->rowCount();
    $log("Purged {$purgedAbandoned} abandoned DLQ items older than 90 days.");

    // 3. Auto-abandon items that have been retried 5+ times and are still pending
    $abandonStmt = $db->prepare("
        UPDATE odoo_webhook_dlq
        SET status = 'abandoned'
        WHERE status = 'pending'
          AND retry_count >= 5
    ");
    $abandonStmt->execute();
    $abandoned = $abandonStmt->rowCount();
    if ($abandoned > 0) {
        $log("Abandoned {$abandoned} DLQ items with 5+ retries.");
    }

    // 4. Check pending count and alert if > 1000
    $pendingCount = (int) $db->query("SELECT COUNT(*) FROM odoo_webhook_dlq WHERE status = 'pending'")->fetchColumn();
    $log("Current pending DLQ items: {$pendingCount}");

    if ($pendingCount > 1000) {
        $alertMsg = "[ALERT] DLQ has {$pendingCount} pending items (threshold: 1000). Investigation needed.";
        $log($alertMsg);

        // Log alert to activity log if table exists
        try {
            $db->prepare("
                INSERT INTO odoo_activity_log (user_id, action, entity_type, details, created_at)
                VALUES (0, 'dlq_alert', 'system', ?, NOW())
            ")->execute([json_encode(['pending_count' => $pendingCount, 'message' => $alertMsg])]);
        } catch (Exception $e) {
            // Activity log table may not exist
        }
    }

    $duration = round(microtime(true) - $startTime, 2);
    $log("DLQ cleanup complete in {$duration}s. Purged: {$purgedResolved} resolved, {$purgedAbandoned} abandoned.");

} catch (Exception $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
