<?php
/**
 * Show Table Status
 * 
 * Quick check of table existence and structure
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== Table Status Check ===\n\n";
    
    $tables = ['odoo_orders', 'odoo_invoices', 'odoo_bdos'];
    
    foreach ($tables as $table) {
        echo str_repeat("=", 60) . "\n";
        echo "Table: {$table}\n";
        echo str_repeat("=", 60) . "\n";
        
        try {
            // Check if table exists
            $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
            $exists = $stmt->rowCount() > 0;
            
            if (!$exists) {
                echo "❌ TABLE DOES NOT EXIST\n\n";
                continue;
            }
            
            echo "✓ Table exists\n\n";
            
            // Show CREATE TABLE statement
            $createStmt = $db->query("SHOW CREATE TABLE {$table}");
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($createRow) {
                $createSql = $createRow['Create Table'];
                
                // Extract columns
                preg_match_all('/`(\w+)`\s+([^,\n]+)/i', $createSql, $matches, PREG_SET_ORDER);
                
                echo "Columns:\n";
                foreach ($matches as $match) {
                    if (in_array($match[1], ['PRIMARY', 'KEY', 'UNIQUE', 'INDEX'])) continue;
                    echo "  - {$match[1]}: {$match[2]}\n";
                }
                
                echo "\n";
                
                // Check for required columns
                $requiredCols = [
                    'odoo_orders' => ['id', 'order_id', 'order_name', 'partner_id'],
                    'odoo_invoices' => ['id', 'invoice_id', 'invoice_number', 'partner_id'],
                    'odoo_bdos' => ['id', 'bdo_id', 'bdo_name', 'partner_id']
                ];
                
                if (isset($requiredCols[$table])) {
                    echo "Required columns check:\n";
                    foreach ($requiredCols[$table] as $col) {
                        $hasCol = stripos($createSql, "`{$col}`") !== false;
                        echo "  " . ($hasCol ? "✓" : "✗") . " {$col}\n";
                    }
                    echo "\n";
                }
                
                // Count rows
                $countStmt = $db->query("SELECT COUNT(*) FROM {$table}");
                $count = $countStmt->fetchColumn();
                echo "Row count: {$count}\n";
            }
            
        } catch (Exception $e) {
            echo "❌ ERROR: {$e->getMessage()}\n";
        }
        
        echo "\n";
    }
    
    echo str_repeat("=", 60) . "\n";
    echo "Check complete\n";
    
} catch (Exception $e) {
    echo "Fatal error: {$e->getMessage()}\n";
    exit(1);
}
