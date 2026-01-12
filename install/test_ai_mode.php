<?php
/**
 * Test AI Mode - ทดสอบว่า AI ใช้ mode ไหน
 */
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = 3; // CNY Pharmacy

echo "=== AI Mode Test ===\n\n";

// 1. Check ai_settings
echo "1. AI Settings:\n";
$stmt = $db->prepare("SELECT * FROM ai_settings WHERE line_account_id = ?");
$stmt->execute([$lineAccountId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);
if ($settings) {
    echo "   - ai_mode: " . ($settings['ai_mode'] ?? 'NULL') . "\n";
    echo "   - is_enabled: " . ($settings['is_enabled'] ?? 'NULL') . "\n";
    echo "   - gemini_api_key: " . (empty($settings['gemini_api_key']) ? 'EMPTY' : 'SET (' . strlen($settings['gemini_api_key']) . ' chars)') . "\n";
} else {
    echo "   - No settings found!\n";
}

// 2. Check ai_user_mode
echo "\n2. User AI Modes (last 5):\n";
try {
    $stmt = $db->query("SELECT * FROM ai_user_mode ORDER BY updated_at DESC LIMIT 5");
    $modes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($modes)) {
        echo "   - No user modes found\n";
    } else {
        foreach ($modes as $m) {
            echo "   - User {$m['user_id']}: {$m['ai_mode']} (updated: {$m['updated_at']})\n";
        }
    }
} catch (Exception $e) {
    echo "   - Table not exists or error: " . $e->getMessage() . "\n";
}

// 3. Test GeminiChat
echo "\n3. GeminiChat Test:\n";
require_once __DIR__ . '/../classes/GeminiChat.php';
$gemini = new GeminiChat($db, $lineAccountId);
echo "   - isEnabled: " . ($gemini->isEnabled() ? 'YES' : 'NO') . "\n";
echo "   - getMode: " . $gemini->getMode() . "\n";

// 4. Test PharmacyAIAdapter
echo "\n4. PharmacyAIAdapter Test:\n";
$adapterPath = __DIR__ . '/../modules/AIChat/Adapters/PharmacyAIAdapter.php';
if (file_exists($adapterPath)) {
    require_once $adapterPath;
    $adapter = new \Modules\AIChat\Adapters\PharmacyAIAdapter($db, $lineAccountId);
    echo "   - isEnabled: " . ($adapter->isEnabled() ? 'YES' : 'NO') . "\n";
} else {
    echo "   - File not found!\n";
}

// 5. Recent AI logs
echo "\n5. Recent AI Logs (last 10):\n";
$stmt = $db->query("SELECT * FROM dev_logs WHERE source LIKE 'AI_%' ORDER BY id DESC LIMIT 10");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($logs as $log) {
    echo "   [{$log['id']}] {$log['source']}: {$log['message']}\n";
    $data = json_decode($log['data'] ?? '{}', true);
    if (!empty($data['mode'])) echo "       mode: {$data['mode']}\n";
    if (!empty($data['command_mode'])) echo "       command_mode: {$data['command_mode']}\n";
}

echo "\n=== Done ===\n";
