<?php
/**
 * Debug Category Mismatch
 * ตรวจสอบว่าสินค้ามี category_id ที่ไม่ตรงกับ product_categories
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🔍 Debug Category Mismatch</h2>";

// 1. นับสินค้าทั้งหมด
$total = $db->query("SELECT COUNT(*) FROM business_items")->fetchColumn();
echo "<p>📦 สินค้าทั้งหมด: <strong>$total</strong></p>";

// 2. นับหมวดหมู่ทั้งหมด
$catCount = $db->query("SELECT COUNT(*) FROM product_categories")->fetchColumn();
echo "<p>📁 หมวดหมู่ทั้งหมด: <strong>$catCount</strong></p>";

// 3. นับสินค้าที่มี category_id = NULL
$nullCat = $db->query("SELECT COUNT(*) FROM business_items WHERE category_id IS NULL")->fetchColumn();
echo "<p>⚠️ สินค้าที่ไม่มีหมวดหมู่ (NULL): <strong>$nullCat</strong></p>";

// 4. นับสินค้าที่ category_id ไม่ตรงกับ product_categories
$orphan = $db->query("
    SELECT COUNT(*) FROM business_items p 
    WHERE p.category_id IS NOT NULL 
    AND p.category_id NOT IN (SELECT id FROM product_categories)
")->fetchColumn();
echo "<p>❌ สินค้าที่ category_id ไม่มีในตาราง categories: <strong style='color:red'>$orphan</strong></p>";

// 5. แสดง category_id ที่ไม่มีในตาราง
if ($orphan > 0) {
    $stmt = $db->query("
        SELECT DISTINCT p.category_id, COUNT(*) as cnt
        FROM business_items p 
        WHERE p.category_id IS NOT NULL 
        AND p.category_id NOT IN (SELECT id FROM product_categories)
        GROUP BY p.category_id
        ORDER BY cnt DESC
        LIMIT 20
    ");
    $missing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>📋 Category IDs ที่หายไป:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Category ID</th><th>จำนวนสินค้า</th></tr>";
    foreach ($missing as $m) {
        echo "<tr><td>{$m['category_id']}</td><td>{$m['cnt']}</td></tr>";
    }
    echo "</table>";
}

// 6. แสดง categories ที่มีอยู่
echo "<h3>📁 หมวดหมู่ที่มีอยู่:</h3>";
$cats = $db->query("SELECT id, name, cny_code FROM product_categories ORDER BY id LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>CNY Code</th></tr>";
foreach ($cats as $c) {
    echo "<tr><td>{$c['id']}</td><td>" . htmlspecialchars($c['name']) . "</td><td>{$c['cny_code']}</td></tr>";
}
echo "</table>";

// 7. นับสินค้าที่ is_active = 0
$inactive = $db->query("SELECT COUNT(*) FROM business_items WHERE is_active = 0")->fetchColumn();
echo "<p>🚫 สินค้าที่ปิดใช้งาน (is_active=0): <strong>$inactive</strong></p>";

// 8. สรุป
echo "<hr>";
echo "<h3>📊 สรุป:</h3>";
$visible = $total - $orphan - $inactive;
echo "<p>สินค้าที่ควรแสดง: <strong style='color:green'>$visible</strong></p>";

// 9. วิธีแก้
if ($orphan > 0) {
    echo "<h3>🔧 วิธีแก้:</h3>";
    echo "<p>1. Set category_id เป็น NULL สำหรับสินค้าที่ category ไม่มี:</p>";
    echo "<pre>UPDATE business_items SET category_id = NULL WHERE category_id NOT IN (SELECT id FROM product_categories);</pre>";
    
    echo "<p>2. หรือสร้าง category ใหม่ให้ตรงกับ ID เดิม</p>";
}
