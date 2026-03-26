<?php
/**
 * One-time fix: update webhook records incorrectly stored as 'duplicate'
 * back to 'success' for the period when processWebhook was missing (d051e29).
 *
 * Usage: https://cny.re-ya.com/fix-duplicate-status.php?secret=FIX_2026_ONCE
 * DELETE THIS FILE after running.
 */

$secret = $_GET['secret'] ?? '';
if ($secret !== 'FIX_2026_ONCE') {
    http_response_code(403);
    die(json_encode(['error' => 'Forbidden']));
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Count before
    $before = (int) $db->query("
        SELECT COUNT(*) FROM odoo_webhooks_log
        WHERE status = 'duplicate'
          AND processed_at >= '2026-03-25 14:36:00'
    ")->fetchColumn();

    // Fix: duplicate → success for the affected window
    $db->exec("
        UPDATE odoo_webhooks_log
        SET status = 'success'
        WHERE status = 'duplicate'
          AND processed_at >= '2026-03-25 14:36:00'
    ");

    $updated = $db->query("SELECT ROW_COUNT()")->fetchColumn();

    echo json_encode([
        'ok'      => true,
        'before'  => $before,
        'updated' => (int) $updated,
        'message' => "Updated {$updated} records from 'duplicate' → 'success'",
        'note'    => 'DELETE this file now: fix-duplicate-status.php',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
