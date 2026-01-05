<?php
/**
 * Zone Types Migration Runner
 * Creates zone_types table and inserts default types
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Zone Types Migration</h2>";
echo "<pre>";

try {
    // Read migration SQL
    $sql = file_get_contents(__DIR__ . '/../database/migration_zone_types.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        echo "Executing: " . substr($statement, 0, 80) . "...\n";
        $db->exec($statement);
        echo "✓ Success\n\n";
    }
    
    // Verify table exists
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM zone_types");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo "\n=== Migration Complete ===\n";
    echo "Zone types in database: $count\n";
    
    // Show all zone types
    $stmt = $db->query("SELECT code, label, color, icon, is_default FROM zone_types ORDER BY sort_order");
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nZone Types:\n";
    foreach ($types as $type) {
        $default = $type['is_default'] ? ' (default)' : '';
        echo "- {$type['code']}: {$type['label']} [{$type['color']}, {$type['icon']}]$default\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
