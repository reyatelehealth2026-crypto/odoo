<?php
/**
 * Run POS (Point of Sale) Migration
 * สร้างตารางสำหรับระบบ POS ขายหน้าร้าน
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🛒 POS (Point of Sale) Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Define POS tables to check
    $posTables = [
        'pos_shifts',
        'pos_transactions',
        'pos_transaction_items',
        'pos_payments',
        'pos_returns',
        'pos_return_items',
        'pos_daily_summary'
    ];
    
    // Check if tables already exist
    echo "📋 Checking existing POS tables:\n";
    foreach ($posTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "⚠️ Table '$table' already exists\n";
        } else {
            echo "➡️ Table '$table' will be created\n";
        }
    }
    echo "\n";
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    echo "🔄 Running POS migration...\n\n";
    
    // Read and execute table creation from SQL file
    $sqlFile = __DIR__ . '/../database/migration_pos.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("Migration file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Extract CREATE TABLE statements
    preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/s', $sql, $matches);
    
    echo "📝 Creating POS tables:\n";
    foreach ($matches[0] as $createStmt) {
        try {
            $db->exec($createStmt);
            // Extract table name for display
            preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
            $tableName = $tableMatch[1] ?? 'unknown';
            echo "✅ Created table: $tableName\n";
            $success++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                preg_match('/CREATE TABLE IF NOT EXISTS `?(\w+)`?/', $createStmt, $tableMatch);
                $tableName = $tableMatch[1] ?? 'unknown';
                echo "⚠️ Skipped (already exists): $tableName\n";
                $skipped++;
            } else {
                echo "❌ Error: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "✅ Success: $success operations\n";
    if ($skipped > 0) {
        echo "⚠️ Skipped: $skipped operations\n";
    }
    if ($errors > 0) {
        echo "❌ Errors: $errors operations\n";
    }
    echo "========================================\n";
    
    // Verify POS tables
    echo "\n📋 Verifying POS tables:\n";
    foreach ($posTables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $countStmt = $db->query("SELECT COUNT(*) FROM `$table`");
            $count = $countStmt->fetchColumn();
            echo "✅ Table '$table' exists ($count rows)\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
    // Show pos_transactions status enum values
    echo "\n📋 Transaction Status values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'status'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    // Show payment method enum values
    echo "\n📋 Payment Method values:\n";
    $stmt = $db->query("SHOW COLUMNS FROM pos_payments LIKE 'payment_method'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        preg_match("/^enum\(\'(.*)\'\)$/", $row['Type'], $matches);
        if (isset($matches[1])) {
            $values = explode("','", $matches[1]);
            foreach ($values as $val) {
                echo "   - $val\n";
            }
        }
    }
    
    echo "\n🎉 POS Migration completed!\n";
    echo "\n<a href='../pos.php'>👉 Go to POS System</a>\n";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
