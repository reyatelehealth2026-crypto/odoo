<?php
/**
 * POS (Point of Sale) - หน้าขายหน้าร้าน
 * 
 * Main POS interface for in-store sales
 * Requirements: 1.1, 7.5
 */

require_once 'includes/auth_check.php';
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/POSShiftService.php';

$pageTitle = 'POS - ขายหน้าร้าน';

// Get current user
$userId = $_SESSION['admin_id'] ?? null;
$userName = $_SESSION['admin_name'] ?? 'พนักงาน';

// Check for open shift
$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['line_account_id'] ?? 1;
$shiftService = new POSShiftService($db, $lineAccountId);
$currentShift = $userId ? $shiftService->getCurrentShift($userId) : null;

include 'includes/header.php';
?>

<style>
/* POS Specific Styles */
.pos-container {
    display: flex;
    height: calc(100vh - 60px);
    background: #f5f5f5;
}

.pos-left {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 15px;
    overflow: hidden;
}

.pos-right {
    width: 400px;
    background: white;
    display: flex;
    flex-direction: column;
    border-left: 1px solid #ddd;
}

/* Search Section */
.pos-search {
    background: white;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 15px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.pos-search input {
    width: 100%;
    padding: 12px 15px;
    font-size: 16px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
}

.pos-search input:focus {
    border-color: #4CAF50;
    outline: none;
}

/* Products Grid */
.pos-products {
    flex: 1;
    overflow-y: auto;
    background: white;
    border-radius: 8px;
    padding: 15px;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 15px;
}

.product-card {
    background: #f9f9f9;
    border-radius: 8px;
    padding: 10px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
}

.product-card:hover {
    background: #e8f5e9;
    transform: translateY(-2px);
}

.product-card.out-of-stock {
    opacity: 0.5;
    cursor: not-allowed;
}

.product-card.expired {
    background: #ffebee;
}

.product-card img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 4px;
    margin-bottom: 8px;
}

.product-card .name {
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.product-card .price {
    color: #4CAF50;
    font-weight: bold;
}

.product-card .stock {
    font-size: 11px;
    color: #666;
}

/* Cart Section */
.cart-header {
    padding: 15px;
    background: #4CAF50;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cart-customer {
    padding: 10px 15px;
    background: #f5f5f5;
    border-bottom: 1px solid #ddd;
    cursor: pointer;
}

.cart-customer:hover {
    background: #e8e8e8;
}

.cart-items {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
}

.cart-item {
    display: flex;
    padding: 10px;
    border-bottom: 1px solid #eee;
    align-items: center;
}

.cart-item .info {
    flex: 1;
}

.cart-item .name {
    font-weight: 500;
    margin-bottom: 4px;
}

.cart-item .price {
    font-size: 13px;
    color: #666;
}

.cart-item .qty-controls {
    display: flex;
    align-items: center;
    gap: 8px;
}

.cart-item .qty-controls button {
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.cart-item .qty-controls .qty {
    width: 40px;
    text-align: center;
    font-weight: bold;
}

.cart-item .line-total {
    width: 80px;
    text-align: right;
    font-weight: bold;
}

.cart-item .remove-btn {
    color: #f44336;
    cursor: pointer;
    padding: 5px;
}

/* Cart Footer */
.cart-totals {
    padding: 15px;
    background: #f9f9f9;
    border-top: 1px solid #ddd;
}

.cart-totals .row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.cart-totals .total {
    font-size: 20px;
    font-weight: bold;
    color: #4CAF50;
}

.cart-actions {
    padding: 15px;
    display: flex;
    gap: 10px;
}

.cart-actions button {
    flex: 1;
    padding: 15px;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}

.btn-pay {
    background: #4CAF50;
    color: white;
}

.btn-pay:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.btn-hold {
    background: #ff9800;
    color: white;
}

.btn-clear {
    background: #f44336;
    color: white;
}

/* Shift Banner */
.shift-banner {
    padding: 10px 15px;
    background: #fff3e0;
    border-bottom: 1px solid #ffcc80;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.shift-banner.no-shift {
    background: #ffebee;
    border-color: #ef9a9a;
}

.shift-banner .info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.shift-banner .status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.shift-banner .status.open {
    background: #4CAF50;
    color: white;
}

.shift-banner .status.closed {
    background: #f44336;
    color: white;
}

/* Modals */
.pos-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.pos-modal.active {
    display: flex;
}

.pos-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.pos-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pos-modal-body {
    padding: 20px;
}

.pos-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #eee;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Payment Modal */
.payment-methods {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.payment-method {
    padding: 15px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    text-align: center;
    cursor: pointer;
}

.payment-method.active {
    border-color: #4CAF50;
    background: #e8f5e9;
}

.payment-method i {
    font-size: 24px;
    margin-bottom: 8px;
    display: block;
}

.payment-input {
    margin-bottom: 15px;
}

.payment-input label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
}

.payment-input input {
    width: 100%;
    padding: 12px;
    font-size: 18px;
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    text-align: right;
}

.quick-cash {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.quick-cash button {
    padding: 8px 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #f5f5f5;
    cursor: pointer;
}

.quick-cash button:hover {
    background: #e0e0e0;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 40px;
    color: #999;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
}

/* Responsive */
@media (max-width: 768px) {
    .pos-container {
        flex-direction: column;
    }
    
    .pos-right {
        width: 100%;
        height: 50vh;
    }
    
    .pos-left {
        height: 50vh;
    }
}
</style>

<!-- Shift Banner -->
<div class="shift-banner <?= $currentShift ? '' : 'no-shift' ?>">
    <div class="info">
        <span class="status <?= $currentShift ? 'open' : 'closed' ?>">
            <?= $currentShift ? 'กะเปิด' : 'ไม่มีกะ' ?>
        </span>
        <?php if ($currentShift): ?>
            <span>กะ: <?= htmlspecialchars($currentShift['shift_number']) ?></span>
            <span>เปิดเมื่อ: <?= date('H:i', strtotime($currentShift['opened_at'])) ?></span>
        <?php else: ?>
            <span>กรุณาเปิดกะก่อนเริ่มขาย</span>
        <?php endif; ?>
    </div>
    <div>
        <?php if ($currentShift): ?>
            <button class="btn btn-sm btn-warning" onclick="showCloseShiftModal()">
                <i class="fas fa-sign-out-alt"></i> ปิดกะ
            </button>
        <?php else: ?>
            <button class="btn btn-sm btn-success" onclick="showOpenShiftModal()">
                <i class="fas fa-sign-in-alt"></i> เปิดกะ
            </button>
        <?php endif; ?>
        <button class="btn btn-sm btn-secondary" onclick="showShiftSummary()">
            <i class="fas fa-chart-bar"></i> สรุปกะ
        </button>
    </div>
</div>

<!-- Main POS Container -->
<div class="pos-container">
    <!-- Left: Products -->
    <div class="pos-left">
        <div class="pos-search">
            <input type="text" id="productSearch" placeholder="🔍 ค้นหาสินค้า (ชื่อ, SKU, บาร์โค้ด)..." 
                   onkeyup="searchProducts(this.value)" <?= !$currentShift ? 'disabled' : '' ?>>
        </div>
        
        <div class="pos-products">
            <div id="productsGrid" class="products-grid">
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <p>พิมพ์ค้นหาสินค้าหรือสแกนบาร์โค้ด</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right: Cart -->
    <div class="pos-right">
        <div class="cart-header">
            <span><i class="fas fa-shopping-cart"></i> ตะกร้าสินค้า</span>
            <span id="cartCount">0 รายการ</span>
        </div>
        
        <div class="cart-customer" onclick="showCustomerModal()">
            <div id="customerInfo">
                <i class="fas fa-user"></i> ลูกค้าทั่วไป (Walk-in)
                <small class="text-muted d-block">คลิกเพื่อเลือกสมาชิก</small>
            </div>
        </div>
        
        <div class="cart-items" id="cartItems">
            <div class="empty-state">
                <i class="fas fa-shopping-basket"></i>
                <p>ยังไม่มีสินค้าในตะกร้า</p>
            </div>
        </div>
        
        <div class="cart-totals">
            <div class="row">
                <span>รวม</span>
                <span id="subtotal">฿0.00</span>
            </div>
            <div class="row" id="discountRow" style="display: none;">
                <span>ส่วนลด</span>
                <span id="discount" class="text-danger">-฿0.00</span>
            </div>
            <div class="row">
                <span>VAT 7%</span>
                <span id="vat">฿0.00</span>
            </div>
            <div class="row total">
                <span>ยอดสุทธิ</span>
                <span id="total">฿0.00</span>
            </div>
        </div>
        
        <div class="cart-actions">
            <button class="btn-clear" onclick="clearCart()" title="ล้างตะกร้า">
                <i class="fas fa-trash"></i>
            </button>
            <button class="btn-hold" onclick="showDiscountModal()" title="ส่วนลด">
                <i class="fas fa-percent"></i>
            </button>
            <button class="btn-pay" id="payBtn" onclick="showPaymentModal()" disabled>
                <i class="fas fa-credit-card"></i> ชำระเงิน
            </button>
        </div>
    </div>
</div>

<!-- Include POS Modals -->
<?php include 'includes/pos/modals.php'; ?>

<!-- POS JavaScript -->
<script src="assets/js/pos.js"></script>

<script>
// Initialize POS
document.addEventListener('DOMContentLoaded', function() {
    POS.init({
        hasShift: <?= $currentShift ? 'true' : 'false' ?>,
        shiftId: <?= $currentShift ? $currentShift['id'] : 'null' ?>,
        cashierId: <?= $userId ?? 'null' ?>,
        cashierName: '<?= addslashes($userName) ?>'
    });
});
</script>

<?php include 'includes/footer.php'; ?>
