<?php
/**
 * Notification Queue
 * 
 * Manages async notification queue with retry support
 * Handles queuing, processing, and retry logic
 */

class NotificationQueue
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Queue notification for async processing
     */
    public function enqueue($notification)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_queue
                (delivery_id, event_type, order_id, order_ref, recipient_type, 
                 line_user_id, line_account_id, message_type, message_payload, 
                 alt_text, batch_group_id, is_batched, priority, scheduled_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $scheduledAt = $notification['scheduled_at'] ?? date('Y-m-d H:i:s');
            $expiresAt = $notification['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'));
            
            $stmt->execute([
                $notification['delivery_id'],
                $notification['event_type'],
                $notification['order_id'] ?? null,
                $notification['order_ref'] ?? null,
                $notification['recipient_type'],
                $notification['line_user_id'],
                $notification['line_account_id'] ?? null,
                $notification['message_type'],
                json_encode($notification['message_payload']),
                $notification['alt_text'] ?? null,
                $notification['batch_group_id'] ?? null,
                $notification['is_batched'] ?? false,
                $notification['priority'] ?? 5,
                $scheduledAt,
                $expiresAt
            ]);
            
            return $this->db->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Error enqueueing notification: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Re-throw in development to see actual error
            if (defined('DEBUG') && DEBUG) {
                throw $e;
            }
            return null;
        }
    }
    
    /**
     * Get pending notifications ready to send
     */
    public function getPending($limit = 10)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_queue
                WHERE status = 'pending'
                  AND scheduled_at <= NOW()
                  AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY priority ASC, scheduled_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($notifications as &$notif) {
                if (!empty($notif['message_payload'])) {
                    $notif['message_payload'] = json_decode($notif['message_payload'], true);
                }
            }
            
            return $notifications;
            
        } catch (Exception $e) {
            error_log("Error getting pending notifications: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark notification as processing
     */
    public function markProcessing($queueId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_queue
                SET status = 'processing',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$queueId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error marking notification processing: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark notification as sent
     */
    public function markSent($queueId, $response)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_queue
                SET status = 'sent',
                    sent_at = NOW(),
                    line_api_status = ?,
                    line_api_response = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $response['status'] ?? 200,
                json_encode($response),
                $queueId
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error marking notification sent: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark notification as failed
     */
    public function markFailed($queueId, $error, $shouldRetry = false)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT retry_count, max_retries FROM odoo_notification_queue
                WHERE id = ?
            ");
            $stmt->execute([$queueId]);
            $notif = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$notif) {
                return false;
            }
            
            $retryCount = $notif['retry_count'] + 1;
            $maxRetries = $notif['max_retries'];
            
            if ($shouldRetry && $retryCount < $maxRetries) {
                $nextRetryDelay = $this->getRetryDelay($retryCount);
                $scheduledAt = date('Y-m-d H:i:s', time() + $nextRetryDelay);
                
                $stmt = $this->db->prepare("
                    UPDATE odoo_notification_queue
                    SET status = 'pending',
                        retry_count = ?,
                        scheduled_at = ?,
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $retryCount,
                    $scheduledAt,
                    $error,
                    $queueId
                ]);
                
                error_log("Notification {$queueId} scheduled for retry {$retryCount}/{$maxRetries} in {$nextRetryDelay}s");
                
            } else {
                $stmt = $this->db->prepare("
                    UPDATE odoo_notification_queue
                    SET status = 'failed',
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([$error, $queueId]);
                
                error_log("Notification {$queueId} marked as failed after {$retryCount} attempts");
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error marking notification failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get retry delay based on attempt count
     */
    private function getRetryDelay($retryCount)
    {
        $delays = [60, 300, 900]; // 1min, 5min, 15min
        $index = min($retryCount - 1, count($delays) - 1);
        return $delays[$index];
    }
    
    /**
     * Retry failed notifications
     */
    public function retryFailed($limit = 10)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_queue
                WHERE status = 'failed'
                  AND retry_count < max_retries
                  AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY updated_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($notifications as $notif) {
                $this->db->prepare("
                    UPDATE odoo_notification_queue
                    SET status = 'pending',
                        scheduled_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ")->execute([$notif['id']]);
            }
            
            return count($notifications);
            
        } catch (Exception $e) {
            error_log("Error retrying failed notifications: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Expire old notifications
     */
    public function expireOld()
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_queue
                SET status = 'expired',
                    updated_at = NOW()
                WHERE status IN ('pending', 'processing')
                  AND expires_at IS NOT NULL
                  AND expires_at < NOW()
            ");
            $stmt->execute();
            
            $expiredCount = $stmt->rowCount();
            
            if ($expiredCount > 0) {
                error_log("Expired {$expiredCount} old notifications");
            }
            
            return $expiredCount;
            
        } catch (Exception $e) {
            error_log("Error expiring old notifications: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Cancel notification
     */
    public function cancel($queueId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_queue
                SET status = 'cancelled',
                    updated_at = NOW()
                WHERE id = ?
                  AND status IN ('pending', 'processing')
            ");
            $stmt->execute([$queueId]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log("Error cancelling notification: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getStats()
    {
        try {
            $stmt = $this->db->query("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(retry_count) as avg_retries
                FROM odoo_notification_queue
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY status
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting queue stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean up old completed notifications
     */
    public function cleanup($daysOld = 7)
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM odoo_notification_queue
                WHERE status IN ('sent', 'expired', 'cancelled', 'failed')
                  AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysOld]);
            
            $deletedCount = $stmt->rowCount();
            
            if ($deletedCount > 0) {
                error_log("Cleaned up {$deletedCount} old notifications");
            }
            
            return $deletedCount;
            
        } catch (Exception $e) {
            error_log("Error cleaning up notifications: " . $e->getMessage());
            return 0;
        }
    }
}
