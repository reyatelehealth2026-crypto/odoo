<?php
/**
 * Seed Test Data for Executive Dashboard
 * สร้างข้อมูลทดสอบสำหรับ Executive Dashboard
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>🌱 Seeding Executive Dashboard Test Data</h2>";
echo "<pre>";

$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// ==================== 1. Create Test Users ====================
echo "\n📌 Creating test users...\n";

$testUsers = [
    ['line_user_id' => 'U_test_customer_001', 'display_name' => 'คุณสมชาย ใจดี', 'picture_url' => 'https://via.placeholder.com/100'],
    ['line_user_id' => 'U_test_customer_002', 'display_name' => 'คุณสมหญิง รักสุขภาพ', 'picture_url' => 'https://via.placeholder.com/100'],
    ['line_user_id' => 'U_test_customer_003', 'display_name' => 'คุณวิชัย ปวดหัว', 'picture_url' => 'https://via.placeholder.com/100'],
    ['line_user_id' => 'U_test_customer_004', 'display_name' => 'คุณมาลี ไม่สบาย', 'picture_url' => 'https://via.placeholder.com/100'],
    ['line_user_id' => 'U_test_customer_005', 'display_name' => 'คุณประยุทธ์ สอบถาม', 'picture_url' => 'https://via.placeholder.com/100'],
];

$userIds = [];
foreach ($testUsers as $user) {
    try {
        // Check if exists
        $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ?");
        $stmt->execute([$user['line_user_id']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $userIds[] = $existing['id'];
            echo "  ✓ User exists: {$user['display_name']} (ID: {$existing['id']})\n";
        } else {
            $stmt = $db->prepare("INSERT INTO users (line_user_id, display_name, picture_url, created_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user['line_user_id'], $user['display_name'], $user['picture_url'], $now]);
            $userIds[] = $db->lastInsertId();
            echo "  ✓ Created: {$user['display_name']} (ID: {$db->lastInsertId()})\n";
        }
    } catch (Exception $e) {
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

// ==================== 2. Create Test Messages ====================
echo "\n📌 Creating test messages...\n";

// Check if sent_by column exists
$hasSentBy = false;
try {
    $db->query("SELECT sent_by FROM messages LIMIT 1");
    $hasSentBy = true;
} catch (Exception $e) {
    // Add sent_by column
    try {
        $db->exec("ALTER TABLE messages ADD COLUMN sent_by VARCHAR(100) NULL");
        $hasSentBy = true;
        echo "  ✓ Added sent_by column to messages table\n";
    } catch (Exception $e2) {}
}

$adminNames = ['Admin สมศักดิ์', 'Admin มานี', 'Admin วิภา', 'Bot'];
$problemKeywords = ['ปัญหา', 'ไม่พอใจ', 'ช้า', 'รอนาน', 'ไม่ตอบ'];
$normalKeywords = ['สอบถาม', 'สินค้า', 'ราคา', 'จัดส่ง', 'แนะนำ', 'ขอบคุณ', 'สั่งซื้อ'];

$messagesCreated = 0;

// Generate messages for different hours today
for ($hour = 8; $hour <= date('H'); $hour++) {
    $msgCount = rand(5, 15); // 5-15 messages per hour
    
    for ($i = 0; $i < $msgCount; $i++) {
        $userId = $userIds[array_rand($userIds)];
        $minute = rand(0, 59);
        $second = rand(0, 59);
        $msgTime = sprintf('%s %02d:%02d:%02d', $today, $hour, $minute, $second);
        
        // 20% chance of problem message
        $isProblem = rand(1, 100) <= 20;
        
        if ($isProblem) {
            $keyword = $problemKeywords[array_rand($problemKeywords)];
            $messages = [
                "มี{$keyword}เรื่องสินค้าครับ",
                "{$keyword}มากเลย รอนานมาก",
                "ทำไม{$keyword}แบบนี้",
                "สินค้า{$keyword} ช่วยดูให้หน่อย",
                "{$keyword}ครับ ติดต่อไม่ได้เลย"
            ];
        } else {
            $keyword = $normalKeywords[array_rand($normalKeywords)];
            $messages = [
                "{$keyword}หน่อยครับ",
                "อยาก{$keyword}สินค้า",
                "มี{$keyword}อะไรบ้างคะ",
                "{$keyword}ได้ไหมครับ",
                "ขอ{$keyword}ด้วยค่ะ"
            ];
        }
        
        $message = $messages[array_rand($messages)];
        
        try {
            // Incoming message
            $stmt = $db->prepare("INSERT INTO messages (user_id, message, direction, is_read, created_at) VALUES (?, ?, 'incoming', ?, ?)");
            $isRead = rand(0, 1);
            $stmt->execute([$userId, $message, $isRead, $msgTime]);
            $messagesCreated++;
            
            // 70% chance of reply
            if (rand(1, 100) <= 70) {
                $replyDelay = rand(1, 30); // 1-30 minutes delay
                $replyTime = date('Y-m-d H:i:s', strtotime($msgTime) + ($replyDelay * 60));
                
                $replies = [
                    "สวัสดีค่ะ ยินดีให้บริการค่ะ",
                    "รับทราบค่ะ รอสักครู่นะคะ",
                    "ขอบคุณที่ติดต่อมาค่ะ",
                    "สินค้าพร้อมส่งค่ะ",
                    "ราคา xxx บาทค่ะ"
                ];
                $reply = $replies[array_rand($replies)];
                $admin = $adminNames[array_rand($adminNames)];
                
                if ($hasSentBy) {
                    $stmt = $db->prepare("INSERT INTO messages (user_id, message, direction, is_read, sent_by, created_at) VALUES (?, ?, 'outgoing', 1, ?, ?)");
                    $stmt->execute([$userId, $reply, $admin, $replyTime]);
                } else {
                    $stmt = $db->prepare("INSERT INTO messages (user_id, message, direction, is_read, created_at) VALUES (?, ?, 'outgoing', 1, ?)");
                    $stmt->execute([$userId, $reply, $replyTime]);
                }
                $messagesCreated++;
            }
        } catch (Exception $e) {
            // Skip duplicates
        }
    }
}

echo "  ✓ Created {$messagesCreated} messages\n";

// ==================== 3. Create Test Orders ====================
echo "\n📌 Creating test orders...\n";

// Check which table exists
$ordersTable = 'transactions';
try {
    $db->query("SELECT 1 FROM transactions LIMIT 1");
} catch (Exception $e) {
    $ordersTable = 'orders';
}

$ordersCreated = 0;
$statuses = ['pending', 'confirmed', 'paid', 'completed', 'delivered'];

for ($i = 0; $i < 10; $i++) {
    $userId = $userIds[array_rand($userIds)];
    $status = $statuses[array_rand($statuses)];
    $total = rand(100, 5000);
    $hour = rand(8, date('H'));
    $orderTime = sprintf('%s %02d:%02d:%02d', $today, $hour, rand(0, 59), rand(0, 59));
    
    try {
        if ($ordersTable === 'transactions') {
            $stmt = $db->prepare("INSERT INTO transactions (user_id, status, grand_total, created_at) VALUES (?, ?, ?, ?)");
        } else {
            $stmt = $db->prepare("INSERT INTO orders (user_id, status, grand_total, created_at) VALUES (?, ?, ?, ?)");
        }
        $stmt->execute([$userId, $status, $total, $orderTime]);
        $ordersCreated++;
    } catch (Exception $e) {
        // Skip errors
    }
}

echo "  ✓ Created {$ordersCreated} orders in {$ordersTable} table\n";

// ==================== Summary ====================
echo "\n" . str_repeat("=", 50) . "\n";
echo "✅ Test data seeding completed!\n";
echo str_repeat("=", 50) . "\n";

echo "\n📊 Summary:\n";
echo "  - Users: " . count($userIds) . "\n";
echo "  - Messages: {$messagesCreated}\n";
echo "  - Orders: {$ordersCreated}\n";

echo "\n🔗 View Executive Dashboard: <a href='/executive-dashboard'>Click here</a>\n";
echo "</pre>";
