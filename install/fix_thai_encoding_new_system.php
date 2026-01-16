<?php
/**
 * Fix Thai Encoding - สำหรับระบบใหม่
 * แก้ปัญหาภาษาไทยแสดงเป็น ??????? เฉพาะเวลาขึ้นระบบใหม่
 * 
 * วิธีใช้:
 * 1. เรียกผ่าน browser: http://yourdomain.com/install/fix_thai_encoding_new_system.php
 * 2. หรือ CLI: php install/fix_thai_encoding_new_system.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$isCLI = php_sapi_name() === 'cli';

function output($msg, $type = 'info') {
    global $isCLI;
    $colors = [
        'success' => $isCLI ? "\033[32m" : "<span style='color:green'>",
        'error' => $isCLI ? "\033[31m" : "<span style='color:red'>",
        'warning' => $isCLI ? "\033[33m" : "<span style='color:orange'>",
        'info' => $isCLI ? "\033[36m" : "<span style='color:blue'>",
    ];
    $reset = $isCLI ? "\033[0m" : "</span>";
    
    if (!$isCLI) {
        echo $colors[$type] . $msg . $reset . "<br>\n";
    } else {
        echo $colors[$type] . $msg . $reset . "\n";
    }
}

if (!$isCLI) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix Thai Encoding</title></head><body>";
    echo "<h1>🔧 แก้ไขปัญหาภาษาไทย (Thai Encoding Fix)</h1>";
}

output("=== เริ่มแก้ไขปัญหา Encoding ===", 'info');
output("", 'info');

try {
    $db = Database::getInstance()->getConnection();
    
    // Force UTF-8 connection
    $db->exec("SET NAMES utf8mb4");
    $db->exec("SET CHARACTER SET utf8mb4");
    
    output("✓ เชื่อมต่อฐานข้อมูลสำเร็จ", 'success');
    
    // ===== STEP 1: แก้ Database Charset =====
    output("", 'info');
    output("STEP 1: ตั้งค่า Database Charset", 'info');
    output("----------------------------------------", 'info');
    
    try {
        $db->exec("ALTER DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        output("✓ Database charset = utf8mb4", 'success');
    } catch (Exception $e) {
        output("⚠ ไม่สามารถแก้ database charset (อาจต้องแก้ manual): " . $e->getMessage(), 'warning');
    }
    
    // ===== STEP 2: แปลงตารางทั้งหมด =====
    output("", 'info');
    output("STEP 2: แปลงตารางเป็น UTF8MB4", 'info');
    output("----------------------------------------", 'info');
    
    $tables = [
        'activity_logs',
        'admin_activity_log',
        'admin_users',
        'auto_reply_rules',
        'broadcast_messages',
        'business_items',
        'contacts',
        'customer_notes',
        'dev_logs',
        'faq_items',
        'health_articles',
        'landing_banners',
        'landing_seo_settings',
        'line_accounts',
        'messages',
        'pos_sales',
        'pos_sale_items',
        'products',
        'product_batches',
        'purchase_orders',
        'stock_movements',
        'suppliers',
        'templates',
        'testimonials',
        'transactions',
        'warehouse_locations',
        'wms_activity_logs'
    ];
    
    $fixedTables = 0;
    $skippedTables = 0;
    
    foreach ($tables as $table) {
        try {
            // Check if table exists
            $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() === 0) {
                output("  ⊘ ไม่พบตาราง '{$table}' - ข้าม", 'warning');
                $skippedTables++;
                continue;
            }
            
            $db->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            output("  ✓ {$table}", 'success');
            $fixedTables++;
        } catch (Exception $e) {
            output("  ✗ {$table}: " . $e->getMessage(), 'error');
        }
    }
    
    output("", 'info');
    output("แปลงสำเร็จ: {$fixedTables} ตาราง | ข้าม: {$skippedTables} ตาราง", 'info');
    
    // ===== STEP 3: แก้ Text Columns เฉพาะ =====
    output("", 'info');
    output("STEP 3: แก้ไข Text Columns เฉพาะ", 'info');
    output("----------------------------------------", 'info');
    
    $textColumns = [
        'activity_logs' => ['description', 'user_name', 'admin_name'],
        'admin_activity_log' => ['details'],
        'admin_users' => ['username', 'full_name'],
        'auto_reply_rules' => ['keyword', 'response'],
        'business_items' => ['name', 'description', 'category'],
        'contacts' => ['display_name', 'status_message'],
        'customer_notes' => ['note_text'],
        'dev_logs' => ['message', 'source', 'data'],
        'faq_items' => ['question', 'answer'],
        'health_articles' => ['title', 'content', 'excerpt'],
        'landing_banners' => ['title', 'subtitle'],
        'line_accounts' => ['name', 'basic_id'],
        'messages' => ['message_text'],
        'products' => ['name', 'description', 'category'],
        'suppliers' => ['name', 'contact_person', 'address'],
        'templates' => ['name', 'content'],
        'testimonials' => ['customer_name', 'content'],
        'transactions' => ['notes', 'customer_name'],
        'warehouse_locations' => ['location_code', 'location_name', 'description']
    ];
    
    $fixedColumns = 0;
    
    foreach ($textColumns as $table => $columns) {
        try {
            // Check if table exists
            $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() === 0) {
                continue;
            }
            
            foreach ($columns as $column) {
                try {
                    // Check if column exists
                    $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
                    if ($stmt->rowCount() === 0) {
                        continue;
                    }
                    
                    $db->exec("ALTER TABLE {$table} MODIFY {$column} TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    output("  ✓ {$table}.{$column}", 'success');
                    $fixedColumns++;
                } catch (Exception $e) {
                    output("  ✗ {$table}.{$column}: " . $e->getMessage(), 'error');
                }
            }
        } catch (Exception $e) {
            output("  ✗ {$table}: " . $e->getMessage(), 'error');
        }
    }
    
    output("", 'info');
    output("แก้ไข columns สำเร็จ: {$fixedColumns} columns", 'info');
    
    // ===== STEP 4: ทดสอบภาษาไทย =====
    output("", 'info');
    output("STEP 4: ทดสอบภาษาไทย", 'info');
    output("----------------------------------------", 'info');
    
    $testText = 'ทดสอบภาษาไทย - Test Thai ' . date('Y-m-d H:i:s');
    $testAdmin = 'ทดสอบ Admin';
    
    $stmt = $db->prepare("INSERT INTO activity_logs (log_type, action, description, admin_name) VALUES (?, ?, ?, ?)");
    $stmt->execute(['system', 'test', $testText, $testAdmin]);
    $lastId = $db->lastInsertId();
    
    // Read back
    $stmt = $db->prepare("SELECT description, admin_name FROM activity_logs WHERE id = ?");
    $stmt->execute([$lastId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row['description'] === $testText && $row['admin_name'] === $testAdmin) {
        output("✓ ภาษาไทยทำงานถูกต้อง!", 'success');
        output("  บันทึก: {$testText}", 'info');
        output("  อ่านกลับ: {$row['description']}", 'info');
    } else {
        output("✗ ภาษาไทยยังมีปัญหา", 'error');
        output("  คาดหวัง: {$testText}", 'info');
        output("  ได้รับ: {$row['description']}", 'info');
    }
    
    // Clean up test record
    $db->exec("DELETE FROM activity_logs WHERE id = {$lastId}");
    
    // ===== สรุป =====
    output("", 'info');
    output("=== เสร็จสิ้น ===", 'success');
    output("✓ ระบบพร้อมใช้งานกับภาษาไทย", 'success');
    output("✓ ข้อมูลใหม่จะบันทึกภาษาไทยได้ถูกต้อง", 'success');
    output("", 'info');
    output("หมายเหตุ:", 'warning');
    output("- ถ้าข้อมูลเก่ายังแสดง ??????? อาจต้องกรอกใหม่", 'warning');
    output("- เพราะข้อมูลเดิมถูกบันทึกด้วย encoding ผิดไปแล้ว", 'warning');
    output("- ข้อมูลใหม่ที่บันทึกหลังจากนี้จะถูกต้อง", 'warning');
    
} catch (Exception $e) {
    output("", 'info');
    output("=== เกิดข้อผิดพลาด ===", 'error');
    output("✗ " . $e->getMessage(), 'error');
    output("", 'info');
    output("กรุณาตรวจสอบ:", 'warning');
    output("1. การเชื่อมต่อฐานข้อมูลใน config/config.php", 'warning');
    output("2. สิทธิ์ในการแก้ไข database structure", 'warning');
}

if (!$isCLI) {
    echo "<br><br>";
    echo "<a href='../activity-logs.php' style='padding:10px 20px; background:#06C755; color:white; text-decoration:none; border-radius:5px;'>📋 ดู Activity Logs</a> ";
    echo "<a href='../dashboard.php' style='padding:10px 20px; background:#4CAF50; color:white; text-decoration:none; border-radius:5px;'>🏠 กลับหน้าหลัก</a>";
    echo "</body></html>";
}
