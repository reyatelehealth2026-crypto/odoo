<?php
/**
 * Export CNY Products to CSV for business_items import
 * ดึงข้อมูลจาก CNY API และ export เป็น CSV ที่ import เข้า business_items ได้ 100%
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/CnyPharmacyAPI.php';

// Check if download requested
$action = $_GET['action'] ?? '';

if ($action === 'download') {
    downloadCsv();
    exit;
}

if ($action === 'download_chunk') {
    $offset = (int)($_GET['offset'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 500);
    downloadCsvChunk($offset, $limit);
    exit;
}

$pageTitle = 'Export CNY Products to CSV';
require_once __DIR__ . '/../includes/header.php';

// Get product count from API
$api = new CnyPharmacyAPI();
$skuResult = $api->getSkuList();
$totalProducts = $skuResult['success'] ? count($skuResult['data']) : 0;
?>

<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="mb-6">
        <a href="/admin/setup-cny.php" class="text-blue-600 hover:underline">
            <i class="fas fa-arrow-left mr-2"></i>กลับหน้า CNY Setup
        </a>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">
            <i class="fas fa-file-csv text-green-500 mr-2"></i>
            Export CNY Products to CSV
        </h1>
        
        <p class="text-gray-600 mb-6">
            ดึงข้อมูลสินค้าจาก CNY Pharmacy API และ export เป็นไฟล์ CSV 
            ที่สามารถ import เข้าตาราง <code class="bg-gray-100 px-2 py-1 rounded">business_items</code> ได้โดยตรง
        </p>

        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
            <div class="flex items-center">
                <i class="fas fa-info-circle text-blue-500 mr-3 text-xl"></i>
                <div>
                    <p class="font-medium text-blue-800">สินค้าทั้งหมดใน CNY API</p>
                    <p class="text-3xl font-bold text-blue-600"><?= number_format($totalProducts) ?> รายการ</p>
                </div>
            </div>
        </div>

        <!-- Export Options -->
        <div class="space-y-4 mb-6">
            <h3 class="font-semibold text-gray-800">เลือกวิธี Export:</h3>
            
            <!-- Option 1: Full Download -->
            <div class="border rounded-lg p-4 hover:bg-gray-50">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-download text-green-600 text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-800">Download ทั้งหมด (แนะนำ)</h4>
                        <p class="text-sm text-gray-600 mb-3">
                            ดาวน์โหลดสินค้าทั้งหมดเป็นไฟล์ CSV เดียว
                            <br><span class="text-yellow-600">⚠️ อาจใช้เวลานานถ้ามีสินค้าเยอะ</span>
                        </p>
                        <a href="?action=download" 
                           class="inline-block px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600"
                           onclick="this.innerHTML='<i class=\'fas fa-spinner fa-spin mr-2\'></i>กำลังดาวน์โหลด...'; this.classList.add('opacity-75');">
                            <i class="fas fa-file-csv mr-2"></i>Download CSV
                        </a>
                    </div>
                </div>
            </div>

            <!-- Option 2: Chunked Download -->
            <div class="border rounded-lg p-4 hover:bg-gray-50">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-layer-group text-blue-600 text-xl"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-800">Download แบบแบ่งไฟล์</h4>
                        <p class="text-sm text-gray-600 mb-3">
                            แบ่งดาวน์โหลดเป็นไฟล์ละ 500 รายการ สำหรับกรณี timeout
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <?php 
                            $chunks = ceil($totalProducts / 500);
                            for ($i = 0; $i < min($chunks, 20); $i++): 
                                $offset = $i * 500;
                                $end = min($offset + 500, $totalProducts);
                            ?>
                            <a href="?action=download_chunk&offset=<?= $offset ?>&limit=500" 
                               class="px-3 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 text-sm">
                                <?= $offset + 1 ?>-<?= $end ?>
                            </a>
                            <?php endfor; ?>
                            <?php if ($chunks > 20): ?>
                            <span class="text-gray-500 text-sm">... และอีก <?= $chunks - 20 ?> ไฟล์</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CSV Format Info -->
        <div class="bg-gray-50 rounded-lg p-4">
            <h3 class="font-semibold text-gray-800 mb-3">
                <i class="fas fa-table mr-2"></i>รูปแบบ CSV
            </h3>
            <p class="text-sm text-gray-600 mb-2">Columns ที่จะ export (ตรงกับ business_items):</p>
            <div class="flex flex-wrap gap-2 text-xs">
                <?php
                $columns = ['id', 'sku', 'barcode', 'name', 'name_en', 'description', 'price', 'stock', 
                           'image_url', 'is_active', 'category_id', 'generic_name', 'usage_instructions',
                           'manufacturer', 'unit', 'base_unit', 'product_price', 'properties_other',
                           'photo_path', 'cny_id', 'cny_category', 'hashtag', 'qty_incoming', 'enable'];
                foreach ($columns as $col):
                ?>
                <span class="px-2 py-1 bg-white border rounded"><?= $col ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Import Instructions -->
        <div class="mt-6 border-t pt-6">
            <h3 class="font-semibold text-gray-800 mb-3">
                <i class="fas fa-upload mr-2"></i>วิธี Import เข้า Database
            </h3>
            <div class="space-y-3 text-sm text-gray-600">
                <div class="flex items-start gap-2">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-xs">1</span>
                    <p>เปิด phpMyAdmin แล้วเลือก database</p>
                </div>
                <div class="flex items-start gap-2">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-xs">2</span>
                    <p>เลือกตาราง <code class="bg-gray-100 px-1 rounded">business_items</code></p>
                </div>
                <div class="flex items-start gap-2">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-xs">3</span>
                    <p>คลิก <strong>Import</strong> → เลือกไฟล์ CSV</p>
                </div>
                <div class="flex items-start gap-2">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-xs">4</span>
                    <div>
                        <p>ตั้งค่า:</p>
                        <ul class="list-disc list-inside ml-4 mt-1">
                            <li>Format: CSV</li>
                            <li>Columns enclosed with: <code>"</code></li>
                            <li>✅ The first line contains column names</li>
                        </ul>
                    </div>
                </div>
                <div class="flex items-start gap-2">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-xs">5</span>
                    <p>คลิก <strong>Go</strong> เพื่อ import</p>
                </div>
            </div>
            
            <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p class="text-sm text-yellow-800">
                    <i class="fas fa-lightbulb mr-2"></i>
                    <strong>Tip:</strong> ถ้ามี duplicate ID ให้เลือก "REPLACE" หรือ "UPDATE" ใน phpMyAdmin
                </p>
            </div>
        </div>
    </div>
</div>

<?php 
require_once __DIR__ . '/../includes/footer.php';

// ==================== FUNCTIONS ====================

function downloadCsv() {
    set_time_limit(600);
    ini_set('memory_limit', '512M');
    
    $api = new CnyPharmacyAPI();
    $result = $api->getAllProductsCached();
    
    if (!$result['success']) {
        die('Error: ' . ($result['error'] ?? 'Failed to fetch products'));
    }
    
    $products = $result['data'];
    
    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cny_products_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row
    $headers = [
        'id', 'sku', 'barcode', 'name', 'name_en', 'description', 'price', 'stock',
        'image_url', 'is_active', 'category_id', 'generic_name', 'usage_instructions',
        'manufacturer', 'unit', 'base_unit', 'product_price', 'properties_other',
        'photo_path', 'cny_id', 'cny_category', 'hashtag', 'qty_incoming', 'enable'
    ];
    fputcsv($output, $headers);
    
    // Data rows
    foreach ($products as $p) {
        $row = mapProductToRow($p);
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function downloadCsvChunk($offset, $limit) {
    set_time_limit(300);
    ini_set('memory_limit', '256M');
    
    $api = new CnyPharmacyAPI();
    $result = $api->getAllProductsCached();
    
    if (!$result['success']) {
        die('Error: ' . ($result['error'] ?? 'Failed to fetch products'));
    }
    
    $products = array_slice($result['data'], $offset, $limit);
    
    // Output CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cny_products_' . ($offset + 1) . '-' . ($offset + count($products)) . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row
    $headers = [
        'id', 'sku', 'barcode', 'name', 'name_en', 'description', 'price', 'stock',
        'image_url', 'is_active', 'category_id', 'generic_name', 'usage_instructions',
        'manufacturer', 'unit', 'base_unit', 'product_price', 'properties_other',
        'photo_path', 'cny_id', 'cny_category', 'hashtag', 'qty_incoming', 'enable'
    ];
    fputcsv($output, $headers);
    
    foreach ($products as $p) {
        $row = mapProductToRow($p);
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function mapProductToRow($p) {
    // Get price from product_price array
    $price = 0;
    $unit = '';
    $baseUnit = '';
    $prices = $p['product_price'] ?? [];
    
    if (!empty($prices)) {
        // Try GEN price first
        foreach ($prices as $pr) {
            if (strpos($pr['customer_group'] ?? '', 'GEN') !== false) {
                $price = floatval($pr['price']);
                break;
            }
        }
        // Fallback to first price
        if ($price == 0 && isset($prices[0]['price'])) {
            $price = floatval($prices[0]['price']);
        }
        // Get unit
        if (!empty($prices[0]['unit'])) {
            $unit = $prices[0]['unit'];
            if (preg_match('/^([^\[\s]+)/', $unit, $matches)) {
                $baseUnit = trim($matches[1]);
            }
        }
    }
    
    // Extract manufacturer from name_en
    $manufacturer = '';
    if (!empty($p['name_en']) && preg_match('/\[([^\]]+)\]/', $p['name_en'], $matches)) {
        $manufacturer = $matches[1];
    }
    
    // Clean description - skip full HTML pages
    $description = '';
    if (!empty($p['description']) && 
        strpos($p['description'], '<!doctype') === false && 
        strpos($p['description'], '<html') === false) {
        $description = strip_tags($p['description']);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim(substr($description, 0, 2000));
    }
    
    // Clean usage instructions
    $usageInstructions = '';
    if (!empty($p['how_to_use']) && 
        strpos($p['how_to_use'], '<!doctype') === false && 
        strpos($p['how_to_use'], '<html') === false) {
        $usageInstructions = strip_tags($p['how_to_use']);
        $usageInstructions = preg_replace('/\s+/', ' ', $usageInstructions);
        $usageInstructions = trim(substr($usageInstructions, 0, 5000));
    }
    
    // Clean properties
    $propertiesOther = '';
    if (!empty($p['properties_other']) && 
        strpos($p['properties_other'], '<!doctype') === false && 
        strpos($p['properties_other'], '<html') === false) {
        $propertiesOther = strip_tags($p['properties_other']);
        $propertiesOther = preg_replace('/\s+/', ' ', $propertiesOther);
        $propertiesOther = trim(substr($propertiesOther, 0, 5000));
    }
    
    return [
        $p['id'] ?? '',                                    // id
        $p['sku'] ?? '',                                   // sku
        $p['barcode'] ?? '',                               // barcode
        $p['name'] ?? '',                                  // name
        $p['name_en'] ?? '',                               // name_en
        $description,                                       // description
        $price,                                            // price
        intval($p['qty'] ?? 0),                            // stock
        $p['photo_path'] ?? '',                            // image_url
        ($p['enable'] ?? '1') == '1' ? 1 : 0,              // is_active
        '',                                                // category_id (empty, will be set later)
        $p['spec_name'] ?? '',                             // generic_name
        $usageInstructions,                                // usage_instructions
        $manufacturer,                                     // manufacturer
        $unit,                                             // unit
        $baseUnit,                                         // base_unit
        json_encode($prices, JSON_UNESCAPED_UNICODE),      // product_price (JSON)
        $propertiesOther,                                  // properties_other
        $p['photo_path'] ?? '',                            // photo_path
        $p['id'] ?? '',                                    // cny_id
        $p['category'] ?? '',                              // cny_category
        $p['hashtag'] ?? '',                               // hashtag
        intval($p['qty_incoming'] ?? 0),                   // qty_incoming
        ($p['enable'] ?? '1') == '1' ? 1 : 0               // enable
    ];
}
