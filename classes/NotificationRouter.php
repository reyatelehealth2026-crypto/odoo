<?php
/**
 * Notification Router
 * 
 * Routes notifications based on preferences and batching rules
 * Main entry point for notification system
 */

require_once __DIR__ . '/NotificationPreferencesManager.php';
require_once __DIR__ . '/NotificationBatcher.php';
require_once __DIR__ . '/NotificationQueue.php';
require_once __DIR__ . '/NotificationLogger.php';

class NotificationRouter
{
    private $db;
    private $preferencesManager;
    private $batcher;
    private $queue;
    private $logger;
    
    public function __construct($db)
    {
        $this->db = $db;
        $this->preferencesManager = new NotificationPreferencesManager($db);
        $this->batcher = new NotificationBatcher($db);
        $this->queue = new NotificationQueue($db);
        $this->logger = new NotificationLogger($db);
    }
    
    /**
     * Main routing logic
     * Routes notification based on preferences and batching rules
     */
    public function route($deliveryId, $eventType, $data, $notify)
    {
        $results = [
            'routed' => [],
            'skipped' => [],
            'batched' => [],
            'queued' => []
        ];
        
        $customerPartnerId = $data['customer']['partner_id'] ?? null;
        $salespersonPartnerId = $data['salesperson']['partner_id'] ?? null;
        
        if ($notify['customer'] && $customerPartnerId) {
            $user = $this->findLineUser($customerPartnerId, $data['customer']['line_user_id'] ?? null);
            error_log("NotificationRouter.route: partner_id={$customerPartnerId} line_user_id=" . ($user['line_user_id'] ?? 'NULL') . " user=" . ($user ? 'found' : 'null'));
            if ($user) {
                $result = $this->routeForUser(
                    $deliveryId,
                    $eventType,
                    $data,
                    $user,
                    'customer'
                );
                $results = $this->mergeResults($results, $result);
            }
        }
        
        if ($notify['salesperson'] && $salespersonPartnerId) {
            $user = $this->findLineUser($salespersonPartnerId, $data['salesperson']['line_user_id'] ?? null);
            if ($user) {
                $result = $this->routeForUser(
                    $deliveryId,
                    $eventType,
                    $data,
                    $user,
                    'salesperson'
                );
                $results = $this->mergeResults($results, $result);
            }
        }
        
        return $results;
    }
    
    /**
     * Intermediate order events that should NOT trigger LINE notifications.
     * These are silently logged; the consolidated notification is sent at order.to_delivery.
     */
    private static $silentOrderEvents = [
        'order.picker_assigned',
        'order.picking',
        'order.picked',
        'order.packing',
        'order.packed',
        'invoice.created',
        'invoice.overdue',
    ];

    private function routeForUser($deliveryId, $eventType, $data, $user, $recipientType)
    {
        $lineUserId = $user['line_user_id'];

        // [TEMPORARILY DISABLED] All LINE notifications to customers are disabled
        if ($recipientType === 'customer') {
            error_log("NotificationRouter: SKIP customer notification for {$eventType} — ALL customer notifications temporarily disabled");
            $this->logger->logSkip($deliveryId, [
                'type'         => $recipientType,
                'line_user_id' => $lineUserId,
                'event_type'   => $eventType,
                'method'       => 'flex',
            ], 'all_customer_notifications_disabled');
            return ['skipped' => [$recipientType]];
        }

        // Skip LINE notification for intermediate order statuses — they are
        // consolidated into a single message when order.to_delivery arrives.
        if (in_array($eventType, self::$silentOrderEvents, true)) {
            error_log("NotificationRouter: SKIP intermediate event {$eventType} for {$lineUserId} (consolidated at order.to_delivery)");
            $this->logger->logSkip($deliveryId, [
                'type'         => $recipientType,
                'line_user_id' => $lineUserId,
                'event_type'   => $eventType,
                'method'       => 'flex',
            ], 'consolidated_at_to_delivery');
            return ['skipped' => [$recipientType]];
        }

        $shouldNotify = $this->preferencesManager->shouldNotify($lineUserId, $eventType);
        error_log("NotificationRouter: user={$lineUserId} event={$eventType} should_send=" . json_encode($shouldNotify['should_send']) . " reason=" . ($shouldNotify['reason'] ?? '-'));

        if (!$shouldNotify['should_send']) {
            $this->logger->logSkip($deliveryId, [
                'type'         => $recipientType,
                'line_user_id' => $lineUserId,
                'event_type'   => $eventType,
                'method'       => 'flex',
            ], $shouldNotify['reason']);

            return ['skipped' => [$recipientType]];
        }
        
        // queue path: no background worker running — send immediately instead
        // if ($shouldNotify['should_send'] === 'queue') {
        //     return $this->addToQueue($deliveryId, $eventType, $data, $user, $recipientType);
        // }

        // batch path: no worker to flush batches — send immediately instead
        // $shouldBatch = $this->preferencesManager->shouldBatch($lineUserId, $eventType);
        // if ($shouldBatch && !empty($data['order_id'])) {
        //     return $this->addToBatch($deliveryId, $eventType, $data, $user, $recipientType);
        // }

        return $this->sendImmediate($deliveryId, $eventType, $data, $user, $recipientType);
    }
    
    /**
     * Add to batch for roadmap
     */
    private function addToBatch($deliveryId, $eventType, $data, $user, $recipientType)
    {
        $orderId = $data['order_id'];
        $lineUserId = $user['line_user_id'];
        
        $batchGroupId = $this->batcher->addEvent($orderId, $lineUserId, $eventType, $data);
        
        if (!$batchGroupId) {
            return $this->sendImmediate($deliveryId, $eventType, $data, $user, $recipientType);
        }
        
        $isMilestone = $this->preferencesManager->isMilestoneEvent($lineUserId, $eventType);
        
        if ($isMilestone) {
            $this->batcher->checkMilestone($batchGroupId, $eventType);
            
            $roadmapMessage = $this->batcher->createRoadmapMessage($batchGroupId);
            
            if ($roadmapMessage) {
                $queueId = $this->queue->enqueue([
                    'delivery_id' => $deliveryId,
                    'event_type' => 'roadmap.milestone',
                    'order_id' => $orderId,
                    'order_ref' => $data['order_ref'] ?? $data['order_name'] ?? null,
                    'recipient_type' => $recipientType,
                    'line_user_id' => $lineUserId,
                    'line_account_id' => $user['line_account_id'] ?? null,
                    'message_type' => 'roadmap',
                    'message_payload' => $roadmapMessage,
                    'alt_text' => "อัปเดตสถานะออเดอร์ " . ($data['order_ref'] ?? ''),
                    'batch_group_id' => $batchGroupId,
                    'is_batched' => true,
                    'priority' => 5
                ]);
                
                $this->batcher->markBatchSent($batchGroupId);
                
                return ['routed' => [$recipientType], 'batched' => true, 'milestone' => true];
            }
        }
        
        return ['batched' => [$recipientType], 'batch_group_id' => $batchGroupId];
    }
    
    /**
     * Add to queue for later processing
     */
    private function addToQueue($deliveryId, $eventType, $data, $user, $recipientType)
    {
        require_once __DIR__ . '/OdooFlexTemplates.php';
        
        $message = $this->buildMessage($eventType, $data, $recipientType);
        $flexBubble = OdooFlexTemplates::odooStatusUpdate($eventType, $data, $message, $recipientType === 'salesperson');
        
        $queueId = $this->queue->enqueue([
            'delivery_id' => $deliveryId,
            'event_type' => $eventType,
            'order_id' => $data['order_id'] ?? null,
            'order_ref' => $data['order_ref'] ?? $data['order_name'] ?? null,
            'recipient_type' => $recipientType,
            'line_user_id' => $user['line_user_id'],
            'line_account_id' => $user['line_account_id'] ?? null,
            'message_type' => 'flex',
            'message_payload' => $flexBubble,
            'alt_text' => $message,
            'priority' => $this->getPriority($eventType)
        ]);
        
        return ['queued' => [$recipientType], 'queue_id' => $queueId];
    }
    
    /**
     * Send notification immediately via LINE API and log to odoo_notification_log
     */
    private function sendImmediate($deliveryId, $eventType, $data, $user, $recipientType)
    {
        require_once __DIR__ . '/OdooFlexTemplates.php';

        $lineUserId      = $user['line_user_id'];
        $accessToken     = $user['channel_access_token'];
        $message         = $this->buildMessage($eventType, $data, $recipientType);
        $isSalesperson   = ($recipientType === 'salesperson');

        $flexBubble = null;
        try {
            $flexBubble = OdooFlexTemplates::odooStatusUpdate($eventType, $data, $message, $isSalesperson);
        } catch (Exception $e) {
            error_log('OdooFlexTemplates error: ' . $e->getMessage());
        }

        $startTime  = microtime(true);
        $apiStatus  = null;
        $apiError   = null;
        $sent       = false;

        if ($lineUserId && $accessToken) {
            try {
                if ($flexBubble) {
                    $body = json_encode([
                        'to' => $lineUserId,
                        'messages' => [[
                            'type'    => 'flex',
                            'altText' => $message,
                            'contents' => $flexBubble,
                        ]]
                    ]);
                } else {
                    $body = json_encode([
                        'to' => $lineUserId,
                        'messages' => [['type' => 'text', 'text' => $message]]
                    ]);
                }

                $ch = curl_init('https://api.line.me/v2/bot/message/push');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $accessToken,
                    ],
                    CURLOPT_TIMEOUT => 10,
                ]);
                $response  = curl_exec($ch);
                $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $apiStatus = $httpCode;
                $sent      = ($httpCode >= 200 && $httpCode < 300);
                if (!$sent) {
                    $apiError = $response;
                    error_log("LINE push failed [{$httpCode}]: {$response}");
                }
            } catch (Exception $e) {
                $apiError = $e->getMessage();
                error_log('LINE push exception: ' . $e->getMessage());
            }
        } else {
            $apiError = 'missing line_user_id or channel_access_token';
        }

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        // Log to odoo_notification_log
        try {
            $status = $sent ? 'sent' : 'failed';
            $stmt = $this->db->prepare("
                INSERT INTO odoo_notification_log
                (delivery_id, event_type, recipient_type, line_user_id,
                 notification_method, status, line_api_status, line_api_response,
                 error_message, latency_ms, sent_at)
                VALUES (?, ?, ?, ?, 'flex', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $deliveryId,
                $eventType,
                $recipientType,
                $lineUserId,
                $status,
                $apiStatus,
                $sent ? null : json_encode(['error' => $apiError]),
                $sent ? null : $apiError,
                $latencyMs,
            ]);
        } catch (Exception $e) {
            error_log('Error logging to odoo_notification_log: ' . $e->getMessage());
        }

        return ['routed' => [$recipientType], 'immediate' => true, 'sent' => $sent];
    }
    
    /**
     * Build message text
     */
    private function buildMessage($eventType, $data, $recipientType)
    {
        $orderRef = $data['order_ref'] ?? $data['order_name'] ?? 'ออเดอร์';
        
        $messages = [
            'order.validated' => "✅ ยืนยันออเดอร์ {$orderRef} แล้ว",
            'order.picker_assigned' => "👤 มีพนักงานรับออเดอร์ {$orderRef}",
            'order.picking' => "📦 กำลังจัดสินค้า {$orderRef}",
            'order.picked' => "✅ จัดสินค้า {$orderRef} เสร็จแล้ว",
            'order.packing' => "📦 กำลังแพ็คสินค้า {$orderRef}",
            'order.packed' => "✅ แพ็คสินค้า {$orderRef} เสร็จแล้ว - พร้อมจัดส่ง",
            'order.to_delivery' => "🚚 เตรียมจัดส่ง {$orderRef}",
            'order.in_delivery' => "🚚 กำลังจัดส่ง {$orderRef}",
            'order.delivered' => "✅ จัดส่ง {$orderRef} สำเร็จ",
        ];
        
        return $messages[$eventType] ?? "อัปเดตสถานะ {$orderRef}";
    }
    
    /**
     * Get priority for event type
     */
    private function getPriority($eventType)
    {
        $priorities = [
            'order.awaiting_payment' => 1,
            'bdo.confirmed' => 1,
            'invoice.overdue' => 1,
            'order.delivered' => 2,
            'order.packed' => 3,
            'order.validated' => 3,
        ];
        
        return $priorities[$eventType] ?? 5;
    }
    
    /**
     * Find LINE user by Odoo partner ID
     */
    private function findLineUser($odooPartnerId, $fallbackLineUserId = null)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT olu.line_user_id, olu.line_notification_enabled,
                       la.id as line_account_id, la.channel_access_token
                FROM odoo_line_users olu
                LEFT JOIN line_accounts la ON olu.line_account_id = la.id
                WHERE olu.odoo_partner_id = ?
                LIMIT 1
            ");
            $stmt->execute([$odooPartnerId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // If line_user_id is NULL in DB, use fallback from payload
            if ($user && empty($user['line_user_id']) && $fallbackLineUserId) {
                $user['line_user_id'] = $fallbackLineUserId;
            }

            // If channel_access_token is NULL (line_account_id mismatch), fetch from first account
            if ($user && empty($user['channel_access_token'])) {
                $laStmt = $this->db->prepare("SELECT id as line_account_id, channel_access_token FROM line_accounts WHERE channel_access_token IS NOT NULL AND channel_access_token != '' LIMIT 1");
                $laStmt->execute();
                $la = $laStmt->fetch(PDO::FETCH_ASSOC);
                if ($la) {
                    $user['line_account_id']      = $la['line_account_id'];
                    $user['channel_access_token'] = $la['channel_access_token'];
                }
            }

            // No DB record but have fallback — build minimal record with first line_account
            if (!$user && $fallbackLineUserId) {
                $laStmt = $this->db->prepare("SELECT id as line_account_id, channel_access_token FROM line_accounts WHERE channel_access_token IS NOT NULL AND channel_access_token != '' LIMIT 1");
                $laStmt->execute();
                $la = $laStmt->fetch(PDO::FETCH_ASSOC);
                if ($la) {
                    $user = [
                        'line_user_id'              => $fallbackLineUserId,
                        'line_notification_enabled' => 1,
                        'line_account_id'           => $la['line_account_id'],
                        'channel_access_token'      => $la['channel_access_token'],
                    ];
                }
            }

            error_log("NotificationRouter.findLineUser: partner={$odooPartnerId} line_user_id=" . ($user['line_user_id'] ?? 'NULL') . " has_token=" . (!empty($user['channel_access_token']) ? 'yes' : 'no'));
            return $user ?: null;

        } catch (Exception $e) {
            error_log("Error finding LINE user: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Merge routing results
     */
    private function mergeResults($results, $newResult)
    {
        foreach ($newResult as $key => $value) {
            if (is_array($value)) {
                $results[$key] = array_merge($results[$key] ?? [], $value);
            } else {
                $results[$key] = $value;
            }
        }
        return $results;
    }
}
