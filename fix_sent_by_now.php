<?php
/**
 * Fix sent_by - ตรวจสอบและแก้ไขปัญหา sent_by
 */
session_start();
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔧 Fix sent_by Issue</h1>";

// 1. ตรวจสอบ Session
echo "<h2>1. Session Check</h2>";
$adminUser = $_SESSION['admin_user'] ?? null;
if ($adminUser) {
    $username = $adminUser['username'] ?? 'N/A';
    $displayName = $adminUser['display_name'] ?? 'N/A';
    echo "<p style='color:green;'>✅ Logged in as: <strong>{$username}</strong></p>";
    echo "<p>Display Name: {$displayName}</p>";
    
    // What will be used
    $expectedName = 'Admin';
    if (!empty($adminUser['username'])) {
        $expectedName = $adminUser['username'];
    } elseif (!empty($adminUser['display_name'])) {
        $expectedName = $adminUser['display_name'];
    }
    echo "<p>Expected sent_by: <strong style='color:blue;'>admin:{$expectedName}</strong></p>";
} else {
    echo "<p style='color:red;'>❌ NOT LOGGED IN! Please login first.</p>";
    echo "<p><a href='auth/login.php'>Go to Login</a></p>";
    exit;
}

// 2. ตรวจสอบ Column
echo "<h2>2. Column Check</h2>";
$stmt = $db->query("SHOW COLUMNS FROM messages LIKE 'sent_by'");
if ($stmt->rowCount() > 0) {
    echo "<p style='color:green;'>✅ Column 'sent_by' exists</p>";
} else {
    echo "<p style='color:orange;'>⚠️ Adding column 'sent_by'...</p>";
    $db->exec("ALTER TABLE messages ADD COLUMN sent_by VARCHAR(100) DEFAULT NULL AFTER content");
    echo "<p style='color:green;'>✅ Column added!</p>";
}

// 3. ดูข้อความล่าสุด
echo "<h2>3. Recent Messages</h2>";
$stmt = $db->query("SELECT id, content, sent_by, created_at FROM messages WHERE direction = 'outgoing' ORDER BY created_at DESC LIMIT 10");
$msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
echo "<tr><th>ID</th><th>Content</th><th>Sent By</th><th>Status</th><th>Created</th></tr>";
foreach ($msgs as $m) {
    $sentBy = $m['sent_by'] ?? '';
    $isCorrect = !empty($sentBy) && $sentBy !== 'admin:Admin' && strpos($sentBy, 'admin:') === 0;
    $status = $isCorrect ? '✅ OK' : '❌ Wrong';
    $color = $isCorrect ? 'green' : 'red';
    
    echo "<tr>";
    echo "<td>{$m['id']}</td>";
    echo "<td>" . htmlspecialchars(mb_substr($m['content'], 0, 30)) . "</td>";
    echo "<td style='color:{$color};'><strong>" . htmlspecialchars($sentBy ?: 'NULL') . "</strong></td>";
    echo "<td>{$status}</td>";
    echo "<td>{$m['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Test Insert
echo "<h2>4. Test Insert</h2>";
if (isset($_GET['test_insert'])) {
    $testContent = 'TEST_' . date('His');
    $testSentBy = 'admin:' . $expectedName;
    
    $stmt = $db->prepare("INSERT INTO messages (line_account_id, user_id, direction, message_type, content, sent_by, created_at, is_read) VALUES (1, 1, 'outgoing', 'text', ?, ?, NOW(), 0)");
    $stmt->execute([$testContent, $testSentBy]);
    $newId = $db->lastInsertId();
    
    // Verify
    $stmt = $db->prepare("SELECT sent_by FROM messages WHERE id = ?");
    $stmt->execute([$newId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Inserted message ID: {$newId}</p>";
    echo "<p>Expected sent_by: <strong>{$testSentBy}</strong></p>";
    echo "<p>Actual sent_by: <strong>" . htmlspecialchars($result['sent_by']) . "</strong></p>";
    
    if ($result['sent_by'] === $testSentBy) {
        echo "<p style='color:green; font-size:20px;'>✅ INSERT WORKS CORRECTLY!</p>";
    } else {
        echo "<p style='color:red; font-size:20px;'>❌ INSERT FAILED - sent_by mismatch!</p>";
    }
    
    // Delete test message
    $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$newId]);
    echo "<p><small>Test message deleted.</small></p>";
} else {
    echo "<p><a href='?test_insert=1' style='padding:10px 20px; background:#4CAF50; color:white; text-decoration:none; border-radius:5px;'>🧪 Run Test Insert</a></p>";
}

// 5. Fix Option
echo "<h2>5. Fix Recent Messages</h2>";
$wrongCount = 0;
foreach ($msgs as $m) {
    if (empty($m['sent_by']) || $m['sent_by'] === 'admin:Admin') {
        $wrongCount++;
    }
}

if ($wrongCount > 0) {
    echo "<p>Found <strong style='color:red;'>{$wrongCount}</strong> messages with wrong sent_by</p>";
    
    if (isset($_GET['fix_recent'])) {
        $correctSentBy = 'admin:' . $expectedName;
        $stmt = $db->prepare("UPDATE messages SET sent_by = ? WHERE direction = 'outgoing' AND (sent_by IS NULL OR sent_by = '' OR sent_by = 'admin:Admin') AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmt->execute([$correctSentBy]);
        $affected = $stmt->rowCount();
        echo "<p style='color:green;'>✅ Fixed {$affected} messages to: {$correctSentBy}</p>";
        echo "<p><a href='fix_sent_by_now.php'>Refresh to see results</a></p>";
    } else {
        echo "<p><a href='?fix_recent=1' style='padding:10px 20px; background:#FF9800; color:white; text-decoration:none; border-radius:5px;'>🔧 Fix Recent Messages (Last 24h)</a></p>";
    }
} else {
    echo "<p style='color:green;'>✅ All recent messages have correct sent_by!</p>";
}

echo "<hr>";
echo "<p><a href='inbox'>← Back to Inbox</a> | <a href='check_messages_db.php'>Check Messages DB</a></p>";
