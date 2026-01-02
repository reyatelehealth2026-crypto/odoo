<?php
/**
 * Admin Login Page - Modern Split Screen Design
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
    <title>เข้าสู่ระบบ - Re-ya Pharmacy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', 'Noto Sans Thai', sans-serif; }
        
        .login-bg {
            background: linear-gradient(135deg, #4a5568 0%, #2d3748 50%, #1a202c 100%);
            position: relative;
            overflow: hidden;
        }
        
        .login-bg::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                linear-gradient(135deg, transparent 40%, rgba(74, 85, 104, 0.3) 40%, rgba(74, 85, 104, 0.3) 60%, transparent 60%),
                linear-gradient(225deg, transparent 40%, rgba(45, 55, 72, 0.3) 40%, rgba(45, 55, 72, 0.3) 60%, transparent 60%);
            pointer-events: none;
        }
        
        .divider-line {
            width: 2px;
            height: 60%;
            background: rgba(255, 255, 255, 0.3);
        }
        
        .input-field {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            padding-right: 40px;
            width: 100%;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #d4a574;
            box-shadow: 0 0 0 3px rgba(212, 165, 116, 0.1);
        }
        
        .input-field::placeholder {
            color: #a0aec0;
        }
        
        .input-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
        }
        
        .login-btn {
            background: #d4a574;
            color: #1a202c;
            font-weight: 600;
            padding: 12px 32px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .login-btn:hover {
            background: #c9956a;
            transform: translateY(-1px);
        }
        
        .lock-icon {
            width: 50px;
            height: 50px;
            background: #d4a574;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-pin {
            width: 60px;
            height: 80px;
            background: #d4a574;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .logo-pin::after {
            content: '';
            position: absolute;
            bottom: -10px;
            width: 40px;
            height: 10px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 50%;
        }
    </style>
</head>
<body class="bg-[#f5e6d3] min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-5xl">
        <!-- Main Card -->
        <div class="bg-white rounded-3xl shadow-2xl overflow-hidden">
            <div class="login-bg flex flex-col md:flex-row min-h-[500px]">
                
                <!-- Left Side - Logo -->
                <div class="flex-1 flex flex-col items-center justify-center p-8 md:p-12 relative">
                    <h2 class="text-white text-lg font-medium tracking-wider mb-8">RE-YA PHARMACY</h2>
                    
                    <!-- Logo Pin Icon -->
                    <div class="logo-pin mb-4">
                        <i class="fas fa-map-marker-alt text-2xl text-gray-700"></i>
                    </div>
                    
                    <p class="text-gray-400 text-sm mt-8">ระบบจัดการร้านขายยา</p>
                </div>
                
                <!-- Divider -->
                <div class="hidden md:flex items-center">
                    <div class="divider-line"></div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="flex-1 flex flex-col items-center justify-center p-8 md:p-12">
                    <!-- Lock Icon -->
                    <div class="lock-icon mb-4">
                        <i class="fas fa-lock text-xl text-gray-700"></i>
                    </div>
                    
                    <h3 class="text-white text-lg font-medium tracking-wider mb-8">USER LOGIN</h3>
                    
                    <!-- Error Message -->
                    <?php if ($error): ?>
                    <div class="w-full max-w-xs mb-4 p-3 bg-red-500/20 border border-red-500/50 text-red-200 rounded-lg text-sm text-center">
                        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Login Form -->
                    <form method="POST" class="w-full max-w-xs space-y-4">
                        <div class="relative">
                            <input type="text" name="username" required autofocus
                                   class="input-field"
                                   placeholder="email address"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                            <i class="fas fa-envelope input-icon"></i>
                        </div>
                        
                        <div class="relative">
                            <input type="password" name="password" required
                                   class="input-field"
                                   placeholder="password">
                            <i class="fas fa-lock input-icon"></i>
                        </div>
                        
                        <!-- Remember Me -->
                        <div class="flex items-center">
                            <input type="checkbox" id="remember" name="remember" 
                                   class="w-4 h-4 rounded border-gray-300 text-[#d4a574] focus:ring-[#d4a574]">
                            <label for="remember" class="ml-2 text-sm text-gray-400">Remember me</label>
                        </div>
                        
                        <!-- Login Button -->
                        <button type="submit" class="login-btn w-full">
                            LOGIN
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-gray-500 text-sm mt-6">
            Re-ya Pharmacy Management System
        </p>
    </div>
</body>
</html>
