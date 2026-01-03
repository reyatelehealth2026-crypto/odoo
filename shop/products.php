    <?php
    /**
     * Shop - จัดการสินค้า/บริการ
     * V4.0 - Pagination, Lazy Load, Sorting
     */
    require_once '../config/config.php';
    require_once '../config/database.php';
    if (file_exists('../classes/UnifiedShop.php')) {
        require_once '../classes/UnifiedShop.php';
    }

    $db = Database::getInstance()->getConnection();
    $pageTitle = 'จัดการสินค้า/บริการ';
    $currentBotId = $_SESSION['current_bot_id'] ?? 1;

    // Initialize UnifiedShop
    $shop = new UnifiedShop($db, null, $currentBotId);
    $tablesExist = $shop->isReady();
    $useBusinessItems = $shop->isV25();
    $productsTable = $shop->getItemsTable() ?? 'products';
    $categoriesTable = $shop->getCategoriesTable() ?? 'product_categories';

    // Create tables if not exist
    if (!$tablesExist) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS product_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                image_url VARCHAR(500),
                sort_order INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            $db->exec("CREATE TABLE IF NOT EXISTS products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT DEFAULT NULL,
                category_id INT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(10,2) NOT NULL,
                sale_price DECIMAL(10,2) NULL,
                image_url VARCHAR(500),
                item_type ENUM('physical','digital','service','booking','content') DEFAULT 'physical',
                delivery_method ENUM('shipping','email','line','download','onsite') DEFAULT 'shipping',
                stock INT DEFAULT 0,
                sku VARCHAR(100),
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("INSERT INTO product_categories (name, sort_order) VALUES 
                ('สินค้าทั่วไป', 1), ('สินค้าแนะนำ', 2), ('โปรโมชั่น', 3)");
            
            $tablesExist = true;
            $productsTable = 'products';
            $categoriesTable = 'product_categories';
        } catch (Exception $e) {
            $error = "ไม่สามารถสร้างตารางได้: " . $e->getMessage();
        }
    }

    // Item types
    $itemTypes = [
        'physical' => ['icon' => '📦', 'label' => 'สินค้าจัดส่ง'],
        'digital' => ['icon' => '🎮', 'label' => 'สินค้าดิจิทัล'],
        'service' => ['icon' => '💆', 'label' => 'บริการ'],
        'booking' => ['icon' => '📅', 'label' => 'จองคิว'],
        'content' => ['icon' => '📚', 'label' => 'เนื้อหา']
    ];

    // Check columns
    $hasItemType = false;
    $hasNewColumns = false;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM {$productsTable} LIKE 'item_type'");
        $hasItemType = $stmt->rowCount() > 0;
        $stmt = $db->query("SHOW COLUMNS FROM {$productsTable} LIKE 'barcode'");
        $hasNewColumns = $stmt->rowCount() > 0;
    } catch (Exception $e) {}

    // Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tablesExist) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create' || $action === 'update') {
            $cols = ['category_id', 'name', 'description', 'price', 'sale_price', 'image_url', 'stock', 'sku', 'is_active'];
            $data = [
                $_POST['category_id'] ?: null,
                $_POST['name'],
                $_POST['description'],
                (float)$_POST['price'],
                $_POST['sale_price'] ? (float)$_POST['sale_price'] : null,
                $_POST['image_url'],
                (int)$_POST['stock'],
                $_POST['sku'] ?: null,
                isset($_POST['is_active']) ? 1 : 0
            ];
            
            if ($hasNewColumns) {
                $cols = array_merge($cols, ['barcode', 'manufacturer', 'generic_name', 'usage_instructions', 'unit']);
                $data = array_merge($data, [
                    $_POST['barcode'] ?: null,
                    $_POST['manufacturer'] ?: null,
                    $_POST['generic_name'] ?: null,
                    $_POST['usage_instructions'] ?: null,
                    $_POST['unit'] ?: 'ชิ้น'
                ]);
            }
            
            if ($hasItemType) {
                $cols = array_merge($cols, ['item_type', 'delivery_method']);
                $data = array_merge($data, [$_POST['item_type'] ?? 'physical', $_POST['delivery_method'] ?? 'shipping']);
            }
            
            // Promotion settings (is_featured, is_flash_sale, is_choice, flash_sale_end)
            $cols = array_merge($cols, ['is_featured', 'is_flash_sale', 'is_choice', 'flash_sale_end']);
            $data = array_merge($data, [
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['is_flash_sale']) ? 1 : 0,
                isset($_POST['is_choice']) ? 1 : 0,
                !empty($_POST['flash_sale_end']) ? $_POST['flash_sale_end'] : null
            ]);
            
            if ($action === 'create') {
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $stmt = $db->prepare("INSERT INTO {$productsTable} (" . implode(',', $cols) . ") VALUES ({$placeholders})");
            } else {
                $sets = implode('=?, ', $cols) . '=?';
                $data[] = $_POST['id'];
                $stmt = $db->prepare("UPDATE {$productsTable} SET {$sets} WHERE id=?");
            }
            $stmt->execute($data);
            
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM {$productsTable} WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            
        } elseif ($action === 'toggle') {
            $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            
        // ==================== BULK ACTIONS ====================
        } elseif ($action === 'bulk_deactivate') {
            // ปิดสินค้าที่เลือก
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = 0 WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
            }
            
        } elseif ($action === 'bulk_activate') {
            // เปิดสินค้าที่เลือก
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("UPDATE {$productsTable} SET is_active = 1 WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
            }
            
        } elseif ($action === 'bulk_delete') {
            // ลบสินค้าที่เลือก
            $ids = $_POST['selected_ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $db->prepare("DELETE FROM {$productsTable} WHERE id IN ({$placeholders})");
                $stmt->execute($ids);
            }
            
        } elseif ($action === 'deactivate_out_of_stock') {
            // ปิดสินค้าที่หมด stock ทั้งหมด
            $stmt = $db->query("UPDATE {$productsTable} SET is_active = 0 WHERE stock <= 0 AND is_active = 1");
            $_SESSION['bulk_message'] = 'ปิดสินค้าที่หมด stock แล้ว ' . $stmt->rowCount() . ' รายการ';
            
        } elseif ($action === 'deactivate_low_stock') {
            // ปิดสินค้าที่ stock น้อยกว่า 5
            $stmt = $db->query("UPDATE {$productsTable} SET is_active = 0 WHERE stock <= 5 AND is_active = 1");
            $_SESSION['bulk_message'] = 'ปิดสินค้าที่ stock น้อย แล้ว ' . $stmt->rowCount() . ' รายการ';
        }
        
        header('Location: products.php?' . http_build_query($_GET));
        exit;
    }

    require_once '../includes/header.php';
    ?>

    <?php if (!$tablesExist): ?>
    <div class="bg-yellow-100 text-yellow-700 p-4 rounded-lg">
        <i class="fas fa-exclamation-triangle mr-2"></i>ระบบร้านค้ายังไม่พร้อมใช้งาน
    </div>
    <?php require_once '../includes/footer.php'; exit; endif; ?>

    <?php
    // Get categories
    $categories = [];
    try {
        $stmt = $db->query("SELECT * FROM {$categoriesTable} WHERE is_active = 1 ORDER BY sort_order");
        $categories = $stmt->fetchAll();
    } catch (Exception $e) {}

    // Filters
    $categoryFilter = $_GET['category'] ?? '';
    $typeFilter = $_GET['type'] ?? '';
    $searchFilter = $_GET['search'] ?? '';
    $stockFilter = $_GET['stock_filter'] ?? '';
    $sortBy = $_GET['sort'] ?? 'created_at';
    $sortDir = $_GET['dir'] ?? 'DESC';

    // Validate sort
    $allowedSorts = ['id', 'name', 'price', 'stock', 'created_at', 'sku'];
    if (!in_array($sortBy, $allowedSorts)) $sortBy = 'created_at';
    $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

    // Pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = intval($_GET['per_page'] ?? 50);
    if (!in_array($perPage, [20, 50, 100, 200])) $perPage = 50;

    // Count total (แสดงทั้งหมด ไม่ filter ตาม line_account_id)
    $countSql = "SELECT COUNT(*) FROM {$productsTable} p WHERE 1=1";
    $params = [];

    if ($categoryFilter) {
        $countSql .= " AND p.category_id = ?";
        $params[] = (int)$categoryFilter;
    }
    if ($typeFilter && $hasItemType) {
        $countSql .= " AND p.item_type = ?";
        $params[] = $typeFilter;
    }
    if ($stockFilter) {
        switch ($stockFilter) {
            case 'low':
                $countSql .= " AND p.stock > 0 AND p.stock <= 5";
                break;
            case 'out':
                $countSql .= " AND p.stock <= 0";
                break;
            case 'inactive':
                $countSql .= " AND p.is_active = 0";
                break;
        }
    }
    if ($searchFilter) {
        if ($hasNewColumns) {
            $countSql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.id = ?)";
            $params[] = "%{$searchFilter}%";
            $params[] = "%{$searchFilter}%";
            $params[] = "%{$searchFilter}%";
            $params[] = intval($searchFilter);
        } else {
            $countSql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)";
            $params[] = "%{$searchFilter}%";
            $params[] = "%{$searchFilter}%";
            $params[] = intval($searchFilter);
        }
    }

    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalProducts = $stmt->fetchColumn();
    $totalPages = ceil($totalProducts / $perPage);
    $offset = ($page - 1) * $perPage;

    // Get products (แสดงทั้งหมด ไม่ filter ตาม line_account_id)
    $sql = "SELECT p.*, c.name as category_name FROM {$productsTable} p 
            LEFT JOIN {$categoriesTable} c ON p.category_id = c.id 
            WHERE 1=1";
    $params = [];

    if ($categoryFilter) {
        $sql .= " AND p.category_id = ?";
        $params[] = (int)$categoryFilter;
    }
    if ($typeFilter && $hasItemType) {
        $sql .= " AND p.item_type = ?";
        $params[] = $typeFilter;
    }
    if ($stockFilter) {
        switch ($stockFilter) {
            case 'low':
                $sql .= " AND p.stock > 0 AND p.stock <= 5";
                break;
            case 'out':
                $sql .= " AND p.stock <= 0";
                break;
            case 'inactive':
                $sql .= " AND p.is_active = 0";
                break;
        }
    }
    if ($searchFilter) {
        if ($hasNewColumns) {
            $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.id = ?)";
            $params[] = "%{$searchFilter}%";
            $params[] = "%{$searchFilter}%";
            $params[] = "%{$searchFilter}%";
            $params[] = intval($searchFilter);
        } else {
            $sql .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.id = ?)";
            $params[] = "%{$searchFilter}%";
            $params[] = "%{$searchFilter}%";
            $params[] = intval($searchFilter);
        }
    }

    $sql .= " ORDER BY p.{$sortBy} {$sortDir} LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    // Stats (แสดงทั้งหมด ไม่ filter ตาม line_account_id)
    $statsStmt = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN stock > 0 AND stock <= 5 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN stock > 0 AND stock <= 5 AND is_active = 1 THEN 1 ELSE 0 END) as low_stock_active,
        SUM(CASE WHEN stock <= 0 AND is_active = 1 THEN 1 ELSE 0 END) as out_of_stock_active
        FROM {$productsTable}");
    $stats = $statsStmt->fetch();

    // Build query string helper
    function buildQuery($overrides = []) {
        $params = array_merge($_GET, $overrides);
        unset($params['_']); // Remove cache buster
        return http_build_query($params);
    }

    function sortLink($column, $label) {
        global $sortBy, $sortDir;
        $newDir = ($sortBy === $column && $sortDir === 'ASC') ? 'DESC' : 'ASC';
        $icon = '';
        if ($sortBy === $column) {
            $icon = $sortDir === 'ASC' ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>';
        }
        return '<a href="?'.buildQuery(['sort' => $column, 'dir' => $newDir, 'page' => 1]).'" class="hover:text-blue-600">'.$label.$icon.'</a>';
    }
    ?>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-box text-green-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">สินค้าทั้งหมด</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['total']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-check-circle text-blue-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">เปิดขาย</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['active']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">สินค้าใกล้หมด</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['low_stock']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="flex items-center">
                <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-times-circle text-red-500 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500">สินค้าหมด</p>
                    <p class="text-2xl font-bold"><?= number_format($stats['out_of_stock']) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Actions -->
    <div class="mb-4 flex flex-wrap justify-between items-center gap-4">
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" class="flex items-center gap-2 flex-wrap">
                <input type="text" name="search" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="ค้นหา SKU/ชื่อ..." class="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 w-40">
                <select name="category" class="px-4 py-2 border rounded-lg">
                    <option value="">ทุกหมวดหมู่</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="per_page" class="px-3 py-2 border rounded-lg">
                    <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20/หน้า</option>
                    <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50/หน้า</option>
                    <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100/หน้า</option>
                    <option value="200" <?= $perPage == 200 ? 'selected' : '' ?>>200/หน้า</option>
                </select>
                <input type="hidden" name="sort" value="<?= $sortBy ?>">
                <input type="hidden" name="dir" value="<?= $sortDir ?>">
                <button type="submit" class="px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200"><i class="fas fa-search"></i></button>
                <?php if ($searchFilter || $categoryFilter): ?>
                <a href="products.php" class="px-4 py-2 text-gray-500 hover:text-gray-700"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex items-center border rounded-lg overflow-hidden">
                <button type="button" onclick="setViewMode('table')" id="viewTable" class="px-3 py-2 hover:bg-gray-100 border-r bg-green-100 text-green-600"><i class="fas fa-list"></i></button>
                <button type="button" onclick="setViewMode('grid')" id="viewGrid" class="px-3 py-2 hover:bg-gray-100"><i class="fas fa-th"></i></button>
            </div>
            <a href="categories.php" class="px-4 py-2 border rounded-lg hover:bg-gray-50"><i class="fas fa-folder mr-2"></i>หมวดหมู่</a>
            <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600"><i class="fas fa-plus mr-2"></i>เพิ่มสินค้า</button>
        </div>
    </div>

    <!-- Quick Sort & Filter Buttons -->
    <div class="mb-4 bg-white rounded-xl shadow p-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-500 mr-2"><i class="fas fa-sort mr-1"></i>จัดเรียง:</span>
            
            <!-- Sort Buttons -->
            <a href="?<?= buildQuery(['sort' => 'price', 'dir' => ($sortBy == 'price' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'price' ? 'bg-green-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-baht-sign mr-1"></i>ราคา
                <?php if ($sortBy == 'price'): ?>
                    <i class="fas fa-arrow-<?= $sortDir == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                <?php endif; ?>
            </a>
            
            <a href="?<?= buildQuery(['sort' => 'stock', 'dir' => ($sortBy == 'stock' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'stock' ? 'bg-blue-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-boxes-stacked mr-1"></i>สต็อก
                <?php if ($sortBy == 'stock'): ?>
                    <i class="fas fa-arrow-<?= $sortDir == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                <?php endif; ?>
            </a>
            
            <a href="?<?= buildQuery(['sort' => 'name', 'dir' => ($sortBy == 'name' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'name' ? 'bg-purple-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-font mr-1"></i>ชื่อ
                <?php if ($sortBy == 'name'): ?>
                    <i class="fas fa-arrow-<?= $sortDir == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                <?php endif; ?>
            </a>
            
            <a href="?<?= buildQuery(['sort' => 'created_at', 'dir' => 'DESC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'created_at' ? 'bg-orange-500 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-clock mr-1"></i>ล่าสุด
            </a>
            
            <a href="?<?= buildQuery(['sort' => 'id', 'dir' => ($sortBy == 'id' && $sortDir == 'ASC') ? 'DESC' : 'ASC', 'page' => 1]) ?>" 
               class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= $sortBy == 'id' ? 'bg-gray-600 text-white' : 'bg-gray-100 hover:bg-gray-200 text-gray-700' ?>">
                <i class="fas fa-hashtag mr-1"></i>ID
                <?php if ($sortBy == 'id'): ?>
                    <i class="fas fa-arrow-<?= $sortDir == 'ASC' ? 'up' : 'down' ?> ml-1"></i>
                <?php endif; ?>
            </a>

            <div class="border-l pl-3 ml-2">
                <span class="text-sm text-gray-500 mr-2"><i class="fas fa-filter mr-1"></i>กรอง:</span>
                
                <a href="?<?= buildQuery(['stock_filter' => 'low', 'page' => 1]) ?>" 
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= ($_GET['stock_filter'] ?? '') == 'low' ? 'bg-yellow-500 text-white' : 'bg-yellow-100 hover:bg-yellow-200 text-yellow-700' ?>">
                    <i class="fas fa-exclamation-triangle mr-1"></i>สินค้าใกล้หมด
                </a>
                
                <a href="?<?= buildQuery(['stock_filter' => 'out', 'page' => 1]) ?>" 
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= ($_GET['stock_filter'] ?? '') == 'out' ? 'bg-red-500 text-white' : 'bg-red-100 hover:bg-red-200 text-red-700' ?>">
                    <i class="fas fa-times-circle mr-1"></i>สินค้าหมด
                </a>
                
                <a href="?<?= buildQuery(['stock_filter' => 'inactive', 'page' => 1]) ?>" 
                   class="px-3 py-1.5 rounded-lg text-sm font-medium transition <?= ($_GET['stock_filter'] ?? '') == 'inactive' ? 'bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-700' ?>">
                    <i class="fas fa-eye-slash mr-1"></i>ปิดขาย
                </a>
                
                <?php if (!empty($_GET['stock_filter'])): ?>
                <a href="?<?= buildQuery(['stock_filter' => '']) ?>" class="px-2 py-1.5 text-gray-500 hover:text-red-500">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bulk Actions Bar -->
    <?php 
    $bulkMessage = $_SESSION['bulk_message'] ?? null;
    unset($_SESSION['bulk_message']);
    ?>
    <?php if ($bulkMessage): ?>
    <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($bulkMessage) ?>
    </div>
    <?php endif; ?>
    
    <div class="mb-4 bg-white rounded-xl shadow p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500"><i class="fas fa-tasks mr-1"></i>Bulk Actions:</span>
                
                <!-- Quick bulk actions - ใช้ตัวเลขเฉพาะที่ยังเปิดขายอยู่ -->
                <?php if ($stats['out_of_stock_active'] > 0): ?>
                <form method="POST" class="inline" onsubmit="return confirm('ปิดสินค้าที่หมด stock ทั้งหมด <?= number_format($stats['out_of_stock_active']) ?> รายการ?')">
                    <input type="hidden" name="action" value="deactivate_out_of_stock">
                    <button type="submit" class="px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-sm font-medium hover:bg-red-200">
                        <i class="fas fa-ban mr-1"></i>ปิดสินค้าหมด (<?= number_format($stats['out_of_stock_active']) ?>)
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($stats['low_stock_active'] > 0): ?>
                <form method="POST" class="inline" onsubmit="return confirm('ปิดสินค้าที่ stock น้อยกว่า 5 ทั้งหมด <?= number_format($stats['low_stock_active']) ?> รายการ?')">
                    <input type="hidden" name="action" value="deactivate_low_stock">
                    <button type="submit" class="px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-lg text-sm font-medium hover:bg-yellow-200">
                        <i class="fas fa-exclamation-triangle mr-1"></i>ปิดสินค้าใกล้หมด (<?= number_format($stats['low_stock_active']) ?>)
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($stats['out_of_stock_active'] == 0 && $stats['low_stock_active'] == 0): ?>
                <span class="text-sm text-gray-400">ไม่มีสินค้าที่ต้องปิด</span>
                <?php endif; ?>
            </div>
            
            <!-- Selection-based actions -->
            <div id="selectionActions" class="hidden flex items-center gap-2">
                <span class="text-sm text-gray-600"><span id="selectedCount">0</span> รายการที่เลือก:</span>
                <button type="button" onclick="bulkAction('bulk_activate')" class="px-3 py-1.5 bg-green-100 text-green-700 rounded-lg text-sm font-medium hover:bg-green-200">
                    <i class="fas fa-check mr-1"></i>เปิดขาย
                </button>
                <button type="button" onclick="bulkAction('bulk_deactivate')" class="px-3 py-1.5 bg-gray-200 text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-300">
                    <i class="fas fa-eye-slash mr-1"></i>ปิดขาย
                </button>
                <button type="button" onclick="bulkAction('bulk_delete')" class="px-3 py-1.5 bg-red-500 text-white rounded-lg text-sm font-medium hover:bg-red-600">
                    <i class="fas fa-trash mr-1"></i>ลบ
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden form for bulk actions -->
    <form id="bulkForm" method="POST" class="hidden">
        <input type="hidden" name="action" id="bulkAction">
        <div id="bulkIds"></div>
    </form>

    <!-- Pagination Info -->
    <div class="mb-4 flex justify-between items-center text-sm text-gray-600">
        <div>
            แสดง <?= number_format($offset + 1) ?>-<?= number_format(min($offset + $perPage, $totalProducts)) ?> จาก <?= number_format($totalProducts) ?> รายการ
        </div>
        <div class="flex items-center gap-2">
            <?php if ($page > 1): ?>
            <a href="?<?= buildQuery(['page' => $page - 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
            <a href="?<?= buildQuery(['page' => $i]) ?>" class="px-3 py-1 border rounded <?= $i == $page ? 'bg-green-500 text-white' : 'hover:bg-gray-100' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?<?= buildQuery(['page' => $page + 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Products Table View -->
    <div id="productsTable" class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th class="px-2 py-3 text-center w-10">
                            <input type="checkbox" id="selectAll" onclick="toggleSelectAll()" class="w-4 h-4 rounded">
                        </th>
                        <th class="px-2 py-3 text-left w-14">รูป</th>
                        <th class="px-2 py-3 text-left"><?= sortLink('sku', 'SKU') ?></th>
                        <th class="px-2 py-3 text-left"><?= sortLink('name', 'ชื่อสินค้า') ?></th>
                        <th class="px-2 py-3 text-left">หมวดหมู่</th>
                        <th class="px-2 py-3 text-right"><?= sortLink('price', 'ราคา') ?></th>
                        <th class="px-2 py-3 text-center"><?= sortLink('stock', 'Stock') ?></th>
                        <th class="px-2 py-3 text-center">สถานะ</th>
                        <th class="px-2 py-3 text-center w-28">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($products as $product): ?>
                    <?php $extraData = !empty($product['extra_data']) ? json_decode($product['extra_data'], true) : []; ?>
                    <tr class="hover:bg-gray-50 product-row" data-id="<?= $product['id'] ?>">
                        <td class="px-2 py-2 text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="product-checkbox w-4 h-4 rounded" value="<?= $product['id'] ?>" onchange="updateSelection()">
                        </td>
                        <td class="px-2 py-2 cursor-pointer" onclick="window.location='product-detail.php?id=<?= $product['id'] ?>'">
                            <div class="w-12 h-12 bg-gray-100 rounded overflow-hidden">
                                <?php if ($product['image_url']): ?>
                                <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-full h-full object-cover lazy-img" loading="lazy" onerror="this.src='https://via.placeholder.com/48?text=No'">
                                <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-2 py-2">
                            <?php if (!empty($product['sku'])): ?>
                            <div class="font-mono text-xs font-medium text-gray-800 bg-blue-50 px-1.5 py-0.5 rounded inline-block"><?= htmlspecialchars($product['sku']) ?></div>
                            <?php endif; ?>
                            <?php if ($hasNewColumns && !empty($product['barcode'])): ?>
                            <div class="font-mono text-xs text-gray-400 mt-0.5"><?= htmlspecialchars($product['barcode']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2">
                            <div class="font-medium text-gray-900 line-clamp-2"><?= htmlspecialchars($product['name']) ?></div>
                            <?php if ($hasNewColumns && !empty($product['generic_name'])): ?>
                            <div class="text-xs text-blue-600 mt-0.5"><?= htmlspecialchars($product['generic_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-xs">
                            <?php if ($hasNewColumns && !empty($product['manufacturer'])): ?>
                            <div class="text-gray-700"><?= htmlspecialchars($product['manufacturer']) ?></div>
                            <?php endif; ?>
                            <div class="text-gray-400"><?= htmlspecialchars($product['category_name'] ?? '-') ?></div>
                        </td>
                        <td class="px-2 py-2 text-right">
                            <?php if ($product['sale_price']): ?>
                            <div class="text-red-500 font-bold">฿<?= number_format($product['sale_price'], 2) ?></div>
                            <div class="text-xs text-gray-400 line-through">฿<?= number_format($product['price'], 2) ?></div>
                            <?php else: ?>
                            <div class="text-green-600 font-bold">฿<?= number_format($product['price'], 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-center">
                            <?php if ($product['stock'] <= 0): ?>
                            <span class="px-2 py-1 bg-red-100 text-red-600 text-xs rounded-full">หมด</span>
                            <?php elseif ($product['stock'] <= 5): ?>
                            <span class="px-2 py-1 bg-yellow-100 text-yellow-700 text-xs rounded-full"><?= $product['stock'] ?></span>
                            <?php else: ?>
                            <span class="text-gray-800 font-medium"><?= number_format($product['stock']) ?></span>
                            <?php endif; ?>
                            <?php if ($hasNewColumns && !empty($product['unit'])): ?>
                            <div class="text-xs text-gray-400"><?= htmlspecialchars($product['unit']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-center">
                            <?php if ($product['is_active']): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-600 text-xs rounded-full">เปิด</span>
                            <?php else: ?>
                            <span class="px-2 py-1 bg-gray-100 text-gray-500 text-xs rounded-full">ปิด</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-center gap-1">
                                <a href="product-detail.php?id=<?= $product['id'] ?>" class="p-1.5 text-green-500 hover:bg-green-50 rounded"><i class="fas fa-eye"></i></a>
                                <button onclick='editProduct(<?= json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="p-1.5 text-blue-500 hover:bg-blue-50 rounded"><i class="fas fa-edit"></i></button>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                    <button type="submit" class="p-1.5 text-gray-500 hover:bg-gray-100 rounded"><i class="fas <?= $product['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                                </form>
                                <form method="POST" onsubmit="return confirm('ลบสินค้านี้?')" class="inline">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                    <button type="submit" class="p-1.5 text-red-500 hover:bg-red-50 rounded"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($products)): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500"><i class="fas fa-box-open text-4xl mb-2"></i><p>ไม่พบสินค้า</p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- Products Grid View (Hidden) -->
    <div id="productsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 hidden">
        <?php foreach ($products as $product): ?>
        <?php $extraData = !empty($product['extra_data']) ? json_decode($product['extra_data'], true) : []; ?>
        <div class="bg-white rounded-xl shadow overflow-hidden hover:shadow-lg transition-shadow group">
            <a href="product-detail.php?id=<?= $product['id'] ?>" class="block">
                <div class="aspect-square bg-gray-100 relative overflow-hidden">
                    <?php if ($product['image_url']): ?>
                    <img src="<?= htmlspecialchars($product['image_url']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform lazy-img" loading="lazy" onerror="this.src='https://via.placeholder.com/300?text=No'">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300"><i class="fas fa-image text-4xl"></i></div>
                    <?php endif; ?>
                    
                    <?php if ($product['sale_price']): ?>
                    <span class="absolute top-2 left-2 px-2 py-1 bg-red-500 text-white text-xs rounded font-bold">-<?= round((($product['price'] - $product['sale_price']) / $product['price']) * 100) ?>%</span>
                    <?php endif; ?>
                    
                    <?php if (!$product['is_active']): ?>
                    <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center"><span class="text-white font-bold">ปิดการขาย</span></div>
                    <?php endif; ?>
                    
                    <div class="absolute bottom-2 left-2">
                        <?php if ($product['stock'] <= 0): ?>
                        <span class="px-2 py-1 bg-red-500 text-white text-xs rounded">หมด</span>
                        <?php elseif ($product['stock'] <= 5): ?>
                        <span class="px-2 py-1 bg-yellow-500 text-white text-xs rounded">เหลือ <?= $product['stock'] ?></span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-green-500/80 text-white text-xs rounded"><?= number_format($product['stock']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            
            <div class="p-3">
                <?php if (!empty($product['sku'])): ?>
                <span class="font-mono text-xs bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded"><?= htmlspecialchars($product['sku']) ?></span>
                <?php endif; ?>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($product['category_name'] ?? '-') ?></p>
                <a href="product-detail.php?id=<?= $product['id'] ?>">
                    <h3 class="font-semibold text-gray-800 line-clamp-2 hover:text-blue-600 mt-1"><?= htmlspecialchars($product['name']) ?></h3>
                </a>
                <?php if ($hasNewColumns && !empty($product['generic_name'])): ?>
                <p class="text-xs text-blue-600"><?= htmlspecialchars($product['generic_name']) ?></p>
                <?php endif; ?>
                
                <div class="flex items-baseline mt-2 gap-2">
                    <?php if ($product['sale_price']): ?>
                    <span class="text-lg font-bold text-red-500">฿<?= number_format($product['sale_price'], 2) ?></span>
                    <span class="text-sm text-gray-400 line-through">฿<?= number_format($product['price'], 2) ?></span>
                    <?php else: ?>
                    <span class="text-lg font-bold text-green-600">฿<?= number_format($product['price'], 2) ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="flex space-x-2 mt-3 pt-3 border-t">
                    <button onclick='editProduct(<?= json_encode($product, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="flex-1 py-2 border rounded-lg hover:bg-blue-50 text-sm text-blue-600"><i class="fas fa-edit"></i></button>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $product['id'] ?>">
                        <button type="submit" class="px-3 py-2 border rounded-lg hover:bg-gray-100"><i class="fas <?= $product['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                    </form>
                    <form method="POST" onsubmit="return confirm('ลบ?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $product['id'] ?>">
                        <button type="submit" class="px-3 py-2 border border-red-300 text-red-500 rounded-lg hover:bg-red-50"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($products)): ?>
        <div class="col-span-full bg-white rounded-xl shadow p-8 text-center text-gray-500">
            <i class="fas fa-box-open text-6xl mb-4"></i>
            <p class="text-lg">ไม่พบสินค้า</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Bottom Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="mt-4 flex justify-center items-center gap-2 text-sm">
        <?php if ($page > 1): ?>
        <a href="?<?= buildQuery(['page' => 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-angle-double-left"></i></a>
        <a href="?<?= buildQuery(['page' => $page - 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-chevron-left"></i></a>
        <?php endif; ?>
        
        <?php
        $startPage = max(1, $page - 3);
        $endPage = min($totalPages, $page + 3);
        if ($startPage > 1) echo '<span class="px-2">...</span>';
        for ($i = $startPage; $i <= $endPage; $i++):
        ?>
        <a href="?<?= buildQuery(['page' => $i]) ?>" class="px-3 py-1 border rounded <?= $i == $page ? 'bg-green-500 text-white' : 'hover:bg-gray-100' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($endPage < $totalPages) echo '<span class="px-2">...</span>'; ?>
        
        <?php if ($page < $totalPages): ?>
        <a href="?<?= buildQuery(['page' => $page + 1]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-chevron-right"></i></a>
        <a href="?<?= buildQuery(['page' => $totalPages]) ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><i class="fas fa-angle-double-right"></i></a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Modal -->
    <div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl w-full max-w-3xl mx-4 max-h-[95vh] overflow-hidden flex flex-col">
            <form method="POST" id="productForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="formId">
                
                <div class="px-6 py-4 bg-gradient-to-r from-blue-500 to-blue-600 text-white flex justify-between items-center">
                    <h3 class="text-lg font-semibold" id="modalTitle">เพิ่มสินค้า</h3>
                    <button type="button" onclick="closeModal()" class="text-white hover:text-blue-200 text-2xl"><i class="fas fa-times"></i></button>
                </div>
                
                <div class="flex-1 overflow-y-auto p-6 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">รหัสสินค้า (SKU)</label>
                            <input type="text" name="sku" id="sku" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <?php if ($hasNewColumns): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">บาร์โค้ด</label>
                            <input type="text" name="barcode" id="barcode" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อสินค้า *</label>
                        <input type="text" name="name" id="name" required class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    
                    <?php if ($hasNewColumns): ?>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ผู้ผลิต</label>
                            <input type="text" name="manufacturer" id="manufacturer" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อสามัญยา</label>
                            <input type="text" name="generic_name" id="generic_name" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">วิธีใช้</label>
                        <textarea name="usage_instructions" id="usage_instructions" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">หมวดหมู่</label>
                        <select name="category_id" id="category_id" class="w-full px-3 py-2 border rounded-lg">
                            <option value="">-- เลือก --</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียด</label>
                        <textarea name="description" id="description" rows="2" class="w-full px-3 py-2 border rounded-lg"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">URL รูปภาพ</label>
                        <input type="url" name="image_url" id="image_url" class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ราคา *</label>
                            <input type="number" name="price" id="price" required min="0" step="0.01" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ราคาลด</label>
                            <input type="number" name="sale_price" id="sale_price" min="0" step="0.01" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">จำนวน</label>
                            <input type="number" name="stock" id="stock" value="0" min="0" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <?php if ($hasNewColumns): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">หน่วย</label>
                            <input type="text" name="unit" id="unit" value="ชิ้น" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($hasItemType): ?>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ประเภท</label>
                            <select name="item_type" id="item_type" class="w-full px-3 py-2 border rounded-lg">
                                <?php foreach ($itemTypes as $key => $type): ?>
                                <option value="<?= $key ?>"><?= $type['icon'] ?> <?= $type['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">วิธีส่งมอบ</label>
                            <select name="delivery_method" id="delivery_method" class="w-full px-3 py-2 border rounded-lg">
                                <option value="shipping">📦 จัดส่ง</option>
                                <option value="line">💬 LINE</option>
                                <option value="email">📧 Email</option>
                                <option value="download">📥 ดาวน์โหลด</option>
                                <option value="onsite">📍 รับที่ร้าน</option>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_active" id="is_active" checked class="w-4 h-4 text-green-500">
                        <label for="is_active" class="text-sm">เปิดขาย</label>
                    </div>
                    
                    <!-- Product Promotion Settings -->
                    <div class="mt-4 p-4 bg-gradient-to-r from-orange-50 to-yellow-50 rounded-lg border border-orange-200">
                        <h4 class="text-sm font-semibold text-orange-700 mb-3"><i class="fas fa-star mr-2"></i>ตั้งค่าโปรโมชั่น</h4>
                        <div class="grid grid-cols-3 gap-4">
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="is_featured" id="is_featured" class="w-4 h-4 text-orange-500">
                                <label for="is_featured" class="text-sm"><i class="fas fa-thumbs-up text-orange-500 mr-1"></i>สินค้าแนะนำ</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="is_flash_sale" id="is_flash_sale" class="w-4 h-4 text-red-500" onchange="toggleFlashSaleEnd()">
                                <label for="is_flash_sale" class="text-sm"><i class="fas fa-bolt text-red-500 mr-1"></i>Flash Sale</label>
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="checkbox" name="is_choice" id="is_choice" class="w-4 h-4 text-blue-500">
                                <label for="is_choice" class="text-sm"><i class="fas fa-award text-blue-500 mr-1"></i>Choice</label>
                            </div>
                        </div>
                        <div id="flash_sale_end_wrapper" class="mt-3 hidden">
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-clock mr-1"></i>สิ้นสุด Flash Sale</label>
                            <input type="datetime-local" name="flash_sale_end" id="flash_sale_end" class="w-full px-3 py-2 border rounded-lg">
                        </div>
                    </div>
                </div>
                
                <div class="px-6 py-4 border-t flex justify-end space-x-2">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                    <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600"><i class="fas fa-save mr-2"></i>บันทึก</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // View Mode
    let currentViewMode = localStorage.getItem('productsViewMode') || 'table';

    function setViewMode(mode) {
        currentViewMode = mode;
        localStorage.setItem('productsViewMode', mode);
        
        const tableView = document.getElementById('productsTable');
        const gridView = document.getElementById('productsGrid');
        const viewTableBtn = document.getElementById('viewTable');
        const viewGridBtn = document.getElementById('viewGrid');
        
        if (mode === 'table') {
            tableView.classList.remove('hidden');
            gridView.classList.add('hidden');
            viewTableBtn.classList.add('bg-green-100', 'text-green-600');
            viewGridBtn.classList.remove('bg-green-100', 'text-green-600');
        } else {
            tableView.classList.add('hidden');
            gridView.classList.remove('hidden');
            viewGridBtn.classList.add('bg-green-100', 'text-green-600');
            viewTableBtn.classList.remove('bg-green-100', 'text-green-600');
        }
    }

    // Modal
    function openModal() {
        document.getElementById('modal').classList.remove('hidden');
        document.getElementById('modal').classList.add('flex');
        document.getElementById('formAction').value = 'create';
        document.getElementById('modalTitle').textContent = 'เพิ่มสินค้า';
        document.getElementById('productForm').reset();
        document.getElementById('is_active').checked = true;
    }

    function closeModal() {
        document.getElementById('modal').classList.add('hidden');
        document.getElementById('modal').classList.remove('flex');
    }

    function editProduct(product) {
        document.getElementById('modal').classList.remove('hidden');
        document.getElementById('modal').classList.add('flex');
        document.getElementById('formAction').value = 'update';
        document.getElementById('formId').value = product.id;
        document.getElementById('modalTitle').textContent = 'แก้ไขสินค้า';
        
        document.getElementById('sku').value = product.sku || '';
        document.getElementById('name').value = product.name || '';
        document.getElementById('description').value = product.description || '';
        document.getElementById('price').value = product.price || '';
        document.getElementById('sale_price').value = product.sale_price || '';
        document.getElementById('stock').value = product.stock || 0;
        document.getElementById('image_url').value = product.image_url || '';
        document.getElementById('category_id').value = product.category_id || '';
        document.getElementById('is_active').checked = product.is_active == 1;
        
        if (document.getElementById('barcode')) document.getElementById('barcode').value = product.barcode || '';
        if (document.getElementById('manufacturer')) document.getElementById('manufacturer').value = product.manufacturer || '';
        if (document.getElementById('generic_name')) document.getElementById('generic_name').value = product.generic_name || '';
        if (document.getElementById('usage_instructions')) document.getElementById('usage_instructions').value = product.usage_instructions || '';
        if (document.getElementById('unit')) document.getElementById('unit').value = product.unit || 'ชิ้น';
        if (document.getElementById('item_type')) document.getElementById('item_type').value = product.item_type || 'physical';
        if (document.getElementById('delivery_method')) document.getElementById('delivery_method').value = product.delivery_method || 'shipping';
        
        // Promotion settings
        if (document.getElementById('is_featured')) document.getElementById('is_featured').checked = product.is_featured == 1;
        if (document.getElementById('is_flash_sale')) {
            document.getElementById('is_flash_sale').checked = product.is_flash_sale == 1;
            toggleFlashSaleEnd();
        }
        if (document.getElementById('is_choice')) document.getElementById('is_choice').checked = product.is_choice == 1;
        if (document.getElementById('flash_sale_end') && product.flash_sale_end) {
            // Convert to datetime-local format
            const dt = new Date(product.flash_sale_end);
            const formatted = dt.toISOString().slice(0, 16);
            document.getElementById('flash_sale_end').value = formatted;
        }
    }
    
    // Toggle flash sale end datetime input
    function toggleFlashSaleEnd() {
        const checkbox = document.getElementById('is_flash_sale');
        const wrapper = document.getElementById('flash_sale_end_wrapper');
        if (checkbox && wrapper) {
            if (checkbox.checked) {
                wrapper.classList.remove('hidden');
            } else {
                wrapper.classList.add('hidden');
                document.getElementById('flash_sale_end').value = '';
            }
        }
    }

    // Lazy Load Images
    document.addEventListener('DOMContentLoaded', function() {
        setViewMode(currentViewMode);
        
        // Intersection Observer for lazy loading
        if ('IntersectionObserver' in window) {
            const imgObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                        observer.unobserve(img);
                    }
                });
            }, { rootMargin: '50px' });
            
            document.querySelectorAll('img.lazy-img').forEach(img => {
                imgObserver.observe(img);
            });
        }
    });

    // Close modal on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });
    
    // ==================== BULK ACTIONS ====================
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.product-checkbox');
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
        updateSelection();
    }
    
    function updateSelection() {
        const checkboxes = document.querySelectorAll('.product-checkbox:checked');
        const count = checkboxes.length;
        const selectionActions = document.getElementById('selectionActions');
        const selectedCount = document.getElementById('selectedCount');
        
        if (count > 0) {
            selectionActions.classList.remove('hidden');
            selectionActions.classList.add('flex');
            selectedCount.textContent = count;
        } else {
            selectionActions.classList.add('hidden');
            selectionActions.classList.remove('flex');
        }
        
        // Update select all checkbox
        const allCheckboxes = document.querySelectorAll('.product-checkbox');
        const selectAll = document.getElementById('selectAll');
        selectAll.checked = count > 0 && count === allCheckboxes.length;
        selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
    }
    
    function bulkAction(action) {
        const checkboxes = document.querySelectorAll('.product-checkbox:checked');
        if (checkboxes.length === 0) {
            alert('กรุณาเลือกสินค้าก่อน');
            return;
        }
        
        let confirmMsg = '';
        if (action === 'bulk_activate') confirmMsg = 'เปิดขายสินค้าที่เลือก ' + checkboxes.length + ' รายการ?';
        else if (action === 'bulk_deactivate') confirmMsg = 'ปิดขายสินค้าที่เลือก ' + checkboxes.length + ' รายการ?';
        else if (action === 'bulk_delete') confirmMsg = 'ลบสินค้าที่เลือก ' + checkboxes.length + ' รายการ? (ไม่สามารถกู้คืนได้)';
        
        if (!confirm(confirmMsg)) return;
        
        // Build form
        const form = document.getElementById('bulkForm');
        document.getElementById('bulkAction').value = action;
        
        // Clear old inputs
        const bulkIds = document.getElementById('bulkIds');
        bulkIds.innerHTML = '';
        
        // Add selected IDs
        checkboxes.forEach(cb => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = cb.value;
            bulkIds.appendChild(input);
        });
        
        form.submit();
    }
    </script>

    <?php require_once '../includes/footer.php'; ?>
