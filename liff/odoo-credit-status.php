<?php
/**
 * Odoo Credit Status - LIFF Page
 * แสดงสถานะวงเงินเครดิตจาก Odoo ERP
 * 
 * Features:
 * - แสดง credit limit
 * - แสดง credit used
 * - แสดง credit available
 * - แสดง overdue amount
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LandingSEOService.php';

$db = Database::getInstance()->getConnection();

// Get parameters
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
    error_log("odoo-credit-status.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

// Initialize SEO service
$seoService = new LandingSEOService($db, $lineAccountId);
$pageTitle = 'สถานะวงเงินเครดิต';
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

        /* Credit Status Card */
        .credit-card {
            background: white;
            margin: 16px;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .credit-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .credit-title {
            font-size: 16px;
            color: #666;
            margin-bottom: 8px;
        }

        .credit-limit {
            font-size: 36px;
            font-weight: 700;
            color: #11B0A6;
        }

        /* Progress Bar */
        .credit-progress {
            margin: 24px 0;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }

        .progress-bar-container {
            width: 100%;
            height: 12px;
            background: #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #11B0A6 0%, #0D8B82 100%);
            transition: width 0.3s ease;
        }

        .progress-bar.warning {
            background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
        }

        .progress-bar.danger {
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
        }

        /* Credit Details */
        .credit-details {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e0e0e0;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .detail-label {
            font-size: 15px;
            color: #666;
        }

        .detail-value {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .detail-value.available {
            color: #11B0A6;
        }

        .detail-value.overdue {
            color: #ef4444;
        }

        /* Alert Box */
        .alert-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 16px;
            border-radius: 8px;
            margin-top: 16px;
        }

        .alert-box.warning {
            background: #fffbeb;
            border-left-color: #f59e0b;
        }

        .alert-title {
            font-size: 14px;
            font-weight: 600;
            color: #991b1b;
            margin-bottom: 4px;
        }

        .alert-box.warning .alert-title {
            color: #92400e;
        }

        .alert-text {
            font-size: 13px;
            color: #7f1d1d;
        }

        .alert-box.warning .alert-text {
            color: #78350f;
        }

        /* Loading, Error states */
        .loading, .error-state {
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
            margin-top: 20px;
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
                <h1 class="page-title">สถานะวงเงินเครดิต</h1>
            </div>
        </div>

        <!-- Content -->
        <div id="content-container">
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
            ACCOUNT_ID: <?= (int) $lineAccountId ?>
        };

        let liffProfile = null;

        // Initialize LIFF
        async function initLiff() {
            try {
                await liff.init({ liffId: window.APP_CONFIG.LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    liffProfile = await liff.getProfile();
                    loadCreditStatus();
                } else {
                    liff.login();
                }
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อกับ LINE ได้');
            }
        }

        // Load credit status from API
        async function loadCreditStatus() {
            const container = document.getElementById('content-container');
            
            if (!liffProfile) {
                container.innerHTML = `
                    <div class="error-state">
                        <div class="error-icon"><i class="fas fa-user-slash"></i></div>
                        <h3 class="error-title">กรุณาเข้าสู่ระบบ</h3>
                        <p class="error-text">คุณต้องเข้าสู่ระบบเพื่อดูสถานะวงเงิน</p>
                    </div>
                `;
                return;
            }

            try {
                const url = `${window.APP_CONFIG.BASE_URL}/api/odoo-invoices.php`;
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'credit_status',
                        line_user_id: liffProfile.userId,
                        line_account_id: window.APP_CONFIG.ACCOUNT_ID
                    })
                });

                const data = await response.json();

                if (data.success && data.data) {
                    renderCreditStatus(data.data);
                } else {
                    showError('ไม่สามารถโหลดข้อมูลได้');
                }
            } catch (error) {
                console.error('Error loading credit status:', error);
                showError('ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่อีกครั้ง');
            }
        }

        // Render credit status
        function renderCreditStatus(data) {
            const container = document.getElementById('content-container');
            
            const creditLimit = parseFloat(data.credit_limit || 0);
            const creditUsed = parseFloat(data.credit_used || 0);
            const creditAvailable = creditLimit - creditUsed;
            const overdueAmount = parseFloat(data.overdue_amount || 0);
            
            const usagePercent = creditLimit > 0 ? (creditUsed / creditLimit * 100) : 0;
            
            let progressClass = '';
            let alertHtml = '';
            
            if (usagePercent >= 90) {
                progressClass = 'danger';
                alertHtml = `
                    <div class="alert-box">
                        <div class="alert-title">⚠️ วงเงินใกล้หมด</div>
                        <div class="alert-text">คุณใช้วงเงินเครดิตไปแล้ว ${usagePercent.toFixed(0)}% กรุณาชำระเงินเพื่อเพิ่มวงเงิน</div>
                    </div>
                `;
            } else if (usagePercent >= 75) {
                progressClass = 'warning';
                alertHtml = `
                    <div class="alert-box warning">
                        <div class="alert-title">⚠️ วงเงินใกล้เต็ม</div>
                        <div class="alert-text">คุณใช้วงเงินเครดิตไปแล้ว ${usagePercent.toFixed(0)}%</div>
                    </div>
                `;
            }
            
            if (overdueAmount > 0) {
                alertHtml += `
                    <div class="alert-box">
                        <div class="alert-title">⚠️ มียอดเกินกำหนดชำระ</div>
                        <div class="alert-text">คุณมียอดเกินกำหนดชำระ ฿${overdueAmount.toFixed(2)} กรุณาชำระโดยเร็วที่สุด</div>
                    </div>
                `;
            }

            container.innerHTML = `
                <div class="credit-card">
                    <div class="credit-header">
                        <div class="credit-title">วงเงินเครดิตทั้งหมด</div>
                        <div class="credit-limit">฿${creditLimit.toFixed(2)}</div>
                    </div>

                    <div class="credit-progress">
                        <div class="progress-label">
                            <span>ใช้ไปแล้ว</span>
                            <span>${usagePercent.toFixed(0)}%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div class="progress-bar ${progressClass}" style="width: ${usagePercent}%"></div>
                        </div>
                    </div>

                    <div class="credit-details">
                        <div class="detail-row">
                            <span class="detail-label">ใช้ไปแล้ว</span>
                            <span class="detail-value">฿${creditUsed.toFixed(2)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">คงเหลือ</span>
                            <span class="detail-value available">฿${creditAvailable.toFixed(2)}</span>
                        </div>
                        ${overdueAmount > 0 ? `
                        <div class="detail-row">
                            <span class="detail-label">เกินกำหนดชำระ</span>
                            <span class="detail-value overdue">฿${overdueAmount.toFixed(2)}</span>
                        </div>
                        ` : ''}
                    </div>

                    ${alertHtml}
                </div>
            `;
        }

        // Show error state
        function showError(message) {
            const container = document.getElementById('content-container');
            container.innerHTML = `
                <div class="error-state">
                    <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3 class="error-title">เกิดข้อผิดพลาด</h3>
                    <p class="error-text">${message}</p>
                    <button class="btn-retry" onclick="loadCreditStatus()">
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
