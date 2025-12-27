<?php
/**
 * LIFF Points History - ประวัติคะแนนสะสม
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
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }
        .points-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    </style>
</head>
<body class="min-h-screen pb-20">
    <!-- Header -->
    <div class="bg-white shadow-sm sticky top-0 z-10">
        <div class="flex items-center justify-between p-4">
            <button onclick="goBack()" class="w-10 h-10 flex items-center justify-center text-gray-600">
                <i class="fas fa-arrow-left text-xl"></i>
            </button>
            <h1 class="font-bold text-lg text-gray-800">ประวัติคะแนน</h1>
            <div class="w-10"></div>
        </div>
    </div>

    <!-- Points Summary -->
    <div class="p-4">
        <div class="points-card rounded-2xl p-5 text-white shadow-lg">
            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <p class="text-white/70 text-xs mb-1">สะสมทั้งหมด</p>
                    <p class="text-xl font-bold" id="totalPoints">-</p>
                </div>
                <div class="border-x border-white/20">
                    <p class="text-white/70 text-xs mb-1">ใช้ได้</p>
                    <p class="text-xl font-bold" id="availablePoints">-</p>
                </div>
                <div>
                    <p class="text-white/70 text-xs mb-1">ใช้ไปแล้ว</p>
                    <p class="text-xl font-bold" id="usedPoints">-</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="px-4 mb-4">
        <div class="flex gap-2 bg-gray-100 p-1 rounded-xl">
            <button onclick="filterHistory('all')" id="tab-all" class="flex-1 py-2 px-4 rounded-lg text-sm font-medium bg-white text-purple-600 shadow-sm">
                ทั้งหมด
            </button>
            <button onclick="filterHistory('earn')" id="tab-earn" class="flex-1 py-2 px-4 rounded-lg text-sm font-medium text-gray-500">
                ได้รับ
            </button>
            <button onclick="filterHistory('redeem')" id="tab-redeem" class="flex-1 py-2 px-4 rounded-lg text-sm font-medium text-gray-500">
                ใช้ไป
            </button>
        </div>
    </div>

    <!-- History List -->
    <div class="px-4">
        <div id="historyList" class="space-y-3">
            <!-- Loading -->
            <div id="loadingSkeleton" class="space-y-3">
                <?php for ($i = 0; $i < 5; $i++): ?>
                <div class="bg-white rounded-xl p-4 animate-pulse">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gray-200 rounded-full"></div>
                            <div>
                                <div class="h-4 bg-gray-200 rounded w-32 mb-2"></div>
                                <div class="h-3 bg-gray-200 rounded w-24"></div>
                            </div>
                        </div>
                        <div class="h-5 bg-gray-200 rounded w-16"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
            
            <!-- Empty State -->
            <div id="emptyState" class="hidden text-center py-12">
                <i class="fas fa-history text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-500">ยังไม่มีประวัติ</p>
            </div>
        </div>
    </div>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    
    let userId = null;
    let allHistory = [];
    let currentFilter = 'all';

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
                await loadHistory();
            } else {
                liff.login();
            }
        } catch (e) {
            console.error(e);
            showError('เกิดข้อผิดพลาด');
        }
    }

    async function loadHistory() {
        try {
            const response = await fetch(`${BASE_URL}/api/points-history.php?action=history&line_user_id=${userId}&limit=50`);
            const data = await response.json();
            
            document.getElementById('loadingSkeleton').classList.add('hidden');
            
            if (data.success) {
                // Update summary
                document.getElementById('totalPoints').textContent = numberFormat(data.user.total_points);
                document.getElementById('availablePoints').textContent = numberFormat(data.user.available_points);
                document.getElementById('usedPoints').textContent = numberFormat(data.user.used_points);
                
                allHistory = data.history;
                
                if (allHistory.length > 0) {
                    renderHistory(allHistory);
                } else {
                    document.getElementById('emptyState').classList.remove('hidden');
                }
            } else {
                showError(data.error || 'ไม่สามารถโหลดข้อมูลได้');
            }
        } catch (e) {
            console.error(e);
            showError('ไม่สามารถโหลดข้อมูลได้');
        }
    }

    function filterHistory(type) {
        currentFilter = type;
        
        // Update tabs
        ['all', 'earn', 'redeem'].forEach(t => {
            const tab = document.getElementById(`tab-${t}`);
            if (t === type) {
                tab.classList.add('bg-white', 'text-purple-600', 'shadow-sm');
                tab.classList.remove('text-gray-500');
            } else {
                tab.classList.remove('bg-white', 'text-purple-600', 'shadow-sm');
                tab.classList.add('text-gray-500');
            }
        });
        
        // Filter and render
        let filtered = allHistory;
        if (type !== 'all') {
            filtered = allHistory.filter(h => h.type === type);
        }
        
        if (filtered.length > 0) {
            document.getElementById('emptyState').classList.add('hidden');
            renderHistory(filtered);
        } else {
            document.getElementById('historyList').innerHTML = '';
            document.getElementById('emptyState').classList.remove('hidden');
        }
    }

    function renderHistory(history) {
        const container = document.getElementById('historyList');
        let html = '';
        
        history.forEach(item => {
            const isEarn = item.type === 'earn';
            const iconClass = isEarn ? 'fa-plus-circle text-green-500' : 'fa-minus-circle text-red-500';
            const bgClass = isEarn ? 'bg-green-50' : 'bg-red-50';
            const pointsClass = isEarn ? 'text-green-600' : 'text-red-500';
            const pointsPrefix = isEarn ? '+' : '';
            
            html += `
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 ${bgClass} rounded-full flex items-center justify-center">
                                <i class="fas ${iconClass}"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-800 text-sm">${escapeHtml(item.description)}</p>
                                <p class="text-xs text-gray-400">${item.formatted_date}</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold ${pointsClass}">${pointsPrefix}${numberFormat(Math.abs(item.points))}</p>
                            <p class="text-xs text-gray-400">คงเหลือ ${numberFormat(item.balance_after)}</p>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    function goBack() {
        if (liff.isInClient()) {
            window.location.href = `${BASE_URL}/liff-member-card.php?account=${ACCOUNT_ID}`;
        } else {
            window.history.back();
        }
    }

    function showError(msg) {
        document.getElementById('loadingSkeleton').classList.add('hidden');
        document.getElementById('historyList').innerHTML = `
            <div class="text-center py-12">
                <i class="fas fa-exclamation-circle text-5xl text-red-300 mb-4"></i>
                <p class="text-gray-500">${msg}</p>
            </div>
        `;
    }

    function numberFormat(num) {
        return new Intl.NumberFormat('th-TH').format(num || 0);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
