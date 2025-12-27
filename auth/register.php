<?php
/**
 * Register Page - สมัครสมาชิก
 */
require_once '../config/config.php';
require_once '../config/database.php';

$error = '';

// ถ้าล็อกอินแล้วให้ redirect
if (isset($_SESSION['admin_user'])) {
    header('Location: ../admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $display_name = trim($_POST['display_name'] ?? '');
    
    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $error = 'กรุณากรอกข้อมูลให้ครบ';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'รูปแบบอีเมลไม่ถูกต้อง';
    } elseif (strlen($password) < 6) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    } elseif ($password !== $confirm_password) {
        $error = 'รหัสผ่านไม่ตรงกัน';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, 0-9 และ _';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            
            // Check if username or email exists
            $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = 'ชื่อผู้ใช้หรืออีเมลนี้ถูกใช้แล้ว';
            } else {
                // Create user
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO admin_users (username, email, password, display_name, role) VALUES (?, ?, ?, ?, 'user')");
                $stmt->execute([$username, $email, $hashedPassword, $display_name ?: $username]);
                
                header('Location: login.php?registered=1');
                exit;
            }
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - <?= APP_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Noto Sans Thai', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-green-400 to-green-600 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-green-500 to-green-600 p-6 text-center">
            <div class="w-16 h-16 bg-white rounded-2xl mx-auto flex items-center justify-center mb-3 shadow-lg">
                <i class="fab fa-line text-green-500 text-3xl"></i>
            </div>
            <h1 class="text-xl font-bold text-white">สมัครสมาชิก</h1>
            <p class="text-green-100 text-sm mt-1">สร้างบัญชีเพื่อเริ่มใช้งาน</p>
        </div>
        
        <!-- Form -->
        <div class="p-6">
            <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-600 rounded-lg text-sm flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ใช้ <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" name="username" required 
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="username"
                               pattern="[a-zA-Z0-9_]+"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                    <p class="text-xs text-gray-500 mt-1">ใช้ได้เฉพาะ a-z, 0-9 และ _</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">อีเมล <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input type="email" name="email" required 
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="email@example.com"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อที่แสดง</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-id-card"></i>
                        </span>
                        <input type="text" name="display_name" 
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="ชื่อที่ต้องการแสดง"
                               value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่าน <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="password" required minlength="6"
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="อย่างน้อย 6 ตัวอักษร">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ยืนยันรหัสผ่าน <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="confirm_password" required 
                               class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="กรอกรหัสผ่านอีกครั้ง">
                    </div>
                </div>
                
                <button type="submit" class="w-full py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold rounded-lg hover:from-green-600 hover:to-green-700 transition shadow-lg">
                    <i class="fas fa-user-plus mr-2"></i>สมัครสมาชิก
                </button>
            </form>
            
            <div class="mt-4 text-center">
                <p class="text-gray-600 text-sm">
                    มีบัญชีแล้ว? 
                    <a href="login.php" class="text-green-600 font-semibold hover:underline">เข้าสู่ระบบ</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
