<?php
/**
 * Run One Sync - Direct test with full error output
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (function_exists('opcache_reset')) opcache_reset();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooSyncService.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

echo "=== Direct Sync Test ===\n\n";

// Show file path being loaded
$ref = new ReflectionClass('OdooSyncService');
echo "Loading OdooSyncService from: " . $ref->getFileName() . "\n";
echo "syncOrder starts at line: " . $ref->getMethod('syncOrder')->getStartLine() . "\n\n";

// Get first unsynced order webhook
$stmt = $db->prepare("
    SELECT id, event_type, payload FROM odoo_webhooks_log
    WHERE event_type LIKE 'order.%' AND synced_to_tables = FALSE
    LIMIT 1
");
$stmt->execute();
$wh = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$wh) {
    echo "No unsynced order webhooks found\n";
    exit(1);
}

echo "Testing webhook #{$wh['id']} ({$wh['event_type']})\n\n";

$payload = json_decode($wh['payload'], true);
echo "Payload keys: " . implode(', ', array_keys($payload)) . "\n";

$orderId = (int)($payload['order_id'] ?? $payload['id'] ?? 0);
echo "order_id: {$orderId}\n";

$customer = $payload['customer'] ?? [];
$partnerId = isset($customer['id']) ? (int)$customer['id'] : null;
echo "partner_id: {$partnerId}\n\n";

// Check table exists and has right columns
$checkStmt = $db->query("SHOW COLUMNS FROM odoo_orders LIKE 'order_id'");
if ($checkStmt->rowCount() === 0) {
    echo "❌ CRITICAL: odoo_orders table has NO 'order_id' column!\n";
    echo "Run SHOW COLUMNS FROM odoo_orders; to check actual structure\n";
    exit(1);
}
echo "✓ odoo_orders.order_id column exists\n\n";

// Try direct INSERT
$data = [
    'order_id'   => $orderId,
    'order_name' => $payload['order_name'] ?? null,
    'partner_id' => $partnerId,
    'state'      => $payload['new_state'] ?? $payload['state'] ?? null,
    'amount_total' => (float)($payload['amount_total'] ?? 0),
];

// Filter nulls
$data = array_filter($data, fn($v) => $v !== null);
$data['order_id'] = $orderId;

echo "Attempting INSERT with: " . json_encode($data) . "\n\n";

try {
    $fields = array_keys($data);
    $placeholders = array_fill(0, count($fields), '?');
    $sql = "INSERT INTO odoo_orders (`" . implode('`, `', $fields) . "`) VALUES (" . implode(',', $placeholders) . ")";
    
    echo "SQL: $sql\n\n";
    
    $insertStmt = $db->prepare($sql);
    $result = $insertStmt->execute(array_values($data));
    
    if ($result) {
        echo "✓ INSERT SUCCESS!\n";
        
        $verifyStmt = $db->prepare("SELECT order_id, order_name, partner_id, state FROM odoo_orders WHERE order_id = ?");
        $verifyStmt->execute([$orderId]);
        $row = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        echo "Verified: " . json_encode($row) . "\n";
        
        // Cleanup
        $db->exec("DELETE FROM odoo_orders WHERE order_id = {$orderId}");
        echo "✓ Cleanup done\n";
    } else {
        echo "✗ INSERT FAILED\n";
        echo "Error: " . implode(', ', $insertStmt->errorInfo()) . "\n";
    }
} catch (Exception $e) {
    echo "✗ EXCEPTION: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    
    // Check if it's because already exists
    if ($e->getCode() == 23000) {
        echo "\n→ Duplicate entry! order_id {$orderId} already exists in odoo_orders\n";
        $checkStmt = $db->prepare("SELECT * FROM odoo_orders WHERE order_id = ?");
        $checkStmt->execute([$orderId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        echo "Existing row: " . json_encode($existing) . "\n";
    }
}

echo "\n=== Done ===\n";
