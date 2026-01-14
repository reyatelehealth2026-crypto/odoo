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

// Force re-analyze if requested
$forceReanalyze = isset($_GET['force']) && $_GET['force'] == '1';
if ($forceReanalyze) {
    echo "<p style='color:green;font-weight:bold;'>🔄 Force re-analyzing all profiles...</p>";
    // Clear existing profiles to force re-analysis
    try {
        $db->exec("DELETE FROM customer_health_profiles");
        echo "<p style='color:green;'>✓ Cleared existing profiles</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red;'>Error clearing profiles: " . $e->getMessage() . "</p>";
    }
}

echo "<p><a href='?force=1' style='color:blue;'>🔄 Click to force re-analyze all customers</a></p>";

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

echo "<h3>Users with messages (Top 10 by message count):</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
echo "<tr style='background:#f0f0f0;'><th>ID</th><th>Name</th><th>Msg Count</th><th>Last Message</th><th>Type</th><th>Confidence</th><th>Emotion</th><th>Scores</th></tr>";

$healthEngine = new CustomerHealthEngineService($db, $lineAccountId);

$typeColors = [
    'A' => '#e3f2fd',
    'B' => '#fce4ec',
    'C' => '#e8f5e9'
];

foreach ($users as $user) {
    $classification = $healthEngine->classifyCustomer($user['id']);
    $bgColor = $typeColors[$classification['type']] ?? '#fff';
    
    // Format scores
    $scoresStr = '';
    if (isset($classification['scores'])) {
        $scoresStr = "A:" . ($classification['scores']['A'] ?? 0) . 
                     " B:" . ($classification['scores']['B'] ?? 0) . 
                     " C:" . ($classification['scores']['C'] ?? 0);
    }
    
    echo "<tr style='background:{$bgColor};'>";
    echo "<td>{$user['id']}</td>";
    echo "<td>" . htmlspecialchars($user['display_name'] ?? 'N/A') . "</td>";
    echo "<td>{$user['msg_count']}</td>";
    echo "<td style='max-width:200px;overflow:hidden;'>" . htmlspecialchars(mb_substr($user['last_msg'] ?? '', 0, 50)) . "</td>";
    echo "<td style='font-weight:bold;font-size:16px;'>{$classification['type']}</td>";
    echo "<td>" . round(($classification['confidence'] ?? 0) * 100) . "%</td>";
    echo "<td>" . ($classification['emotion'] ?? 'neutral') . "</td>";
    echo "<td style='font-size:11px;'>{$scoresStr}</td>";
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

echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
echo "<tr style='background:#f0f0f0;'><th>Message</th><th>Detected Emotion</th></tr>";

// Use reflection to call private method
$reflection = new ReflectionClass($healthEngine);
$method = $reflection->getMethod('detectEmotion');
$method->setAccessible(true);

foreach ($testMessages as $msg) {
    $emotion = $method->invoke($healthEngine, $msg);
    echo "<tr><td>" . htmlspecialchars($msg) . "</td><td>{$emotion}</td></tr>";
}
echo "</table>";

// Test classification patterns
echo "<h3>Test Classification Patterns:</h3>";
$testPatterns = [
    ['msg' => 'พาราเซตามอล 500 กล่อง', 'expected' => 'A - Short, direct order'],
    ['msg' => 'ด่วนครับ ต้องการวันนี้เลย', 'expected' => 'A - Urgent'],
    ['msg' => 'กังวลเรื่องผลข้างเคียงครับ ปลอดภัยไหม', 'expected' => 'B - Concerned about safety'],
    ['msg' => 'ขอบคุณมากครับ ช่วยแนะนำด้วยนะคะ', 'expected' => 'B - Polite, asks for help'],
    ['msg' => 'ตัวนี้กับตัวนั้นต่างกันยังไงครับ ยี่ห้อไหนดีกว่า', 'expected' => 'C - Comparison request'],
    ['msg' => 'ขอรายละเอียดส่วนประกอบหน่อยครับ', 'expected' => 'C - Wants details'],
];

echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
echo "<tr style='background:#f0f0f0;'><th>Message</th><th>Expected</th><th>Scores</th></tr>";

$analyzeMethod = $reflection->getMethod('analyzeMessagePatterns');
$analyzeMethod->setAccessible(true);

foreach ($testPatterns as $pattern) {
    $scores = $analyzeMethod->invoke($healthEngine, [['content' => $pattern['msg']]]);
    $scoresStr = "A:" . $scores['A'] . " B:" . $scores['B'] . " C:" . $scores['C'];
    $winner = array_keys($scores, max($scores))[0];
    $bgColor = $typeColors[$winner] ?? '#fff';
    echo "<tr style='background:{$bgColor};'>";
    echo "<td>" . htmlspecialchars($pattern['msg']) . "</td>";
    echo "<td>{$pattern['expected']}</td>";
    echo "<td><strong>{$winner}</strong> ({$scoresStr})</td>";
    echo "</tr>";
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
