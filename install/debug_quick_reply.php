<?php
/**
 * Debug Quick Reply Templates
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>Debug Quick Reply Templates</h2>";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if quick_reply column exists
    $stmt = $db->query("SHOW COLUMNS FROM quick_reply_templates LIKE 'quick_reply'");
    $hasColumn = $stmt->rowCount() > 0;
    echo "<p>Column 'quick_reply' exists: " . ($hasColumn ? '✓ Yes' : '✗ No') . "</p>";
    
    // Get all templates with quick_reply
    $stmt = $db->query("SELECT id, name, quick_reply FROM quick_reply_templates ORDER BY id DESC LIMIT 10");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Templates:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Quick Reply</th></tr>";
    
    foreach ($templates as $t) {
        echo "<tr>";
        echo "<td>{$t['id']}</td>";
        echo "<td>" . htmlspecialchars($t['name']) . "</td>";
        echo "<td><pre>" . htmlspecialchars($t['quick_reply'] ?? 'NULL') . "</pre></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Test sending quick reply format
    echo "<h3>LINE Quick Reply Format Example:</h3>";
    $exampleQuickReply = [
        [
            'type' => 'action',
            'action' => [
                'type' => 'message',
                'label' => 'ดีครับ',
                'text' => 'ดีครับ'
            ]
        ]
    ];
    
    $messageWithQuickReply = [
        'type' => 'text',
        'text' => 'สวัสดีครับ',
        'quickReply' => [
            'items' => $exampleQuickReply
        ]
    ];
    
    echo "<pre>" . json_encode($messageWithQuickReply, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
