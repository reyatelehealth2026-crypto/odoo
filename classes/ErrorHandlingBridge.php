<?php

/**
 * Error Handling Bridge for PHP Integration
 * Bridges existing PHP system with new Node.js error handling infrastructure
 * Implements BR-2.2, NFR-2.2 requirements for comprehensive error handling
 */

class ErrorHandlingBridge
{
    private $db;
    private $config;
    private $logLevels = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4
    ];

    public function __construct($database = null)
    {
        $this->db = $database ?: Database::getInstance()->getConnection();
        $this->config = [
            'enable_logging' => true,
            'enable_notifications' => true,
            'error_threshold' => 10,
            'log_retention_days' => 30
        ];
    }

    /**
     * Log error to the new error handling system
     */
    public function logError($code, $message, $details = null, $requestId = null, $userId = null, $endpoint = null)
    {
        try {
            $errorId = $this->generateUUID();
            $level = $this->determineErrorLevel($code);
            
            $stmt = $this->db->prepare("
                INSERT INTO error_logs (
                    id, level, code, message, details, request_id, user_id, endpoint,
                    user_agent, ip_address, timestamp
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $detailsJson = $details ? json_encode($details) : null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $ipAddress = $this->getClientIP();

            $stmt->execute([
                $errorId,
                $level,
                $code,
                $message,
                $detailsJson,
                $requestId,
                $userId,
                $endpoint,
                $userAgent,
                $ipAddress
            ]);

            // Check if we need to send alerts
            $this->checkErrorThreshold($code);

            return $errorId;

        } catch (Exception $e) {
            // Fallback logging to dev_logs table
            error_log("Error logging failed: " . $e->getMessage());
            $this->fallbackLog($code, $message, $details);
            return null;
        }
    }

    /**
     * Add failed operation to dead letter queue
     */
    public function addToDeadLetterQueue($operationType, $payload, $error, $attempts = 0, $maxAttempts = 5, $priority = 'medium')
    {
        try {
            $messageId = $this->generateUUID();
            $now = date('Y-m-d H:i:s');
            $nextRetry = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            $stmt = $this->db->prepare("
                INSERT INTO dead_letter_queue (
                    id, operation_type, payload, original_error, attempts, max_attempts,
                    first_failed_at, last_attempt_at, next_retry_at, status, priority
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ");

            $payloadJson = json_encode($payload);
            $errorMessage = is_string($error) ? $error : $error->getMessage();

            $stmt->execute([
                $messageId,
                $operationType,
                $payloadJson,
                $errorMessage,
                $attempts,
                $maxAttempts,
                $now,
                $now,
                $nextRetry,
                $priority
            ]);

            return $messageId;

        } catch (Exception $e) {
            error_log("Failed to add to dead letter queue: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update service health status
     */
    public function updateServiceHealth($serviceName, $healthy, $errorMessage = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO service_health (id, service_name, healthy, error_count, last_error, last_check)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    healthy = VALUES(healthy),
                    last_check = NOW(),
                    error_count = IF(VALUES(healthy), GREATEST(error_count - 1, 0), error_count + 1),
                    degradation_level = CASE 
                        WHEN error_count >= 10 THEN 'full'
                        WHEN error_count >= 5 THEN 'partial'
                        ELSE 'none'
                    END,
                    last_error = IF(VALUES(healthy), last_error, VALUES(last_error)),
                    updated_at = NOW()
            ");

            $serviceId = 'srv-health-' . $serviceName;
            $errorCount = $healthy ? 0 : 1;

            $stmt->execute([
                $serviceId,
                $serviceName,
                $healthy ? 1 : 0,
                $errorCount,
                $errorMessage
            ]);

        } catch (Exception $e) {
            error_log("Failed to update service health: " . $e->getMessage());
        }
    }

    /**
     * Execute operation with retry logic
     */
    public function executeWithRetry(callable $operation, $operationName, $maxAttempts = 3, $baseDelay = 1000)
    {
        $attempts = 0;
        $lastError = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            
            try {
                $result = $operation();
                
                if ($attempts > 1) {
                    $this->logError(
                        'RETRY_SUCCESS',
                        "Operation '{$operationName}' succeeded after {$attempts} attempts",
                        ['operation' => $operationName, 'attempts' => $attempts]
                    );
                }
                
                return $result;

            } catch (Exception $e) {
                $lastError = $e;
                
                if (!$this->isRetryableError($e) || $attempts >= $maxAttempts) {
                    break;
                }

                // Calculate delay with exponential backoff
                $delay = min($baseDelay * pow(2, $attempts - 1), 30000);
                
                $this->logError(
                    'RETRY_ATTEMPT',
                    "Retry attempt {$attempts} for operation '{$operationName}'",
                    [
                        'operation' => $operationName,
                        'attempt' => $attempts,
                        'error' => $e->getMessage(),
                        'delay_ms' => $delay
                    ]
                );

                usleep($delay * 1000); // Convert to microseconds
            }
        }

        // All attempts failed
        $this->logError(
            'RETRY_EXHAUSTED',
            "Operation '{$operationName}' failed after {$attempts} attempts",
            [
                'operation' => $operationName,
                'total_attempts' => $attempts,
                'final_error' => $lastError->getMessage()
            ]
        );

        throw $lastError;
    }

    /**
     * Get graceful degradation fallback data
     */
    public function getGracefulFallback($endpoint, $service = null)
    {
        $fallbackData = [
            '/api/dashboard-overview.php' => [
                'orders' => ['todayCount' => 0, 'todayTotal' => 0],
                'payments' => ['pendingSlips' => 0, 'processedToday' => 0],
                'webhooks' => ['successRate' => 0, 'totalEvents' => 0],
                '_degraded' => true,
                '_degradationReason' => 'Service temporarily unavailable'
            ],
            '/api/orders.php' => [
                'data' => [],
                'total' => 0,
                'page' => 1,
                '_degraded' => true,
                '_degradationReason' => 'Order service unavailable'
            ]
        ];

        return $fallbackData[$endpoint] ?? [
            '_degraded' => true,
            '_degradationReason' => 'Service temporarily unavailable',
            '_message' => 'Please try again later'
        ];
    }

    /**
     * Check if error is retryable
     */
    private function isRetryableError(Exception $e)
    {
        $retryablePatterns = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'unavailable',
            'overloaded'
        ];

        $message = strtolower($e->getMessage());
        
        foreach ($retryablePatterns as $pattern) {
            if (strpos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine error level based on error code
     */
    private function determineErrorLevel($code)
    {
        $criticalErrors = ['DATABASE_ERROR', 'CIRCUIT_BREAKER_OPEN'];
        $highErrors = ['EXTERNAL_SERVICE_ERROR', 'CACHE_ERROR'];
        $mediumErrors = ['WEBHOOK_PROCESSING_FAILED', 'SERVICE_UNAVAILABLE'];

        if (in_array($code, $criticalErrors)) {
            return 'critical';
        } elseif (in_array($code, $highErrors)) {
            return 'high';
        } elseif (in_array($code, $mediumErrors)) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Check error threshold and send alerts if needed
     */
    private function checkErrorThreshold($errorCode)
    {
        if (!$this->config['enable_notifications']) {
            return;
        }

        // Count recent errors of this type
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as error_count 
            FROM error_logs 
            WHERE code = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$errorCode]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['error_count'] >= $this->config['error_threshold']) {
            $this->sendErrorAlert($errorCode, $result['error_count']);
        }
    }

    /**
     * Send error alert notification
     */
    private function sendErrorAlert($errorCode, $count)
    {
        try {
            // Use existing notification system
            if (class_exists('NotificationRouter')) {
                $notificationRouter = new NotificationRouter($this->db);
                $message = "🚨 Error Alert: {$errorCode} has occurred {$count} times in the last hour";
                
                $notificationRouter->sendAlert([
                    'type' => 'error_threshold',
                    'severity' => 'high',
                    'message' => $message,
                    'details' => [
                        'errorCode' => $errorCode,
                        'count' => $count,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
            }

            // Log to dev_logs as fallback
            $this->fallbackLog('ERROR_ALERT', $message, [
                'errorCode' => $errorCode,
                'count' => $count
            ]);

        } catch (Exception $e) {
            error_log("Failed to send error alert: " . $e->getMessage());
        }
    }

    /**
     * Fallback logging to existing dev_logs table
     */
    private function fallbackLog($type, $message, $data = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO dev_logs (log_type, source, message, data, created_at)
                VALUES (?, 'ErrorHandlingBridge', ?, ?, NOW())
            ");

            $dataJson = $data ? json_encode($data) : null;
            $stmt->execute([$type, $message, $dataJson]);

        } catch (Exception $e) {
            error_log("Fallback logging failed: " . $e->getMessage());
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIP()
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * Generate UUID for error tracking
     */
    private function generateUUID()
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
     * Get error statistics for monitoring
     */
    public function getErrorStatistics($timeRange = '24 HOUR')
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    code,
                    level,
                    COUNT(*) as count,
                    MAX(timestamp) as latest_occurrence
                FROM error_logs 
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL {$timeRange})
                GROUP BY code, level
                ORDER BY count DESC, level DESC
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Failed to get error statistics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get service health summary
     */
    public function getServiceHealthSummary()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    service_name,
                    healthy,
                    degradation_level,
                    error_count,
                    last_check,
                    TIMESTAMPDIFF(MINUTE, last_check, NOW()) as minutes_since_check
                FROM service_health
                ORDER BY 
                    CASE degradation_level 
                        WHEN 'full' THEN 1 
                        WHEN 'partial' THEN 2 
                        WHEN 'none' THEN 3 
                    END,
                    service_name
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log("Failed to get service health: " . $e->getMessage());
            return [];
        }
    }
}