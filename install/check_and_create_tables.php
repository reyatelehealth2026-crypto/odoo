<?php
/**
 * Check and Create Tables
 * 
 * Verifies if sync tables exist and creates them if missing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== Checking Odoo Sync Tables ===\n\n";
    
    // Check each table
    $tables = ['odoo_orders', 'odoo_invoices', 'odoo_bdos'];
    $missing = [];
    
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
        $exists = $stmt->rowCount() > 0;
        
        if ($exists) {
            echo "✓ {$table} exists\n";
            
            // Show columns
            $colStmt = $db->query("SHOW COLUMNS FROM {$table}");
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
            echo "  Columns: " . implode(', ', $columns) . "\n";
        } else {
            echo "✗ {$table} MISSING\n";
            $missing[] = $table;
        }
    }
    
    if (empty($missing)) {
        echo "\n✓ All tables exist!\n";
        exit(0);
    }
    
    echo "\n⚠️  Missing tables: " . implode(', ', $missing) . "\n";
    echo "\nReading migration SQL...\n";
    
    $sqlFile = __DIR__ . '/migrations/add_odoo_sync_tables.sql';
    
    if (!file_exists($sqlFile)) {
        echo "✗ Migration file not found: {$sqlFile}\n";
        exit(1);
    }
    
    $sql = file_get_contents($sqlFile);
    
    echo "✓ Migration file loaded (" . strlen($sql) . " bytes)\n";
    echo "\nExecuting migration...\n\n";
    
    // Split by semicolons and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $executed = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || str_starts_with($statement, '--')) {
            continue;
        }
        
        try {
            $db->exec($statement);
            $executed++;
            
            // Show what was executed
            if (preg_match('/CREATE TABLE.*?`(\w+)`/i', $statement, $matches)) {
                echo "✓ Created table: {$matches[1]}\n";
            } elseif (preg_match('/ALTER TABLE.*?`(\w+)`/i', $statement, $matches)) {
                echo "✓ Altered table: {$matches[1]}\n";
            }
        } catch (Exception $e) {
            $errors++;
            echo "✗ Error: " . $e->getMessage() . "\n";
            
            // Show problematic statement (first 200 chars)
            echo "  Statement: " . substr($statement, 0, 200) . "...\n";
        }
    }
    
    echo "\n=== Migration Complete ===\n";
    echo "Executed: {$executed} statements\n";
    echo "Errors: {$errors}\n\n";
    
    // Verify tables now exist
    echo "Verifying tables...\n";
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
        $exists = $stmt->rowCount() > 0;
        
        if ($exists) {
            echo "✓ {$table} now exists\n";
            
            // Count rows
            $countStmt = $db->query("SELECT COUNT(*) FROM {$table}");
            $count = $countStmt->fetchColumn();
            echo "  Rows: {$count}\n";
        } else {
            echo "✗ {$table} still missing!\n";
        }
    }
    
} catch (Exception $e) {
    echo "Fatal error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
