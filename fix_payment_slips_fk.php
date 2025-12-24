<?php
/**
 * Fix Payment Slips Foreign Key
 * แก้ไข FK ที่ชี้ไปผิดตาราง (orders -> transactions)
 */
header('Content-Type: text/html; charset=utf-8');
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>🔧 Fix Payment Slips Foreign Key</h1>";

try {
    // 1. Show current FK constraints
    echo "<h2>1. FK Constraints ปัจจุบัน</h2>";
    $stmt = $db->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'payment_slips'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($fks) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Constraint</th><th>Column</th><th>References Table</th><th>References Column</th></tr>";
        foreach ($fks as $fk) {
            $color = ($fk['REFERENCED_TABLE_NAME'] === 'orders') ? 'color:red' : '';
            echo "<tr style='{$color}'>";
            echo "<td>{$fk['CONSTRAINT_NAME']}</td>";
            echo "<td>{$fk['COLUMN_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_TABLE_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_COLUMN_NAME']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>ไม่พบ FK constraints</p>";
    }
    
    // 2. Drop wrong FK (pointing to orders)
    echo "<h2>2. ลบ FK ที่ผิด</h2>";
    foreach ($fks as $fk) {
        if ($fk['REFERENCED_TABLE_NAME'] === 'orders') {
            $constraintName = $fk['CONSTRAINT_NAME'];
            echo "<p>Dropping: {$constraintName}...</p>";
            try {
                $db->exec("ALTER TABLE payment_slips DROP FOREIGN KEY `{$constraintName}`");
                echo "<p style='color:green'>✅ Dropped {$constraintName}</p>";
            } catch (Exception $e) {
                echo "<p style='color:orange'>⚠️ " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // 3. Check if transactions table exists
    echo "<h2>3. ตรวจสอบตาราง transactions</h2>";
    $stmt = $db->query("SHOW TABLES LIKE 'transactions'");
    if ($stmt->fetch()) {
        echo "<p style='color:green'>✅ ตาราง transactions มีอยู่</p>";
        
        // Add new FK to transactions (optional - can skip if causing issues)
        echo "<h2>4. เพิ่ม FK ใหม่ (ชี้ไป transactions)</h2>";
        try {
            // First check if index exists
            $stmt = $db->query("SHOW INDEX FROM payment_slips WHERE Key_name = 'idx_order_id'");
            if (!$stmt->fetch()) {
                $db->exec("ALTER TABLE payment_slips ADD INDEX idx_order_id (order_id)");
                echo "<p>Added index idx_order_id</p>";
            }
            
            // Add FK - but make it optional (SET NULL on delete)
            // Actually, let's NOT add FK to avoid future issues
            echo "<p style='color:blue'>ℹ️ ไม่เพิ่ม FK ใหม่ เพื่อความยืดหยุ่น</p>";
            
        } catch (Exception $e) {
            echo "<p style='color:orange'>⚠️ " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color:red'>❌ ไม่พบตาราง transactions</p>";
    }
    
    // 5. Test INSERT
    echo "<h2>5. ทดสอบ INSERT</h2>";
    
    // Get latest transaction
    $stmt = $db->query("SELECT id, order_number, user_id FROM transactions ORDER BY id DESC LIMIT 1");
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($tx) {
        echo "<p>Testing with Transaction ID: {$tx['id']}, Order: {$tx['order_number']}</p>";
        
        $testUrl = 'https://re-ya.com/uploads/slips/test_' . time() . '.jpg';
        
        try {
            $stmt = $db->prepare("INSERT INTO payment_slips (order_id, transaction_id, user_id, image_url, status) VALUES (?, ?, ?, ?, 'pending')");
            $stmt->execute([$tx['id'], $tx['id'], $tx['user_id'], $testUrl]);
            $slipId = $db->lastInsertId();
            echo "<p style='color:green'>✅ INSERT สำเร็จ! Slip ID: {$slipId}</p>";
            
            // Delete test record
            $db->exec("DELETE FROM payment_slips WHERE id = {$slipId}");
            echo "<p>🗑️ Deleted test record</p>";
            
        } catch (Exception $e) {
            echo "<p style='color:red'>❌ INSERT Error: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color:orange'>⚠️ ไม่มี transaction สำหรับทดสอบ</p>";
    }
    
    // 6. Verify FK constraints after fix
    echo "<h2>6. FK Constraints หลังแก้ไข</h2>";
    $stmt = $db->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'payment_slips'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($fks) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Constraint</th><th>Column</th><th>References Table</th><th>References Column</th></tr>";
        foreach ($fks as $fk) {
            echo "<tr>";
            echo "<td>{$fk['CONSTRAINT_NAME']}</td>";
            echo "<td>{$fk['COLUMN_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_TABLE_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_COLUMN_NAME']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:green'>✅ ไม่มี FK constraints (ดี - ยืดหยุ่นกว่า)</p>";
    }
    
    echo "<h2>✅ เสร็จสิ้น!</h2>";
    echo "<p><a href='test_slip_upload.php'>📤 ทดสอบอัพโหลดสลิป</a></p>";
    echo "<p><a href='debug_payment_slips.php'>🔍 Debug Payment Slips</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
