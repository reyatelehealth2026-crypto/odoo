<?php
/**
 * Debug Messages Line Account ID
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Debug Messages Line Account ID</h1>";

// Check if messages table has line_account_id column
echo "<h2>1. Check messages table structure:</h2>";
$stmt = $db->query("SHOW COLUMNS FROM messages LIKE 'line_account_id'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col) {
    echo "<p style='color:green'>✓ messages table HAS line_account_id column</p>";
    echo "<pre>" . print_r($col, true) . "</pre>";
} else {
    echo "<p style='color:red'>✗ messages table does NOT have line_account_id column!</p>";
}

// Check messages for user 15 (jame.ver)
echo "<h2>2. Messages for user 15 (jame.ver):</h2>";
$stmt = $db->prepare("SELECT id, line_account_id, content, message_type, direction, created_at FROM messages WHERE user_id = 15 ORDER BY created_at DESC LIMIT 10");
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>line_account_id</th><th>Content</th><th>Type</th><th>Direction</th><th>Created At</th></tr>";
foreach ($messages as $msg) {
    $content = $msg['content'];
    if (strlen($content) > 30) {
        $content = mb_substr($content, 0, 30) . '...';
    }
    $lineAccountId = $msg['line_account_id'] ?? 'NULL';
    $color = ($lineAccountId == 3) ? 'green' : 'red';
    echo "<tr>";
    echo "<td>{$msg['id']}</td>";
    echo "<td style='color:{$color}'>{$lineAccountId}</td>";
    echo "<td>" . htmlspecialchars($content) . "</td>";
    echo "<td>{$msg['message_type']}</td>";
    echo "<td>{$msg['direction']}</td>";
    echo "<td>{$msg['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// Check user 15's line_account_id
echo "<h2>3. User 15 info:</h2>";
$stmt = $db->prepare("SELECT id, display_name, line_account_id FROM users WHERE id = 15");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($user, true) . "</pre>";

// Test query with line_account_id = 3 in subquery
echo "<h2>4. Test query WITH line_account_id filter in subquery:</h2>";
$stmt = $db->prepare("
    SELECT 
        u.id,
        u.display_name,
        (SELECT content FROM messages WHERE user_id = u.id AND line_account_id = 3 ORDER BY created_at DESC LIMIT 1) as last_message_filtered,
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message_unfiltered
    FROM users u
    WHERE u.id = 15
");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($result, true) . "</pre>";

if ($result['last_message_filtered'] !== $result['last_message_unfiltered']) {
    echo "<p style='color:red'>⚠️ DIFFERENT! Filtered: '{$result['last_message_filtered']}' vs Unfiltered: '{$result['last_message_unfiltered']}'</p>";
} else {
    echo "<p style='color:green'>✓ Same result</p>";
}

// Count messages by line_account_id for user 15
echo "<h2>5. Messages count by line_account_id for user 15:</h2>";
$stmt = $db->prepare("SELECT line_account_id, COUNT(*) as cnt FROM messages WHERE user_id = 15 GROUP BY line_account_id");
$stmt->execute();
$counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($counts, true) . "</pre>";
