<?php
/**
 * Points Earning Rules Migration
 * Requirements: 25.1-25.12 - Points Earning Rules Configuration
 * 
 * Creates tables for:
 * - points_campaigns (Requirement 25.3)
 * - category_points_bonus (Requirement 25.4)
 * - tier_settings (Requirements 25.5, 25.8)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

echo "<h2>📊 Points Earning Rules Migration</h2>";
echo "<pre>";

$db = Database::getInstance()->getConnection();

try {
    // First, ensure points_rules table exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS `points_rules` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT DEFAULT NULL,
            `rule_type` ENUM('base', 'campaign', 'category', 'tier') NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT,
            `value` DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
            `conditions` JSON DEFAULT NULL,
            `start_date` DATE DEFAULT NULL,
            `end_date` DATE DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `priority` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_type` (`rule_type`),
            INDEX `idx_active` (`is_active`),
            INDEX `idx_dates` (`start_date`, `end_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created/verified points_rules table\n";

    // Create points_campaigns table (Requirement 25.3)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `points_campaigns` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT DEFAULT NULL,
            `name` VARCHAR(255) NOT NULL COMMENT 'Campaign name',
            `description` TEXT,
            `multiplier` DECIMAL(3,2) DEFAULT 2.00 COMMENT 'Points multiplier (e.g., 2.0 for double points)',
            `start_date` DATETIME NOT NULL,
            `end_date` DATETIME NOT NULL,
            `applicable_categories` JSON COMMENT 'Array of category IDs, null = all categories',
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_campaign_account` (`line_account_id`),
            INDEX `idx_campaign_dates` (`start_date`, `end_date`),
            INDEX `idx_campaign_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created points_campaigns table\n";
    
    // Create category_points_bonus table (Requirement 25.4)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `category_points_bonus` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT DEFAULT NULL,
            `category_id` INT NOT NULL,
            `multiplier` DECIMAL(3,2) DEFAULT 1.00 COMMENT 'Points multiplier for this category',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_account_category` (`line_account_id`, `category_id`),
            INDEX `idx_category_bonus_account` (`line_account_id`),
            INDEX `idx_category_bonus_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created category_points_bonus table\n";
    
    // Create tier_settings table (Requirements 25.5, 25.8)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `tier_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT DEFAULT NULL,
            `name` VARCHAR(50) NOT NULL COMMENT 'Tier name (Silver, Gold, Platinum)',
            `min_points` INT NOT NULL DEFAULT 0 COMMENT 'Minimum points to reach this tier',
            `multiplier` DECIMAL(3,2) DEFAULT 1.00 COMMENT 'Points earning multiplier for this tier',
            `benefits` TEXT COMMENT 'JSON or text description of tier benefits',
            `badge_color` VARCHAR(50) DEFAULT NULL COMMENT 'CSS color for tier badge',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_tier_account` (`line_account_id`),
            INDEX `idx_tier_points` (`min_points`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created tier_settings table\n";
    
    // Insert default tier settings if none exist
    $stmt = $db->query("SELECT COUNT(*) FROM tier_settings");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("
            INSERT INTO tier_settings (line_account_id, name, min_points, multiplier, badge_color) VALUES
            (NULL, 'Bronze', 0, 1.00, '#CD7F32'),
            (NULL, 'Silver', 1000, 1.25, '#C0C0C0'),
            (NULL, 'Gold', 5000, 1.50, '#FFD700'),
            (NULL, 'Platinum', 15000, 2.00, '#E5E4E2')
        ");
        echo "✅ Inserted default tier settings\n";
    } else {
        echo "⚠️ Tier settings already exist, skipping insert\n";
    }
    
    // Insert default base rule if none exist
    $stmt = $db->query("SELECT COUNT(*) FROM points_rules WHERE rule_type = 'base'");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("
            INSERT INTO points_rules (line_account_id, rule_type, name, description, value, is_active) VALUES
            (NULL, 'base', 'อัตราแต้มพื้นฐาน', 'ได้ 1 แต้มต่อทุก 1 บาท', 1.0000, 1)
        ");
        echo "✅ Inserted default base rule\n";
    } else {
        echo "⚠️ Base rule already exists, skipping insert\n";
    }
    
    echo "\n========================================\n";
    echo "🎉 Points Earning Rules Migration completed!\n";
    echo "========================================\n";
    echo "\n<a href='../admin-points-settings.php'>👉 Go to Points Settings</a>\n";
    echo "<a href='../admin-rewards.php'>👉 Go to Rewards Management</a>\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
