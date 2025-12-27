<?php
/**
 * Add appointment_id column to video_calls table
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Adding appointment_id to video_calls table</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if column exists
    $cols = $db->query("DESCRIBE video_calls")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('appointment_id', $cols)) {
        echo "<p style='color: green;'>✅ Column appointment_id already exists!</p>";
    } else {
        // Add column
        $db->exec("ALTER TABLE video_calls ADD COLUMN appointment_id INT NULL AFTER line_account_id");
        echo "<p style='color: green;'>✅ Added appointment_id column</p>";
        
        // Add index
        try {
            $db->exec("ALTER TABLE video_calls ADD INDEX idx_appointment (appointment_id)");
            echo "<p style='color: green;'>✅ Added index idx_appointment</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠️ Index may already exist: " . $e->getMessage() . "</p>";
        }
    }
    
    // Show current columns
    echo "<h3>Current video_calls columns:</h3>";
    echo "<pre>";
    $cols = $db->query("DESCRIBE video_calls")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
    echo "</pre>";
    
    echo "<p style='color: green; font-weight: bold;'>✅ Done! You can now close this page.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
