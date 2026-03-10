<?php
/**
 * Odoo order analytics helpers (webhook snapshot based)
 */

if (!function_exists('odooHasColumn')) {
    function odooHasColumn($db, $table, $column)
    {
        try {
            $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            try {
                $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE " . $db->quote($column));
                return $stmt && $stmt->rowCount() > 0;
            } catch (Exception $inner) {
                return false;
            }
        }
    }
}

if (!function_exists('buildOdooWebhookSnapshotBase')) {
    /**
     * Returns SQL expressions + base subquery for Odoo webhook order snapshots.
     */
    function buildOdooWebhookSnapshotBase($db, $lineAccountId = null, $searchTerm = '')
    {
        $orderKeyExpr = "COALESCE(CAST(order_id AS CHAR), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')))";
        $stateExpr = "LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state')), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')), ''))";
        $amountExpr = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')), '0') AS DECIMAL(12,2))";
        $customerExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')), '-')";

        $where = "status = 'success' AND {$orderKeyExpr} IS NOT NULL AND {$orderKeyExpr} != ''";
        $params = [];

        if ($lineAccountId !== null && odooHasColumn($db, 'odoo_webhooks_log', 'line_account_id')) {
            $where .= ' AND (line_account_id = ? OR line_account_id IS NULL)';
            $params[] = $lineAccountId;
        }

        $searchTerm = trim((string) $searchTerm);
        if ($searchTerm !== '') {
            $where .= " AND ({$orderKeyExpr} LIKE ? OR {$customerExpr} LIKE ?)";
            $like = '%' . $searchTerm . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $baseSubquery = "
            SELECT
                {$orderKeyExpr} AS order_key,
                processed_at,
                {$amountExpr} AS amount_total,
                {$stateExpr} AS order_state,
                {$customerExpr} AS customer_name
            FROM odoo_webhooks_log
            WHERE {$where}
        ";

        $snapshotSql = "
            SELECT
                order_key,
                MIN(processed_at) AS created_at,
                MAX(processed_at) AS updated_at,
                MAX(amount_total) AS amount_total,
                SUBSTRING_INDEX(GROUP_CONCAT(order_state ORDER BY processed_at DESC), ',', 1) AS status,
                SUBSTRING_INDEX(GROUP_CONCAT(customer_name ORDER BY processed_at DESC), ',', 1) AS customer_name
            FROM ({$baseSubquery}) s
            GROUP BY order_key
        ";

        return [
            'base_subquery' => $baseSubquery,
            'snapshot_sql' => $snapshotSql,
            'params' => $params
        ];
    }
}

if (!function_exists('getOdooOrderStateBuckets')) {
    function getOdooOrderStateBuckets()
    {
        return [
            'pending' => ['draft', 'sent', 'pending', 'confirmed'],
            'completed' => ['sale', 'done', 'paid', 'delivered', 'completed'],
            'cancelled' => ['cancel', 'cancelled']
        ];
    }
}
