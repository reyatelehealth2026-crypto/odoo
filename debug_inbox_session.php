<?php
/**
 * Debug Inbox Session - ตรวจสอบว่า session เก็บข้อมูลอะไรบ้าง
 */
session_start();

echo "<h2>🔐 Debug Session for Inbox</h2>";

echo "<h3>1. Full Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>2. admin_user Details:</h3>";
if (isset($_SESSION['admin_user'])) {
    $adminUser = $_SESSION['admin_user'];
    echo "<table border='1' cellpadding='5'>";
    foreach ($adminUser as $key => $value) {
        echo "<tr><td><strong>{$key}</strong></td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
    }
    echo "</table>";
    
    echo "<h3>3. Name Resolution:</h3>";
    $displayName = $adminUser['display_name'] ?? null;
    $username = $adminUser['username'] ?? null;
    $adminName = $_SESSION['admin_name'] ?? null;
    
    echo "<p>display_name: <strong>" . ($displayName ?: 'NOT SET') . "</strong></p>";
    echo "<p>username: <strong>" . ($username ?: 'NOT SET') . "</strong></p>";
    echo "<p>admin_name (legacy): <strong>" . ($adminName ?: 'NOT SET') . "</strong></p>";
    
    // What inbox.php will use
    $finalName = $displayName ?? $username ?? $adminName ?? 'Admin';
    echo "<p style='color:green; font-size:18px;'>✅ Final name for inbox: <strong>{$finalName}</strong></p>";
} else {
    echo "<p style='color:red;'>❌ Not logged in (admin_user not in session)</p>";
}

echo "<h3>4. Check admin_users table:</h3>";
require_once 'config/config.php';
require_once 'config/database.php';
$db = Database::getInstance()->getConnection();

$stmt = $db->query("SELECT id, username, display_name, email, role FROM admin_users LIMIT 10");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Username</th><th>Display Name</th><th>Email</th><th>Role</th></tr>";
foreach ($users as $u) {
    echo "<tr>";
    echo "<td>{$u['id']}</td>";
    echo "<td>{$u['username']}</td>";
    echo "<td><strong>" . ($u['display_name'] ?: '-') . "</strong></td>";
    echo "<td>{$u['email']}</td>";
    echo "<td>{$u['role']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>5. Recent Messages sent_by:</h3>";
$stmt = $db->query("SELECT id, content, sent_by, created_at FROM messages WHERE direction = 'outgoing' ORDER BY created_at DESC LIMIT 5");
$msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Content</th><th>Sent By</th><th>Created</th></tr>";
foreach ($msgs as $m) {
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($m['content'], 0, 20)) . "</td>";
    echo "<td><strong>" . htmlspecialchars($m['sent_by'] ?? '-') . "</strong></td>";
    echo "<td>{$m['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr><p><a href='inbox'>Go to Inbox</a></p>";
