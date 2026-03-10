<?php
/**
 * Odoo Orders API
 * 
 * Handles order-related requests from LINE users.
 * Provides order list, detail, tracking, and search functionality.
 * 
 * Actions:
 * - list: Get orders list with filters
 * - detail: Get order detail
 * - tracking: Get order tracking timeline
 * - search: Search orders
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
            $result = handleList($db, $odooClient, $lineUserId, $data);
            break;

        case 'detail':
            $result = handleDetail($odooClient, $lineUserId, $data);
            break;

        case 'tracking':
            $result = handleTracking($odooClient, $lineUserId, $data);
            break;

        case 'search':
            $result = handleSearch($odooClient, $lineUserId, $data);
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
 * Handle orders list request
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @param array $data Request data
 * @return array Orders list with pagination
 */
function handleList($db, $odooClient, $lineUserId, $data)
{
    // Extract filter options
    $options = [];

    // State filter (e.g., 'sale', 'done', 'cancel')
    if (isset($data['state'])) {
        $options['state'] = $data['state'];
    }

    // Date range filter
    if (isset($data['date_from'])) {
        $options['date_from'] = $data['date_from'];
    }

    if (isset($data['date_to'])) {
        $options['date_to'] = $data['date_to'];
    }

    // Pagination
    if (isset($data['limit'])) {
        $options['limit'] = (int) $data['limit'];
    }

    if (isset($data['offset'])) {
        $options['offset'] = (int) $data['offset'];
    }

    // Also pass partner_id from local DB in case Odoo needs it
    $stmt = $db->prepare("SELECT odoo_partner_id FROM odoo_line_users WHERE line_user_id = ? LIMIT 1");
    $stmt->execute([$lineUserId]);
    $linked = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($linked && !empty($linked['odoo_partner_id'])) {
        $options['partner_id'] = (int) $linked['odoo_partner_id'];
    }

    error_log("odoo-orders handleList: line_user_id=$lineUserId, partner_id=" . ($linked['odoo_partner_id'] ?? 'null') . ", options=" . json_encode($options));

    // Call Odoo API
    $result = $odooClient->getOrders($lineUserId, $options);

    error_log('odoo-orders handleList raw result type: ' . gettype($result));
    error_log('odoo-orders handleList raw keys: ' . (is_array($result) ? json_encode(array_keys($result)) : 'N/A'));
    error_log('odoo-orders handleList raw result: ' . substr(json_encode($result, JSON_UNESCAPED_UNICODE), 0, 500));

    // Normalize: Odoo API returns {success, data: {orders:[...]}, meta:{total:N}}
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
        // Flat array
        if (isset($result[0])) {
            return ['orders' => $result, 'total' => count($result)];
        }
    }

    return [
        'orders' => [],
        'total' => 0,
        'raw_keys' => is_array($result) ? array_keys($result) : [],
        'debug' => 'Unrecognized orders response structure'
    ];
}

/**
 * Handle order detail request
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @param array $data Request data
 * @return array Order details
 */
function handleDetail($odooClient, $lineUserId, $data)
{
    // Validate required parameter
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
        throw new Exception('Missing required field: order_id');
    }

    // Call Odoo API
    $result = $odooClient->getOrderDetail($orderId, $lineUserId);

    return $result;
}

/**
 * Handle order tracking request
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @param array $data Request data
 * @return array Order tracking timeline
 */
function handleTracking($odooClient, $lineUserId, $data)
{
    // Validate required parameter
    $orderId = $data['order_id'] ?? null;

    if (!$orderId) {
        throw new Exception('Missing required field: order_id');
    }

    // Call Odoo API
    $result = $odooClient->getOrderTracking($orderId, $lineUserId);

    return $result;
}

/**
 * Handle order search request
 * 
 * @param OdooAPIClient $odooClient Odoo API client instance
 * @param string $lineUserId LINE user ID
 * @param array $data Request data
 * @return array Search results
 */
function handleSearch($odooClient, $lineUserId, $data)
{
    // Extract search parameters
    $options = [];

    // Search query (order name, reference, etc.)
    if (isset($data['query'])) {
        $options['query'] = $data['query'];
    }

    // State filter
    if (isset($data['state'])) {
        $options['state'] = $data['state'];
    }

    // Date range filter
    if (isset($data['date_from'])) {
        $options['date_from'] = $data['date_from'];
    }

    if (isset($data['date_to'])) {
        $options['date_to'] = $data['date_to'];
    }

    // Pagination
    if (isset($data['limit'])) {
        $options['limit'] = (int) $data['limit'];
    }

    if (isset($data['offset'])) {
        $options['offset'] = (int) $data['offset'];
    }

    // Call Odoo API (using getOrders with search query)
    $result = $odooClient->getOrders($lineUserId, $options);

    return $result;
}
