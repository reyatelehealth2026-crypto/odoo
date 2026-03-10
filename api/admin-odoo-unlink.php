<?php
/**
 * Admin: Odoo-LINE Unlink API
 *
 * Requires admin session. Removes the odoo_line_users record for a given
 * line_user_id and attempts a best-effort call to the Odoo API.
 *
 * POST /api/admin-odoo-unlink.php
 * Body: { "line_user_id": "Uxxxx" }
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Fatal: ' . $error['message']]);
        exit;
    }
});

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require admin session
if (empty($_SESSION['admin_user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['admin_user']['role'] ?? '';
if (!in_array($role, ['super_admin', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden: admin role required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON');
    }

    $lineUserId = trim((string) ($data['line_user_id'] ?? ''));
    if ($lineUserId === '') {
        throw new Exception('Missing line_user_id');
    }

    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../classes/Database.php';
    require_once __DIR__ . '/../classes/OdooAPIClient.php';

    use Modules\Core\Database;

    $pdo = Database::getInstance()->getConnection();

    // Verify the link exists
    $stmt = $pdo->prepare("SELECT * FROM odoo_line_users WHERE line_user_id = ? LIMIT 1");
    $stmt->execute([$lineUserId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        throw new Exception('ไม่พบการเชื่อมต่อ Odoo สำหรับผู้ใช้นี้');
    }

    // Delete from DB first (source of truth)
    $stmt = $pdo->prepare("DELETE FROM odoo_line_users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $deleted = $stmt->rowCount();

    // Also clear projection caches for this user (best-effort)
    try {
        $pdo->prepare("DELETE FROM odoo_customer_projection WHERE line_user_id = ?")->execute([$lineUserId]);
    } catch (Exception $e) {
        error_log('admin-odoo-unlink: projection cleanup error: ' . $e->getMessage());
    }

    // Best-effort: notify Odoo API (do not fail if Odoo is unreachable)
    $odooApiError = null;
    try {
        $odooClient = new OdooAPIClient($pdo, $existing['line_account_id'] ?? null);
        $odooClient->unlinkUser($lineUserId);
    } catch (Exception $e) {
        $odooApiError = $e->getMessage();
        error_log('admin-odoo-unlink: Odoo API unlink failed (non-fatal): ' . $e->getMessage());
    }

    // Log the admin action
    $adminId   = $_SESSION['admin_user']['id'] ?? null;
    $adminName = $_SESSION['admin_user']['name'] ?? $_SESSION['admin_user']['username'] ?? 'admin';
    error_log(sprintf(
        'admin-odoo-unlink: admin=%s(%s) unlinked line_user_id=%s odoo_partner_id=%s',
        $adminName,
        $adminId,
        $lineUserId,
        $existing['odoo_partner_id'] ?? '-'
    ));

    echo json_encode([
        'success'        => true,
        'message'        => 'ยกเลิกการเชื่อมต่อ Odoo สำเร็จ',
        'deleted_rows'   => $deleted,
        'odoo_api_error' => $odooApiError,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
