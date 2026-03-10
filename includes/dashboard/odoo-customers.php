<?php
/**
 * Odoo Customer Management Tab
 * Embedded in dashboard.php?tab=odoo-customers
 */
$jsVersion = filemtime(__DIR__ . '/../../odoo-dashboard.js');
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<style>
.odoo-cust-wrap { padding: 1.5rem; }
.odoo-cust-wrap .content-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.25rem; margin-bottom:1rem; }
.odoo-cust-wrap .content-title { font-size:1rem; font-weight:600; color:#1f2937; display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem; }
.odoo-cust-wrap .form-control { border:1px solid #d1d5db; border-radius:8px; padding:0.4rem 0.75rem; font-size:0.875rem; }
.odoo-cust-wrap .btn-primary { background:#3b82f6; color:#fff; border:none; border-radius:8px; padding:0.4rem 1rem; cursor:pointer; font-size:0.875rem; }
.odoo-cust-wrap .btn-primary:hover { background:#2563eb; }
.odoo-cust-wrap .chip { background:#f3f4f6; border:1px solid #e5e7eb; border-radius:50px; padding:0.3rem 0.75rem; font-size:0.8rem; cursor:pointer; }
.odoo-cust-wrap .chip:hover { background:#e5e7eb; }
.odoo-cust-wrap .loading { display:flex; flex-direction:column; align-items:center; gap:0.5rem; padding:2rem; color:#6b7280; }
.odoo-cust-wrap .spin { animation: spin 1s linear infinite; display:inline-block; }
@keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
</style>

<div class="odoo-cust-wrap">
    <div class="content-card">
        <div class="content-title">
            <i class="bi bi-people"></i> รายการลูกค้า Odoo
            <span id="custTotalCount" style="font-size:0.8rem;color:#6b7280;margin-left:auto;"></span>
            <a href="/dashboard?tab=executive" style="margin-left:0.75rem;background:#4f46e5;color:#fff;border-radius:8px;padding:0.3rem 0.85rem;font-size:0.8rem;text-decoration:none;white-space:nowrap;"><i class="bi bi-graph-up-arrow"></i> Executive Dashboard</a>
            <a href="/dashboard?tab=odoo-overview" style="margin-left:0.4rem;background:#0891b2;color:#fff;border-radius:8px;padding:0.3rem 0.85rem;font-size:0.8rem;text-decoration:none;white-space:nowrap;"><i class="bi bi-broadcast"></i> Odoo Overview</a>
        </div>
        <div class="d-flex gap-2 mb-3" style="flex-wrap:wrap;">
            <input type="text" class="form-control" id="custSearch" placeholder="ค้นหาชื่อ / รหัสลูกค้า..." style="max-width:280px;" onkeyup="if(event.key==='Enter')loadCustomers()">
            <select class="form-control" id="custInvoiceFilter" onchange="loadCustomers()" style="max-width:180px;">
                <option value="">ทุกสถานะ</option>
                <option value="unpaid">มีค้างชำระ</option>
                <option value="overdue">เกินกำหนด</option>
            </select>
            <button class="btn-primary" onclick="loadCustomers()"><i class="bi bi-search"></i> ค้นหา</button>
            <button class="chip" onclick="resetCustomerFilter()"><i class="bi bi-x-circle"></i> ล้าง</button>
            <button class="chip" onclick="loadCustomers()"><i class="bi bi-arrow-repeat"></i> รีเฟรช</button>
        </div>
        <div id="customerList"><div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด...</div></div></div>
        <div id="customerPagination" class="d-flex justify-content-center gap-2 mt-3" style="display:none !important;"></div>
    </div>

    <!-- Customer Detail Modal -->
    <div id="customerInvoiceModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:flex-start;justify-content:center;overflow-y:auto;padding:2rem 1rem;">
        <div style="max-width:800px;width:100%;background:white;border-radius:16px;padding:1.5rem;position:relative;max-height:85vh;overflow-y:auto;margin:auto;">
            <button onclick="closeCustomerInvoiceModal()" style="position:absolute;top:1rem;right:1rem;background:none;border:none;font-size:1.5rem;cursor:pointer;color:#6b7280;">&times;</button>
            <h5 id="customerInvoiceTitle" style="margin-bottom:1rem;"><i class="bi bi-person-lines-fill"></i> ข้อมูลลูกค้า</h5>
            <div id="customerInvoiceContent"></div>
        </div>
    </div>
</div>

<!-- Odoo Unlink Modal (Dashboard) -->
<div id="dashUnlinkModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.55);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.2);width:100%;max-width:400px;margin:1rem;overflow:hidden;">
        <div style="background:#fef2f2;padding:1.25rem 1.5rem;border-bottom:1px solid #fecaca;display:flex;align-items:center;gap:0.75rem;">
            <div style="width:38px;height:38px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-unlink" style="color:#dc2626;font-size:1.1rem;"></i>
            </div>
            <div>
                <div style="font-weight:600;color:#1f2937;">ยืนยันการยกเลิกเชื่อมต่อ</div>
                <div style="font-size:0.78rem;color:#6b7280;">การดำเนินการนี้ไม่สามารถย้อนกลับได้</div>
            </div>
        </div>
        <div style="padding:1.25rem 1.5rem;">
            <div style="background:#f9fafb;border-radius:10px;padding:0.875rem;margin-bottom:1rem;font-size:0.875rem;">
                <div style="display:flex;justify-content:space-between;margin-bottom:0.4rem;">
                    <span style="color:#6b7280;">ลูกค้า</span>
                    <span id="dashUnlinkName" style="font-weight:600;color:#1f2937;text-align:right;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#6b7280;">LINE User ID</span>
                    <span id="dashUnlinkLineId" style="font-family:monospace;font-size:0.78rem;color:#374151;"></span>
                </div>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:0.75rem;font-size:0.78rem;color:#92400e;margin-bottom:1rem;">
                <i class="bi bi-exclamation-triangle-fill" style="margin-right:0.3rem;"></i>
                หลังยกเลิก ลูกค้าจะไม่ได้รับแจ้งเตือนจาก Odoo จนกว่าจะเชื่อมต่อใหม่
            </div>
            <div id="dashUnlinkError" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem;font-size:0.78rem;color:#dc2626;margin-bottom:1rem;">
                <i class="bi bi-x-circle-fill" style="margin-right:0.3rem;"></i>
                <span id="dashUnlinkErrorMsg"></span>
            </div>
        </div>
        <div style="padding:0 1.5rem 1.25rem;display:flex;gap:0.75rem;">
            <button id="dashUnlinkCancelBtn" onclick="closeDashUnlinkModal()"
                style="flex:1;padding:0.6rem;background:#f3f4f6;border:none;border-radius:10px;font-size:0.875rem;font-weight:500;cursor:pointer;color:#374151;">
                ยกเลิก
            </button>
            <button id="dashUnlinkConfirmBtn" onclick="confirmDashUnlink()"
                style="flex:1;padding:0.6rem;background:#dc2626;border:none;border-radius:10px;font-size:0.875rem;font-weight:600;cursor:pointer;color:white;display:flex;align-items:center;justify-content:center;gap:0.4rem;">
                <i id="dashUnlinkIcon" class="bi bi-unlink"></i>
                <span id="dashUnlinkBtnText">ยืนยันยกเลิกเชื่อมต่อ</span>
            </button>
        </div>
    </div>
</div>

<script src="odoo-dashboard.js?v=<?= $jsVersion ?>"></script>
<script>
/* Auto-load customers when this tab is shown */
document.addEventListener('DOMContentLoaded', function() {
    if (typeof loadCustomers === 'function') loadCustomers();
});

let _dashUnlinkLineUserId = '';
let _dashUnlinkSourceBtn = null;

function openDashUnlinkModal(lineUserId, custName, btn) {
    _dashUnlinkLineUserId = lineUserId;
    _dashUnlinkSourceBtn = btn || null;
    document.getElementById('dashUnlinkName').textContent = custName || '-';
    document.getElementById('dashUnlinkLineId').textContent = lineUserId || '-';
    document.getElementById('dashUnlinkError').style.display = 'none';
    document.getElementById('dashUnlinkConfirmBtn').disabled = false;
    document.getElementById('dashUnlinkCancelBtn').disabled = false;
    document.getElementById('dashUnlinkBtnText').textContent = 'ยืนยันยกเลิกเชื่อมต่อ';
    document.getElementById('dashUnlinkIcon').className = 'bi bi-unlink';
    const modal = document.getElementById('dashUnlinkModal');
    modal.style.display = 'flex';
}

function closeDashUnlinkModal() {
    document.getElementById('dashUnlinkModal').style.display = 'none';
}

document.getElementById('dashUnlinkModal').addEventListener('click', function(e) {
    if (e.target === this) closeDashUnlinkModal();
});

async function confirmDashUnlink() {
    const confirmBtn = document.getElementById('dashUnlinkConfirmBtn');
    const cancelBtn  = document.getElementById('dashUnlinkCancelBtn');
    const errBox     = document.getElementById('dashUnlinkError');
    const errMsg     = document.getElementById('dashUnlinkErrorMsg');

    confirmBtn.disabled = true;
    cancelBtn.disabled  = true;
    document.getElementById('dashUnlinkBtnText').textContent = 'กำลังดำเนินการ...';
    document.getElementById('dashUnlinkIcon').className = 'bi bi-arrow-repeat spin';
    errBox.style.display = 'none';

    try {
        const res = await fetch('api/odoo-user-link.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'unlink', line_user_id: _dashUnlinkLineUserId })
        });
        const json = await res.json();

        if (json.success) {
            closeDashUnlinkModal();
            // Update the row DOM immediately so badge reflects unlinked state
            if (_dashUnlinkSourceBtn) {
                const tr = _dashUnlinkSourceBtn.closest('tr');
                if (tr) {
                    // Replace LINE badge cell (5th td, index 5)
                    const tds = tr.querySelectorAll('td');
                    if (tds[5]) tds[5].innerHTML = '<span style="background:#f3f4f6;color:#9ca3af;padding:2px 7px;border-radius:50px;font-size:0.72rem;">ยังไม่</span>';
                    // Remove unlink button cell (last td)
                    if (tds[tds.length-1]) tds[tds.length-1].innerHTML = '';
                }
                _dashUnlinkSourceBtn = null;
            }
            // Also reload full list to sync
            setTimeout(() => loadCustomers(), 800);
        } else {
            errMsg.textContent = json.error || 'เกิดข้อผิดพลาด';
            errBox.style.display = 'block';
            confirmBtn.disabled = false;
            cancelBtn.disabled  = false;
            document.getElementById('dashUnlinkBtnText').textContent = 'ยืนยันยกเลิกเชื่อมต่อ';
            document.getElementById('dashUnlinkIcon').className = 'bi bi-unlink';
        }
    } catch (err) {
        errMsg.textContent = 'ไม่สามารถเชื่อมต่อ server ได้: ' + err.message;
        errBox.style.display = 'block';
        confirmBtn.disabled = false;
        cancelBtn.disabled  = false;
        document.getElementById('dashUnlinkBtnText').textContent = 'ยืนยันยกเลิกเชื่อมต่อ';
        document.getElementById('dashUnlinkIcon').className = 'bi bi-unlink';
    }
}
</script>
