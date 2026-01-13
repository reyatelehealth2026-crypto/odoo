<?php
/**
 * Migration: Add cost_price column to business_items table
 * This column is needed for drug pricing calculations
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Add cost_price Column Migration</h1>";
echo "<pre>";

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    echo "✅ Database connected\n\n";
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit;
}

// Check if column exists
echo "=== Checking business_items table structure ===\n";
try {
    $stmt = $db->query("DESCRIBE business_items");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Current columns: " . implode(', ', $columns) . "\n\n";
    
    if (in_array('cost_price', $columns)) {
        echo "✅ cost_price column already exists\n";
    } else {
        echo "Adding cost_price column...\n";
        
        $db->exec("ALTER TABLE business_items ADD COLUMN cost_price DECIMAL(10,2) NULL AFTER sale_price");
        
        echo "✅ cost_price column added successfully\n";
    }
    
    // Also check for other potentially missing columns
    $requiredColumns = [
        'cost_price' => "DECIMAL(10,2) NULL AFTER sale_price",
        'generic_name' => "VARCHAR(500) COMMENT 'ชื่อสามัญ/สารสำคัญ' AFTER name_en",
        'usage_instructions' => "TEXT COMMENT 'วิธีใช้' AFTER short_description",
        'active_ingredient' => "TEXT COMMENT 'ตัวยาสำคัญ' AFTER manufacturer",
        'dosage_form' => "VARCHAR(100) COMMENT 'รูปแบบยา' AFTER active_ingredient",
        'drug_category' => "VARCHAR(50) COMMENT 'ประเภทยา: otc, dangerous, controlled' AFTER dosage_form",
        'contraindications' => "TEXT COMMENT 'ข้อห้ามใช้' AFTER drug_category",
        'dosage' => "VARCHAR(255) COMMENT 'ขนาดยา' AFTER contraindications"
    ];
    
    echo "\n=== Checking other required columns ===\n";
    
    // Refresh column list
    $stmt = $db->query("DESCRIBE business_items");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($requiredColumns as $col => $definition) {
        if (!in_array($col, $columns)) {
            echo "Adding {$col} column...\n";
            try {
                $db->exec("ALTER TABLE business_items ADD COLUMN {$col} {$definition}");
                echo "✅ {$col} added\n";
            } catch (PDOException $e) {
                echo "⚠️ Could not add {$col}: " . $e->getMessage() . "\n";
            }
        } else {
            echo "✅ {$col} exists\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Verifying final structure ===\n";
try {
    $stmt = $db->query("SELECT id, name, price, sale_price, cost_price FROM business_items LIMIT 3");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Sample data:\n";
    foreach ($items as $item) {
        echo "  - {$item['name']}: price={$item['price']}, sale_price={$item['sale_price']}, cost_price={$item['cost_price']}\n";
    }
} catch (PDOException $e) {
    echo "❌ Verification error: " . $e->getMessage() . "\n";
}

echo "\n</pre>";
echo "<p><a href='debug_drug_pricing.php'>Test Drug Pricing API</a></p>";
echo "<p><a href='../inbox-v2.php'>Back to Inbox</a></p>";
