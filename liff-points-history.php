<?php
/**
 * LIFF Points History - ประวัติคะแนนสะสม
 * 
 * Requirements: 22.1-22.12
 * - Display transactions in chronological order (newest first)
 * - Show transaction type icon, description, points, balance, timestamp
 * - Green/plus for earned, red/minus for redeemed, gray for expired
 * - Filter tabs for All, Earned, Redeemed, Expired
 * - Infinite scroll for loading more transactions
 * - Show order ID or reward name when applicable
 * - Empty state with illustration and "Start Shopping" button
 * - Summary totals for filtered period at top
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_GET['account'] ?? 1;
$companyName = 'ร้านค้า';

require_once 'includes/liff-helper.php';

$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
$lineAccountId = $liffData['line_account_id'];
$companyName = $liffData['account_name'];

$shopSettings = getShopSettings($db, $lineAccountId);
if (!empty($shopSettings['shop_name'])) $companyName = $shopSettings['shop_name'];

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ประวัติคะแนน - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/liff/assets/css/liff-app.css">
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: #F8FAFC; 
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        /* Header */
        .points-history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: white;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .back-btn {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #374151;
            font-size: 18px;
            cursor: pointer;
            border-radius: 50%;
            transition: background 0.2s;
        }
        .back-btn:hover { background: #f3f4f6; }
        .page-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        .header-spacer { width: 40px; }
        
        /* Summary Header */
        .points-summary-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px 16px;
            color: white;
        }
        .summary-balance {
            text-align: center;
            margin-bottom: 16px;
        }
        .balance-label {
            display: block;
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 4px;
        }
        .balance-value {
            font-size: 36px;
            font-weight: 700;
        }
        .summary-stats {
            display: flex;
            justify-content: space-around;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            padding: 12px;
        }
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .stat-item i { font-size: 16px; opacity: 0.9; }
        .stat-value { font-size: 16px; font-weight: 600; }
        .stat-label { font-size: 11px; opacity: 0.8; }
        
        /* Filter Tabs */
        .filter-tabs-container {
            background: white;
            padding: 12px 16px;
            position: sticky;
            top: 72px;
            z-index: 90;
            border-bottom: 1px solid #e5e7eb;
        }
        .filter-tabs {
            display: flex;
            gap: 8px;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 12px;
        }
        .filter-tab {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 12px;
            border: none;
            background: transparent;
            color: #6b7280;
            font-size: 13px;
            font-weight: 500;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-tab i { font-size: 12px; }
        .filter-tab.active {
            background: white;
            color: #7c3aed;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Transactions Container */
        .transactions-container {
            padding: 16px;
            padding-bottom: 100px;
        }
        .transactions-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        /* Transaction Item - Requirements 22.2, 22.3, 22.4, 22.5 */
        .transaction-item {
            display: flex;
            gap: 12px;
            background: white;
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .tx-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        /* Requirement 22.3 - Green for earned */
        .tx-icon.bg-earned { background: #dcfce7; color: #16a34a; }
        /* Requirement 22.4 - Red for redeemed */
        .tx-icon.bg-used { background: #fee2e2; color: #dc2626; }
        /* Requirement 22.5 - Gray for expired */
        .tx-icon.bg-expired { background: #f3f4f6; color: #6b7280; }
        
        .tx-content {
            flex: 1;
            min-width: 0;
        }
        .tx-main {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 4px;
        }
        .tx-description {
            font-size: 14px;
            font-weight: 500;
            color: #1f2937;
            line-height: 1.4;
        }
        .tx-points {
            font-size: 16px;
            font-weight: 700;
            white-space: nowrap;
        }
        .tx-points.earned { color: #16a34a; }
        .tx-points.used { color: #dc2626; }
        .tx-points.expired { color: #6b7280; }
        
        .tx-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #9ca3af;
        }
        .tx-meta-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tx-reference {
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #6b7280;
        }
        .tx-balance { color: #9ca3af; }
        
        /* Empty State - Requirement 22.9 */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
        }
        .empty-illustration {
            width: 100px;
            height: 100px;
            margin: 0 auto 24px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .empty-illustration i {
            font-size: 40px;
            color: #d1d5db;
        }
        .empty-title {
            font-size: 18px;
            font-weight: 600;
            color: #374151;
            margin: 0 0 8px;
        }
        .empty-message {
            font-size: 14px;
            color: #6b7280;
            margin: 0 0 24px;
            line-height: 1.5;
        }
        
        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn-primary:hover { opacity: 0.9; }
        .btn-outline {
            background: white;
            color: #7c3aed;
            border: 2px solid #7c3aed;
        }
        .btn-outline:hover { background: #f5f3ff; }
        
        /* Load More / Infinite Scroll - Requirement 22.7 */
        .load-more-trigger {
            padding: 20px;
            text-align: center;
        }
        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #9ca3af;
            font-size: 14px;
        }
        .loading-spinner i { font-size: 16px; }
        .end-of-list {
            text-align: center;
            padding: 16px;
            color: #9ca3af;
            font-size: 13px;
        }
        
        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: 4px;
        }
        .skeleton-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
        }
        .skeleton-text {
            height: 14px;
            margin-bottom: 8px;
        }
        .skeleton-text.short { height: 12px; }
        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .skeleton-item .tx-content {
            flex: 1;
        }
        .skeleton-item .tx-main,
        .skeleton-item .tx-meta {
            display: flex;
            justify-content: space-between;
        }
        
        /* Error State */
        .error-state {
            text-align: center;
            padding: 48px 24px;
        }
        .error-state i {
            font-size: 48px;
            color: #fca5a5;
            margin-bottom: 16px;
        }
        .error-state p {
            color: #6b7280;
            margin: 0;
        }
    </style>
</head>
<body>
    <div id="app">
        <!-- Loading State -->
        <div id="loading-state">
            <div class="points-history-header">
                <button class="back-btn" onclick="goBack()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="page-title">ประวัติคะแนน</h1>
                <button class="back-btn" onclick="goToPointsRules()" title="กฎการสะสมคะแนน">
                    <i class="fas fa-info-circle"></i>
                </button>
            </div>
            <div class="points-summary-header">
                <div class="summary-balance">
                    <span class="balance-label">คะแนนคงเหลือ</span>
                    <span class="balance-value">-</span>
                </div>
                <div class="summary-stats">
                    <div class="stat-item"><i class="fas fa-plus-circle"></i><span class="stat-value">-</span><span class="stat-label">ได้รับ</span></div>
                    <div class="stat-item"><i class="fas fa-minus-circle"></i><span class="stat-value">-</span><span class="stat-label">ใช้ไป</span></div>
                    <div class="stat-item"><i class="fas fa-clock"></i><span class="stat-value">-</span><span class="stat-label">หมดอายุ</span></div>
                </div>
            </div>
            <div class="filter-tabs-container">
                <div class="filter-tabs">
                    <button class="filter-tab active"><i class="fas fa-list"></i><span>ทั้งหมด</span></button>
                    <button class="filter-tab"><i class="fas fa-plus-circle"></i><span>ได้รับ</span></button>
                    <button class="filter-tab"><i class="fas fa-minus-circle"></i><span>ใช้ไป</span></button>
                    <button class="filter-tab"><i class="fas fa-clock"></i><span>หมดอายุ</span></button>
                </div>
            </div>
            <div class="transactions-container">
                <div class="transactions-list">
                    <?php for ($i = 0; $i < 5; $i++): ?>
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
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        
        <!-- Main Content (rendered by JS) -->
        <div id="main-content" style="display: none;"></div>
    </div>

    <script src="<?= $baseUrl ?>/liff/assets/js/components/points-history.js"></script>
    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    
    // Global config for components
    window.APP_CONFIG = {
        BASE_URL: BASE_URL,
        ACCOUNT_ID: ACCOUNT_ID
    };
    
    let userId = null;
    window.pointsHistory = null;

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        if (!LIFF_ID) {
            showError('ไม่พบการตั้งค่า LIFF');
            return;
        }
        
        try {
            await liff.init({ liffId: LIFF_ID });
            
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                userId = profile.userId;
                await loadPointsHistory();
            } else {
                liff.login();
            }
        } catch (e) {
            console.error('LIFF init error:', e);
            showError('เกิดข้อผิดพลาดในการเชื่อมต่อ');
        }
    }

    async function loadPointsHistory() {
        try {
            // Initialize Points History component
            window.pointsHistory = new PointsHistory({
                baseUrl: BASE_URL,
                accountId: ACCOUNT_ID,
                pageSize: 20
            });
            
            // Load initial data
            await window.pointsHistory.init(userId);
            
            // Hide loading, show main content
            document.getElementById('loading-state').style.display = 'none';
            const mainContent = document.getElementById('main-content');
            mainContent.style.display = 'block';
            mainContent.innerHTML = window.pointsHistory.render();
            
            // Setup infinite scroll after render
            window.pointsHistory.setupInfiniteScroll();
            
        } catch (e) {
            console.error('Load points history error:', e);
            showError('ไม่สามารถโหลดข้อมูลได้');
        }
    }

    function goBack() {
        if (liff.isInClient()) {
            window.location.href = `${BASE_URL}/liff-member-card.php?account=${ACCOUNT_ID}`;
        } else {
            window.history.back();
        }
    }

    function goToPointsRules() {
        window.location.href = `${BASE_URL}/liff-points-rules.php?account=${ACCOUNT_ID}`;
    }

    function showError(msg) {
        document.getElementById('loading-state').style.display = 'none';
        document.getElementById('main-content').style.display = 'block';
        document.getElementById('main-content').innerHTML = `
            <div class="points-history-header">
                <button class="back-btn" onclick="goBack()">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="page-title">ประวัติคะแนน</h1>
                <div class="header-spacer"></div>
            </div>
            <div class="error-state">
                <i class="fas fa-exclamation-circle"></i>
                <p>${msg}</p>
                <button class="btn btn-outline" style="margin-top: 16px;" onclick="location.reload()">
                    <i class="fas fa-redo"></i> ลองใหม่
                </button>
            </div>
        `;
    }

    // Simple router mock for navigation
    window.router = {
        navigate: function(path) {
            if (path === '/shop') {
                window.location.href = `${BASE_URL}/liff-shop.php?account=${ACCOUNT_ID}`;
            } else if (path === '/member') {
                window.location.href = `${BASE_URL}/liff-member-card.php?account=${ACCOUNT_ID}`;
            } else {
                window.location.href = `${BASE_URL}/liff-main.php?account=${ACCOUNT_ID}`;
            }
        },
        back: function() {
            goBack();
        }
    };
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
