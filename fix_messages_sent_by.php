<?php
/**
 * Fix messages sent_by column
 * อัพเดทข้อความเก่าที่ไม่มี sent_by ให้เป็น 'admin:Admin'
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

// 2. Count messages without sent_by
$stmt = $db->query("SELECT COUNT(*) FROM messages WHERE direction = 'outgoing' AND (sent_by IS NULL OR sent_by = '')");
$count = $stmt->fetchColumn();
echo "<p>📊 Messages without sent_by: <strong>{$count}</strong></p>";

// 3. Update old messages
if ($count > 0) {
    echo "<p>🔄 Updating old messages...</p>";
    
    // Set default 'admin:Admin' for old outgoing messages
    $stmt = $db->prepare("UPDATE messages SET sent_by = 'admin:Admin' WHERE direction = 'outgoing' AND (sent_by IS NULL OR sent_by = '')");
    $stmt->execute();
    $affected = $stmt->rowCount();
    
    echo "<p>✅ Updated <strong>{$affected}</strong> messages</p>";
}

// 4. Show sample messages
echo "<h3>📋 Sample Messages with sent_by:</h3>";
$stmt = $db->query("SELECT id, direction, content, sent_by, created_at FROM messages WHERE direction = 'outgoing' ORDER BY created_at DESC LIMIT 10");
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Direction</th><th>Content</th><th>Sent By</th><th>Created</th></tr>";
foreach ($messages as $msg) {
    $content = mb_substr($msg['content'] ?? '', 0, 30) . '...';
    echo "<tr>";
    echo "<td>{$msg['id']}</td>";
    echo "<td>{$msg['direction']}</td>";
    echo "<td>" . htmlspecialchars($content) . "</td>";
    echo "<td><strong>" . htmlspecialchars($msg['sent_by'] ?? '-') . "</strong></td>";
    echo "<td>{$msg['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// 5. Check current session
echo "<h3>🔐 Current Session:</h3>";
session_start();
if (isset($_SESSION['admin_user'])) {
    echo "<pre>";
    print_r($_SESSION['admin_user']);
    echo "</pre>";
    
    $displayName = $_SESSION['admin_user']['display_name'] ?? 'N/A';
    $username = $_SESSION['admin_user']['username'] ?? 'N/A';
    echo "<p>Display Name: <strong>{$displayName}</strong></p>";
    echo "<p>Username: <strong>{$username}</strong></p>";
} else {
    echo "<p>❌ Not logged in</p>";
}

echo "<hr><p>✅ Done! <a href='inbox'>Go to Inbox</a></p>";
