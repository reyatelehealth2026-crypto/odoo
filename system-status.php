<?php
/**
 * System Status - ตรวจสอบสถานะการทำงานระบบ Inbox V2
 * เช็คสถานะ services, database, และ dependencies ทั้งหมด
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Status check results
$checks = [];
$overallStatus = 'healthy';

// 1. Database Connection
try {
    $stmt = $db->query("SELECT 1");
    $checks['database'] = ['status' => 'ok', 'message' => 'เชื่อมต่อฐานข้อมูลสำเร็จ'];
} catch (Exception $e) {
    $checks['database'] = ['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล: ' . $e->getMessage()];
    $overallStatus = 'critical';
}

// 2. Vibe Selling Helper (V2 Toggle)
try {
    require_once __DIR__ . '/classes/VibeSellingHelper.php';
    $vibeHelper = VibeSellingHelper::getInstance($db);
    $v2Enabled = $vibeHelper->isV2Enabled($currentBotId);
    $checks['vibe_selling'] = [
        'status' => 'ok', 
        'message' => 'Vibe Selling Helper โหลดสำเร็จ',
        'v2_enabled' => $v2Enabled
    ];
} catch (Exception $e) {
    $checks['vibe_selling'] = ['status' => 'warning', 'message' => 'Vibe Selling Helper: ' . $e->getMessage()];
    if ($overallStatus === 'healthy') $overallStatus = 'degraded';
}

// 3. Inbox Service
try {
    require_once __DIR__ . '/classes/InboxService.php';
    $inboxService = new InboxService($db, $currentBotId);
    $checks['inbox_service'] = ['status' => 'ok', 'message' => 'Inbox Service พร้อมใช้งาน'];
} catch (Exception $e) {
    $checks['inbox_service'] = ['status' => 'error', 'message' => 'Inbox Service: ' . $e->getMessage()];
    $overallStatus = 'critical';
}

// 4. V2 Services
$v2Services = [
    'DrugPricingEngineService' => 'Drug Pricing Engine',
    'CustomerHealthEngineService' => 'Customer Health Engine', 
    'PharmacyImageAnalyzerService' => 'Pharmacy Image Analyzer',
    'PharmacyGhostDraftService' => 'Ghost Draft Service'
];

foreach ($v2Services as $class => $name) {
    try {
        require_once __DIR__ . "/classes/{$class}.php";
        $instance = new $class($db, $currentBotId);
        $checks["v2_{$class}"] = ['status' => 'ok', 'message' => "{$name} พร้อมใช้งาน"];
    } catch (Exception $e) {
        $checks["v2_{$class}"] = ['status' => 'warning', 'message' => "{$name}: " . $e->getMessage()];
        if ($overallStatus === 'healthy') $overallStatus = 'degraded';
    }
}

// 5. Required Tables Check
$requiredTables = [
    'users' => 'ตารางผู้ใช้',
    'messages' => 'ตารางข้อความ',
    'line_accounts' => 'ตารางบัญชี LINE',
    'user_tags' => 'ตารางแท็กผู้ใช้',
    'admin_users' => 'ตารางผู้ดูแลระบบ'
];

foreach ($requiredTables as $table => $name) {
    try {
        $stmt = $db->query("SELECT 1 FROM {$table} LIMIT 1");
        $checks["table_{$table}"] = ['status' => 'ok', 'message' => "{$name} ({$table}) พร้อมใช้งาน"];
    } catch (Exception $e) {
        $checks["table_{$table}"] = ['status' => 'error', 'message' => "{$name} ({$table}) ไม่พบ"];
        $overallStatus = 'critical';
    }
}

// 6. V2 Tables Check
$v2Tables = [
    'customer_health_profiles' => 'Health Profiles',
    'drug_pricing_rules' => 'Drug Pricing Rules',
    'ghost_draft_learning' => 'Ghost Draft Learning'
];

foreach ($v2Tables as $table => $name) {
    try {
        $stmt = $db->query("SELECT 1 FROM {$table} LIMIT 1");
        $checks["v2_table_{$table}"] = ['status' => 'ok', 'message' => "{$name} ({$table}) พร้อมใช้งาน"];
    } catch (Exception $e) {
        $checks["v2_table_{$table}"] = ['status' => 'warning', 'message' => "{$name} ({$table}) ยังไม่ได้ migrate"];
        if ($overallStatus === 'healthy') $overallStatus = 'degraded';
    }
}

// 7. LINE API Check
try {
    require_once __DIR__ . '/classes/LineAPI.php';
    require_once __DIR__ . '/classes/LineAccountManager.php';
    $lineManager = new LineAccountManager($db);
    $lineAPI = $lineManager->getLineAPI($currentBotId);
    $checks['line_api'] = ['status' => 'ok', 'message' => 'LINE API พร้อมใช้งาน'];
} catch (Exception $e) {
    $checks['line_api'] = ['status' => 'warning', 'message' => 'LINE API: ' . $e->getMessage()];
    if ($overallStatus === 'healthy') $overallStatus = 'degraded';
} catch (Error $e) {
    $checks['line_api'] = ['status' => 'warning', 'message' => 'LINE API: ' . $e->getMessage()];
    if ($overallStatus === 'healthy') $overallStatus = 'degraded';
}

// 8. AI Module Check
try {
    require_once __DIR__ . '/modules/AIChat/Autoloader.php';
    $adapter = new \Modules\AIChat\Adapters\GeminiChatAdapter($db, $currentBotId);
    $aiEnabled = $adapter->isEnabled();
    $checks['ai_module'] = [
        'status' => $aiEnabled ? 'ok' : 'warning',
        'message' => $aiEnabled ? 'AI Module เปิดใช้งานแล้ว' : 'AI Module ยังไม่ได้เปิดใช้งาน',
        'enabled' => $aiEnabled
    ];
} catch (Exception $e) {
    $checks['ai_module'] = ['status' => 'warning', 'message' => 'AI Module: ' . $e->getMessage()];
}

// 9. Message Stats
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM messages WHERE line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $totalMessages = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(*) as unread FROM messages WHERE line_account_id = ? AND direction = 'incoming' AND is_read = 0");
    $stmt->execute([$currentBotId]);
    $unreadMessages = $stmt->fetchColumn();
    
    $checks['message_stats'] = [
        'status' => 'ok',
        'message' => "ข้อความทั้งหมด: {$totalMessages}, ยังไม่อ่าน: {$unreadMessages}",
        'total' => $totalMessages,
        'unread' => $unreadMessages
    ];
} catch (Exception $e) {
    $checks['message_stats'] = ['status' => 'warning', 'message' => 'ไม่สามารถดึงสถิติข้อความ'];
}

// 10. User Stats
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE line_account_id = ?");
    $stmt->execute([$currentBotId]);
    $totalUsers = $stmt->fetchColumn();
    
    $checks['user_stats'] = [
        'status' => 'ok',
        'message' => "ผู้ใช้ทั้งหมด: {$totalUsers}",
        'total' => $totalUsers
    ];
} catch (Exception $e) {
    $checks['user_stats'] = ['status' => 'warning', 'message' => 'ไม่สามารถดึงสถิติผู้ใช้'];
}

$pageTitle = 'เช็คสถานะระบบ';
require_once 'includes/header.php';
?>

<div class="p-6 max-w-6xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
            <span class="text-3xl">🔍</span>
            เช็คสถานะระบบ Inbox V2
        </h1>
        <p class="text-gray-500 mt-1">ตรวจสอบสถานะการทำงานของระบบทั้งหมด</p>
    </div>

    <!-- Overall Status -->
    <div class="mb-6 p-4 rounded-xl <?php 
        echo $overallStatus === 'healthy' ? 'bg-green-50 border border-green-200' : 
            ($overallStatus === 'degraded' ? 'bg-yellow-50 border border-yellow-200' : 'bg-red-50 border border-red-200');
    ?>">
        <div class="flex items-center gap-3">
            <span class="text-4xl">
                <?php echo $overallStatus === 'healthy' ? '✅' : ($overallStatus === 'degraded' ? '⚠️' : '❌'); ?>
            </span>
            <div>
                <h2 class="text-lg font-semibold <?php 
                    echo $overallStatus === 'healthy' ? 'text-green-700' : 
                        ($overallStatus === 'degraded' ? 'text-yellow-700' : 'text-red-700');
                ?>">
                    <?php 
                    echo $overallStatus === 'healthy' ? 'ระบบทำงานปกติ' : 
                        ($overallStatus === 'degraded' ? 'ระบบทำงานได้บางส่วน' : 'ระบบมีปัญหา');
                    ?>
                </h2>
                <p class="text-sm text-gray-600">ตรวจสอบเมื่อ: <?php echo date('d/m/Y H:i:s'); ?></p>
            </div>
            <div class="ml-auto">
                <button onclick="location.reload()" class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 flex items-center gap-2">
                    <i class="fas fa-sync-alt"></i> รีเฟรช
                </button>
            </div>
        </div>
    </div>

    <!-- Status Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($checks as $key => $check): ?>
        <div class="bg-white rounded-xl border p-4 hover:shadow-md transition-shadow">
            <div class="flex items-start gap-3">
                <span class="text-2xl">
                    <?php 
                    echo $check['status'] === 'ok' ? '✅' : 
                        ($check['status'] === 'warning' ? '⚠️' : '❌');
                    ?>
                </span>
                <div class="flex-1 min-w-0">
                    <h3 class="font-medium text-gray-800 truncate">
                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $key))); ?>
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">
                        <?php echo htmlspecialchars($check['message']); ?>
                    </p>
                    <?php if (isset($check['v2_enabled'])): ?>
                    <span class="inline-block mt-2 px-2 py-1 text-xs rounded <?php echo $check['v2_enabled'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                        V2: <?php echo $check['v2_enabled'] ? 'เปิดใช้งาน' : 'ปิดอยู่'; ?>
                    </span>
                    <?php endif; ?>
                    <?php if (isset($check['enabled'])): ?>
                    <span class="inline-block mt-2 px-2 py-1 text-xs rounded <?php echo $check['enabled'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
                        <?php echo $check['enabled'] ? 'เปิดใช้งาน' : 'ปิดอยู่'; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quick Actions -->
    <div class="mt-6 bg-white rounded-xl border p-4">
        <h3 class="font-semibold text-gray-800 mb-3">🚀 Quick Actions</h3>
        <div class="flex flex-wrap gap-3">
            <a href="/inbox-v2" class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 flex items-center gap-2">
                <i class="fas fa-inbox"></i> เปิด Inbox V2
            </a>
            <a href="/inbox" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 flex items-center gap-2">
                <i class="fas fa-inbox"></i> เปิด Inbox V1
            </a>
            <a href="/settings?tab=vibe-selling" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 flex items-center gap-2">
                <i class="fas fa-cog"></i> ตั้งค่า Vibe Selling
            </a>
            <a href="/dev-dashboard" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 flex items-center gap-2">
                <i class="fas fa-code"></i> Dev Dashboard
            </a>
        </div>
    </div>

    <!-- System Info -->
    <div class="mt-6 bg-gray-50 rounded-xl border p-4">
        <h3 class="font-semibold text-gray-800 mb-3">📋 System Info</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-500">PHP Version:</span>
                <span class="font-medium"><?php echo phpversion(); ?></span>
            </div>
            <div>
                <span class="text-gray-500">Current Bot ID:</span>
                <span class="font-medium"><?php echo $currentBotId; ?></span>
            </div>
            <div>
                <span class="text-gray-500">Server Time:</span>
                <span class="font-medium"><?php echo date('H:i:s'); ?></span>
            </div>
            <div>
                <span class="text-gray-500">Memory Usage:</span>
                <span class="font-medium"><?php echo round(memory_get_usage() / 1024 / 1024, 2); ?> MB</span>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
