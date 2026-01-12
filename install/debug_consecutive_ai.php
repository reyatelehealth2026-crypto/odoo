<?php
/**
 * Debug Consecutive AI Calls
 * ดูว่าทำไม AI ไม่ตอบติดกัน 2 ครั้ง
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Debug Consecutive AI Calls</h1>";
echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";

// 1. ดู webhook_events ล่าสุด
echo "<h2>1. Webhook Events (ล่าสุด 20 รายการ)</h2>";
try {
    $stmt = $db->query("SELECT * FROM webhook_events ORDER BY id DESC LIMIT 20");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Event ID</th><th>Created</th></tr>";
    foreach ($events as $e) {
        echo "<tr><td>{$e['id']}</td><td>" . htmlspecialchars($e['event_id'] ?? '') . "</td><td>" . ($e['created_at'] ?? '') . "</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:orange'>Table webhook_events ไม่มี หรือ error: " . $e->getMessage() . "</p>";
}

// 2. ดู dev_logs ที่เกี่ยวกับ AI
echo "<h2>2. AI Related Logs (ล่าสุด 30 รายการ)</h2>";
$stmt = $db->query("
    SELECT * FROM dev_logs 
    WHERE source IN ('webhook', 'GeminiChat') 
    OR message LIKE '%AI%' 
    OR message LIKE '%Gemini%'
    ORDER BY created_at DESC 
    LIMIT 30
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='font-size:11px'>";
echo "<tr style='background:#f0f0f0'><th>Time</th><th>Level</th><th>Source</th><th>Message</th><th>Data</th></tr>";
foreach ($logs as $log) {
    $rowColor = 'white';
    if (($log['level'] ?? '') === 'error') $rowColor = '#ffe0e0';
    elseif (strpos($log['message'] ?? '', 'response') !== false) $rowColor = '#e0ffe0';
    
    echo "<tr style='background:{$rowColor}'>";
    echo "<td nowrap>" . ($log['created_at'] ?? '') . "</td>";
    echo "<td>" . ($log['level'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($log['source'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($log['message'] ?? '') . "</td>";
    echo "<td><pre style='max-width:400px;overflow:auto;margin:0;font-size:10px'>" . htmlspecialchars(mb_substr($log['data'] ?? '', 0, 300)) . "</pre></td>";
    echo "</tr>";
}
echo "</table>";

// 3. ดู messages ล่าสุด
echo "<h2>3. Messages (ล่าสุด 20 รายการ)</h2>";
$stmt = $db->query("
    SELECT m.*, u.display_name 
    FROM messages m 
    LEFT JOIN users u ON m.user_id = u.id
    ORDER BY m.created_at DESC 
    LIMIT 20
");
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='font-size:11px'>";
echo "<tr style='background:#f0f0f0'><th>Time</th><th>User</th><th>Direction</th><th>Content</th></tr>";
foreach ($messages as $msg) {
    $bgColor = $msg['direction'] === 'incoming' ? '#e0f0ff' : '#f0ffe0';
    echo "<tr style='background:{$bgColor}'>";
    echo "<td nowrap>" . ($msg['created_at'] ?? '') . "</td>";
    echo "<td>" . htmlspecialchars($msg['display_name'] ?? 'Unknown') . "</td>";
    echo "<td>" . $msg['direction'] . "</td>";
    echo "<td>" . htmlspecialchars(mb_substr($msg['content'] ?? '', 0, 100)) . "</td>";
    echo "</tr>";
}
echo "</table>";

// 4. ดู ai_chat_logs
echo "<h2>4. AI Chat Logs (ล่าสุด 10 รายการ)</h2>";
try {
    $stmt = $db->query("SELECT * FROM ai_chat_logs ORDER BY created_at DESC LIMIT 10");
    $aiLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5' style='font-size:11px'>";
    echo "<tr style='background:#f0f0f0'><th>Time</th><th>User Msg</th><th>AI Response</th><th>Time(ms)</th></tr>";
    foreach ($aiLogs as $log) {
        echo "<tr>";
        echo "<td nowrap>" . ($log['created_at'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($log['user_message'] ?? '', 0, 50)) . "</td>";
        echo "<td>" . htmlspecialchars(mb_substr($log['ai_response'] ?? '', 0, 100)) . "</td>";
        echo "<td>" . ($log['response_time_ms'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:orange'>Table ai_chat_logs ไม่มี</p>";
}

// 5. ดู ai_user_mode
echo "<h2>5. AI User Mode (active)</h2>";
try {
    $stmt = $db->query("SELECT * FROM ai_user_mode WHERE expires_at > NOW() ORDER BY created_at DESC LIMIT 10");
    $modes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($modes)) {
        echo "<p>ไม่มี active AI mode</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>User ID</th><th>Mode</th><th>Expires</th></tr>";
        foreach ($modes as $m) {
            echo "<tr><td>{$m['user_id']}</td><td>{$m['ai_mode']}</td><td>{$m['expires_at']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:orange'>Table ai_user_mode ไม่มี</p>";
}

echo "<hr><p><a href='check_latest_logs.php'>← Back to Latest Logs</a></p>";
