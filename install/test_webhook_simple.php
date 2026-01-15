<?php
/**
 * Simple Webhook Test - ทดสอบว่า webhook โหลดได้หรือไม่
 */

echo "=== Webhook Simple Test ===\n\n";

// Test 1: Check if webhook file exists
echo "1. Checking webhook.php file...\n";
if (file_exists(__DIR__ . '/../webhook.php')) {
    echo "   ✅ webhook.php exists\n";
} else {
    echo "   ❌ webhook.php NOT FOUND\n";
    exit(1);
}

// Test 2: Check if webhook_functions.php exists
echo "\n2. Checking webhook_functions.php file...\n";
if (file_exists(__DIR__ . '/../includes/webhook_functions.php')) {
    echo "   ✅ webhook_functions.php exists\n";
} else {
    echo "   ❌ webhook_functions.php NOT FOUND\n";
    exit(1);
}

// Test 3: Try to include webhook_functions.php
echo "\n3. Testing webhook_functions.php include...\n";
try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/webhook_functions.php';
    echo "   ✅ webhook_functions.php loaded successfully\n";
} catch (Exception $e) {
    echo "   ❌ Error loading webhook_functions.php: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: Check if functions are defined
echo "\n4. Checking if functions are defined...\n";
$functions = [
    'devLog',
    'getOrCreateUser',
    'saveAccountFollower',
    'saveAccountEvent',
    'updateAccountDailyStats',
    'updateFollowerInteraction',
    'getAccountName',
    'checkUserConsent',
    'getUserState',
    'setUserState',
    'clearUserState'
];

$allDefined = true;
foreach ($functions as $func) {
    if (function_exists($func)) {
        echo "   ✅ {$func}() is defined\n";
    } else {
        echo "   ❌ {$func}() is NOT defined\n";
        $allDefined = false;
    }
}

if (!$allDefined) {
    echo "\n❌ Some functions are missing!\n";
    exit(1);
}

// Test 5: Test database connection
echo "\n5. Testing database connection...\n";
try {
    $db = Database::getInstance()->getConnection();
    echo "   ✅ Database connected\n";
    
    // Test devLog function
    devLog($db, 'info', 'test_webhook_simple', 'Testing devLog function', ['test' => true]);
    echo "   ✅ devLog() works\n";
    
} catch (Exception $e) {
    echo "   ❌ Database error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ All tests passed! Webhook should work now.\n";
echo "\nNext: Send a test message to your LINE bot:\n";
echo "- สวัสดี\n";
echo "- ติดต่อ\n";
echo "- เมนู\n";
