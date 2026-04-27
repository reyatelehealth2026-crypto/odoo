<?php

/**
 * customer-churn-settings.php — Churn Tracker Admin Settings
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 5-6
 * Role gate: isSuperAdmin() ONLY (stricter than dashboard).
 * Data: reads churn_settings id=1; form POSTs to api/churn-settings-update.php.
 * Charset: utf8mb4, Timezone: Asia/Bangkok (+07:00)
 *
 * Called by: browser entry point only.
 * Calls:     includes/header.php, includes/footer.php,
 *            api/churn-settings-update.php (form POST target).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php'; // pulls auth_check, session, $currentUser

// ── Permission gate: super_admin only ───────────────────────────────────────
if (!isSuperAdmin()) {
    http_response_code(403);
    echo '<div style="padding:40px;font-family:sans-serif;color:#f87171;">'
       . 'ไม่มีสิทธิ์เข้าถึงหน้านี้ (Super Admin only)</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$db = Database::getInstance()->getConnection();

// ── Load current settings ────────────────────────────────────────────────────
$settings  = null;
$loadError = null;
try {
    $stmt     = $db->query('SELECT * FROM churn_settings WHERE id = 1 LIMIT 1');
    $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $loadError = $e->getMessage();
}

// ── Flash messages from redirect after save ──────────────────────────────────
$flashOk  = isset($_GET['saved']) && $_GET['saved'] === '1';
$flashErr = isset($_GET['error']) ? htmlspecialchars((string) $_GET['error'], ENT_QUOTES) : null;

// ── Helpers ──────────────────────────────────────────────────────────────────
$val = static function (string $key, $default = '') use ($settings): string {
    if ($settings === null) {
        return (string) $default;
    }
    return htmlspecialchars((string) ($settings[$key] ?? $default), ENT_QUOTES);
};

$checked = static function (string $key) use ($settings): string {
    if ($settings === null) {
        return '';
    }
    return ((int) ($settings[$key] ?? 0) === 1) ? 'checked' : '';
};
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Churn Settings — CNY Admin</title>
  <style>
    .cs-wrap     { max-width:820px; margin:32px auto; padding:0 16px; font-family:sans-serif; }
    .cs-card     { background:#1e293b; border-radius:12px; padding:28px 32px; margin-bottom:24px; }
    .cs-title    { font-size:1.4rem; font-weight:700; color:#f1f5f9; margin:0 0 4px; }
    .cs-subtitle { font-size:.85rem; color:#94a3b8; margin:0 0 24px; }
    .cs-section  { margin-bottom:28px; }
    .cs-section h3 {
      font-size:1rem; font-weight:600; color:#7dd3fc; margin:0 0 14px;
      border-bottom:1px solid #334155; padding-bottom:6px;
    }
    .cs-row      { display:flex; align-items:flex-start; gap:16px; margin-bottom:14px; }
    .cs-row label { min-width:260px; font-size:.875rem; color:#cbd5e1; padding-top:7px; }
    .cs-row input[type=number],
    .cs-row input[type=text],
    .cs-row textarea {
      flex:1; background:#0f172a; border:1px solid #334155; border-radius:6px;
      color:#f1f5f9; padding:7px 10px; font-size:.875rem; box-sizing:border-box; width:100%;
    }
    .cs-row input:focus,
    .cs-row textarea:focus { outline:none; border-color:#3b82f6; }
    .cs-toggle   { display:flex; align-items:center; gap:10px; padding-top:4px; }
    .cs-toggle input[type=checkbox] { width:18px; height:18px; cursor:pointer; }
    .cs-toggle span { font-size:.875rem; color:#cbd5e1; }
    .cs-hint     { font-size:.75rem; color:#64748b; margin:4px 0 0; }
    .cs-actions  { display:flex; gap:12px; justify-content:flex-end; padding-top:8px;
                   border-top:1px solid #334155; margin-top:8px; }
    .btn-save    { background:#3b82f6; color:#fff; border:none; border-radius:8px;
                   padding:10px 28px; font-size:.9rem; cursor:pointer; font-weight:600; }
    .btn-save:hover { background:#2563eb; }
    .btn-back    { color:#94a3b8; text-decoration:none; padding:10px 0; font-size:.875rem;
                   display:inline-flex; align-items:center; }
    .flash-ok    { background:#064e3b; color:#6ee7b7; border-radius:8px;
                   padding:12px 18px; margin-bottom:20px; font-size:.875rem; }
    .flash-err   { background:#7f1d1d; color:#fca5a5; border-radius:8px;
                   padding:12px 18px; margin-bottom:20px; font-size:.875rem; }
    .badge-on    { background:#064e3b; color:#6ee7b7; border-radius:4px;
                   padding:2px 8px; font-size:.75rem; font-weight:600; }
    .badge-off   { background:#450a0a; color:#fca5a5; border-radius:4px;
                   padding:2px 8px; font-size:.75rem; font-weight:600; }
  </style>
</head>
<body style="background:#0f172a; color:#f1f5f9; margin:0;">

<div class="cs-wrap">
  <div class="cs-card">

    <p class="cs-title">Churn Tracker Settings</p>
    <p class="cs-subtitle">
      ตั้งค่าระบบ Customer Churn Tracker — Super Admin เท่านั้น
      <?php if ($settings !== null): ?>
        &nbsp;|&nbsp;
        System:
        <?php if ((int) ($settings['system_enabled'] ?? 0) === 1): ?>
          <span class="badge-on">ENABLED</span>
        <?php else: ?>
          <span class="badge-off">DISABLED</span>
        <?php endif; ?>
        &nbsp;Soft-launch:
        <?php if ((int) ($settings['soft_launch'] ?? 1) === 1): ?>
          <span class="badge-on">ON</span>
        <?php else: ?>
          <span class="badge-off">OFF</span>
        <?php endif; ?>
      <?php endif; ?>
    </p>

    <?php if ($flashOk): ?>
      <div class="flash-ok">บันทึกการตั้งค่าเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if ($flashErr !== null): ?>
      <div class="flash-err">เกิดข้อผิดพลาด: <?= $flashErr ?></div>
    <?php endif; ?>

    <?php if ($loadError !== null): ?>
      <div class="flash-err">
        ไม่สามารถโหลดการตั้งค่า: <?= htmlspecialchars($loadError, ENT_QUOTES) ?>
      </div>
    <?php elseif ($settings === null): ?>
      <div class="flash-err">
        ไม่พบแถว churn_settings (id=1) — กรุณารัน migration ก่อน
      </div>
    <?php else: ?>

    <form method="POST" action="api/churn-settings-update.php">

      <!-- ── Feature Flags ─────────────────────────────────────────────────── -->
      <div class="cs-section">
        <h3>Feature Flags / Kill Switch</h3>

        <div class="cs-row">
          <label>System Enabled</label>
          <div style="flex:1">
            <div class="cs-toggle">
              <input type="checkbox" name="system_enabled" value="1"
                     <?= $checked('system_enabled') ?>>
              <span>เปิดใช้งาน auto-actions ทั้งหมด</span>
            </div>
            <p class="cs-hint">ปิด = kill switch — หยุดทุก auto-action ทันที (spec §9)</p>
          </div>
        </div>

        <div class="cs-row">
          <label>Soft Launch Mode</label>
          <div style="flex:1">
            <div class="cs-toggle">
              <input type="checkbox" name="soft_launch" value="1"
                     <?= $checked('soft_launch') ?>>
              <span>อ่านอย่างเดียว (ไม่มี notification ออก)</span>
            </div>
            <p class="cs-hint">เปิดเป็น default ใน 7 วันแรก — admin review segment list ก่อน</p>
          </div>
        </div>
      </div>

      <!-- ── Segment Thresholds ────────────────────────────────────────────── -->
      <div class="cs-section">
        <h3>Segment Thresholds (Recency Ratio)</h3>

        <div class="cs-row">
          <label>At-Risk threshold</label>
          <div style="flex:1">
            <input type="number" name="threshold_at_risk"
                   step="0.01" min="1.0" max="5.0"
                   value="<?= $val('threshold_at_risk', '1.50') ?>">
            <p class="cs-hint">ratio ≥ ค่านี้ → At-Risk (ช่วง 1.0–5.0)</p>
          </div>
        </div>

        <div class="cs-row">
          <label>Lost threshold</label>
          <div style="flex:1">
            <input type="number" name="threshold_lost"
                   step="0.01" min="1.0" max="5.0"
                   value="<?= $val('threshold_lost', '2.00') ?>">
            <p class="cs-hint">ต้องมากกว่า At-Risk threshold</p>
          </div>
        </div>

        <div class="cs-row">
          <label>Churned threshold</label>
          <div style="flex:1">
            <input type="number" name="threshold_churned"
                   step="0.01" min="1.0" max="10.0"
                   value="<?= $val('threshold_churned', '3.00') ?>">
            <p class="cs-hint">ต้องมากกว่า Lost threshold</p>
          </div>
        </div>

        <div class="cs-row">
          <label>Hysteresis buffer</label>
          <div style="flex:1">
            <input type="number" name="hysteresis_buffer"
                   step="0.01" min="0.0" max="1.0"
                   value="<?= $val('hysteresis_buffer', '0.20') ?>">
            <p class="cs-hint">
              ลูกค้าต้องลด ratio ต่ำกว่า (threshold − buffer) ก่อนจะ upgrade segment กลับ
            </p>
          </div>
        </div>
      </div>

      <!-- ── High-Value Settings ───────────────────────────────────────────── -->
      <div class="cs-section">
        <h3>High-Value Customer Definition</h3>

        <div class="cs-row">
          <label>Use top-percent mode</label>
          <div style="flex:1">
            <div class="cs-toggle">
              <input type="checkbox" name="high_value_use_top_percent" value="1"
                     <?= $checked('high_value_use_top_percent') ?>>
              <span>คำนวณ top N% อัตโนมัติ (แนะนำ — auto-tunes ตาม LTV distribution)</span>
            </div>
          </div>
        </div>

        <div class="cs-row">
          <label>Top percent (%)</label>
          <div style="flex:1">
            <input type="number" name="high_value_top_percent"
                   step="1" min="1" max="50"
                   value="<?= $val('high_value_top_percent', '20') ?>">
            <p class="cs-hint">ใช้เมื่อ top-percent mode เปิด (default 20%; cutoff ~79K THB ตาม spike)</p>
          </div>
        </div>

        <div class="cs-row">
          <label>Fixed threshold (THB/year)</label>
          <div style="flex:1">
            <input type="number" name="high_value_threshold_thb"
                   step="1000" min="0"
                   value="<?= $val('high_value_threshold_thb', '100000') ?>">
            <p class="cs-hint">ใช้เมื่อปิด top-percent mode (default 100,000 THB)</p>
          </div>
        </div>
      </div>

      <!-- ── Cost Guards ───────────────────────────────────────────────────── -->
      <div class="cs-section">
        <h3>Cost Guards (spec §9)</h3>

        <div class="cs-row">
          <label>Gemini daily cap (calls/day)</label>
          <div style="flex:1">
            <input type="number" name="gemini_daily_cap_calls"
                   step="1" min="0" max="10000"
                   value="<?= $val('gemini_daily_cap_calls', '200') ?>">
            <p class="cs-hint">
              วันนี้ใช้ไปแล้ว:
              <strong><?= (int) ($settings['gemini_calls_today'] ?? 0) ?></strong> calls
              (reset 00:00 น.)
            </p>
          </div>
        </div>

        <div class="cs-row">
          <label>Notification frequency cap (days)</label>
          <div style="flex:1">
            <input type="number" name="notification_frequency_cap_days"
                   step="1" min="1" max="365"
                   value="<?= $val('notification_frequency_cap_days', '14') ?>">
            <p class="cs-hint">
              ลูกค้าหนึ่งรายรับ auto check-in ได้สูงสุด 1 ครั้งต่อ N วัน (default 14 วัน)
            </p>
          </div>
        </div>
      </div>

      <!-- ── Notification Recipients ───────────────────────────────────────── -->
      <div class="cs-section">
        <h3>Manager Escalation Recipients</h3>

        <div class="cs-row">
          <label>Admin IDs (JSON array)</label>
          <div style="flex:1">
            <textarea name="notification_recipients" rows="3"
                      style="font-family:monospace"
                      placeholder='[1, 3, 7]'><?= $val('notification_recipients', '[]') ?></textarea>
            <p class="cs-hint">
              JSON array ของ admin_users.id ที่รับ LINE push เมื่อมี Churned high-value<br>
              ตัวอย่าง: [1, 3, 7]
            </p>
          </div>
        </div>
      </div>

      <!-- ── Form Actions ──────────────────────────────────────────────────── -->
      <div class="cs-actions">
        <a href="customer-churn.php" class="btn-back">กลับ Dashboard</a>
        <button type="submit" class="btn-save">บันทึกการตั้งค่า</button>
      </div>

    </form>

    <?php endif; ?>

  </div><!-- /.cs-card -->
</div><!-- /.cs-wrap -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
