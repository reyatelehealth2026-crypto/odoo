<?php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config/config.php';

$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$name = defined('DB_NAME') ? DB_NAME : '';
$user = defined('DB_USER') ? DB_USER : '';
$pass = defined('DB_PASS') ? DB_PASS : '';

try {
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== AI Chat Settings ===\n\n";
    
    $stmt = $pdo->query("SELECT id, line_account_id, sender_name, sender_icon, quick_reply_buttons, is_enabled FROM ai_chat_settings");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        echo "ID: {$row['id']}\n";
        echo "Line Account: {$row['line_account_id']}\n";
        echo "Sender: {$row['sender_name']}\n";
        echo "Enabled: {$row['is_enabled']}\n";
        echo "Quick Reply: " . ($row['quick_reply_buttons'] ?? 'null') . "\n";
        echo "---\n";
    }
    
    // Update sender name if requested
    if (isset($_GET['fix'])) {
        $pdo->exec("UPDATE ai_chat_settings SET sender_name = 'พนักงานขาย AI', quick_reply_buttons = NULL");
        echo "\nUpdated sender name to 'พนักงานขาย AI' and cleared quick reply buttons!\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
