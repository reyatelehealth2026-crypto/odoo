<?php
/**
 * Fix Points Settings Decimal Precision
 * เปลี่ยน DECIMAL precision เพื่อรองรับค่า 0.001 หรือน้อยกว่า
 * 
 * Run this script once: php install/fix_points_settings_precision.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Fixing Points Settings Decimal Precision ===\n\n";

try {
    // 1. Fix points_per_baht column to support more decimal places
    echo "1. Fixing points_per_baht column precision...\n";

    $alterQueries = [
        // Change from DECIMAL(10,2) to DECIMAL(10,6) for more precision
        "ALTER TABLE points_settings MODIFY COLUMN points_per_baht DECIMAL(10,6) DEFAULT 0.001000 COMMENT 'แต้มต่อบาท (รองรับถึง 0.000001)'",

        // Also fix min_order_for_points to DECIMAL for flexibility
        "ALTER TABLE points_settings MODIFY COLUMN min_order_for_points DECIMAL(12,2) DEFAULT 0.00 COMMENT 'ยอดสั่งซื้อขั้นต่ำเพื่อรับแต้ม'"
    ];

    foreach ($alterQueries as $sql) {
        try {
            $db->exec($sql);
            echo "   ✅ " . substr($sql, 0, 60) . "...\n";
        } catch (Exception $e) {
            echo "   ⚠️ " . $e->getMessage() . "\n";
        }
    }

    // 2. Verify the change
    echo "\n2. Verifying column definitions...\n";
    $stmt = $db->query("DESCRIBE points_settings");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($columns as $col) {
        if ($col['Field'] === 'points_per_baht') {
            echo "   points_per_baht: {$col['Type']}\n";
        }
        if ($col['Field'] === 'min_order_for_points') {
            echo "   min_order_for_points: {$col['Type']}\n";
        }
    }

    // 3. Show current settings
    echo "\n3. Current points_settings values:\n";
    $stmt = $db->query("SELECT line_account_id, points_per_baht, min_order_for_points FROM points_settings ORDER BY line_account_id");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($settings)) {
        echo "   (no settings found)\n";
    } else {
        foreach ($settings as $s) {
            echo "   Line Account {$s['line_account_id']}: points_per_baht = {$s['points_per_baht']}, min_order = {$s['min_order_for_points']}\n";
        }
    }

    echo "\n=== Done! ===\n";
    echo "Now you can set points_per_baht to values like 0.001, 0.0001, etc.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
