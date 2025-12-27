<?php
/**
 * Setup LINE Account - หน้าตั้งค่าบัญชี LINE สำหรับผู้ใช้ใหม่
 * ผู้ใช้สามารถสร้าง LINE OA ของตัวเองได้
 */
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LineAPI.php';

// ต้องล็อกอินก่อน
if (!isset($_SESSION['admin_user'])) {
    header('Location: login.php');
    exit;
}

$user = $_SESSION['admin_user'];

// Admin ไม่ต้องตั้งค่า
if ($user['role'] === 'admin') {
    header('Location: ../admin/');
    exit;
}

// ถ้าตั้งค่าแล้วให้ไป dashboard
if (!empty($user['line_account_id'])) {
    header('Location: ../user/dashboard.php');
    exit;
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$step = (int)($_GET['step'] ?? 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_line_account') {
        $name = trim($_POST['name'] ?? '');
        $channelId = trim($_POST['channel_id'] ?? '');
        $channelSecret = trim($_POST['channel_secret'] ?? '');
        $channelAccessToken = trim($_POST['channel_access_token'] ?? '');
        $basicId = trim($_POST['basic_id'] ?? '');
        
        if (empty($name) || empty($channelSecret) || empty($channelAccessToken)) {
            $error = 'กรุณากรอก ชื่อบัญชี, Channel Secret และ Channel Access Token';
        } else {
            // ทดสอบการเชื่อมต่อก่อน
            $lineApi = new LineAPI($channelAccessToken);
            $botInfo = $lineApi->getBotInfo();
            
            if (!$botInfo) {
                $error = 'ไม่สามารถเชื่อมต่อกับ LINE API ได้ กรุณาตรวจสอบ Channel Access Token';
            } else {
                // ตรวจสอบว่า channel_secret ซ้ำหรือไม่
                $stmt = $db->prepare("SELECT id FROM line_accounts WHERE channel_secret = ?");
                $stmt->execute([$channelSecret]);
                if ($stmt->fetch()) {
                    $error = 'บัญชี LINE นี้ถูกลงทะเบียนในระบบแล้ว';
                } else {
                    // สร้าง LINE Account ใหม่
                    $webhookUrl = BASE_URL . '/webhook.php';
                    $pictureUrl = $botInfo['pictureUrl'] ?? null;
                    $displayName = $botInfo['displayName'] ?? $name;
                    
                    $stmt = $db->prepare("INSERT INTO line_accounts (name, channel_id, channel_secret, channel_access_token, webhook_url, basic_id, picture_url, is_active, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)");
                    $stmt->execute([$displayName, $channelId, $channelSecret, $channelAccessToken, $webhookUrl, $basicId, $pictureUrl]);
                    $lineAccountId = $db->lastInsertId();
                    
                    // อัพเดท user
                    $stmt = $db->prepare("UPDATE admin_users SET line_account_id = ? WHERE id = ?");
                    $stmt->execute([$lineAccountId, $user['id']]);
                    
                    // Update session
                    $_SESSION['admin_user']['line_account_id'] = $lineAccountId;
                    $_SESSION['current_bot_id'] = $lineAccountId;
                    
                    // สร้างข้อความต้อนรับเริ่มต้น
                    $welcomeMsg = "สวัสดีครับ/ค่ะ ยินดีต้อนรับสู่ {$displayName} 🎉\n\nพิมพ์ 'สินค้า' เพื่อดูสินค้าของเรา\nหรือพิมพ์ 'ติดต่อ' เพื่อติดต่อเจ้าหน้าที่";
                    $stmt = $db->prepare("INSERT INTO welcome_settings (line_account_id, is_enabled, message_type, text_content) VALUES (?, 1, 'text', ?)");
                    $stmt->execute([$lineAccountId, $welcomeMsg]);
                    
                    // สร้าง auto-reply เริ่มต้น
                    $defaultReplies = [
                        ['สินค้า', 'contains', "📦 สินค้าของเรา\n\nกรุณาพิมพ์ชื่อสินค้าที่ต้องการค้นหา หรือติดต่อเจ้าหน้าที่เพื่อสอบถามเพิ่มเติมครับ/ค่ะ"],
                        ['ติดต่อ', 'contains', "📞 ติดต่อเรา\n\nสามารถส่งข้อความมาได้เลยครับ/ค่ะ เจ้าหน้าที่จะตอบกลับโดยเร็วที่สุด"],
                        ['สวัสดี', 'contains', "สวัสดีครับ/ค่ะ 👋 มีอะไรให้ช่วยเหลือไหมครับ/ค่ะ?"],
                    ];
                    
                    $stmt = $db->prepare("INSERT INTO auto_replies (line_account_id, keyword, match_type, reply_content, is_active) VALUES (?, ?, ?, ?, 1)");
                    foreach ($defaultReplies as $reply) {
                        $stmt->execute([$lineAccountId, $reply[0], $reply[1], $reply[2]]);
                    }
                    
                    header('Location: setup-account.php?step=3&success=1');
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าบัญชี LINE - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans Thai', sans-serif; }
        .step-item {
            display: flex;
            align-items: center;
        }
        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }
        .step-circle.active {
            background: #06C755;
            color: white;
        }
        .step-circle.completed {
            background: #06C755;
            color: white;
        }
        .step-circle.inactive {
            background: #e2e8f0;
            color: #94a3b8;
        }
        .step-line {
            flex: 1;
            height: 2px;
            margin: 0 8px;
        }
        .step-line.active {
            background: #06C755;
        }
        .step-line.inactive {
            background: #e2e8f0;
        }
        .guide-step {
            display: flex;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .guide-step:last-child {
            border-bottom: none;
        }
        .guide-number {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #06C755;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
            margin-right: 12px;
            margin-top: 2px;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-green-400 to-green-600 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-green-500 to-green-600 p-6 text-center">
            <div class="w-16 h-16 bg-white rounded-2xl mx-auto flex items-center justify-center mb-3 shadow-lg">
                <i class="fab fa-line text-green-500 text-3xl"></i>
            </div>
            <h1 class="text-xl font-bold text-white">เชื่อมต่อบัญชี LINE OA</h1>
            <p class="text-green-100 text-sm mt-1">สร้างและเชื่อมต่อบัญชี LINE Official Account ของคุณ</p>
        </div>
        
        <!-- Steps Indicator -->
        <div class="px-6 py-4 bg-gray-50 border-b">
            <div class="flex items-center justify-center max-w-md mx-auto">
                <div class="step-item">
                    <div class="step-circle <?= $step >= 1 ? 'completed' : 'inactive' ?>">
                        <?= $step > 1 ? '<i class="fas fa-check text-xs"></i>' : '1' ?>
                    </div>
                    <span class="ml-2 text-sm <?= $step >= 1 ? 'text-gray-700 font-medium' : 'text-gray-400' ?>">คำแนะนำ</span>
                </div>
                <div class="step-line <?= $step >= 2 ? 'active' : 'inactive' ?>"></div>
                <div class="step-item">
                    <div class="step-circle <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : 'inactive' ?>">
                        <?= $step > 2 ? '<i class="fas fa-check text-xs"></i>' : '2' ?>
                    </div>
                    <span class="ml-2 text-sm <?= $step >= 2 ? 'text-gray-700 font-medium' : 'text-gray-400' ?>">กรอกข้อมูล</span>
                </div>
                <div class="step-line <?= $step >= 3 ? 'active' : 'inactive' ?>"></div>
                <div class="step-item">
                    <div class="step-circle <?= $step >= 3 ? 'active' : 'inactive' ?>">3</div>
                    <span class="ml-2 text-sm <?= $step >= 3 ? 'text-gray-700 font-medium' : 'text-gray-400' ?>">เสร็จสิ้น</span>
                </div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="p-6">
            <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-600 rounded-lg flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($step == 1): ?>
            <!-- Step 1: Guide -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-4">
                    <i class="fas fa-book text-green-500 mr-2"></i>ขั้นตอนการเชื่อมต่อ LINE OA
                </h2>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 mt-0.5 mr-2"></i>
                        <div class="text-sm text-blue-700">
                            <p class="font-medium">สวัสดี <?= htmlspecialchars($user['display_name'] ?: $user['username']) ?>!</p>
                            <p class="mt-1">ก่อนเริ่มใช้งาน คุณต้องเชื่อมต่อบัญชี LINE Official Account ของคุณกับระบบ โปรดทำตามขั้นตอนด้านล่าง</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white border rounded-lg p-4">
                    <div class="guide-step">
                        <div class="guide-number">1</div>
                        <div>
                            <p class="font-medium text-gray-800">สร้างบัญชี LINE Official Account</p>
                            <p class="text-sm text-gray-500 mt-1">ไปที่ <a href="https://manager.line.biz/" target="_blank" class="text-green-600 underline">LINE Official Account Manager</a> และสร้างบัญชีใหม่ (ถ้ายังไม่มี)</p>
                        </div>
                    </div>
                    
                    <div class="guide-step">
                        <div class="guide-number">2</div>
                        <div>
                            <p class="font-medium text-gray-800">เปิดใช้งาน Messaging API</p>
                            <p class="text-sm text-gray-500 mt-1">ไปที่ <a href="https://developers.line.biz/console/" target="_blank" class="text-green-600 underline">LINE Developers Console</a> → เลือก Provider → สร้าง Channel ใหม่ หรือเชื่อมต่อกับ LINE OA ที่มีอยู่</p>
                        </div>
                    </div>
                    
                    <div class="guide-step">
                        <div class="guide-number">3</div>
                        <div>
                            <p class="font-medium text-gray-800">คัดลอก Channel ID และ Channel Secret</p>
                            <p class="text-sm text-gray-500 mt-1">ในหน้า Basic settings ของ Channel ให้คัดลอก <strong>Channel ID</strong> และ <strong>Channel Secret</strong></p>
                            <img src="https://developers.line.biz/media/messaging-api/channel-secret-01-e9e3c6e7.png" class="mt-2 rounded border max-w-full h-auto" style="max-height: 150px;">
                        </div>
                    </div>
                    
                    <div class="guide-step">
                        <div class="guide-number">4</div>
                        <div>
                            <p class="font-medium text-gray-800">สร้าง Channel Access Token</p>
                            <p class="text-sm text-gray-500 mt-1">ไปที่แท็บ <strong>Messaging API</strong> → เลื่อนลงไปที่ "Channel access token" → กด <strong>Issue</strong></p>
                            <img src="https://developers.line.biz/media/messaging-api/channel-access-token-01-4c8e7c7e.png" class="mt-2 rounded border max-w-full h-auto" style="max-height: 150px;">
                        </div>
                    </div>
                    
                    <div class="guide-step">
                        <div class="guide-number">5</div>
                        <div>
                            <p class="font-medium text-gray-800">ตั้งค่า Webhook URL</p>
                            <p class="text-sm text-gray-500 mt-1">ในแท็บ Messaging API → ตั้งค่า Webhook URL เป็น:</p>
                            <div class="mt-2 p-2 bg-gray-100 rounded font-mono text-sm break-all">
                                <?= BASE_URL ?>/webhook.php?account=YOUR_ACCOUNT_ID
                            </div>
                            <p class="text-xs text-gray-400 mt-1">* หลังจากเชื่อมต่อสำเร็จ ระบบจะแสดง URL ที่ถูกต้องให้</p>
                        </div>
                    </div>
                    
                    <div class="guide-step">
                        <div class="guide-number">6</div>
                        <div>
                            <p class="font-medium text-gray-800">เปิดใช้งาน Webhook</p>
                            <p class="text-sm text-gray-500 mt-1">เปิด "Use webhook" และปิด "Auto-reply messages" และ "Greeting messages" ใน LINE Official Account Manager</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-between">
                <a href="logout.php" class="px-4 py-2 text-gray-500 hover:text-gray-700">
                    <i class="fas fa-sign-out-alt mr-1"></i>ออกจากระบบ
                </a>
                <a href="?step=2" class="px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold rounded-lg hover:from-green-600 hover:to-green-700 shadow-lg">
                    ถัดไป <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
            
            <?php elseif ($step == 2): ?>
            <!-- Step 2: Form -->
            <div class="mb-6">
                <h2 class="text-lg font-semibold mb-4">
                    <i class="fas fa-plug text-green-500 mr-2"></i>กรอกข้อมูล LINE API
                </h2>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create_line_account">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อบัญชี/ร้านค้า <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="เช่น ร้านขายของออนไลน์"
                               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Channel ID</label>
                        <input type="text" name="channel_id" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono"
                               placeholder="1234567890"
                               value="<?= htmlspecialchars($_POST['channel_id'] ?? '') ?>">
                        <p class="text-xs text-gray-500 mt-1">พบได้ที่ Basic settings ใน LINE Developers Console</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Channel Secret <span class="text-red-500">*</span></label>
                        <input type="text" name="channel_secret" required 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono"
                               placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                               value="<?= htmlspecialchars($_POST['channel_secret'] ?? '') ?>">
                        <p class="text-xs text-gray-500 mt-1">พบได้ที่ Basic settings ใน LINE Developers Console</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Channel Access Token <span class="text-red-500">*</span></label>
                        <textarea name="channel_access_token" required rows="3"
                                  class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 font-mono text-sm"
                                  placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx..."><?= htmlspecialchars($_POST['channel_access_token'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-500 mt-1">พบได้ที่ Messaging API settings → Channel access token → กด Issue</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">LINE Basic ID</label>
                        <input type="text" name="basic_id" 
                               class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="@yourshop"
                               value="<?= htmlspecialchars($_POST['basic_id'] ?? '') ?>">
                        <p class="text-xs text-gray-500 mt-1">ID ที่ขึ้นต้นด้วย @ (ไม่บังคับ)</p>
                    </div>
                    
                    <div class="flex justify-between pt-4">
                        <a href="?step=1" class="px-4 py-2 border rounded-lg hover:bg-gray-50">
                            <i class="fas fa-arrow-left mr-1"></i>ย้อนกลับ
                        </a>
                        <button type="submit" class="px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold rounded-lg hover:from-green-600 hover:to-green-700 shadow-lg">
                            <i class="fas fa-plug mr-2"></i>เชื่อมต่อ
                        </button>
                    </div>
                </form>
            </div>
            
            <?php elseif ($step == 3): ?>
            <!-- Step 3: Success -->
            <div class="text-center py-8">
                <div class="w-20 h-20 bg-green-100 rounded-full mx-auto flex items-center justify-center mb-4">
                    <i class="fas fa-check text-green-500 text-4xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-800 mb-2">เชื่อมต่อสำเร็จ!</h2>
                <p class="text-gray-600 mb-6">บัญชี LINE OA ของคุณพร้อมใช้งานแล้ว</p>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6 text-left max-w-md mx-auto">
                    <p class="font-medium text-yellow-800 mb-2"><i class="fas fa-exclamation-triangle mr-2"></i>อย่าลืม!</p>
                    <p class="text-sm text-yellow-700 mb-2">ตั้งค่า Webhook URL ใน LINE Developers Console:</p>
                    <div class="p-2 bg-white rounded font-mono text-xs break-all border">
                        <?= BASE_URL ?>/webhook.php?account=<?= $_SESSION['admin_user']['line_account_id'] ?>
                    </div>
                    <p class="text-xs text-yellow-600 mt-2">และเปิด "Use webhook" ให้เรียบร้อย</p>
                </div>
                
                <div class="bg-gray-50 rounded-lg p-4 mb-6 text-left max-w-md mx-auto">
                    <p class="font-medium text-gray-700 mb-2"><i class="fas fa-gift text-green-500 mr-2"></i>ระบบได้สร้างให้คุณแล้ว:</p>
                    <ul class="text-sm text-gray-600 space-y-1">
                        <li><i class="fas fa-check text-green-500 mr-2"></i>ข้อความต้อนรับอัตโนมัติ</li>
                        <li><i class="fas fa-check text-green-500 mr-2"></i>กฎตอบกลับอัตโนมัติเบื้องต้น</li>
                    </ul>
                </div>
                
                <a href="../user/dashboard.php" class="inline-block px-8 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold rounded-lg hover:from-green-600 hover:to-green-700 shadow-lg">
                    <i class="fas fa-rocket mr-2"></i>เริ่มใช้งาน
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
