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
echo "<h3>3. Verify Token</h3>";
if ($account && !empty($account['channel_access_token'])) {
    $token = $account['channel_access_token'];
    
    // Verify token
    $ch = curl_init('https://api.line.me/v2/bot/info');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "Token verification: ";
    if ($httpCode === 200) {
        $botInfo = json_decode($response, true);
        echo "✅ Valid<br>";
        echo "Bot Name: " . htmlspecialchars($botInfo['displayName'] ?? 'N/A') . "<br>";
        echo "Bot ID: " . ($botInfo['userId'] ?? 'N/A') . "<br>";
    } else {
        echo "❌ Invalid (HTTP {$httpCode})<br>";
        echo "Response: " . htmlspecialchars($response) . "<br>";
    }
}

echo "<h3>4. Check User Follow Status</h3>";
if ($account && !empty($account['channel_access_token']) && $user && !empty($user['line_user_id'])) {
    $token = $account['channel_access_token'];
    $lineUserId = $user['line_user_id'];
    
    // Get user profile to check if they follow the bot
    $ch = curl_init("https://api.line.me/v2/bot/profile/{$lineUserId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "User follow status: ";
    if ($httpCode === 200) {
        $profile = json_decode($response, true);
        echo "✅ User follows this bot<br>";
        echo "Display Name: " . htmlspecialchars($profile['displayName'] ?? 'N/A') . "<br>";
    } else {
        echo "❌ User does NOT follow this bot or blocked (HTTP {$httpCode})<br>";
        echo "Response: " . htmlspecialchars($response) . "<br>";
        echo "<br><strong style='color:red'>This is why messages fail! User must add the bot as friend first.</strong><br>";
    }
}

echo "<h3>5. Send Test Message</h3>";
echo "<h3>6. All LINE Accounts</h3>";
try {
    $stmt = $db->query("SELECT id, name, channel_access_token, is_active FROM line_accounts ORDER BY id");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Token</th><th>Active</th></tr>";
    foreach ($accounts as $acc) {
        $tokenLen = strlen($acc['channel_access_token'] ?? '');
        echo "<tr>";
        echo "<td>{$acc['id']}</td>";
        echo "<td>" . htmlspecialchars($acc['name']) . "</td>";
        echo "<td>" . ($tokenLen > 0 ? "{$tokenLen} chars" : 'No token') . "</td>";
        echo "<td>" . ($acc['is_active'] ? '✅' : '❌') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

echo "<h3>7. User's LINE Account</h3>";
try {
    $stmt = $db->query("SELECT u.id, u.display_name, u.line_user_id, u.line_account_id, la.name as account_name 
                        FROM users u 
                        LEFT JOIN line_accounts la ON u.line_account_id = la.id 
                        WHERE u.id = 28");
    $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($userInfo) {
        echo "User ID: {$userInfo['id']}<br>";
        echo "Display Name: " . htmlspecialchars($userInfo['display_name']) . "<br>";
        echo "LINE User ID: {$userInfo['line_user_id']}<br>";
        echo "LINE Account ID: " . ($userInfo['line_account_id'] ?? 'NULL') . "<br>";
        echo "LINE Account Name: " . htmlspecialchars($userInfo['account_name'] ?? 'N/A') . "<br>";
        
        if ($userInfo['line_account_id']) {
            // Try with user's LINE account
            $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
            $stmt->execute([$userInfo['line_account_id']]);
            $userAccount = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userAccount && !empty($userAccount['channel_access_token'])) {
                echo "<br><strong>Testing with user's LINE account token...</strong><br>";
                
                $token = $userAccount['channel_access_token'];
                $lineUserId = $userInfo['line_user_id'];
                
                // Check follow status
                $ch = curl_init("https://api.line.me/v2/bot/profile/{$lineUserId}");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    echo "✅ User follows this bot!<br>";
                    
                    if (isset($_GET['send2'])) {
                        // Send test message
                        $data = [
                            'to' => $lineUserId,
                            'messages' => [['type' => 'text', 'text' => "🧪 ทดสอบจาก account ที่ถูกต้อง\n\nเวลา: " . date('Y-m-d H:i:s')]]
                        ];
                        
                        $ch = curl_init('https://api.line.me/v2/bot/message/push');
                        curl_setopt_array($ch, [
                            CURLOPT_POST => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
                            CURLOPT_POSTFIELDS => json_encode($data)
                        ]);
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        
                        echo "Send result: " . ($httpCode === 200 ? '✅ SUCCESS!' : "❌ Failed (HTTP {$httpCode})") . "<br>";
                    } else {
                        echo "<a href='?send2=1' style='background:#10b981;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;'>📤 ส่งข้อความทดสอบ (ใช้ account ที่ถูกต้อง)</a>";
                    }
                } else {
                    echo "❌ User does not follow this bot either<br>";
                }
            }
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

