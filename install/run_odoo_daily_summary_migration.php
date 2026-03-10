#!/usr/bin/env php
<?php
/**
 * Quick Installation Script for Odoo Daily Summary Auto-Send
 * 
 * This script will:
 * 1. Create required database tables
 * 2. Insert default settings
 * 3. Verify installation
 * 
 * Usage: php install/run_odoo_daily_summary_migration.php
 * 
 * @version 1.0.0
 * @created 2026-02-26
 */

echo "========================================\n";
echo "Odoo Daily Summary Auto-Send Installation\n";
echo "========================================\n\n";

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "[1/4] Creating odoo_daily_summary_settings table...\n";
    
    $sql1 = "CREATE TABLE IF NOT EXISTS `odoo_daily_summary_settings` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `setting_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Setting identifier',
      `setting_value` TEXT COMMENT 'Setting value (JSON or plain text)',
      `enabled` TINYINT(1) DEFAULT 1 COMMENT 'Whether this setting is active',
      `updated_by` VARCHAR(100) DEFAULT NULL COMMENT 'Admin user who last updated',
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_setting_key` (`setting_key`),
      INDEX `idx_enabled` (`enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Settings for automated daily summary notifications'";
    
    $db->exec($sql1);
    echo "   ✓ Table created successfully\n\n";
    
    echo "[2/4] Creating odoo_daily_summary_auto_log table...\n";
    
    $sql2 = "CREATE TABLE IF NOT EXISTS `odoo_daily_summary_auto_log` (
      `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `execution_date` DATE NOT NULL COMMENT 'Date this auto-send was executed',
      `execution_time` DATETIME NOT NULL COMMENT 'Actual execution timestamp',
      `scheduled_time` TIME NOT NULL COMMENT 'Configured scheduled time',
      `total_recipients` INT DEFAULT 0 COMMENT 'Total users eligible',
      `sent_count` INT DEFAULT 0 COMMENT 'Successfully sent',
      `failed_count` INT DEFAULT 0 COMMENT 'Failed to send',
      `skipped_count` INT DEFAULT 0 COMMENT 'Skipped (already sent today)',
      `execution_duration_ms` INT DEFAULT 0 COMMENT 'Execution time in milliseconds',
      `status` ENUM('success', 'partial', 'failed') DEFAULT 'success',
      `error_message` TEXT DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_execution_date` (`execution_date`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Execution log for automated daily summary sends'";
    
    $db->exec($sql2);
    echo "   ✓ Table created successfully\n\n";
    
    echo "[3/4] Inserting default settings...\n";
    
    $sql3 = "INSERT INTO `odoo_daily_summary_settings` (`setting_key`, `setting_value`, `enabled`) VALUES
        ('auto_send_enabled', '0', 1),
        ('send_time', '09:00', 1),
        ('send_timezone', 'Asia/Bangkok', 1),
        ('lookback_days', '1', 1),
        ('last_sent_date', NULL, 1)
    ON DUPLICATE KEY UPDATE 
        `setting_key` = VALUES(`setting_key`)";
    
    $db->exec($sql3);
    echo "   ✓ Default settings inserted\n\n";
    
    echo "[4/4] Verifying installation...\n";
    
    // Check tables exist
    $stmt = $db->query("SHOW TABLES LIKE 'odoo_daily_summary_settings'");
    $table1Exists = $stmt->rowCount() > 0;
    
    $stmt = $db->query("SHOW TABLES LIKE 'odoo_daily_summary_auto_log'");
    $table2Exists = $stmt->rowCount() > 0;
    
    if (!$table1Exists || !$table2Exists) {
        throw new Exception("Tables were not created properly");
    }
    
    // Check settings count
    $stmt = $db->query("SELECT COUNT(*) FROM odoo_daily_summary_settings");
    $settingsCount = $stmt->fetchColumn();
    
    echo "   ✓ Tables verified\n";
    echo "   ✓ Settings count: {$settingsCount}\n\n";
    
    echo "========================================\n";
    echo "✓ Installation completed successfully!\n";
    echo "========================================\n\n";
    
    echo "Next steps:\n";
    echo "1. Set up cron job to run: cron/odoo_daily_summary_auto.php\n";
    echo "   Example: * * * * * php " . realpath(__DIR__ . '/../cron/odoo_daily_summary_auto.php') . "\n\n";
    echo "2. Go to odoo-dashboard.php and click 'สรุปประจำวัน'\n";
    echo "3. Enable auto-send and set your preferred time\n\n";
    echo "For detailed instructions, see: install/INSTALL_ODOO_DAILY_SUMMARY_AUTO.md\n\n";
    
    exit(0);
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n\n";
    exit(1);
}
