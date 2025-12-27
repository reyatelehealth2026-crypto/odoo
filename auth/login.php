<?php
/**
 * Admin Login Page
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AdminAuth.php';

$db = Database::getInstance()->getConnection();
$auth = new AdminAuth($db);

// Already logged in - redirect to dashboard
if (isset($_SESSION['admin_user']) && !empty($_SESSION['admin_user']['id'])) {
    header('Location: ../admin/');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            header('Location: ../admin/');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - LINE CRM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-green-400 to-green-600 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-green-500 to-green-600 p-8 text-center">
            <div class="w-20 h-20 bg-white rounded-full mx-auto flex items-center justify-center mb-4">
                <i class="fab fa-line text-5xl text-green-500"></i>
            </div>
            <h1 class="text-2xl font-bold text-white">LINE CRM</h1>
            <p class="text-green-100 text-sm mt-1">ระบบจัดการลูกค้า</p>
        </div>
        
        <!-- Form -->
        <div class="p-8">
            <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-700 rounded-lg text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อผู้ใช้</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" name="username" required autofocus
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                               placeholder="ชื่อผู้ใช้"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">รหัสผ่าน</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="password" required
                               class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent"
                               placeholder="รหัสผ่าน">
                    </div>
                </div>
                
                <button type="submit" class="w-full py-3 bg-green-500 text-white rounded-lg font-medium hover:bg-green-600 transition flex items-center justify-center">
                    <i class="fas fa-sign-in-alt mr-2"></i>เข้าสู่ระบบ
                </button>
            </form>
            
            <p class="text-center text-gray-500 text-xs mt-6">
                Default: admin / password
            </p>
        </div>
    </div>
</body>
</html>
