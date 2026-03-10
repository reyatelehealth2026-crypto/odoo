<?php
/**
 * Customer Slips API
 * 
 * Fetches payment slip records for a specific customer (by line_user_id).
 * Used by Next.js admin inbox to display slips in customer detail page.
 * 
 * GET params:
 *   line_user_id - LINE user ID (required)
 *   limit        - Max records (default 50)
 *   offset       - Pagination offset (default 0)
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    $lineUserId = $_GET['line_user_id'] ?? null;
    if (!$lineUserId) {
        throw new Exception('Missing line_user_id');
    }

    $limit = min((int) ($_GET['limit'] ?? 50), 100);
    $offset = max((int) ($_GET['offset'] ?? 0), 0);

    // Fetch slips for this customer
    $stmt = $db->prepare("
        SELECT 
            id,
            line_account_id,
            line_user_id,
            odoo_slip_id,
            odoo_partner_id,
            bdo_id,
            invoice_id,
            order_id,
            amount,
            transfer_date,
            image_path,
            image_url,
            uploaded_by,
            message_id,
            status,
            match_reason,
            uploaded_at,
            matched_at
        FROM odoo_slip_uploads
        WHERE line_user_id = ?
        ORDER BY uploaded_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$lineUserId, $limit, $offset]);
    $slips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_slip_uploads WHERE line_user_id = ?");
    $countStmt->execute([$lineUserId]);
    $total = (int) $countStmt->fetchColumn();

    // Build full image URLs
    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : 'https://cny.re-ya.com', '/');
    foreach ($slips as &$slip) {
        $slip['id'] = (int) $slip['id'];
        $slip['amount'] = $slip['amount'] !== null ? (float) $slip['amount'] : null;
        if ($slip['image_path']) {
            $slip['image_full_url'] = $baseUrl . '/' . $slip['image_path'];
        } else {
            $slip['image_full_url'] = $slip['image_url'];
        }
    }
    unset($slip);

    echo json_encode([
        'success' => true,
        'data' => [
            'slips' => $slips,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
