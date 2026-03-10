<?php
/**
 * Notification Batcher
 * 
 * Manages batching of notifications for roadmap messages
 * Collects events within a time window and sends combined roadmap at milestone
 */

class NotificationBatcher
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Add event to batch
     * Creates or updates batch group for the order
     */
    public function addEvent($orderId, $lineUserId, $eventType, $eventData)
    {
        try {
            $batchGroup = $this->getBatchGroup($orderId, $lineUserId);
            
            if (!$batchGroup) {
                $batchGroupId = $this->createBatchGroup($orderId, $lineUserId, $eventType, $eventData);
            } else {
                $batchGroupId = $batchGroup['batch_group_id'];
                $this->updateBatchGroup($batchGroupId, $eventType, $eventData);
            }
            
            return $batchGroupId;
            
        } catch (Exception $e) {
            error_log("Error adding event to batch: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create new batch group
     */
    private function createBatchGroup($orderId, $lineUserId, $eventType, $eventData)
    {
        $batchGroupId = "batch_{$orderId}_{$lineUserId}_" . time();
        $orderRef = $eventData['order_ref'] ?? $eventData['order_name'] ?? "SO{$orderId}";
        
        $windowSeconds = 300; // 5 minutes default
        $windowExpires = date('Y-m-d H:i:s', time() + $windowSeconds);
        
        $eventTypes = json_encode([$eventType]);
        $eventDataJson = json_encode([
            [
                'event_type' => $eventType,
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => $eventData
            ]
        ]);
        
        $stmt = $this->db->prepare("
            INSERT INTO odoo_notification_batch_groups
            (batch_group_id, order_id, order_ref, line_user_id, event_types, 
             event_count, event_data, first_event_at, last_event_at, window_expires_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), NOW(), ?)
        ");
        
        $stmt->execute([
            $batchGroupId,
            $orderId,
            $orderRef,
            $lineUserId,
            $eventTypes,
            $eventDataJson,
            $windowExpires
        ]);
        
        return $batchGroupId;
    }
    
    /**
     * Update existing batch group with new event
     */
    private function updateBatchGroup($batchGroupId, $eventType, $eventData)
    {
        $stmt = $this->db->prepare("
            SELECT event_types, event_data, event_count 
            FROM odoo_notification_batch_groups
            WHERE batch_group_id = ?
        ");
        $stmt->execute([$batchGroupId]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$group) {
            return false;
        }
        
        $eventTypes = json_decode($group['event_types'], true) ?? [];
        $eventTypes[] = $eventType;
        
        $eventDataArray = json_decode($group['event_data'], true) ?? [];
        $eventDataArray[] = [
            'event_type' => $eventType,
            'timestamp' => date('Y-m-d H:i:s'),
            'data' => $eventData
        ];
        
        $stmt = $this->db->prepare("
            UPDATE odoo_notification_batch_groups
            SET event_types = ?,
                event_data = ?,
                event_count = event_count + 1,
                last_event_at = NOW()
            WHERE batch_group_id = ?
        ");
        
        $stmt->execute([
            json_encode($eventTypes),
            json_encode($eventDataArray),
            $batchGroupId
        ]);
        
        return true;
    }
    
    /**
     * Check if milestone reached for batch
     */
    public function checkMilestone($batchGroupId, $eventType)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_batch_groups
                WHERE batch_group_id = ?
            ");
            $stmt->execute([$batchGroupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$group) {
                return false;
            }
            
            // Check if this event is order.packed (our milestone)
            if ($eventType === 'order.packed') {
                $this->markMilestoneReached($batchGroupId, $eventType);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error checking milestone: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark milestone as reached
     */
    private function markMilestoneReached($batchGroupId, $milestoneEvent)
    {
        $stmt = $this->db->prepare("
            UPDATE odoo_notification_batch_groups
            SET milestone_reached = TRUE,
                milestone_event = ?,
                status = 'ready'
            WHERE batch_group_id = ?
        ");
        
        $stmt->execute([$milestoneEvent, $batchGroupId]);
    }
    
    /**
     * Get batch group for order and user
     */
    public function getBatchGroup($orderId, $lineUserId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_batch_groups
                WHERE order_id = ? 
                  AND line_user_id = ?
                  AND status IN ('collecting', 'ready')
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([$orderId, $lineUserId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting batch group: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create roadmap message from batch
     */
    public function createRoadmapMessage($batchGroupId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_batch_groups
                WHERE batch_group_id = ?
            ");
            $stmt->execute([$batchGroupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$group) {
                return null;
            }
            
            $eventData = json_decode($group['event_data'], true) ?? [];
            
            require_once __DIR__ . '/RoadmapMessageBuilder.php';
            $builder = new RoadmapMessageBuilder();
            
            $orderData = [
                'order_id' => $group['order_id'],
                'order_ref' => $group['order_ref'],
                'event_count' => $group['event_count']
            ];
            
            return $builder->buildRoadmapFlex($eventData, $orderData);
            
        } catch (Exception $e) {
            error_log("Error creating roadmap message: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get ready batches to send
     */
    public function getReadyBatches($limit = 10)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_batch_groups
                WHERE status = 'ready'
                  AND sent_at IS NULL
                ORDER BY last_event_at ASC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting ready batches: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark batch as sent
     */
    public function markBatchSent($batchGroupId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_batch_groups
                SET status = 'sent',
                    sent_at = NOW()
                WHERE batch_group_id = ?
            ");
            $stmt->execute([$batchGroupId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error marking batch sent: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Expire old batches that didn't reach milestone
     */
    public function expireOldBatches()
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_batch_groups
                SET status = 'expired'
                WHERE status = 'collecting'
                  AND window_expires_at < NOW()
            ");
            $stmt->execute();
            
            $expiredCount = $stmt->rowCount();
            
            if ($expiredCount > 0) {
                error_log("Expired {$expiredCount} old batch groups");
            }
            
            return $expiredCount;
            
        } catch (Exception $e) {
            error_log("Error expiring old batches: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send batch immediately (force send)
     */
    public function sendBatchNow($batchGroupId)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE odoo_notification_batch_groups
                SET status = 'ready',
                    milestone_reached = TRUE,
                    milestone_event = 'forced'
                WHERE batch_group_id = ?
                  AND status = 'collecting'
            ");
            $stmt->execute([$batchGroupId]);
            
            return $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log("Error forcing batch send: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check and send ready batches
     */
    public function checkAndSendReadyBatches()
    {
        $readyBatches = $this->getReadyBatches(10);
        $sentCount = 0;
        
        foreach ($readyBatches as $batch) {
            try {
                require_once __DIR__ . '/NotificationQueue.php';
                $queue = new NotificationQueue($this->db);
                
                $roadmapMessage = $this->createRoadmapMessage($batch['batch_group_id']);
                
                if ($roadmapMessage) {
                    $queueId = $queue->enqueue([
                        'delivery_id' => 'batch_' . $batch['batch_group_id'],
                        'event_type' => 'roadmap.milestone',
                        'order_id' => $batch['order_id'],
                        'order_ref' => $batch['order_ref'],
                        'recipient_type' => 'customer',
                        'line_user_id' => $batch['line_user_id'],
                        'message_type' => 'roadmap',
                        'message_payload' => $roadmapMessage,
                        'alt_text' => "อัปเดตสถานะออเดอร์ {$batch['order_ref']}",
                        'batch_group_id' => $batch['batch_group_id'],
                        'is_batched' => true,
                        'priority' => 5
                    ]);
                    
                    if ($queueId) {
                        $this->markBatchSent($batch['batch_group_id']);
                        $sentCount++;
                    }
                }
                
            } catch (Exception $e) {
                error_log("Error sending ready batch: " . $e->getMessage());
            }
        }
        
        return $sentCount;
    }
}
