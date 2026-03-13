<?php
/**
 * BDO Confirm — Sales Matching Workspace
 *
 * หน้าหลักสำหรับ Sales ทำ BDO Confirm / Slip Matching
 * ใช้ได้ทั้งแบบ standalone และ embed ใน inboxreya ผ่าน iframe/SSO
 *
 * Features:
 *  - BDO list (waiting state) พร้อม financial breakdown
 *  - Slip list (pending/unmatched) พร้อม slip image preview
 *  - Matching workspace: 1:1, 1:N, N:1 พร้อม amount validation
 *  - Smart suggestions (auto-detect bdo_id + exact amount)
 *  - Statement PDF download
 *  - Unmatch (blocked if posted/done)
 *  - Staged loading: BDO list first, detail on demand
 *
 * @version 1.0.0 (March 2026 — cny_reya_connector v11.0.1.3.0)
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Auth: require admin session
if (empty($_SESSION['admin_user']['id'])) {
    header('Location: auth/login.php');
    exit;
}

$pageTitle = 'BDO Confirm — Slip Matching';
$apiBase   = 'api/bdo-inbox-api.php';
$internalSecret = defined('INTERNAL_API_SECRET') ? INTERNAL_API_SECRET : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
    --primary: #2563eb;
    --primary-light: #dbeafe;
    --success: #16a34a;
    --success-light: #dcfce7;
    --warning: #d97706;
    --warning-light: #fef3c7;
    --danger: #dc2626;
    --danger-light: #fee2e2;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --radius: 10px;
    --shadow: 0 1px 4px rgba(0,0,0,.08);
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--gray-50); color: var(--gray-800); font-size: 14px; }

/* Layout */
.page-header { background: white; border-bottom: 1px solid var(--gray-200); padding: 12px 20px; display: flex; align-items: center; gap: 12px; }
.page-header h1 { font-size: 1.1rem; font-weight: 700; color: var(--gray-800); }
.page-body { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 16px; max-width: 1400px; margin: 0 auto; }
@media (max-width: 900px) { .page-body { grid-template-columns: 1fr; } }

/* Cards */
.card { background: white; border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
.card-header { padding: 12px 16px; border-bottom: 1px solid var(--gray-100); display: flex; align-items: center; justify-content: space-between; }
.card-title { font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; gap: 6px; }
.card-body { padding: 12px 16px; }

/* Badges */
.badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 50px; font-size: 0.72rem; font-weight: 600; }
.badge-waiting { background: var(--warning-light); color: var(--warning); }
.badge-matched { background: var(--success-light); color: var(--success); }
.badge-new, .badge-unmatched { background: var(--gray-100); color: var(--gray-600); }
.badge-posted, .badge-done { background: var(--primary-light); color: var(--primary); }
.badge-private { background: #fce7f3; color: #be185d; }
.badge-company { background: #e0f2fe; color: #0369a1; }

/* BDO List */
.bdo-item { padding: 10px 12px; border: 2px solid var(--gray-200); border-radius: 8px; cursor: pointer; transition: all .15s; margin-bottom: 8px; }
.bdo-item:hover { border-color: var(--primary); background: var(--primary-light); }
.bdo-item.selected { border-color: var(--primary); background: var(--primary-light); }
.bdo-item-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.bdo-name { font-weight: 700; font-size: 0.9rem; color: var(--primary); }
.bdo-amount { font-weight: 700; font-size: 1rem; color: var(--gray-800); }
.bdo-meta { font-size: 0.78rem; color: var(--gray-500); display: flex; gap: 8px; flex-wrap: wrap; }
.bdo-financial { margin-top: 8px; padding: 8px; background: var(--gray-50); border-radius: 6px; font-size: 0.78rem; }
.fin-row { display: flex; justify-content: space-between; padding: 2px 0; }
.fin-row.total { font-weight: 700; border-top: 1px solid var(--gray-200); margin-top: 4px; padding-top: 4px; }

/* Slip List */
.slip-item { padding: 8px 10px; border: 2px solid var(--gray-200); border-radius: 8px; cursor: pointer; transition: all .15s; margin-bottom: 6px; display: flex; gap: 10px; align-items: flex-start; }
.slip-item:hover { border-color: var(--primary); }
.slip-item.selected { border-color: var(--success); background: var(--success-light); }
.slip-thumb { width: 52px; height: 52px; object-fit: cover; border-radius: 6px; flex-shrink: 0; background: var(--gray-100); }
.slip-thumb-placeholder { width: 52px; height: 52px; border-radius: 6px; background: var(--gray-100); display: flex; align-items: center; justify-content: center; color: var(--gray-400); flex-shrink: 0; }
.slip-info { flex: 1; min-width: 0; }
.slip-amount { font-weight: 700; font-size: 0.95rem; }
.slip-meta { font-size: 0.75rem; color: var(--gray-500); }
.slip-confidence { font-size: 0.72rem; }

/* Match Summary Bar */
.match-bar { position: sticky; bottom: 0; background: white; border-top: 2px solid var(--gray-200); padding: 12px 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; z-index: 10; }
.match-bar-amounts { display: flex; gap: 16px; flex: 1; }
.match-bar-item { text-align: center; }
.match-bar-label { font-size: 0.72rem; color: var(--gray-500); }
.match-bar-value { font-weight: 700; font-size: 1rem; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 7px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: none; transition: all .15s; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: #1d4ed8; }
.btn-primary:disabled { background: var(--gray-400); cursor: not-allowed; }
.btn-success { background: var(--success); color: white; }
.btn-success:hover { background: #15803d; }
.btn-danger { background: var(--danger); color: white; }
.btn-danger:hover { background: #b91c1c; }
.btn-outline { background: white; color: var(--gray-700); border: 1px solid var(--gray-300); }
.btn-outline:hover { background: var(--gray-50); }
.btn-sm { padding: 4px 10px; font-size: 0.78rem; }

/* Suggestions */
.suggestion-item { padding: 8px 12px; border-radius: 8px; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
.suggestion-exact { background: var(--success-light); border: 1px solid #86efac; }
.suggestion-bdo-id { background: var(--primary-light); border: 1px solid #93c5fd; }
.suggestion-mismatch { background: var(--warning-light); border: 1px solid #fcd34d; }

/* Detail panel */
.detail-panel { padding: 12px; background: var(--gray-50); border-radius: 8px; margin-top: 8px; }
.detail-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 0.82rem; }
.detail-label { color: var(--gray-500); }
.detail-value { font-weight: 600; }

/* Loading */
.loading { text-align: center; padding: 32px; color: var(--gray-400); }
.spin { animation: spin .8s linear infinite; display: inline-block; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Toast */
.toast-container { position: fixed; top: 16px; right: 16px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; }
.toast { padding: 10px 16px; border-radius: 8px; color: white; font-size: 0.85rem; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,.15); animation: slideIn .2s ease; }
.toast-success { background: var(--success); }
.toast-error { background: var(--danger); }
.toast-warning { background: var(--warning); }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

/* Modal */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 100; align-items: center; justify-content: center; }
.modal-overlay.active { display: flex; }
.modal { background: white; border-radius: var(--radius); max-width: 520px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.modal-header { padding: 16px 20px; border-bottom: 1px solid var(--gray-200); display: flex; justify-content: space-between; align-items: center; }
.modal-body { padding: 16px 20px; }
.modal-footer { padding: 12px 20px; border-top: 1px solid var(--gray-200); display: flex; gap: 8px; justify-content: flex-end; }

/* Note input */
.note-input { width: 100%; padding: 8px 10px; border: 1px solid var(--gray-200); border-radius: 7px; font-size: 0.85rem; resize: none; }
.note-input:focus { outline: none; border-color: var(--primary); }

/* Tabs */
.tabs { display: flex; gap: 4px; border-bottom: 2px solid var(--gray-200); margin-bottom: 12px; }
.tab { padding: 7px 14px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; color: var(--gray-500); }
.tab.active { color: var(--primary); border-bottom-color: var(--primary); }

/* Diff indicator */
.diff-ok { color: var(--success); }
.diff-over { color: var(--warning); }
.diff-under { color: var(--danger); }

/* Empty state */
.empty { text-align: center; padding: 32px 16px; color: var(--gray-400); }
.empty i { font-size: 2rem; display: block; margin-bottom: 8px; }
</style>
</head>
<body>

<div class="page-header">
    <i class="bi bi-receipt-cutoff" style="font-size:1.4rem;color:var(--primary);"></i>
    <h1>BDO Confirm — Slip Matching</h1>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <span id="connectionStatus" style="font-size:0.78rem;color:var(--gray-400);">กำลังเชื่อมต่อ...</span>
        <button class="btn btn-outline btn-sm" onclick="refreshAll()"><i class="bi bi-arrow-clockwise"></i> รีเฟรช</button>
    </div>
</div>

<!-- Customer selector -->
<div style="background:white;border-bottom:1px solid var(--gray-200);padding:10px 20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <label style="font-size:0.85rem;font-weight:600;color:var(--gray-600);">ลูกค้า:</label>
    <input id="searchLineUserId" type="text" placeholder="LINE User ID (Uxxxxxxxxx)" style="padding:6px 10px;border:1px solid var(--gray-200);border-radius:7px;font-size:0.85rem;width:240px;">
    <input id="searchPartnerId" type="text" placeholder="Partner ID (Odoo)" style="padding:6px 10px;border:1px solid var(--gray-200);border-radius:7px;font-size:0.85rem;width:160px;">
    <button class="btn btn-primary btn-sm" onclick="loadWorkspace()"><i class="bi bi-search"></i> โหลด</button>
    <span id="customerInfo" style="font-size:0.82rem;color:var(--gray-500);"></span>
</div>

<div class="page-body">

    <!-- LEFT: BDO List -->
    <div>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="bi bi-file-earmark-text"></i> BDO รอชำระ</span>
                <span id="bdoCount" style="font-size:0.78rem;color:var(--gray-400);"></span>
            </div>
            <div class="card-body" style="max-height:calc(100vh - 280px);overflow-y:auto;" id="bdoList">
                <div class="empty"><i class="bi bi-inbox"></i>ยังไม่ได้โหลดข้อมูล</div>
            </div>
        </div>
    </div>

    <!-- RIGHT: Slip List + Matching -->
    <div>
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <span class="card-title"><i class="bi bi-image"></i> สลิปรอจับคู่</span>
                <span id="slipCount" style="font-size:0.78rem;color:var(--gray-400);"></span>
            </div>
            <div class="tabs" style="padding:0 16px;">
                <div class="tab active" onclick="setSlipTab('pending')" id="tabPending">รอจับคู่</div>
                <div class="tab" onclick="setSlipTab('all')" id="tabAll">ทั้งหมด</div>
            </div>
            <div class="card-body" style="max-height:280px;overflow-y:auto;" id="slipList">
                <div class="empty"><i class="bi bi-inbox"></i>ยังไม่ได้โหลดข้อมูล</div>
            </div>
        </div>

        <!-- Smart Suggestions -->
        <div class="card" id="suggestionsCard" style="margin-bottom:16px;display:none;">
            <div class="card-header">
                <span class="card-title"><i class="bi bi-lightning-charge-fill" style="color:#f59e0b;"></i> คู่ที่แนะนำ</span>
                <button class="btn btn-success btn-sm" onclick="batchConfirmSuggestions()" id="batchConfirmBtn">
                    <i class="bi bi-check2-all"></i> ยืนยันทั้งหมด
                </button>
            </div>
            <div class="card-body" id="suggestionsList"></div>
        </div>

        <!-- Match Summary Bar -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="bi bi-link-45deg"></i> จับคู่ด้วยตนเอง</span>
                <button class="btn btn-outline btn-sm" onclick="clearMatchSelection()"><i class="bi bi-x"></i> ล้าง</button>
            </div>
            <div class="card-body">
                <div class="match-bar-amounts" style="margin-bottom:10px;">
                    <div class="match-bar-item">
                        <div class="match-bar-label">สลิปที่เลือก</div>
                        <div class="match-bar-value" id="sumSlipAmt">-</div>
                    </div>
                    <div style="display:flex;align-items:center;color:var(--gray-400);font-size:1.2rem;">→</div>
                    <div class="match-bar-item">
                        <div class="match-bar-label">BDO ที่เลือก</div>
                        <div class="match-bar-value" id="sumBdoAmt">-</div>
                    </div>
                    <div class="match-bar-item">
                        <div class="match-bar-label">ผลต่าง</div>
                        <div class="match-bar-value" id="sumDiff">-</div>
                    </div>
                </div>
                <textarea class="note-input" id="matchNote" rows="2" placeholder="หมายเหตุ (ถ้ามี)"></textarea>
                <div style="margin-top:8px;display:flex;gap:8px;">
                    <button class="btn btn-success" id="confirmMatchBtn" onclick="confirmManualMatch()" disabled>
                        <i class="bi bi-check2-circle"></i> ยืนยันจับคู่
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- BDO Detail Modal -->
<div class="modal-overlay" id="bdoDetailModal">
    <div class="modal">
        <div class="modal-header">
            <span style="font-weight:700;" id="bdoDetailTitle">รายละเอียด BDO</span>
            <button onclick="closeBdoDetail()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body" id="bdoDetailBody">
            <div class="loading"><i class="bi bi-arrow-repeat spin"></i><br>กำลังโหลด...</div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline btn-sm" id="bdoDetailPdfBtn" onclick="downloadStatementPdf()" style="display:none;">
                <i class="bi bi-file-earmark-pdf"></i> Statement PDF
            </button>
            <button class="btn btn-outline btn-sm" onclick="closeBdoDetail()">ปิด</button>
        </div>
    </div>
</div>

<!-- Slip Image Modal -->
<div class="modal-overlay" id="slipImageModal">
    <div class="modal" style="max-width:400px;">
        <div class="modal-header">
            <span style="font-weight:700;">รูปสลิป</span>
            <button onclick="closeSlipImage()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <img id="slipImageFull" src="" style="max-width:100%;border-radius:8px;" alt="Slip">
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger btn-sm" id="unmatchSlipBtn" onclick="unmatchCurrentSlip()" style="display:none;">
                <i class="bi bi-x-circle"></i> ยกเลิกจับคู่
            </button>
            <button class="btn btn-outline btn-sm" onclick="closeSlipImage()">ปิด</button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="toast-container" id="toastContainer"></div>

<script>
// ════════════════════════════════════════════════════════════════════════════
// STATE
// ════════════════════════════════════════════════════════════════════════════
const API_BASE = '<?= htmlspecialchars($apiBase) ?>';
const API_SECRET = '<?= htmlspecialchars($internalSecret) ?>';

let _bdos = [];
let _slips = [];
let _suggestions = [];
let _selectedBdoIds = new Set();
let _selectedSlipIds = new Set();
let _slipTab = 'pending';
let _currentBdoDetailId = null;
let _currentSlipForUnmatch = null;

// ════════════════════════════════════════════════════════════════════════════
// API HELPER
// ════════════════════════════════════════════════════════════════════════════
async function apiCall(action, params = {}) {
    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 15000);
        const res = await fetch(API_BASE + '?secret=' + encodeURIComponent(API_SECRET), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Internal-Secret': API_SECRET },
            body: JSON.stringify({ action, ...params }),
            signal: ctrl.signal,
        });
        clearTimeout(timer);
        const json = await res.json();
        return json;
    } catch (e) {
        return { success: false, error: e.name === 'AbortError' ? 'หมดเวลาการเชื่อมต่อ' : e.message };
    }
}

// ════════════════════════════════════════════════════════════════════════════
// LOAD / REFRESH
// ════════════════════════════════════════════════════════════════════════════
async function loadWorkspace() {
    const lineUserId = document.getElementById('searchLineUserId').value.trim();
    const partnerId  = document.getElementById('searchPartnerId').value.trim();

    if (!lineUserId && !partnerId) {
        showToast('กรุณาระบุ LINE User ID หรือ Partner ID', 'warning');
        return;
    }

    document.getElementById('bdoList').innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><br>กำลังโหลด BDO...</div>';
    document.getElementById('slipList').innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><br>กำลังโหลดสลิป...</div>';
    document.getElementById('suggestionsCard').style.display = 'none';

    const [wsRes] = await Promise.all([
        apiCall('matching_workspace', { line_user_id: lineUserId, partner_id: partnerId }),
    ]);

    if (!wsRes || !wsRes.success) {
        showToast('โหลดข้อมูลไม่สำเร็จ: ' + (wsRes?.error || 'Unknown error'), 'error');
        document.getElementById('bdoList').innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i>โหลดไม่สำเร็จ</div>';
        document.getElementById('slipList').innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i>โหลดไม่สำเร็จ</div>';
        return;
    }

    _bdos        = wsRes.data.open_bdos || [];
    _slips       = wsRes.data.pending_slips || [];
    _suggestions = wsRes.data.suggestions || [];
    _selectedBdoIds.clear();
    _selectedSlipIds.clear();

    document.getElementById('connectionStatus').textContent = 'เชื่อมต่อแล้ว ✓';
    document.getElementById('connectionStatus').style.color = 'var(--success)';

    renderBdoList();
    renderSlipList();
    renderSuggestions();
    updateMatchSummary();
}

function refreshAll() {
    loadWorkspace();
}

// ════════════════════════════════════════════════════════════════════════════
// BDO LIST RENDER
// ════════════════════════════════════════════════════════════════════════════
function renderBdoList() {
    const el = document.getElementById('bdoList');
    document.getElementById('bdoCount').textContent = _bdos.length + ' รายการ';

    if (!_bdos.length) {
        el.innerHTML = '<div class="empty"><i class="bi bi-inbox"></i>ไม่มี BDO รอชำระ</div>';
        return;
    }

    el.innerHTML = _bdos.map(bdo => {
        const sel = _selectedBdoIds.has(bdo.bdo_id);
        const deliveryBadge = bdo.delivery_type === 'private'
            ? '<span class="badge badge-private">ขนส่งเอกชน</span>'
            : bdo.delivery_type === 'company'
                ? '<span class="badge badge-company">สายส่ง</span>'
                : '';
        const stateBadge = `<span class="badge badge-${bdo.state}">${bdo.state === 'waiting' ? 'รอชำระ' : bdo.state}</span>`;
        const netToPay = bdo.amount_net_to_pay ?? bdo.amount_total ?? 0;

        return `<div class="bdo-item ${sel ? 'selected' : ''}" id="bdo-item-${bdo.bdo_id}"
            onclick="toggleBdoSelect(${bdo.bdo_id})"
            ondblclick="openBdoDetail(${bdo.bdo_id})">
            <div class="bdo-item-header">
                <span class="bdo-name">${esc(bdo.bdo_name || 'BDO-' + bdo.bdo_id)}</span>
                <span class="bdo-amount">฿${fmt(netToPay)}</span>
            </div>
            <div class="bdo-meta">
                ${stateBadge} ${deliveryBadge}
                ${bdo.order_name ? '<span>' + esc(bdo.order_name) + '</span>' : ''}
                ${bdo.salesperson_name ? '<span>เซลล์: ' + esc(bdo.salesperson_name) + '</span>' : ''}
            </div>
            ${renderFinancialMini(bdo)}
            <div style="margin-top:6px;display:flex;gap:6px;">
                <button class="btn btn-outline btn-sm" onclick="event.stopPropagation();openBdoDetail(${bdo.bdo_id})">
                    <i class="bi bi-eye"></i> รายละเอียด
                </button>
                ${bdo.statement_pdf_path ? `<button class="btn btn-outline btn-sm" onclick="event.stopPropagation();downloadStatementPdfById(${bdo.bdo_id})">
                    <i class="bi bi-file-earmark-pdf"></i> PDF
                </button>` : ''}
            </div>
        </div>`;
    }).join('');
}

function renderFinancialMini(bdo) {
    const fin = bdo.financial_summary || {};
    const hasData = bdo.amount_outstanding_invoice || bdo.amount_credit_note || bdo.amount_deposit || bdo.amount_so_this_round;
    if (!hasData) return '';

    return `<div class="bdo-financial">
        ${bdo.amount_so_this_round ? `<div class="fin-row"><span>ออเดอร์รอบนี้</span><span>฿${fmt(bdo.amount_so_this_round)}</span></div>` : ''}
        ${bdo.amount_outstanding_invoice ? `<div class="fin-row"><span>ค้างชำระ (${bdo.outstanding_invoice_count || ''} ใบ)</span><span>฿${fmt(bdo.amount_outstanding_invoice)}</span></div>` : ''}
        ${bdo.amount_credit_note ? `<div class="fin-row" style="color:var(--success);"><span>ใบลดหนี้</span><span>-฿${fmt(bdo.amount_credit_note)}</span></div>` : ''}
        ${bdo.amount_deposit ? `<div class="fin-row" style="color:var(--success);"><span>เงินมัดจำ</span><span>-฿${fmt(bdo.amount_deposit)}</span></div>` : ''}
        <div class="fin-row total"><span>ยอดสุทธิ</span><span>฿${fmt(bdo.amount_net_to_pay ?? bdo.amount_total ?? 0)}</span></div>
    </div>`;
}

function toggleBdoSelect(bdoId) {
    if (_selectedBdoIds.has(bdoId)) {
        _selectedBdoIds.delete(bdoId);
    } else {
        _selectedBdoIds.add(bdoId);
    }
    renderBdoList();
    updateMatchSummary();
}

// ════════════════════════════════════════════════════════════════════════════
// SLIP LIST RENDER
// ════════════════════════════════════════════════════════════════════════════
function setSlipTab(tab) {
    _slipTab = tab;
    document.getElementById('tabPending').classList.toggle('active', tab === 'pending');
    document.getElementById('tabAll').classList.toggle('active', tab === 'all');
    renderSlipList();
}

function renderSlipList() {
    const el = document.getElementById('slipList');
    const slips = _slipTab === 'pending'
        ? _slips.filter(s => ['new', 'unmatched', 'manual'].includes(s.status || 'new'))
        : _slips;

    document.getElementById('slipCount').textContent = slips.length + ' รายการ';

    if (!slips.length) {
        el.innerHTML = '<div class="empty"><i class="bi bi-inbox"></i>ไม่มีสลิปรอจับคู่</div>';
        return;
    }

    el.innerHTML = slips.map(slip => {
        const sel = _selectedSlipIds.has(slip.canonical_slip_id ?? slip.id);
        const confidenceBadge = renderConfidenceBadge(slip.match_confidence);
        const statusBadge = `<span class="badge badge-${slip.status || 'new'}">${slipStatusLabel(slip.status)}</span>`;

        return `<div class="slip-item ${sel ? 'selected' : ''}" id="slip-item-${slip.canonical_slip_id ?? slip.id}"
            onclick="toggleSlipSelect(${slip.canonical_slip_id ?? slip.id}, ${slip.id})">
            ${slip.image_full_url
                ? `<img class="slip-thumb" src="${esc(slip.image_full_url)}" onclick="event.stopPropagation();openSlipImage('${esc(slip.image_full_url)}', ${slip.canonical_slip_id ?? slip.id}, '${esc(slip.status || 'new')}')" alt="slip" onerror="this.style.display='none'">`
                : `<div class="slip-thumb-placeholder"><i class="bi bi-image"></i></div>`}
            <div class="slip-info">
                <div class="slip-amount">฿${fmt(slip.amount)}</div>
                <div class="slip-meta">
                    ${slip.transfer_date ? esc(slip.transfer_date) : ''}
                    ${slip.bdo_name ? ' · ' + esc(slip.bdo_name) : ''}
                </div>
                <div style="margin-top:3px;display:flex;gap:4px;flex-wrap:wrap;">
                    ${statusBadge} ${confidenceBadge}
                </div>
                ${slip.slip_inbox_id ? `<div style="font-size:0.7rem;color:var(--gray-400);">SLIP-ID: ${slip.slip_inbox_id}</div>` : ''}
            </div>
        </div>`;
    }).join('');
}

function renderConfidenceBadge(confidence) {
    const map = {
        exact: ['badge-matched', 'ยอดตรง 100%'],
        bdo_prepayment: ['badge-matched', 'จ่ายก่อนส่ง'],
        partial: ['badge-waiting', 'ชำระบางส่วน'],
        multi: ['badge-matched', 'หลายใบแจ้งหนี้'],
        manual: ['badge-waiting', 'รอ Manual'],
        unmatched: ['badge-new', 'ยังไม่จับคู่'],
    };
    const [cls, label] = map[confidence] || ['badge-new', confidence || 'ใหม่'];
    return `<span class="badge ${cls} slip-confidence">${label}</span>`;
}

function slipStatusLabel(status) {
    const m = { new: 'ใหม่', matched: 'จับคู่แล้ว', payment_created: 'สร้าง Payment', posted: 'Posted', done: 'เสร็จสิ้น' };
    return m[status] || status || 'ใหม่';
}

function toggleSlipSelect(canonicalId, localId) {
    const id = canonicalId ?? localId;
    if (_selectedSlipIds.has(id)) {
        _selectedSlipIds.delete(id);
    } else {
        _selectedSlipIds.add(id);
    }
    renderSlipList();
    updateMatchSummary();
}

// ════════════════════════════════════════════════════════════════════════════
// SUGGESTIONS
// ════════════════════════════════════════════════════════════════════════════
function renderSuggestions() {
    const card = document.getElementById('suggestionsCard');
    const list = document.getElementById('suggestionsList');

    if (!_suggestions.length) {
        card.style.display = 'none';
        return;
    }

    card.style.display = '';
    list.innerHTML = _suggestions.map((s, i) => {
        const isExact = s.confidence === 'exact_bdo_id' || s.confidence === 'exact_amount';
        const cls = s.confidence === 'exact_bdo_id' ? 'suggestion-bdo-id' : isExact ? 'suggestion-exact' : 'suggestion-mismatch';
        const icon = isExact ? '✅' : '⚠️';
        const slipName = s.slip?.slip_inbox_name || ('SLIP-' + s.slip_id);
        const bdoName  = s.bdo?.bdo_name || ('BDO-' + s.bdo_id);
        const diff     = s.amount_diff ?? 0;

        return `<div class="suggestion-item ${cls}">
            <span style="font-size:1.1rem;">${icon}</span>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:0.85rem;">${esc(slipName)} → ${esc(bdoName)}</div>
                <div style="font-size:0.75rem;color:var(--gray-600);">
                    ฿${fmt(s.slip?.amount)} → ฿${fmt(s.bdo?.amount_total ?? s.bdo?.amount_net_to_pay)}
                    ${diff > 0 ? ` <span class="diff-over">(ต่าง ฿${fmt(diff)})</span>` : ''}
                </div>
            </div>
            <button class="btn btn-success btn-sm" onclick="confirmSuggestion(${i})">
                <i class="bi bi-check"></i> ยืนยัน
            </button>
        </div>`;
    }).join('');
}

async function confirmSuggestion(idx) {
    const s = _suggestions[idx];
    if (!s) return;

    const slipInboxId = s.slip?.canonical_slip_id ?? s.slip?.slip_inbox_id ?? s.slip_id;
    const lineUserId  = s.slip?.line_user_id || document.getElementById('searchLineUserId').value.trim();
    const amount      = parseFloat(s.bdo?.amount_total ?? s.bdo?.amount_net_to_pay ?? 0);

    const res = await apiCall('slip_match_bdo', {
        slip_inbox_id: slipInboxId,
        line_user_id:  lineUserId,
        matches:       [{ bdo_id: s.bdo_id, amount }],
        note:          'Auto-confirm: ' + s.confidence,
        slip_amount:   parseFloat(s.slip?.amount ?? 0),
    });

    if (res?.success) {
        showToast('✅ จับคู่สำเร็จ', 'success');
        loadWorkspace();
    } else {
        showToast('❌ ' + (res?.data?.error || res?.error || 'เกิดข้อผิดพลาด'), 'error');
    }
}

async function batchConfirmSuggestions() {
    const toConfirm = _suggestions.filter(s => s.confidence === 'exact_bdo_id' || s.confidence === 'exact_amount');
    if (!toConfirm.length) {
        showToast('ไม่มีคู่ที่สามารถยืนยันอัตโนมัติได้', 'warning');
        return;
    }

    if (!confirm(`ยืนยันจับคู่ทั้งหมด ${toConfirm.length} คู่?`)) return;

    const btn = document.getElementById('batchConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';

    let ok = 0, fail = 0;
    const lineUserId = document.getElementById('searchLineUserId').value.trim();

    for (const s of toConfirm) {
        const slipInboxId = s.slip?.canonical_slip_id ?? s.slip?.slip_inbox_id ?? s.slip_id;
        const amount = parseFloat(s.bdo?.amount_total ?? s.bdo?.amount_net_to_pay ?? 0);
        const res = await apiCall('slip_match_bdo', {
            slip_inbox_id: slipInboxId,
            line_user_id:  lineUserId,
            matches:       [{ bdo_id: s.bdo_id, amount }],
            note:          'Batch confirm: ' + s.confidence,
            slip_amount:   parseFloat(s.slip?.amount ?? 0),
        });
        if (res?.success) ok++; else fail++;
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check2-all"></i> ยืนยันทั้งหมด';

    showToast(`✅ สำเร็จ ${ok} รายการ${fail ? ` ❌ ล้มเหลว ${fail} รายการ` : ''}`, ok > 0 ? 'success' : 'error');
    if (ok > 0) loadWorkspace();
}

// ════════════════════════════════════════════════════════════════════════════
// MANUAL MATCH
// ════════════════════════════════════════════════════════════════════════════
function updateMatchSummary() {
    let slipTotal = 0, bdoTotal = 0;

    _selectedSlipIds.forEach(id => {
        const s = _slips.find(x => (x.canonical_slip_id ?? x.id) == id);
        if (s) slipTotal += parseFloat(s.amount || 0);
    });
    _selectedBdoIds.forEach(id => {
        const b = _bdos.find(x => x.bdo_id == id);
        if (b) bdoTotal += parseFloat(b.amount_net_to_pay ?? b.amount_total ?? 0);
    });

    const hasSelection = _selectedSlipIds.size > 0 && _selectedBdoIds.size > 0;

    document.getElementById('sumSlipAmt').textContent = _selectedSlipIds.size > 0 ? '฿' + fmt(slipTotal) : '-';
    document.getElementById('sumBdoAmt').textContent  = _selectedBdoIds.size  > 0 ? '฿' + fmt(bdoTotal)  : '-';

    const diffEl = document.getElementById('sumDiff');
    if (hasSelection) {
        const diff = slipTotal - bdoTotal;
        if (Math.abs(diff) <= 1) {
            diffEl.innerHTML = '<span class="diff-ok">✅ ยอดตรง</span>';
        } else if (diff > 0) {
            diffEl.innerHTML = `<span class="diff-over">เกิน ฿${fmt(Math.abs(diff))}</span>`;
        } else {
            diffEl.innerHTML = `<span class="diff-under">ขาด ฿${fmt(Math.abs(diff))}</span>`;
        }
    } else {
        diffEl.textContent = '-';
    }

    document.getElementById('confirmMatchBtn').disabled = !hasSelection;
}

function clearMatchSelection() {
    _selectedBdoIds.clear();
    _selectedSlipIds.clear();
    renderBdoList();
    renderSlipList();
    updateMatchSummary();
}

async function confirmManualMatch() {
    if (_selectedSlipIds.size === 0 || _selectedBdoIds.size === 0) {
        showToast('กรุณาเลือกสลิปและ BDO อย่างน้อยอย่างละ 1 รายการ', 'warning');
        return;
    }

    const note        = document.getElementById('matchNote').value.trim();
    const lineUserId  = document.getElementById('searchLineUserId').value.trim();
    const btn         = document.getElementById('confirmMatchBtn');

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> กำลังบันทึก...';

    const selectedSlips = [..._selectedSlipIds].map(id => _slips.find(s => (s.canonical_slip_id ?? s.id) == id)).filter(Boolean);
    const selectedBdos  = [..._selectedBdoIds].map(id => _bdos.find(b => b.bdo_id == id)).filter(Boolean);

    let ok = 0, fail = 0;

    for (const slip of selectedSlips) {
        const slipInboxId = slip.canonical_slip_id ?? slip.slip_inbox_id ?? slip.id;
        const matches = selectedBdos.map(bdo => ({
            bdo_id: bdo.bdo_id,
            amount: parseFloat(bdo.amount_net_to_pay ?? bdo.amount_total ?? 0),
        }));

        const res = await apiCall('slip_match_bdo', {
            slip_inbox_id: slipInboxId,
            line_user_id:  lineUserId,
            matches,
            note:          note || 'Manual match via BDO Confirm',
            slip_amount:   parseFloat(slip.amount ?? 0),
        });

        if (res?.success) ok++; else {
            fail++;
            showToast('❌ ' + (res?.data?.error || res?.error || 'เกิดข้อผิดพลาด'), 'error');
        }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check2-circle"></i> ยืนยันจับคู่';
    document.getElementById('matchNote').value = '';

    if (ok > 0) {
        showToast(`✅ จับคู่สำเร็จ ${ok} รายการ`, 'success');
        loadWorkspace();
    }
}

// ════════════════════════════════════════════════════════════════════════════
// BDO DETAIL MODAL
// ════════════════════════════════════════════════════════════════════════════
async function openBdoDetail(bdoId) {
    _currentBdoDetailId = bdoId;
    document.getElementById('bdoDetailModal').classList.add('active');
    document.getElementById('bdoDetailTitle').textContent = 'กำลังโหลด...';
    document.getElementById('bdoDetailBody').innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><br>กำลังโหลด...</div>';
    document.getElementById('bdoDetailPdfBtn').style.display = 'none';

    const lineUserId = document.getElementById('searchLineUserId').value.trim();
    const res = await apiCall('bdo_detail', { bdo_id: bdoId, line_user_id: lineUserId });

    if (!res?.success) {
        document.getElementById('bdoDetailBody').innerHTML = `<div class="empty"><i class="bi bi-exclamation-triangle"></i>โหลดไม่สำเร็จ: ${esc(res?.error || 'Unknown')}</div>`;
        return;
    }

    const bdo = res.data?.bdo || {};
    document.getElementById('bdoDetailTitle').textContent = bdo.bdo_name || ('BDO-' + bdoId);

    if (res.data?.source === 'local_cache') {
        document.getElementById('bdoDetailTitle').textContent += ' ⚠️ (ข้อมูล Cache)';
    }

    const invoices = bdo.selected_invoices || [];
    const cns      = bdo.selected_credit_notes || [];
    const slips    = bdo.matched_slips || [];

    document.getElementById('bdoDetailBody').innerHTML = `
        <div class="detail-panel">
            <div class="detail-row"><span class="detail-label">สถานะ</span><span class="detail-value">${esc(bdo.state || '-')}</span></div>
            <div class="detail-row"><span class="detail-label">ประเภทขนส่ง</span><span class="detail-value">${bdo.delivery_type === 'private' ? '🚚 ขนส่งเอกชน (จ่ายก่อนส่ง)' : bdo.delivery_type === 'company' ? '🏢 สายส่ง (จ่ายทีหลัง)' : '-'}</span></div>
            <div class="detail-row"><span class="detail-label">ลูกค้า</span><span class="detail-value">${esc(bdo.partner_name || '-')}</span></div>
            <div class="detail-row"><span class="detail-label">เซลล์</span><span class="detail-value">${esc(bdo.salesperson_name || '-')}</span></div>
        </div>

        <div style="margin-top:12px;">
            <div style="font-weight:700;font-size:0.85rem;margin-bottom:6px;">สรุปยอดการเงิน</div>
            <div class="bdo-financial" style="background:white;border:1px solid var(--gray-200);">
                ${bdo.amount_so_this_round ? `<div class="fin-row"><span>ออเดอร์รอบนี้</span><span>฿${fmt(bdo.amount_so_this_round)}</span></div>` : ''}
                ${bdo.amount_outstanding_invoice ? `<div class="fin-row"><span>ค้างชำระ (${bdo.outstanding_invoice_count || ''} ใบ)</span><span>฿${fmt(bdo.amount_outstanding_invoice)}</span></div>` : ''}
                ${bdo.amount_credit_note ? `<div class="fin-row" style="color:var(--success);"><span>ใบลดหนี้ (${bdo.credit_note_count || ''} ใบ)</span><span>-฿${fmt(bdo.amount_credit_note)}</span></div>` : ''}
                ${bdo.amount_deposit ? `<div class="fin-row" style="color:var(--success);"><span>เงินมัดจำ</span><span>-฿${fmt(bdo.amount_deposit)}</span></div>` : ''}
                <div class="fin-row total"><span>ยอดสุทธิที่ต้องชำระ</span><span style="color:var(--primary);">฿${fmt(bdo.amount_net_to_pay ?? bdo.amount_total ?? 0)}</span></div>
            </div>
        </div>

        ${invoices.length ? `
        <div style="margin-top:12px;">
            <div style="font-weight:700;font-size:0.85rem;margin-bottom:6px;">ใบแจ้งหนี้ที่เลือก (${invoices.length} ใบ)</div>
            ${invoices.map(inv => `
                <div style="display:flex;justify-content:space-between;padding:4px 8px;background:var(--gray-50);border-radius:6px;margin-bottom:4px;font-size:0.82rem;">
                    <span>${esc(inv.number || '-')}</span>
                    <span>฿${fmt(inv.residual ?? inv.amount_total ?? 0)}</span>
                </div>`).join('')}
        </div>` : ''}

        ${slips.length ? `
        <div style="margin-top:12px;">
            <div style="font-weight:700;font-size:0.85rem;margin-bottom:6px;">สลิปที่จับคู่แล้ว (${slips.length} ใบ)</div>
            ${slips.map(s => `
                <div style="display:flex;justify-content:space-between;padding:4px 8px;background:var(--success-light);border-radius:6px;margin-bottom:4px;font-size:0.82rem;">
                    <span>${esc(s.slip_inbox_name || s.name || '-')}</span>
                    <span>฿${fmt(s.amount ?? 0)}</span>
                </div>`).join('')}
        </div>` : ''}
    `;

    if (bdo.statement_pdf_url || bdo.statement_pdf_path) {
        document.getElementById('bdoDetailPdfBtn').style.display = '';
    }
}

function closeBdoDetail() {
    document.getElementById('bdoDetailModal').classList.remove('active');
    _currentBdoDetailId = null;
}

async function downloadStatementPdf() {
    if (!_currentBdoDetailId) return;
    downloadStatementPdfById(_currentBdoDetailId);
}

async function downloadStatementPdfById(bdoId) {
    const lineUserId = document.getElementById('searchLineUserId').value.trim();
    const res = await apiCall('statement_pdf_url', { bdo_id: bdoId, line_user_id: lineUserId });
    if (res?.success && res.data?.url) {
        window.open(res.data.url, '_blank');
    } else {
        showToast('ไม่สามารถดาวน์โหลด PDF ได้', 'error');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// SLIP IMAGE MODAL + UNMATCH
// ════════════════════════════════════════════════════════════════════════════
function openSlipImage(url, canonicalSlipId, status) {
    _currentSlipForUnmatch = { canonicalSlipId, status };
    document.getElementById('slipImageFull').src = url;
    document.getElementById('slipImageModal').classList.add('active');

    const unmatchBtn = document.getElementById('unmatchSlipBtn');
    const canUnmatch = status === 'matched' || status === 'new';
    unmatchBtn.style.display = canUnmatch ? '' : 'none';
}

function closeSlipImage() {
    document.getElementById('slipImageModal').classList.remove('active');
    _currentSlipForUnmatch = null;
}

async function unmatchCurrentSlip() {
    if (!_currentSlipForUnmatch) return;

    const { canonicalSlipId, status } = _currentSlipForUnmatch;

    if (['posted', 'done'].includes(status)) {
        showToast('ไม่สามารถยกเลิกได้ สลิปอยู่ในสถานะ ' + status, 'error');
        return;
    }

    if (!confirm('ยืนยันการยกเลิกการจับคู่สลิปนี้?')) return;

    const lineUserId = document.getElementById('searchLineUserId').value.trim();
    const res = await apiCall('slip_unmatch', {
        slip_inbox_id: canonicalSlipId,
        line_user_id:  lineUserId,
        reason:        'ยกเลิกจาก BDO Confirm',
    });

    if (res?.success) {
        showToast('✅ ยกเลิกการจับคู่เรียบร้อยแล้ว', 'success');
        closeSlipImage();
        loadWorkspace();
    } else {
        showToast('❌ ' + (res?.data?.error || res?.error || 'เกิดข้อผิดพลาด'), 'error');
    }
}

// ════════════════════════════════════════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════════════════════════════════════════
function fmt(n) {
    const num = parseFloat(n);
    if (isNaN(num)) return '-';
    return num.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function showToast(msg, type = 'success') {
    const container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// Close modals on overlay click
document.getElementById('bdoDetailModal').addEventListener('click', function(e) {
    if (e.target === this) closeBdoDetail();
});
document.getElementById('slipImageModal').addEventListener('click', function(e) {
    if (e.target === this) closeSlipImage();
});

// Auto-load if URL params present
(function() {
    const params = new URLSearchParams(window.location.search);
    const uid = params.get('line_user_id') || params.get('user');
    const pid = params.get('partner_id');
    if (uid) document.getElementById('searchLineUserId').value = uid;
    if (pid) document.getElementById('searchPartnerId').value = pid;
    if (uid || pid) loadWorkspace();
})();
</script>
</body>
</html>
