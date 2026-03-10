<?php
/**
 * Database Migration: BDO ↔ Order Linkage
 * 
 * Creates odoo_bdo_orders table (many-to-many BDO ↔ SO)
 * and adds payment-related columns to odoo_bdos.
 * Safe to run multiple times.
 * 
 * Run: php install/migration_bdo_order_link.php
 * 
 * @version 1.0.0
 * @created 2026-03-06
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    $results = [];

    // ------------------------------------------------------------------ //
    // 1. Create odoo_bdo_orders table
    // ------------------------------------------------------------------ //
    $db->exec("
        CREATE TABLE IF NOT EXISTS odoo_bdo_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bdo_id INT NOT NULL COMMENT 'Odoo BDO ID',
            bdo_name VARCHAR(64) DEFAULT NULL COMMENT 'BDO number e.g. BDO2603-00439',
            order_id INT NOT NULL COMMENT 'Odoo Sale Order ID',
            order_name VARCHAR(64) DEFAULT NULL COMMENT 'SO number e.g. SO2603-06523',
            amount_total DECIMAL(14,2) DEFAULT NULL COMMENT 'BDO total amount',
            payment_reference VARCHAR(128) DEFAULT NULL COMMENT 'ref for slip matching (= bdo_name)',
            partner_id INT DEFAULT NULL COMMENT 'Odoo partner ID',
            customer_name VARCHAR(255) DEFAULT NULL COMMENT 'Customer display name',
            line_user_id VARCHAR(64) DEFAULT NULL COMMENT 'LINE user ID',
            payment_method VARCHAR(50) DEFAULT NULL COMMENT 'promptpay / bank_transfer',
            payment_status ENUM('pending','slip_uploaded','matched','paid') DEFAULT 'pending' COMMENT 'Payment workflow status',
            slip_upload_id INT DEFAULT NULL COMMENT 'FK to odoo_slip_uploads.id',
            webhook_delivery_id VARCHAR(128) DEFAULT NULL COMMENT 'delivery_id of source webhook',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_bdo_order (bdo_id, order_id),
            INDEX idx_bdo_id (bdo_id),
            INDEX idx_order_id (order_id),
            INDEX idx_partner (partner_id),
            INDEX idx_payment_ref (payment_reference),
            INDEX idx_payment_status (payment_status),
            INDEX idx_line_user (line_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Links BDO to Sale Orders (many-to-many) with payment tracking'
    ");
    $results[] = '✅ odoo_bdo_orders table created (or already exists)';

    // ------------------------------------------------------------------ //
    // 2. Add new columns to odoo_bdos (if not exist)
    // ------------------------------------------------------------------ //
    $columnsToAdd = [
        'payment_method'    => "VARCHAR(50) DEFAULT NULL COMMENT 'promptpay / bank_transfer'",
        'payment_reference' => "VARCHAR(128) DEFAULT NULL COMMENT 'ref for slip matching (= bdo_name)'",
        'payment_status'    => "ENUM('pending','slip_uploaded','matched','paid') DEFAULT 'pending' COMMENT 'Payment workflow status'",
        'qr_data'           => "TEXT DEFAULT NULL COMMENT 'PromptPay QR raw payload'",
    ];

    foreach ($columnsToAdd as $column => $definition) {
        $check = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'odoo_bdos' 
              AND COLUMN_NAME = '$column'
        ")->fetchColumn();

        if ((int)$check === 0) {
            $db->exec("ALTER TABLE odoo_bdos ADD COLUMN $column $definition");
            $results[] = "✅ Added column odoo_bdos.$column";
        } else {
            $results[] = "⏭️ Column odoo_bdos.$column already exists";
        }
    }

    // ------------------------------------------------------------------ //
    // 3. Add index on payment_reference in odoo_bdos (if not exist)
    // ------------------------------------------------------------------ //
    $idxCheck = $db->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'odoo_bdos' 
          AND INDEX_NAME = 'idx_payment_ref'
    ")->fetchColumn();

    if ((int)$idxCheck === 0) {
        $colCheck = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'odoo_bdos' 
              AND COLUMN_NAME = 'payment_reference'
        ")->fetchColumn();

        if ((int)$colCheck > 0) {
            $db->exec("ALTER TABLE odoo_bdos ADD INDEX idx_payment_ref (payment_reference)");
            $results[] = '✅ Added index idx_payment_ref on odoo_bdos.payment_reference';
        }
    } else {
        $results[] = '⏭️ Index idx_payment_ref already exists';
    }

    // ------------------------------------------------------------------ //
    // 4. Add index on payment_status in odoo_bdos (if not exist)
    // ------------------------------------------------------------------ //
    $idxCheck2 = $db->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'odoo_bdos' 
          AND INDEX_NAME = 'idx_payment_status'
    ")->fetchColumn();

    if ((int)$idxCheck2 === 0) {
        $colCheck2 = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'odoo_bdos' 
              AND COLUMN_NAME = 'payment_status'
        ")->fetchColumn();

        if ((int)$colCheck2 > 0) {
            $db->exec("ALTER TABLE odoo_bdos ADD INDEX idx_payment_status (payment_status)");
            $results[] = '✅ Added index idx_payment_status on odoo_bdos.payment_status';
        }
    } else {
        $results[] = '⏭️ Index idx_payment_status already exists';
    }

    // ------------------------------------------------------------------ //
    // Summary
    // ------------------------------------------------------------------ //
    echo "\n=== BDO-Order Link Migration ===\n\n";
    foreach ($results as $r) {
        echo "  $r\n";
    }
    echo "\n✅ Migration completed successfully.\n\n";

} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
