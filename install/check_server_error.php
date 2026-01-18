<?php
/**
 * ตรวจสอบ Server Error ล่าสุด
 */

echo "=== ตรวจสอบ Server Error ===\n\n";

// Check PHP error log
$errorLogPaths = [
    '/home/zrismpsz/public_html/emp.re-ya.net/error_log',
    __DIR__ . '/../error_log',
    ini_get('error_log'),
    '/var/log/php_errors.log',
    '/tmp/php_errors.log'
];

echo "กำลังตรวจสอบ error logs...\n\n";

foreach ($errorLogPaths as $path) {
    if ($path && file_exists($path)) {
        echo "✓ พบ error log: $path\n";
        echo str_repeat("-", 100) . "\n";
        
        // Read last 50 lines
        $lines = file($path);
        $recentLines = array_slice($lines, -50);
        
        echo "Error ล่าสุด 50 บรรทัด:\n";
        echo str_repeat("-", 100) . "\n";
        
        foreach ($recentLines as $line) {
            // แสดงเฉพาะ error ที่สำคัญ
            if (stripos($line, 'error') !== false || 
                stripos($line, 'fatal') !== false || 
                stripos($line, 'warning') !== false ||
                stripos($line, 'parse') !== false) {
                echo $line;
            }
        }
        
        echo "\n";
        break;
    }
}

// Check if webhook.php has syntax errors
echo "\n" . str_repeat("=", 100) . "\n";
echo "ตรวจสอบ Syntax Error ใน webhook.php:\n";
echo str_repeat("-", 100) . "\n";

$webhookPath = __DIR__ . '/../webhook.php';
if (file_exists($webhookPath)) {
    exec("php -l $webhookPath 2>&1", $output, $returnCode);
    
    if ($returnCode === 0) {
        echo "✓ webhook.php ไม่มี syntax error\n";
    } else {
        echo "✗ webhook.php มี syntax error:\n";
        foreach ($output as $line) {
            echo "  $line\n";
        }
    }
} else {
    echo "✗ ไม่พบไฟล์ webhook.php\n";
}

echo "\n" . str_repeat("=", 100) . "\n";
echo "คำแนะนำ:\n";
echo "1. ถ้ามี syntax error - แก้ไขตามที่แจ้ง\n";
echo "2. ถ้ามี fatal error - ดูว่า class หรือ function ไหนหายไป\n";
echo "3. ถ้ามี memory error - เพิ่ม memory_limit ใน php.ini\n";
echo "4. ถ้าไม่มี error แต่ยัง 500 - ตรวจสอบ .htaccess\n";
