<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>Recent AI Logs</h2><pre>";
    
    $stmt = $db->query("SELECT created_at, source, message FROM dev_logs WHERE source LIKE 'AI%' OR source = 'webhook' ORDER BY created_at DESC LIMIT 30");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $row['created_at'] . " | " . $row['source'] . " | " . $row['message'] . "\n";
    }
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
