<?php
/**
 * Reset customer health profiles to force re-classification
 * Run this after updating the classification algorithm
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Reset Customer Health Profiles</h2>";

try {
    // Clear existing profiles
    $stmt = $db->exec("DELETE FROM customer_health_profiles");
    echo "<p style='color:green;'>✓ Cleared all customer health profiles</p>";
    echo "<p>Profiles will be re-analyzed when customers are viewed in inbox-v2</p>";
    echo "<p><a href='debug_classification.php'>→ Go to debug classification page</a></p>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}
