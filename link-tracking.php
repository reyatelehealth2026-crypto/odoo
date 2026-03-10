<?php
/**
 * Link Tracking V3.0 - ติดตามการคลิกลิงก์
 * Modal + AJAX
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'classes/LinkTrackingService.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Link Tracking';
$currentBotId = $_SESSION['current_bot_id'] ?? null;

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? '')
];

$linkService = new LinkTrackingService($db, $currentBotId);
$links = $linkService->getLinks($filters);
$stats = $linkService->getStats();
$usageRatio = $linkService->getUsageRatio();
$usagePercent = min(100, max(0, round($usageRatio * 100, 1)));

$baseUrl = rtrim(BASE_URL ?? '', '/');

require_once 'includes/header.php';
?>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-link text-blue-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Total Links</p>
                <p class="text-2xl font-bold"><?= count($links) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-mouse-pointer text-green-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Total Clicks</p>
                <p class="text-2xl font-bold"><?= number_format($totalClicks) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-user-check text-purple-500 text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Unique Clicks</p>
                <p class="text-2xl font-bold"><?= number_format($uniqueClicks) ?></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow p-4 cursor-pointer hover:shadow-lg transition" onclick="openModal()">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-500 rounded-lg flex items-center justify-center">
                <i class="fas fa-plus text-white text-xl"></i>
            </div>
            <div class="ml-4">
                <p class="text-sm text-gray-500">Quick Action</p>
                <p class="text-lg font-bold text-green-600">สร้าง Link</p>
            </div>
        </div>
    </div>
</div>

<!-- Links Table -->
<div class="bg-white rounded-xl shadow">
    <div class="p-4 border-b flex justify-between items-center">
        <h3 class="font-semibold">🔗 Tracked Links</h3>
        <button onclick="openModal()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 text-sm">
            <i class="fas fa-plus mr-2"></i>สร้าง Link
        </button>
    </div>
    
    <div id="linksContainer">
        <?php if (empty($links)): ?>
        <div class="p-12 text-center text-gray-400">
            <i class="fas fa-link text-5xl mb-4"></i>
            <p class="text-lg">ยังไม่มี Tracked Links</p>
            <button onclick="openModal()" class="mt-4 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-plus mr-2"></i>สร้าง Link แรก
            </button>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Link</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tracking URL</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Clicks</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Unique</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($links as $link): ?>
                    <tr id="link-row-<?= $link['id'] ?>" class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4">
                            <div class="font-medium"><?= htmlspecialchars($link['title'] ?: 'Untitled') ?></div>
                            <a href="<?= htmlspecialchars($link['original_url']) ?>" target="_blank" class="text-xs text-blue-500 hover:underline truncate block max-w-xs">
                                <?= htmlspecialchars($link['original_url']) ?>
                            </a>
                            <?php if ($link['last_clicked_at']): ?>
                            <span class="text-xs text-gray-400">Last click: <?= date('d/m/Y H:i', strtotime($link['last_clicked_at'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <input type="text" id="url-<?= $link['id'] ?>" value="<?= $baseUrl ?>/t.php?c=<?= $link['short_code'] ?>" readonly class="px-3 py-1.5 border rounded-lg text-sm w-52 bg-gray-50 focus:outline-none">
                                <button onclick="copyLink(<?= $link['id'] ?>)" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-lg" title="Copy">
                                    <i class="fas fa-copy"></i>
                                </button>
                                <button onclick="openQRModal('<?= $baseUrl ?>/t.php?c=<?= $link['short_code'] ?>', '<?= htmlspecialchars(addslashes($link['title'] ?: 'Link')) ?>')" class="p-2 text-gray-400 hover:text-purple-500 hover:bg-purple-50 rounded-lg" title="QR Code">
                                    <i class="fas fa-qrcode"></i>
                                </button>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-xl font-bold text-green-600"><?= number_format($link['click_count']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <span class="text-xl font-bold text-purple-600"><?= number_format($link['unique_clicks']) ?></span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($link)) ?>)" class="p-2 text-gray-400 hover:text-blue-500 hover:bg-blue-50 rounded-lg" title="แก้ไข">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteLink(<?= $link['id'] ?>, '<?= htmlspecialchars(addslashes($link['title'] ?: 'Link')) ?>')" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg" title="ลบ">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create/Edit Modal -->
<div id="linkModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg mx-4">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 id="modalTitle" class="text-lg font-semibold">🔗 สร้าง Tracked Link</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="linkForm" onsubmit="return saveLink(event)">
            <input type="hidden" id="linkId" value="">
            
            <div class="p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-2">URL ปลายทาง *</label>
                    <input type="url" id="linkUrl" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="https://example.com/product">
                </div>
                
                <div>
                    <label class="block text-sm font-medium mb-2">ชื่อ Link (สำหรับจำ)</label>
                    <input type="text" id="linkTitle" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:outline-none" placeholder="เช่น โปรโมชั่นธันวาคม">
                </div>
                
                <div id="generatedUrlDiv" class="hidden p-3 bg-green-50 rounded-lg">
                    <label class="block text-sm font-medium mb-2 text-green-700">Tracking URL</label>
                    <div class="flex items-center gap-2">
                        <input type="text" id="generatedUrl" readonly class="flex-1 px-3 py-2 border rounded-lg bg-white text-sm">
                        <button type="button" onclick="copyGeneratedUrl()" class="px-3 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                
                <div id="errorMessage" class="hidden p-3 bg-red-100 text-red-700 rounded-lg text-sm"></div>
            </div>
            
            <div class="p-4 border-t flex gap-3">
                <button type="button" onclick="closeModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" id="saveBtn" class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                    <span id="saveBtnText">สร้าง Link</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- QR Code Modal -->
<div id="qrModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 text-center">
        <div class="p-4 border-b flex justify-between items-center">
            <h3 class="text-lg font-semibold">📱 QR Code</h3>
            <button onclick="closeQRModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6">
            <p id="qrTitle" class="text-gray-600 mb-4"></p>
            <div id="qrCode" class="flex justify-center mb-4"></div>
            <p id="qrUrl" class="text-xs text-gray-400 break-all"></p>
        </div>
        <div class="p-4 border-t">
            <button onclick="closeQRModal()" class="w-full px-4 py-2 border rounded-lg hover:bg-gray-50">ปิด</button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4">
        <div class="p-6 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-trash text-red-500 text-2xl"></i>
            </div>
            <h3 class="text-lg font-semibold mb-2">ลบ Link?</h3>
            <p class="text-gray-500 mb-4">คุณต้องการลบ "<span id="deleteLinkName" class="font-medium"></span>" หรือไม่?</p>
            
            <input type="hidden" id="deleteLinkId">
            
            <div class="flex gap-3">
                <button onclick="closeDeleteModal()" class="flex-1 px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button onclick="confirmDelete()" id="deleteBtn" class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">ลบ</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="fixed bottom-4 right-4 z-50 hidden">
    <div class="bg-gray-800 text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3">
        <i id="toastIcon" class="fas fa-check-circle text-green-400"></i>
        <span id="toastMessage"></span>
    </div>
</div>

<!-- QR Code Library -->
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>

<script>
const API_URL = 'api/ajax_handler.php';
const BASE_URL = '<?= $baseUrl ?>';

// Modal functions
function openModal() {
    document.getElementById('modalTitle').textContent = '🔗 สร้าง Tracked Link';
    document.getElementById('linkId').value = '';
    document.getElementById('linkForm').reset();
    document.getElementById('generatedUrlDiv').classList.add('hidden');
    document.getElementById('saveBtnText').textContent = 'สร้าง Link';
    hideError();
    showModal('linkModal');
}

function openEditModal(link) {
    document.getElementById('modalTitle').textContent = '✏️ แก้ไข Link';
    document.getElementById('linkId').value = link.id;
    document.getElementById('linkUrl').value = link.original_url;
    document.getElementById('linkTitle').value = link.title || '';
    document.getElementById('generatedUrl').value = `${BASE_URL}/t.php?c=${link.short_code}`;
    document.getElementById('generatedUrlDiv').classList.remove('hidden');
    document.getElementById('saveBtnText').textContent = 'บันทึก';
    hideError();
    showModal('linkModal');
}

function closeModal() { hideModal('linkModal'); }

function openQRModal(url, title) {
    document.getElementById('qrTitle').textContent = title;
    document.getElementById('qrUrl').textContent = url;
    
    // Generate QR Code
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    document.getElementById('qrCode').innerHTML = qr.createImgTag(5, 10);
    
    showModal('qrModal');
}

function closeQRModal() { hideModal('qrModal'); }

function deleteLink(id, name) {
    document.getElementById('deleteLinkId').value = id;
    document.getElementById('deleteLinkName').textContent = name;
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

// API functions
async function saveLink(e) {
    e.preventDefault();
    
    const id = document.getElementById('linkId').value;
    const url = document.getElementById('linkUrl').value.trim();
    const title = document.getElementById('linkTitle').value.trim();
    
    if (!url) {
        showError('กรุณาระบุ URL');
        return false;
    }
    
    setLoading(true);
    
    try {
        const formData = new FormData();
        formData.append('action', id ? 'update_link' : 'create_link');
        formData.append('url', url);
        formData.append('title', title);
        if (id) formData.append('link_id', id);
        
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            if (!id && result.short_code) {
                // Show generated URL
                document.getElementById('generatedUrl').value = `${BASE_URL}/t.php?c=${result.short_code}`;
                document.getElementById('generatedUrlDiv').classList.remove('hidden');
                showToast('สร้าง Link สำเร็จ!');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(id ? 'อัพเดท Link สำเร็จ!' : 'สร้าง Link สำเร็จ!');
                closeModal();
                setTimeout(() => location.reload(), 500);
            }
        } else {
            showError(result.error || 'เกิดข้อผิดพลาด');
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาด: ' + error.message);
    } finally {
        setLoading(false);
    }
    
    return false;
}

async function confirmDelete() {
    const id = document.getElementById('deleteLinkId').value;
    const btn = document.getElementById('deleteBtn');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังลบ...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_link');
        formData.append('link_id', id);
        
        const response = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            showToast('ลบ Link สำเร็จ!');
            closeDeleteModal();
            
            const row = document.getElementById(`link-row-${id}`);
            if (row) {
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(() => row.remove(), 300);
            }
        } else {
            showToast(result.error || 'เกิดข้อผิดพลาด', 'error');
        }
    } catch (error) {
        showToast('เกิดข้อผิดพลาด', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = 'ลบ';
    }
}

// Helper functions
function setLoading(loading) {
    const btn = document.getElementById('saveBtn');
    const text = document.getElementById('saveBtnText');
    btn.disabled = loading;
    if (loading) {
        text.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>กำลังบันทึก...';
    } else {
        text.textContent = document.getElementById('linkId').value ? 'บันทึก' : 'สร้าง Link';
    }
}

function copyLink(id) {
    const input = document.getElementById(`url-${id}`);
    input.select();
    document.execCommand('copy');
    showToast('คัดลอก URL แล้ว!');
}

function copyGeneratedUrl() {
    const input = document.getElementById('generatedUrl');
    input.select();
    document.execCommand('copy');
    showToast('คัดลอก URL แล้ว!');
}

function showError(message) {
    const el = document.getElementById('errorMessage');
    el.textContent = message;
    el.classList.remove('hidden');
}

function hideError() {
    document.getElementById('errorMessage').classList.add('hidden');
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const icon = document.getElementById('toastIcon');
    const msg = document.getElementById('toastMessage');
    
    msg.textContent = message;
    icon.className = type === 'success' ? 'fas fa-check-circle text-green-400' : 'fas fa-exclamation-circle text-red-400';
    
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 3000);
}

// Keyboard shortcuts
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeQRModal();
        closeDeleteModal();
    }
});

// Close on backdrop click
['linkModal', 'qrModal', 'deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target.id === id) hideModal(id);
    });
});
</script>

<style>
#link-row-<?= $link['id'] ?? '' ?> { transition: all 0.3s ease; }
tr { transition: all 0.3s ease; }
</style>

<?php require_once 'includes/footer.php'; ?>
