<?php
/**
 * Check if webhook.php has the new AI mode code
 */
header('Content-Type: text/plain; charset=utf-8');

$webhookPath = __DIR__ . '/../webhook.php';
$webhookContent = file_get_contents($webhookPath);

echo "=== Webhook Code Check ===\n\n";

// Check for new debug logs
$checks = [
    'AI_entry' => strpos($webhookContent, 'AI_entry') !== false,
    'AI_flow' => strpos($webhookContent, 'AI_flow') !== false,
    'AI_mode_check' => strpos($webhookContent, 'AI_mode_check') !== false,
    'AI_sales' => strpos($webhookContent, 'AI_sales') !== false,
    'AI_before_pharmacy' => strpos($webhookContent, 'AI_before_pharmacy') !== false,
    'currentAIMode === sales' => strpos($webhookContent, "currentAIMode === 'sales'") !== false,
    'GeminiChat for sales' => strpos($webhookContent, 'พนักงานขาย AI') !== false,
];

echo "Debug Logs Present:\n";
foreach ($checks as $name => $found) {
    echo "  - {$name}: " . ($found ? "✅ YES" : "❌ NO") . "\n";
}

// Check checkAIChatbot function
echo "\n=== checkAIChatbot Function ===\n";
if (preg_match('/function checkAIChatbot\([^)]*\)\s*\{/', $webhookContent, $match, PREG_OFFSET_CAPTURE)) {
    $startPos = $match[0][1];
    $excerpt = substr($webhookContent, $startPos, 3000);
    
    // Check for sales mode logic
    $hasSalesCheck = strpos($excerpt, "currentAIMode === 'sales'") !== false;
    $hasGeminiChat = strpos($excerpt, 'GeminiChat') !== false;
    
    echo "  - Has sales mode check: " . ($hasSalesCheck ? "✅ YES" : "❌ NO") . "\n";
    echo "  - Has GeminiChat: " . ($hasGeminiChat ? "✅ YES" : "❌ NO") . "\n";
    
    // Show first 500 chars of function
    echo "\nFirst 500 chars of checkAIChatbot:\n";
    echo "---\n";
    echo substr($excerpt, 0, 500);
    echo "\n---\n";
}

// Check ai_settings query
echo "\n=== AI Settings Query ===\n";
if (preg_match('/SELECT ai_mode FROM ai_settings/', $webhookContent)) {
    echo "✅ Found ai_settings query\n";
} else {
    echo "❌ NOT found ai_settings query\n";
}

// Check file modification time
echo "\n=== File Info ===\n";
echo "File: {$webhookPath}\n";
echo "Size: " . filesize($webhookPath) . " bytes\n";
echo "Modified: " . date('Y-m-d H:i:s', filemtime($webhookPath)) . "\n";

// Show git status
echo "\n=== Git Status ===\n";
$gitStatus = shell_exec('cd ' . dirname($webhookPath) . ' && git status webhook.php 2>&1');
echo $gitStatus;

echo "\n=== Git Log (last 3 commits) ===\n";
$gitLog = shell_exec('cd ' . dirname($webhookPath) . ' && git log --oneline -3 webhook.php 2>&1');
echo $gitLog;
