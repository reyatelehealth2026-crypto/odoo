<?php
/**
 * Debug script for drug_pricing API
 * Tests the API endpoint directly to identify the 500 error cause
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Drug Pricing API</h1>";
echo "<pre>";

// Load config
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = Database::getInstance()->getConnection();
    echo "✅ Database connected\n\n";
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    exit;
}

$lineAccountId = 1;

// Test 1: Check if DrugPricingEngineService exists
echo "=== Test 1: Check DrugPricingEngineService ===\n";
$classFile = __DIR__ . '/../classes/DrugPricingEngineService.php';
if (file_exists($classFile)) {
    echo "✅ DrugPricingEngineService.php exists\n";
    require_once $classFile;
    
    if (class_exists('DrugPricingEngineService')) {
        echo "✅ DrugPricingEngineService class exists\n";
        
        try {
            $pricingEngine = new DrugPricingEngineService($db, $lineAccountId);
            echo "✅ DrugPricingEngineService instantiated\n";
        } catch (Exception $e) {
            echo "❌ Failed to instantiate: " . $e->getMessage() . "\n";
        }
    } else {
        echo "❌ DrugPricingEngineService class not found\n";
    }
} else {
    echo "❌ DrugPricingEngineService.php not found\n";
}

// Test 2: Get sample drugs from business_items (without cost_price)
echo "\n=== Test 2: Get Sample Drugs ===\n";
try {
    $stmt = $db->query("SELECT id, name, price, sale_price, stock FROM business_items WHERE is_active = 1 LIMIT 5");
    $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($drugs) . " drugs:\n";
    foreach ($drugs as $drug) {
        echo "  - ID: {$drug['id']}, Name: {$drug['name']}, Price: {$drug['price']}\n";
    }
    
    if (!empty($drugs)) {
        $testDrugId = $drugs[0]['id'];
    } else {
        echo "❌ No drugs found in business_items\n";
        $testDrugId = null;
    }
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    $testDrugId = null;
}

// Test 3: Check if cost_price column exists
echo "\n=== Test 3: Check cost_price Column ===\n";
try {
    $stmt = $db->query("SHOW COLUMNS FROM business_items LIKE 'cost_price'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($column) {
        echo "✅ cost_price column exists\n";
    } else {
        echo "⚠️ cost_price column does NOT exist - will use estimated cost\n";
        echo "   Run install/run_cost_price_migration.php to add this column\n";
    }
} catch (PDOException $e) {
    echo "❌ Error checking column: " . $e->getMessage() . "\n";
}

// Test 4: Test calculateMargin directly
if ($testDrugId && isset($pricingEngine)) {
    echo "\n=== Test 4: Test calculateMargin for drug ID {$testDrugId} ===\n";
    try {
        $result = $pricingEngine->calculateMargin($testDrugId);
        echo "✅ calculateMargin result:\n";
        print_r($result);
    } catch (Exception $e) {
        echo "❌ calculateMargin error: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// Test 5: Test specific drug IDs from error logs
echo "\n=== Test 5: Test Specific Drug IDs from Errors ===\n";
$errorDrugIds = [2474, 2413, 477, 1236, 478];

foreach ($errorDrugIds as $drugId) {
    echo "\nTesting drug ID: {$drugId}\n";
    try {
        $stmt = $db->prepare("SELECT id, name, price, sale_price FROM business_items WHERE id = ?");
        $stmt->execute([$drugId]);
        $drug = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($drug) {
            echo "  ✅ Found: {$drug['name']}\n";
            
            if (isset($pricingEngine)) {
                try {
                    $result = $pricingEngine->calculateMargin($drugId);
                    echo "  ✅ Pricing: Cost={$result['cost']}, Price={$result['price']}, Margin={$result['marginPercent']}%\n";
                } catch (Exception $e) {
                    echo "  ❌ Pricing error: " . $e->getMessage() . "\n";
                }
            }
        } else {
            echo "  ❌ Drug not found\n";
        }
    } catch (PDOException $e) {
        echo "  ❌ Database error: " . $e->getMessage() . "\n";
    }
}

// Test 6: Check ConsultationAnalyzerService
echo "\n=== Test 6: Test ConsultationAnalyzerService ===\n";
$analyzerFile = __DIR__ . '/../classes/ConsultationAnalyzerService.php';
if (file_exists($analyzerFile)) {
    echo "✅ ConsultationAnalyzerService.php exists\n";
    require_once $analyzerFile;
    
    if (class_exists('ConsultationAnalyzerService')) {
        echo "✅ ConsultationAnalyzerService class exists\n";
        
        try {
            $analyzer = new ConsultationAnalyzerService($db, $lineAccountId);
            echo "✅ ConsultationAnalyzerService instantiated\n";
            
            // Test searchDrugsFromMessage with sample messages
            $testMessages = [
                'ปวดหัว',
                'พาราเซตามอล',
                'ยาแก้ไอ',
                'วิตามินซี'
            ];
            
            foreach ($testMessages as $msg) {
                echo "\nTesting message: '{$msg}'\n";
                try {
                    $drugs = $analyzer->searchDrugsFromMessage($msg);
                    echo "  Found " . count($drugs) . " drugs\n";
                    foreach ($drugs as $drug) {
                        echo "    - {$drug['name']} (฿{$drug['price']})\n";
                    }
                } catch (Exception $e) {
                    echo "  ❌ Error: " . $e->getMessage() . "\n";
                }
            }
        } catch (Exception $e) {
            echo "❌ Failed to instantiate: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "❌ ConsultationAnalyzerService.php not found\n";
}

echo "\n</pre>";
echo "<p><a href='run_cost_price_migration.php'>Run cost_price Migration</a></p>";
echo "<p><a href='../inbox-v2.php'>Back to Inbox</a></p>";
