<?php
/**
 * Webhook Retry Processor
 * 
 * Processes webhooks that are scheduled for retry with exponential backoff.
 * This cron job should run every 5 minutes to handle failed webhook retries.
 * 
 * Schedule: */5 * * * * (every 5 minutes)
 * 
 * @version 1.0.0
 * @created 2026-01-23
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/WebhookLoggingService.php';

// Prevent multiple instances
$lockFile = __DIR__ . '/../tmp/webhook_retry_processor.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    echo "Another instance is already running. Exiting.\n";
    exit(0);
}
file_put_contents($lockFile, getmypid());

// Cleanup lock file on exit
register_shutdown_function(function() use ($lockFile) {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

try {
    $db = Database::getInstance()->getConnection();
    $startTime = microtime(true);
    
    echo "[" . date('Y-m-d H:i:s') . "] Starting webhook retry processor...\n";
    
    // Get webhooks ready for retry
    $webhookService = new WebhookLoggingService($db);
    $webhooksToRetry = $webhookService->getWebhooksForRetry();
    
    if (empty($webhooksToRetry)) {
        echo "No webhooks ready for retry.\n";
        exit(0);
    }
    
    echo "Found " . count($webhooksToRetry) . " webhooks ready for retry.\n";
    
    $successCount = 0;
    $failureCount = 0;
    $dlqCount = 0;
    
    foreach ($webhooksToRetry as $webhook) {
        $webhookId = $webhook['id'];
        $webhookType = $webhook['webhook_type'];
        $eventType = $webhook['event_type'];
        $payload = json_decode($webhook['payload'], true);
        $retryCount = (int) $webhook['retry_count'];
        
        echo "Processing webhook {$webhookId} (type: {$webhookType}, event: {$eventType}, retry: {$retryCount})...\n";
        
        try {
            // Update status to processing
            $webhookService->updateWebhookStatus($webhookId, WebhookLoggingService::STATUS_PROCESSING);
            
            // Process webhook based on type
            $result = processWebhookByType($db, $webhookType, $eventType, $payload, $webhook);
            
            if ($result['success']) {
                // Mark as processed
                $webhookService->updateWebhookStatus(
                    $webhookId, 
                    WebhookLoggingService::STATUS_PROCESSED,
                    null,
                    $result['data'] ?? []
                );
                $successCount++;
                echo "  ✓ Successfully processed\n";
            } else {
                // Mark as failed, will be scheduled for next retry or moved to DLQ
                $webhookService->updateWebhookStatus(
                    $webhookId, 
                    WebhookLoggingService::STATUS_FAILED,
                    $result['error'] ?? 'Unknown error'
                );
                $failureCount++;
                echo "  ✗ Failed: " . ($result['error'] ?? 'Unknown error') . "\n";
            }
            
        } catch (Exception $e) {
            // Mark as failed
            $webhookService->updateWebhookStatus(
                $webhookId, 
                WebhookLoggingService::STATUS_FAILED,
                $e->getMessage()
            );
            $failureCount++;
            echo "  ✗ Exception: " . $e->getMessage() . "\n";
        }
        
        // Small delay to prevent overwhelming the system
        usleep(100000); // 100ms
    }
    
    // Check for webhooks that should be moved to DLQ
    $dlqMoved = moveExhaustedWebhooksToDLQ($db, $webhookService);
    $dlqCount += $dlqMoved;
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo "\nRetry processing completed:\n";
    echo "  - Processed: {$successCount}\n";
    echo "  - Failed: {$failureCount}\n";
    echo "  - Moved to DLQ: {$dlqCount}\n";
    echo "  - Duration: {$duration}ms\n";
    
    // Log summary to database
    $stmt = $db->prepare("
        INSERT INTO dev_logs (log_type, source, message, data, created_at) 
        VALUES ('info', 'webhook_retry_processor', ?, ?, NOW())
    ");
    
    $stmt->execute([
        'Webhook retry processing completed',
        json_encode([
            'processed' => $successCount,
            'failed' => $failureCount,
            'dlq_moved' => $dlqCount,
            'duration_ms' => $duration,
            'total_webhooks' => count($webhooksToRetry)
        ], JSON_UNESCAPED_UNICODE)
    ]);
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    
    // Log error to database
    try {
        $stmt = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at) 
            VALUES ('error', 'webhook_retry_processor', ?, ?, NOW())
        ");
        
        $stmt->execute([
            'Webhook retry processor failed: ' . $e->getMessage(),
            json_encode([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $logError) {
        error_log("Failed to log webhook retry processor error: " . $logError->getMessage());
    }
    
    exit(1);
}

/**
 * Process webhook based on its type
 */
function processWebhookByType($db, $webhookType, $eventType, $payload, $webhook)
{
    switch ($webhookType) {
        case WebhookLoggingService::TYPE_ODOO:
            return processOdooWebhook($db, $eventType, $payload, $webhook);
            
        case WebhookLoggingService::TYPE_LINE:
            return processLineWebhook($db, $eventType, $payload, $webhook);
            
        case WebhookLoggingService::TYPE_PAYMENT:
            return processPaymentWebhook($db, $eventType, $payload, $webhook);
            
        case WebhookLoggingService::TYPE_DELIVERY:
            return processDeliveryWebhook($db, $eventType, $payload, $webhook);
            
        default:
            return ['success' => false, 'error' => "Unknown webhook type: {$webhookType}"];
    }
}

/**
 * Process Odoo webhook
 */
function processOdooWebhook($db, $eventType, $payload, $webhook)
{
    try {
        // Load Odoo webhook handler if available
        if (file_exists(__DIR__ . '/../api/odoo-webhook.php')) {
            // Simulate the webhook processing
            require_once __DIR__ . '/../api/odoo-webhook.php';
            
            $handler = new OdooWebhookHandler($db);
            $result = $handler->process($eventType, $payload);
            
            return ['success' => true, 'data' => $result];
        } else {
            return ['success' => false, 'error' => 'Odoo webhook handler not available'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process LINE webhook
 */
function processLineWebhook($db, $eventType, $payload, $webhook)
{
    try {
        // For LINE webhooks, we might need to replay the message processing
        // This is more complex as it involves user state and context
        
        // For now, just mark as processed since LINE webhooks are typically
        // one-time events that shouldn't be retried
        return ['success' => true, 'data' => ['note' => 'LINE webhook retry - marked as processed']];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process payment webhook
 */
function processPaymentWebhook($db, $eventType, $payload, $webhook)
{
    try {
        // Process payment-related webhooks
        // This might involve updating payment status, matching slips, etc.
        
        switch ($eventType) {
            case 'payment.confirmed':
                return processPaymentConfirmation($db, $payload);
                
            case 'payment.failed':
                return processPaymentFailure($db, $payload);
                
            default:
                return ['success' => false, 'error' => "Unknown payment event: {$eventType}"];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process delivery webhook
 */
function processDeliveryWebhook($db, $eventType, $payload, $webhook)
{
    try {
        // Process delivery-related webhooks
        // This might involve updating delivery status, notifying customers, etc.
        
        switch ($eventType) {
            case 'delivery.departed':
            case 'delivery.completed':
                return processDeliveryStatusUpdate($db, $eventType, $payload);
                
            default:
                return ['success' => false, 'error' => "Unknown delivery event: {$eventType}"];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process payment confirmation
 */
function processPaymentConfirmation($db, $payload)
{
    // Update payment status in database
    $paymentId = $payload['payment_id'] ?? null;
    if (!$paymentId) {
        return ['success' => false, 'error' => 'Missing payment_id'];
    }
    
    $stmt = $db->prepare("
        UPDATE odoo_payments 
        SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW()
        WHERE odoo_payment_id = ?
    ");
    
    $stmt->execute([$paymentId]);
    
    return ['success' => true, 'data' => ['payment_id' => $paymentId, 'status' => 'confirmed']];
}

/**
 * Process payment failure
 */
function processPaymentFailure($db, $payload)
{
    $paymentId = $payload['payment_id'] ?? null;
    $reason = $payload['failure_reason'] ?? 'Unknown';
    
    if (!$paymentId) {
        return ['success' => false, 'error' => 'Missing payment_id'];
    }
    
    $stmt = $db->prepare("
        UPDATE odoo_payments 
        SET status = 'failed', failure_reason = ?, failed_at = NOW(), updated_at = NOW()
        WHERE odoo_payment_id = ?
    ");
    
    $stmt->execute([$reason, $paymentId]);
    
    return ['success' => true, 'data' => ['payment_id' => $paymentId, 'status' => 'failed', 'reason' => $reason]];
}

/**
 * Process delivery status update
 */
function processDeliveryStatusUpdate($db, $eventType, $payload)
{
    $deliveryId = $payload['delivery_id'] ?? null;
    $orderIds = $payload['order_ids'] ?? [];
    
    if (!$deliveryId || empty($orderIds)) {
        return ['success' => false, 'error' => 'Missing delivery_id or order_ids'];
    }
    
    $status = ($eventType === 'delivery.departed') ? 'in_delivery' : 'delivered';
    
    // Update order statuses
    $placeholders = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $db->prepare("
        UPDATE odoo_orders 
        SET state = ?, state_display = ?, updated_at = NOW()
        WHERE odoo_order_id IN ({$placeholders})
    ");
    
    $params = array_merge([$status, ucfirst(str_replace('_', ' ', $status))], $orderIds);
    $stmt->execute($params);
    
    $updatedCount = $stmt->rowCount();
    
    return [
        'success' => true, 
        'data' => [
            'delivery_id' => $deliveryId, 
            'status' => $status, 
            'updated_orders' => $updatedCount
        ]
    ];
}

/**
 * Move webhooks that have exhausted retries to DLQ
 */
function moveExhaustedWebhooksToDLQ($db, $webhookService)
{
    $stmt = $db->prepare("
        SELECT id, retry_count 
        FROM odoo_webhooks_log 
        WHERE status = ? 
        AND COALESCE(retry_count, 0) >= 5
        AND dlq_at IS NULL
    ");
    
    $stmt->execute([WebhookLoggingService::STATUS_FAILED]);
    $exhaustedWebhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $movedCount = 0;
    foreach ($exhaustedWebhooks as $webhook) {
        $webhookService->moveToDeadLetterQueue(
            $webhook['id'], 
            "Max retries exceeded ({$webhook['retry_count']} attempts)"
        );
        $movedCount++;
    }
    
    return $movedCount;
}