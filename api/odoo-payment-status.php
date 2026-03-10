<?php
/**
 * Odoo Payment Status API
 * 
 * Handles payment status check requests from LINE users.
 * Checks payment status for orders, BDOs, or invoices.
 * 
 * Actions:
 * - check: Check payment status
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooAPIClient.php';

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
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Get request body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }

    // ========================================================================
    // 14.2.1 Handle action: `check`
    // ========================================================================
    $action = $data['action'] ?? null;
    $lineUserId = $data['line_user_id'] ?? null;

    if (!$action) {
        throw new Exception('Missing required field: action');
    }

    if (!$lineUserId) {
        throw new Exception('Missing required field: line_user_id');
    }

    // Initialize database and API client
    $db = Database::getInstance()->getConnection();
    
    // Get LINE account ID for this user
    $lineAccountId = null;
    if (isset($data['line_account_id'])) {
        $lineAccountId = $data['line_account_id'];
    } else {
        // Find LINE account for this user
        $stmt = $db->prepare("
            SELECT line_account_id 
            FROM users 
            WHERE line_user_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$lineUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $lineAccountId = $user['line_account_id'];
        } else {
            // Default to account 1 if user not found
            $lineAccountId = 1;
        }
    }
    
    $odooClient = new OdooAPIClient($db, $lineAccountId);

    // Route to appropriate handler
    switch ($action) {
        case 'check':
            $result = handleCheck($odooClient, $lineUserId, $data);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

    // ========================================================================
    // 14.2.3 Return payment status
    // ========================================================================
    echo json_encode([
        'success' => true,
        'payment_status' => $result
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Handle payment status check
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @param array $data Request data
 * @return array Payment status data
 */
function handleCheck($odooClient, $lineUserId, $data)
{
    // Extract optional parameters
    $orderId = $data['order_id'] ?? null;
    $bdoId = $data['bdo_id'] ?? null;
    $invoiceId = $data['invoice_id'] ?? null;

    // ========================================================================
    // 14.2.2 เรียก `getPaymentStatus()`
    // ========================================================================
    $result = $odooClient->getPaymentStatus(
        $lineUserId,
        $orderId,
        $bdoId,
        $invoiceId
    );

    return $result;
}
