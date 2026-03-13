<?php
/**
 * Database Migration: BDO Context v2
 *
 * Adds new columns to odoo_bdo_context for storing financial summary,
 * selected invoices, and selected credit notes from bdo.confirmed webhook.
 *
 * Safe to run multiple times (idempotent).
 * Run: php install/migration_bdo_context_v2.php
 *
 * @version 2.0.0 (March 2026 — cny_reya_connector v11.0.1.3.0)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    $results = [];

    // ── Ensure base table exists (from migration_bdo_matching.php) ──────────
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
            webhook_delivery_id VARCHAR(128) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_line_user (line_user_id),
            INDEX idx_bdo_id (bdo_id),
            INDEX idx_state (state),
            UNIQUE KEY uk_line_bdo (line_user_id, bdo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $results[] = '✅ odoo_bdo_context table ensured';

    // ── Add new columns ──────────────────────────────────────────────────────
    $columnsToAdd = [
        'financial_summary_json'     => "MEDIUMTEXT DEFAULT NULL COMMENT 'Full financial breakdown JSON from bdo.confirmed'",
        'selected_invoices_json'      => "TEXT DEFAULT NULL COMMENT 'selected_invoices array from financial_summary'",
        'selected_credit_notes_json'  => "TEXT DEFAULT NULL COMMENT 'selected_credit_notes array from financial_summary'",
    ];

    foreach ($columnsToAdd as $column => $definition) {
        $check = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'odoo_bdo_context'
              AND COLUMN_NAME = '{$column}'
        ")->fetchColumn();

        if ((int) $check === 0) {
            $db->exec("ALTER TABLE odoo_bdo_context ADD COLUMN {$column} {$definition}");
            $results[] = "✅ Added column odoo_bdo_context.{$column}";
        } else {
            $results[] = "⏭️ Column odoo_bdo_context.{$column} already exists";
        }
    }

    // ── Add slip_inbox_id and match_confidence to odoo_slip_uploads ──────────
    $slipColumns = [
        'match_confidence'  => "VARCHAR(30) DEFAULT NULL COMMENT 'exact, partial, multi, bdo_prepayment, manual, unmatched'",
        'bdo_name'          => "VARCHAR(64) DEFAULT NULL COMMENT 'BDO number e.g. BDO2511-01778'",
        'delivery_type'     => "VARCHAR(20) DEFAULT NULL COMMENT 'company or private'",
        'bdo_amount'        => "DECIMAL(14,2) DEFAULT NULL COMMENT 'Net to pay amount from BDO'",
        'slip_inbox_id'     => "INT DEFAULT NULL COMMENT 'Odoo Slip Inbox record ID (canonical match key)'",
        'slip_inbox_name'   => "VARCHAR(64) DEFAULT NULL COMMENT 'Slip number e.g. SLIP-2603-00111'",
    ];

    foreach ($slipColumns as $column => $definition) {
        $check = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'odoo_slip_uploads'
              AND COLUMN_NAME = '{$column}'
        ")->fetchColumn();

        if ((int) $check === 0) {
            $db->exec("ALTER TABLE odoo_slip_uploads ADD COLUMN {$column} {$definition}");
            $results[] = "✅ Added column odoo_slip_uploads.{$column}";
        } else {
            $results[] = "⏭️ Column odoo_slip_uploads.{$column} already exists";
        }
    }

    // ── Add index on slip_inbox_id ───────────────────────────────────────────
    $idxCheck = $db->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'odoo_slip_uploads'
          AND INDEX_NAME = 'idx_slip_inbox_id'
    ")->fetchColumn();

    if (!(int) $idxCheck) {
        $colCheck = $db->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'odoo_slip_uploads'
              AND COLUMN_NAME = 'slip_inbox_id'
        ")->fetchColumn();

        if ((int) $colCheck) {
            $db->exec("ALTER TABLE odoo_slip_uploads ADD INDEX idx_slip_inbox_id (slip_inbox_id)");
            $results[] = '✅ Added index idx_slip_inbox_id on odoo_slip_uploads.slip_inbox_id';
        }
    } else {
        $results[] = '⏭️ Index idx_slip_inbox_id already exists';
    }

    echo "\n=== BDO Context v2 Migration ===\n\n";
    foreach ($results as $r) {
        echo "  {$r}\n";
    }
    echo "\n✅ Migration completed successfully.\n\n";

} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
