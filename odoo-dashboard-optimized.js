tEnd - perfData.loadEventStart),
                total: Math.round(perfData.loadEventEnd - perfData.fetchStart)
            });
        }
    });
});

// ===== EXPORT FOR TESTING =====
if(typeof module !== 'undefined' && module.exports){
    module.exports = {
        debounce,
        toggleAdminMode,
        showSection,
        openMatchingForCustomer,
        closeMatchingCustomer,
        warmCriticalCaches,
        perfMonitor
    };
}
        .section-panel {
            contain: layout style paint;
        }
    `;
    document.head.appendChild(style);
    
    // Log performance metrics
    window.addEventListener('load', () => {
        const perfData = performance.getEntriesByType('navigation')[0];
        if(perfData){
            console.log('[Perf] Page load:', {
                domContentLoaded: Math.round(perfData.domContentLoadedEventEnd - perfData.domContentLoadedEventStart),
                loadComplete: Math.round(perfData.loadEvennd: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s ease-in-out infinite;
        }
        
        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Optimize animations */
        .menu-card, .kpi-card, .chip {
            will-change: transform;
        }
        
        /* Reduce paint on scroll */
nd(label);
        return result;
    }
};

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', function(){
    // Initialize admin mode from storage
    initAdminMode();
    
    // Test connection
    testConnection().then(() => {
        // Warm critical caches after connection is established
        warmCriticalCaches();
    });
    
    // Add CSS for skeleton loading
    const style = document.createElement('style');
    style.textContent = `
        .skeleton {
            backgrou
        const duration = performance.now() - this.marks[label];
        delete this.marks[label];
        
        // Log slow operations
        if(duration > 1000){
            console.warn(`[Perf] ${label} took ${duration.toFixed(0)}ms`);
        }
        
        return duration;
    },
    
    measure(label, fn){
        this.start(label);
        const result = fn();
        
        if(result instanceof Promise){
            return result.finally(() => this.end(label));
        }
        
        this.ech(error) {
            lastError = error;
            
            // Wait before retry (exponential backoff)
            if(i < maxRetries - 1){
                await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
            }
        }
    }
    
    throw lastError;
}

// ===== PERFORMANCE MONITORING =====
const perfMonitor = {
    marks: {},
    
    start(label){
        this.marks[label] = performance.now();
    },
    
    end(label){
        if(!this.marks[label]) return null;ries = 3){
    let lastError;
    
    for(let i = 0; i < maxRetries; i++){
        try {
            const response = await fetch(url, options);
            if(response.ok) return response;
            
            // Don't retry on 4xx errors (client errors)
            if(response.status >= 400 && response.status < 500){
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            lastError = new Error(`HTTP ${response.status}`);
        } catIC UI UPDATE HELPER =====
function optimisticUpdate(containerId, newContent, rollbackFn){
    const container = document.getElementById(containerId);
    if(!container) return null;
    
    const originalContent = container.innerHTML;
    container.innerHTML = newContent;
    
    return {
        rollback: () => {
            container.innerHTML = originalContent;
            if(rollbackFn) rollbackFn();
        }
    };
}

// ===== ERROR RETRY MECHANISM =====
async function fetchWithRetry(url, options, maxRet:120px;border-radius:12px;"></div>
        `,
        'list': `
            <div style="display:flex;flex-direction:column;gap:0.5rem;padding:1rem;">
                <div class="skeleton" style="height:60px;border-radius:8px;"></div>
                <div class="skeleton" style="height:60px;border-radius:8px;"></div>
                <div class="skeleton" style="height:60px;border-radius:8px;"></div>
            </div>
        `
    };
    
    return skeletons[type] || skeletons['card'];
}

// ===== OPTIMIST===== LOADING SKELETON COMPONENT =====
function renderSkeleton(type){
    const skeletons = {
        'table-row': `
            <tr>
                <td colspan="100%" style="padding:1rem;">
                    <div class="skeleton" style="height:20px;border-radius:4px;margin-bottom:8px;"></div>
                    <div class="skeleton" style="height:20px;border-radius:4px;width:80%;"></div>
                </td>
            </tr>
        `,
        'card': `
            <div class="skeleton" style="height present
    if(!_cacheGet('match_grid')){
        console.log('[Cache] Warming matching grid cache...');
        loadMatchingCustomerGrid();
    }
}

// ===== OPTIMIZED IMAGE LOADING =====
function createLazyImage(src, alt, className, style){
    return `<img src="${escapeHtml(src)}" 
                 alt="${escapeHtml(alt || '')}" 
                 class="${className || ''}" 
                 style="${style || ''}" 
                 loading="lazy" 
                 onerror="this.style.display='none'">`;
}

// dLoadCustomers = debounce(function(){
    custCurrentOffset = 0;
    loadCustomers();
}, 500);

const debouncedLoadWebhooks = debounce(function(){
    whCurrentOffset = 0;
    loadWebhooks();
}, 400);

// ===== CACHE WARMING ON PAGE LOAD =====
async function warmCriticalCaches(){
    // Warm overview cache if not present
    if(!_cacheGet(_dashCacheKey('overview', 'today'))){
        console.log('[Cache] Warming overview cache...');
        loadTodayOverview();
    }
    
    // Warm matching grid cache if nots customer
    loadMatchingDashboard();
}

function closeMatchingCustomer(){
    _matchActiveCustomer = null;
    const gridZone = document.getElementById('matchCustomerGridZone');
    const detailZone = document.getElementById('matchCustomerDetailZone');
    if(detailZone) detailZone.style.display = 'none';
    if(gridZone) gridZone.style.display = '';
}

// ===== DEBOUNCED SEARCH FUNCTIONS =====
const debouncedLoadSlips = debounce(function(){
    slipCurrentOffset = 0;
    loadSlips();
}, 300);

const debounce700;font-size:1.05rem;color:var(--gray-800);">
                            ${escapeHtml(name)}
                        </div>
                        <div style="font-size:0.8rem;color:var(--gray-500);">
                            รหัส: ${escapeHtml(ref)}
                            ${salespersonName ? ' · พนักงานขาย: ' + escapeHtml(salespersonName) : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Load matching data for thiId('matchCustomerDetailHeader');
    if(header){
        header.innerHTML = `
            <div class="content-card" style="margin-bottom:0.75rem;">
                <div style="display:flex;align-items:center;gap:1rem;">
                    <button class="chip" onclick="closeMatchingCustomer()" style="font-size:0.85rem;">
                        <i class="bi bi-arrow-left"></i> กลับรายการลูกค้า
                    </button>
                    <div style="flex:1;">
                        <div style="font-weight:ext
    _matchActiveCustomer = {
        ref: ref,
        name: name,
        partnerId: partnerId,
        salespersonName: salespersonName
    };
    
    // Toggle zones
    const gridZone = document.getElementById('matchCustomerGridZone');
    const detailZone = document.getElementById('matchCustomerDetailZone');
    
    if(gridZone) gridZone.style.display = 'none';
    if(detailZone) detailZone.style.display = 'block';
    
    // Update header with back button
    const header = document.getElementBy             loadNotifications();
                _sectionMarkLoaded('notifications');
            }
        },
        
        'daily-summary': () => {
            if(dailySummaryData.length === 0) loadDailySummary();
        },
        
        'health': () => loadSystemHealth()
    };

    const loader = loaders[sectionId];
    if(loader) loader();
}

// ===== FIXED MATCHING CUSTOMER SELECTION =====
function openMatchingForCustomer(ref, name, partnerId, salespersonName){
    // Store active customer cont      
        'slips': () => {
            if(_sectionNeedsLoad('slips')){
                loadSlips();
                _sectionMarkLoaded('slips');
            }
        },
        
        'matching': () => {
            loadSalespersonDropdown();
            if(_sectionNeedsLoad('matching')){
                loadMatchingCustomerGrid();
                _sectionMarkLoaded('matching');
            }
        },
        
        'notifications': () => {
            if(_sectionNeedsLoad('notifications')){
   Load('webhooks')){
                loadWebhookStats();
                _sectionMarkLoaded('webhooks');
            }
            if(forceListMode) setWhViewMode('list');
            else if(whViewMode === 'grouped') loadOrdersGrouped();
            else loadWebhooks();
        },
        
        'customers': () => {
            loadSalespersonDropdown();
            if(_sectionNeedsLoad('customers')){
                loadCustomers();
                _sectionMarkLoaded('customers');
            }
        },
  document.querySelectorAll('.menu-card').forEach(c => c.classList.remove('active'));
    
    const panel = document.getElementById('section-' + sectionId);
    if(panel) panel.classList.add('active');
    
    const menuCard = document.querySelector(`.menu-card[onclick*="showSection('${id}')"]`);
    if(menuCard) menuCard.classList.add('active');

    // Simplified loader map
    const loaders = {
        'overview': () => loadTodayOverview(),
        
        'webhooks': () => {
            if(_sectionNeedslabel.textContent = 'Admin ON';
    }
}

// ===== OPTIMIZED SECTION NAVIGATION =====
function showSection(id){
    // Handle special cases
    let sectionId = id;
    let forceListMode = false;
    if(id === 'webhooks-raw'){ 
        sectionId = 'webhooks'; 
        forceListMode = true; 
    }

    // Update UI (batch DOM updates)
    document.querySelectorAll('.section-panel').forEach(s => s.classList.remove('active'));
    
    const isAdmin = document.body.classList.contains('admin-mode');
    localStorage.setItem('adminMode', isAdmin ? '1' : '0');
    const label = document.getElementById('adminToggleLabel');
    if(label) label.textContent = isAdmin ? 'Admin ON' : 'Admin';
}

// Initialize admin mode from localStorage
function initAdminMode(){
    if(localStorage.getItem('adminMode') === '1'){
        document.body.classList.add('admin-mode');
        const label = document.getElementById('adminToggleLabel');
        if(label) HBOARD - OPTIMIZED VERSION
// Performance improvements and bug fixes applied
// ═══════════════════════════════════════════════════════════════════════════════

// ===== UTILITY: DEBOUNCE =====
function debounce(func, wait){
    let timeout;
    return function executedFunction(...args){
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// ===== ADMIN MODE WITH PERSISTENCE =====
function toggleAdminMode(){
    document.body.classList.toggle('admin-mode');══════════════════════════════════════════════════
// ODOO DAS// ═════════════════════════════