<?php
/**
 * Migration: Add missing columns to pharmacist_notifications table
 */
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔧 Pharmacist Notifications Migration</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'pharmacist_notifications'");
    if ($stmt->rowCount() == 0) {
        echo "<p>❌ Table pharmacist_notifications does not exist. Creating...</p>";
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS `pharmacist_notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `line_account_id` INT NULL,
                `type` VARCHAR(50) DEFAULT 'emergency_alert',
                `title` VARCHAR(255),
                `message` TEXT,
                `notification_data` JSON,
                `reference_id` INT NULL,
                `reference_type` VARCHAR(50) NULL,
                `user_id` INT NULL,
                `triage_session_id` INT NULL,
                `priority` ENUM('normal', 'urgent') DEFAULT 'normal',
                `status` ENUM('pending', 'handled', 'dismissed') DEFAULT 'pending',
                `is_read` TINYINT(1) DEFAULT 0,
                `handled_by` INT NULL,
                `handled_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_line_account` (`line_account_id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_priority` (`priority`),
                INDEX `idx_is_read` (`is_read`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "<p>✅ Table created successfully</p>";
    } else {
        echo "<p>✅ Table pharmacist_notifications exists</p>";
        
        // Check and add missing columns
        $columns = ['reference_id', 'reference_type', 'triage_session_id'];
        
        foreach ($columns as $column) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'pharmacist_notifications' 
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$column]);
            
            if ($stmt->fetchColumn() == 0) {
                echo "<p>Adding column: {$column}...</p>";
                
                switch ($column) {
                    case 'reference_id':
                        $db->exec("ALTER TABLE pharmacist_notifications ADD COLUMN reference_id INT NULL COMMENT 'ID of related record'");
                        break;
                    case 'reference_type':
                        $db->exec("ALTER TABLE pharmacist_notifications ADD COLUMN reference_type VARCHAR(50) NULL COMMENT 'Type of related record'");
                        break;
                    case 'triage_session_id':
                        $db->exec("ALTER TABLE pharmacist_notifications ADD COLUMN triage_session_id INT NULL");
                        break;
                }
                
                echo "<p>✅ Column {$column} added</p>";
            } else {
                echo "<p>✅ Column {$column} already exists</p>";
            }
        }
    }
    
    echo "<h3>✅ Migration completed successfully!</h3>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
