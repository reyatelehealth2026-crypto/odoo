<?php
/**
 * Full Backfill - Direct SQL, no OdooSyncService
 * Access via: https://cny.re-ya.com/install/run_full_backfill.php
 */
set_time_limit(600);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
use Modules\Core\Database;

$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$batchSize = 500;

echo "=== Full Backfill ===\n\n";

// Fix NULL flags
$db->exec("UPDATE odoo_webhooks_log SET synced_to_tables = FALSE WHERE synced_to_tables IS NULL");

// Count
$total = (int)$db->query("
    SELECT COUNT(*) FROM odoo_webhooks_log
    WHERE synced_to_tables = FALSE
      AND (event_type LIKE 'order.%' OR event_type LIKE 'invoice.%' 
           OR event_type LIKE 'bdo.%' OR event_type LIKE 'delivery.%'
           OR event_type LIKE 'payment.%' OR event_type LIKE 'sale.%')
")->fetchColumn();

echo "Total to process: {$total}\n";
echo "Batch size: {$batchSize}\n\n";

$orders = 0; $invoices = 0; $bdos = 0; $errors = 0; $skipped = 0;
$firstError = null;
$offset = 0;

while (true) {
    $stmt = $db->prepare("
        SELECT id, event_type, payload
        FROM odoo_webhooks_log
        WHERE synced_to_tables = FALSE
          AND (event_type LIKE 'order.%' OR event_type LIKE 'invoice.%' 
               OR event_type LIKE 'bdo.%' OR event_type LIKE 'delivery.%'
               OR event_type LIKE 'payment.%' OR event_type LIKE 'sale.%')
        ORDER BY id ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($batch)) break;
    
    echo "Batch at offset {$offset} (" . count($batch) . " records)... ";
    $batchOrders = $batchInvoices = $batchBdos = $batchErrors = 0;
    
    foreach ($batch as $wh) {
        $payload = json_decode($wh['payload'], true);
        if (!$payload) {
            $errors++; $batchErrors++;
            // Mark as synced to skip bad JSON
            $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
            continue;
        }

        $et = $wh['event_type'];
        $success = false;

        try {
            if (str_starts_with($et, 'order.') || str_starts_with($et, 'delivery.') || 
                str_starts_with($et, 'payment.') || str_starts_with($et, 'sale.')) {
                
                $orderId = (int)($payload['order_id'] ?? $payload['id'] ?? 0);
                if (!$orderId) { $skipped++; 
                    $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
                    continue; 
                }

                $c = $payload['customer'] ?? [];
                $partnerId = (isset($c['id']) && $c['id']) ? (int)$c['id'] : null;
                $lu = $c['line_user_id'] ?? null;
                if ($lu === false || $lu === '') $lu = null;
                $orderName = $payload['order_name'] ?? $payload['name'] ?? ('SO-' . $orderId);
                $state = $payload['new_state'] ?? $payload['state'] ?? null;
                $amt = (float)($payload['amount_total'] ?? 0);
                $currency = $payload['currency'] ?? 'THB';
                $dateOrder = $payload['order_date'] ?? $payload['date_order'] ?? null;
                $salesperson = $payload['salesperson'] ?? [];
                $salespersonId = (isset($salesperson['id']) && $salesperson['id']) ? (int)$salesperson['id'] : null;
                $salespersonName = $salesperson['name'] ?? null;
                $customerRef = $c['ref'] ?? null;
                $itemsCount = (int)($payload['items_count'] ?? 0);

                $chk = $db->prepare("SELECT id FROM odoo_orders WHERE order_id = ? LIMIT 1");
                $chk->execute([$orderId]);
                
                if ($chk->rowCount() > 0) {
                    $s = $db->prepare("UPDATE odoo_orders SET 
                        order_name=?, partner_id=?, customer_ref=?, line_user_id=?,
                        salesperson_id=?, salesperson_name=?, state=?, amount_total=?,
                        currency=?, date_order=?, items_count=?, latest_event=?,
                        synced_at=NOW(), updated_at=NOW()
                        WHERE order_id=?");
                    $success = $s->execute([$orderName, $partnerId, $customerRef, $lu,
                        $salespersonId, $salespersonName, $state, $amt,
                        $currency, $dateOrder, $itemsCount, $et, $orderId]);
                } else {
                    $s = $db->prepare("INSERT INTO odoo_orders 
                        (order_id, order_name, partner_id, customer_ref, line_user_id,
                         salesperson_id, salesperson_name, state, amount_total,
                         currency, date_order, items_count, latest_event)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $success = $s->execute([$orderId, $orderName, $partnerId, $customerRef, $lu,
                        $salespersonId, $salespersonName, $state, $amt,
                        $currency, $dateOrder, $itemsCount, $et]);
                }
                if ($success) { $orders++; $batchOrders++; }

            } elseif (str_starts_with($et, 'invoice.')) {
                
                $invoiceId = (int)($payload['invoice_id'] ?? $payload['id'] ?? 0);
                if (!$invoiceId) { $skipped++;
                    $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
                    continue;
                }

                $c = $payload['customer'] ?? [];
                $partnerId = (isset($c['id']) && $c['id']) ? (int)$c['id'] : null;
                $lu = $c['line_user_id'] ?? null;
                if ($lu === false || $lu === '') $lu = null;
                $invoiceNumber = $payload['invoice_number'] ?? $payload['name'] ?? ('INV-' . $invoiceId);
                $isPaid = (str_contains($et, 'paid') || ($payload['payment_state'] ?? '') === 'paid') ? 1 : 0;
                $amt = (float)($payload['amount_total'] ?? 0);
                $invoiceDate = $payload['invoice_date'] ?? null;
                $dueDate = $payload['due_date'] ?? null;
                $orderId = (int)($payload['order_id'] ?? 0) ?: null;
                $orderName = $payload['order_name'] ?? null;
                $customerRef = $c['ref'] ?? null;
                $salesperson = $payload['salesperson'] ?? [];
                $salespersonId = (isset($salesperson['id']) && $salesperson['id']) ? (int)$salesperson['id'] : null;
                $salespersonName = $salesperson['name'] ?? null;
                $pdfUrl = $payload['pdf_url'] ?? null;
                $paymentTerm = $payload['payment_term'] ?? null;

                $chk = $db->prepare("SELECT id FROM odoo_invoices WHERE invoice_id = ? LIMIT 1");
                $chk->execute([$invoiceId]);

                if ($chk->rowCount() > 0) {
                    $s = $db->prepare("UPDATE odoo_invoices SET
                        invoice_number=?, order_id=?, order_name=?, partner_id=?, customer_ref=?,
                        line_user_id=?, salesperson_id=?, salesperson_name=?,
                        is_paid=?, amount_total=?, invoice_date=?, due_date=?,
                        pdf_url=?, payment_term=?, latest_event=?, synced_at=NOW(), updated_at=NOW()
                        WHERE invoice_id=?");
                    $success = $s->execute([$invoiceNumber, $orderId, $orderName, $partnerId, $customerRef,
                        $lu, $salespersonId, $salespersonName,
                        $isPaid, $amt, $invoiceDate, $dueDate,
                        $pdfUrl, $paymentTerm, $et, $invoiceId]);
                } else {
                    $s = $db->prepare("INSERT INTO odoo_invoices
                        (invoice_id, invoice_number, order_id, order_name, partner_id, customer_ref,
                         line_user_id, salesperson_id, salesperson_name,
                         is_paid, amount_total, invoice_date, due_date, pdf_url, payment_term, latest_event)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $success = $s->execute([$invoiceId, $invoiceNumber, $orderId, $orderName, $partnerId, $customerRef,
                        $lu, $salespersonId, $salespersonName,
                        $isPaid, $amt, $invoiceDate, $dueDate, $pdfUrl, $paymentTerm, $et]);
                }
                if ($success) { $invoices++; $batchInvoices++; }

            } elseif (str_starts_with($et, 'bdo.')) {
                
                $bdoId = (int)($payload['bdo_id'] ?? 0);
                if (!$bdoId) { $skipped++;
                    $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
                    continue;
                }

                $c = $payload['customer'] ?? [];
                $partnerId = (isset($c['id']) && $c['id']) ? (int)$c['id'] : null;
                $lu = $c['line_user_id'] ?? null;
                if ($lu === false || $lu === '') $lu = null;
                $bdoName = $payload['bdo_name'] ?? ('BDO-' . $bdoId);
                $orderId = (int)($payload['order_id'] ?? 0) ?: null;
                $orderName = $payload['order_name'] ?? null;
                $customerRef = $c['ref'] ?? null;
                $amt = (float)($payload['amount_total'] ?? 0);
                $bdoDate = $payload['bdo_date'] ?? null;
                $expectedDelivery = $payload['expected_delivery'] ?? null;
                $state = $payload['state'] ?? 'confirmed';

                $chk = $db->prepare("SELECT id FROM odoo_bdos WHERE bdo_id = ? LIMIT 1");
                $chk->execute([$bdoId]);

                if ($chk->rowCount() > 0) {
                    $s = $db->prepare("UPDATE odoo_bdos SET
                        bdo_name=?, order_id=?, order_name=?, partner_id=?, customer_ref=?,
                        line_user_id=?, state=?, amount_total=?, bdo_date=?, expected_delivery=?,
                        latest_event=?, synced_at=NOW(), updated_at=NOW()
                        WHERE bdo_id=?");
                    $success = $s->execute([$bdoName, $orderId, $orderName, $partnerId, $customerRef,
                        $lu, $state, $amt, $bdoDate, $expectedDelivery, $et, $bdoId]);
                } else {
                    $s = $db->prepare("INSERT INTO odoo_bdos
                        (bdo_id, bdo_name, order_id, order_name, partner_id, customer_ref,
                         line_user_id, state, amount_total, bdo_date, expected_delivery, latest_event)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    $success = $s->execute([$bdoId, $bdoName, $orderId, $orderName, $partnerId, $customerRef,
                        $lu, $state, $amt, $bdoDate, $expectedDelivery, $et]);
                }
                if ($success) { $bdos++; $batchBdos++; }
            }

            if ($success) {
                $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
            } else {
                $errors++; $batchErrors++;
                if (!$firstError) $firstError = "Webhook #{$wh['id']} ({$et}): execute returned false";
                // Mark as synced to avoid infinite loop - record may have bad data
                $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
            }

        } catch (Exception $e) {
            $errors++; $batchErrors++;
            if (!$firstError) $firstError = "Webhook #{$wh['id']} ({$et}): " . $e->getMessage();
            // Only mark as synced if NOT a schema error (so we can retry after table fix)
            if (strpos($e->getMessage(), 'odoo_invoice_id') === false && 
                strpos($e->getMessage(), 'Column not found') === false) {
                $db->prepare("UPDATE odoo_webhooks_log SET synced_to_tables = TRUE WHERE id = ?")->execute([$wh['id']]);
            }
        }
    }
    
    echo "Orders+{$batchOrders} Invoices+{$batchInvoices} BDOs+{$batchBdos} Errors:{$batchErrors}\n";
    $offset += count($batch);
    
    if (count($batch) < $batchSize) break;
}

echo "\n=== Done ===\n";
echo "Orders: {$orders}\n";
echo "Invoices: {$invoices}\n";
echo "BDOs: {$bdos}\n";
echo "Skipped: {$skipped}\n";
echo "Errors: {$errors}\n";
if ($firstError) echo "First error: {$firstError}\n";

echo "\n=== Table Counts ===\n";
echo "odoo_orders: " . $db->query("SELECT COUNT(*) FROM odoo_orders")->fetchColumn() . "\n";
echo "odoo_invoices: " . $db->query("SELECT COUNT(*) FROM odoo_invoices")->fetchColumn() . "\n";
echo "odoo_bdos: " . $db->query("SELECT COUNT(*) FROM odoo_bdos")->fetchColumn() . "\n";
