<?php
/**
 * Import Legacy Points - นำเข้าแต้มจากระบบเก่า
 * แมทช์เบอร์โทรศัพท์แล้วเพิ่มแต้มเข้าระบบใหม่
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/AdminAuth.php';
require_once __DIR__ . '/classes/LoyaltyPoints.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

$db = Database::getInstance()->getConnection();
$auth = new AdminAuth($db);
$activityLogger = ActivityLogger::getInstance($db);

// Require admin access
$auth->requireLogin('auth/login.php');
$currentUser = $auth->getCurrentUser();

if (!in_array($currentUser['role'], ['super_admin', 'admin'])) {
    header('Location: index.php?error=no_permission');
    exit;
}

$pageTitle = 'นำเข้าแต้มจากระบบเก่า';
$error = null;
$success = null;
$previewData = null;
$importResults = null;

// Phone number normalization function
function normalizePhone($phone)
{
    // Remove all non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);

    // Handle international format (66xxxxxxxxx → 0xxxxxxxxx)
    if (strlen($phone) === 11 && substr($phone, 0, 2) === '66') {
        $phone = '0' . substr($phone, 2);
    }
    // Handle +66 format that was already stripped
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '660') {
        $phone = '0' . substr($phone, 3);
    }

    return $phone;
}

// Handle file upload and processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'preview') {
            // Handle CSV upload and preview
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('กรุณาอัพโหลดไฟล์ CSV');
            }

            $file = $_FILES['csv_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($ext !== 'csv') {
                throw new Exception('รองรับเฉพาะไฟล์ CSV เท่านั้น');
            }

            // Parse CSV
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                throw new Exception('ไม่สามารถอ่านไฟล์ได้');
            }

            $matchType = $_POST['match_type'] ?? 'phone';
            $identifierCol = (int) ($_POST['identifier_column'] ?? 0);
            $pointsCol = (int) ($_POST['points_column'] ?? 1);
            $hasHeader = isset($_POST['has_header']);

            $rows = [];
            $lineNum = 0;
            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                $lineNum++;

                // Skip header if specified
                if ($hasHeader && $lineNum === 1) {
                    continue;
                }

                if (!isset($data[$identifierCol]) || !isset($data[$pointsCol])) {
                    continue;
                }

                $identifier = trim($data[$identifierCol]);
                $points = (int) trim($data[$pointsCol]);

                if (empty($identifier) || $points <= 0) {
                    continue;
                }

                $normalizedValue = $identifier;
                $user = null;

                if ($matchType === 'phone') {
                    // Match by phone number
                    $normalizedValue = normalizePhone($identifier);
                    $stmt = $db->prepare("
                        SELECT id, display_name, phone, line_user_id, available_points, total_points 
                        FROM users 
                        WHERE REPLACE(REPLACE(phone, '-', ''), ' ', '') = ? 
                           OR REPLACE(REPLACE(phone, '-', ''), ' ', '') = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$normalizedValue, $identifier]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    // Match by LINE User ID
                    $stmt = $db->prepare("
                        SELECT id, display_name, phone, line_user_id, available_points, total_points 
                        FROM users 
                        WHERE line_user_id = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$identifier]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                $rows[] = [
                    'match_type' => $matchType,
                    'original_value' => $identifier,
                    'normalized_value' => $normalizedValue,
                    'points' => $points,
                    'matched' => $user ? true : false,
                    'user_id' => $user ? $user['id'] : null,
                    'user_name' => $user ? $user['display_name'] : null,
                    'current_points' => $user ? $user['available_points'] : null
                ];
            }
            fclose($handle);

            if (empty($rows)) {
                throw new Exception('ไม่พบข้อมูลใน CSV');
            }

            // Store in session for import
            $_SESSION['import_data'] = $rows;
            $_SESSION['import_match_type'] = $matchType;
            $previewData = $rows;

        } elseif ($action === 'import') {
            // Execute import
            if (!isset($_SESSION['import_data']) || empty($_SESSION['import_data'])) {
                throw new Exception('ไม่มีข้อมูลสำหรับ Import - กรุณา Preview ก่อน');
            }

            $rows = $_SESSION['import_data'];
            $lineAccountId = (int) ($_POST['line_account_id'] ?? 1);
            $description = trim($_POST['description'] ?? 'นำเข้าจากระบบเก่า');

            $loyaltyPoints = new LoyaltyPoints($db, $lineAccountId);

            $imported = 0;
            $skipped = 0;
            $details = [];

            foreach ($rows as $row) {
                if (!$row['matched'] || !$row['user_id']) {
                    $skipped++;
                    continue;
                }

                $result = $loyaltyPoints->addPoints(
                    $row['user_id'],
                    $row['points'],
                    'import',
                    null,
                    $description
                );

                if ($result) {
                    $imported++;
                    $details[] = [
                        'identifier' => $row['original_value'],
                        'user' => $row['user_name'],
                        'points' => $row['points'],
                        'status' => 'success'
                    ];
                } else {
                    $skipped++;
                    $details[] = [
                        'identifier' => $row['original_value'],
                        'user' => $row['user_name'],
                        'points' => $row['points'],
                        'status' => 'failed'
                    ];
                }
            }

            // Log activity
            $activityLogger->logAdmin(ActivityLogger::ACTION_CREATE, 'นำเข้าแต้มจากระบบเก่า', [
                'entity_type' => 'points_import',
                'new_value' => ['imported' => $imported, 'skipped' => $skipped]
            ]);

            // Clear session data
            unset($_SESSION['import_data']);

            $importResults = [
                'imported' => $imported,
                'skipped' => $skipped,
                'details' => $details
            ];

            $success = "นำเข้าสำเร็จ {$imported} รายการ, ข้ามไป {$skipped} รายการ";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get LINE accounts for selection
$lineAccounts = $db->query("SELECT id, name FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC, name")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-file-import text-indigo-500 mr-2"></i>นำเข้าแต้มจากระบบเก่า
            </h1>
            <p class="text-gray-500 text-sm mt-1">อัพโหลด CSV เพื่อแมทช์เบอร์โทรศัพท์แล้วเพิ่มแต้มเข้าระบบ</p>
        </div>
        <a href="users.php"
            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition-all">
            <i class="fas fa-arrow-left mr-2"></i>กลับ
        </a>
    </div>

    <?php if ($error): ?>
        <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center">
            <i class="fas fa-exclamation-circle mr-3 text-red-500"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl flex items-center">
            <i class="fas fa-check-circle mr-3 text-green-500"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!$previewData && !$importResults): ?>
        <!-- Upload Form -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-indigo-500 to-indigo-600 text-white">
                <h3 class="font-semibold text-lg">
                    <i class="fas fa-upload mr-2"></i>อัพโหลดไฟล์ CSV
                </h3>
            </div>

            <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                <input type="hidden" name="action" value="preview">

                <!-- File Upload -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-file-csv text-green-500 mr-1"></i>เลือกไฟล์ CSV
                    </label>
                    <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-indigo-400 transition-colors"
                        id="dropzone">
                        <input type="file" name="csv_file" id="csv_file" accept=".csv" required class="hidden"
                            onchange="updateFileName(this)">
                        <label for="csv_file" class="cursor-pointer">
                            <div class="text-5xl text-gray-300 mb-4">
                                <i class="fas fa-cloud-upload-alt"></i>
                            </div>
                            <p class="text-gray-600 font-medium" id="file_name">คลิกเพื่อเลือกไฟล์หรือลากวาง</p>
                            <p class="text-gray-400 text-sm mt-2">รองรับไฟล์ .csv</p>
                        </label>
                    </div>
                </div>

                <!-- Match Type Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-search text-indigo-500 mr-1"></i>วิธีการแมทช์ผู้ใช้
                    </label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="relative cursor-pointer">
                            <input type="radio" name="match_type" value="phone" checked 
                                   class="peer sr-only" onchange="updateMatchTypeUI()">
                            <div class="p-4 border-2 border-gray-200 rounded-xl peer-checked:border-indigo-500 peer-checked:bg-indigo-50 transition-all">
                                <div class="flex items-center">
                                    <i class="fas fa-phone text-blue-500 text-xl mr-3"></i>
                                    <div>
                                        <div class="font-medium">เบอร์โทรศัพท์</div>
                                        <div class="text-xs text-gray-500">แมทช์ด้วยเบอร์มือถือ</div>
                                    </div>
                                </div>
                            </div>
                        </label>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="match_type" value="line_user_id" 
                                   class="peer sr-only" onchange="updateMatchTypeUI()">
                            <div class="p-4 border-2 border-gray-200 rounded-xl peer-checked:border-green-500 peer-checked:bg-green-50 transition-all">
                                <div class="flex items-center">
                                    <i class="fab fa-line text-green-500 text-xl mr-3"></i>
                                    <div>
                                        <div class="font-medium">LINE User ID</div>
                                        <div class="text-xs text-gray-500">แมทช์ด้วย Uxxxxxxxxx</div>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Column Settings -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" id="identifier_label">
                            <i class="fas fa-phone text-blue-500 mr-1" id="identifier_icon"></i>
                            <span id="identifier_text">คอลัมน์เบอร์โทรศัพท์</span> (เริ่มจาก 0)
                        </label>
                        <input type="number" name="identifier_column" value="0" min="0"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">เช่น คอลัมน์แรก = 0, คอลัมน์สอง = 1</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-star text-yellow-500 mr-1"></i>คอลัมน์แต้ม (เริ่มจาก 0)
                        </label>
                        <input type="number" name="points_column" value="1" min="0"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                </div>

                <div>
                    <label class="flex items-center cursor-pointer">
                        <input type="checkbox" name="has_header" checked
                            class="w-5 h-5 text-indigo-500 border-gray-300 rounded focus:ring-indigo-500">
                        <span class="ml-2 text-sm text-gray-700">ไฟล์มีแถวหัวตาราง (Header Row)</span>
                    </label>
                </div>

                <!-- Info Box -->
                <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl" id="info_phone">
                    <h4 class="font-medium text-blue-800 mb-2">
                        <i class="fas fa-info-circle mr-1"></i>รูปแบบเบอร์โทรที่รองรับ
                    </h4>
                    <div class="text-sm text-blue-700 space-y-1">
                        <p><code class="bg-blue-100 px-1 rounded">66859339154</code> → <code class="bg-green-100 px-1 rounded">0859339154</code></p>
                        <p><code class="bg-blue-100 px-1 rounded">+66859339154</code> → <code class="bg-green-100 px-1 rounded">0859339154</code></p>
                        <p><code class="bg-blue-100 px-1 rounded">0859339154</code> → <code class="bg-green-100 px-1 rounded">0859339154</code></p>
                    </div>
                </div>
                <div class="p-4 bg-green-50 border border-green-200 rounded-xl hidden" id="info_line">
                    <h4 class="font-medium text-green-800 mb-2">
                        <i class="fab fa-line mr-1"></i>รูปแบบ LINE User ID
                    </h4>
                    <div class="text-sm text-green-700 space-y-1">
                        <p>LINE User ID จะเป็นรูปแบบ <code class="bg-green-100 px-1 rounded">Uxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</code></p>
                        <p>เริ่มต้นด้วยตัว U และตามด้วยอักษร 32 ตัว</p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                        class="px-6 py-3 bg-gradient-to-r from-indigo-500 to-indigo-600 text-white rounded-xl hover:from-indigo-600 hover:to-indigo-700 transition-all font-medium shadow-lg shadow-indigo-500/25">
                        <i class="fas fa-search mr-2"></i>Preview ข้อมูล
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($previewData): ?>
        <!-- Preview Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div
                class="px-6 py-4 bg-gradient-to-r from-amber-500 to-amber-600 text-white flex items-center justify-between">
                <h3 class="font-semibold text-lg">
                    <i class="fas fa-eye mr-2"></i>Preview ข้อมูล
                </h3>
                <div class="flex gap-4 text-sm">
                    <span class="px-3 py-1 bg-white/20 rounded-full">
                        พบ
                        <?= count($previewData) ?> รายการ
                    </span>
                    <span class="px-3 py-1 bg-green-500 rounded-full">
                        แมทช์ได้
                        <?= count(array_filter($previewData, fn($r) => $r['matched'])) ?> รายการ
                    </span>
                    <span class="px-3 py-1 bg-red-500 rounded-full">
                        ไม่พบ
                        <?= count(array_filter($previewData, fn($r) => !$r['matched'])) ?> รายการ
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                <?= isset($_SESSION['import_match_type']) && $_SESSION['import_match_type'] === 'line_user_id' ? 'LINE User ID' : 'เบอร์เดิม' ?>
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                <?= isset($_SESSION['import_match_type']) && $_SESSION['import_match_type'] === 'line_user_id' ? 'LINE User ID' : 'เบอร์หลัง Normalize' ?>
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">แต้มที่จะเพิ่ม</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ใช้ที่พบ</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">แต้มปัจจุบัน</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach (array_slice($previewData, 0, 100) as $row): ?>
                            <tr class="<?= $row['matched'] ? 'hover:bg-green-50' : 'hover:bg-red-50' ?> transition-colors">
                                <td class="px-4 py-3 text-sm font-mono">
                                    <?= htmlspecialchars($row['original_value']) ?>
                                </td>
                                <td class="px-4 py-3 text-sm font-mono">
                                    <?= htmlspecialchars($row['normalized_value']) ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-right font-semibold text-indigo-600">+
                                    <?= number_format($row['points']) ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($row['matched']): ?>
                                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                            <i class="fas fa-check mr-1"></i>พบ
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">
                                            <i class="fas fa-times mr-1"></i>ไม่พบ
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?= $row['user_name'] ? htmlspecialchars($row['user_name']) : '-' ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-right">
                                    <?= $row['current_points'] !== null ? number_format($row['current_points']) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($previewData) > 100): ?>
                            <tr class="bg-gray-50">
                                <td colspan="6" class="px-4 py-3 text-center text-gray-500 text-sm">
                                    ... และอีก
                                    <?= count($previewData) - 100 ?> รายการ
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Import Form -->
            <form method="POST" class="p-6 bg-gray-50 border-t">
                <input type="hidden" name="action" value="import">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fab fa-line text-green-500 mr-1"></i>LINE Account
                        </label>
                        <select name="line_account_id"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            <?php foreach ($lineAccounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>">
                                    <?= htmlspecialchars($acc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-tag text-purple-500 mr-1"></i>หมายเหตุ (Description)
                        </label>
                        <input type="text" name="description" value="นำเข้าแต้มจากระบบเก่า"
                            class="w-full px-4 py-2.5 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    </div>
                </div>

                <div class="flex justify-between items-center">
                    <a href="import-legacy-points.php"
                        class="px-5 py-2.5 border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-100 transition-colors font-medium">
                        <i class="fas fa-redo mr-2"></i>อัพโหลดใหม่
                    </a>
                    <button type="submit"
                        onclick="return confirm('ยืนยันการนำเข้า <?= count(array_filter($previewData, fn($r) => $r['matched'])) ?> รายการ?')"
                        class="px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-xl hover:from-green-600 hover:to-green-700 transition-all font-medium shadow-lg shadow-green-500/25">
                        <i class="fas fa-check mr-2"></i>นำเข้า
                        <?= count(array_filter($previewData, fn($r) => $r['matched'])) ?> รายการ
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($importResults): ?>
        <!-- Results Section -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <div class="px-6 py-4 bg-gradient-to-r from-green-500 to-green-600 text-white">
                <h3 class="font-semibold text-lg">
                    <i class="fas fa-check-circle mr-2"></i>ผลการนำเข้า
                </h3>
            </div>

            <div class="p-6">
                <!-- Summary Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                    <div class="p-4 bg-green-50 border border-green-200 rounded-xl">
                        <div class="text-3xl font-bold text-green-600">
                            <?= number_format($importResults['imported']) ?>
                        </div>
                        <div class="text-sm text-green-700">นำเข้าสำเร็จ</div>
                    </div>
                    <div class="p-4 bg-gray-50 border border-gray-200 rounded-xl">
                        <div class="text-3xl font-bold text-gray-600">
                            <?= number_format($importResults['skipped']) ?>
                        </div>
                        <div class="text-sm text-gray-700">ข้ามไป (ไม่พบเบอร์)</div>
                    </div>
                </div>

                <?php if (!empty($importResults['details'])): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">เบอร์โทร</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ผู้ใช้</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">แต้มที่เพิ่ม
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach (array_slice($importResults['details'], 0, 50) as $detail): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-4 py-3 text-sm font-mono">
                                            <?= htmlspecialchars($detail['identifier']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <?= htmlspecialchars($detail['user'] ?? '-') ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right font-semibold text-green-600">+
                                            <?= number_format($detail['points']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <?php if ($detail['status'] === 'success'): ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                                    <i class="fas fa-check"></i>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">
                                                    <i class="fas fa-times"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="mt-6 flex justify-center">
                    <a href="import-legacy-points.php"
                        class="px-6 py-3 bg-gradient-to-r from-indigo-500 to-indigo-600 text-white rounded-xl hover:from-indigo-600 hover:to-indigo-700 transition-all font-medium shadow-lg shadow-indigo-500/25">
                        <i class="fas fa-plus mr-2"></i>นำเข้าเพิ่มเติม
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function updateFileName(input) {
        const fileName = input.files[0]?.name || 'คลิกเพื่อเลือกไฟล์หรือลากวาง';
        document.getElementById('file_name').textContent = fileName;
        if (input.files[0]) {
            document.getElementById('dropzone').classList.add('border-indigo-500', 'bg-indigo-50');
        }
    }

    // Drag and drop support
    const dropzone = document.getElementById('dropzone');
    if (dropzone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => {
                dropzone.classList.add('border-indigo-500', 'bg-indigo-50');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => {
                dropzone.classList.remove('border-indigo-500', 'bg-indigo-50');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('csv_file').files = files;
                updateFileName(document.getElementById('csv_file'));
            }
        });
    }

    // Match type UI switching
    function updateMatchTypeUI() {
        const matchType = document.querySelector('input[name="match_type"]:checked')?.value || 'phone';
        const identifierIcon = document.getElementById('identifier_icon');
        const identifierText = document.getElementById('identifier_text');
        const infoPhone = document.getElementById('info_phone');
        const infoLine = document.getElementById('info_line');
        
        if (matchType === 'line_user_id') {
            if (identifierIcon) {
                identifierIcon.className = 'fab fa-line text-green-500 mr-1';
            }
            if (identifierText) {
                identifierText.textContent = 'คอลัมน์ LINE User ID';
            }
            if (infoPhone) infoPhone.classList.add('hidden');
            if (infoLine) infoLine.classList.remove('hidden');
        } else {
            if (identifierIcon) {
                identifierIcon.className = 'fas fa-phone text-blue-500 mr-1';
            }
            if (identifierText) {
                identifierText.textContent = 'คอลัมน์เบอร์โทรศัพท์';
            }
            if (infoPhone) infoPhone.classList.remove('hidden');
            if (infoLine) infoLine.classList.add('hidden');
        }
    }
</script>

<?php require_once 'includes/footer.php'; ?>