<?php
/**
 * Cron Job: Process Broadcast Queue
 * รันทุกนาที: * * * * * php /path/to/cron/process_broadcast_queue.php
 * 
 * Update V2: Use Multicast (500 users/request) & Rate Limiting
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';
require_once __DIR__ . '/../classes/RateLimiter.php';

$db = Database::getInstance()->getConnection();
$lineManager = new LineAccountManager($db);

// Process up to 2000 messages per run (4 batches of multicast)
$batchSize = 2000;

// Fetch pending queue
$stmt = $db->prepare("SELECT q.*, b.content, b.message_type, b.line_account_id, u.line_user_id 
                      FROM broadcast_queue q 
                      JOIN broadcasts b ON q.broadcast_id = b.id 
                      JOIN users u ON q.user_id = u.id 
                      WHERE q.status = 'pending' 
                      ORDER BY q.created_at ASC 
                      LIMIT ?");
$stmt->execute([$batchSize]);
$queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($queue)) {
    exit("No pending broadcasts\n");
}

echo "Found " . count($queue) . " pending messages. Grouping...\n";

// Group by Broadcast ID to batch users
$grouped = [];
foreach ($queue as $item) {
    if (!isset($grouped[$item['broadcast_id']])) {
        $grouped[$item['broadcast_id']] = [
            'meta' => $item, // Store metadata from first item
            'items' => []
        ];
    }
    $grouped[$item['broadcast_id']]['items'][] = $item;
}

$totalSent = 0;
$totalFailed = 0;

foreach ($grouped as $broadcastId => $group) {
    $meta = $group['meta'];
    $accountId = $meta['line_account_id'];
    $line = $lineManager->getLineAPI($accountId);
    $limiter = new RateLimiter('broadcast_' . $accountId, 60, 60); // 60 requests per minute

    // Prepare message content
    if ($meta['message_type'] === 'flex') {
        $content = json_decode($meta['content'], true);
        $message = ['type' => 'flex', 'altText' => 'ข้อความจากระบบ', 'contents' => $content];
    } else {
        $message = ['type' => 'text', 'text' => $meta['content']];
    }

    // Chunk into 500 (LINE Multicast limit)
    $chunks = array_chunk($group['items'], 500);

    foreach ($chunks as $chunk) {
        $userIds = array_column($chunk, 'line_user_id');
        $queueIds = array_column($chunk, 'id');

        $limiter->wait(); // Wait if rate limited

        try {
            // Try Multicast first
            $result = $line->multicastMessage($userIds, $message);

            if ($result['code'] === 200) {
                // Success - Update all
                $inQuery = str_repeat('?,', count($queueIds) - 1) . '?';
                $updateStmt = $db->prepare("UPDATE broadcast_queue SET status = 'sent', sent_at = NOW() WHERE id IN ($inQuery)");
                $updateStmt->execute($queueIds);
                $totalSent += count($queueIds);
                echo "Multicast sent to " . count($queueIds) . " users.\n";
            } else {
                throw new Exception("Multicast failed: " . ($result['body']['message'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            echo "Multicast failed, falling back to individual: " . $e->getMessage() . "\n";

            // Fallback: Send individually
            foreach ($chunk as $item) {
                try {
                    $limiter->wait();
                    $res = $line->pushMessage($item['line_user_id'], $message);

                    if ($res['code'] === 200) {
                        $db->prepare("UPDATE broadcast_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$item['id']]);
                        $totalSent++;
                    } else {
                        $db->prepare("UPDATE broadcast_queue SET status = 'failed', error_message = ? WHERE id = ?")
                            ->execute([$res['body']['message'] ?? 'API Error', $item['id']]);
                        $totalFailed++;
                    }
                } catch (Exception $ex) {
                    $db->prepare("UPDATE broadcast_queue SET status = 'failed', error_message = ? WHERE id = ?")
                        ->execute([$ex->getMessage(), $item['id']]);
                    $totalFailed++;
                }
            }
        }
    }

    // Update broadcast stats
    $db->prepare("UPDATE broadcasts SET sent_count = (SELECT COUNT(*) FROM broadcast_queue WHERE broadcast_id = ? AND status = 'sent') WHERE id = ?")
        ->execute([$broadcastId, $broadcastId]);
}

echo "Processed: {$totalSent} sent, {$totalFailed} failed\n";
