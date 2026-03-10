<?php
/**
 * Notification Logger
 * 
 * Logs all notification attempts for audit and analytics
 */

class NotificationLogger
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Log notification attempt
     */
    public function logAttempt($deliveryId, $eventType, $recipient, $method)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id, 
                 notification_method, status, sent_at)
                VALUES (?, ?, ?, ?, ?, 'sent', NOW())
            ");
            
            $stmt->execute([
                $deliveryId,
                $eventType,
                $recipient['type'],
                $recipient['line_user_id'],
                $method
            ]);
            
            return $this->db->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Error logging notification attempt: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Log successful notification
     */
    public function logSuccess($deliveryId, $recipient, $response, $latency = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id, 
                 notification_method, status, line_api_status, line_api_response, 
                 latency_ms, sent_at)
                VALUES (?, ?, ?, ?, ?, 'sent', ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $deliveryId,
                $recipient['event_type'] ?? 'unknown',
                $recipient['type'],
                $recipient['line_user_id'],
                $recipient['method'] ?? 'flex',
                $response['status'] ?? 200,
                json_encode($response),
                $latency
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error logging success: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log failed notification
     */
    public function logFailure($deliveryId, $recipient, $error)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id, 
                 notification_method, status, error_message, sent_at)
                VALUES (?, ?, ?, ?, ?, 'failed', ?, NOW())
            ");
            
            $stmt->execute([
                $deliveryId,
                $recipient['event_type'] ?? 'unknown',
                $recipient['type'],
                $recipient['line_user_id'],
                $recipient['method'] ?? 'flex',
                $error
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error logging failure: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log skipped notification
     */
    public function logSkip($deliveryId, $recipient, $reason)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id, 
                 notification_method, status, skip_reason, sent_at)
                VALUES (?, ?, ?, ?, ?, 'skipped', ?, NOW())
            ");
            
            $stmt->execute([
                $deliveryId,
                $recipient['event_type'] ?? 'unknown',
                $recipient['type'],
                $recipient['line_user_id'],
                $recipient['method'] ?? 'flex',
                $reason
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error logging skip: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get statistics
     */
    public function getStats($startDate, $endDate, $filters = [])
    {
        try {
            $where = ["sent_at BETWEEN ? AND ?"];
            $params = [$startDate, $endDate];
            
            if (!empty($filters['event_type'])) {
                $where[] = "event_type = ?";
                $params[] = $filters['event_type'];
            }
            
            if (!empty($filters['recipient_type'])) {
                $where[] = "recipient_type = ?";
                $params[] = $filters['recipient_type'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            $stmt = $this->db->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    AVG(latency_ms) as avg_latency
                FROM odoo_notification_log
                WHERE {$whereClause}
                GROUP BY status
            ");
            
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting notification stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent notifications
     */
    public function getRecent($limit = 50, $filters = [])
    {
        try {
            $where = ["1=1"];
            $params = [];
            
            if (!empty($filters['line_user_id'])) {
                $where[] = "line_user_id = ?";
                $params[] = $filters['line_user_id'];
            }
            
            if (!empty($filters['status'])) {
                $where[] = "status = ?";
                $params[] = $filters['status'];
            }
            
            $whereClause = implode(' AND ', $where);
            $params[] = $limit;
            
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_log
                WHERE {$whereClause}
                ORDER BY sent_at DESC
                LIMIT ?
            ");
            
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Error getting recent notifications: " . $e->getMessage());
            return [];
        }
    }
}
