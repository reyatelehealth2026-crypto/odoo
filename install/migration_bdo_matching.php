<?php
/**
 * Database Migration: BDO Matching Workflow Support
 * 
 * Creates odoo_bdo_context table and adds new columns to odoo_slip_uploads.
 * Safe to run multiple times (uses IF NOT EXISTS / IF NOT EXISTS checks).
 * 
 * Run: php install/migration_bdo_matching.php
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
    // 1. Create odoo_bdo_context table
    // ------------------------------------------------------------------ //
    $db->exec("
        CREATE TABLE IF NOT EXISTS odoo_bdo_context (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(64) NOT NULL,
            bdo_id INT NOT NULL,
            bdo_name VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(14,2) DEFAULT NULL,
            delivery_type VARCHAR(20) DEFAULT NULL COMMENT 'company or private',
            state VARCHAR(20) DEFAULT 'waiting',
            qr_payload TEXT DEFAULT NULL COMMENT 'PromptPay QR raw payload',
            statement_pdf_path VARCHAR(255) DEFAULT NULL,
            financial_summary_json JSON DEFAULT NULL,
            selected_invoices_json JSON DEFAULT NULL,
            selected_credit_notes_json JSON DEFAULT NULL,
            webhook_delivery_id VARCHAR(128) DEFAULT NULL COMMENT 'delivery_id of bdo.confirmed webhook',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_line_user (line_user_id),
            INDEX idx_bdo_id (bdo_id),
            INDEX idx_state (state),
            UNIQUE KEY uk_line_bdo (line_user_id, bdo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = '✅ odoo_bdo_context table created (or already exists)';

    // ------------------------------------------------------------------ //
    // 2. Add new columns to odoo_slip_uploads (if not exist)
    // ------------------------------------------------------------------ //
    $columnsToAdd = [
        'match_confidence'  => "VARCHAR(30) DEFAULT NULL COMMENT 'exact, partial, multi, bdo_prepayment, manual, unmatched'",
        'bdo_name'          => "VARCHAR(64) DEFAULT NULL COMMENT 'BDO number e.g. BDO2511-01778'",
        'delivery_type'     => "VARCHAR(20) DEFAULT NULL COMMENT 'company or private'",
        'bdo_amount'        => "DECIMAL(14,2) DEFAULT NULL COMMENT 'Net to pay amount from BDO'",
        'slip_inbox_id'     => "INT DEFAULT NULL COMMENT 'Odoo Slip Inbox record ID'",
        'slip_inbox_name'   => "VARCHAR(64) DEFAULT NULL COMMENT 'Slip number e.g. SLIP-2603-00111'",
    ];

    foreach ($columnsToAdd as $column => $definition) {
        $check = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'odoo_slip_uploads' 
              AND COLUMN_NAME = '$column'
        ")->fetchColumn();

        if ((int)$check === 0) {
            $db->exec("ALTER TABLE odoo_slip_uploads ADD COLUMN $column $definition");
            $results[] = "✅ Added column odoo_slip_uploads.$column";
        } else {
            $results[] = "⏭️ Column odoo_slip_uploads.$column already exists";
        }
    }

    // ------------------------------------------------------------------ //
    // 2.5 Add new columns to odoo_bdo_context (if not exist)
    // ------------------------------------------------------------------ //
    $contextColumnsToAdd = [
        'financial_summary_json' => "JSON DEFAULT NULL",
        'selected_invoices_json' => "JSON DEFAULT NULL",
        'selected_credit_notes_json' => "JSON DEFAULT NULL",
    ];

    foreach ($contextColumnsToAdd as $column => $definition) {
        $check = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'odoo_bdo_context'
              AND COLUMN_NAME = '$column'
        ")->fetchColumn();

        if ((int)$check === 0) {
            $db->exec("ALTER TABLE odoo_bdo_context ADD COLUMN $column $definition");
            $results[] = "✅ Added column odoo_bdo_context.$column";
        } else {
            $results[] = "⏭️ Column odoo_bdo_context.$column already exists";
        }
    }

    // ------------------------------------------------------------------ //
    // 3. Add index on bdo_id in odoo_slip_uploads (if not exist)
    // ------------------------------------------------------------------ //
    $idxCheck = $db->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'odoo_slip_uploads' 
          AND INDEX_NAME = 'idx_slip_bdo_id'
    ")->fetchColumn();

    if ((int)$idxCheck === 0) {
        // Check if bdo_id column exists first
        $bdoColCheck = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'odoo_slip_uploads' 
              AND COLUMN_NAME = 'bdo_id'
        ")->fetchColumn();

        if ((int)$bdoColCheck > 0) {
            $db->exec("ALTER TABLE odoo_slip_uploads ADD INDEX idx_slip_bdo_id (bdo_id)");
            $results[] = '✅ Added index idx_slip_bdo_id on odoo_slip_uploads.bdo_id';
        }
    } else {
        $results[] = '⏭️ Index idx_slip_bdo_id already exists';
    }

    // ------------------------------------------------------------------ //
    // Summary
    // ------------------------------------------------------------------ //
    echo "\n=== BDO Matching Migration ===\n\n";
    foreach ($results as $r) {
        echo "  $r\n";
    }
    echo "\n✅ Migration completed successfully.\n\n";

} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
