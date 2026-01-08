<?php
/**
 * Run Product CNY Fields Migration
 * เพิ่ม columns ที่จำเป็นสำหรับ CNY API compatibility ให้ business_items
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=== Product CNY Fields Migration ===\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if business_items table exists
    $stmt = $db->query("SHOW TABLES LIKE 'business_items'");
    if ($stmt->rowCount() === 0) {
        echo "❌ Table business_items does not exist!\n";
        echo "Please run the main installation first.\n";
        exit(1);
    }
    
    // Get existing columns
    $stmt = $db->query("SHOW COLUMNS FROM business_items");
    $existingColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['Field'];
    }
    
    echo "Existing columns: " . count($existingColumns) . "\n\n";
    
    // Define columns to add
    $columnsToAdd = [
        'name_en' => "VARCHAR(500) NULL COMMENT 'ชื่อภาษาอังกฤษ'",
        'generic_name' => "VARCHAR(500) NULL COMMENT 'ชื่อสามัญ/สารสำคัญ (spec_name)'",
        'usage_instructions' => "TEXT NULL COMMENT 'วิธีใช้ (how_to_use)'",
        'manufacturer' => "VARCHAR(255) NULL COMMENT 'ผู้ผลิต'",
        'barcode' => "VARCHAR(100) NULL",
        'unit' => "VARCHAR(100) NULL COMMENT 'หน่วยจำนวน เช่น ขวด[ 60ML ]'",
        'base_unit' => "VARCHAR(50) NULL COMMENT 'หน่วยนับ เช่น ขวด, กล่อง, แผง'",
        'product_price' => "JSON NULL COMMENT 'ราคาตามกลุ่มลูกค้า JSON array'",
        'properties_other' => "TEXT NULL COMMENT 'สรรพคุณอื่นๆ'",
        'photo_path' => "VARCHAR(500) NULL COMMENT 'URL รูปภาพจาก CNY'",
        'cny_id' => "INT NULL COMMENT 'ID จาก CNY API'",
        'cny_category' => "VARCHAR(100) NULL COMMENT 'หมวดหมู่จาก CNY'",
        'hashtag' => "VARCHAR(500) NULL COMMENT 'Hashtag สำหรับค้นหา'",
        'qty_incoming' => "INT DEFAULT 0 COMMENT 'จำนวนที่กำลังเข้า'",
        'enable' => "TINYINT(1) DEFAULT 1 COMMENT 'เปิด/ปิดขาย'",
        'last_synced_at' => "TIMESTAMP NULL COMMENT 'เวลา sync ล่าสุด'"
    ];
    
    $added = 0;
    $skipped = 0;
    
    foreach ($columnsToAdd as $column => $definition) {
        if (in_array($column, $existingColumns)) {
            echo "⏭️  Column '{$column}' already exists\n";
            $skipped++;
            continue;
        }
        
        try {
            $sql = "ALTER TABLE business_items ADD COLUMN {$column} {$definition}";
            $db->exec($sql);
            echo "✅ Added column '{$column}'\n";
            $added++;
        } catch (PDOException $e) {
            echo "⚠️  Error adding '{$column}': " . $e->getMessage() . "\n";
        }
    }
    
    // Add indexes
    echo "\nAdding indexes...\n";
    
    $indexes = [
        'idx_business_items_barcode' => 'barcode',
        'idx_business_items_cny_id' => 'cny_id',
        'idx_business_items_enable' => 'enable'
    ];
    
    foreach ($indexes as $indexName => $column) {
        try {
            // Check if index exists
            $stmt = $db->query("SHOW INDEX FROM business_items WHERE Key_name = '{$indexName}'");
            if ($stmt->rowCount() > 0) {
                echo "⏭️  Index '{$indexName}' already exists\n";
                continue;
            }
            
            $db->exec("CREATE INDEX {$indexName} ON business_items({$column})");
            echo "✅ Created index '{$indexName}'\n";
        } catch (PDOException $e) {
            echo "⚠️  Error creating index '{$indexName}': " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== Migration Complete ===\n";
    echo "Added: {$added} columns\n";
    echo "Skipped: {$skipped} columns (already exist)\n";
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}
