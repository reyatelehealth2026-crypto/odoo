<?php
/**
 * Debug AI Settings - ตรวจสอบการตั้งค่า AI
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔍 Debug AI Settings</h1>";
echo "<style>body{font-family:sans-serif;padding:20px;} table{border-collapse:collapse;margin:10px 0;} th,td{border:1px solid #ccc;padding:8px;text-align:left;} .ok{color:green;} .error{color:red;}</style>";

// 1. ตรวจสอบ ai_settings table
echo "<h2>1. ai_settings Table</h2>";
try {
    $stmt = $db->query("SELECT id, line_account_id, is_enabled, ai_mode, model, 
                        CASE WHEN gemini_api_key IS NOT NULL AND gemini_api_key != '' THEN 'SET' ELSE 'EMPTY' END as api_key_status
                        FROM ai_settings ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "<p class='error'>❌ ไม่พบข้อมูลใน ai_settings</p>";
    } else {
        echo "<table><tr><th>ID</th><th>Line Account ID</th><th>Enabled</th><th>AI Mode</th><th>Model</th><th>API Key</th></tr>";
        foreach ($rows as $row) {
            $enabled = $row['is_enabled'] ? '✅ Yes' : '❌ No';
            echo "<tr><td>{$row['id']}</td><td>{$row['line_account_id']}</td><td>{$enabled}</td><td><b>{$row['ai_mode']}</b></td><td>{$row['model']}</td><td>{$row['api_key_status']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}

// 2. ตรวจสอบ ai_chat_settings table
echo "<h2>2. ai_chat_settings Table</h2>";
try {
    $stmt = $db->query("SELECT id, line_account_id, is_enabled, 
                        CASE WHEN gemini_api_key IS NOT NULL AND gemini_api_key != '' THEN 'SET' ELSE 'EMPTY' END as api_key_status
                        FROM ai_chat_settings ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "<p class='error'>❌ ไม่พบข้อมูลใน ai_chat_settings</p>";
    } else {
        echo "<table><tr><th>ID</th><th>Line Account ID</th><th>Enabled</th><th>API Key</th></tr>";
        foreach ($rows as $row) {
            $enabled = $row['is_enabled'] ? '✅ Yes' : '❌ No';
            echo "<tr><td>{$row['id']}</td><td>{$row['line_account_id']}</td><td>{$enabled}</td><td>{$row['api_key_status']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}

// 3. ตรวจสอบ line_accounts
echo "<h2>3. line_accounts Table</h2>";
try {
    $stmt = $db->query("SELECT id, name, bot_mode FROM line_accounts ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        echo "<p class='error'>❌ ไม่พบข้อมูลใน line_accounts</p>";
    } else {
        echo "<table><tr><th>ID</th><th>Name</th><th>Bot Mode</th></tr>";
        foreach ($rows as $row) {
            echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['bot_mode']}</td></tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}

// 4. ทดสอบ GeminiChat
echo "<h2>4. Test GeminiChat Class</h2>";
try {
    require_once __DIR__ . '/../classes/GeminiChat.php';
    
    // ดึง line_account_id แรก
    $stmt = $db->query("SELECT id FROM line_accounts LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    $lineAccountId = $account ? $account['id'] : null;
    
    echo "<p>Testing with line_account_id: <b>" . ($lineAccountId ?? 'NULL') . "</b></p>";
    
    $gemini = new GeminiChat($db, $lineAccountId);
    
    echo "<table>";
    echo "<tr><td>isEnabled()</td><td>" . ($gemini->isEnabled() ? '<span class="ok">✅ Yes</span>' : '<span class="error">❌ No</span>') . "</td></tr>";
    echo "<tr><td>getMode()</td><td><b>" . $gemini->getMode() . "</b></td></tr>";
    echo "</table>";
    
    if (!$gemini->isEnabled()) {
        echo "<p class='error'>⚠️ GeminiChat ไม่ enabled - ตรวจสอบ:</p>";
        echo "<ul>";
        echo "<li>ai_settings.is_enabled = 1 ?</li>";
        echo "<li>ai_settings.gemini_api_key มีค่า ?</li>";
        echo "<li>หรือ ai_chat_settings.gemini_api_key มีค่า ?</li>";
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}

// 5. ทดสอบ PharmacyAIAdapter
echo "<h2>5. Test PharmacyAIAdapter Class</h2>";
try {
    require_once __DIR__ . '/../modules/AIChat/Adapters/PharmacyAIAdapter.php';
    
    $stmt = $db->query("SELECT id FROM line_accounts LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    $lineAccountId = $account ? $account['id'] : null;
    
    $adapter = new \Modules\AIChat\Adapters\PharmacyAIAdapter($db, $lineAccountId);
    
    echo "<table>";
    echo "<tr><td>isEnabled()</td><td>" . ($adapter->isEnabled() ? '<span class="ok">✅ Yes</span>' : '<span class="error">❌ No</span>') . "</td></tr>";
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
}

// 6. สรุปปัญหา
echo "<h2>6. 📋 สรุป</h2>";
echo "<div style='background:#f5f5f5;padding:15px;border-radius:8px;'>";

try {
    $stmt = $db->query("SELECT id FROM line_accounts LIMIT 1");
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    $lineAccountId = $account ? $account['id'] : null;
    
    // Check ai_settings
    $stmt = $db->prepare("SELECT ai_mode, is_enabled, CASE WHEN gemini_api_key != '' THEN 1 ELSE 0 END as has_key FROM ai_settings WHERE line_account_id = ?");
    $stmt->execute([$lineAccountId]);
    $aiSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check ai_chat_settings
    $stmt = $db->prepare("SELECT is_enabled, CASE WHEN gemini_api_key != '' THEN 1 ELSE 0 END as has_key FROM ai_chat_settings WHERE line_account_id = ?");
    $stmt->execute([$lineAccountId]);
    $chatSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($aiSettings) {
        echo "<p><b>ai_settings.ai_mode:</b> " . $aiSettings['ai_mode'] . "</p>";
        echo "<p><b>ai_settings.is_enabled:</b> " . ($aiSettings['is_enabled'] ? 'Yes' : 'No') . "</p>";
        echo "<p><b>ai_settings.has_api_key:</b> " . ($aiSettings['has_key'] ? 'Yes' : 'No') . "</p>";
    }
    
    if ($chatSettings) {
        echo "<p><b>ai_chat_settings.is_enabled:</b> " . ($chatSettings['is_enabled'] ? 'Yes' : 'No') . "</p>";
        echo "<p><b>ai_chat_settings.has_api_key:</b> " . ($chatSettings['has_key'] ? 'Yes' : 'No') . "</p>";
    }
    
    // แนะนำการแก้ไข
    echo "<hr>";
    echo "<h3>🔧 แนะนำการแก้ไข:</h3>";
    
    if (!$aiSettings || !$aiSettings['is_enabled'] || !$aiSettings['has_key']) {
        echo "<p class='error'>❌ ต้องตั้งค่า ai_settings:</p>";
        echo "<pre>UPDATE ai_settings SET is_enabled = 1, ai_mode = 'sales', gemini_api_key = 'YOUR_API_KEY' WHERE line_account_id = {$lineAccountId};</pre>";
    }
    
    if ($aiSettings && $aiSettings['ai_mode'] !== 'sales') {
        echo "<p class='error'>❌ ai_mode ไม่ใช่ 'sales' - ถ้าต้องการใช้ Sales mode:</p>";
        echo "<pre>UPDATE ai_settings SET ai_mode = 'sales' WHERE line_account_id = {$lineAccountId};</pre>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
}

echo "</div>";

// 7. Quick Fix Button
echo "<h2>7. 🚀 Quick Fix</h2>";
if (isset($_POST['fix_ai_mode'])) {
    try {
        $stmt = $db->query("SELECT id FROM line_accounts LIMIT 1");
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        $lineAccountId = $account ? $account['id'] : null;
        
        // Copy API key from ai_chat_settings to ai_settings if needed
        $stmt = $db->prepare("SELECT gemini_api_key FROM ai_chat_settings WHERE line_account_id = ? AND gemini_api_key IS NOT NULL LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $chatKey = $stmt->fetchColumn();
        
        if ($chatKey) {
            $stmt = $db->prepare("UPDATE ai_settings SET is_enabled = 1, ai_mode = 'sales', gemini_api_key = ? WHERE line_account_id = ?");
            $stmt->execute([$chatKey, $lineAccountId]);
            echo "<p class='ok'>✅ อัพเดท ai_settings สำเร็จ! (ai_mode = sales, copied API key)</p>";
        } else {
            $stmt = $db->prepare("UPDATE ai_settings SET is_enabled = 1, ai_mode = 'sales' WHERE line_account_id = ?");
            $stmt->execute([$lineAccountId]);
            echo "<p class='ok'>✅ อัพเดท ai_settings สำเร็จ! (ai_mode = sales)</p>";
        }
        
        echo "<p><a href=''>🔄 Refresh หน้านี้</a></p>";
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
    }
}
?>
<form method="POST">
    <button type="submit" name="fix_ai_mode" style="padding:10px 20px;background:#10b981;color:white;border:none;border-radius:5px;cursor:pointer;font-size:16px;">
        🔧 Fix: Set ai_mode = 'sales' และ copy API key
    </button>
</form>
