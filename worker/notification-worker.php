<?php
/**
 * Notification Worker
 * 
 * Background worker for processing notification queue
 * Handles sending notifications, retries, and batch processing
 * 
 * Usage:
 *   php worker/notification-worker.php
 * 
 * Or as cron job:
 *   * * * * * cd /path/to/re-ya && php worker/notification-worker.php >> logs/notification-worker.log 2>&1 &
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/NotificationQueue.php';
require_once __DIR__ . '/../classes/NotificationBatcher.php';
require_once __DIR__ . '/../classes/NotificationLogger.php';

$workerStartTime = time();
$processedCount = 0;
$errorCount = 0;

echo "[" . date('Y-m-d H:i:s') . "] Notification Worker Started\n";

try {
    $db = Database::getInstance()->getConnection();
    $queue = new NotificationQueue($db);
    $batcher = new NotificationBatcher($db);
    $logger = new NotificationLogger($db);
    
    while (true) {
        try {
            // 1. Process queued notifications
            $notifications = $queue->getPending(10);
            
            foreach ($notifications as $notif) {
                $queue->markProcessing($notif['id']);
                
                try {
                    $startTime = microtime(true);
                    
                    $result = sendNotification($notif, $db);
                    
                    $latency = (int)((microtime(true) - $startTime) * 1000);
                    
                    if ($result['success']) {
                        $queue->markSent($notif['id'], $result);
                        $logger->logSuccess(
                            $notif['delivery_id'],
                            [
                                'type' => $notif['recipient_type'],
                                'line_user_id' => $notif['line_user_id'],
                                'event_type' => $notif['event_type'],
                                'method' => $notif['message_type']
                            ],
                            $result,
                            $latency
                        );
                        $processedCount++;
                        echo "[" . date('H:i:s') . "] ✓ Sent notification #{$notif['id']} to {$notif['line_user_id']}\n";
                    } else {
                        throw new Exception($result['error'] ?? 'Unknown error');
                    }
                    
                } catch (Exception $e) {
                    $shouldRetry = isRetriableError($e->getMessage());
                    $queue->markFailed($notif['id'], $e->getMessage(), $shouldRetry);
                    $logger->logFailure(
                        $notif['delivery_id'],
                        [
                            'type' => $notif['recipient_type'],
                            'line_user_id' => $notif['line_user_id'],
                            'event_type' => $notif['event_type'],
                            'method' => $notif['message_type']
                        ],
                        $e->getMessage()
                    );
                    $errorCount++;
                    echo "[" . date('H:i:s') . "] ✗ Failed notification #{$notif['id']}: {$e->getMessage()}\n";
                }
            }
            
            // 2. Check for batch milestones and send ready batches
            $sentBatches = $batcher->checkAndSendReadyBatches();
            if ($sentBatches > 0) {
                echo "[" . date('H:i:s') . "] 📦 Sent {$sentBatches} roadmap batches\n";
            }
            
            // 3. Expire old batches and notifications
            $expiredBatches = $batcher->expireOldBatches();
            $expiredNotifications = $queue->expireOld();
            
            if ($expiredBatches > 0 || $expiredNotifications > 0) {
                echo "[" . date('H:i:s') . "] ⏰ Expired {$expiredBatches} batches, {$expiredNotifications} notifications\n";
            }
            
            // 4. Cleanup old completed items (once per hour)
            if (time() % 3600 < 10) {
                $cleaned = $queue->cleanup(7);
                if ($cleaned > 0) {
                    echo "[" . date('H:i:s') . "] 🧹 Cleaned up {$cleaned} old notifications\n";
                }
            }
            
            // 5. Show stats every 5 minutes
            if (time() % 300 < 10) {
                $stats = $queue->getStats();
                echo "[" . date('H:i:s') . "] 📊 Queue Stats:\n";
                foreach ($stats as $stat) {
                    echo "  {$stat['status']}: {$stat['count']} (avg retries: " . number_format($stat['avg_retries'], 2) . ")\n";
                }
                echo "  Processed: {$processedCount}, Errors: {$errorCount}\n";
            }
            
        } catch (Exception $e) {
            echo "[" . date('H:i:s') . "] ✗ Worker error: {$e->getMessage()}\n";
            error_log("Notification worker error: " . $e->getMessage());
        }
        
        // Check if worker should stop (after 1 hour)
        if (time() - $workerStartTime > 3600) {
            echo "[" . date('Y-m-d H:i:s') . "] Worker stopping after 1 hour (processed: {$processedCount}, errors: {$errorCount})\n";
            break;
        }
        
        sleep(5); // Poll every 5 seconds
    }
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Fatal error: {$e->getMessage()}\n";
    error_log("Notification worker fatal error: " . $e->getMessage());
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Notification Worker Stopped\n";

/**
 * Send notification via LINE API
 */
function sendNotification($notif, $db)
{
    try {
        $lineUserId = $notif['line_user_id'];
        $messagePayload = $notif['message_payload'];
        $messageType = $notif['message_type'];
        $altText = $notif['alt_text'] ?? 'แจ้งเตือน';
        
        // Get channel access token
        $channelAccessToken = getChannelAccessToken($db, $notif['line_account_id']);
        
        if (!$channelAccessToken) {
            throw new Exception('Channel access token not found');
        }
        
        // Build LINE message
        $messages = [];
        
        if ($messageType === 'flex' || $messageType === 'roadmap') {
            $messages[] = [
                'type' => 'flex',
                'altText' => $altText,
                'contents' => $messagePayload
            ];
        } else {
            $messages[] = [
                'type' => 'text',
                'text' => is_array($messagePayload) ? json_encode($messagePayload) : $messagePayload
            ];
        }
        
        // Send via LINE API
        $url = 'https://api.line.me/v2/bot/message/push';
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $channelAccessToken
        ];
        
        $data = [
            'to' => $lineUserId,
            'messages' => $messages
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("cURL error: {$curlError}");
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $responseData['message'] ?? "HTTP {$httpCode}";
            throw new Exception("LINE API error: {$errorMsg}");
        }
        
        return [
            'success' => true,
            'status' => $httpCode,
            'response' => $responseData
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get channel access token
 */
function getChannelAccessToken($db, $lineAccountId)
{
    try {
        if ($lineAccountId) {
            $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
            $stmt->execute([$lineAccountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($account) {
                return $account['channel_access_token'];
            }
        }
        
        // Fallback to first active account
        $stmt = $db->query("SELECT channel_access_token FROM line_accounts WHERE is_active = 1 LIMIT 1");
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        return $account['channel_access_token'] ?? null;
        
    } catch (Exception $e) {
        error_log("Error getting channel access token: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if error is retriable
 */
function isRetriableError($message)
{
    $normalized = strtolower($message);
    $keywords = [
        'timeout',
        'temporarily',
        'network',
        'connection reset',
        'connection refused',
        'could not resolve host',
        'too many requests',
        'service unavailable',
        'gateway timeout',
        'internal server error',
        'curl error'
    ];
    
    foreach ($keywords as $keyword) {
        if (strpos($normalized, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}
