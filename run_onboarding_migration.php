<?php
/**
 * Run Onboarding Assistant Migration
 */

require_once 'config/config.php';
require_once 'config/database.php';

echo "<h2>Running Onboarding Assistant Migration</h2>";
echo "<pre>";

try {
    $db = Database::getInstance()->getConnection();
    
    echo "Database connected successfully\n\n";
    
    // Create tables directly
    echo "Creating onboarding_sessions table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS onboarding_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT NOT NULL,
            admin_user_id INT NOT NULL,
            conversation_history JSON,
            current_topic VARCHAR(100) DEFAULT NULL,
            business_type VARCHAR(50) DEFAULT NULL,
            setup_progress JSON,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_line_account (line_account_id),
            INDEX idx_admin_user (admin_user_id),
            INDEX idx_last_activity (last_activity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ onboarding_sessions created\n";
    
    echo "Creating setup_progress table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS setup_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT NOT NULL,
            item_key VARCHAR(50) NOT NULL,
            status ENUM('pending', 'in_progress', 'completed', 'skipped') DEFAULT 'pending',
            completed_at TIMESTAMP NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_progress (line_account_id, item_key),
            INDEX idx_line_account (line_account_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ setup_progress created\n";
    
    echo "\n✅ Migration completed successfully!\n";
    
    // Verify tables
    echo "\n--- Verifying Tables ---\n";
    
    $tables = ['onboarding_sessions', 'setup_progress'];
    foreach ($tables as $table) {
        $stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table '$table' exists\n";
            
            // Show structure
            $cols = $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            echo "   Columns: " . implode(', ', array_column($cols, 'Field')) . "\n";
        } else {
            echo "❌ Table '$table' NOT found\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p><a href='/onboarding-assistant.php'>Go to Onboarding Assistant</a></p>";
