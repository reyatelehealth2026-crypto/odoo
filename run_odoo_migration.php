<?php
/**
 * Odoo Integration Database Migration Runner (Embedded SQL Version)
 * 
 * Usages:
 * 1. CLI: php run_odoo_migration.php
 * 2. Browser: https://your-domain.com/run_odoo_migration.php
 */

header('Content-Type: text/html; charset=utf-8');
echo "<h1>Odoo Integration Setup & Migration</h1>";

// 1. Load Configuration & Database
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

echo "<h2>1. Environment Check</h2>";
$secret = getenv('ODOO_STAGING_WEBHOOK_SECRET');
if ($secret) {
    echo "<p style='color:green'>✅ ODOO_STAGING_WEBHOOK_SECRET is set: " . substr($secret, 0, 5) . "...</p>";
} else {
    echo "<p style='color:red'>❌ ODOO_STAGING_WEBHOOK_SECRET is NOT set.</p>";
    echo "<p>Checking paths:</p>";
    echo "<ul>";
    echo "<li>re-ya/.env: " . (file_exists(__DIR__ . '/.env') ? 'Found ✅' : 'Not Found ❌') . "</li>";
    echo "<li>../.env: " . (file_exists(dirname(__DIR__) . '/.env') ? 'Found ✅' : 'Not Found ❌') . "</li>";
    echo "</ul>";
}

echo "<h2>2. Database Migration</h2>";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    echo "<p>✅ Database Connected</p>";

    // EMBEDDED SQL (To avoid file not found issues)
    $sql = <<<SQL
-- Table: odoo_line_users
CREATE TABLE IF NOT EXISTS odoo_line_users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT NOT NULL COMMENT 'Reference to line_accounts table',
  line_user_id VARCHAR(100) NOT NULL COMMENT 'LINE user ID (U...)',
  odoo_partner_id INT NOT NULL COMMENT 'Odoo partner ID',
  odoo_partner_name VARCHAR(255) COMMENT 'Partner name from Odoo',
  odoo_customer_code VARCHAR(100) COMMENT 'Customer code from Odoo',
  linked_via ENUM('phone', 'email', 'customer_code') NOT NULL COMMENT 'Method used to link account',
  line_notification_enabled TINYINT(1) DEFAULT 1 COMMENT 'Enable/disable LINE notifications',
  linked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY unique_line_user (line_user_id),
  INDEX idx_odoo_partner (odoo_partner_id),
  INDEX idx_line_account (line_account_id),
  FOREIGN KEY fk_line_account (line_account_id) REFERENCES line_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: odoo_webhooks_log
CREATE TABLE IF NOT EXISTS odoo_webhooks_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT COMMENT 'Reference to line_accounts table',
  delivery_id VARCHAR(100) NOT NULL COMMENT 'X-Odoo-Delivery-Id header',
  event_type VARCHAR(100) NOT NULL COMMENT 'Webhook event type',
  payload JSON NOT NULL COMMENT 'Full webhook payload',
  signature VARCHAR(255) COMMENT 'X-Odoo-Signature header',
  processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  status ENUM('success', 'failed', 'duplicate') DEFAULT 'success',
  error_message TEXT COMMENT 'Error details if failed',
  line_user_id VARCHAR(100) COMMENT 'LINE user ID',
  order_id INT COMMENT 'Odoo order ID',
  UNIQUE KEY unique_delivery_id (delivery_id),
  INDEX idx_event_type (event_type),
  INDEX idx_processed_at (processed_at),
  INDEX idx_line_user (line_user_id),
  FOREIGN KEY fk_webhook_line_account (line_account_id) REFERENCES line_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: odoo_slip_uploads
CREATE TABLE IF NOT EXISTS odoo_slip_uploads (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT NOT NULL COMMENT 'Reference to line_accounts table',
  line_user_id VARCHAR(100) NOT NULL COMMENT 'LINE user ID',
  odoo_slip_id INT COMMENT 'Slip ID from Odoo',
  odoo_partner_id INT COMMENT 'Odoo partner ID',
  bdo_id INT COMMENT 'Bank Deposit Order ID',
  invoice_id INT COMMENT 'Invoice ID',
  order_id INT COMMENT 'Order ID',
  amount DECIMAL(10,2) COMMENT 'Payment amount',
  transfer_date DATE COMMENT 'Transfer date',
  status ENUM('pending', 'matched', 'failed') DEFAULT 'pending',
  match_reason TEXT COMMENT 'Reason for match/fail',
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  matched_at DATETIME,
  INDEX idx_line_user (line_user_id),
  INDEX idx_status (status),
  INDEX idx_uploaded_at (uploaded_at),
  FOREIGN KEY fk_slip_line_account (line_account_id) REFERENCES line_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: odoo_api_logs
CREATE TABLE IF NOT EXISTS odoo_api_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  line_account_id INT NOT NULL COMMENT 'Reference to line_accounts table',
  endpoint VARCHAR(255) NOT NULL COMMENT 'API endpoint called',
  method VARCHAR(10) DEFAULT 'POST',
  request_params JSON COMMENT 'Request parameters',
  response_data JSON COMMENT 'Response data',
  status_code INT COMMENT 'HTTP status code',
  error_message TEXT COMMENT 'Error message if failed',
  duration_ms INT COMMENT 'Request duration in milliseconds',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_endpoint (endpoint),
  INDEX idx_created_at (created_at),
  FOREIGN KEY fk_api_log_line_account (line_account_id) REFERENCES line_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    // Simple split by semicolon
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $stmt) {
        if (empty($stmt))
            continue;
        if (strpos($stmt, '--') === 0)
            continue;

        try {
            $pdo->exec($stmt);
            if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $stmt, $matches)) {
                echo "<p style='color:green'>✅ Table <strong>{$matches[1]}</strong> checked/created.</p>";
            }
        } catch (PDOException $e) {
            echo "<p style='color:red'>❌ Error executing statement: " . $e->getMessage() . "</p>";
        }
    }

    echo "<h3>Migration Completed!</h3>";

} catch (Exception $e) {
    echo "<p style='color:red'>❌ Critical Error: " . $e->getMessage() . "</p>";
}
?>