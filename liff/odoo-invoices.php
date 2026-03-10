<?php
/**
 * Odoo Invoices List - LIFF Page
 * แสดงรายการใบแจ้งหนี้จาก Odoo ERP
 * 
 * Features:
 * - แสดงรายการ invoices
 * - Filter by state
 * - แสดง summary (total due, overdue)
 * - Highlight overdue invoices
 * - ปุ่มดู PDF
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
    error_log("odoo-invoices.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

// Initialize SEO service
$seoService = new LandingSEOService($db, $lineAccountId);
$pageTitle = 'ใบแจ้งหนี้ Odoo';
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

        /* Summary Section */
        .summary-section {
            background: white;
            padding: 16px;
            margin-bottom: 8px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .summary-card {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 12px;
            border-left: 4px solid #11B0A6;
        }

        .summary-card.overdue {
            border-left-color: #ef4444;
        }

        .summary-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 24px;
            font-weight: 700;
            color: #11B0A6;
        }

        .summary-card.overdue .summary-value {
            color: #ef4444;
        }

        /* Filters */
        .filters-section {
            background: white;
            padding: 16px;
            margin-bottom: 8px;
        }

        .filter-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Sarabun', sans-serif;
            background: white;
            cursor: pointer;
        }

        /* Invoices List */
        .invoices-list {
            padding: 0 16px;
        }

        .invoice-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.2s;
        }

        .invoice-card.overdue {
            border-left: 4px solid #ef4444;
        }

        .invoice-card:active {
            transform: scale(0.98);
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .invoice-number {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .invoice-date {
            font-size: 13px;
            color: #999;
            margin-top: 4px;
        }

        .invoice-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-posted { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-cancel { background: #fee2e2; color: #991b1b; }

        .invoice-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 12px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
        }

        .invoice-info-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
        }

        .invoice-info-label {
            color: #666;
        }

        .invoice-info-value {
            font-weight: 500;
            color: #333;
        }

        .invoice-total {
            font-size: 18px;
            font-weight: 600;
            color: #11B0A6;
        }

        .invoice-total.overdue {
            color: #ef4444;
        }

        .invoice-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
        }

        .due-date {
            font-size: 13px;
            color: #666;
        }

        .due-date.overdue {
            color: #ef4444;
            font-weight: 600;
        }

        .btn-view-pdf {
            padding: 8px 16px;
            background: #11B0A6;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-view-pdf:active {
            background: #0D8B82;
            transform: scale(0.95);
        }

        /* Loading, Empty, Error states */
        .loading, .empty-state, .error-state {
            text-align: center;
            padding: 40px 20px;
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

        .empty-icon, .error-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        .empty-icon { color: #e0e0e0; }
        .error-icon { color: #ef4444; }

        .empty-title, .error-title {
            font-size: 18px;
            font-weight: 600;
            color: #666;
            margin-bottom: 8px;
        }

        .empty-text, .error-text {
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
                <h1 class="page-title">ใบแจ้งหนี้ Odoo</h1>
            </div>
        </div>

        <!-- Summary Section -->
        <div id="summary-section" class="summary-section" style="display: none;">
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">ยอดค้างชำระทั้งหมด</div>
                    <div class="summary-value" id="total-due">฿0.00</div>
                </div>
                <div class="summary-card overdue">
                    <div class="summary-label">ยอดเกินกำหนด</div>
                    <div class="summary-value" id="total-overdue">฿0.00</div>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <select id="state-filter" class="filter-select" onchange="handleFilterChange()">
                <option value="">ทุกสถานะ</option>
                <option value="draft">ร่าง</option>
                <option value="posted">ออกแล้ว</option>
                <option value="paid">ชำระแล้ว</option>
                <option value="cancel">ยกเลิก</option>
            </select>
        </div>

        <!-- Invoices List -->
        <div id="invoices-container" class="invoices-list">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>กำลังโหลดใบแจ้งหนี้...</p>
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

        let allInvoices = [];
        let filteredInvoices = [];
        let liffProfile = null;

        // Initialize LIFF
        async function initLiff() {
            try {
                await liff.init({ liffId: window.APP_CONFIG.LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    liffProfile = await liff.getProfile();
                    loadInvoices();
                } else {
                    liff.login();
                }
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อกับ LINE ได้');
            }
        }

        // Load invoices from API
        async function loadInvoices() {
            const container = document.getElementById('invoices-container');
            
            if (!liffProfile) {
                container.innerHTML = `
                    <div class="error-state">
                        <div class="error-icon"><i class="fas fa-user-slash"></i></div>
                        <h3 class="error-title">กรุณาเข้าสู่ระบบ</h3>
                        <p class="error-text">คุณต้องเข้าสู่ระบบเพื่อดูใบแจ้งหนี้</p>
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
                        action: 'list',
                        line_user_id: liffProfile.userId,
                        line_account_id: window.APP_CONFIG.ACCOUNT_ID
                    })
                });

                const data = await response.json();

                if (data.success && data.data && data.data.invoices) {
                    allInvoices = data.data.invoices;
                    filteredInvoices = allInvoices;
                    updateSummary();
                    renderInvoices();
                } else {
                    showEmpty();
                }
            } catch (error) {
                console.error('Error loading invoices:', error);
                showError('ไม่สามารถโหลดใบแจ้งหนี้ได้ กรุณาลองใหม่อีกครั้ง');
            }
        }

        // Update summary
        function updateSummary() {
            const summarySection = document.getElementById('summary-section');
            const totalDueEl = document.getElementById('total-due');
            const totalOverdueEl = document.getElementById('total-overdue');

            let totalDue = 0;
            let totalOverdue = 0;

            allInvoices.forEach(invoice => {
                if (invoice.state !== 'paid' && invoice.state !== 'cancel') {
                    totalDue += parseFloat(invoice.amount_residual || 0);
                    
                    if (invoice.is_overdue) {
                        totalOverdue += parseFloat(invoice.amount_residual || 0);
                    }
                }
            });

            totalDueEl.textContent = '฿' + totalDue.toFixed(2);
            totalOverdueEl.textContent = '฿' + totalOverdue.toFixed(2);
            summarySection.style.display = 'block';
        }

        // Render invoices
        function renderInvoices() {
            const container = document.getElementById('invoices-container');

            if (filteredInvoices.length === 0) {
                showEmpty();
                return;
            }

            container.innerHTML = filteredInvoices.map(invoice => renderInvoiceCard(invoice)).join('');
        }

        // Render single invoice card
        function renderInvoiceCard(invoice) {
            const statusClass = `status-${invoice.state || 'draft'}`;
            const statusText = getStatusText(invoice.state);
            const date = formatDate(invoice.invoice_date);
            const dueDate = formatDate(invoice.invoice_date_due);
            const total = parseFloat(invoice.amount_total || 0).toFixed(2);
            const residual = parseFloat(invoice.amount_residual || 0).toFixed(2);
            const isOverdue = invoice.is_overdue || false;
            const pdfUrl = invoice.pdf_url || '#';

            return `
                <div class="invoice-card ${isOverdue ? 'overdue' : ''}">
                    <div class="invoice-header">
                        <div>
                            <div class="invoice-number">${invoice.name || 'N/A'}</div>
                            <div class="invoice-date">${date}</div>
                        </div>
                        <span class="invoice-status ${statusClass}">${statusText}</span>
                    </div>
                    <div class="invoice-info">
                        <div class="invoice-info-row">
                            <span class="invoice-info-label">ยอดรวม:</span>
                            <span class="invoice-info-value">฿${total}</span>
                        </div>
                        <div class="invoice-info-row">
                            <span class="invoice-info-label">ยอดค้างชำระ:</span>
                            <span class="invoice-total ${isOverdue ? 'overdue' : ''}">฿${residual}</span>
                        </div>
                    </div>
                    <div class="invoice-footer">
                        <span class="due-date ${isOverdue ? 'overdue' : ''}">
                            ${isOverdue ? '⚠️ ' : ''}ครบกำหนด: ${dueDate}
                        </span>
                        <button class="btn-view-pdf" onclick="viewPDF('${pdfUrl}')">
                            <i class="fas fa-file-pdf"></i> ดู PDF
                        </button>
                    </div>
                </div>
            `;
        }

        // Get status text in Thai
        function getStatusText(state) {
            const statusMap = {
                'draft': 'ร่าง',
                'posted': 'ออกแล้ว',
                'paid': 'ชำระแล้ว',
                'cancel': 'ยกเลิก'
            };
            return statusMap[state] || state;
        }

        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Handle filter change
        function handleFilterChange() {
            const state = document.getElementById('state-filter').value;

            if (state) {
                filteredInvoices = allInvoices.filter(invoice => invoice.state === state);
            } else {
                filteredInvoices = allInvoices;
            }

            renderInvoices();
        }

        // View PDF
        function viewPDF(url) {
            if (url && url !== '#') {
                window.open(url, '_blank');
            } else {
                alert('ไม่พบไฟล์ PDF');
            }
        }

        // Show empty state
        function showEmpty() {
            const container = document.getElementById('invoices-container');
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-file-invoice"></i></div>
                    <h3 class="empty-title">ไม่พบใบแจ้งหนี้</h3>
                    <p class="empty-text">คุณยังไม่มีใบแจ้งหนี้ในระบบ Odoo</p>
                </div>
            `;
        }

        // Show error state
        function showError(message) {
            const container = document.getElementById('invoices-container');
            container.innerHTML = `
                <div class="error-state">
                    <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3 class="error-title">เกิดข้อผิดพลาด</h3>
                    <p class="error-text">${message}</p>
                    <button class="btn-retry" onclick="loadInvoices()">
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
