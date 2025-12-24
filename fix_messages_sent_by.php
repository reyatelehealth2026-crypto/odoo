<?php
/**
 * Fix messages sent_by column
 * ตรวจสอบและแก้ไขข้อความที่ sent_by ไม่ถูกต้อง
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🔧 Fix Messages sent_by Column</h2>";

// 1. Check if sent_by column exists
$stmt = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
if ($stmt->rowCount() == 0) {
    echo "<p>❌ Column 'sent_by' does not exist. Adding...</p>";
    $db->exec("ALTER TABLE messages ADD COLUMN sent_by VARCHAR(100) DEFAULT NULL AFTER content");
    echo "<p>✅ Column added!</p>";
} else {
    echo "<p>✅ Column 'sent_by' exists</p>";
}

// 2. Count messages by sent_by value
echo "<h3>📊 Messages by sent_by:</h3>";
$stmt = $db->query("SELECT sent_by, COUNT(*) as cnt FROM messages WHERE direction = 'outgoing' GROUP BY sent_by ORDER BY cnt DESC");
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Sent By</th><th>Count</th></tr>";
foreach ($stats as $s) {
    echo "<tr><td>" . htmlspecialchars($s['sent_by'] ?? 'NULL') . "</td><td>{$s['cnt']}</td></tr>";
}
echo "</table>";

// 3. Show recent messages
echo "<h3>📋 Recent Outgoing Messages:</h3>";
$stmt = $db->query("SELECT id, direction, content, sent_by, created_at FROM messages WHERE direction = 'outgoing' ORDER BY created_at DESC LIMIT 15");
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Content</th><th>Sent By</th><th>Created</th></tr>";
foreach ($messages as $msg) {
    $content = mb_substr($msg['content'] ?? '', 0, 30) . '...';
    $sentByStyle = ($msg['sent_by'] === 'admin:Admin' || empty($msg['sent_by'])) ? 'color:red;' : 'color:green;';
    echo "<tr>";
    echo "<td>{$msg['id']}</td>";
    echo "<td>" . htmlspecialchars($content) . "</td>";
    echo "<td style='{$sentByStyle}'><strong>" . htmlspecialchars($msg['sent_by'] ?? 'NULL') . "</strong></td>";
    echo "<td>{$msg['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Check current session
echo "<h3>🔐 Current Session:</h3>";
session_start();
if (isset($_SESSION['admin_user'])) {
    $adminUser = $_SESSION['admin_user'];
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Key</th><th>Value</th></tr>";
    foreach (['id', 'username', 'display_name', 'email', 'role'] as $key) {
        $val = $adminUser[$key] ?? 'N/A';
        echo "<tr><td>{$key}</td><td><strong>" . htmlspecialchars($val) . "</strong></td></tr>";
    }
    echo "</table>";
    
    // What will be used for sent_by
    $finalName = !empty($adminUser['username']) ? $adminUser['username'] : (!empty($adminUser['display_name']) ? $adminUser['display_name'] : 'Admin');
    echo "<p style='font-size:18px;'>✅ ชื่อที่จะใช้สำหรับ sent_by: <strong style='color:green;'>admin:{$finalName}</strong></p>";
} else {
    echo "<p style='color:red;'>❌ Not logged in</p>";
}

// 5. Check admin_users table
echo "<h3>👥 Admin Users:</h3>";
$stmt = $db->query("SELECT id, username, display_name, role FROM admin_users ORDER BY id");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Username</th><th>Display Name</th><th>Role</th></tr>";
foreach ($users as $u) {
    echo "<tr>";
    echo "<td>{$u['id']}</td>";
    echo "<td><strong>{$u['username']}</strong></td>";
    echo "<td>" . ($u['display_name'] ?: '-') . "</td>";
    echo "<td>{$u['role']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr><p>✅ Done! <a href='inbox'>Go to Inbox</a></p>";
