<?php
/**
 * api/churn-dashboard-data.php — JSON endpoint for Customer Churn Dashboard
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.5 (Phase 3)
 * Called by: assets/js/customer-churn.js (fetch, live-refresh every 60 s)
 *            tests/CRM/CustomerChurnDashboardTest.php (integration tests)
 *
 * Routes (GET ?action=):
 *   kpi       — 6 segment counts + total_eligible + last_computed_at
 *   watchlist — paginated watchlist rows (?segment= ?page= ?per_page=)
 *   cohort    — segment distribution for Chart.js + 30-day transition trend
 *   health    — system health meta (gemini quota, soft_launch flag, etc.)
 *
 * Response envelope: {"success": bool, "data": mixed, "error": string|null}
 * SELECT-only — no writes.
 * Permission gate: isAdmin() only (401 otherwise).
 * Charset: utf8mb4. Timezone: Asia/Bangkok (+07:00).
 */

declare(strict_types=1);

// ── Bootstrap (JSON endpoint — no header.php, no HTML output) ───────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php'; // sets $currentUser + isAdmin()

// ── Response helpers ─────────────────────────────────────────────────────────

function churnApiOk(mixed $data): never
{
    echo json_encode(
        ['success' => true, 'data' => $data, 'error' => null],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function churnApiError(int $httpCode, string $message): never
{
    http_response_code($httpCode);
    echo json_encode(
        ['success' => false, 'data' => null, 'error' => $message],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ── Permission gate ──────────────────────────────────────────────────────────
if (!isAdmin()) {
    churnApiError(401, 'Unauthorized: admin role required');
}

// ── Input validation ─────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';

$allowedActions = ['kpi', 'watchlist', 'cohort', 'health'];
if (!in_array($action, $allowedActions, true)) {
    churnApiError(400, 'Invalid action. Allowed: ' . implode(', ', $allowedActions));
}

// ── DB connection ─────────────────────────────────────────────────────────────
$db = Database::getInstance()->getConnection();

// ════════════════════════════════════════════════════════════════════════════
// kpi — 6 segment counts + metadata
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'kpi') {
    $segments = [
        'Champion'    => 0,
        'Watchlist'   => 0,
        'At-Risk'     => 0,
        'Lost'        => 0,
        'Churned'     => 0,
        'Hibernating' => 0,
    ];

    try {
        $stmtSeg = $db->query(
            "SELECT current_segment, COUNT(*) AS cnt
               FROM customer_rfm_profile
              WHERE current_segment IS NOT NULL
              GROUP BY current_segment"
        );
        if ($stmtSeg) {
            foreach ($stmtSeg->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $seg = $row['current_segment'];
                if (array_key_exists($seg, $segments)) {
                    $segments[$seg] = (int) $row['cnt'];
                }
            }
        }
    } catch (\Throwable $e) {
        // customer_rfm_profile may not exist pre-migration; return zeros.
    }

    $lastComputedAt = null;
    try {
        $stmtLast = $db->query('SELECT MAX(computed_at) FROM customer_rfm_profile');
        if ($stmtLast) {
            $val = $stmtLast->fetchColumn();
            $lastComputedAt = ($val !== false && $val !== null) ? (string) $val : null;
        }
    } catch (\Throwable $e) {
        // Silently ignore.
    }

    churnApiOk([
        'segments'        => $segments,
        'total_eligible'  => array_sum($segments),
        'last_computed_at'=> $lastComputedAt,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// watchlist — paginated rows, filterable by segment
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'watchlist') {
    $allowedSegments = ['all', 'Champion', 'Watchlist', 'At-Risk', 'Lost', 'Churned', 'Hibernating'];
    $segFilter = isset($_GET['segment']) ? trim((string) $_GET['segment']) : 'all';
    if (!in_array($segFilter, $allowedSegments, true)) {
        $segFilter = 'all';
    }

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 50)));
    $offset  = ($page - 1) * $perPage;

    // Build WHERE clause — never interpolate user input; use named params.
    $whereClause = ($segFilter === 'all')
        ? "p.current_segment IN ('Churned','Lost','At-Risk','Watchlist')"
        : 'p.current_segment = :seg';

    $params = ($segFilter === 'all') ? [] : [':seg' => $segFilter];

    $total = 0;
    $rows  = [];

    try {
        $stmtCount = $db->prepare(
            "SELECT COUNT(*)
               FROM customer_rfm_profile p
              WHERE {$whereClause}"
        );
        foreach ($params as $k => $v) {
            $stmtCount->bindValue($k, $v);
        }
        $stmtCount->execute();
        $total = (int) $stmtCount->fetchColumn();

        $stmtData = $db->prepare("
            SELECT
                p.odoo_partner_id,
                COALESCE(cp.customer_name, CONCAT('Partner #', p.odoo_partner_id)) AS store_name,
                p.customer_type,
                p.avg_order_cycle_days,
                DATEDIFF(CURDATE(), p.last_order_date)  AS days_since,
                p.recency_ratio,
                p.lifetime_value,
                p.last_order_date,
                p.is_high_value,
                p.is_seasonal,
                p.cycle_confidence,
                p.current_segment,
                lu.line_user_id
            FROM customer_rfm_profile p
            LEFT JOIN odoo_customer_projection cp
                   ON cp.odoo_partner_id = p.odoo_partner_id
            LEFT JOIN odoo_line_users lu
                   ON lu.odoo_partner_id = p.odoo_partner_id
            WHERE {$whereClause}
            ORDER BY
                CASE p.current_segment
                    WHEN 'Churned'   THEN CASE WHEN p.is_high_value = 1 THEN 1 ELSE 2 END
                    WHEN 'Lost'      THEN 3
                    WHEN 'At-Risk'   THEN 4
                    WHEN 'Watchlist' THEN 5
                    ELSE 6
                END ASC,
                p.recency_ratio DESC
            LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) {
            $stmtData->bindValue($k, $v);
        }
        $stmtData->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmtData->bindValue(':off', $offset,  \PDO::PARAM_INT);
        $stmtData->execute();
        $rows = $stmtData->fetchAll(\PDO::FETCH_ASSOC);

        // Cast numeric fields for correct JSON types.
        foreach ($rows as &$r) {
            $r['odoo_partner_id']      = (int) $r['odoo_partner_id'];
            $r['avg_order_cycle_days'] = $r['avg_order_cycle_days'] !== null ? (float) $r['avg_order_cycle_days'] : null;
            $r['days_since']           = $r['days_since'] !== null ? (int) $r['days_since'] : null;
            $r['recency_ratio']        = $r['recency_ratio'] !== null ? (float) $r['recency_ratio'] : null;
            $r['lifetime_value']       = $r['lifetime_value'] !== null ? (float) $r['lifetime_value'] : null;
            $r['is_high_value']        = (bool) $r['is_high_value'];
            $r['is_seasonal']          = (bool) $r['is_seasonal'];
        }
        unset($r);
    } catch (\Throwable $e) {
        churnApiError(500, 'Database error retrieving watchlist');
    }

    churnApiOk([
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'segment'  => $segFilter,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// cohort — segment distribution for Chart.js + 30-day transition trend
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'cohort') {
    $labels = ['Champion', 'Watchlist', 'At-Risk', 'Lost', 'Churned', 'Hibernating'];
    $counts = array_fill_keys($labels, 0);

    try {
        $stmtSeg = $db->query(
            "SELECT current_segment, COUNT(*) AS cnt
               FROM customer_rfm_profile
              WHERE current_segment IS NOT NULL
              GROUP BY current_segment"
        );
        if ($stmtSeg) {
            foreach ($stmtSeg->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $seg = $row['current_segment'];
                if (array_key_exists($seg, $counts)) {
                    $counts[$seg] = (int) $row['cnt'];
                }
            }
        }
    } catch (\Throwable $e) {
        // Return zeros on schema-not-ready.
    }

    $trend30d = [];
    try {
        $stmtTrend = $db->query(
            "SELECT to_segment, COUNT(*) AS cnt
               FROM customer_segment_history
              WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              GROUP BY to_segment"
        );
        if ($stmtTrend) {
            foreach ($stmtTrend->fetchAll(\PDO::FETCH_ASSOC) as $tRow) {
                $trend30d[(string) $tRow['to_segment']] = (int) $tRow['cnt'];
            }
        }
    } catch (\Throwable $e) {
        // Silently ignore missing table.
    }

    churnApiOk([
        'labels'          => $labels,
        'counts'          => array_values($counts),
        'counts_by_label' => $counts,
        'trend_30d'       => $trend30d,
    ]);
}

// ════════════════════════════════════════════════════════════════════════════
// health — system health meta
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'health') {
    $health = [
        'total_eligible'     => 0,
        'last_computed_at'   => null,
        'gemini_calls_today' => 0,
        'gemini_daily_cap'   => 200,
        'soft_launch'        => true,
        'system_enabled'     => false,
    ];

    try {
        $stmtCount = $db->query('SELECT COUNT(*) FROM customer_rfm_profile');
        if ($stmtCount) {
            $health['total_eligible'] = (int) $stmtCount->fetchColumn();
        }

        $stmtLast = $db->query('SELECT MAX(computed_at) FROM customer_rfm_profile');
        if ($stmtLast) {
            $val = $stmtLast->fetchColumn();
            $health['last_computed_at'] = ($val !== false && $val !== null) ? (string) $val : null;
        }
    } catch (\Throwable $e) {
        // Pre-migration; totals stay at defaults.
    }

    try {
        $stmtSettings = $db->query(
            'SELECT soft_launch, system_enabled, gemini_calls_today, gemini_daily_cap_calls
               FROM churn_settings WHERE id = 1 LIMIT 1'
        );
        if ($stmtSettings) {
            $sr = $stmtSettings->fetch(\PDO::FETCH_ASSOC);
            if ($sr) {
                $health['soft_launch']        = (bool) $sr['soft_launch'];
                $health['system_enabled']     = (bool) $sr['system_enabled'];
                $health['gemini_calls_today'] = (int) $sr['gemini_calls_today'];
                $health['gemini_daily_cap']   = (int) $sr['gemini_daily_cap_calls'];
            }
        }
    } catch (\Throwable $e) {
        // Settings table may not exist pre-migration; return defaults.
    }

    churnApiOk($health);
}
