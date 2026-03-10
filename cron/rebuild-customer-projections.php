<?php
/**
 * Cron: Rebuild Customer Projections
 * 
 * Rebuilds odoo_customer_projection table from webhook logs and Odoo API data.
 * Schedule: Daily at 03:00 AM
 * 
 * Usage: php cron/rebuild-customer-projections.php
 * 
 * @version 1.0.0
 */

set_time_limit(300);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$startTime = microtime(true);
$log = function ($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
    error_log("[rebuild-projections] {$msg}");
};

$log('Starting customer projection rebuild...');

try {
    $db = Database::getInstance()->getConnection();

    // Check if projection table exists
    try {
        $db->query("SELECT 1 FROM odoo_customer_projection LIMIT 1");
    } catch (Exception $e) {
        $log('ERROR: odoo_customer_projection table does not exist. Run migrations first.');
        exit(1);
    }

    // Get all unique customers from webhook log
    $pidExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')), ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), ''))";
    $nameExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')";
    $refExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '')";
    $amtExpr = "CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))";
    $orderKeyExpr = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR))";

    $stmt = $db->query("
        SELECT
            {$pidExpr} AS partner_id,
            MAX({$nameExpr}) AS customer_name,
            MAX({$refExpr}) AS customer_ref,
            MAX(line_user_id) AS line_user_id,
            COUNT(DISTINCT {$orderKeyExpr}) AS orders_count_total,
            COUNT(DISTINCT CASE WHEN processed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN {$orderKeyExpr} END) AS orders_count_30d,
            MAX(processed_at) AS latest_order_at
        FROM odoo_webhooks_log
        WHERE status = 'success'
          AND {$pidExpr} IS NOT NULL
        GROUP BY {$pidExpr}
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $log('Found ' . count($customers) . ' unique customers in webhook log.');

    // Compute spend per customer (MAX amount per unique order, then SUM)
    $spendStmt = $db->query("
        SELECT ckey, SUM(max_amt) AS spend_total,
               SUM(CASE WHEN last_event >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN max_amt ELSE 0 END) AS spend_30d
        FROM (
            SELECT {$pidExpr} AS ckey,
                   {$orderKeyExpr} AS okey,
                   MAX({$amtExpr}) AS max_amt,
                   MAX(processed_at) AS last_event
            FROM odoo_webhooks_log
            WHERE status = 'success' AND {$pidExpr} IS NOT NULL
            GROUP BY ckey, okey
        ) per_order
        GROUP BY ckey
    ");
    $spendMap = [];
    while ($row = $spendStmt->fetch(PDO::FETCH_ASSOC)) {
        $spendMap[$row['ckey']] = $row;
    }

    // Upsert each customer
    $upserted = 0;
    $upsertSql = "
        INSERT INTO odoo_customer_projection
            (odoo_partner_id, customer_name, customer_ref, line_user_id,
             orders_count_total, orders_count_30d, spend_30d,
             latest_order_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            customer_name = VALUES(customer_name),
            customer_ref = VALUES(customer_ref),
            line_user_id = COALESCE(VALUES(line_user_id), line_user_id),
            orders_count_total = VALUES(orders_count_total),
            orders_count_30d = VALUES(orders_count_30d),
            spend_30d = VALUES(spend_30d),
            latest_order_at = VALUES(latest_order_at),
            updated_at = NOW()
    ";
    $upsertStmt = $db->prepare($upsertSql);

    foreach ($customers as $cust) {
        $pid = $cust['partner_id'];
        if (!$pid || !is_numeric($pid)) continue;

        $spend = $spendMap[$pid] ?? ['spend_total' => 0, 'spend_30d' => 0];

        $upsertStmt->execute([
            (int) $pid,
            $cust['customer_name'],
            $cust['customer_ref'],
            $cust['line_user_id'],
            (int) $cust['orders_count_total'],
            (int) $cust['orders_count_30d'],
            (float) $spend['spend_30d'],
            $cust['latest_order_at'],
        ]);
        $upserted++;
    }

    $duration = round(microtime(true) - $startTime, 2);
    $log("Rebuild complete: {$upserted} customers upserted in {$duration}s.");

} catch (Exception $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
