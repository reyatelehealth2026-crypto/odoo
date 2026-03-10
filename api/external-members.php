<?php
/**
 * External Members API
 * สำหรับเรียกใช้ข้อมูล member_id และ line_user_id จากภายนอก
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    jsonResponse(false, 'Database connection failed');
}

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

// Get parameters
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;
$memberIdFilter = trim($_GET['member_id'] ?? '');
$lineAccountId = isset($_GET['line_account_id']) ? (int) $_GET['line_account_id'] : null;

try {
    // Build query
    $whereConditions = ['member_id IS NOT NULL', "member_id != ''"];
    $params = [];

    // Filter by member_id (partial match)
    if ($memberIdFilter) {
        $whereConditions[] = 'member_id LIKE ?';
        $params[] = '%' . $memberIdFilter . '%';
    }

    // Filter by line_account_id
    if ($lineAccountId !== null) {
        $whereConditions[] = 'line_account_id = ?';
        $params[] = $lineAccountId;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Count total
    $countSql = "SELECT COUNT(*) FROM users WHERE {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Get data
    $sql = "SELECT member_id, line_user_id, display_name, created_at, updated_at 
            FROM users 
            WHERE {$whereClause}
            ORDER BY member_id ASC
            LIMIT ? OFFSET ?";

    $queryParams = array_merge($params, [$limit, $offset]);
    $stmt = $db->prepare($sql);
    $stmt->execute($queryParams);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $data = array_map(function ($row) {
        return [
            'member_id' => $row['member_id'],
            'line_user_id' => $row['line_user_id'],
            'display_name' => $row['display_name'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }, $members);

    jsonResponse(true, 'Success', [
        'members' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => ceil($total / $limit)
        ]
    ]);

} catch (Exception $e) {
    error_log("External Members API Error: " . $e->getMessage());
    jsonResponse(false, 'Database query failed');
}

/**
 * JSON Response helper
 */
function jsonResponse($success, $message, $data = [], $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
