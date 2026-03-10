<?php
/**
 * TikTok Shop Webhook Handler
 *
 * Handles incoming event notifications from TikTok Shop Partner API.
 * Events are signed with HmacSHA256(timestamp + body, app_secret).
 *
 * Setup in TikTok Shop Partner Center:
 *   Webhook URL: https://your-domain.com/tiktok-webhook.php
 *   Events:      im.message (new customer message)
 *
 * Reference: https://partner.tiktokshop.com/docv2/page/tts-webhooks-overview
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/TikTokShopAPI.php';

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------

function tkLog(string $level, string $message, array $context = []): void
{
    $entry = date('Y-m-d H:i:s') . " [{$level}] tiktok-webhook: {$message}";
    if ($context) {
        $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    error_log($entry);
}

/**
 * Notify Next.js webhook-notify endpoint so Pusher events are fired.
 */
function notifyNextJsTk(array $payload): void
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
        tkLog('warn', "webhook-notify returned {$httpCode}", ['response' => $response]);
    }
}

/**
 * Load the TikTok Shop account that matches the given shop_id.
 */
function loadTikTokAccount(PDO $db, ?string $shopId = null, ?int $accountId = null): ?array
{
    if ($accountId !== null) {
        $stmt = $db->prepare("SELECT * FROM tiktok_shop_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$accountId]);
    } elseif ($shopId !== null) {
        $stmt = $db->prepare("SELECT * FROM tiktok_shop_accounts WHERE shop_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$shopId]);
    } else {
        $stmt = $db->query("SELECT * FROM tiktok_shop_accounts WHERE is_active = 1 LIMIT 1");
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Get or create a user record for a TikTok buyer.
 *
 * @return array{id:int, display_name:string, picture_url:string}
 */
function getOrCreateTikTokUser(
    PDO $db,
    TikTokShopAPI $tkApi,
    string $buyerUserId,
    string $conversationId,
    int $tiktokAccountId
): array {
    // Check existing user (keyed by platform_user_id = buyer_user_id)
    $stmt = $db->prepare("
        SELECT id, display_name, picture_url
        FROM users
        WHERE platform = 'tiktok'
          AND platform_user_id = ?
          AND tiktok_account_id = ?
        LIMIT 1
    ");
    $stmt->execute([$buyerUserId, $tiktokAccountId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Try to fetch buyer profile
    $displayName = 'TikTok Buyer';
    $pictureUrl  = '';
    try {
        $profileResp = $tkApi->getBuyerProfile($buyerUserId);
        if (!empty($profileResp['data']['buyer_name'])) {
            $displayName = $profileResp['data']['buyer_name'];
        }
        if (!empty($profileResp['data']['avatar_url'])) {
            $pictureUrl = $profileResp['data']['avatar_url'];
        }
    } catch (Throwable $e) {
        tkLog('warn', 'getBuyerProfile failed: ' . $e->getMessage());
    }

    if ($user) {
        $db->prepare("UPDATE users SET display_name = ?, picture_url = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$displayName, $pictureUrl, $user['id']]);
        $user['display_name'] = $displayName;
        $user['picture_url']  = $pictureUrl;
        return $user;
    }

    // Create new user
    // line_user_id reuses buyer_user_id as placeholder (NOT NULL constraint)
    $stmt = $db->prepare("
        INSERT INTO users
            (platform, platform_user_id, tiktok_account_id,
             line_user_id, display_name, picture_url,
             chat_status, created_at, updated_at)
        VALUES
            ('tiktok', ?, ?,
             ?, ?, ?,
             'new', NOW(), NOW())
    ");
    $stmt->execute([
        $buyerUserId,
        $tiktokAccountId,
        $buyerUserId,
        $displayName,
        $pictureUrl,
    ]);

    return [
        'id'           => (int) $db->lastInsertId(),
        'display_name' => $displayName,
        'picture_url'  => $pictureUrl,
    ];
}

/**
 * Save an incoming TikTok message to the messages table.
 *
 * @return int Inserted message ID
 */
function saveTikTokMessage(
    PDO $db,
    int $userId,
    string $messageType,
    string $content,
    ?string $mediaUrl = null
): int {
    $stmt = $db->prepare("
        INSERT INTO messages
            (platform, line_account_id, user_id, direction, message_type,
             content, media_url, is_read, created_at, updated_at)
        VALUES
            ('tiktok', NULL, ?, 'incoming', ?,
             ?, ?, 0, NOW(), NOW())
    ");
    $stmt->execute([$userId, $messageType, $content, $mediaUrl]);

    $db->prepare("UPDATE users SET last_interaction = NOW() WHERE id = ?")
       ->execute([$userId]);

    return (int) $db->lastInsertId();
}

// -----------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$rawBody   = file_get_contents('php://input');
$payload   = json_decode($rawBody, true);
$timestamp = $_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? (string) time();
$signature = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '';

$db = Database::getInstance()->getConnection();

// Respond 200 immediately (TikTok requires fast response)
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['code' => 0, 'message' => 'success']);

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_flush();
    flush();
}

// -----------------------------------------------------------------------
// Load account
// -----------------------------------------------------------------------

$shopId    = (string) ($payload['shop_id'] ?? '');
$accountId = isset($_GET['account']) ? (int) $_GET['account'] : null;
$account   = loadTikTokAccount($db, $shopId ?: null, $accountId);

if (!$account) {
    tkLog('warn', "No active TikTok account for shop_id={$shopId}");
    exit;
}

$tkApi = new TikTokShopAPI($account);

// -----------------------------------------------------------------------
// Validate signature
// -----------------------------------------------------------------------

if ($signature && !$tkApi->validateWebhook($rawBody, $signature, $timestamp)) {
    tkLog('error', 'Invalid webhook signature for shop_id=' . $shopId);
    exit;
}

// -----------------------------------------------------------------------
// Route by event type
// -----------------------------------------------------------------------

$eventType = (string) ($payload['type'] ?? '');
$data      = $payload['data'] ?? [];

tkLog('info', "Event received: {$eventType}", ['shop_id' => $shopId]);

switch ($eventType) {
    case 'im.message':
    case 'customer_service.message':
        processTikTokMessage($db, $tkApi, $account, $data);
        break;

    default:
        tkLog('info', "Unhandled event type: {$eventType}");
}

exit;

// -----------------------------------------------------------------------
// Message processor
// -----------------------------------------------------------------------

function processTikTokMessage(
    PDO $db,
    TikTokShopAPI $tkApi,
    array $account,
    array $data
): void {
    $conversationId = (string) ($data['conversation_id'] ?? '');
    $buyerUserId    = (string) ($data['buyer_user_id']    ?? $data['sender_id'] ?? '');
    $msgId          = (string) ($data['message_id']       ?? $data['id']        ?? '');

    if (!$conversationId || !$buyerUserId) {
        tkLog('warn', 'Missing conversation_id or buyer_user_id', $data);
        return;
    }

    // Deduplicate
    if ($msgId) {
        $stmt = $db->prepare("SELECT id FROM webhook_events WHERE event_id = ? LIMIT 1");
        $stmt->execute(['tk_' . $msgId]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $db->prepare("INSERT IGNORE INTO webhook_events (event_id) VALUES (?)")
           ->execute(['tk_' . $msgId]);
    }

    $tiktokAccountId = (int) $account['id'];
    $user = getOrCreateTikTokUser($db, $tkApi, $buyerUserId, $conversationId, $tiktokAccountId);

    // Determine message type
    $contentType = strtolower((string) ($data['content_type'] ?? 'text'));
    $rawContent  = (string) ($data['content'] ?? '');

    $messageType = 'text';
    $content     = $rawContent;
    $mediaUrl    = null;

    switch ($contentType) {
        case 'image':
            $messageType = 'image';
            $mediaUrl    = $rawContent;
            break;
        case 'video':
            $messageType = 'video';
            $mediaUrl    = $rawContent;
            break;
        case 'file':
            $messageType = 'file';
            $mediaUrl    = $rawContent;
            break;
        case 'order':
        case 'product':
            $messageType = 'text';
            $content     = '[' . strtoupper($contentType) . '] ' . $rawContent;
            break;
    }

    if (!$content && !$mediaUrl) {
        return;
    }

    $savedMsgId = saveTikTokMessage(
        $db,
        $user['id'],
        $messageType,
        $content,
        $mediaUrl
    );

    tkLog('info', "Message saved from buyer={$buyerUserId} user_id={$user['id']} msg_id={$savedMsgId}");

    // Notify Next.js → Pusher → UI
    notifyNextJsTk([
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
                'platform'    => 'tiktok',
                'createdAt'   => date('c'),
                'sentBy'      => null,
            ],
        ],
    ]);
}
