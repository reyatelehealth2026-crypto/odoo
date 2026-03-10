<?php
/**
 * Odoo Webhook Pipeline Migration
 *
 * Phase 1-2 schema upgrade:
 * - Expand webhook lifecycle statuses
 * - Add observability columns for webhook processing
 * - Add retry/dead-letter support table
 * - Add projection tables for Customer 360 reads
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function tableExists(PDO $db, $table)
{
    $pattern = $db->quote($table);
    $stmt = $db->query("SHOW TABLES LIKE {$pattern}");
    return $stmt && $stmt->rowCount() > 0;
}

function columnExists(PDO $db, $table, $column)
{
    $table = str_replace('`', '``', $table);
    $columnPattern = $db->quote($column);
    $stmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE {$columnPattern}");
    return $stmt && $stmt->rowCount() > 0;
}

function indexExists(PDO $db, $table, $index)
{
    $table = str_replace('`', '``', $table);
    $indexValue = $db->quote($index);
    $stmt = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = {$indexValue}");
    return $stmt && $stmt->rowCount() > 0;
}

function runSql(PDO $db, $sql, $label)
{
    try {
        $db->exec($sql);
        echo "✓ {$label}\n";
    } catch (Exception $e) {
        echo "✗ {$label}: " . $e->getMessage() . "\n";
    }
}

function addColumn(PDO $db, $table, $column, $definition)
{
    if (columnExists($db, $table, $column)) {
        echo "• Column {$table}.{$column} already exists\n";
        return;
    }

    runSql(
        $db,
        "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}",
        "Added column {$table}.{$column}"
    );
}

function addIndex(PDO $db, $table, $index, $definition)
{
    if (indexExists($db, $table, $index)) {
        echo "• Index {$table}.{$index} already exists\n";
        return;
    }

    runSql(
        $db,
        "ALTER TABLE `{$table}` ADD INDEX `{$index}` {$definition}",
        "Added index {$table}.{$index}"
    );
}

echo "<h2>Odoo Webhook Pipeline Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();

    if (!tableExists($db, 'odoo_webhooks_log')) {
        throw new Exception("Table 'odoo_webhooks_log' not found. Please run install/run_odoo_integration_migration.php first.");
    }

    echo "Step 1) Upgrade webhook lifecycle schema\n";
    runSql(
        $db,
        "ALTER TABLE `odoo_webhooks_log`
            MODIFY COLUMN `status` ENUM(
                'received',
                'processing',
                'success',
                'failed',
                'duplicate',
                'retry',
                'dead_letter'
            ) NOT NULL DEFAULT 'received' COMMENT 'Webhook lifecycle status'",
        'Expanded odoo_webhooks_log.status enum'
    );

    addColumn($db, 'odoo_webhooks_log', 'received_at', "DATETIME NULL DEFAULT NULL COMMENT 'When webhook was initially received' AFTER `signature`");
    addColumn($db, 'odoo_webhooks_log', 'processing_started_at', "DATETIME NULL DEFAULT NULL COMMENT 'When processing started' AFTER `received_at`");
    addColumn($db, 'odoo_webhooks_log', 'retry_count', "INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Retry attempts for this delivery' AFTER `processing_started_at`");
    addColumn($db, 'odoo_webhooks_log', 'attempt_count', "INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Total receive attempts for this delivery_id' AFTER `retry_count`");
    addColumn($db, 'odoo_webhooks_log', 'process_latency_ms', "INT UNSIGNED NULL DEFAULT NULL COMMENT 'End-to-end processing latency (ms)' AFTER `attempt_count`");
    addColumn($db, 'odoo_webhooks_log', 'last_error_code', "VARCHAR(64) NULL DEFAULT NULL COMMENT 'Stable internal error code' AFTER `error_message`");
    addColumn($db, 'odoo_webhooks_log', 'payload_hash', "CHAR(64) NULL DEFAULT NULL COMMENT 'SHA256 hash of payload for forensic checks' AFTER `payload`");
    addColumn($db, 'odoo_webhooks_log', 'source_ip', "VARCHAR(45) NULL DEFAULT NULL COMMENT 'Source IP received at webhook endpoint' AFTER `line_user_id`");
    addColumn($db, 'odoo_webhooks_log', 'webhook_timestamp', "BIGINT NULL DEFAULT NULL COMMENT 'X-Odoo-Timestamp header value' AFTER `source_ip`");
    addColumn($db, 'odoo_webhooks_log', 'header_json', "JSON NULL COMMENT 'Captured webhook headers snapshot' AFTER `webhook_timestamp`");
    addColumn($db, 'odoo_webhooks_log', 'notified_targets', "JSON NULL COMMENT 'List of notification targets that were sent' AFTER `header_json`");

    runSql(
        $db,
        "UPDATE `odoo_webhooks_log`
         SET `received_at` = COALESCE(`received_at`, `processed_at`, NOW())",
        'Backfilled received_at values'
    );

    runSql(
        $db,
        "UPDATE `odoo_webhooks_log`
         SET `attempt_count` = CASE WHEN `attempt_count` IS NULL OR `attempt_count` < 1 THEN 1 ELSE `attempt_count` END",
        'Normalized attempt_count values'
    );

    addIndex($db, 'odoo_webhooks_log', 'idx_received_at', '(`received_at`)');
    addIndex($db, 'odoo_webhooks_log', 'idx_payload_hash', '(`payload_hash`)');
    addIndex($db, 'odoo_webhooks_log', 'idx_retry_count', '(`retry_count`)');
    addIndex($db, 'odoo_webhooks_log', 'idx_last_error_code', '(`last_error_code`)');
    addIndex($db, 'odoo_webhooks_log', 'idx_status_processed', '(`status`, `processed_at`)');
    addIndex($db, 'odoo_webhooks_log', 'idx_event_status_processed', '(`event_type`, `status`, `processed_at`)');
    addIndex($db, 'odoo_webhooks_log', 'idx_line_order_time', '(`line_user_id`, `order_id`, `processed_at`)');

    echo "\nStep 2) Create dead-letter queue table\n";
    runSql(
        $db,
        "CREATE TABLE IF NOT EXISTS `odoo_webhook_dlq` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `delivery_id` VARCHAR(100) NOT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `payload` JSON NOT NULL,
            `error_code` VARCHAR(64) NULL,
            `error_message` TEXT NULL,
            `retry_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `failed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `resolved_at` DATETIME NULL,
            `resolution_note` VARCHAR(255) NULL,
            UNIQUE KEY `uq_dlq_delivery_id` (`delivery_id`),
            INDEX `idx_dlq_failed_at` (`failed_at`),
            INDEX `idx_dlq_event_type` (`event_type`),
            INDEX `idx_dlq_resolved_at` (`resolved_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Created odoo_webhook_dlq'
    );

    echo "\nStep 3) Create Customer 360 projection tables\n";
    runSql(
        $db,
        "CREATE TABLE IF NOT EXISTS `odoo_order_projection` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `order_id` INT NOT NULL,
            `order_name` VARCHAR(120) NULL,
            `line_user_id` VARCHAR(100) NULL,
            `odoo_partner_id` INT NULL,
            `customer_name` VARCHAR(255) NULL,
            `customer_ref` VARCHAR(100) NULL,
            `latest_event_type` VARCHAR(100) NULL,
            `latest_state` VARCHAR(100) NULL,
            `latest_state_display` VARCHAR(150) NULL,
            `amount_total` DECIMAL(14,2) NULL,
            `currency` VARCHAR(10) NULL DEFAULT 'THB',
            `source_delivery_id` VARCHAR(100) NULL,
            `source_status` VARCHAR(50) NULL,
            `last_webhook_at` DATETIME NULL,
            `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_order_projection_order_id` (`order_id`),
            INDEX `idx_order_projection_line_user` (`line_user_id`),
            INDEX `idx_order_projection_partner` (`odoo_partner_id`),
            INDEX `idx_order_projection_state` (`latest_state`),
            INDEX `idx_order_projection_last_webhook` (`last_webhook_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Created odoo_order_projection'
    );

    runSql(
        $db,
        "CREATE TABLE IF NOT EXISTS `odoo_customer_projection` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `line_user_id` VARCHAR(100) NOT NULL,
            `odoo_partner_id` INT NULL,
            `customer_name` VARCHAR(255) NULL,
            `customer_ref` VARCHAR(100) NULL,
            `credit_limit` DECIMAL(14,2) NULL,
            `credit_used` DECIMAL(14,2) NULL,
            `credit_remaining` DECIMAL(14,2) NULL,
            `total_due` DECIMAL(14,2) NULL,
            `overdue_amount` DECIMAL(14,2) NULL,
            `latest_order_id` INT NULL,
            `latest_order_name` VARCHAR(120) NULL,
            `latest_order_at` DATETIME NULL,
            `orders_count_30d` INT UNSIGNED NOT NULL DEFAULT 0,
            `orders_count_90d` INT UNSIGNED NOT NULL DEFAULT 0,
            `spend_30d` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `spend_90d` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_customer_projection_line_user` (`line_user_id`),
            INDEX `idx_customer_projection_partner` (`odoo_partner_id`),
            INDEX `idx_customer_projection_latest_order` (`latest_order_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Created odoo_customer_projection'
    );

    runSql(
        $db,
        "CREATE TABLE IF NOT EXISTS `odoo_customer_product_stats` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `line_user_id` VARCHAR(100) NOT NULL,
            `odoo_partner_id` INT NULL,
            `product_id` INT NULL,
            `product_code` VARCHAR(100) NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `qty_30d` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `qty_90d` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `amount_30d` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `amount_90d` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            `last_purchased_at` DATETIME NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_customer_product_stats_line_product` (`line_user_id`, `product_name`),
            INDEX `idx_customer_product_stats_partner` (`odoo_partner_id`),
            INDEX `idx_customer_product_stats_amount_90d` (`amount_90d`),
            INDEX `idx_customer_product_stats_last_purchase` (`last_purchased_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Created odoo_customer_product_stats'
    );

    echo "\nMigration completed.\n";
    echo "Next recommended steps:\n";
    echo "1) Deploy updated webhook endpoint + handler\n";
    echo "2) Backfill projection tables from existing webhook logs\n";
    echo "3) Enable Customer 360 API consumers\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}

echo "</pre>";
