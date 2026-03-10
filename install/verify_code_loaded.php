<?php
/**
 * Verify Code Loaded
 * 
 * Check if the fixed OdooSyncService code is actually loaded
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OdooSyncService.php';

use Modules\Core\Database;

echo "=== Verify Code Loaded ===\n\n";

// Check if class exists
if (!class_exists('OdooSyncService')) {
    echo "✗ OdooSyncService class not found!\n";
    exit(1);
}

echo "✓ OdooSyncService class loaded\n\n";

// Use reflection to check the syncOrder method
$reflection = new ReflectionClass('OdooSyncService');
$method = $reflection->getMethod('syncOrder');

echo "syncOrder method info:\n";
echo "  File: " . $method->getFileName() . "\n";
echo "  Start line: " . $method->getStartLine() . "\n";
echo "  End line: " . $method->getEndLine() . "\n\n";

// Read the actual code
$file = file($method->getFileName());
$methodCode = array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

echo "First 20 lines of syncOrder():\n";
echo str_repeat("-", 80) . "\n";
foreach (array_slice($methodCode, 0, 20) as $i => $line) {
    echo ($method->getStartLine() + $i) . ": " . $line;
}
echo str_repeat("-", 80) . "\n\n";

// Check if partner_id extraction code is present
$codeStr = implode('', $methodCode);
if (strpos($codeStr, 'customer.id IS the partner_id') !== false) {
    echo "✓ Found partner_id extraction comment\n";
} else {
    echo "✗ Partner_id extraction comment NOT found - old code?\n";
}

if (strpos($codeStr, "if (isset(\$customer['id']) && \$customer['id'])") !== false) {
    echo "✓ Found customer['id'] check\n";
} else {
    echo "✗ customer['id'] check NOT found - old code?\n";
}

echo "\n";

// Now test actual sync
$db = Database::getInstance()->getConnection();
$syncService = new OdooSyncService($db);

echo "Testing actual sync with sample payload...\n";

$testPayload = [
    "order_id" => 999999,
    "order_name" => "TEST-ORDER",
    "new_state" => "packing",
    "customer" => [
        "id" => 8480,
        "ref" => "TEST-REF",
        "name" => "Test Customer"
    ],
    "amount_total" => 1000,
    "currency" => "THB"
];

try {
    $result = $syncService->syncWebhook($testPayload, 'order.packing', 999999);
    
    if ($result) {
        echo "✓ Sync returned TRUE\n";
        
        // Check database
        $stmt = $db->prepare("SELECT order_name, partner_id, state FROM odoo_orders WHERE order_id = ?");
        $stmt->execute([999999]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            echo "✓ Found in database:\n";
            echo "  order_name: {$row['order_name']}\n";
            echo "  partner_id: {$row['partner_id']}\n";
            echo "  state: {$row['state']}\n";
            
            // Cleanup
            $db->exec("DELETE FROM odoo_orders WHERE order_id = 999999");
            echo "✓ Cleanup complete\n";
        } else {
            echo "✗ NOT found in database despite TRUE return!\n";
        }
    } else {
        echo "✗ Sync returned FALSE\n";
        echo "Check error_log or add more debugging\n";
    }
} catch (Exception $e) {
    echo "✗ Exception: {$e->getMessage()}\n";
    echo "  File: {$e->getFile()}:{$e->getLine()}\n";
}

echo "\n=== Verification Complete ===\n";
