/**
 * Odoo Dashboard Local API Integration Layer
 * Provides fast, local-only data access using new cache tables
 * 
 * This file extends odoo-dashboard.js with local API capabilities.
 * Add this script tag AFTER odoo-dashboard.js:
 * <script src="odoo-dashboard-local.js"></script>
 * 
 * @version 1.0.0
 * @created 2026-03-11
 */

// Local API Configuration
const LOCAL_API = {
    endpoint: 'api/odoo-dashboard-local.php',
    enabled: false,
    fallbackToWebhook: true, // Fallback to old API if local fails
    cacheTTL: 30000 // 30 seconds client-side cache
};

function isLocalApiOptedIn() {
    if (typeof window === 'undefined') return false;
    if (window.ENABLE_LOCAL_DASHBOARD === true) return true;
    if (document.body && document.body.dataset.enableLocalApi === '1') return true;
    const params = new URLSearchParams(window.location.search || '');
    return params.get('local_api') === '1';
}

// Local API client
async function localApiCall(action, params = {}) {
    const payload = { action, ...params, _t: Date.now() };
    
    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 10000); // 10s timeout for local
        
        const r = await fetch(LOCAL_API.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: ctrl.signal
        });
        
        clearTimeout(timer);
        
        if (!r.ok) throw new Error('HTTP ' + r.status);
        
        const data = await r.json();
        return data;
    } catch (e) {
        return { success: false, error: e.message, local: true };
    }
}

// Check if local tables are available
async function checkLocalApiAvailable() {
    const res = await localApiCall('health');
    const available = res.success && res.data && res.data.status === 'ok';
    
    // Check if tables exist and have data
    if (available && res.data.local_tables) {
        const tables = res.data.local_tables;
        const hasData = Object.values(tables).some(t => t.exists && t.count > 0);
        return { available, hasData, tables };
    }
    
    return { available, hasData: false, tables: {} };
}

// ============================================
// REPLACEMENT FUNCTIONS (override original)
// ============================================

// Store original functions
const _original = {
    loadTodayOverview: typeof loadTodayOverview === 'function' ? loadTodayOverview : null,
    loadCustomers: typeof loadCustomers === 'function' ? loadCustomers : null,
    loadSlips: typeof loadSlips === 'function' ? loadSlips : null,
    loadWebhookStats: typeof loadWebhookStats === 'function' ? loadWebhookStats : null,
    showCustomerDetail: typeof showCustomerDetail === 'function' ? showCustomerDetail : null,
    showOrderTimeline: typeof showOrderTimeline === 'function' ? showOrderTimeline : null,
    globalSearch: typeof globalSearch === 'function' ? globalSearch : null
};

function getCustomerOffsetLocal() {
    if (typeof custCurrentOffset !== 'undefined') return custCurrentOffset;
    return window.custCurrentOffset || 0;
}

function setCustomerOffsetLocal(value) {
    if (typeof custCurrentOffset !== 'undefined') {
        custCurrentOffset = value;
    }
    window.custCurrentOffset = value;
}

// Override: Load Today's Overview (KPI cards)
async function loadTodayOverviewLocal() {
    // Try local API first
    const res = await localApiCall('overview_kpi');
    
    if (!res.success || !res.data) {
        console.log('[LocalAPI] Overview KPI not available, using fallback');
        if (_original.loadTodayOverview) return _original.loadTodayOverview();
        return;
    }
    
    const kpi = res.data;
    
    // Update KPI cards
    const kpiOrdersToday = document.getElementById('kpiOrdersToday');
    const kpiSalesToday = document.getElementById('kpiSalesToday');
    const kpiSlipsPending = document.getElementById('kpiSlipsPending');
    const kpiBdosPending = document.getElementById('kpiBdosPending');
    const kpiPaymentsToday = document.getElementById('kpiPaymentsToday');
    const kpiOverdueCustomers = document.getElementById('kpiOverdueCustomers');
    
    if (kpiOrdersToday) kpiOrdersToday.textContent = Number(kpi.orders?.today || 0).toLocaleString();
    if (kpiSalesToday) kpiSalesToday.textContent = '฿' + Number(kpi.revenue?.today || 0).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
    if (kpiSlipsPending) kpiSlipsPending.textContent = Number(kpi.slips?.pending || 0).toLocaleString();
    if (kpiOverdueCustomers) kpiOverdueCustomers.textContent = Number(kpi.invoices?.overdue || 0).toLocaleString();
    if (kpiBdosPending) kpiBdosPending.textContent = Number(kpi.bdos?.pending || 0).toLocaleString();
    if (kpiPaymentsToday) {
        const matchedToday = Number(kpi.slips?.matched_today || 0);
        kpiPaymentsToday.textContent = matchedToday > 0 ? matchedToday.toLocaleString() : '-';
    }
    
    // Update lists
    await Promise.all([
        loadRecentOrdersLocal(),
        loadPendingSlipsLocal(),
        loadOverdueCustomersLocal()
    ]);
    
    console.log('[LocalAPI] Overview loaded from local cache');
}

// Load recent orders for overview
async function loadRecentOrdersLocal() {
    const res = await localApiCall('orders_today');
    if (!res.success) return;
    
    const container = document.getElementById('overviewRecentOrders');
    if (!container) return;
    
    const orders = res.data.orders || [];
    if (orders.length === 0) {
        container.innerHTML = '<p style="color:var(--gray-400);padding:1rem;text-align:center;">ยังไม่มีออเดอร์วันนี้</p>';
        return;
    }
    
    let html = '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
    orders.forEach(o => {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem;border-radius:6px;background:#f8fafc;">
            <div style="display:flex;flex-direction:column;">
                <span style="font-size:0.85rem;font-weight:600;color:var(--gray-800);">${escapeHtml(o.order_key)}</span>
                <span style="font-size:0.75rem;color:var(--gray-500);">${escapeHtml(o.customer_name || '-')}</span>
            </div>
            <div style="text-align:right;">
                <span style="font-size:0.85rem;font-weight:600;color:var(--success);">฿${Number(o.amount_total || 0).toLocaleString()}</span>
                <span style="font-size:0.7rem;color:var(--gray-400);display:block;">${escapeHtml(o.state_display || o.state || '-')}</span>
            </div>
        </div>`;
    });
    html += '</div>';
    
    container.innerHTML = html;
}

// Load pending slips for overview
async function loadPendingSlipsLocal() {
    const res = await localApiCall('slips_pending');
    if (!res.success) return;
    
    const container = document.getElementById('overviewPendingSlips');
    if (!container) return;
    
    const slips = res.data.slips || [];
    if (slips.length === 0) {
        container.innerHTML = '<p style="color:var(--gray-400);padding:1rem;text-align:center;">ไม่มีสลิปรอดำเนินการ</p>';
        return;
    }
    
    let html = '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
    slips.slice(0, 5).forEach(s => {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem;border-radius:6px;background:#fffbeb;">
            <div style="display:flex;flex-direction:column;">
                <span style="font-size:0.8rem;font-weight:500;color:var(--gray-800);">${escapeHtml(s.customer_name || '-')}</span>
                <span style="font-size:0.7rem;color:var(--gray-500);">${escapeHtml(s.slip_id)}</span>
            </div>
            <div style="text-align:right;">
                <span style="font-size:0.8rem;font-weight:600;color:#d97706;">฿${Number(s.amount || 0).toLocaleString()}</span>
                <span style="font-size:0.7rem;color:var(--gray-400);display:block;">${escapeHtml(s.payment_date || '-')}</span>
            </div>
        </div>`;
    });
    if (slips.length > 5) {
        html += `<div style="text-align:center;font-size:0.75rem;color:var(--gray-400);padding:0.5rem;">+${slips.length - 5} รายการอื่น</div>`;
    }
    html += '</div>';
    
    container.innerHTML = html;
}

// Load overdue customers for overview
async function loadOverdueCustomersLocal() {
    const res = await localApiCall('invoices_overdue');
    if (!res.success) return;
    
    const container = document.getElementById('overviewOverdueCustomers');
    if (!container) return;
    
    const invoices = res.data.invoices || [];
    if (invoices.length === 0) {
        container.innerHTML = '<p style="color:var(--gray-400);padding:1rem;text-align:center;">ไม่มีลูกค้าค้างชำระ</p>';
        return;
    }
    
    let html = '<div style="display:flex;flex-direction:column;gap:0.5rem;">';
    invoices.slice(0, 5).forEach(inv => {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem;border-radius:6px;background:#fee2e2;">
            <div style="display:flex;flex-direction:column;">
                <span style="font-size:0.8rem;font-weight:500;color:var(--gray-800);">${escapeHtml(inv.customer_name || '-')}</span>
                <span style="font-size:0.7rem;color:#dc2626;">${escapeHtml(inv.invoice_number)} · ${inv.days_overdue} วันเกินกำหนด</span>
            </div>
            <div style="text-align:right;">
                <span style="font-size:0.8rem;font-weight:600;color:#dc2626;">฿${Number(inv.amount_residual || 0).toLocaleString()}</span>
            </div>
        </div>`;
    });
    if (invoices.length > 5) {
        html += `<div style="text-align:center;font-size:0.75rem;color:var(--gray-400);padding:0.5rem;">+${invoices.length - 5} รายการอื่น</div>`;
    }
    html += '</div>';
    
    container.innerHTML = html;
}

// Override: Load Customers List
async function loadCustomersLocal() {
    const c = document.getElementById('customerList');
    if (!c) return;
    
    c.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด (local)...</div></div>';
    
    const invoiceFilter = document.getElementById('custInvoiceFilter')?.value || '';
    const sortBy = document.getElementById('custSortBy')?.value || '';
    const salespersonId = document.getElementById('custSalesperson')?.value || '';
    const search = document.getElementById('custSearch')?.value || '';
    
    const res = await localApiCall('customers_list', {
        limit: 30,
        offset: getCustomerOffsetLocal(),
        search,
        invoice_filter: invoiceFilter,
        sort_by: sortBy,
        salesperson_id: salespersonId
    });
    
    if (!res.success || !res.data) {
        // Fallback to original
        console.log('[LocalAPI] Customers local failed, using fallback');
        if (_original.loadCustomers) return _original.loadCustomers();
        c.innerHTML = '<p style="padding:1rem;color:var(--danger);">Error loading customers</p>';
        return;
    }
    
    const { customers, total } = res.data;
    
    const tc = document.getElementById('custTotalCount');
    if (tc) tc.textContent = Number(total || 0).toLocaleString() + ' รายการ';
    
    if (!customers || customers.length === 0) {
        c.innerHTML = '<p style="padding:1rem;color:var(--gray-500);text-align:center;">ไม่พบข้อมูลลูกค้า</p>';
        return;
    }
    
    // Render customer table
    let html = '<table class="data-table" style="width:100%;"><thead><tr>';
    html += '<th>ลูกค้า</th><th>รหัส</th><th>Partner ID</th><th>ยอดรวม</th><th>ออเดอร์</th><th>พนักงานขาย</th><th>LINE</th><th>ยอดค้าง/เกินกำหนด</th><th>การจัดการ</th>';
    html += '</tr></thead><tbody>';
    
    customers.forEach(cu => {
        const hasLine = cu.line_user_id ? 'เชื่อม' : 'ยังไม่';
        const lineBadge = cu.line_user_id 
            ? '<span style="background:#dcfce7;color:#16a34a;padding:2px 7px;border-radius:50px;font-size:0.72rem;">เชื่อม</span>'
            : '<span style="background:#f3f4f6;color:#9ca3af;padding:2px 7px;border-radius:50px;font-size:0.72rem;">ยังไม่</span>';
        
        const overdueBadge = cu.overdue_amount > 0
            ? `<span style="background:#fee2e2;color:#dc2626;padding:2px 7px;border-radius:50px;font-size:0.72rem;">฿${Number(cu.overdue_amount).toLocaleString()}</span>`
            : (cu.total_due > 0 ? `<span style="background:#fef3c7;color:#d97706;padding:2px 7px;border-radius:50px;font-size:0.72rem;">฿${Number(cu.total_due).toLocaleString()}</span>` : '-');
        
        html += `<tr style="cursor:pointer;" onclick="showCustomerDetail('${escapeHtml(cu.customer_ref || '')}', '${escapeHtml(cu.partner_id || cu.customer_id || '')}', '${escapeHtml(cu.customer_name || '')}')">`;
        html += `<td><strong>${escapeHtml(cu.customer_name || '-')}</strong></td>`;
        html += `<td>${escapeHtml(cu.customer_ref || '-')}</td>`;
        html += `<td>${escapeHtml(cu.partner_id || '-')}</td>`;
        html += `<td style="text-align:right;">฿${Number(cu.spend_30d || 0).toLocaleString()}</td>`;
        html += `<td>${cu.orders_count_30d || 0} / ${cu.orders_count_total || 0}</td>`;
        html += `<td>${escapeHtml(cu.salesperson_name || '-')}</td>`;
        html += `<td>${lineBadge}</td>`;
        html += `<td>${overdueBadge}</td>`;
        html += `<td><button class="chip" onclick="event.stopPropagation();showCustomerDetail('${escapeHtml(cu.customer_ref || '')}', '${escapeHtml(cu.partner_id || cu.customer_id || '')}', '${escapeHtml(cu.customer_name || '')}')">รายละเอียด</button></td>`;
        html += '</tr>';
    });
    
    html += '</tbody></table>';
    
    // Pagination
    const totalPages = Math.ceil(total / 30);
    const currentPage = Math.floor(getCustomerOffsetLocal() / 30) + 1;
    
    if (totalPages > 1) {
        html += '<div style="display:flex;justify-content:center;gap:0.5rem;margin-top:1rem;">';
        for (let i = 1; i <= Math.min(totalPages, 10); i++) {
            const active = i === currentPage ? 'background:var(--primary);color:white;' : 'background:var(--gray-100);color:var(--gray-600);';
            html += `<button onclick="setCustomerOffsetLocal(${(i-1)*30});loadCustomersLocal()" style="${active}border:none;border-radius:6px;padding:4px 12px;cursor:pointer;font-size:0.8rem;">${i}</button>`;
        }
        html += '</div>';
    }
    
    c.innerHTML = html;
    console.log('[LocalAPI] Customers loaded from local cache');
}

// Override: Load Slips List
async function loadSlipsLocal() {
    const el = document.getElementById('slipList');
    if (!el) return;

    const cacheKey = typeof _dashCacheKey === 'function'
        ? _dashCacheKey('slips-local', JSON.stringify({
            o: typeof slipCurrentOffset !== 'undefined' ? slipCurrentOffset : (window.slipCurrentOffset || 0),
            q: document.getElementById('slipSearch')?.value || '',
            s: document.getElementById('slipStatusFilter')?.value || '',
            d: document.getElementById('slipDateFilter')?.value || ''
        }))
        : null;
    const cached = cacheKey ? _cacheGet(cacheKey) : null;
    if (cacheKey && cached && typeof _dashRenderFromCache === 'function'
        && _dashRenderFromCache('slipList', cached.html, { cachedAt: cached.cachedAt, refreshFn: '_cacheClear(\'dash:slips-local\');loadSlips()' })) {
        return;
    }

    el.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด (local)...</div></div>';

    const limit = typeof slipPageSize !== 'undefined' ? slipPageSize : 30;
    const offset = typeof slipCurrentOffset !== 'undefined' ? slipCurrentOffset : (window.slipCurrentOffset || 0);
    const search = document.getElementById('slipSearch')?.value || '';
    const status = document.getElementById('slipStatusFilter')?.value || '';
    const date = document.getElementById('slipDateFilter')?.value || '';

    const res = await localApiCall('slips_list', { limit, offset, search, status, date });
    if (!res.success || !res.data) {
        console.log('[LocalAPI] Slips local failed, using fallback');
        if (_original.loadSlips) return _original.loadSlips();
        el.innerHTML = '<p style="padding:1rem;color:var(--danger);">Error loading slips</p>';
        return;
    }

    const slips = res.data.slips || [];
    const total = Number(res.data.total || 0);
    const tc = document.getElementById('slipTotalCount');
    if (tc) tc.textContent = total.toLocaleString() + ' รายการ';

    if (slips.length === 0) {
        el.innerHTML = '<p style="color:var(--gray-500);padding:1.5rem;text-align:center;"><i class="bi bi-inbox" style="font-size:2rem;"></i><br>ไม่พบสลิป</p>';
        return;
    }

    window._slipErrors = window._slipErrors || {};
    window._slipMeta = window._slipMeta || {};

    let html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:0.875rem;"><thead><tr style="background:var(--gray-50);">';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">รูปสลิป</th>';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">ลูกค้า</th>';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">จำนวนเงิน</th>';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">สถานะ</th>';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">ออเดอร์/ใบแจ้งหนี้</th>';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">บันทึกโดย</th>';
    html += '<th style="padding:10px 12px;text-align:left;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">วันที่บันทึก</th>';
    html += '<th style="padding:10px 12px;text-align:center;font-weight:600;color:var(--gray-600);border-bottom:2px solid var(--gray-200);">การดำเนินการ</th>';
    html += '</tr></thead><tbody>';

    slips.forEach((s, i) => {
        const bg = i % 2 === 0 ? 'white' : 'var(--gray-50)';
        const amt = s.amount != null ? '฿' + parseFloat(s.amount).toLocaleString('th-TH', { minimumFractionDigits: 2 }) : '-';
        const dt = s.uploaded_at ? new Date(s.uploaded_at).toLocaleString('th-TH', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';
        const thumb = s.image_full_url
            ? '<img src="' + escapeHtml(s.image_full_url) + '" onclick="openSlipPreview(\'' + escapeHtml(s.image_full_url) + '\')" style="width:48px;height:60px;object-fit:cover;border-radius:6px;cursor:pointer;border:1px solid var(--gray-200);" onerror="this.style.display=\'none\'">'
            : '<span style="color:var(--gray-400);font-size:0.75rem;">ไม่มีรูป</span>';
        const custName = escapeHtml(s.customer_name || s.line_user_id || '-');
        const custLine = s.customer_name ? '<div style="font-size:0.75rem;color:var(--gray-400);">' + escapeHtml(s.line_user_id || '') + '</div>' : '';
        if (s.status === 'failed') window._slipErrors[s.id] = s.match_reason || 'ไม่มีข้อมูล';
        window._slipMeta[s.id] = {
            line_user_id: s.line_user_id,
            line_account_id: s.line_account_id,
            amount: s.amount,
            status: s.status,
            customer_name: s.customer_name || s.line_user_id,
            slip_inbox_id: s.slip_inbox_id || s.odoo_slip_id || 0
        };

        let actionBtn = '';
        if (s.status === 'pending') {
            actionBtn = '<div style="display:flex;flex-direction:column;gap:4px;align-items:center;">'
                + '<button id="slip-btn-' + s.id + '" class="chip" onclick="sendOneSlipToOdoo(' + s.id + ',false)" style="font-size:0.75rem;padding:3px 10px;white-space:nowrap;"><i class="bi bi-cloud-upload"></i> ส่ง Odoo</button>'
                + '</div>';
        } else if (s.status === 'matched') {
            actionBtn = '<div style="display:flex;flex-direction:column;gap:4px;align-items:center;">'
                + '<span style="color:#16a34a;font-size:0.75rem;">✓ ส่งแล้ว</span>'
                + '<button class="chip" onclick="unMatchSlip(' + s.id + ')" style="font-size:0.7rem;padding:2px 6px;border-color:#6b7280;color:#6b7280;" title="รีเซ็ตกลับเป็น pending"><i class="bi bi-arrow-counterclockwise"></i> รีเซ็ต</button>'
                + '</div>';
        } else {
            actionBtn = '<div style="display:flex;flex-direction:column;gap:3px;align-items:center;">'
                + '<span style="color:#dc2626;font-size:0.72rem;cursor:pointer;text-decoration:underline;" onclick="showSlipError(' + s.id + ');">[ดูข้อผิดพลาด]</span>'
                + '<button id="slip-btn-' + s.id + '" class="chip" onclick="sendOneSlipToOdoo(' + s.id + ',true)" style="font-size:0.72rem;padding:2px 8px;border-color:#dc2626;color:#dc2626;white-space:nowrap;"><i class="bi bi-arrow-clockwise"></i> ส่งซ้ำ</button>'
                + '</div>';
        }

        html += '<tr style="background:' + bg + ';border-bottom:1px solid var(--gray-100);" id="slip-row-' + s.id + '">'
            + '<td style="padding:10px 12px;">' + thumb + '</td>'
            + '<td style="padding:10px 12px;"><div style="font-weight:500;">' + custName + '</div>' + custLine + '</td>'
            + '<td style="padding:10px 12px;font-weight:600;color:#16a34a;">' + amt + '</td>'
            + '<td style="padding:10px 12px;">' + slipStatusBadge(s.status) + '</td>'
            + '<td style="padding:10px 12px;" id="slip-orders-' + s.id + '">' + slipOrderInfo(s) + '</td>'
            + '<td style="padding:10px 12px;color:var(--gray-500);font-size:0.8rem;">' + escapeHtml(s.uploaded_by || '-') + '</td>'
            + '<td style="padding:10px 12px;color:var(--gray-500);font-size:0.8rem;">' + dt + '</td>'
            + '<td style="padding:10px 12px;text-align:center;" id="slip-action-' + s.id + '">' + actionBtn + '</td>'
            + '</tr>';
    });

    html += '</tbody></table></div>';
    el.innerHTML = html;
    if (cacheKey && typeof _dashCacheSaveHtml === 'function') {
        _dashCacheSaveHtml(cacheKey, 'slipList', '_cacheClear(\'dash:slips-local\');loadSlips()');
    }

    const pag = document.getElementById('slipPagination');
    if (pag) {
        if (total > limit) {
            const tp = Math.ceil(total / limit);
            const cp = Math.floor(offset / limit) + 1;
            let ph = cp > 1 ? '<button class="chip" onclick="slipGoPage(' + (cp - 2) + ')"><i class="bi bi-chevron-left"></i></button>' : '';
            ph += '<span style="padding:0.5rem 1rem;font-size:0.85rem;">หน้า ' + cp + ' / ' + tp + '</span>';
            if (cp < tp) ph += '<button class="chip" onclick="slipGoPage(' + cp + ')"><i class="bi bi-chevron-right"></i></button>';
            pag.innerHTML = ph;
        } else {
            pag.innerHTML = '';
        }
    }
}

// Override: Show Customer Detail
async function showCustomerDetailLocal(customerRef, partnerId, custName) {
    const modal = document.getElementById('customerInvoiceModal');
    const content = document.getElementById('customerInvoiceContent');
    const title = document.getElementById('customerInvoiceTitle');
    
    if (!modal || !content) return;
    
    modal.style.display = 'flex';
    content.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด (local)...</div></div>';
    
    const resolvedCustomerRef = customerRef || '';
    const resolvedPartnerId = partnerId || '';
    const res = await localApiCall('customer_detail', {
        customer_ref: resolvedCustomerRef,
        partner_id: resolvedPartnerId
    });
    
    if (!res.success || !res.data) {
        // Fallback to original implementation
        console.log('[LocalAPI] Customer detail local failed, using fallback');
        if (_original.showCustomerDetail) {
            return _original.showCustomerDetail(customerRef, partnerId, custName);
        }
        content.innerHTML = '<p style="padding:1rem;color:var(--danger);">Error loading customer detail</p>';
        return;
    }
    
    const data = res.data;
    const profile = data.profile || {};
    
    if (title) title.innerHTML = `<i class="bi bi-person-lines-fill"></i> ${escapeHtml(profile.customer_name || custName || 'รายละเอียดลูกค้า')}`;
    
    let html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">';
    html += `<div class="info-box"><div class="info-label">รหัสลูกค้า</div><div class="info-value">${escapeHtml(profile.customer_ref || '-')}</div></div>`;
    html += `<div class="info-box"><div class="info-label">Partner ID</div><div class="info-value">${escapeHtml(profile.partner_id || profile.customer_id || '-')}</div></div>`;
    html += `<div class="info-box"><div class="info-label">ยอดซื้อรวม</div><div class="info-value">฿${Number(profile.spend_total || 0).toLocaleString()}</div></div>`;
    html += `<div class="info-box"><div class="info-label">ออเดอร์รวม</div><div class="info-value">${profile.orders_count_total || 0}</div></div>`;
    html += '</div>';
    
    // Recent orders
    if (data.orders && data.orders.length > 0) {
        html += '<h6 style="margin:1rem 0 0.5rem;font-weight:600;"><i class="bi bi-box-seam"></i> ออเดอร์ล่าสุด</h6>';
        html += '<div style="max-height:200px;overflow-y:auto;">';
        data.orders.slice(0, 10).forEach(o => {
            html += `<div style="display:flex;justify-content:space-between;padding:0.5rem;border-bottom:1px solid var(--gray-100);">
                <span>${escapeHtml(o.order_key)} · ${escapeHtml(o.customer_name || '-')}</span>
                <span style="font-weight:600;">฿${Number(o.amount_total || 0).toLocaleString()}</span>
            </div>`;
        });
        html += '</div>';
    }
    
    // Invoices
    if (data.invoices && data.invoices.length > 0) {
        html += '<h6 style="margin:1rem 0 0.5rem;font-weight:600;"><i class="bi bi-receipt"></i> ใบแจ้งหนี้</h6>';
        html += '<div style="max-height:200px;overflow-y:auto;">';
        data.invoices.forEach(inv => {
            const statusColor = inv.is_overdue ? '#dc2626' : (inv.state === 'paid' ? '#16a34a' : '#d97706');
            html += `<div style="display:flex;justify-content:space-between;padding:0.5rem;border-bottom:1px solid var(--gray-100);">
                <span>${escapeHtml(inv.invoice_number)}${inv.is_overdue ? ` <span style="color:#dc2626;font-size:0.75rem;">(${inv.days_overdue} วัน)</span>` : ''}</span>
                <span style="font-weight:600;color:${statusColor};">฿${Number(inv.amount_residual || 0).toLocaleString()}</span>
            </div>`;
        });
        html += '</div>';
    }
    
    content.innerHTML = html;
    console.log('[LocalAPI] Customer detail loaded from local cache');
}

async function showOrderTimelineLocal(orderId, orderName) {
    const modal = document.getElementById('orderTimelineModal');
    const content = document.getElementById('orderTimelineContent');
    if (!modal || !content) return;

    content.innerHTML = '<div class="loading"><i class="bi bi-arrow-repeat spin"></i><div>กำลังโหลด (local)...</div></div>';
    modal.classList.add('active');

    const res = await localApiCall('order_timeline', {
        order_id: orderId || '',
        order_key: orderName || ''
    });

    if (!res.success || !res.data) {
        console.log('[LocalAPI] Timeline local failed, using fallback');
        if (_original.showOrderTimeline) return _original.showOrderTimeline(orderId, orderName);
        content.innerHTML = '<p style="color:var(--danger);">Error loading timeline</p>';
        return;
    }

    const events = res.data.events || [];
    const orderTitle = res.data.order_name || orderName || orderId || '-';
    let html = '<h5 style="margin-bottom:1rem;"><i class="bi bi-clock-history"></i> Timeline: ' + escapeHtml(orderTitle) + '</h5>';
    if (!events.length) {
        html += '<p style="color:var(--gray-400);">ไม่พบข้อมูล</p>';
    } else {
        html += '<div style="position:relative;padding-left:24px;border-left:3px solid var(--gray-200);margin-left:8px;">';
        events.forEach((e, i) => {
            const et = String(e.event_type || '');
            const icon = EVENT_ICONS[et] || '📌';
            const pd = e.processed_at ? new Date(e.processed_at) : null;
            const t = pd && !isNaN(pd) ? pd.toLocaleString('th-TH') : '-';
            const state = e.new_state_display && e.new_state_display !== 'null' ? e.new_state_display : (et ? et.split('.').pop() : '-');
            const dot = i === events.length - 1 ? 'var(--primary)' : 'var(--gray-400)';
            html += '<div style="position:relative;margin-bottom:1.5rem;padding-left:16px;">'
                + '<div style="position:absolute;left:-32px;top:2px;width:16px;height:16px;border-radius:50%;background:' + dot + ';border:3px solid white;box-shadow:0 0 0 2px ' + dot + ';"></div>'
                + '<div style="font-weight:600;font-size:0.9rem;">' + icon + ' ' + escapeHtml(state) + '</div>'
                + '<div style="font-size:0.8rem;color:var(--gray-500);margin-top:2px;">' + escapeHtml(t) + '</div>'
                + '<div style="font-size:0.75rem;color:var(--gray-400);">' + escapeHtml(et) + ' &middot; ' + escapeHtml(e.status || '-') + '</div>'
                + '</div>';
        });
        html += '</div>';
    }

    content.innerHTML = html;
}

// Override: Global Search
async function globalSearchLocal(query) {
    if (!query || query.length < 2) return { results: [], total: 0 };
    
    const res = await localApiCall('search_global', { q: query, limit: 20 });
    
    if (!res.success) {
        if (_original.globalSearch) {
            return _original.globalSearch(query);
        }
        return { results: [], total: 0, error: res.error };
    }
    
    return res.data || { results: [], total: 0 };
}

// ============================================
// INITIALIZATION
// ============================================

// Auto-detect and switch to local API when available
async function initLocalApi() {
    if (!LOCAL_API.enabled && !isLocalApiOptedIn()) {
        console.log('[LocalAPI] Auto-init disabled - using primary dashboard runtime');
        return false;
    }

    const status = await checkLocalApiAvailable();
    
    console.log('[LocalAPI] Status:', status);
    
    if (status.available && status.hasData) {
        console.log('[LocalAPI] Local tables available with data - enabling local mode');
        
        // Override global functions
        if (typeof window !== 'undefined') {
            window.loadTodayOverview = loadTodayOverviewLocal;
            window.loadCustomers = loadCustomersLocal;
            window.loadSlips = loadSlipsLocal;
            window.showCustomerDetail = showCustomerDetailLocal;
            window.showOrderTimeline = showOrderTimelineLocal;
            window.globalSearch = globalSearchLocal;
            
            console.log('[LocalAPI] Functions overridden to use local cache');
        }

        if (document.getElementById('section-overview')?.classList.contains('active')) {
            window.loadTodayOverview();
        }
        if (document.getElementById('section-customers')?.classList.contains('active')) {
            window.loadCustomers();
        }
        if (document.getElementById('section-slips')?.classList.contains('active')) {
            window.loadSlips();
        }
        
        return true;
    } else if (status.available && !status.hasData) {
        console.log('[LocalAPI] Local tables exist but empty - need sync');
        return false;
    } else {
        console.log('[LocalAPI] Local tables not available - using fallback');
        return false;
    }
}

// Auto-init when DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLocalApi);
} else {
    initLocalApi();
}

// Expose for manual control
window.LocalApi = {
    call: localApiCall,
    check: checkLocalApiAvailable,
    init: initLocalApi,
    config: LOCAL_API
};

console.log('[LocalAPI] Module loaded - v1.0.0');
