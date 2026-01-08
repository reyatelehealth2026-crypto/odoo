<?php
// Debug price extraction from cny_products
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug CNY Products Price</h2>";

$stmt = $db->query("SELECT id, sku, product_price FROM cny_products LIMIT 5");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<hr>";
    echo "<p><strong>SKU:</strong> " . htmlspecialchars($row['sku']) . "</p>";
    echo "<p><strong>product_price type:</strong> " . gettype($row['product_price']) . "</p>";
    echo "<p><strong>product_price raw (first 500 chars):</strong></p>";
    echo "<pre>" . htmlspecialchars(substr($row['product_price'] ?? 'NULL', 0, 500)) . "</pre>";
    
    // Try to decode
    $decoded = json_decode($row['product_price'], true);
    echo "<p><strong>json_decode result:</strong> " . (is_array($decoded) ? 'Array with ' . count($decoded) . ' items' : 'FAILED - ' . json_last_error_msg()) . "</p>";
    
    if (is_array($decoded) && !empty($decoded)) {
        echo "<p><strong>First price entry:</strong></p>";
        echo "<pre>" . print_r($decoded[0], true) . "</pre>";
        
        // Extract price
        $price = 0;
        foreach ($decoded as $p) {
            if (strpos($p['customer_group'] ?? '', 'GEN') !== false) {
                $price = floatval($p['price'] ?? 0);
                break;
            }
        }
        if ($price == 0 && isset($decoded[0]['price'])) {
            $price = floatval($decoded[0]['price']);
        }
        echo "<p><strong>Extracted price:</strong> " . $price . "</p>";
    }
}
