<?php
/**
 * LINE Send Media API
 * 
 * Endpoint สำหรับส่งรูปภาพหรือไฟล์ไปยัง LINE user
 * ใช้โดย Next.js app ผ่าน PHP Bridge
 * 
 * POST /api/line/send-media.php
 * 
 * FormData:
 * - file: ไฟล์ที่ต้องการส่ง
 * - user_id: LINE User ID (ไม่ใช่ internal user ID)
 * - type: 'image' หรือ 'file'
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Internal-Request');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load dependencies
try {
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../classes/LineAPI.php';
    require_once __DIR__ . '/../../classes/LineAccountManager.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load dependencies: ' . $e->getMessage()]);
    exit;
}

// Database connection
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    // Validate input
    $lineUserId = $_POST['user_id'] ?? '';
    $type = $_POST['type'] ?? '';
    
    if (empty($lineUserId)) {
        throw new Exception('user_id is required');
    }
    
    if (empty($type) || !in_array($type, ['image', 'file'])) {
        throw new Exception('type must be "image" or "file"');
    }
    
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    
    $file = $_FILES['file'];
    $fileSize = $file['size'];
    $fileType = $file['type'];
    $fileName = $file['name'];
    $tmpName = $file['tmp_name'];
    
    // Validate file size (max 10MB for images, 20MB for files)
    $maxSize = ($type === 'image') ? 10 * 1024 * 1024 : 20 * 1024 * 1024;
    if ($fileSize > $maxSize) {
        throw new Exception("File too large. Max size: " . round($maxSize / 1024 / 1024) . "MB");
    }
    
    // Get user info to find line_account_id
    $stmt = $db->prepare("SELECT id, line_account_id FROM users WHERE line_user_id = ? LIMIT 1");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found');
    }
    
    $lineAccountId = $user['line_account_id'] ?? null;
    if (!$lineAccountId) {
        throw new Exception('User has no LINE account');
    }
    
    // Prepare upload directory
    $uploadDir = __DIR__ . '/../../uploads/chat_images/';
    if ($type === 'file') {
        $uploadDir = __DIR__ . '/../../uploads/chat_files/';
    }
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $ext = pathinfo($fileName, PATHINFO_EXTENSION) ?: ($type === 'image' ? 'jpg' : 'bin');
    $filename = 'chat_' . time() . '_' . uniqid() . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($tmpName, $filepath)) {
        throw new Exception('Failed to save file');
    }
    
    // Generate public URL (same pattern as inbox-v2.php)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $mediaUrl = $protocol . $host . '/uploads/' . ($type === 'image' ? 'chat_images' : 'chat_files') . '/' . $filename;
    
    // Get LINE API instance
    $lineManager = new LineAccountManager($db);
    $line = $lineManager->getLineAPI($lineAccountId);
    
    // Send via LINE API
    if ($type === 'image') {
        // Validate image type
        $allowedImageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($fileType, $allowedImageTypes)) {
            @unlink($filepath);
            throw new Exception('Invalid image type. Allowed: JPG, PNG, GIF, WEBP');
        }
        
        // Send image message
        $result = $line->pushMessage($lineUserId, [
            [
                'type' => 'image',
                'originalContentUrl' => $mediaUrl,
                'previewImageUrl' => $mediaUrl
            ]
        ]);
    } else {
        // For files, LINE API requires a publicly accessible HTTPS URL
        // Check if URL is HTTPS
        if (strpos($mediaUrl, 'https://') !== 0) {
            @unlink($filepath);
            throw new Exception('File URL must be HTTPS. Please configure HTTPS for file uploads.');
        }
        
        // Send file message
        $result = $line->pushMessage($lineUserId, [
            [
                'type' => 'file',
                'fileName' => $fileName,
                'fileUrl' => $mediaUrl
            ]
        ]);
    }
    
    // Check result
    if ($result['code'] === 200) {
        // Success - return response
        echo json_encode([
            'success' => true,
            'data' => [
                'mediaUrl' => $mediaUrl,
                'messageId' => null, // LINE API doesn't return message ID for push messages
            ]
        ]);
    } else {
        // Failed - delete uploaded file and return error
        @unlink($filepath);
        $errorMsg = $result['body']['message'] ?? 'Failed to send media via LINE API';
        throw new Exception('LINE API error: ' . $errorMsg);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
