<?php
/**
 * Debug AI Settings Table
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug AI Settings</h2>";

// Check table structure
echo "<h3>1. Table Structure</h3>";
try {
    $stmt = $db->query("DESCRIBE ai_settings");
    echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        foreach ($row as $val) {
            echo "<td>" . htmlspecialchars($val ?? 'NULL') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Check indexes
echo "<h3>2. Indexes</h3>";
try {
    $stmt = $db->query("SHOW INDEX FROM ai_settings");
    echo "<table border='1' cellpadding='5'><tr><th>Key_name</th><th>Column_name</th><th>Non_unique</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr><td>{$row['Key_name']}</td><td>{$row['Column_name']}</td><td>{$row['Non_unique']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Check current data
echo "<h3>3. Current Data</h3>";
try {
    $stmt = $db->query("SELECT * FROM ai_settings ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "<p>No data in table</p>";
    } else {
        echo "<table border='1' cellpadding='5'><tr>";
        foreach (array_keys($rows[0]) as $col) {
            echo "<th>$col</th>";
        }
        echo "</tr>";
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $key => $val) {
                $display = $key === 'setting_value' && strlen($val) > 50 ? substr($val, 0, 50) . '...' : $val;
                echo "<td>" . htmlspecialchars($display ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Test insert
echo "<h3>4. Test Insert</h3>";
try {
    $testKey = 'test_key_' . time();
    $stmt = $db->prepare("INSERT INTO ai_settings (line_account_id, setting_key, setting_value) VALUES (?, ?, ?) 
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $result = $stmt->execute([null, $testKey, 'test_value']);
    echo "<p style='color:green'>✅ Insert test passed! (key: $testKey)</p>";
    
    // Clean up
    $db->prepare("DELETE FROM ai_settings WHERE setting_key = ?")->execute([$testKey]);
    echo "<p>Cleaned up test data</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Insert failed: " . $e->getMessage() . "</p>";
}

// Session info
echo "<h3>5. Session Info</h3>";
echo "<p>current_bot_id: " . ($_SESSION['current_bot_id'] ?? 'NULL') . "</p>";
