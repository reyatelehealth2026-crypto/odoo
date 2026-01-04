<?php
header('Content-Type: application/json');
session_start();

// Try to load database config
$dbAvailable = false;
$conn = null;

if (file_exists('../config/config.php')) {
    @include_once '../config/config.php';
    if (defined('DB_HOST') && file_exists('../config/database.php')) {
        require_once '../config/database.php';
        $dbAvailable = true;
    }
}

// Allow access without auth for testing purposes
$userId = $_SESSION['admin_id'] ?? 0;
$userName = $_SESSION['admin_name'] ?? 'Anonymous Tester';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Save test results
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['testData'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data']);
        exit;
    }
    
    // If database not available, just return success (data saved in localStorage)
    if (!$dbAvailable) {
        echo json_encode([
            'success' => true,
            'message' => 'Results saved to localStorage (database not configured)',
            'timestamp' => date('Y-m-d H:i:s'),
            'storage' => 'localStorage'
        ]);
        exit;
    }
    
    try {
        $testData = json_encode($input['testData']);
        $timestamp = $input['timestamp'] ?? date('Y-m-d H:i:s');
        
        // Create table if not exists
        $createTable = "CREATE TABLE IF NOT EXISTS testing_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            user_name VARCHAR(255),
            test_data JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        )";
        $conn->query($createTable);
        
        // Check if user has existing results
        $checkStmt = $conn->prepare("SELECT id FROM testing_results WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $checkStmt->bind_param("i", $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            // Update existing
            $row = $result->fetch_assoc();
            $stmt = $conn->prepare("UPDATE testing_results SET test_data = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $testData, $row['id']);
        } else {
            // Insert new
            $stmt = $conn->prepare("INSERT INTO testing_results (user_id, user_name, test_data) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $userId, $userName, $testData);
        }
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Results saved successfully',
                'timestamp' => date('Y-m-d H:i:s'),
                'storage' => 'database'
            ]);
        } else {
            throw new Exception('Failed to save results');
        }
        
    } catch (Exception $e) {
        // Fallback to localStorage message
        echo json_encode([
            'success' => true,
            'message' => 'Results saved to localStorage (database error)',
            'timestamp' => date('Y-m-d H:i:s'),
            'storage' => 'localStorage'
        ]);
    }
    
} elseif ($method === 'GET') {
    // Get test results
    if (!$dbAvailable) {
        echo json_encode([
            'success' => true,
            'testData' => null,
            'message' => 'Load from localStorage (database not configured)'
        ]);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("SELECT test_data, updated_at FROM testing_results WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'testData' => json_decode($row['test_data'], true),
                'lastUpdated' => $row['updated_at']
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'testData' => null,
                'message' => 'No saved results found'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => true,
            'testData' => null,
            'message' => 'Load from localStorage'
        ]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
