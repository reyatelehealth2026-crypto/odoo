<?php
/**
 * Debug Webhook Structure
 * 
 * Analyzes webhook log to understand event types and payload structures
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();

    echo "=== Webhook Event Types Analysis ===\n\n";

    // Get event type distribution
    $stmt = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
        GROUP BY event_type
        ORDER BY count DESC
        LIMIT 30
    ");
    
    echo "Event Type Distribution:\n";
    echo str_repeat("-", 60) . "\n";
    printf("%-40s %10s\n", "Event Type", "Count");
    echo str_repeat("-", 60) . "\n";
    
    $eventTypes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-40s %10d\n", $row['event_type'], $row['count']);
        $eventTypes[] = $row['event_type'];
    }
    
    echo "\n\n=== Sample Payloads ===\n\n";

    // Sample payloads for each major event type
    foreach (array_slice($eventTypes, 0, 10) as $eventType) {
        echo "Event: {$eventType}\n";
        echo str_repeat("-", 80) . "\n";
        
        $stmt = $db->prepare("
            SELECT payload
            FROM odoo_webhooks_log
            WHERE event_type = ?
              AND synced_to_tables = FALSE
            LIMIT 1
        ");
        $stmt->execute([$eventType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $payload = json_decode($row['payload'], true);
            
            // Show top-level keys
            echo "Top-level keys: " . implode(', ', array_keys($payload)) . "\n";
            
            // Show relevant IDs
            $ids = [];
            if (isset($payload['order_id'])) $ids[] = "order_id: {$payload['order_id']}";
            if (isset($payload['id'])) $ids[] = "id: {$payload['id']}";
            if (isset($payload['invoice_id'])) $ids[] = "invoice_id: {$payload['invoice_id']}";
            if (isset($payload['bdo_id'])) $ids[] = "bdo_id: {$payload['bdo_id']}";
            if (isset($payload['order']['id'])) $ids[] = "order.id: {$payload['order']['id']}";
            
            if ($ids) {
                echo "IDs found: " . implode(', ', $ids) . "\n";
            } else {
                echo "⚠️  NO IDs FOUND!\n";
            }
            
            // Show sample payload (first 500 chars)
            $jsonStr = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            echo substr($jsonStr, 0, 500) . "...\n";
        }
        
        echo "\n";
    }

    echo "\n=== Sync Status Summary ===\n";
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(synced_to_tables) as synced,
            SUM(NOT synced_to_tables) as unsynced
        FROM odoo_webhooks_log
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total webhooks: {$stats['total']}\n";
    echo "Synced: {$stats['synced']}\n";
    echo "Unsynced: {$stats['unsynced']}\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
