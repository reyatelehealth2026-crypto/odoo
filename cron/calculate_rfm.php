<?php
/**
 * Cron: Nightly RFM Recompute — CNY Wholesale Churn Tracker
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.3, §8
 * Schedule: 02:00 Asia/Bangkok daily
 * Usage:  php cron/calculate_rfm.php
 *
 * Idempotent — safe to re-run; produces the same result for the same data.
 * Memory-bounded: processes one partner at a time inside RFMService loop.
 *
 * Logging: summary written to `dev_logs` per CLAUDE.md convention.
 */

declare(strict_types=1);

set_time_limit(600);
ini_set('memory_limit', '256M');

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Classes\CRM\RFMCalculator;
use Classes\CRM\RFMRepository;
use Classes\CRM\RFMService;

date_default_timezone_set('Asia/Bangkok');

$startTime = microtime(true);

// ── Logging helper (writes to dev_logs per CLAUDE.md) ─────────────────────
$devLog = static function (
    \PDO $pdo,
    string $type,
    string $message,
    mixed $data = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at)
            VALUES (?, 'calculate_rfm', ?, ?, NOW())
        ");
        $stmt->execute([
            $type,
            $message,
            $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (\Throwable $e) {
        // Fallback to error_log so the cron run is never blocked by a log failure.
        error_log("[calculate_rfm] dev_logs write failed: " . $e->getMessage());
    }
};

$stdLog = static function (string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
    error_log("[calculate_rfm] {$msg}");
};

// ── Main ───────────────────────────────────────────────────────────────────
try {
    $db   = \Database::getInstance()->getConnection();
    $calc = new RFMCalculator();
    $repo = new RFMRepository($db);
    $svc  = new RFMService($db, $calc, $repo);

    $stdLog('Starting RFM recompute...');

    $result = $svc->recomputeAll();

    $elapsed = round(microtime(true) - $startTime, 2);

    $summary = [
        'processed'       => $result['processed'],
        'segment_changes' => $result['segment_changes'],
        'elapsed_s'       => $elapsed,
    ];

    $stdLog(sprintf(
        'RFM recompute complete. processed=%d segment_changes=%d elapsed=%.2fs',
        $result['processed'],
        $result['segment_changes'],
        $elapsed
    ));

    $devLog($db, 'cron', 'RFM recompute complete', $summary);
} catch (\Throwable $e) {
    $elapsed = round(microtime(true) - $startTime, 2);

    $errContext = [
        'error'     => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
        'elapsed_s' => $elapsed,
    ];

    $stdLog('ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // Write error to dev_logs if we have a DB handle.
    if (isset($db)) {
        $devLog($db, 'error', 'RFM recompute failed', $errContext);
    }

    // Non-zero exit so cron monitoring can detect failure.
    exit(1);
}
