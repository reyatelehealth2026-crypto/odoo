<?php
/**
 * Facebook Messenger Webhook Handler
 *
 * Handles:
 *   GET  - Webhook verification from Meta App Dashboard
 *   POST - Incoming message events from Facebook Messenger
 *
 * Setup in Meta App Dashboard:
 *   Webhook URL:   https://your-domain.com/facebook-webhook.php
 *   Verify Token:  (value stored in facebook_accounts.verify_token)
 *   Subscriptions: messages, message_deliveries, message_reads
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/FacebookMessengerAPI.php';

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function fbLog(string $level, string $message, array $context = []): void
{
    $entry = date('Y-m-d H:i:s') . " [{$level}] facebook-webhook: {$message}";
    if ($context) {
        $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($entry);
}

/**
 * Notify Next.js webhook-notify endpoint so Pusher events are fired.
 */
function notifyNextJs(array $payload): void
{
    if (!defined('NEXTJS_API_URL') || !NEXTJS_API_URL) {
        return;
    }

    $url = rtrim(NEXTJS_API_URL, '/') . '/api/inbox/webhook-notify';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . (defined('INTERNAL_API_SECRET') ? INTERNAL_API_SECRET : ''),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        fbLog('warn', "webhook-notify returned {$httpCode}", ['response' => $response]);
    }
}

/**
 * Get or create a user record for a Facebook PSID.
 *
 * @param PDO                  $db
 * @param FacebookMessengerAPI $fbApi
 * @param string               $psid            Facebook Page-Scoped ID
 * @param int                  $facebookAccountId
 *
 * @return array{id:int, display_name:string, picture_url:string}
 */
function getOrCreateFacebookUser(
    PDO $db,
    FacebookMessengerAPI $fbApi,
    string $psid,
    int $facebookAccountId
): array {
    fbLog('debug', "getOrCreateFacebookUser called", [
        'psid' => $psid,
        'facebook_account_id' => $facebookAccountId
    ]);

    // Check existing user
    $stmt = $db->prepare("
        SELECT id, display_name, picture_url
        FROM users
        WHERE platform = 'facebook'
          AND platform_user_id = ?
          AND facebook_account_id = ?
        LIMIT 1
    ");
    $stmt->execute([$psid, $facebookAccountId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    fbLog('debug', "Existing user check", ['found' => (bool)$user, 'user_id' => $user['id'] ?? null]);

    // Fetch profile from Facebook
    $profile     = $fbApi->getProfile($psid);
    $displayName = $profile['name'] ?? ('FB User ' . substr($psid, -6));
    $pictureUrl  = $profile['profile_pic'] ?? '';

    fbLog('debug', "Facebook profile fetched", [
        'name' => $displayName,
        'has_picture' => !empty($pictureUrl)
    ]);

    if ($user) {
        fbLog('info', "Updating existing Facebook user", ['user_id' => $user['id']]);
        // Update profile if changed
        $stmt = $db->prepare("
            UPDATE users
            SET display_name = ?, picture_url = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$displayName, $pictureUrl, $user['id']]);
        
        // Cast to int for type safety
        return [
            'id'           => (int) $user['id'],
            'display_name' => $displayName,
            'picture_url'  => $pictureUrl,
        ];
    }

    // Create new user
    fbLog('info', "Creating new Facebook user", [
        'psid' => $psid,
        'facebook_account_id' => $facebookAccountId,
        'display_name' => $displayName
    ]);

    try {
        $stmt = $db->prepare("
            INSERT INTO users
                (platform, platform_user_id, facebook_account_id,
                 line_user_id, display_name, picture_url,
                 chat_status, created_at, updated_at)
            VALUES
                ('facebook', ?, ?,
                 ?, ?, ?,
                 'new', NOW(), NOW())
        ");
        // line_user_id reuses psid as a placeholder so the NOT NULL constraint is satisfied
        $stmt->execute([
            $psid,
            $facebookAccountId,
            $psid,
            $displayName,
            $pictureUrl,
        ]);

        $newUserId = (int) $db->lastInsertId();
        fbLog('info', "New Facebook user created successfully", ['user_id' => $newUserId]);

        return [
            'id'           => $newUserId,
            'display_name' => $displayName,
            'picture_url'  => $pictureUrl,
        ];
    } catch (PDOException $e) {
        fbLog('error', "Failed to create new Facebook user: " . $e->getMessage(), [
            'psid' => $psid,
            'facebook_account_id' => $facebookAccountId,
            'error_code' => $e->getCode()
        ]);
        throw $e;
    }
}

/**
 * Save an incoming message to the messages table.
 *
 * @return int Inserted message ID
 */
function saveIncomingMessage(
    PDO $db,
    int $userId,
    int $facebookAccountId,
    string $messageType,
    string $content,
    ?string $mediaUrl = null
): int {
    $stmt = $db->prepare("
        INSERT INTO messages
            (platform, line_account_id, user_id, direction, message_type,
             content, media_url, is_read, created_at, updated_at)
        VALUES
            ('facebook', ?, ?, 'incoming', ?,
             ?, ?, 0, NOW(), NOW())
    ");
    // line_account_id is nullable; we store NULL for non-LINE platforms
    $stmt->execute([null, $userId, $messageType, $content, $mediaUrl]);

    // Update user's last_interaction
    $db->prepare("UPDATE users SET last_interaction = NOW() WHERE id = ?")
       ->execute([$userId]);

    return (int) $db->lastInsertId();
}

// -----------------------------------------------------------------------
// Load Facebook account from DB
// -----------------------------------------------------------------------

$db = Database::getInstance()->getConnection();

/**
 * Load the Facebook account that matches the given page_id.
 * If ?account=<id> is provided we use that; otherwise we look up by page_id
 * from the webhook payload (available after signature verification).
 */
function loadFacebookAccount(PDO $db, ?string $pageId = null, ?int $accountId = null): ?array
{
    if ($accountId !== null) {
        $stmt = $db->prepare("SELECT * FROM facebook_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$accountId]);
    } elseif ($pageId !== null) {
        $stmt = $db->prepare("SELECT * FROM facebook_accounts WHERE page_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$pageId]);
    } else {
        $stmt = $db->query("SELECT * FROM facebook_accounts WHERE is_active = 1 LIMIT 1");
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -----------------------------------------------------------------------
// GET – Webhook verification
// -----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // PHP converts hub.mode → hub_mode, hub.verify_token → hub_verify_token, etc.
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    if ($mode !== 'subscribe') {
        http_response_code(400);
        exit('Bad request');
    }

    // Load any active Facebook account to validate the verify_token
    $accountId = isset($_GET['account']) ? (int) $_GET['account'] : null;
    $account   = loadFacebookAccount($db, null, $accountId);

    if (!$account) {
        http_response_code(404);
        exit('No Facebook account configured');
    }

    $fbApi = new FacebookMessengerAPI($account);

    if (!$fbApi->validateVerifyToken($token)) {
        http_response_code(403);
        exit('Verification failed');
    }

    // Echo back the challenge to complete verification
    http_response_code(200);
    echo $challenge;
    exit;
}

// -----------------------------------------------------------------------
// POST – Event notifications
// -----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$rawBody  = file_get_contents('php://input');
$payload  = json_decode($rawBody, true);

fbLog('debug', 'Webhook POST received', [
    'payload_size' => strlen($rawBody),
    'entry_count' => count($payload['entry'] ?? []),
    'object' => $payload['object'] ?? null
]);

// Respond 200 immediately (Meta requires response within 5 seconds)
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);

// Flush output to Meta before processing
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_flush();
    flush();
}

// -----------------------------------------------------------------------
// Validate payload structure
// -----------------------------------------------------------------------

if (($payload['object'] ?? '') !== 'page') {
    fbLog('warn', 'Non-page webhook object received', ['object' => $payload['object'] ?? null]);
    exit;
}

// -----------------------------------------------------------------------
// Process each entry / messaging event
// -----------------------------------------------------------------------

foreach ($payload['entry'] ?? [] as $entry) {
    $pageId  = (string) ($entry['id'] ?? '');
    $messagingCount = count($entry['messaging'] ?? []);
    
    fbLog('debug', 'Processing entry', [
        'page_id' => $pageId,
        'messaging_events' => $messagingCount
    ]);
    
    $account = loadFacebookAccount($db, $pageId);

    if (!$account) {
        fbLog('warn', "No active Facebook account for page_id={$pageId}");
        continue;
    }

    $fbApi = new FacebookMessengerAPI($account);

    // Validate signature (X-Hub-Signature-256)
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($signature && !$fbApi->validateSignature($rawBody, $signature)) {
        fbLog('error', 'Invalid X-Hub-Signature-256 for page_id=' . $pageId);
        continue;
    }

    foreach ($entry['messaging'] ?? [] as $messagingEvent) {
        $senderId = $messagingEvent['sender']['id'] ?? 'unknown';
        fbLog('debug', 'Processing messaging event', [
            'sender_id' => $senderId,
            'has_message' => isset($messagingEvent['message']),
            'has_delivery' => isset($messagingEvent['delivery']),
            'has_read' => isset($messagingEvent['read'])
        ]);
        
        try {
            processMessagingEvent($db, $fbApi, $account, $messagingEvent);
        } catch (Throwable $e) {
            fbLog('error', 'Error processing messaging event: ' . $e->getMessage(), [
                'event' => $messagingEvent,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

exit;

// -----------------------------------------------------------------------
// Event processor
// -----------------------------------------------------------------------

function processMessagingEvent(
    PDO $db,
    FacebookMessengerAPI $fbApi,
    array $account,
    array $event
): void {
    $senderId    = (string) ($event['sender']['id']    ?? '');
    $recipientId = (string) ($event['recipient']['id'] ?? '');

    if (!$senderId) {
        return;
    }

    // Ignore echo events (messages sent by the page itself)
    if ($senderId === $account['page_id']) {
        return;
    }

    // ---- Message event ----
    if (isset($event['message'])) {
        $msg         = $event['message'];
        $messageId   = $msg['mid'] ?? '';
        $text        = $msg['text'] ?? '';
        $attachments = $msg['attachments'] ?? [];

        // Deduplicate by Facebook message ID
        if ($messageId) {
            $stmt = $db->prepare("SELECT id FROM webhook_events WHERE event_id = ? LIMIT 1");
            $stmt->execute(['fb_' . $messageId]);
            if ($stmt->fetchColumn()) {
                return; // Already processed
            }
            $db->prepare("INSERT IGNORE INTO webhook_events (event_id) VALUES (?)")
               ->execute(['fb_' . $messageId]);
        }

        $facebookAccountId = (int) $account['id'];
        $user = getOrCreateFacebookUser($db, $fbApi, $senderId, $facebookAccountId);

        // Determine message type and content
        $messageType = 'text';
        $content     = $text;
        $mediaUrl    = null;

        if (!$text && $attachments) {
            $attachment  = $attachments[0];
            $attachType  = $attachment['type'] ?? 'file';
            $attachUrl   = $attachment['payload']['url'] ?? '';

            switch ($attachType) {
                case 'image':
                    $messageType = 'image';
                    $content     = $attachUrl;
                    $mediaUrl    = $attachUrl;
                    break;
                case 'video':
                    $messageType = 'video';
                    $content     = $attachUrl;
                    $mediaUrl    = $attachUrl;
                    break;
                case 'audio':
                    $messageType = 'audio';
                    $content     = $attachUrl;
                    $mediaUrl    = $attachUrl;
                    break;
                default:
                    $messageType = 'file';
                    $content     = $attachUrl;
                    $mediaUrl    = $attachUrl;
            }
        }

        if (!$content && !$mediaUrl) {
            return; // Nothing to save
        }

        $savedMsgId = saveIncomingMessage(
            $db,
            (int) $user['id'],
            $facebookAccountId,
            $messageType,
            $content,
            $mediaUrl
        );

        fbLog('info', "Message saved from PSID={$senderId} user_id={$user['id']} msg_id={$savedMsgId}");

        // Mark as read on Facebook side
        try {
            $fbApi->markAsRead($senderId);
        } catch (Throwable $e) {
            fbLog('warn', 'markAsRead failed: ' . $e->getMessage());
        }

        // Notify Next.js → Pusher → UI
        notifyNextJs([
            'type' => 'new_message',
            'data' => [
                'conversationId' => (string) $user['id'],
                'message'        => [
                    'id'          => (string) $savedMsgId,
                    'userId'      => (string) $user['id'],
                    'direction'   => 'incoming',
                    'messageType' => $messageType,
                    'content'     => $content,
                    'mediaUrl'    => $mediaUrl,
                    'platform'    => 'facebook',
                    'createdAt'   => date('c'),
                    'sentBy'      => null,
                ],
            ],
        ]);

        return;
    }

    // ---- Delivery event ----
    if (isset($event['delivery'])) {
        // Optionally mark outgoing messages as delivered
        return;
    }

    // ---- Read event ----
    if (isset($event['read'])) {
        // Optionally update read receipts
        return;
    }
}
