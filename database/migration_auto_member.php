<?php
/**
 * Migration: Auto-upgrade existing users to members
 * 
 * This script upgrades all existing users (is_registered = 0 or NULL) to members automatically.
 * Run this once after deploying the new auto-register feature.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>🔄 Auto-Upgrade Existing Users to Members</h2>\n";
echo "<style>body { font-family: Arial, sans-serif; padding: 20px; }</style>\n";

try {
    $db = Database::getInstance()->getConnection();

    // Check how many users need upgrade
    $stmt = $db->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE is_registered = 0 OR is_registered IS NULL
    ");
    $toUpgrade = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    echo "<p>📊 พบ users ที่ต้อง upgrade: <strong>{$toUpgrade}</strong> คน</p>\n";

    if ($toUpgrade == 0) {
        echo "<p style='color: green;'>✅ ไม่มี users ที่ต้อง upgrade!</p>\n";
        exit;
    }

    // Get prefix for member_id (check existing pattern)
    $prefix = 'M';
    $year = date('y');

    // Get last member number
    $stmt = $db->prepare("
        SELECT member_id FROM users 
        WHERE member_id LIKE ? 
        ORDER BY member_id DESC LIMIT 1
    ");
    $stmt->execute([$prefix . $year . '%']);
    $last = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($last && preg_match('/^M\d{2}(\d{5})$/', $last['member_id'], $matches)) {
        $nextNum = intval($matches[1]) + 1;
    } else {
        $nextNum = 1;
    }

    echo "<p>📝 เริ่มจาก member_id: {$prefix}{$year}" . str_pad($nextNum, 5, '0', STR_PAD_LEFT) . "</p>\n";

    // Confirm action
    if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
        echo "<p style='color: orange;'>⚠️ กรุณายืนยันการ upgrade โดยเพิ่ม <code>?confirm=yes</code> ใน URL</p>\n";
        echo "<p><a href='?confirm=yes' style='background: #10B981; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>✅ ยืนยันการ Upgrade</a></p>\n";
        exit;
    }

    // Get all users to upgrade
    $stmt = $db->query("
        SELECT id, line_user_id, display_name, line_account_id 
        FROM users 
        WHERE is_registered = 0 OR is_registered IS NULL
        ORDER BY id ASC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $db->beginTransaction();

    $upgraded = 0;
    $errors = [];

    foreach ($users as $user) {
        try {
            $memberId = $prefix . $year . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
            $lineAccountId = $user['line_account_id'] ?? 1;

            // Update user to member
            $updateStmt = $db->prepare("
                UPDATE users SET 
                    member_id = ?,
                    is_registered = 1,
                    member_tier = COALESCE(member_tier, 'bronze'),
                    points = COALESCE(points, 0) + 50,
                    registered_at = COALESCE(registered_at, created_at, NOW()),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$memberId, $user['id']]);

            // Log welcome bonus in points_history
            try {
                $logStmt = $db->prepare("
                    INSERT INTO points_history 
                    (line_account_id, user_id, points, type, description, balance_after)
                    VALUES (?, ?, 50, 'bonus', 'โบนัสต้อนรับสมาชิก (Auto-Upgrade Migration)', 
                           (SELECT COALESCE(points, 50) FROM users WHERE id = ?))
                ");
                $logStmt->execute([$lineAccountId, $user['id'], $user['id']]);
            } catch (Exception $e) {
                // points_history might not exist, ignore
            }

            $upgraded++;
            $nextNum++;

        } catch (Exception $e) {
            $errors[] = "User ID {$user['id']}: " . $e->getMessage();
        }
    }

    $db->commit();

    echo "<p style='color: green;'>✅ อัพเกรดสำเร็จ: <strong>{$upgraded}</strong> คน</p>\n";

    if (!empty($errors)) {
        echo "<p style='color: red;'>❌ Errors (" . count($errors) . "):</p>\n";
        echo "<ul>\n";
        foreach ($errors as $err) {
            echo "<li>{$err}</li>\n";
        }
        echo "</ul>\n";
    }

    // Show summary
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_registered = 1 THEN 1 ELSE 0 END) as registered,
            SUM(CASE WHEN is_registered = 0 OR is_registered IS NULL THEN 1 ELSE 0 END) as not_registered
        FROM users
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h3>📊 สรุป:</h3>\n";
    echo "<ul>\n";
    echo "<li>Total users: {$stats['total']}</li>\n";
    echo "<li>Registered members: {$stats['registered']}</li>\n";
    echo "<li>Not registered: {$stats['not_registered']}</li>\n";
    echo "</ul>\n";

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
}
