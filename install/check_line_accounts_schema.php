<?php
/**
 * Check LINE Accounts Table Schema
 * ตรวจสอบ columns ที่มีจริงในตาราง line_accounts
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    echo "=== LINE Accounts Table Schema ===\n\n";
    
    // ดู columns ทั้งหมดในตาราง line_accounts
    $stmt = $db->query("SHOW COLUMNS FROM line_accounts");
    
    echo "Columns in line_accounts table:\n";
    echo str_repeat("-", 80) . "\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        printf("%-30s | %-15s | %-5s | %-5s | %s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'],
            $row['Default'] ?? 'NULL'
        );
    }
    
    echo "\n\n";
    
    // ดูข้อมูลตัวอย่าง
    echo "Sample Data:\n";
    echo str_repeat("-", 80) . "\n";
    
    $stmt = $db->query("SELECT * FROM line_accounts LIMIT 5");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($data) > 0) {
        // แสดง column names
        $columns = array_keys($data[0]);
        echo implode(" | ", $columns) . "\n";
        echo str_repeat("-", 80) . "\n";
        
        // แสดงข้อมูล
        foreach ($data as $row) {
            $values = array_map(function($v) {
                if (is_null($v)) return 'NULL';
                if (strlen($v) > 20) return substr($v, 0, 17) . '...';
                return $v;
            }, array_values($row));
            echo implode(" | ", $values) . "\n";
        }
    } else {
        echo "No data found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
