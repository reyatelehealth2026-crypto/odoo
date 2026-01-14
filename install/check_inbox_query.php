<?php
/**
 * Check which query inbox-v2 is using
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Check Inbox Query Version</h2>";

// Read inbox-v2.php and check query
$inboxFile = file_get_contents(__DIR__ . '/../inbox-v2.php');

if (strpos($inboxFile, 'v2.1 - fixed') !== false) {
    echo "<p style='color:green;font-weight:bold;'>✓ inbox-v2.php is using NEW query (v2.1)</p>";
} else {
    echo "<p style='color:red;font-weight:bold;'>✗ inbox-v2.php is using OLD query</p>";
}

if (strpos($inboxFile, 'MAX(id) as max_id') !== false) {
    echo "<p style='color:red;'>✗ Still using MAX(id) - OLD query</p>";
} else {
    echo "<p style='color:green;'>✓ Not using MAX(id)</p>";
}

if (strpos($inboxFile, 'ORDER BY created_at DESC LIMIT 1') !== false) {
    echo "<p style='color:green;'>✓ Using subquery with ORDER BY created_at DESC</p>";
} else {
    echo "<p style='color:red;'>✗ Not using subquery</p>";
}

// Test the query directly
echo "<h3>Test Query for jame.ver (user 15):</h3>";

$sql = "SELECT u.id, u.display_name,
        (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
        (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time
        FROM users u 
        WHERE u.id = 15";
$stmt = $db->query($sql);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Value</th></tr>";
foreach ($result as $key => $value) {
    echo "<tr><td>{$key}</td><td>" . htmlspecialchars($value ?? '') . "</td></tr>";
}
echo "</table>";

echo "<p>If last_msg shows '.' and last_type shows 'text', the query is correct.</p>";
echo "<p>If last_msg shows image URL and last_type shows 'image', the query is wrong.</p>";
