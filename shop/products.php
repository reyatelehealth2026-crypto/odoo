<?php
/**
 * Shop Products - CNY Style UI with business_items table
 * Display products from business_items with CNY-style grid layout
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'สินค้า - ร้านยา';

// Check if table exists
$tableExists = false;
$productsTable = 'business_items';
try {
    $db->query("SELECT 1 FROM {$productsTable} LIMIT 1");
    $tableExists = true;
} catch (PDOException $e) {
    // Try legacy products table
    try {
        $db->query("SELECT 1 FROM products LIMIT 1");
        $tableExists = true;
        $productsTable = 'products';
    } catch (PDOException $e2) {
        // No table
    }
}

if (!$tableExists) {
    require_once __DIR__ . '/../includes/header.php';
    ?>
    <div class="max-w-4xl mx-auto px-4 py-6">
        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-6 rounded-lg">
            <h2 class="text-xl font-bold text-yellow-800 mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                ยังไม่ได้ติดตั้งระบบสินค้า
            </h2>
            <p class="text-yellow-700 mb-4">
                กรุณารันคำสั่งต่อไปนี้เพื่อสร้างตาราง:
            </p>
            <div class="bg-gray-900 text-green-400 p-4 rounded font-mono text-sm mb-4">
                php install/install_fresh.php
            </div>
            <a href="../" class="inline-block px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-arrow-left mr-2"></i>กลับหน้าหลัก
            </a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// Check available columns
$hasPhotoPath = false;
$hasProductPrice = false;
$hasEnable = false;
$hasGenericName = false;
$hasUsageInstructions = false;
$hasPropertiesOther = false;
$hasNameEn = false;

try {
    $stmt = $db->query("SHOW COLUMNS FROM {$productsTable}");
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($col['Field'] === 'photo_path') $hasPhotoPath = true;
        if ($col['Field'] === 'product_price') $hasProductPrice = true;
        if ($col['Field'] === 'enable') $hasEnable = true;
        if ($col['Field'] === 'generic_name') $hasGenericName = true;
        if ($col['Field'] === 'usage_instructions') $hasUsageInstructions = true;
        if ($col['Field'] === 'properties_other') $hasPropertiesOther = true;
        if ($col['Field'] === 'name_en') $hasNameEn = true;
    }
} catch (Exception $e) {}

// Get filters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

// Build query
$where = ["1=1"];
$params = [];

// Active filter - use enable if available, otherwise is_active
if ($hasEnable) {
    $where[] = "enable = 1";
} else {
    $where[] = "is_active = 1";
}

if ($search) {
    $searchFields = ["name LIKE :search1", "sku LIKE :search2"];
    if ($hasNameEn) $searchFields[] = "name_en LIKE :search3";
    $where[] = "(" . implode(" OR ", $searchFields) . ")";
    $searchTerm = "%{$search}%";
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    if ($hasNameEn) $params[':search3'] = $searchTerm;
}

if ($category) {
    $where[] = "category_id = :category";
    $params[':category'] = (int)$category;
}

if ($stockFilter === 'in') {
    $where[] = "stock > 0";
} elseif ($stockFilter === 'out') {
    $where[] = "stock <= 0";
} elseif ($stockFilter === 'low') {
    $where[] = "stock > 0 AND stock <= 5";
}

$whereClause = implode(' AND ', $where);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM {$productsTable} WHERE {$whereClause}");
$countStmt->execute($params);
$totalProducts = $countStmt->fetchColumn();
$totalPages = ceil($totalProducts / $perPage);

// Get products for current page
$imageCol = $hasPhotoPath ? "COALESCE(photo_path, image_url) as display_image" : "image_url as display_image";
$stmt = $db->prepare("
    SELECT *, {$imageCol}
    FROM {$productsTable} 
    WHERE {$whereClause}
    ORDER BY name
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories
$categories = [];
try {
    $catTable = 'product_categories';
    $db->query("SELECT 1 FROM {$catTable} LIMIT 1");
    $catStmt = $db->query("SELECT id, name FROM {$catTable} WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get last sync time if available
$lastSync = null;
try {
    $syncStmt = $db->query("SELECT MAX(last_synced_at) as last_sync FROM {$productsTable} WHERE last_synced_at IS NOT NULL");
    $lastSync = $syncStmt->fetchColumn();
} catch (Exception $e) {}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Header -->
    <div class="mb-6 flex flex-wrap justify-between items-start gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">
                <i class="fas fa-pills text-blue-500 mr-2"></i>
                สินค้าร้านยา
            </h1>
            <p class="text-gray-600">
                ทั้งหมด <?= number_format($totalProducts) ?> รายการ
                <?php if ($lastSync): ?>
                <span class="text-xs text-gray-500 ml-2">
                    (Sync ล่าสุด: <?= date('d/m/Y H:i', strtotime($lastSync)) ?>)
                </span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="/inventory?tab=products" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                <i class="fas fa-table mr-2"></i>มุมมองตาราง
            </a>
            <a href="/admin/setup-cny.php" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-sync mr-2"></i>Sync จาก CNY
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ค้นหาชื่อยา, SKU..." 
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <?php if (!empty($categories)): ?>
            <select name="category" class="px-4 py-2 border rounded-lg">
                <option value="">ทุกหมวดหมู่</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="stock" class="px-4 py-2 border rounded-lg">
                <option value="">สต็อกทั้งหมด</option>
                <option value="in" <?= $stockFilter === 'in' ? 'selected' : '' ?>>มีสินค้า</option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>ใกล้หมด (≤5)</option>
                <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>หมดสต็อก</option>
            </select>
            <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                <i class="fas fa-search mr-2"></i>ค้นหา
            </button>
            <?php if ($search || $category || $stockFilter): ?>
            <a href="?" class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                <i class="fas fa-times mr-2"></i>ล้าง
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Products Grid -->
    <?php if (empty($products)): ?>
    <div class="bg-white rounded-xl shadow p-12 text-center">
        <i class="fas fa-box-open text-gray-300 text-6xl mb-4"></i>
        <p class="text-gray-500 text-lg">ไม่พบสินค้า</p>
        <?php if ($totalProducts == 0): ?>
        <p class="text-gray-400 text-sm mt-2">
            <a href="/admin/setup-cny.php" class="text-blue-600 hover:underline">Sync สินค้าจาก CNY API</a>
            หรือ <a href="/inventory?tab=products" class="text-blue-600 hover:underline">เพิ่มสินค้าใหม่</a>
        </p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-6">
        <?php foreach ($products as $product): 
            // Get price - from product_price JSON or price column
            $price = 0;
            $unit = '';
            if ($hasProductPrice && !empty($product['product_price'])) {
                $priceData = is_string($product['product_price']) 
                    ? json_decode($product['product_price'], true) 
                    : $product['product_price'];
                if (is_array($priceData) && !empty($priceData[0])) {
                    $price = (float)($priceData[0]['price'] ?? 0);
                    $unit = $priceData[0]['unit'] ?? '';
                }
            }
            if ($price == 0) {
                $price = (float)($product['price'] ?? 0);
            }
            
            $stock = (int)($product['stock'] ?? 0);
            $inStock = $stock > 0;
            $imageUrl = $product['display_image'] ?? '';
        ?>
        <div class="bg-white rounded-xl shadow hover:shadow-lg transition overflow-hidden group">
            <!-- Product Image -->
            <div class="relative aspect-square bg-gray-100">
                <?php if (!empty($imageUrl)): ?>
                <img src="<?= htmlspecialchars($imageUrl) ?>" 
                     alt="<?= htmlspecialchars($product['name']) ?>"
                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                     loading="lazy"
                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 200 200%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2220%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-pills text-gray-300 text-6xl"></i>
                </div>
                <?php endif; ?>
                
                <!-- Stock Badge -->
                <div class="absolute top-2 right-2">
                    <?php if ($inStock): ?>
                        <?php if ($stock <= 5): ?>
                        <span class="px-2 py-1 bg-yellow-500 text-white text-xs rounded-full">
                            <i class="fas fa-exclamation mr-1"></i>เหลือ <?= $stock ?>
                        </span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-green-500 text-white text-xs rounded-full">
                            <i class="fas fa-check mr-1"></i>มีสินค้า
                        </span>
                        <?php endif; ?>
                    <?php else: ?>
                    <span class="px-2 py-1 bg-red-500 text-white text-xs rounded-full">
                        <i class="fas fa-times mr-1"></i>หมด
                    </span>
                    <?php endif; ?>
                </div>
                
                <!-- Edit Button (Admin) -->
                <a href="/inventory?tab=products&search=<?= urlencode($product['sku'] ?? $product['name']) ?>" 
                   class="absolute top-2 left-2 px-2 py-1 bg-white/80 text-gray-700 text-xs rounded-full opacity-0 group-hover:opacity-100 transition-opacity">
                    <i class="fas fa-edit mr-1"></i>แก้ไข
                </a>
            </div>

            <!-- Product Info -->
            <div class="p-4">
                <?php if (!empty($product['sku'])): ?>
                <div class="text-xs text-gray-500 mb-1">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                <?php endif; ?>
                
                <h3 class="font-semibold text-gray-800 mb-2 line-clamp-2 min-h-[3rem]">
                    <?= htmlspecialchars($product['name']) ?>
                </h3>
                
                <?php if ($hasGenericName && !empty($product['generic_name'])): ?>
                <p class="text-xs text-blue-600 mb-2 line-clamp-1">
                    <?= htmlspecialchars($product['generic_name']) ?>
                </p>
                <?php endif; ?>

                <div class="flex items-center justify-between mb-3">
                    <div>
                        <div class="text-2xl font-bold text-blue-600">฿<?= number_format($price, 2) ?></div>
                        <?php if ($unit): ?>
                        <div class="text-xs text-gray-500"><?= htmlspecialchars($unit) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-600">คงเหลือ</div>
                        <div class="font-bold <?= $inStock ? ($stock <= 5 ? 'text-yellow-600' : 'text-green-600') : 'text-red-600' ?>">
                            <?= number_format($stock) ?>
                        </div>
                    </div>
                </div>

                <a href="product-detail.php?id=<?= $product['id'] ?>" 
                   class="block w-full text-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <i class="fas fa-eye mr-2"></i>ดูรายละเอียด
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex justify-center gap-2">
        <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
           class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50">
            <i class="fas fa-chevron-left"></i>
        </a>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        if ($startPage > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" 
           class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50">1</a>
        <?php if ($startPage > 2): ?>
        <span class="px-2 py-2 text-gray-400">...</span>
        <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
           class="px-4 py-2 rounded-lg <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white border hover:bg-gray-50' ?>">
            <?= $i ?>
        </a>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
        <?php if ($endPage < $totalPages - 1): ?>
        <span class="px-2 py-2 text-gray-400">...</span>
        <?php endif; ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" 
           class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
           class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50">
            <i class="fas fa-chevron-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
