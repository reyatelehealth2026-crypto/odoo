<?php
/**
 * Groups Manager - จัดการกลุ่มผู้ใช้และแท็ก
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'Groups Manager';

require_once 'includes/header.php';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $stmt = $db->prepare("INSERT INTO groups (name, description, color) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['description'], $_POST['color']]);
    } elseif ($action === 'update') {
        $stmt = $db->prepare("UPDATE groups SET name=?, description=?, color=? WHERE id=?");
        $stmt->execute([$_POST['name'], $_POST['description'], $_POST['color'], $_POST['id']]);
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM groups WHERE id = ?");
        $stmt->execute([$_POST['id']]);
    } elseif ($action === 'add_member') {
        $stmt = $db->prepare("INSERT IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)");
        $stmt->execute([$_POST['user_id'], $_POST['group_id']]);
    } elseif ($action === 'remove_member') {
        $stmt = $db->prepare("DELETE FROM user_groups WHERE user_id = ? AND group_id = ?");
        $stmt->execute([$_POST['user_id'], $_POST['group_id']]);
    }
    header('Location: groups.php' . (isset($_POST['group_id']) ? '?view=' . (int) $_POST['group_id'] : ''));
    exit;
}

// Get all groups with member count
$stmt = $db->query("SELECT g.*, COUNT(ug.user_id) as member_count FROM groups g LEFT JOIN user_groups ug ON g.id = ug.group_id GROUP BY g.id ORDER BY g.name");
$groups = $stmt->fetchAll();

// Get all users for adding to groups (filtered by current bot)
$stmt = $db->prepare("SELECT id, display_name, picture_url FROM users WHERE is_blocked = 0 AND (line_account_id = ? OR line_account_id IS NULL) ORDER BY display_name");
$stmt->execute([$currentBotId]);
$allUsers = $stmt->fetchAll();

// View group members
$viewGroup = null;
$members = [];
if (isset($_GET['view'])) {
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = ?");
    $stmt->execute([$_GET['view']]);
    $viewGroup = $stmt->fetch();
    
    if ($viewGroup) {
        $stmt = $db->prepare("SELECT u.* FROM users u JOIN user_groups ug ON u.id = ug.user_id WHERE ug.group_id = ? AND (u.line_account_id = ? OR u.line_account_id IS NULL)");
        $stmt->execute([$viewGroup['id'], $currentBotId]);
        $members = $stmt->fetchAll();
    }
}
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Groups List -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="font-semibold">กลุ่มทั้งหมด</h3>
                <button onclick="openModal()" class="px-3 py-1 bg-green-500 text-white text-sm rounded-lg hover:bg-green-600">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
            <div class="divide-y max-h-96 overflow-y-auto">
                <?php foreach ($groups as $group): ?>
                <a href="?view=<?= $group['id'] ?>" class="flex items-center p-4 hover:bg-gray-50 <?= ($viewGroup && $viewGroup['id'] == $group['id']) ? 'bg-green-50' : '' ?>">
                    <div class="w-4 h-4 rounded-full mr-3" style="background-color: <?= $group['color'] ?>"></div>
                    <div class="flex-1">
                        <p class="font-medium"><?= htmlspecialchars($group['name']) ?></p>
                        <p class="text-xs text-gray-500"><?= $group['member_count'] ?> สมาชิก</p>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                <p class="p-4 text-gray-500 text-center">ยังไม่มีกลุ่ม</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Group Details -->
    <div class="lg:col-span-2">
        <?php if ($viewGroup): ?>
        <div class="bg-white rounded-xl shadow">
            <div class="p-4 border-b flex justify-between items-center">
                <div class="flex items-center">
                    <div class="w-6 h-6 rounded-full mr-3" style="background-color: <?= $viewGroup['color'] ?>"></div>
                    <div>
                        <h3 class="font-semibold"><?= htmlspecialchars($viewGroup['name']) ?></h3>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($viewGroup['description']) ?></p>
                    </div>
                </div>
                <div class="space-x-2">
                    <button onclick='editGroup(<?= json_encode($viewGroup) ?>)' class="px-3 py-1 border rounded-lg hover:bg-gray-50"><i class="fas fa-edit"></i></button>
                    <form method="POST" class="inline" onsubmit="return confirmDelete()">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $viewGroup['id'] ?>">
                        <button type="submit" class="px-3 py-1 border border-red-300 text-red-500 rounded-lg hover:bg-red-50"><i class="fas fa-trash"></i></button>
                    </form>
                </div>
            </div>
            
            <!-- Add Member -->
            <div class="p-4 border-b">
                <form method="POST" class="flex space-x-2">
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="group_id" value="<?= $viewGroup['id'] ?>">
                    <select name="user_id" required class="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">-- เลือกผู้ใช้ --</option>
                        <?php foreach ($allUsers as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['display_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">เพิ่มสมาชิก</button>
                </form>
            </div>
            
            <!-- Members List -->
            <div class="divide-y max-h-80 overflow-y-auto">
                <?php foreach ($members as $member): ?>
                <div class="flex items-center p-4 hover:bg-gray-50">
                    <img src="<?= $member['picture_url'] ?: 'https://via.placeholder.com/40' ?>" class="w-10 h-10 rounded-full mr-3">
                    <div class="flex-1">
                        <p class="font-medium"><?= htmlspecialchars($member['display_name']) ?></p>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="remove_member">
                        <input type="hidden" name="user_id" value="<?= $member['id'] ?>">
                        <input type="hidden" name="group_id" value="<?= $viewGroup['id'] ?>">
                        <button type="submit" class="text-red-500 hover:text-red-700"><i class="fas fa-times"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                <p class="p-4 text-gray-500 text-center">ยังไม่มีสมาชิกในกลุ่ม</p>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-xl shadow p-8 text-center text-gray-500">
            <i class="fas fa-users text-6xl mb-4"></i>
            <p>เลือกกลุ่มเพื่อดูรายละเอียด</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div id="modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl w-full max-w-md mx-4">
        <form method="POST">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="formId">
            <div class="p-6 border-b">
                <h3 class="text-lg font-semibold" id="modalTitle">สร้างกลุ่มใหม่</h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1">ชื่อกลุ่ม</label>
                    <input type="text" name="name" id="name" required class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">คำอธิบาย</label>
                    <textarea name="description" id="description" rows="3" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">สี</label>
                    <input type="color" name="color" id="color" value="#3B82F6" class="w-full h-10 rounded-lg cursor-pointer">
                </div>
            </div>
            <div class="p-6 border-t flex justify-end space-x-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal() {
    document.getElementById('modal').classList.remove('hidden');
    document.getElementById('modal').classList.add('flex');
    document.getElementById('formAction').value = 'create';
    document.getElementById('modalTitle').textContent = 'สร้างกลุ่มใหม่';
    document.querySelector('#modal form').reset();
}

function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.getElementById('modal').classList.remove('flex');
}

function editGroup(group) {
    openModal();
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = group.id;
    document.getElementById('modalTitle').textContent = 'แก้ไขกลุ่ม';
    document.getElementById('name').value = group.name;
    document.getElementById('description').value = group.description || '';
    document.getElementById('color').value = group.color;
}
</script>

<?php require_once 'includes/footer.php'; ?>
