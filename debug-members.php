<!DOCTYPE html>
<html>
<head>
    <title>Debug Group Members</title>
    <meta charset="UTF-8">
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; margin: 20px 0; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
        h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; }
    </style>
</head>
<body>
    <h1>🔍 Debug Group Members Data</h1>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // 1. Check groups
    echo '<div class="section">';
    echo '<h2>1. Groups in Database</h2>';
    $stmt = $db->query("SELECT id, group_id, group_name, member_count, total_messages FROM line_groups ORDER BY id LIMIT 10");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($groups)) {
        echo '<p class="error">❌ No groups found!</p>';
    } else {
        echo '<p class="success">✓ Found ' . count($groups) . ' groups</p>';
        echo '<table>';
        echo '<tr><th>DB ID</th><th>Group ID</th><th>Name</th><th>Member Count</th><th>Messages</th></tr>';
        foreach ($groups as $group) {
            echo '<tr>';
            echo '<td>' . $group['id'] . '</td>';
            echo '<td>' . substr($group['group_id'], 0, 20) . '...</td>';
            echo '<td>' . htmlspecialchars($group['group_name']) . '</td>';
            echo '<td>' . $group['member_count'] . '</td>';
            echo '<td>' . $group['total_messages'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 2. Check members
    echo '<div class="section">';
    echo '<h2>2. Members in Database</h2>';
    $stmt = $db->query("SELECT COUNT(*) as count FROM line_group_members");
    $memberCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($memberCount['count'] == 0) {
        echo '<p class="error">❌ NO MEMBERS IN DATABASE!</p>';
        echo '<p class="warning">⚠️ This is why the UI shows "ยังไม่มีข้อมูลสมาชิก"</p>';
        
        echo '<h3>Table Structure:</h3>';
        $stmt = $db->query("DESCRIBE line_group_members");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<table>';
        echo '<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>';
        foreach ($columns as $col) {
            echo '<tr>';
            echo '<td>' . $col['Field'] . '</td>';
            echo '<td>' . $col['Type'] . '</td>';
            echo '<td>' . $col['Null'] . '</td>';
            echo '<td>' . $col['Key'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        echo '<h3>Solution:</h3>';
        echo '<p>Members need to be synced from LINE API. Options:</p>';
        echo '<ol>';
        echo '<li>Use LINE Messaging API to get group members</li>';
        echo '<li>Add test data manually for testing</li>';
        echo '<li>Wait for webhook events when users join/leave groups</li>';
        echo '</ol>';
        
        echo '<h3>Test Data SQL:</h3>';
        echo '<pre>';
        echo "-- Add test members for group ID 1\n";
        echo "INSERT INTO line_group_members (group_id, line_user_id, display_name, is_active, joined_at, created_at, updated_at)\n";
        echo "VALUES \n";
        echo "  (1, 'U1234567890abcdef', 'Test User 1', 1, NOW(), NOW(), NOW()),\n";
        echo "  (1, 'U2345678901bcdefg', 'Test User 2', 1, NOW(), NOW(), NOW()),\n";
        echo "  (1, 'U3456789012cdefgh', 'Test User 3', 1, NOW(), NOW(), NOW());\n";
        echo '</pre>';
        
    } else {
        echo '<p class="success">✓ Found ' . $memberCount['count'] . ' members</p>';
        
        // Show members by group
        $stmt = $db->query("
            SELECT group_id, COUNT(*) as count 
            FROM line_group_members 
            GROUP BY group_id 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $membersByGroup = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h3>Members by Group:</h3>';
        echo '<table>';
        echo '<tr><th>Group ID</th><th>Member Count</th></tr>';
        foreach ($membersByGroup as $row) {
            echo '<tr>';
            echo '<td>' . $row['group_id'] . '</td>';
            echo '<td>' . $row['count'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        // Show sample members
        echo '<h3>Sample Members:</h3>';
        $stmt = $db->query("SELECT * FROM line_group_members LIMIT 10");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo '<table>';
        echo '<tr><th>ID</th><th>Group ID</th><th>User ID</th><th>Display Name</th><th>Active</th></tr>';
        foreach ($members as $member) {
            echo '<tr>';
            echo '<td>' . $member['id'] . '</td>';
            echo '<td>' . $member['group_id'] . '</td>';
            echo '<td>' . substr($member['line_user_id'], 0, 15) . '...</td>';
            echo '<td>' . htmlspecialchars($member['display_name'] ?? 'N/A') . '</td>';
            echo '<td>' . ($member['is_active'] ? '✓' : '✗') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 3. Check messages
    echo '<div class="section">';
    echo '<h2>3. Messages in Database</h2>';
    $stmt = $db->query("SELECT COUNT(*) as count FROM line_group_messages");
    $messageCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($messageCount['count'] == 0) {
        echo '<p class="warning">⚠️ No messages in database</p>';
    } else {
        echo '<p class="success">✓ Found ' . $messageCount['count'] . ' messages</p>';
        
        $stmt = $db->query("
            SELECT group_id, COUNT(*) as count 
            FROM line_group_messages 
            GROUP BY group_id 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $messagesByGroup = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<table>';
        echo '<tr><th>Group ID</th><th>Message Count</th></tr>';
        foreach ($messagesByGroup as $row) {
            echo '<tr>';
            echo '<td>' . $row['group_id'] . '</td>';
            echo '<td>' . $row['count'] . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 4. Test API call
    echo '<div class="section">';
    echo '<h2>4. Test API Call</h2>';
    echo '<p>Testing: <code>GET /api/inbox-groups.php?action=get_members&group_id=1</code></p>';
    
    $stmt = $db->prepare("SELECT * FROM line_group_members WHERE group_id = ?");
    $stmt->execute([1]);
    $group1Members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($group1Members)) {
        echo '<p class="error">❌ No members for group_id = 1</p>';
        echo '<p>API will return: <code>{"success": true, "data": {"members": []}}</code></p>';
    } else {
        echo '<p class="success">✓ Found ' . count($group1Members) . ' members for group_id = 1</p>';
        echo '<pre>' . json_encode(['success' => true, 'data' => ['members' => $group1Members]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
    }
    echo '</div>';
    
    // 5. Recommendations
    echo '<div class="section">';
    echo '<h2>5. Recommendations</h2>';
    
    if ($memberCount['count'] == 0) {
        echo '<div class="warning">';
        echo '<h3>⚠️ No Member Data Available</h3>';
        echo '<p><strong>Why:</strong> The database has no member records yet.</p>';
        echo '<p><strong>Solutions:</strong></p>';
        echo '<ol>';
        echo '<li><strong>Sync from LINE API:</strong> Implement member sync functionality</li>';
        echo '<li><strong>Add Test Data:</strong> Insert sample members for testing (see SQL above)</li>';
        echo '<li><strong>Wait for Webhooks:</strong> Members will be added when they interact in groups</li>';
        echo '</ol>';
        echo '</div>';
    } else {
        echo '<div class="success">';
        echo '<h3>✓ Member Data Available</h3>';
        echo '<p>The database has member records. If UI still shows 0 members, check:</p>';
        echo '<ol>';
        echo '<li>Browser console for API errors</li>';
        echo '<li>Network tab to see actual API responses</li>';
        echo '<li>Verify group_id parameter is being sent correctly</li>';
        echo '</ol>';
        echo '</div>';
    }
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="section error">';
    echo '<h2>❌ Error</h2>';
    echo '<p><strong>Message:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
    echo '<p><strong>Line:</strong> ' . $e->getLine() . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</div>';
}
?>

<div class="section">
    <h2>📝 Quick Summary</h2>
    <p>This page checks if your database has member data for LINE groups.</p>
    <p><strong>Expected Result:</strong> You should see tables with group and member information.</p>
    <p><strong>If you see errors:</strong> Check that your database connection is working.</p>
</div>

</body>
</html>
