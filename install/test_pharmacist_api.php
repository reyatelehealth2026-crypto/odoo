<?php
/**
 * Test Pharmacist API
 */
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "<h2>Test Pharmacist API</h2>";

// Test 1: Check if PharmacistNotifier can be loaded
echo "<h3>1. Load PharmacistNotifier</h3>";
try {
    require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
    echo "✅ PharmacistNotifier loaded<br>";
    
    $notifier = new \Modules\AIChat\Services\PharmacistNotifier();
    echo "✅ PharmacistNotifier instantiated<br>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// Test 2: Check database
echo "<h3>2. Database Check</h3>";
try {
    $db = Database::getInstance()->getConnection();
    echo "✅ Database connected<br>";
    
    // Check pharmacist_notifications
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM pharmacist_notifications WHERE status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Pending notifications: " . $result['cnt'] . "<br>";
    
    // Check if medical_history table exists
    $stmt = $db->query("SHOW TABLES LIKE 'medical_history'");
    $tables = $stmt->fetchAll();
    echo "medical_history table exists: " . (count($tables) > 0 ? 'Yes' : 'No') . "<br>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 3: Simulate approve_drugs
echo "<h3>3. Test approve_drugs (dry run)</h3>";
try {
    // Get a pending notification
    $stmt = $db->query("SELECT pn.*, u.line_user_id FROM pharmacist_notifications pn LEFT JOIN users u ON pn.user_id = u.id WHERE pn.status = 'pending' LIMIT 1");
    $notif = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($notif) {
        echo "Found notification ID: " . $notif['id'] . "<br>";
        echo "User ID: " . $notif['user_id'] . "<br>";
        echo "User LINE ID: " . ($notif['line_user_id'] ?? 'N/A') . "<br>";
        
        // Get triage data
        $stmt = $db->prepare("SELECT ts.triage_data FROM pharmacist_notifications pn LEFT JOIN triage_sessions ts ON pn.triage_session_id = ts.id WHERE pn.id = ?");
        $stmt->execute([$notif['id']]);
        $triageResult = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Triage data: " . substr($triageResult['triage_data'] ?? 'null', 0, 100) . "...<br>";
    } else {
        echo "No pending notifications found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<h3>4. Direct API Test</h3>";
echo "<p>Try calling: <code>POST /api/pharmacist.php</code> with:</p>";
echo "<pre>";
echo json_encode([
    'action' => 'approve_drugs',
    'notification_id' => 1,
    'user_id' => 28,
    'drugs' => [['name' => 'Test Drug', 'price' => 100]],
    'note' => 'Test note'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "</pre>";
