<?php
/**
 * Odoo User Linking API
 * 
 * Handles LINE user to Odoo partner account linking operations.
 * 
 * Actions:
 * - link: Link LINE user to Odoo partner
 * - unlink: Unlink LINE user from Odoo partner
 * - profile: Get user profile
 * - notification: Update notification settings
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

// Debugging 500 errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ]);
        exit;
    }
});

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooAPIClient.php';

use Modules\Core\Database;

// CORS headers (if needed)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

try {
    // Get request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }

    // Validate required fields
    $action = $data['action'] ?? null;
    $lineUserId = $data['line_user_id'] ?? null;

    if (!$action) {
        throw new Exception('Missing required field: action');
    }

    if (!$lineUserId) {
        throw new Exception('Missing required field: line_user_id');
    }

    // Initialize database and API client
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Attempt to get line_account_id if we have line_user_id
    $lineAccountId = null;
    if ($lineUserId) {
        // Try to find which account this user belongs to if possible, 
        // but for now we pass null or 0 as OdooAPIClient handles null lineAccountId (shared mode)
        // However, if we want to log correctly, we might want it.
        // For shared mode, it might not matter.
    }

    $odooClient = new OdooAPIClient($pdo, $lineAccountId);

    // Route to appropriate handler
    switch ($action) {
        case 'link':
            $result = handleLink($pdo, $odooClient, $lineUserId, $data);
            break;

        case 'unlink':
            $result = handleUnlink($pdo, $odooClient, $lineUserId);
            break;

        case 'profile':
            $result = handleProfile($pdo, $odooClient, $lineUserId);
            break;

        case 'notification':
            $result = handleNotification($pdo, $lineUserId, $data);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

    echo json_encode([
        'success' => true,
        'data' => $result
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Handle user linking
 */
function handleLink($pdo, $odooClient, $lineUserId, $data)
{
    // Check if already linked
    $stmt = $pdo->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        throw new Exception('ALREADY_LINKED: บัญชี LINE นี้เชื่อมต่อกับบัญชีอื่นแล้ว');
    }

    // Extract linking parameters
    $phone = $data['phone'] ?? null;
    $customerCode = $data['customer_code'] ?? null;
    $email = $data['email'] ?? null;
    $accountId = $data['account_id'] ?? null;

    if (!$accountId) {
        throw new Exception('Missing required field: account_id');
    }

    if (!$phone && !$customerCode && !$email) {
        throw new Exception('กรุณาระบุอย่างน้อย 1 วิธี: เบอร์โทร, รหัสลูกค้า, หรืออีเมล');
    }

    // API v11.0.1.2.0: When using customer_code, phone is REQUIRED for identity verification
    if ($customerCode && !$phone) {
        throw new Exception('เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน (PHONE_REQUIRED)');
    }

    // Call Odoo API to link user (phone is always sent with customer_code)
    $result = $odooClient->linkUser($lineUserId, $phone, $customerCode, $email);

    // Check for explicit error response from Odoo result
    if (isset($result['success']) && !$result['success']) {
        $errorCode = $result['error']['code'] ?? 'UNKNOWN';
        $errorMessage = $result['error']['message'] ?? 'Unknown Odoo Error';

        // Special handling for ALREADY_LINKED with data
        if ($errorCode === 'ALREADY_LINKED' && isset($result['data']['partner_id'])) {
            // Use the data from the error response as if it were a successful result
            $result = array_merge($result['data'], [
                'success' => true,
                'message' => $errorMessage
            ]);
        } else {
            // Propagate Odoo error
            throw new Exception("$errorMessage ($errorCode)");
        }
    }

    // Validate result
    if (empty($result) || !isset($result['partner_id'])) {
        $debug = json_encode($result, JSON_UNESCAPED_UNICODE);
        throw new Exception("Odoo Error: ไม่ได้รับ partner_id จากระบบ (Response: $debug)");
    }

    // Determine linked_via
    $linkedVia = $phone ? 'phone' : ($customerCode ? 'customer_code' : 'email');

    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO odoo_line_users 
        (line_user_id, line_account_id, odoo_partner_id, odoo_partner_name, odoo_customer_code, 
         linked_via, line_notification_enabled, linked_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
    ");

    $stmt->execute([
        $lineUserId,
        $accountId,
        $result['partner_id'],
        $result['partner_name'] ?? null,
        $result['customer_code'] ?? null,
        $linkedVia
    ]);

    return [
        'partner_id' => $result['partner_id'],
        'partner_name' => $result['partner_name'] ?? null,
        'customer_code' => $result['customer_code'] ?? null,
        'linked_via' => $linkedVia,
        'message' => 'เชื่อมต่อบัญชีสำเร็จ'
    ];
}

/**
 * Handle user unlinking
 */
function handleUnlink($pdo, $odooClient, $lineUserId)
{
    // Check if linked
    $stmt = $pdo->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('NOT_LINKED: ยังไม่ได้เชื่อมต่อบัญชี Odoo');
    }

    // Call Odoo API to unlink
    $odooClient->unlinkUser($lineUserId);

    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);

    return [
        'message' => 'ยกเลิกการเชื่อมต่อบัญชีสำเร็จ'
    ];
}

/**
 * Handle get user profile
 */
function handleProfile($pdo, $odooClient, $lineUserId)
{
    // Check if linked
    $stmt = $pdo->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $localProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$localProfile) {
        throw new Exception('NOT_LINKED: ยังไม่ได้เชื่อมต่อบัญชี Odoo');
    }

    // Get profile from Odoo
    $odooProfile = $odooClient->getUserProfile($lineUserId);

    // Merge local and Odoo data
    return [
        'partner_id' => $localProfile['odoo_partner_id'],
        'partner_name' => $odooProfile['partner_name'] ?? $localProfile['odoo_partner_name'],
        'customer_code' => $odooProfile['customer_code'] ?? $localProfile['odoo_customer_code'],
        'email' => $odooProfile['email'] ?? null,
        'phone' => $odooProfile['phone'] ?? null,
        'linked_via' => $localProfile['linked_via'],
        'linked_at' => $localProfile['linked_at'],
        'notification_enabled' => (bool) $localProfile['line_notification_enabled'],
        'credit_limit' => $odooProfile['credit_limit'] ?? null,
        'credit_used' => $odooProfile['credit_used'] ?? null
    ];
}

/**
 * Handle notification settings update
 */
function handleNotification($pdo, $lineUserId, $data)
{
    $enabled = $data['enabled'] ?? null;

    if ($enabled === null) {
        throw new Exception('Missing required field: enabled');
    }

    // Check if linked
    $stmt = $pdo->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('NOT_LINKED: ยังไม่ได้เชื่อมต่อบัญชี Odoo');
    }

    // Update local database
    $stmt = $pdo->prepare("
        UPDATE odoo_line_users 
        SET line_notification_enabled = ?, updated_at = NOW()
        WHERE line_user_id = ?
    ");
    $stmt->execute([$enabled ? 1 : 0, $lineUserId]);

    return [
        'notification_enabled' => (bool) $enabled,
        'message' => $enabled ? 'เปิดการแจ้งเตือนแล้ว' : 'ปิดการแจ้งเตือนแล้ว'
    ];
}
