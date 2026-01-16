<?php
/**
 * ปิด AI Module ทุก Bot - เวอร์ชันง่าย
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database credentials
$host = 'localhost';
$dbname = 'zrismpsz_cny';
$username = 'zrismpsz_cny';
$password = 'zrismpsz_cny';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>ปิด AI Module</title>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    h1 { color: #f44336; border-bottom: 3px solid #f44336; padding-bottom: 10px; }
    h2 { color: #333; margin-top: 30px; }
    .ok { color: #4CAF50; font-weight: bold; }
    .error { color: #f44336; font-weight: bold; }
    .warning { color: #ff9800; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background-color: #f44336; color: white; }
    tr:nth-child(even) { background-color: #f9f9f9; }
    .btn { display: inline-block; padding: 15px 30px; background: #f44336; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
    .btn:hover { background: #d32f2f; }
    .info { background: #e3f2fd; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
</style></head><body><div class='container'>";

echo "<h1>🔴 ปิด AI Module ทุก Bot</h1>";

// เชื่อมต่อ Database
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>✅ เชื่อมต่อ Database สำเร็จ</p>";
} catch (PDOException $e) {
    die("<p class='error'>❌ ไม่สามารถเชื่อมต่อ Database: " . htmlspecialchars($e->getMessage()) . "</p></div></body></html>");
}

// ตรวจสอบว่ามีตารางหรือไม่
try {
    $tables = $pdo->query("SHOW TABLES LIKE 'ai_%'")->fetchAll(PDO::FETCH_COLUMN);
    echo "<div class='info'><strong>ตารางที่พบ:</strong> " . implode(', ', $tables) . "</div>";
} catch (Exception $e) {
    echo "<p class='error'>❌ ไม่สามารถตรวจสอบตาราง: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===== 1. แสดงสถานะปัจจุบัน =====
echo "<h2>1. สถานะปัจจุบัน</h2>";

// ตรวจสอบ ai_settings
echo "<h3>📊 ai_settings</h3>";
try {
    $stmt = $pdo->query("
        SELECT la.id, la.bot_name, 
               COALESCE(ai.is_enabled, 0) as is_enabled,
               ai.ai_mode
        FROM line_accounts la
        LEFT JOIN ai_settings ai ON la.id = ai.line_account_id
        ORDER BY la.id
    ");
    $aiSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($aiSettings) > 0) {
        echo "<table><tr><th>Bot ID</th><th>Bot Name</th><th>AI Mode</th><th>Status</th></tr>";
        foreach ($aiSettings as $row) {
            $status = $row['is_enabled'] ? '<span class="error">✅ เปิด</span>' : '<span class="ok">❌ ปิด</span>';
            $mode = $row['ai_mode'] ?? '-';
            echo "<tr><td>{$row['id']}</td><td>" . htmlspecialchars($row['bot_name']) . "</td><td>{$mode}</td><td>{$status}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ ไม่พบข้อมูล Bot</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ตรวจสอบ ai_chat_settings
echo "<h3>📊 ai_chat_settings</h3>";
try {
    $stmt = $pdo->query("
        SELECT la.id, la.bot_name, 
               COALESCE(aic.is_enabled, 0) as is_enabled
        FROM line_accounts la
        LEFT JOIN ai_chat_settings aic ON la.id = aic.line_account_id
        ORDER BY la.id
    ");
    $aiChatSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($aiChatSettings) > 0) {
        echo "<table><tr><th>Bot ID</th><th>Bot Name</th><th>Status</th></tr>";
        foreach ($aiChatSettings as $row) {
            $status = $row['is_enabled'] ? '<span class="error">✅ เปิด</span>' : '<span class="ok">❌ ปิด</span>';
            echo "<tr><td>{$row['id']}</td><td>" . htmlspecialchars($row['bot_name']) . "</td><td>{$status}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='warning'>⚠️ ไม่พบข้อมูล ai_chat_settings</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===== 2. ปุ่มยืนยัน =====
if (!isset($_GET['confirm'])) {
    echo "<h2>2. ⚠️ คำเตือน</h2>";
    echo "<div class='info'>";
    echo "<p><strong>การดำเนินการนี้จะ:</strong></p>";
    echo "<ul>";
    echo "<li>ปิด AI ในตาราง <code>ai_settings</code> ทุก Bot</li>";
    echo "<li>ปิด AI ในตาราง <code>ai_chat_settings</code> ทุก Bot</li>";
    echo "<li>Bot จะไม่ตอบกลับด้วย AI อีกต่อไป</li>";
    echo "</ul>";
    echo "</div>";
    echo "<a href='?confirm=1' class='btn' onclick=\"return confirm('ยืนยันการปิด AI ทุก Bot?')\">🔴 ยืนยันปิด AI ทุก Bot</a>";
    echo "</div></body></html>";
    exit;
}

// ===== 3. ดำเนินการปิด =====
echo "<h2>3. 🔧 กำลังปิด AI...</h2>";

try {
    $pdo->beginTransaction();
    
    // ปิด ai_settings
    $stmt = $pdo->prepare("UPDATE ai_settings SET is_enabled = 0");
    $stmt->execute();
    $count1 = $stmt->rowCount();
    echo "<p class='ok'>✅ ปิด ai_settings แล้ว ({$count1} records)</p>";
    
    // ปิด ai_chat_settings
    $stmt = $pdo->prepare("UPDATE ai_chat_settings SET is_enabled = 0");
    $stmt->execute();
    $count2 = $stmt->rowCount();
    echo "<p class='ok'>✅ ปิด ai_chat_settings แล้ว ({$count2} records)</p>";
    
    $pdo->commit();
    echo "<h2 class='ok'>✅ สำเร็จ! ปิด AI ทุก Bot แล้ว</h2>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p class='error'>❌ เกิดข้อผิดพลาด: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===== 4. แสดงสถานะหลังปิด =====
echo "<h2>4. สถานะหลังปิด</h2>";

try {
    $stmt = $pdo->query("
        SELECT la.id, la.bot_name, 
               COALESCE(ai.is_enabled, 0) as ai_enabled,
               COALESCE(aic.is_enabled, 0) as aic_enabled
        FROM line_accounts la
        LEFT JOIN ai_settings ai ON la.id = ai.line_account_id
        LEFT JOIN ai_chat_settings aic ON la.id = aic.line_account_id
        ORDER BY la.id
    ");
    
    echo "<table><tr><th>Bot ID</th><th>Bot Name</th><th>ai_settings</th><th>ai_chat_settings</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ai = $row['ai_enabled'] ? '<span class="error">⚠️ เปิด</span>' : '<span class="ok">✅ ปิด</span>';
        $aic = $row['aic_enabled'] ? '<span class="error">⚠️ เปิด</span>' : '<span class="ok">✅ ปิด</span>';
        echo "<tr><td>{$row['id']}</td><td>" . htmlspecialchars($row['bot_name']) . "</td><td>{$ai}</td><td>{$aic}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><p><small>Generated: " . date('Y-m-d H:i:s') . "</small></p>";
echo "</div></body></html>";
?>
