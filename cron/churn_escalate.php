<?php

/**
 * Cron: Churn Manager Escalation — Churned high-value customers
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 4, §9
 * Schedule: Daily, suggested 08:20 Asia/Bangkok
 * Crontab:  20 8 * * * /usr/bin/php /home/zrismpsz/public_html/cny.re-ya.com/cron/churn_escalate.php >> /tmp/churn_escalate.log 2>&1
 *
 * What this does:
 *   1. Guards: system_enabled=1 AND soft_launch=0, else no-op.
 *   2. Reads customer_rfm_profile WHERE current_segment='Churned' AND is_high_value=1.
 *   3. Idempotent: skips partner if customer_call_log already has a row with
 *      segment_at_call='Churned' in the last 30 days.
 *      Frequency cap from churn_settings is also applied as an outer guard.
 *   4. Inserts ONE escalation row per partner in customer_call_log
 *      (channel=phone, outcome=NULL).
 *   5. Notifies every manager listed in churn_settings.notification_recipients
 *      (JSON array of admin_ids) via LINE push, resolving their line_user_id
 *      from the admin_users table.
 *
 * Lock: tmp/churn_escalate.lock — prevents concurrent runs.
 */

declare(strict_types=1);

set_time_limit(0);
date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CRM/AutoActionService.php';

use Classes\CRM\AutoActionService;

// ── Lock file (same pattern as cron/webhook_retry_processor.php) ─────────────
$lockFile = __DIR__ . '/../tmp/churn_escalate.lock';
$lockTtl  = 3600; // 1 hour max run time before stale lock expires

if (file_exists($lockFile) && (time() - filemtime($lockFile)) < $lockTtl) {
    echo '[' . date('Y-m-d H:i:s') . '] Already running (lock active). Exit.' . PHP_EOL;
    exit(0);
}

file_put_contents($lockFile, (string) getmypid());
register_shutdown_function(static function () use ($lockFile): void {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('churn_escalate starting');

try {
    $db      = Database::getInstance()->getConnection();
    $service = new AutoActionService($db);

    // Guard: no-op if system disabled or soft_launch active.
    if (!$service->shouldRun()) {
        $log('shouldRun=false (system_enabled=0 or soft_launch=1). Exit.');
        exit(0);
    }

    $settings = $service->loadSettings();
    if ($settings === null) {
        $log('ERROR: could not load churn_settings. Exit.');
        exit(1);
    }

    $capDays = (int) ($settings['notification_frequency_cap_days'] ?? 14);

    // Decode notification_recipients — JSON array of admin_ids (integers).
    $recipientsJson    = $settings['notification_recipients'] ?? null;
    $recipientAdminIds = [];
    if (!empty($recipientsJson)) {
        $decoded = json_decode((string) $recipientsJson, true);
        if (is_array($decoded)) {
            $recipientAdminIds = array_values(
                array_map(
                    'intval',
                    array_filter(
                        $decoded,
                        static fn ($v) => is_int($v)
                            || (is_string($v) && ctype_digit($v))
                    )
                )
            );
        }
    }

    if (empty($recipientAdminIds)) {
        $log('WARN: notification_recipients is empty — escalations logged but no LINE push sent.');
    }

    // ── Resolve manager LINE user IDs up front ────────────────────────────────
    // admin_users.line_user_id stores the LINE user ID for admin panel users.
    // If the column is absent or NULL we skip push for that manager.
    $managerLineUserIds = []; // admin_id (int) => line_user_id (string|null)
    foreach ($recipientAdminIds as $adminId) {
        $lineUserId = null;
        try {
            $aStmt = $db->prepare(
                'SELECT line_user_id FROM admin_users WHERE id = ? LIMIT 1'
            );
            $aStmt->execute([$adminId]);
            $aRow = $aStmt->fetch(\PDO::FETCH_ASSOC);
            if ($aRow && !empty($aRow['line_user_id'])) {
                $lineUserId = (string) $aRow['line_user_id'];
            }
        } catch (\Throwable $e) {
            // Column may not exist in all environments — log and continue.
            $log(
                "WARN: could not query admin_users.line_user_id"
                . " for admin_id={$adminId}: " . $e->getMessage()
            );
        }
        $managerLineUserIds[$adminId] = $lineUserId;
    }

    // ── Fetch Churned high-value customers ────────────────────────────────────
    $stmt = $db->query(
        "SELECT odoo_partner_id
           FROM customer_rfm_profile
          WHERE current_segment = 'Churned'
            AND is_high_value   = 1
          ORDER BY segment_changed_at ASC"
    );
    $partners = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    $log('Churned high-value partners found: ' . count($partners));

    $escalated     = 0;
    $skippedCap    = 0;
    $skippedRecent = 0;
    $errors        = 0;

    foreach ($partners as $partnerId) {
        $partnerId = (int) $partnerId;

        // Outer guard: frequency cap from settings.
        if (!$service->frequencyCapAllows($partnerId, $capDays)) {
            $skippedCap++;
            continue;
        }

        // Idempotency: skip if already escalated in last 30 days.
        // This is tighter than the generic frequency cap (30d fixed for Churned).
        $iStmt = $db->prepare(
            "SELECT id FROM customer_call_log
              WHERE odoo_partner_id = ?
                AND segment_at_call = 'Churned'
                AND called_at      >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              LIMIT 1"
        );
        $iStmt->execute([$partnerId]);
        if ($iStmt->fetch()) {
            $skippedRecent++;
            continue;
        }

        $recipientStr = implode(',', $recipientAdminIds);
        $notes        = "Churned high-value — manager escalation auto-queued"
                      . " (recipients: {$recipientStr})";

        $rowId = $service->enqueueCallLog(
            $partnerId,
            'phone',
            'Churned',
            $notes
        );

        if ($rowId === null) {
            $log("ERROR: enqueueCallLog failed for partner {$partnerId}");
            $errors++;
            continue;
        }

        $escalated++;

        // ── Notify each manager via LINE push ─────────────────────────────────
        foreach ($managerLineUserIds as $adminId => $lineUserId) {
            if ($lineUserId === null) {
                $log("INFO: no LINE user_id for admin_id={$adminId} — skipping push");
                continue;
            }
            $pushText = "[CNY Churn] ลูกค้า High-Value partner_id={$partnerId}"
                      . " อยู่ใน segment 'Churned' — กรุณา escalate โดยด่วน"
                      . " (call_log_id={$rowId})";
            $pushed = $service->pushLineText($lineUserId, $pushText);
            if (!$pushed) {
                $log(
                    "WARN: LINE push failed for admin_id={$adminId}"
                    . " line_user_id={$lineUserId} partner={$partnerId}"
                );
            }
        }
    }

    $msg = "churn_escalate done"
         . " — escalated={$escalated} skipped_cap={$skippedCap}"
         . " skipped_recent={$skippedRecent} errors={$errors}";
    $log($msg);

    $service->devLog('churn_escalate', $msg, [
        'escalated'      => $escalated,
        'skipped_cap'    => $skippedCap,
        'skipped_recent' => $skippedRecent,
        'errors'         => $errors,
        'recipients'     => $recipientAdminIds,
        'cap_days'       => $capDays,
    ]);

} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL;

    if (isset($service)) {
        $service->devLogError('churn_escalate', 'FATAL: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }

    exit(1);
}
