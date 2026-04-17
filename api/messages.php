<?php
/**
 * Messages API - Unified API for Chat System
 * Supports: get_messages, send_message, poll_new, mark_read
 */
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';
require_once __DIR__ . '/../classes/FacebookMessengerAPI.php';
require_once __DIR__ . '/../classes/TikTokShopAPI.php';

$db = Database::getInstance()->getConnection();
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_conversations':
            // Get list of conversations with last message - only users with messages
            $search = $_GET['search'] ?? '';
            $limit = min(intval($_GET['limit'] ?? 50), 100);
            $offset = intval($_GET['offset'] ?? 0);

            // Use JOIN to only get users with messages and get accurate last_time
            $sql = "SELECT u.id, u.line_user_id, u.display_name, u.picture_url, u.phone,
                    m_last.content as last_message,
                    m_last.message_type as last_type,
                    m_last.created_at as last_time,
                    (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread_count
                    FROM users u 
                    INNER JOIN (
                        SELECT user_id, MAX(id) as max_id
                        FROM messages
                        GROUP BY user_id
                    ) m_max ON u.id = m_max.user_id
                    INNER JOIN messages m_last ON m_max.max_id = m_last.id
                    WHERE u.line_account_id = ?";

            $params = [$currentBotId];

            if ($search) {
                $sql .= " AND (u.display_name LIKE ? OR u.phone LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }

            $sql .= " ORDER BY m_last.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total unread
            $stmt = $db->prepare("SELECT COUNT(*) FROM messages m 
                                  JOIN users u ON m.user_id = u.id 
                                  WHERE u.line_account_id = ? AND m.direction = 'incoming' AND m.is_read = 0");
            $stmt->execute([$currentBotId]);
            $totalUnread = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'conversations' => $conversations,
                'total_unread' => $totalUnread
            ]);
            break;

        case 'get_messages':
            // Get messages for a user
            $userId = intval($_GET['user_id'] ?? 0);
            $lastId = intval($_GET['last_id'] ?? 0);
            $limit = min(intval($_GET['limit'] ?? 50), 200);

            if (!$userId) {
                throw new Exception('user_id required');
            }

            $sql = "SELECT * FROM messages WHERE user_id = ?";
            $params = [$userId];

            if ($lastId > 0) {
                $sql .= " AND id > ?";
                $params[] = $lastId;
            }

            $sql .= " ORDER BY created_at ASC LIMIT ?";
            $params[] = $limit;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'count' => count($messages)
            ]);
            break;

        case 'poll':
            // Real-time polling - get new messages since last_id
            $userId = intval($_GET['user_id'] ?? 0);
            $lastId = intval($_GET['last_id'] ?? 0);

            if (!$userId) {
                throw new Exception('user_id required');
            }

            // Get new messages
            $stmt = $db->prepare("SELECT * FROM messages WHERE user_id = ? AND id > ? ORDER BY created_at ASC");
            $stmt->execute([$userId, $lastId]);
            $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get unread count for sidebar update
            $stmt = $db->prepare("SELECT u.id, 
                                  (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread
                                  FROM users u WHERE u.line_account_id = ? AND u.id != ?
                                  HAVING unread > 0");
            $stmt->execute([$currentBotId, $userId]);
            $unreadUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Check for recently updated conversations (within last poll interval)
            $stmt = $db->prepare("SELECT u.id, u.display_name, u.picture_url,
                                  (SELECT content FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_message,
                                  (SELECT message_type FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_type,
                                  (SELECT created_at FROM messages WHERE user_id = u.id ORDER BY created_at DESC LIMIT 1) as last_time,
                                  (SELECT COUNT(*) FROM messages WHERE user_id = u.id AND direction = 'incoming' AND is_read = 0) as unread_count
                                  FROM users u 
                                  WHERE u.line_account_id = ? 
                                  AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND))
                                  ORDER BY last_time DESC LIMIT 20");
            $stmt->execute([$currentBotId]);
            $updatedConversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'messages' => $newMessages,
                'unread_users' => $unreadUsers,
                'updated_conversations' => $updatedConversations,
                'timestamp' => time()
            ]);
            break;

        case 'send':
            // Send message to LINE only (don't save to database)
            // Next.js will handle saving to Prisma database
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $userId = intval($_POST['user_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $messageType = $_POST['type'] ?? 'text';
            $sentByParam = $_POST['sent_by'] ?? null;

            $replyToId = intval($_POST['reply_to_id'] ?? 0);
            // Accept quote_token from request (passed from Next.js API)
            $quoteTokenFromRequest = trim($_POST['quote_token'] ?? '');

            if ($userId <= 0 || empty($message)) {
                throw new Exception('user_id and message required');
            }
            // 2000-char limit applies only to plain text messages.
            // Flex/image/video/sticker payloads are JSON and can legitimately exceed 2000 chars.
            if ($messageType === 'text' && mb_strlen($message) > 2000) {
                throw new Exception('Message content is too long (max 2000 characters)');
            }

            // Get user info including platform
            $stmt = $db->prepare("
                SELECT line_user_id, line_account_id, reply_token, reply_token_expires,
                       COALESCE(platform, 'line') AS platform,
                       platform_user_id,
                       facebook_account_id,
                       tiktok_account_id
                FROM users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found');
            }

            $platform = $user['platform'] ?? 'line';

            // ----------------------------------------------------------------
            // Platform-aware send routing
            // ----------------------------------------------------------------

            if ($platform === 'facebook') {
                // --- Facebook Messenger ---
                $fbAccountId = (int) ($user['facebook_account_id'] ?? 0);
                $fbStmt = $db->prepare("SELECT * FROM facebook_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
                $fbStmt->execute([$fbAccountId]);
                $fbAccount = $fbStmt->fetch(PDO::FETCH_ASSOC);

                if (!$fbAccount) {
                    throw new Exception('Facebook account not found or inactive');
                }

                $fbApi  = new FacebookMessengerAPI($fbAccount);
                $psid   = $user['platform_user_id'] ?? $user['line_user_id'];
                $result = $fbApi->sendTextMessage($psid, $message);

                if (!($result['success'] ?? false)) {
                    throw new Exception('Facebook API Error: ' . json_encode($result['error'] ?? 'Unknown'));
                }

                echo json_encode([
                    'success'  => true,
                    'message'  => 'Message sent to Facebook Messenger successfully',
                    'platform' => 'facebook',
                ]);

            } elseif ($platform === 'tiktok') {
                // --- TikTok Shop ---
                $tkAccountId = (int) ($user['tiktok_account_id'] ?? 0);
                $tkStmt = $db->prepare("SELECT * FROM tiktok_shop_accounts WHERE id = ? AND is_active = 1 LIMIT 1");
                $tkStmt->execute([$tkAccountId]);
                $tkAccount = $tkStmt->fetch(PDO::FETCH_ASSOC);

                if (!$tkAccount) {
                    throw new Exception('TikTok Shop account not found or inactive');
                }

                $tkApi          = new TikTokShopAPI($tkAccount);
                $conversationId = $user['platform_user_id'] ?? $user['line_user_id'];
                $result         = $tkApi->sendMessage($conversationId, $message);

                if (!($result['success'] ?? false)) {
                    throw new Exception('TikTok API Error: ' . json_encode($result['message'] ?? 'Unknown'));
                }

                echo json_encode([
                    'success'  => true,
                    'message'  => 'Message sent to TikTok Shop successfully',
                    'platform' => 'tiktok',
                ]);

            } else {
                // --- LINE (default) ---

                // Get quoteToken - prioritize from request, then try database
                $quoteToken = null;
                if (!empty($quoteTokenFromRequest)) {
                    $quoteToken = $quoteTokenFromRequest;
                } elseif ($replyToId > 0) {
                    try {
                        $check = $db->query("SHOW COLUMNS FROM messages LIKE 'quote_token'");
                        if ($check->rowCount() > 0) {
                            $stmt = $db->prepare("SELECT quote_token FROM messages WHERE id = ?");
                            $stmt->execute([$replyToId]);
                            $quoteToken = $stmt->fetchColumn();
                        }

                        if (!$quoteToken) {
                            $stmt = $db->prepare("SELECT metadata FROM messages WHERE id = ?");
                            $stmt->execute([$replyToId]);
                            $metadata = $stmt->fetchColumn();
                            if ($metadata) {
                                $metadataObj = json_decode($metadata, true);
                                if (isset($metadataObj['quoteToken'])) {
                                    $quoteToken = $metadataObj['quoteToken'];
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // Ignore if column doesn't exist
                    }
                }

                // Build LINE message payload based on type.
                // For non-text types (flex/image/video/sticker/audio/location/imagemap),
                // $message is a JSON-encoded LINE message object — decode it.
                if ($messageType === 'text') {
                    $messagePayload = [
                        'type' => 'text',
                        'text' => $message,
                    ];
                    if ($quoteToken) {
                        $messagePayload['quoteToken'] = $quoteToken;
                    }
                } else {
                    $decoded = json_decode($message, true);
                    if (!is_array($decoded)) {
                        throw new Exception(
                            "Invalid $messageType payload: expected JSON object, got " .
                            substr($message, 0, 80)
                        );
                    }
                    // Ensure type field matches declared messageType
                    if (!isset($decoded['type'])) {
                        $decoded['type'] = $messageType;
                    }
                    if ($quoteToken) {
                        $decoded['quoteToken'] = $quoteToken;
                    }
                    $messagePayload = $decoded;
                }

                $lineManager = new LineAccountManager($db);
                $line        = $lineManager->getLineAPI($user['line_account_id']);

                if (method_exists($line, 'sendMessage')) {
                    $result = $line->sendMessage(
                        $user['line_user_id'],
                        $messagePayload,
                        $user['reply_token'] ?? null,
                        $user['reply_token_expires'] ?? null,
                        $db,
                        $userId
                    );
                } else {
                    $msgs   = is_array($messagePayload) ? [$messagePayload] : [['type' => 'text', 'text' => $messagePayload]];
                    $result = $line->pushMessage($user['line_user_id'], $msgs);
                    $result['method'] = 'push';
                }

                if ($result['code'] !== 200) {
                    // LINE API error body shape:
                    //   { "message": "...", "details": [{ "message": "...", "property": "..." }] }
                    $body = $result['body'] ?? [];
                    $errMsg = $body['message'] ?? ($result['error'] ?? 'Unknown');
                    if (!empty($body['details']) && is_array($body['details'])) {
                        $detailParts = [];
                        foreach ($body['details'] as $d) {
                            $detailParts[] = ($d['property'] ?? '') . ': ' . ($d['message'] ?? '');
                        }
                        $errMsg .= ' (' . implode('; ', $detailParts) . ')';
                    }
                    error_log('LINE API send failed (code ' . $result['code'] . '): ' . json_encode($result));
                    throw new Exception('LINE API Error: ' . $errMsg);
                }

                // Extract sentMessages from LINE API response
                // LINE returns: { "sentMessages": [{ "id": "LINE_MSG_ID", "quoteToken": "..." }] }
                $sentMessages = $result['body']['sentMessages'] ?? [];
                $sentLineMessageId = $sentMessages[0]['id'] ?? null;
                $sentQuoteToken = $sentMessages[0]['quoteToken'] ?? null;

                echo json_encode([
                    'success'        => true,
                    'message'        => 'Message sent to LINE successfully',
                    'method'         => $result['method'] ?? 'push',
                    'line_sent'      => true,
                    'platform'       => 'line',
                    'lineMessageId'  => $sentLineMessageId,
                    'quoteToken'     => $sentQuoteToken,
                    'sentMessages'   => $sentMessages,
                ]);
            }
            break;

        case 'mark_read':
            // Mark messages as read in local database
            $userId = intval($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

            if (!$userId) {
                throw new Exception('user_id required');
            }

            // Get markAsReadTokens before updating database
            $tokens = [];
            try {
                $stmt = $db->prepare("SELECT mark_as_read_token FROM messages WHERE user_id = ? AND direction = 'incoming' AND is_read = 0 AND mark_as_read_token IS NOT NULL");
                $stmt->execute([$userId]);
                $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                // Ignore if column doesn't exist
            }

            // Update local database
            $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND direction = 'incoming' AND is_read = 0");
            $stmt->execute([$userId]);
            $affected = $stmt->rowCount();

            // Notify LINE to mark as read
            if (!empty($tokens)) {
                try {
                    // Get bot ID for this user
                    $stmt = $db->prepare("SELECT line_account_id FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $lineAccountId = $stmt->fetchColumn();

                    if ($lineAccountId) {
                        $lineManager = new LineAccountManager($db);
                        $line = $lineManager->getLineAPI($lineAccountId);
                        if (method_exists($line, 'markMultipleAsRead')) {
                            $line->markMultipleAsRead($tokens);
                        }
                    }
                } catch (Exception $e) {
                    // Log error but don't fail the request
                    error_log("Failed to mark messages as read on LINE: " . $e->getMessage());
                }
            }

            echo json_encode([
                'success' => true,
                'marked' => $affected,
                'line_marked' => count($tokens)
            ]);
            break;

        case 'get_user':
            // Get user details
            $userId = intval($_GET['user_id'] ?? 0);

            if (!$userId) {
                throw new Exception('user_id required');
            }

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found');
            }

            // Get tags
            $tags = [];
            try {
                $stmt = $db->prepare("SELECT t.* FROM user_tags t 
                                      JOIN user_tag_assignments uta ON t.id = uta.tag_id 
                                      WHERE uta.user_id = ?");
                $stmt->execute([$userId]);
                $tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
            }

            // Get notes
            $notes = [];
            try {
                $stmt = $db->prepare("SELECT * FROM user_notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
                $stmt->execute([$userId]);
                $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
            }

            // Get orders
            $orders = [];
            try {
                $stmt = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
                $stmt->execute([$userId]);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
            }

            echo json_encode([
                'success' => true,
                'user' => $user,
                'tags' => $tags,
                'notes' => $notes,
                'orders' => $orders
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
