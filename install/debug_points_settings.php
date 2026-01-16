<?php
/**
 * Debug Points Settings
 * ตรวจสอบปัญหาการบันทึกการตั้งค่าแต้ม
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Debug Points Settings</title>";
echo "<style>body{font-family:'Sarabun',sans-serif;padding:20px;max-width:1000px;margin:0 auto;} .success{color:green;padding:10px;background:#d4edda;border-radius:5px;margin:10px 0;} .error{color:red;padding:10px;background:#f8d7da;border-radius:5px;margin:10px 0;} .info{color:#004085;padding:10px;background:#d1ecf1;border-radius:5px;margin:10px 0;} pre{background:#f5f5f5;padding:10px;border-radius:5px;overflow-x:auto;} table{width:100%;border-collapse:collapse;margin:20px 0;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background:#f8f9fa;}</style>";
echo "</head><body>";

echo "<h1>🔍 Debug Points Settings</h1>";

// 1. Check tables
echo "<h2>1. ตรวจสอบตาราง</h2>";
$requiredTables = ['points_settings', 'points_transactions', 'points_campaigns', 'tier_settings', 'category_points_bonus'];
$existingTables = [];
$missingTables = [];

foreach ($requiredTables as $table) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM `{$table}`");
        $count = $stmt->fetchColumn();
        $existingTables[] = $table;
        echo "<div class='success'>✅ {$table}: {$count} records</div>";
    } catch (Exception $e) {
        $missingTables[] = $table;
        echo "<div class='error'>❌ {$table}: ไม่มีตาราง</div>";
    }
}

if (!empty($missingTables)) {
    echo "<div class='error'><strong>ต้องรัน migration:</strong> <a href='run_loyalty_migration.php'>run_loyalty_migration.php</a></div>";
}

// 2. Check LoyaltyPoints class
echo "<h2>2. ตรวจสอบ LoyaltyPoints Class</h2>";
try {
    $loyalty = new LoyaltyPoints($db, 1);
    echo "<div class='success'>✅ LoyaltyPoints class โหลดสำเร็จ</div>";
    
    // Get current settings
    $settings = $loyalty->getSettings();
    echo "<h3>การตั้งค่าปัจจุบัน:</h3>";
    echo "<pre>" . print_r($settings, true) . "</pre>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// 3. Test update settings
echo "<h2>3. ทดสอบการบันทึกการตั้งค่า</h2>";
echo "<form method='post'>";
echo "<table>";
echo "<tr><th>ฟิลด์</th><th>ค่าปัจจุบัน</th><th>ค่าใหม่</th></tr>";
echo "<tr><td>points_per_baht</td><td>" . ($settings['points_per_baht'] ?? 'N/A') . "</td><td><input type='number' name='points_per_baht' value='" . ($settings['points_per_baht'] ?? 0.001) . "' step='0.001'></td></tr>";
echo "<tr><td>min_order_for_points</td><td>" . ($settings['min_order_for_points'] ?? 'N/A') . "</td><td><input type='number' name='min_order_for_points' value='" . ($settings['min_order_for_points'] ?? 0) . "'></td></tr>";
echo "<tr><td>points_expiry_days</td><td>" . ($settings['points_expiry_days'] ?? 'N/A') . "</td><td><input type='number' name='points_expiry_days' value='" . ($settings['points_expiry_days'] ?? 365) . "'></td></tr>";
echo "<tr><td>is_active</td><td>" . ($settings['is_active'] ?? 'N/A') . "</td><td><input type='checkbox' name='is_active' " . (($settings['is_active'] ?? 1) ? 'checked' : '') . "></td></tr>";
echo "</table>";
echo "<button type='submit' name='test_update' value='1' style='padding:10px 20px;background:#007bff;color:white;border:none;border-radius:5px;cursor:pointer;'>ทดสอบบันทึก</button>";
echo "</form>";

if (isset($_POST['test_update'])) {
    echo "<h3>ผลการทดสอบ:</h3>";
    try {
        $data = [
            'points_per_baht' => floatval($_POST['points_per_baht'] ?? 0.001),
            'min_order_for_points' => floatval($_POST['min_order_for_points'] ?? 0),
            'points_expiry_days' => intval($_POST['points_expiry_days'] ?? 365),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        
        echo "<div class='info'>ข้อมูลที่จะบันทึก:<pre>" . print_r($data, true) . "</pre></div>";
        
        $result = $loyalty->updateSettings($data);
        
        if ($result) {
            echo "<div class='success'>✅ บันทึกสำเร็จ!</div>";
            
            // Reload settings
            $newSettings = $loyalty->getSettings();
            echo "<h4>การตั้งค่าใหม่:</h4>";
            echo "<pre>" . print_r($newSettings, true) . "</pre>";
        } else {
            echo "<div class='error'>❌ บันทึกไม่สำเร็จ (return false)</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
}

// 4. Check database structure
echo "<h2>4. โครงสร้างตาราง points_settings</h2>";
try {
    $stmt = $db->query("SHOW CREATE TABLE points_settings");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . htmlspecialchars($result['Create Table'] ?? 'N/A') . "</pre>";
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>";
}

// 5. Test direct SQL update
echo "<h2>5. ทดสอบ SQL โดยตรง</h2>";
echo "<form method='post'>";
echo "<p>ทดสอบ INSERT ... ON DUPLICATE KEY UPDATE</p>";
echo "<button type='submit' name='test_sql' value='1' style='padding:10px 20px;background:#28a745;color:white;border:none;border-radius:5px;cursor:pointer;'>ทดสอบ SQL</button>";
echo "</form>";

if (isset($_POST['test_sql'])) {
    try {
        $stmt = $db->prepare("
            INSERT INTO points_settings 
            (line_account_id, points_per_baht, min_order_for_points, points_expiry_days, is_active) 
            VALUES (1, 0.001, 0, 365, 1) 
            ON DUPLICATE KEY UPDATE 
            points_per_baht = VALUES(points_per_baht), 
            min_order_for_points = VALUES(min_order_for_points), 
            points_expiry_days = VALUES(points_expiry_days), 
            is_active = VALUES(is_active)
        ");
        $result = $stmt->execute();
        
        if ($result) {
            echo "<div class='success'>✅ SQL execute สำเร็จ!</div>";
            echo "<p>Affected rows: " . $stmt->rowCount() . "</p>";
        } else {
            echo "<div class='error'>❌ SQL execute ไม่สำเร็จ</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ SQL Error: " . $e->getMessage() . "</div>";
    }
}

echo "<hr>";
echo "<p><a href='../membership.php?tab=settings'>กลับไปหน้าตั้งค่า</a></p>";

echo "</body></html>";
