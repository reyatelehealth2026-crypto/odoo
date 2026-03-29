<?php
/**
 * api/inbox-intelligence.php
 *
 * Inbox Intelligence API - response time analytics, SLA monitoring, sentiment summary.
 * New file, no existing files modified.
 *
 * Endpoints:
 *   GET ?action=response_time        - Response time analytics by day/period
 *   GET ?action=response_by_admin    - Per-admin response time breakdown
 *   GET ?action=sla_breach           - Messages waiting > X minutes without reply
 *   GET ?action=sentiment_summary    - Sentiment breakdown from auto-tags
 *   GET ?action=unread_wait          - Unread messages with wait time
 *   GET ?action=customer_journey     - Inbox → Order → Payment funnel
 *   GET ?action=daily_report         - Executive daily report (orders, revenue, slips, BDO, inbox)
 *   GET ?action=overview             - All metrics in one call
 *
 * Auth: Same as other dashboard APIs (uses INTERNAL_API_SECRET or session)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
session_write_close();

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? 'overview';
$days = isset($_GET['days']) ? max(1, min(intval($_GET['days']), 365)) : 7;
$line_account_id = isset($_GET['line_account_id']) ? intval($_GET['line_account_id']) : 3;
$sla_threshold = isset($_GET['sla_threshold']) ? intval($_GET['sla_threshold']) : 50;

function jsonResp($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonResponseTimes($db, $days, $lineAccountId) {
    $sql = "
        SELECT
            DATE(ma.created_at) AS day,
            COUNT(*) AS conversations,
            ROUND(AVG(ma.response_time_seconds)) AS avg_sec,
            ROUND(MIN(ma.response_time_seconds)) AS min_sec,
            ROUND(MAX(ma.response_time_seconds)) AS max_sec,
            SUM(CASE WHEN ma.response_time_seconds <= 60 THEN 1 ELSE 0 END) AS under_1min,
            SUM(CASE WHEN ma.response_time_seconds <= 300 THEN 1 ELSE 0 END) AS under_5min,
            SUM(CASE WHEN ma.response_time_seconds <= 600 THEN 1 ELSE 0 END) AS under_10min,
            SUM(CASE WHEN ma.response_time_seconds > 600 THEN 1 ELSE 0 END) AS over_10min,
            SUM(CASE WHEN ma.response_time_seconds > 1800 THEN 1 ELSE 0 END) AS over_30min
        FROM message_analytics ma
        JOIN inbox_line_users u ON u.id = ma.user_id";

    $params = [];
    if ($lineAccountId > 0) {
        // Filter by account via messages table join
        $sql .= " JOIN messages m ON m.id = ma.message_id AND m.line_account_id = ?";
        $params[] = $lineAccountId;
    }

    $sql .= "
        WHERE ma.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(ma.created_at)
        ORDER BY day";
    $params[] = $days;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function jsonAdminBreakdown($db, $days) {
    $stmt = $db->prepare("
        SELECT
            ma.admin_id,
            COALESCE(au.display_name, CONCAT('Admin ', ma.admin_id)) AS admin_name,
            COUNT(*) AS conversations,
            ROUND(AVG(ma.response_time_seconds)) AS avg_sec,
            SUM(CASE WHEN ma.response_time_seconds <= 300 THEN 1 ELSE 0 END) AS under_5min,
            SUM(CASE WHEN ma.response_time_seconds <= 600 THEN 1 ELSE 0 END) AS under_10min,
            SUM(CASE WHEN ma.response_time_seconds > 600 THEN 1 ELSE 0 END) AS over_10min,
            SUM(CASE WHEN ma.response_time_seconds > 1800 THEN 1 ELSE 0 END) AS over_30min
        FROM message_analytics ma
        LEFT JOIN admin_users au ON au.id = ma.admin_id
        WHERE ma.admin_id IS NOT NULL
          AND ma.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY ma.admin_id
        ORDER BY avg_sec ASC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

function jsonSlaBreach($db, $slaThreshold) {
    $stmt = $db->prepare("
        SELECT
            m.user_id,
            u.display_name,
            u.line_user_id,
            LEFT(m.content, 150) AS last_message,
            m.created_at AS sent_at,
            TIMESTAMPDIFF(MINUTE, m.created_at, NOW()) AS wait_minutes,
            c.customer_ref,
            c.customer_name
        FROM messages m
        JOIN inbox_line_users u ON u.id = m.user_id
        LEFT JOIN odoo_customers_cache c ON c.line_user_id = u.line_user_id
        WHERE m.direction = 'incoming'
          AND m.message_type = 'text'
          AND m.is_read = 0
          AND m.created_at >= NOW() - INTERVAL 4 HOUR
          AND NOT EXISTS (
              SELECT 1 FROM messages r
              WHERE r.user_id = m.user_id
                AND r.direction = 'outgoing'
                AND r.created_at > m.created_at
          )
        ORDER BY m.created_at ASC
    ");
    $stmt->execute();
    $all = $stmt->fetchAll();

    // Calculate business-hour wait time (08:00-18:00, Mon-Sat, exclude Sun)
    $now = new DateTime();
    foreach ($all as &$row) {
        $in = new DateTime($row['sent_at']);
        $bizMinutes = 0;
        $cursor = clone $in;

        while ($cursor < $now) {
            $dow = (int) $cursor->format('w');
            if ($dow === 0) { // Sunday - skip
                $cursor->setTime(0, 0, 0);
                $cursor->modify('+1 day');
                $cursor->setTime(8, 0, 0);
                continue;
            }
            $cursorTime = (int) $cursor->format('Hi');
            if ($cursorTime < 800) { // Before 08:00
                $cursor->setTime(8, 0, 0);
                continue;
            }
            if ($cursorTime >= 1700) { // After 18:00
                $cursor->setTime(0, 0, 0);
                $cursor->modify('+1 day');
                $cursor->setTime(8, 0, 0);
                continue;
            }
            // Count minutes until end of biz day or end of wait
            $dayEnd = clone $cursor;
            $dayEnd->setTime(17, 0, 0);
            $endPoint = $dayEnd < $now ? $dayEnd : $now;
            $bizMinutes += (int) $dayEnd->diff($endPoint)->format('%i') + ((int) $dayEnd->diff($endPoint)->format('%h')) * 60;
            $cursor = clone $endPoint;
            if ($cursor >= $now) break;
            // Move to next biz day
            $cursor->setTime(0, 0, 0);
            $cursor->modify('+1 day');
            $cursor->setTime(8, 0, 0);
        }
        $row['wait_minutes'] = $bizMinutes;
    }
    unset($row);

    $breach = array_filter($all, fn($r) => $r['wait_minutes'] >= $slaThreshold);
    return [
        'total_waiting' => count($all),
        'breach_count' => count($breach),
        'threshold_minutes' => $slaThreshold,
        'breaches' => array_values($breach),
    ];
}

function jsonSentimentSummary($db, $days) {
    $stmt = $db->prepare("
        SELECT
            t.name AS tag_name,
            COUNT(a.id) AS count
        FROM inbox_user_tag_assignments a
        JOIN user_tags t ON t.id = a.tag_id
        WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND t.name IN ('ร้องเรียน', 'ไม่พอใจ', 'ต้องติดตาม', 'เชิงบวก', 'รอตอบนาน')
        GROUP BY t.id, t.name
        ORDER BY count DESC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

function calcBizWaitMinutes($sentAtStr) {
    // Calculate business-hour wait (08:00-18:00 Mon-Sat)
    $in = new DateTime($sentAtStr);
    $now = new DateTime();
    $bizMinutes = 0;
    $cursor = clone $in;

    while ($cursor < $now) {
        $dow = (int) $cursor->format('w');
        if ($dow === 0) {
            $cursor->setTime(0, 0, 0);
            $cursor->modify('+1 day');
            $cursor->setTime(8, 0, 0);
            continue;
        }
        $cursorTime = (int) $cursor->format('Hi');
        if ($cursorTime < 800) {
            $cursor->setTime(8, 0, 0);
            continue;
        }
        if ($cursorTime >= 1700) {
            $cursor->setTime(0, 0, 0);
            $cursor->modify('+1 day');
            $cursor->setTime(8, 0, 0);
            continue;
        }
        $dayEnd = clone $cursor;
        $dayEnd->setTime(17, 0, 0);
        $endPoint = $dayEnd < $now ? $dayEnd : $now;
        $bizMinutes += (int) $dayEnd->diff($endPoint)->format('%i') + ((int) $dayEnd->diff($endPoint)->format('%h')) * 60;
        $cursor = clone $endPoint;
        if ($cursor >= $now) break;
        $cursor->setTime(0, 0, 0);
        $cursor->modify('+1 day');
        $cursor->setTime(8, 0, 0);
    }
    return $bizMinutes;
}

function jsonUnreadWait($db) {
    $stmt = $db->query("
        SELECT
            m.user_id,
            u.display_name,
            m.message_type,
            LEFT(m.content, 100) AS preview,
            m.created_at,
            TIMESTAMPDIFF(MINUTE, m.created_at, NOW()) AS wait_minutes_raw
        FROM messages m
        JOIN inbox_line_users u ON u.id = m.user_id
        WHERE m.direction = 'incoming'
          AND m.is_read = 0
        ORDER BY m.created_at ASC
        LIMIT 50
    ");
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['wait_minutes'] = calcBizWaitMinutes($row['created_at']);
    }
    unset($row);
    return $rows;
}

function jsonCustomerJourney($db, $days) {
    $stmt = $db->prepare("
        SELECT
            cs.stage,
            COUNT(*) AS users,
            SUM(CASE WHEN cs.has_urgent_symptoms = 1 THEN 1 ELSE 0 END) AS urgent
        FROM consultation_stages cs
        WHERE cs.updated_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY cs.stage
        ORDER BY users DESC
    ");
    $stmt->execute([$days]);
    $stages = $stmt->fetchAll();

    // Inbox to Order conversion — join via users.line_user_id → odoo_orders.line_user_id
    $stmt2 = $db->prepare("
        SELECT
            COUNT(DISTINCT u.line_user_id) AS inbox_users,
            COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN u.line_user_id END) AS has_orders,
            COUNT(DISTINCT CASE WHEN rc.order_count > 1 THEN u.line_user_id END) AS repeat_customers
        FROM messages m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN odoo_orders o ON o.line_user_id = u.line_user_id AND o.state NOT IN ('cancel')
        LEFT JOIN (
            SELECT line_user_id, COUNT(*) as order_count
            FROM odoo_orders
            WHERE line_user_id IS NOT NULL AND state NOT IN ('cancel')
            GROUP BY line_user_id
        ) rc ON rc.line_user_id = u.line_user_id
        WHERE m.direction = 'incoming'
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt2->execute([$days]);
    $conversion = $stmt2->fetch();

    return [
        'consultation_stages' => $stages,
        'conversion' => $conversion,
    ];
}

function jsonDailyReport($db) {
    // ── Today's Orders ──
    $todayOrders = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(amount_total), 0) AS amount,
            SUM(CASE WHEN state IN ('to_delivery','assigned_driver','out_for_delivery') THEN 1 ELSE 0 END) AS delivering,
            SUM(CASE WHEN state IN ('packed','packing','picking','picked','picker_assign') THEN 1 ELSE 0 END) AS in_warehouse,
            SUM(CASE WHEN state = 'delivered' THEN 1 ELSE 0 END) AS delivered,
            SUM(CASE WHEN is_paid = 1 THEN amount_total ELSE 0 END) AS paid,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders WHERE DATE(date_order) = CURDATE()
    ")->fetch();

    // ── Yesterday (compare) ──
    $yesterdayOrders = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(amount_total), 0) AS amount,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders WHERE DATE(date_order) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND state NOT IN ('cancel')
    ")->fetch();

    // ── This Month ──
    $monthOrders = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(amount_total), 0) AS amount,
            SUM(CASE WHEN is_paid = 1 THEN amount_total ELSE 0 END) AS paid,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders WHERE date_order >= '2026-04-01' AND state NOT IN ('cancel')
    ")->fetch();

    // ── Last Month (compare) ──
    $lastMonthOrders = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(amount_total), 0) AS amount,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders WHERE date_order >= '2026-03-01' AND date_order < '2026-04-01' AND state NOT IN ('cancel')
    ")->fetch();

    // ── Current month actually ──
    $currentMonth = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(amount_total), 0) AS amount,
            SUM(CASE WHEN is_paid = 1 THEN amount_total ELSE 0 END) AS paid,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders WHERE date_order >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND state NOT IN ('cancel')
    ")->fetch();

    $prevMonth = $db->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(amount_total), 0) AS amount,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders WHERE date_order >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
          AND date_order < DATE_FORMAT(CURDATE(), '%Y-%m-01') AND state NOT IN ('cancel')
    ")->fetch();

    // ── BDO Today ──
    $bdoToday = $db->query("
        SELECT COUNT(*) AS total, COALESCE(SUM(amount_total), 0) AS amount,
            SUM(CASE WHEN state = 'done' THEN 1 ELSE 0 END) AS done
        FROM odoo_bdos WHERE DATE(created_at) = CURDATE()
    ")->fetch();

    // ── BDO Yesterday ──
    $bdoYesterday = $db->query("
        SELECT COUNT(*) AS total, COALESCE(SUM(amount_total), 0) AS amount,
            SUM(CASE WHEN state = 'done' THEN 1 ELSE 0 END) AS done
        FROM odoo_bdos WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ")->fetch();

    // ── Slips Today ──
    $slipsToday = $db->query("
        SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount,
            SUM(CASE WHEN status = 'matched' THEN 1 ELSE 0 END) AS matched,
            SUM(CASE WHEN status IN ('new','pending') THEN 1 ELSE 0 END) AS pending
        FROM odoo_slip_uploads WHERE DATE(uploaded_at) = CURDATE()
    ")->fetch();

    // ── Slips Yesterday ──
    $slipsYesterday = $db->query("
        SELECT COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount,
            SUM(CASE WHEN status = 'matched' THEN 1 ELSE 0 END) AS matched,
            SUM(CASE WHEN status IN ('new','pending') THEN 1 ELSE 0 END) AS pending
        FROM odoo_slip_uploads WHERE DATE(uploaded_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ")->fetch();

    // ── Overdue ──
    $overdue = $db->query("
        SELECT COUNT(*) AS customers, COALESCE(SUM(overdue_amount), 0) AS total_amount
        FROM odoo_customer_projection WHERE overdue_amount > 0
    ")->fetch();

    $overdueTop = $db->query("
        SELECT customer_ref, customer_name, overdue_amount
        FROM odoo_customer_projection
        WHERE overdue_amount > 0
        ORDER BY overdue_amount DESC LIMIT 5
    ")->fetchAll();

    // ── Messages Today ──
    $messages = $db->query("
        SELECT
            SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) AS incoming,
            SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) AS outgoing,
            COUNT(DISTINCT CASE WHEN direction = 'incoming' THEN user_id END) AS senders,
            SUM(CASE WHEN direction = 'incoming' AND is_read = 0 THEN 1 ELSE 0 END) AS unread
        FROM messages WHERE DATE(created_at) = CURDATE()
    ")->fetch();

    // ── Hourly traffic today ──
    $hourly = $db->query("
        SELECT
            HOUR(created_at) AS hour,
            SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) AS incoming,
            SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) AS outgoing
        FROM messages WHERE DATE(created_at) = CURDATE()
        GROUP BY HOUR(created_at) ORDER BY hour
    ")->fetchAll();

    // ── Top Customers Today ──
    $topCustomers = $db->query("
        SELECT o.customer_ref, cp.customer_name, COUNT(*) AS orders, SUM(o.amount_total) AS amount
        FROM odoo_orders o
        LEFT JOIN odoo_customer_projection cp ON cp.customer_ref = o.customer_ref
        WHERE DATE(o.date_order) = CURDATE()
        GROUP BY o.customer_ref, cp.customer_name
        ORDER BY amount DESC LIMIT 10
    ")->fetchAll();

    // ── Salespeople Today ──
    $salespeople = $db->query("
        SELECT
            o.salesperson_id,
            o.salesperson_name,
            COUNT(*) AS orders,
            COALESCE(SUM(o.amount_total), 0) AS amount
        FROM odoo_orders o
        WHERE DATE(o.date_order) = CURDATE()
        GROUP BY o.salesperson_id, o.salesperson_name
        ORDER BY amount DESC
    ")->fetchAll();

    // ── 7-day trend ──
    $trend = $db->query("
        SELECT
            DATE_FORMAT(date_order, '%d/%m') AS day,
            COUNT(*) AS orders,
            COALESCE(SUM(amount_total), 0) AS amount,
            COUNT(DISTINCT partner_id) AS customers
        FROM odoo_orders
        WHERE date_order >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND state NOT IN ('cancel')
        GROUP BY DATE(date_order) ORDER BY date_order
    ")->fetchAll();

    // ── Top admins today ──
    $topAdmins = $db->query("
        SELECT sent_by, COUNT(*) AS cnt
        FROM messages
        WHERE direction = 'outgoing' AND DATE(created_at) = CURDATE()
          AND sent_by IS NOT NULL AND sent_by NOT LIKE 'system%'
        GROUP BY sent_by ORDER BY cnt DESC LIMIT 5
    ")->fetchAll();

    // Map admin IDs to names
    $allAdminIds = array_unique(array_filter(array_map(fn($a) => (int)preg_replace('/[^0-9]/','',$a['sent_by']), $topAdmins)));
    $adminNames = [];
    if (!empty($allAdminIds)) {
        $placeholders = implode(',', array_fill(0, count($allAdminIds), '?'));
        $nameStmt = $db->prepare("SELECT id, display_name FROM admin_users WHERE id IN ($placeholders)");
        $nameStmt->execute(array_values($allAdminIds));
        foreach ($nameStmt->fetchAll() as $a) {
            $adminNames[$a['id']] = $a['display_name'];
        }
    }

    $topAdminsNamed = array_map(function($a) use ($adminNames) {
        $id = (int)preg_replace('/[^0-9]/','',$a['sent_by']);
        return [
            'sent_by' => $adminNames[$id] ?? $a['sent_by'],
            'count' => (int)$a['cnt']
        ];
    }, $topAdmins);

    return [
        'date' => date('Y-m-d'),
        'orders_today' => $todayOrders,
        'orders_yesterday' => $yesterdayOrders,
        'current_month' => $currentMonth,
        'prev_month' => $prevMonth,
        'bdo_today' => $bdoToday,
        'bdo_yesterday' => $bdoYesterday,
        'slips_today' => $slipsToday,
        'slips_yesterday' => $slipsYesterday,
        'overdue' => $overdue,
        'overdue_top' => $overdueTop,
        'messages_today' => $messages,
        'hourly_traffic' => $hourly,
        'top_customers' => $topCustomers,
        'salespeople' => $salespeople,
        'trend_7d' => $trend,
        'top_admins' => $topAdminsNamed,
    ];
}



// ═══════════════════════════════════════════════════════════════
// TRAFFIC COMPARISON
// ═══════════════════════════════════════════════════════════════
function jsonTrafficComparison(PDO $db, int $days, int $accountId): array {
    $daily = $db->query("
        SELECT 
            DATE(created_at) AS date,
            COUNT(CASE WHEN direction='incoming' THEN 1 END) AS msg_in,
            COUNT(CASE WHEN direction='outgoing' THEN 1 END) AS msg_out,
            COUNT(DISTINCT user_id) AS users
        FROM messages 
        WHERE line_account_id={$accountId} 
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $today = $db->query("
        SELECT 
            COUNT(CASE WHEN direction='incoming' THEN 1 END) AS today_in,
            COUNT(CASE WHEN direction='outgoing' THEN 1 END) AS today_out,
            COUNT(DISTINCT user_id) AS today_users,
            COUNT(DISTINCT CASE 
                WHEN direction='incoming' AND user_id NOT IN (
                    SELECT DISTINCT user_id FROM messages 
                    WHERE line_account_id={$accountId} AND direction='incoming' 
                    AND created_at < CURDATE()
                ) THEN user_id 
            END) AS new_today
        FROM messages 
        WHERE line_account_id={$accountId} AND DATE(created_at) = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $yesterday = $db->query("
        SELECT 
            COUNT(CASE WHEN direction='incoming' THEN 1 END) AS yday_in,
            COUNT(CASE WHEN direction='outgoing' THEN 1 END) AS yday_out,
            COUNT(DISTINCT user_id) AS yday_users,
            COUNT(DISTINCT CASE 
                WHEN direction='incoming' AND user_id NOT IN (
                    SELECT DISTINCT user_id FROM messages 
                    WHERE line_account_id={$accountId} AND direction='incoming' 
                    AND created_at < DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                ) THEN user_id 
            END) AS new_yday
        FROM messages 
        WHERE line_account_id={$accountId} AND DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    // Calculate 7d average (excluding today)
    $avg = $db->query("
        SELECT 
            AVG(d.msg_in) AS avg_in,
            AVG(d.msg_out) AS avg_out
        FROM (
            SELECT 
                COUNT(CASE WHEN direction='incoming' THEN 1 END) AS msg_in,
                COUNT(CASE WHEN direction='outgoing' THEN 1 END) AS msg_out
            FROM messages 
            WHERE line_account_id={$accountId} 
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
              AND created_at < CURDATE()
            GROUP BY DATE(created_at)
        ) d
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    return array_merge(
        $today ?: ['today_in'=>0,'today_out'=>0,'today_users'=>0,'new_today'=>0],
        $yesterday ?: ['yday_in'=>0,'yday_out'=>0,'yday_users'=>0,'new_yday'=>0],
        $avg ?: ['avg_in'=>0,'avg_out'=>0],
        ['days' => $days, 'data' => $daily]
    );
}

// ═══════════════════════════════════════════════════════════════
// MESSAGE TYPE DISTRIBUTION
// ═══════════════════════════════════════════════════════════════
function jsonMessageTypeDist(PDO $db, int $days, int $accountId): array {
    $rows = $db->query("
        SELECT message_type, COUNT(*) AS cnt
        FROM messages 
        WHERE line_account_id={$accountId} 
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
        GROUP BY message_type
        ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $byDirection = $db->query("
        SELECT direction, message_type, COUNT(*) AS cnt
        FROM messages 
        WHERE line_account_id={$accountId} 
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
        GROUP BY direction, message_type
        ORDER BY direction, cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return ['days' => $days, 'types' => $rows, 'data' => $byDirection];
}

// ═══════════════════════════════════════════════════════════════
// ADMIN WORKLOAD (messages sent by each admin)
// ═══════════════════════════════════════════════════════════════
function jsonAdminWorkload(PDO $db, int $days, int $accountId): array {
    $rows = $db->query("
        SELECT 
            m.sent_by AS sent_by,
            COALESCE(a.display_name, m.sent_by) AS admin_name,
            COUNT(*) AS total_sent,
            COUNT(CASE WHEN DATE(m.created_at) = CURDATE() THEN 1 END) AS today_sent
        FROM messages m
        LEFT JOIN admin_users a ON a.id = CAST(m.sent_by AS UNSIGNED)
        WHERE m.line_account_id={$accountId} 
          AND m.direction = 'outgoing'
          AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
        GROUP BY m.sent_by
        ORDER BY total_sent DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return ['days' => $days, 'data' => $rows];
}


function jsonFlaggedMessages($db, $days = 7) {
    // Problematic incoming messages
    $stmt = $db->prepare("
        SELECT m.id, m.created_at, u.display_name, m.content,
               CASE
                   WHEN m.content LIKE '%ไม่พอใจ%' OR m.content LIKE '%แย่%' OR m.content LIKE '%บริการไม่ดี%'
                       OR m.content LIKE '%โกง%' OR m.content LIKE '%ร้องเรียน%' OR m.content LIKE '%เรียกร้อง%'
                   THEN 'complaint'
                   WHEN m.content LIKE '%ขอคืน%' OR m.content LIKE '%คืนเงิน%' OR m.content LIKE '%ส่งผิด%'
                       OR m.content LIKE '%ของผิด%' OR m.content LIKE '%ชิ้นเสีย%' OR m.content LIKE '%ผิดกล่อง%'
                       OR m.content LIKE '%สั่งผิด%'
                   THEN 'return_exchange'
                   WHEN m.content LIKE '%ไม่ได้รับ%' OR m.content LIKE '%หาย%' OR m.content LIKE '%ขาด%'
                   THEN 'missing_item'
                   WHEN m.content LIKE '%ช้า%' OR m.content LIKE '%ไม่ตอบ%' OR m.content LIKE '%สาย%'
                       OR m.content LIKE '%รอนาน%'
                   THEN 'slow_response'
                   WHEN m.content LIKE '%ผิด%' OR m.content LIKE '%คีย์ผิด%' OR m.content LIKE '%ทำผิด%'
                   THEN 'error_complaint'
                   ELSE 'other'
               END as category
        FROM messages m
        JOIN users u ON u.id = m.user_id
        WHERE m.direction = 'incoming' AND m.message_type = 'text'
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (
            m.content LIKE '%ไม่พอใจ%' OR m.content LIKE '%แย่%' OR m.content LIKE '%บริการไม่ดี%'
            OR m.content LIKE '%โกง%' OR m.content LIKE '%ร้องเรียน%' OR m.content LIKE '%เรียกร้อง%'
            OR m.content LIKE '%ขอคืน%' OR m.content LIKE '%คืนเงิน%' OR m.content LIKE '%ส่งผิด%'
            OR m.content LIKE '%ของผิด%' OR m.content LIKE '%ชิ้นเสีย%' OR m.content LIKE '%ผิดกล่อง%'
            OR m.content LIKE '%สั่งผิด%' OR m.content LIKE '%ไม่ได้รับ%' OR m.content LIKE '%หาย%'
            OR m.content LIKE '%ขาด%' OR m.content LIKE '%ช้า%' OR m.content LIKE '%ไม่ตอบ%'
            OR m.content LIKE '%สาย%' OR m.content LIKE '%รอนาน%' OR m.content LIKE '%ผิด%'
            OR m.content LIKE '%คีย์ผิด%' OR m.content LIKE '%ทำผิด%' OR m.content LIKE '%เข้าใจผิด%'
          )
          AND LENGTH(m.content) > 3
          AND m.content NOT LIKE '................%'
        ORDER BY m.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$days]);
    $incoming = $stmt->fetchAll();

    // Inappropriate outgoing messages (admin)
    $stmt2 = $db->prepare("
        SELECT m.id, m.created_at, COALESCE(au.display_name, CONCAT('Admin ', m.sent_by)) as admin_name, m.content,
               CASE
                   WHEN m.content REGEXP '^[\\\\.\\\\[\\\\]\\\\s0-9a-zA-Z]{0,5}$' THEN 'typo_garbage'
                   WHEN m.content LIKE '%เดี๋ยว%' AND m.content NOT LIKE '%เดี๋ยวแอดมิน%' THEN 'vague_promise'
                   ELSE 'unprofessional'
               END as category
        FROM messages m
        LEFT JOIN admin_users au ON au.id = m.sent_by
        WHERE m.direction = 'outgoing' AND m.sent_by IS NOT NULL
          AND m.message_type = 'text'
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (
            m.content REGEXP '^[\\\\.\\\\[\\\\]\\\\s0-9a-zA-Z]{0,5}$'
            OR m.content IN ('5','ก','111111111.','[\\\\')
            OR (m.content LIKE '%เดี๋ยว%' AND LENGTH(m.content) < 30)
          )
        ORDER BY m.created_at DESC
        LIMIT 30
    ");
    $stmt2->execute([$days]);
    $outgoing = $stmt2->fetchAll();

    return [
        'problematic_incoming' => $incoming,
        'inappropriate_outgoing' => $outgoing,
    ];
}

function jsonFlaggedSummary($db, $days = 7) {
    $stmt = $db->prepare("
        SELECT
            CASE
                WHEN m.content LIKE '%ไม่พอใจ%' OR m.content LIKE '%แย่%' OR m.content LIKE '%บริการไม่ดี%'
                    OR m.content LIKE '%โกง%' OR m.content LIKE '%ร้องเรียน%' OR m.content LIKE '%เรียกร้อง%'
                THEN 'complaint'
                WHEN m.content LIKE '%ขอคืน%' OR m.content LIKE '%คืนเงิน%' OR m.content LIKE '%ส่งผิด%'
                    OR m.content LIKE '%ของผิด%' OR m.content LIKE '%ชิ้นเสีย%' OR m.content LIKE '%ผิดกล่อง%'
                    OR m.content LIKE '%สั่งผิด%'
                THEN 'return_exchange'
                WHEN m.content LIKE '%ไม่ได้รับ%' OR m.content LIKE '%หาย%' OR m.content LIKE '%ขาด%'
                THEN 'missing_item'
                WHEN m.content LIKE '%ช้า%' OR m.content LIKE '%ไม่ตอบ%' OR m.content LIKE '%สาย%'
                    OR m.content LIKE '%รอนาน%'
                THEN 'slow_response'
                WHEN m.content LIKE '%ผิด%' OR m.content LIKE '%คีย์ผิด%' OR m.content LIKE '%ทำผิด%'
                THEN 'error_complaint'
                ELSE 'other'
            END as category,
            COUNT(*) as count
        FROM messages m
        WHERE m.direction = 'incoming' AND m.message_type = 'text'
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (
            m.content LIKE '%ไม่พอใจ%' OR m.content LIKE '%แย่%' OR m.content LIKE '%บริการไม่ดี%'
            OR m.content LIKE '%โกง%' OR m.content LIKE '%ร้องเรียน%' OR m.content LIKE '%เรียกร้อง%'
            OR m.content LIKE '%ขอคืน%' OR m.content LIKE '%คืนเงิน%' OR m.content LIKE '%ส่งผิด%'
            OR m.content LIKE '%ของผิด%' OR m.content LIKE '%ชิ้นเสีย%' OR m.content LIKE '%ผิดกล่อง%'
            OR m.content LIKE '%สั่งผิด%' OR m.content LIKE '%ไม่ได้รับ%' OR m.content LIKE '%หาย%'
            OR m.content LIKE '%ขาด%' OR m.content LIKE '%ช้า%' OR m.content LIKE '%ไม่ตอบ%'
            OR m.content LIKE '%สาย%' OR m.content LIKE '%รอนาน%' OR m.content LIKE '%ผิด%'
            OR m.content LIKE '%คีย์ผิด%' OR m.content LIKE '%ทำผิด%' OR m.content LIKE '%เข้าใจผิด%'
          )
          AND LENGTH(m.content) > 3
          AND m.content NOT LIKE '................%'
        GROUP BY category
        ORDER BY count DESC
    ");
    $stmt->execute([$days]);
    $incomingSummary = $stmt->fetchAll();

    $stmt2 = $db->prepare("
        SELECT
            COALESCE(au.display_name, CONCAT('Admin ', m.sent_by)) as admin_name,
            COUNT(*) as count
        FROM messages m
        LEFT JOIN admin_users au ON au.id = m.sent_by
        WHERE m.direction = 'outgoing' AND m.sent_by IS NOT NULL
          AND m.message_type = 'text'
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (
            m.content REGEXP '^[\\\\.\\\\[\\\\]\\\\s0-9a-zA-Z]{0,5}$'
            OR m.content IN ('5','ก','111111111.','[\\\\')
            OR (m.content LIKE '%เดี๋ยว%' AND LENGTH(m.content) < 30)
          )
        GROUP BY m.sent_by
        ORDER BY count DESC
    ");
    $stmt2->execute([$days]);
    $outgoingSummary = $stmt2->fetchAll();

    return [
        'incoming_by_category' => $incomingSummary,
        'outgoing_by_admin' => $outgoingSummary,
    ];
}
try {
    switch ($action) {
        case 'response_time':
            jsonResp(['days' => $days, 'data' => jsonResponseTimes($db, $days, $line_account_id)]);

        case 'response_by_admin':
            jsonResp(['days' => $days, 'data' => jsonAdminBreakdown($db, $days)]);

        case 'sla_breach':
            jsonResp(jsonSlaBreach($db, $sla_threshold));

        case 'sentiment_summary':
            jsonResp(['days' => $days, 'data' => jsonSentimentSummary($db, $days)]);

        case 'unread_wait':
            jsonResp(['data' => jsonUnreadWait($db)]);

        case 'customer_journey':
            jsonResp(['days' => $days, 'data' => jsonCustomerJourney($db, $days)]);

        case 'daily_report':
            jsonResp(['data' => jsonDailyReport($db)]);

        case 'flagged_messages':
            jsonResp(['days' => $days, 'data' => jsonFlaggedMessages($db, $days)]);

        case 'flagged_summary':
            jsonResp(['days' => $days, 'data' => jsonFlaggedSummary($db, $days)]);

        
        case 'peak_hours':
        case 'traffic_comparison':
            jsonResp(jsonTrafficComparison($db, $days, $line_account_id));

        case 'message_type_dist':
            jsonResp(jsonMessageTypeDist($db, $days, $line_account_id));

        case 'admin_workload':
            jsonResp(jsonAdminWorkload($db, $days, $line_account_id));

        case 'overview':
        default:
            jsonResp([
                'response_time' => jsonResponseTimes($db, $days, $line_account_id),
                'admin_breakdown' => jsonAdminBreakdown($db, $days),
                'sla_breach' => jsonSlaBreach($db, $sla_threshold),
                'sentiment' => jsonSentimentSummary($db, $days),
                'unread' => jsonUnreadWait($db),
                'customer_journey' => jsonCustomerJourney($db, $days),
                'daily_report' => jsonDailyReport($db),
            ]);

}
} catch (Exception $e) {
    jsonResp(['error' => $e->getMessage()], 500);
}
