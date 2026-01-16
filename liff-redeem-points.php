<?php
/**
 * LIFF Redeem Points - แลกของรางวัล
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
    <title>แลกของรางวัล - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/liff/assets/css/liff-app.css">
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: var(--bg-light); 
            margin: 0;
            padding: 0;
        }
    </style>
</head>
<body class="min-h-screen pb-24">
    <!-- Header -->
    <div class="bg-white shadow-sm sticky top-0 z-10">
        <div class="flex items-center justify-between p-4">
            <button onclick="goBack()" class="w-10 h-10 flex items-center justify-center text-gray-600">
                <i class="fas fa-arrow-left text-xl"></i>
            </button>
            <h1 class="font-bold text-lg text-gray-800">แลกของรางวัล</h1>
            <div class="flex items-center gap-2">
                <a href="liff-points-rules.php?account=<?= $lineAccountId ?>" class="w-10 h-10 flex items-center justify-center text-gray-600" title="กฎการสะสมคะแนน">
                    <i class="fas fa-info-circle text-xl"></i>
                </a>
                <a href="liff-points-history.php?account=<?= $lineAccountId ?>" class="w-10 h-10 flex items-center justify-center text-gray-600" title="ประวัติคะแนน">
                    <i class="fas fa-history text-xl"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Points Summary -->
    <div class="p-4">
        <div class="points-card rounded-2xl p-5 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-white/70 text-sm mb-1">แต้มที่ใช้ได้</p>
                    <p class="text-4xl font-bold" id="availablePoints">-</p>
                </div>
                <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center">
                    <i class="fas fa-coins text-3xl"></i>
                </div>
            </div>
            <div class="mt-4 pt-4 border-t border-white/20 flex justify-between text-sm">
                <div>
                    <span class="text-white/70">สะสมทั้งหมด</span>
                    <span class="ml-2 font-semibold" id="totalPoints">-</span>
                </div>
                <div>
                    <span class="text-white/70">ใช้ไปแล้ว</span>
                    <span class="ml-2 font-semibold" id="usedPoints">-</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="px-4 mb-4">
        <div class="flex gap-2 bg-gray-100 p-1 rounded-xl">
            <button onclick="switchTab('rewards')" id="tab-rewards" class="flex-1 py-2 px-4 rounded-lg text-sm font-medium bg-white text-purple-600 shadow-sm">
                <i class="fas fa-gift mr-1"></i>ของรางวัล
            </button>
            <button onclick="switchTab('my-rewards')" id="tab-my-rewards" class="flex-1 py-2 px-4 rounded-lg text-sm font-medium text-gray-500">
                <i class="fas fa-ticket-alt mr-1"></i>รางวัลของฉัน
            </button>
        </div>
    </div>

    <!-- Rewards List -->
    <div id="rewards-content" class="px-4">
        <!-- Loading -->
        <div id="loadingSkeleton" class="grid grid-cols-2 gap-3">
            <?php for ($i = 0; $i < 4; $i++): ?>
            <div class="bg-white rounded-xl overflow-hidden shadow-sm">
                <div class="h-32 shimmer"></div>
                <div class="p-3">
                    <div class="h-4 shimmer rounded mb-2"></div>
                    <div class="h-3 shimmer rounded w-2/3"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        
        <!-- Rewards Grid -->
        <div id="rewardsGrid" class="hidden grid grid-cols-2 gap-3"></div>
        
        <!-- Empty State -->
        <div id="emptyRewards" class="hidden text-center py-12">
            <i class="fas fa-gift text-6xl text-gray-300 mb-4"></i>
            <p class="text-gray-500 mb-2">ยังไม่มีของรางวัล</p>
            <p class="text-gray-400 text-sm">กำลังเตรียมของรางวัลสุดพิเศษให้คุณ</p>
        </div>
    </div>

    <!-- My Rewards List -->
    <div id="my-rewards-content" class="px-4 hidden">
        <div id="myRewardsList" class="space-y-3"></div>
        
        <!-- Empty State -->
        <div id="emptyMyRewards" class="hidden text-center py-12">
            <i class="fas fa-ticket-alt text-6xl text-gray-300 mb-4"></i>
            <p class="text-gray-500">ยังไม่มีรางวัลที่แลก</p>
            <button onclick="switchTab('rewards')" class="mt-4 px-4 py-2 bg-purple-600 text-white rounded-lg">
                ไปแลกรางวัล
            </button>
        </div>
    </div>

    <!-- Redeem Modal -->
    <div id="redeemModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm overflow-hidden">
            <div class="relative">
                <img id="modalImage" src="" class="w-full h-40 object-cover bg-gray-100">
                <button onclick="closeModal()" class="absolute top-2 right-2 w-8 h-8 bg-black/50 text-white rounded-full flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-4">
                <h3 id="modalName" class="font-bold text-lg text-gray-800 mb-2"></h3>
                <p id="modalDesc" class="text-sm text-gray-500 mb-4"></p>
                
                <div class="flex items-center justify-between p-3 bg-purple-50 rounded-xl mb-4">
                    <span class="text-gray-600">แต้มที่ใช้</span>
                    <span id="modalPoints" class="text-xl font-bold text-purple-600"></span>
                </div>
                
                <div id="modalStock" class="text-sm text-gray-500 mb-4 text-center"></div>
                
                <div class="flex gap-2">
                    <button onclick="closeModal()" class="flex-1 py-3 bg-gray-200 text-gray-700 rounded-xl font-medium">
                        ยกเลิก
                    </button>
                    <button id="confirmRedeemBtn" onclick="confirmRedeem()" class="flex-1 py-3 bg-purple-600 text-white rounded-xl font-medium">
                        <i class="fas fa-exchange-alt mr-1"></i>แลกเลย
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm p-6 text-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check text-4xl text-green-500"></i>
            </div>
            <h3 class="font-bold text-xl text-gray-800 mb-2">แลกสำเร็จ!</h3>
            <p class="text-gray-500 mb-4">รหัสรับรางวัลของคุณ</p>
            <div class="bg-gray-100 rounded-xl p-4 mb-4">
                <code id="redemptionCode" class="text-2xl font-bold text-purple-600 tracking-wider"></code>
            </div>
            <p class="text-sm text-gray-400 mb-4">กรุณาแสดงรหัสนี้เพื่อรับรางวัล</p>
            <button onclick="closeSuccessModal()" class="w-full py-3 bg-purple-600 text-white rounded-xl font-medium">
                เข้าใจแล้ว
            </button>
        </div>
    </div>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    
    let userId = null;
    let userPoints = 0;
    let rewards = [];
    let myRedemptions = [];
    let selectedReward = null;
    let currentTab = 'rewards';

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
                await loadData();
            } else {
                liff.login();
            }
        } catch (e) {
            console.error(e);
            showError('เกิดข้อผิดพลาด');
        }
    }

    async function loadData() {
        try {
            const response = await fetch(`${BASE_URL}/api/points-history.php?action=rewards&line_user_id=${userId}`);
            const data = await response.json();
            
            console.log('Rewards API Response:', data); // Debug log
            
            document.getElementById('loadingSkeleton').classList.add('hidden');
            
            if (data.success) {
                userPoints = data.available_points || 0;
                rewards = data.rewards || [];
                myRedemptions = data.my_redemptions || [];
                
                console.log('User Points:', userPoints);
                console.log('Rewards Count:', rewards.length);
                console.log('My Redemptions Count:', myRedemptions.length);
                
                // Update points display
                document.getElementById('availablePoints').textContent = numberFormat(userPoints);
                
                // Load user summary
                await loadUserSummary();
                
                // Render rewards
                if (rewards.length > 0) {
                    renderRewards();
                    document.getElementById('rewardsGrid').classList.remove('hidden');
                    document.getElementById('emptyRewards').classList.add('hidden');
                } else {
                    document.getElementById('rewardsGrid').classList.add('hidden');
                    document.getElementById('emptyRewards').classList.remove('hidden');
                }
                
                // Render my redemptions
                renderMyRedemptions();
            } else {
                showError(data.error || 'ไม่สามารถโหลดข้อมูลได้');
            }
        } catch (e) {
            console.error('Load data error:', e);
            showError('เกิดข้อผิดพลาดในการโหลดข้อมูล: ' + e.message);
        }
    }

    async function loadUserSummary() {
        try {
            const response = await fetch(`${BASE_URL}/api/points-history.php?action=history&line_user_id=${userId}&limit=1`);
            const data = await response.json();
            
            if (data.success && data.user) {
                document.getElementById('totalPoints').textContent = numberFormat(data.user.total_points);
                document.getElementById('usedPoints').textContent = numberFormat(data.user.used_points);
            }
        } catch (e) {
            console.error(e);
        }
    }

    function renderRewards() {
        const container = document.getElementById('rewardsGrid');
        let html = '';
        
        rewards.forEach(reward => {
            const canRedeem = userPoints >= reward.points_required && (reward.stock < 0 || reward.stock > 0);
            const isOutOfStock = reward.stock === 0;
            
            html += `
                <div class="reward-card bg-white rounded-xl overflow-hidden shadow-sm ${!canRedeem ? 'disabled' : ''}" 
                     onclick="${canRedeem ? `openRedeemModal(${reward.id})` : ''}">
                    <div class="relative h-32 bg-gray-100">
                        ${reward.image_url ? 
                            `<img src="${escapeHtml(reward.image_url)}" class="w-full h-full object-cover">` :
                            `<div class="w-full h-full flex items-center justify-center">
                                <i class="fas ${getRewardIcon(reward.reward_type)} text-4xl text-gray-300"></i>
                            </div>`
                        }
                        ${isOutOfStock ? 
                            `<div class="absolute inset-0 bg-black/50 flex items-center justify-center">
                                <span class="bg-red-500 text-white px-3 py-1 rounded-full text-sm font-medium">หมดแล้ว</span>
                            </div>` : ''
                        }
                        <div class="absolute top-2 right-2 px-2 py-1 bg-purple-600 text-white text-xs rounded-full font-medium">
                            ${numberFormat(reward.points_required)} แต้ม
                        </div>
                    </div>
                    <div class="p-3">
                        <h3 class="font-medium text-gray-800 text-sm line-clamp-1">${escapeHtml(reward.name)}</h3>
                        <p class="text-xs text-gray-400 mt-1 line-clamp-1">${escapeHtml(reward.description || '')}</p>
                        ${reward.stock >= 0 && reward.stock > 0 ? 
                            `<p class="text-xs text-orange-500 mt-1">เหลือ ${reward.stock} ชิ้น</p>` : ''
                        }
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
        container.classList.remove('hidden');
    }

    function renderMyRedemptions() {
        const container = document.getElementById('myRewardsList');
        
        if (!myRedemptions || myRedemptions.length === 0) {
            document.getElementById('emptyMyRewards').classList.remove('hidden');
            return;
        }
        
        let html = '';
        myRedemptions.forEach(r => {
            const statusClass = getStatusClass(r.status);
            const statusLabel = getStatusLabel(r.status);
            
            html += `
                <div class="bg-white rounded-xl p-4 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex-shrink-0 overflow-hidden">
                            ${r.image_url ? 
                                `<img src="${escapeHtml(r.image_url)}" class="w-full h-full object-cover">` :
                                `<div class="w-full h-full flex items-center justify-center">
                                    <i class="fas fa-gift text-2xl text-gray-300"></i>
                                </div>`
                            }
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-medium text-gray-800 text-sm">${escapeHtml(r.reward_name)}</h4>
                            <p class="text-xs text-gray-400 mt-1">${r.formatted_date || ''}</p>
                            <div class="flex items-center justify-between mt-2">
                                <span class="px-2 py-1 text-xs rounded-full ${statusClass}">${statusLabel}</span>
                                <code class="text-xs bg-gray-100 px-2 py-1 rounded">${escapeHtml(r.redemption_code)}</code>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    function switchTab(tab) {
        currentTab = tab;
        
        // Update tab buttons
        ['rewards', 'my-rewards'].forEach(t => {
            const tabBtn = document.getElementById(`tab-${t}`);
            const content = document.getElementById(`${t}-content`);
            
            if (t === tab) {
                tabBtn.classList.add('bg-white', 'text-purple-600', 'shadow-sm');
                tabBtn.classList.remove('text-gray-500');
                content.classList.remove('hidden');
            } else {
                tabBtn.classList.remove('bg-white', 'text-purple-600', 'shadow-sm');
                tabBtn.classList.add('text-gray-500');
                content.classList.add('hidden');
            }
        });
    }

    function openRedeemModal(rewardId) {
        selectedReward = rewards.find(r => r.id === rewardId);
        if (!selectedReward) return;
        
        document.getElementById('modalImage').src = selectedReward.image_url || 'https://via.placeholder.com/400x200?text=Reward';
        document.getElementById('modalName').textContent = selectedReward.name;
        document.getElementById('modalDesc').textContent = selectedReward.description || 'ไม่มีรายละเอียด';
        document.getElementById('modalPoints').textContent = numberFormat(selectedReward.points_required) + ' แต้ม';
        
        let stockText = '';
        if (selectedReward.stock < 0) {
            stockText = '<i class="fas fa-infinity mr-1"></i>ไม่จำกัดจำนวน';
        } else {
            stockText = `<i class="fas fa-box mr-1"></i>เหลือ ${selectedReward.stock} ชิ้น`;
        }
        document.getElementById('modalStock').innerHTML = stockText;
        
        // Check if can redeem
        const canRedeem = userPoints >= selectedReward.points_required;
        const btn = document.getElementById('confirmRedeemBtn');
        if (canRedeem) {
            btn.disabled = false;
            btn.classList.remove('bg-gray-400');
            btn.classList.add('bg-purple-600');
        } else {
            btn.disabled = true;
            btn.classList.add('bg-gray-400');
            btn.classList.remove('bg-purple-600');
            btn.innerHTML = `<i class="fas fa-lock mr-1"></i>แต้มไม่พอ`;
        }
        
        document.getElementById('redeemModal').classList.remove('hidden');
        document.getElementById('redeemModal').classList.add('flex');
    }

    function closeModal() {
        document.getElementById('redeemModal').classList.add('hidden');
        document.getElementById('redeemModal').classList.remove('flex');
        selectedReward = null;
    }

    async function confirmRedeem() {
        if (!selectedReward || !userId) return;
        
        const btn = document.getElementById('confirmRedeemBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังแลก...';
        
        try {
            const formData = new FormData();
            formData.append('action', 'redeem');
            formData.append('line_user_id', userId);
            formData.append('reward_id', selectedReward.id);
            
            const response = await fetch(`${BASE_URL}/api/points-history.php`, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            
            if (data.success) {
                closeModal();
                showSuccess(data.redemption_code);
                
                // Update points
                userPoints -= selectedReward.points_required;
                document.getElementById('availablePoints').textContent = numberFormat(userPoints);
                
                // Reload data
                await loadData();
            } else {
                alert(data.error || 'ไม่สามารถแลกรางวัลได้');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-exchange-alt mr-1"></i>แลกเลย';
            }
        } catch (e) {
            console.error(e);
            alert('เกิดข้อผิดพลาด');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-exchange-alt mr-1"></i>แลกเลย';
        }
    }

    function showSuccess(code) {
        document.getElementById('redemptionCode').textContent = code;
        document.getElementById('successModal').classList.remove('hidden');
        document.getElementById('successModal').classList.add('flex');
        
        // Confetti effect
        createConfetti();
    }

    function closeSuccessModal() {
        document.getElementById('successModal').classList.add('hidden');
        document.getElementById('successModal').classList.remove('flex');
    }

    function createConfetti() {
        const colors = ['#667eea', '#764ba2', '#f59e0b', '#10b981', '#ef4444'];
        for (let i = 0; i < 50; i++) {
            const confetti = document.createElement('div');
            confetti.className = 'confetti';
            confetti.style.left = Math.random() * 100 + 'vw';
            confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
            confetti.style.animationDelay = Math.random() * 2 + 's';
            document.body.appendChild(confetti);
            
            setTimeout(() => confetti.remove(), 3000);
        }
    }

    function getRewardIcon(type) {
        const icons = {
            'discount': 'fa-percent',
            'shipping': 'fa-truck',
            'gift': 'fa-gift',
            'product': 'fa-box',
            'coupon': 'fa-ticket-alt'
        };
        return icons[type] || 'fa-gift';
    }

    function getStatusClass(status) {
        const classes = {
            'pending': 'bg-orange-100 text-orange-700',
            'approved': 'bg-blue-100 text-blue-700',
            'delivered': 'bg-green-100 text-green-700',
            'cancelled': 'bg-red-100 text-red-700'
        };
        return classes[status] || 'bg-gray-100 text-gray-700';
    }

    function getStatusLabel(status) {
        const labels = {
            'pending': 'รอดำเนินการ',
            'approved': 'อนุมัติแล้ว',
            'delivered': 'รับแล้ว',
            'cancelled': 'ยกเลิก'
        };
        return labels[status] || status;
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
        document.getElementById('rewardsGrid').innerHTML = `
            <div class="col-span-2 text-center py-12">
                <i class="fas fa-exclamation-circle text-5xl text-red-300 mb-4"></i>
                <p class="text-gray-500">${msg}</p>
            </div>
        `;
        document.getElementById('rewardsGrid').classList.remove('hidden');
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
