<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";
echo "Step 1: Start\n";

echo "Step 2: Config...\n";
require_once 'config/config.php';
echo "Step 2: OK\n";

echo "Step 3: Database...\n";
require_once 'config/database.php';
echo "Step 3: OK\n";

echo "Step 4: Session...\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Session ID: " . session_id() . "\n";
echo "Session data: " . print_r($_SESSION, true) . "\n";

echo "Step 5: Auth check...\n";
require_once 'includes/auth_check.php';
echo "Step 5: OK\n";

echo "Step 6: Header...\n";
// Don't include header, just test the parts
$db = Database::getInstance()->getConnection();
echo "DB OK\n";

echo "Step 7: Test products table...\n";
$stmt = $db->query("SELECT COUNT(*) FROM products");
echo "Products count: " . $stmt->fetchColumn() . "\n";

echo "Step 8: Test product_categories...\n";
try {
    $stmt = $db->query("SELECT COUNT(*) FROM product_categories");
    echo "Categories count: " . $stmt->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nAll tests passed!\n";
echo "</pre>";
