<?php
/**
 * System Health API
 * 
 * Unified health score for LINE + Odoo + Scheduler subsystems.
 * Returns 0-100 score per subsystem and overall weighted average.
 *
 * @version 1.0.0
 * @created 2026-02-26
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    $line = getLineHealth($db);
    $odoo = getOdooHealth($db);
    $scheduler = getSchedulerHealth($db);

    // Weighted average: LINE 40%, Odoo 40%, Scheduler 20%
    $overall = (int) round($line['score'] * 0.4 + $odoo['score'] * 0.4 + $scheduler['score'] * 0.2);

    echo json_encode([
        'success' => true,
        'data' => [
            'line' => $line,
            'odoo' => $odoo,
            'scheduler' => $scheduler,
            'overall' => [
                'score' => $overall,
                'status' => scoreToStatus($overall),
            ],
            'timestamp' => date('c'),
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Convert score to status label.
 */
function scoreToStatus($score)
{
    if ($score >= 90) return 'healthy';
    if ($score >= 70) return 'degraded';
    return 'critical';
}

/**
 * Check if table exists.
 */
function healthTableExists($db, $table)
{
    try {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * LINE subsystem health.
 * Checks: last webhook received, failed messages today, bot accounts online.
 */
function getLineHealth($db)
{
    $score = 100;
    $details = [];

    // Last LINE webhook event
    $lastWebhook = null;
    if (healthTableExists($db, 'webhook_events')) {
        try {
            $lastWebhook = $db->query("SELECT MAX(created_at) FROM webhook_events")->fetchColumn();
        } catch (Exception $e) {
            // ignore
        }
    }
    if (!$lastWebhook && healthTableExists($db, 'dev_logs')) {
        try {
            $lastWebhook = $db->query("SELECT MAX(created_at) FROM dev_logs WHERE source = 'webhook' ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch (Exception $e) {
            // ignore
        }
    }

    $details['last_webhook'] = $lastWebhook;
    if ($lastWebhook) {
        $minutesAgo = (time() - strtotime($lastWebhook)) / 60;
        $details['minutes_since_last'] = round($minutesAgo, 1);
        if ($minutesAgo > 60) $score -= 15;
        elseif ($minutesAgo > 30) $score -= 5;
    } else {
        $score -= 20;
        $details['minutes_since_last'] = null;
    }

    // Bot accounts online
    $botCount = 0;
    try {
        $botCount = (int) $db->query("SELECT COUNT(*) FROM line_accounts WHERE is_active = 1")->fetchColumn();
    } catch (Exception $e) {
        try {
            $botCount = (int) $db->query("SELECT COUNT(*) FROM line_accounts")->fetchColumn();
        } catch (Exception $e2) {
            // ignore
        }
    }
    $details['bot_accounts'] = $botCount;
    if ($botCount === 0) $score -= 30;

    // Failed messages today from dev_logs
    $failedToday = 0;
    $totalToday = 0;
    if (healthTableExists($db, 'dev_logs')) {
        try {
            $row = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(IF(log_type = 'error', 1, 0)) as errors
                FROM dev_logs
                WHERE DATE(created_at) = CURDATE() AND source LIKE 'webhook%'
            ")->fetch(PDO::FETCH_ASSOC);
            $totalToday = (int) ($row['total'] ?? 0);
            $failedToday = (int) ($row['errors'] ?? 0);
        } catch (Exception $e) {
            // ignore
        }
    }
    $details['errors_today'] = $failedToday;
    $details['events_today'] = $totalToday;
    if ($totalToday > 0) {
        $errorRate = $failedToday / $totalToday;
        if ($errorRate > 0.2) $score -= 20;
        elseif ($errorRate > 0.05) $score -= 10;
    }

    $score = max(0, min(100, $score));
    return ['score' => $score, 'status' => scoreToStatus($score), 'details' => $details];
}

/**
 * Odoo subsystem health.
 * Checks: last webhook, DLQ count, failed %, notification success rate.
 */
function getOdooHealth($db)
{
    $score = 100;
    $details = [];

    if (!healthTableExists($db, 'odoo_webhooks_log')) {
        return ['score' => 0, 'status' => 'critical', 'details' => ['error' => 'odoo_webhooks_log table not found']];
    }

    // Resolve timestamp column
    $tsCol = null;
    foreach (['processed_at', 'created_at', 'received_at', 'updated_at'] as $col) {
        try {
            $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'odoo_webhooks_log' AND COLUMN_NAME = ? LIMIT 1");
            $stmt->execute([$col]);
            if ($stmt->fetchColumn()) { $tsCol = "`{$col}`"; break; }
        } catch (Exception $e) { /* ignore */ }
    }
    $tsExpr = $tsCol ?: 'NOW()';

    // Consolidated stats
    $agg = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(IF(DATE({$tsExpr}) = CURDATE(), 1, 0)) as today,
            SUM(IF(status = 'failed', 1, 0)) as failed,
            SUM(IF(status = 'dead_letter', 1, 0)) as dlq,
            SUM(IF(status = 'retry', 1, 0)) as retry,
            SUM(IF(status = 'success', 1, 0)) as success,
            MAX({$tsExpr}) as last_webhook
        FROM odoo_webhooks_log
    ")->fetch(PDO::FETCH_ASSOC);

    $total = (int) ($agg['total'] ?? 0);
    $today = (int) ($agg['today'] ?? 0);
    $failed = (int) ($agg['failed'] ?? 0);
    $dlq = (int) ($agg['dlq'] ?? 0);
    $retry = (int) ($agg['retry'] ?? 0);
    $success = (int) ($agg['success'] ?? 0);
    $lastWebhook = $agg['last_webhook'] ?? null;

    $details['total'] = $total;
    $details['today'] = $today;
    $details['failed'] = $failed;
    $details['dlq'] = $dlq;
    $details['retry'] = $retry;
    $details['last_webhook'] = $lastWebhook;

    // Last webhook recency
    if ($lastWebhook) {
        $minutesAgo = (time() - strtotime($lastWebhook)) / 60;
        $details['minutes_since_last'] = round($minutesAgo, 1);
        if ($minutesAgo > 120) $score -= 20;
        elseif ($minutesAgo > 60) $score -= 10;
    } else {
        $score -= 25;
        $details['minutes_since_last'] = null;
    }

    // DLQ penalty
    if ($dlq > 10) $score -= 25;
    elseif ($dlq > 0) $score -= 15;

    // Retry penalty
    if ($retry > 20) $score -= 10;
    elseif ($retry > 0) $score -= 5;

    // Failed rate
    if ($total > 0) {
        $failRate = $failed / $total;
        $details['fail_rate'] = round($failRate * 100, 1);
        if ($failRate > 0.1) $score -= 15;
        elseif ($failRate > 0.02) $score -= 5;
    }

    // Notification success rate
    if (healthTableExists($db, 'odoo_notification_log')) {
        try {
            $notifRow = $db->query("
                SELECT
                    COUNT(*) as total,
                    SUM(IF(status = 'sent', 1, 0)) as sent
                FROM odoo_notification_log
                WHERE DATE(sent_at) = CURDATE()
            ")->fetch(PDO::FETCH_ASSOC);
            $notifTotal = (int) ($notifRow['total'] ?? 0);
            $notifSent = (int) ($notifRow['sent'] ?? 0);
            $details['notif_today'] = $notifTotal;
            $details['notif_sent'] = $notifSent;
            if ($notifTotal > 0) {
                $notifRate = $notifSent / $notifTotal;
                $details['notif_success_rate'] = round($notifRate * 100, 1);
                if ($notifRate < 0.7) $score -= 10;
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    $score = max(0, min(100, $score));
    return ['score' => $score, 'status' => scoreToStatus($score), 'details' => $details];
}

/**
 * Scheduler subsystem health.
 * Checks: last broadcast run, pending count, failed scheduled broadcasts.
 */
function getSchedulerHealth($db)
{
    $score = 100;
    $details = [];

    if (!healthTableExists($db, 'broadcasts')) {
        return ['score' => 80, 'status' => 'healthy', 'details' => ['note' => 'broadcasts table not found, assuming OK']];
    }

    // Pending scheduled broadcasts
    try {
        $pending = (int) $db->query("SELECT COUNT(*) FROM broadcasts WHERE status = 'scheduled' AND scheduled_at <= NOW()")->fetchColumn();
        $details['overdue_pending'] = $pending;
        if ($pending > 5) $score -= 20;
        elseif ($pending > 0) $score -= 5;
    } catch (Exception $e) {
        $details['overdue_pending'] = null;
    }

    // Recently failed broadcasts
    try {
        $failedRecent = (int) $db->query("SELECT COUNT(*) FROM broadcasts WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        $details['failed_24h'] = $failedRecent;
        if ($failedRecent > 3) $score -= 15;
        elseif ($failedRecent > 0) $score -= 5;
    } catch (Exception $e) {
        $details['failed_24h'] = null;
    }

    // Last successful broadcast
    try {
        $lastSent = $db->query("SELECT MAX(created_at) FROM broadcasts WHERE status = 'sent'")->fetchColumn();
        $details['last_sent'] = $lastSent;
    } catch (Exception $e) {
        $details['last_sent'] = null;
    }

    // Total scheduled waiting
    try {
        $totalScheduled = (int) $db->query("SELECT COUNT(*) FROM broadcasts WHERE status = 'scheduled'")->fetchColumn();
        $details['total_scheduled'] = $totalScheduled;
    } catch (Exception $e) {
        $details['total_scheduled'] = null;
    }

    // Auto-send cron health (odoo daily summary)
    if (healthTableExists($db, 'odoo_daily_summary_settings')) {
        try {
            $lastExec = $db->query("SELECT value FROM odoo_daily_summary_settings WHERE `key` = 'last_sent_date' LIMIT 1")->fetchColumn();
            $details['daily_summary_last'] = $lastExec ?: null;
        } catch (Exception $e) {
            // ignore
        }
    }

    $score = max(0, min(100, $score));
    return ['score' => $score, 'status' => scoreToStatus($score), 'details' => $details];
}
