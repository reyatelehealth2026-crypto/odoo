<?php
/**
 * Migration: Add multi-platform support (Facebook Messenger + TikTok Shop)
 *
 * Run this script once to:
 * 1. Add platform + platform_user_id columns to users table
 * 2. Add platform column to messages table
 * 3. Add facebook_account_id + tiktok_account_id columns to users table
 * 4. Create facebook_accounts table
 * 5. Create tiktok_shop_accounts table
 * 6. Backfill existing rows with platform='line'
 */

// Output as plain text immediately so errors are visible
header('Content-Type: text/plain; charset=utf-8');

// Show all errors during migration
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== Migration: Add Multi-Platform Support ===\n\n";

// Load config - try multiple paths
$configPaths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../config/database.php',
];

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
    }
}

// Connect to DB - try Database class first, fall back to direct PDO
$db = null;

if (class_exists('Database')) {
    try {
        $db = Database::getInstance()->getConnection();
        echo "[OK] Connected via Database class\n";
    } catch (Exception $e) {
        echo "[WARN] Database class failed: " . $e->getMessage() . "\n";
    }
}

if (!$db) {
    // Fall back to direct PDO using constants from config.php
    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $name = defined('DB_NAME') ? DB_NAME : '';
    $user = defined('DB_USER') ? DB_USER : '';
    $pass = defined('DB_PASS') ? DB_PASS : '';

    if (!$name) {
        echo "[FAIL] Cannot connect: DB_HOST/DB_NAME/DB_USER/DB_PASS not defined.\n";
        echo "Make sure config.php is loaded correctly.\n";
        exit(1);
    }

    try {
        $db = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "[OK] Connected via direct PDO ({$user}@{$host}/{$name})\n";
    } catch (PDOException $e) {
        echo "[FAIL] PDO connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n";

// -----------------------------------------------------------------------
// Helper
// -----------------------------------------------------------------------

function runStep(PDO $db, string $label, string $sql): void
{
    try {
        $db->exec($sql);
        echo "[OK]   {$label}\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Ignore "already exists" / "duplicate column" – migration is idempotent
        if (
            stripos($msg, 'Duplicate column name') !== false ||
            stripos($msg, 'already exists') !== false ||
            stripos($msg, "Duplicate key name") !== false
        ) {
            echo "[SKIP] {$label} (already applied)\n";
        } else {
            echo "[FAIL] {$label}: {$msg}\n";
        }
    }
}

// -----------------------------------------------------------------------
// 1. users table – platform discriminator
// -----------------------------------------------------------------------
echo "--- users table ---\n";

runStep($db, 'Add facebook_account_id column to users', "
    ALTER TABLE users
    ADD COLUMN facebook_account_id INT DEFAULT NULL
    AFTER line_account_id
");

runStep($db, 'Add tiktok_account_id column to users', "
    ALTER TABLE users
    ADD COLUMN tiktok_account_id INT DEFAULT NULL
    AFTER facebook_account_id
");

runStep($db, 'Add platform column to users', "
    ALTER TABLE users
    ADD COLUMN platform VARCHAR(20) NOT NULL DEFAULT 'line'
    AFTER tiktok_account_id
");

runStep($db, 'Add platform_user_id column to users', "
    ALTER TABLE users
    ADD COLUMN platform_user_id VARCHAR(100) DEFAULT NULL
    AFTER platform
");

runStep($db, 'Add index on users.platform', "
    ALTER TABLE users ADD INDEX idx_platform (platform)
");

// -----------------------------------------------------------------------
// 2. messages table – platform discriminator
// -----------------------------------------------------------------------
echo "\n--- messages table ---\n";

runStep($db, 'Add platform column to messages', "
    ALTER TABLE messages
    ADD COLUMN platform VARCHAR(20) NOT NULL DEFAULT 'line'
    AFTER line_account_id
");

runStep($db, 'Add index on messages.platform', "
    ALTER TABLE messages ADD INDEX idx_msg_platform (platform)
");

// -----------------------------------------------------------------------
// 3. facebook_accounts table
// -----------------------------------------------------------------------
echo "\n--- facebook_accounts table ---\n";

runStep($db, 'Create facebook_accounts table', "
    CREATE TABLE IF NOT EXISTS facebook_accounts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(255) NOT NULL,
        page_id             VARCHAR(100) NOT NULL,
        app_id              VARCHAR(100) NOT NULL,
        app_secret          VARCHAR(255) NOT NULL,
        page_access_token   TEXT NOT NULL,
        verify_token        VARCHAR(255) NOT NULL,
        webhook_url         VARCHAR(500) DEFAULT NULL,
        picture_url         VARCHAR(500) DEFAULT NULL,
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        settings            JSON DEFAULT NULL,
        created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_page_id (page_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// -----------------------------------------------------------------------
// 4. tiktok_shop_accounts table
// -----------------------------------------------------------------------
echo "\n--- tiktok_shop_accounts table ---\n";

runStep($db, 'Create tiktok_shop_accounts table', "
    CREATE TABLE IF NOT EXISTS tiktok_shop_accounts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(255) NOT NULL,
        shop_id             VARCHAR(100) NOT NULL,
        app_key             VARCHAR(100) NOT NULL,
        app_secret          VARCHAR(255) NOT NULL,
        access_token        TEXT NOT NULL,
        refresh_token       TEXT DEFAULT NULL,
        token_expires_at    DATETIME DEFAULT NULL,
        shop_cipher         VARCHAR(255) DEFAULT NULL,
        webhook_url         VARCHAR(500) DEFAULT NULL,
        picture_url         VARCHAR(500) DEFAULT NULL,
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        settings            JSON DEFAULT NULL,
        created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_shop_id (shop_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// -----------------------------------------------------------------------
// 5. Backfill existing rows
// -----------------------------------------------------------------------
echo "\n--- backfill ---\n";

runStep($db, 'Backfill users.platform = line', "
    UPDATE users SET platform = 'line' WHERE platform = '' OR platform IS NULL
");

runStep($db, 'Backfill users.platform_user_id from line_user_id', "
    UPDATE users SET platform_user_id = line_user_id
    WHERE platform = 'line' AND (platform_user_id IS NULL OR platform_user_id = '')
");

runStep($db, 'Backfill messages.platform = line', "
    UPDATE messages SET platform = 'line' WHERE platform = '' OR platform IS NULL
");

echo "\n=== Done ===\n";
