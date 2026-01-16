<?php
/**
 * Test Auto Reply - เพิ่ม auto reply rules สำหรับทดสอบ
 */
require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🧪 Test Auto Reply Setup</h1>";
echo "<style>
body { font-family: sans-serif; padding: 20px; }
.success { color: green; }
.error { color: red; }
.info { color: blue; }
</style>";

// ตรวจสอบตาราง auto_replies
try {
    $stmt = $db->query("SHOW TABLES LIKE 'auto_replies'");
    if ($stmt->rowCount() == 0) {
        echo "<p class='error'>❌ ตาราง auto_replies ไม่มีอยู่</p>";
        echo "<p>สร้างตารางก่อน...</p>";
        
        $sql = "CREATE TABLE IF NOT EXISTS `auto_replies` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `keyword` varchar(255) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `match_type` enum('exact','contains','starts_with','regex','all') DEFAULT 'contains',
            `reply_type` enum('text','flex') DEFAULT 'text',
            `reply_content` text NOT NULL,
            `alt_text` varchar(400) DEFAULT NULL,
            `sender_name` varchar(100) DEFAULT NULL,
            `sender_icon` varchar(500) DEFAULT NULL,
            `quick_reply` text DEFAULT NULL,
            `tags` varchar(255) DEFAULT NULL,
            `priority` int(11) DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `use_count` int(11) DEFAULT 0,
            `last_used_at` timestamp NULL DEFAULT NULL,
            `line_account_id` int(11) DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_keyword` (`keyword`),
            KEY `idx_active` (`is_active`),
            KEY `idx_line_account` (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($sql);
        echo "<p class='success'>✅ สร้างตาราง auto_replies เรียบร้อย</p>";
    } else {
        echo "<p class='success'>✅ ตาราง auto_replies มีอยู่แล้ว</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error: " . $e->getMessage() . "</p>";
    exit;
}

// เพิ่ม auto reply rules ตัวอย่าง
$testRules = [
    [
        'keyword' => 'สวัสดี',
        'description' => 'ทักทาย',
        'match_type' => 'contains',
        'reply_type' => 'text',
        'reply_content' => 'สวัสดีครับ! ยินดีต้อนรับสู่ร้านยา 😊\n\nพิมพ์ "เมนู" เพื่อดูตัวเลือก',
        'priority' => 10
    ],
    [
        'keyword' => 'hello',
        'description' => 'English greeting',
        'match_type' => 'contains',
        'reply_type' => 'text',
        'reply_content' => 'Hello! Welcome to our pharmacy 😊\n\nType "menu" to see options',
        'priority' => 10
    ],
    [
        'keyword' => 'ราคา',
        'description' => 'สอบถามราคา',
        'match_type' => 'contains',
        'reply_type' => 'text',
        'reply_content' => '💰 สอบถามราคาสินค้า\n\nกรุณาส่งชื่อสินค้าที่ต้องการทราบราคา หรือพิมพ์ "สินค้า" เพื่อดูรายการสินค้า',
        'priority' => 8
    ],
    [
        'keyword' => 'price',
        'description' => 'Price inquiry',
        'match_type' => 'contains',
        'reply_type' => 'text',
        'reply_content' => '💰 Product Price Inquiry\n\nPlease send the product name you want to know the price, or type "products" to see product list',
        'priority' => 8
    ],
    [
        'keyword' => 'ทดสอบ',
        'description' => 'ทดสอบระบบ',
        'match_type' => 'exact',
        'reply_type' => 'text',
        'reply_content' => '🧪 ระบบทำงานปกติ!\n\nเวลา: ' . date('Y-m-d H:i:s'),
        'priority' => 5
    ]
];

echo "<h2>เพิ่ม Auto Reply Rules ตัวอย่าง</h2>";

foreach ($testRules as $rule) {
    try {
        // ตรวจสอบว่ามี rule นี้อยู่แล้วหรือไม่
        $stmt = $db->prepare("SELECT id FROM auto_replies WHERE keyword = ? AND match_type = ?");
        $stmt->execute([$rule['keyword'], $rule['match_type']]);
        
        if ($stmt->rowCount() > 0) {
            echo "<p class='info'>ℹ️ Rule '{$rule['keyword']}' มีอยู่แล้ว - ข้าม</p>";
            continue;
        }
        
        // เพิ่ม rule ใหม่
        $stmt = $db->prepare("INSERT INTO auto_replies 
            (keyword, description, match_type, reply_type, reply_content, priority, is_active, line_account_id) 
            VALUES (?, ?, ?, ?, ?, ?, 1, NULL)");
        $stmt->execute([
            $rule['keyword'],
            $rule['description'],
            $rule['match_type'],
            $rule['reply_type'],
            $rule['reply_content'],
            $rule['priority']
        ]);
        
        echo "<p class='success'>✅ เพิ่ม rule '{$rule['keyword']}' เรียบร้อย</p>";
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Error adding '{$rule['keyword']}': " . $e->getMessage() . "</p>";
    }
}

// แสดงสถิติ
try {
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(is_active) as active FROM auto_replies");
    $stats = $stmt->fetch();
    
    echo "<h2>📊 สถิติ Auto Reply</h2>";
    echo "<p><strong>Total rules:</strong> {$stats['total']}</p>";
    echo "<p><strong>Active rules:</strong> {$stats['active']}</p>";
    
    // แสดง rules ที่ active
    $stmt = $db->query("SELECT keyword, match_type, reply_type, priority FROM auto_replies WHERE is_active = 1 ORDER BY priority DESC");
    $activeRules = $stmt->fetchAll();
    
    if (!empty($activeRules)) {
        echo "<h3>Active Rules:</h3>";
        echo "<ul>";
        foreach ($activeRules as $rule) {
            echo "<li><strong>{$rule['keyword']}</strong> ({$rule['match_type']}) - {$rule['reply_type']} [Priority: {$rule['priority']}]</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Error getting stats: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>🔗 ลิงก์ที่เป็นประโยชน์</h2>";
echo "<ul>";
echo "<li><a href='/auto-reply.php'>จัดการ Auto Reply Rules</a></li>";
echo "<li><a href='/debug-auto-reply.php'>Debug Auto Reply</a></li>";
echo "<li><a href='/webhook.php'>Webhook (สำหรับ LINE)</a></li>";
echo "</ul>";

echo "<h2>📝 วิธีทดสอบ</h2>";
echo "<ol>";
echo "<li>ส่งข้อความ 'สวัสดี' หรือ 'hello' ใน LINE</li>";
echo "<li>ส่งข้อความ 'ราคา' หรือ 'price' ใน LINE</li>";
echo "<li>ส่งข้อความ 'ทดสอบ' ใน LINE</li>";
echo "<li>ตรวจสอบ logs ใน dev_logs table</li>";
echo "</ol>";
?>