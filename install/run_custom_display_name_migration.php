<?php
/**
 * Run Custom Display Name Migration
 * Adds custom_display_name field to users table
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Starting custom_display_name migration...\n\n";
    
    // Read migration file
    $migrationFile = __DIR__ . '/../database/migration_custom_display_name.sql';
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: {$migrationFile}");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        echo "Executing: " . substr($statement, 0, 100) . "...\n";
        $db->exec($statement);
        echo "✓ Success\n\n";
    }
    
    echo "✅ Migration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Update API to use custom_display_name\n";
    echo "2. Update webhook to respect custom_display_name\n";
    echo "3. Update display logic to prioritize custom_display_name\n";
    
} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
