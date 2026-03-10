<?php
/**
 * Debug Sync Detailed
 * 
 * Shows exactly what happens when trying to sync each event type
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooSyncService.php';

use Modules\Core\Database;

try {
    $db = Database::getInstance()->getConnection();
    $syncService = new OdooSyncService($db);

    echo "=== Detailed Sync Debug ===\n\n";

    // Get one sample of each event type
    $stmt = $db->query("
        SELECT event_type, COUNT(*) as count
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
          AND (
            event_type LIKE 'order.%' OR
            event_type LIKE 'invoice.%' OR
            event_type LIKE 'bdo.%' OR
            event_type LIKE 'delivery.%'
          )
        GROUP BY event_type
        ORDER BY count DESC
        LIMIT 10
    ");
    
    $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($eventTypes as $et) {
        $eventType = $et['event_type'];
        echo str_repeat("=", 80) . "\n";
        echo "Event: {$eventType} ({$et['count']} records)\n";
        echo str_repeat("=", 80) . "\n";
        
        // Get sample
        $sampleStmt = $db->prepare("
            SELECT id, payload
            FROM odoo_webhooks_log
            WHERE event_type = ?
              AND synced_to_tables = FALSE
            LIMIT 1
        ");
        $sampleStmt->execute([$eventType]);
        $sample = $sampleStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sample) {
            echo "No sample found\n\n";
            continue;
        }
        
        $payload = json_decode($sample['payload'], true);
        
        // Show routing decision
        echo "\nRouting logic:\n";
        if (str_starts_with($eventType, 'order.')) {
            echo "  → Routes to: syncOrder() [str_starts_with('order.')]\n";
        } elseif (str_starts_with($eventType, 'sale.')) {
            echo "  → Routes to: syncOrder() [str_starts_with('sale.')]\n";
        } elseif (str_starts_with($eventType, 'delivery.')) {
            echo "  → Routes to: syncOrder() [str_starts_with('delivery.')]\n";
        } elseif (str_starts_with($eventType, 'payment.')) {
            echo "  → Routes to: syncOrder() [str_starts_with('payment.')]\n";
        } elseif (str_starts_with($eventType, 'invoice.')) {
            echo "  → Routes to: syncInvoice() [str_starts_with('invoice.')]\n";
        } elseif (str_starts_with($eventType, 'bdo.')) {
            echo "  → Routes to: syncBdo() [str_starts_with('bdo.')]\n";
        } else {
            echo "  → NOT ROUTED! Unhandled event type\n";
        }
        
        // Show IDs in payload
        echo "\nPayload IDs:\n";
        if (isset($payload['order_id'])) echo "  order_id: {$payload['order_id']}\n";
        if (isset($payload['id'])) echo "  id: {$payload['id']}\n";
        if (isset($payload['invoice_id'])) echo "  invoice_id: {$payload['invoice_id']}\n";
        if (isset($payload['bdo_id'])) echo "  bdo_id: {$payload['bdo_id']}\n";
        
        // Show customer data
        if (isset($payload['customer'])) {
            echo "\nCustomer data:\n";
            if (isset($payload['customer']['id'])) echo "  customer.id: {$payload['customer']['id']}\n";
            if (isset($payload['customer']['ref'])) echo "  customer.ref: {$payload['customer']['ref']}\n";
            if (isset($payload['customer']['name'])) echo "  customer.name: {$payload['customer']['name']}\n";
        } else {
            echo "\n⚠️  NO CUSTOMER DATA\n";
        }
        
        // Try to sync
        echo "\nAttempting sync...\n";
        
        try {
            $result = $syncService->syncWebhook($payload, $eventType, $sample['id']);
            
            if ($result) {
                echo "✓ Sync returned TRUE\n";
                
                // Check if actually in database
                if (str_starts_with($eventType, 'order.') || str_starts_with($eventType, 'delivery.')) {
                    if (isset($payload['order_id'])) {
                        $checkStmt = $db->prepare("SELECT COUNT(*) FROM odoo_orders WHERE order_id = ?");
                        $checkStmt->execute([$payload['order_id']]);
                        $count = $checkStmt->fetchColumn();
                        echo "  → Found in odoo_orders: {$count}\n";
                        
                        if ($count > 0) {
                            $detailStmt = $db->prepare("SELECT order_name, partner_id, state FROM odoo_orders WHERE order_id = ?");
                            $detailStmt->execute([$payload['order_id']]);
                            $detail = $detailStmt->fetch(PDO::FETCH_ASSOC);
                            echo "    order_name: {$detail['order_name']}\n";
                            echo "    partner_id: {$detail['partner_id']}\n";
                            echo "    state: {$detail['state']}\n";
                        }
                    }
                } elseif (str_starts_with($eventType, 'invoice.')) {
                    if (isset($payload['invoice_id'])) {
                        $checkStmt = $db->prepare("SELECT COUNT(*) FROM odoo_invoices WHERE invoice_id = ?");
                        $checkStmt->execute([$payload['invoice_id']]);
                        $count = $checkStmt->fetchColumn();
                        echo "  → Found in odoo_invoices: {$count}\n";
                    }
                }
            } else {
                echo "✗ Sync returned FALSE\n";
                echo "  → Check why syncOrder/syncInvoice/syncBdo returned false\n";
            }
        } catch (Exception $e) {
            echo "✗ EXCEPTION: {$e->getMessage()}\n";
            echo "  File: {$e->getFile()}:{$e->getLine()}\n";
        }
        
        echo "\n";
    }
    
    echo str_repeat("=", 80) . "\n";
    echo "Debug complete\n";

} catch (Exception $e) {
    echo "Fatal error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
