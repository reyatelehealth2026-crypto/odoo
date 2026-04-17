<?php
/**
 * Member Notifications Preferences API
 *
 * Actions (POST JSON or form-urlencoded):
 *   action=opt_in   — subscribe LINE user to OA push/service messages
 *   action=opt_out  — unsubscribe
 *   action=status   — (GET or POST) query current preference
 *
 * Payload: { line_user_id, line_account_id, action }
 * Response: { success: bool, message: string, enabled?: bool }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

function jsonFail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Parse input: JSON body first, then GET/POST form
$raw = file_get_contents('php://input') ?: '';
$input = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
$input = array_merge($_GET, $_POST, $input);

$action = $input['action'] ?? '';
$lineUserId = trim((string) ($input['line_user_id'] ?? ''));
$lineAccountId = isset($input['line_account_id']) ? (int) $input['line_account_id'] : 0;

if ($lineUserId === '') {
    jsonFail('Missing line_user_id');
}
if ($lineAccountId <= 0) {
    jsonFail('Missing or invalid line_account_id');
}
if (!in_array($action, ['opt_in', 'opt_out', 'status'], true)) {
    jsonFail('Invalid action');
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    jsonFail('Database connection failed', 500);
}

// Ensure table exists (idempotent)
try {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `member_notification_preferences` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_user_id` VARCHAR(64) NOT NULL,
            `line_account_id` INT NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_account` (`line_user_id`, `line_account_id`),
            INDEX `idx_account` (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Exception $e) {
    jsonFail('Schema init failed: ' . $e->getMessage(), 500);
}

try {
    if ($action === 'status') {
        $stmt = $db->prepare(
            'SELECT enabled FROM member_notification_preferences WHERE line_user_id = ? AND line_account_id = ? LIMIT 1'
        );
        $stmt->execute([$lineUserId, $lineAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'enabled' => $row ? (bool) $row['enabled'] : true,
            'message' => 'ok',
        ]);
        exit;
    }

    $enabled = $action === 'opt_in' ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO member_notification_preferences (line_user_id, line_account_id, enabled)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
    );
    $stmt->execute([$lineUserId, $lineAccountId, $enabled]);

    echo json_encode([
        'success' => true,
        'enabled' => (bool) $enabled,
        'message' => $enabled ? 'เปิดรับการแจ้งเตือนแล้ว' : 'ปิดรับการแจ้งเตือนแล้ว',
    ]);
} catch (Exception $e) {
    jsonFail('Operation failed: ' . $e->getMessage(), 500);
}
