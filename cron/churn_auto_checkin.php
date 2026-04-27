<?php

/**
 * Cron: Churn Auto Check-in — At-Risk customers
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 4, §9
 * Schedule: Daily, suggested 08:00 Asia/Bangkok
 * Crontab:  0 8 * * * /usr/bin/php /home/zrismpsz/public_html/cny.re-ya.com/cron/churn_auto_checkin.php >> /tmp/churn_auto_checkin.log 2>&1
 *
 * What this does:
 *   1. Checks system_enabled=1 AND soft_launch=0 via AutoActionService::shouldRun()
 *      — exits immediately (no-op) if either guard fails.
 *   2. Reads customer_rfm_profile WHERE current_segment='At-Risk'
 *      AND cycle_confidence='high'.
 *   3. For each partner: skips if last customer_call_log entry is within
 *      notification_frequency_cap_days (default 14 days).
 *   4. Drafts a soft Thai check-in message into customer_call_log with
 *      channel='line', outcome=NULL (queued for admin approval).
 *   5. Does NOT push to LINE — admin UI releases the queue.
 *
 * Lock: tmp/churn_auto_checkin.lock — prevents concurrent runs.
 */

declare(strict_types=1);

set_time_limit(0);
date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CRM/AutoActionService.php';

use Classes\CRM\AutoActionService;

// ── Lock file (same pattern as cron/webhook_retry_processor.php) ─────────────
$lockFile = __DIR__ . '/../tmp/churn_auto_checkin.lock';
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

$log('churn_auto_checkin starting');

try {
    $db      = Database::getInstance()->getConnection();
    $service = new AutoActionService($db);

    // Guard: no-op if system disabled or soft_launch active.
    if (!$service->shouldRun()) {
        $log('shouldRun=false (system_enabled=0 or soft_launch=1). Exit.');
        exit(0);
    }

    // Load settings for frequency cap.
    $settings = $service->loadSettings();
    if ($settings === null) {
        $log('ERROR: could not load churn_settings. Exit.');
        exit(1);
    }
    $capDays = (int) ($settings['notification_frequency_cap_days'] ?? 14);

    // ── Fetch At-Risk customers with high cycle confidence ────────────────────
    $stmt = $db->query(
        "SELECT odoo_partner_id
           FROM customer_rfm_profile
          WHERE current_segment  = 'At-Risk'
            AND cycle_confidence = 'high'
          ORDER BY segment_changed_at ASC"
    );
    $partners = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    $log('At-Risk high-confidence partners found: ' . count($partners));

    $queued  = 0;
    $skipped = 0;
    $errors  = 0;

    foreach ($partners as $partnerId) {
        $partnerId = (int) $partnerId;

        // Frequency cap: skip if we already logged an entry recently.
        if (!$service->frequencyCapAllows($partnerId, $capDays)) {
            $skipped++;
            continue;
        }

        // Soft check-in draft in Thai — outcome=NULL means pending admin approval.
        $notes = 'สวัสดีครับ/ค่ะ ทีม CNY ต้องการสอบถามความต้องการสินค้าในรอบนี้ '
               . 'หากสะดวก รบกวนติดต่อกลับได้เลยนะครับ/ค่ะ '
               . '(Auto-draft — At-Risk, awaiting admin approval)';

        $rowId = $service->enqueueCallLog(
            $partnerId,
            'line',
            'At-Risk',
            $notes
        );

        if ($rowId === null) {
            $log("ERROR: enqueueCallLog failed for partner {$partnerId}");
            $errors++;
            continue;
        }

        $queued++;
    }

    $msg = "churn_auto_checkin done — queued={$queued} skipped={$skipped} errors={$errors}";
    $log($msg);

    $service->devLog('churn_auto_checkin', $msg, [
        'queued'   => $queued,
        'skipped'  => $skipped,
        'errors'   => $errors,
        'cap_days' => $capDays,
    ]);

} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL;

    if (isset($service)) {
        $service->devLogError('churn_auto_checkin', 'FATAL: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }

    exit(1);
}
