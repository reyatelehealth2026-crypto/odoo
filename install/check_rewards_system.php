<?php
/**
 * Check Rewards System - ตรวจสอบระบบรางวัลทั้งหมด
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== ตรวจสอบระบบรางวัล ===\n\n";

// 1. Check rewards table
echo "1. ตรวจสอบตารางรางวัล:\n";
try {
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(is_active=1) as active FROM rewards");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "   ✓ มีรางวัลทั้งหมด: {$result['total']} รายการ\n";
    echo "   ✓ รางวัลที่เปิดใช้งาน: {$result['active']} รายการ\n\n";
    
    // Show active rewards
    $stmt = $db->query("SELECT id, name, points_required, stock, is_active FROM rewards WHERE is_active = 1 LIMIT 5");
    $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rewards)) {
        echo "   รางวัลที่เปิดใช้งาน:\n";
        foreach ($rewards as $r) {
            $stock = $r['stock'] < 0 ? 'ไม่จำกัด' : $r['stock'];
            echo "   - [{$r['id']}] {$r['name']} ({$r['points_required']} แต้ม, คงเหลือ: {$stock})\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// 2. Check test user
echo "2. ตรวจสอบ User ทดสอบ (LINE ID: U1cffe699e4ebedcefafe47073a933ea0):\n";
try {
    $stmt = $db->prepare("SELECT id, display_name, points, available_points, total_points, used_points FROM users WHERE line_user_id = ?");
    $stmt->execute(['U1cffe699e4ebedcefafe47073a933ea0']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "   ✓ พบ User: {$user['display_name']} (ID: {$user['id']})\n";
        echo "   - points: {$user['points']}\n";
        echo "   - available_points: {$user['available_points']}\n";
        echo "   - total_points: {$user['total_points']}\n";
        echo "   - used_points: {$user['used_points']}\n\n";
    } else {
        echo "   ✗ ไม่พบ User\n\n";
    }
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n\n";
}

// 3. Check API endpoint
echo "3. ทดสอบ API Endpoint:\n";
$testUrl = "https://cny.re-ya.com/api/points-history.php?action=rewards&line_user_id=U1cffe699e4ebedcefafe47073a933ea0";
echo "   URL: {$testUrl}\n";

$ch = curl_init($testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Code: {$httpCode}\n";
if ($httpCode == 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['success'])) {
        echo "   ✓ API ทำงานได้\n";
        echo "   - success: " . ($data['success'] ? 'true' : 'false') . "\n";
        if (isset($data['rewards'])) {
            echo "   - จำนวนรางวัล: " . count($data['rewards']) . "\n";
        }
        if (isset($data['error'])) {
            echo "   - error: {$data['error']}\n";
        }
    } else {
        echo "   ✗ Response ไม่ใช่ JSON:\n";
        echo "   " . substr($response, 0, 200) . "...\n";
    }
} else {
    echo "   ✗ API Error\n";
    echo "   Response: " . substr($response, 0, 200) . "\n";
}
echo "\n";

// 4. Check LoyaltyPoints class
echo "4. ตรวจสอบ LoyaltyPoints Class:\n";
try {
    require_once __DIR__ . '/../classes/LoyaltyPoints.php';
    $loyalty = new LoyaltyPoints($db, 1);
    echo "   ✓ LoyaltyPoints class โหลดได้\n";
    
    // Test getRewards
    $rewards = $loyalty->getRewards(true);
    echo "   ✓ getRewards() ทำงานได้ - พบ " . count($rewards) . " รางวัล\n";
} catch (Exception $e) {
    echo "   ✗ Error: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Recommendations
echo "=== คำแนะนำ ===\n";
if (empty($rewards)) {
    echo "⚠️  ไม่มีรางวัลในระบบ - ต้องสร้างรางวัลก่อน\n";
    echo "   ไปที่: https://cny.re-ya.com/membership.php?tab=rewards\n\n";
}

if ($user && $user['available_points'] == 0) {
    echo "⚠️  User ไม่มีแต้ม - ต้องเพิ่มแต้มให้ User ทดสอบ\n";
    echo "   รันคำสั่ง: php install/add_test_points.php\n\n";
}

echo "✅ ขั้นตอนต่อไป:\n";
echo "1. สร้างรางวัลในระบบ (ถ้ายังไม่มี)\n";
echo "2. เพิ่มแต้มให้ User ทดสอบ\n";
echo "3. ทดสอบแลกรางวัลใน LIFF app\n";
