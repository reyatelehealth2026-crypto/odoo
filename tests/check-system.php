<?php
/**
 * Check System Status
 * 
 * Simple check to see if notification system is ready
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>\n<html>\n<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<title>System Check</title>\n";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}";
echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#569cd6;}.warning{color:#dcdcaa;}</style>\n";
echo "</head>\n<body>\n";

echo "<h2 class='info'>🔍 System Status Check</h2>\n";
echo "<pre>\n";

try {
    $db = Database::getInstance()->getConnection();
    echo "<span class='success'>✓ Database connection OK</span>\n\n";
    
    // Check required tables
    $requiredTables = [
        'odoo_notification_preferences',
        'odoo_notification_queue',
        'odoo_notification_log',
        'odoo_notification_batch_groups',
        'odoo_notification_templates'
    ];
    
    echo "<span class='info'>Checking tables...</span>\n";
    $missingTables = [];
    
    foreach ($requiredTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() > 0) {
            echo "<span class='success'>  ✓ {$table}</span>\n";
        } else {
            echo "<span class='error'>  ✗ {$table} (MISSING)</span>\n";
            $missingTables[] = $table;
        }
    }
    
    if (empty($missingTables)) {
        echo "\n<span class='success'>═══════════════════════════════════════════════════</span>\n";
        echo "<span class='success'>✓ All tables exist!</span>\n";
        echo "<span class='success'>═══════════════════════════════════════════════════</span>\n\n";
        
        // Check if classes exist
        echo "<span class='info'>Checking classes...</span>\n";
        $classes = [
            'NotificationPreferencesManager',
            'NotificationBatcher',
            'NotificationQueue',
            'NotificationLogger',
            'NotificationRouter',
            'RoadmapMessageBuilder'
        ];
        
        $missingClasses = [];
        foreach ($classes as $class) {
            $file = __DIR__ . "/../classes/{$class}.php";
            if (file_exists($file)) {
                echo "<span class='success'>  ✓ {$class}.php</span>\n";
            } else {
                echo "<span class='error'>  ✗ {$class}.php (MISSING)</span>\n";
                $missingClasses[] = $class;
            }
        }
        
        if (empty($missingClasses)) {
            echo "\n<span class='success'>═══════════════════════════════════════════════════</span>\n";
            echo "<span class='success'>🎉 System is ready!</span>\n";
            echo "<span class='success'>═══════════════════════════════════════════════════</span>\n\n";
            
            echo "<span class='info'>Next steps:</span>\n";
            echo "  1. Set up cron job for worker\n";
            echo "  2. Test with: <a href='/tests/test-notification-system.php' style='color:#569cd6;'>test-notification-system.php</a>\n";
            echo "  3. Send test webhook\n";
        } else {
            echo "\n<span class='error'>═══════════════════════════════════════════════════</span>\n";
            echo "<span class='error'>⚠️ Missing classes!</span>\n";
            echo "<span class='error'>═══════════════════════════════════════════════════</span>\n\n";
            echo "<span class='warning'>Please upload missing class files to /classes/ folder</span>\n";
        }
        
    } else {
        echo "\n<span class='error'>═══════════════════════════════════════════════════</span>\n";
        echo "<span class='error'>⚠️ Missing tables!</span>\n";
        echo "<span class='error'>═══════════════════════════════════════════════════</span>\n\n";
        
        echo "<span class='warning'>Please run migration first:</span>\n";
        echo "<a href='/install/run_odoo_notification_system_migration.php' style='color:#dcdcaa;'>";
        echo "→ Click here to run migration</a>\n";
    }
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Error: {$e->getMessage()}</span>\n";
    echo "<span class='error'>  {$e->getFile()}:{$e->getLine()}</span>\n";
}

echo "</pre>\n";
echo "</body>\n</html>";
