<?php
/**
 * Odoo Notification System Migration Runner
 *
 * Creates complete notification system with:
 * - Notification preferences
 * - Notification queue
 * - Notification log
 * - Batch groups for roadmap
 * - Template management
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>\n<html>\n<head>\n";
echo "<meta charset='UTF-8'>\n";
echo "<title>Odoo Notification System Migration</title>\n";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#d4d4d4;}";
echo ".success{color:#4ec9b0;}.error{color:#f48771;}.info{color:#569cd6;}.warning{color:#dcdcaa;}</style>\n";
echo "</head>\n<body>\n";

echo "<h2 class='info'>🚀 Odoo Notification System Migration</h2>\n";
echo "<pre>\n";

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<span class='info'>📋 Reading migration SQL file...</span>\n";
    $sqlFile = __DIR__ . '/../database/migration_odoo_notification_system.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    echo "<span class='success'>✓ SQL file loaded</span>\n\n";
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', preg_split('/;[\r\n]+/', $sql)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^--/', $stmt) && 
                   !preg_match('/^\/\*/', $stmt);
        }
    );
    
    echo "<span class='info'>📊 Found " . count($statements) . " SQL statements</span>\n\n";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $index => $statement) {
        // Extract table name or operation for logging
        $label = 'Statement ' . ($index + 1);
        if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
            $label = "CREATE TABLE {$matches[1]}";
        } elseif (preg_match('/INSERT INTO.*?`?(\w+)`?/i', $statement, $matches)) {
            $label = "INSERT INTO {$matches[1]}";
        } elseif (preg_match('/UPDATE.*?`?(\w+)`?/i', $statement, $matches)) {
            $label = "UPDATE {$matches[1]}";
        }
        
        try {
            $db->exec($statement);
            echo "<span class='success'>✓ {$label}</span>\n";
            $successCount++;
        } catch (Exception $e) {
            // Check if error is "table already exists" which is OK
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "<span class='warning'>⚠ {$label} (already exists)</span>\n";
                $successCount++;
            } else {
                echo "<span class='error'>✗ {$label}: {$e->getMessage()}</span>\n";
                $errorCount++;
            }
        }
    }
    
    echo "\n<span class='info'>═══════════════════════════════════════════════════</span>\n";
    echo "<span class='success'>✓ Migration completed</span>\n";
    echo "<span class='info'>  Success: {$successCount}</span>\n";
    if ($errorCount > 0) {
        echo "<span class='error'>  Errors: {$errorCount}</span>\n";
    }
    echo "<span class='info'>═══════════════════════════════════════════════════</span>\n\n";
    
    // Verify tables were created
    echo "<span class='info'>🔍 Verifying tables...</span>\n\n";
    
    $tables = [
        'odoo_notification_preferences',
        'odoo_notification_queue',
        'odoo_notification_log',
        'odoo_notification_batch_groups',
        'odoo_notification_templates'
    ];
    
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() > 0) {
            // Get row count
            $countStmt = $db->query("SELECT COUNT(*) as count FROM `{$table}`");
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            echo "<span class='success'>✓ {$table} ({$count} rows)</span>\n";
        } else {
            echo "<span class='error'>✗ {$table} (not found)</span>\n";
        }
    }
    
    echo "\n<span class='info'>═══════════════════════════════════════════════════</span>\n";
    echo "<span class='success'>🎉 Notification System Ready!</span>\n";
    echo "<span class='info'>═══════════════════════════════════════════════════</span>\n\n";
    
    echo "<span class='info'>📋 Next Steps:</span>\n";
    echo "  1. Implement core classes (NotificationRouter, NotificationBatcher, etc.)\n";
    echo "  2. Integrate with OdooWebhookHandler\n";
    echo "  3. Setup notification worker (worker/notification-worker.php)\n";
    echo "  4. Create LIFF settings page\n";
    echo "  5. Test roadmap notifications\n\n";
    
    echo "<span class='info'>📊 Default Preferences Loaded:</span>\n";
    $stmt = $db->query("SELECT event_type, enabled, batch_enabled FROM odoo_notification_preferences WHERE line_user_id = '_default_customer' ORDER BY event_type");
    $prefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prefs as $pref) {
        $status = $pref['enabled'] ? '✓' : '✗';
        $batch = $pref['batch_enabled'] ? '[BATCH]' : '';
        echo "  {$status} {$pref['event_type']} {$batch}\n";
    }
    
} catch (Exception $e) {
    echo "<span class='error'>✗ Fatal error: {$e->getMessage()}</span>\n";
    echo "<span class='error'>  {$e->getFile()}:{$e->getLine()}</span>\n";
}

echo "</pre>\n";
echo "</body>\n</html>";
