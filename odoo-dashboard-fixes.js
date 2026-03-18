s:', {
                domContentLoaded: Math.round(perfData.domContentLoadedEventEnd - perfData.domContentLoadedEventStart) + 'ms',
                loadComplete: Math.round(perfData.loadEventEnd - perfData.loadEventStart) + 'ms',
                total: Math.round(perfData.loadEventEnd - perfData.fetchStart) + 'ms'
            });
        }
    });
});

console.log('[Dashboard] Fixes loaded successfully ✓');
'DOMContentLoaded', function(){
    console.log('[Dashboard] Initializing optimizations...');
    
    // Test connection then warm caches
    if(typeof testConnection === 'function'){
        testConnection().then(() => {
            setTimeout(warmCriticalCaches, 500);
        });
    }
    
    // Log page load performance
    window.addEventListener('load', () => {
        const perfData = performance.getEntriesByType('navigation')[0];
        if(perfData){
            console.log('[Perf] Page load metricTORING =====
window.perfMonitor = {
    marks: {},
    
    start(label){
        this.marks[label] = performance.now();
    },
    
    end(label){
        if(!this.marks[label]) return null;
        const duration = performance.now() - this.marks[label];
        delete this.marks[label];
        
        if(duration > 1000){
            console.warn(`[Perf] ${label} took ${duration.toFixed(0)}ms`);
        }
        
        return duration;
    }
};

// ===== 9. INITIALIZATION =====
document.addEventListener( 60px;
    border-radius: 8px;
    margin-bottom: 8px;
}

.skeleton-card {
    height: 120px;
    border-radius: 12px;
}

/* Performance optimizations */
.menu-card, .kpi-card, .chip {
    will-change: transform;
}

.section-panel {
    contain: layout style paint;
}
`;

// Inject skeleton CSS
document.addEventListener('DOMContentLoaded', function(){
    const style = document.createElement('style');
    style.textContent = skeletonCSS;
    document.head.appendChild(style);
});

// ===== 8. PERFORMANCE MONIf loadSystemHealth === 'function' && loadSystemHealth()
    };

    const loader = loaders[sectionId];
    if(loader) loader();
}

// ===== 7. LOADING SKELETON CSS =====
const skeletonCSS = `
.skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s ease-in-out infinite;
}

@keyframes skeleton-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.skeleton-row {
    height:       if(typeof _sectionNeedsLoad === 'function' && _sectionNeedsLoad('notifications')){
                if(typeof loadNotifications === 'function') loadNotifications();
                if(typeof _sectionMarkLoaded === 'function') _sectionMarkLoaded('notifications');
            }
        },
        'daily-summary': () => {
            if(typeof dailySummaryData !== 'undefined' && dailySummaryData.length === 0 && typeof loadDailySummary === 'function') loadDailySummary();
        },
        'health': () => typeoion') _sectionMarkLoaded('slips');
            }
        },
        'matching': () => {
            if(typeof loadSalespersonDropdown === 'function') loadSalespersonDropdown();
            if(typeof _sectionNeedsLoad === 'function' && _sectionNeedsLoad('matching')){
                if(typeof loadMatchingCustomerGrid === 'function') loadMatchingCustomerGrid();
                if(typeof _sectionMarkLoaded === 'function') _sectionMarkLoaded('matching');
            }
        },
        'notifications': () => {
               if(typeof _sectionNeedsLoad === 'function' && _sectionNeedsLoad('customers')){
                if(typeof loadCustomers === 'function') loadCustomers();
                if(typeof _sectionMarkLoaded === 'function') _sectionMarkLoaded('customers');
            }
        },
        'slips': () => {
            if(typeof _sectionNeedsLoad === 'function' && _sectionNeedsLoad('slips')){
                if(typeof loadSlips === 'function') loadSlips();
                if(typeof _sectionMarkLoaded === 'functrkLoaded === 'function') _sectionMarkLoaded('webhooks');
            }
            if(forceListMode && typeof setWhViewMode === 'function') setWhViewMode('list');
            else if(typeof whViewMode !== 'undefined' && whViewMode === 'grouped' && typeof loadOrdersGrouped === 'function') loadOrdersGrouped();
            else if(typeof loadWebhooks === 'function') loadWebhooks();
        },
        'customers': () => {
            if(typeof loadSalespersonDropdown === 'function') loadSalespersonDropdown();
   const menuCard = document.querySelector(`.menu-card[onclick*="showSection('${id}')"]`);
    if(menuCard) menuCard.classList.add('active');

    // Simplified loaders
    const loaders = {
        'overview': () => typeof loadTodayOverview === 'function' && loadTodayOverview(),
        'webhooks': () => {
            if(typeof _sectionNeedsLoad === 'function' && _sectionNeedsLoad('webhooks')){
                if(typeof loadWebhookStats === 'function') loadWebhookStats();
                if(typeof _sectionMa showSectionOptimized(id){
    let sectionId = id;
    let forceListMode = false;
    if(id === 'webhooks-raw'){ 
        sectionId = 'webhooks'; 
        forceListMode = true; 
    }

    // Batch DOM updates
    document.querySelectorAll('.section-panel').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.menu-card').forEach(c => c.classList.remove('active'));
    
    const panel = document.getElementById('section-' + sectionId);
    if(panel) panel.classList.add('active');
    
   og('[Cache] Warming overview...');
            if(typeof loadTodayOverview === 'function') loadTodayOverview();
        }
    }
    
    // Warm matching grid if not cached
    if(typeof _cacheGet === 'function' && !_cacheGet('match_grid')){
        console.log('[Cache] Warming matching grid...');
        if(typeof loadMatchingCustomerGrid === 'function') loadMatchingCustomerGrid();
    }
}

// ===== 6. OPTIMIZED SECTION NAVIGATION =====
// Simplified version - replace existing showSection if needed
functionncedLoadOrdersGrouped = debounce(function(){
    if(typeof grpCurrentOffset !== 'undefined') grpCurrentOffset = 0;
    if(typeof loadOrdersGrouped === 'function') loadOrdersGrouped();
}, 400);

// ===== 5. CACHE WARMING =====
async function warmCriticalCaches(){
    console.log('[Cache] Warming critical caches...');
    
    // Warm overview if not cached
    if(typeof _cacheGet === 'function' && typeof _dashCacheKey === 'function'){
        if(!_cacheGet(_dashCacheKey('overview', 'today'))){
            console.lpCurrentOffset !== 'undefined') slipCurrentOffset = 0;
    if(typeof loadSlips === 'function') loadSlips();
}, 300);

window.debouncedLoadCustomers = debounce(function(){
    if(typeof custCurrentOffset !== 'undefined') custCurrentOffset = 0;
    if(typeof loadCustomers === 'function') loadCustomers();
}, 500);

window.debouncedLoadWebhooks = debounce(function(){
    if(typeof whCurrentOffset !== 'undefined') whCurrentOffset = 0;
    if(typeof loadWebhooks === 'function') loadWebhooks();
}, 400);

window.debou  รหัส: ${escapeHtml(ref)}
                            ${salespersonName ? ' · พนักงานขาย: ' + escapeHtml(salespersonName) : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Load matching data
    if(typeof loadMatchingDashboard === 'function'){
        loadMatchingDashboard();
    }
}

// ===== 4. DEBOUNCED SEARCH FUNCTIONS =====
// Create debounced versions
window.debouncedLoadSlips = debounce(function(){
    if(typeof sli           <button class="chip" onclick="closeMatchingCustomer()" style="font-size:0.85rem;">
                        <i class="bi bi-arrow-left"></i> กลับรายการลูกค้า
                    </button>
                    <div style="flex:1;">
                        <div style="font-weight:700;font-size:1.05rem;color:var(--gray-800);">
                            ${escapeHtml(name)}
                        </div>
                        <div style="font-size:0.8rem;color:var(--gray-500);">
                          tById('matchCustomerGridZone');
    const detailZone = document.getElementById('matchCustomerDetailZone');
    
    if(gridZone) gridZone.style.display = 'none';
    if(detailZone) detailZone.style.display = 'block';
    
    // Update header
    const header = document.getElementById('matchCustomerDetailHeader');
    if(header){
        header.innerHTML = `
            <div class="content-card" style="margin-bottom:0.75rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
         ElementById('adminToggleLabel');
        if(label) label.textContent = 'Admin ON';
    }
});

// ===== 3. FIX MATCHING CUSTOMER NAVIGATION =====
// Replace the existing openMatchingForCustomer function
function openMatchingForCustomer(ref, name, partnerId, salespersonName){
    // Store active customer
    window._matchActiveCustomer = {
        ref: ref,
        name: name,
        partnerId: partnerId,
        salespersonName: salespersonName
    };
    
    // Toggle zones
    const gridZone = document.getElemennt.body.classList.toggle('admin-mode');
    const isAdmin = document.body.classList.contains('admin-mode');
    localStorage.setItem('adminMode', isAdmin ? '1' : '0');
    const label = document.getElementById('adminToggleLabel');
    if(label) label.textContent = isAdmin ? 'Admin ON' : 'Admin';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function(){
    if(localStorage.getItem('adminMode') === '1'){
        document.body.classList.add('admin-mode');
        const label = document.getCRITICAL FIXES & PERFORMANCE OPTIMIZATIONS
// เพิ่มไฟล์นี้หลัง odoo-dashboard.js หรือ merge เข้าไปในไฟล์หลัก
// ═══════════════════════════════════════════════════════════════════════════════

// ===== 1. DEBOUNCE UTILITY =====
function debounce(func, wait){
    let timeout;
    return function executedFunction(...args){
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// ===== 2. FIX ADMIN MODE PERSISTENCE =====
function toggleAdminMode(){
    docume// ═══════════════════════════════════════════════════════════════════════════════
// ODOO DASHBOARD - 