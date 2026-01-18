<?php
/**
 * ตรวจสอบ Reply Token ในตาราง users สำหรับ Account 3
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "=== ตรวจสอบ Reply Token ในตาราง users (Account 3) ===\n\n";

try {
    // ตรวจสอบว่ามี column line_account_id หรือไม่
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'line_account_id'");
    $hasAccountCol = $stmt->rowCount() > 0;
    
    if (!$hasAccountCol) {
        echo "⚠️ ตาราง users ไม่มี column line_account_id\n";
        echo "ไม่สามารถแยก users ตาม LINE Account ได้\n\n";
        
        // แสดง users ล่าสุดทั้งหมด
        echo "Users ล่าสุด 10 คน (ทุก Account):\n";
        echo str_repeat("-", 120) . "\n";
        
        $stmt = $db->query("
            SELECT 
                id,
                line_user_id,
                display_name,
                reply_token IS NOT NULL as has_token,
                reply_token_expires,
                updated_at
            FROM users
            ORDER BY updated_at DESC
            LIMIT 10
        ");
    } else {
        // แสดง users จาก Account 3
        echo "Users จาก Account 3 (10 คนล่าสุด):\n";
        echo str_repeat("-", 120) . "\n";
        
        $stmt = $db->query("
            SELECT 
                id,
                line_user_id,
                display_name,
                reply_token IS NOT NULL as has_token,
                reply_token_expires,
                updated_at
            FROM users
            WHERE line_account_id = 3
            ORDER BY updated_at DESC
            LIMIT 10
        ");
    }
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "ไม่พบ users\n";
    } else {
        printf("%-6s %-20s %-25s %-10s %-20s %-20s\n", 
            "ID", "LINE User ID", "Display Name", "Has Token", "Token Expires", "Updated At");
        echo str_repeat("-", 120) . "\n";
        
        foreach ($users as $user) {
            printf("%-6s %-20s %-25s %-10s %-20s %-20s\n",
                $user['id'],
                substr($user['line_user_id'], 0, 20),
                mb_substr($user['display_name'], 0, 25),
                $user['has_token'] ? 'YES' : 'NO',
                $user['reply_token_expires'] ?? 'NULL',
                $user['updated_at']
            );
        }
    }
    
    echo "\n";
    
    // สถิติ reply_token
    if ($hasAccountCol) {
        echo "สถิติ Reply Token (Account 3):\n";
        echo str_repeat("-", 60) . "\n";
        
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN reply_token IS NOT NULL THEN 1 ELSE 0 END) as with_token,
                SUM(CASE WHEN reply_token IS NULL THEN 1 ELSE 0 END) as without_token,
                SUM(CASE WHEN reply_token_expires > NOW() THEN 1 ELSE 0 END) as valid_token
            FROM users
            WHERE line_account_id = 3
        ");
    } else {
        echo "สถิติ Reply Token (ทุก Account):\n";
        echo str_repeat("-", 60) . "\n";
        
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN reply_token IS NOT NULL THEN 1 ELSE 0 END) as with_token,
                SUM(CASE WHEN reply_token IS NULL THEN 1 ELSE 0 END) as without_token,
                SUM(CASE WHEN reply_token_expires > NOW() THEN 1 ELSE 0 END) as valid_token
            FROM users
        ");
    }
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Total Users: " . $stats['total_users'] . "\n";
    echo "With Token: " . $stats['with_token'] . " (" . 
         ($stats['total_users'] > 0 ? round($stats['with_token'] / $stats['total_users'] * 100, 2) : 0) . "%)\n";
    echo "Without Token: " . $stats['without_token'] . " (" . 
         ($stats['total_users'] > 0 ? round($stats['without_token'] / $stats['total_users'] * 100, 2) : 0) . "%)\n";
    echo "Valid Token (ยังไม่หมดอายุ): " . $stats['valid_token'] . "\n";
    
    echo "\n";
    
    // ตรวจสอบ users ที่ได้รับข้อความล่าสุดแต่ไม่มี token
    echo "Users ที่ได้รับข้อความล่าสุดแต่ไม่มี reply_token:\n";
    echo str_repeat("-", 120) . "\n";
    
    if ($hasAccountCol) {
        $stmt = $db->query("
            SELECT 
                u.id,
                u.line_user_id,
                u.display_name,
                u.reply_token IS NOT NULL as has_token,
                u.updated_at,
                MAX(m.created_at) as last_message
            FROM users u
            LEFT JOIN messages m ON u.id = m.user_id AND m.direction = 'incoming'
            WHERE u.line_account_id = 3
            AND u.reply_token IS NULL
            GROUP BY u.id
            ORDER BY last_message DESC
            LIMIT 5
        ");
    } else {
        $stmt = $db->query("
            SELECT 
                u.id,
                u.line_user_id,
                u.display_name,
                u.reply_token IS NOT NULL as has_token,
                u.updated_at,
                MAX(m.created_at) as last_message
            FROM users u
            LEFT JOIN messages m ON u.id = m.user_id AND m.direction = 'incoming'
            WHERE u.reply_token IS NULL
            GROUP BY u.id
            ORDER BY last_message DESC
            LIMIT 5
        ");
    }
    
    $noTokenUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($noTokenUsers)) {
        echo "ไม่พบ users ที่ไม่มี token\n";
    } else {
        printf("%-6s %-20s %-25s %-20s %-20s\n", 
            "ID", "LINE User ID", "Display Name", "Updated At", "Last Message");
        echo str_repeat("-", 120) . "\n";
        
        foreach ($noTokenUsers as $user) {
            printf("%-6s %-20s %-25s %-20s %-20s\n",
                $user['id'],
                substr($user['line_user_id'], 0, 20),
                mb_substr($user['display_name'], 0, 25),
                $user['updated_at'],
                $user['last_message'] ?? 'ไม่มีข้อความ'
            );
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 120) . "\n";
echo "สรุป:\n";
echo "- ถ้า users จาก Account 3 ไม่มี reply_token เลย = webhook ไม่ได้บันทึก token\n";
echo "- ถ้ามี token บ้าง ไม่มีบ้าง = บันทึกได้บางครั้ง (ต้องหาสาเหตุ)\n";
echo "- ถ้ามี token แต่หมดอายุ = ปกติ (token หมดอายุใน 50 วินาที)\n";
echo "\nส่งข้อความทดสอบไปที่ Account 3 แล้วรันสคริปต์นี้อีกครั้งเพื่อดูว่า token ถูกบันทึกหรือไม่\n";
