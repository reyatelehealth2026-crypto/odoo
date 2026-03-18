<?php
/**
 * Webhook Logging and Monitoring Service
 * 
 * Comprehensive webhook event logging with detailed payload storage,
 * statistics calculation, and retry mechanisms for failed webhooks.
 * 
 * Features:
 * - Detailed webhook event logging with payload storage
 * - Statistics calculation for dashboard metrics
 * - Retry mechanism with exponential backoff
 * - Performance monitoring and alerting
 * - Support for different webhook types (Odoo, LINE, etc.)
 * 
 * @version 1.0.0
 * @created 2026-01-23
 */

class WebhookLoggingService
{
    private $db;
    private $lineAccountId;
    private $maxRetries;
    private $baseRetryDelay;
    
    // Webhook status constants
    const STATUS_RECEIVED = 'RECEIVED';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_PROCESSED = 'PROCESSED';
    const STATUS_FAILED = 'FAILED';
    const STATUS_RETRY = 'RETRY';
    const STATUS_DLQ = 'DLQ'; // Dead Letter Queue
    const STATUS_DUPLICATE = 'DUPLICATE';
    
    // Webhook types
    const TYPE_ODOO = 'odoo';
    const TYPE_LINE = 'line';
    const TYPE_PAYMENT = 'payment';
    const TYPE_DELIVERY = 'delivery';
    const TYPE_SYSTEM = 'system';
    
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->maxRetries = 5;
        $this->baseRetryDelay = 30; // seconds
    }
    
    /**
     * Log incoming webhook event
     * 
     * @param string $webhookType Type of webhook (odoo, line, payment, etc.)
     * @param string $eventType Specific event type (order.created, message.received, etc.)
     * @param array $payload Full webhook payload
     * @param array $metadata Additional metadata (headers, source IP, etc.)
     * @return string Webhook log ID
     */
    public function logWebhookEvent($webhookType, $eventType, $payload, $metadata = [])
    {
        $webhookId = $this->generateWebhookId();
        $startTime = microtime(true);
        
        try {
            // Check for duplicates
            if ($this->isDuplicateWebhook($webhookType, $eventType, $payload)) {
                $this->logDuplicateWebhook($webhookId, $webhookType, $eventType, $payload, $metadata);
                return $webhookId;
            }
            
            // Extract common fields from payload
            $extractedData = $this->extractCommonFields($webhookType, $payload);
            
            $stmt = $this->db->prepare("
                INSERT INTO odoo_webhooks_log (
                    id, webhook_type, event_type, status, 
                    line_account_id, line_user_id, order_id, invoice_id, payment_id,
                    delivery_id, customer_id, customer_name, customer_ref,
                    payload, metadata, 
                    received_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
            ");
            
            $stmt->execute([
                $webhookId,
                $webhookType,
                $eventType,
                self::STATUS_RECEIVED,
                $this->lineAccountId,
                $extractedData['line_user_id'] ?? null,
                $extractedData['order_id'] ?? null,
                $extractedData['invoice_id'] ?? null,
                $extractedData['payment_id'] ?? null,
                $extractedData['delivery_id'] ?? null,
                $extractedData['customer_id'] ?? null,
                $extractedData['customer_name'] ?? null,
                $extractedData['customer_ref'] ?? null,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                json_encode($metadata, JSON_UNESCAPED_UNICODE)
            ]);
            
            // Log performance metrics
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->updateProcessingMetrics($webhookId, $processingTime);
            
            return $webhookId;
            
        } catch (Exception $e) {
            error_log("WebhookLoggingService::logWebhookEvent failed: " . $e->getMessage());
            
            // Log to dev_logs as fallback
            $this->logToDevLogs('error', 'webhook_logging', $e->getMessage(), [
                'webhook_id' => $webhookId,
                'webhook_type' => $webhookType,
                'event_type' => $eventType
            ]);
            
            throw $e;
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
        $startTime = microtime(true);
        
        try {
            $updateFields = ['status = ?', 'updated_at = NOW()'];
            $params = [$status];
            
            if ($status === self::STATUS_PROCESSING) {
                $updateFields[] = 'processing_started_at = NOW()';
            } elseif ($status === self::STATUS_PROCESSED) {
                $updateFields[] = 'processed_at = NOW()';
                $updateFields[] = 'processing_completed_at = NOW()';
            } elseif ($status === self::STATUS_FAILED) {
                $updateFields[] = 'error_message = ?';
                $updateFields[] = 'failed_at = NOW()';
                $params[] = $errorMessage;
                
                // Increment retry count
                $updateFields[] = 'retry_count = COALESCE(retry_count, 0) + 1';
            }
            
            if (!empty($processingData)) {
                $updateFields[] = 'processing_data = ?';
                $params[] = json_encode($processingData, JSON_UNESCAPED_UNICODE);
            }
            
            $params[] = $webhookId;
            
            $stmt = $this->db->prepare("
                UPDATE odoo_webhooks_log 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            
            $stmt->execute($params);
            
            // Update processing metrics
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->updateProcessingMetrics($webhookId, $processingTime, 'status_update');
            
            // Handle retry logic for failed webhooks
            if ($status === self::STATUS_FAILED) {
                $this->handleFailedWebhook($webhookId);
            }
            
        } catch (Exception $e) {
            error_log("WebhookLoggingService::updateWebhookStatus failed: " . $e->getMessage());
            throw $e;
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
        $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo = $filters['date_to'] ?? date('Y-m-d');
        $webhookType = $filters['webhook_type'] ?? null;
        
        $whereConditions = ['DATE(created_at) BETWEEN ? AND ?'];
        $params = [$dateFrom, $dateTo];
        
        if ($this->lineAccountId) {
            $whereConditions[] = 'line_account_id = ?';
            $params[] = $this->lineAccountId;
        }
        
        if ($webhookType) {
            $whereConditions[] = 'webhook_type = ?';
            $params[] = $webhookType;
        }
        
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        
        // Overall statistics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = ? THEN 1 END) as processed,
                COUNT(CASE WHEN status = ? THEN 1 END) as failed,
                COUNT(CASE WHEN status = ? THEN 1 END) as retry,
                COUNT(CASE WHEN status = ? THEN 1 END) as dlq,
                COUNT(CASE WHEN status = ? THEN 1 END) as duplicate,
                COUNT(CASE WHEN status IN (?, ?) THEN 1 END) as pending,
                AVG(CASE WHEN process_latency_ms IS NOT NULL THEN process_latency_ms END) as avg_processing_time,
                MAX(created_at) as last_event_at
            FROM odoo_webhooks_log 
            {$whereClause}
        ");
        
        $stmt->execute(array_merge($params, [
            self::STATUS_PROCESSED,
            self::STATUS_FAILED,
            self::STATUS_RETRY,
            self::STATUS_DLQ,
            self::STATUS_DUPLICATE,
            self::STATUS_RECEIVED,
            self::STATUS_PROCESSING
        ]));
        
        $overall = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Success rate calculation
        $successRate = $overall['total'] > 0 
            ? round(($overall['processed'] / $overall['total']) * 100, 2) 
            : 0;
        
        // Event type breakdown
        $stmt = $this->db->prepare("
            SELECT 
                event_type,
                COUNT(*) as count,
                COUNT(CASE WHEN status = ? THEN 1 END) as processed,
                COUNT(CASE WHEN status = ? THEN 1 END) as failed
            FROM odoo_webhooks_log 
            {$whereClause}
            GROUP BY event_type
            ORDER BY count DESC
            LIMIT 10
        ");
        
        $stmt->execute(array_merge($params, [self::STATUS_PROCESSED, self::STATUS_FAILED]));
        $eventTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Hourly distribution for today
        $stmt = $this->db->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as count,
                COUNT(CASE WHEN status = ? THEN 1 END) as processed,
                COUNT(CASE WHEN status = ? THEN 1 END) as failed
            FROM odoo_webhooks_log 
            WHERE DATE(created_at) = CURDATE()
            " . ($this->lineAccountId ? "AND line_account_id = {$this->lineAccountId}" : "") . "
            " . ($webhookType ? "AND webhook_type = '{$webhookType}'" : "") . "
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        
        $stmt->execute([self::STATUS_PROCESSED, self::STATUS_FAILED]);
        $hourlyDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'overall' => array_merge($overall, ['success_rate' => $successRate]),
            'event_types' => $eventTypes,
            'hourly_distribution' => $hourlyDistribution,
            'date_range' => ['from' => $dateFrom, 'to' => $dateTo]
        ];
    }
    
    /**
     * Retry failed webhook
     * 
     * @param string $webhookId Webhook log ID
     * @return bool Success status
     */
    public function retryWebhook($webhookId)
    {
        try {
            // Get webhook details
            $stmt = $this->db->prepare("
                SELECT id, webhook_type, event_type, payload, retry_count, 
                       COALESCE(retry_count, 0) as current_retry_count
                FROM odoo_webhooks_log 
                WHERE id = ? AND status IN (?, ?)
            ");
            
            $stmt->execute([$webhookId, self::STATUS_FAILED, self::STATUS_RETRY]);
            $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$webhook) {
                throw new Exception("Webhook not found or not in retryable state");
            }
            
            // Check retry limit
            if ($webhook['current_retry_count'] >= $this->maxRetries) {
                $this->moveToDeadLetterQueue($webhookId, 'Max retries exceeded');
                return false;
            }
            
            // Calculate retry delay with exponential backoff
            $retryDelay = $this->calculateRetryDelay($webhook['current_retry_count']);
            
            // Update status to retry with next retry time
            $stmt = $this->db->prepare("
                UPDATE odoo_webhooks_log 
                SET status = ?, 
                    next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    retry_count = COALESCE(retry_count, 0) + 1,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([self::STATUS_RETRY, $retryDelay, $webhookId]);
            
            // Log retry attempt
            $this->logToDevLogs('info', 'webhook_retry', "Webhook retry scheduled", [
                'webhook_id' => $webhookId,
                'retry_count' => $webhook['current_retry_count'] + 1,
                'retry_delay' => $retryDelay
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("WebhookLoggingService::retryWebhook failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get webhooks ready for retry
     * 
     * @return array List of webhooks ready for retry
     */
    public function getWebhooksForRetry()
    {
        $stmt = $this->db->prepare("
            SELECT id, webhook_type, event_type, payload, retry_count
            FROM odoo_webhooks_log 
            WHERE status = ? 
            AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            AND COALESCE(retry_count, 0) < ?
            ORDER BY next_retry_at ASC, created_at ASC
            LIMIT 50
        ");
        
        $stmt->execute([self::STATUS_RETRY, $this->maxRetries]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Move webhook to Dead Letter Queue
     * 
     * @param string $webhookId Webhook log ID
     * @param string $reason Reason for moving to DLQ
     */
    public function moveToDeadLetterQueue($webhookId, $reason)
    {
        $stmt = $this->db->prepare("
            UPDATE odoo_webhooks_log 
            SET status = ?, 
                dlq_reason = ?,
                dlq_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([self::STATUS_DLQ, $reason, $webhookId]);
        
        // Log DLQ event
        $this->logToDevLogs('warning', 'webhook_dlq', "Webhook moved to DLQ", [
            'webhook_id' => $webhookId,
            'reason' => $reason
        ]);
    }
    
    /**
     * Get Dead Letter Queue items
     * 
     * @param array $filters Filters for DLQ items
     * @return array DLQ items
     */
    public function getDeadLetterQueueItems($filters = [])
    {
        $limit = min((int) ($filters['limit'] ?? 50), 200);
        $offset = max((int) ($filters['offset'] ?? 0), 0);
        
        $whereConditions = ['status = ?'];
        $params = [self::STATUS_DLQ];
        
        if ($this->lineAccountId) {
            $whereConditions[] = 'line_account_id = ?';
            $params[] = $this->lineAccountId;
        }
        
        if (!empty($filters['webhook_type'])) {
            $whereConditions[] = 'webhook_type = ?';
            $params[] = $filters['webhook_type'];
        }
        
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        
        // Get total count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM odoo_webhooks_log {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int) $countStmt->fetchColumn();
        
        // Get items
        $stmt = $this->db->prepare("
            SELECT id, webhook_type, event_type, error_message, dlq_reason,
                   retry_count, created_at, dlq_at,
                   JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) as order_name,
                   JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.name')) as customer_name
            FROM odoo_webhooks_log 
            {$whereClause}
            ORDER BY dlq_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'items' => $items,
            'total' => $totalCount,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Clean up old webhook logs
     * 
     * @param int $daysToKeep Number of days to keep logs
     * @return int Number of deleted records
     */
    public function cleanupOldLogs($daysToKeep = 90)
    {
        // Only delete processed webhooks older than specified days
        $stmt = $this->db->prepare("
            DELETE FROM odoo_webhooks_log 
            WHERE status = ? 
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $stmt->execute([self::STATUS_PROCESSED, $daysToKeep]);
        $deletedCount = $stmt->rowCount();
        
        if ($deletedCount > 0) {
            $this->logToDevLogs('info', 'webhook_cleanup', "Cleaned up old webhook logs", [
                'deleted_count' => $deletedCount,
                'days_to_keep' => $daysToKeep
            ]);
        }
        
        return $deletedCount;
    }
    
    /**
     * Generate unique webhook ID
     * 
     * @return string Unique webhook ID
     */
    private function generateWebhookId()
    {
        return 'wh_' . date('Ymd_His') . '_' . substr(uniqid(), -6) . '_' . mt_rand(100, 999);
    }
    
    /**
     * Check if webhook is duplicate
     * 
     * @param string $webhookType Webhook type
     * @param string $eventType Event type
     * @param array $payload Webhook payload
     * @return bool True if duplicate
     */
    private function isDuplicateWebhook($webhookType, $eventType, $payload)
    {
        // Generate content hash for duplicate detection
        $contentHash = $this->generateContentHash($webhookType, $eventType, $payload);
        
        $stmt = $this->db->prepare("
            SELECT id FROM odoo_webhooks_log 
            WHERE webhook_type = ? 
            AND event_type = ? 
            AND content_hash = ?
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 1
        ");
        
        $stmt->execute([$webhookType, $eventType, $contentHash]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Log duplicate webhook
     */
    private function logDuplicateWebhook($webhookId, $webhookType, $eventType, $payload, $metadata)
    {
        $extractedData = $this->extractCommonFields($webhookType, $payload);
        $contentHash = $this->generateContentHash($webhookType, $eventType, $payload);
        
        $stmt = $this->db->prepare("
            INSERT INTO odoo_webhooks_log (
                id, webhook_type, event_type, status, content_hash,
                line_account_id, line_user_id, order_id,
                payload, metadata, 
                received_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        
        $stmt->execute([
            $webhookId,
            $webhookType,
            $eventType,
            self::STATUS_DUPLICATE,
            $contentHash,
            $this->lineAccountId,
            $extractedData['line_user_id'] ?? null,
            $extractedData['order_id'] ?? null,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            json_encode($metadata, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    /**
     * Generate content hash for duplicate detection
     */
    private function generateContentHash($webhookType, $eventType, $payload)
    {
        // Create hash based on key identifying fields
        $hashData = [
            'type' => $webhookType,
            'event' => $eventType,
            'order_id' => $payload['order_id'] ?? $payload['data']['order_id'] ?? null,
            'delivery_id' => $payload['delivery_id'] ?? null,
            'timestamp' => $payload['timestamp'] ?? null
        ];
        
        return hash('sha256', json_encode($hashData, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Extract common fields from webhook payload
     */
    private function extractCommonFields($webhookType, $payload)
    {
        $data = [];
        
        switch ($webhookType) {
            case self::TYPE_ODOO:
                $data['line_user_id'] = $payload['customer']['line_user_id'] ?? 
                                       $payload['data']['customer']['line_user_id'] ?? null;
                $data['order_id'] = $payload['order_id'] ?? 
                                   $payload['data']['order_id'] ?? null;
                $data['invoice_id'] = $payload['invoice_id'] ?? 
                                     $payload['data']['invoice_id'] ?? null;
                $data['payment_id'] = $payload['payment_id'] ?? 
                                     $payload['data']['payment_id'] ?? null;
                $data['delivery_id'] = $payload['delivery_id'] ?? null;
                $data['customer_id'] = $payload['customer']['id'] ?? 
                                      $payload['data']['customer']['id'] ?? null;
                $data['customer_name'] = $payload['customer']['name'] ?? 
                                        $payload['data']['customer']['name'] ?? null;
                $data['customer_ref'] = $payload['customer']['ref'] ?? 
                                       $payload['data']['customer']['ref'] ?? null;
                break;
                
            case self::TYPE_LINE:
                $data['line_user_id'] = $payload['events'][0]['source']['userId'] ?? 
                                       $payload['source']['userId'] ?? null;
                break;
                
            default:
                // Generic extraction
                $data['line_user_id'] = $payload['line_user_id'] ?? null;
                $data['order_id'] = $payload['order_id'] ?? null;
                break;
        }
        
        return $data;
    }
    
    /**
     * Handle failed webhook
     */
    private function handleFailedWebhook($webhookId)
    {
        // Get current retry count
        $stmt = $this->db->prepare("
            SELECT COALESCE(retry_count, 0) as retry_count 
            FROM odoo_webhooks_log 
            WHERE id = ?
        ");
        
        $stmt->execute([$webhookId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['retry_count'] >= $this->maxRetries) {
            $this->moveToDeadLetterQueue($webhookId, 'Max retries exceeded');
        } else {
            // Schedule for retry
            $retryDelay = $this->calculateRetryDelay($result['retry_count'] ?? 0);
            
            $stmt = $this->db->prepare("
                UPDATE odoo_webhooks_log 
                SET status = ?, 
                    next_retry_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([self::STATUS_RETRY, $retryDelay, $webhookId]);
        }
    }
    
    /**
     * Calculate retry delay with exponential backoff
     */
    private function calculateRetryDelay($retryCount)
    {
        // Exponential backoff: base_delay * (2 ^ retry_count) + jitter
        $delay = $this->baseRetryDelay * pow(2, $retryCount);
        $jitter = mt_rand(0, min($delay * 0.1, 30)); // Max 30 seconds jitter
        
        return min($delay + $jitter, 3600); // Max 1 hour delay
    }
    
    /**
     * Update processing metrics
     */
    private function updateProcessingMetrics($webhookId, $processingTime, $operation = 'main')
    {
        $stmt = $this->db->prepare("
            UPDATE odoo_webhooks_log 
            SET process_latency_ms = COALESCE(process_latency_ms, 0) + ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([$processingTime, $webhookId]);
    }
    
    /**
     * Log to dev_logs table
     */
    private function logToDevLogs($logType, $source, $message, $data = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO dev_logs (log_type, source, message, data, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $logType,
                $source,
                $message,
                $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log to dev_logs: " . $e->getMessage());
        }
    }
}