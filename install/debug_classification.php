<?php
/**
 * Debug script to test customer classification
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CustomerHealthEngineService.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = 1;

echo "<h2>Debug Customer Classification</h2>";

// Get some users with messages
$stmt = $db->query("
    SELECT u.id, u.display_name, 
           (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming') as msg_count,
           (SELECT content FROM messages WHERE user_id = u.id AND direction = 'incoming' ORDER BY created_at DESC LIMIT 1) as last_msg
    FROM users u 
    WHERE u.line_account_id = 1
    ORDER BY msg_count DESC
    LIMIT 10
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Users with messages:</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Msg Count</th><th>Last Message</th><th>Classification</th><th>Emotion</th></tr>";

$healthEngine = new CustomerHealthEngineService($db, $lineAccountId);

foreach ($users as $user) {
    $classification = $healthEngine->classifyCustomer($user['id']);
    
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>" . htmlspecialchars($user['display_name'] ?? 'N/A') . "</td>";
    echo "<td>{$user['msg_count']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($user['last_msg'] ?? '', 0, 50)) . "</td>";
    echo "<td>Type: {$classification['type']}, Confidence: {$classification['confidence']}</td>";
    echo "<td>" . ($classification['emotion'] ?? 'N/A') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Test emotion detection directly
echo "<h3>Test Emotion Detection:</h3>";
$testMessages = [
    'ขอบคุณมากครับ ดีมากเลย',
    'ทำไมช้าจัง รอนานมาก',
    'โอเคครับ เข้าใจแล้ว',
    'งงมาก ไม่เข้าใจเลย',
    'กังวลเรื่องผลข้างเคียง',
    'ด่วนมาก ต้องการตอนนี้เลย',
    'มียาพาราเซตามอลไหม',
    'สวัสดีครับ'
];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Message</th><th>Detected Emotion</th></tr>";

// Use reflection to call private method
$reflection = new ReflectionClass($healthEngine);
$method = $reflection->getMethod('detectEmotion');
$method->setAccessible(true);

foreach ($testMessages as $msg) {
    $emotion = $method->invoke($healthEngine, $msg);
    echo "<tr><td>" . htmlspecialchars($msg) . "</td><td>{$emotion}</td></tr>";
}
echo "</table>";

echo "<h3>Check customer_health_profiles table:</h3>";
try {
    $stmt = $db->query("SELECT * FROM customer_health_profiles LIMIT 5");
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($profiles, true) . "</pre>";
} catch (PDOException $e) {
    echo "<p style='color:red'>Table might not exist: " . $e->getMessage() . "</p>";
}
