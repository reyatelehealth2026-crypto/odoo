<?php
/**
 * Debug LINE Accounts Configuration
 * ตรวจสอบการตั้งค่าของแต่ละบอท
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🤖 LINE Accounts Configuration</h2>";

try {
    $stmt = $db->query("SELECT * FROM line_accounts ORDER BY id");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "<p style='color: red;'>❌ ไม่พบ LINE Account ในระบบ</p>";
        exit;
    }
    
    echo "<p>พบ " . count($accounts) . " บอท</p>";
    
    foreach ($accounts as $account) {
        echo "<div style='border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>บอท #{$account['id']}: {$account['name']}</h3>";
        
        // Basic Info
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Value</th><th>Status</th></tr>";
        
        // Channel Access Token
        $hasToken = !empty($account['channel_access_token']);
        $tokenPreview = $hasToken ? substr($account['channel_access_token'], 0, 20) . '...' : 'ไม่มี';
        echo "<tr><td>Access Token</td><td>{$tokenPreview}</td><td>" . ($hasToken ? '✅' : '❌') . "</td></tr>";
        
        // Channel Secret
        $hasSecret = !empty($account['channel_secret']);
        $secretPreview = $hasSecret ? substr($account['channel_secret'], 0, 10) . '...' : 'ไม่มี';
        echo "<tr><td>Channel Secret</td><td>{$secretPreview}</td><td>" . ($hasSecret ? '✅' : '❌') . "</td></tr>";
        
        // Bot Mode
        $botMode = $account['bot_mode'] ?? 'shop';
        $modeColor = $botMode === 'general' ? 'orange' : 'green';
        echo "<tr><td>Bot Mode</td><td style='color: {$modeColor}; font-weight: bold;'>{$botMode}</td><td>ℹ️</td></tr>";
        
        // LIFF ID
        $hasLiff = !empty($account['liff_id']);
        $liffId = $hasLiff ? $account['liff_id'] : 'ไม่มี';
        echo "<tr><td>LIFF ID</td><td>{$liffId}</td><td>" . ($hasLiff ? '✅' : '⚠️') . "</td></tr>";
        
        // Webhook URL
        $webhookUrl = $account['webhook_url'] ?? 'ไม่ระบุ';
        echo "<tr><td>Webhook URL</td><td>{$webhookUrl}</td><td>ℹ️</td></tr>";
        
        // Status
        $isActive = ($account['is_active'] ?? 1) == 1;
        echo "<tr><td>Active</td><td>" . ($isActive ? 'เปิดใช้งาน' : 'ปิดใช้งาน') . "</td><td>" . ($isActive ? '✅' : '❌') . "</td></tr>";
        
        echo "</table>";
        
        // Test Connection
        echo "<h4>🔍 ทดสอบการเชื่อมต่อ</h4>";
        
        if ($hasToken) {
            require_once __DIR__ . '/../classes/LineAPI.php';
            $line = new LineAPI($account['channel_access_token'], $account['channel_secret']);
            
            try {
                $botInfo = $line->getBotInfo();
                
                if ($botInfo && isset($botInfo['displayName'])) {
                    echo "<p style='color: green;'>✅ เชื่อมต่อสำเร็จ: <strong>{$botInfo['displayName']}</strong></p>";
                    echo "<p>Bot ID: {$botInfo['userId']}</p>";
                } else {
                    echo "<p style='color: red;'>❌ ไม่สามารถดึงข้อมูลบอทได้</p>";
                    echo "<pre>" . print_r($botInfo, true) . "</pre>";
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ ไม่มี Access Token - ไม่สามารถทดสอบได้</p>";
        }
        
        // Check Auto Reply Rules
        echo "<h4>📋 Auto Reply Rules</h4>";
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM auto_reply_rules WHERE line_account_id = ? OR line_account_id IS NULL");
        $stmt->execute([$account['id']]);
        $ruleCount = $stmt->fetchColumn();
        echo "<p>มี {$ruleCount} กฎ Auto Reply</p>";
        
        // Recent Messages
        echo "<h4>💬 ข้อความล่าสุด (5 รายการ)</h4>";
        $stmt = $db->prepare("
            SELECT m.*, u.display_name, u.line_user_id 
            FROM messages m 
            LEFT JOIN users u ON m.user_id = u.id 
            WHERE m.line_account_id = ? 
            ORDER BY m.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$account['id']]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($messages)) {
            echo "<p>ไม่มีข้อความ</p>";
        } else {
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>เวลา</th><th>ผู้ใช้</th><th>ทิศทาง</th><th>ข้อความ</th></tr>";
            foreach ($messages as $msg) {
                $direction = $msg['direction'] === 'incoming' ? '📥 เข้า' : '📤 ออก';
                $content = mb_substr($msg['content'], 0, 50);
                echo "<tr>";
                echo "<td>{$msg['created_at']}</td>";
                echo "<td>{$msg['display_name']}</td>";
                echo "<td>{$direction}</td>";
                echo "<td>{$content}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
