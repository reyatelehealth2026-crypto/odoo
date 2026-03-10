<?php
/**
 * Check Sync Members Error
 * Simulate the exact request that's failing
 */

// Enable error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Simulating Sync Members Request ===\n\n";

// Simulate the request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = 'sync_members';
$_POST['action'] = 'sync_members';
$_POST['group_id'] = 13; // Use your actual group ID

// Simulate headers
$_SERVER['HTTP_X_ADMIN_ID'] = '1';
$_SERVER['HTTP_X_LINE_ACCOUNT_ID'] = '3';

// Simulate JSON body
$jsonBody = json_encode([
    'action' => 'sync_members',
    'group_id' => 13
]);

// Mock php://input
stream_wrapper_unregister("php");
stream_wrapper_register("php", "MockPhpStream");

class MockPhpStream {
    public $position = 0;
    public $data;
    
    public function stream_open($path, $mode, $options, &$opened_path) {
        global $jsonBody;
        $this->data = $jsonBody;
        $this->position = 0;
        return true;
    }
    
    public function stream_read($count) {
        $ret = substr($this->data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    
    public function stream_eof() {
        return $this->position >= strlen($this->data);
    }
    
    public function stream_stat() {
        return [];
    }
}

echo "Request setup:\n";
echo "- Method: POST\n";
echo "- Action: sync_members\n";
echo "- Group ID: 13\n";
echo "- Admin ID: 1\n";
echo "- Line Account ID: 3\n\n";

echo "Calling inbox-groups.php...\n\n";

try {
    // Capture output
    ob_start();
    include 'api/inbox-groups.php';
    $output = ob_get_clean();
    
    echo "=== Response ===\n";
    echo $output;
    echo "\n";
    
} catch (Exception $e) {
    echo "\n=== ERROR CAUGHT ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "\n=== FATAL ERROR CAUGHT ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
