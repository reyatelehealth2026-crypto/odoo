<?php

/**
 * api/churn-settings-update.php — POST endpoint: update churn_settings
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 5-6
 * Called by: customer-churn-settings.php form POST
 * Auth: isSuperAdmin() only — 403 for all others.
 * Method: POST only — 405 for GET/other.
 *
 * Validates all inputs before writing. On success redirects back to
 * customer-churn-settings.php?saved=1. On error redirects with ?error=<msg>.
 * All saves are audited to dev_logs.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

// ── Method guard ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Permission gate: super_admin only ────────────────────────────────────────
if (!isSuperAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}

$redirectBase = '../customer-churn-settings.php';

$redirectError = static function (string $msg) use ($redirectBase): never {
    header('Location: ' . $redirectBase . '?error=' . rawurlencode($msg));
    exit;
};

$redirectOk = static function () use ($redirectBase): never {
    header('Location: ' . $redirectBase . '?saved=1');
    exit;
};

// ── Input helpers ─────────────────────────────────────────────────────────────
$postFloat = static function (string $key, float $default = 0.0): float {
    $raw = $_POST[$key] ?? null;
    if ($raw === null || $raw === '') {
        return $default;
    }
    return (float) $raw;
};

$postInt = static function (string $key, int $default = 0): int {
    $raw = $_POST[$key] ?? null;
    if ($raw === null || $raw === '') {
        return $default;
    }
    return (int) $raw;
};

// Checkboxes are absent when unchecked; present with value "1" when checked.
$postBool = static function (string $key): int {
    return (isset($_POST[$key]) && $_POST[$key] === '1') ? 1 : 0;
};

$postStr = static function (string $key, string $default = ''): string {
    $raw = $_POST[$key] ?? null;
    if ($raw === null) {
        return $default;
    }
    return trim((string) $raw);
};

// ── Parse inputs ──────────────────────────────────────────────────────────────
$systemEnabled          = $postBool('system_enabled');
$softLaunch             = $postBool('soft_launch');
$highValueUseTopPercent = $postBool('high_value_use_top_percent');

$thresholdAtRisk        = $postFloat('threshold_at_risk',        1.50);
$thresholdLost          = $postFloat('threshold_lost',           2.00);
$thresholdChurned       = $postFloat('threshold_churned',        3.00);
$hysteresisBuffer       = $postFloat('hysteresis_buffer',        0.20);
$highValueThresholdThb  = $postFloat('high_value_threshold_thb', 100000.0);

$highValueTopPercent          = $postInt('high_value_top_percent',          20);
$geminiDailyCapCalls          = $postInt('gemini_daily_cap_calls',         200);
$notificationFrequencyCapDays = $postInt('notification_frequency_cap_days', 14);

$notificationRecipients = $postStr('notification_recipients', '[]');

// ── Validation ────────────────────────────────────────────────────────────────

// threshold_at_risk ∈ [1.0, 5.0]
if ($thresholdAtRisk < 1.0 || $thresholdAtRisk > 5.0) {
    $redirectError('threshold_at_risk ต้องอยู่ระหว่าง 1.0 ถึง 5.0');
}

// threshold_lost > threshold_at_risk and ∈ [1.0, 5.0]
if ($thresholdLost <= $thresholdAtRisk) {
    $redirectError('threshold_lost ต้องมากกว่า threshold_at_risk');
}
if ($thresholdLost < 1.0 || $thresholdLost > 5.0) {
    $redirectError('threshold_lost ต้องอยู่ระหว่าง 1.0 ถึง 5.0');
}

// threshold_churned > threshold_lost and ∈ [1.0, 10.0]
if ($thresholdChurned <= $thresholdLost) {
    $redirectError('threshold_churned ต้องมากกว่า threshold_lost');
}
if ($thresholdChurned < 1.0 || $thresholdChurned > 10.0) {
    $redirectError('threshold_churned ต้องอยู่ระหว่าง 1.0 ถึง 10.0');
}

// hysteresis_buffer ∈ [0.0, 1.0]
if ($hysteresisBuffer < 0.0 || $hysteresisBuffer > 1.0) {
    $redirectError('hysteresis_buffer ต้องอยู่ระหว่าง 0.0 ถึง 1.0');
}

// high_value_top_percent ∈ [1, 50]
if ($highValueTopPercent < 1 || $highValueTopPercent > 50) {
    $redirectError('high_value_top_percent ต้องอยู่ระหว่าง 1 ถึง 50');
}

// high_value_threshold_thb must be non-negative
if ($highValueThresholdThb < 0.0) {
    $redirectError('high_value_threshold_thb ต้องไม่ติดลบ');
}

// gemini_daily_cap_calls ∈ [0, 10000]
if ($geminiDailyCapCalls < 0 || $geminiDailyCapCalls > 10000) {
    $redirectError('gemini_daily_cap_calls ต้องอยู่ระหว่าง 0 ถึง 10000');
}

// notification_frequency_cap_days ∈ [1, 365]
if ($notificationFrequencyCapDays < 1 || $notificationFrequencyCapDays > 365) {
    $redirectError('notification_frequency_cap_days ต้องอยู่ระหว่าง 1 ถึง 365');
}

// notification_recipients must be a valid JSON array of integers
$decodedRecipients = json_decode($notificationRecipients, true);
if (
    json_last_error() !== JSON_ERROR_NONE
    || !is_array($decodedRecipients)
    || !array_is_list($decodedRecipients)
) {
    $redirectError('notification_recipients ต้องเป็น JSON array ที่ถูกต้อง เช่น [1, 3, 7]');
}
foreach ($decodedRecipients as $item) {
    if (!is_int($item) && !(is_string($item) && ctype_digit((string) $item))) {
        $redirectError('notification_recipients ต้องมีเฉพาะ admin ID (ตัวเลข) เท่านั้น');
    }
}
// Re-encode to normalise whitespace and ensure compact JSON.
$notificationRecipients = json_encode(
    array_values(array_map('intval', $decodedRecipients)),
    JSON_UNESCAPED_UNICODE
);

// ── Write to DB ───────────────────────────────────────────────────────────────
try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare(
        'UPDATE churn_settings SET
            system_enabled                  = ?,
            soft_launch                     = ?,
            threshold_at_risk               = ?,
            threshold_lost                  = ?,
            threshold_churned               = ?,
            hysteresis_buffer               = ?,
            high_value_threshold_thb        = ?,
            high_value_use_top_percent      = ?,
            high_value_top_percent          = ?,
            gemini_daily_cap_calls          = ?,
            notification_frequency_cap_days = ?,
            notification_recipients         = ?
         WHERE id = 1'
    );

    $stmt->execute([
        $systemEnabled,
        $softLaunch,
        round($thresholdAtRisk,       2),
        round($thresholdLost,         2),
        round($thresholdChurned,      2),
        round($hysteresisBuffer,      2),
        round($highValueThresholdThb, 2),
        $highValueUseTopPercent,
        $highValueTopPercent,
        $geminiDailyCapCalls,
        $notificationFrequencyCapDays,
        $notificationRecipients,
    ]);

    // ── Audit log ─────────────────────────────────────────────────────────────
    $adminId   = $currentUser['id']       ?? null;
    $adminName = $currentUser['name']     ?? ($currentUser['username'] ?? 'unknown');

    $auditStmt = $db->prepare(
        'INSERT INTO dev_logs (log_type, source, message, data, created_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    $auditStmt->execute([
        'info',
        'churn-settings-update',
        "churn_settings updated by admin_id={$adminId} ({$adminName})",
        json_encode([
            'admin_id'                        => $adminId,
            'system_enabled'                  => $systemEnabled,
            'soft_launch'                     => $softLaunch,
            'threshold_at_risk'               => $thresholdAtRisk,
            'threshold_lost'                  => $thresholdLost,
            'threshold_churned'               => $thresholdChurned,
            'hysteresis_buffer'               => $hysteresisBuffer,
            'high_value_threshold_thb'        => $highValueThresholdThb,
            'high_value_use_top_percent'      => $highValueUseTopPercent,
            'high_value_top_percent'          => $highValueTopPercent,
            'gemini_daily_cap_calls'          => $geminiDailyCapCalls,
            'notification_frequency_cap_days' => $notificationFrequencyCapDays,
            'notification_recipients'         => $notificationRecipients,
        ], JSON_UNESCAPED_UNICODE),
    ]);

} catch (\Throwable $e) {
    error_log('[churn-settings-update] DB error: ' . $e->getMessage());
    $redirectError('เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage());
}

$redirectOk();
