<?php
/**
 * Complete System Installation Script
 * ติดตั้งระบบทั้งหมดในครั้งเดียว
 * 
 * วิธีใช้: เข้า https://yourdomain.com/v1/install/install_all.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);

// Security check - ลบไฟล์นี้หลังติดตั้งเสร็จ!
$installKey = $_GET['key'] ?? '';
$requiredKey = 'INSTALL_' . date('Ymd'); // Key เปลี่ยนทุกวัน

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>System Installation</title>";
echo "<style>
body { font-family: 'Segoe UI', sans-serif; max-width: 1000px; margin: 20px auto; padding: 20px; background: #f5f5f5; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.success { color: #10B981; } .error { color: #EF4444; } .warning { color: #F59E0B; } .info { color: #3B82F6; }
h1 { color: #1E293B; } h2 { color: #475569; border-bottom: 2px solid #E2E8F0; padding-bottom: 10px; }
pre { background: #1E293B; color: #10B981; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.btn { display: inline-block; padding: 12px 24px; background: #10B981; color: white; text-decoration: none; border-radius: 8px; margin: 5px; }
.btn:hover { background: #059669; }
.btn-warning { background: #F59E0B; } .btn-warning:hover { background: #D97706; }
table { width: 100%; border-collapse: collapse; } th, td { padding: 10px; text-align: left; border-bottom: 1px solid #E2E8F0; }
th { background: #F8FAFC; }
.check { color: #10B981; } .cross { color: #EF4444; }
</style></head><body>";

echo "<h1>🚀 LINE CRM - Complete Installation</h1>";

// Check config
if (!file_exists(__DIR__ . '/../config/config.php')) {
    echo "<div class='card'><h2 class='error'>❌ Config Not Found</h2>";
    echo "<p>กรุณาสร้างไฟล์ <code>config/config.php</code> ก่อน</p>";
    echo "<pre>
&lt;?php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');

// Site
define('BASE_URL', 'https://yourdomain.com/v1');
define('SITE_NAME', 'LINE CRM');
</pre></div></body></html>";
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$results = [];
$errors = [];

// ============ MIGRATION FILES ============
$migrations = [
    'Core Tables' => [
        'database/install.sql',
        'database/schema.sql',
    ],
    'User & Auth' => [
        'database/migration_admin_users.sql',
        'database/migration_user_details.sql',
    ],
    'LINE Integration' => [
        'database/migration_add_line_account_id.sql',
        'database/migration_line_groups.sql',
        'database/migration_bot_mode.sql',
        'database/migration_welcome_settings.sql',
    ],
    'Shop & Products' => [
        'database/migration_liff_shop.sql',
        'database/migration_unified_shop.sql',
        'database/migration_shop_complete.sql',
        'database/migration_shop_settings_account.sql',
        'database/migration_shop_settings_multi_bot.sql',
        'database/migration_product_details.sql',
        'database/migration_checkout_options.sql',
        'database/migration_v2.5_business_items.sql',
    ],
    'Payments' => [
        'database/migration_fix_payment_slips.sql',
        'database/migration_unify_payment_slips.sql',
        'database/migration_fix_cart_fk.sql',
    ],
    'CRM Features' => [
        'database/migration_advanced_crm.sql',
        'database/migration_loyalty_points.sql',
        'database/migration_auto_tags.sql',
        'database/migration_unify_tags.sql',
        'database/migration_drip_campaigns.sql',
    ],
    'Messaging' => [
        'database/migration_auto_reply_upgrade.sql',
        'database/migration_broadcast_tracking.sql',
        'database/migration_flex_templates.sql',
        'database/migration_share_flex.sql',
        'database/migration_is_read.sql',
    ],
    'Medical & Pharmacy' => [
        'database/migration_medical_info.sql',
        'database/migration_pharmacist_system.sql',
        'database/migration_symptom_assessment.sql',
        'database/migration_triage_system.sql',
    ],
    'Appointments & Video' => [
        'database/migration_appointments.sql',
        'database/migration_video_calls.sql',
        'database/migration_video_calls_v2.sql',
    ],
    'Sync & Reports' => [
        'database/migration_cny_sync.sql',
        'database/migration_sync_queue.sql',
        'database/migration_scheduled_reports.sql',
        'database/migration_quick_access.sql',
    ],
    'System' => [
        'database/migration_dev_logs.sql',
        'database/migration_account_events.sql',
        'database/migration_fix_user_states.sql',
    ],
];

// ============ RUN INSTALLATION ============
if (isset($_POST['install'])) {
    echo "<div class='card'><h2>📦 Running Installation...</h2><pre>";
    
    foreach ($migrations as $category => $files) {
        echo "\n<span class='info'>== $category ==</span>\n";
        foreach ($files as $file) {
            $path = __DIR__ . '/../' . $file;
            if (file_exists($path)) {
                $sql = file_get_contents($path);
                // Split by delimiter or semicolon
                $statements = preg_split('/;\s*$/m', $sql);
                $success = 0;
                $failed = 0;
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if (empty($stmt) || strpos($stmt, '--') === 0) continue;
                    try {
                        $db->exec($stmt);
                        $success++;
                    } catch (PDOException $e) {
                        // Ignore duplicate errors
                        if (strpos($e->getMessage(), 'Duplicate') === false && 
                            strpos($e->getMessage(), 'already exists') === false) {
                            $failed++;
                        }
                    }
                }
                $status = $failed > 0 ? "<span class='warning'>⚠️</span>" : "<span class='success'>✓</span>";
                echo "$status $file ($success OK" . ($failed > 0 ? ", $failed skip" : "") . ")\n";
            } else {
                echo "<span class='warning'>⚠️</span> $file (not found)\n";
            }
        }
    }
    
    echo "</pre></div>";
    
    // Create essential columns if missing
    echo "<div class='card'><h2>🔧 Fixing Essential Columns...</h2><pre>";
    
    $essentialFixes = [
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS reply_token VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS reply_token_expires DATETIME DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS line_account_id INT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_registered TINYINT(1) DEFAULT 0",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS membership_level VARCHAR(20) DEFAULT 'bronze'",
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS loyalty_points INT DEFAULT 0",
        "ALTER TABLE messages ADD COLUMN IF NOT EXISTS is_read TINYINT(1) DEFAULT 0",
        "ALTER TABLE messages ADD COLUMN IF NOT EXISTS sent_by VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE messages ADD COLUMN IF NOT EXISTS line_account_id INT DEFAULT NULL",
    ];
    
    foreach ($essentialFixes as $sql) {
        try {
            $db->exec($sql);
            echo "<span class='success'>✓</span> " . substr($sql, 0, 60) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') === false) {
                echo "<span class='warning'>⚠️</span> " . substr($sql, 0, 60) . "...\n";
            }
        }
    }
    
    echo "</pre></div>";
    
    // Create default admin
    echo "<div class='card'><h2>👤 Creating Default Admin...</h2><pre>";
    try {
        $checkAdmin = $db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
        if ($checkAdmin == 0) {
            $password = password_hash('admin123', PASSWORD_DEFAULT);
            $db->exec("INSERT INTO admin_users (username, password, email, role, is_active) VALUES ('admin', '$password', 'admin@example.com', 'super_admin', 1)");
            echo "<span class='success'>✓</span> Created admin user\n";
            echo "<span class='info'>Username: admin</span>\n";
            echo "<span class='info'>Password: admin123</span>\n";
            echo "<span class='warning'>⚠️ กรุณาเปลี่ยนรหัสผ่านหลังเข้าสู่ระบบ!</span>\n";
        } else {
            echo "<span class='info'>ℹ️</span> Admin user already exists\n";
        }
    } catch (PDOException $e) {
        echo "<span class='error'>✗</span> " . $e->getMessage() . "\n";
    }
    echo "</pre></div>";
    
    echo "<div class='card' style='background: #D1FAE5;'>";
    echo "<h2 class='success'>✅ Installation Complete!</h2>";
    echo "<p>ระบบติดตั้งเสร็จสมบูรณ์แล้ว</p>";
    echo "<a href='check_system.php' class='btn'>🔍 ตรวจสอบระบบ</a>";
    echo "<a href='../admin/' class='btn btn-warning'>🏠 ไปหน้าหลัก</a>";
    echo "</div>";
    
} else {
    // Show installation form
    echo "<div class='card'>";
    echo "<h2>📋 Migration Files to Install</h2>";
    echo "<table><tr><th>Category</th><th>Files</th><th>Status</th></tr>";
    
    foreach ($migrations as $category => $files) {
        $found = 0;
        $total = count($files);
        foreach ($files as $file) {
            if (file_exists(__DIR__ . '/../' . $file)) $found++;
        }
        $status = $found == $total ? "<span class='check'>✓ $found/$total</span>" : "<span class='warning'>⚠️ $found/$total</span>";
        echo "<tr><td><strong>$category</strong></td><td>" . implode('<br>', array_map(function($f) { return basename($f); }, $files)) . "</td><td>$status</td></tr>";
    }
    
    echo "</table></div>";
    
    echo "<div class='card'>";
    echo "<h2>🚀 Start Installation</h2>";
    echo "<p>คลิกปุ่มด้านล่างเพื่อเริ่มติดตั้งระบบ</p>";
    echo "<form method='POST'>";
    echo "<button type='submit' name='install' class='btn' style='font-size: 18px; padding: 15px 40px;'>🚀 Install Now</button>";
    echo "</form>";
    echo "<p class='warning' style='margin-top: 15px;'>⚠️ หลังติดตั้งเสร็จ กรุณาลบไฟล์นี้ออกเพื่อความปลอดภัย!</p>";
    echo "</div>";
}

echo "</body></html>";
