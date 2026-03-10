<?php
/**
 * Reset and Re-run Sync
 * 
 * Resets synced_to_tables flag and re-runs backfill with improved logic
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooSyncService.php';

use Modules\Core\Database;

$resetFirst = in_array('--reset', $argv);
$limit = 100;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
}

try {
    $db = Database::getInstance()->getConnection();
    $syncService = new OdooSyncService($db);

    if ($resetFirst) {
        echo "Resetting synced_to_tables flags...\n";
        $db->exec("UPDATE odoo_webhooks_log SET synced_to_tables = FALSE");
        echo "✓ Reset complete\n\n";
    }

    echo "=== Re-running Sync (Limit: {$limit}) ===\n\n";

    // Get unsynced webhooks with better event filtering
    $stmt = $db->prepare("
        SELECT id, event_type, payload, processed_at
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
        ORDER BY processed_at ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found {$webhooks->rowCount()} unsynced webhooks\n\n";

    $stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'orders' => 0, 'invoices' => 0, 'bdos' => 0];

    foreach ($webhooks as $wh) {
        $stats['total']++;
        $payload = json_decode($wh['payload'], true);
        
        if (!$payload) {
            echo "✗ Webhook #{$wh['id']}: Invalid JSON payload\n";
            $stats['failed']++;
            continue;
        }

        $eventType = $wh['event_type'];
        
        // Try to sync
        $success = $syncService->syncWebhook($payload, $eventType, $wh['id']);
        
        if ($success) {
            // Mark as synced
            $updateStmt = $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?");
            $updateStmt->execute([$wh['id']]);
            
            // Count by type
            if (str_starts_with($eventType, 'order.') || str_starts_with($eventType, 'sale.') || 
                str_starts_with($eventType, 'delivery.') || str_starts_with($eventType, 'payment.')) {
                $stats['orders']++;
                echo "✓ Webhook #{$wh['id']}: {$eventType} → ORDER\n";
            } elseif (str_starts_with($eventType, 'invoice.')) {
                $stats['invoices']++;
                echo "✓ Webhook #{$wh['id']}: {$eventType} → INVOICE\n";
            } elseif (str_starts_with($eventType, 'bdo.')) {
                $stats['bdos']++;
                echo "✓ Webhook #{$wh['id']}: {$eventType} → BDO\n";
            }
            
            $stats['success']++;
        } else {
            echo "✗ Webhook #{$wh['id']}: {$eventType} FAILED (check error_log)\n";
            $stats['failed']++;
        }
    }

    echo "\n=== Summary ===\n";
    echo "Total processed: {$stats['total']}\n";
    echo "Success: {$stats['success']}\n";
    echo "Failed: {$stats['failed']}\n";
    echo "  Orders: {$stats['orders']}\n";
    echo "  Invoices: {$stats['invoices']}\n";
    echo "  BDOs: {$stats['bdos']}\n";

    // Show table counts
    echo "\n=== Table Counts ===\n";
    $orderCount = $db->query("SELECT COUNT(*) FROM odoo_orders")->fetchColumn();
    $invoiceCount = $db->query("SELECT COUNT(*) FROM odoo_invoices")->fetchColumn();
    $bdoCount = $db->query("SELECT COUNT(*) FROM odoo_bdos")->fetchColumn();
    
    echo "odoo_orders: {$orderCount}\n";
    echo "odoo_invoices: {$invoiceCount}\n";
    echo "odoo_bdos: {$bdoCount}\n";

} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
