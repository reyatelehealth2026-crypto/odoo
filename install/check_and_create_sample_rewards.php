<?php
/**
 * Check rewards and create samples if needed
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

echo "=== Checking Rewards System ===\n\n";

// Check if rewards table exists
try {
    $stmt = $db->query("SHOW TABLES LIKE 'rewards'");
    $tableExists = $stmt->fetch() !== false;
    
    if (!$tableExists) {
        echo "❌ Rewards table does not exist!\n";
        echo "Run: php install/run_loyalty_points_migration.php\n";
        exit(1);
    }
    
    echo "✅ Rewards table exists\n\n";
} catch (Exception $e) {
    echo "❌ Error checking table: " . $e->getMessage() . "\n";
    exit(1);
}

// Check for line_account_id = 3
$lineAccountId = 3;

echo "Checking rewards for line_account_id = $lineAccountId...\n";

$loyalty = new LoyaltyPoints($db, $lineAccountId);
$rewards = $loyalty->getActiveRewards();

echo "Found " . count($rewards) . " active rewards\n\n";

if (count($rewards) > 0) {
    echo "Existing rewards:\n";
    foreach ($rewards as $reward) {
        echo "  - ID: {$reward['id']}, Name: {$reward['name']}, Points: {$reward['points_required']}, Stock: {$reward['stock']}\n";
    }
    echo "\n";
} else {
    echo "⚠️ No rewards found. Creating sample rewards...\n\n";
    
    // Create sample rewards
    $sampleRewards = [
        [
            'name' => 'ส่วนลด 50 บาท',
            'description' => 'รับส่วนลด 50 บาท สำหรับการซื้อครั้งถัดไป',
            'points_required' => 500,
            'reward_type' => 'discount',
            'reward_value' => 50,
            'stock' => -1, // Unlimited
            'is_active' => 1
        ],
        [
            'name' => 'ส่วนลด 100 บาท',
            'description' => 'รับส่วนลด 100 บาท สำหรับการซื้อครั้งถัดไป',
            'points_required' => 1000,
            'reward_type' => 'discount',
            'reward_value' => 100,
            'stock' => -1,
            'is_active' => 1
        ],
        [
            'name' => 'จัดส่งฟรี',
            'description' => 'รับบริการจัดส่งฟรีสำหรับคำสั่งซื้อครั้งถัดไป',
            'points_required' => 300,
            'reward_type' => 'free_shipping',
            'reward_value' => 0,
            'stock' => -1,
            'is_active' => 1
        ],
        [
            'name' => 'ของขวัญพิเศษ',
            'description' => 'รับของขวัญพิเศษจากร้าน (จำนวนจำกัด)',
            'points_required' => 2000,
            'reward_type' => 'gift',
            'reward_value' => 0,
            'stock' => 10,
            'is_active' => 1
        ]
    ];
    
    foreach ($sampleRewards as $reward) {
        try {
            $rewardId = $loyalty->createReward($reward);
            echo "✅ Created: {$reward['name']} (ID: $rewardId)\n";
        } catch (Exception $e) {
            echo "❌ Failed to create {$reward['name']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n";
    
    // Reload rewards
    $rewards = $loyalty->getActiveRewards();
    echo "Now have " . count($rewards) . " active rewards\n";
}

// Check users table for test user
echo "\nChecking test user...\n";
$testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);

$stmt = $db->prepare("SELECT id, display_name, total_points, available_points FROM users WHERE line_user_id = ? AND line_account_id = ?");
$stmt->execute([$testUserId, $lineAccountId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "✅ Test user exists:\n";
    echo "   ID: {$user['id']}\n";
    echo "   Name: {$user['display_name']}\n";
    echo "   Total Points: {$user['total_points']}\n";
    echo "   Available Points: {$user['available_points']}\n";
} else {
    echo "⚠️ Test user does not exist. Will be created on first API call.\n";
}

echo "\n=== Check Complete ===\n";
echo "\nYou can now test the debug page at:\n";
echo "https://cny.re-ya.com/liff/debug-rewards.html\n";
