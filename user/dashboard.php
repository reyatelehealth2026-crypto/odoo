<?php
/**
 * User Dashboard - หน้าหลักสำหรับผู้ใช้ทั่วไป
 */
$pageTitle = 'Dashboard';
require_once '../includes/user_header.php';

// Get statistics for user's LINE account
$stats = [];

// Users stats
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE is_blocked = 0 AND line_account_id = ?");
$stmt->execute([$currentBotId]);
$stats['total_users'] = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE() AND line_account_id = ?");
$stmt->execute([$currentBotId]);
$stats['new_today'] = $stmt->fetchColumn();

// Messages stats
$stmt = $db->prepare("SELECT COUNT(*) FROM messages WHERE DATE(created_at) = CURDATE() AND line_account_id = ?");
$stmt->execute([$currentBotId]);
$stats['messages_today'] = $stmt->fetchColumn();

// Orders stats
$stats['total_orders'] = 0;
$stats['pending_orders'] = 0;
$stats['total_revenue'] = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $stats['total_orders'] = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $stats['pending_orders'] = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE status IN ('paid', 'confirmed', 'delivered') AND line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $stats['total_revenue'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Products stats
$stats['total_products'] = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM business_items WHERE is_active = 1 AND line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $stats['total_products'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Auto-reply stats
$stats['auto_replies'] = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM auto_replies WHERE is_active = 1 AND line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $stats['auto_replies'] = $stmt->fetchColumn();
} catch (Exception $e) {}

// Recent messages
$recentMessages = [];
try {
    $stmt = $db->prepare("SELECT m.*, u.display_name, u.picture_url FROM messages m JOIN users u ON m.user_id = u.id WHERE m.direction = 'incoming' AND m.line_account_id = ? ORDER BY m.created_at DESC LIMIT 5");
    $stmt->execute([$currentBotId]);
    $recentMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Recent users
$recentUsers = [];
try {
    $stmt = $db->prepare("SELECT * FROM users WHERE line_account_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$currentBotId]);
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

<!-- Welcome -->
<div class="mb-6 p-6 bg-gradient-to-r from-green-500 to-green-600 rounded-xl text-white">
    <h2 class="text-xl font-bold mb-1">สวัสดี, <?= htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']) ?>!</h2>
    <p class="text-green-100">ยินดีต้อนรับสู่ระบบจัดการ LINE OA ของคุณ</p>
</div>

<!-- Stats Row -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="stat-card">
        <div class="stat-icon bg-blue-100 text-blue-600"><i class="fas fa-users"></i></div>
        <div class="stat-content">
            <div class="stat-label">ลูกค้าทั้งหมด</div>
            <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
            <?php if ($stats['new_today'] > 0): ?>
            <div class="stat-change up">+<?= $stats['new_today'] ?> วันนี้</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-green-100 text-green-600"><i class="fas fa-comments"></i></div>
        <div class="stat-content">
            <div class="stat-label">ข้อความวันนี้</div>
            <div class="stat-value"><?= number_format($stats['messages_today']) ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-orange-100 text-orange-600"><i class="fas fa-shopping-cart"></i></div>
        <div class="stat-content">
            <div class="stat-label">คำสั่งซื้อ</div>
            <div class="stat-value"><?= number_format($stats['total_orders']) ?></div>
            <?php if ($stats['pending_orders'] > 0): ?>
            <div class="stat-change" style="color:#d97706"><?= $stats['pending_orders'] ?> รอดำเนินการ</div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon bg-emerald-100 text-emerald-600"><i class="fas fa-baht-sign"></i></div>
        <div class="stat-content">
            <div class="stat-label">รายได้</div>
            <div class="stat-value">฿<?= number_format($stats['total_revenue']) ?></div>
        </div>
    </div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Recent Messages -->
    <div class="card lg:col-span-2">
        <div class="card-header">
            <span class="card-title"><i class="fas fa-inbox mr-2 text-green-500"></i>ข้อความล่าสุด</span>
            <a href="messages.php" class="text-sm text-green-600 hover:underline">ดูทั้งหมด</a>
        </div>
        <div>
            <?php if (empty($recentMessages)): ?>
            <div class="p-8 text-center text-gray-400">ยังไม่มีข้อความ</div>
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
            <span class="card-title"><i class="fas fa-bolt mr-2 text-yellow-500"></i>ทางลัด</span>
        </div>
        <div class="p-4 grid grid-cols-2 gap-3">
            <a href="broadcast.php" class="quick-action">
                <div class="quick-action-icon bg-green-500"><i class="fas fa-bullhorn"></i></div>
                <span class="quick-action-label">Broadcast</span>
            </a>
            <a href="messages.php" class="quick-action">
                <div class="quick-action-icon bg-blue-500"><i class="fas fa-comments"></i></div>
                <span class="quick-action-label">ข้อความ</span>
            </a>
            <a href="orders.php" class="quick-action">
                <div class="quick-action-icon bg-orange-500"><i class="fas fa-receipt"></i></div>
                <span class="quick-action-label">คำสั่งซื้อ</span>
            </a>
            <a href="auto-reply.php" class="quick-action">
                <div class="quick-action-icon bg-pink-500"><i class="fas fa-robot"></i></div>
                <span class="quick-action-label">ตอบกลับอัตโนมัติ</span>
            </a>
        </div>
        
        <!-- Recent Users -->
        <div class="border-t">
            <div class="px-4 py-3 text-sm font-semibold text-gray-700">
                <i class="fas fa-user-plus mr-2 text-blue-500"></i>ลูกค้าใหม่
            </div>
            <?php if (empty($recentUsers)): ?>
            <div class="p-4 text-center text-gray-400 text-sm">ยังไม่มีลูกค้า</div>
            <?php else: ?>
            <?php foreach (array_slice($recentUsers, 0, 3) as $user): ?>
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
                    <div class="text-xs text-gray-400"><?= date('d/m H:i', strtotime($user['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/user_footer.php'; ?>
