<?php
/**
 * Check Contact Command Logs
 * ตรวจสอบ log การตอบกลับคำสั่ง "ติดต่อ"
 */

// Show all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

header('Content-Type: text/html; charset=utf-8');

$db = Database::getInstance()->getConnection();

echo "<h2>🔍 Contact Command Logs (Last 20)</h2>";
echo "<p>ตรวจสอบ log การตอบกลับคำสั่ง 'ติดต่อ'</p>";

// Get recent contact command logs
$stmt = $db->query("
    SELECT 
        id,
        level,
        category,
        message,
        context,
        user_id,
        created_at
    FROM dev_logs 
    WHERE message LIKE '%Contact command%'
    ORDER BY created_at DESC 
    LIMIT 20
");

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($logs)) {
    echo "<p style='color: orange;'>⚠️ ไม่พบ log ของคำสั่ง 'ติดต่อ'</p>";
    echo "<p>ลองส่งข้อความ 'ติดต่อ' ไปที่บอทแล้วรีเฟรชหน้านี้</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>Time</th>";
    echo "<th>Level</th>";
    echo "<th>Message</th>";
    echo "<th>User ID</th>";
    echo "<th>Details</th>";
    echo "</tr>";
    
    foreach ($logs as $log) {
        $context = json_decode($log['context'], true);
        $bgColor = $log['level'] === 'error' ? '#ffebee' : ($log['level'] === 'debug' ? '#e3f2fd' : '#fff');
        
        echo "<tr style='background: {$bgColor};'>";
        echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
        echo "<td><strong>" . strtoupper($log['level']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($log['message']) . "</td>";
        echo "<td>" . htmlspecialchars($log['user_id'] ?? '-') . "</td>";
        echo "<td><pre style='margin: 0; font-size: 11px;'>" . htmlspecialchars(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre></td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

echo "<hr>";
echo "<h3>📊 Summary</h3>";

// Count by result
$stmt = $db->query("
    SELECT 
        JSON_EXTRACT(context, '$.code') as code,
        COUNT(*) as count
    FROM dev_logs 
    WHERE message = 'Contact command reply result'
    AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    GROUP BY code
");

$summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($summary)) {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>HTTP Code</th><th>Count (Last 24h)</th><th>Status</th></tr>";
    
    foreach ($summary as $row) {
        $code = $row['code'] ?? 'null';
        $status = '';
        $color = '#fff';
        
        if ($code == 200) {
            $status = '✅ Success';
            $color = '#e8f5e9';
        } elseif ($code == 400) {
            $status = '❌ Bad Request (Invalid message format)';
            $color = '#ffebee';
        } elseif ($code == 401) {
            $status = '❌ Unauthorized (Invalid Access Token)';
            $color = '#ffebee';
        } elseif ($code == 404) {
            $status = '❌ Not Found (Reply Token expired)';
            $color = '#ffebee';
        } else {
            $status = '⚠️ Unknown';
            $color = '#fff3e0';
        }
        
        echo "<tr style='background: {$color};'>";
        echo "<td><strong>{$code}</strong></td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>{$status}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>ไม่มีข้อมูลใน 24 ชั่วโมงที่ผ่านมา</p>";
}

echo "<hr>";
echo "<h3>🔗 Related Tools</h3>";
echo "<ul>";
echo "<li><a href='debug_line_accounts.php'>Debug LINE Accounts Configuration</a></li>";
echo "<li><a href='../webhook.php'>Webhook Endpoint</a></li>";
echo "</ul>";
