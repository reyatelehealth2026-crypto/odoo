<?php
/**
 * Process Scheduled Broadcasts API Endpoint
 * Can be triggered via cron or async web requests.
 */

// Allow script to run in background
ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/BroadcastHelper.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if there are any pending broadcasts to avoid loading classes unnecessarily
    $stmt = $db->query("SELECT COUNT(*) as c FROM broadcasts WHERE status = 'scheduled' AND scheduled_at <= NOW()");
    $count = $stmt->fetch()['c'];
    
    if ($count > 0) {
        $processed = BroadcastHelper::processScheduled($db);
        echo json_encode(['status' => 'success', 'processed' => $processed]);
    } else {
        echo json_encode(['status' => 'success', 'processed' => 0, 'message' => 'No pending broadcasts']);
    }
} catch (Exception $e) {
    error_log("Scheduled broadcast processor error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
