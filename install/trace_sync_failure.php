<?php
/**
 * Trace Sync Failure
 * 
 * Add detailed logging to understand why sync is failing
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

// Create a modified OdooSyncService with detailed logging
class DebugOdooSyncService
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    public function syncOrder($payload, $eventType, $webhookId)
    {
        echo "  → syncOrder() called\n";
        
        // Try multiple field names for order_id
        $orderId = (int) ($payload['order_id'] ?? $payload['id'] ?? 0);
        echo "    Step 1: Extract order_id from payload['order_id'] or payload['id']: {$orderId}\n";
        
        // If still no order_id, try extracting from order object
        if (!$orderId && isset($payload['order']['id'])) {
            $orderId = (int) $payload['order']['id'];
            echo "    Step 1b: Fallback to payload['order']['id']: {$orderId}\n";
        }
        
        if (!$orderId) {
            echo "    ✗ FAILED: No order_id found - returning false\n";
            return false;
        }
        
        echo "    ✓ order_id found: {$orderId}\n";
        
        $customer = $payload['customer'] ?? [];
        echo "    Step 2: Extract customer: " . (empty($customer) ? "EMPTY" : "OK") . "\n";
        
        // Extract partner_id
        $partnerId = null;
        if (isset($customer['id']) && $customer['id']) {
            $partnerId = (int) $customer['id'];
            echo "    Step 3: Extract partner_id from customer['id']: {$partnerId}\n";
        } elseif (isset($customer['partner_id']) && $customer['partner_id']) {
            $partnerId = (int) $customer['partner_id'];
            echo "    Step 3: Extract partner_id from customer['partner_id']: {$partnerId}\n";
        } else {
            echo "    Step 3: No partner_id found (will be NULL)\n";
        }
        
        // Build data
        $data = [
            'order_id' => $orderId,
            'order_name' => $payload['order_name'] ?? $payload['name'] ?? $payload['order_ref'] ?? null,
            'partner_id' => $partnerId,
            'state' => $payload['state'] ?? $payload['new_state'] ?? null,
            'amount_total' => isset($payload['amount_total']) ? (float) $payload['amount_total'] : null,
        ];
        
        echo "    Step 4: Built data array with " . count($data) . " fields\n";
        
        // Filter NULLs
        $data = array_filter($data, function($value) {
            return $value !== null;
        });
        $data['order_id'] = $orderId;
        
        echo "    Step 5: After filtering NULLs: " . count($data) . " fields remain\n";
        
        // Try insert
        try {
            $stmt = $this->db->prepare("SELECT id FROM odoo_orders WHERE order_id = ? LIMIT 1");
            $stmt->execute([$orderId]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exists) {
                echo "    Step 6: Order exists (id: {$exists['id']}), would UPDATE\n";
                return true;
            } else {
                echo "    Step 6: Order does not exist, attempting INSERT\n";
                
                $fields = array_keys($data);
                $placeholders = array_fill(0, count($fields), '?');
                
                $sql = "INSERT INTO odoo_orders (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $this->db->prepare($sql);
                $result = $stmt->execute(array_values($data));
                
                if ($result) {
                    echo "    ✓ INSERT SUCCESS\n";
                    return true;
                } else {
                    echo "    ✗ INSERT FAILED: " . implode(', ', $stmt->errorInfo()) . "\n";
                    return false;
                }
            }
        } catch (Exception $e) {
            echo "    ✗ EXCEPTION in upsert: {$e->getMessage()}\n";
            return false;
        }
    }
}

try {
    $db = Database::getInstance()->getConnection();
    $debugService = new DebugOdooSyncService($db);
    
    echo "=== Trace Sync Failure ===\n\n";
    
    // Get one order.packing webhook
    $stmt = $db->prepare("
        SELECT id, event_type, payload
        FROM odoo_webhooks_log
        WHERE event_type = 'order.packing'
          AND synced_to_tables = FALSE
        LIMIT 1
    ");
    $stmt->execute();
    $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$webhook) {
        echo "No order.packing webhook found\n";
        exit(1);
    }
    
    echo "Testing webhook #{$webhook['id']} (event: {$webhook['event_type']})\n\n";
    
    $payload = json_decode($webhook['payload'], true);
    
    echo "Payload keys: " . implode(', ', array_keys($payload)) . "\n\n";
    
    echo "Calling syncOrder()...\n";
    $result = $debugService->syncOrder($payload, $webhook['event_type'], $webhook['id']);
    
    echo "\n";
    echo "Result: " . ($result ? "TRUE" : "FALSE") . "\n";
    
    if ($result) {
        // Verify in database
        $verifyStmt = $db->prepare("SELECT order_name, partner_id, state FROM odoo_orders WHERE order_id = ?");
        $verifyStmt->execute([$payload['order_id']]);
        $row = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            echo "\nVerified in database:\n";
            echo "  order_name: {$row['order_name']}\n";
            echo "  partner_id: {$row['partner_id']}\n";
            echo "  state: {$row['state']}\n";
            
            // Cleanup
            $db->exec("DELETE FROM odoo_orders WHERE order_id = {$payload['order_id']}");
            echo "\nCleaned up test data\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
