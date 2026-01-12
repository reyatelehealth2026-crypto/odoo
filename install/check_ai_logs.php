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
    
    echo "=== Recent AI Logs ===\n\n";
    
    $stmt = $pdo->query("SELECT * FROM dev_logs WHERE category LIKE 'AI%' ORDER BY id DESC LIMIT 20");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        echo "ID: {$row['id']}\n";
        echo "Category: {$row['category']}\n";
        echo "Action: {$row['action']}\n";
        echo "Message: {$row['message']}\n";
        echo "Data: " . substr($row['data'] ?? '', 0, 200) . "\n";
        echo "Time: {$row['created_at']}\n";
        echo "---\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
