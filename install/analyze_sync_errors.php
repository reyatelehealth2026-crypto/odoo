<?php
/**
 * Analyze Sync Errors
 * 
 * Detailed analysis of why webhooks are failing to sync
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooSyncService.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    $syncService = new OdooSyncService($db);

    echo "=== Analyzing Sync Errors ===\n\n";

    // Get sample of failed webhooks by event type
    $stmt = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
        GROUP BY event_type
        ORDER BY count DESC
    ");
    
    $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Event Types (unsynced):\n";
    foreach ($eventTypes as $et) {
        echo "  {$et['event_type']}: {$et['count']}\n";
    }
    echo "\n";

    // Analyze each event type
    foreach (array_slice($eventTypes, 0, 5) as $et) {
        $eventType = $et['event_type'];
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "Analyzing: {$eventType} ({$et['count']} records)\n";
        echo str_repeat("=", 80) . "\n";

        // Get sample payload
        $stmt = $db->prepare("
            SELECT id, payload, processed_at
            FROM odoo_webhooks_log
            WHERE event_type = ?
              AND synced_to_tables = FALSE
            LIMIT 1
        ");
        $stmt->execute([$eventType]);
        $sample = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sample) continue;

        $payload = json_decode($sample['payload'], true);
        
        echo "\nPayload structure:\n";
        echo "  Top-level keys: " . implode(', ', array_keys($payload)) . "\n";
        
        // Check for IDs
        $foundIds = [];
        if (isset($payload['order_id'])) $foundIds[] = "order_id={$payload['order_id']}";
        if (isset($payload['id'])) $foundIds[] = "id={$payload['id']}";
        if (isset($payload['invoice_id'])) $foundIds[] = "invoice_id={$payload['invoice_id']}";
        if (isset($payload['bdo_id'])) $foundIds[] = "bdo_id={$payload['bdo_id']}";
        if (isset($payload['order']['id'])) $foundIds[] = "order.id={$payload['order']['id']}";
        if (isset($payload['invoice']['id'])) $foundIds[] = "invoice.id={$payload['invoice']['id']}";
        
        echo "  IDs found: " . ($foundIds ? implode(', ', $foundIds) : "NONE!") . "\n";
        
        // Check customer data
        if (isset($payload['customer'])) {
            $custKeys = array_keys($payload['customer']);
            echo "  Customer keys: " . implode(', ', $custKeys) . "\n";
        } else {
            echo "  Customer: NOT FOUND\n";
        }

        // Try to sync this sample
        echo "\nTrying to sync sample webhook #{$sample['id']}...\n";
        $result = $syncService->syncWebhook($payload, $eventType, $sample['id']);
        
        if ($result) {
            echo "  ✓ SUCCESS\n";
        } else {
            echo "  ✗ FAILED\n";
            echo "  Check error_log for details\n";
        }

        // Show partial payload
        echo "\nSample payload (first 800 chars):\n";
        echo str_repeat("-", 80) . "\n";
        $jsonStr = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo substr($jsonStr, 0, 800) . "\n...\n";
    }

    echo "\n\n" . str_repeat("=", 80) . "\n";
    echo "Analysis complete. Check error_log for detailed error messages.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
