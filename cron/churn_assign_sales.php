<?php

/**
 * Cron: Churn Sales Assignment — Lost customers
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 4, §9, §13.5
 * Schedule: Daily, suggested 08:10 Asia/Bangkok
 * Crontab:  10 8 * * * /usr/bin/php /home/zrismpsz/public_html/cny.re-ya.com/cron/churn_assign_sales.php >> /tmp/churn_assign_sales.log 2>&1
 *
 * What this does:
 *   1. Guards: system_enabled=1 AND soft_launch=0, else no-op.
 *   2. Reads customer_rfm_profile WHERE current_segment='Lost'.
 *   3. Resolves salesperson via odoo_customer_projection.salesperson_id
 *      (spec §13.5: "assignment is fixed per customer via existing salesperson_id").
 *   4. Applies frequency cap per churn_settings.notification_frequency_cap_days.
 *   5. Inserts assignment row in customer_call_log:
 *        channel=phone, outcome=NULL,
 *        notes = salesperson name + "Lost segment auto-assigned".
 *   6. Notifies salesperson via LINE push if their line_user_id is resolvable.
 *      Notification is informational only (not customer-facing push).
 *
 * Lock: tmp/churn_assign_sales.lock — prevents concurrent runs.
 */

declare(strict_types=1);

set_time_limit(0);
date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CRM/AutoActionService.php';

use Classes\CRM\AutoActionService;

// ── Lock file (same pattern as cron/webhook_retry_processor.php) ─────────────
$lockFile = __DIR__ . '/../tmp/churn_assign_sales.lock';
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

$log('churn_assign_sales starting');

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

    // ── Fetch Lost customers + salesperson in one JOIN ────────────────────────
    // odoo_customer_projection.salesperson_id is the Odoo res.users ID resolved
    // to name via salesperson_name (spec §13.5, spike finding §13.3).
    $stmt = $db->query(
        "SELECT r.odoo_partner_id,
                p.salesperson_id,
                p.salesperson_name
           FROM customer_rfm_profile r
      LEFT JOIN odoo_customer_projection p
             ON p.odoo_partner_id = r.odoo_partner_id
          WHERE r.current_segment = 'Lost'
          ORDER BY r.segment_changed_at ASC"
    );
    $partners = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $log('Lost partners found: ' . count($partners));

    $assigned = 0;
    $skipped  = 0;
    $noSales  = 0;
    $errors   = 0;

    foreach ($partners as $row) {
        $partnerId       = (int) $row['odoo_partner_id'];
        $salespersonId   = $row['salesperson_id']   ?? null;
        $salespersonName = $row['salesperson_name'] ?? 'N/A';

        // Frequency cap: skip if we already logged an entry recently.
        if (!$service->frequencyCapAllows($partnerId, $capDays)) {
            $skipped++;
            continue;
        }

        // Build notes — always record who the assigned salesperson is.
        if (empty($salespersonId)) {
            $noSales++;
            $notes = 'Lost segment auto-assigned — ยังไม่มี salesperson ที่กำหนด'
                   . ' (salesperson_id=NULL)';
        } else {
            $notes = "Lost segment auto-assigned — salesperson: {$salespersonName}"
                   . " (id={$salespersonId})";
        }

        $rowId = $service->enqueueCallLog(
            $partnerId,
            'phone',
            'Lost',
            $notes
        );

        if ($rowId === null) {
            $log("ERROR: enqueueCallLog failed for partner {$partnerId}");
            $errors++;
            continue;
        }

        $assigned++;

        // ── Notify salesperson via LINE if resolvable ─────────────────────────
        // salesperson_id from odoo_customer_projection is an Odoo res.users ID.
        // We attempt to resolve it as an odoo_partner_id in odoo_line_users.
        // If the salesperson has no LINE mapping we skip the push — they may
        // use other channels (phone/email). The call_log row is always created.
        if (!empty($salespersonId) && is_numeric($salespersonId)) {
            $salesLineUserId = $service->resolveLineUserId((int) $salespersonId);
            if ($salesLineUserId !== null) {
                $pushText = "[CNY Churn] ลูกค้า partner_id={$partnerId} "
                          . "อยู่ใน segment 'Lost' — กรุณาโทรหาลูกค้าภายใน 24 ชั่วโมง"
                          . " (call_log_id={$rowId})";
                $pushed = $service->pushLineText($salesLineUserId, $pushText);
                if (!$pushed) {
                    $log(
                        "WARN: LINE push failed for salesperson"
                        . " line_user_id={$salesLineUserId} partner={$partnerId}"
                    );
                }
            } else {
                $log(
                    "INFO: no LINE user_id for salesperson_id={$salespersonId}"
                    . " — skipping LINE push"
                );
            }
        }
    }

    $msg = "churn_assign_sales done"
         . " — assigned={$assigned} skipped={$skipped}"
         . " no_sales={$noSales} errors={$errors}";
    $log($msg);

    $service->devLog('churn_assign_sales', $msg, [
        'assigned' => $assigned,
        'skipped'  => $skipped,
        'no_sales' => $noSales,
        'errors'   => $errors,
        'cap_days' => $capDays,
    ]);

} catch (\Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL;

    if (isset($service)) {
        $service->devLogError('churn_assign_sales', 'FATAL: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
        ]);
    }

    exit(1);
}
