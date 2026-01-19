<?php
/**
 * Test Rewards API
 * ทดสอบระบบแลกรางวัลและ API ที่เกี่ยวข้อง
 */

// Prevent direct execution in production
if (!isset($_GET['run'])) {
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ทดสอบระบบแลกรางวัล</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
            }
            .container {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                padding: 40px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            h1 {
                color: #667eea;
                margin-bottom: 10px;
                font-size: 32px;
            }
            .subtitle {
                color: #666;
                margin-bottom: 30px;
                font-size: 16px;
            }
            .info-box {
                background: #f0f4ff;
                border-left: 4px solid #667eea;
                padding: 20px;
                margin-bottom: 30px;
                border-radius: 8px;
            }
            .info-box h3 {
                color: #667eea;
                margin-bottom: 10px;
            }
            .info-box ul {
                margin-left: 20px;
                color: #555;
            }
            .info-box li {
                margin: 8px 0;
            }
            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 16px 32px;
                font-size: 18px;
                font-weight: 600;
                border-radius: 12px;
                cursor: pointer;
                display: inline-block;
                text-decoration: none;
                transition: transform 0.2s, box-shadow 0.2s;
                box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
            }
            .btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 30px rgba(102, 126, 234, 0.6);
            }
            .warning {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 15px;
                margin: 20px 0;
                border-radius: 8px;
                color: #856404;
            }
            .test-results {
                margin-top: 30px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🎁 ทดสอบระบบแลกรางวัล</h1>
            <p class="subtitle">ตรวจสอบและทดสอบ API สำหรับระบบแลกแต้ม</p>

            <div class="info-box">
                <h3>📋 การทดสอบจะตรวจสอบ:</h3>
                <ul>
                    <li>✅ เชื่อมต่อฐานข้อมูล</li>
                    <li>✅ ตรวจสอบตาราง rewards และ point_rewards</li>
                    <li>✅ จำนวนรางวัลที่มี (active และ inactive)</li>
                    <li>✅ ทดสอบ API endpoints</li>
                    <li>✅ ตรวจสอบโครงสร้างข้อมูล</li>
                </ul>
            </div>

            <div class="warning">
                ⚠️ ไฟล์นี้ใช้สำหรับทดสอบเท่านั้น ควรลบออกหรือจำกัดการเข้าถึงใน production
            </div>

            <a href="?run=1" class="btn">🚀 เริ่มการทดสอบ</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Run tests
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$results = [];
$errors = [];

// Test 1: Database Connection
try {
    $db = Database::getInstance()->getConnection();
    $results[] = ['test' => 'Database Connection', 'status' => 'success', 'message' => 'เชื่อมต่อฐานข้อมูลสำเร็จ'];
} catch (Exception $e) {
    $errors[] = ['test' => 'Database Connection', 'error' => $e->getMessage()];
    renderResults($results, $errors);
    exit;
}

// Test 2: Check if rewards table exists
try {
    $stmt = $db->query("SHOW TABLES LIKE 'rewards'");
    $hasRewardsTable = $stmt->fetch() !== false;

    $stmt = $db->query("SHOW TABLES LIKE 'point_rewards'");
    $hasPointRewardsTable = $stmt->fetch() !== false;

    if ($hasRewardsTable) {
        $results[] = ['test' => 'Rewards Table', 'status' => 'success', 'message' => 'ตาราง rewards มีอยู่'];
    } elseif ($hasPointRewardsTable) {
        $results[] = ['test' => 'Rewards Table', 'status' => 'warning', 'message' => 'ใช้ตาราง point_rewards (legacy)'];
    } else {
        $errors[] = ['test' => 'Rewards Table', 'error' => 'ไม่พบตาราง rewards หรือ point_rewards'];
    }
} catch (Exception $e) {
    $errors[] = ['test' => 'Rewards Table', 'error' => $e->getMessage()];
}

// Test 3: Check rewards columns
try {
    if ($hasRewardsTable) {
        $stmt = $db->query("SHOW COLUMNS FROM rewards");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $requiredColumns = ['id', 'name', 'points_required', 'is_active', 'stock'];
        $existingColumns = array_column($columns, 'Field');
        $missingColumns = array_diff($requiredColumns, $existingColumns);

        if (empty($missingColumns)) {
            $results[] = [
                'test' => 'Table Structure',
                'status' => 'success',
                'message' => 'โครงสร้างตารางครบถ้วน (' . count($existingColumns) . ' columns)',
                'data' => $existingColumns
            ];
        } else {
            $errors[] = [
                'test' => 'Table Structure',
                'error' => 'ขาด columns: ' . implode(', ', $missingColumns)
            ];
        }
    }
} catch (Exception $e) {
    $errors[] = ['test' => 'Table Structure', 'error' => $e->getMessage()];
}

// Test 4: Count rewards
try {
    $tableName = $hasRewardsTable ? 'rewards' : 'point_rewards';

    // Total rewards
    $stmt = $db->query("SELECT COUNT(*) as total FROM $tableName");
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Active rewards
    $stmt = $db->query("SELECT COUNT(*) as active FROM $tableName WHERE is_active = 1");
    $active = $stmt->fetch(PDO::FETCH_ASSOC)['active'];

    // Inactive rewards
    $inactive = $total - $active;

    // In stock rewards
    $stmt = $db->query("SELECT COUNT(*) as in_stock FROM $tableName WHERE is_active = 1 AND (stock IS NULL OR stock = -1 OR stock > 0)");
    $inStock = $stmt->fetch(PDO::FETCH_ASSOC)['in_stock'];

    $message = "รางวัลทั้งหมด: $total | Active: $active | In Stock: $inStock";

    if ($active > 0) {
        $results[] = [
            'test' => 'Rewards Count',
            'status' => 'success',
            'message' => $message,
            'data' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'in_stock' => $inStock
            ]
        ];
    } else {
        $errors[] = [
            'test' => 'Rewards Count',
            'error' => 'ไม่มีรางวัลที่เปิดใช้งาน (is_active = 1)',
            'suggestion' => 'ไปที่ /install/create_sample_rewards.php เพื่อสร้างรางวัลตัวอย่าง'
        ];
    }
} catch (Exception $e) {
    $errors[] = ['test' => 'Rewards Count', 'error' => $e->getMessage()];
}

// Test 5: Get sample rewards
try {
    if ($active > 0) {
        $stmt = $db->query("SELECT id, name, points_required, stock, is_active FROM $tableName WHERE is_active = 1 LIMIT 5");
        $sampleRewards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results[] = [
            'test' => 'Sample Rewards',
            'status' => 'success',
            'message' => 'แสดงรางวัล ' . count($sampleRewards) . ' รายการแรก',
            'data' => $sampleRewards
        ];
    }
} catch (Exception $e) {
    $errors[] = ['test' => 'Sample Rewards', 'error' => $e->getMessage()];
}

// Test 6: Test API endpoint (points.php)
try {
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
              . "://" . $_SERVER['HTTP_HOST'];
    $apiUrl = $baseUrl . '/api/points.php?action=rewards&line_account_id=1';

    $results[] = [
        'test' => 'API Endpoint (points.php)',
        'status' => 'info',
        'message' => 'URL: ' . $apiUrl,
        'action' => '<a href="' . $apiUrl . '" target="_blank" style="color: #667eea; text-decoration: underline;">ทดสอบ API</a>'
    ];
} catch (Exception $e) {
    $errors[] = ['test' => 'API Endpoint', 'error' => $e->getMessage()];
}

// Test 7: Test API endpoint (points-history.php)
try {
    $apiUrl2 = $baseUrl . '/api/points-history.php?action=rewards&line_user_id=Utest123';

    $results[] = [
        'test' => 'API Endpoint (points-history.php)',
        'status' => 'info',
        'message' => 'URL: ' . $apiUrl2,
        'action' => '<a href="' . $apiUrl2 . '" target="_blank" style="color: #667eea; text-decoration: underline;">ทดสอบ API</a>'
    ];
} catch (Exception $e) {
    $errors[] = ['test' => 'API Endpoint 2', 'error' => $e->getMessage()];
}

// Test 8: Check LoyaltyPoints class
try {
    $loyaltyClass = __DIR__ . '/../classes/LoyaltyPoints.php';
    if (file_exists($loyaltyClass)) {
        $results[] = [
            'test' => 'LoyaltyPoints Class',
            'status' => 'success',
            'message' => 'ไฟล์ classes/LoyaltyPoints.php มีอยู่'
        ];
    } else {
        $errors[] = [
            'test' => 'LoyaltyPoints Class',
            'error' => 'ไม่พบไฟล์ classes/LoyaltyPoints.php'
        ];
    }
} catch (Exception $e) {
    $errors[] = ['test' => 'LoyaltyPoints Class', 'error' => $e->getMessage()];
}

// Render results
renderResults($results, $errors);

function renderResults($results, $errors) {
    $totalTests = count($results) + count($errors);
    $successCount = count(array_filter($results, function($r) { return $r['status'] === 'success'; }));
    $errorCount = count($errors);
    $warningCount = count(array_filter($results, function($r) { return $r['status'] === 'warning'; }));
    $infoCount = count(array_filter($results, function($r) { return $r['status'] === 'info'; }));

    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ผลการทดสอบระบบแลกรางวัล</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
            }
            .container {
                max-width: 1000px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                padding: 40px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            h1 {
                color: #667eea;
                margin-bottom: 10px;
                font-size: 32px;
            }
            .subtitle {
                color: #666;
                margin-bottom: 30px;
                font-size: 16px;
            }
            .summary {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 15px;
                margin-bottom: 30px;
            }
            .summary-card {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 12px;
                text-align: center;
                border: 2px solid #e9ecef;
            }
            .summary-card.success { border-color: #28a745; background: #d4edda; }
            .summary-card.error { border-color: #dc3545; background: #f8d7da; }
            .summary-card.warning { border-color: #ffc107; background: #fff3cd; }
            .summary-card.info { border-color: #17a2b8; background: #d1ecf1; }
            .summary-card h3 {
                font-size: 36px;
                margin-bottom: 5px;
            }
            .summary-card p {
                font-size: 14px;
                color: #666;
            }
            .test-result {
                background: #f8f9fa;
                padding: 20px;
                margin-bottom: 15px;
                border-radius: 12px;
                border-left: 5px solid #e9ecef;
            }
            .test-result.success { border-left-color: #28a745; }
            .test-result.error { border-left-color: #dc3545; background: #fff5f5; }
            .test-result.warning { border-left-color: #ffc107; background: #fffef7; }
            .test-result.info { border-left-color: #17a2b8; background: #f7fcfd; }
            .test-result h3 {
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .status-badge.success { background: #28a745; color: white; }
            .status-badge.error { background: #dc3545; color: white; }
            .status-badge.warning { background: #ffc107; color: #333; }
            .status-badge.info { background: #17a2b8; color: white; }
            .test-message {
                color: #555;
                margin: 10px 0;
            }
            .test-data {
                background: white;
                padding: 15px;
                border-radius: 8px;
                margin-top: 10px;
                font-family: 'Courier New', monospace;
                font-size: 13px;
                overflow-x: auto;
            }
            .suggestion {
                background: #fff3cd;
                padding: 15px;
                border-radius: 8px;
                margin-top: 10px;
                border-left: 4px solid #ffc107;
            }
            .actions {
                margin-top: 30px;
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
            }
            .btn {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 14px 28px;
                font-size: 16px;
                font-weight: 600;
                border-radius: 10px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                transition: transform 0.2s;
            }
            .btn:hover {
                transform: translateY(-2px);
            }
            .btn-secondary {
                background: #6c757d;
            }
            pre {
                white-space: pre-wrap;
                word-wrap: break-word;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🎁 ผลการทดสอบระบบแลกรางวัล</h1>
            <p class="subtitle">ทดสอบเสร็จสิ้น - <?php echo date('d/m/Y H:i:s'); ?></p>

            <div class="summary">
                <div class="summary-card">
                    <h3><?php echo $totalTests; ?></h3>
                    <p>การทดสอบทั้งหมด</p>
                </div>
                <div class="summary-card success">
                    <h3><?php echo $successCount; ?></h3>
                    <p>ผ่าน</p>
                </div>
                <div class="summary-card error">
                    <h3><?php echo $errorCount; ?></h3>
                    <p>ไม่ผ่าน</p>
                </div>
                <?php if ($warningCount > 0): ?>
                <div class="summary-card warning">
                    <h3><?php echo $warningCount; ?></h3>
                    <p>คำเตือน</p>
                </div>
                <?php endif; ?>
                <?php if ($infoCount > 0): ?>
                <div class="summary-card info">
                    <h3><?php echo $infoCount; ?></h3>
                    <p>ข้อมูล</p>
                </div>
                <?php endif; ?>
            </div>

            <h2 style="margin-bottom: 20px; color: #333;">📊 รายละเอียดการทดสอบ</h2>

            <?php foreach ($results as $result): ?>
                <div class="test-result <?php echo $result['status']; ?>">
                    <h3>
                        <span><?php echo $result['test']; ?></span>
                        <span class="status-badge <?php echo $result['status']; ?>">
                            <?php echo $result['status']; ?>
                        </span>
                    </h3>
                    <div class="test-message">
                        <?php echo $result['message']; ?>
                    </div>
                    <?php if (isset($result['data'])): ?>
                        <div class="test-data">
                            <pre><?php echo json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($result['action'])): ?>
                        <div style="margin-top: 10px;">
                            <?php echo $result['action']; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php foreach ($errors as $error): ?>
                <div class="test-result error">
                    <h3>
                        <span><?php echo $error['test']; ?></span>
                        <span class="status-badge error">ERROR</span>
                    </h3>
                    <div class="test-message">
                        ❌ <?php echo $error['error']; ?>
                    </div>
                    <?php if (isset($error['suggestion'])): ?>
                        <div class="suggestion">
                            💡 <strong>แนะนำ:</strong> <?php echo $error['suggestion']; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="actions">
                <a href="?" class="btn">🔄 ทดสอบใหม่</a>
                <?php if ($errorCount > 0): ?>
                    <a href="create_sample_rewards.php" class="btn">🎁 สร้างรางวัลตัวอย่าง</a>
                <?php endif; ?>
                <a href="../admin-rewards.php" class="btn btn-secondary">⚙️ จัดการรางวัล</a>
            </div>

            <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px; font-size: 14px; color: #666;">
                📝 <strong>หมายเหตุ:</strong> หากพบปัญหา กรุณาตรวจสอบ:
                <ul style="margin: 10px 0 0 20px;">
                    <li>ฐานข้อมูลมีตาราง rewards หรือ point_rewards</li>
                    <li>มีรางวัลที่ is_active = 1</li>
                    <li>API endpoints สามารถเข้าถึงได้</li>
                    <li>ไฟล์ classes/LoyaltyPoints.php มีอยู่</li>
                </ul>
            </div>
        </div>
    </body>
    </html>
    <?php
}
