<?php
/**
 * Welcome Message Settings
 * ตั้งค่าข้อความต้อนรับ (รองรับ Flex Message)
 */

$currentBotId = $_SESSION['current_bot_id'] ?? null;

// Get current welcome settings
$welcomeSettings = [];
try {
    $stmt = $db->prepare("SELECT * FROM welcome_settings WHERE line_account_id = ? OR (line_account_id IS NULL AND ? IS NULL) LIMIT 1");
    $stmt->execute([$currentBotId, $currentBotId]);
    $welcomeSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $welcomeSettings = [];
}

if (!$welcomeSettings) {
    $welcomeSettings = [
        'is_enabled' => 0,
        'message_type' => 'text',
        'text_content' => "สวัสดีค่ะ ยินดีต้อนรับ! 🎉\n\nขอบคุณที่เพิ่มเราเป็นเพื่อน\nหากต้องการความช่วยเหลือ สามารถพิมพ์ข้อความมาได้เลยค่ะ",
        'flex_content' => ''
    ];
}

$isEnabled = $welcomeSettings['is_enabled'] ?? 0;
$messageType = $welcomeSettings['message_type'] ?? 'text';
$textContent = $welcomeSettings['text_content'] ?? '';
$flexContent = $welcomeSettings['flex_content'] ?? '';
?>

<div class="bg-white rounded-xl shadow-sm p-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-xl font-bold text-gray-800">ข้อความต้อนรับ</h2>
            <p class="text-gray-500 text-sm">ตั้งค่าข้อความที่จะส่งเมื่อมีผู้ติดตามใหม่</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-600">เปิดใช้งาน</span>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" name="is_enabled" form="welcomeForm" <?= $isEnabled ? 'checked' : '' ?> class="sr-only peer">
                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500"></div>
            </label>
        </div>
    </div>

    <form id="welcomeForm" method="POST" action="settings.php?tab=welcome">
        <input type="hidden" name="action" value="save_welcome">
        <input type="hidden" name="tab" value="welcome">
        
        <!-- Message Type -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">ประเภทข้อความ</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="message_type" value="text" <?= $messageType === 'text' ? 'checked' : '' ?> 
                           onchange="toggleMessageType('text')" class="w-4 h-4 text-green-600">
                    <span>ข้อความธรรมดา</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="message_type" value="flex" <?= $messageType === 'flex' ? 'checked' : '' ?>
                           onchange="toggleMessageType('flex')" class="w-4 h-4 text-green-600">
                    <span>Flex Message</span>
                </label>
            </div>
        </div>

        <!-- Text Message -->
        <div id="textMessageSection" class="mb-6 <?= $messageType === 'flex' ? 'hidden' : '' ?>">
            <label class="block text-sm font-medium text-gray-700 mb-2">ข้อความต้อนรับ</label>
            <textarea name="text_content" rows="4" 
                      class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      placeholder="สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา..."><?= htmlspecialchars($textContent) ?></textarea>
            <p class="text-xs text-gray-500 mt-1">รองรับ Emoji และขึ้นบรรทัดใหม่ได้</p>
        </div>

        <!-- Flex Message -->
        <div id="flexMessageSection" class="mb-6 <?= $messageType === 'text' ? 'hidden' : '' ?>">
            <label class="block text-sm font-medium text-gray-700 mb-2">Flex Message JSON</label>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div>
                    <textarea name="flex_content" id="flexJsonInput" rows="15" 
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg font-mono text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
                              placeholder='{"type": "bubble", "body": {...}}'
                              oninput="updateFlexPreview()"><?= htmlspecialchars($flexContent) ?></textarea>
                    <div class="flex gap-2 mt-2">
                        <button type="button" onclick="loadFlexTemplate('welcome')" class="px-3 py-1.5 bg-blue-100 text-blue-700 rounded-lg text-sm hover:bg-blue-200">
                            <i class="fas fa-magic mr-1"></i>Template ต้อนรับ
                        </button>
                        <button type="button" onclick="loadFlexTemplate('promo')" class="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-sm hover:bg-purple-200">
                            <i class="fas fa-gift mr-1"></i>Template โปรโมชั่น
                        </button>
                        <button type="button" onclick="validateFlexJson()" class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">
                            <i class="fas fa-check mr-1"></i>ตรวจสอบ JSON
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ตัวอย่าง Preview</label>
                    <div id="flexPreview" class="border rounded-lg p-4 bg-gray-50 min-h-[300px] overflow-auto">
                        <p class="text-gray-400 text-center py-8">ใส่ JSON เพื่อดูตัวอย่าง</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-2.5 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium">
                <i class="fas fa-save mr-2"></i>บันทึกการตั้งค่า
            </button>
        </div>
    </form>
</div>

<script>
function toggleMessageType(type) {
    document.getElementById('textMessageSection').classList.toggle('hidden', type === 'flex');
    document.getElementById('flexMessageSection').classList.toggle('hidden', type === 'text');
}

function updateFlexPreview() {
    const json = document.getElementById('flexJsonInput').value;
    const preview = document.getElementById('flexPreview');
    
    if (!json.trim()) {
        preview.innerHTML = '<p class="text-gray-400 text-center py-8">ใส่ JSON เพื่อดูตัวอย่าง</p>';
        return;
    }
    
    try {
        const data = JSON.parse(json);
        preview.innerHTML = renderFlexPreview(data);
    } catch (e) {
        preview.innerHTML = `<p class="text-red-500 text-center py-8"><i class="fas fa-exclamation-triangle mr-2"></i>JSON ไม่ถูกต้อง: ${e.message}</p>`;
    }
}

function renderFlexPreview(data) {
    // Simple flex preview renderer
    let html = '<div class="bg-white rounded-xl shadow border overflow-hidden max-w-xs mx-auto">';
    
    if (data.type === 'bubble') {
        // Header
        if (data.header) {
            html += '<div class="bg-gradient-to-r from-green-500 to-green-600 text-white p-4">';
            html += renderFlexContents(data.header.contents || []);
            html += '</div>';
        }
        
        // Hero (image)
        if (data.hero && data.hero.url) {
            html += `<img src="${data.hero.url}" class="w-full h-40 object-cover">`;
        }
        
        // Body
        if (data.body) {
            html += '<div class="p-4">';
            html += renderFlexContents(data.body.contents || []);
            html += '</div>';
        }
        
        // Footer
        if (data.footer) {
            html += '<div class="p-3 border-t bg-gray-50">';
            html += renderFlexContents(data.footer.contents || []);
            html += '</div>';
        }
    } else {
        html += '<div class="p-4 text-gray-500">รองรับเฉพาะ type: bubble</div>';
    }
    
    html += '</div>';
    return html;
}

function renderFlexContents(contents) {
    let html = '';
    contents.forEach(item => {
        if (item.type === 'text') {
            const size = item.size === 'xl' ? 'text-xl' : item.size === 'lg' ? 'text-lg' : item.size === 'sm' ? 'text-sm' : 'text-base';
            const weight = item.weight === 'bold' ? 'font-bold' : '';
            const color = item.color ? `color: ${item.color}` : '';
            html += `<p class="${size} ${weight}" style="${color}">${item.text || ''}</p>`;
        } else if (item.type === 'button') {
            const style = item.style === 'primary' ? 'bg-green-500 text-white' : 'border border-green-500 text-green-600';
            html += `<button class="w-full py-2 rounded-lg ${style} text-sm mt-2">${item.action?.label || 'Button'}</button>`;
        } else if (item.type === 'box') {
            const layout = item.layout === 'horizontal' ? 'flex justify-between' : '';
            html += `<div class="${layout}">${renderFlexContents(item.contents || [])}</div>`;
        } else if (item.type === 'image' && item.url) {
            html += `<img src="${item.url}" class="w-full rounded-lg">`;
        }
    });
    return html;
}

function loadFlexTemplate(type) {
    const templates = {
        welcome: {
            "type": "bubble",
            "header": {
                "type": "box",
                "layout": "vertical",
                "contents": [
                    {"type": "text", "text": "🎉 ยินดีต้อนรับ!", "weight": "bold", "size": "xl", "color": "#ffffff"}
                ]
            },
            "body": {
                "type": "box",
                "layout": "vertical",
                "contents": [
                    {"type": "text", "text": "สวัสดีค่ะ {name}", "weight": "bold", "size": "lg"},
                    {"type": "text", "text": "ขอบคุณที่ติดตามร้านของเรา", "size": "sm", "color": "#666666", "margin": "md"},
                    {"type": "text", "text": "พิมพ์ 'เมนู' เพื่อดูบริการของเรา", "size": "sm", "color": "#888888", "margin": "lg"}
                ]
            },
            "footer": {
                "type": "box",
                "layout": "vertical",
                "contents": [
                    {"type": "button", "style": "primary", "action": {"type": "message", "label": "ดูสินค้า", "text": "สินค้า"}}
                ]
            }
        },
        promo: {
            "type": "bubble",
            "header": {
                "type": "box",
                "layout": "vertical",
                "contents": [
                    {"type": "text", "text": "🎁 โปรโมชั่นพิเศษ!", "weight": "bold", "size": "xl", "color": "#ffffff"}
                ]
            },
            "body": {
                "type": "box",
                "layout": "vertical",
                "contents": [
                    {"type": "text", "text": "ลด 20% สำหรับสมาชิกใหม่", "weight": "bold", "size": "lg"},
                    {"type": "text", "text": "ใช้โค้ด: WELCOME20", "size": "md", "color": "#FF5722", "margin": "md"},
                    {"type": "text", "text": "หมดเขต 31 ม.ค. 2026", "size": "sm", "color": "#888888", "margin": "lg"}
                ]
            },
            "footer": {
                "type": "box",
                "layout": "vertical",
                "contents": [
                    {"type": "button", "style": "primary", "action": {"type": "uri", "label": "ช้อปเลย!", "uri": "https://liff.line.me/xxx"}}
                ]
            }
        }
    };
    
    document.getElementById('flexJsonInput').value = JSON.stringify(templates[type], null, 2);
    updateFlexPreview();
}

function validateFlexJson() {
    const json = document.getElementById('flexJsonInput').value;
    try {
        JSON.parse(json);
        alert('✅ JSON ถูกต้อง!');
    } catch (e) {
        alert('❌ JSON ไม่ถูกต้อง: ' + e.message);
    }
}

// Initial preview
document.addEventListener('DOMContentLoaded', updateFlexPreview);
</script>
