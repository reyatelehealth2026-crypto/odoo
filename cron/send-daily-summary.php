<?php
/**
 * Cron: Send Daily Summary
 * 
 * Generates and sends daily order summary notifications to customers via LINE.
 * Uses the existing getDailySummaryPreview() and sendDailySummary() logic
 * from odoo-webhooks-dashboard.php.
 * 
 * Schedule: Daily at 18:00 (6 PM)
 * 
 * Usage: php cron/send-daily-summary.php
 * 
 * @version 1.0.0
 */

set_time_limit(300);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$startTime = microtime(true);
$log = function ($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
    error_log("[send-daily-summary] {$msg}");
};

$log('Starting daily summary generation...');

try {
    $db = Database::getInstance()->getConnection();

    // Check if notification log table exists
    try {
        $db->query("SELECT 1 FROM odoo_notification_log LIMIT 1");
    } catch (Exception $e) {
        $log('ERROR: odoo_notification_log table does not exist.');
        exit(1);
    }

    // Include the dashboard API functions for preview/send logic
    // We'll replicate the core logic here to avoid loading the full API file
    require_once __DIR__ . '/../classes/OdooFlexTemplates.php';
    require_once __DIR__ . '/../classes/OdooWebhookHandler.php';

    $orderNameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), '')";
    $orderRefExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')), '')";
    $lineUserIdExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')), '')";

    // Get users who already received today's summary
    $sentUsers = $db->query("
        SELECT line_user_id FROM odoo_notification_log 
        WHERE event_type = 'daily.summary' AND status = 'sent' AND DATE(sent_at) = CURDATE()
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $log('Already sent today: ' . count($sentUsers) . ' users');

    // Get today's webhook events grouped by user → order
    $rows = $db->query("
        SELECT 
            {$lineUserIdExpr} as line_user_id,
            COALESCE({$orderNameExpr}, {$orderRefExpr}) as order_ref,
            event_type, status, processed_at as event_time
        FROM odoo_webhooks_log
        WHERE {$lineUserIdExpr} IS NOT NULL AND DATE(processed_at) = CURDATE()
        ORDER BY processed_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Group by user → order
    $userOrders = [];
    foreach ($rows as $row) {
        $userId = $row['line_user_id'];
        $orderRef = $row['order_ref'];
        if (!$orderRef) continue;

        if (!isset($userOrders[$userId])) $userOrders[$userId] = [];
        if (!isset($userOrders[$userId][$orderRef])) {
            $userOrders[$userId][$orderRef] = [
                'order_ref' => $orderRef,
                'events' => [],
                'last_update' => null,
                'last_event' => null,
            ];
        }

        $userOrders[$userId][$orderRef]['events'][] = [
            'event_type' => $row['event_type'],
            'status' => $row['status'],
            'time' => $row['event_time'],
        ];
        $userOrders[$userId][$orderRef]['last_update'] = $row['event_time'];
        $userOrders[$userId][$orderRef]['last_event'] = $row['event_type'];
    }

    $eventLabels = [
        'order.validated' => 'ยืนยันออเดอร์', 'order.picking' => 'กำลังจัดสินค้า',
        'order.picked' => 'จัดเสร็จแล้ว', 'order.packing' => 'กำลังแพ็ค',
        'order.packed' => 'แพ็คเสร็จ', 'order.paid' => 'ชำระเงินแล้ว',
        'order.to_delivery' => 'เตรียมจัดส่ง', 'order.in_delivery' => 'กำลังจัดส่ง',
        'order.delivered' => 'จัดส่งสำเร็จ', 'order.cancelled' => 'ยกเลิกออเดอร์',
        'invoice.posted' => 'ออกใบแจ้งหนี้', 'invoice.paid' => 'ชำระเงินแล้ว',
    ];

    $handler = new OdooWebhookHandler($db, null);
    $successCount = 0;
    $failedCount = 0;
    $skippedCount = 0;

    foreach ($userOrders as $userId => $orders) {
        // Skip if already sent
        if (in_array($userId, $sentUsers)) {
            $skippedCount++;
            continue;
        }

        if (empty($orders)) continue;

        // Build record for Flex template
        $displayName = 'Customer';
        try {
            $stmt = $db->prepare("SELECT display_name FROM users WHERE line_user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $displayName = $stmt->fetchColumn() ?: 'Customer';
        } catch (Exception $e) { /* ignore */ }

        $activeOrders = [];
        foreach ($orders as $orderRef => $order) {
            $lastEvent = $order['last_event'];
            $activeOrders[] = [
                'order_ref' => $orderRef,
                'event_type' => $lastEvent,
                'event_label' => $eventLabels[$lastEvent] ?? explode('.', $lastEvent)[count(explode('.', $lastEvent)) - 1],
                'status' => $order['events'][count($order['events']) - 1]['status'],
                'last_update' => $order['last_update'],
            ];
        }

        $record = [
            'line_user_id' => $userId,
            'display_name' => $displayName,
            'orders' => $activeOrders,
        ];

        // Get LINE access token
        $user = $handler->findLineUserAcrossAccounts(null, $userId);
        if (!$user || empty($user['channel_access_token'])) {
            $failedCount++;
            continue;
        }

        // Generate and send Flex message
        try {
            $flexBubble = OdooFlexTemplates::dailySummary($record);
        } catch (Exception $e) {
            $log("Flex template error for {$userId}: " . $e->getMessage());
            $failedCount++;
            continue;
        }

        $sent = false;
        $apiError = null;
        $apiStatus = null;
        $sendStart = microtime(true);

        try {
            $body = json_encode([
                'to' => $userId,
                'messages' => [[
                    'type' => 'flex',
                    'altText' => 'สรุปออเดอร์ประจำวันของคุณ',
                    'contents' => $flexBubble,
                ]]
            ]);

            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $user['channel_access_token'],
                ],
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $apiStatus = $httpCode;
            $sent = ($httpCode >= 200 && $httpCode < 300);
            if (!$sent) $apiError = $response;
        } catch (Exception $e) {
            $apiError = $e->getMessage();
        }

        $latencyMs = (int) round((microtime(true) - $sendStart) * 1000);

        // Log to notification log
        try {
            $deliveryId = 'daily_cron_' . date('Ymd_His') . '_' . substr(md5($userId), 0, 8);
            $db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id,
                 notification_method, status, line_api_status, error_message, latency_ms, sent_at)
                VALUES (?, 'daily.summary', 'customer', ?, 'flex', ?, ?, ?, ?, NOW())
            ")->execute([
                $deliveryId, $userId,
                $sent ? 'sent' : 'failed',
                $apiStatus,
                $sent ? null : $apiError,
                $latencyMs,
            ]);
        } catch (Exception $e) {
            $log("Error logging notification: " . $e->getMessage());
        }

        if ($sent) $successCount++;
        else $failedCount++;
    }

    $duration = round(microtime(true) - $startTime, 2);
    $log("Daily summary complete in {$duration}s. Sent: {$successCount}, Failed: {$failedCount}, Skipped: {$skippedCount}");

} catch (Exception $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
