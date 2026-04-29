<?php
/**
 * Phase 1B Cron: Broadcast Delivery Reconciliation
 *
 * Schedule (every 5 minutes):
 *   *​/5 * * * * php /path/to/cron/broadcast_delivery_reconcile.php
 *
 * Reconciles broadcasts.sent_count against LINE's actual delivery counters:
 *   - Multicast / push / broadcast: LineAPI::getNumberOfSentMessages($yyyymmdd)
 *     returns the daily aggregate per LINE OA. We attribute it back to
 *     each broadcast on that date in proportion to sent_count.
 *   - Narrowcast: LineAPI::getNarrowcastProgress($requestId) returns the
 *     per-request success count directly.
 *
 * Idempotent. Skips rows reconciled within the last 5 minutes.
 * Exits quietly if the Phase 1B migration has not been applied yet.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

$db          = Database::getInstance()->getConnection();
$lineManager = new LineAccountManager($db);
$now         = date('Y-m-d H:i:s');

// Pre-flight: skip cleanly if migration_2026-04-28_broadcast_delivery.sql isn't applied.
try {
    $db->query("SELECT delivery_status FROM broadcasts LIMIT 1");
} catch (Exception $e) {
    error_log('[broadcast_delivery_reconcile] migration not applied yet — skipping');
    exit(0);
}

$reconciled = 0;
$skipped    = 0;

// ── Path A: narrowcast broadcasts ─────────────────────────────────────────
try {
    $stmtN = $db->prepare("SELECT id, line_account_id, sent_count, narrowcast_request_id
        FROM broadcasts
        WHERE target_type = 'narrowcast'
          AND status = 'sent'
          AND delivery_status IN ('pending','partial')
          AND narrowcast_request_id IS NOT NULL
          AND (delivery_reconciled_at IS NULL OR delivery_reconciled_at < (NOW() - INTERVAL 5 MINUTE))
        LIMIT 50");
    $stmtN->execute();
    foreach ($stmtN->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $line = $lineManager->getLineAPI((int)$row['line_account_id']);
            $r    = $line->getNarrowcastProgress((string)$row['narrowcast_request_id']);
            $body = $r['body'] ?? [];
            $phase   = $body['phase'] ?? null;
            $success = isset($body['successCount']) ? (int)$body['successCount'] : null;

            $newStatus = 'pending';
            if ($phase === 'succeeded')   { $newStatus = 'complete'; }
            elseif ($phase === 'failed')  { $newStatus = 'failed'; }
            elseif ($phase === 'sending') { $newStatus = 'partial'; }

            $upd = $db->prepare("UPDATE broadcasts
                SET delivered_count = ?, delivery_reconciled_at = NOW(), delivery_status = ?
                WHERE id = ?");
            $upd->execute([$success, $newStatus, (int)$row['id']]);
            $reconciled++;
        } catch (Exception $e) {
            error_log('[broadcast_delivery_reconcile] narrowcast row ' . $row['id'] . ' failed: ' . $e->getMessage());
            $skipped++;
        }
    }
} catch (Exception $e) {
    error_log('[broadcast_delivery_reconcile] narrowcast batch failed: ' . $e->getMessage());
}

// ── Path B: non-narrowcast (push / multicast / broadcast) ─────────────────
// LINE's /v2/bot/message/delivery/multicast?date=YYYYMMDD returns the daily
// aggregate. We attribute it per broadcast in proportion to sent_count.
try {
    $stmtD = $db->prepare("SELECT line_account_id, DATE(sent_at) AS d
        FROM broadcasts
        WHERE target_type <> 'narrowcast'
          AND status = 'sent'
          AND sent_at IS NOT NULL
          AND delivery_status IN ('pending','partial')
          AND (delivery_reconciled_at IS NULL OR delivery_reconciled_at < (NOW() - INTERVAL 5 MINUTE))
        GROUP BY line_account_id, DATE(sent_at)
        LIMIT 30");
    $stmtD->execute();
    foreach ($stmtD->fetchAll(PDO::FETCH_ASSOC) as $bucket) {
        $accId = (int)$bucket['line_account_id'];
        $day   = (string)$bucket['d'];
        $yyyymmdd = date('Ymd', strtotime($day));

        try {
            $line  = $lineManager->getLineAPI($accId);
            $r     = $line->getNumberOfSentMessages($yyyymmdd);
            $body  = $r['body'] ?? [];
            $statusFromLine = $body['status'] ?? 'unready';
            if ($statusFromLine !== 'ready') { continue; }
            $linerSuccess = isset($body['success']) ? (int)$body['success'] : 0;

            $list = $db->prepare("SELECT id, sent_count
                FROM broadcasts
                WHERE line_account_id = ? AND DATE(sent_at) = ?
                  AND target_type <> 'narrowcast'
                  AND status = 'sent'");
            $list->execute([$accId, $day]);
            $rows = $list->fetchAll(PDO::FETCH_ASSOC);
            $totalSent = 0;
            foreach ($rows as $rr) { $totalSent += (int)$rr['sent_count']; }
            if ($totalSent <= 0) { continue; }

            foreach ($rows as $rr) {
                $share = (int) round($linerSuccess * ((int)$rr['sent_count']) / $totalSent);
                $share = min($share, (int)$rr['sent_count']);
                $newStatus = ($share >= (int)$rr['sent_count']) ? 'complete' : 'partial';
                $upd = $db->prepare("UPDATE broadcasts
                    SET delivered_count = ?, delivery_reconciled_at = NOW(), delivery_status = ?
                    WHERE id = ?");
                $upd->execute([$share, $newStatus, (int)$rr['id']]);
                $reconciled++;
            }
        } catch (Exception $e) {
            error_log('[broadcast_delivery_reconcile] daily aggregate failed for acct ' . $accId . ' / ' . $day . ': ' . $e->getMessage());
            $skipped++;
        }
    }
} catch (Exception $e) {
    error_log('[broadcast_delivery_reconcile] daily batch failed: ' . $e->getMessage());
}

echo "[{$now}] Reconciled: {$reconciled}, skipped: {$skipped}\n";
