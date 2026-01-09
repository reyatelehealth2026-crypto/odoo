/**
 * POS JavaScript
 * 
 * Handles all POS frontend operations:
 * - Cart state management
 * - API calls
 * - Barcode scanner integration
 * - Keyboard shortcuts
 * 
 * Requirements: 1.1-1.6
 */

const POS = {
    // State
    config: {
        hasShift: false,
        shiftId: null,
        cashierId: null,
        cashierName: ''
    },
    transaction: null,
    cart: [],
    customer: null,
    searchTimeout: null,
    
    /**
     * Initialize POS
     */
    init: function(config) {
        this.config = { ...this.config, ...config };
        
        // Setup keyboard shortcuts
        this.setupKeyboardShortcuts();
        
        // Setup barcode scanner
        this.setupBarcodeScanner();
        
        // Focus search on load
        if (this.config.hasShift) {
            document.getElementById('productSearch').focus();
        }
        
        console.log('POS initialized', this.config);
    },
    
    /**
     * Setup keyboard shortcuts
     */
    setupKeyboardShortcuts: function() {
        document.addEventListener('keydown', (e) => {
            // F2 - Focus search
            if (e.key === 'F2') {
                e.preventDefault();
                document.getElementById('productSearch').focus();
            }
            
            // F4 - Payment
            if (e.key === 'F4' && this.cart.length > 0) {
                e.preventDefault();
                showPaymentModal();
            }
            
            // F8 - Clear cart
            if (e.key === 'F8') {
                e.preventDefault();
                clearCart();
            }
            
            // Escape - Close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.pos-modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
    },
    
    /**
     * Setup barcode scanner (listens for rapid input)
     */
    setupBarcodeScanner: function() {
        let barcodeBuffer = '';
        let lastKeyTime = 0;
        
        document.addEventListener('keypress', (e) => {
            const currentTime = Date.now();
            
            // If typing fast (barcode scanner), accumulate
            if (currentTime - lastKeyTime < 50) {
                barcodeBuffer += e.key;
            } else {
                barcodeBuffer = e.key;
            }
            
            lastKeyTime = currentTime;
            
            // If Enter pressed and we have a barcode
            if (e.key === 'Enter' && barcodeBuffer.length > 3) {
                e.preventDefault();
                this.handleBarcodeScan(barcodeBuffer.slice(0, -1)); // Remove Enter
                barcodeBuffer = '';
            }
        });
    },
    
    /**
     * Handle barcode scan
     */
    handleBarcodeScan: function(barcode) {
        if (!this.config.hasShift) {
            showToast('กรุณาเปิดกะก่อน', 'warning');
            return;
        }
        
        // Search for product by barcode
        this.searchAndAddProduct(barcode);
    },
    
    /**
     * Search and add product by barcode
     */
    searchAndAddProduct: async function(barcode) {
        try {
            const response = await fetch(`api/pos.php?action=search_products&q=${encodeURIComponent(barcode)}`);
            const data = await response.json();
            
            if (data.success && data.data.length > 0) {
                // Add first matching product
                await this.addToCart(data.data[0].id);
            } else {
                showToast('ไม่พบสินค้า', 'warning');
            }
        } catch (error) {
            console.error('Search error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },
    
    /**
     * Create new transaction
     */
    createTransaction: async function() {
        try {
            const response = await fetch('api/pos.php?action=create_transaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    customer_id: this.customer?.id || null
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.transaction = data.data;
                return this.transaction;
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            console.error('Create transaction error:', error);
            showToast(error.message || 'ไม่สามารถสร้างรายการได้', 'error');
            return null;
        }
    },
    
    /**
     * Add product to cart
     */
    addToCart: async function(productId, quantity = 1) {
        if (!this.config.hasShift) {
            showToast('กรุณาเปิดกะก่อน', 'warning');
            return;
        }
        
        // Create transaction if not exists
        if (!this.transaction) {
            await this.createTransaction();
            if (!this.transaction) return;
        }
        
        try {
            const response = await fetch('api/pos.php?action=add_to_cart', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_id: this.transaction.id,
                    product_id: productId,
                    quantity: quantity
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.transaction = data.data.transaction;
                this.updateCartDisplay();
                showToast('เพิ่มสินค้าแล้ว', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Add to cart error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },
    
    /**
     * Update cart item quantity
     */
    updateCartItem: async function(itemId, quantity) {
        if (quantity < 1) {
            return this.removeFromCart(itemId);
        }
        
        try {
            const response = await fetch('api/pos.php?action=update_cart_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    item_id: itemId,
                    quantity: quantity
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                await this.refreshTransaction();
            } else {
                showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Update cart error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },
    
    /**
     * Remove item from cart
     */
    removeFromCart: async function(itemId) {
        try {
            const response = await fetch('api/pos.php?action=remove_cart_item', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: itemId })
            });
            
            const data = await response.json();
            
            if (data.success) {
                await this.refreshTransaction();
                showToast('ลบสินค้าแล้ว', 'success');
            } else {
                showToast(data.message, 'error');
            }
        } catch (error) {
            console.error('Remove from cart error:', error);
            showToast('เกิดข้อผิดพลาด', 'error');
        }
    },
    
    /**
     * Refresh transaction data
     */
    refreshTransaction: async function() {
        if (!this.transaction) return;
        
        try {
            const response = await fetch(`api/pos.php?action=get_transaction&id=${this.transaction.id}`);
            const data = await response.json();
            
            if (data.success) {
                this.transaction = data.data;
                this.updateCartDisplay();
            }
        } catch (error) {
            console.error('Refresh error:', error);
        }
    },
    
    /**
     * Update cart display
     */
    updateCartDisplay: function() {
        const items = this.transaction?.items || [];
        const cartItemsEl = document.getElementById('cartItems');
        const cartCountEl = document.getElementById('cartCount');
        const subtotalEl = document.getElementById('subtotal');
        const discountEl = document.getElementById('discount');
        const discountRowEl = document.getElementById('discountRow');
        const vatEl = document.getElementById('vat');
        const totalEl = document.getElementById('total');
        const payBtn = document.getElementById('payBtn');
        
        // Update count
        cartCountEl.textContent = `${items.length} รายการ`;
        
        // Update items
        if (items.length === 0) {
            cartItemsEl.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-shopping-basket"></i>
                    <p>ยังไม่มีสินค้าในตะกร้า</p>
                </div>
            `;
            payBtn.disabled = true;
        } else {
            cartItemsEl.innerHTML = items.map(item => `
                <div class="cart-item">
                    <div class="info">
                        <div class="name">${this.escapeHtml(item.product_name)}</div>
                        <div class="price">฿${this.formatNumber(item.unit_price)} 
                            ${item.discount_amount > 0 ? `<span class="text-danger">-฿${this.formatNumber(item.discount_amount)}</span>` : ''}
                        </div>
                    </div>
                    <div class="qty-controls">
                        <button onclick="POS.updateCartItem(${item.id}, ${item.quantity - 1})">-</button>
                        <span class="qty">${item.quantity}</span>
                        <button onclick="POS.updateCartItem(${item.id}, ${item.quantity + 1})">+</button>
                    </div>
                    <div class="line-total">฿${this.formatNumber(item.line_total)}</div>
                    <div class="remove-btn" onclick="POS.removeFromCart(${item.id})">
                        <i class="fas fa-times"></i>
                    </div>
                </div>
            `).join('');
            payBtn.disabled = false;
        }
        
        // Update totals
        const t = this.transaction || {};
        subtotalEl.textContent = `฿${this.formatNumber(t.subtotal || 0)}`;
        
        if (t.discount_amount > 0) {
            discountRowEl.style.display = 'flex';
            discountEl.textContent = `-฿${this.formatNumber(t.discount_amount)}`;
        } else {
            discountRowEl.style.display = 'none';
        }
        
        vatEl.textContent = `฿${this.formatNumber(t.vat_amount || 0)}`;
        totalEl.textContent = `฿${this.formatNumber(t.total_amount || 0)}`;
    },
    
    /**
     * Set customer
     */
    setCustomer: async function(customer) {
        this.customer = customer;
        
        // Update display
        const customerInfoEl = document.getElementById('customerInfo');
        if (customer) {
            customerInfoEl.innerHTML = `
                <i class="fas fa-user-check text-success"></i> ${this.escapeHtml(customer.display_name)}
                <small class="text-muted d-block">${customer.phone || ''} | แต้ม: ${customer.available_points || 0}</small>
            `;
            
            // Show points payment option
            document.getElementById('pointsMethod').style.display = 'block';
            document.getElementById('availablePoints').textContent = customer.available_points || 0;
        } else {
            customerInfoEl.innerHTML = `
                <i class="fas fa-user"></i> ลูกค้าทั่วไป (Walk-in)
                <small class="text-muted d-block">คลิกเพื่อเลือกสมาชิก</small>
            `;
            document.getElementById('pointsMethod').style.display = 'none';
        }
        
        // Update transaction if exists
        if (this.transaction && customer) {
            try {
                await fetch('api/pos.php?action=set_customer', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        transaction_id: this.transaction.id,
                        customer_id: customer.id
                    })
                });
            } catch (error) {
                console.error('Set customer error:', error);
            }
        }
    },
    
    /**
     * Clear cart
     */
    clearCart: function() {
        this.transaction = null;
        this.customer = null;
        this.updateCartDisplay();
        this.setCustomer(null);
    },
    
    /**
     * Complete transaction
     */
    completeTransaction: async function(payments) {
        if (!this.transaction) return null;
        
        try {
            const response = await fetch('api/pos.php?action=complete_transaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    transaction_id: this.transaction.id,
                    payments: payments
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                return data.data;
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            console.error('Complete transaction error:', error);
            showToast(error.message || 'เกิดข้อผิดพลาด', 'error');
            return null;
        }
    },
    
    // Utility functions
    formatNumber: function(num) {
        return parseFloat(num || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    
    escapeHtml: function(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};


// =========================================
// Global Functions (called from HTML)
// =========================================

/**
 * Search products
 */
function searchProducts(query) {
    clearTimeout(POS.searchTimeout);
    
    if (query.length < 1) {
        document.getElementById('productsGrid').innerHTML = `
            <div class="empty-state">
                <i class="fas fa-search"></i>
                <p>พิมพ์ค้นหาสินค้าหรือสแกนบาร์โค้ด</p>
            </div>
        `;
        return;
    }
    
    POS.searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`api/pos.php?action=search_products&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (data.success) {
                displayProducts(data.data);
            }
        } catch (error) {
            console.error('Search error:', error);
        }
    }, 300);
}

/**
 * Display products grid
 */
function displayProducts(products) {
    const grid = document.getElementById('productsGrid');
    
    if (products.length === 0) {
        grid.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <p>ไม่พบสินค้า</p>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = products.map(p => {
        const isOutOfStock = p.stock <= 0;
        const isExpired = p.is_expired;
        let classes = 'product-card';
        if (isOutOfStock) classes += ' out-of-stock';
        if (isExpired) classes += ' expired';
        
        return `
            <div class="${classes}" onclick="${!isOutOfStock && !isExpired ? `POS.addToCart(${p.id})` : ''}">
                <img src="${p.image_url || 'assets/images/no-image.png'}" alt="${POS.escapeHtml(p.name)}" 
                     onerror="this.src='assets/images/no-image.png'">
                <div class="name" title="${POS.escapeHtml(p.name)}">${POS.escapeHtml(p.name)}</div>
                <div class="price">฿${POS.formatNumber(p.price)}</div>
                <div class="stock">
                    ${isExpired ? '<span class="text-danger">หมดอายุ</span>' : 
                      isOutOfStock ? '<span class="text-danger">หมด</span>' : 
                      `คงเหลือ: ${p.stock}`}
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Search customers
 */
async function searchCustomers(query) {
    if (query.length < 2) {
        document.getElementById('customerResults').innerHTML = `
            <div class="text-center text-muted py-3">พิมพ์เพื่อค้นหาลูกค้า</div>
        `;
        return;
    }
    
    try {
        const response = await fetch(`api/pos.php?action=search_customers&q=${encodeURIComponent(query)}`);
        const data = await response.json();
        
        if (data.success) {
            displayCustomers(data.data);
        }
    } catch (error) {
        console.error('Search customers error:', error);
    }
}

/**
 * Display customers list
 */
function displayCustomers(customers) {
    const container = document.getElementById('customerResults');
    
    if (customers.length === 0) {
        container.innerHTML = `<div class="text-center text-muted py-3">ไม่พบลูกค้า</div>`;
        return;
    }
    
    container.innerHTML = customers.map(c => `
        <div class="customer-item" onclick="selectCustomer(${JSON.stringify(c).replace(/"/g, '&quot;')})">
            <img src="${c.picture_url || 'assets/images/default-avatar.png'}" 
                 onerror="this.src='assets/images/default-avatar.png'">
            <div class="info">
                <div class="name">${POS.escapeHtml(c.display_name)}</div>
                <div class="phone">${c.phone || '-'}</div>
            </div>
            <div class="points">
                <div class="value">${c.available_points || 0}</div>
                <div class="label">แต้ม</div>
            </div>
        </div>
    `).join('');
}

/**
 * Select customer
 */
function selectCustomer(customer) {
    POS.setCustomer(customer);
    closeModal('customerModal');
}

/**
 * Select walk-in customer
 */
function selectWalkIn() {
    POS.setCustomer(null);
    closeModal('customerModal');
}

/**
 * Clear cart
 */
function clearCart() {
    if (POS.transaction && POS.transaction.items?.length > 0) {
        if (!confirm('ต้องการล้างตะกร้าหรือไม่?')) return;
    }
    POS.clearCart();
}

// =========================================
// Modal Functions
// =========================================

function showModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function showCustomerModal() {
    if (!POS.config.hasShift) {
        showToast('กรุณาเปิดกะก่อน', 'warning');
        return;
    }
    showModal('customerModal');
    document.getElementById('customerSearch').focus();
}

function showDiscountModal() {
    if (!POS.transaction || POS.transaction.items?.length === 0) {
        showToast('กรุณาเพิ่มสินค้าก่อน', 'warning');
        return;
    }
    showModal('discountModal');
}

function showPaymentModal() {
    if (!POS.transaction || POS.transaction.items?.length === 0) {
        showToast('กรุณาเพิ่มสินค้าก่อน', 'warning');
        return;
    }
    
    document.getElementById('paymentTotal').textContent = `฿${POS.formatNumber(POS.transaction.total_amount)}`;
    document.getElementById('cashReceived').value = '';
    document.getElementById('changeAmount').textContent = '฿0.00';
    
    showModal('paymentModal');
    selectPaymentMethod('cash');
}

// =========================================
// Shift Functions
// =========================================

function showOpenShiftModal() {
    document.getElementById('openingCash').value = '0';
    showModal('openShiftModal');
}

function showCloseShiftModal() {
    document.getElementById('closingCash').value = '';
    document.getElementById('varianceInfo').style.display = 'none';
    showModal('closeShiftModal');
}

async function openShift() {
    const openingCash = parseFloat(document.getElementById('openingCash').value) || 0;
    
    try {
        const response = await fetch('api/pos.php?action=open_shift', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ opening_cash: openingCash })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('เปิดกะสำเร็จ', 'success');
            location.reload();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Open shift error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function closeShift() {
    const closingCash = parseFloat(document.getElementById('closingCash').value) || 0;
    
    if (!confirm('ยืนยันปิดกะ?')) return;
    
    try {
        const response = await fetch('api/pos.php?action=close_shift', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                shift_id: POS.config.shiftId,
                closing_cash: closingCash
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showToast('ปิดกะสำเร็จ', 'success');
            location.reload();
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Close shift error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

async function calculateVariance() {
    const closingCash = parseFloat(document.getElementById('closingCash').value) || 0;
    
    try {
        const response = await fetch('api/pos.php?action=calculate_variance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                shift_id: POS.config.shiftId,
                actual_cash: closingCash
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            const v = data.data;
            document.getElementById('varOpeningCash').textContent = `฿${POS.formatNumber(v.opening_cash)}`;
            document.getElementById('varCashSales').textContent = `฿${POS.formatNumber(v.cash_sales)}`;
            document.getElementById('varCashRefunds').textContent = `-฿${POS.formatNumber(v.cash_refunds)}`;
            document.getElementById('varExpected').textContent = `฿${POS.formatNumber(v.expected_cash)}`;
            
            const varianceEl = document.getElementById('varVariance');
            varianceEl.textContent = `฿${POS.formatNumber(v.variance)}`;
            varianceEl.className = v.variance >= 0 ? 'fw-bold text-success' : 'fw-bold text-danger';
            
            document.getElementById('varianceInfo').style.display = 'block';
        }
    } catch (error) {
        console.error('Calculate variance error:', error);
    }
}

async function showShiftSummary() {
    showModal('shiftSummaryModal');
    
    if (!POS.config.shiftId) {
        document.getElementById('shiftSummaryContent').innerHTML = `
            <div class="text-center py-4 text-muted">
                <i class="fas fa-info-circle fa-2x mb-2"></i>
                <p>ไม่มีกะที่เปิดอยู่</p>
            </div>
        `;
        return;
    }
    
    try {
        const response = await fetch(`api/pos.php?action=shift_summary&id=${POS.config.shiftId}`);
        const data = await response.json();
        
        if (data.success) {
            displayShiftSummary(data.data);
        }
    } catch (error) {
        console.error('Shift summary error:', error);
    }
}

function displayShiftSummary(summary) {
    const s = summary.summary;
    const c = summary.cash_summary;
    
    document.getElementById('shiftSummaryContent').innerHTML = `
        <div class="mb-4">
            <h6>ข้อมูลกะ</h6>
            <p class="mb-1">กะ: ${summary.shift.shift_number}</p>
            <p class="mb-1">เปิดเมื่อ: ${new Date(summary.shift.opened_at).toLocaleString('th-TH')}</p>
        </div>
        
        <div class="mb-4">
            <h6>สรุปยอดขาย</h6>
            <table class="table table-sm">
                <tr><td>จำนวนรายการ</td><td class="text-end">${s.transaction_count}</td></tr>
                <tr><td>ยอดขายรวม</td><td class="text-end">฿${POS.formatNumber(s.total_sales)}</td></tr>
                <tr><td>ยกเลิก</td><td class="text-end">${s.voided_count} รายการ (฿${POS.formatNumber(s.voided_amount)})</td></tr>
                <tr><td>คืนสินค้า</td><td class="text-end">${s.return_count} รายการ (฿${POS.formatNumber(s.total_refunds)})</td></tr>
                <tr class="table-success"><td><strong>ยอดสุทธิ</strong></td><td class="text-end"><strong>฿${POS.formatNumber(s.net_sales)}</strong></td></tr>
            </table>
        </div>
        
        <div class="mb-4">
            <h6>แยกตามวิธีชำระ</h6>
            <table class="table table-sm">
                ${summary.payment_breakdown.map(p => `
                    <tr><td>${getPaymentMethodLabel(p.payment_method)}</td><td class="text-end">฿${POS.formatNumber(p.total)}</td></tr>
                `).join('')}
            </table>
        </div>
        
        <div>
            <h6>สรุปเงินสด</h6>
            <table class="table table-sm">
                <tr><td>เงินเปิดกะ</td><td class="text-end">฿${POS.formatNumber(c.opening_cash)}</td></tr>
                <tr><td>รับเงินสด</td><td class="text-end">฿${POS.formatNumber(c.cash_sales)}</td></tr>
                <tr><td>คืนเงินสด</td><td class="text-end">-฿${POS.formatNumber(c.cash_refunds)}</td></tr>
                <tr class="table-info"><td><strong>เงินที่ควรมี</strong></td><td class="text-end"><strong>฿${POS.formatNumber(c.expected_cash)}</strong></td></tr>
            </table>
        </div>
    `;
}

function getPaymentMethodLabel(method) {
    const labels = {
        'cash': 'เงินสด',
        'transfer': 'โอน/QR',
        'card': 'บัตร',
        'points': 'แต้ม',
        'credit': 'เครดิต'
    };
    return labels[method] || method;
}

// =========================================
// Payment Functions
// =========================================

function selectPaymentMethod(method) {
    // Update UI
    document.querySelectorAll('.payment-method').forEach(el => {
        el.classList.toggle('active', el.dataset.method === method);
    });
    
    // Show/hide sections
    document.querySelectorAll('.payment-section').forEach(el => {
        el.style.display = 'none';
    });
    document.getElementById(method + 'Payment').style.display = 'block';
    
    // Focus appropriate input
    if (method === 'cash') {
        document.getElementById('cashReceived').focus();
    }
}

function setCashAmount(amount) {
    const current = parseFloat(document.getElementById('cashReceived').value) || 0;
    document.getElementById('cashReceived').value = current + amount;
    calculateChange();
}

function setExactAmount() {
    document.getElementById('cashReceived').value = POS.transaction?.total_amount || 0;
    calculateChange();
}

function calculateChange() {
    const total = POS.transaction?.total_amount || 0;
    const received = parseFloat(document.getElementById('cashReceived').value) || 0;
    const change = Math.max(0, received - total);
    document.getElementById('changeAmount').textContent = `฿${POS.formatNumber(change)}`;
}

function calculatePointsValue() {
    const points = parseInt(document.getElementById('pointsToUse').value) || 0;
    const value = points * 0.1; // 10 points = 1 baht
    document.getElementById('pointsValue').textContent = `฿${POS.formatNumber(value)}`;
}

async function processPayment() {
    const activeMethod = document.querySelector('.payment-method.active')?.dataset.method || 'cash';
    const total = POS.transaction?.total_amount || 0;
    
    let payments = [];
    
    if (activeMethod === 'cash') {
        const received = parseFloat(document.getElementById('cashReceived').value) || 0;
        if (received < total) {
            showToast('จำนวนเงินไม่เพียงพอ', 'error');
            return;
        }
        payments.push({
            method: 'cash',
            amount: total,
            cash_received: received,
            change_amount: received - total
        });
    } else if (activeMethod === 'transfer') {
        payments.push({
            method: 'transfer',
            amount: total,
            reference_number: document.getElementById('transferRef').value
        });
    } else if (activeMethod === 'card') {
        payments.push({
            method: 'card',
            amount: total,
            reference_number: document.getElementById('cardRef').value
        });
    } else if (activeMethod === 'points') {
        const points = parseInt(document.getElementById('pointsToUse').value) || 0;
        const pointsValue = points * 0.1;
        
        if (pointsValue < total) {
            showToast('แต้มไม่เพียงพอ กรุณาชำระส่วนต่างด้วยวิธีอื่น', 'warning');
            return;
        }
        
        payments.push({
            method: 'points',
            amount: total,
            points_used: Math.ceil(total / 0.1)
        });
    }
    
    // Complete transaction
    const result = await POS.completeTransaction(payments);
    
    if (result) {
        closeModal('paymentModal');
        showReceipt(result);
    }
}

async function showReceipt(transaction) {
    try {
        const response = await fetch(`api/pos.php?action=receipt_html&id=${transaction.id}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('receiptPreview').innerHTML = data.data.html;
            
            // Show LINE button if member
            if (transaction.customer_id) {
                document.getElementById('sendLineBtn').style.display = 'inline-block';
            } else {
                document.getElementById('sendLineBtn').style.display = 'none';
            }
            
            showModal('receiptModal');
        }
    } catch (error) {
        console.error('Receipt error:', error);
    }
}

function closeReceiptAndNewSale() {
    closeModal('receiptModal');
    POS.clearCart();
    document.getElementById('productSearch').focus();
}

async function printReceipt() {
    if (!POS.transaction) return;
    
    try {
        await fetch('api/pos.php?action=print_receipt', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ transaction_id: POS.transaction.id })
        });
        showToast('ส่งพิมพ์แล้ว', 'success');
    } catch (error) {
        console.error('Print error:', error);
    }
}

// =========================================
// Discount Functions
// =========================================

async function applyBillDiscount() {
    const type = document.querySelector('input[name="discountType"]:checked').value;
    const value = parseFloat(document.getElementById('discountValue').value) || 0;
    
    if (value <= 0) {
        showToast('กรุณาระบุจำนวนส่วนลด', 'warning');
        return;
    }
    
    try {
        const response = await fetch('api/pos.php?action=apply_bill_discount', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                transaction_id: POS.transaction.id,
                type: type,
                value: value
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            POS.transaction = data.data;
            POS.updateCartDisplay();
            closeModal('discountModal');
            showToast('ใช้ส่วนลดแล้ว', 'success');
        } else {
            showToast(data.message, 'error');
        }
    } catch (error) {
        console.error('Apply discount error:', error);
        showToast('เกิดข้อผิดพลาด', 'error');
    }
}

// =========================================
// Toast Notification
// =========================================

function showToast(message, type = 'info') {
    // Simple toast implementation
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        padding: 12px 24px;
        border-radius: 8px;
        color: white;
        font-weight: 500;
        z-index: 9999;
        animation: fadeInUp 0.3s ease;
    `;
    
    const colors = {
        success: '#4CAF50',
        error: '#f44336',
        warning: '#ff9800',
        info: '#2196F3'
    };
    toast.style.background = colors[type] || colors.info;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'fadeOutDown 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateX(-50%) translateY(20px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
    @keyframes fadeOutDown {
        from { opacity: 1; transform: translateX(-50%) translateY(0); }
        to { opacity: 0; transform: translateX(-50%) translateY(20px); }
    }
`;
document.head.appendChild(style);
