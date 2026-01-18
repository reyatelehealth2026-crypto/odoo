<?php
/**
 * View Full Account 3 Debug Logs with Complete Details
 */

echo "=== Full Account 3 Debug Logs ===\n\n";

$errorLogPath = '/home/zrismpsz/public_html/emp.re-ya.net/error_log';

if (!file_exists($errorLogPath)) {
    echo "Error log not found at: $errorLogPath\n";
    exit;
}

// Read last 500 lines
$lines = file($errorLogPath);
$recentLines = array_slice($lines, -500);

echo "Showing last 500 lines filtered for Account 3:\n";
echo str_repeat("=", 80) . "\n\n";

$inDebugBlock = false;
$blockLines = [];

foreach ($recentLines as $line) {
    // Check if this line contains Account 3 related content
    if (stripos($line, 'ACCOUNT 3') !== false) {
        echo $line;
        $inDebugBlock = true;
        $blockLines = [$line];
    } elseif ($inDebugBlock) {
        // Continue showing lines after ACCOUNT 3 marker
        echo $line;
        $blockLines[] = $line;
        
        // Stop after showing a few more lines or hitting another marker
        if (count($blockLines) > 15 || stripos($line, '===') !== false) {
            $inDebugBlock = false;
            echo "\n";
        }
    }
}

echo "\n" . str_repeat("=", 80) . "\n";
echo "If you see only '=== ACCOUNT 3 DEBUG ===' without details,\n";
echo "the error_log() calls might not be writing all data.\n";
echo "\nTry sending another test message to Account 3 now.\n";
