<?php
/**
 * Webhook Integration Helper
 * 
 * Helper class to integrate the new WebhookLoggingService with existing
 * webhook handlers and provide backward compatibility.
 * 
 * @version 1.0.0
 * @created 2026-01-23
 */

class WebhookIntegrationHelper
{
    private $db;
    private $loggingService;
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        
        // Load WebhookLoggingService if available
        if (class_exists('WebhookLoggingService')) {
            $this->loggingService = new WebhookLoggingService($db, $lineAccountId);
        }
    }
    
    /**
     * Log webhook event with enhanced monitoring
     * 
     * @param string $webhookType Type of webhook (odoo, line, payment, etc.)
     * @param string $eventType Specific event type
     * @param array $payload Webhook payload
     * @param array $metadata Additional metadata (headers, IP, etc.)
     * @return string|null Webhook log ID
     */
    public function logWebhookEvent($webhookType, $eventType, $payload, $metadata = [])
    {
        if ($this->loggingService) {
            try {
                return $this->loggingService->logWebhookEvent($webhookType, $eventType, $payload, $metadata);
            } catch (Exception $e) {
                error_log("WebhookIntegrationHelper::logWebhookEvent failed: " . $e->getMessage());
                // Fall back to basic logging
                return $this->basicWebhookLog($webhookType, $eventType, $payload);
            }
        } else {
            // Fall back to basic logging if service not available
            return $this->basicWebhookLog($webhookType, $eventType, $payload);
        }
    }
    
    /**
     * Update webhook processing status
     * 
     * @param string $webhookId Webhook log ID
     * @param string $status New status
     * @param string|null $errorMessage Error message if failed
     * @param array $processingData Additional processing data
     */
    public function updateWebhookStatus($webhookId, $status, $errorMessage = null, $processingData = [])
    {
        if ($this->loggingService && $webhookId) {
            try {
                $this->loggingService->updateWebhookStatus($webhookId, $status, $errorMessage, $processingData);
            } catch (Exception $e) {
                error_log("WebhookIntegrationHelper::updateWebhookStatus failed: " . $e->getMessage());
                // Fall back to basic status update
                $this->basicStatusUpdate($webhookId, $status, $errorMessage);
            }
        } elseif ($webhookId) {
            // Fall back to basic status update
            $this->basicStatusUpdate($webhookId, $status, $errorMessage);
        }
    }
    
    /**
     * Get webhook statistics for dashboard
     * 
     * @param array $filters Date range and other filters
     * @return array Statistics data
     */
    public function getWebhookStatistics($filters = [])
    {
        if ($this->loggingService) {
            try {
                return $this->loggingService->getWebhookStatistics($filters);
            } catch (Exception $e) {
                error_log("WebhookIntegrationHelper::getWebhookStatistics failed: " . $e->getMessage());
                return $this->basicWebhookStats($filters);
            }
        } else {
            return $this->basicWebhookStats($filters);
        }
    }
    
    /**
     * Retry failed webhook
     * 
     * @param string $webhookId Webhook log ID
     * @return bool Success status
     */
    public function retryWebhook($webhookId)
    {
        if ($this->loggingService) {
            try {
                return $this->loggingService->retryWebhook($webhookId);
            } catch (Exception $e) {
                error_log("WebhookIntegrationHelper::retryWebhook failed: " . $e->getMessage());
                return false;
            }
        }
        return false;
    }
    
    /**
     * Extract metadata from current request
     * 
     * @return array Request metadata
     */
    public static function extractRequestMetadata()
    {
        return [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'headers' => self::getRelevantHeaders(),
            'timestamp' => date('c'),
            'server_name' => $_SERVER['SERVER_NAME'] ?? null
        ];
    }
    
    /**
     * Get relevant HTTP headers for logging
     * 
     * @return array Filtered headers
     */
    private static function getRelevantHeaders()
    {
        $relevantHeaders = [
            'HTTP_X_LINE_SIGNATURE',
            'HTTP_X_ODOO_SIGNATURE',
            'HTTP_X_ODOO_TIMESTAMP',
            'HTTP_X_ODOO_EVENT',
            'HTTP_CONTENT_TYPE',
            'HTTP_CONTENT_LENGTH',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP'
        ];
        
        $headers = [];
        foreach ($relevantHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $headers[strtolower(str_replace('HTTP_', '', $header))] = $_SERVER[$header];
            }
        }
        
        return $headers;
    }
    
    /**
     * Basic webhook logging fallback
     */
    private function basicWebhookLog($webhookType, $eventType, $payload)
    {
        try {
            $webhookId = 'wh_' . date('Ymd_His') . '_' . substr(uniqid(), -6);
            
            $stmt = $this->db->prepare("
                INSERT INTO odoo_webhooks_log (
                    id, webhook_type, event_type, status, payload, 
                    received_at, created_at, updated_at
                ) VALUES (?, ?, ?, 'RECEIVED', ?, NOW(), NOW(), NOW())
            ");
            
            $stmt->execute([
                $webhookId,
                $webhookType,
                $eventType,
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            ]);
            
            return $webhookId;
            
        } catch (Exception $e) {
            error_log("Basic webhook logging failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Basic status update fallback
     */
    private function basicStatusUpdate($webhookId, $status, $errorMessage = null)
    {
        try {
            $updateFields = ['status = ?', 'updated_at = NOW()'];
            $params = [$status];
            
            if ($status === 'PROCESSED') {
                $updateFields[] = 'processed_at = NOW()';
            } elseif ($status === 'FAILED' && $errorMessage) {
                $updateFields[] = 'error_message = ?';
                $params[] = $errorMessage;
            }
            
            $params[] = $webhookId;
            
            $stmt = $this->db->prepare("
                UPDATE odoo_webhooks_log 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            
            $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("Basic status update failed: " . $e->getMessage());
        }
    }
    
    /**
     * Basic webhook statistics fallback
     */
    private function basicWebhookStats($filters = [])
    {
        try {
            $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $filters['date_to'] ?? date('Y-m-d');
            
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed,
                    COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed,
                    COUNT(CASE WHEN status = 'RETRY' THEN 1 END) as retry,
                    COUNT(CASE WHEN status = 'DLQ' THEN 1 END) as dlq,
                    MAX(created_at) as last_event_at
                FROM odoo_webhooks_log 
                WHERE DATE(created_at) BETWEEN ? AND ?
            ");
            
            $stmt->execute([$dateFrom, $dateTo]);
            $overall = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $successRate = $overall['total'] > 0 
                ? round(($overall['processed'] / $overall['total']) * 100, 2) 
                : 0;
            
            return [
                'overall' => array_merge($overall, ['success_rate' => $successRate]),
                'event_types' => [],
                'hourly_distribution' => [],
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo]
            ];
            
        } catch (Exception $e) {
            error_log("Basic webhook stats failed: " . $e->getMessage());
            return [
                'overall' => ['total' => 0, 'processed' => 0, 'failed' => 0, 'success_rate' => 0],
                'event_types' => [],
                'hourly_distribution' => [],
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo]
            ];
        }
    }
    
    /**
     * Check if enhanced logging is available
     * 
     * @return bool True if WebhookLoggingService is available
     */
    public function isEnhancedLoggingAvailable()
    {
        return $this->loggingService !== null;
    }
    
    /**
     * Get webhook processing metrics
     * 
     * @param array $filters Optional filters
     * @return array Processing metrics
     */
    public function getProcessingMetrics($filters = [])
    {
        try {
            $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-24 hours'));
            $dateTo = $filters['date_to'] ?? date('Y-m-d H:i:s');
            
            $stmt = $this->db->prepare("
                SELECT 
                    AVG(process_latency_ms) as avg_processing_time,
                    MIN(process_latency_ms) as min_processing_time,
                    MAX(process_latency_ms) as max_processing_time,
                    COUNT(CASE WHEN process_latency_ms IS NOT NULL THEN 1 END) as measured_count,
                    COUNT(CASE WHEN process_latency_ms > 5000 THEN 1 END) as slow_count
                FROM odoo_webhooks_log 
                WHERE created_at BETWEEN ? AND ?
                AND process_latency_ms IS NOT NULL
            ");
            
            $stmt->execute([$dateFrom, $dateTo]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            
        } catch (Exception $e) {
            error_log("Get processing metrics failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent webhook errors
     * 
     * @param int $limit Number of errors to return
     * @return array Recent errors
     */
    public function getRecentErrors($limit = 10)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, webhook_type, event_type, error_message, 
                       last_error_code, retry_count, created_at
                FROM odoo_webhooks_log 
                WHERE status IN ('FAILED', 'DLQ')
                AND error_message IS NOT NULL
                ORDER BY created_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get recent errors failed: " . $e->getMessage());
            return [];
        }
    }
}