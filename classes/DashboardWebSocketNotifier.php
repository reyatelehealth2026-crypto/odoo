<?php

/**
 * Dashboard WebSocket Notifier
 * 
 * Integrates with the dashboard WebSocket server to send real-time updates
 * from PHP backend to connected dashboard clients.
 * 
 * Requirements: FR-1.4, BR-3.3
 */
class DashboardWebSocketNotifier
{
    private $redis;
    private $lineAccountId;
    private $logger;

    public function __construct($lineAccountId)
    {
        $this->lineAccountId = $lineAccountId;
        $this->initializeRedis();
        $this->logger = new ActivityLogger();
    }

    /**
     * Initialize Redis connection for publishing updates
     */
    private function initializeRedis()
    {
        try {
            $this->redis = new Redis();
            $redisHost = $_ENV['REDIS_HOST'] ?? 'localhost';
            $redisPort = $_ENV['REDIS_PORT'] ?? 6379;
            $redisPassword = $_ENV['REDIS_PASSWORD'] ?? null;

            $this->redis->connect($redisHost, $redisPort);
            
            if ($redisPassword) {
                $this->redis->auth($redisPassword);
            }

            // Set timeout
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, 5);
        } catch (Exception $e) {
            error_log("Failed to initialize Redis for dashboard notifications: " . $e->getMessage());
            $this->redis = null;
        }
    }

    /**
     * Broadcast dashboard metrics update
     */
    public function broadcastMetricsUpdate($metrics)
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $updateData = [
                'line_account_id' => $this->lineAccountId,
                'type' => 'metrics_updated',
                'payload' => $metrics,
                'timestamp' => time() * 1000 // JavaScript timestamp
            ];

            $result = $this->redis->publish('dashboard_updates', json_encode($updateData));
            
            $this->logger->log('dashboard_websocket', 'metrics_update_sent', [
                'line_account_id' => $this->lineAccountId,
                'subscribers' => $result
            ]);

            return $result > 0;
        } catch (Exception $e) {
            error_log("Failed to broadcast dashboard metrics update: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast order status change
     */
    public function broadcastOrderStatusChange($orderId, $oldStatus, $newStatus, $updatedBy, $orderData = [])
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $updateData = [
                'line_account_id' => $this->lineAccountId,
                'type' => 'order_status_changed',
                'payload' => [
                    'orderId' => $orderId,
                    'oldStatus' => $oldStatus,
                    'newStatus' => $newStatus,
                    'updatedBy' => $updatedBy,
                    'updatedAt' => date('c'),
                    'orderData' => $orderData
                ],
                'timestamp' => time() * 1000
            ];

            $result = $this->redis->publish('dashboard_updates', json_encode($updateData));
            
            $this->logger->log('dashboard_websocket', 'order_status_change_sent', [
                'line_account_id' => $this->lineAccountId,
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'subscribers' => $result
            ]);

            return $result > 0;
        } catch (Exception $e) {
            error_log("Failed to broadcast order status change: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast payment processed update
     */
    public function broadcastPaymentProcessed($paymentId, $orderId, $amount, $status, $processedBy, $matchingRate = null)
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $updateData = [
                'line_account_id' => $this->lineAccountId,
                'type' => 'payment_processed',
                'payload' => [
                    'paymentId' => $paymentId,
                    'orderId' => $orderId,
                    'amount' => $amount,
                    'status' => $status,
                    'processedBy' => $processedBy,
                    'processedAt' => date('c'),
                    'matchingRate' => $matchingRate
                ],
                'timestamp' => time() * 1000
            ];

            $result = $this->redis->publish('dashboard_updates', json_encode($updateData));
            
            $this->logger->log('dashboard_websocket', 'payment_processed_sent', [
                'line_account_id' => $this->lineAccountId,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'amount' => $amount,
                'status' => $status,
                'subscribers' => $result
            ]);

            return $result > 0;
        } catch (Exception $e) {
            error_log("Failed to broadcast payment processed update: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast webhook received update
     */
    public function broadcastWebhookReceived($webhookId, $type, $status, $responseTime, $payload = null)
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $updateData = [
                'line_account_id' => $this->lineAccountId,
                'type' => 'webhook_received',
                'payload' => [
                    'webhookId' => $webhookId,
                    'type' => $type,
                    'status' => $status,
                    'responseTime' => $responseTime,
                    'receivedAt' => date('c'),
                    'payload' => $payload
                ],
                'timestamp' => time() * 1000
            ];

            $result = $this->redis->publish('dashboard_updates', json_encode($updateData));
            
            $this->logger->log('dashboard_websocket', 'webhook_received_sent', [
                'line_account_id' => $this->lineAccountId,
                'webhook_id' => $webhookId,
                'type' => $type,
                'status' => $status,
                'subscribers' => $result
            ]);

            return $result > 0;
        } catch (Exception $e) {
            error_log("Failed to broadcast webhook received update: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Broadcast custom dashboard event
     */
    public function broadcastCustomEvent($eventType, $eventData)
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $updateData = [
                'line_account_id' => $this->lineAccountId,
                'type' => $eventType,
                'payload' => $eventData,
                'timestamp' => time() * 1000
            ];

            $result = $this->redis->publish('dashboard_updates', json_encode($updateData));
            
            $this->logger->log('dashboard_websocket', 'custom_event_sent', [
                'line_account_id' => $this->lineAccountId,
                'event_type' => $eventType,
                'subscribers' => $result
            ]);

            return $result > 0;
        } catch (Exception $e) {
            error_log("Failed to broadcast custom dashboard event: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Test Redis connection
     */
    public function testConnection()
    {
        if (!$this->redis) {
            return false;
        }

        try {
            return $this->redis->ping() === '+PONG';
        } catch (Exception $e) {
            error_log("Dashboard WebSocket Redis connection test failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get connection statistics
     */
    public function getConnectionStats()
    {
        if (!$this->redis) {
            return null;
        }

        try {
            // Get number of subscribers to dashboard_updates channel
            $subscribers = $this->redis->pubsub('numsub', 'dashboard_updates');
            
            return [
                'channel' => 'dashboard_updates',
                'subscribers' => isset($subscribers[1]) ? $subscribers[1] : 0,
                'redis_connected' => true
            ];
        } catch (Exception $e) {
            error_log("Failed to get dashboard WebSocket connection stats: " . $e->getMessage());
            return [
                'channel' => 'dashboard_updates',
                'subscribers' => 0,
                'redis_connected' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Close Redis connection
     */
    public function __destruct()
    {
        if ($this->redis) {
            try {
                $this->redis->close();
            } catch (Exception $e) {
                // Ignore errors during cleanup
            }
        }
    }
}