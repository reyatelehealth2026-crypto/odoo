<?php
/**
 * Migration: Add slip verification columns to odoo_slip_uploads
 * 
 * Stores SlipMate API verification results alongside slip records.
 * 
 * Run once on server:
 *   php install/migration_slip_verification.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    echo "=== Slip Verification Migration ===\n\n";

    // Check if columns already exist
    $cols = $db->query("SHOW COLUMNS FROM odoo_slip_uploads LIKE 'slip_verified'")->fetchAll();
    if (count($cols) > 0) {
        echo "✓ Columns already exist — skipping migration.\n";
        exit(0);
    }

    $db->exec("
        ALTER TABLE odoo_slip_uploads
        ADD COLUMN slip_verified TINYINT(1) DEFAULT NULL COMMENT 'null=not checked, 1=verified, 0=failed' AFTER message_id,
        ADD COLUMN slip_verify_ref VARCHAR(100) DEFAULT NULL COMMENT 'Transaction reference from SlipMate' AFTER slip_verified,
        ADD COLUMN slip_verify_amount DECIMAL(12,2) DEFAULT NULL COMMENT 'Amount verified by SlipMate' AFTER slip_verify_ref,
        ADD COLUMN slip_verify_data JSON DEFAULT NULL COMMENT 'Full SlipData payload from SlipMate' AFTER slip_verify_amount,
        ADD COLUMN slip_verified_at DATETIME DEFAULT NULL COMMENT 'When verification was performed' AFTER slip_verify_data
    ");

    echo "✓ Added columns: slip_verified, slip_verify_ref, slip_verify_amount, slip_verify_data, slip_verified_at\n";

    // Add index on slip_verified for filtering
    $db->exec("
        ALTER TABLE odoo_slip_uploads
        ADD INDEX idx_slip_verified (slip_verified)
    ");

    echo "✓ Added index: idx_slip_verified\n";
    echo "\n=== Migration complete ===\n";

} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
