<?php
/**
 * Create Test Reward - สร้างรางวัลทดสอบ
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

echo "=== สร้างรางวัลทดสอบ ===\n\n";

try {
    $loyalty = new LoyaltyPoints($db, 1);
    
    // Create test rewards
    $testRewards = [
        [
            'name' => 'ส่วนลด 50 บาท',
            'description' => 'รับส่วนลด 50 บาท สำหรับการซื้อครั้งถัดไป',
            'points_required' => 100,
            'reward_type' => 'discount',
            'reward_value' => '50',
            'stock' => 10,
            'max_per_user' => 3,
            'is_active' => 1,
            'image_url' => 'https://via.placeholder.com/300x200/4F46E5/FFFFFF?text=50+Baht+OFF'
        ],
        [
            'name' => 'ส่วนลด 100 บาท',
            'description' => 'รับส่วนลด 100 บาท สำหรับการซื้อครั้งถัดไป',
            'points_required' => 200,
            'reward_type' => 'discount',
            'reward_value' => '100',
            'stock' => 5,
            'max_per_user' => 2,
            'is_active' => 1,
            'image_url' => 'https://via.placeholder.com/300x200/7C3AED/FFFFFF?text=100+Baht+OFF'
        ],
        [
            'name' => 'ค่าจัดส่งฟรี',
            'description' => 'รับค่าจัดส่งฟรีสำหรับคำสั่งซื้อครั้งถัดไป',
            'points_required' => 150,
            'reward_type' => 'shipping',
            'reward_value' => 'free',
            'stock' => -1, // unlimited
            'max_per_user' => 0, // unlimited
            'is_active' => 1,
            'image_url' => 'https://via.placeholder.com/300x200/10B981/FFFFFF?text=Free+Shipping'
        ]
    ];
    
    foreach ($testRewards as $reward) {
        // Check if reward already exists
        $stmt = $db->prepare("SELECT id FROM rewards WHERE name = ? AND line_account_id = 1");
        $stmt->execute([$reward['name']]);
        if ($stmt->fetch()) {
            echo "⚠️  รางวัล '{$reward['name']}' มีอยู่แล้ว - ข้าม\n";
            continue;
        }
        
        $id = $loyalty->createReward($reward);
        echo "✓ สร้างรางวัล: {$reward['name']} (ID: {$id}, {$reward['points_required']} แต้ม)\n";
    }
    
    echo "\n✅ สร้างรางวัลทดสอบเสร็จสิ้น!\n";
    echo "\nดูรางวัลได้ที่: https://cny.re-ya.com/membership.php?tab=rewards\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
