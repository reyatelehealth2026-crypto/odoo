<?php
/**
 * Clear Test/Seed Data - ล้างข้อมูลทดสอบ
 */
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🧹 Clear Test Data</h1>";

// Check if action requested
$action = $_GET['action'] ?? '';

if ($action === 'clear_messages') {
    // ลบข้อความทดสอบ (content ที่ขึ้นต้นด้วย TEST_ หรือเป็นข้อความ seed)
    $seedMessages = [
        'สินค้าพร้อมส่งค่ะ ราคา 150 บาท',
        'สวัสดีค่ะ ยินดีให้บริการค่ะ',
        'ขอบคุณที่ติดต่อมาค่ะ',
        'แนะนำตัวนี้ค่ะ ดีมากๆ',
        'จัดส่งภายใน 1-2 วันค่ะ',
        'รับทราบค่ะ รอสักครู่นะคะ',
        'TEST_%',
        'RAW_TEST'
    ];
    
    $deleted = 0;
    foreach ($seedMessages as $msg) {
        $stmt = $db->prepare("DELETE FROM messages WHERE content LIKE ?");
        $stmt->execute([$msg]);
        $deleted += $stmt->rowCount();
    }
    
    echo "<p style='color:green;'>✅ ลบข้อความทดสอบ {$deleted} รายการ</p>";
    
} elseif ($action === 'clear_all_messages') {
    // ลบข้อความทั้งหมด (ระวัง!)
    $stmt = $db->query("DELETE FROM messages");
    $deleted = $stmt->rowCount();
    echo "<p style='color:green;'>✅ ลบข้อความทั้งหมด {$deleted} รายการ</p>";
    
} elseif ($action === 'clear_test_users') {
    // ลบ users ทดสอบ (display_name ที่มี Test หรือ ทดสอบ)
    $stmt = $db->query("DELETE FROM users WHERE display_name LIKE '%Test%' OR display_name LIKE '%ทดสอบ%'");
    $deleted = $stmt->rowCount();
    echo "<p style='color:green;'>✅ ลบ users ทดสอบ {$deleted} รายการ</p>";
}

// Show current data counts
echo "<h2>📊 Current Data</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
echo "<tr><th>Table</th><th>Count</th><th>Action</th></tr>";

// Messages
$count = $db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
$seedCount = $db->query("SELECT COUNT(*) FROM messages WHERE content IN ('สินค้าพร้อมส่งค่ะ ราคา 150 บาท','สวัสดีค่ะ ยินดีให้บริการค่ะ','ขอบคุณที่ติดต่อมาค่ะ','แนะนำตัวนี้ค่ะ ดีมากๆ','จัดส่งภายใน 1-2 วันค่ะ','รับทราบค่ะ รอสักครู่นะคะ') OR content LIKE 'TEST_%'")->fetchColumn();
echo "<tr>";
echo "<td>messages</td>";
echo "<td>{$count} (seed: ~{$seedCount})</td>";
echo "<td><a href='?action=clear_messages' onclick=\"return confirm('ลบข้อความ seed?')\">🗑️ Clear Seed</a> | <a href='?action=clear_all_messages' onclick=\"return confirm('⚠️ ลบข้อความทั้งหมด?')\" style='color:red;'>⚠️ Clear All</a></td>";
echo "</tr>";

// Users
$count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$testCount = $db->query("SELECT COUNT(*) FROM users WHERE display_name LIKE '%Test%' OR display_name LIKE '%ทดสอบ%'")->fetchColumn();
echo "<tr>";
echo "<td>users</td>";
echo "<td>{$count} (test: {$testCount})</td>";
echo "<td><a href='?action=clear_test_users' onclick=\"return confirm('ลบ users ทดสอบ?')\">🗑️ Clear Test Users</a></td>";
echo "</tr>";

// Transactions
$count = $db->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
echo "<tr><td>transactions</td><td>{$count}</td><td>-</td></tr>";

echo "</table>";

// Recent messages preview
echo "<h2>📝 Recent Messages (Last 10)</h2>";
$stmt = $db->query("SELECT id, content, sent_by, created_at FROM messages ORDER BY created_at DESC LIMIT 10");
$msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Content</th><th>Sent By</th><th>Created</th></tr>";
foreach ($msgs as $m) {
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($m['content'], 0, 40)) . "</td>";
    echo "<td>" . htmlspecialchars($m['sent_by'] ?? '-') . "</td>";
    echo "<td>{$m['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr><p><a href='inbox'>← Back to Inbox</a> | <a href='fix_sent_by_column.php'>Fix sent_by Column</a></p>";
