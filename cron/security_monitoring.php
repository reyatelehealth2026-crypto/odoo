<?php

/**
 * Security Monitoring and Cleanup Cron Job
 * 
 * Performs automated security monitoring tasks:
 * - Clean up expired security events
 * - Generate security alerts for anomalies
 * - Maintain audit log retention policies
 * - Monitor system security health
 * 
 * Schedule: Every 30 minutes
 * Requirements: NFR-3.3, BR-5.4
 * 
 * @author Kiro AI Assistant
 * @version 1.0.0
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AuditLogger.php';

// Set timezone to Bangkok
date_default_timezone_set('Asia/Bangkok');

try {
    $db = Database::getInstance()->getConnection();
    $auditLogger = new AuditLogger($db);
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting security monitoring tasks...\n";
    
    // 1. Clean up old audit logs (retention policy: 1 year)
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning up old audit logs...\n";
    $deletedAuditLogs = $auditLogger->cleanupOldLogs(365);
    echo "[" . date('Y-m-d H:i:s') . "] Cleaned up {$deletedAuditLogs} old audit log entries\n";
    
    // 2. Clean up old security events (retention policy: 90 days)
    echo "[" . date('Y-m-d H:i:s') . "] Cleaning up old security events...\n";
    $deletedSecurityEvents = cleanupOldSecurityEvents($db, 90);
    echo "[" . date('Y-m-d H:i:s') . "] Cleaned up {$deletedSecurityEvents} old security events\n";
    
    // 3. Monitor for security anomalies
    echo "[" . date('Y-m-d H:i:s') . "] Monitoring for security anomalies...\n";
    $anomalies = detectSecurityAnomalies($db, $auditLogger);
    if (!empty($anomalies)) {
        echo "[" . date('Y-m-d H:i:s') . "] Detected " . count($anomalies) . " security anomalies\n";
        foreach ($anomalies as $anomaly) {
            echo "[" . date('Y-m-d H:i:s') . "] - {$anomaly['type']}: {$anomaly['description']}\n";
        }
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No security anomalies detected\n";
    }
    
    // 4. Generate security health report
    echo "[" . date('Y-m-d H:i:s') . "] Generating security health report...\n";
    $healthReport = generateSecurityHealthReport($db);
    echo "[" . date('Y-m-d H:i:s') . "] Security Health Summary:\n";
    echo "[" . date('Y-m-d H:i:s') . "] - Failed logins (24h): {$healthReport['failed_logins_24h']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] - Security events (7d): {$healthReport['security_events_7d']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] - Active sessions: {$healthReport['active_sessions']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] - Audit logs (30d): {$healthReport['audit_logs_30d']}\n";
    
    // 5. Check for critical security thresholds
    echo "[" . date('Y-m-d H:i:s') . "] Checking security thresholds...\n";
    $criticalAlerts = checkSecurityThresholds($db, $auditLogger, $healthReport);
    if (!empty($criticalAlerts)) {
        echo "[" . date('Y-m-d H:i:s') . "] CRITICAL: " . count($criticalAlerts) . " security threshold violations detected!\n";
        foreach ($criticalAlerts as $alert) {
            echo "[" . date('Y-m-d H:i:s') . "] - CRITICAL: {$alert}\n";
        }
        
        // Send notifications for critical alerts
        sendSecurityAlertNotifications($criticalAlerts);
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] All security thresholds within normal ranges\n";
    }
    
    // 6. Update security metrics cache
    echo "[" . date('Y-m-d H:i:s') . "] Updating security metrics cache...\n";
    updateSecurityMetricsCache($db, $healthReport);
    
    echo "[" . date('Y-m-d H:i:s') . "] Security monitoring tasks completed successfully\n";
    
} catch (Exception $e) {
    $errorMessage = "Security monitoring cron job failed: " . $e->getMessage();
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: {$errorMessage}\n";
    
    // Log the error
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            'error',
            'security_monitoring_cron',
            $errorMessage,
            json_encode([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ])
        ]);
    } catch (Exception $logError) {
        echo "[" . date('Y-m-d H:i:s') . "] Failed to log error: " . $logError->getMessage() . "\n";
    }
    
    exit(1);
}

/**
 * Clean up old security events
 */
function cleanupOldSecurityEvents($db, $retentionDays)
{
    try {
        $stmt = $db->prepare("
            DELETE FROM security_events 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND severity NOT IN ('high', 'critical')
        ");
        $stmt->execute([$retentionDays]);
        return $stmt->rowCount();
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error cleaning up security events: " . $e->getMessage() . "\n";
        return 0;
    }
}

/**
 * Detect security anomalies
 */
function detectSecurityAnomalies($db, $auditLogger)
{
    $anomalies = [];
    
    try {
        // 1. Detect unusual login patterns
        $stmt = $db->prepare("
            SELECT ip_address, COUNT(*) as failed_count
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = FALSE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY ip_address
            HAVING failed_count >= 10
        ");
        $stmt->execute();
        $suspiciousIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($suspiciousIPs as $ip) {
            $anomalies[] = [
                'type' => 'brute_force_pattern',
                'description' => "IP {$ip['ip_address']} has {$ip['failed_count']} failed login attempts in the last hour",
                'severity' => 'high',
                'data' => $ip
            ];
            
            // Log security event
            $auditLogger->logSecurityEvent(
                'brute_force_pattern_detected',
                'high',
                [
                    'ip_address' => $ip['ip_address'],
                    'failed_count' => $ip['failed_count'],
                    'time_window' => '1 hour'
                ]
            );
        }
        
        // 2. Detect unusual access patterns
        $stmt = $db->prepare("
            SELECT user_id, COUNT(DISTINCT ip_address) as ip_count
            FROM audit_logs 
            WHERE action = 'login' 
            AND success = TRUE 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY user_id
            HAVING ip_count >= 5
        ");
        $stmt->execute();
        $multiLocationUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($multiLocationUsers as $user) {
            $anomalies[] = [
                'type' => 'multi_location_access',
                'description' => "User {$user['user_id']} accessed from {$user['ip_count']} different IP addresses in 24 hours",
                'severity' => 'medium',
                'data' => $user
            ];
        }
        
        // 3. Detect high error rates
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) as failed_requests,
                (SUM(CASE WHEN success = FALSE THEN 1 ELSE 0 END) / COUNT(*)) * 100 as error_rate
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND action NOT IN ('login', 'logout')
        ");
        $stmt->execute();
        $errorStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($errorStats['error_rate'] > 10) { // More than 10% error rate
            $anomalies[] = [
                'type' => 'high_error_rate',
                'description' => "System error rate is {$errorStats['error_rate']}% in the last hour ({$errorStats['failed_requests']}/{$errorStats['total_requests']} requests)",
                'severity' => 'high',
                'data' => $errorStats
            ];
        }
        
        // 4. Detect unusual admin activity
        $stmt = $db->prepare("
            SELECT user_id, COUNT(*) as admin_actions
            FROM audit_logs a
            JOIN users u ON a.user_id = u.id
            WHERE u.role IN ('super_admin', 'admin')
            AND a.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND a.action IN ('user_create', 'user_delete', 'role_change', 'permission_change')
            GROUP BY user_id
            HAVING admin_actions >= 10
        ");
        $stmt->execute();
        $unusualAdminActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($unusualAdminActivity as $admin) {
            $anomalies[] = [
                'type' => 'unusual_admin_activity',
                'description' => "Admin user {$admin['user_id']} performed {$admin['admin_actions']} administrative actions in the last hour",
                'severity' => 'medium',
                'data' => $admin
            ];
        }
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error detecting security anomalies: " . $e->getMessage() . "\n";
    }
    
    return $anomalies;
}

/**
 * Generate security health report
 */
function generateSecurityHealthReport($db)
{
    $report = [
        'failed_logins_24h' => 0,
        'security_events_7d' => 0,
        'active_sessions' => 0,
        'audit_logs_30d' => 0,
        'blocked_ips' => 0,
        'critical_events_24h' => 0,
    ];
    
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
        $report['failed_logins_24h'] = (int)$stmt->fetchColumn();
        
        // Security events in last 7 days
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM security_events 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $report['security_events_7d'] = (int)$stmt->fetchColumn();
        
        // Active user sessions
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM user_sessions 
            WHERE is_active = TRUE 
            AND expires_at > NOW()
        ");
        $stmt->execute();
        $report['active_sessions'] = (int)$stmt->fetchColumn();
        
        // Audit logs in last 30 days
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM audit_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $report['audit_logs_30d'] = (int)$stmt->fetchColumn();
        
        // Critical security events in last 24 hours
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM security_events 
            WHERE severity = 'critical' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $report['critical_events_24h'] = (int)$stmt->fetchColumn();
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error generating security health report: " . $e->getMessage() . "\n";
    }
    
    return $report;
}

/**
 * Check security thresholds and generate alerts
 */
function checkSecurityThresholds($db, $auditLogger, $healthReport)
{
    $alerts = [];
    
    // Threshold: More than 100 failed logins in 24 hours
    if ($healthReport['failed_logins_24h'] > 100) {
        $alerts[] = "Excessive failed login attempts: {$healthReport['failed_logins_24h']} in 24 hours (threshold: 100)";
        
        $auditLogger->logSecurityEvent(
            'excessive_failed_logins',
            'critical',
            ['failed_count' => $healthReport['failed_logins_24h'], 'threshold' => 100]
        );
    }
    
    // Threshold: More than 50 security events in 7 days
    if ($healthReport['security_events_7d'] > 50) {
        $alerts[] = "High security event volume: {$healthReport['security_events_7d']} events in 7 days (threshold: 50)";
        
        $auditLogger->logSecurityEvent(
            'high_security_event_volume',
            'high',
            ['event_count' => $healthReport['security_events_7d'], 'threshold' => 50]
        );
    }
    
    // Threshold: Any critical security events in 24 hours
    if ($healthReport['critical_events_24h'] > 0) {
        $alerts[] = "Critical security events detected: {$healthReport['critical_events_24h']} in 24 hours";
        
        $auditLogger->logSecurityEvent(
            'critical_events_detected',
            'critical',
            ['critical_count' => $healthReport['critical_events_24h']]
        );
    }
    
    // Threshold: More than 1000 active sessions (potential resource exhaustion)
    if ($healthReport['active_sessions'] > 1000) {
        $alerts[] = "High active session count: {$healthReport['active_sessions']} sessions (threshold: 1000)";
        
        $auditLogger->logSecurityEvent(
            'high_session_count',
            'medium',
            ['session_count' => $healthReport['active_sessions'], 'threshold' => 1000]
        );
    }
    
    return $alerts;
}

/**
 * Send security alert notifications
 */
function sendSecurityAlertNotifications($alerts)
{
    try {
        // In a real implementation, this would send notifications via:
        // - Email to security team
        // - LINE notifications to administrators
        // - Slack/Discord webhooks
        // - SMS alerts for critical issues
        
        foreach ($alerts as $alert) {
            echo "[" . date('Y-m-d H:i:s') . "] ALERT NOTIFICATION: {$alert}\n";
            
            // Log notification attempt
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("
                INSERT INTO dev_logs (log_type, source, message, data, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                'security_alert',
                'security_monitoring_cron',
                'Security alert notification sent',
                json_encode(['alert' => $alert])
            ]);
        }
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error sending security alert notifications: " . $e->getMessage() . "\n";
    }
}

/**
 * Update security metrics cache
 */
function updateSecurityMetricsCache($db, $healthReport)
{
    try {
        $cacheKey = 'security_metrics_' . date('Y-m-d_H');
        $cacheData = json_encode([
            'metrics' => $healthReport,
            'timestamp' => date('Y-m-d H:i:s'),
            'generated_by' => 'security_monitoring_cron'
        ]);
        
        // Store in database cache table
        $stmt = $db->prepare("
            INSERT INTO api_cache (cache_key, data, expires_at) 
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ON DUPLICATE KEY UPDATE 
            data = VALUES(data), 
            expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$cacheKey, $cacheData]);
        
        echo "[" . date('Y-m-d H:i:s') . "] Security metrics cache updated\n";
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Error updating security metrics cache: " . $e->getMessage() . "\n";
    }
}