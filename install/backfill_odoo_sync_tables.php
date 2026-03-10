<?php
/**
 * Backfill Odoo Sync Tables
 * 
 * Migrates existing webhook log data to new dedicated tables
 * (odoo_orders, odoo_invoices, odoo_bdos)
 * 
 * Usage: php backfill_odoo_sync_tables.php [--batch=1000] [--offset=0]
 */

// Clear OPcache to ensure we load the latest code
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OPcache cleared\n";
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooSyncService.php';

use Modules\Core\Database;

// Parse CLI arguments
$batchSize = 1000;
$startOffset = 0;
$verbose = false;
$showSamples = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batchSize = (int) substr($arg, 8);
    }
    if (str_starts_with($arg, '--offset=')) {
        $startOffset = (int) substr($arg, 9);
    }
    if ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    }
    if ($arg === '--samples') {
        $showSamples = true;
    }
}

echo "=== Odoo Sync Tables Backfill ===\n";
echo "Batch size: {$batchSize}\n";
echo "Starting offset: {$startOffset}\n\n";

try {
    $db = Database::getInstance()->getConnection();
    $syncService = new OdooSyncService($db);

    // Get total count of unsynced webhooks
    $stmt = $db->query("
        SELECT COUNT(*) as total
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
          AND event_type IN (
            'order.confirmed', 'order.paid', 'order.delivered', 'order.cancelled',
            'order.picker_assigned', 'order.picking', 'order.picked', 'order.packing', 'order.packed',
            'order.reserved', 'order.awaiting_payment', 'order.to_delivery', 'order.in_delivery',
            'sale.order.confirmed', 'sale.order.done',
            'invoice.posted', 'invoice.paid', 'invoice.created', 'invoice.overdue',
            'bdo.confirmed', 'bdo.updated', 'bdo.done', 'bdo.cancelled',
            'delivery.completed', 'delivery.departed', 'payment.confirmed', 'payment.received'
          )
    ");
    $totalCount = (int) $stmt->fetchColumn();
    
    echo "Total unsynced webhooks: {$totalCount}\n";
    echo "Estimated batches: " . ceil($totalCount / $batchSize) . "\n\n";

    if ($totalCount === 0) {
        echo "✅ All webhooks already synced!\n";
        exit(0);
    }

    $offset = $startOffset;
    $totalProcessed = 0;
    $totalOrders = 0;
    $totalInvoices = 0;
    $totalBdos = 0;
    $totalErrors = 0;

    while ($offset < $totalCount) {
        echo "Processing batch at offset {$offset}...\n";
        
        $stats = $syncService->backfillFromWebhookLog($batchSize, $offset);
        
        $totalProcessed += $stats['processed'];
        $totalOrders += $stats['orders'];
        $totalInvoices += $stats['invoices'];
        $totalBdos += $stats['bdos'];
        $totalErrors += $stats['errors'];
        
        if (!empty($stats['last_error'])) {
            echo "  Last error: {$stats['last_error']}\n";
        }

        $progress = min(100, round(($offset + $stats['processed']) / $totalCount * 100, 2));
        
        echo "  ✓ Processed: {$stats['processed']} records\n";
        echo "  ✓ Orders: {$stats['orders']}, Invoices: {$stats['invoices']}, BDOs: {$stats['bdos']}\n";
        echo "  ✗ Errors: {$stats['errors']}\n";
        if (!empty($stats['last_error'])) {
            echo "  ⚠ Last error: {$stats['last_error']}\n";
        }
        echo "  Progress: {$progress}%\n\n";

        if ($stats['processed'] === 0) {
            break; // No more records to process
        }

        $offset += $batchSize;
        
        // Small delay to avoid overwhelming the database
        usleep(100000); // 100ms
    }

    echo "\n=== Backfill Complete ===\n";
    echo "Total processed: {$totalProcessed}\n";
    echo "Orders synced: {$totalOrders}\n";
    echo "Invoices synced: {$totalInvoices}\n";
    echo "BDOs synced: {$totalBdos}\n";
    echo "Errors: {$totalErrors}\n";

    // Show final counts from new tables
    echo "\n=== Final Table Counts ===\n";
    $orderCount = $db->query("SELECT COUNT(*) FROM odoo_orders")->fetchColumn();
    $invoiceCount = $db->query("SELECT COUNT(*) FROM odoo_invoices")->fetchColumn();
    $bdoCount = $db->query("SELECT COUNT(*) FROM odoo_bdos")->fetchColumn();
    
    echo "odoo_orders: {$orderCount}\n";
    echo "odoo_invoices: {$invoiceCount}\n";
    echo "odoo_bdos: {$bdoCount}\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
