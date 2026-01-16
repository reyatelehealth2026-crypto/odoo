<?php
/**
 * Create Sample Rewards
 * สร้างรางวัลตัวอย่างสำหรับทดสอบระบบ
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>สร้างรางวัลตัวอย่าง</title>";
echo "<style>body{font-family:'Sarabun',sans-serif;padding:20px;max-width:800px;margin:0 auto;} .success{color:green;padding:10px;background:#d4edda;border-radius:5px;margin:10px 0;} .error{color:red;padding:10px;background:#f8d7da;border-radius:5px;margin:10px 0;} .info{color:#004085;padding:10px;background:#d1ecf1;border-radius:5px;margin:10px 0;} table{width:100%;border-collapse:collapse;margin:20px 0;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background:#f8f9fa;} .btn{display:inline-block;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:5px;margin:5px;border:none;cursor:pointer;} .btn:hover{background:#0056b3;} .btn-danger{background:#dc3545;} .btn-danger:hover{background:#c82333;}</style>";
echo "</head><body>";

echo "<h1>🎁 สร้างรางวัลตัวอย่าง</h1>";

// Check if rewards table exists
try {
    $stmt = $db->query("SELECT COUNT(*) FROM rewards");
    $existingCount = $stmt->fetchColumn();
    
    if ($existingCount > 0) {
        echo "<div class='info'>ℹ️ มีรางวัลอยู่แล้ว {$existingCount} รายการ</div>";
        echo "<p><a href='../admin-rewards.php' class='btn'>ดูรางวัลทั้งหมด</a></p>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ ตาราง rewards ยังไม่มี: " . $e->getMessage() . "</div>";
    echo "<p>กรุณารัน migration ก่อน: <a href='run_loyalty_migration.php'>Run Loyalty Migration</a></p>";
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $lineAccountId = (int)($_POST['line_account_id'] ?? 1);
    
    $sampleRewards = [
        [
            'name' => '🎫 ส่วนลด 50 บาท',
            'description' => 'รับส่วนลด 50 บาท สำหรับการซื้อครั้งถัดไป (ขั้นต่ำ 200 บาท)',
            'points_required' => 500,
            'reward_type' => 'discount',
            'reward_value' => 50,
            'stock' => -1,
            'max_per_user' => 0
        ],
        [
            'name' => '🎫 ส่วนลด 100 บาท',
            'description' => 'รับส่วนลด 100 บาท สำหรับการซื้อครั้งถัดไป (ขั้นต่ำ 500 บาท)',
            'points_required' => 1000,
            'reward_type' => 'discount',
            'reward_value' => 100,
            'stock' => -1,
            'max_per_user' => 0
        ],
        [
            'name' => '🚚 จัดส่งฟรี',
            'description' => 'รับบริการจัดส่งฟรี 1 ครั้ง (ไม่จำกัดมูลค่า)',
            'points_required' => 300,
            'reward_type' => 'shipping',
            'reward_value' => 0,
            'stock' => -1,
            'max_per_user' => 0
        ],
        [
            'name' => '🎁 ของขวัญพิเศษ',
            'description' => 'รับของขวัญพิเศษจากร้าน (จำนวนจำกัด)',
            'points_required' => 2000,
            'reward_type' => 'gift',
            'reward_value' => 0,
            'stock' => 10,
            'max_per_user' => 1
        ],
        [
            'name' => '💊 ส่วนลด 20%',
            'description' => 'รับส่วนลด 20% สูงสุด 200 บาท (ขั้นต่ำ 1000 บาท)',
            'points_required' => 1500,
            'reward_type' => 'discount',
            'reward_value' => 200,
            'stock' => -1,
            'max_per_user' => 0
        ]
    ];
    
    $created = 0;
    $errors = [];
    
    foreach ($sampleRewards as $reward) {
        try {
            $stmt = $db->prepare("
                INSERT INTO rewards 
                (line_account_id, name, description, points_required, reward_type, reward_value, stock, max_per_user, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $lineAccountId,
                $reward['name'],
                $reward['description'],
                $reward['points_required'],
                $reward['reward_type'],
                $reward['reward_value'],
                $reward['stock'],
                $reward['max_per_user']
            ]);
            $created++;
        } catch (Exception $e) {
            $errors[] = "Error creating '{$reward['name']}': " . $e->getMessage();
        }
    }
    
    if ($created > 0) {
        echo "<div class='success'>✅ สร้างรางวัลสำเร็จ {$created} รายการ!</div>";
    }
    
    if (!empty($errors)) {
        foreach ($errors as $error) {
            echo "<div class='error'>❌ {$error}</div>";
        }
    }
    
    echo "<p><a href='../admin-rewards.php' class='btn'>ดูรางวัลทั้งหมด</a> ";
    echo "<a href='../liff-redeem-points.php?account={$lineAccountId}' class='btn'>ทดสอบหน้าแลกรางวัล</a></p>";
}

// Show form
echo "<h2>รางวัลที่จะสร้าง</h2>";
echo "<table>";
echo "<tr><th>ชื่อรางวัล</th><th>รายละเอียด</th><th>แต้มที่ใช้</th><th>ประเภท</th><th>สต็อก</th></tr>";

$previewRewards = [
    ['name' => '🎫 ส่วนลด 50 บาท', 'desc' => 'ส่วนลด 50 บาท (ขั้นต่ำ 200 บาท)', 'points' => 500, 'type' => 'discount', 'stock' => 'ไม่จำกัด'],
    ['name' => '🎫 ส่วนลด 100 บาท', 'desc' => 'ส่วนลด 100 บาท (ขั้นต่ำ 500 บาท)', 'points' => 1000, 'type' => 'discount', 'stock' => 'ไม่จำกัด'],
    ['name' => '🚚 จัดส่งฟรี', 'desc' => 'จัดส่งฟรี 1 ครั้ง', 'points' => 300, 'type' => 'shipping', 'stock' => 'ไม่จำกัด'],
    ['name' => '🎁 ของขวัญพิเศษ', 'desc' => 'ของขวัญพิเศษจากร้าน', 'points' => 2000, 'type' => 'gift', 'stock' => '10 ชิ้น'],
    ['name' => '💊 ส่วนลด 20%', 'desc' => 'ส่วนลด 20% สูงสุด 200 บาท', 'points' => 1500, 'type' => 'discount', 'stock' => 'ไม่จำกัด']
];

foreach ($previewRewards as $r) {
    echo "<tr>";
    echo "<td>{$r['name']}</td>";
    echo "<td>{$r['desc']}</td>";
    echo "<td>{$r['points']} แต้ม</td>";
    echo "<td>{$r['type']}</td>";
    echo "<td>{$r['stock']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<form method='post'>";
echo "<p><label>LINE Account ID: <input type='number' name='line_account_id' value='1' required></label></p>";
echo "<p><button type='submit' name='create' value='1' class='btn'>สร้างรางวัลทั้งหมด</button></p>";
echo "</form>";

echo "<hr>";
echo "<p><a href='test_rewards_api.php'>ทดสอบ API</a> | <a href='../admin-rewards.php'>จัดการรางวัล</a> | <a href='../admin-points-settings.php'>ตั้งค่าแต้ม</a></p>";

echo "</body></html>";
