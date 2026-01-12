<?php
/**
 * Check if GeminiChat has devLog
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/GeminiChat.php';

$db = Database::getInstance()->getConnection();

echo "<h1>GeminiChat Code Check</h1>";

// Check if devLog method exists
$reflection = new ReflectionClass('GeminiChat');
$methods = $reflection->getMethods();

echo "<h2>Methods in GeminiChat:</h2>";
echo "<ul>";
foreach ($methods as $method) {
    echo "<li>" . $method->getName() . "</li>";
}
echo "</ul>";

// Check if devLog exists
if ($reflection->hasMethod('devLog')) {
    echo "<p style='color:green'>✅ devLog method exists</p>";
} else {
    echo "<p style='color:red'>❌ devLog method NOT found</p>";
}

// Test generateResponse
echo "<h2>Test generateResponse:</h2>";
$gemini = new GeminiChat($db, 3);

echo "<p>isEnabled: " . ($gemini->isEnabled() ? 'Yes' : 'No') . "</p>";
echo "<p>getMode: " . $gemini->getMode() . "</p>";

echo "<p>Calling generateResponse...</p>";
$response = $gemini->generateResponse("ทดสอบ", null, []);
echo "<p>Response: " . ($response ? htmlspecialchars(mb_substr($response, 0, 200)) : 'NULL') . "</p>";

// Check latest logs
echo "<h2>Latest GeminiChat Logs:</h2>";
$stmt = $db->query("SELECT * FROM dev_logs WHERE source = 'GeminiChat' ORDER BY created_at DESC LIMIT 10");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($logs)) {
    echo "<p style='color:orange'>No GeminiChat logs found</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Time</th><th>Message</th><th>Data</th></tr>";
    foreach ($logs as $log) {
        echo "<tr><td>{$log['created_at']}</td><td>{$log['message']}</td><td><pre>" . htmlspecialchars($log['data']) . "</pre></td></tr>";
    }
    echo "</table>";
}
