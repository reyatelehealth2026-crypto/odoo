<?php
/**
 * POS Modals
 * 
 * Contains all modal dialogs for POS system:
 * - Open/Close Shift
 * - Customer Selection
 * - Payment
 * - Discount
 * - Receipt Preview
 */
?>

<!-- Open Shift Modal -->
<div class="pos-modal" id="openShiftModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-sign-in-alt"></i> เปิดกะ</h5>
            <button type="button" class="btn-close" onclick="closeModal('openShiftModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="payment-input">
                <label>เงินสดเปิดกะ (บาท)</label>
                <input type="number" id="openingCash" value="0" min="0" step="0.01">
            </div>
            <p class="text-muted small">
                <i class="fas fa-info-circle"></i> 
                กรุณานับเงินสดในลิ้นชักและกรอกจำนวนเงินเปิดกะ
            </p>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('openShiftModal')">ยกเลิก</button>
            <button class="btn btn-success" onclick="openShift()">
                <i class="fas fa-check"></i> เปิดกะ
            </button>
        </div>
    </div>
</div>

<!-- Close Shift Modal -->
<div class="pos-modal" id="closeShiftModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-sign-out-alt"></i> ปิดกะ</h5>
            <button type="button" class="btn-close" onclick="closeModal('closeShiftModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="payment-input">
                <label>เงินสดปิดกะ (บาท)</label>
                <input type="number" id="closingCash" value="0" min="0" step="0.01" onchange="calculateVariance()">
            </div>
            
            <div id="varianceInfo" class="mt-3 p-3 bg-light rounded" style="display: none;">
                <div class="row mb-2">
                    <span>เงินเปิดกะ:</span>
                    <span id="varOpeningCash">฿0.00</span>
                </div>
                <div class="row mb-2">
                    <span>ยอดขายเงินสด:</span>
                    <span id="varCashSales">฿0.00</span>
                </div>
                <div class="row mb-2">
                    <span>คืนเงินสด:</span>
                    <span id="varCashRefunds">-฿0.00</span>
                </div>
                <div class="row mb-2">
                    <span>เงินที่ควรมี:</span>
                    <span id="varExpected">฿0.00</span>
                </div>
                <hr>
                <div class="row">
                    <span><strong>ส่วนต่าง:</strong></span>
                    <span id="varVariance" class="fw-bold">฿0.00</span>
                </div>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('closeShiftModal')">ยกเลิก</button>
            <button class="btn btn-warning" onclick="closeShift()">
                <i class="fas fa-check"></i> ปิดกะ
            </button>
        </div>
    </div>
</div>

<!-- Shift Summary Modal -->
<div class="pos-modal" id="shiftSummaryModal">
    <div class="pos-modal-content" style="max-width: 600px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-chart-bar"></i> สรุปกะ</h5>
            <button type="button" class="btn-close" onclick="closeModal('shiftSummaryModal')"></button>
        </div>
        <div class="pos-modal-body" id="shiftSummaryContent">
            <div class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">กำลังโหลด...</p>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('shiftSummaryModal')">ปิด</button>
            <button class="btn btn-primary" onclick="printShiftSummary()">
                <i class="fas fa-print"></i> พิมพ์
            </button>
        </div>
    </div>
</div>

<!-- Customer Selection Modal -->
<div class="pos-modal" id="customerModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-user"></i> เลือกลูกค้า</h5>
            <button type="button" class="btn-close" onclick="closeModal('customerModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="mb-3">
                <input type="text" class="form-control" id="customerSearch" 
                       placeholder="ค้นหาด้วยเบอร์โทรหรือชื่อ..." onkeyup="searchCustomers(this.value)">
            </div>
            
            <div id="customerResults" class="customer-list">
                <div class="text-center text-muted py-3">
                    พิมพ์เพื่อค้นหาลูกค้า
                </div>
            </div>
            
            <hr>
            
            <button class="btn btn-outline-secondary w-100" onclick="selectWalkIn()">
                <i class="fas fa-user"></i> ลูกค้าทั่วไป (Walk-in)
            </button>
        </div>
    </div>
</div>

<!-- Discount Modal -->
<div class="pos-modal" id="discountModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-percent"></i> ส่วนลดบิล</h5>
            <button type="button" class="btn-close" onclick="closeModal('discountModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="mb-3">
                <label class="form-label">ประเภทส่วนลด</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="discountType" id="discountPercent" value="percent" checked>
                    <label class="btn btn-outline-primary" for="discountPercent">เปอร์เซ็นต์ (%)</label>
                    
                    <input type="radio" class="btn-check" name="discountType" id="discountFixed" value="fixed">
                    <label class="btn btn-outline-primary" for="discountFixed">จำนวนเงิน (฿)</label>
                </div>
            </div>
            
            <div class="payment-input">
                <label>จำนวนส่วนลด</label>
                <input type="number" id="discountValue" value="0" min="0" step="0.01">
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('discountModal')">ยกเลิก</button>
            <button class="btn btn-primary" onclick="applyBillDiscount()">
                <i class="fas fa-check"></i> ใช้ส่วนลด
            </button>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="pos-modal" id="paymentModal">
    <div class="pos-modal-content" style="max-width: 550px;">
        <div class="pos-modal-header">
            <h5><i class="fas fa-credit-card"></i> ชำระเงิน</h5>
            <button type="button" class="btn-close" onclick="closeModal('paymentModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div class="text-center mb-4">
                <h3 class="text-success mb-0" id="paymentTotal">฿0.00</h3>
                <small class="text-muted">ยอดที่ต้องชำระ</small>
            </div>
            
            <div class="payment-methods">
                <div class="payment-method active" data-method="cash" onclick="selectPaymentMethod('cash')">
                    <i class="fas fa-money-bill-wave text-success"></i>
                    <div>เงินสด</div>
                </div>
                <div class="payment-method" data-method="transfer" onclick="selectPaymentMethod('transfer')">
                    <i class="fas fa-qrcode text-primary"></i>
                    <div>โอน/QR</div>
                </div>
                <div class="payment-method" data-method="card" onclick="selectPaymentMethod('card')">
                    <i class="fas fa-credit-card text-info"></i>
                    <div>บัตร</div>
                </div>
                <div class="payment-method" data-method="points" onclick="selectPaymentMethod('points')" id="pointsMethod" style="display: none;">
                    <i class="fas fa-star text-warning"></i>
                    <div>แต้ม</div>
                </div>
            </div>
            
            <!-- Cash Payment -->
            <div id="cashPayment" class="payment-section">
                <div class="payment-input">
                    <label>รับเงิน (บาท)</label>
                    <input type="number" id="cashReceived" value="0" min="0" step="0.01" onchange="calculateChange()">
                </div>
                
                <div class="quick-cash">
                    <button onclick="setCashAmount(20)">฿20</button>
                    <button onclick="setCashAmount(50)">฿50</button>
                    <button onclick="setCashAmount(100)">฿100</button>
                    <button onclick="setCashAmount(500)">฿500</button>
                    <button onclick="setCashAmount(1000)">฿1000</button>
                    <button onclick="setExactAmount()">พอดี</button>
                </div>
                
                <div class="mt-3 p-3 bg-light rounded text-center">
                    <small class="text-muted">เงินทอน</small>
                    <h2 id="changeAmount" class="text-primary mb-0">฿0.00</h2>
                </div>
            </div>
            
            <!-- Transfer Payment -->
            <div id="transferPayment" class="payment-section" style="display: none;">
                <div class="text-center mb-3">
                    <div id="qrCodeDisplay" class="mb-2">
                        <!-- QR Code will be displayed here -->
                        <div class="border rounded p-4">
                            <i class="fas fa-qrcode fa-5x text-muted"></i>
                            <p class="mt-2 mb-0 text-muted">QR Code สำหรับชำระเงิน</p>
                        </div>
                    </div>
                </div>
                <div class="payment-input">
                    <label>เลขอ้างอิง (ถ้ามี)</label>
                    <input type="text" id="transferRef" placeholder="เลขอ้างอิงการโอน">
                </div>
            </div>
            
            <!-- Card Payment -->
            <div id="cardPayment" class="payment-section" style="display: none;">
                <div class="payment-input">
                    <label>เลขอ้างอิง/Approval Code</label>
                    <input type="text" id="cardRef" placeholder="เลขอ้างอิงจากเครื่อง EDC">
                </div>
            </div>
            
            <!-- Points Payment -->
            <div id="pointsPayment" class="payment-section" style="display: none;">
                <div class="text-center mb-3">
                    <p>แต้มที่มี: <strong id="availablePoints">0</strong> แต้ม</p>
                    <p class="text-muted small">10 แต้ม = 1 บาท</p>
                </div>
                <div class="payment-input">
                    <label>จำนวนแต้มที่ใช้</label>
                    <input type="number" id="pointsToUse" value="0" min="0" onchange="calculatePointsValue()">
                </div>
                <div class="text-center">
                    <p>มูลค่า: <strong id="pointsValue">฿0.00</strong></p>
                </div>
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('paymentModal')">ยกเลิก</button>
            <button class="btn btn-success btn-lg" onclick="processPayment()" id="confirmPayBtn">
                <i class="fas fa-check"></i> ยืนยันชำระเงิน
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="pos-modal" id="receiptModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-receipt"></i> ใบเสร็จ</h5>
            <button type="button" class="btn-close" onclick="closeModal('receiptModal')"></button>
        </div>
        <div class="pos-modal-body">
            <div id="receiptPreview" class="text-center">
                <!-- Receipt will be loaded here -->
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeReceiptAndNewSale()">
                <i class="fas fa-plus"></i> ขายรายการใหม่
            </button>
            <button class="btn btn-primary" onclick="printReceipt()">
                <i class="fas fa-print"></i> พิมพ์
            </button>
            <button class="btn btn-success" onclick="sendLineReceipt()" id="sendLineBtn" style="display: none;">
                <i class="fab fa-line"></i> ส่ง LINE
            </button>
        </div>
    </div>
</div>

<!-- Item Discount Modal -->
<div class="pos-modal" id="itemDiscountModal">
    <div class="pos-modal-content">
        <div class="pos-modal-header">
            <h5><i class="fas fa-percent"></i> ส่วนลดสินค้า</h5>
            <button type="button" class="btn-close" onclick="closeModal('itemDiscountModal')"></button>
        </div>
        <div class="pos-modal-body">
            <input type="hidden" id="discountItemId">
            
            <div class="mb-3">
                <strong id="discountItemName">สินค้า</strong>
                <p class="text-muted mb-0" id="discountItemPrice">฿0.00</p>
            </div>
            
            <div class="mb-3">
                <label class="form-label">ประเภทส่วนลด</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="itemDiscountType" id="itemDiscountPercent" value="percent" checked>
                    <label class="btn btn-outline-primary" for="itemDiscountPercent">%</label>
                    
                    <input type="radio" class="btn-check" name="itemDiscountType" id="itemDiscountFixed" value="fixed">
                    <label class="btn btn-outline-primary" for="itemDiscountFixed">฿</label>
                </div>
            </div>
            
            <div class="payment-input">
                <label>จำนวนส่วนลด</label>
                <input type="number" id="itemDiscountValue" value="0" min="0" step="0.01">
            </div>
        </div>
        <div class="pos-modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('itemDiscountModal')">ยกเลิก</button>
            <button class="btn btn-primary" onclick="applyItemDiscount()">
                <i class="fas fa-check"></i> ใช้ส่วนลด
            </button>
        </div>
    </div>
</div>

<style>
/* Customer List */
.customer-list {
    max-height: 300px;
    overflow-y: auto;
}

.customer-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
}

.customer-item:hover {
    background: #f5f5f5;
}

.customer-item img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 12px;
}

.customer-item .info {
    flex: 1;
}

.customer-item .name {
    font-weight: 500;
}

.customer-item .phone {
    font-size: 13px;
    color: #666;
}

.customer-item .points {
    text-align: right;
}

.customer-item .points .value {
    font-weight: bold;
    color: #ff9800;
}

.customer-item .points .label {
    font-size: 11px;
    color: #999;
}

/* Payment Sections */
.payment-section {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Row helper */
.row {
    display: flex;
    justify-content: space-between;
}
</style>
