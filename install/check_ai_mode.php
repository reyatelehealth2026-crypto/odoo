<?php
/**
 * Check and fix AI mode in database
 */
header('Content-Type: text/plain; charset=utf-8');

try {
    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance()->getConnection();
    
    echo "=== AI Settings ===\n";
    $stmt = $db->query("SELECT id, line_account_id, ai_mode, is_enabled, model FROM ai_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
    
    // Update all to sales mode
    if (isset($_GET['fix']) || (isset($argv[1]) && $argv[1] === 'fix')) {
        echo "\n=== Updating to sales mode ===\n";
        $db->exec("UPDATE ai_settings SET ai_mode = 'sales'");
        echo "Done!\n";
        
        // Verify
        $stmt = $db->query("SELECT id, line_account_id, ai_mode FROM ai_settings");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString();
}
