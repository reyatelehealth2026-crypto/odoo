<?php

/**
 * Audit Logs API Endpoint
 * 
 * Provides access to audit logs and session management
 * Requirements: BR-5.4 (Security & Access Control), NFR-3.4 (Security compliance)
 * 
 * @author Kiro AI Assistant
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/AuditLogger.php';
require_once '../classes/SessionManager.php';

try {
    $db = Database::getInstance()->getConnection();
    $auditLogger = new AuditLogger($db);
    $sessionManager = new SessionManager($db, $auditLogger);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_GET['path'] ?? '';
    
    // Basic authentication check (in production, use proper JWT validation)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (empty($authHeader)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'MISSING_AUTHORIZATION',
                'message' => 'Authorization header required'
            ]
        ]);
        exit;
    }
    
    // Extract token from Bearer header
    $token = str_replace('Bearer ', '', $authHeader);
    $userInfo = $sessionManager->validateToken($token);
    
    if (!$userInfo) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'INVALID_TOKEN',
                'message' => 'Invalid or expired token'
            ]
        ]);
        exit;
    }
    
    // Set user context for audit logging
    $auditLogger->setUserContext($userInfo['user_id'], $userInfo['session_id']);
    
    // Route requests
    switch ($method) {
        case 'GET':
            handleGetRequest($path, $auditLogger, $sessionManager, $userInfo);
            break;
            
        case 'POST':
            handlePostRequest($path, $auditLogger, $sessionManager, $userInfo);
            break;
            
        case 'PUT':
            handlePutRequest($path, $auditLogger, $sessionManager, $userInfo);
            break;
            
        case 'DELETE':
            handleDeleteRequest($path, $auditLogger, $sessionManager, $userInfo);
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
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'INTERNAL_ERROR',
            'message' => 'Internal server error',
            'details' => $e->getMessage()
        ]
    ]);
}

/**
 * Handle GET requests
 */
function handleGetRequest($path, $auditLogger, $sessionManager, $userInfo)
{
    switch ($path) {
        case 'logs':
            // Get audit logs with pagination
            $page = (int)($_GET['page'] ?? 1);
            $limit = min((int)($_GET['limit'] ?? 50), 100);
            $userId = $_GET['user_id'] ?? null;
            $resourceType = $_GET['resource_type'] ?? null;
            $resourceId = $_GET['resource_id'] ?? null;
            
            if ($resourceType && $resourceId) {
                $logs = $auditLogger->getAuditTrail($resourceType, $resourceId, $limit);
            } else {
                $logs = $auditLogger->getRecentActivities($limit, $userId);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $logs,
                'meta' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => count($logs)
                ]
            ]);
            break;
            
        case 'sessions':
            // Get user sessions
            $targetUserId = $_GET['user_id'] ?? $userInfo['user_id'];
            
            // Only allow users to see their own sessions unless they're admin
            if ($targetUserId !== $userInfo['user_id'] && !in_array($userInfo['role'], ['admin', 'super_admin'])) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_PERMISSIONS',
                        'message' => 'Access denied'
                    ]
                ]);
                return;
            }
            
            $sessions = $sessionManager->getUserSessions($targetUserId);
            
            echo json_encode([
                'success' => true,
                'data' => $sessions
            ]);
            break;
            
        case 'stats':
            // Get audit statistics
            $stats = getAuditStats($auditLogger->db);
            
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
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
            break;
    }
}

/**
 * Handle POST requests
 */
function handlePostRequest($path, $auditLogger, $sessionManager, $userInfo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($path) {
        case 'log':
            // Manual audit log entry (for testing)
            $logId = $auditLogger->logAction(
                $input['action'] ?? 'manual_entry',
                $input['resource_type'] ?? 'test',
                $input['resource_id'] ?? null,
                $input['old_values'] ?? null,
                $input['new_values'] ?? null,
                $input['success'] ?? true,
                $input['error_message'] ?? null,
                $input['metadata'] ?? ['source' => 'api_test']
            );
            
            echo json_encode([
                'success' => true,
                'data' => ['log_id' => $logId]
            ]);
            break;
            
        case 'security-event':
            // Log security event
            $eventId = $auditLogger->logSecurityEvent(
                $input['event_type'] ?? 'unknown',
                $input['severity'] ?? 'medium',
                $input['details'] ?? [],
                $input['user_id'] ?? $userInfo['user_id']
            );
            
            echo json_encode([
                'success' => true,
                'data' => ['event_id' => $eventId]
            ]);
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
            break;
    }
}

/**
 * Handle PUT requests
 */
function handlePutRequest($path, $auditLogger, $sessionManager, $userInfo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($path) {
        case 'revoke-session':
            // Revoke a specific session
            $sessionId = $input['session_id'] ?? '';
            $reason = $input['reason'] ?? 'user_request';
            
            if (empty($sessionId)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => [
                        'code' => 'MISSING_SESSION_ID',
                        'message' => 'Session ID required'
                    ]
                ]);
                return;
            }
            
            $success = $sessionManager->revokeSession($sessionId, $userInfo['user_id'], $reason);
            
            echo json_encode([
                'success' => $success,
                'data' => ['revoked' => $success]
            ]);
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
            break;
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequest($path, $auditLogger, $sessionManager, $userInfo)
{
    switch ($path) {
        case 'revoke-all-sessions':
            // Revoke all sessions for current user
            $reason = $_GET['reason'] ?? 'user_request';
            
            $revokedCount = $sessionManager->revokeAllUserSessions(
                $userInfo['user_id'], 
                $userInfo['user_id'], 
                $reason
            );
            
            echo json_encode([
                'success' => true,
                'data' => ['revoked_count' => $revokedCount]
            ]);
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
            break;
    }
}

/**
 * Get audit statistics
 */
function getAuditStats($db)
{
    try {
        // Get basic statistics
        $stats = [];
        
        // Total audit logs
        $stmt = $db->query("SELECT COUNT(*) as total FROM audit_logs");
        $stats['total_logs'] = (int)$stmt->fetchColumn();
        
        // Logs by action type (last 30 days)
        $stmt = $db->query("
            SELECT action, COUNT(*) as count 
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY action 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stats['top_actions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Active sessions
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM user_sessions 
            WHERE is_active = TRUE AND expires_at > NOW()
        ");
        $stats['active_sessions'] = (int)$stmt->fetchColumn();
        
        // Failed actions (last 24 hours)
        $stmt = $db->query("
            SELECT COUNT(*) as count 
            FROM audit_logs 
            WHERE success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stats['failed_actions_24h'] = (int)$stmt->fetchColumn();
        
        // Security events (last 7 days)
        $stmt = $db->query("
            SELECT severity, COUNT(*) as count 
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY severity
        ");
        $stats['security_events_7d'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Failed to get audit stats: " . $e->getMessage());
        return [];
    }
}