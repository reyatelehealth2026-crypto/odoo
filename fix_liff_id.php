<?php
/**
 * Quick Fix LIFF ID - บันทึก LIFF ID ลงฐานข้อมูล
 * เปิดไฟล์นี้เพื่อ fix ปัญหา "ไม่พบ LIFF ID"
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// LIFF ID ที่ตั้งค่าไว้ใน LINE Developers Console
$liffId = '2008728363-92WCzBs4';
$lineAccountId = $_GET['account'] ?? 1;

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Fix LIFF ID</title>";
echo "<style>body{font-family:sans-serif;padding:20px;max-width:600px;margin:0 auto}";
echo ".success{background:#D1FAE5;color:#065F46;padding:15px;border-radius:8px;margin:10px 0}";
echo ".error{background:#FEE2E2;color:#991B1B;padding:15px;border-radius:8px;margin:10px 0}";
echo ".info{background:#DBEAFE;color:#1E40AF;padding:15px;border-radius:8px;margin:10px 0}";
echo "a{display:inline-block;margin:10px 5px 10px 0;padding:10px 20px;background:#10B981;color:white;text-decoration:none;border-radius:8px}";
echo "a:hover{background:#059669}</style></head><body>";

echo "<h1>🔧 Fix LIFF ID</h1>";

try {
    // 1. Check if liff_id column exists
    $cols = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'liff_id'")->fetchAll();
    
    if (empty($cols)) {
        // Add column
        $db->exec("ALTER TABLE line_accounts ADD COLUMN liff_id VARCHAR(100) NULL AFTER channel_secret");
        echo "<div class='info'>✅ เพิ่ม column liff_id แล้ว</div>";
    }
    
    // 2. Check current value
    $stmt = $db->prepare("SELECT id, name, liff_id FROM line_accounts WHERE id = ?");
    $stmt->execute([$lineAccountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        echo "<div class='error'>❌ ไม่พบ Account ID: $lineAccountId</div>";
    } else {
        echo "<div class='info'>";
        echo "<strong>Account:</strong> {$account['name']} (ID: {$account['id']})<br>";
        echo "<strong>LIFF ID เดิม:</strong> " . ($account['liff_id'] ?: '<em>ว่าง</em>');
        echo "</div>";
        
        // 3. Update LIFF ID
        $stmt = $db->prepare("UPDATE line_accounts SET liff_id = ? WHERE id = ?");
        $stmt->execute([$liffId, $lineAccountId]);
        
        echo "<div class='success'>";
        echo "✅ <strong>อัพเดท LIFF ID สำเร็จ!</strong><br>";
        echo "LIFF ID ใหม่: <code>$liffId</code>";
        echo "</div>";
        
        // 4. Verify
        $stmt = $db->prepare("SELECT liff_id FROM line_accounts WHERE id = ?");
        $stmt->execute([$lineAccountId]);
        $newValue = $stmt->fetchColumn();
        
        if ($newValue === $liffId) {
            echo "<div class='success'>✅ ยืนยัน: LIFF ID ถูกบันทึกเรียบร้อย</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "<h2>🔗 ทดสอบ</h2>";
echo "<a href='liff-app.php?account=$lineAccountId'>หน้าหลัก LIFF App</a>";
echo "<a href='liff-register.php?account=$lineAccountId'>หน้าสมัครสมาชิก</a>";
echo "<a href='https://liff.line.me/$liffId' target='_blank' style='background:#06C755'>เปิดผ่าน LINE</a>";
echo "<a href='debug_liff_id.php?account=$lineAccountId' style='background:#6366F1'>Debug Page</a>";

echo "</body></html>";
