<?php
/**
 * Standalone Backfill - No OdooSyncService, direct SQL only
 * Run this to bypass all class issues and test if basic sync works
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$batchSize = 500;
$maxRecords = 50000; // Process all
$isTest = in_array('--test', $argv ?? []);
if ($isTest) $batchSize = 50;

echo "=== Standalone Backfill ===\n";
echo "Mode: " . ($isTest ? "TEST (50 records)" : "FULL") . "\n\n";

// Fix NULLs first
$fixed = $db->exec("UPDATE odoo_webhooks_log SET synced_to_tables = FALSE WHERE synced_to_tables IS NULL");
echo "Fixed NULL flags: {$fixed}\n";

// Count total
$total = $db->query("
    SELECT COUNT(*) FROM odoo_webhooks_log
    WHERE synced_to_tables = FALSE
      AND (event_type LIKE 'order.%' OR event_type LIKE 'invoice.%' OR event_type LIKE 'bdo.%' OR event_type LIKE 'delivery.%' OR event_type LIKE 'payment.%' OR event_type LIKE 'sale.%')
")->fetchColumn();
echo "Total to process: {$total}\n\n";

$stats = ['orders' => 0, 'invoices' => 0, 'bdos' => 0, 'errors' => 0, 'skipped' => 0];
$firstError = null;
$offset = 0;

do {
    $stmt = $db->prepare("
        SELECT id, event_type, payload
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
          AND (event_type LIKE 'order.%' OR event_type LIKE 'invoice.%' OR event_type LIKE 'bdo.%' OR event_type LIKE 'delivery.%' OR event_type LIKE 'payment.%' OR event_type LIKE 'sale.%')
        ORDER BY processed_at ASC
        LIMIT {$batchSize} OFFSET {$offset}
    ");
    $stmt->execute();
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($webhooks)) break;

    echo "Processing batch at offset {$offset} (" . count($webhooks) . " records)...\n";

foreach ($webhooks as $wh) {
    $payload = json_decode($wh['payload'], true);
    if (!$payload) {
        $stats['errors']++;
        continue;
    }

    $eventType = $wh['event_type'];

    try {
        $success = false;

        if (str_starts_with($eventType, 'order.') || str_starts_with($eventType, 'delivery.')) {
            // Sync order
            $orderId = (int)($payload['order_id'] ?? $payload['id'] ?? 0);
            if (!$orderId) { $stats['skipped']++; continue; }

            $customer = $payload['customer'] ?? [];
            $partnerId = isset($customer['id']) && $customer['id'] ? (int)$customer['id'] : null;
            $lineUserId = ($customer['line_user_id'] ?? null);
            if ($lineUserId === false || $lineUserId === '') $lineUserId = null;
            $orderName = $payload['order_name'] ?? $payload['name'] ?? ('SO-' . $orderId);
            $state = $payload['new_state'] ?? $payload['state'] ?? null;
            $amountTotal = isset($payload['amount_total']) ? (float)$payload['amount_total'] : 0;

            // Check exists
            $chk = $db->prepare("SELECT id FROM odoo_orders WHERE order_id = ? LIMIT 1");
            $chk->execute([$orderId]);
            
            if ($chk->rowCount() > 0) {
                $sql = "UPDATE odoo_orders SET order_name=?, partner_id=?, line_user_id=?, state=?, amount_total=?, latest_event=?, synced_at=NOW(), updated_at=NOW() WHERE order_id=?";
                $ins = $db->prepare($sql);
                $success = $ins->execute([$orderName, $partnerId, $lineUserId, $state, $amountTotal, $eventType, $orderId]);
            } else {
                $sql = "INSERT INTO odoo_orders (order_id, order_name, partner_id, line_user_id, state, amount_total, latest_event) VALUES (?,?,?,?,?,?,?)";
                $ins = $db->prepare($sql);
                $success = $ins->execute([$orderId, $orderName, $partnerId, $lineUserId, $state, $amountTotal, $eventType]);
            }

            if ($success) $stats['orders']++;

        } elseif (str_starts_with($eventType, 'invoice.')) {
            // Sync invoice
            $invoiceId = (int)($payload['invoice_id'] ?? $payload['id'] ?? 0);
            if (!$invoiceId) { $stats['skipped']++; continue; }

            $customer = $payload['customer'] ?? [];
            $partnerId = isset($customer['id']) && $customer['id'] ? (int)$customer['id'] : null;
            $lineUserId = ($customer['line_user_id'] ?? null);
            if ($lineUserId === false || $lineUserId === '') $lineUserId = null;
            $invoiceNumber = $payload['invoice_number'] ?? $payload['name'] ?? ('INV-' . $invoiceId);
            $isPaid = str_contains($eventType, 'paid');
            $amountTotal = isset($payload['amount_total']) ? (float)$payload['amount_total'] : 0;
            $invoiceDate = $payload['invoice_date'] ?? null;
            $dueDate = $payload['due_date'] ?? null;

            $chk = $db->prepare("SELECT id FROM odoo_invoices WHERE invoice_id = ? LIMIT 1");
            $chk->execute([$invoiceId]);

            if ($chk->rowCount() > 0) {
                $sql = "UPDATE odoo_invoices SET invoice_number=?, partner_id=?, line_user_id=?, is_paid=?, amount_total=?, latest_event=?, synced_at=NOW(), updated_at=NOW() WHERE invoice_id=?";
                $ins = $db->prepare($sql);
                $success = $ins->execute([$invoiceNumber, $partnerId, $lineUserId, $isPaid, $amountTotal, $eventType, $invoiceId]);
            } else {
                $sql = "INSERT INTO odoo_invoices (invoice_id, invoice_number, partner_id, line_user_id, is_paid, amount_total, invoice_date, due_date, latest_event) VALUES (?,?,?,?,?,?,?,?,?)";
                $ins = $db->prepare($sql);
                $success = $ins->execute([$invoiceId, $invoiceNumber, $partnerId, $lineUserId, $isPaid, $amountTotal, $invoiceDate, $dueDate, $eventType]);
            }

            if ($success) $stats['invoices']++;

        } elseif (str_starts_with($eventType, 'bdo.')) {
            // Sync bdo
            $bdoId = (int)($payload['bdo_id'] ?? 0);
            if (!$bdoId) { $stats['skipped']++; continue; }

            $customer = $payload['customer'] ?? [];
            $partnerId = isset($customer['id']) && $customer['id'] ? (int)$customer['id'] : null;
            $bdoName = $payload['bdo_name'] ?? ('BDO-' . $bdoId);

            $chk = $db->prepare("SELECT id FROM odoo_bdos WHERE bdo_id = ? LIMIT 1");
            $chk->execute([$bdoId]);

            if ($chk->rowCount() > 0) {
                $sql = "UPDATE odoo_bdos SET bdo_name=?, partner_id=?, latest_event=?, synced_at=NOW(), updated_at=NOW() WHERE bdo_id=?";
                $ins = $db->prepare($sql);
                $success = $ins->execute([$bdoName, $partnerId, $eventType, $bdoId]);
            } else {
                $sql = "INSERT INTO odoo_bdos (bdo_id, bdo_name, partner_id, latest_event) VALUES (?,?,?,?)";
                $ins = $db->prepare($sql);
                $success = $ins->execute([$bdoId, $bdoName, $partnerId, $eventType]);
            }

            if ($success) $stats['bdos']++;
        }

        if ($success) {
            $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
        } else {
            $stats['errors']++;
            if (!$firstError) $firstError = "Webhook #{$wh['id']} ({$eventType}): execute() returned false";
        }

    } catch (Exception $e) {
        $stats['errors']++;
        if (!$firstError) $firstError = "Webhook #{$wh['id']} ({$eventType}): " . $e->getMessage();
    }
} // end foreach

    $processed = count($webhooks);
    $offset += $processed;
    echo "  → Orders: +{$stats['orders']}, Invoices: +{$stats['invoices']}, BDOs: +{$stats['bdos']}, Errors: {$stats['errors']}\n";
    
    if ($isTest) break;
    
} while (count($webhooks) === $batchSize); // end do-while

echo "\n=== Results ===\n";
echo "Orders synced: {$stats['orders']}\n";
echo "Invoices synced: {$stats['invoices']}\n";
echo "BDOs synced: {$stats['bdos']}\n";
echo "Skipped (no ID): {$stats['skipped']}\n";
echo "Errors: {$stats['errors']}\n";

if ($firstError) {
    echo "\nFirst error: {$firstError}\n";
}

// Final counts
echo "\n=== Table Counts ===\n";
echo "odoo_orders: " . $db->query("SELECT COUNT(*) FROM odoo_orders")->fetchColumn() . "\n";
echo "odoo_invoices: " . $db->query("SELECT COUNT(*) FROM odoo_invoices")->fetchColumn() . "\n";
echo "odoo_bdos: " . $db->query("SELECT COUNT(*) FROM odoo_bdos")->fetchColumn() . "\n";
