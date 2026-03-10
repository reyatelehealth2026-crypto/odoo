<?php
/**
 * Debug Queue Table
 * Check what's wrong with the queue table
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>\n<html>\n<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<title>Debug Queue</title>\n";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}";
echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#569cd6;}.warning{color:#dcdcaa;}</style>\n";
echo "</head>\n<body>\n";

echo "<h2 class='info'>🔍 Debug Queue Table</h2>\n";
echo "<pre>\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'odoo_notification_queue'");
    if ($stmt->rowCount() === 0) {
        echo "<span class='error'>✗ Table 'odoo_notification_queue' does not exist!</span>\n";
        echo "<span class='warning'>Please run migration first.</span>\n";
        exit;
    }
    
    echo "<span class='success'>✓ Table exists</span>\n\n";
    
    // Show table structure
    echo "<span class='info'>Table structure:</span>\n";
    $stmt = $db->query("DESCRIBE odoo_notification_queue");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $col) {
        echo "  {$col['Field']} - {$col['Type']} - {$col['Null']} - {$col['Key']}\n";
    }
    
    echo "\n<span class='info'>Testing INSERT...</span>\n";
    
    // Try minimal insert
    try {
        $stmt = $db->prepare("
            INSERT INTO odoo_notification_queue
            (delivery_id, event_type, recipient_type, line_user_id, message_type, message_payload)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $testId = 'debug_' . time();
        $result = $stmt->execute([
            $testId,
            'test.event',
            'customer',
            'U_test',
            'text',
            json_encode(['text' => 'test'])
        ]);
        
        if ($result) {
            $insertId = $db->lastInsertId();
            echo "<span class='success'>✓ INSERT works! ID: {$insertId}</span>\n";
            
            // Clean up
            $db->exec("DELETE FROM odoo_notification_queue WHERE delivery_id = '{$testId}'");
            echo "<span class='success'>✓ Cleanup done</span>\n";
        } else {
            echo "<span class='error'>✗ INSERT failed but no exception</span>\n";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ INSERT error: {$e->getMessage()}</span>\n";
        echo "<span class='warning'>SQL State: " . $stmt->errorInfo()[0] . "</span>\n";
        echo "<span class='warning'>Error Code: " . $stmt->errorInfo()[1] . "</span>\n";
        echo "<span class='warning'>Error Message: " . $stmt->errorInfo()[2] . "</span>\n";
    }
    
    echo "\n<span class='info'>Testing with all fields...</span>\n";
    
    try {
        $stmt = $db->prepare("
            INSERT INTO odoo_notification_queue
            (delivery_id, event_type, order_id, order_ref, recipient_type, 
             line_user_id, line_account_id, message_type, message_payload, 
             alt_text, batch_group_id, is_batched, priority, scheduled_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $testId = 'debug_full_' . time();
        $result = $stmt->execute([
            $testId,
            'test.event',
            99999,
            'TEST-001',
            'customer',
            'U_test',
            NULL,
            'text',
            json_encode(['text' => 'test']),
            'Test message',
            NULL,
            0,
            5,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+24 hours'))
        ]);
        
        if ($result) {
            $insertId = $db->lastInsertId();
            echo "<span class='success'>✓ Full INSERT works! ID: {$insertId}</span>\n";
            
            // Clean up
            $db->exec("DELETE FROM odoo_notification_queue WHERE delivery_id = '{$testId}'");
            echo "<span class='success'>✓ Cleanup done</span>\n";
        }
        
    } catch (Exception $e) {
        echo "<span class='error'>✗ Full INSERT error: {$e->getMessage()}</span>\n";
    }
    
    echo "\n<span class='success'>═══════════════════════════════════════════════════</span>\n";
    echo "<span class='success'>Debug complete!</span>\n";
    echo "<span class='success'>═══════════════════════════════════════════════════</span>\n";
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Fatal error: {$e->getMessage()}</span>\n";
}

echo "</pre>\n";
echo "</body>\n</html>";
