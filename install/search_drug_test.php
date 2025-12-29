<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
$db = Database::getInstance()->getConnection();

$search = $_GET['q'] ?? 'คูป';
echo "<h3>Searching for: $search</h3>";

// Search in name - check is_active status
$stmt = $db->prepare("SELECT id, name, generic_name, is_active FROM business_items WHERE name LIKE ? OR generic_name LIKE ? LIMIT 30");
$stmt->execute(["%$search%", "%$search%"]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found: " . count($results) . " items</p>";
echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Generic</th><th>is_active</th></tr>";
foreach ($results as $r) {
    $active = $r['is_active'] ? '✅' : '❌';
    echo "<tr><td>{$r['id']}</td><td>{$r['name']}</td><td>{$r['generic_name']}</td><td>{$active}</td></tr>";
}
echo "</table>";

// Count active vs inactive
$stmt = $db->query("SELECT COUNT(*) as total, SUM(is_active) as active FROM business_items");
$counts = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p>Total items: {$counts['total']}, Active: {$counts['active']}</p>";
