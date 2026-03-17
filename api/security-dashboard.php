<?php

/**
 * Security Dashboard API Endpoint
 * 
 * Provides comprehensive security monitoring data for the dashboard
 * Integrates with both PHP and Node.js security systems
 * Requirements: NFR-3.3, BR-5.4
 * 
 * @author Kiro AI Assistant
 * @version 1.0.0
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

// Set timezone to Bangkok
date_default_timezone_set('Asia/Bangkok');

try {
    $db = Database::getInstance()->getConnection();
    $auditLogger = new AuditLogger($db);
    $sessionManager = new SessionManager($db, $auditLogger);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'overview';
    
    // Basic authentication check
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
    
    // Extract and validate token
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
    
    // Check permissions (only admin and super_admin can access security dashboard)
    if (!in_array($userInfo['role'], ['admin', 'super_admin'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'INSUFFICIENT_PERMISSIONS',
                'message' => 'Access denied - admin privileges required'
            ]
        ]);
        exit;
    }
    
    // Set user context for audit logging
    $auditLogger->setUserContext($userInfo['user_id'], $userInfo['session_id']);
    
    // Route requests
    switch ($method) {
        case 'GET':
            handleGetRequest($action, $db, $auditLogger, $userInfo);
            break;
            
        case 'POST':
            handlePostRequest($action, $db, $auditLogger, $userInfo);
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
function handleGetRequest($action, $db, $auditLogger, $userInfo)
{
    switch ($action) {
        case 'overview':
            $data = getSecurityOverview($db);
            break;
            
        case 'threats':
            $days = (int)($_GET['days'] ?? 7);
            $data = getSecurityThreats($db, $days);
            break;
            
        case 'audit-stats':
            $days = (int)($_GET['days'] ?? 30);
            $data = getAuditStatistics($db, $days);
            break;
            
        case 'failed-logins':
            $hours = (int)($_GET['hours'] ?? 24);
            $data = getFailedLoginAnalysis($db, $hours);
            break;
            
        case 'active-sessions':
            $data = getActiveSessionsAnalysis($db);
            break;
            
        case 'security-events':
            $days = (int)($_GET['days'] ?? 7);
            $severity = $_GET['severity'] ?? null;
            $data = getSecurityEvents($db, $days, $severity);
            break;
            
        case 'blocked-ips':
            $data = getBlockedIPsStatus($db);
            break;
            
        case 'system-health':
            $data = getSystemSecurityHealth($db);
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'ACTION_NOT_FOUND',
                    'message' => 'Action not found'
                ]
            ]);
            return;
    }
    
    // Log dashboard access
    $auditLogger->logAction(
        'security_dashboard_accessed',
        'security',
        null,
        null,
        ['action' => $action, 'timestamp' => date('Y-m-d H:i:s')],
        true,
        null,
        ['dashboard_section' => $action]
    );
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s'),
        'timezone' => 'Asia/Bangkok'
    ]);
}

/**
 * Handle POST requests
 */
function handlePostRequest($action, $db, $auditLogger, $userInfo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'block-ip':
            $result = blockIPAddress($db, $auditLogger, $userInfo, $input);
            break;
            
        case 'unblock-ip':
            $result = unblockIPAddress($db, $auditLogger, $userInfo, $input);
            break;
            
        case 'acknowledge-alert':
            $result = acknowledgeSecurityAlert($db, $auditLogger, $userInfo, $input);
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'ACTION_NOT_FOUND',
                    'message' => 'Action not found'
                ]
            ]);
            return;
    }
    
    echo json_encode($result);
}

/**
 * Get security overview dashboard data
 */
function getSecurityOverview($db)
{
    $overview = [];
    
    try {
        // Failed logins in last 24 hours
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $overview['failed_logins_24h'] = (int)$stmt->fetchColumn();
        
        // Security events in last 7 days
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $overview['security_events_7d'] = (int)$stmt->fetchColumn();
        
        // Active user sessions
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM user_sessions 
            WHERE is_active = TRUE 
            AND expires_at > NOW()
        ");
        $stmt->execute();
        $overview['active_sessions'] = (int)$stmt->fetchColumn();
        
        // Critical security events in last 24 hours
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM security_events 
            WHERE severity = 'critical' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $overview['critical_events_24h'] = (int)$stmt->fetchColumn();
        
        // Audit logs in last 30 days
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $overview['audit_logs_30d'] = (int)$stmt->fetchColumn();
        
        // Success rate calculation
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $successStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $overview['success_rate_24h'] = $successStats['total'] > 0 ? 
            round(($successStats['successful'] / $successStats['total']) * 100, 2) : 100;
        
        // Top security event types
        $stmt = $db->prepare("
            SELECT event_type, COUNT(*) as count 
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY event_type 
            ORDER BY count DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $overview['top_event_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent critical alerts
        $stmt = $db->prepare("
            SELECT event_type, severity, created_at, details
            FROM security_events 
            WHERE severity IN ('high', 'critical')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $overview['recent_critical_alerts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting security overview: " . $e->getMessage());
        $overview['error'] = 'Failed to retrieve some security metrics';
    }
    
    return $overview;
}

/**
 * Get security threats analysis
 */
function getSecurityThreats($db, $days)
{
    $threats = [];
    
    try {
        // Threat distribution by type
        $stmt = $db->prepare("
            SELECT event_type, severity, COUNT(*) as count 
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY event_type, severity 
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        $threats['threat_distribution'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Threat timeline (daily)
        $stmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_threats,
                SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high,
                SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN severity = 'low' THEN 1 ELSE 0 END) as low
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at) 
            ORDER BY date DESC
        ");
        $stmt->execute([$days]);
        $threats['threat_timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top attacking IPs
        $stmt = $db->prepare("
            SELECT 
                ip_address,
                COUNT(*) as threat_count,
                MAX(created_at) as last_seen,
                GROUP_CONCAT(DISTINCT event_type) as event_types
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND ip_address IS NOT NULL
            GROUP BY ip_address 
            ORDER BY threat_count DESC 
            LIMIT 10
        ");
        $stmt->execute([$days]);
        $threats['top_attacking_ips'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting security threats: " . $e->getMessage());
        $threats['error'] = 'Failed to retrieve threat analysis';
    }
    
    return $threats;
}

/**
 * Get audit statistics
 */
function getAuditStatistics($db, $days)
{
    $stats = [];
    
    try {
        // Action distribution
        $stmt = $db->prepare("
            SELECT action, COUNT(*) as count 
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY action 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stmt->execute([$days]);
        $stats['top_actions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // User activity
        $stmt = $db->prepare("
            SELECT 
                a.user_id,
                u.username,
                u.role,
                COUNT(*) as action_count,
                MAX(a.created_at) as last_activity
            FROM audit_logs a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY a.user_id, u.username, u.role
            ORDER BY action_count DESC 
            LIMIT 10
        ");
        $stmt->execute([$days]);
        $stats['top_users'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Daily audit activity
        $stmt = $db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_actions,
                SUM(CASE WHEN success = TRUE THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at) 
            ORDER BY date DESC
        ");
        $stmt->execute([$days]);
        $stats['daily_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting audit statistics: " . $e->getMessage());
        $stats['error'] = 'Failed to retrieve audit statistics';
    }
    
    return $stats;
}

/**
 * Get failed login analysis
 */
function getFailedLoginAnalysis($db, $hours)
{
    $analysis = [];
    
    try {
        // Failed logins by IP
        $stmt = $db->prepare("
            SELECT 
                ip_address,
                COUNT(*) as failed_count,
                MAX(created_at) as last_attempt,
                GROUP_CONCAT(DISTINCT user_agent SEPARATOR '; ') as user_agents
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY ip_address 
            ORDER BY failed_count DESC 
            LIMIT 20
        ");
        $stmt->execute([$hours]);
        $analysis['failed_by_ip'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Failed logins timeline (hourly)
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as failed_count
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')
            ORDER BY hour DESC
        ");
        $stmt->execute([$hours]);
        $analysis['hourly_timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Potential brute force attacks (5+ failures from same IP)
        $stmt = $db->prepare("
            SELECT 
                ip_address,
                COUNT(*) as failed_count,
                MIN(created_at) as first_attempt,
                MAX(created_at) as last_attempt,
                COUNT(DISTINCT user_agent) as user_agent_count
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY ip_address 
            HAVING failed_count >= 5
            ORDER BY failed_count DESC
        ");
        $stmt->execute([$hours]);
        $analysis['potential_brute_force'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting failed login analysis: " . $e->getMessage());
        $analysis['error'] = 'Failed to retrieve failed login analysis';
    }
    
    return $analysis;
}

/**
 * Get active sessions analysis
 */
function getActiveSessionsAnalysis($db)
{
    $analysis = [];
    
    try {
        // Sessions by user role
        $stmt = $db->prepare("
            SELECT 
                u.role,
                COUNT(*) as session_count,
                AVG(TIMESTAMPDIFF(MINUTE, s.created_at, NOW())) as avg_duration_minutes
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.is_active = TRUE 
            AND s.expires_at > NOW()
            GROUP BY u.role
            ORDER BY session_count DESC
        ");
        $stmt->execute();
        $analysis['sessions_by_role'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Sessions by IP (detect shared IPs)
        $stmt = $db->prepare("
            SELECT 
                ip_address,
                COUNT(*) as session_count,
                COUNT(DISTINCT user_id) as unique_users,
                GROUP_CONCAT(DISTINCT u.username SEPARATOR ', ') as usernames
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.is_active = TRUE 
            AND s.expires_at > NOW()
            AND s.ip_address IS NOT NULL
            GROUP BY ip_address
            HAVING session_count > 1
            ORDER BY session_count DESC
            LIMIT 10
        ");
        $stmt->execute();
        $analysis['shared_ips'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Long-running sessions (over 8 hours)
        $stmt = $db->prepare("
            SELECT 
                s.user_id,
                u.username,
                u.role,
                s.ip_address,
                s.created_at,
                TIMESTAMPDIFF(HOUR, s.created_at, NOW()) as duration_hours
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.is_active = TRUE 
            AND s.expires_at > NOW()
            AND TIMESTAMPDIFF(HOUR, s.created_at, NOW()) > 8
            ORDER BY duration_hours DESC
            LIMIT 10
        ");
        $stmt->execute();
        $analysis['long_running_sessions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error getting active sessions analysis: " . $e->getMessage());
        $analysis['error'] = 'Failed to retrieve session analysis';
    }
    
    return $analysis;
}

/**
 * Get security events
 */
function getSecurityEvents($db, $days, $severity = null)
{
    $events = [];
    
    try {
        $sql = "
            SELECT 
                id,
                event_type,
                severity,
                user_id,
                ip_address,
                user_agent,
                details,
                created_at
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ";
        
        $params = [$days];
        
        if ($severity) {
            $sql .= " AND severity = ?";
            $params[] = $severity;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 100";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $events['events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON details
        foreach ($events['events'] as &$event) {
            if ($event['details']) {
                $event['details'] = json_decode($event['details'], true);
            }
        }
        
    } catch (Exception $e) {
        error_log("Error getting security events: " . $e->getMessage());
        $events['error'] = 'Failed to retrieve security events';
    }
    
    return $events;
}

/**
 * Get blocked IPs status (placeholder - would integrate with Redis in production)
 */
function getBlockedIPsStatus($db)
{
    // This is a placeholder implementation
    // In production, this would query Redis for blocked IPs
    return [
        'blocked_ips' => [],
        'total_blocked' => 0,
        'note' => 'Blocked IPs are managed by the Node.js security service'
    ];
}

/**
 * Get system security health
 */
function getSystemSecurityHealth($db)
{
    $health = [];
    
    try {
        // Calculate various health metrics
        $health['status'] = 'healthy';
        $health['checks'] = [];
        
        // Check 1: Failed login rate
        $stmt = $db->prepare("
            SELECT COUNT(*) as failed_logins 
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $failedLogins = (int)$stmt->fetchColumn();
        
        $health['checks']['failed_login_rate'] = [
            'status' => $failedLogins < 50 ? 'healthy' : ($failedLogins < 100 ? 'warning' : 'critical'),
            'value' => $failedLogins,
            'threshold' => 50,
            'description' => 'Failed login attempts in the last hour'
        ];
        
        // Check 2: Critical security events
        $stmt = $db->prepare("
            SELECT COUNT(*) as critical_events 
            FROM security_events 
            WHERE severity = 'critical' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $criticalEvents = (int)$stmt->fetchColumn();
        
        $health['checks']['critical_events'] = [
            'status' => $criticalEvents == 0 ? 'healthy' : ($criticalEvents < 5 ? 'warning' : 'critical'),
            'value' => $criticalEvents,
            'threshold' => 0,
            'description' => 'Critical security events in the last 24 hours'
        ];
        
        // Check 3: Audit log volume
        $stmt = $db->prepare("
            SELECT COUNT(*) as audit_volume 
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $auditVolume = (int)$stmt->fetchColumn();
        
        $health['checks']['audit_volume'] = [
            'status' => $auditVolume < 10000 ? 'healthy' : 'warning',
            'value' => $auditVolume,
            'threshold' => 10000,
            'description' => 'Audit log entries in the last hour'
        ];
        
        // Overall health status
        $criticalCount = 0;
        $warningCount = 0;
        
        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'critical') $criticalCount++;
            elseif ($check['status'] === 'warning') $warningCount++;
        }
        
        if ($criticalCount > 0) {
            $health['status'] = 'critical';
        } elseif ($warningCount > 0) {
            $health['status'] = 'warning';
        }
        
        $health['last_updated'] = date('Y-m-d H:i:s');
        
    } catch (Exception $e) {
        error_log("Error getting system security health: " . $e->getMessage());
        $health = [
            'status' => 'error',
            'error' => 'Failed to retrieve system health metrics'
        ];
    }
    
    return $health;
}

/**
 * Block IP address (placeholder - would integrate with Redis/Node.js service)
 */
function blockIPAddress($db, $auditLogger, $userInfo, $input)
{
    // This is a placeholder implementation
    // In production, this would call the Node.js security service
    
    $ip = $input['ip'] ?? '';
    $reason = $input['reason'] ?? '';
    $duration = $input['duration'] ?? 30; // minutes
    
    if (empty($ip) || empty($reason)) {
        return [
            'success' => false,
            'error' => [
                'code' => 'INVALID_INPUT',
                'message' => 'IP address and reason are required'
            ]
        ];
    }
    
    // Log the action
    $auditLogger->logAction(
        'ip_blocked_manually',
        'security',
        $ip,
        null,
        [
            'ip' => $ip,
            'reason' => $reason,
            'duration_minutes' => $duration,
            'blocked_by' => $userInfo['user_id']
        ],
        true,
        null,
        ['manual_block' => true]
    );
    
    return [
        'success' => true,
        'data' => [
            'ip' => $ip,
            'blocked' => true,
            'reason' => $reason,
            'duration_minutes' => $duration,
            'message' => 'IP blocking request logged. Integration with Node.js security service required for actual blocking.'
        ]
    ];
}

/**
 * Unblock IP address (placeholder)
 */
function unblockIPAddress($db, $auditLogger, $userInfo, $input)
{
    $ip = $input['ip'] ?? '';
    $reason = $input['reason'] ?? '';
    
    if (empty($ip) || empty($reason)) {
        return [
            'success' => false,
            'error' => [
                'code' => 'INVALID_INPUT',
                'message' => 'IP address and reason are required'
            ]
        ];
    }
    
    // Log the action
    $auditLogger->logAction(
        'ip_unblocked_manually',
        'security',
        $ip,
        null,
        [
            'ip' => $ip,
            'reason' => $reason,
            'unblocked_by' => $userInfo['user_id']
        ],
        true,
        null,
        ['manual_unblock' => true]
    );
    
    return [
        'success' => true,
        'data' => [
            'ip' => $ip,
            'unblocked' => true,
            'reason' => $reason,
            'message' => 'IP unblocking request logged. Integration with Node.js security service required for actual unblocking.'
        ]
    ];
}

/**
 * Acknowledge security alert (placeholder)
 */
function acknowledgeSecurityAlert($db, $auditLogger, $userInfo, $input)
{
    $alertId = $input['alert_id'] ?? '';
    $notes = $input['notes'] ?? '';
    
    if (empty($alertId)) {
        return [
            'success' => false,
            'error' => [
                'code' => 'INVALID_INPUT',
                'message' => 'Alert ID is required'
            ]
        ];
    }
    
    // Log the acknowledgment
    $auditLogger->logAction(
        'security_alert_acknowledged',
        'security',
        $alertId,
        null,
        [
            'alert_id' => $alertId,
            'notes' => $notes,
            'acknowledged_by' => $userInfo['user_id']
        ],
        true,
        null,
        ['alert_acknowledgment' => true]
    );
    
    return [
        'success' => true,
        'data' => [
            'alert_id' => $alertId,
            'acknowledged' => true,
            'acknowledged_by' => $userInfo['user_id'],
            'acknowledged_at' => date('Y-m-d H:i:s'),
            'notes' => $notes,
            'message' => 'Security alert acknowledgment logged.'
        ]
    ];
}