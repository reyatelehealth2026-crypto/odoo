<?php
/**
 * Odoo Orders List - LIFF Page
 * แสดงรายการออเดอร์จาก Odoo ERP
 * 
 * Features:
 * - แสดงรายการ orders
 * - Filter by state
 * - Filter by date range
 * - Search box
 * - Pagination
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
    error_log("odoo-orders.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

// Initialize SEO service
$seoService = new LandingSEOService($db, $lineAccountId);
$pageTitle = 'ออเดอร์ Odoo';
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

        /* Search and Filters */
        .filters-section {
            background: white;
            padding: 16px;
            margin-bottom: 8px;
        }

        .search-box {
            position: relative;
            margin-bottom: 16px;
        }

        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Sarabun', sans-serif;
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: #11B0A6;
            box-shadow: 0 0 0 3px rgba(17, 176, 166, 0.1);
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .filter-row {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .filter-select {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Sarabun', sans-serif;
            background: white;
            cursor: pointer;
        }

        .filter-select:focus {
            outline: none;
            border-color: #11B0A6;
        }

        .date-inputs {
            display: flex;
            gap: 8px;
        }

        .date-input {
            flex: 1;
            padding: 10px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Sarabun', sans-serif;
        }

        .date-input:focus {
            outline: none;
            border-color: #11B0A6;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Sarabun', sans-serif;
        }

        .btn-primary {
            background: #11B0A6;
            color: white;
            flex: 1;
        }

        .btn-primary:active {
            background: #0D8B82;
            transform: scale(0.98);
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #666;
        }

        .btn-secondary:active {
            background: #e0e0e0;
        }

        /* Orders List */
        .orders-list {
            padding: 0 16px;
        }

        .order-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            transition: all 0.2s;
        }

        .order-card:active {
            transform: scale(0.98);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .order-number {
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }

        .order-date {
            font-size: 13px;
            color: #999;
            margin-top: 4px;
        }

        .order-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-draft { background: #f3f4f6; color: #6b7280; }
        .status-sent { background: #dbeafe; color: #1e40af; }
        .status-sale { background: #d1fae5; color: #065f46; }
        .status-done { background: #d1fae5; color: #065f46; }
        .status-cancel { background: #fee2e2; color: #991b1b; }

        .order-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 12px 0;
            border-top: 1px solid #f0f0f0;
            border-bottom: 1px solid #f0f0f0;
        }

        .order-info-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
        }

        .order-info-label {
            color: #666;
        }

        .order-info-value {
            font-weight: 500;
            color: #333;
        }

        .order-total {
            font-size: 18px;
            font-weight: 600;
            color: #11B0A6;
        }

        .order-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
        }

        .order-items-count {
            font-size: 13px;
            color: #666;
        }

        .btn-view-detail {
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

        .btn-view-detail:active {
            background: #0D8B82;
            transform: scale(0.95);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px 16px;
        }

        .pagination-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn:not(:disabled):active {
            background: #f5f5f5;
        }

        .pagination-info {
            font-size: 14px;
            color: #666;
            padding: 0 12px;
        }

        /* Loading */
        .loading {
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            font-size: 64px;
            color: #e0e0e0;
            margin-bottom: 16px;
        }

        .empty-title {
            font-size: 18px;
            font-weight: 600;
            color: #666;
            margin-bottom: 8px;
        }

        .empty-text {
            font-size: 14px;
            color: #999;
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
                <h1 class="page-title">ออเดอร์ Odoo</h1>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <!-- Search Box -->
            <div class="search-box">
                <i class="fas fa-search search-icon"></i>
                <input type="text" 
                       id="search-input" 
                       class="search-input" 
                       placeholder="ค้นหาเลขที่ออเดอร์..."
                       onkeyup="handleSearch()">
            </div>

            <!-- State Filter -->
            <div class="filter-row">
                <select id="state-filter" class="filter-select" onchange="handleFilterChange()">
                    <option value="">ทุกสถานะ</option>
                    <option value="draft">ร่าง</option>
                    <option value="sent">ส่งแล้ว</option>
                    <option value="sale">ขายแล้ว</option>
                    <option value="done">เสร็จสิ้น</option>
                    <option value="cancel">ยกเลิก</option>
                </select>
            </div>

            <!-- Date Range Filter -->
            <div class="date-inputs">
                <input type="date" 
                       id="date-from" 
                       class="date-input" 
                       placeholder="จากวันที่"
                       onchange="handleFilterChange()">
                <input type="date" 
                       id="date-to" 
                       class="date-input" 
                       placeholder="ถึงวันที่"
                       onchange="handleFilterChange()">
            </div>

            <!-- Filter Actions -->
            <div class="filter-actions">
                <button class="btn btn-secondary" onclick="clearFilters()">
                    <i class="fas fa-times"></i> ล้างตัวกรอง
                </button>
                <button class="btn btn-primary" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> กรอง
                </button>
            </div>
        </div>

        <!-- Orders List -->
        <div id="orders-container" class="orders-list">
            <div class="loading">
                <div class="loading-spinner"></div>
                <p>กำลังโหลดออเดอร์...</p>
            </div>
        </div>

        <!-- Pagination -->
        <div id="pagination-container" class="pagination" style="display: none;">
            <button class="pagination-btn" id="prev-btn" onclick="previousPage()" disabled>
                <i class="fas fa-chevron-left"></i> ก่อนหน้า
            </button>
            <span class="pagination-info" id="pagination-info">หน้า 1 / 1</span>
            <button class="pagination-btn" id="next-btn" onclick="nextPage()" disabled>
                ถัดไป <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <!-- App Configuration -->
    <script>
        window.APP_CONFIG = {
            BASE_URL: '<?= $baseUrl ?>',
            LIFF_ID: '<?= $liffId ?>',
            ACCOUNT_ID: <?= (int) $lineAccountId ?>
        };

        // Global state
        let currentPage = 1;
        let totalPages = 1;
        let currentFilters = {
            search: '',
            state: '',
            dateFrom: '',
            dateTo: ''
        };
        let allOrders = [];
        let filteredOrders = [];
        let liffProfile = null;

        // Initialize LIFF
        async function initLiff() {
            try {
                await liff.init({ liffId: window.APP_CONFIG.LIFF_ID });
                
                if (liff.isLoggedIn()) {
                    liffProfile = await liff.getProfile();
                    loadOrders();
                } else {
                    liff.login();
                }
            } catch (error) {
                console.error('LIFF init error:', error);
                showError('ไม่สามารถเชื่อมต่อกับ LINE ได้');
            }
        }

        // Load orders from API
        async function loadOrders() {
            const container = document.getElementById('orders-container');
            
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
                        action: 'list',
                        line_user_id: liffProfile.userId,
                        line_account_id: window.APP_CONFIG.ACCOUNT_ID
                    })
                });

                const data = await response.json();

                if (data.success && data.data && data.data.orders) {
                    allOrders = data.data.orders;
                    filteredOrders = allOrders;
                    renderOrders();
                } else {
                    showEmpty();
                }
            } catch (error) {
                console.error('Error loading orders:', error);
                showError('ไม่สามารถโหลดออเดอร์ได้ กรุณาลองใหม่อีกครั้ง');
            }
        }

        // Render orders
        function renderOrders() {
            const container = document.getElementById('orders-container');
            const paginationContainer = document.getElementById('pagination-container');

            if (filteredOrders.length === 0) {
                showEmpty();
                paginationContainer.style.display = 'none';
                return;
            }

            // Pagination
            const ordersPerPage = 10;
            totalPages = Math.ceil(filteredOrders.length / ordersPerPage);
            const startIndex = (currentPage - 1) * ordersPerPage;
            const endIndex = startIndex + ordersPerPage;
            const ordersToShow = filteredOrders.slice(startIndex, endIndex);

            // Render orders
            container.innerHTML = ordersToShow.map(order => renderOrderCard(order)).join('');

            // Update pagination
            updatePagination();
            paginationContainer.style.display = 'flex';
        }

        // Render single order card
        function renderOrderCard(order) {
            const statusClass = `status-${order.state || 'draft'}`;
            const statusText = getStatusText(order.state);
            const date = formatDate(order.date_order);
            const total = parseFloat(order.amount_total || 0).toFixed(2);
            const itemsCount = order.order_line?.length || 0;

            return `
                <div class="order-card" onclick="viewOrderDetail('${order.id}')">
                    <div class="order-header">
                        <div>
                            <div class="order-number">${order.name || 'N/A'}</div>
                            <div class="order-date">${date}</div>
                        </div>
                        <span class="order-status ${statusClass}">${statusText}</span>
                    </div>
                    <div class="order-info">
                        <div class="order-info-row">
                            <span class="order-info-label">ลูกค้า:</span>
                            <span class="order-info-value">${order.partner_name || 'N/A'}</span>
                        </div>
                        <div class="order-info-row">
                            <span class="order-info-label">ยอดรวม:</span>
                            <span class="order-total">฿${total}</span>
                        </div>
                    </div>
                    <div class="order-footer">
                        <span class="order-items-count">${itemsCount} รายการ</span>
                        <button class="btn-view-detail" onclick="event.stopPropagation(); viewOrderDetail('${order.id}')">
                            ดูรายละเอียด <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
            `;
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

        // Format date
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleDateString('th-TH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Show empty state
        function showEmpty() {
            const container = document.getElementById('orders-container');
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-box-open"></i></div>
                    <h3 class="empty-title">ไม่พบออเดอร์</h3>
                    <p class="empty-text">คุณยังไม่มีออเดอร์ในระบบ Odoo</p>
                </div>
            `;
        }

        // Show error state
        function showError(message) {
            const container = document.getElementById('orders-container');
            container.innerHTML = `
                <div class="error-state">
                    <div class="error-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <h3 class="error-title">เกิดข้อผิดพลาด</h3>
                    <p class="error-text">${message}</p>
                    <button class="btn-retry" onclick="loadOrders()">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            `;
        }

        // Handle search
        function handleSearch() {
            const searchValue = document.getElementById('search-input').value.toLowerCase();
            currentFilters.search = searchValue;
            applyFilters();
        }

        // Handle filter change
        function handleFilterChange() {
            // Filters will be applied when user clicks "กรอง" button
        }

        // Apply filters
        function applyFilters() {
            const state = document.getElementById('state-filter').value;
            const dateFrom = document.getElementById('date-from').value;
            const dateTo = document.getElementById('date-to').value;
            const search = document.getElementById('search-input').value.toLowerCase();

            currentFilters = { search, state, dateFrom, dateTo };

            // Filter orders
            filteredOrders = allOrders.filter(order => {
                // Search filter
                if (search && !order.name.toLowerCase().includes(search)) {
                    return false;
                }

                // State filter
                if (state && order.state !== state) {
                    return false;
                }

                // Date range filter
                if (dateFrom || dateTo) {
                    const orderDate = new Date(order.date_order);
                    if (dateFrom && orderDate < new Date(dateFrom)) {
                        return false;
                    }
                    if (dateTo && orderDate > new Date(dateTo + 'T23:59:59')) {
                        return false;
                    }
                }

                return true;
            });

            // Reset to page 1
            currentPage = 1;
            renderOrders();
        }

        // Clear filters
        function clearFilters() {
            document.getElementById('search-input').value = '';
            document.getElementById('state-filter').value = '';
            document.getElementById('date-from').value = '';
            document.getElementById('date-to').value = '';
            
            currentFilters = { search: '', state: '', dateFrom: '', dateTo: '' };
            filteredOrders = allOrders;
            currentPage = 1;
            renderOrders();
        }

        // Pagination functions
        function updatePagination() {
            const prevBtn = document.getElementById('prev-btn');
            const nextBtn = document.getElementById('next-btn');
            const paginationInfo = document.getElementById('pagination-info');

            prevBtn.disabled = currentPage === 1;
            nextBtn.disabled = currentPage === totalPages;
            paginationInfo.textContent = `หน้า ${currentPage} / ${totalPages}`;
        }

        function previousPage() {
            if (currentPage > 1) {
                currentPage--;
                renderOrders();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        function nextPage() {
            if (currentPage < totalPages) {
                currentPage++;
                renderOrders();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        // View order detail
        function viewOrderDetail(orderId) {
            // Navigate to order detail page
            window.location.href = `odoo-order-detail.php?id=${orderId}&account=${window.APP_CONFIG.ACCOUNT_ID}`;
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
