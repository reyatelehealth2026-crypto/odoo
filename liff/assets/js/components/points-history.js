/**
 * Points History Component
 * Transaction History for LIFF Telepharmacy Loyalty Points
 * 
 * Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.6, 22.7, 22.8, 22.9, 22.10, 22.11, 22.12
 * - Display transactions in chronological order (newest first)
 * - Show transaction type icon, description, points amount, balance after, and timestamp
 * - Green/plus for earned, red/minus for redeemed, gray for expired
 * - Filter tabs for All, Earned, Redeemed, Expired
 * - Infinite scroll for loading more transactions
 * - Show order ID or reward name when applicable
 * - Empty state with illustration and "Start Shopping" button
 * - Summary totals for filtered period at top
 * - JSON serialization/deserialization for storage and API
 */

class PointsHistory {
    constructor(config = {}) {
        this.config = {
            baseUrl: config.baseUrl || window.APP_CONFIG?.BASE_URL || '',
            accountId: config.accountId || window.APP_CONFIG?.ACCOUNT_ID || 1,
            pageSize: config.pageSize || 20,
            ...config
        };
        
        // State
        this.currentFilter = 'all';
        this.page = 1;
        this.transactions = [];
        this.allTransactions = []; // All loaded transactions for client-side filtering
        this.hasMore = true;
        this.isLoading = false;
        this.summary = {
            total_earned: 0,
            total_used: 0,
            total_expired: 0,
            available_points: 0
        };
        this.lineUserId = null;
        
        // Intersection Observer for infinite scroll
        this.observer = null;
    }

    /**
     * Initialize the component
     * @param {string} lineUserId - LINE user ID
     */
    async init(lineUserId) {
        this.lineUserId = lineUserId;
        await this.loadTransactions();
        this.setupInfiniteScroll();
    }

    /**
     * Load transactions from API
     * Requirements: 22.1, 22.7, 22.11, 22.12 - Load and deserialize transactions
     * @param {boolean} append - Whether to append to existing transactions
     * @returns {Promise<Object>} API response
     */
    async loadTransactions(append = false) {
        if (this.isLoading || (!this.hasMore && append)) return null;
        
        this.isLoading = true;
        
        try {
            const url = `${this.config.baseUrl}/api/points-history.php?action=full_history&line_user_id=${this.lineUserId}&page=${this.page}&limit=${this.config.pageSize}&type=${this.currentFilter}`;
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success) {
                // Deserialize transactions (Requirement 22.12)
                const newTransactions = this.deserializeTransactions(data.history || []);
                
                if (append) {
                    this.allTransactions = [...this.allTransactions, ...newTransactions];
                } else {
                    this.allTransactions = newTransactions;
                }
                
                // Apply current filter
                this.applyFilter();
                
                // Update summary (Requirement 22.10)
                this.summary = {
                    total_earned: parseInt(data.total_earned || data.user?.total_points || 0),
                    total_used: parseInt(data.total_used || data.user?.used_points || 0),
                    total_expired: parseInt(data.total_expired || 0),
                    available_points: parseInt(data.user?.available_points || 0)
                };
                
                // Check if there are more pages
                this.hasMore = newTransactions.length >= this.config.pageSize;
                
                return data;
            } else {
                console.error('PointsHistory: API error', data.error);
                return null;
            }
        } catch (error) {
            console.error('PointsHistory: Failed to load transactions', error);
            return null;
        } finally {
            this.isLoading = false;
        }
    }

    /**
     * Deserialize transactions from API response
     * Requirements: 22.12 - Decode JSON and reconstruct transaction objects
     * @param {Array} transactions - Raw transactions from API
     * @returns {Array} Deserialized transactions
     */
    deserializeTransactions(transactions) {
        return transactions.map(tx => ({
            id: parseInt(tx.id) || 0,
            type: this.normalizeTransactionType(tx.type),
            points: parseInt(tx.points) || 0,
            balance_after: parseInt(tx.balance_after) || 0,
            description: tx.description || '',
            reference_type: tx.reference_type || null,
            reference_id: tx.reference_id || null,
            reference_code: tx.reference_code || tx.reference_id || null,
            created_at: tx.created_at || null,
            formatted_date: tx.formatted_date || this.formatDateTime(tx.created_at)
        }));
    }

    /**
     * Serialize transactions for storage
     * Requirements: 22.11 - Encode using JSON format
     * @param {Array} transactions - Transactions to serialize
     * @returns {string} JSON string
     */
    serializeTransactions(transactions) {
        return JSON.stringify({
            transactions: transactions.map(tx => ({
                id: tx.id,
                type: tx.type,
                points: tx.points,
                balance_after: tx.balance_after,
                description: tx.description,
                reference_type: tx.reference_type,
                reference_id: tx.reference_id,
                created_at: tx.created_at
            })),
            serialized_at: new Date().toISOString()
        });
    }

    /**
     * Normalize transaction type to standard format
     * @param {string} type - Raw type from API
     * @returns {string} Normalized type
     */
    normalizeTransactionType(type) {
        const typeMap = {
            'earn': 'earned',
            'earned': 'earned',
            'redeem': 'redeemed',
            'redeemed': 'redeemed',
            'expire': 'expired',
            'expired': 'expired'
        };
        return typeMap[(type || '').toLowerCase()] || 'earned';
    }

    /**
     * Apply current filter to transactions
     * Requirements: 22.6 - Filter transactions by type
     */
    applyFilter() {
        if (this.currentFilter === 'all') {
            this.transactions = [...this.allTransactions];
        } else {
            this.transactions = this.allTransactions.filter(tx => tx.type === this.currentFilter);
        }
    }

    /**
     * Set filter and reload/re-filter transactions
     * Requirements: 22.6 - Filter tabs for All, Earned, Redeemed, Expired
     * @param {string} filter - Filter type
     */
    async setFilter(filter) {
        this.currentFilter = filter;
        this.page = 1;
        this.hasMore = true;
        
        // Re-apply filter to existing data
        this.applyFilter();
        
        // Re-render
        this.renderTransactionsList();
        this.updateFilterTabs();
        this.updateSummaryForFilter();
    }

    /**
     * Load more transactions (infinite scroll)
     * Requirements: 22.7 - Infinite scroll pattern
     */
    async loadMore() {
        if (this.isLoading || !this.hasMore) return;
        
        this.page++;
        await this.loadTransactions(true);
        this.renderTransactionsList();
    }

    /**
     * Setup infinite scroll observer
     * Requirements: 22.7 - Load more on scroll to bottom
     */
    setupInfiniteScroll() {
        // Disconnect existing observer
        if (this.observer) {
            this.observer.disconnect();
        }
        
        this.observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !this.isLoading && this.hasMore) {
                    this.loadMore();
                }
            });
        }, {
            rootMargin: '100px'
        });
        
        // Observe the load more trigger element
        const trigger = document.getElementById('load-more-trigger');
        if (trigger) {
            this.observer.observe(trigger);
        }
    }

    /**
     * Render the complete points history page
     * @returns {string} HTML string
     */
    render() {
        return `
            <div class="points-history-page">
                ${this.renderHeader()}
                ${this.renderSummaryCard()}
                ${this.renderFilterTabs()}
                <div class="transactions-container" id="transactions-container">
                    ${this.renderTransactionsContent()}
                </div>
            </div>
        `;
    }

    /**
     * Render page header
     */
    renderHeader() {
        return `
            <div class="points-history-header">
                <button class="back-btn" onclick="window.router ? window.router.back() : window.history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="page-title">ประวัติคะแนน</h1>
                <div class="header-spacer"></div>
            </div>
        `;
    }

    /**
     * Render summary card with totals
     * Requirements: 22.10 - Show summary totals for filtered period at top
     * @returns {string} HTML string
     */
    renderSummaryCard() {
        return `
            <div class="points-summary-header">
                <div class="summary-balance">
                    <span class="balance-label">คะแนนคงเหลือ</span>
                    <span class="balance-value">${this.formatNumber(this.summary.available_points)}</span>
                </div>
                <div class="summary-stats" id="summary-stats">
                    ${this.renderSummaryStats()}
                </div>
            </div>
        `;
    }

    /**
     * Render summary statistics
     * @returns {string} HTML string
     */
    renderSummaryStats() {
        return `
            <div class="stat-item earned">
                <i class="fas fa-plus-circle"></i>
                <span class="stat-value">+${this.formatNumber(this.summary.total_earned)}</span>
                <span class="stat-label">ได้รับ</span>
            </div>
            <div class="stat-item used">
                <i class="fas fa-minus-circle"></i>
                <span class="stat-value">-${this.formatNumber(this.summary.total_used)}</span>
                <span class="stat-label">ใช้ไป</span>
            </div>
            <div class="stat-item expired">
                <i class="fas fa-clock"></i>
                <span class="stat-value">-${this.formatNumber(this.summary.total_expired)}</span>
                <span class="stat-label">หมดอายุ</span>
            </div>
        `;
    }

    /**
     * Update summary display for current filter
     */
    updateSummaryForFilter() {
        const statsEl = document.getElementById('summary-stats');
        if (statsEl) {
            statsEl.innerHTML = this.renderSummaryStats();
        }
    }

    /**
     * Render filter tabs
     * Requirements: 22.6 - Filter tabs for All, Earned, Redeemed, Expired
     * @returns {string} HTML string
     */
    renderFilterTabs() {
        const filters = [
            { key: 'all', label: 'ทั้งหมด', icon: 'fa-list' },
            { key: 'earned', label: 'ได้รับ', icon: 'fa-plus-circle' },
            { key: 'redeemed', label: 'ใช้ไป', icon: 'fa-minus-circle' },
            { key: 'expired', label: 'หมดอายุ', icon: 'fa-clock' }
        ];
        
        return `
            <div class="filter-tabs-container">
                <div class="filter-tabs" id="filter-tabs">
                    ${filters.map(f => `
                        <button class="filter-tab ${this.currentFilter === f.key ? 'active' : ''}"
                                data-filter="${f.key}"
                                onclick="window.pointsHistory.setFilter('${f.key}')">
                            <i class="fas ${f.icon}"></i>
                            <span>${f.label}</span>
                        </button>
                    `).join('')}
                </div>
            </div>
        `;
    }

    /**
     * Update filter tabs active state
     */
    updateFilterTabs() {
        const tabs = document.querySelectorAll('.filter-tab');
        tabs.forEach(tab => {
            const filter = tab.dataset.filter;
            if (filter === this.currentFilter) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });
    }

    /**
     * Render transactions content (list or empty state)
     * @returns {string} HTML string
     */
    renderTransactionsContent() {
        if (this.isLoading && this.transactions.length === 0) {
            return this.renderSkeleton();
        }
        
        if (this.transactions.length === 0) {
            return this.renderEmptyState();
        }
        
        return `
            <div class="transactions-list" id="transactions-list">
                ${this.transactions.map(tx => this.renderTransaction(tx)).join('')}
            </div>
            ${this.hasMore ? `
                <div class="load-more-trigger" id="load-more-trigger">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>กำลังโหลด...</span>
                    </div>
                </div>
            ` : `
                <div class="end-of-list">
                    <span>แสดงรายการทั้งหมดแล้ว</span>
                </div>
            `}
        `;
    }

    /**
     * Re-render just the transactions list
     */
    renderTransactionsList() {
        const container = document.getElementById('transactions-container');
        if (container) {
            container.innerHTML = this.renderTransactionsContent();
            this.setupInfiniteScroll();
        }
    }

    /**
     * Render a single transaction item
     * Requirements: 22.2, 22.3, 22.4, 22.5, 22.8 - Transaction display with styling
     * @param {Object} tx - Transaction object
     * @returns {string} HTML string
     */
    renderTransaction(tx) {
        // Type configuration for styling (Requirements 22.3, 22.4, 22.5)
        const typeConfig = {
            'earned': { 
                icon: 'fa-plus-circle', 
                colorClass: 'earned',
                bgClass: 'bg-earned',
                prefix: '+',
                label: 'ได้รับ'
            },
            'redeemed': { 
                icon: 'fa-minus-circle', 
                colorClass: 'used',
                bgClass: 'bg-used',
                prefix: '-',
                label: 'ใช้ไป'
            },
            'expired': { 
                icon: 'fa-clock', 
                colorClass: 'expired',
                bgClass: 'bg-expired',
                prefix: '-',
                label: 'หมดอายุ'
            }
        };
        
        const config = typeConfig[tx.type] || typeConfig['earned'];
        const points = Math.abs(tx.points);
        const isPositive = tx.type === 'earned';
        
        // Build reference display (Requirement 22.8)
        let referenceDisplay = '';
        if (tx.reference_type && tx.reference_id) {
            if (tx.reference_type === 'order') {
                referenceDisplay = `<span class="tx-reference">คำสั่งซื้อ #${tx.reference_id}</span>`;
            } else if (tx.reference_type === 'reward') {
                referenceDisplay = `<span class="tx-reference">แลกรางวัล</span>`;
            } else if (tx.reference_code) {
                referenceDisplay = `<span class="tx-reference">#${tx.reference_code}</span>`;
            }
        }
        
        return `
            <div class="transaction-item" data-id="${tx.id}">
                <div class="tx-icon ${config.bgClass}">
                    <i class="fas ${config.icon}"></i>
                </div>
                <div class="tx-content">
                    <div class="tx-main">
                        <div class="tx-description">${this.escapeHtml(tx.description || config.label)}</div>
                        <div class="tx-points ${config.colorClass}">
                            ${isPositive ? '+' : '-'}${this.formatNumber(points)}
                        </div>
                    </div>
                    <div class="tx-meta">
                        <div class="tx-meta-left">
                            ${referenceDisplay}
                            <span class="tx-date">${tx.formatted_date || this.formatDateTime(tx.created_at)}</span>
                        </div>
                        <div class="tx-balance">
                            คงเหลือ ${this.formatNumber(tx.balance_after)}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Render empty state
     * Requirements: 22.9 - Empty state with illustration and "Start Shopping" button
     * @returns {string} HTML string
     */
    renderEmptyState() {
        const filterMessages = {
            'all': 'ยังไม่มีประวัติคะแนน',
            'earned': 'ยังไม่มีรายการได้รับคะแนน',
            'redeemed': 'ยังไม่มีรายการใช้คะแนน',
            'expired': 'ไม่มีคะแนนหมดอายุ'
        };
        
        const message = filterMessages[this.currentFilter] || filterMessages['all'];
        
        return `
            <div class="empty-state">
                <div class="empty-illustration">
                    <i class="fas fa-receipt"></i>
                </div>
                <h3 class="empty-title">${message}</h3>
                <p class="empty-message">
                    ${this.currentFilter === 'all' 
                        ? 'เริ่มช้อปเพื่อสะสมคะแนนและรับสิทธิพิเศษมากมาย'
                        : 'ลองเปลี่ยนตัวกรองเพื่อดูรายการอื่น'}
                </p>
                ${this.currentFilter === 'all' ? `
                    <button class="btn btn-primary" onclick="window.router ? window.router.navigate('/shop') : (window.location.href = 'liff-shop.php')">
                        <i class="fas fa-shopping-bag"></i>
                        เริ่มช้อปเลย
                    </button>
                ` : `
                    <button class="btn btn-outline" onclick="window.pointsHistory.setFilter('all')">
                        <i class="fas fa-list"></i>
                        ดูทั้งหมด
                    </button>
                `}
            </div>
        `;
    }

    /**
     * Render skeleton loading state
     * @returns {string} HTML string
     */
    renderSkeleton() {
        const skeletonItems = Array(5).fill(0).map(() => `
            <div class="transaction-item skeleton-item">
                <div class="skeleton skeleton-icon"></div>
                <div class="tx-content">
                    <div class="tx-main">
                        <div class="skeleton skeleton-text" style="width: 60%;"></div>
                        <div class="skeleton skeleton-text" style="width: 80px;"></div>
                    </div>
                    <div class="tx-meta">
                        <div class="skeleton skeleton-text short" style="width: 40%;"></div>
                        <div class="skeleton skeleton-text short" style="width: 60px;"></div>
                    </div>
                </div>
            </div>
        `).join('');
        
        return `
            <div class="transactions-list skeleton-list">
                ${skeletonItems}
            </div>
        `;
    }

    /**
     * Format number with thousand separators
     * @param {number} num - Number to format
     * @returns {string} Formatted number
     */
    formatNumber(num) {
        return (parseInt(num) || 0).toLocaleString('th-TH');
    }

    /**
     * Format date and time for display
     * @param {string} dateStr - Date string
     * @returns {string} Formatted date
     */
    formatDateTime(dateStr) {
        if (!dateStr) return '-';
        try {
            const date = new Date(dateStr);
            return date.toLocaleDateString('th-TH', {
                day: 'numeric',
                month: 'short',
                year: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch (e) {
            return dateStr;
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} str - String to escape
     * @returns {string} Escaped string
     */
    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Destroy the component and cleanup
     */
    destroy() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }
}

// Export for global use
window.PointsHistory = PointsHistory;
