<?php
/**
 * ตรวจสอบ Bot Mode จาก LINE API
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';

$db = Database::getInstance()->getConnection();

echo "=== ตรวจสอบ LINE Bot Mode ===\n\n";

try {
    $stmt = $db->query("SELECT id, bot_name, channel_access_token FROM line_accounts WHERE channel_access_token IS NOT NULL");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($accounts as $account) {
        echo "Account {$account['id']}: {$account['bot_name']}\n";
        echo str_repeat("-", 60) . "\n";
        
        $line = new LineAPI($account['channel_access_token']);
        
        // Get bot info
        $ch = curl_init('https://api.line.me/v2/bot/info');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $account['channel_access_token']
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $botInfo = json_decode($response, true);
            echo "✓ Bot Info:\n";
            echo "  User ID: " . ($botInfo['userId'] ?? 'N/A') . "\n";
            echo "  Display Name: " . ($botInfo['displayName'] ?? 'N/A') . "\n";
            echo "  Picture URL: " . ($botInfo['pictureUrl'] ?? 'N/A') . "\n";
            echo "  Status: " . ($botInfo['chatMode'] ?? 'N/A') . "\n";
            
            if (isset($botInfo['chatMode']) && $botInfo['chatMode'] === 'bot') {
                echo "  ✓ Mode: ACTIVE (มี replyToken)\n";
            } else {
                echo "  ⚠️  Mode: STANDBY (ไม่มี replyToken)\n";
            }
        } else {
            echo "✗ ไม่สามารถดึงข้อมูล bot ได้ (HTTP $httpCode)\n";
            echo "  Response: $response\n";
        }
        
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo str_repeat("=", 60) . "\n";
echo "สรุป:\n";
echo "- ถ้า Mode = ACTIVE → มี replyToken ปกติ\n";
echo "- ถ้า Mode = STANDBY → ไม่มี replyToken (ต้องแก้ใน LINE Console)\n";
echo "\nวิธีแก้:\n";
echo "1. เข้า LINE Developers Console\n";
echo "2. เลือก Account ที่เป็น STANDBY\n";
echo "3. Messaging API > Response settings\n";
echo "4. เปลี่ยนเป็น Active mode\n";
