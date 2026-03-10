<?php
/**
 * Backfill BDO ↔ Order Links from Webhook Log
 * 
 * Parses sale_orders[] from bdo.* webhook payloads and inserts into odoo_bdo_orders.
 * Safe to run multiple times (uses ON DUPLICATE KEY UPDATE).
 * 
 * Usage: php install/backfill_bdo_orders.php [--batch=500] [--offset=0]
 * 
 * @version 1.0.0
 * @created 2026-03-06
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

$batch = 500;
$offset = 0;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--batch=')) $batch = (int) substr($arg, 8);
    if (str_starts_with($arg, '--offset=')) $offset = (int) substr($arg, 9);
}

try {
    $db = Database::getInstance()->getConnection();

    // Check if target table exists
    $tblCheck = $db->query("SHOW TABLES LIKE 'odoo_bdo_orders'");
    if ($tblCheck->rowCount() === 0) {
        echo "❌ Table odoo_bdo_orders does not exist. Run migration first:\n";
        echo "   php install/migration_bdo_order_link.php\n";
        exit(1);
    }

    echo "=== Backfill BDO ↔ Order Links ===\n\n";

    $insertStmt = $db->prepare("
        INSERT INTO odoo_bdo_orders 
            (bdo_id, bdo_name, order_id, order_name, amount_total, payment_reference, 
             partner_id, customer_name, line_user_id, payment_method, webhook_delivery_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            bdo_name = VALUES(bdo_name),
            order_name = VALUES(order_name),
            amount_total = VALUES(amount_total),
            payment_reference = VALUES(payment_reference),
            partner_id = COALESCE(VALUES(partner_id), partner_id),
            customer_name = COALESCE(VALUES(customer_name), customer_name),
            line_user_id = COALESCE(VALUES(line_user_id), line_user_id),
            payment_method = COALESCE(VALUES(payment_method), payment_method),
            webhook_delivery_id = VALUES(webhook_delivery_id),
            updated_at = NOW()
    ");

    $totalInserted = 0;
    $totalSkipped = 0;
    $totalErrors = 0;
    $processed = 0;

    do {
        $stmt = $db->prepare("
            SELECT id, delivery_id, event_type, payload
            FROM odoo_webhooks_log
            WHERE event_type LIKE 'bdo.%'
              AND status = 'success'
            ORDER BY id ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$batch, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) break;

        foreach ($rows as $row) {
            $processed++;
            $payload = json_decode($row['payload'], true);
            if (!$payload || !is_array($payload)) {
                $totalSkipped++;
                continue;
            }

            // Extract from nested 'data' key if present
            $data = $payload['data'] ?? $payload;

            $bdoId = (int) ($data['bdo_id'] ?? 0);
            if (!$bdoId) {
                $totalSkipped++;
                continue;
            }

            $bdoName = $data['bdo_name'] ?? null;
            $amountTotal = isset($data['amount_total']) ? (float) $data['amount_total'] : null;

            // Customer info
            $customer = $data['customer'] ?? [];
            $partnerId = null;
            if (isset($customer['id']) && $customer['id']) {
                $partnerId = (int) $customer['id'];
            } elseif (isset($customer['partner_id']) && $customer['partner_id']) {
                $partnerId = (int) $customer['partner_id'];
            }
            $customerName = $customer['name'] ?? null;
            $lineUserId = $customer['line_user_id'] ?? null;
            if ($lineUserId === false || $lineUserId === 'false' || $lineUserId === '') {
                $lineUserId = null;
            }

            // Payment info
            $payment = $data['payment'] ?? [];
            $paymentMethod = $payment['method'] ?? null;
            $paymentReference = $payment['reference'] ?? $bdoName;

            // Collect sale orders
            $orders = [];
            $saleOrders = $data['sale_orders'] ?? [];
            if (!empty($saleOrders) && is_array($saleOrders)) {
                foreach ($saleOrders as $so) {
                    $soId = (int) ($so['id'] ?? 0);
                    if ($soId > 0) {
                        $orders[] = ['id' => $soId, 'name' => $so['name'] ?? null];
                    }
                }
            }
            if (empty($orders) && isset($data['sale_order']['id'])) {
                $soId = (int) $data['sale_order']['id'];
                if ($soId > 0) {
                    $orders[] = ['id' => $soId, 'name' => $data['sale_order']['name'] ?? null];
                }
            }
            if (empty($orders)) {
                $soId = (int) ($data['order_id'] ?? 0);
                if ($soId > 0) {
                    $orders[] = ['id' => $soId, 'name' => $data['order_name'] ?? $data['order_ref'] ?? null];
                }
            }

            if (empty($orders)) {
                $totalSkipped++;
                continue;
            }

            foreach ($orders as $so) {
                try {
                    $insertStmt->execute([
                        $bdoId,
                        $bdoName,
                        $so['id'],
                        $so['name'],
                        $amountTotal,
                        $paymentReference,
                        $partnerId,
                        $customerName,
                        $lineUserId,
                        $paymentMethod,
                        $row['delivery_id'],
                    ]);
                    $totalInserted++;
                } catch (Exception $e) {
                    $totalErrors++;
                    if ($totalErrors <= 5) {
                        echo "  ⚠ Error BDO {$bdoId} ↔ SO {$so['id']}: " . $e->getMessage() . "\n";
                    }
                }
            }
        }

        $offset += $batch;
        echo "  Processed {$processed} webhooks... (inserted: {$totalInserted}, skipped: {$totalSkipped})\n";

    } while (count($rows) === $batch);

    echo "\n=== Backfill Complete ===\n";
    echo "  Processed: {$processed}\n";
    echo "  Inserted/Updated: {$totalInserted}\n";
    echo "  Skipped (no orders): {$totalSkipped}\n";
    echo "  Errors: {$totalErrors}\n";

    // Show table count
    $count = $db->query("SELECT COUNT(*) FROM odoo_bdo_orders")->fetchColumn();
    echo "\n  odoo_bdo_orders total rows: {$count}\n";

} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
