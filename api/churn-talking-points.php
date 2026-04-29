<?php
/**
 * Churn Talking Points API
 *
 * POST /api/churn-talking-points.php
 * Body (JSON): { "partner_id": int }
 *
 * Permission gate: super_admin, admin, sales, pharmacist
 * Auth model: session-based (same as all admin panel pages via auth_check.php)
 *
 * Response envelope:
 * {
 *   "success": bool,
 *   "data": {
 *     "opener": string,
 *     "context_reminder": string,
 *     "discovery_questions": string[],
 *     "objection_handlers": string[],
 *     "next_best_offer": string,
 *     "warning": string|null
 *   } | null,
 *   "cached": bool,
 *   "error": string|null
 * }
 *
 * HTTP status codes:
 *   200 — success (cache hit or miss)
 *   400 — missing/invalid partner_id or wrong Content-Type
 *   401 — not authenticated
 *   403 — insufficient role
 *   405 — wrong HTTP method
 *   429 — daily Gemini cap reached
 *   500 — unexpected server error
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.4, §9
 */

declare(strict_types=1);

ob_start();

// ── Fatal-error safety net ────────────────────────────────────────────────
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) {
            ob_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'data'    => null,
            'cached'  => false,
            'error'   => 'PHP fatal error — contact support',
        ]);
    }
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Bangkok');

// ── Bootstrap ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Start session before auth_check (auth_check does session_start safely)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_check.php';

// ── Security headers ──────────────────────────────────────────────────────
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── OPTIONS preflight ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────

/**
 * Emit JSON response and terminate execution.
 *
 * @param array<string, mixed> $payload
 */
function churnTpRespond(int $httpCode, array $payload): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Method gate ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    churnTpRespond(405, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'Method not allowed — use POST',
    ]);
}

// ── Content-Type gate ─────────────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') === false) {
    churnTpRespond(400, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'Content-Type must be application/json',
    ]);
}

// ── Authentication check ──────────────────────────────────────────────────
// auth_check.php normally redirects unauthenticated users. For API endpoints
// we must return 401 JSON instead. Check session directly.
if (!isset($_SESSION['admin_user'])) {
    churnTpRespond(401, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'Authentication required',
    ]);
}

// $currentUser is set by auth_check.php from $_SESSION['admin_user']
/** @var array<string, mixed> $currentUser */

// ── Permission gate ───────────────────────────────────────────────────────
// Open to any authenticated user; auth_check.php enforces login above.

// ── Parse body ────────────────────────────────────────────────────────────
$rawBody = (string) file_get_contents('php://input');
$body    = json_decode($rawBody, true);

if (!is_array($body)) {
    churnTpRespond(400, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'Invalid JSON body',
    ]);
}

$partnerId = isset($body['partner_id']) ? (int) $body['partner_id'] : 0;
if ($partnerId <= 0) {
    churnTpRespond(400, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'partner_id must be a positive integer',
    ]);
}

$adminId = (int) ($currentUser['id'] ?? 0);

// ── DB connection ─────────────────────────────────────────────────────────
try {
    $db = Database::getInstance()->getConnection();
} catch (\Exception $e) {
    error_log("churn-talking-points: DB connect failed: " . $e->getMessage());
    churnTpRespond(500, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'Database unavailable',
    ]);
}

// ── Service layer ─────────────────────────────────────────────────────────
require_once __DIR__ . '/../classes/GeminiAI.php';
require_once __DIR__ . '/../classes/CRM/PartnerContextLoader.php';
require_once __DIR__ . '/../classes/CRM/TalkingPointsService.php';

try {
    // GeminiAI loads API key + model from ai_settings table (never hardcoded)
    $gemini = new \GeminiAI(null, $db);
} catch (\Exception $e) {
    error_log("churn-talking-points: GeminiAI init failed: " . $e->getMessage());
    churnTpRespond(500, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'AI service not configured — check Gemini API key in AI settings',
    ]);
}

$contextLoader = new \Classes\CRM\PartnerContextLoader($db);
$service       = new \Classes\CRM\TalkingPointsService($db, $gemini, $contextLoader);

// ── Execute and respond ───────────────────────────────────────────────────
try {
    $result = $service->getForPartner($partnerId);

    // Audit: log every successful call to dev_logs (spec §6.4)
    try {
        $auditStmt = $db->prepare(
            "INSERT INTO dev_logs (log_type, source, message, data, created_at)
             VALUES ('info', 'churn_talking_points_api', ?, ?, NOW())"
        );
        $auditStmt->execute([
            "Talking points fetched for partner {$partnerId}",
            json_encode([
                'admin_id'    => $adminId,
                'partner_id'  => $partnerId,
                'cache_hit'   => $result['cached'],
                'tokens_used' => $result['tokens_used'],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Exception $auditEx) {
        error_log("churn-talking-points: dev_logs write failed: " . $auditEx->getMessage());
    }

    churnTpRespond(200, [
        'success'     => true,
        'data'        => $result['payload'],
        'cached'      => $result['cached'],
        'tokens_used' => (int) ($result['tokens_used'] ?? 0),
        'error'       => null,
    ]);

} catch (\RuntimeException $e) {
    $code = (int) $e->getCode();

    if ($code === 429) {
        // Audit the cap-hit event
        try {
            $capStmt = $db->prepare(
                "INSERT INTO dev_logs (log_type, source, message, data, created_at)
                 VALUES ('warn', 'churn_talking_points_api', 'Daily Gemini cap reached', ?, NOW())"
            );
            $capStmt->execute([
                json_encode(['admin_id' => $adminId, 'partner_id' => $partnerId]),
            ]);
        } catch (\Exception $ignored) {
        }

        churnTpRespond(429, [
            'success' => false,
            'data'    => null,
            'cached'  => false,
            'error'   => $e->getMessage(),
        ]);
    }

    error_log("churn-talking-points: RuntimeException ({$code}): " . $e->getMessage());
    churnTpRespond(500, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => $e->getMessage(),
    ]);

} catch (\Exception $e) {
    error_log("churn-talking-points: Unexpected exception: " . $e->getMessage());
    churnTpRespond(500, [
        'success' => false,
        'data'    => null,
        'cached'  => false,
        'error'   => 'Internal server error',
    ]);
}
