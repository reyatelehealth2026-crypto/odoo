<?php
/**
 * Notification Preferences API
 * 
 * Manage user notification preferences
 * 
 * Endpoints:
 *   GET    /api/notification-preferences.php?line_user_id=xxx - Get preferences
 *   POST   /api/notification-preferences.php - Update preferences
 *   DELETE /api/notification-preferences.php - Reset to defaults
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/NotificationPreferencesManager.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = Database::getInstance()->getConnection();
    $prefsManager = new NotificationPreferencesManager($db);
    
    switch ($method) {
        case 'GET':
            handleGet($prefsManager);
            break;
            
        case 'POST':
            handlePost($prefsManager);
            break;
            
        case 'DELETE':
            handleDelete($prefsManager, $db);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Get user preferences
 */
function handleGet($prefsManager)
{
    $lineUserId = $_GET['line_user_id'] ?? null;
    
    if (!$lineUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'line_user_id required']);
        return;
    }
    
    // Get all event types
    $eventTypes = [
        'order.validated',
        'order.picker_assigned',
        'order.picking',
        'order.picked',
        'order.packing',
        'order.packed',
        'order.awaiting_payment',
        'order.paid',
        'order.to_delivery',
        'order.in_delivery',
        'order.delivered',
        'bdo.confirmed',
        'invoice.created',
        'invoice.overdue'
    ];
    
    $preferences = [];
    foreach ($eventTypes as $eventType) {
        $prefs = $prefsManager->getPreferences($lineUserId, $eventType);
        if ($prefs) {
            $preferences[] = $prefs;
        }
    }
    
    echo json_encode([
        'success' => true,
        'line_user_id' => $lineUserId,
        'preferences' => $preferences
    ]);
}

/**
 * Update preferences
 */
function handlePost($prefsManager)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $lineUserId = $input['line_user_id'] ?? null;
    $preferences = $input['preferences'] ?? null;
    
    if (!$lineUserId || !$preferences) {
        http_response_code(400);
        echo json_encode(['error' => 'line_user_id and preferences required']);
        return;
    }
    
    $updated = [];
    $errors = [];
    
    foreach ($preferences as $pref) {
        $pref['line_user_id'] = $lineUserId;
        
        if (!isset($pref['event_type'])) {
            $errors[] = 'event_type required for each preference';
            continue;
        }
        
        $result = $prefsManager->updatePreferences($lineUserId, $pref);
        
        if ($result) {
            $updated[] = $pref['event_type'];
        } else {
            $errors[] = "Failed to update {$pref['event_type']}";
        }
    }
    
    echo json_encode([
        'success' => count($errors) === 0,
        'updated' => $updated,
        'errors' => $errors
    ]);
}

/**
 * Reset to defaults
 */
function handleDelete($prefsManager, $db)
{
    $lineUserId = $_GET['line_user_id'] ?? null;
    
    if (!$lineUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'line_user_id required']);
        return;
    }
    
    try {
        // Delete user-specific preferences
        $stmt = $db->prepare("DELETE FROM odoo_notification_preferences WHERE line_user_id = ?");
        $stmt->execute([$lineUserId]);
        
        // Re-initialize with defaults
        $prefsManager->initializeUserPreferences($lineUserId);
        
        echo json_encode([
            'success' => true,
            'message' => 'Preferences reset to defaults'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to reset preferences',
            'message' => $e->getMessage()
        ]);
    }
}
