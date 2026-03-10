<?php
/**
 * Inline Test - Direct function call
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/Database.php';

use Modules\Core\Database;

$db = Database::getInstance()->getConnection();

// Test payload from analyze output
$payload = [
    "order_id" => 162473,
    "order_name" => "SO2602-04462",
    "old_state" => "picked",
    "new_state" => "packing",
    "old_state_display" => "จัดเสร็จแล้ว",
    "new_state_display" => "กำลังแพ็ค",
    "customer" => [
        "id" => 8480,
        "ref" => "PC210014",
        "name" => "บริษัท ใบปอฟาร์มาซี จำกัด",
        "line_user_id" => null,
        "phone" => "0947812647"
    ],
    "salesperson" => [
        "id" => 29,
        "name" => "วิรัชนก ฉ่ำชะเอม",
        "line_user_id" => false
    ],
    "picker" => null,
    "amount_total" => 3583.5,
    "currency" => "THB",
    "order_date" => "2026-02-12",
    "expected_delivery" => "2026-02-12",
    "items_count" => 11
];

echo "=== Inline Test ===\n\n";

// Manually extract data like syncOrder does
$orderId = (int) ($payload['order_id'] ?? $payload['id'] ?? 0);
echo "1. Extract order_id: {$orderId}\n";

if (!$orderId && isset($payload['order']['id'])) {
    $orderId = (int) $payload['order']['id'];
    echo "   → Fallback to order.id: {$orderId}\n";
}

if (!$orderId) {
    echo "   ✗ NO ORDER ID FOUND - syncOrder would return false here!\n";
    exit(1);
}

$customer = $payload['customer'] ?? [];
echo "2. Customer data: " . (empty($customer) ? "EMPTY" : "OK") . "\n";

// Extract partner_id
$partnerId = null;
if (isset($customer['id']) && $customer['id']) {
    $partnerId = (int) $customer['id'];
    echo "3. Extract partner_id from customer.id: {$partnerId}\n";
} elseif (isset($customer['partner_id']) && $customer['partner_id']) {
    $partnerId = (int) $customer['partner_id'];
    echo "3. Extract partner_id from customer.partner_id: {$partnerId}\n";
} else {
    echo "3. ✗ NO PARTNER ID - will be NULL\n";
}

// Build data array
$data = [
    'order_id' => $orderId,
    'order_name' => $payload['order_name'] ?? $payload['name'] ?? $payload['order_ref'] ?? null,
    'partner_id' => $partnerId,
    'customer_ref' => $customer['ref'] ?? null,
    'line_user_id' => $customer['line_user_id'] ?? null,
    'state' => $payload['state'] ?? $payload['new_state'] ?? null,
    'state_display' => $payload['state_display'] ?? $payload['new_state_display'] ?? null,
    'amount_total' => isset($payload['amount_total']) ? (float) $payload['amount_total'] : null,
    'currency' => $payload['currency'] ?? 'THB',
];

echo "\n4. Data to insert:\n";
foreach ($data as $key => $value) {
    if ($value === null) {
        echo "   {$key}: NULL\n";
    } else {
        echo "   {$key}: {$value}\n";
    }
}

// Filter out NULLs
$data = array_filter($data, function($value) {
    return $value !== null;
});
$data['order_id'] = $orderId; // Ensure order_id is always present

echo "\n5. After filtering NULLs:\n";
foreach ($data as $key => $value) {
    echo "   {$key}: {$value}\n";
}

// Try to insert
echo "\n6. Attempting INSERT...\n";

try {
    // Check if exists
    $stmt = $db->prepare("SELECT id FROM odoo_orders WHERE order_id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($exists) {
        echo "   → Order already exists (id: {$exists['id']}), would UPDATE\n";
    } else {
        echo "   → Order does not exist, will INSERT\n";
        
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        
        $sql = "INSERT INTO odoo_orders (`" . implode('`, `', $fields) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        echo "   SQL: {$sql}\n";
        echo "   Values: " . implode(', ', array_values($data)) . "\n";
        
        $stmt = $db->prepare($sql);
        $result = $stmt->execute(array_values($data));
        
        if ($result) {
            echo "   ✓ INSERT SUCCESS\n";
            
            // Verify
            $verifyStmt = $db->prepare("SELECT order_name, partner_id, state FROM odoo_orders WHERE order_id = ?");
            $verifyStmt->execute([$orderId]);
            $row = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            echo "\n7. Verification:\n";
            echo "   order_name: {$row['order_name']}\n";
            echo "   partner_id: {$row['partner_id']}\n";
            echo "   state: {$row['state']}\n";
            
            // Cleanup
            echo "\n8. Cleaning up test data...\n";
            $db->exec("DELETE FROM odoo_orders WHERE order_id = {$orderId}");
            echo "   ✓ Cleanup complete\n";
        } else {
            echo "   ✗ INSERT FAILED\n";
            print_r($stmt->errorInfo());
        }
    }
} catch (Exception $e) {
    echo "   ✗ EXCEPTION: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
    echo "   Trace:\n{$e->getTraceAsString()}\n";
}

echo "\n=== Test Complete ===\n";
