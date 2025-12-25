<?php
/**
 * Loyalty Rewards Management - จัดการรางวัลแลกแต้ม (Admin)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_SESSION['current_bot_id'] ?? null;
$adminId = $_SESSION['admin_user']['id'] ?? null;
$pageTitle = 'จัดการรางวัลแลกแต้ม';

$loyalty = new LoyaltyPoints($db, $lineAccountId);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'create':
                $data = [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'points_required' => (int)($_POST['points_required'] ?? 0),
                    'reward_type' => $_POST['reward_type'] ?? 'gift',
                    'reward_value' => trim($_POST['reward_value'] ?? ''),
                    'stock' => (int)($_POST['stock'] ?? -1),
                    'max_per_user' => (int)($_POST['max_per_user'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'image_url' => trim($_POST['image_url'] ?? '')
                ];
                
                if (empty($data['name']) || $data['points_required'] <= 0) {
                    echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบ']);
                    exit;
                }
                
                $id = $loyalty->createReward($data);
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'เพิ่มรางวัลสำเร็จ']);
                exit;

            case 'update':
                $id = (int)($_POST['id'] ?? 0);
                $data = [
                    'name' => trim($_POST['name'] ?? ''),
                    'description' => trim($_POST['description'] ?? ''),
                    'points_required' => (int)($_POST['points_required'] ?? 0),
                    'stock' => (int)($_POST['stock'] ?? -1),
                    'max_per_user' => (int)($_POST['max_per_user'] ?? 0),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    'image_url' => trim($_POST['image_url'] ?? '')
                ];
                
                $loyalty->updateReward($id, $data);
                echo json_encode(['success' => true, 'message' => 'อัปเดตสำเร็จ']);
                exit;
                
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                $loyalty->deleteReward($id);
                echo json_encode(['success' => true, 'message' => 'ลบสำเร็จ']);
                exit;
                
            case 'toggle':
                $id = (int)($_POST['id'] ?? 0);
                $reward = $loyalty->getReward($id);
                if ($reward) {
                    $loyalty->updateReward($id, ['is_active' => $reward['is_active'] ? 0 : 1]);
                    echo json_encode(['success' => true, 'is_active' => !$reward['is_active']]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'ไม่พบรางวัล']);
                }
                exit;
                
            case 'update_redemption':
                $redemptionId = (int)($_POST['redemption_id'] ?? 0);
                $status = $_POST['status'] ?? '';
                $notes = trim($_POST['notes'] ?? '');
                
                if (in_array($status, ['approved', 'delivered', 'cancelled'])) {
                    $loyalty->updateRedemptionStatus($redemptionId, $status, $adminId, $notes);
                    echo json_encode(['success' => true, 'message' => 'อัปเดตสถานะสำเร็จ']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'สถานะไม่ถูกต้อง']);
                }
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Get data
$rewards = $loyalty->getRewards(false);
$summary = $loyalty->getPointsSummary();
$settings = $loyalty->getSettings();
$pendingRedemptions = $loyalty->getAllRedemptions('pending', 20);
$recentRedemptions = $loyalty->getAllRedemptions(null, 50);

// Tab
$tab = $_GET['tab'] ?? 'rewards';

require_once __DIR__ . '/includes/header.php';
?>

<!-- Stats Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-coins text-purple-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">แต้มที่แจกไป</p>
                <p class="text-xl font-bold text-purple-600"><?= number_format($summary['total_issued']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-exchange-alt text-green-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">แต้มที่ใช้ไป</p>
                <p class="text-xl font-bold text-green-600"><?= number_format($summary['total_redeemed']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-gift text-blue-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">รางวัลที่เปิดใช้</p>
                <p class="text-xl font-bold text-blue-600"><?= number_format($summary['active_rewards']) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-clock text-orange-600 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">รอดำเนินการ</p>
                <p class="text-xl font-bold text-orange-600"><?= number_format($summary['pending_redemptions']) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="bg-white rounded-xl shadow mb-6">
    <div class="flex border-b">
        <a href="?tab=rewards" class="px-6 py-3 font-medium <?= $tab === 'rewards' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-gift mr-2"></i>รางวัล
        </a>
        <a href="?tab=redemptions" class="px-6 py-3 font-medium <?= $tab === 'redemptions' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-history mr-2"></i>ประวัติการแลก
            <?php if ($summary['pending_redemptions'] > 0): ?>
            <span class="ml-1 px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full"><?= $summary['pending_redemptions'] ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=settings" class="px-6 py-3 font-medium <?= $tab === 'settings' ? 'text-purple-600 border-b-2 border-purple-600' : 'text-gray-500 hover:text-gray-700' ?>">
            <i class="fas fa-cog mr-2"></i>ตั้งค่า
        </a>
    </div>
</div>

<?php if ($tab === 'rewards'): ?>
<!-- Rewards Tab -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-gift mr-2 text-purple-500"></i>รายการรางวัล</h2>
        <button onclick="openModal()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-plus mr-1"></i>เพิ่มรางวัล
        </button>
    </div>
    
    <?php if (empty($rewards)): ?>
    <div class="p-8 text-center">
        <i class="fas fa-gift text-5xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">ยังไม่มีรางวัล</p>
        <button onclick="openModal()" class="mt-4 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
            <i class="fas fa-plus mr-1"></i>เพิ่มรางวัลแรก
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รางวัล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">ประเภท</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">แต้มที่ใช้</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">คงเหลือ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($rewards as $reward): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0 overflow-hidden">
                                <?php if ($reward['image_url']): ?>
                                <img src="<?= htmlspecialchars($reward['image_url']) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                <i class="fas <?= getRewardIcon($reward['reward_type']) ?> text-xl text-gray-400"></i>
                                <?php endif; ?>
                            </div>
                            <div class="ml-3">
                                <p class="font-medium text-gray-800"><?= htmlspecialchars($reward['name']) ?></p>
                                <p class="text-xs text-gray-500 line-clamp-1"><?= htmlspecialchars($reward['description'] ?? '-') ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?= getRewardTypeBadge($reward['reward_type']) ?>">
                            <?= getRewardTypeLabel($reward['reward_type']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center font-bold text-purple-600"><?= number_format($reward['points_required']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($reward['stock'] < 0): ?>
                        <span class="text-green-600">ไม่จำกัด</span>
                        <?php elseif ($reward['stock'] == 0): ?>
                        <span class="text-red-600 font-bold">หมด</span>
                        <?php else: ?>
                        <span class="font-medium"><?= number_format($reward['stock']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleReward(<?= $reward['id'] ?>)" class="px-3 py-1 rounded-full text-xs font-medium <?= $reward['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                            <?= $reward['is_active'] ? 'เปิดใช้งาน' : 'ปิดใช้งาน' ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="editReward(<?= htmlspecialchars(json_encode($reward)) ?>)" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteReward(<?= $reward['id'] ?>, '<?= htmlspecialchars($reward['name']) ?>')" class="p-2 text-red-600 hover:bg-red-50 rounded-lg" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'redemptions'): ?>
<!-- Redemptions Tab -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-history mr-2 text-purple-500"></i>ประวัติการแลกรางวัล</h2>
    </div>
    
    <?php if (empty($recentRedemptions)): ?>
    <div class="p-8 text-center">
        <i class="fas fa-inbox text-5xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">ยังไม่มีการแลกรางวัล</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้แลก</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">รางวัล</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">แต้มที่ใช้</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">รหัส</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">วันที่</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($recentRedemptions as $r): ?>
                <tr class="hover:bg-gray-50 <?= $r['status'] === 'pending' ? 'bg-orange-50' : '' ?>">
                    <td class="px-4 py-3">
                        <div class="flex items-center">
                            <img src="<?= htmlspecialchars($r['picture_url'] ?? 'https://via.placeholder.com/40') ?>" class="w-8 h-8 rounded-full">
                            <span class="ml-2 text-sm"><?= htmlspecialchars($r['display_name'] ?? 'Unknown') ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars($r['reward_name']) ?></td>
                    <td class="px-4 py-3 text-center font-medium text-purple-600"><?= number_format($r['points_used']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <code class="px-2 py-1 bg-gray-100 rounded text-xs"><?= htmlspecialchars($r['redemption_code']) ?></code>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 text-xs rounded-full <?= getStatusBadge($r['status']) ?>">
                            <?= getStatusLabel($r['status']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500">
                        <?= date('d/m/Y H:i', strtotime($r['created_at'])) ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($r['status'] === 'pending'): ?>
                        <button onclick="updateRedemption(<?= $r['id'] ?>, 'approved')" class="px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600" title="อนุมัติ">
                            <i class="fas fa-check"></i>
                        </button>
                        <button onclick="updateRedemption(<?= $r['id'] ?>, 'cancelled')" class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600" title="ยกเลิก">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php elseif ($r['status'] === 'approved'): ?>
                        <button onclick="updateRedemption(<?= $r['id'] ?>, 'delivered')" class="px-2 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600" title="ส่งมอบแล้ว">
                            <i class="fas fa-truck"></i>
                        </button>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php elseif ($tab === 'settings'): ?>
<!-- Settings Tab -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b">
        <h2 class="font-semibold text-gray-800"><i class="fas fa-cog mr-2 text-purple-500"></i>ตั้งค่าระบบแต้ม</h2>
    </div>
    <form id="settingsForm" class="p-6 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">แต้มต่อบาท</label>
                <input type="number" name="points_per_baht" value="<?= $settings['points_per_baht'] ?? 1 ?>" min="0" step="0.01" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                <p class="text-xs text-gray-500 mt-1">ทุก 1 บาท ได้กี่แต้ม</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">ยอดขั้นต่ำที่ได้แต้ม (บาท)</label>
                <input type="number" name="min_order_for_points" value="<?= $settings['min_order_for_points'] ?? 0 ?>" min="0" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                <p class="text-xs text-gray-500 mt-1">0 = ไม่มีขั้นต่ำ</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">แต้มหมดอายุ (วัน)</label>
                <input type="number" name="points_expiry_days" value="<?= $settings['points_expiry_days'] ?? 365 ?>" min="0" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                <p class="text-xs text-gray-500 mt-1">0 = ไม่หมดอายุ</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">สถานะระบบ</label>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" <?= ($settings['is_active'] ?? 1) ? 'checked' : '' ?> class="w-5 h-5 text-purple-600 rounded">
                    <span class="ml-2">เปิดใช้งานระบบแต้ม</span>
                </label>
            </div>
        </div>
        <div class="pt-4 border-t">
            <button type="submit" class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                <i class="fas fa-save mr-1"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Add/Edit Modal -->
<div id="rewardModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-semibold text-lg" id="modalTitle">เพิ่มรางวัล</h3>
            <button onclick="closeModal()" class="p-2 hover:bg-gray-100 rounded-lg"><i class="fas fa-times"></i></button>
        </div>
        <form id="rewardForm" class="p-4 space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" value="">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อรางวัล <span class="text-red-500">*</span></label>
                <input type="text" name="name" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="เช่น ส่วนลด 50 บาท">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด</label>
                <textarea name="description" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="รายละเอียดเพิ่มเติม"></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ประเภท</label>
                    <select name="reward_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        <option value="discount">ส่วนลด</option>
                        <option value="shipping">ค่าส่งฟรี</option>
                        <option value="gift">ของแถม</option>
                        <option value="product">สินค้า</option>
                        <option value="coupon">คูปอง</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">แต้มที่ใช้ <span class="text-red-500">*</span></label>
                    <input type="number" name="points_required" required min="1" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="100">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">มูลค่า/รหัส</label>
                <input type="text" name="reward_value" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="เช่น 50 (บาท) หรือ COUPON123">
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">จำนวนคงเหลือ</label>
                    <input type="number" name="stock" value="-1" min="-1" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <p class="text-xs text-gray-500 mt-1">-1 = ไม่จำกัด</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">จำกัดต่อคน</label>
                    <input type="number" name="max_per_user" value="0" min="0" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    <p class="text-xs text-gray-500 mt-1">0 = ไม่จำกัด</p>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">URL รูปภาพ</label>
                <input type="url" name="image_url" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500" placeholder="https://...">
            </div>
            
            <div>
                <label class="flex items-center cursor-pointer">
                    <input type="checkbox" name="is_active" value="1" checked class="w-5 h-5 text-purple-600 rounded">
                    <span class="ml-2">เปิดใช้งาน</span>
                </label>
            </div>
            
            <div class="pt-4 border-t flex gap-2">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function openModal() {
    document.getElementById('modalTitle').textContent = 'เพิ่มรางวัล';
    document.getElementById('rewardForm').reset();
    document.querySelector('[name="action"]').value = 'create';
    document.querySelector('[name="id"]').value = '';
    document.querySelector('[name="is_active"]').checked = true;
    document.getElementById('rewardModal').classList.remove('hidden');
    document.getElementById('rewardModal').classList.add('flex');
}

function closeModal() {
    document.getElementById('rewardModal').classList.add('hidden');
    document.getElementById('rewardModal').classList.remove('flex');
}

function editReward(reward) {
    document.getElementById('modalTitle').textContent = 'แก้ไขรางวัล';
    document.querySelector('[name="action"]').value = 'update';
    document.querySelector('[name="id"]').value = reward.id;
    document.querySelector('[name="name"]').value = reward.name || '';
    document.querySelector('[name="description"]').value = reward.description || '';
    document.querySelector('[name="reward_type"]').value = reward.reward_type || 'gift';
    document.querySelector('[name="points_required"]').value = reward.points_required || 0;
    document.querySelector('[name="reward_value"]').value = reward.reward_value || '';
    document.querySelector('[name="stock"]').value = reward.stock ?? -1;
    document.querySelector('[name="max_per_user"]').value = reward.max_per_user || 0;
    document.querySelector('[name="image_url"]').value = reward.image_url || '';
    document.querySelector('[name="is_active"]').checked = reward.is_active == 1;
    document.getElementById('rewardModal').classList.remove('hidden');
    document.getElementById('rewardModal').classList.add('flex');
}

async function deleteReward(id, name) {
    const result = await Swal.fire({
        title: 'ยืนยันการลบ',
        html: `ต้องการลบรางวัล <b>${name}</b>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#EF4444',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก'
    });
    if (!result.isConfirmed) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    
    const res = await fetch('loyalty-rewards.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        Swal.fire({ icon: 'success', title: 'ลบสำเร็จ', timer: 1500, showConfirmButton: false });
        setTimeout(() => location.reload(), 1500);
    } else {
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message });
    }
}

async function toggleReward(id) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    
    const res = await fetch('loyalty-rewards.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        location.reload();
    }
}

async function updateRedemption(id, status) {
    const labels = { approved: 'อนุมัติ', delivered: 'ส่งมอบแล้ว', cancelled: 'ยกเลิก' };
    const result = await Swal.fire({
        title: `ยืนยัน${labels[status]}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก'
    });
    if (!result.isConfirmed) return;
    
    const formData = new FormData();
    formData.append('action', 'update_redemption');
    formData.append('redemption_id', id);
    formData.append('status', status);
    
    const res = await fetch('loyalty-rewards.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        Swal.fire({ icon: 'success', title: 'สำเร็จ', timer: 1500, showConfirmButton: false });
        setTimeout(() => location.reload(), 1500);
    } else {
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message });
    }
}

// Form submit
document.getElementById('rewardForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    const res = await fetch('loyalty-rewards.php', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        closeModal();
        Swal.fire({ icon: 'success', title: data.message, timer: 1500, showConfirmButton: false });
        setTimeout(() => location.reload(), 1500);
    } else {
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: data.message });
    }
});

// Settings form
document.getElementById('settingsForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    // TODO: Implement settings save via API
    Swal.fire({ icon: 'info', title: 'ฟีเจอร์นี้กำลังพัฒนา', text: 'กรุณาแก้ไขในฐานข้อมูลโดยตรง' });
});

// Close modal on outside click
document.getElementById('rewardModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php
// Helper functions
function getRewardIcon($type) {
    $icons = [
        'discount' => 'fa-percent',
        'shipping' => 'fa-truck',
        'gift' => 'fa-gift',
        'product' => 'fa-box',
        'coupon' => 'fa-ticket-alt'
    ];
    return $icons[$type] ?? 'fa-gift';
}

function getRewardTypeBadge($type) {
    $badges = [
        'discount' => 'bg-green-100 text-green-700',
        'shipping' => 'bg-blue-100 text-blue-700',
        'gift' => 'bg-pink-100 text-pink-700',
        'product' => 'bg-orange-100 text-orange-700',
        'coupon' => 'bg-purple-100 text-purple-700'
    ];
    return $badges[$type] ?? 'bg-gray-100 text-gray-700';
}

function getRewardTypeLabel($type) {
    $labels = [
        'discount' => 'ส่วนลด',
        'shipping' => 'ค่าส่งฟรี',
        'gift' => 'ของแถม',
        'product' => 'สินค้า',
        'coupon' => 'คูปอง'
    ];
    return $labels[$type] ?? $type;
}

function getStatusBadge($status) {
    $badges = [
        'pending' => 'bg-orange-100 text-orange-700',
        'approved' => 'bg-green-100 text-green-700',
        'delivered' => 'bg-blue-100 text-blue-700',
        'cancelled' => 'bg-red-100 text-red-700'
    ];
    return $badges[$status] ?? 'bg-gray-100 text-gray-700';
}

function getStatusLabel($status) {
    $labels = [
        'pending' => 'รอดำเนินการ',
        'approved' => 'อนุมัติแล้ว',
        'delivered' => 'ส่งมอบแล้ว',
        'cancelled' => 'ยกเลิก'
    ];
    return $labels[$status] ?? $status;
}

require_once __DIR__ . '/includes/footer.php';
?>
