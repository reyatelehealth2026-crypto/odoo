<?php
/**
 * Slips List API
 * 
 * Returns paginated list of all slip uploads, auto-joined with users table
 * to show customer name. Used by odoo-dashboard.php.
 * 
 * GET params:
 *   limit   - Max records (default 30)
 *   offset  - Pagination offset (default 0)
 *   search  - Search by customer display_name or line_user_id
 *   status  - Filter by status (pending|matched|failed)
 *   date    - Filter by upload date (YYYY-MM-DD)
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

    $limit  = min((int) ($_GET['limit']  ?? 30), 100);
    $offset = max((int) ($_GET['offset'] ?? 0),  0);
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $date   = trim($_GET['date']   ?? '');

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]  = '(u.display_name LIKE ? OR s.line_user_id LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if ($status !== '') {
        $where[]  = 's.status = ?';
        $params[] = $status;
    }

    if ($date !== '') {
        $where[]  = 'DATE(s.uploaded_at) = ?';
        $params[] = $date;
    }

    $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // Count
    $countSql = "
        SELECT COUNT(*) 
        FROM odoo_slip_uploads s
        LEFT JOIN users u ON u.line_user_id = s.line_user_id
        $whereClause
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Data
    $dataSql = "
        SELECT 
            s.id,
            s.line_user_id,
            s.line_account_id,
            s.odoo_slip_id,
            s.bdo_id,
            s.invoice_id,
            s.order_id,
            s.amount,
            s.transfer_date,
            s.image_path,
            s.image_url,
            s.uploaded_by,
            s.message_id,
            s.status,
            s.match_reason,
            s.uploaded_at,
            s.matched_at,
            u.display_name AS customer_name,
            u.picture_url  AS customer_avatar
        FROM odoo_slip_uploads s
        LEFT JOIN users u ON u.line_user_id = s.line_user_id
        $whereClause
        ORDER BY s.uploaded_at DESC
        LIMIT ? OFFSET ?
    ";
    $dataParams = array_merge($params, [$limit, $offset]);
    $dataStmt = $db->prepare($dataSql);
    $dataStmt->execute($dataParams);
    $slips = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build full image URLs
    $baseUrl = rtrim(defined('SITE_URL') ? SITE_URL : 'https://cny.re-ya.com', '/');
    foreach ($slips as &$slip) {
        $slip['id']     = (int) $slip['id'];
        $slip['amount'] = $slip['amount'] !== null ? (float) $slip['amount'] : null;

        if ($slip['image_path']) {
            $slip['image_full_url'] = $baseUrl . '/' . ltrim($slip['image_path'], '/');
        } else {
            $slip['image_full_url'] = $slip['image_url'] ?: null;
        }
    }
    unset($slip);

    echo json_encode([
        'success' => true,
        'data'    => [
            'slips'  => $slips,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
