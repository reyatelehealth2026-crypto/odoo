<?php
/**
 * Odoo Order Detail - LIFF Page
 * แสดงรายละเอียดออเดอร์จาก Odoo ERP
 * 
 * Features:
 * - แสดงรายละเอียด order
 * - แสดง order lines (รายการสินค้า)
 * - แสดง payment status badge
 * - แสดง shipping address
 * - ปุ่มดู tracking
 * - ปุ่มตรวจสอบ payment status
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LandingSEOService.php';

$db = Database::getInstance()->getConnection();

// Get parameters
$orderId = $_GET['id'] ?? null;
$lineAccountId = $_GET['account'] ?? null;
$liffIdParam = $_GET['liff_id'] ?? null;

// Get LIFF ID and shop settings
$liffId = '';
$shopName = 'ร้านค้า';
$shopLogo = '';
$companyName = 'MedCare';
$v = '202602141500'; // Cache buster

try {
    if ($liffIdParam) {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE liff_id = ? LIMIT 1");
        $stmt->execute([$liffIdParam]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($lineAccountId) {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            $stmt = $db->prepare("SELECT * FROM line_accounts ORDER BY id LIMIT 1");
            $stmt->execute();
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if ($account) {
        $lineAccountId = $account['id'];
        $liffId = $account['liff_id'] ?? '';
        $shopName = $account['name'];
        $companyName = $shopName;

        try {
            $stmt2 = $db->prepare("SELECT shop_name, shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1");
            $stmt2->execute([$lineAccountId]);
            $shop = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($shop) {
                if ($shop['shop_name']) {
                    $shopName = $shop['shop_name'];
                    $companyName = $shopName;
                }
                if ($shop['shop_logo']) {
                    $shopLogo = $shop['shop_logo'];
                }
            }
        } catch (Exception $e2) {
        }
    } else {
        $lineAccountId = 1;
    }
} catch (Exception $e) {
    error_log("odoo-order-detail.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

// Initialize SEO service
$seoService = new LandingSEOService($db, $lineAccountId);
$pageTitle = 'รายละเอียดออเดอร์';
$appName = $seoService->getAppName();
$faviconUrl = $seoService->getFaviconUrl();

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#11B0A6">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($appName) ?></title>

    <!-- Favicon -->
    <?php if (!empty($faviconUrl)): ?>
        <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
        <link rel="shortcut icon" type="image/x-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
        <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php endif; ?>

    <!-- LIFF SDK -->
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>

    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Styles -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background: #f5f5f5;
            color: #333;
            padding-bottom: 20px;
        }

        .container {
            max-width: 100%;
            margin: 0 auto;
        }

        /* Header */
        .page-header {
            background: linear-gradient(135deg, #11B0A6 0%, #0D8B82 100%);
            color: white;
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .back-btn:active {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(0.95);
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            flex: 1;
        }

        /* Order Header Card */
        .order-header-card {
            background: white;
            padding: 20px;
            margin-bottom: 8px;
        }

        .order-number {
            font-size: 24px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .order-date {
            font-size: 14px;
            color: #666;
            margin-bottom: 16px;
        }

        .order-status-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .order-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }

        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-sent { background: #dbeafe; color: #1e40af; }
        .status-sale { background: #d1fae5; color: #065f46; }
        .status-done { background: #d1fae5; color: #065f46; }
        .status-cancel { background: #fee2e2; color: #991b1b; }

        .payment-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }

        .payment-paid { background: #d1fae5; color: #065f46; }
        .payment-partial { background: #fef3c7; color: #92400e; }
        .payment-unpaid { background: #fee2e2; color: #991b1b; }
        .payment-not_paid { background: #fee2e2; color: #991b1b; }

        /* Customer Info Card */
        .info-card {
            background: white;
            padding: 20px;
            margin-bottom: 8px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title i {
            color: #11B0A6;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-size: 14px;
            color: #666;
        }

        .info-value {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            text-align: right;
        }

        /* Shipping Address Card */
        .address-text {
            font-size: 14px;
            color: #333;
            line-height: 1.6;
        }

        /* Order Lines Card */
        .order-line-item {
            display: flex;
            gap: 12px;
            padding: 16px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .order-line-item:last-child {
            border-bottom: none;
        }

        .line-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .line-image i {
            font-size: 24px;
            color: #ccc;
        }

        .line-info {
            flex: 1;
        }

        .line-name {
            font-size: 15px;
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }

        .line-details {
            font-size: 13px;
            color: #666;
            margin-bottom: 4px;
        }

        .line-price {
            font-size: 14px;
            font-weight: 600;
            color: #11B0A6;
        }

        /* Order Summary Card */
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            font-size: 14px;
        }

        .summary-row.total {
            border-top: 2px solid #e0e0e0;
            padding-top: 16px;
            margin-top: 8px;
        }

        .summary-label {
            color: #666;
        }

        .summary-value {
            font-weight: 500;
            color: #333;
        }

        .summary-row.total .summary-label {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .summary-row.total .summary-value {
            font-size: 18px;
            font-weight: 700;
            color: #11B0A6;
        }

        /* Action Buttons */
        .action-buttons {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn {
            padding: 14px 20px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Sarabun', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary {
            background: #11B0A6;
            color: white;
        }

        .btn-primary:active {
            background: #0D8B82;
            transform: scale(0.98);
        }

        .btn-secondary {
            background: white;
            color: #11B0A6;
            border: 2px solid #11B0A6;
        }

        .btn-secondary:active {
            background: #f0f9f8;
            transform: scale(0.98);
        }

        /* Loading */
        .loading {
            text-align: center;
            padding: 60px 20px;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #11B0A6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 16px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Error State */
        .error-state {
            text-align: center;
            padding: 60px 20px;
        }

        .error-icon {
            font-size: 64px;
            color: #ef4444;
            margin-bottom: 16px;
        }

        .error-title {
            font-size: 18px;
            font-weight: 600;
            color: #666;
            margin-bottom: 8px;
        }

        .error-text {
            font-size: 14px;
            color: #999;
            margin-bottom: 20px;
        }

        .btn-retry {
            padding: 12px 24px;
            background: #11B0A6;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-retry:active {
            background: #0D8B82;
            transform: scale(0.98);
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="page-header">
            <div class="header-content">
                <button class="back-btn" onclick="window.history.back()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="page-title">รายละเอียดออเดอร์</h1>
            </div>
        </div>

        <!-- Order Content -->
        <div id="order-content">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>กำลังโหลดข้อมูล...</p>
            </div>
        </div>

        <!-- Action Buttons -->
        <div id="action-buttons" class="action-buttons" style="display: none;">
            <button class="btn btn-primary" onclick="viewTracking()">
                <i class="fas fa-map-marker-alt"></i>
                ติดตามสถานะการจัดส่ง
            </button>
            <button class="btn btn-secondary" onclick="checkPaymentStatus()">
                <i class="fas fa-credit-card"></i>
                ตรวจสอบสถานะการชำระเงิน
            </button>
        </div>
    </div>

    <!-- App Configuration -->
    <script>
        window.APP_CONFIG = {
            BASE_URL: '<?= $baseUrl ?>',
            LIFF_ID: '<?= $liffId ?>',
            ACCOUNT_ID: <?= (int) $lineAccountId ?>,
            ORDER_ID: '<?= htmlspecialchars($orderId ?? '') ?>'
        };

        // Global state
        let liffProfile = null;
        let orderData = null;

        // Initialize LIFF
        async function initLiff() {
            try {
                await liff.init({ liffId: window.APP_CONFIG.LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    liffProfile = await liff.getProfile();
                    loadOrderDetail();
                } else {
                    liff.login();
                }
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อกับ LINE ได้');
            }
        }

        // Load order detail from API
        async function loadOrderDetail() {
            const container = document.getElementById('order-content');
            
            if (!window.APP_CONFIG.ORDER_ID) {
                container.innerHTML = `
                    <div class="error-state">
                        <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <h3 class="error-title">ไม่พบข้อมูลออเดอร์</h3>
                        <p class="error-text">กรุณาระบุเลขที่ออเดอร์</p>
                    </div>
                `;
                return;
            }

            if (!liffProfile) {
                container.innerHTML = `
                    <div class="error-state">
                        <div class="error-icon"><i class="fas fa-user-slash"></i></div>
                        <h3 class="error-title">กรุณาเข้าสู่ระบบ</h3>
                        <p class="error-text">คุณต้องเข้าสู่ระบบเพื่อดูออเดอร์</p>
                    </div>
                `;
                return;
            }

            try {
                const url = `${window.APP_CONFIG.BASE_URL}/api/odoo-orders.php`;
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'detail',
                        order_id: window.APP_CONFIG.ORDER_ID,
                        line_user_id: liffProfile.userId,
                        line_account_id: window.APP_CONFIG.ACCOUNT_ID
                    })
                });

                const data = await response.json();

                if (data.success && data.data) {
                    orderData = data.data;
                    renderOrderDetail(orderData);
                    document.getElementById('action-buttons').style.display = 'flex';
                } else {
                    showError(data.error || 'ไม่สามารถโหลดข้อมูลออเดอร์ได้');
                }
            } catch (error) {
                console.error('Error loading order detail:', error);
                showError('ไม่สามารถโหลดข้อมูลออเดอร์ได้ กรุณาลองใหม่อีกครั้ง');
            }
        }

        // Render order detail
        function renderOrderDetail(order) {
            const container = document.getElementById('order-content');
            
            // Order header
            const statusClass = `status-${order.state || 'draft'}`;
            const statusText = getStatusText(order.state);
            const date = formatDate(order.date_order);
            
            // Payment status
            const paymentState = order.payment_status?.payment_state || order.payment_state || 'not_paid';
            const paymentClass = `payment-${paymentState}`;
            const paymentText = getPaymentStatusText(paymentState);
            
            // Customer info
            const customer = order.customer || {};
            const customerName = customer.name || order.partner_name || 'N/A';
            const customerPhone = customer.phone || 'N/A';
            const customerEmail = customer.email || 'N/A';
            
            // Shipping address
            const address = order.shipping_address || {};
            const addressText = formatAddress(address);
            
            // Order lines
            const orderLines = order.order_lines || order.order_line || [];
            
            // Amounts
            const subtotal = parseFloat(order.amount_untaxed || 0).toFixed(2);
            const tax = parseFloat(order.amount_tax || 0).toFixed(2);
            const total = parseFloat(order.amount_total || 0).toFixed(2);
            
            container.innerHTML = `
                <!-- Order Header Card -->
                <div class="order-header-card">
                    <div class="order-number">${order.name || 'N/A'}</div>
                    <div class="order-date">${date}</div>
                    <div class="order-status-row">
                        <span class="order-status ${statusClass}">${statusText}</span>
                        <span class="payment-status ${paymentClass}">${paymentText}</span>
                    </div>
                </div>

                <!-- Customer Info Card -->
                <div class="info-card">
                    <div class="card-title">
                        <i class="fas fa-user"></i>
                        ข้อมูลลูกค้า
                    </div>
                    <div class="info-row">
                        <span class="info-label">ชื่อ:</span>
                        <span class="info-value">${customerName}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">เบอร์โทร:</span>
                        <span class="info-value">${customerPhone}</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">อีเมล:</span>
                        <span class="info-value">${customerEmail}</span>
                    </div>
                </div>

                <!-- Shipping Address Card -->
                <div class="info-card">
                    <div class="card-title">
                        <i class="fas fa-map-marker-alt"></i>
                        ที่อยู่จัดส่ง
                    </div>
                    <div class="address-text">${addressText}</div>
                </div>

                <!-- Order Lines Card -->
                <div class="info-card">
                    <div class="card-title">
                        <i class="fas fa-box"></i>
                        รายการสินค้า (${orderLines.length} รายการ)
                    </div>
                    ${renderOrderLines(orderLines)}
                </div>

                <!-- Order Summary Card -->
                <div class="info-card">
                    <div class="card-title">
                        <i class="fas fa-calculator"></i>
                        สรุปยอดรวม
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">ยอดรวมสินค้า:</span>
                        <span class="summary-value">฿${subtotal}</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">ภาษี:</span>
                        <span class="summary-value">฿${tax}</span>
                    </div>
                    <div class="summary-row total">
                        <span class="summary-label">ยอดรวมทั้งหมด:</span>
                        <span class="summary-value">฿${total}</span>
                    </div>
                </div>
            `;
        }

        // Render order lines
        function renderOrderLines(lines) {
            if (!lines || lines.length === 0) {
                return '<p style="color: #999; text-align: center; padding: 20px;">ไม่มีรายการสินค้า</p>';
            }

            return lines.map(line => {
                const productName = line.product_name || line.name || 'N/A';
                const qty = parseFloat(line.product_uom_qty || line.qty || 0);
                const price = parseFloat(line.price_unit || 0).toFixed(2);
                const subtotal = parseFloat(line.price_subtotal || 0).toFixed(2);

                return `
                    <div class="order-line-item">
                        <div class="line-image">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="line-info">
                            <div class="line-name">${productName}</div>
                            <div class="line-details">จำนวน: ${qty} × ฿${price}</div>
                            <div class="line-price">฿${subtotal}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Get status text in Thai
        function getStatusText(state) {
            const statusMap = {
                'draft': 'ร่าง',
                'sent': 'ส่งแล้ว',
                'sale': 'ขายแล้ว',
                'done': 'เสร็จสิ้น',
                'cancel': 'ยกเลิก'
            };
            return statusMap[state] || state;
        }

        // Get payment status text in Thai
        function getPaymentStatusText(state) {
            const statusMap = {
                'paid': 'ชำระเงินแล้ว',
                'partial': 'ชำระบางส่วน',
                'not_paid': 'ยังไม่ชำระ',
                'unpaid': 'ยังไม่ชำระ'
            };
            return statusMap[state] || state;
        }

        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Format address
        function formatAddress(address) {
            if (!address || Object.keys(address).length === 0) {
                return 'ไม่มีข้อมูลที่อยู่';
            }

            const parts = [];
            
            if (address.street) parts.push(address.street);
            if (address.street2) parts.push(address.street2);
            if (address.city) parts.push(address.city);
            if (address.state) parts.push(address.state);
            if (address.zip) parts.push(address.zip);
            if (address.country) parts.push(address.country);
            
            if (parts.length === 0) {
                return 'ไม่มีข้อมูลที่อยู่';
            }

            return parts.join('<br>');
        }

        // Show error state
        function showError(message) {
            const container = document.getElementById('order-content');
            container.innerHTML = `
                <div class="error-state">
                    <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3 class="error-title">เกิดข้อผิดพลาด</h3>
                    <p class="error-text">${message}</p>
                    <button class="btn-retry" onclick="loadOrderDetail()">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            `;
        }

        // View tracking
        function viewTracking() {
            if (!window.APP_CONFIG.ORDER_ID) {
                alert('ไม่พบข้อมูลออเดอร์');
                return;
            }

            // Navigate to tracking page
            window.location.href = `odoo-order-tracking.php?id=${window.APP_CONFIG.ORDER_ID}&account=${window.APP_CONFIG.ACCOUNT_ID}`;
        }

        // Check payment status
        async function checkPaymentStatus() {
            if (!window.APP_CONFIG.ORDER_ID || !liffProfile) {
                alert('ไม่สามารถตรวจสอบสถานะการชำระเงินได้');
                return;
            }

            try {
                // Show loading
                const btn = event.target;
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังตรวจสอบ...';
                btn.disabled = true;

                const url = `${window.APP_CONFIG.BASE_URL}/api/odoo-payment-status.php`;
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'check',
                        line_user_id: liffProfile.userId,
                        order_id: window.APP_CONFIG.ORDER_ID,
                        line_account_id: window.APP_CONFIG.ACCOUNT_ID
                    })
                });

                const data = await response.json();

                // Restore button
                btn.innerHTML = originalText;
                btn.disabled = false;

                if (data.success && data.payment_status) {
                    const status = data.payment_status;
                    const paymentState = status.payment_state || 'not_paid';
                    const paymentText = getPaymentStatusText(paymentState);
                    const amountPaid = parseFloat(status.amount_paid || 0).toFixed(2);
                    const amountDue = parseFloat(status.amount_due || 0).toFixed(2);

                    let message = `สถานะการชำระเงิน: ${paymentText}\n\n`;
                    message += `ยอดที่ชำระแล้ว: ฿${amountPaid}\n`;
                    message += `ยอดคงค้าง: ฿${amountDue}`;

                    alert(message);

                    // Reload order detail to update payment status
                    loadOrderDetail();
                } else {
                    alert(data.error || 'ไม่สามารถตรวจสอบสถานะการชำระเงินได้');
                }
            } catch (error) {
                console.error('Error checking payment status:', error);
                alert('เกิดข้อผิดพลาดในการตรวจสอบสถานะการชำระเงิน');
                
                // Restore button
                const btn = event.target;
                btn.innerHTML = '<i class="fas fa-credit-card"></i> ตรวจสอบสถานะการชำระเงิน';
                btn.disabled = false;
            }
        }

        // Initialize on page load
        if (window.APP_CONFIG.LIFF_ID) {
            initLiff();
        } else {
            showError('ไม่พบการตั้งค่า LIFF ID');
        }
    </script>
</body>

</html>
