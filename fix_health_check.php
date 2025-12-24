<?php
/**
 * Fix Health Check Issues
 * แก้ไขปัญหาที่พบจาก System Health Check
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🔧 Fix Health Check Issues</h2>";
$fixed = 0;
$errors = [];

// 1. Create loyalty_points table
echo "<h3>1. สร้างตาราง loyalty_points</h3>";
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS loyalty_points (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            line_account_id INT DEFAULT NULL,
            points INT DEFAULT 0,
            lifetime_points INT DEFAULT 0,
            tier VARCHAR(50) DEFAULT 'bronze',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user (user_id),
            INDEX idx_line_account (line_account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p style='color:green'>✓ สร้างตาราง loyalty_points สำเร็จ</p>";
    $fixed++;
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "<p style='color:blue'>○ ตาราง loyalty_points มีอยู่แล้ว</p>";
    } else {
        echo "<p style='color:red'>✗ Error: " . $e->getMessage() . "</p>";
        $errors[] = $e->getMessage();
    }
}

// 2. Add missing columns to users table
echo "<h3>2. เพิ่มคอลัมน์ที่ขาดในตาราง users</h3>";

$userColumns = [
    'reply_token' => "ALTER TABLE users ADD COLUMN reply_token VARCHAR(255) NULL",
    'reply_token_expires' => "ALTER TABLE users ADD COLUMN reply_token_expires DATETIME NULL",
    'is_registered' => "ALTER TABLE users ADD COLUMN is_registered TINYINT(1) DEFAULT 0",
    'loyalty_points' => "ALTER TABLE users ADD COLUMN loyalty_points INT DEFAULT 0"
];

foreach ($userColumns as $col => $sql) {
    try {
        $check = $db->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch();
        if (!$check) {
            $db->exec($sql);
            echo "<p style='color:green'>✓ เพิ่มคอลัมน์ users.$col สำเร็จ</p>";
            $fixed++;
        } else {
            echo "<p style='color:blue'>○ คอลัมน์ users.$col มีอยู่แล้ว</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>✗ Error users.$col: " . $e->getMessage() . "</p>";
        $errors[] = $e->getMessage();
    }
}

// 3. Add sent_by column to messages table
echo "<h3>3. เพิ่มคอลัมน์ sent_by ในตาราง messages</h3>";
try {
    $check = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'")->fetch();
    if (!$check) {
        $db->exec("ALTER TABLE messages ADD COLUMN sent_by ENUM('user', 'admin', 'bot', 'system') DEFAULT 'user'");
        echo "<p style='color:green'>✓ เพิ่มคอลัมน์ messages.sent_by สำเร็จ</p>";
        $fixed++;
    } else {
        echo "<p style='color:blue'>○ คอลัมน์ messages.sent_by มีอยู่แล้ว</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Error: " . $e->getMessage() . "</p>";
    $errors[] = $e->getMessage();
}

// Summary
echo "<hr>";
echo "<h3>📊 สรุป</h3>";
echo "<p>แก้ไขสำเร็จ: <strong style='color:green'>$fixed</strong> รายการ</p>";
if (!empty($errors)) {
    echo "<p>พบข้อผิดพลาด: <strong style='color:red'>" . count($errors) . "</strong> รายการ</p>";
}

echo "<p><a href='install/check_system.php'>🔄 ตรวจสอบอีกครั้ง</a></p>";
