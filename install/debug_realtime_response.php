<?php
/**
 * Debug Realtime API Response
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Inbox Preview Issue</h2>";

// Check jame.ver's line_account_id
$stmt = $db->query("SELECT id, display_name, line_account_id FROM users WHERE id = 15");
$jameUser = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<h3>1. jame.ver User Info:</h3>";
echo "<p>ID: {$jameUser['id']}, Name: {$jameUser['display_name']}, <strong>line_account_id: {$jameUser['line_account_id']}</strong></p>";

// Check Kratae's line_account_id
$stmt = $db->query("SELECT id, display_name, line_account_id FROM users WHERE id = 492");
$krataeUser = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<h3>2. Kratae User Info:</h3>";
echo "<p>ID: {$krataeUser['id']}, Name: {$krataeUser['display_name']}, <strong>line_account_id: {$krataeUser['line_account_id']}</strong></p>";

// Show latest 5 messages for jame.ver
echo "<h3>3. Latest 5 messages for jame.ver (ID 15):</h3>";
$stmt = $db->query("SELECT id, content, message_type, direction, created_at FROM messages WHERE user_id = 15 ORDER BY created_at DESC LIMIT 5");
$latestMsgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Content</th><th>Type</th><th>Direction</th><th>Created At</th></tr>";
foreach ($latestMsgs as $m) {
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($m['content'] ?? '', 0, 50)) . "</td>";
    echo "<td>{$m['message_type']}</td>";
    echo "<td>{$m['direction']}</td>";
    echo "<td>{$m['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// Show latest 5 messages for Kratae
echo "<h3>4. Latest 5 messages for Kratae (ID 492):</h3>";
$stmt = $db->query("SELECT id, content, message_type, direction, created_at FROM messages WHERE user_id = 492 ORDER BY created_at DESC LIMIT 5");
$latestMsgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Content</th><th>Type</th><th>Direction</th><th>Created At</th></tr>";
foreach ($latestMsgs as $m) {
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($m['content'] ?? '', 0, 50)) . "</td>";
    echo "<td>{$m['message_type']}</td>";
    echo "<td>{$m['direction']}</td>";
    echo "<td>{$m['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test query with line_account_id = 3 (jame.ver's account)
$lineAccountId = $jameUser['line_account_id'];
echo "<h3>5. Query with line_account_id = {$lineAccountId}:</h3>";
$stmt = $db->prepare("
    SELECT 
        u.id,
        u.display_name,
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time
    FROM users u
    WHERE u.line_account_id = ?
    AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)
    ORDER BY last_time DESC
    LIMIT 10
");
$stmt->execute([$lineAccountId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Last Message</th><th>Type</th><th>Time</th></tr>";
foreach ($conversations as $conv) {
    $highlight = ($conv['id'] == 15 || $conv['id'] == 492) ? "style='background:yellow;'" : "";
    echo "<tr {$highlight}>";
    echo "<td>{$conv['id']}</td>";
    echo "<td>{$conv['display_name']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($conv['last_message'] ?? '', 0, 50)) . "</td>";
    echo "<td>{$conv['last_type']}</td>";
    echo "<td>{$conv['last_time']}</td>";
    echo "</tr>";
}
echo "</table>";

// Test query with line_account_id = 1 (default)
echo "<h3>6. Query with line_account_id = 1 (default):</h3>";
$stmt = $db->prepare("
    SELECT 
        u.id,
        u.display_name,
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time
    FROM users u
    WHERE u.line_account_id = 1
    AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)
    ORDER BY last_time DESC
    LIMIT 10
");
$stmt->execute();
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Last Message</th><th>Type</th><th>Time</th></tr>";
foreach ($conversations as $conv) {
    $highlight = ($conv['id'] == 15 || $conv['id'] == 492) ? "style='background:yellow;'" : "";
    echo "<tr {$highlight}>";
    echo "<td>{$conv['id']}</td>";
    echo "<td>{$conv['display_name']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($conv['last_message'] ?? '', 0, 50)) . "</td>";
    echo "<td>{$conv['last_type']}</td>";
    echo "<td>{$conv['last_time']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check session
echo "<h3>7. Session Info:</h3>";
session_start();
echo "<p>current_bot_id in session: <strong>" . ($_SESSION['current_bot_id'] ?? 'NOT SET') . "</strong></p>";
echo "<p>line_account_id in session: <strong>" . ($_SESSION['line_account_id'] ?? 'NOT SET') . "</strong></p>";
