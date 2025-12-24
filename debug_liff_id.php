<?php
/**
 * Debug & Fix LIFF ID - ตรวจสอบและแก้ไข LIFF ID ในฐานข้อมูล
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_GET['account'] ?? 1;
$action = $_GET['action'] ?? '';

// Default LIFF ID ที่ตั้งค่าไว้ใน LINE Developers Console
$defaultLiffId = '2008728363-92WCzBs4';

// Handle fix action
if ($action === 'fix') {
    $newLiffId = $_GET['liff_id'] ?? $defaultLiffId;
    try {
        // Check if liff_id column exists
        $cols = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'liff_id'")->fetchAll();
        if (empty($cols)) {
            // Add column if not exists
            $db->exec("ALTER TABLE line_accounts ADD COLUMN liff_id VARCHAR(100) NULL AFTER channel_secret");
        }
        
        $stmt = $db->prepare("UPDATE line_accounts SET liff_id = ? WHERE id = ?");
        $stmt->execute([$newLiffId, $lineAccountId]);
        
        header("Location: debug_liff_id.php?account=$lineAccountId&success=1");
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug LIFF ID</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen p-4">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
            <i class="fas fa-bug text-orange-500"></i> Debug LIFF ID
        </h1>
        
        <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4">
            <i class="fas fa-check-circle mr-2"></i> อัพเดท LIFF ID สำเร็จ!
        </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4">
            <i class="fas fa-exclamation-circle mr-2"></i> Error: <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <!-- Current Status -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-4">
            <h2 class="font-bold text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-database text-blue-500"></i> สถานะปัจจุบัน
            </h2>
            
            <?php
            try {
                // Check column exists
                $cols = $db->query("SHOW COLUMNS FROM line_accounts LIKE 'liff_id'")->fetchAll();
                $columnExists = !empty($cols);
                
                // Get all accounts
                $stmt = $db->query("SELECT id, name, liff_id, is_default, is_active FROM line_accounts ORDER BY id");
                $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <div class="mb-4">
                <span class="text-sm text-gray-500">Column liff_id:</span>
                <?php if ($columnExists): ?>
                <span class="ml-2 px-2 py-1 bg-green-100 text-green-700 rounded text-sm">✅ มีอยู่แล้ว</span>
                <?php else: ?>
                <span class="ml-2 px-2 py-1 bg-red-100 text-red-700 rounded text-sm">❌ ไม่มี column</span>
                <?php endif; ?>
            </div>
            
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">ID</th>
                        <th class="px-3 py-2 text-left">Name</th>
                        <th class="px-3 py-2 text-left">LIFF ID</th>
                        <th class="px-3 py-2 text-center">Default</th>
                        <th class="px-3 py-2 text-center">Active</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $a): ?>
                    <tr class="border-t <?= $a['id'] == $lineAccountId ? 'bg-teal-50' : '' ?>">
                        <td class="px-3 py-2 font-mono"><?= $a['id'] ?></td>
                        <td class="px-3 py-2"><?= htmlspecialchars($a['name']) ?></td>
                        <td class="px-3 py-2">
                            <?php if (!empty($a['liff_id'])): ?>
                            <code class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs"><?= htmlspecialchars($a['liff_id']) ?></code>
                            <?php else: ?>
                            <span class="text-red-500 font-bold">❌ NULL/EMPTY</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-center"><?= $a['is_default'] ? '✅' : '' ?></td>
                        <td class="px-3 py-2 text-center"><?= $a['is_active'] ? '✅' : '' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php } catch (Exception $e) { ?>
            <div class="text-red-500">Error: <?= htmlspecialchars($e->getMessage()) ?></div>
            <?php } ?>
        </div>
        
        <!-- Fix Section -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-4">
            <h2 class="font-bold text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-wrench text-orange-500"></i> แก้ไข LIFF ID
            </h2>
            
            <form method="get" class="space-y-4">
                <input type="hidden" name="action" value="fix">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Account ID</label>
                    <input type="number" name="account" value="<?= (int)$lineAccountId ?>" 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-teal-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">LIFF ID</label>
                    <input type="text" name="liff_id" value="<?= htmlspecialchars($defaultLiffId) ?>" 
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-teal-500 font-mono">
                    <p class="text-xs text-gray-500 mt-1">LIFF ID จาก LINE Developers Console</p>
                </div>
                
                <button type="submit" class="w-full bg-teal-500 hover:bg-teal-600 text-white font-bold py-3 px-4 rounded-lg transition">
                    <i class="fas fa-save mr-2"></i> บันทึก LIFF ID
                </button>
            </form>
        </div>
        
        <!-- Test Links -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h2 class="font-bold text-lg mb-4 flex items-center gap-2">
                <i class="fas fa-external-link-alt text-purple-500"></i> ทดสอบ
            </h2>
            
            <div class="space-y-2">
                <a href="liff-app.php?account=<?= $lineAccountId ?>" 
                   class="block px-4 py-3 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    <i class="fas fa-home mr-2 text-teal-500"></i> LIFF App (หน้าหลัก)
                </a>
                <a href="liff-register.php?account=<?= $lineAccountId ?>" 
                   class="block px-4 py-3 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                    <i class="fas fa-user-plus mr-2 text-blue-500"></i> หน้าสมัครสมาชิก
                </a>
                <a href="https://liff.line.me/<?= htmlspecialchars($defaultLiffId) ?>" target="_blank"
                   class="block px-4 py-3 bg-green-100 hover:bg-green-200 rounded-lg transition">
                    <i class="fab fa-line mr-2 text-green-600"></i> เปิดผ่าน LINE LIFF URL
                </a>
            </div>
        </div>
        
        <p class="text-center text-gray-400 text-sm mt-6">
            <a href="index.php" class="hover:text-gray-600">← กลับหน้าหลัก</a>
        </p>
    </div>
</body>
</html>
