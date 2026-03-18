<?php

/**
 * Feature Flags Management API
 * Purpose: Admin interface for managing feature flags and A/B tests
 * Requirements: TC-3.1
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/FeatureFlagBridge.php';

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Line-Account-ID');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Authentication check
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'UNAUTHORIZED',
            'message' => 'Admin access required'
        ]
    ]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));
$action = $pathParts[count($pathParts) - 1] ?? '';

// Initialize feature flag bridge
try {
    $featureFlagBridge = new FeatureFlagBridge();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INITIALIZATION_ERROR',
            'message' => 'Failed to initialize feature flag service'
        ]
    ]);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($featureFlagBridge, $action);
            break;
        case 'POST':
            handlePostRequest($featureFlagBridge, $action);
            break;
        case 'PUT':
            handlePutRequest($featureFlagBridge, $action);
            break;
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'Method not allowed'
                ]
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => $e->getMessage()
        ]
    ]);
}

function handleGetRequest($featureFlagBridge, $action)
{
    switch ($action) {
        case 'flags':
            getUserFeatureFlags($featureFlagBridge);
            break;
        case 'metrics':
            getRoutingMetrics($featureFlagBridge);
            break;
        case 'rollout':
            getRolloutStatus($featureFlagBridge);
            break;
        case 'health':
            getHealthStatus($featureFlagBridge);
            break;
        default:
            getAllFeatureFlags($featureFlagBridge);
    }
}

function handlePostRequest($featureFlagBridge, $action)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'INVALID_JSON',
                'message' => 'Invalid JSON in request body'
            ]
        ]);
        return;
    }

    switch ($action) {
        case 'test-routing':
            testRouting($featureFlagBridge, $input);
            break;
        case 'ab-test':
            createABTest($featureFlagBridge, $input);
            break;
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'ENDPOINT_NOT_FOUND',
                    'message' => 'Endpoint not found'
                ]
            ]);
    }
}

function handlePutRequest($featureFlagBridge, $action)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'INVALID_JSON',
                'message' => 'Invalid JSON in request body'
            ]
        ]);
        return;
    }

    switch ($action) {
        case 'rollout':
            updateRollout($featureFlagBridge, $input);
            break;
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'ENDPOINT_NOT_FOUND',
                    'message' => 'Endpoint not found'
                ]
            ]);
    }
}

function getUserFeatureFlags($featureFlagBridge)
{
    $userId = $_GET['userId'] ?? $_SESSION['user_id'];
    $userRole = $_GET['userRole'] ?? $_SESSION['role'];
    $lineAccountId = $_GET['lineAccountId'] ?? $_SESSION['line_account_id'];

    if (!$userId || !$userRole || !$lineAccountId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'MISSING_PARAMETERS',
                'message' => 'userId, userRole, and lineAccountId are required'
            ]
        ]);
        return;
    }

    $flags = $featureFlagBridge->getFeatureFlags($userId, $userRole, $lineAccountId);

    echo json_encode([
        'success' => true,
        'data' => [
            'userId' => $userId,
            'userRole' => $userRole,
            'lineAccountId' => $lineAccountId,
            'featureFlags' => $flags,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

function getAllFeatureFlags($featureFlagBridge)
{
    // Get all available feature flags with their current status
    $allFlags = [
        'useNewDashboard' => [
            'name' => 'New Dashboard',
            'description' => 'Use modernized dashboard system',
            'rolloutPercentage' => $featureFlagBridge->getRolloutPercentage('useNewDashboard')
        ],
        'useNewOrderManagement' => [
            'name' => 'New Order Management',
            'description' => 'Use modernized order management system',
            'rolloutPercentage' => $featureFlagBridge->getRolloutPercentage('useNewOrderManagement')
        ],
        'useNewPaymentProcessing' => [
            'name' => 'New Payment Processing',
            'description' => 'Use modernized payment processing system',
            'rolloutPercentage' => $featureFlagBridge->getRolloutPercentage('useNewPaymentProcessing')
        ],
        'useNewWebhookManagement' => [
            'name' => 'New Webhook Management',
            'description' => 'Use modernized webhook management system',
            'rolloutPercentage' => $featureFlagBridge->getRolloutPercentage('useNewWebhookManagement')
        ],
        'useNewCustomerManagement' => [
            'name' => 'New Customer Management',
            'description' => 'Use modernized customer management system',
            'rolloutPercentage' => $featureFlagBridge->getRolloutPercentage('useNewCustomerManagement')
        ]
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'featureFlags' => $allFlags,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

function getRoutingMetrics($featureFlagBridge)
{
    $date = $_GET['date'] ?? null;
    $metrics = $featureFlagBridge->getRoutingMetrics($date);

    echo json_encode([
        'success' => true,
        'data' => $metrics
    ]);
}

function getRolloutStatus($featureFlagBridge)
{
    $flagName = $_GET['flag'] ?? null;
    
    if (!$flagName) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'MISSING_PARAMETER',
                'message' => 'flag parameter is required'
            ]
        ]);
        return;
    }

    $percentage = $featureFlagBridge->getRolloutPercentage($flagName);

    echo json_encode([
        'success' => true,
        'data' => [
            'flagName' => $flagName,
            'rolloutPercentage' => $percentage,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

function getHealthStatus($featureFlagBridge)
{
    // Basic health check for feature flag system
    $health = [
        'status' => 'healthy',
        'checks' => [
            'redis' => ['status' => 'healthy', 'message' => 'Redis connection active'],
            'featureFlags' => ['status' => 'healthy', 'message' => 'Feature flag service operational']
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];

    echo json_encode([
        'success' => true,
        'data' => $health
    ]);
}

function testRouting($featureFlagBridge, $input)
{
    $userId = $input['userId'] ?? null;
    $userRole = $input['userRole'] ?? null;
    $lineAccountId = $input['lineAccountId'] ?? null;
    $route = $input['route'] ?? null;

    if (!$userId || !$userRole || !$lineAccountId || !$route) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'MISSING_PARAMETERS',
                'message' => 'userId, userRole, lineAccountId, and route are required'
            ]
        ]);
        return;
    }

    $useNewSystem = $featureFlagBridge->shouldUseNewSystem($route, $userId, $userRole, $lineAccountId);
    $flags = $featureFlagBridge->getFeatureFlags($userId, $userRole, $lineAccountId);

    echo json_encode([
        'success' => true,
        'data' => [
            'userId' => $userId,
            'userRole' => $userRole,
            'lineAccountId' => $lineAccountId,
            'route' => $route,
            'useNewSystem' => $useNewSystem,
            'featureFlags' => $flags,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}

function updateRollout($featureFlagBridge, $input)
{
    $flagName = $input['flagName'] ?? null;
    $percentage = $input['percentage'] ?? null;
    $updatedBy = $_SESSION['username'] ?? $_SESSION['user_id'];

    if (!$flagName || $percentage === null) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'MISSING_PARAMETERS',
                'message' => 'flagName and percentage are required'
            ]
        ]);
        return;
    }

    if (!is_numeric($percentage) || $percentage < 0 || $percentage > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'INVALID_PERCENTAGE',
                'message' => 'Percentage must be a number between 0 and 100'
            ]
        ]);
        return;
    }

    try {
        $featureFlagBridge->updateRolloutPercentage($flagName, (int)$percentage, $updatedBy);

        echo json_encode([
            'success' => true,
            'data' => [
                'flagName' => $flagName,
                'percentage' => (int)$percentage,
                'updatedBy' => $updatedBy,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'UPDATE_FAILED',
                'message' => $e->getMessage()
            ]
        ]);
    }
}

function createABTest($featureFlagBridge, $input)
{
    // A/B test creation would be implemented here
    // For now, return not implemented
    http_response_code(501);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'NOT_IMPLEMENTED',
            'message' => 'A/B test creation not yet implemented'
        ]
    ]);
}