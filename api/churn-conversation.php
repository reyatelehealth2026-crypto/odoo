<?php
/**
 * api/churn-conversation.php — Returns recent two-way conversation between
 * a customer and the team for an admin-only conversation viewer.
 *
 * POST /api/churn-conversation.php  (Content-Type: application/json)
 * Body: { "partner_id": int, "days": int? (default 30) }
 *
 * Auth: requires session admin user with role super_admin / admin / sales /
 *       pharmacist (matches churn-talking-points.php).
 *
 * Read-only: SELECTs from messages, users, odoo_customers_cache,
 *            odoo_customer_projection, customer_rfm_profile.
 *            Writes one audit row to dev_logs per request.
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md (cross-reference layer)
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth_check.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** @param array<string, mixed> $payload */
function convoRespond(int $httpCode, array $payload): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    convoRespond(405, ['success' => false, 'data' => null, 'error' => 'Use POST']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    convoRespond(400, ['success' => false, 'data' => null, 'error' => 'Content-Type must be application/json']);
}

if (!isset($_SESSION['admin_user'])) {
    convoRespond(401, ['success' => false, 'data' => null, 'error' => 'Authentication required']);
}

/** @var array<string, mixed> $currentUser */
$userRole     = (string) ($currentUser['role'] ?? '');
$allowedRoles = ['super_admin', 'admin', 'sales', 'pharmacist'];
if (!in_array($userRole, $allowedRoles, true)) {
    convoRespond(403, ['success' => false, 'data' => null, 'error' => 'Permission denied']);
}

// ── Parse + validate body ────────────────────────────────────────────────
$rawBody = file_get_contents('php://input') ?: '';
$body    = json_decode($rawBody, true);
if (!is_array($body)) {
    convoRespond(400, ['success' => false, 'data' => null, 'error' => 'Invalid JSON body']);
}

$partnerId = (int) ($body['partner_id'] ?? 0);
$days      = (int) ($body['days'] ?? 30);
if ($partnerId <= 0) {
    convoRespond(400, ['success' => false, 'data' => null, 'error' => 'partner_id required']);
}
$days = max(1, min(180, $days));

// ── Load conversation ────────────────────────────────────────────────────
try {
    $db = Database::getInstance()->getConnection();

    require_once __DIR__ . '/../classes/CRM/InboxSentinel.php';
    $sentinel = new \Classes\CRM\InboxSentinel($db);
    $messages = $sentinel->getConversation($partnerId, $days, 200);

    // Resolve partner info (read-only)
    $infoStmt = $db->prepare("
        SELECT cp.odoo_partner_id,
               COALESCE(cp.customer_name, CONCAT('Partner #', cp.odoo_partner_id)) AS customer_name,
               cp.customer_ref,
               cp.salesperson_name,
               cp.spend_total,
               cp.orders_count_total,
               cp.latest_order_at,
               rfm.current_segment,
               rfm.recency_ratio,
               rfm.is_high_value
        FROM   odoo_customer_projection cp
        LEFT JOIN customer_rfm_profile rfm
               ON rfm.odoo_partner_id = cp.odoo_partner_id
        WHERE  cp.odoo_partner_id = ?
        LIMIT 1
    ");
    $infoStmt->execute([$partnerId]);
    $partnerInfo = $infoStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

    // Compact summary stats (counts + worst severity in window)
    $stats = [
        'total'           => count($messages),
        'incoming'        => 0,
        'outgoing'        => 0,
        'flagged_red'     => 0,
        'flagged_orange'  => 0,
        'flagged_yellow'  => 0,
        'first_at'        => null,
        'last_at'         => null,
    ];
    foreach ($messages as $m) {
        if ($m['direction'] === 'incoming') {
            $stats['incoming']++;
        } else {
            $stats['outgoing']++;
        }
        $cls = $m['classification'];
        if ($cls === 'red') {
            $stats['flagged_red']++;
        } elseif ($cls === 'orange') {
            $stats['flagged_orange']++;
        } elseif ($cls === 'yellow' || $cls === 'yellow_urgent') {
            $stats['flagged_yellow']++;
        }
        if ($stats['first_at'] === null) {
            $stats['first_at'] = $m['created_at'];
        }
        $stats['last_at'] = $m['created_at'];
    }

    // Audit log — admin viewed this customer's conversation
    try {
        $auditStmt = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at)
            VALUES ('info', 'churn_conversation_api', ?, ?, NOW())
        ");
        $auditStmt->execute([
            "Conversation viewed for partner {$partnerId}",
            json_encode([
                'admin_id'   => $_SESSION['admin_user']['id'] ?? null,
                'partner_id' => $partnerId,
                'days'       => $days,
                'msg_count'  => count($messages),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $auditEx) {
        error_log('churn-conversation: audit log failed: ' . $auditEx->getMessage());
    }

    convoRespond(200, [
        'success' => true,
        'data' => [
            'messages'     => $messages,
            'partner_info' => $partnerInfo,
            'stats'        => $stats,
            'days_window'  => $days,
        ],
        'error'   => null,
    ]);
} catch (\Throwable $e) {
    error_log('churn-conversation: ' . $e->getMessage());
    convoRespond(500, ['success' => false, 'data' => null, 'error' => 'Internal error']);
}
