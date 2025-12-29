<?php
/**
 * Test LINE Push Message
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Test LINE Push Message</h2>";

// 1. Get LINE account
echo "<h3>1. LINE Account</h3>";
$account = null;
try {
    $stmt = $db->query("SELECT id, name, channel_access_token FROM line_accounts WHERE is_active = 1 LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($account) {
        echo "Account ID: " . $account['id'] . "<br>";
        echo "Account Name: " . htmlspecialchars($account['name']) . "<br>";
        $tokenLen = strlen($account['channel_access_token'] ?? '');
        echo "Token exists: " . ($tokenLen > 0 ? "Yes ({$tokenLen} chars)" : '<span style="color:red">No - THIS IS THE PROBLEM!</span>') . "<br>";
        if ($tokenLen > 0) {
            echo "Token preview: " . substr($account['channel_access_token'], 0, 20) . "...<br>";
        }
    } else {
        echo "❌ No active LINE account found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 2. Get user
echo "<h3>2. User Info</h3>";
$user = null;
try {
    $stmt = $db->query("SELECT id, display_name, line_user_id FROM users WHERE id = 28");
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "User ID: " . $user['id'] . "<br>";
        echo "Display Name: " . htmlspecialchars($user['display_name']) . "<br>";
        echo "LINE User ID: " . ($user['line_user_id'] ?? 'N/A') . "<br>";
    } else {
        echo "❌ User not found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// 3. Direct LINE API Test
echo "<h3>3. Direct LINE API Test</h3>";
if ($account && !empty($account['channel_access_token']) && $user && !empty($user['line_user_id'])) {
    if (isset($_GET['send'])) {
        $token = $account['channel_access_token'];
        $lineUserId = $user['line_user_id'];
        
        $data = [
            'to' => $lineUserId,
            'messages' => [[
                'type' => 'text',
                'text' => "🧪 ทดสอบส่งข้อความ\n\nเวลา: " . date('Y-m-d H:i:s')
            ]]
        ];
        
        echo "Sending to: {$lineUserId}<br>";
        echo "Using token: " . substr($token, 0, 20) . "...<br>";
        
        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token
            ],
            CURLOPT_POSTFIELDS => json_encode($data)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        echo "<br><strong>Result:</strong><br>";
        echo "HTTP Code: {$httpCode}<br>";
        if ($curlError) {
            echo "cURL Error: {$curlError}<br>";
        }
        echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";
        
        if ($httpCode === 200) {
            echo "<br>✅ <strong style='color:green'>SUCCESS!</strong>";
        } else {
            echo "<br>❌ <strong style='color:red'>FAILED</strong>";
        }
    } else {
        echo "<a href='?send=1' style='background:#10b981;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>📤 ส่งข้อความทดสอบ</a>";
    }
} else {
    echo "❌ Cannot test - missing token or user LINE ID<br>";
    if (!$account || empty($account['channel_access_token'])) {
        echo "- No channel_access_token in line_accounts table<br>";
    }
    if (!$user || empty($user['line_user_id'])) {
        echo "- No line_user_id for user<br>";
    }
}

