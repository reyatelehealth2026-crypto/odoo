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

    // Fetch slips for this customer — includes all fields needed by inboxreya BDO Confirm UI
    $stmt = $db->prepare("
        SELECT 
            id,
            line_account_id,
            line_user_id,
            odoo_slip_id,
            slip_inbox_id,
            slip_inbox_name,
            odoo_partner_id,
            bdo_id,
            bdo_name,
            invoice_id,
            order_id,
            amount,
            bdo_amount,
            transfer_date,
            image_path,
            image_url,
            uploaded_by,
            message_id,
            status,
            match_confidence,
            match_reason,
            delivery_type,
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

    // Build full image URLs and normalize types
    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : (defined('BASE_URL') ? BASE_URL : 'https://cny.re-ya.com'), '/');
    foreach ($slips as &$slip) {
        $slip['id']            = (int) $slip['id'];
        $slip['slip_inbox_id'] = $slip['slip_inbox_id'] !== null ? (int) $slip['slip_inbox_id'] : null;
        $slip['odoo_slip_id']  = $slip['odoo_slip_id']  !== null ? (int) $slip['odoo_slip_id']  : null;
        $slip['bdo_id']        = $slip['bdo_id']        !== null ? (int) $slip['bdo_id']        : null;
        $slip['amount']        = $slip['amount']        !== null ? (float) $slip['amount']      : null;
        $slip['bdo_amount']    = $slip['bdo_amount']    !== null ? (float) $slip['bdo_amount']  : null;
        // Canonical slip ID for Odoo operations (prefer slip_inbox_id over odoo_slip_id over local id)
        $slip['canonical_slip_id'] = $slip['slip_inbox_id'] ?? $slip['odoo_slip_id'] ?? $slip['id'];
        if ($slip['image_path']) {
            $slip['image_full_url'] = $baseUrl . '/' . ltrim($slip['image_path'], '/');
        } else {
            $slip['image_full_url'] = $slip['image_url'] ?: null;
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
