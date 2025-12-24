<?php
/**
 * Check Messages Database - ตรวจสอบข้อความในฐานข้อมูล
 */
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Check Messages Database</h1>";

// 1. Check sent_by column
echo "<h2>1. Column Check</h2>";
$stmt = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
if ($stmt->rowCount() > 0) {
    echo "<p style='color:green;'>✅ Column 'sent_by' exists</p>";
} else {
    echo "<p style='color:red;'>❌ Column 'sent_by' NOT FOUND!</p>";
    echo "<p>Adding column...</p>";
    $db->exec("ALTER TABLE messages ADD COLUMN sent_by VARCHAR(100) DEFAULT NULL AFTER content");
    echo "<p style='color:green;'>✅ Column added!</p>";
}

// 2. Statistics
echo "<h2>2. Statistics by sent_by</h2>";
$stmt = $db->query("SELECT sent_by, COUNT(*) as cnt FROM messages WHERE direction = 'outgoing' GROUP BY sent_by ORDER BY cnt DESC");
$stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
echo "<tr><th>Sent By</th><th>Count</th></tr>";
foreach ($stats as $s) {
    $color = ($s['sent_by'] === 'admin:Admin' || empty($s['sent_by'])) ? 'red' : 'green';
    echo "<tr><td style='color:{$color};'><strong>" . htmlspecialchars($s['sent_by'] ?? 'NULL') . "</strong></td><td>{$s['cnt']}</td></tr>";
}
echo "</table>";

// 3. Recent messages
echo "<h2>3. Recent 20 Outgoing Messages</h2>";
$stmt = $db->query("SELECT id, user_id, content, sent_by, created_at FROM messages WHERE direction = 'outgoing' ORDER BY created_at DESC LIMIT 20");
$msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='8' style='border-collapse:collapse; width:100%;'>";
echo "<tr><th>ID</th><th>User</th><th>Content</th><th>Sent By</th><th>Created</th></tr>";
foreach ($msgs as $m) {
    $color = ($m['sent_by'] === 'admin:Admin' || empty($m['sent_by'])) ? 'red' : 'green';
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>{$m['user_id']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($m['content'], 0, 40)) . "</td>";
    echo "<td style='color:{$color};'><strong>" . htmlspecialchars($m['sent_by'] ?? 'NULL') . "</strong></td>";
    echo "<td>{$m['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Current session
echo "<h2>4. Current Session</h2>";
if (isset($_SESSION['admin_user'])) {
    $u = $_SESSION['admin_user'];
    echo "<p style='color:green;'>✅ Logged in</p>";
    echo "<ul>";
    echo "<li>ID: <strong>{$u['id']}</strong></li>";
    echo "<li>Username: <strong>" . htmlspecialchars($u['username'] ?? 'N/A') . "</strong></li>";
    echo "<li>Display Name: <strong>" . htmlspecialchars($u['display_name'] ?? 'N/A') . "</strong></li>";
    echo "</ul>";
    
    // What will be used
    $adminName = 'Admin';
    if (!empty($u['username'])) {
        $adminName = $u['username'];
    } elseif (!empty($u['display_name'])) {
        $adminName = $u['display_name'];
    }
    echo "<p style='font-size:18px;'>📝 Next message will be saved as: <strong style='color:blue;'>admin:{$adminName}</strong></p>";
} else {
    echo "<p style='color:red;'>❌ Not logged in!</p>";
}

echo "<hr><p><a href='inbox'>← Back to Inbox</a> | <a href='test_session_debug.php'>Test Session Debug</a></p>";
