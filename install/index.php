<?php
/**
 * LINE CRM Installer
 * ติดตั้งฐานข้อมูลทั้งหมด
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../config/database.php';

$step = $_GET['step'] ?? 'check';
$message = '';
$error = '';

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
try {
    $db = Database::getInstance()->getConnection();
    $dbConnected = true;
} catch (Exception $e) {
    $dbConnected = false;
    $error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . $e->getMessage();
}

// รัน Installation
if ($step === 'install' && $dbConnected) {
    try {
        // อ่านไฟล์ SQL
        $sqlFile = file_get_contents('../database/install.sql');
        
        // แยก statements
        $statements = array_filter(
            preg_split('/;\s*$/m', $sqlFile),
            function($stmt) {
                $stmt = trim($stmt);
                return !empty($stmt) && strpos($stmt, '--') !== 0;
            }
        );
        
        $success = 0;
        $errors = 0;
        $results = [];
        
        foreach ($statements as $sql) {
            $sql = trim($sql);
            if (empty($sql) || strpos($sql, '--') === 0) continue;
            
            try {
                $db->exec($sql);
                $success++;
                
                // ดึงชื่อตาราง
                if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $sql, $m)) {
                    $results[] = ['status' => 'success', 'table' => $m[1]];
                }
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    $results[] = ['status' => 'skip', 'message' => 'Already exists'];
                } else {
                    $errors++;
                    $results[] = ['status' => 'error', 'message' => $e->getMessage()];
                }
            }
        }
        
        // เพิ่ม bot_mode column
        try {
            $stmt = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'bot_mode'");
            if ($stmt->rowCount() == 0) {
                $db->exec("ALTER TABLE line_accounts ADD COLUMN bot_mode ENUM('shop', 'general', 'auto_reply_only') DEFAULT 'shop' AFTER is_default");
                $results[] = ['status' => 'success', 'table' => 'bot_mode column'];
            }
        } catch (Exception $e) {}
        
        $message = "ติดตั้งสำเร็จ! สร้างตาราง $success รายการ";
        if ($errors > 0) {
            $error = "มี $errors รายการที่ผิดพลาด";
        }
        
        $step = 'done';
        
    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// ตรวจสอบตารางที่มีอยู่
$existingTables = [];
if ($dbConnected) {
    try {
        $stmt = $db->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}
}

$requiredTables = [
    'line_accounts', 'admin_users', 'users', 'groups', 'user_groups',
    'messages', 'auto_replies', 'broadcasts', 'rich_menus', 'templates',
    'scheduled_messages', 'welcome_settings', 'ai_settings', 'analytics',
    'product_categories', 'products', 'cart_items', 'orders', 'order_items',
    'payment_slips', 'shop_settings', 'user_states', 'webhook_events'
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LINE CRM Installer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <div class="text-center mb-8">
                <i class="fab fa-line text-6xl text-green-500 mb-4"></i>
                <h1 class="text-2xl font-bold text-gray-800">LINE CRM Installer</h1>
                <p class="text-gray-500">ติดตั้งฐานข้อมูลสำหรับระบบ LINE CRM</p>
            </div>
            
            <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-100 text-red-700 rounded-lg">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
            <div class="mb-6 p-4 bg-green-100 text-green-700 rounded-lg">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($step === 'check'): ?>
            <!-- Step 1: Check -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-4">📋 ตรวจสอบระบบ</h2>
                
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                        <span>การเชื่อมต่อฐานข้อมูล</span>
                        <?php if ($dbConnected): ?>
                        <span class="text-green-500"><i class="fas fa-check-circle"></i> เชื่อมต่อแล้ว</span>
                        <?php else: ?>
                        <span class="text-red-500"><i class="fas fa-times-circle"></i> ไม่สามารถเชื่อมต่อ</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                        <span>ไฟล์ install.sql</span>
                        <?php if (file_exists('../database/install.sql')): ?>
                        <span class="text-green-500"><i class="fas fa-check-circle"></i> พบไฟล์</span>
                        <?php else: ?>
                        <span class="text-red-500"><i class="fas fa-times-circle"></i> ไม่พบไฟล์</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-4">📊 สถานะตาราง</h2>
                <div class="grid grid-cols-2 gap-2 text-sm">
                    <?php foreach ($requiredTables as $table): ?>
                    <div class="flex items-center p-2 bg-gray-50 rounded">
                        <?php if (in_array($table, $existingTables)): ?>
                        <i class="fas fa-check text-green-500 mr-2"></i>
                        <?php else: ?>
                        <i class="fas fa-times text-red-500 mr-2"></i>
                        <?php endif; ?>
                        <span><?= $table ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php if ($dbConnected): ?>
            <div class="flex space-x-4">
                <a href="?step=install" class="flex-1 py-3 bg-green-500 text-white text-center rounded-lg hover:bg-green-600 font-medium">
                    <i class="fas fa-database mr-2"></i>ติดตั้งฐานข้อมูล
                </a>
                <a href="../admin/" class="flex-1 py-3 bg-gray-200 text-gray-700 text-center rounded-lg hover:bg-gray-300 font-medium">
                    <i class="fas fa-arrow-left mr-2"></i>กลับหน้าหลัก
                </a>
            </div>
            <?php endif; ?>
            
            <?php elseif ($step === 'done'): ?>
            <!-- Step 2: Done -->
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
                <h2 class="text-xl font-semibold mb-2">ติดตั้งเสร็จสิ้น!</h2>
                <p class="text-gray-500 mb-6">ระบบพร้อมใช้งานแล้ว</p>
                
                <div class="flex space-x-4 justify-center">
                    <a href="../admin/" class="px-6 py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                        <i class="fas fa-home mr-2"></i>ไปหน้าหลัก
                    </a>
                    <a href="../line-accounts.php" class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium">
                        <i class="fab fa-line mr-2"></i>ตั้งค่า LINE Account
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <p class="text-center text-gray-400 text-sm mt-4">
            LINE CRM v4.0 &copy; <?= date('Y') ?>
        </p>
    </div>
</body>
</html>
