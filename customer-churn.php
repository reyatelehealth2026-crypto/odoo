<?php
/**
 * customer-churn.php — CNY Wholesale Customer Churn Tracker Dashboard
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.5 (Phase 3)
 * Role gate: admin or super_admin only.
 * Data: read-only from customer_rfm_profile, churn_settings, odoo_customer_projection.
 * Charset: utf8mb4, Timezone: Asia/Bangkok (+07:00)
 *
 * Called by: browser entry point (no PHP file imports this).
 * Calls:     includes/header.php, includes/footer.php, assets/js/customer-churn.js
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/header.php'; // pulls auth_check, session, $currentUser

// ── Permission gate ─────────────────────────────────────────────────────────
if (!isAdmin()) {
    http_response_code(403);
    echo '<div style="padding:40px;font-family:sans-serif;color:#f87171;">ไม่มีสิทธิ์เข้าถึงหน้านี้ (Admin only)</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── DB connection ────────────────────────────────────────────────────────────
$db = Database::getInstance()->getConnection();

// ── Soft-launch + settings ───────────────────────────────────────────────────
$softLaunch      = false;
$settingsRow     = null;
$geminiCallsToday = 0;
$geminiDailyCap   = 200;
try {
    $stmtSettings = $db->query(
        'SELECT soft_launch, gemini_calls_today, gemini_daily_cap_calls
           FROM churn_settings WHERE id = 1 LIMIT 1'
    );
    if ($stmtSettings) {
        $settingsRow = $stmtSettings->fetch(\PDO::FETCH_ASSOC);
    }
    if ($settingsRow) {
        $softLaunch       = (bool) $settingsRow['soft_launch'];
        $geminiCallsToday = (int) ($settingsRow['gemini_calls_today'] ?? 0);
        $geminiDailyCap   = (int) ($settingsRow['gemini_daily_cap_calls'] ?? 200);
    }
} catch (\Throwable $e) {
    // churn_settings may not exist pre-migration; page still renders.
}

// ── KPI segment counts ───────────────────────────────────────────────────────
$kpiCounts = [
    'Champion'    => 0,
    'Watchlist'   => 0,
    'At-Risk'     => 0,
    'Lost'        => 0,
    'Churned'     => 0,
    'Hibernating' => 0,
];
$totalEligible  = 0;
$lastComputedAt = null;

try {
    $stmtKpi = $db->query(
        'SELECT current_segment, COUNT(*) AS cnt
           FROM customer_rfm_profile
          WHERE current_segment IS NOT NULL
          GROUP BY current_segment'
    );
    if ($stmtKpi) {
        foreach ($stmtKpi->fetchAll(\PDO::FETCH_ASSOC) as $kpiRow) {
            $seg = $kpiRow['current_segment'];
            if (array_key_exists($seg, $kpiCounts)) {
                $kpiCounts[$seg] = (int) $kpiRow['cnt'];
            }
        }
    }

    $stmtTotal = $db->query('SELECT COUNT(*) FROM customer_rfm_profile');
    if ($stmtTotal) {
        $totalEligible = (int) $stmtTotal->fetchColumn();
    }

    $stmtLast = $db->query('SELECT MAX(computed_at) FROM customer_rfm_profile');
    if ($stmtLast) {
        $val = $stmtLast->fetchColumn();
        $lastComputedAt = $val ?: null;
    }
} catch (\Throwable $e) {
    // Table may not exist yet; all counts remain 0.
}

// ── Watchlist rows (Churned HV first → Lost → At-Risk → Watchlist) ──────────
$watchlistRows = [];
try {
    $watchSql = "
        SELECT
            p.odoo_partner_id,
            COALESCE(cp.customer_name, CONCAT('Partner #', p.odoo_partner_id)) AS store_name,
            cp.customer_ref,
            p.customer_type,
            p.avg_order_cycle_days,
            DATEDIFF(CURDATE(), p.last_order_date)  AS days_since,
            p.recency_ratio,
            p.lifetime_value,
            p.last_order_date,
            p.is_high_value,
            p.is_seasonal,
            p.cycle_confidence,
            p.current_segment,
            lu.line_user_id
        FROM customer_rfm_profile p
        LEFT JOIN odoo_customer_projection cp
               ON cp.odoo_partner_id = p.odoo_partner_id
        LEFT JOIN odoo_line_users lu
               ON lu.odoo_partner_id = p.odoo_partner_id
        WHERE p.current_segment IN ('Churned','Lost','At-Risk','Watchlist')
        ORDER BY
            CASE p.current_segment
                WHEN 'Churned'   THEN CASE WHEN p.is_high_value = 1 THEN 1 ELSE 2 END
                WHEN 'Lost'      THEN 3
                WHEN 'At-Risk'   THEN 4
                WHEN 'Watchlist' THEN 5
                ELSE 6
            END ASC,
            p.recency_ratio DESC
        LIMIT 200
    ";
    $stmtWatch = $db->query($watchSql);
    if ($stmtWatch) {
        $watchlistRows = $stmtWatch->fetchAll(\PDO::FETCH_ASSOC);
    }
} catch (\Throwable $e) {
    $watchlistRows = [];
}

// ── Enrich watchlist rows with InboxSentinel flags (last 30 days) ──────────
// Surfaces customers who are BOTH in churn segment AND complaining in inbox —
// the highest-priority "P0" overlap discovered in the historical scan.
// See classes/CRM/InboxSentinel.php.
$inboxFlags = [];
try {
    if (!empty($watchlistRows)) {
        require_once __DIR__ . '/classes/CRM/InboxSentinel.php';
        $sentinel    = new \Classes\CRM\InboxSentinel($db);
        $partnerIds  = array_column($watchlistRows, 'odoo_partner_id');
        $inboxFlags  = $sentinel->getInboxFlagsForPartners($partnerIds, 30);
    }
} catch (\Throwable $e) {
    $inboxFlags = [];
}

// ── Pure helper functions ────────────────────────────────────────────────────

function churnSegmentBadgeClass(string $seg): string
{
    return match ($seg) {
        'Champion'    => 'badge-green',
        'Watchlist'   => 'badge-yellow',
        'At-Risk'     => 'badge-amber',
        'Lost'        => 'badge-red',
        'Churned'     => 'badge-red',
        'Hibernating' => 'badge-gray',
        default       => 'badge-gray',
    };
}

function churnKpiColor(string $seg): string
{
    return match ($seg) {
        'Champion'    => '#34d399',
        'Watchlist'   => '#fbbf24',
        'At-Risk'     => '#fb923c',
        'Lost'        => '#f87171',
        'Churned'     => '#ef4444',
        'Hibernating' => '#94a3b8',
        default       => '#94a3b8',
    };
}

function churnFormatThb(mixed $val): string
{
    if ($val === null || $val === '') {
        return '—';
    }
    return '฿' . number_format((float) $val, 0);
}

function churnFormatCycle(mixed $val): string
{
    if ($val === null || $val === '') {
        return '—';
    }
    return (string) (int) round((float) $val) . ' วัน';
}

function churnFormatRatio(mixed $val): string
{
    if ($val === null || $val === '') {
        return '—';
    }
    return number_format((float) $val, 2) . 'x';
}

$typeLabels = [
    'pharmacy'  => 'ร้านยา',
    'clinic'    => 'คลินิก',
    'hospital'  => 'โรงพยาบาล',
    'other'     => 'อื่นๆ',
];

$kpiDefs = [
    'Champion'    => ['icon' => '&#9733;',  'label' => 'Champion · ลูกค้าขาประจำ',  'sub' => 'ซื้อตามรอบปกติ'],
    'Watchlist'   => ['icon' => '&#128064;','label' => 'Watchlist · เริ่มห่าง',     'sub' => 'ช้ากว่ารอบเล็กน้อย'],
    'At-Risk'     => ['icon' => '&#9888;',  'label' => 'At-Risk · เริ่มหาย',          'sub' => 'เลยรอบสั่งปกติ 1.5×'],
    'Lost'        => ['icon' => '&#128679;','label' => 'Lost · หายไปแล้ว',           'sub' => 'เลยรอบสั่งปกติ 2× (ต้องตามด่วน)'],
    'Churned'     => ['icon' => '&#128681;','label' => 'Churned · เลิกซื้อ (VIP)',    'sub' => 'เลยรอบ 3× + ยอดสูง (escalate)'],
    'Hibernating' => ['icon' => '&#128739;','label' => 'Hibernating · จำศีล',         'sub' => 'เลยรอบ 3× (ยอดทั่วไป)'],
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Churn Tracker — CNY Wholesale</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    /* ── Base — light/white palette ── */
    *, *::before, *::after { box-sizing: border-box; }
    * { font-family: 'Noto Sans Thai', sans-serif; }
    body { background: #f9fafb; color: #1f2937; min-height: 100vh; -webkit-font-smoothing: antialiased; }

    .page { max-width: 1440px; margin: 0 auto; padding: 0 16px; }
    @media(min-width:640px) { .page { padding: 0 24px; } }

    /* ── Tab nav (matches inbox-intelligence) ── */
    .tab-nav { display:flex; gap:4px; align-items:center; flex-wrap:wrap; }
    .tab-link {
      display:inline-flex; align-items:center; gap:6px;
      padding:8px 14px; border-radius:8px 8px 0 0;
      font-size:13px; font-weight:500; color:#6b7280;
      text-decoration:none; border:1px solid transparent;
      border-bottom:none; transition:all 0.15s;
    }
    .tab-link:hover { background:#f3f4f6; color:#1f2937; }
    .tab-link.active {
      background:#ffffff; color:#1f2937; font-weight:600;
      border-color:#e5e7eb;
      box-shadow:0 -1px 0 rgba(0,0,0,0.02);
      position:relative; top:1px;
    }

    /* ── Card ── */
    .card { background:#ffffff; border:1px solid #e5e7eb; border-radius:14px; transition:border-color 0.2s, box-shadow 0.2s; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
    .card:hover { border-color:#d1d5db; box-shadow:0 1px 3px rgba(0,0,0,0.06); }
    .p-5 { padding: 20px; }
    .card-title { font-size:13px; font-weight:600; color:#1f2937; margin-bottom:14px; display:flex; align-items:center; gap:7px; }

    /* ── Section heading ── */
    .sec-head {
      display:flex; align-items:center; gap:10px;
      font-size:11px; font-weight:700; letter-spacing:0.12em;
      text-transform:uppercase; color:#6b7280;
      margin:32px 0 16px;
    }
    .sec-head::after { content:''; flex:1; height:1px; background:linear-gradient(90deg,#e5e7eb,transparent); }

    /* ── 6-card KPI strip ── */
    .kpi-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
    @media(min-width:640px)  { .kpi-grid { grid-template-columns:repeat(3,1fr); } }
    @media(min-width:1024px) { .kpi-grid { grid-template-columns:repeat(6,1fr); gap:12px; } }

    .kpi-card {
      background:#ffffff; border:1px solid #e5e7eb; border-radius:12px;
      padding:14px; transition:border-color 0.2s, transform 0.15s, box-shadow 0.2s;
      box-shadow:0 1px 2px rgba(0,0,0,0.03);
    }
    .kpi-card:hover { border-color:#d1d5db; transform:translateY(-1px); box-shadow:0 4px 8px rgba(0,0,0,0.05); }
    .kpi-icon  { font-size:20px; margin-bottom:6px; }
    .kpi-label { font-size:11px; color:#6b7280; font-weight:600; letter-spacing:0.02em; }
    .kpi-value { font-size:26px; font-weight:700; line-height:1.15; font-family:'JetBrains Mono',monospace; margin:3px 0; }
    .kpi-sub   { font-size:10px; color:#9ca3af; }

    /* ── Badges (light theme — solid color on tinted bg) ── */
    .badge { display:inline-flex; align-items:center; padding:2px 9px; border-radius:9999px; font-size:11px; font-weight:600; border:1px solid; line-height:1.5; white-space:nowrap; }
    .badge-green  { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
    .badge-yellow { background:#fef9c3; color:#854d0e; border-color:#fde68a; }
    .badge-amber  { background:#ffedd5; color:#9a3412; border-color:#fed7aa; }
    .badge-red    { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    .badge-purple { background:#f3e8ff; color:#6b21a8; border-color:#e9d5ff; }
    .badge-gray   { background:#f3f4f6; color:#4b5563; border-color:#e5e7eb; }
    .badge-blue   { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }

    /* ── Table ── */
    .dt { width:100%; border-collapse:collapse; font-size:13px; }
    .dt thead th { padding:10px 8px; text-align:left; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; font-size:11px; white-space:nowrap; text-transform:uppercase; letter-spacing:0.05em; }
    .dt tbody td { padding:10px 8px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
    .dt tbody tr:last-child td { border-bottom:none; }
    .dt tbody tr:hover td { background:#f9fafb; }
    .row-critical td { background:#fef2f2 !important; }
    .row-critical:hover td { background:#fee2e2 !important; }
    .row-warn     td { background:#fff7ed !important; }
    .row-warn:hover td { background:#ffedd5 !important; }

    /* ── Alert banners ── */
    .alert { border-radius:10px; padding:14px 18px; }
    .alert-amber { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    .alert-title { font-weight:700; font-size:13px; display:flex; align-items:center; gap:8px; margin-bottom:4px; }

    /* ── Health strip ── */
    .health-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-top:12px; }
    .health-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; text-align:center; }
    .health-num   { font-size:22px; font-weight:700; font-family:'JetBrains Mono',monospace; line-height:1; }
    .health-label { font-size:11px; color:#6b7280; margin-top:6px; font-weight:500; }

    /* ── Shimmer skeleton (light) ── */
    @keyframes shimmer { 0%{background-position:-200% 0} 100%{background-position:200% 0} }
    .sk { background:linear-gradient(90deg,#f3f4f6 25%,#e5e7eb 50%,#f3f4f6 75%); background-size:200% 100%; animation:shimmer 1.5s infinite; border-radius:8px; }

    /* ── Spinner ── */
    @keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
    .spinning { animation:spin 0.8s linear infinite; }

    /* ── Utilities (light-tone semantic colors) ── */
    .c-green  { color:#16a34a; } .c-amber { color:#ea580c; }
    .c-red    { color:#dc2626; } .c-blue  { color:#2563eb; }
    .c-slate4 { color:#6b7280; } .c-slate5 { color:#9ca3af; }
    .mono { font-family:'JetBrains Mono',monospace; }
    .chart-wrap { position:relative; height:220px; }
    .table-scroll { overflow-x:auto; }

    /* ── Action link (table) ── */
    .act-link {
      display:inline-flex; align-items:center; gap:4px;
      padding:5px 10px; border-radius:6px;
      font-size:12px; font-weight:500;
      background:#eff6ff; color:#1d4ed8;
      border:1px solid #bfdbfe;
      text-decoration:none; transition:all 0.15s;
    }
    .act-link:hover { background:#dbeafe; border-color:#93c5fd; }

    /* ── Inbox sentinel flag pill ── */
    .inbox-flag {
      display:inline-flex; align-items:center; gap:4px;
      padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600;
      cursor:help; white-space:nowrap;
    }
    .inbox-flag-red    { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
    .inbox-flag-orange { background:#ffedd5; color:#9a3412; border:1px solid #fed7aa; }
    .inbox-flag-yellow { background:#fef9c3; color:#854d0e; border:1px solid #fde68a; }
    .inbox-flag-none   { color:#d1d5db; font-size:13px; }

    /* ── P0 Critical Overlap card ── */
    .overlap-alert {
      background:linear-gradient(135deg,#fef2f2 0%,#fff7ed 100%);
      border:1px solid #fecaca;
      border-left:4px solid #dc2626;
      padding:16px 20px; border-radius:12px;
      margin-bottom:20px;
    }
    .overlap-alert-title {
      font-size:13px; font-weight:700; color:#991b1b;
      display:flex; align-items:center; gap:8px; margin-bottom:8px;
    }
    .overlap-alert-list { display:flex; flex-direction:column; gap:6px; }
    .overlap-alert-item {
      font-size:12.5px; color:#374151; padding:6px 10px;
      background:rgba(255,255,255,0.7); border-radius:6px;
      display:flex; align-items:center; gap:8px; flex-wrap:wrap;
    }
    .overlap-alert-item .seg-tag {
      font-size:10px; font-weight:700; padding:1px 6px; border-radius:4px;
      background:#dc2626; color:#fff;
    }
    .overlap-alert-item .seg-tag.lost     { background:#dc2626; }
    .overlap-alert-item .seg-tag.atrisk   { background:#ea580c; }
    .overlap-alert-item .seg-tag.churned  { background:#7f1d1d; }

    /* ── Action button: AI variant ── */
    .act-link-ai {
      background:#fef3c7; color:#92400e; border-color:#fde68a;
      cursor:pointer; font-family:inherit;
    }
    .act-link-ai:hover { background:#fde68a; border-color:#fbbf24; }
    .act-link-ai:disabled { opacity:0.6; cursor:wait; }
    .act-link-ai .spin-icon { animation:spin 0.8s linear infinite; }

    /* ── AI Brief Modal ── */
    .ai-modal-overlay {
      display:none; position:fixed; inset:0; z-index:100;
      background:rgba(17,24,39,0.55); backdrop-filter:blur(3px);
      align-items:flex-start; justify-content:center; padding:48px 16px;
      overflow-y:auto;
    }
    .ai-modal-overlay.open { display:flex; }
    .ai-modal {
      background:#ffffff; border:1px solid #e5e7eb; border-radius:16px;
      max-width:780px; width:100%; box-shadow:0 24px 60px rgba(0,0,0,0.18);
      overflow:hidden;
    }
    .ai-modal-head {
      display:flex; align-items:center; justify-content:space-between;
      padding:18px 22px; border-bottom:1px solid #f3f4f6;
      background:linear-gradient(135deg,#fffbeb 0%,#ffffff 100%);
    }
    .ai-modal-title { font-size:15px; font-weight:700; color:#111827; display:flex; align-items:center; gap:8px; }
    .ai-modal-sub   { font-size:12px; color:#6b7280; margin-top:2px; }
    .ai-modal-close {
      background:transparent; border:none; cursor:pointer; color:#6b7280;
      font-size:22px; line-height:1; padding:4px 8px; border-radius:6px;
    }
    .ai-modal-close:hover { background:#f3f4f6; color:#111827; }
    .ai-modal-body { padding:20px 22px; max-height:70vh; overflow-y:auto; }

    .ai-section { margin-bottom:18px; }
    .ai-section:last-child { margin-bottom:0; }
    .ai-section-label {
      font-size:11px; font-weight:700; letter-spacing:0.08em;
      text-transform:uppercase; color:#6b7280; margin-bottom:8px;
      display:flex; align-items:center; gap:6px;
    }
    .ai-section-text { font-size:13.5px; line-height:1.65; color:#1f2937; }
    .ai-list { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px; }
    .ai-list li {
      padding:8px 12px; background:#f9fafb; border:1px solid #f3f4f6;
      border-radius:8px; font-size:13px; line-height:1.55; color:#374151;
    }
    .ai-signal {
      display:flex; align-items:flex-start; gap:10px;
      padding:10px 12px; background:#f9fafb; border:1px solid #f3f4f6;
      border-radius:8px; margin-bottom:6px;
    }
    .ai-signal-sev {
      flex-shrink:0; padding:2px 8px; border-radius:9999px;
      font-size:10px; font-weight:700; letter-spacing:0.04em;
    }
    .ai-signal-sev.low      { background:#dcfce7; color:#166534; }
    .ai-signal-sev.medium   { background:#fef9c3; color:#854d0e; }
    .ai-signal-sev.high     { background:#ffedd5; color:#9a3412; }
    .ai-signal-sev.critical { background:#fee2e2; color:#991b1b; }
    .ai-signal-body { flex:1; }
    .ai-signal-label { font-size:12px; font-weight:600; color:#111827; }
    .ai-signal-detail { font-size:12.5px; color:#4b5563; margin-top:2px; }

    .ai-action {
      display:flex; align-items:flex-start; gap:10px;
      padding:10px 12px; background:#eff6ff; border:1px solid #dbeafe;
      border-radius:8px; margin-bottom:6px;
    }
    .ai-action-prio {
      flex-shrink:0; padding:2px 8px; border-radius:6px;
      font-size:10px; font-weight:700; background:#1e40af; color:#ffffff;
    }
    .ai-action-prio.P1 { background:#991b1b; }
    .ai-action-prio.P2 { background:#1e40af; }
    .ai-action-prio.P3 { background:#4b5563; }
    .ai-action-body { flex:1; font-size:13px; }
    .ai-action-owner { font-size:11px; color:#6b7280; margin-top:3px; }

    .ai-note {
      padding:14px 16px; background:#fffbeb; border:1px solid #fde68a;
      border-radius:10px; font-size:13.5px; line-height:1.6; color:#78350f;
    }
    .ai-meta {
      display:flex; align-items:center; gap:10px; flex-wrap:wrap;
      padding:10px 22px; border-top:1px solid #f3f4f6;
      background:#f9fafb; font-size:11px; color:#6b7280;
    }
    .ai-meta .badge { font-size:10px; }
    .ai-error { padding:14px 16px; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; color:#991b1b; font-size:13px; }
    .ai-loading { display:flex; align-items:center; gap:10px; padding:24px; color:#6b7280; font-size:13px; justify-content:center; }
    .ai-copy-btn {
      background:#ffffff; border:1px solid #d1d5db; border-radius:6px;
      font-size:11px; color:#374151; padding:4px 10px; cursor:pointer;
      transition:all 0.15s;
    }
    .ai-copy-btn:hover { background:#f3f4f6; border-color:#9ca3af; }

    /* ── Scrollbar ── */
    * { scrollbar-width:thin; scrollbar-color:#d1d5db transparent; }
    *::-webkit-scrollbar { width:6px; height:6px; }
    *::-webkit-scrollbar-track { background:transparent; }
    *::-webkit-scrollbar-thumb { background:#d1d5db; border-radius:3px; }
    *::-webkit-scrollbar-thumb:hover { background:#9ca3af; }

    /* ── Filter button active state ── */
    .seg-filter-btn {
      padding:6px 14px; border-radius:6px; border:1px solid #e5e7eb;
      background:#ffffff; color:#6b7280; font-size:12px; font-weight:500;
      cursor:pointer; transition:all 0.15s;
    }
    .seg-filter-btn:hover { background:#f9fafb; color:#1f2937; }
    .seg-filter-btn[aria-selected="true"] {
      background:#1f2937 !important;
      color:#ffffff !important;
      border-color:#1f2937 !important;
    }

    /* ── Refresh button ── */
    .btn-refresh {
      display:flex; align-items:center; gap:6px;
      padding:8px 14px; border-radius:8px;
      background:#ffffff; border:1px solid #e5e7eb;
      cursor:pointer; font-size:12px; font-weight:500; color:#374151;
      white-space:nowrap; transition:all 0.15s;
    }
    .btn-refresh:hover { background:#f9fafb; border-color:#d1d5db; }
  </style>
</head>
<body>

<!-- ════ STICKY HEADER ════════════════════════════════════════════ -->
<header style="position:sticky;top:0;z-index:50;background:rgba(255,255,255,0.96);backdrop-filter:blur(14px);border-bottom:1px solid #e5e7eb;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
  <div class="page" style="padding-top:14px;padding-bottom:0;display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:12px;padding-bottom:10px;">
      <div style="width:36px;height:36px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;" aria-hidden="true">&#128681;</div>
      <div>
        <div style="font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;">
          Customer Churn Tracker — รายงานลูกค้าหลุดรอบ
          <?php if ($softLaunch): ?>
          <span class="badge badge-amber" style="font-size:10px;">soft-launch</span>
          <?php else: ?>
          <span class="badge badge-blue" style="font-size:10px;">Read-only</span>
          <?php endif; ?>
        </div>
        <div id="last-updated" style="font-size:11px;color:#6b7280;margin-top:2px;">
          <?php if ($lastComputedAt): ?>
          คำนวณล่าสุด: <?= htmlspecialchars($lastComputedAt, ENT_QUOTES, 'UTF-8') ?>
          <?php else: ?>
          ยังไม่มีข้อมูล RFM — รอ cron รอบแรก (รัน 02:00 น. ทุกคืน)
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;padding-bottom:10px;">
      <button id="refresh-btn" class="btn-refresh" aria-label="รีเฟรชข้อมูล">
        <svg id="refresh-icon" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
        </svg>
        รีเฟรช
      </button>
    </div>
  </div>
  <!-- ── Tab nav: ลิงก์ระหว่างหน้ารายงานในกลุ่มเดียวกัน ── -->
  <div class="page" style="padding-top:0;padding-bottom:0;">
    <nav class="tab-nav" role="tablist" aria-label="กลุ่มรายงานลูกค้า">
      <a class="tab-link" href="inbox-intelligence.html" role="tab" aria-selected="false">
        📊 รายงานกล่องข้อความ
      </a>
      <a class="tab-link active" href="customer-churn.php" role="tab" aria-selected="true" aria-current="page">
        🚩 ลูกค้าหลุดรอบ (Churn)
      </a>
      <?php if (function_exists('isSuperAdmin') && isSuperAdmin()): ?>
      <a class="tab-link" href="customer-churn-settings.php" role="tab" aria-selected="false">
        ⚙️ ตั้งค่าระบบ
      </a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="page" style="padding-top:24px;padding-bottom:48px;">

  <!-- ── Soft-launch banner ───────────────────────────────────────── -->
  <?php if ($softLaunch): ?>
  <div class="alert alert-amber" style="margin-top:20px;margin-bottom:20px;" role="alert" aria-live="polite">
    <div class="alert-title">⚠️ โหมดเริ่มต้นใช้งาน (Soft-Launch)</div>
    <div style="font-size:12.5px;line-height:1.6;margin-top:4px;">
      ระบบยัง<b>ไม่ส่งข้อความหาลูกค้าอัตโนมัติ</b> — ผู้ดูแลตรวจรายชื่อในตารางด้านล่างได้ แต่ยังไม่มีการส่ง LINE / อีเมล / โทรศัพท์ออกโดยระบบ
      เปิดใช้จริงได้ที่หน้า <b>ตั้งค่าระบบ</b> (ตั้ง <code>system_enabled = 1</code> และ <code>soft_launch = 0</code>)
    </div>
  </div>
  <?php endif; ?>

  <!-- ════ KPI STRIP ════════════════════════════════════════════════ -->
  <div class="sec-head">ภาพรวมตาม Segment — ลูกค้าทั้งหมดที่คำนวณแล้ว</div>

  <div class="kpi-grid" id="kpi-grid" role="list" aria-label="สรุปจำนวนลูกค้าตาม segment">
    <?php foreach ($kpiDefs as $seg => $def):
      $color = churnKpiColor($seg);
      $count = $kpiCounts[$seg];
      $segId = strtolower(str_replace([' ', '-'], '_', $seg));
    ?>
    <div class="kpi-card" role="listitem" data-segment="<?= htmlspecialchars($seg, ENT_QUOTES, 'UTF-8') ?>">
      <div class="kpi-icon" aria-hidden="true"><?= $def['icon'] ?></div>
      <div class="kpi-label"><?= htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="kpi-value"
           style="color:<?= $color ?>;"
           id="kpi-<?= htmlspecialchars($segId, ENT_QUOTES, 'UTF-8') ?>"
           aria-label="<?= htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') ?> <?= $count ?> ราย"><?= $count ?></div>
      <div class="kpi-sub"><?= htmlspecialchars($def['sub'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ════ P0 CRITICAL OVERLAP — Inbox complaint + Churn segment ═══════ -->
  <?php
    // Build "P0 overlap" list: watchlist customers who ALSO have a recent
    // inbox complaint (red/orange severity in last 30 days).
    $p0Overlap = [];
    foreach ($watchlistRows as $wRow) {
        $pid = (int) $wRow['odoo_partner_id'];
        $seg = (string) ($wRow['current_segment'] ?? '');
        if (!isset($inboxFlags[$pid])) continue;
        $flag = $inboxFlags[$pid];
        if (!in_array($flag['severity'], ['red', 'orange'], true)) continue;
        if (!in_array($seg, ['Churned', 'Lost', 'At-Risk'], true)) continue;
        $p0Overlap[] = ['row' => $wRow, 'flag' => $flag];
    }
  ?>
  <?php if (!empty($p0Overlap)): ?>
  <div class="sec-head" style="margin-top:36px;color:#991b1b;">🚨 P0 — Critical Overlap (บ่นในกล่องข้อความ + กำลังจะหาย)</div>
  <div class="overlap-alert">
    <div class="overlap-alert-title">
      🚨 ลูกค้า <?= count($p0Overlap) ?> รายที่ทั้งบ่นใน inbox และอยู่ใน Churned / Lost / At-Risk
    </div>
    <div style="font-size:12px;color:#7c2d12;margin-bottom:10px;">
      ลูกค้ากลุ่มนี้คือ <b>เป้าหมายเร่งด่วนที่สุด</b> — ตอบ inbox ทันที + ใช้ปุ่ม AI วิเคราะห์ เพื่อรับ context สำหรับ Sales
    </div>
    <div class="overlap-alert-list">
      <?php foreach ($p0Overlap as $p0):
        $r = $p0['row'];
        $f = $p0['flag'];
        $segClass = strtolower(str_replace('-','',$r['current_segment']));
      ?>
      <div class="overlap-alert-item">
        <span class="seg-tag <?= htmlspecialchars($segClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($r['current_segment'], ENT_QUOTES, 'UTF-8') ?></span>
        <span style="font-weight:600;"><?= htmlspecialchars((string) ($r['store_name'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (!empty($r['customer_ref'])): ?>
        <span style="color:#6b7280;font-size:11px;">(<?= htmlspecialchars($r['customer_ref'], ENT_QUOTES, 'UTF-8') ?>)</span>
        <?php endif; ?>
        <span class="inbox-flag inbox-flag-<?= htmlspecialchars($f['severity'], ENT_QUOTES, 'UTF-8') ?>">
          <?= $f['severity'] === 'red' ? '🔴 ร้องเรียน' : '🟠 ไม่พอใจ' ?> (<?= (int) $f['count'] ?>)
        </span>
        <span style="color:#374151;font-size:12px;flex:1;min-width:200px;">"<?= htmlspecialchars(mb_substr((string) $f['latest_text'], 0, 120), ENT_QUOTES, 'UTF-8') ?>"</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ════ WATCHLIST TABLE ════════════════════════════════════════ -->
  <div class="sec-head" style="margin-top:36px;">รายชื่อลูกค้าที่ต้องติดตาม — เรียงตามความเร่งด่วน</div>

  <div class="card p-5">
    <!-- Segment filter tabs -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;align-items:center;" role="group" aria-label="กรองตาม segment">
      <?php
      $filterOptions = [
          'all'      => 'ทั้งหมด',
          'Churned'  => '🚩 Churned (VIP)',
          'Lost'     => '⛔ Lost',
          'At-Risk'  => '⚠️ At-Risk',
          'Watchlist'=> '👀 Watchlist',
      ];
      foreach ($filterOptions as $fKey => $fLabel):
      ?>
      <button class="seg-filter-btn"
        data-filter="<?= htmlspecialchars($fKey, ENT_QUOTES, 'UTF-8') ?>"
        aria-selected="<?= $fKey === 'all' ? 'true' : 'false' ?>">
        <?= htmlspecialchars($fLabel, ENT_QUOTES, 'UTF-8') ?>
      </button>
      <?php endforeach; ?>
      <div style="margin-left:auto;font-size:12px;color:#6b7280;">
        แสดง <span id="watchlist-count" style="font-weight:600;color:#1f2937;"><?= count($watchlistRows) ?></span> รายการ
      </div>
    </div>

    <?php if (empty($watchlistRows)): ?>
    <div style="padding:48px 20px;text-align:center;color:#9ca3af;" aria-live="polite">
      <div style="font-size:40px;margin-bottom:14px;" aria-hidden="true">&#127881;</div>
      <div style="font-size:15px;color:#374151;font-weight:500;">ยังไม่มีลูกค้าในรายการต้องติดตาม</div>
      <div style="font-size:12px;margin-top:8px;color:#6b7280;">
        อาจเป็นเพราะ cron ยังไม่ได้รันครั้งแรก หรือลูกค้าทุกรายยังซื้อตามรอบปกติ
      </div>
    </div>
    <?php else: ?>

    <div class="table-scroll">
      <table class="dt" id="watchlist-table" aria-label="รายการลูกค้าที่ต้องติดตาม">
        <thead>
          <tr>
            <th>ชื่อร้าน / Partner ID</th>
            <th>ประเภท</th>
            <th title="รอบสั่งปกติ (วัน) — คำนวณจาก median ของช่วงระหว่างออเดอร์">รอบสั่งปกติ</th>
            <th title="จำนวนวันที่หายไปจากออเดอร์ล่าสุด">หายไป</th>
            <th title="หายไป ÷ รอบปกติ — ยิ่งสูงยิ่งหนัก (1.5×=At-Risk, 2×=Lost, 3×=Churned)">หนักแค่ไหน</th>
            <th title="ยอดซื้อสะสมตลอดอายุลูกค้า (THB)">ยอดซื้อสะสม</th>
            <th>ออเดอร์ล่าสุด</th>
            <th>สถานะ</th>
            <th title="สัญญาณจากกล่องข้อความ LINE 30 วันล่าสุด — บ่น/ร้องเรียน/ตามผล">📬 Inbox</th>
            <th>การดำเนินการ</th>
          </tr>
        </thead>
        <tbody id="watchlist-tbody">
          <?php foreach ($watchlistRows as $wRow):
            $wSeg       = (string) ($wRow['current_segment'] ?? '');
            $wPartner   = (int) $wRow['odoo_partner_id'];
            $wStore     = (string) ($wRow['store_name'] ?? 'ไม่ระบุ');
            $wType      = $typeLabels[(string) ($wRow['customer_type'] ?? '')] ?? ($wRow['customer_type'] ?? '—');
            $wConfidence = (string) ($wRow['cycle_confidence'] ?? 'fallback');
            $wSeasonal  = (bool) ($wRow['is_seasonal'] ?? false);
            $wIsHV      = (bool) ($wRow['is_high_value'] ?? false);
            $wDaysSince = ($wRow['days_since'] !== null) ? (int) $wRow['days_since'] : null;
            $wRatio     = ($wRow['recency_ratio'] !== null) ? (float) $wRow['recency_ratio'] : null;
            $wLineUser  = $wRow['line_user_id'] ?? null;

            $rowClass = match ($wSeg) {
                'Churned' => 'row-critical',
                'Lost'    => 'row-warn',
                default   => '',
            };
            $dayColor   = ($wDaysSince !== null && $wDaysSince > 60) ? '#f87171' : '#cbd5e1';
            $ratioColor = '#cbd5e1';
            if ($wRatio !== null) {
                if ($wRatio >= 3.0)      { $ratioColor = '#ef4444'; }
                elseif ($wRatio >= 2.0)  { $ratioColor = '#f87171'; }
                elseif ($wRatio >= 1.5)  { $ratioColor = '#fb923c'; }
                else                     { $ratioColor = '#fbbf24'; }
            }
          ?>
          <?php
            // Build odoo-customer-detail URL — page expects ?partner_id=&ref=&name=
            $wRef        = (string) ($wRow['customer_ref'] ?? '');
            $detailQuery = http_build_query([
                'partner_id' => $wPartner,
                'ref'        => $wRef,
                'name'       => $wStore,
            ]);
          ?>
          <tr class="<?= $rowClass ?>"
              data-segment="<?= htmlspecialchars($wSeg, ENT_QUOTES, 'UTF-8') ?>">
            <td>
              <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <?php if ($wSeasonal): ?>
                <span title="ลูกค้าซื้อตามฤดูกาล — ระบบจะไม่ส่ง notification อัตโนมัติ" aria-label="seasonal" style="color:#d97706;font-size:13px;">&#9924;</span>
                <?php endif; ?>
                <span style="font-weight:600;color:#111827;"><?= htmlspecialchars($wStore, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($wIsHV): ?>
                <span class="badge badge-purple" title="ลูกค้ายอดสูง — Top 20% ของ LTV">VIP</span>
                <?php endif; ?>
                <?php if ($wConfidence === 'fallback'): ?>
                <span class="badge badge-gray" title="ข้อมูลออเดอร์ยังไม่พอ — ระบบจะไม่ส่ง notification อัตโนมัติ" style="font-size:10px;">ข้อมูลน้อย</span>
                <?php endif; ?>
              </div>
              <div style="font-size:11px;color:#9ca3af;margin-top:2px;">
                <?php if ($wRef): ?>
                <span title="รหัสลูกค้า"><?= htmlspecialchars($wRef, ENT_QUOTES, 'UTF-8') ?></span> ·
                <?php endif; ?>
                Partner #<?= $wPartner ?>
              </div>
            </td>
            <td style="color:#4b5563;"><?= htmlspecialchars($wType, ENT_QUOTES, 'UTF-8') ?></td>
            <td><span class="mono" style="color:#374151;"><?= churnFormatCycle($wRow['avg_order_cycle_days']) ?></span></td>
            <td><span class="mono" style="color:<?= $dayColor ?>;font-weight:500;">
              <?= ($wDaysSince !== null) ? $wDaysSince . ' วัน' : '—' ?>
            </span></td>
            <td><span class="mono" style="color:<?= $ratioColor ?>;font-weight:600;">
              <?= churnFormatRatio($wRow['recency_ratio']) ?>
            </span></td>
            <td><span class="mono" style="color:#1f2937;font-weight:500;"><?= churnFormatThb($wRow['lifetime_value']) ?></span></td>
            <td style="color:#6b7280;"><?= $wRow['last_order_date'] ? htmlspecialchars((string) $wRow['last_order_date'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
            <td>
              <span class="badge <?= churnSegmentBadgeClass($wSeg) ?>">
                <?= htmlspecialchars($wSeg, ENT_QUOTES, 'UTF-8') ?>
              </span>
            </td>
            <td>
              <?php
                $wFlag = $inboxFlags[$wPartner] ?? null;
                if ($wFlag !== null):
                    $sevLabel = match ($wFlag['severity']) {
                        'red'           => '🔴 ร้องเรียน',
                        'orange'        => '🟠 ไม่พอใจ',
                        'yellow_urgent' => '🟡 ตามผลด่วน',
                        'yellow'        => '🟡 ตามผล',
                        default         => '—',
                    };
                    $sevCss = in_array($wFlag['severity'], ['yellow', 'yellow_urgent'], true)
                        ? 'yellow'
                        : $wFlag['severity'];
                    $tipText = '"' . mb_substr((string) $wFlag['latest_text'], 0, 160) . '" · '
                             . (string) $wFlag['latest_at'];
              ?>
              <span class="inbox-flag inbox-flag-<?= htmlspecialchars($sevCss, ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= htmlspecialchars($tipText, ENT_QUOTES, 'UTF-8') ?>">
                <?= $sevLabel ?> (<?= (int) $wFlag['count'] ?>)
              </span>
              <?php else: ?>
              <span class="inbox-flag-none" aria-label="ไม่มีสัญญาณ">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a class="act-link"
                   href="odoo-customer-detail.php?<?= $detailQuery ?>"
                   target="_blank" rel="noopener"
                   aria-label="เปิด Odoo รายละเอียดของ <?= htmlspecialchars($wStore, ENT_QUOTES, 'UTF-8') ?>"
                   title="ดูออเดอร์ / ใบแจ้งหนี้ / BDO ล่าสุด">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M14 3h7v7M10 14L21 3M21 14v7H3V3h7"/>
                  </svg>
                  ดู Odoo
                </a>
                <button type="button"
                        class="act-link act-link-ai"
                        data-partner-id="<?= $wPartner ?>"
                        data-store-name="<?= htmlspecialchars($wStore, ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="ขอ AI วิเคราะห์ลูกค้า <?= htmlspecialchars($wStore, ENT_QUOTES, 'UTF-8') ?>"
                        title="ขอ AI วิเคราะห์ลูกค้ารายนี้ — บันทึกภายในให้ทีม Sales (ไม่ส่งหาลูกค้า)">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                  </svg>
                  AI วิเคราะห์
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php endif; ?>
  </div>

  <!-- ════ COHORT RETENTION CHART ════════════════════════════════ -->
  <div class="sec-head" style="margin-top:36px;">การกระจายตัวของลูกค้าตาม Segment</div>

  <div class="card p-5">
    <div class="card-title">&#128202;&nbsp;จำนวนลูกค้าในแต่ละกลุ่ม</div>
    <div id="cohort-loading" style="display:none;text-align:center;padding:20px;color:#6b7280;font-size:12px;" aria-live="polite">กำลังโหลด...</div>
    <div class="chart-wrap">
      <canvas id="cohort-chart" aria-label="แผนภูมิการกระจายตัว segment" role="img"></canvas>
    </div>
  </div>

  <!-- ════ SYSTEM HEALTH MINI-STRIP ════════════════════════════════ -->
  <div class="sec-head" style="margin-top:36px;">สถานะการทำงานของระบบ</div>

  <div class="card p-5" id="health-card">
    <div class="card-title">&#9989;&nbsp;System Health — ภาพรวมการทำงาน</div>
    <div class="health-strip" id="health-strip">
      <div class="health-box">
        <div class="health-num c-blue" id="health-eligible"><?= $totalEligible ?></div>
        <div class="health-label">จำนวนลูกค้าที่อยู่ใน RFM (≥3 ออเดอร์)</div>
      </div>
      <div class="health-box">
        <div class="health-num" id="health-computed"
             style="color:#374151;font-size:<?= $lastComputedAt ? '13px' : '22px' ?>;line-height:1.4;">
          <?= $lastComputedAt ? htmlspecialchars($lastComputedAt, ENT_QUOTES, 'UTF-8') : '—' ?>
        </div>
        <div class="health-label">เวลาคำนวณ RFM ครั้งล่าสุด</div>
      </div>
      <div class="health-box">
        <div class="health-num"
             style="color:<?= ($geminiCallsToday >= $geminiDailyCap) ? '#dc2626' : '#16a34a' ?>;"
             id="health-gemini"><?= $geminiCallsToday ?> / <?= $geminiDailyCap ?></div>
        <div class="health-label">การเรียก Gemini AI วันนี้ / โควตารายวัน</div>
      </div>
      <div class="health-box">
        <div class="health-num" style="color:<?= $softLaunch ? '#d97706' : '#16a34a' ?>;">
          <?= $softLaunch ? 'Soft-Launch' : 'เปิดใช้จริง' ?>
        </div>
        <div class="health-label">โหมดระบบปัจจุบัน</div>
      </div>
    </div>
  </div>

</main>

<!-- ════ AI BRIEF MODAL (admin-only, internal use, no customer push) ════ -->
<div id="ai-brief-modal" class="ai-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="ai-modal-title" aria-hidden="true">
  <div class="ai-modal" role="document">
    <header class="ai-modal-head">
      <div>
        <div id="ai-modal-title" class="ai-modal-title">
          <span aria-hidden="true">🤖</span>
          <span>บันทึกวิเคราะห์ลูกค้า (AI)</span>
        </div>
        <div id="ai-modal-sub" class="ai-modal-sub">—</div>
      </div>
      <button type="button" class="ai-modal-close" data-close-ai-modal aria-label="ปิดหน้าต่าง">&times;</button>
    </header>

    <div class="ai-modal-body" id="ai-modal-body">
      <div class="ai-loading" id="ai-modal-loading">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin-icon" aria-hidden="true">
          <path d="M21 12a9 9 0 1 1-6.22-8.56"/>
        </svg>
        <span>กำลังให้ AI วิเคราะห์ข้อมูลลูกค้า… (โดยปกติใช้เวลา 3-8 วินาที)</span>
      </div>
    </div>

    <div class="ai-meta" id="ai-modal-meta" style="display:none;">
      <span id="ai-meta-cache" class="badge badge-gray">—</span>
      <span id="ai-meta-tokens" class="badge badge-gray">—</span>
      <span style="margin-left:auto;color:#9ca3af;font-style:italic;">บันทึกภายในเท่านั้น · ไม่ส่งหาลูกค้า</span>
    </div>
  </div>
</div>

<script>
  /* Bootstrap: pass server-rendered counts to JS module */
  window.__churnInitial = {
    kpi: <?= json_encode($kpiCounts, JSON_UNESCAPED_UNICODE) ?>,
    totalEligible: <?= $totalEligible ?>,
    lastComputedAt: <?= json_encode($lastComputedAt, JSON_UNESCAPED_UNICODE) ?>,
    softLaunch: <?= $softLaunch ? 'true' : 'false' ?>,
    geminiCallsToday: <?= $geminiCallsToday ?>,
    geminiDailyCap: <?= $geminiDailyCap ?>
  };
</script>
<script src="assets/js/customer-churn.js?v=<?= filemtime(__DIR__ . '/assets/js/customer-churn.js') ?>"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
