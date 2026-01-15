<?php
/**
 * Run Broadcasts Target Type Migration
 * Fix target_type column size to support all values
 */

require_once __DIR__ . '/../config/config.php';

// Direct PDO connection
try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

echo "Starting broadcasts target_type migration...\n";

try {
    // Read migration file
    $sql = file_get_contents(__DIR__ . '/../database/migration_broadcasts_target_type.sql');
    
    // Execute migration
    $db->exec($sql);
    
    echo "✓ Migration completed successfully!\n";
    echo "✓ broadcasts.target_type column updated to VARCHAR(20)\n";
    
} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
