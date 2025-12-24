<?php
/**
 * Debug Slip Upload Issue
 * ตรวจสอบปัญหาการอัพโหลดสลิป
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Debug Slip Upload Issue</h1>";

// 1. Check uploads/slips directory
echo "<h2>1. ตรวจสอบ Directory</h2>";
$uploadDir = __DIR__ . '/uploads/slips/';
echo "<p>Path: {$uploadDir}</p>";

if (is_dir($uploadDir)) {
    echo "<p style='color:green'>✅ Directory exists</p>";
    
    // Check permissions
    $perms = substr(sprintf('%o', fileperms($uploadDir)), -4);
    echo "<p>Permissions: {$perms}</p>";
    
    // Check if writable
    if (is_writable($uploadDir)) {
        echo "<p style='color:green'>✅ Directory is writable</p>";
    } else {
        echo "<p style='color:red'>❌ Directory is NOT writable</p>";
    }
    
    // List files
    $files = glob($uploadDir . '*');
    echo "<p>Files in directory: " . count($files) . "</p>";
    if ($files) {
        echo "<ul>";
        foreach (array_slice($files, -10) as $file) {
            $name = basename($file);
            $size = filesize($file);
            $time = date('Y-m-d H:i:s', filemtime($file));
            echo "<li>{$name} ({$size} bytes) - {$time}</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p style='color:orange'>⚠️ Directory does not exist - will be created on first upload</p>";
    
    // Try to create
    if (mkdir($uploadDir, 0755, true)) {
        echo "<p style='color:green'>✅ Created directory successfully</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to create directory</p>";
    }
}

// 2. Check payment_slips table
echo "<h2>2. ตรวจสอบ payment_slips Table</h2>";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM payment_slips");
    $count = $stmt->fetchColumn();
    echo "<p>Total slips in database: {$count}</p>";
    
    if ($count > 0) {
        $stmt = $db->query("SELECT * FROM payment_slips ORDER BY id DESC LIMIT 5");
        $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Order ID</th><th>Transaction ID</th><th>User ID</th><th>Image URL</th><th>Status</th><th>Created</th></tr>";
        foreach ($slips as $slip) {
            echo "<tr>";
            echo "<td>{$slip['id']}</td>";
            echo "<td>{$slip['order_id']}</td>";
            echo "<td>" . ($slip['transaction_id'] ?? '-') . "</td>";
            echo "<td>" . ($slip['user_id'] ?? '-') . "</td>";
            echo "<td><a href='{$slip['image_url']}' target='_blank'>View</a></td>";
            echo "<td>{$slip['status']}</td>";
            echo "<td>{$slip['created_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:orange'>⚠️ ไม่มีสลิปในระบบ</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// 3. Check recent transactions
echo "<h2>3. Transactions ล่าสุด</h2>";
try {
    $stmt = $db->query("SELECT id, order_number, user_id, status, payment_status, created_at FROM transactions ORDER BY id DESC LIMIT 5");
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Order Number</th><th>User ID</th><th>Status</th><th>Payment</th><th>Created</th><th>Has Slip?</th></tr>";
    foreach ($transactions as $tx) {
        // Check if has slip
        $stmt2 = $db->prepare("SELECT COUNT(*) FROM payment_slips WHERE order_id = ? OR transaction_id = ?");
        $stmt2->execute([$tx['id'], $tx['id']]);
        $hasSlip = $stmt2->fetchColumn() > 0;
        
        echo "<tr>";
        echo "<td>{$tx['id']}</td>";
        echo "<td>{$tx['order_number']}</td>";
        echo "<td>{$tx['user_id']}</td>";
        echo "<td>{$tx['status']}</td>";
        echo "<td>{$tx['payment_status']}</td>";
        echo "<td>{$tx['created_at']}</td>";
        echo "<td>" . ($hasSlip ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// 4. Check error log
echo "<h2>4. Error Log (ล่าสุด)</h2>";
$errorLog = __DIR__ . '/error_log';
if (file_exists($errorLog)) {
    $lines = file($errorLog);
    $lastLines = array_slice($lines, -30);
    
    // Filter for slip-related
    $slipLines = array_filter($lastLines, function($line) {
        return stripos($line, 'slip') !== false || stripos($line, 'upload') !== false || stripos($line, 'payment') !== false;
    });
    
    if ($slipLines) {
        echo "<pre style='background:#f5f5f5; padding:10px; overflow:auto; max-height:300px;'>";
        foreach ($slipLines as $line) {
            echo htmlspecialchars($line);
        }
        echo "</pre>";
    } else {
        echo "<p>ไม่พบ log ที่เกี่ยวกับ slip</p>";
    }
} else {
    echo "<p>ไม่พบไฟล์ error_log</p>";
}

// 5. Test direct upload
echo "<h2>5. ทดสอบอัพโหลดโดยตรง</h2>";
echo "<p><a href='test_slip_upload.php' style='padding:10px 20px; background:#11B0A6; color:white; text-decoration:none; border-radius:5px;'>📤 ไปหน้าทดสอบอัพโหลด</a></p>";

// 6. Check BASE_URL
echo "<h2>6. ตรวจสอบ Config</h2>";
echo "<p>BASE_URL: " . (defined('BASE_URL') ? BASE_URL : 'NOT DEFINED') . "</p>";
?>
