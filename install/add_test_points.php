<?php
/**
 * Add Test Points - เพิ่มแต้มให้ User ทดสอบ
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

$lineUserId = 'U1cffe699e4ebedcefafe47073a933ea0';
$pointsToAdd = 1000;

echo "=== เพิ่มแต้มทดสอบ ===\n\n";

try {
    // Get user
    $stmt = $db->prepare("SELECT id, line_account_id, display_name, available_points FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "✗ ไม่พบ User\n";
        exit(1);
    }
    
    echo "User: {$user['display_name']} (ID: {$user['id']})\n";
    echo "แต้มปัจจุบัน: {$user['available_points']}\n\n";
    
    // Add points using LoyaltyPoints class
    $loyalty = new LoyaltyPoints($db, $user['line_account_id']);
    $result = $loyalty->addPoints(
        $user['id'],
        $pointsToAdd,
        'manual',
        null,
        'เพิ่มแต้มทดสอบระบบแลกรางวัล'
    );
    
    if ($result) {
        echo "✓ เพิ่มแต้มสำเร็จ: +{$pointsToAdd} แต้ม\n\n";
        
        // Check new balance
        $stmt = $db->prepare("SELECT available_points, total_points FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "แต้มใหม่: {$updated['available_points']} แต้ม\n";
        echo "แต้มสะสมทั้งหมด: {$updated['total_points']} แต้ม\n\n";
        
        echo "✅ พร้อมทดสอบแลกรางวัลแล้ว!\n";
    } else {
        echo "✗ เพิ่มแต้มไม่สำเร็จ\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
