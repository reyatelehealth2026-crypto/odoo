<?php
/**
 * User Settings Overview - ภาพรวมการตั้งค่าทั้งหมดของ LINE Account
 */
$pageTitle = 'ภาพรวมการตั้งค่า';
require_once '../includes/user_header.php';

// Get all settings for this LINE Account
$settings = [];

// 1. Welcome Settings
$stmt = $db->prepare("SELECT * FROM welcome_settings WHERE line_account_id = ?");
$stmt->execute([$currentBotId]);
$settings['welcome'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. Auto-Reply Count
$stmt = $db->prepare("SELECT COUNT(*) as total, SUM(is_active) as active FROM auto_replies WHERE line_account_id = ?");
$stmt->execute([$currentBotId]);
$settings['auto_reply'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Shop Settings
$stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ?");
$stmt->execute([$currentBotId]);
$settings['shop'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 4. Products Count
$stmt = $db->prepare("SELECT COUNT(*) as total, SUM(is_active) as active FROM business_items WHERE line_account_id = ?");
$stmt->execute([$currentBotId]);
$settings['products'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 5. Categories Count
$stmt = $db->prepare("SELECT COUNT(*) as total FROM product_categories WHERE line_account_id = ?");
$stmt->execute([$currentBotId]);
$settings['categories'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 6. Users/Customers Count
$stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE line_account_id = ? AND is_blocked = 0");
$stmt->execute([$currentBotId]);
$settings['users'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 7. Orders Count
$stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending FROM orders WHERE line_account_id = ?");
$stmt->execute([$currentBotId]);
$settings['orders'] = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="mb-6">
    <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-6 text-white">
        <div class="flex items-center">
            <div class="w-16 h-16 bg-white/20 rounded-xl flex items-center justify-center mr-4">
                <?php if ($lineAccount['picture_url']): ?>
                <img src="<?= htmlspecialchars($lineAccount['picture_url']) ?>" class="w-full h-full rounded-xl object-cover">
                <?php else: ?>
                <i class="fab fa-line text-3xl"></i>
                <?php endif; ?>
            </div>
            <div>
                <h1 class="text-xl font-bold"><?= htmlspecialchars($lineAccount['name']) ?></h1>
                <p class="text-green-100"><?= htmlspecialchars($lineAccount['basic_id'] ?? 'LINE Official Account') ?></p>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <!-- Welcome Message -->
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-yellow-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-hand-wave text-yellow-600"></i>
                </div>
                <h3 class="font-semibold">ข้อความต้อนรับ</h3>
            </div>
            <?php if ($settings['welcome'] && $settings['welcome']['is_enabled']): ?>
            <span class="px-2 py-1 bg-green-100 text-green-600 text-xs rounded-full">เปิด</span>
            <?php else: ?>
            <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded-full">ปิด</span>
            <?php endif; ?>
        </div>
        <p class="text-sm text-gray-500 mb-4">
            <?php if ($settings['welcome'] && $settings['welcome']['text_content']): ?>
            <?= htmlspecialchars(mb_substr($settings['welcome']['text_content'], 0, 80)) ?>...
            <?php else: ?>
            ยังไม่ได้ตั้งค่าข้อความต้อนรับ
            <?php endif; ?>
        </p>
        <a href="welcome-settings.php" class="text-green-600 text-sm font-medium hover:underline">
            <i class="fas fa-cog mr-1"></i>ตั้งค่า
        </a>
    </div>
    
    <!-- Auto Reply -->
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-robot text-blue-600"></i>
                </div>
                <h3 class="font-semibold">ตอบกลับอัตโนมัติ</h3>
            </div>
            <span class="px-2 py-1 bg-blue-100 text-blue-600 text-xs rounded-full">
                <?= (int)($settings['auto_reply']['active'] ?? 0) ?> กฎ
            </span>
        </div>
        <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
            <span>กฎทั้งหมด: <?= (int)($settings['auto_reply']['total'] ?? 0) ?></span>
            <span>เปิดใช้งาน: <?= (int)($settings['auto_reply']['active'] ?? 0) ?></span>
        </div>
        <a href="auto-reply.php" class="text-green-600 text-sm font-medium hover:underline">
            <i class="fas fa-cog mr-1"></i>จัดการ
        </a>
    </div>
    
    <!-- Shop Settings -->
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-store text-purple-600"></i>
                </div>
                <h3 class="font-semibold">ร้านค้า</h3>
            </div>
            <?php if ($settings['shop'] && $settings['shop']['is_open']): ?>
            <span class="px-2 py-1 bg-green-100 text-green-600 text-xs rounded-full">เปิด</span>
            <?php else: ?>
            <span class="px-2 py-1 bg-red-100 text-red-600 text-xs rounded-full">ปิด</span>
            <?php endif; ?>
        </div>
        <p class="text-sm text-gray-500 mb-4">
            <?= htmlspecialchars($settings['shop']['shop_name'] ?? 'ยังไม่ได้ตั้งค่า') ?>
        </p>
        <a href="shop-settings.php" class="text-green-600 text-sm font-medium hover:underline">
            <i class="fas fa-cog mr-1"></i>ตั้งค่า
        </a>
    </div>
    
    <!-- Products -->
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-box text-orange-600"></i>
                </div>
                <h3 class="font-semibold">สินค้า</h3>
            </div>
            <span class="px-2 py-1 bg-orange-100 text-orange-600 text-xs rounded-full">
                <?= (int)($settings['products']['total'] ?? 0) ?> รายการ
            </span>
        </div>
        <div class="flex items-center justify-between text-sm text-gray-500 mb-4">
            <span>ทั้งหมด: <?= (int)($settings['products']['total'] ?? 0) ?></span>
            <span>เปิดขาย: <?= (int)($settings['products']['active'] ?? 0) ?></span>
        </div>
        <a href="products.php" class="text-green-600 text-sm font-medium hover:underline">
            <i class="fas fa-cog mr-1"></i>จัดการ
        </a>
    </div>
    
    <!-- Categories -->
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-folder text-indigo-600"></i>
                </div>
                <h3 class="font-semibold">หมวดหมู่</h3>
            </div>
            <span class="px-2 py-1 bg-indigo-100 text-indigo-600 text-xs rounded-full">
                <?= (int)($settings['categories']['total'] ?? 0) ?> หมวด
            </span>
        </div>
        <p class="text-sm text-gray-500 mb-4">
            จัดกลุ่มสินค้าเป็นหมวดหมู่
        </p>
        <a href="categories.php" class="text-green-600 text-sm font-medium hover:underline">
            <i class="fas fa-cog mr-1"></i>จัดการ
        </a>
    </div>
    
    <!-- Customers -->
    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center">
                <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3">
                    <i class="fas fa-users text-green-600"></i>
                </div>
                <h3 class="font-semibold">ลูกค้า</h3>
            </div>
            <span class="px-2 py-1 bg-green-100 text-green-600 text-xs rounded-full">
                <?= number_format((int)($settings['users']['total'] ?? 0)) ?> คน
            </span>
        </div>
        <p class="text-sm text-gray-500 mb-4">
            ผู้ติดตาม LINE OA ของคุณ
        </p>
        <a href="users.php" class="text-green-600 text-sm font-medium hover:underline">
            <i class="fas fa-eye mr-1"></i>ดูทั้งหมด
        </a>
    </div>
</div>

<!-- Data Isolation Notice -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4">
    <div class="flex items-start">
        <i class="fas fa-shield-alt text-blue-500 mt-1 mr-3"></i>
        <div>
            <h4 class="font-medium text-blue-800">ข้อมูลแยกตาม LINE Account</h4>
            <p class="text-sm text-blue-600 mt-1">
                ข้อมูลทั้งหมดที่แสดงด้านบนเป็นของ <strong><?= htmlspecialchars($lineAccount['name']) ?></strong> เท่านั้น 
                ไม่ปะปนกับ LINE Account อื่นในระบบ
            </p>
        </div>
    </div>
</div>

<!-- Gemini AI API Key -->
<div class="mt-6 bg-white rounded-xl shadow p-6">
    <h3 class="font-semibold mb-4"><i class="fas fa-magic text-purple-500 mr-2"></i>Gemini AI (ช่วยคิดแคปชั่น)</h3>
    <p class="text-sm text-gray-500 mb-3">กรอก API Key เพื่อใช้ AI ช่วยเขียนข้อความ Broadcast</p>
    <form id="geminiForm" class="flex items-center gap-2">
        <input type="password" id="geminiApiKey" value="<?= htmlspecialchars($lineAccount['gemini_api_key'] ?? '') ?>" 
               placeholder="AIzaSy..." class="flex-1 px-4 py-2 border rounded-lg text-sm font-mono">
        <button type="button" onclick="toggleApiKeyVisibility()" class="px-3 py-2 border rounded-lg hover:bg-gray-50">
            <i class="fas fa-eye" id="eyeIcon"></i>
        </button>
        <button type="submit" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
            <i class="fas fa-save mr-1"></i>บันทึก
        </button>
    </form>
    <p class="text-xs text-gray-400 mt-2">
        <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-purple-500 hover:underline">
            <i class="fas fa-external-link-alt mr-1"></i>สร้าง API Key ฟรีที่ Google AI Studio
        </a>
    </p>
</div>

<!-- Webhook URL -->
<div class="mt-6 bg-white rounded-xl shadow p-6">
    <h3 class="font-semibold mb-4"><i class="fas fa-link text-gray-400 mr-2"></i>Webhook URL</h3>
    <p class="text-sm text-gray-500 mb-2">ใช้ URL นี้ในการตั้งค่า LINE Developers Console:</p>
    <div class="flex items-center gap-2">
        <input type="text" readonly value="<?= BASE_URL ?>/webhook.php?account=<?= $currentBotId ?>" 
               class="flex-1 px-4 py-2 bg-gray-50 border rounded-lg text-sm font-mono" id="webhookUrl">
        <button onclick="copyWebhookUrl()" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
            <i class="fas fa-copy"></i>
        </button>
    </div>
</div>

<script>
function copyWebhookUrl() {
    const input = document.getElementById('webhookUrl');
    input.select();
    document.execCommand('copy');
    showToast('คัดลอก URL แล้ว');
}

function toggleApiKeyVisibility() {
    const input = document.getElementById('geminiApiKey');
    const icon = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

document.getElementById('geminiForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const apiKey = document.getElementById('geminiApiKey').value.trim();
    
    try {
        const response = await fetch('../api/ajax_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_gemini_key&api_key=' + encodeURIComponent(apiKey)
        });
        const result = await response.json();
        if (result.success) {
            showToast('บันทึก API Key สำเร็จ');
        } else {
            alert('Error: ' + result.error);
        }
    } catch (err) {
        alert('Connection Error');
    }
});
</script>

<?php require_once '../includes/user_footer.php'; ?>
