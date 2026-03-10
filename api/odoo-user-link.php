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

        case 'orders':
            $result = handleOrders($pdo, $odooClient, $lineUserId, $data);
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

    // Extract customer_code and phone from Odoo response with fallbacks
    $resCustomerCode = $result['customer_code'] ?? $result['ref'] ?? $customerCode ?? null;
    $resPhone = $result['phone'] ?? $result['mobile'] ?? $phone ?? null;
    $resEmail = $result['email'] ?? $email ?? null;

    // Save to database (include phone and email for local fallback)
    $stmt = $pdo->prepare("
        INSERT INTO odoo_line_users 
        (line_user_id, line_account_id, odoo_partner_id, odoo_partner_name, odoo_customer_code, 
         linked_via, line_notification_enabled, linked_at, odoo_phone, odoo_email)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?, ?)
    ");

    try {
        $stmt->execute([
            $lineUserId,
            $accountId,
            $result['partner_id'],
            $result['partner_name'] ?? $result['name'] ?? null,
            $resCustomerCode,
            $linkedVia,
            $resPhone,
            $resEmail
        ]);
    } catch (PDOException $e) {
        // If odoo_phone/odoo_email columns don't exist yet, fallback to original insert
        if (strpos($e->getMessage(), 'odoo_phone') !== false || strpos($e->getMessage(), 'odoo_email') !== false) {
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
                $result['partner_name'] ?? $result['name'] ?? null,
                $resCustomerCode,
                $linkedVia
            ]);
        } else {
            throw $e;
        }
    }

    return [
        'partner_id' => $result['partner_id'],
        'partner_name' => $result['partner_name'] ?? $result['name'] ?? null,
        'customer_code' => $resCustomerCode,
        'phone' => $resPhone,
        'email' => $resEmail,
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

    // Delete from database first (source of truth)
    $stmt = $pdo->prepare("DELETE FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);

    // Clear projection cache (best-effort)
    try {
        $pdo->prepare("UPDATE odoo_customer_projection SET line_user_id = NULL WHERE line_user_id = ?")->execute([$lineUserId]);
    } catch (Exception $e) {
        error_log('handleUnlink: projection clear error: ' . $e->getMessage());
    }

    // Best-effort: notify Odoo API
    try {
        $odooClient->unlinkUser($lineUserId);
    } catch (Exception $e) {
        error_log('handleUnlink: Odoo API error (non-fatal): ' . $e->getMessage());
    }

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
    $odooProfile = [];
    try {
        $odooProfile = $odooClient->getUserProfile($lineUserId);
        if (!is_array($odooProfile)) $odooProfile = [];
        // Debug: log Odoo response keys to identify field names
        error_log('Odoo getUserProfile keys: ' . json_encode(array_keys($odooProfile)));
        error_log('Odoo getUserProfile data: ' . json_encode($odooProfile, JSON_UNESCAPED_UNICODE));
    } catch (Exception $e) {
        // If Odoo is unreachable, use local data only
        error_log('getUserProfile failed: ' . $e->getMessage());
    }

    // Local DB fallbacks
    $localPhone = $localProfile['odoo_phone'] ?? null;
    $localEmail = $localProfile['odoo_email'] ?? null;

    // Merge local and Odoo data with robust field mapping
    return [
        'partner_id' => $localProfile['odoo_partner_id'],
        'partner_name' => $odooProfile['partner_name'] ?? $odooProfile['name'] ?? $localProfile['odoo_partner_name'],
        'customer_code' => $odooProfile['customer_code'] ?? $odooProfile['ref'] ?? $localProfile['odoo_customer_code'] ?? null,
        'email' => $odooProfile['email'] ?? $localEmail ?? null,
        'phone' => $odooProfile['phone'] ?? $odooProfile['mobile'] ?? $localPhone ?? null,
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

/**
 * Handle fetching orders from Odoo
 */
function handleOrders($pdo, $odooClient, $lineUserId, $data)
{
    // Check if linked
    $stmt = $pdo->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('NOT_LINKED: ยังไม่ได้เชื่อมต่อบัญชี Odoo');
    }

    $limit = (int) ($data['limit'] ?? 10);
    $offset = (int) ($data['offset'] ?? 0);
    $state = $data['state'] ?? null;

    $options = ['limit' => $limit, 'offset' => $offset];
    if ($state) {
        $options['state'] = $state;
    }
    // Also pass partner_id from local DB in case Odoo needs it
    if (!empty($existing['odoo_partner_id'])) {
        $options['partner_id'] = (int) $existing['odoo_partner_id'];
    }

    error_log("handleOrders: line_user_id=$lineUserId, partner_id=" . ($existing['odoo_partner_id'] ?? 'null') . ", options=" . json_encode($options));

    $result = $odooClient->getOrders($lineUserId, $options);

    error_log('handleOrders raw result keys: ' . (is_array($result) ? json_encode(array_keys($result)) : gettype($result)));
    error_log('handleOrders raw result: ' . substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, 500));

    // Normalize: Odoo API may return {success, data: {orders:[...]}, meta:{total,...}}
    // We need to unwrap and always return { orders: [...], total: N }
    if (is_array($result)) {
        // Odoo returns {success:true, data:{orders:[...]}, meta:{total:N}}
        if (isset($result['data']) && is_array($result['data']) && isset($result['data']['orders'])) {
            return [
                'orders' => $result['data']['orders'],
                'total' => $result['meta']['total'] ?? count($result['data']['orders'])
            ];
        }
        // Direct {orders:[...]}
        if (isset($result['orders']) && is_array($result['orders'])) {
            return [
                'orders' => $result['orders'],
                'total' => $result['total'] ?? $result['meta']['total'] ?? count($result['orders'])
            ];
        }
        // {result: {orders:[...]}} or {result: [...]}
        if (isset($result['result']) && is_array($result['result'])) {
            $inner = $result['result'];
            if (isset($inner['orders']) && is_array($inner['orders'])) {
                return ['orders' => $inner['orders'], 'total' => $inner['total'] ?? count($inner['orders'])];
            }
            if (isset($inner[0])) {
                return ['orders' => $inner, 'total' => count($inner)];
            }
        }
        // Flat array of orders
        if (isset($result[0])) {
            return ['orders' => $result, 'total' => count($result)];
        }
    }

    return [
        'orders' => [],
        'total' => 0,
        'raw_keys' => is_array($result) ? array_keys($result) : [],
        'debug' => 'Unrecognized response structure'
    ];
}
