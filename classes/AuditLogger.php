<?php

/**
 * Enhanced Audit Logger Service
 * 
 * Provides comprehensive audit logging for all sensitive operations
 * Requirements: BR-5.4 (Security & Access Control), NFR-3.4 (Security compliance)
 * 
 * @author Kiro AI Assistant
 * @version 1.0.0
 */
class AuditLogger
{
    private $db;
    private $currentUserId;
    private $currentSessionId;
    private $ipAddress;
    private $userAgent;

    public function __construct($database = null, $userId = null, $sessionId = null)
    {
        $this->db = $database ?: Database::getInstance()->getConnection();
        $this->currentUserId = $userId;
        $this->currentSessionId = $sessionId;
        $this->ipAddress = $this->getClientIpAddress();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Log an audit event
     * 
     * @param string $action Action performed (e.g., 'login', 'order_update', 'payment_process')
     * @param string $resourceType Type of resource (e.g., 'order', 'payment', 'user')
     * @param string|null $resourceId ID of the specific resource
     * @param array|null $oldValues Previous values before change
     * @param array|null $newValues New values after change
     * @param bool $success Whether the action was successful
     * @param string|null $errorMessage Error message if action failed
     * @param array|null $metadata Additional context-specific data
     * @param string|null $requestId Request ID for tracing
     * @return string The audit log ID
     */
    public function logAction(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        bool $success = true,
        ?string $errorMessage = null,
        ?array $metadata = null,
        ?string $requestId = null
    ): string {
        try {
            $auditId = $this->generateUUID();
            
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    id, user_id, action, resource_type, resource_id,
                    old_values, new_values, ip_address, user_agent,
                    session_id, request_id, success, error_message, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $auditId,
                $this->currentUserId ?? 'anonymous',
                $action,
                $resourceType,
                $resourceId,
                $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                $this->ipAddress,
                $this->userAgent,
                $this->currentSessionId,
                $requestId ?? $this->generateRequestId(),
                $success ? 1 : 0,
                $errorMessage,
                $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null
            ]);
            
            return $auditId;
        } catch (Exception $e) {
            // Log to error log if audit logging fails
            error_log("Audit logging failed: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Log authentication events
     */
    public function logLogin(string $userId, bool $success, ?string $errorMessage = null): string
    {
        return $this->logAction(
            'login',
            'authentication',
            $userId,
            null,
            ['timestamp' => date('Y-m-d H:i:s')],
            $success,
            $errorMessage,
            [
                'login_method' => 'jwt',
                'ip_address' => $this->ipAddress,
                'user_agent' => $this->userAgent
            ]
        );
    }

    public function logLogout(string $userId, string $sessionId): string
    {
        return $this->logAction(
            'logout',
            'authentication',
            $userId,
            null,
            ['session_id' => $sessionId, 'timestamp' => date('Y-m-d H:i:s')],
            true,
            null,
            ['logout_type' => 'user_initiated']
        );
    }

    public function logTokenRefresh(string $userId, string $oldTokenHash, string $newTokenHash): string
    {
        return $this->logAction(
            'token_refresh',
            'authentication',
            $userId,
            ['token_hash' => substr($oldTokenHash, 0, 8) . '...'],
            ['token_hash' => substr($newTokenHash, 0, 8) . '...'],
            true,
            null,
            ['refresh_timestamp' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Log order-related events
     */
    public function logOrderUpdate(string $orderId, array $oldValues, array $newValues): string
    {
        return $this->logAction(
            'order_update',
            'order',
            $orderId,
            $oldValues,
            $newValues,
            true,
            null,
            ['update_type' => 'status_change']
        );
    }

    public function logOrderCreation(string $orderId, array $orderData): string
    {
        return $this->logAction(
            'order_create',
            'order',
            $orderId,
            null,
            $orderData,
            true,
            null,
            ['creation_source' => 'dashboard']
        );
    }

    /**
     * Log payment-related events
     */
    public function logPaymentProcessing(string $paymentId, array $paymentData, bool $success, ?string $errorMessage = null): string
    {
        return $this->logAction(
            'payment_process',
            'payment',
            $paymentId,
            null,
            $paymentData,
            $success,
            $errorMessage,
            [
                'payment_method' => $paymentData['method'] ?? 'unknown',
                'amount' => $paymentData['amount'] ?? 0
            ]
        );
    }

    public function logPaymentSlipUpload(string $slipId, array $uploadData): string
    {
        return $this->logAction(
            'payment_slip_upload',
            'payment_slip',
            $slipId,
            null,
            $uploadData,
            true,
            null,
            [
                'file_size' => $uploadData['file_size'] ?? 0,
                'file_type' => $uploadData['file_type'] ?? 'unknown'
            ]
        );
    }

    /**
     * Log security events
     */
    public function logSecurityEvent(
        string $eventType,
        string $severity,
        array $details,
        ?string $userId = null
    ): string {
        try {
            $eventId = $this->generateUUID();
            
            $stmt = $this->db->prepare("
                INSERT INTO security_events (
                    id, event_type, severity, user_id, ip_address,
                    user_agent, details
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $eventId,
                $eventType,
                $severity,
                $userId,
                $this->ipAddress,
                $this->userAgent,
                json_encode($details, JSON_UNESCAPED_UNICODE)
            ]);
            
            return $eventId;
        } catch (Exception $e) {
            error_log("Security event logging failed: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Get audit trail for a specific resource
     */
    public function getAuditTrail(string $resourceType, string $resourceId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    a.*,
                    u.username,
                    u.email,
                    u.role
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.resource_type = ? AND a.resource_id = ?
                ORDER BY a.created_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$resourceType, $resourceId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to retrieve audit trail: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get recent audit activities
     */
    public function getRecentActivities(int $limit = 100, ?string $userId = null): array
    {
        try {
            $sql = "
                SELECT 
                    a.*,
                    u.username,
                    u.email,
                    u.role
                FROM audit_logs a
                LEFT JOIN users u ON a.user_id = u.id
                WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ";
            
            $params = [];
            if ($userId) {
                $sql .= " AND a.user_id = ?";
                $params[] = $userId;
            }
            
            $sql .= " ORDER BY a.created_at DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to retrieve recent activities: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up old audit logs (for data retention compliance)
     */
    public function cleanupOldLogs(int $retentionDays = 365): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND action NOT IN ('login', 'logout', 'payment_process')
            ");
            
            $stmt->execute([$retentionDays]);
            $deletedCount = $stmt->rowCount();
            
            // Log the cleanup action
            $this->logAction(
                'audit_cleanup',
                'system',
                null,
                null,
                ['deleted_count' => $deletedCount, 'retention_days' => $retentionDays],
                true,
                null,
                ['cleanup_type' => 'scheduled']
            );
            
            return $deletedCount;
        } catch (Exception $e) {
            error_log("Failed to cleanup old audit logs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get client IP address (handles proxies and load balancers)
     */
    private function getClientIpAddress(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Generate UUID v4
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Generate request ID for tracing
     */
    private function generateRequestId(): string
    {
        return 'req_' . uniqid() . '_' . mt_rand(1000, 9999);
    }

    /**
     * Set current user context
     */
    public function setUserContext(string $userId, ?string $sessionId = null): void
    {
        $this->currentUserId = $userId;
        $this->currentSessionId = $sessionId;
    }
}