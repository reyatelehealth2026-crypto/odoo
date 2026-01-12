<?php
/**
 * Check latest webhook logs
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>📋 Latest Logs</h1>";
echo "<p>Time: " . date('Y-m-d H:i:s') . "</p>";

$stmt = $db->query("
    SELECT * FROM dev_logs 
    ORDER BY created_at DESC 
    LIMIT 50
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5' style='border-collapse:collapse;font-size:11px'>";
echo "<tr style='background:#f0f0f0'><th>Time</th><th>Level</th><th>Source</th><th>Message</th><th>Data</th></tr>";
foreach ($logs as $log) {
    $rowColor = 'white';
    if ($log['level'] === 'error') $rowColor = '#ffe0e0';
    elseif ($log['level'] === 'warning') $rowColor = '#fff0e0';
    elseif (strpos($log['message'], 'success') !== false) $rowColor = '#e0ffe0';
    
    echo "<tr style='background:{$rowColor}'>";
    echo "<td nowrap>" . $log['created_at'] . "</td>";
    echo "<td>" . $log['level'] . "</td>";
    echo "<td>" . htmlspecialchars($log['source']) . "</td>";
    echo "<td>" . htmlspecialchars($log['message']) . "</td>";
    echo "<td><pre style='max-width:400px;overflow:auto;margin:0;white-space:pre-wrap;font-size:10px'>" . htmlspecialchars(mb_substr($log['data'] ?? '', 0, 500)) . "</pre></td>";
    echo "</tr>";
}
echo "</table>";
