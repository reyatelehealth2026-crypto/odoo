<?php
/**
 * Shop Categories V4.0 - Compact & Simple
 * ไม่มีรูปภาพ, ใช้งานง่าย, กระทัดรัด
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
if (file_exists(__DIR__ . '/../classes/UnifiedShop.php')) {
    require_once __DIR__ . '/../classes/UnifiedShop.php';
}

$db = Database::getInstance()->getConnection();
$pageTitle = 'หมวดหมู่สินค้า';
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Initialize UnifiedShop
$shop = new UnifiedShop($db, null, $currentBotId);
$categoriesTable = $shop->getCategoriesTable() ?? 'product_categories';
$productsTable = $shop->getItemsTable() ?? 'products';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $stmt = $db->prepare("INSERT INTO {$categoriesTable} (name, sort_order, is_active) VALUES (?, ?, 1)");
        $stmt->execute([trim($_POST['name']), intval($_POST['sort_order'] ?? 0)]);
    } elseif ($action === 'update') {
        $stmt = $db->prepare("UPDATE {$categoriesTable} SET name = ?, sort_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([trim($_POST['name']), intval($_POST['sort_order']), isset($_POST['is_active']) ? 1 : 0, $_POST['id']]);
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM {$categoriesTable} WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        // Clear category from products
        $stmt = $db->prepare("UPDATE {$productsTable} SET category_id = NULL WHERE category_id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($action === 'toggle') {
        $stmt = $db->prepare("UPDATE {$categoriesTable} SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($action === 'reorder') {
        $orders = json_decode($_POST['orders'], true);
        foreach ($orders as $id => $order) {
            $stmt = $db->prepare("UPDATE {$categoriesTable} SET sort_order = ? WHERE id = ?");
            $stmt->execute([$order, $id]);
        }
    }
    
    header('Location: categories.php');
    exit;
}

// Get categories with product count (shared across all LINE accounts)
$categories = [];
try {
    $stmt = $db->query("
        SELECT c.*, COALESCE(COUNT(p.id), 0) as product_count 
        FROM {$categoriesTable} c 
        LEFT JOIN {$productsTable} p ON c.id = p.category_id AND p.is_active = 1
        GROUP BY c.id 
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$totalProducts = array_sum(array_column($categories, 'product_count'));
$activeCount = count(array_filter($categories, fn($c) => $c['is_active']));

require_once '../includes/header.php';
?>

<style>
.cat-row { transition: all 0.2s; }
.cat-row:hover { background: #f8fafc; }
.cat-row.inactive { opacity: 0.5; }
.drag-handle { cursor: grab; }
.drag-handle:active { cursor: grabbing; }
.quick-edit { display: none; }
.cat-row:hover .quick-edit { display: flex; }
</style>

<!-- Header Actions -->
<div class="flex flex-wrap justify-between items-center gap-4 mb-4">
    <div class="flex items-center gap-4">
        <div class="flex items-center gap-2 text-sm text-gray-600">
            <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full font-medium"><?= count($categories) ?> หมวดหมู่</span>
            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-medium"><?= $activeCount ?> เปิดใช้</span>
            <span class="px-3 py-1 bg-purple-100 text-purple-700 rounded-full font-medium"><?= number_format($totalProducts) ?> สินค้า</span>
        </div>
    </div>
    <div class="flex items-center gap-2">
        <a href="../sync_categories_from_manufacturer.php" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 text-sm">
            <i class="fas fa-magic mr-2"></i>สร้างจากผู้ผลิต
        </a>
        <button onclick="openAddModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
            <i class="fas fa-plus mr-2"></i>เพิ่มหมวดหมู่
        </button>
    </div>
</div>

<!-- Categories Table -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50 text-gray-600 text-sm">
            <tr>
                <th class="px-4 py-3 text-left w-10">#</th>
                <th class="px-4 py-3 text-left">ชื่อหมวดหมู่</th>
                <th class="px-4 py-3 text-center w-24">สินค้า</th>
                <th class="px-4 py-3 text-center w-20">ลำดับ</th>
                <th class="px-4 py-3 text-center w-20">สถานะ</th>
                <th class="px-4 py-3 text-center w-32">จัดการ</th>
            </tr>
        </thead>
        <tbody id="categoriesList" class="divide-y">
            <?php if (empty($categories)): ?>
            <tr>
                <td colspan="6" class="px-4 py-12 text-center text-gray-400">
                    <i class="fas fa-folder-open text-4xl mb-3"></i>
                    <p>ยังไม่มีหมวดหมู่</p>
                    <button onclick="openAddModal()" class="mt-3 px-4 py-2 bg-green-500 text-white rounded-lg text-sm">
                        <i class="fas fa-plus mr-1"></i>เพิ่มหมวดหมู่แรก
                    </button>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($categories as $i => $cat): ?>
            <tr class="cat-row <?= !$cat['is_active'] ? 'inactive' : '' ?>" data-id="<?= $cat['id'] ?>">
                <td class="px-4 py-3 text-gray-400 drag-handle">
                    <i class="fas fa-grip-vertical"></i>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center text-white text-sm font-bold">
                            <?= mb_substr($cat['name'], 0, 1) ?>
                        </div>
                        <div>
                            <div class="font-medium text-gray-800"><?= htmlspecialchars($cat['name']) ?></div>
                            <?php if (!empty($cat['manufacturer_code'])): ?>
                            <div class="text-xs text-gray-400">รหัส: <?= htmlspecialchars($cat['manufacturer_code']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td class="px-4 py-3 text-center">
                    <a href="products.php?category=<?= $cat['id'] ?>" class="px-3 py-1 bg-blue-50 text-blue-600 rounded-full text-sm hover:bg-blue-100">
                        <?= number_format($cat['product_count']) ?>
                    </a>
                </td>
                <td class="px-4 py-3 text-center text-gray-500 text-sm">
                    <?= $cat['sort_order'] ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                        <button type="submit" class="px-3 py-1 rounded-full text-xs font-medium <?= $cat['is_active'] ? 'bg-green-100 text-green-600 hover:bg-green-200' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' ?>">
                            <?= $cat['is_active'] ? 'เปิด' : 'ปิด' ?>
                        </button>
                    </form>
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center justify-center gap-1">
                        <button onclick='openEditModal(<?= json_encode($cat, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                                class="p-2 text-blue-500 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="deleteCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars(addslashes($cat['name'])) ?>')" 
                                class="p-2 text-red-500 hover:bg-red-50 rounded-lg" title="ลบ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add/Edit Modal -->
<div id="catModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4">
        <form method="POST">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="catId" value="">
            
            <div class="p-4 border-b">
                <h3 id="modalTitle" class="text-lg font-semibold">เพิ่มหมวดหมู่</h3>
            </div>
            
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อหมวดหมู่ *</label>
                    <input type="text" name="name" id="catName" required 
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-1">ลำดับ</label>
                    <input type="number" name="sort_order" id="catSort" value="0" min="0"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none">
                </div>
                
                <div id="activeField" class="hidden">
                    <label class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg cursor-pointer">
                        <input type="checkbox" name="is_active" id="catActive" checked class="w-4 h-4 text-green-500 rounded">
                        <span class="text-sm">เปิดใช้งาน</span>
                    </label>
                </div>
            </div>
            
            <div class="p-4 border-t flex gap-2">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-xs mx-4 p-6 text-center">
        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
            <i class="fas fa-trash text-red-500"></i>
        </div>
        <h3 class="font-semibold mb-2">ลบหมวดหมู่?</h3>
        <p class="text-gray-500 text-sm mb-4" id="deleteText"></p>
        
        <form method="POST" class="flex gap-2">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteId">
            <button type="button" onclick="closeDeleteModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
            <button type="submit" class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">ลบ</button>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'เพิ่มหมวดหมู่';
    document.getElementById('formAction').value = 'create';
    document.getElementById('catId').value = '';
    document.getElementById('catName').value = '';
    document.getElementById('catSort').value = '0';
    document.getElementById('activeField').classList.add('hidden');
    showModal('catModal');
}

function openEditModal(cat) {
    document.getElementById('modalTitle').textContent = 'แก้ไขหมวดหมู่';
    document.getElementById('formAction').value = 'update';
    document.getElementById('catId').value = cat.id;
    document.getElementById('catName').value = cat.name;
    document.getElementById('catSort').value = cat.sort_order || 0;
    document.getElementById('catActive').checked = cat.is_active == 1;
    document.getElementById('activeField').classList.remove('hidden');
    showModal('catModal');
}

function closeModal() { hideModal('catModal'); }

function deleteCategory(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteText').textContent = 'ลบ "' + name + '" ?';
    showModal('deleteModal');
}

function closeDeleteModal() { hideModal('deleteModal'); }

function showModal(id) {
    document.getElementById(id).classList.remove('hidden');
    document.getElementById(id).classList.add('flex');
}

function hideModal(id) {
    document.getElementById(id).classList.add('hidden');
    document.getElementById(id).classList.remove('flex');
}

// Close on backdrop click
['catModal', 'deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target.id === id) hideModal(id);
    });
});

// ESC to close
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeModal(); closeDeleteModal(); }
});
</script>

<?php require_once '../includes/footer.php'; ?>
