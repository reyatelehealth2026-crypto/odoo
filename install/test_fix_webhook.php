<?php
/**
 * Test Fix Webhook URL - Simple Version
 */

// Show all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Test Fix Webhook URL</h2>";

try {
    echo "<p>1. Loading config...</p>";
    require_once __DIR__ . '/../config/config.php';
    echo "<p>✅ Config loaded</p>";
    
    echo "<p>2. Loading Database class...</p>";
    require_once __DIR__ . '/../classes/Database.php';
    echo "<p>✅ Database class loaded</p>";
    
    echo "<p>3. Connecting to database...</p>";
    $db = Database::getInstance()->getConnection();
    echo "<p>✅ Database connected</p>";
    
    echo "<p>4. Querying line_accounts...</p>";
    $stmt = $db->query("SELECT id, name, webhook_url FROM line_accounts LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>✅ Query successful</p>";
    
    if ($account) {
        echo "<h3>Sample Account:</h3>";
        echo "<pre>" . print_r($account, true) . "</pre>";
        
        echo "<h3>BASE_URL:</h3>";
        echo "<p>" . BASE_URL . "</p>";
        
        echo "<h3>Fixed URL:</h3>";
        $baseUrl = rtrim(BASE_URL, '/');
        $newUrl = $baseUrl . '/webhook.php?account=' . $account['id'];
        echo "<p>" . $newUrl . "</p>";
    } else {
        echo "<p>⚠️ No accounts found</p>";
    }
    
    echo "<hr>";
    echo "<p>✅ All tests passed!</p>";
    echo "<p><a href='fix_webhook_url.php'>Go to Fix Webhook URL</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
