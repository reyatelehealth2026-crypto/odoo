<?php
/**
 * Debug Triage Sessions
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug Triage Sessions</h2>";

// 1. Check table exists
echo "<h3>1. Table Structure</h3>";
try {
    $stmt = $db->query("DESCRIBE triage_sessions");
    echo "<table border='1' cellpadding='5'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Key']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Table not found: " . $e->getMessage() . "</p>";
}

// 2. Check recent sessions
echo "<h3>2. Recent Sessions (last 10)</h3>";
try {
    $stmt = $db->query("SELECT ts.*, u.display_name 
                        FROM triage_sessions ts 
                        LEFT JOIN users u ON ts.user_id = u.id 
                        ORDER BY ts.created_at DESC LIMIT 10");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "<p style='color:orange'>⚠️ No sessions found</p>";
    } else {
        echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>User</th><th>State</th><th>Status</th><th>Created</th><th>Updated</th><th>Data Preview</th></tr>";
        foreach ($rows as $row) {
            $dataPreview = mb_substr($row['triage_data'] ?? '', 0, 100) . '...';
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['display_name']} (ID: {$row['user_id']})</td>";
            echo "<td>{$row['current_state']}</td>";
            echo "<td>{$row['status']}</td>";
            echo "<td>{$row['created_at']}</td>";
            echo "<td>{$row['updated_at']}</td>";
            echo "<td><small>" . htmlspecialchars($dataPreview) . "</small></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// 3. Check active sessions
echo "<h3>3. Active Sessions</h3>";
try {
    $stmt = $db->query("SELECT ts.*, u.display_name 
                        FROM triage_sessions ts 
                        LEFT JOIN users u ON ts.user_id = u.id 
                        WHERE ts.status = 'active'
                        ORDER BY ts.updated_at DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "<p style='color:orange'>⚠️ No active sessions</p>";
    } else {
        echo "<p style='color:green'>✅ Found " . count($rows) . " active sessions</p>";
        echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>User</th><th>State</th><th>Updated</th></tr>";
        foreach ($rows as $row) {
            echo "<tr><td>{$row['id']}</td><td>{$row['display_name']}</td><td>{$row['current_state']}</td><td>{$row['updated_at']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}

// 4. Test specific user (if provided)
$testUserId = $_GET['user_id'] ?? null;
if ($testUserId) {
    echo "<h3>4. Test User ID: {$testUserId}</h3>";
    try {
        $stmt = $db->prepare("SELECT * FROM triage_sessions WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$testUserId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            echo "<p style='color:green'>✅ Found active session</p>";
            echo "<pre>" . print_r($session, true) . "</pre>";
            echo "<h4>Triage Data:</h4>";
            echo "<pre>" . json_encode(json_decode($session['triage_data']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        } else {
            echo "<p style='color:orange'>⚠️ No active session for this user</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>5. Test with User ID</h3>";
echo "<form method='get'>";
echo "<input type='number' name='user_id' placeholder='Enter User ID'>";
echo "<button type='submit'>Check</button>";
echo "</form>";
