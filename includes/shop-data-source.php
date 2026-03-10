<?php
/**
 * Shop data source helpers
 * Supports: shop | odoo
 */

if (!function_exists('normalizeShopOrderDataSource')) {
    function normalizeShopOrderDataSource($value)
    {
        $mode = strtolower(trim((string) $value));
        return $mode === 'odoo' ? 'odoo' : 'shop';
    }
}

if (!function_exists('ensureShopOrderDataSourceColumn')) {
    function ensureShopOrderDataSourceColumn($db)
    {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM shop_settings LIKE 'order_data_source'");
            if ($stmt->rowCount() === 0) {
                $db->exec("ALTER TABLE shop_settings ADD COLUMN order_data_source VARCHAR(20) DEFAULT 'shop'");
            }
        } catch (Exception $e) {
            // Keep silent - caller will fallback to default mode
        }
    }
}

if (!function_exists('getShopOrderDataSource')) {
    function getShopOrderDataSource($db, $lineAccountId = null)
    {
        ensureShopOrderDataSourceColumn($db);

        try {
            if ($lineAccountId) {
                $stmt = $db->prepare("SELECT order_data_source FROM shop_settings WHERE line_account_id = ? LIMIT 1");
                $stmt->execute([$lineAccountId]);
                $value = $stmt->fetchColumn();
                if ($value !== false && $value !== null && $value !== '') {
                    return normalizeShopOrderDataSource($value);
                }
            }

            $stmt = $db->query("SELECT order_data_source FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1");
            $value = $stmt->fetchColumn();
            return normalizeShopOrderDataSource($value);
        } catch (Exception $e) {
            return 'shop';
        }
    }
}
