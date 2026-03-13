<?php
/**
 * BDO Confirm — Slip Matching Workspace
 * Logic เดิมจาก odoo-dashboard.js + odoo-webhooks-dashboard.php
 * ปรับให้ standalone, fast load, ไม่ล่ม
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/config.php';

// Auth
if (empty($_SESSION['admin_user']['id'])) {
    header('Location: auth/login.php');
    exit;
}

$odooBase = defined('ODOO_API_BASE_URL') ? rtrim(ODOO_API_BASE_URL, '/') : 'https://erp.cnyrxapp.com';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BDO Confirm — Slip Matching</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
:root{
  --primary:#6366f1;--primary-light:#e0e7ff;
  --success:#10b981;--success-light:#d1fae5;
  --warning:#f59e0b;--warning-light:#fef3c7;
  --danger:#ef4444;--danger-light:#fee2e2;
  --gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;
  --gray-300:#cbd5e1;--gray-400:#94a3b8;--gray-500:#64748b;
  --gray-600:#475569;--gray-700:#334155;--gray-800:#1e293b;
  --radius:12px;--shadow:0 4px 16px rgba(0,0,0,.06);
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'IBM Plex Sans Thai',system-ui,sans-serif;background:var(--gray-50);color:var(--gray-800);font-size:14px;}

/* Header */
.top-bar{background:#fff;border-bottom:1px solid var(--gray-200);padding:10px 20px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50;}
.top-bar h1{font-size:1.05rem;font-weight:700;color:var(--gray-900);}

/* Layout */
.page-wrap{padding:16px;max-width:1440px;margin:0 auto;}
.three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
@media(max-width:1100px){.three-col{grid-template-columns:1fr 1fr;}}
@media(max-width:700px){.three-col{grid-template-columns:1fr;}}

/* Cards */
.card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.card-hd{padding:10px 14px;border-bottom:1px solid var(--gray-100);display:flex;align-items:center;justify-content:space-between;gap:8px;}
.card-hd-title{font-weight:700;font-size:0.88rem;display:flex;align-items:center;gap:6px;}
.card-body{padding:10px 14px;}
.scroll-area{overflow-y:auto;max-height:420px;}

/* KPI row */
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.kpi-card{background:#fff;border-radius:10px;padding:10px 14px;box-shadow:var(--shadow);text-align:center;}
.kpi-num{font-size:1.6rem;font-weight:800;line-height:1;}
.kpi-lbl{font-size:0.72rem;color:var(--gray-500);margin-top:3px;}

/* Suggestion item */
.sugg-item{display:flex;align-items:center;gap:10px;background:#fff;border:1.5px solid var(--gray-200);border-radius:10px;padding:8px 10px;margin-bottom:6px;transition:box-shadow .15s;}
.sugg-item:hover{box-shadow:0 2px 10px rgba(99,102,241,.12);}
.sugg-item.conf-exact_bdo_id{border-color:#86efac33;background:#f0fdf4;}
.sugg-item.conf-exact_amount{border-color:#93c5fd33;background:#eff6ff;}
.sugg-item.conf-bdo_id_amount_diff{border-color:#fcd34d33;background:#fffbeb;}
.sugg-item.conf-fuzzy{border-color:#d8b4fe33;background:#faf5ff;}

/* Slip / BDO card */
.match-card{display:flex;align-items:center;gap:8px;padding:7px 8px;margin-bottom:5px;border:2px solid var(--gray-200);border-radius:10px;cursor:pointer;background:#fff;transition:all .15s;}
.match-card:hover{border-color:var(--primary);}
.match-card.selected{border-color:var(--primary);background:var(--primary-light);}
.match-card.has-sugg{border-color:#a5b4fc;background:#f5f3ff;}
.slip-thumb{width:38px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;border:1px solid var(--gray-200);}
.slip-thumb-ph{width:38px;height:48px;background:var(--gray-100);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}

/* Group header */
.group-hd{display:flex;align-items:center;gap:6px;font-size:0.72rem;font-weight:700;background:var(--gray-100);padding:3px 8px;border-radius:6px;margin-bottom:5px;border-left:3px solid var(--primary);}
.group-hd.bdo-group{border-left-color:var(--warning);}

/* Summary bar */
.sum-bar{background:#fff;border:1px solid var(--gray-200);border-radius:10px;padding:10px 14px;margin-top:10px;display:none;}
.sum-bar.show{display:block;}
.sum-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
.sum-item{text-align:center;}
.sum-lbl{font-size:0.7rem;color:var(--gray-500);}
.sum-val{font-weight:700;font-size:1rem;}

/* Badges */
.badge-conf{display:inline-flex;align-items:center;padding:1px 6px;border-radius:50px;font-size:0.65rem;font-weight:600;}
.badge-exact_bdo_id{background:#dcfce7;color:#16a34a;}
.badge-exact_amount{background:#dbeafe;color:#1d4ed8;}
.badge-bdo_id_amount_diff{background:#fef9c3;color:#92400e;}
.badge-fuzzy{background:#f3e8ff;color:#6d28d9;}
.badge-pending{background:#fef3c7;color:#d97706;}
.badge-matched{background:#dcfce7;color:#16a34a;}
.badge-new{background:var(--gray-100);color:var(--gray-600);}
.badge-private{background:#fce7f3;color:#be185d;}
.badge-company{background:#e0f2fe;color:#0369a1;}

/* Buttons */
.btn-confirm{background:linear-gradient(135deg,#16a34a,#059669);color:#fff;border:none;border-radius:8px;padding:5px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;white-space:nowrap;}
.btn-dismiss{background:var(--gray-100);color:var(--gray-600);border:none;border-radius:8px;padding:5px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;white-space:nowrap;}
.btn-main{background:var(--primary);color:#fff;border:none;border-radius:8px;padding:7px 16px;font-size:0.85rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;}
.btn-main:disabled{background:var(--gray-400);cursor:not-allowed;}
.btn-outline{background:#fff;color:var(--gray-700);border:1px solid var(--gray-300);border-radius:8px;padding:6px 14px;font-size:0.82rem;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;}
.btn-outline:hover{background:var(--gray-50);}
.btn-danger{background:var(--danger);color:#fff;border:none;border-radius:8px;padding:5px 12px;font-size:0.78rem;cursor:pointer;font-family:inherit;}

/* Loading */
.loading{text-align:center;padding:2rem;color:var(--gray-400);}
.spin{animation:spin .8s linear infinite;display:inline-block;}
@keyframes spin{to{transform:rotate(360deg);}}

/* Toast */
.toast-wrap{position:fixed;top:14px;right:14px;z-index:9999;display:flex;flex-direction:column;gap:6px;}
.toast{padding:9px 16px;border-radius:8px;color:#fff;font-size:0.83rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);animation:fadeIn .2s ease;}
.toast-ok{background:#16a34a;}.toast-err{background:#dc2626;}.toast-warn{background:#d97706;}
@keyframes fadeIn{from{opacity:0;transform:translateX(30px);}to{opacity:1;transform:none;}}

/* Modal */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center;}
.modal-bg.open{display:flex;}
.modal-box{background:#fff;border-radius:14px;max-width:480px;width:92%;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-hd{padding:14px 18px;border-bottom:1px solid var(--gray-200);display:flex;justify-content:space-between;align-items:center;font-weight:700;}
.modal-body{padding:14px 18px;}
.modal-ft{padding:10px 18px;border-top:1px solid var(--gray-200);display:flex;gap:8px;justify-content:flex-end;}

/* Note textarea */
.note-ta{width:100%;padding:7px 10px;border:1px solid var(--gray-200);border-radius:8px;font-size:0.83rem;resize:none;font-family:inherit;}
.note-ta:focus{outline:none;border-color:var(--primary);}

/* Matched today table */
.matched-table{width:100%;border-collapse:collapse;font-size:0.82rem;}
.matched-table th{background:var(--gray-50);padding:5px 8px;border-bottom:2px solid var(--gray-200);text-align:left;font-weight:600;}
.matched-table td{padding:4px 8px;border-bottom:1px solid var(--gray-100);}
.matched-table tr:hover td{background:#f0fdf4;}

/* Filter tabs */
.filter-tabs{display:flex;gap:4px;margin-bottom:10px;}
.ftab{padding:5px 12px;border-radius:20px;font-size:0.78rem;font-weight:600;cursor:pointer;border:1.5px solid var(--gray-200);background:#fff;color:var(--gray-600);transition:all .15s;}
.ftab.active{background:var(--primary);color:#fff;border-color:var(--primary);}
</style>
</head>
<body>

<!-- Top bar -->
<div class="top-bar">
  <i class="bi bi-receipt-cutoff" style="font-size:1.3rem;color:var(--primary);"></i>
  <h1>BDO Confirm — Slip Matching</h1>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <span id="statusDot" style="font-size:0.75rem;color:var(--gray-400);">กำลังโหลด...</span>
    <button class="btn-outline" onclick="loadAll()"><i class="bi bi-arrow-clockwise"></i> รีเฟรช</button>
  </div>
</div>

<div class="page-wrap">

  <!-- KPI row -->
  <div class="kpi-row">
    <div class="kpi-card">
      <div class="kpi-num" id="kpiPending" style="color:var(--warning);">-</div>
      <div class="kpi-lbl">สลิป / BDO รอจับคู่</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-num" id="kpiSugg" style="color:var(--primary);">-</div>
      <div class="kpi-lbl">คู่ที่แนะนำ</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-num" id="kpiSuccess" style="color:var(--success);">-</div>
      <div class="kpi-lbl">จับคู่สำเร็จวันนี้</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-num" id="kpiProblem" style="color:var(--danger);">-</div>
      <div class="kpi-lbl">มีปัญหา</div>
    </div>
  </div>

  <!-- Filter + search bar -->
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
    <div class="filter-tabs">
      <div class="ftab active" id="ftab-pending" onclick="setFilter('pending')">รอจับคู่</div>
      <div class="ftab" id="ftab-matched" onclick="setFilter('matched')">จับคู่แล้ว</div>
      <div class="ftab" id="ftab-all" onclick="setFilter('all')">ทั้งหมด</div>
    </div>
    <input id="searchInput" type="text" placeholder="ค้นหาชื่อลูกค้า / BDO / ยอด..." style="padding:6px 12px;border:1px solid var(--gray-200);border-radius:8px;font-size:0.83rem;width:240px;font-family:inherit;" oninput="debounceSearch()">
    <button class="btn-main" id="batchConfirmBtn" onclick="batchConfirmMatches()" disabled>
      <i class="bi bi-check2-all"></i> ยืนยันทั้งหมด <span id="batchCount"></span>
    </button>
  </div>

  <!-- Suggestions (full width) -->
  <div class="card" style="margin-bottom:14px;" id="suggCard">
    <div class="card-hd">
      <span class="card-hd-title"><i class="bi bi-lightning-charge-fill" style="color:var(--warning);"></i> คู่ที่ระบบแนะนำ</span>
      <span id="suggCount" style="font-size:0.75rem;color:var(--gray-400);">ยังไม่มีคำแนะนำ</span>
    </div>
    <div class="card-body scroll-area" id="suggList" style="max-height:280px;">
      <div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>
    </div>
  </div>

  <!-- 3-col: Slips | BDOs | Matched today -->
  <div class="three-col">

    <!-- Slips -->
    <div class="card">
      <div class="card-hd">
        <span class="card-hd-title"><i class="bi bi-image"></i> สลิป</span>
        <span id="slipCount" style="font-size:0.75rem;color:var(--gray-400);"></span>
      </div>
      <div class="card-body scroll-area" id="slipList">
        <div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>
      </div>
    </div>

    <!-- BDOs -->
    <div class="card">
      <div class="card-hd">
        <span class="card-hd-title"><i class="bi bi-file-earmark-text"></i> BDO</span>
        <span id="bdoCount" style="font-size:0.75rem;color:var(--gray-400);"></span>
      </div>
      <div class="card-body scroll-area" id="bdoList">
        <div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>
      </div>
    </div>

    <!-- Matched today -->
    <div class="card">
      <div class="card-hd">
        <span class="card-hd-title"><i class="bi bi-check2-circle" style="color:var(--success);"></i> จับคู่สำเร็จวันนี้</span>
        <span id="matchedTodayCount" style="font-size:0.75rem;color:var(--gray-400);"></span>
      </div>
      <div class="card-body scroll-area" id="matchedTodayList">
        <div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>
      </div>
    </div>

  </div>

  <!-- Manual match summary bar -->
  <div class="sum-bar" id="sumBar">
    <div class="sum-row">
      <div class="sum-item">
        <div class="sum-lbl">สลิปที่เลือก</div>
        <div class="sum-val" id="sumSlipAmt">-</div>
      </div>
      <div style="font-size:1.3rem;color:var(--gray-400);">→</div>
      <div class="sum-item">
        <div class="sum-lbl">BDO ที่เลือก</div>
        <div class="sum-val" id="sumBdoAmt">-</div>
      </div>
      <div class="sum-item" id="sumDiffWrap"></div>
      <div style="flex:1;"></div>
      <textarea class="note-ta" id="matchNote" rows="1" placeholder="หมายเหตุ (ถ้ามี)" style="width:200px;"></textarea>
      <button class="btn-main" id="confirmMatchBtn" onclick="confirmManualMatch()" disabled>
        <i class="bi bi-check2-circle"></i> ยืนยันจับคู่
      </button>
      <button class="btn-outline" onclick="clearSelection()"><i class="bi bi-x"></i> ล้าง</button>
    </div>
  </div>

</div><!-- /page-wrap -->

<!-- Slip image modal -->
<div class="modal-bg" id="slipModal">
  <div class="modal-box">
    <div class="modal-hd">
      <span>รูปสลิป</span>
      <button onclick="closeModal('slipModal')" style="background:none;border:none;cursor:pointer;font-size:1.2rem;"><i class="bi bi-x"></i></button>
    </div>
    <div class="modal-body" style="text-align:center;">
      <img id="slipModalImg" src="" style="max-width:100%;border-radius:8px;" alt="slip">
    </div>
    <div class="modal-ft">
      <button class="btn-danger" id="unmatchBtn" onclick="unmatchCurrentSlip()" style="display:none;"><i class="bi bi-x-circle"></i> ยกเลิกจับคู่</button>
      <button class="btn-outline" onclick="closeModal('slipModal')">ปิด</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
// ═══════════════════════════════════════════════════════════════
// CONFIG
// ═══════════════════════════════════════════════════════════════
const WH_API  = 'api/odoo-webhooks-dashboard.php';
const SLIP_API = 'api/slips-list.php';
const ODOO_BASE = '<?= htmlspecialchars($odooBase) ?>';

// ═══════════════════════════════════════════════════════════════
// STATE  (same as odoo-dashboard.js)
// ═══════════════════════════════════════════════════════════════
let _slips = [], _bdos = [], _suggestions = [];
let _selSlips = new Set(), _selBdos = new Set();
let _filterMode = 'pending';
let _searchTerm = '';
let _searchTimer = null;
let _currentSlipForUnmatch = null;

// ═══════════════════════════════════════════════════════════════
// API HELPERS
// ═══════════════════════════════════════════════════════════════
async function whApi(params) {
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 20000);
    const r = await fetch(WH_API, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(params),
      signal: ctrl.signal
    });
    clearTimeout(t);
    return await r.json();
  } catch(e) {
    return {success:false, error: e.name==='AbortError'?'หมดเวลา':e.message};
  }
}

async function slipApi(params) {
  try {
    const qs = new URLSearchParams(params).toString();
    const r = await fetch(SLIP_API + '?' + qs);
    return await r.json();
  } catch(e) {
    return {success:false, error: e.message};
  }
}

// ═══════════════════════════════════════════════════════════════
// LOAD ALL  (parallel fetch — same as loadMatchingDashboard)
// ═══════════════════════════════════════════════════════════════
async function loadAll() {
  setStatus('กำลังโหลด...');
  setLoading('suggList'); setLoading('slipList'); setLoading('bdoList'); setLoading('matchedTodayList');
  _selSlips.clear(); _selBdos.clear(); updateSumBar();

  const slipStatus = _filterMode === 'matched' ? 'matched' : (_filterMode === 'pending' ? 'pending' : '');
  const search = _searchTerm;

  const [slipRes, bdoRes] = await Promise.all([
    slipApi({limit:200, offset:0, ...(slipStatus ? {status:slipStatus} : {}), ...(search ? {search} : {})}),
    whApi({action:'odoo_bdos', limit:200, offset:0, ...(search ? {search} : {})})
  ]);

  _slips = (slipRes?.success && slipRes.data?.slips) ? slipRes.data.slips : [];
  _bdos  = (bdoRes?.success  && bdoRes.data?.bdos)  ? bdoRes.data.bdos   : [];

  _suggestions = computeSmartMatches(_slips, _bdos);

  const today = new Date().toISOString().slice(0,10);
  const matchedToday = _slips.filter(s => s.status === 'matched' && s.matched_at?.slice(0,10) === today);
  const pendingSlips = _slips.filter(s => s.status === 'pending' || s.status === 'new');
  const pendingBdos  = _bdos.filter(b  => (b.payment_status||'pending') === 'pending');
  const failedSlips  = _slips.filter(s => s.status === 'failed');

  // KPI
  setEl('kpiPending', pendingSlips.length + ' / ' + pendingBdos.length);
  setEl('kpiSugg',    _suggestions.length);
  setEl('kpiSuccess', matchedToday.length);
  setEl('kpiProblem', failedSlips.length);

  // Batch btn
  const batchBtn = document.getElementById('batchConfirmBtn');
  const toConfirm = _suggestions.filter(m => m.confidence !== 'exact_bdo_id');
  batchBtn.disabled = toConfirm.length === 0;
  setEl('batchCount', toConfirm.length ? '('+toConfirm.length+')' : '');

  // Auto-confirm exact_bdo_id
  const autoConf = _suggestions.filter(m => m.confidence === 'exact_bdo_id');
  if (autoConf.length) autoConfirmExact(autoConf);

  // Separate unmatched
  const sugSlipIds = new Set(_suggestions.map(m => m.slip.id || m.slip.slip_id));
  const sugBdoIds  = new Set(_suggestions.map(m => m.bdo.bdo_id || m.bdo.id));
  const unmatchedSlips = pendingSlips.filter(s => !sugSlipIds.has(s.id || s.slip_id));
  const unmatchedBdos  = pendingBdos.filter(b  => !sugBdoIds.has(b.bdo_id || b.id));

  // Render
  renderSuggestions(_filterMode === 'matched' ? [] : _suggestions);
  renderSlipList(_filterMode === 'matched' ? _slips : unmatchedSlips);
  renderBdoList(_filterMode === 'matched' ? _bdos : unmatchedBdos);
  renderMatchedToday(matchedToday);

  setEl('suggCount', _suggestions.length ? _suggestions.length + ' คู่' : 'ยังไม่มีคำแนะนำ');
  setEl('slipCount', (_filterMode === 'matched' ? _slips.length : unmatchedSlips.length) + ' รายการ');
  setEl('bdoCount',  (_filterMode === 'matched' ? _bdos.length  : unmatchedBdos.length)  + ' รายการ');
  setEl('matchedTodayCount', matchedToday.length + ' รายการ');

  setStatus('อัปเดตแล้ว ' + new Date().toLocaleTimeString('th-TH'));
}

// ═══════════════════════════════════════════════════════════════
// SMART MATCH  (identical logic to odoo-dashboard.js)
// ═══════════════════════════════════════════════════════════════
function computeSmartMatches(slips, bdos) {
  const suggestions = [];
  const usedSlips = new Set(), usedBdos = new Set();
  const pendingSlips = slips.filter(s => s.status === 'pending' || s.status === 'new');
  const pendingBdos  = bdos.filter(b  => (b.payment_status||'pending') === 'pending');

  // P1: bdo_id direct match
  pendingSlips.forEach((slip, si) => {
    if (usedSlips.has(si) || !slip.bdo_id) return;
    pendingBdos.forEach((bdo, bi) => {
      if (usedBdos.has(bi)) return;
      const bdoId = bdo.bdo_id || bdo.id;
      if (String(slip.bdo_id) === String(bdoId)) {
        const sa = parseFloat(slip.amount||0), ba = parseFloat(bdo.amount_total||bdo.amount_net_to_pay||0);
        const isExact = Math.abs(sa-ba) <= 1;
        suggestions.push({slip, slipIdx:si, bdo, bdoIdx:bi,
          confidence: isExact ? 'exact_bdo_id' : 'bdo_id_amount_diff',
          diff: sa-ba, label: isExact ? '✅ bdo_id + ยอดตรง' : '⚠️ bdo_id ตรง แต่ยอดต่าง ฿'+(sa-ba).toLocaleString()});
        usedSlips.add(si); usedBdos.add(bi);
      }
    });
  });

  // P2: exact amount ±฿1
  pendingSlips.forEach((slip, si) => {
    if (usedSlips.has(si)) return;
    const sa = parseFloat(slip.amount||0); if (sa <= 0) return;
    pendingBdos.forEach((bdo, bi) => {
      if (usedBdos.has(bi)) return;
      const ba = parseFloat(bdo.amount_total||bdo.amount_net_to_pay||0);
      if (Math.abs(sa-ba) <= 1) {
        suggestions.push({slip, slipIdx:si, bdo, bdoIdx:bi,
          confidence:'exact_amount', diff:sa-ba, label:'💰 ยอดตรง ฿'+sa.toLocaleString()});
        usedSlips.add(si); usedBdos.add(bi);
      }
    });
  });

  // P3: fuzzy ±5% + same customer
  pendingSlips.forEach((slip, si) => {
    if (usedSlips.has(si)) return;
    const sa = parseFloat(slip.amount||0); if (sa <= 0) return;
    const slipCust = slip.line_user_id || slip.customer_name || '';
    pendingBdos.forEach((bdo, bi) => {
      if (usedBdos.has(bi)) return;
      const ba = parseFloat(bdo.amount_total||bdo.amount_net_to_pay||0);
      const tol = Math.max(1, ba*0.05);
      const diff = sa-ba;
      if (Math.abs(diff) <= tol) {
        const bdoCust = bdo.customer_name || bdo.customer_ref || '';
        const sameCust = slipCust && bdoCust && (slipCust === bdo.line_user_id || (slip.customer_name && slip.customer_name === bdo.customer_name));
        if (sameCust || Math.abs(diff) <= Math.max(1, ba*0.02)) {
          suggestions.push({slip, slipIdx:si, bdo, bdoIdx:bi,
            confidence:'fuzzy', diff, label:'🔍 ยอดใกล้เคียง (ต่าง ฿'+Math.abs(diff).toLocaleString()+')'});
          usedSlips.add(si); usedBdos.add(bi);
        }
      }
    });
  });

  return suggestions;
}

// ═══════════════════════════════════════════════════════════════
// RENDER SUGGESTIONS
// ═══════════════════════════════════════════════════════════════
const CONF_COLORS = {
  exact_bdo_id:       {bg:'#dcfce7',clr:'#16a34a',icon:'✅',lbl:'bdo_id + ยอดตรง'},
  exact_amount:       {bg:'#dbeafe',clr:'#1d4ed8',icon:'💰',lbl:'ยอดตรงเป๊ะ'},
  bdo_id_amount_diff: {bg:'#fef9c3',clr:'#92400e',icon:'⚠️',lbl:'bdo_id ตรง แต่ยอดต่าง'},
  fuzzy:              {bg:'#f3e8ff',clr:'#6d28d9',icon:'🔍',lbl:'ยอดใกล้เคียง'}
};

function renderSuggestions(suggs) {
  const el = document.getElementById('suggList');
  if (!suggs.length) {
    el.innerHTML = '<div style="text-align:center;padding:1.5rem;color:var(--gray-400);font-size:0.82rem;"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:4px;"></i>ระบบยังไม่พบคู่ที่แนะนำ — เลือกสลิปและ BDO ด้านล่างเพื่อจับคู่เอง</div>';
    return;
  }
  let html = '<div style="display:flex;flex-direction:column;gap:6px;">';
  suggs.forEach((m, idx) => {
    const s = m.slip, b = m.bdo;
    const sid = s.id || s.slip_id, bid = b.bdo_id || b.id;
    const conf = CONF_COLORS[m.confidence] || CONF_COLORS.fuzzy;
    const diffStr = m.diff !== 0 ? ' (ต่าง ฿'+Math.abs(m.diff).toLocaleString()+')' : '';
    const thumb = s.image_full_url
      ? `<img src="${esc(s.image_full_url)}" onclick="event.stopPropagation();openSlipImg('${esc(s.image_full_url)}')" style="width:40px;height:50px;object-fit:cover;border-radius:6px;cursor:pointer;flex-shrink:0;" onerror="this.style.display='none'">`
      : `<div style="width:40px;height:50px;background:var(--gray-100);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>`;
    const bdoName = b.bdo_name || ('BDO-'+bid);
    const slipAmt = s.amount != null ? '฿'+parseFloat(s.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '-';
    const bdoAmt  = b.amount_total != null ? '฿'+Number(b.amount_total).toLocaleString() : '-';
    const slipCust = esc(s.customer_name || s.line_user_id || '-');
    const bdoCust  = esc(b.customer_name || b.customer_ref || '-');

    html += `<div class="sugg-item conf-${m.confidence}">
      ${thumb}
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:0.88rem;color:#16a34a;">${slipAmt}</div>
        <div style="font-size:0.72rem;color:var(--gray-500);">${slipCust}</div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:center;gap:2px;flex-shrink:0;padding:0 6px;">
        <span style="font-size:1rem;color:${conf.clr};">→</span>
        <span style="background:${conf.bg};color:${conf.clr};font-size:0.62rem;font-weight:700;padding:1px 6px;border-radius:50px;white-space:nowrap;">${conf.icon} ${conf.lbl}${diffStr}</span>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;font-size:0.88rem;">${esc(bdoName)}</div>
        <div style="font-weight:700;font-size:0.92rem;color:#d97706;">${bdoAmt}</div>
        <div style="font-size:0.72rem;color:var(--gray-500);">${bdoCust}</div>
      </div>
      <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">
        <button class="btn-confirm" onclick="confirmSuggestion(${sid},${bid},this)"><i class="bi bi-check2-circle"></i> ยืนยัน</button>
        <button class="btn-dismiss" onclick="dismissSuggestion(${idx},this)"><i class="bi bi-x"></i> ข้าม</button>
      </div>
    </div>`;
  });
  html += '</div>';
  el.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════
// RENDER SLIP LIST
// ═══════════════════════════════════════════════════════════════
function renderSlipList(slips) {
  const el = document.getElementById('slipList');
  if (!slips.length) {
    el.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-check-circle" style="font-size:2rem;display:block;margin-bottom:4px;color:#16a34a;"></i>ไม่มีสลิปรอจับคู่</div>';
    return;
  }
  const groups = new Map();
  slips.forEach(s => {
    const key = s.customer_name || s.line_user_id || 'ไม่ระบุชื่อ';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(s);
  });
  let html = '';
  groups.forEach((gs, cust) => {
    html += `<div style="margin-bottom:8px;">
      <div class="group-hd"><i class="bi bi-person-fill" style="color:var(--primary);"></i> ${esc(cust)} <span style="font-weight:400;color:var(--gray-400);">(${gs.length} สลิป)</span></div>`;
    gs.forEach(s => { html += renderSlipCard(s); });
    html += '</div>';
  });
  el.innerHTML = html;
}

function renderSlipCard(s) {
  const sid = s.id || s.slip_id;
  const isSel = _selSlips.has(sid);
  const sugg = _suggestions.find(m => (m.slip.id||m.slip.slip_id) == sid);
  const border = isSel ? 'var(--primary)' : (sugg ? '#a5b4fc' : 'var(--gray-200)');
  const bg     = isSel ? 'var(--primary-light)' : (sugg ? '#f5f3ff' : '#fff');
  const amt = s.amount != null ? '฿'+parseFloat(s.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '-';
  const dt  = fmtDate(s.transfer_date || s.uploaded_at);
  const thumb = s.image_full_url
    ? `<img class="slip-thumb" src="${esc(s.image_full_url)}" onclick="event.stopPropagation();openSlipImg('${esc(s.image_full_url)}','${sid}','${esc(s.status||'new')}')" onerror="this.style.display='none'" alt="">`
    : `<div class="slip-thumb-ph"><i class="bi bi-image" style="color:var(--gray-400);"></i></div>`;
  const confBadge = s.match_confidence ? `<span class="badge-conf badge-${s.match_confidence}">${esc(s.match_confidence)}</span> ` : '';
  const dlvBadge  = s.delivery_type ? `<span class="badge-conf ${s.delivery_type==='company'?'badge-company':'badge-private'}">${s.delivery_type==='company'?'สายส่ง':'ขนส่งเอกชน'}</span>` : '';
  const suggBadge = sugg ? `<div style="font-size:0.68rem;margin-top:2px;"><span style="background:#e0e7ff;color:#4338ca;padding:1px 5px;border-radius:50px;">⭐ ${esc(sugg.label)}</span></div>` : '';
  return `<div id="sc${sid}" class="match-card${isSel?' selected':''}${sugg&&!isSel?' has-sugg':''}" style="border-color:${border};background:${bg};" onclick="toggleSlip(${sid})">
    <input type="checkbox" ${isSel?'checked':''} style="accent-color:var(--primary);flex-shrink:0;" onclick="event.stopPropagation();toggleSlip(${sid})">
    ${thumb}
    <div style="flex:1;min-width:0;">
      <div style="font-weight:700;font-size:0.9rem;color:#16a34a;">${amt}</div>
      <div style="font-size:0.7rem;color:var(--gray-400);">${dt} ${confBadge}${dlvBadge}</div>
      ${suggBadge}
    </div>
  </div>`;
}

// ═══════════════════════════════════════════════════════════════
// RENDER BDO LIST
// ═══════════════════════════════════════════════════════════════
function renderBdoList(bdos) {
  const el = document.getElementById('bdoList');
  if (!bdos.length) {
    el.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-check-circle" style="font-size:2rem;display:block;margin-bottom:4px;color:#16a34a;"></i>ไม่มี BDO รอชำระ</div>';
    return;
  }
  const groups = new Map();
  bdos.forEach(b => {
    const key = b.customer_name || b.customer_ref || 'ไม่ระบุชื่อ';
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(b);
  });
  let html = '';
  groups.forEach((gb, cust) => {
    const total = gb.reduce((s,b) => s+parseFloat(b.amount_total||0), 0);
    html += `<div style="margin-bottom:8px;">
      <div class="group-hd bdo-group"><i class="bi bi-person-fill" style="color:var(--warning);"></i> ${esc(cust)} <span style="font-weight:400;color:var(--gray-400);">(${gb.length} BDO · ฿${total.toLocaleString()})</span></div>`;
    gb.forEach(b => { html += renderBdoCard(b); });
    html += '</div>';
  });
  el.innerHTML = html;
}

function renderBdoCard(bdo) {
  const bid = bdo.bdo_id || bdo.id;
  const isSel = _selBdos.has(bid);
  const sugg = _suggestions.find(m => (m.bdo.bdo_id||m.bdo.id) == bid);
  const border = isSel ? 'var(--primary)' : (sugg ? '#a5b4fc' : 'var(--gray-200)');
  const bg     = isSel ? 'var(--primary-light)' : (sugg ? '#f5f3ff' : '#fff');
  const bdoName = bdo.bdo_name || ('BDO-'+bid);
  const amt = bdo.amount_total != null ? '฿'+Number(bdo.amount_total).toLocaleString() : '-';
  const dt  = fmtDate(bdo.bdo_date || bdo.updated_at || bdo.synced_at);
  const ps  = bdo.payment_status || 'pending';
  const psMap = {pending:{bg:'#fef3c7',clr:'#d97706',lbl:'รอชำระ'},slip_uploaded:{bg:'#dbeafe',clr:'#1d4ed8',lbl:'อัพสลิปแล้ว'},matched:{bg:'#dcfce7',clr:'#16a34a',lbl:'จับคู่แล้ว'},paid:{bg:'#dcfce7',clr:'#16a34a',lbl:'ชำระแล้ว'}};
  const psc = psMap[ps] || psMap.pending;
  const dlvBadge = bdo.delivery_type ? `<span class="badge-conf ${bdo.delivery_type==='company'?'badge-company':'badge-private'}"><i class="bi bi-truck" style="font-size:0.6rem;"></i> ${bdo.delivery_type==='company'?'สายส่ง':'ขนส่งเอกชน'}</span>` : '';
  const suggBadge = sugg ? `<div style="font-size:0.68rem;margin-top:2px;"><span style="background:#e0e7ff;color:#4338ca;padding:1px 5px;border-radius:50px;">⭐ แนะนำจับคู่</span></div>` : '';
  const odooLink = ODOO_BASE+'/web#id='+bid+'&model=cny.bill.invoice.before.delivery&view_type=form';
  return `<div id="bc${bid}" class="match-card${isSel?' selected':''}${sugg&&!isSel?' has-sugg':''}" style="border-color:${border};background:${bg};align-items:flex-start;" onclick="toggleBdo(${bid})">
    <input type="checkbox" ${isSel?'checked':''} style="accent-color:var(--primary);flex-shrink:0;margin-top:3px;" onclick="event.stopPropagation();toggleBdo(${bid})">
    <div style="flex:1;min-width:0;">
      <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
        <span style="font-weight:700;font-size:0.88rem;">${esc(bdoName)}</span>
        <span style="background:${psc.bg};color:${psc.clr};padding:1px 6px;border-radius:50px;font-size:0.65rem;">${psc.lbl}</span>
        ${dlvBadge}
      </div>
      <div style="font-weight:700;font-size:0.95rem;margin-top:2px;">${amt}</div>
      <div style="font-size:0.7rem;color:var(--gray-400);">${esc(bdo.order_name||'-')} · ${dt}</div>
      ${suggBadge}
    </div>
    <a href="${esc(odooLink)}" target="_blank" onclick="event.stopPropagation()" title="เปิดใน Odoo" style="color:var(--gray-400);font-size:0.8rem;flex-shrink:0;"><i class="bi bi-box-arrow-up-right"></i></a>
  </div>`;
}

// ═══════════════════════════════════════════════════════════════
// RENDER MATCHED TODAY
// ═══════════════════════════════════════════════════════════════
function renderMatchedToday(slips) {
  const el = document.getElementById('matchedTodayList');
  if (!slips.length) {
    el.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--gray-400);"><i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:4px;"></i>ยังไม่มีรายการจับคู่สำเร็จ</div>';
    return;
  }
  let html = '<div style="overflow-x:auto;"><table class="matched-table"><thead><tr><th>สลิป</th><th>ยอด</th><th>ลูกค้า</th><th>จับคู่กับ</th><th>Confidence</th><th>เวลา</th></tr></thead><tbody>';
  slips.slice(0,30).forEach(s => {
    const amt = s.amount != null ? '฿'+parseFloat(s.amount).toLocaleString('th-TH',{minimumFractionDigits:0}) : '-';
    const cust = esc(s.customer_name || s.line_user_id || '-');
    const ref  = s.bdo_id ? 'BDO #'+s.bdo_id : (s.order_id ? 'SO-'+s.order_id : (s.invoice_id ? 'INV-'+s.invoice_id : '-'));
    const conf = esc(s.match_confidence || '-');
    const ts   = s.matched_at ? new Date(s.matched_at).toLocaleString('th-TH',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}) : '-';
    const thumb = s.image_full_url ? `<img src="${esc(s.image_full_url)}" style="width:26px;height:32px;object-fit:cover;border-radius:4px;vertical-align:middle;" onerror="this.style.display='none'">` : '';
    html += `<tr style="background:#f0fdf4;">
      <td>${thumb}</td>
      <td style="font-weight:700;color:#16a34a;">${amt}</td>
      <td style="font-size:0.78rem;">${cust}</td>
      <td style="font-weight:600;color:var(--primary);">${esc(ref)}</td>
      <td><span style="background:#dcfce7;color:#16a34a;padding:1px 5px;border-radius:50px;font-size:0.68rem;">${conf}</span></td>
      <td style="font-size:0.72rem;color:var(--gray-500);">${ts}</td>
    </tr>`;
  });
  html += '</tbody></table></div>';
  el.innerHTML = html;
}

// ═══════════════════════════════════════════════════════════════
// SELECTION & SUMMARY BAR
// ═══════════════════════════════════════════════════════════════
function toggleSlip(sid) {
  _selSlips.has(sid) ? _selSlips.delete(sid) : _selSlips.add(sid);
  const card = document.getElementById('sc'+sid);
  if (card) {
    const isSel = _selSlips.has(sid);
    const sugg = _suggestions.find(m => (m.slip.id||m.slip.slip_id) == sid);
    card.style.borderColor = isSel ? 'var(--primary)' : (sugg ? '#a5b4fc' : 'var(--gray-200)');
    card.style.background  = isSel ? 'var(--primary-light)' : (sugg ? '#f5f3ff' : '#fff');
    const cb = card.querySelector('input[type="checkbox"]');
    if (cb) cb.checked = isSel;
  }
  updateSumBar();
}

function toggleBdo(bid) {
  _selBdos.has(bid) ? _selBdos.delete(bid) : _selBdos.add(bid);
  const card = document.getElementById('bc'+bid);
  if (card) {
    const isSel = _selBdos.has(bid);
    const sugg = _suggestions.find(m => (m.bdo.bdo_id||m.bdo.id) == bid);
    card.style.borderColor = isSel ? 'var(--primary)' : (sugg ? '#a5b4fc' : 'var(--gray-200)');
    card.style.background  = isSel ? 'var(--primary-light)' : (sugg ? '#f5f3ff' : '#fff');
    const cb = card.querySelector('input[type="checkbox"]');
    if (cb) cb.checked = isSel;
  }
  updateSumBar();
}

function updateSumBar() {
  const bar = document.getElementById('sumBar');
  const hasAny = _selSlips.size > 0 || _selBdos.size > 0;
  const hasBoth = _selSlips.size > 0 && _selBdos.size > 0;
  bar.classList.toggle('show', hasAny);

  let sa = 0; _selSlips.forEach(id => { const s = _slips.find(x=>(x.id||x.slip_id)==id); if(s) sa+=parseFloat(s.amount||0); });
  let ba = 0; _selBdos.forEach(id => { const b = _bdos.find(x=>(x.bdo_id||x.id)==id); if(b) ba+=parseFloat(b.amount_total||b.amount_net_to_pay||0); });

  setEl('sumSlipAmt', _selSlips.size > 0 ? '฿'+sa.toLocaleString() : '-');
  setEl('sumBdoAmt',  _selBdos.size  > 0 ? '฿'+ba.toLocaleString() : '-');

  const diffEl = document.getElementById('sumDiffWrap');
  if (hasBoth) {
    const diff = sa - ba;
    if (Math.abs(diff) <= 1)  diffEl.innerHTML = '<div class="sum-lbl">ผลต่าง</div><div class="sum-val" style="color:#16a34a;">✅ ยอดตรง</div>';
    else if (diff > 0)        diffEl.innerHTML = '<div class="sum-lbl">ผลต่าง</div><div class="sum-val" style="color:#d97706;">เกิน ฿'+Math.abs(diff).toLocaleString()+'</div>';
    else                      diffEl.innerHTML = '<div class="sum-lbl">ผลต่าง</div><div class="sum-val" style="color:#dc2626;">ขาด ฿'+Math.abs(diff).toLocaleString()+'</div>';
  } else { diffEl.innerHTML = ''; }

  document.getElementById('confirmMatchBtn').disabled = !hasBoth;
}

function clearSelection() {
  _selSlips.clear(); _selBdos.clear();
  loadAll();
}

// ═══════════════════════════════════════════════════════════════
// MATCH HELPER — build correct payload for slip_match_bdo
// Prefers slip_inbox_id + matches[] (Odoo-first).
// Falls back to legacy slip_id + bdo_id if slip_inbox_id is absent.
// ═══════════════════════════════════════════════════════════════
function _buildMatchPayload(slip, bdo, note) {
  const slipInboxId = parseInt(slip.slip_inbox_id || slip.odoo_slip_id || 0, 10);
  const bdoId       = parseInt(bdo.bdo_id || bdo.id || 0, 10);
  const bdoAmt      = parseFloat(bdo.amount_total || bdo.amount_net_to_pay || bdo.amount || 0);

  if (slipInboxId > 0 && bdoId > 0) {
    return {
      action:        'slip_match_bdo',
      slip_inbox_id: slipInboxId,
      line_user_id:  slip.line_user_id || '',
      matches:       [{ bdo_id: bdoId, amount: bdoAmt }],
      note:          note || '',
    };
  }
  // Legacy fallback — local-only match via slip_id + bdo_id
  return {
    action:  'slip_match_bdo',
    slip_id: parseInt(slip.id || slip.slip_id || 0, 10),
    bdo_id:  bdoId,
    note:    note || '',
  };
}

// ═══════════════════════════════════════════════════════════════
// CONFIRM SUGGESTION (single)
// ═══════════════════════════════════════════════════════════════
async function confirmSuggestion(slipId, bdoId, btn) {
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat spin"></i>'; }
  // Look up full objects so _buildMatchPayload can use slip_inbox_id
  const slip = _slips.find(s => (s.id || s.slip_id) == slipId) || { id: slipId };
  const bdo  = _bdos.find(b => (b.bdo_id || b.id) == bdoId)   || { bdo_id: bdoId };
  const result = await whApi(_buildMatchPayload(slip, bdo, ''));
  if (result?.success) {
    const card = btn?.closest('.sugg-item');
    if (card) { card.style.background='#f0fdf4'; card.style.borderColor='#86efac'; card.innerHTML='<div style="padding:6px;color:#16a34a;font-size:0.83rem;"><i class="bi bi-check-circle-fill"></i> จับคู่สำเร็จแล้ว</div>'; }
    toast('✅ จับคู่สำเร็จ', 'ok');
    loadAll();
  } else {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle"></i> ยืนยัน'; }
    toast('❌ ' + (result?.error || 'เกิดข้อผิดพลาด'), 'err');
  }
}

function dismissSuggestion(idx, btn) {
  const card = btn?.closest('.sugg-item');
  if (card) { card.style.opacity='0.3'; card.style.pointerEvents='none'; }
  _suggestions.splice(idx, 1);
  setEl('suggCount', _suggestions.length ? _suggestions.length+' คู่' : 'ยังไม่มีคำแนะนำ');
}

// ═══════════════════════════════════════════════════════════════
// BATCH CONFIRM
// ═══════════════════════════════════════════════════════════════
async function batchConfirmMatches() {
  const toConfirm = _suggestions.filter(m => m.confidence !== 'exact_bdo_id');
  if (!toConfirm.length) { toast('ไม่มีรายการที่รอยืนยัน', 'warn'); return; }
  if (!confirm('ยืนยันจับคู่ทั้งหมด '+toConfirm.length+' คู่?')) return;

  const btn = document.getElementById('batchConfirmBtn');
  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';

  let ok = 0, fail = 0;
  for (const m of toConfirm) {
    const r = await whApi(_buildMatchPayload(m.slip, m.bdo, 'Batch match: '+m.confidence));
    if (r?.success) ok++; else fail++;
  }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-all"></i> ยืนยันทั้งหมด';
  toast(`✅ สำเร็จ ${ok}${fail?' ❌ ล้มเหลว '+fail:''}`, ok>0?'ok':'err');
  if (ok > 0) loadAll();
}

// ═══════════════════════════════════════════════════════════════
// AUTO CONFIRM EXACT
// ═══════════════════════════════════════════════════════════════
async function autoConfirmExact(list) {
  for (const m of list) {
    await whApi(_buildMatchPayload(m.slip, m.bdo, 'Auto-confirm exact_bdo_id'));
  }
  if (list.length) { toast('✅ Auto-confirm '+list.length+' คู่ exact', 'ok'); loadAll(); }
}

// ═══════════════════════════════════════════════════════════════
// MANUAL MATCH CONFIRM
// ═══════════════════════════════════════════════════════════════
async function confirmManualMatch() {
  if (_selSlips.size === 0 || _selBdos.size === 0) { toast('กรุณาเลือกสลิปและ BDO อย่างน้อยอย่างละ 1 รายการ', 'warn'); return; }
  const note = document.getElementById('matchNote')?.value || '';
  const btn = document.getElementById('confirmMatchBtn');
  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';

  const selSlips = [..._selSlips].map(id => _slips.find(s=>(s.id||s.slip_id)==id)).filter(Boolean);
  const selBdos  = [..._selBdos].map(id => _bdos.find(b=>(b.bdo_id||b.id)==id)).filter(Boolean);

  let ok = 0, fail = 0;
  for (const slip of selSlips) {
    const slipInboxId = parseInt(slip.slip_inbox_id || slip.odoo_slip_id || 0, 10);
    let r;
    if (slipInboxId > 0) {
      // Odoo-first: send all selected BDOs as matches array
      const matches = selBdos.map(b => ({
        bdo_id: parseInt(b.bdo_id||b.id||0, 10),
        amount: parseFloat(b.amount_total||b.amount_net_to_pay||b.amount||0)
      }));
      r = await whApi({ action:'slip_match_bdo', slip_inbox_id:slipInboxId, line_user_id:slip.line_user_id||'', matches, note });
    } else {
      // Legacy: match to first selected BDO only
      const bdo = selBdos[0];
      r = await whApi(_buildMatchPayload(slip, bdo, note));
    }
    if (r?.success) ok++; else { fail++; toast('❌ '+(r?.error||'เกิดข้อผิดพลาด'), 'err'); }
  }

  btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle"></i> ยืนยันจับคู่';
  document.getElementById('matchNote').value = '';
  if (ok > 0) { toast('✅ จับคู่สำเร็จ '+ok+' รายการ'+(fail?' ❌ ล้มเหลว '+fail:''), 'ok'); loadAll(); }
}

// ═══════════════════════════════════════════════════════════════
// SLIP IMAGE + UNMATCH
// ═══════════════════════════════════════════════════════════════
function openSlipImg(url, slipId, status) {
  _currentSlipForUnmatch = {slipId, status};
  document.getElementById('slipModalImg').src = url;
  const unmatchBtn = document.getElementById('unmatchBtn');
  unmatchBtn.style.display = (status === 'matched') ? '' : 'none';
  openModal('slipModal');
}

async function unmatchCurrentSlip() {
  if (!_currentSlipForUnmatch) return;
  const {slipId, status} = _currentSlipForUnmatch;
  if (['posted','done'].includes(status)) { toast('ไม่สามารถยกเลิกได้ สถานะ: '+status, 'err'); return; }
  if (!confirm('ยืนยันการยกเลิกการจับคู่สลิปนี้?')) return;

  // Try Odoo-first unmatch if slip_inbox_id is available
  const slip = _slips.find(s => (s.id || s.slip_id) == slipId);
  const slipInboxId = parseInt(slip?.slip_inbox_id || slip?.odoo_slip_id || 0, 10);
  let r;
  if (slipInboxId > 0) {
    r = await whApi({ action:'slip_unmatch', slip_inbox_id:slipInboxId, line_user_id:slip?.line_user_id||'', reason:'ยกเลิกจาก BDO Confirm' });
  } else {
    r = await whApi({ action:'unmatch_slip', slip_id:slipId, reason:'ยกเลิกจาก BDO Confirm' });
  }
  if (r?.success) { toast('✅ ยกเลิกการจับคู่เรียบร้อยแล้ว', 'ok'); closeModal('slipModal'); loadAll(); }
  else toast('❌ '+(r?.error||'เกิดข้อผิดพลาด'), 'err');
}

// ═══════════════════════════════════════════════════════════════
// FILTER & SEARCH
// ═══════════════════════════════════════════════════════════════
function setFilter(mode) {
  _filterMode = mode;
  ['pending','matched','all'].forEach(m => {
    document.getElementById('ftab-'+m)?.classList.toggle('active', m === mode);
  });
  loadAll();
}

function debounceSearch() {
  clearTimeout(_searchTimer);
  _searchTimer = setTimeout(() => { _searchTerm = document.getElementById('searchInput').value; loadAll(); }, 400);
}

// ═══════════════════════════════════════════════════════════════
// UTILS
// ═══════════════════════════════════════════════════════════════
function fmtDate(d) {
  if (!d) return '-';
  try { return new Date(d).toLocaleDateString('th-TH',{day:'2-digit',month:'short',year:'2-digit'}); } catch(e) { return d; }
}

function esc(s) {
  if (s == null) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function setEl(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

function setLoading(id) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div>';
}

function setStatus(msg) { setEl('statusDot', msg); }

function toast(msg, type='ok') {
  const w = document.getElementById('toastWrap');
  const t = document.createElement('div');
  t.className = 'toast toast-'+type;
  t.textContent = msg;
  w.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

// Close modal on backdrop click
document.querySelectorAll('.modal-bg').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// ═══════════════════════════════════════════════════════════════
// INIT
// ═══════════════════════════════════════════════════════════════
loadAll();
// Auto-refresh every 60s
setInterval(loadAll, 60000);
</script>
</body>
</html>
