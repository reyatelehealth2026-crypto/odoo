<?php
/**
 * ปิด AI Module ทุก Bot - เวอร์ชัน Direct SQL
 * ไม่ใช้ Database class เพื่อหลีกเลี่ยง autoload issues
 */

// Database credentials - ปรับตามค่าจริง
$host = 'localhost';
$dbname = 'zrismpsz_cny';
$username = 'zrismpsz_cny';
$password = 'zrismpsz_cny';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<h1>❌ Database Connection Failed</h1><p>" . $e->getMessage() . "</p>");
}

echo "<h1>🔴 ปิด AI Module ทุก Bot (Direct SQL)</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; }
    .ok { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background-color: #f44336; color: white; }
</style>";

// ===== 1. แสดงสถานะปัจจุบัน =====
echo "<h2>1. สถานะปัจจุบัน</h2>";

echo "<h3>ai_settings</h3>";
$stmt = $pdo->query("
    SELECT la.id, la.bot_name, 
           COALESCE(ai.is_enabled, 0) as is_enabled
    FROM line_accounts la
    LEFT JOIN ai_settings ai ON la.id = ai.line_account_id
    ORDER BY la.id
");
$aiSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table><tr><th>Bot ID</th><th>Bot Name</th><th>Status</th></tr>";
foreach ($aiSettings as $row) {
    $status = $row['is_enabled'] ? '✅ เปิด' : '❌ ปิด';
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$status}</td></tr>";
}
echo "</table>";

echo "<h3>ai_chat_settings</h3>";
$stmt = $pdo->query("
    SELECT la.id, la.bot_name, 
           COALESCE(aic.is_enabled, 0) as is_enabled
    FROM line_accounts la
    LEFT JOIN ai_chat_settings aic ON la.id = aic.line_account_id
    ORDER BY la.id
");
$aiChatSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table><tr><th>Bot ID</th><th>Bot Name</th><th>Status</th></tr>";
foreach ($aiChatSettings as $row) {
    $status = $row['is_enabled'] ? '✅ เปิด' : '❌ ปิด';
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$status}</td></tr>";
}
echo "</table>";

// ===== 2. ปุ่มยืนยัน =====
if (!isset($_GET['confirm'])) {
    echo "<h2>2. ⚠️ คำเตือน</h2>";
    echo "<p class='error'>การดำเนินการนี้จะปิด AI ทั้งหมดในระบบ</p>";
    echo "<p><a href='?confirm=1' onclick=\"return confirm('ยืนยันการปิด AI ทุก Bot?')\" 
          style='display:inline-block; padding:15px 30px; background:#f44336; color:white; text-decoration:none; border-radius:5px; font-weight:bold;'>
          🔴 ยืนยันปิด AI ทุก Bot
          </a></p>";
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
    echo "<h2>4. ✅ สำเร็จ!</h2>";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p class='error'>❌ เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
}

// ===== 5. แสดงสถานะหลังปิด =====
echo "<h2>5. สถานะหลังปิด</h2>";

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
    echo "<tr><td>{$row['id']}</td><td>{$row['bot_name']}</td><td>{$ai}</td><td>{$aic}</td></tr>";
}
echo "</table>";

echo "<hr><p><small>Generated: " . date('Y-m-d H:i:s') . "</small></p>";
?>
