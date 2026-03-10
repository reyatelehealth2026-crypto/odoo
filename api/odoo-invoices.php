<?php
/**
 * Odoo Invoices API
 * 
 * Handles invoice-related requests from LINE users.
 * Provides invoice list and credit status functionality.
 * 
 * Actions:
 * - list: Get invoices list with filters
 * - credit_status: Get credit status
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

    // Required parameters
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
        case 'list':
            $result = handleList($odooClient, $lineUserId, $data);
            break;

        case 'credit_status':
            $result = handleCreditStatus($odooClient, $lineUserId);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }

    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $result
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ============================================================================
// Action Handlers
// ============================================================================

/**
 * Handle invoices list request
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @param array $data Request data
 * @return array Invoices list with pagination
 */
function handleList($odooClient, $lineUserId, $data)
{
    // Extract filter options
    $options = [];

    // State filter (e.g., 'draft', 'posted', 'paid')
    if (isset($data['state'])) {
        $options['state'] = $data['state'];
    }

    // Pagination
    if (isset($data['limit'])) {
        $options['limit'] = (int) $data['limit'];
    }

    if (isset($data['offset'])) {
        $options['offset'] = (int) $data['offset'];
    }

    // Call Odoo API
    $result = $odooClient->getInvoices($lineUserId, $options);

    return $result;
}

/**
 * Handle credit status request
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @return array Credit status data
 */
function handleCreditStatus($odooClient, $lineUserId)
{
    // Call Odoo API
    $result = $odooClient->getCreditStatus($lineUserId);

    return $result;
}
