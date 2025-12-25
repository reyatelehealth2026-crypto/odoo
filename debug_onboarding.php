<?php
/**
 * Debug Onboarding Migration
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Debug Onboarding Migration</h2>";
echo "<pre>";

// Step 1: Check config files
echo "=== Step 1: Check Config Files ===\n";
$configFile = __DIR__ . '/config/config.php';
$dbFile = __DIR__ . '/config/database.php';

echo "config.php exists: " . (file_exists($configFile) ? "YES" : "NO") . "\n";
echo "database.php exists: " . (file_exists($dbFile) ? "YES" : "NO") . "\n";

// Step 2: Load config
echo "\n=== Step 2: Load Config ===\n";
try {
    require_once $configFile;
    echo "config.php loaded OK\n";
} catch (Exception $e) {
    echo "config.php ERROR: " . $e->getMessage() . "\n";
}

try {
    require_once $dbFile;
    echo "database.php loaded OK\n";
} catch (Exception $e) {
    echo "database.php ERROR: " . $e->getMessage() . "\n";
}

// Step 3: Test Database Connection
echo "\n=== Step 3: Test Database Connection ===\n";
try {
    if (class_exists('Database')) {
        $db = Database::getInstance()->getConnection();
        echo "Database connected OK\n";
        
        // Test query
        $result = $db->query("SELECT 1 as test")->fetch(PDO::FETCH_ASSOC);
        echo "Test query OK: " . json_encode($result) . "\n";
        
        // Step 4: Check existing tables
        echo "\n=== Step 4: Check Existing Tables ===\n";
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Total tables: " . count($tables) . "\n";
        
        // Check for onboarding tables
        $onboardingTables = array_filter($tables, function($t) {
            return strpos($t, 'onboarding') !== false || strpos($t, 'setup_progress') !== false;
        });
        
        if (empty($onboardingTables)) {
            echo "Onboarding tables NOT found\n";
        } else {
            echo "Onboarding tables found: " . implode(', ', $onboardingTables) . "\n";
        }
        
        // Step 5: Try to create tables
        echo "\n=== Step 5: Create Tables ===\n";
        
        try {
            $sql1 = "CREATE TABLE IF NOT EXISTS onboarding_sessions (
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
                INDEX idx_admin_user (admin_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $db->exec($sql1);
            echo "✅ onboarding_sessions created/exists\n";
        } catch (PDOException $e) {
            echo "❌ onboarding_sessions error: " . $e->getMessage() . "\n";
        }
        
        try {
            $sql2 = "CREATE TABLE IF NOT EXISTS setup_progress (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_account_id INT NOT NULL,
                item_key VARCHAR(50) NOT NULL,
                status ENUM('pending', 'in_progress', 'completed', 'skipped') DEFAULT 'pending',
                completed_at TIMESTAMP NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_progress (line_account_id, item_key),
                INDEX idx_line_account (line_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $db->exec($sql2);
            echo "✅ setup_progress created/exists\n";
        } catch (PDOException $e) {
            echo "❌ setup_progress error: " . $e->getMessage() . "\n";
        }
        
        // Step 6: Verify
        echo "\n=== Step 6: Verify Tables ===\n";
        foreach (['onboarding_sessions', 'setup_progress'] as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "✅ $table EXISTS\n";
            } else {
                echo "❌ $table NOT FOUND\n";
            }
        }
        
    } else {
        echo "Database class not found!\n";
    }
} catch (Exception $e) {
    echo "Database ERROR: " . $e->getMessage() . "\n";
    echo "Stack: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p><a href='/onboarding-assistant.php'>Go to Onboarding Assistant</a></p>";
