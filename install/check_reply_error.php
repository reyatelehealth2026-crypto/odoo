<?php
/**
 * Debug: Check replyMessage error logs
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Reply Message Debug</h1>";

echo "<h2>Recent replyMessage Failed Logs</h2>";

// ดึง log ที่เกี่ยวกับ replyMessage failed
$stmt = $db->query("
    SELECT * FROM dev_logs 
    WHERE message LIKE '%replyMessage failed%' 
    ORDER BY created_at DESC 
    LIMIT 10
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($logs)) {
    echo "<p style='color:green'>✅ No replyMessage failed logs found.</p>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
    echo "<tr style='background:#f0f0f0'><th>Time</th><th>Message</th><th>replyCode</th><th>replyBody (Error Detail)</th></tr>";
    foreach ($logs as $log) {
        $data = json_decode($log['data'], true);
        $replyCode = $data['replyCode'] ?? 'N/A';
        $replyBody = $data['replyBody'] ?? null;
        
        // Highlight error codes
        $codeColor = $replyCode == 200 ? 'green' : 'red';
        
        // Parse error message
        $errorMsg = 'N/A';
        if ($replyBody) {
            if (isset($replyBody['message'])) {
                $errorMsg = $replyBody['message'];
            } elseif (isset($replyBody['details'])) {
                $errorMsg = json_encode($replyBody['details'], JSON_UNESCAPED_UNICODE);
            } else {
                $errorMsg = json_encode($replyBody, JSON_UNESCAPED_UNICODE);
            }
        }
        
        echo "<tr>";
        echo "<td>" . $log['created_at'] . "</td>";
        echo "<td>" . htmlspecialchars($log['message']) . "</td>";
        echo "<td style='color:{$codeColor};font-weight:bold'>" . $replyCode . "</td>";
        echo "<td><pre style='max-width:400px;overflow:auto;margin:0'>" . htmlspecialchars($errorMsg) . "</pre></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Explain common error codes
    echo "<h3>📖 Error Code Reference:</h3>";
    echo "<ul>";
    echo "<li><b>400</b> - Invalid request (bad message format, invalid replyToken)</li>";
    echo "<li><b>401</b> - Unauthorized (invalid channel access token)</li>";
    echo "<li><b>403</b> - Forbidden (bot blocked by user)</li>";
    echo "<li><b>408</b> - Request timeout</li>";
    echo "<li><b>429</b> - Rate limit exceeded</li>";
    echo "<li><b>500</b> - LINE server error</li>";
    echo "</ul>";
    
    echo "<h3>🔑 Common replyToken Issues:</h3>";
    echo "<ul>";
    echo "<li><b>'Invalid reply token'</b> - Token หมดอายุ (>30 วินาที) หรือถูกใช้ไปแล้ว</li>";
    echo "<li><b>'The request body has X error(s)'</b> - Message format ผิด</li>";
    echo "</ul>";
}

echo "<hr><h2>Recent AI Logs (last 20)</h2>";

$stmt = $db->query("
    SELECT * FROM dev_logs 
    WHERE source LIKE 'AI%' OR source = 'webhook'
    ORDER BY created_at DESC 
    LIMIT 20
");
$aiLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
echo "<tr style='background:#f0f0f0'><th>Time</th><th>Source</th><th>Message</th><th>Data</th></tr>";
foreach ($aiLogs as $log) {
    $rowColor = strpos($log['message'], 'failed') !== false ? '#ffe0e0' : 'white';
    echo "<tr style='background:{$rowColor}'>";
    echo "<td>" . $log['created_at'] . "</td>";
    echo "<td>" . htmlspecialchars($log['source']) . "</td>";
    echo "<td>" . htmlspecialchars($log['message']) . "</td>";
    echo "<td><pre style='max-width:400px;overflow:auto;margin:0'>" . htmlspecialchars(mb_substr($log['data'] ?? '', 0, 500)) . "</pre></td>";
    echo "</tr>";
}
echo "</table>";

// Check AI timing
echo "<hr><h2>⏱️ AI Processing Time Analysis</h2>";
$stmt = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as time,
        source,
        message,
        JSON_EXTRACT(data, '$.elapsed_ms') as elapsed_ms
    FROM dev_logs 
    WHERE source LIKE 'AI%' AND data LIKE '%elapsed_ms%'
    ORDER BY created_at DESC 
    LIMIT 10
");
$timingLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($timingLogs) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
    echo "<tr style='background:#f0f0f0'><th>Time</th><th>Source</th><th>Elapsed (ms)</th></tr>";
    foreach ($timingLogs as $log) {
        $elapsed = $log['elapsed_ms'] ?? 'N/A';
        $color = ($elapsed && $elapsed > 25000) ? 'red' : 'green';
        echo "<tr>";
        echo "<td>" . $log['time'] . "</td>";
        echo "<td>" . htmlspecialchars($log['source']) . "</td>";
        echo "<td style='color:{$color}'>" . $elapsed . " ms</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No timing data found.</p>";
}
