<?php
/**
 * Cron: Rebuild Customer Projections (Updated v2.0)
 * 
 * Rebuilds odoo_customer_projection from webhook logs.
 * Schedule: Every 10 minutes
 * 
 * Changes v2.0:
 * - Added partner_name, customer_id, salesperson_id/name, spend_total, orders_count_total
 * - Changed UNIQUE KEY from line_user_id → odoo_partner_id (set in Phase 1.2)
 * - Covers ALL customers including those without LINE user ID
 * 
 * Usage: php cron/rebuild-customer-projections.php
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

$log('Starting customer projection rebuild v2.0...');

try {
    $db = Database::getInstance()->getConnection();

    // Check table exists
    try {
        $db->query("SELECT 1 FROM odoo_customer_projection LIMIT 1");
    } catch (Exception $e) {
        $log('ERROR: odoo_customer_projection table does not exist.');
        exit(1);
    }

    // Step 1: Aggregate base customer info from webhooks
    $log('Querying webhook log for unique customers...');
    $stmt = $db->query("
        SELECT
            NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')) AS UNSIGNED), 0) AS odoo_partner_id,
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')), '') AS customer_ref,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '')) AS customer_name,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.partner_name')), '')) AS partner_name,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')), '')) AS customer_id,
            MAX(line_user_id) AS line_user_id,
            MAX(order_id) AS latest_order_id,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), '')) AS latest_order_name,
            MAX(processed_at) AS latest_order_at,
            COUNT(DISTINCT CASE WHEN processed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                THEN COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR)) 
                END) AS orders_count_30d,
            COUNT(DISTINCT CASE WHEN processed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) 
                THEN COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR)) 
                END) AS orders_count_90d,
            COUNT(DISTINCT 
                COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), ''), CAST(order_id AS CHAR))
            ) AS orders_count_total,
            SUM(CASE WHEN processed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                THEN CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2)) 
                ELSE 0 END) AS spend_30d,
            SUM(CASE WHEN processed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) 
                THEN CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2)) 
                ELSE 0 END) AS spend_90d,
            SUM(CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), ''), '0') AS DECIMAL(14,2))) AS spend_total,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.id')), '')) AS salesperson_id,
            MAX(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.salesperson.name')), '')) AS salesperson_name
        FROM odoo_webhooks_log
        WHERE status = 'success'
          AND (
            NULLIF(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')) AS UNSIGNED), 0) IS NOT NULL
          )
        GROUP BY odoo_partner_id
        HAVING odoo_partner_id IS NOT NULL
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $log('Found ' . count($customers) . ' unique customers in webhook log.');

    // Step 2: Upsert each customer
    $upserted = 0;
    $errors = 0;
    $upsertSql = "
        INSERT INTO odoo_customer_projection
            (odoo_partner_id, customer_ref, customer_name, partner_name, customer_id,
             line_user_id, latest_order_id, latest_order_name, latest_order_at,
             orders_count_30d, orders_count_90d, orders_count_total,
             spend_30d, spend_90d, spend_total,
             salesperson_id, salesperson_name, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            customer_ref         = COALESCE(VALUES(customer_ref), customer_ref),
            customer_name        = COALESCE(VALUES(customer_name), customer_name),
            partner_name         = COALESCE(VALUES(partner_name), partner_name),
            customer_id          = COALESCE(VALUES(customer_id), customer_id),
            line_user_id         = COALESCE(VALUES(line_user_id), line_user_id),
            latest_order_id      = VALUES(latest_order_id),
            latest_order_name    = COALESCE(VALUES(latest_order_name), latest_order_name),
            latest_order_at      = VALUES(latest_order_at),
            orders_count_30d     = VALUES(orders_count_30d),
            orders_count_90d     = VALUES(orders_count_90d),
            orders_count_total   = VALUES(orders_count_total),
            spend_30d            = VALUES(spend_30d),
            spend_90d            = VALUES(spend_90d),
            spend_total          = VALUES(spend_total),
            salesperson_id       = COALESCE(VALUES(salesperson_id), salesperson_id),
            salesperson_name     = COALESCE(VALUES(salesperson_name), salesperson_name),
            updated_at           = NOW()
    ";
    $upsertStmt = $db->prepare($upsertSql);

    foreach ($customers as $cust) {
        $pid = $cust['odoo_partner_id'];
        if (!$pid || !is_numeric($pid) || (int)$pid <= 0) continue;

        try {
            $upsertStmt->execute([
                (int) $pid,
                $cust['customer_ref'],
                $cust['customer_name'],
                $cust['partner_name'],
                $cust['customer_id'],
                $cust['line_user_id'],
                $cust['latest_order_id'] ? (int)$cust['latest_order_id'] : null,
                $cust['latest_order_name'],
                $cust['latest_order_at'],
                (int) $cust['orders_count_30d'],
                (int) $cust['orders_count_90d'],
                (int) $cust['orders_count_total'],
                (float) $cust['spend_30d'],
                (float) $cust['spend_90d'],
                (float) $cust['spend_total'],
                $cust['salesperson_id'],
                $cust['salesperson_name'],
            ]);
            $upserted++;
        } catch (Exception $e) {
            $errors++;
            $log("ERROR upsert partner_id={$pid}: " . $e->getMessage());
        }
    }

    $duration = round(microtime(true) - $startTime, 2);
    $log("Rebuild complete: {$upserted} customers upserted, {$errors} errors, in {$duration}s.");

    // Log final count
    $countStmt = $db->query("SELECT COUNT(*) FROM odoo_customer_projection");
    $totalRows = $countStmt->fetchColumn();
    $log("Total rows in projection table: {$totalRows}");

} catch (Exception $e) {
    $log('FATAL ERROR: ' . $e->getMessage());
    exit(1);
}
