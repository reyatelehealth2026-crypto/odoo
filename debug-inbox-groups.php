<!DOCTYPE html>
<html>
<head>
    <title>Debug Inbox Groups API</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        .test { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
        .success { background: #d4edda; }
        .error { background: #f8d7da; }
        pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Debug Inbox Groups API</h1>
    
    <?php
    require_once __DIR__ . '/config/config.php';
    require_once __DIR__ . '/config/database.php';
    
    // Test 1: Check database connection
    echo '<div class="test">';
    echo '<h2>Test 1: Database Connection</h2>';
    try {
        $db = Database::getInstance()->getConnection();
        echo '<p class="success">✓ Database connected</p>';
    } catch (Exception $e) {
        echo '<p class="error">✗ Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
    
    // Test 2: Check line_groups table
    echo '<div class="test">';
    echo '<h2>Test 2: Check line_groups Table</h2>';
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM line_groups");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo '<p>Total groups: ' . $result['count'] . '</p>';
        
        if ($result['count'] > 0) {
            $stmt = $db->query("SELECT id, group_id, group_name, is_active, line_account_id FROM line_groups LIMIT 5");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo '<pre>' . print_r($groups, true) . '</pre>';
        } else {
            echo '<p class="error">No groups found in database</p>';
        }
    } catch (Exception $e) {
        echo '<p class="error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
    
    // Test 3: Test API directly
    echo '<div class="test">';
    echo '<h2>Test 3: Test API Endpoint</h2>';
    
    // Simulate GET request
    $_GET['action'] = 'get_stats';
    $_GET['line_account_id'] = '1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_ADMIN_ID'] = '1';
    $_SERVER['HTTP_X_LINE_ACCOUNT_ID'] = '1';
    
    echo '<p>Simulating: GET /api/inbox-groups.php?action=get_stats&line_account_id=1</p>';
    
    ob_start();
    try {
        include __DIR__ . '/api/inbox-groups.php';
        $output = ob_get_clean();
        echo '<pre>' . htmlspecialchars($output) . '</pre>';
    } catch (Exception $e) {
        ob_end_clean();
        echo '<p class="error">✗ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</div>';
    
    // Test 4: Check if file exists
    echo '<div class="test">';
    echo '<h2>Test 4: Check API File</h2>';
    $apiFile = __DIR__ . '/api/inbox-groups.php';
    if (file_exists($apiFile)) {
        echo '<p class="success">✓ File exists: ' . $apiFile . '</p>';
        echo '<p>File size: ' . filesize($apiFile) . ' bytes</p>';
        echo '<p>Readable: ' . (is_readable($apiFile) ? 'Yes' : 'No') . '</p>';
    } else {
        echo '<p class="error">✗ File not found: ' . $apiFile . '</p>';
    }
    echo '</div>';
    
    // Test 5: Check required classes
    echo '<div class="test">';
    echo '<h2>Test 5: Check Required Classes</h2>';
    $classes = ['Database', 'LineAPI', 'LineAccountManager'];
    foreach ($classes as $class) {
        if (class_exists($class)) {
            echo '<p class="success">✓ Class exists: ' . $class . '</p>';
        } else {
            echo '<p class="error">✗ Class not found: ' . $class . '</p>';
        }
    }
    echo '</div>';
    ?>
    
    <div class="test">
        <h2>Test 6: Manual API Test</h2>
        <p>Try accessing the API directly:</p>
        <ul>
            <li><a href="/api/inbox-groups.php?action=get_stats&line_account_id=1" target="_blank">Get Stats</a></li>
            <li><a href="/api/inbox-groups.php?action=get_groups&line_account_id=1" target="_blank">Get Groups</a></li>
        </ul>
    </div>
</body>
</html>
