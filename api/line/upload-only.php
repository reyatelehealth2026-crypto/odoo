<?php
/**
 * LINE Upload Only API
 * 
 * Endpoint สำหรับอัปโหลดไฟล์ไปยัง server เท่านั้น (ไม่ส่งไป LINE)
 * ใช้โดย Next.js app เพื่ออัปโหลดไฟล์และได้ public URL
 * 
 * POST /api/line/upload-only.php
 * 
 * FormData:
 * - file: ไฟล์ที่ต้องการอัปโหลด
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
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load dependencies: ' . $e->getMessage()]);
    exit;
}

try {
    // Validate input
    $type = $_POST['type'] ?? '';
    
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
    
    // Validate image type if it's an image
    if ($type === 'image') {
        $allowedImageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($fileType, $allowedImageTypes)) {
            throw new Exception('Invalid image type. Allowed: JPG, PNG, GIF, WEBP');
        }
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
    
    // Return success with media URL
    echo json_encode([
        'success' => true,
        'data' => [
            'mediaUrl' => $mediaUrl,
            'filename' => $filename,
        ]
    ]);
    
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
