<?php
/**
 * Odoo Order Tracking - LIFF Page
 * แสดง timeline การดำเนินการของออเดอร์จาก Odoo ERP
 * 
 * Features:
 * - แสดง timeline ทุกขั้นตอน (ยืนยัน → จัดสินค้า → แพ็ค → จัดส่ง)
 * - Highlight ขั้นตอนปัจจุบัน
 * - แสดงข้อมูล driver/vehicle (ถ้ากำลังจัดส่ง)
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
    error_log("odoo-order-tracking.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

// Initialize SEO service
$seoService = new LandingSEOService($db, $lineAccountId);
$pageTitle = 'ติดตามสถานะออเดอร์';
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

        .current-status {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 15px;
            font-weight: 600;
            background: linear-gradient(135deg, #11B0A6 0%, #0D8B82 100%);
            color: white;
        }

        /* Timeline Card */
        .timeline-card {
            background: white;
            padding: 20px;
            margin-bottom: 8px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title i {
            color: #11B0A6;
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 40px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 32px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-dot {
            position: absolute;
            left: -33px;
            top: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: white;
            border: 3px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .timeline-dot i {
            font-size: 14px;
            color: #999;
        }

        /* Timeline item states */
        .timeline-item.completed .timeline-dot {
            background: #11B0A6;
            border-color: #11B0A6;
        }

        .timeline-item.completed .timeline-dot i {
            color: white;
        }

        .timeline-item.current .timeline-dot {
            background: white;
            border-color: #11B0A6;
            border-width: 4px;
            animation: pulse 2s infinite;
        }

        .timeline-item.current .timeline-dot i {
            color: #11B0A6;
        }

        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(17, 176, 166, 0.4);
            }
            50% {
                box-shadow: 0 0 0 10px rgba(17, 176, 166, 0);
            }
        }

        .timeline-item.pending .timeline-dot {
            background: white;
            border-color: #e0e0e0;
        }

        .timeline-item.pending .timeline-dot i {
            color: #ccc;
        }

        /* Timeline content */
        .timeline-content {
            padding-left: 8px;
        }

        .timeline-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }

        .timeline-item.current .timeline-title {
            color: #11B0A6;
        }

        .timeline-item.pending .timeline-title {
            color: #999;
        }

        .timeline-description {
            font-size: 14px;
            color: #666;
            margin-bottom: 4px;
        }

        .timeline-item.pending .timeline-description {
            color: #999;
        }

        .timeline-timestamp {
            font-size: 13px;
            color: #999;
        }

        .timeline-item.current .timeline-timestamp {
            color: #11B0A6;
            font-weight: 500;
        }

        /* Delivery Info Card */
        .delivery-info-card {
            background: linear-gradient(135deg, #11B0A6 0%, #0D8B82 100%);
            color: white;
            padding: 20px;
            margin-bottom: 8px;
            border-radius: 12px;
        }

        .delivery-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .delivery-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .delivery-row:last-child {
            border-bottom: none;
        }

        .delivery-label {
            font-size: 14px;
            opacity: 0.9;
        }

        .delivery-value {
            font-size: 14px;
            font-weight: 500;
            text-align: right;
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
                <h1 class="page-title">ติดตามสถานะออเดอร์</h1>
            </div>
        </div>

        <!-- Tracking Content -->
        <div id="tracking-content">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>กำลังโหลดข้อมูล...</p>
            </div>
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
        let trackingData = null;

        // Initialize LIFF
        async function initLiff() {
            try {
                await liff.init({ liffId: window.APP_CONFIG.LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    liffProfile = await liff.getProfile();
                    loadOrderTracking();
                } else {
                    liff.login();
                }
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อกับ LINE ได้');
            }
        }

        // Load order tracking from API
        async function loadOrderTracking() {
            const container = document.getElementById('tracking-content');
            
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
                        action: 'tracking',
                        order_id: window.APP_CONFIG.ORDER_ID,
                        line_user_id: liffProfile.userId,
                        line_account_id: window.APP_CONFIG.ACCOUNT_ID
                    })
                });

                const data = await response.json();

                if (data.success && data.data) {
                    trackingData = data.data;
                    renderOrderTracking(trackingData);
                } else {
                    showError(data.error || 'ไม่สามารถโหลดข้อมูลการติดตามได้');
                }
            } catch (error) {
                console.error('Error loading order tracking:', error);
                showError('ไม่สามารถโหลดข้อมูลการติดตามได้ กรุณาลองใหม่อีกครั้ง');
            }
        }

        // Render order tracking
        function renderOrderTracking(tracking) {
            const container = document.getElementById('tracking-content');
            
            // Order info
            const orderName = tracking.order_name || 'N/A';
            const orderDate = formatDate(tracking.order_date);
            const currentState = tracking.current_state || 'draft';
            const currentStateText = getStateText(currentState);
            
            // Timeline
            const timeline = tracking.timeline || [];
            
            // Delivery info
            const delivery = tracking.delivery || null;
            
            let html = `
                <!-- Order Header Card -->
                <div class="order-header-card">
                    <div class="order-number">${orderName}</div>
                    <div class="order-date">${orderDate}</div>
                    <div class="current-status">${currentStateText}</div>
                </div>
            `;
            
            // Add delivery info card if available
            if (delivery && (currentState === 'in_delivery' || currentState === 'delivered')) {
                html += renderDeliveryInfo(delivery);
            }
            
            // Add timeline card
            html += `
                <div class="timeline-card">
                    <div class="card-title">
                        <i class="fas fa-route"></i>
                        ขั้นตอนการดำเนินการ
                    </div>
                    <div class="timeline">
                        ${renderTimeline(timeline, currentState)}
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        }

        // Render delivery info card
        function renderDeliveryInfo(delivery) {
            const driverName = delivery.driver_name || 'N/A';
            const driverPhone = delivery.driver_phone || 'N/A';
            const vehicleNumber = delivery.vehicle_number || 'N/A';
            const vehicleType = delivery.vehicle_type || 'N/A';
            const departureTime = delivery.departure_time ? formatDate(delivery.departure_time) : 'N/A';
            const estimatedArrival = delivery.estimated_arrival ? formatDate(delivery.estimated_arrival) : 'N/A';
            
            return `
                <div class="delivery-info-card">
                    <div class="delivery-title">
                        <i class="fas fa-truck"></i>
                        ข้อมูลการจัดส่ง
                    </div>
                    <div class="delivery-row">
                        <span class="delivery-label">คนขับ:</span>
                        <span class="delivery-value">${driverName}</span>
                    </div>
                    <div class="delivery-row">
                        <span class="delivery-label">เบอร์โทร:</span>
                        <span class="delivery-value">${driverPhone}</span>
                    </div>
                    <div class="delivery-row">
                        <span class="delivery-label">ทะเบียนรถ:</span>
                        <span class="delivery-value">${vehicleNumber}</span>
                    </div>
                    <div class="delivery-row">
                        <span class="delivery-label">ประเภทรถ:</span>
                        <span class="delivery-value">${vehicleType}</span>
                    </div>
                    <div class="delivery-row">
                        <span class="delivery-label">เวลาออกจัดส่ง:</span>
                        <span class="delivery-value">${departureTime}</span>
                    </div>
                    <div class="delivery-row">
                        <span class="delivery-label">เวลาถึงโดยประมาณ:</span>
                        <span class="delivery-value">${estimatedArrival}</span>
                    </div>
                </div>
            `;
        }

        // Render timeline
        function renderTimeline(timeline, currentState) {
            if (!timeline || timeline.length === 0) {
                // Default timeline if no data from API
                return renderDefaultTimeline(currentState);
            }
            
            return timeline.map(item => {
                const state = item.state || '';
                const title = item.title || getStateText(state);
                const description = item.description || '';
                const timestamp = item.timestamp ? formatDate(item.timestamp) : '';
                const icon = getStateIcon(state);
                
                // Determine item status
                let itemClass = 'pending';
                if (item.completed || item.status === 'completed') {
                    itemClass = 'completed';
                } else if (state === currentState) {
                    itemClass = 'current';
                }
                
                return `
                    <div class="timeline-item ${itemClass}">
                        <div class="timeline-dot">
                            <i class="${icon}"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">${title}</div>
                            ${description ? `<div class="timeline-description">${description}</div>` : ''}
                            ${timestamp ? `<div class="timeline-timestamp">${timestamp}</div>` : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Render default timeline (fallback)
        function renderDefaultTimeline(currentState) {
            const states = [
                { state: 'draft', title: 'สร้างออเดอร์', description: 'ออเดอร์ถูกสร้างในระบบ' },
                { state: 'sent', title: 'ส่งออเดอร์', description: 'ออเดอร์ถูกส่งให้ร้านค้า' },
                { state: 'sale', title: 'ยืนยันออเดอร์', description: 'ร้านค้ายืนยันรับออเดอร์' },
                { state: 'picking', title: 'กำลังจัดสินค้า', description: 'กำลังเตรียมสินค้าตามออเดอร์' },
                { state: 'packed', title: 'แพ็คเสร็จสิ้น', description: 'สินค้าถูกแพ็คเรียบร้อย' },
                { state: 'to_delivery', title: 'พร้อมจัดส่ง', description: 'สินค้าพร้อมสำหรับการจัดส่ง' },
                { state: 'in_delivery', title: 'กำลังจัดส่ง', description: 'รถกำลังจัดส่งสินค้า' },
                { state: 'delivered', title: 'จัดส่งสำเร็จ', description: 'ลูกค้าได้รับสินค้าแล้ว' }
            ];
            
            const currentIndex = states.findIndex(s => s.state === currentState);
            
            return states.map((item, index) => {
                let itemClass = 'pending';
                if (index < currentIndex) {
                    itemClass = 'completed';
                } else if (index === currentIndex) {
                    itemClass = 'current';
                }
                
                const icon = getStateIcon(item.state);
                
                return `
                    <div class="timeline-item ${itemClass}">
                        <div class="timeline-dot">
                            <i class="${icon}"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title">${item.title}</div>
                            <div class="timeline-description">${item.description}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Get state text in Thai
        function getStateText(state) {
            const stateMap = {
                'draft': 'สร้างออเดอร์',
                'sent': 'ส่งออเดอร์',
                'sale': 'ยืนยันออเดอร์',
                'validated': 'ยืนยันออเดอร์',
                'picking': 'กำลังจัดสินค้า',
                'picked': 'จัดสินค้าเสร็จ',
                'packing': 'กำลังแพ็ค',
                'packed': 'แพ็คเสร็จสิ้น',
                'reserved': 'จองสินค้าแล้ว',
                'awaiting_payment': 'รอชำระเงิน',
                'paid': 'ชำระเงินแล้ว',
                'to_delivery': 'พร้อมจัดส่ง',
                'in_delivery': 'กำลังจัดส่ง',
                'delivered': 'จัดส่งสำเร็จ',
                'done': 'เสร็จสิ้น',
                'cancel': 'ยกเลิก'
            };
            return stateMap[state] || state;
        }

        // Get state icon
        function getStateIcon(state) {
            const iconMap = {
                'draft': 'fas fa-file-alt',
                'sent': 'fas fa-paper-plane',
                'sale': 'fas fa-check-circle',
                'validated': 'fas fa-check-circle',
                'picking': 'fas fa-boxes',
                'picked': 'fas fa-box-open',
                'packing': 'fas fa-box',
                'packed': 'fas fa-box-check',
                'reserved': 'fas fa-bookmark',
                'awaiting_payment': 'fas fa-credit-card',
                'paid': 'fas fa-money-check-alt',
                'to_delivery': 'fas fa-shipping-fast',
                'in_delivery': 'fas fa-truck',
                'delivered': 'fas fa-home',
                'done': 'fas fa-check-double',
                'cancel': 'fas fa-times-circle'
            };
            return iconMap[state] || 'fas fa-circle';
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

        // Show error state
        function showError(message) {
            const container = document.getElementById('tracking-content');
            container.innerHTML = `
                <div class="error-state">
                    <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3 class="error-title">เกิดข้อผิดพลาด</h3>
                    <p class="error-text">${message}</p>
                    <button class="btn-retry" onclick="loadOrderTracking()">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            `;
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
