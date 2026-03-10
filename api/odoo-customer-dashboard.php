<?php
/**
 * Odoo Customer Dashboard API
 *
 * Unified Customer 360 endpoint for admin and LIFF consumers.
 * Returns: profile, credit, latest order, timeline, frequent products, invoices.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/OdooCustomerDashboardService.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
    } else {
        $input = $_GET;
    }

    $lineUserId = trim((string) ($input['line_user_id'] ?? ''));
    $userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;

    if ($lineUserId === '' && $userId > 0) {
        $stmt = $db->prepare('SELECT line_user_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $lineUserId = trim((string) $stmt->fetchColumn());
    }

    if ($lineUserId === '') {
        throw new Exception('Missing required parameter: line_user_id or user_id');
    }

    $lineAccountId = null;
    if (isset($input['line_account_id']) && $input['line_account_id'] !== '') {
        $lineAccountId = (int) $input['line_account_id'];
    }

    if ($lineAccountId === null) {
        // Prefer linked Odoo account.
        $stmt = $db->prepare('SELECT line_account_id FROM odoo_line_users WHERE line_user_id = ? LIMIT 1');
        $stmt->execute([$lineUserId]);
        $linkedAccount = $stmt->fetchColumn();

        if ($linkedAccount !== false && $linkedAccount !== null) {
            $lineAccountId = (int) $linkedAccount;
        }
    }

    if ($lineAccountId === null) {
        // Fallback to users table account.
        $stmt = $db->prepare('SELECT line_account_id FROM users WHERE line_user_id = ? LIMIT 1');
        $stmt->execute([$lineUserId]);
        $userAccount = $stmt->fetchColumn();

        if ($userAccount !== false && $userAccount !== null) {
            $lineAccountId = (int) $userAccount;
        }
    }

    $options = [
        'orders_limit' => isset($input['orders_limit']) ? (int) $input['orders_limit'] : 10,
        'invoices_limit' => isset($input['invoices_limit']) ? (int) $input['invoices_limit'] : 10,
        'timeline_limit' => isset($input['timeline_limit']) ? (int) $input['timeline_limit'] : 20,
        'top_products' => isset($input['top_products']) ? (int) $input['top_products'] : 5,
    ];

    $service = new OdooCustomerDashboardService($db, $lineAccountId);
    $dashboard = $service->buildByLineUserId($lineUserId, $options);

    // Hide internal warnings in normal runtime; keep only in explicit debug mode.
    if (!(defined('DEBUG_MODE') && DEBUG_MODE)) {
        $dashboard['warnings'] = [];
    }

    echo json_encode([
        'success' => true,
        'data' => $dashboard
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
