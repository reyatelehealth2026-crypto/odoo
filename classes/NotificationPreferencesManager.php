<?php
/**
 * Notification Preferences Manager
 * 
 * Manages user notification preferences and settings
 * Handles default preferences, quiet hours, and batching configuration
 */

class NotificationPreferencesManager
{
    private $db;
    private $cache = [];
    
    public function __construct($db)
    {
        $this->db = $db;
    }
    
    /**
     * Get user preferences for specific event
     * Falls back to default preferences if user-specific not found
     */
    public function getPreferences($lineUserId, $eventType)
    {
        $cacheKey = "{$lineUserId}:{$eventType}";
        
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_notification_preferences
                WHERE line_user_id = ? AND event_type = ?
                LIMIT 1
            ");
            $stmt->execute([$lineUserId, $eventType]);
            $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$prefs) {
                $stmt = $this->db->prepare("
                    SELECT * FROM odoo_notification_preferences
                    WHERE line_user_id = '_default_customer' AND event_type = ?
                    LIMIT 1
                ");
                $stmt->execute([$eventType]);
                $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($prefs) {
                if (!empty($prefs['batch_milestone_events'])) {
                    $prefs['batch_milestone_events'] = json_decode($prefs['batch_milestone_events'], true);
                }
                $this->cache[$cacheKey] = $prefs;
                return $prefs;
            }
            
            return $this->getDefaultPreferences($eventType);
            
        } catch (Exception $e) {
            error_log("Error getting preferences: " . $e->getMessage());
            return $this->getDefaultPreferences($eventType);
        }
    }
    
    /**
     * Check if notification should be sent
     * Considers enabled flag, quiet hours, and other settings
     */
    public function shouldNotify($lineUserId, $eventType, $currentTime = null)
    {
        $prefs = $this->getPreferences($lineUserId, $eventType);
        
        if (!$prefs || !$prefs['enabled']) {
            return ['should_send' => false, 'reason' => 'disabled'];
        }
        
        if ($prefs['notification_method'] === 'none') {
            return ['should_send' => false, 'reason' => 'method_none'];
        }
        
        if ($prefs['quiet_hours_enabled']) {
            $currentTime = $currentTime ?: date('H:i:s');
            $start = $prefs['quiet_hours_start'];
            $end = $prefs['quiet_hours_end'];
            
            if ($this->isInQuietHours($currentTime, $start, $end)) {
                $action = $prefs['quiet_hours_action'] ?? 'queue';
                
                if ($action === 'skip') {
                    return ['should_send' => false, 'reason' => 'quiet_hours_skip'];
                } elseif ($action === 'queue') {
                    return ['should_send' => 'queue', 'reason' => 'quiet_hours_queue'];
                } elseif ($action === 'silent') {
                    return ['should_send' => true, 'reason' => 'quiet_hours_silent', 'silent' => true];
                }
            }
        }
        
        return ['should_send' => true, 'reason' => 'ok'];
    }
    
    /**
     * Check if should batch this event
     */
    public function shouldBatch($lineUserId, $eventType)
    {
        $prefs = $this->getPreferences($lineUserId, $eventType);
        return $prefs && $prefs['batch_enabled'];
    }
    
    /**
     * Get batch settings
     */
    public function getBatchSettings($lineUserId, $eventType)
    {
        $prefs = $this->getPreferences($lineUserId, $eventType);
        
        if (!$prefs) {
            return null;
        }
        
        return [
            'enabled' => $prefs['batch_enabled'],
            'window_seconds' => $prefs['batch_window_seconds'] ?? 300,
            'milestone_events' => $prefs['batch_milestone_events'] ?? []
        ];
    }
    
    /**
     * Check if milestone event
     */
    public function isMilestoneEvent($lineUserId, $eventType)
    {
        $prefs = $this->getPreferences($lineUserId, $eventType);
        
        if (!$prefs || empty($prefs['batch_milestone_events'])) {
            return false;
        }
        
        $milestones = is_array($prefs['batch_milestone_events']) 
            ? $prefs['batch_milestone_events']
            : json_decode($prefs['batch_milestone_events'], true);
        
        return in_array($eventType, $milestones ?? []);
    }
    
    /**
     * Update user preferences
     */
    public function updatePreferences($lineUserId, $preferences)
    {
        try {
            $eventType = $preferences['event_type'];
            
            $fields = [
                'enabled' => $preferences['enabled'] ?? true,
                'notification_method' => $preferences['notification_method'] ?? 'flex',
                'batch_enabled' => $preferences['batch_enabled'] ?? false,
                'batch_window_seconds' => $preferences['batch_window_seconds'] ?? 300,
                'quiet_hours_enabled' => $preferences['quiet_hours_enabled'] ?? false,
                'quiet_hours_start' => $preferences['quiet_hours_start'] ?? null,
                'quiet_hours_end' => $preferences['quiet_hours_end'] ?? null,
                'quiet_hours_action' => $preferences['quiet_hours_action'] ?? 'queue',
                'priority' => $preferences['priority'] ?? 'medium'
            ];
            
            if (isset($preferences['batch_milestone_events'])) {
                $fields['batch_milestone_events'] = json_encode($preferences['batch_milestone_events']);
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_preferences 
                (line_user_id, event_type, enabled, notification_method, batch_enabled, 
                 batch_window_seconds, batch_milestone_events, quiet_hours_enabled, 
                 quiet_hours_start, quiet_hours_end, quiet_hours_action, priority)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    notification_method = VALUES(notification_method),
                    batch_enabled = VALUES(batch_enabled),
                    batch_window_seconds = VALUES(batch_window_seconds),
                    batch_milestone_events = VALUES(batch_milestone_events),
                    quiet_hours_enabled = VALUES(quiet_hours_enabled),
                    quiet_hours_start = VALUES(quiet_hours_start),
                    quiet_hours_end = VALUES(quiet_hours_end),
                    quiet_hours_action = VALUES(quiet_hours_action),
                    priority = VALUES(priority)
            ");
            
            $stmt->execute([
                $lineUserId,
                $eventType,
                $fields['enabled'],
                $fields['notification_method'],
                $fields['batch_enabled'],
                $fields['batch_window_seconds'],
                $fields['batch_milestone_events'] ?? null,
                $fields['quiet_hours_enabled'],
                $fields['quiet_hours_start'],
                $fields['quiet_hours_end'],
                $fields['quiet_hours_action'],
                $fields['priority']
            ]);
            
            unset($this->cache["{$lineUserId}:{$eventType}"]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error updating preferences: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get default preferences for event type
     */
    public function getDefaultPreferences($eventType = null)
    {
        $defaults = [
            'enabled' => true,
            'notification_method' => 'flex',
            'batch_enabled' => false,
            'batch_window_seconds' => 300,
            'batch_milestone_events' => [],
            'quiet_hours_enabled' => false,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '08:00:00',
            'quiet_hours_action' => 'queue',
            'priority' => 'medium'
        ];
        
        if ($eventType) {
            $defaults['event_type'] = $eventType;
        }
        
        return $defaults;
    }
    
    /**
     * Check if current time is in quiet hours
     */
    private function isInQuietHours($currentTime, $start, $end)
    {
        if (empty($start) || empty($end)) {
            return false;
        }
        
        $current = strtotime($currentTime);
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        
        if ($startTime < $endTime) {
            return $current >= $startTime && $current <= $endTime;
        } else {
            return $current >= $startTime || $current <= $endTime;
        }
    }
    
    /**
     * Initialize default preferences for new user
     */
    public function initializeUserPreferences($lineUserId)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_preferences 
                (line_user_id, event_type, enabled, notification_method, batch_enabled, 
                 batch_window_seconds, batch_milestone_events, quiet_hours_enabled, 
                 quiet_hours_start, quiet_hours_end, priority)
                SELECT 
                    ? as line_user_id,
                    event_type,
                    enabled,
                    notification_method,
                    batch_enabled,
                    batch_window_seconds,
                    batch_milestone_events,
                    quiet_hours_enabled,
                    quiet_hours_start,
                    quiet_hours_end,
                    priority
                FROM odoo_notification_preferences
                WHERE line_user_id = '_default_customer'
                ON DUPLICATE KEY UPDATE
                    line_user_id = line_user_id
            ");
            
            $stmt->execute([$lineUserId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error initializing user preferences: " . $e->getMessage());
            return false;
        }
    }
}
