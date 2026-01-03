<?php
/**
 * LIFF My Orders - ออเดอร์ของฉัน
 * Updated: Full details + Reorder button
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
    <title>ออเดอร์ของฉัน - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #11B0A6; --primary-dark: #0D9488; }
        body { font-family: 'Sarabun', sans-serif; background: #F8FAFC; }
        .status-pending { background: #FEF3C7; color: #D97706; }
        .status-paid { background: #DBEAFE; color: #2563EB; }
        .status-confirmed { background: #DBEAFE; color: #2563EB; }
        .status-processing { background: #E0E7FF; color: #4F46E5; }
        .status-shipping { background: #E0E7FF; color: #4F46E5; }
        .status-shipped { background: #E0E7FF; color: #4F46E5; }
        .status-delivered { background: #D1FAE5; color: #059669; }
        .status-completed { background: #D1FAE5; color: #059669; }
        .status-cancelled { background: #FEE2E2; color: #DC2626; }
        .tab-active { border-bottom: 3px solid var(--primary); color: var(--primary); font-weight: 600; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }
        .btn-primary:active { transform: scale(0.98); }
    </style>
</head>
<body class="min-h-screen pb-20">
    <!-- Header -->
    <div class="bg-gradient-to-r from-teal-500 to-teal-600 text-white sticky top-0 z-20">
        <div class="flex items-center justify-between p-4">
            <button onclick="goBack()" class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-white/20">
                <i class="fas fa-arrow-left text-xl"></i>
            </button>
            <h1 class="font-bold text-lg">ออเดอร์ของฉัน</h1>
            <div class="w-10"></div>
        </div>
        
        <!-- Tabs -->
        <div class="flex bg-white/10">
            <button onclick="filterOrders('all')" class="tab-btn flex-1 py-3 text-sm text-white/80 tab-active" data-tab="all">ทั้งหมด</button>
            <button onclick="filterOrders('pending')" class="tab-btn flex-1 py-3 text-sm text-white/80" data-tab="pending">รอดำเนินการ</button>
            <button onclick="filterOrders('shipping')" class="tab-btn flex-1 py-3 text-sm text-white/80" data-tab="shipping">กำลังจัดส่ง</button>
            <button onclick="filterOrders('completed')" class="tab-btn flex-1 py-3 text-sm text-white/80" data-tab="completed">สำเร็จ</button>
        </div>
    </div>

    <!-- Orders List -->
    <div class="p-4">
        <div id="ordersList" class="space-y-4">
            <!-- Loading Skeleton -->
            <div id="loadingSkeleton" class="space-y-4">
                <div class="bg-white rounded-2xl p-4 animate-pulse shadow-sm">
                    <div class="flex justify-between mb-3">
                        <div class="h-4 bg-gray-200 rounded w-32"></div>
                        <div class="h-6 bg-gray-200 rounded-full w-20"></div>
                    </div>
                    <div class="flex gap-3 mb-3">
                        <div class="w-20 h-20 bg-gray-200 rounded-xl"></div>
                        <div class="flex-1">
                            <div class="h-4 bg-gray-200 rounded w-3/4 mb-2"></div>
                            <div class="h-3 bg-gray-200 rounded w-1/2 mb-2"></div>
                            <div class="h-3 bg-gray-200 rounded w-1/3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    
    let userId = null;
    let allOrders = [];
    let currentTab = 'all';

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        if (!LIFF_ID) { showError('ไม่พบการตั้งค่า LIFF'); return; }
        
        try {
            await liff.init({ liffId: LIFF_ID });
            
            if (liff.isLoggedIn()) {
                const profile = await liff.getProfile();
                userId = profile.userId;
                await loadOrders();
            } else {
                liff.login();
            }
        } catch (e) {
            console.error('LIFF error:', e);
            showError('เกิดข้อผิดพลาด: ' + e.message);
        }
    }

    async function loadOrders() {
        try {
            const response = await fetch(`${BASE_URL}/api/orders.php?action=my_orders&line_user_id=${userId}&line_account_id=${ACCOUNT_ID}`);
            const data = await response.json();
            
            document.getElementById('loadingSkeleton').classList.add('hidden');
            
            if (data.success) {
                allOrders = data.orders || [];
                renderOrders(allOrders);
            } else {
                showError(data.message || 'ไม่สามารถโหลดข้อมูลได้');
            }
        } catch (e) {
            console.error('Load error:', e);
            showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
        }
    }

    function renderOrders(orders) {
        const container = document.getElementById('ordersList');
        
        if (orders.length === 0) {
            container.innerHTML = `
                <div class="text-center py-16">
                    <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-box-open text-4xl text-gray-300"></i>
                    </div>
                    <p class="text-gray-500 mb-4">ยังไม่มีออเดอร์</p>
                    <button onclick="goToShop()" class="px-6 py-3 btn-primary text-white rounded-xl font-bold shadow-lg">
                        <i class="fas fa-shopping-bag mr-2"></i>ไปช้อปปิ้ง
                    </button>
                </div>
            `;
            return;
        }
        
        let html = '';
        orders.forEach(order => {
            const statusInfo = getStatusInfo(order.status);
            const items = order.items || [];
            const itemCount = items.length;
            const totalQty = items.reduce((sum, i) => sum + parseInt(i.quantity || 1), 0);
            
            // Build items preview (show up to 3)
            let itemsHtml = '';
            items.slice(0, 3).forEach(item => {
                const itemName = item.name || item.product_name || 'สินค้า';
                const itemPrice = parseFloat(item.price || item.product_price || 0);
                const itemQty = parseInt(item.quantity || 1);
                const itemSubtotal = parseFloat(item.subtotal || (itemPrice * itemQty));
                const itemImage = item.image || item.image_url || 'https://via.placeholder.com/100?text=No+Image';
                
                itemsHtml += `
                    <div class="flex gap-3 py-2 border-b border-gray-100 last:border-0">
                        <div class="w-14 h-14 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                            <img src="${itemImage}" 
                                class="w-full h-full object-cover" 
                                onerror="this.src='https://via.placeholder.com/100?text=No+Image'">
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">${escapeHtml(itemName)}</p>
                            <p class="text-xs text-gray-500">฿${numberFormat(itemPrice)} x ${itemQty}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-sm font-bold text-gray-800">฿${numberFormat(itemSubtotal)}</p>
                        </div>
                    </div>
                `;
            });
            
            if (itemCount > 3) {
                itemsHtml += `<p class="text-xs text-teal-600 text-center py-2">+${itemCount - 3} รายการเพิ่มเติม</p>`;
            }
            
            // Delivery info
            const delivery = order.delivery_info || {};
            const deliveryType = delivery.type || 'shipping';
            const deliveryIcon = deliveryType === 'pickup' ? 'fa-store' : deliveryType === 'call_rider' ? 'fa-motorcycle' : 'fa-truck';
            const deliveryText = deliveryType === 'pickup' ? 'รับที่ร้าน' : deliveryType === 'call_rider' ? 'เรียก Rider' : 'ฝากส่ง';
            
            html += `
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <!-- Header -->
                    <div class="p-4 border-b border-gray-100">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-xs text-gray-400 mb-1">${formatDate(order.created_at)}</p>
                                <p class="font-bold text-gray-800">#${order.order_number || order.order_id}</p>
                            </div>
                            <span class="px-3 py-1 rounded-full text-xs font-bold ${statusInfo.class}">${statusInfo.text}</span>
                        </div>
                    </div>
                    
                    <!-- Items -->
                    <div class="px-4 py-2">
                        ${itemsHtml}
                    </div>
                    
                    <!-- Summary -->
                    <div class="px-4 py-3 bg-gray-50">
                        <div class="flex justify-between items-center text-sm mb-2">
                            <span class="text-gray-500"><i class="fas ${deliveryIcon} mr-1"></i>${deliveryText}</span>
                            <span class="text-gray-500">${totalQty} ชิ้น</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">รวมทั้งหมด</span>
                            <span class="text-lg font-bold text-teal-600">฿${numberFormat(order.total_amount)}</span>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div class="p-3 border-t border-gray-100 flex gap-2">
                        <button onclick="viewOrder('${order.order_id}')" class="flex-1 py-2.5 border-2 border-teal-500 text-teal-600 rounded-xl font-medium text-sm hover:bg-teal-50 transition-colors">
                            <i class="fas fa-eye mr-1"></i>ดูรายละเอียด
                        </button>
                        <button onclick="reorderAll('${order.order_id}')" class="flex-1 py-2.5 btn-primary text-white rounded-xl font-medium text-sm shadow-md">
                            <i class="fas fa-redo mr-1"></i>สั่งซื้อซ้ำ
                        </button>
                    </div>
                </div>
            `;
        });
        
        container.innerHTML = html;
    }

    function filterOrders(tab) {
        currentTab = tab;
        
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('tab-active');
            btn.classList.add('text-white/80');
            if (btn.dataset.tab === tab) {
                btn.classList.add('tab-active');
                btn.classList.remove('text-white/80');
            }
        });
        
        let filtered = allOrders;
        if (tab === 'pending') {
            filtered = allOrders.filter(o => ['pending', 'paid', 'confirmed', 'processing'].includes(o.status));
        } else if (tab === 'shipping') {
            filtered = allOrders.filter(o => ['shipping', 'shipped'].includes(o.status));
        } else if (tab === 'completed') {
            filtered = allOrders.filter(o => ['completed', 'delivered'].includes(o.status));
        }
        
        renderOrders(filtered);
    }

    function getStatusInfo(status) {
        const statuses = {
            'pending': { text: 'รอยืนยัน', class: 'status-pending' },
            'paid': { text: 'ชำระแล้ว', class: 'status-paid' },
            'confirmed': { text: 'ยืนยันแล้ว', class: 'status-confirmed' },
            'processing': { text: 'กำลังเตรียม', class: 'status-processing' },
            'shipping': { text: 'กำลังส่ง', class: 'status-shipping' },
            'shipped': { text: 'จัดส่งแล้ว', class: 'status-shipped' },
            'delivered': { text: 'ส่งแล้ว', class: 'status-delivered' },
            'completed': { text: 'สำเร็จ', class: 'status-completed' },
            'cancelled': { text: 'ยกเลิก', class: 'status-cancelled' },
        };
        return statuses[status] || { text: status, class: 'status-pending' };
    }

    async function reorderAll(orderId) {
        const order = allOrders.find(o => o.order_id == orderId);
        if (!order || !order.items || order.items.length === 0) {
            Swal.fire({ icon: 'error', title: 'ไม่พบรายการสินค้า', confirmButtonColor: '#11B0A6' });
            return;
        }
        
        const result = await Swal.fire({
            title: 'สั่งซื้อซ้ำ?',
            html: `เพิ่มสินค้า ${order.items.length} รายการลงตะกร้า`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'ใช่, เพิ่มลงตะกร้า',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#11B0A6'
        });
        
        if (!result.isConfirmed) return;
        
        Swal.fire({ title: 'กำลังเพิ่มสินค้า...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        
        let addedCount = 0;
        for (const item of order.items) {
            try {
                const response = await fetch(`${BASE_URL}/api/checkout.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'add_to_cart',
                        line_user_id: userId,
                        product_id: item.product_id,
                        quantity: item.quantity
                    })
                });
                const data = await response.json();
                if (data.success) addedCount++;
            } catch (e) {
                console.error('Add to cart error:', e);
            }
        }
        
        Swal.fire({
            icon: 'success',
            title: 'เพิ่มลงตะกร้าแล้ว!',
            text: `${addedCount} รายการ`,
            showCancelButton: true,
            confirmButtonText: 'ไปที่ตะกร้า',
            cancelButtonText: 'ช้อปต่อ',
            confirmButtonColor: '#11B0A6'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `${BASE_URL}/liff-checkout.php?user=${userId}&account=${ACCOUNT_ID}`;
            }
        });
    }

    function viewOrder(orderId) {
        window.location.href = `${BASE_URL}/liff-order-detail.php?order=${orderId}&account=${ACCOUNT_ID}`;
    }

    function goToShop() {
        window.location.href = `${BASE_URL}/liff-shop.php?account=${ACCOUNT_ID}`;
    }

    function goBack() {
        if (liff.isInClient()) {
            window.location.href = `${BASE_URL}/liff-member-card.php?account=${ACCOUNT_ID}`;
        } else {
            window.history.back();
        }
    }

    function showError(msg) {
        document.getElementById('loadingSkeleton')?.classList.add('hidden');
        document.getElementById('ordersList').innerHTML = `
            <div class="text-center py-12">
                <i class="fas fa-exclamation-circle text-5xl text-red-300 mb-4"></i>
                <p class="text-gray-500">${msg}</p>
            </div>
        `;
    }

    function numberFormat(num) { return new Intl.NumberFormat('th-TH').format(num || 0); }
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
