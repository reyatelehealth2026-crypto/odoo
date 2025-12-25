<?php
/**
 * User Categories - จัดการหมวดหมู่สินค้า (AJAX Version)
 */
$pageTitle = 'หมวดหมู่สินค้า';
require_once '../includes/user_header.php';

// Get categories - แสดงทั้งหมด ไม่ filter ตาม line_account_id
$stmt = $db->query("SELECT c.*, (SELECT COUNT(*) FROM business_items WHERE category_id = c.id) as product_count FROM product_categories c ORDER BY c.sort_order ASC, c.id DESC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Toast Notification -->
<div id="toast" class="fixed top-4 right-4 z-50 hidden">
    <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center">
        <i class="fas fa-check-circle mr-2"></i>
        <span id="toast-message">สำเร็จ</span>
    </div>
</div>

<div class="mb-4 flex justify-between items-center">
    <div>
        <span class="text-gray-600">หมวดหมู่ทั้งหมด <span id="category-count"><?= count($categories) ?></span> รายการ</span>
    </div>
    <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
        <i class="fas fa-plus mr-2"></i>เพิ่มหมวดหมู่
    </button>
</div>

<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ลำดับ</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ชื่อหมวดหมู่</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จำนวนสินค้า</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">จัดการ</th>
            </tr>
        </thead>
        <tbody id="categories-tbody" class="divide-y divide-gray-100">
            <?php if (empty($categories)): ?>
            <tr id="empty-row">
                <td colspan="5" class="px-4 py-8 text-center text-gray-400">ยังไม่มีหมวดหมู่</td>
            </tr>
            <?php else: ?>
            <?php foreach ($categories as $cat): ?>
            <tr class="hover:bg-gray-50 category-row" data-id="<?= $cat['id'] ?>">
                <td class="px-4 py-3 text-center text-gray-500 cat-sort"><?= $cat['sort_order'] ?></td>
                <td class="px-4 py-3">
                    <div class="font-medium cat-name"><?= htmlspecialchars($cat['name']) ?></div>
                    <div class="text-xs text-gray-500 cat-desc"><?= htmlspecialchars(mb_substr($cat['description'] ?? '', 0, 50)) ?></div>
                </td>
                <td class="px-4 py-3 text-center cat-count"><?= $cat['product_count'] ?></td>
                <td class="px-4 py-3 text-center">
                    <span class="cat-status px-2 py-1 text-xs rounded <?= $cat['is_active'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-600' ?>">
                        <?= $cat['is_active'] ? 'เปิด' : 'ปิด' ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center">
                    <button onclick='editCategory(<?= json_encode($cat) ?>)' class="text-blue-500 hover:text-blue-700 mr-2">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteCategory(<?= $cat['id'] ?>)" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-md mx-4">
        <div class="p-6 border-b">
            <h3 class="text-lg font-semibold" id="modalTitle">เพิ่มหมวดหมู่</h3>
        </div>
        <div class="p-6 space-y-4">
            <input type="hidden" id="formAction" value="create">
            <input type="hidden" id="formId">
            <div>
                <label class="block text-sm font-medium mb-1">ชื่อหมวดหมู่ <span class="text-red-500">*</span></label>
                <input type="text" id="name" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">คำอธิบาย</label>
                <textarea id="description" rows="2" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">ลำดับการแสดง</label>
                <input type="number" id="sort_order" value="0" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="flex items-center">
                    <input type="checkbox" id="is_active" checked class="mr-2">
                    <span class="text-sm">เปิดใช้งาน</span>
                </label>
            </div>
        </div>
        <div class="p-6 border-t flex justify-end space-x-2">
            <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
            <button type="button" onclick="saveCategory()" id="btn-save" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 disabled:opacity-50">บันทึก</button>
        </div>
    </div>
</div>

<script>
function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toast-message');
    toastMsg.textContent = message;
    
    const toastDiv = toast.querySelector('div');
    toastDiv.className = isError 
        ? 'bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center'
        : 'bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg flex items-center';
    
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 3000);
}

function openModal() {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modal').classList.add('flex');
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'เพิ่มหมวดหมู่';
    resetForm();
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.getElementById('modal').classList.remove('flex');
}

function resetForm() {
    document.getElementById('formId').value = '';
    document.getElementById('name').value = '';
    document.getElementById('description').value = '';
    document.getElementById('sort_order').value = '0';
    document.getElementById('is_active').checked = true;
}

function editCategory(cat) {
    openModal();
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = cat.id;
    document.getElementById('modalTitle').textContent = 'แก้ไขหมวดหมู่';
    document.getElementById('name').value = cat.name;
    document.getElementById('description').value = cat.description || '';
    document.getElementById('sort_order').value = cat.sort_order;
    document.getElementById('is_active').checked = cat.is_active == 1;
}

function saveCategory() {
    const btn = document.getElementById('btn-save');
    const action = document.getElementById('formAction').value;
    const name = document.getElementById('name').value.trim();
    
    if (!name) {
        showToast('กรุณากรอกชื่อหมวดหมู่', true);
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>กำลังบันทึก...';
    
    const formData = new FormData();
    formData.append('action', action === 'create' ? 'create_category' : 'update_category');
    formData.append('name', name);
    formData.append('description', document.getElementById('description').value);
    formData.append('sort_order', document.getElementById('sort_order').value);
    if (document.getElementById('is_active').checked) {
        formData.append('is_active', '1');
    }
    if (action === 'update') {
        formData.append('id', document.getElementById('formId').value);
    }
    
    fetch('../api/ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);
            closeModal();
            // Reload to show updated data
            setTimeout(() => location.reload(), 500);
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', true);
        }
    })
    .catch(err => {
        showToast('เกิดข้อผิดพลาด: ' + err.message, true);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = 'บันทึก';
    });
}

function deleteCategory(id) {
    if (!confirm('ยืนยันลบหมวดหมู่นี้?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_category');
    formData.append('id', id);
    
    fetch('../api/ajax_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast(data.message);
            // Remove row from DOM
            const row = document.querySelector(`.category-row[data-id="${id}"]`);
            if (row) {
                row.remove();
                // Update count
                const countEl = document.getElementById('category-count');
                countEl.textContent = parseInt(countEl.textContent) - 1;
            }
        } else {
            showToast(data.error || 'เกิดข้อผิดพลาด', true);
        }
    })
    .catch(err => {
        showToast('เกิดข้อผิดพลาด: ' + err.message, true);
    });
}
</script>

<?php require_once '../includes/user_footer.php'; ?>
