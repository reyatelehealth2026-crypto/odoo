<?php
/**
 * Admin Dashboard Wrapper
 * 
 * This file serves as the entry point for the admin dashboard.
 * It includes the original dashboard functionality from the root directory.
 * 
 * Requirements: 6.1, 6.3
 */

// Debug mode
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Set the base path for includes - we're in /admin/ folder
define('ADMIN_BASE_PATH', dirname(__DIR__) . '/');

// Check if installed
if (!file_exists(ADMIN_BASE_PATH . 'config/installed.lock') && file_exists(ADMIN_BASE_PATH . 'install/index.php')) {
    header('Location: ../install/');
    exit;
}

require_once ADMIN_BASE_PATH . 'config/config.php';
require_once ADMIN_BASE_PATH . 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Dashboard';

require_once ADMIN_BASE_PATH . 'includes/header.php';

// Get all statistics
$stats = [];

// Users stats
$stmt = $currentBotId 
    ? $db->prepare("SELECT COUNT(*) FROM users WHERE is_blocked = 0 AND line_account_id = ?")
    : $db->query("SELECT COUNT(*) FROM users WHERE is_blocked = 0");
if ($currentBotId) $stmt->execute([$currentBotId]);
$stats['total_users'] = $stmt->fetchColumn();

$stmt = $currentBotId
    ? $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND line_account_id = ?")
    : $db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
if ($currentBotId) $stmt->execute([$currentBotId]);
$stats['new_today'] = $stmt->fetchColumn();

// Messages stats
$stmt = $currentBotId
    ? $db->prepare("SELECT COUNT(*) FROM messages WHERE DATE(created_at) = CURDATE() AND line_account_id = ?")
    : $db->query("SELECT COUNT(*) FROM messages WHERE DATE(created_at) = CURDATE()");
if ($currentBotId) $stmt->execute([$currentBotId]);
$stats['messages_today'] = $stmt->fetchColumn();

// Orders stats (use transactions table - unified with LIFF)
$stats['total_orders'] = 0;
$stats['pending_orders'] = 0;
$stats['total_revenue'] = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM transactions");
    $stats['total_orders'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
    $stats['pending_orders'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COALESCE(SUM(grand_total), 0) FROM transactions WHERE status IN ('paid', 'confirmed', 'delivered')");
    $stats['total_revenue'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Products stats
$stats['total_products'] = 0;
$stats['low_stock'] = 0;
try {
    $productsTable = 'products';
    
    $stmt = $db->query("SELECT COUNT(*) FROM {$productsTable} WHERE is_active = 1");
    $stats['total_products'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM {$productsTable} WHERE stock > 0 AND stock <= 5 AND is_active = 1");
    $stats['low_stock'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Auto-reply & Broadcast stats
$stats['auto_replies'] = 0;
$stats['broadcasts'] = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) FROM auto_replies WHERE is_active = 1");
    $stats['auto_replies'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COUNT(*) FROM broadcasts WHERE status = 'sent'");
    $stats['broadcasts'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Pending slips count
$stats['pending_slips'] = 0;
try {
    $stmt = $db->query("SELECT COUNT(DISTINCT transaction_id) FROM payment_slips WHERE status = 'pending'");
    $stats['pending_slips'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Recent orders
$recentOrders = [];
try {
    $stmt = $db->query("SELECT o.*, u.display_name FROM transactions o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT 5");
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Recent messages
$recentMessages = [];
try {
    $stmt = $db->query("SELECT m.*, u.display_name, u.picture_url FROM messages m JOIN users u ON m.user_id = u.id WHERE m.direction = 'incoming' ORDER BY m.created_at DESC LIMIT 5");
    $recentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Recent users
$recentUsers = [];
try {
    $stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
    $recentUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>

<style>
.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    transition: all 0.2s;
}
.stat-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.stat-content { flex: 1; }
.stat-label { font-size: 13px; color: #64748b; margin-bottom: 4px; }
.stat-value { font-size: 24px; font-weight: 700; color: #1e293b; }
.stat-change { font-size: 12px; margin-top: 4px; }
.stat-change.up { color: #10b981; }
.stat-change.down { color: #ef4444; }
.mini-chart { height: 40px; margin-top: 8px; }

.card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
}
.card-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.card-title { font-weight: 600; color: #1e293b; }
.card-body { padding: 0; }

.list-item {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    border-bottom: 1px solid #f8fafc;
    transition: background 0.2s;
}
.list-item:hover { background: #f8fafc; }
.list-item:last-child { border-bottom: none; }

.avatar {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: white;
    overflow: hidden;
}
.avatar img { width: 100%; height: 100%; object-fit: cover; }

.badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
}
.badge-pending { background: #fef3c7; color: #d97706; }
.badge-paid { background: #dcfce7; color: #16a34a; }
.badge-confirmed { background: #dbeafe; color: #2563eb; }
.badge-shipping { background: #e0e7ff; color: #4f46e5; }
.badge-delivered { background: #d1fae5; color: #059669; }
.badge-cancelled { background: #fee2e2; color: #dc2626; }

.quick-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px;
    background: #f8fafc;
    border-radius: 10px;
    transition: all 0.2s;
    text-decoration: none;
}
.quick-action:hover {
    background: #f1f5f9;
    transform: translateY(-2px);
}
.quick-action-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
    margin-bottom: 8px;
}
.quick-action-label { font-size: 12px; color: #64748b; font-weight: 500; }
</style>

<?php if ($stats['pending_slips'] > 0): ?>
<!-- Pending Slips Alert -->
<div class="mb-6 p-4 bg-orange-100 border border-orange-300 rounded-xl flex items-center justify-between shadow-sm">
    <div class="flex items-center">
        <div class="w-12 h-12 bg-orange-500 rounded-full flex items-center justify-center mr-4">
            <i class="fas fa-receipt text-white text-xl"></i>
        </div>
        <div>
            <p class="font-bold text-orange-700 text-lg">มีสลิปรอตรวจสอบ <?= $stats['pending_slips'] ?> รายการ</p>
            <p class="text-sm text-orange-600">ลูกค้าอัพโหลดสลิปแล้ว กรุณาตรวจสอบและอนุมัติ</p>
        </div>
    </div>
    <a href="../shop/orders.php?pending_slip=1" class="px-6 py-3 bg-orange-500 text-white rounded-lg hover:bg-orange-600 font-semibold flex items-center gap-2">
        <i class="fas fa-eye"></i>ตรวจสอบเลย
    </a>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-4 mb-6">
    <div class="stat-card">
        <div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-users"></i></div>
        <div class="stat-content">
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
            <?php if ($stats['new_today'] > 0): ?>
            <div class="stat-change up">+<?= $stats['new_today'] ?> today</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-comments"></i></div>
        <div class="stat-content">
            <div class="stat-label">Messages Today</div>
            <div class="stat-value"><?= number_format($stats['messages_today']) ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-orange-100 text-orange-600"><i class="fas fa-shopping-cart"></i></div>
        <div class="stat-content">
            <div class="stat-label">Total Orders</div>
            <div class="stat-value"><?= number_format($stats['total_orders']) ?></div>
            <?php if ($stats['pending_orders'] > 0): ?>
            <div class="stat-change" style="color:#d97706"><?= $stats['pending_orders'] ?> pending</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-emerald-100 text-emerald-600"><i class="fas fa-baht-sign"></i></div>
        <div class="stat-content">
            <div class="stat-label">Revenue</div>
            <div class="stat-value">฿<?= number_format($stats['total_revenue']) ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-purple-100 text-purple-600"><i class="fas fa-box"></i></div>
        <div class="stat-content">
            <div class="stat-label">Products</div>
            <div class="stat-value"><?= number_format($stats['total_products']) ?></div>
            <?php if ($stats['low_stock'] > 0): ?>
            <div class="stat-change down"><?= $stats['low_stock'] ?> low stock</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-pink-100 text-pink-600"><i class="fas fa-robot"></i></div>
        <div class="stat-content">
            <div class="stat-label">Auto Replies</div>
            <div class="stat-value"><?= number_format($stats['auto_replies']) ?></div>
        </div>
    </div>
    
    <?php if ($stats['pending_slips'] > 0): ?>
    <a href="../shop/orders.php?pending_slip=1" class="stat-card hover:ring-2 hover:ring-orange-400">
        <div class="stat-icon bg-orange-100 text-orange-600"><i class="fas fa-receipt"></i></div>
        <div class="stat-content">
            <div class="stat-label">รอตรวจสลิป</div>
            <div class="stat-value text-orange-600"><?= number_format($stats['pending_slips']) ?></div>
            <div class="stat-change" style="color:#d97706">ต้องตรวจสอบ</div>
        </div>
    </a>
    <?php endif; ?>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Recent Orders -->
    <div class="card lg:col-span-2">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-receipt mr-2 text-orange-500"></i>Recent Orders</span>
            <a href="../shop/orders.php" class="text-sm text-green-600 hover:underline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentOrders)): ?>
            <div class="p-8 text-center text-gray-400">No orders yet</div>
            <?php else: ?>
            <?php foreach ($recentOrders as $order): ?>
            <div class="list-item">
                <div class="avatar bg-orange-500">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="flex-1 ml-3 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-sm">#<?= htmlspecialchars($order['order_number'] ?? $order['id']) ?></span>
                        <span class="badge badge-<?= $order['status'] ?>"><?= ucfirst($order['status']) ?></span>
                    </div>
                    <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($order['display_name'] ?? 'Customer') ?></div>
                </div>
                <div class="text-right">
                    <div class="font-semibold text-green-600">฿<?= number_format($order['grand_total'] ?? 0) ?></div>
                    <div class="text-xs text-gray-400"><?= date('d/m H:i', strtotime($order['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Users -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-user-plus mr-2 text-blue-500"></i>New Users</span>
            <a href="../users.php" class="text-sm text-green-600 hover:underline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentUsers)): ?>
            <div class="p-8 text-center text-gray-400">No users yet</div>
            <?php else: ?>
            <?php foreach ($recentUsers as $user): ?>
            <div class="list-item">
                <div class="avatar bg-blue-500">
                    <?php if (!empty($user['picture_url'])): ?>
                    <img src="<?= htmlspecialchars($user['picture_url']) ?>">
                    <?php else: ?>
                    <?= mb_substr($user['display_name'] ?? 'U', 0, 1) ?>
                    <?php endif; ?>
                </div>
                <div class="flex-1 ml-3 min-w-0">
                    <div class="font-medium text-sm truncate"><?= htmlspecialchars($user['display_name'] ?? 'Unknown') ?></div>
                    <div class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Messages & Quick Actions -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Messages -->
    <div class="card lg:col-span-2">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-inbox mr-2 text-green-500"></i>Recent Messages</span>
            <a href="../messages.php" class="text-sm text-green-600 hover:underline">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentMessages)): ?>
            <div class="p-8 text-center text-gray-400">No messages yet</div>
            <?php else: ?>
            <?php foreach ($recentMessages as $msg): ?>
            <div class="list-item">
                <div class="avatar bg-green-500">
                    <?php if (!empty($msg['picture_url'])): ?>
                    <img src="<?= htmlspecialchars($msg['picture_url']) ?>">
                    <?php else: ?>
                    <?= mb_substr($msg['display_name'] ?? 'U', 0, 1) ?>
                    <?php endif; ?>
                </div>
                <div class="flex-1 ml-3 min-w-0">
                    <div class="font-medium text-sm"><?= htmlspecialchars($msg['display_name'] ?? 'Unknown') ?></div>
                    <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars(mb_substr($msg['content'] ?? '', 0, 50)) ?></div>
                </div>
                <div class="text-xs text-gray-400"><?= date('H:i', strtotime($msg['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-bolt mr-2 text-yellow-500"></i>Quick Actions</span>
        </div>
        <div class="p-4 grid grid-cols-3 gap-3">
            <a href="../broadcast.php" class="quick-action">
                <div class="quick-action-icon bg-green-500"><i class="fas fa-bullhorn"></i></div>
                <span class="quick-action-label">Broadcast</span>
            </a>
            <a href="../messages.php" class="quick-action">
                <div class="quick-action-icon bg-blue-500"><i class="fas fa-comments"></i></div>
                <span class="quick-action-label">Messages</span>
            </a>
            <a href="../shop/orders.php" class="quick-action">
                <div class="quick-action-icon bg-orange-500"><i class="fas fa-receipt"></i></div>
                <span class="quick-action-label">Orders</span>
            </a>
            <a href="../shop/products.php" class="quick-action">
                <div class="quick-action-icon bg-purple-500"><i class="fas fa-box"></i></div>
                <span class="quick-action-label">Products</span>
            </a>
            <a href="../auto-reply.php" class="quick-action">
                <div class="quick-action-icon bg-pink-500"><i class="fas fa-robot"></i></div>
                <span class="quick-action-label">Auto Reply</span>
            </a>
            <a href="../analytics.php" class="quick-action">
                <div class="quick-action-icon bg-indigo-500"><i class="fas fa-chart-pie"></i></div>
                <span class="quick-action-label">Analytics</span>
            </a>
            <a href="sync-cny.php" class="quick-action">
                <div class="quick-action-icon bg-teal-500"><i class="fas fa-sync-alt"></i></div>
                <span class="quick-action-label">Sync CNY</span>
            </a>
        </div>
    </div>
</div>

<?php require_once ADMIN_BASE_PATH . 'includes/footer.php'; ?>
