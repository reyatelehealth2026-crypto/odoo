<?php
/**
 * Fix Message Types - Update existing messages with correct message_type
 * 
 * This script detects and fixes messages that were saved with wrong message_type
 * (e.g., Flex messages saved as 'text')
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "🔧 Fix Message Types\n";
echo "====================\n\n";

try {
    // Find all messages with type 'text' that might actually be flex messages
    $stmt = $db->query("
        SELECT id, content, message_type 
        FROM messages 
        WHERE message_type = 'text' 
        AND content LIKE '%\"type\":%'
        AND (content LIKE '%\"type\":\"flex\"%' OR content LIKE '%\"type\":\"bubble\"%')
        ORDER BY id DESC
        LIMIT 100
    ");
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📊 Found " . count($messages) . " messages to check\n\n";
    
    $fixed = 0;
    $skipped = 0;
    
    foreach ($messages as $msg) {
        $content = $msg['content'];
        $decoded = json_decode($content, true);
        
        if (!$decoded) {
            $skipped++;
            continue;
        }
        
        // Detect actual message type
        $actualType = 'text';
        
        if (isset($decoded['type'])) {
            $actualType = $decoded['type'];
            
            // Normalize type names
            if ($actualType === 'bubble' || $actualType === 'carousel') {
                $actualType = 'flex';
            }
        }
        
        // Update if type is different
        if ($actualType !== $msg['message_type']) {
            $updateStmt = $db->prepare("UPDATE messages SET message_type = ? WHERE id = ?");
            $updateStmt->execute([$actualType, $msg['id']]);
            
            echo "✅ Fixed message ID {$msg['id']}: '{$msg['message_type']}' → '{$actualType}'\n";
            $fixed++;
        } else {
            $skipped++;
        }
    }
    
    echo "\n";
    echo "📈 Summary:\n";
    echo "   Fixed: $fixed messages\n";
    echo "   Skipped: $skipped messages\n";
    echo "\n";
    
    if ($fixed > 0) {
        echo "✅ Message types have been fixed!\n";
        echo "   Refresh your inbox to see the changes.\n";
    } else {
        echo "ℹ️  No messages needed fixing.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
