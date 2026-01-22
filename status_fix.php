<?php
/**
 * Run Status Migration
 * Updates the transactions table status column
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>Status Column Migration</h1>";

try {
    $sqlFile = __DIR__ . '/database/migration_update_transactions_status.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);

    // Split by new line or semicolon if needed, but this is a single statement
    // For single ALTER statement, we can run it directly
    $db->exec($sql);

    echo "<div style='color: green; padding: 20px; border: 1px solid green; border-radius: 5px;'>";
    echo "✅ Successfully updated transactions table status column to VARCHAR(50).";
    echo "</div>";

    echo "<p>You can now go back to <a href='/inventory/?tab=wms&wms_tab=pack'>Inventory Pack Tab</a></p>";

} catch (Exception $e) {
    echo "<div style='color: red; padding: 20px; border: 1px solid red; border-radius: 5px;'>";
    echo "❌ Error: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
