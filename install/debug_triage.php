<?php
/**
 * Debug Triage Sessions
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Triage Sessions</h2>";

// 1. Check if table exists
echo "<h3>1. Table Check</h3>";
try {
    $stmt = $db->query("SHOW TABLES LIKE 'triage_sessions'");
    $tables = $stmt->fetchAll();
    echo "Tables found: " . count($tables) . "<br>";
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// 2. Count all records
echo "<h3>2. Total Records</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM triage_sessions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total records: " . $result['cnt'] . "<br>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// 3. Show all sessions
echo "<h3>3. All Sessions</h3>";
try {
    $stmt = $db->query("SELECT * FROM triage_sessions ORDER BY created_at DESC LIMIT 20");
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($sessions);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// 4. Test the exact query
echo "<h3>4. Test Query with Date Range</h3>";
$startDate = '2025-12-01';
$endDate = '2025-12-31';
try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status IS NULL OR status = 'active' OR status = '' THEN 1 ELSE 0 END) as in_progress
        FROM triage_sessions 
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Query result for $startDate to $endDate:<br>";
    echo "<pre>";
    print_r($result);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// 5. Check database connection info
echo "<h3>5. Database Info</h3>";
try {
    $stmt = $db->query("SELECT DATABASE() as db_name");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Current database: " . $result['db_name'] . "<br>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
