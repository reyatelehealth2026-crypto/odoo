<?php
/**
 * Debug CRM API for HUD Panel
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Debug CRM API</h2>";

// Test user ID (change this to a valid user ID)
$userId = $_GET['user_id'] ?? 15;
$lineAccountId = $_GET['line_account_id'] ?? 3;

echo "<p>Testing with user_id: {$userId}, line_account_id: {$lineAccountId}</p>";

// 1. Check if user exists
echo "<h3>1. User Check</h3>";
$stmt = $db->prepare("SELECT id, display_name, phone, address, line_account_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($user, true) . "</pre>";

// 2. Check user_tags table
echo "<h3>2. All Tags</h3>";
try {
    $stmt = $db->prepare("SELECT id, name, color FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name LIMIT 20");
    $stmt->execute([$lineAccountId]);
    $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($allTags, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 3. Check user's assigned tags
echo "<h3>3. User's Tags</h3>";
try {
    $stmt = $db->prepare("SELECT ut.id, ut.name, ut.color FROM user_tags ut 
                          JOIN user_tag_assignments uta ON ut.id = uta.tag_id 
                          WHERE uta.user_id = ?");
    $stmt->execute([$userId]);
    $userTags = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>" . print_r($userTags, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 4. Check notes tables
echo "<h3>4. Notes Tables</h3>";

// Check customer_notes
echo "<h4>customer_notes table:</h4>";
try {
    $stmt = $db->query("SHOW TABLES LIKE 'customer_notes'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ customer_notes table exists</p>";
        $stmt = $db->prepare("SELECT * FROM customer_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$userId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . print_r($notes, true) . "</pre>";
    } else {
        echo "<p style='color:orange'>✗ customer_notes table does not exist</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Check user_notes
echo "<h4>user_notes table:</h4>";
try {
    $stmt = $db->query("SHOW TABLES LIKE 'user_notes'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✓ user_notes table exists</p>";
        $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$userId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . print_r($notes, true) . "</pre>";
    } else {
        echo "<p style='color:orange'>✗ user_notes table does not exist</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// 5. Test API directly
echo "<h3>5. Test API Response</h3>";
$apiUrl = "api/inbox-v2.php?action=customer_crm&user_id={$userId}&line_account_id={$lineAccountId}";
echo "<p>API URL: <a href='../{$apiUrl}' target='_blank'>{$apiUrl}</a></p>";

// 6. Test update_customer_info
echo "<h3>6. Test Update Customer Info</h3>";
echo "<form method='POST' action='../api/inbox-v2.php'>
    <input type='hidden' name='action' value='update_customer_info'>
    <input type='hidden' name='user_id' value='{$userId}'>
    <input type='hidden' name='line_account_id' value='{$lineAccountId}'>
    <label>Field: <select name='field'>
        <option value='display_name'>display_name</option>
        <option value='phone'>phone</option>
        <option value='address'>address</option>
    </select></label>
    <label>Value: <input type='text' name='value'></label>
    <button type='submit'>Test Update</button>
</form>";

echo "<h3>7. JavaScript Debug Code</h3>";
echo "<p>Open browser console and run:</p>";
echo "<pre>
console.log('ghostDraftState:', window.ghostDraftState);
console.log('currentBotId:', window.currentBotId);
</pre>";
