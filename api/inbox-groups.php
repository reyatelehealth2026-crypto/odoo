<?php
/**
 * LINE Groups API
 * Manages LINE group chats based on LINE Messaging API
 * 
 * Endpoints:
 * - GET ?action=get_groups - List all groups
 * - GET ?action=get_group&id={id} - Get group detail
 * - GET ?action=get_members&group_id={id} - Get group members
 * - GET ?action=get_messages&group_id={id} - Get group messages
 * - GET ?action=get_stats - Get group statistics
 * - POST action=send_message - Send message to group
 * - POST action=leave_group - Leave group
 * - POST action=sync_group - Sync group info from LINE API
 */

// Prevent any output before JSON response
ob_start();

// Set error handling to prevent HTML error pages
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Set JSON headers first
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Admin-ID, X-Line-Account-ID');

// Custom error handler to catch all errors and return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error: ' . $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
});

// Custom exception handler
set_exception_handler(function($exception) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine()
    ]);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean();
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Debug logging
error_log("inbox-groups.php - Method: $method, Action: $action");
error_log("inbox-groups.php - GET params: " . json_encode($_GET));
error_log("inbox-groups.php - POST params: " . json_encode($_POST));

// Get session data
$adminId = $_SESSION['admin_id'] ?? $_SERVER['HTTP_X_ADMIN_ID'] ?? null;
$lineAccountId = $_SESSION['current_bot_id'] ?? $_SERVER['HTTP_X_LINE_ACCOUNT_ID'] ?? $_GET['line_account_id'] ?? null;

// Convert to int if not null
if ($lineAccountId !== null) {
    $lineAccountId = (int)$lineAccountId;
}

error_log("inbox-groups.php - Admin ID: $adminId, Line Account ID: " . ($lineAccountId ?? 'null'));

/**
 * Send JSON response
 */
function sendResponse($data, $statusCode = 200) {
    ob_clean(); // Clear any output buffer
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 */
function sendError($message, $statusCode = 400) {
    ob_clean(); // Clear any output buffer
    sendResponse(['success' => false, 'error' => $message], $statusCode);
}

try {
    // Add default action if empty
    if (empty($action)) {
        error_log("inbox-groups.php - WARNING: Empty action, defaulting to get_groups");
        $action = 'get_groups';
    }
    
    switch ($action) {
        
        // ============================================
        // GET GROUPS - List all groups
        // ============================================
        case 'get_groups':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $isActive = isset($_GET['is_active']) ? (int)$_GET['is_active'] : null;
            $groupType = $_GET['group_type'] ?? null;
            $search = $_GET['search'] ?? null;

            $sql = "SELECT g.*, la.name as bot_name 
                    FROM line_groups g
                    LEFT JOIN line_accounts la ON g.line_account_id = la.id
                    WHERE 1=1";
            $params = [];

            // Only filter by line_account_id if it's provided and not 0
            if ($lineAccountId && $lineAccountId > 0) {
                $sql .= " AND g.line_account_id = ?";
                $params[] = $lineAccountId;
            }

            if ($isActive !== null) {
                $sql .= " AND g.is_active = ?";
                $params[] = $isActive;
            }

            if ($groupType) {
                $sql .= " AND g.group_type = ?";
                $params[] = $groupType;
            }

            if ($search) {
                $sql .= " AND g.group_name LIKE ?";
                $params[] = "%{$search}%";
            }

            $sql .= " ORDER BY g.is_active DESC, g.joined_at DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get stats
            $statsSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(member_count) as total_members,
                    SUM(total_messages) as total_messages
                FROM line_groups
                WHERE 1=1
            ";
            $statsParams = [];
            
            if ($lineAccountId && $lineAccountId > 0) {
                $statsSql .= " AND line_account_id = ?";
                $statsParams[] = $lineAccountId;
            }
            
            $statsStmt = $db->prepare($statsSql);
            $statsStmt->execute($statsParams);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'success' => true,
                'data' => [
                    'groups' => $groups,
                    'stats' => [
                        'total' => (int)($stats['total'] ?? 0),
                        'active' => (int)($stats['active'] ?? 0),
                        'totalMembers' => (int)($stats['total_members'] ?? 0),
                        'totalMessages' => (int)($stats['total_messages'] ?? 0)
                    ]
                ]
            ]);
            break;

        // ============================================
        // GET GROUP - Get single group detail
        // ============================================
        case 'get_group':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $groupId = (int)($_GET['group_id'] ?? $_GET['id'] ?? 0);
            if (!$groupId) {
                sendError('Group ID is required');
            }

            $stmt = $db->prepare("
                SELECT g.*, la.name as bot_name 
                FROM line_groups g
                LEFT JOIN line_accounts la ON g.line_account_id = la.id
                WHERE g.id = ?
            ");
            $stmt->execute([$groupId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                sendError('Group not found', 404);
            }

            // Format for frontend
            $formattedGroup = [
                'id' => (int)$group['id'],
                'lineAccountId' => (int)$group['line_account_id'],
                'lineGroupId' => $group['group_id'],
                'groupType' => $group['group_type'],
                'groupName' => $group['group_name'],
                'pictureUrl' => $group['picture_url'],
                'memberCount' => (int)$group['member_count'],
                'isActive' => (bool)$group['is_active'],
                'joinedAt' => $group['joined_at'],
                'leftAt' => $group['left_at'],
                'totalMessages' => (int)$group['total_messages'],
                'botName' => $group['bot_name']
            ];

            sendResponse([
                'success' => true,
                'data' => $formattedGroup
            ]);
            break;

        // ============================================
        // GET MEMBERS - Get group members
        // ============================================
        case 'get_members':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $groupId = (int)($_GET['group_id'] ?? 0);
            if (!$groupId) {
                sendError('Group ID is required');
            }

            $stmt = $db->prepare("
                SELECT * FROM line_group_members 
                WHERE group_id = ? 
                ORDER BY is_active DESC, total_messages DESC
            ");
            $stmt->execute([$groupId]);
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format for frontend
            $formattedMembers = array_map(function($member) {
                return [
                    'id' => (int)$member['id'],
                    'groupId' => (int)$member['group_id'],
                    'lineUserId' => $member['line_user_id'],
                    'displayName' => $member['display_name'],
                    'pictureUrl' => $member['picture_url'],
                    'isActive' => (bool)$member['is_active'],
                    'totalMessages' => (int)($member['total_messages'] ?? 0),
                    'lastMessageAt' => $member['last_message_at'] ?? null
                ];
            }, $members);

            sendResponse([
                'success' => true,
                'data' => $formattedMembers
            ]);
            break;

        // ============================================
        // GET MESSAGES - Get group messages
        // ============================================
        case 'get_messages':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $groupId = (int)($_GET['group_id'] ?? 0);
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);

            if (!$groupId) {
                sendError('Group ID is required');
            }

            $stmt = $db->prepare("
                SELECT 
                    gm.*,
                    CASE 
                        WHEN gm.line_user_id = 'system' THEN la.name
                        ELSE lgm.display_name
                    END as display_name,
                    CASE 
                        WHEN gm.line_user_id = 'system' THEN NULL
                        ELSE lgm.picture_url
                    END as senderPicture
                FROM line_group_messages gm
                LEFT JOIN line_group_members lgm ON gm.group_id = lgm.group_id AND gm.line_user_id = lgm.line_user_id
                LEFT JOIN line_groups lg ON gm.group_id = lg.id
                LEFT JOIN line_accounts la ON lg.line_account_id = la.id
                WHERE gm.group_id = ? 
                ORDER BY gm.created_at ASC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$groupId, $limit, $offset]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format messages for frontend
            $formattedMessages = array_map(function($msg) {
                return [
                    'id' => (int)$msg['id'],
                    'lineMessageId' => $msg['message_id'] ?? '',
                    'lineUserId' => $msg['line_user_id'] ?? '',
                    'messageType' => $msg['message_type'] ?? 'text',
                    'content' => $msg['content'] ?? '',
                    'createdAt' => $msg['created_at'],
                    'senderName' => $msg['display_name'] ?? 'Unknown User',
                    'senderPicture' => $msg['senderPicture'] ?? null,
                    'isBot' => ($msg['line_user_id'] === 'system')
                ];
            }, $messages);

            sendResponse([
                'success' => true,
                'data' => $formattedMessages
            ]);
            break;

        // ============================================
        // GET STATS - Get group statistics
        // ============================================
        case 'get_stats':
            if ($method !== 'GET') {
                sendError('Method not allowed', 405);
            }

            $statsSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                    SUM(member_count) as total_members,
                    SUM(total_messages) as total_messages
                FROM line_groups
                WHERE 1=1
            ";
            $statsParams = [];
            
            if ($lineAccountId && $lineAccountId > 0) {
                $statsSql .= " AND line_account_id = ?";
                $statsParams[] = $lineAccountId;
            }
            
            $stmt = $db->prepare($statsSql);
            $stmt->execute($statsParams);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            sendResponse([
                'success' => true,
                'data' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'active' => (int)($stats['active'] ?? 0),
                    'totalMembers' => (int)($stats['total_members'] ?? 0),
                    'totalMessages' => (int)($stats['total_messages'] ?? 0)
                ]
            ]);
            break;

        // ============================================
        // SEND MESSAGE - Send message to group
        // ============================================
        case 'send_message':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?: $_POST;

            $groupDbId = (int)($data['group_id'] ?? 0);
            $message = $data['message'] ?? '';

            if (!$groupDbId || !$message) {
                sendError('Group ID and message are required');
            }

            // Get group info
            $stmt = $db->prepare("SELECT * FROM line_groups WHERE id = ?");
            $stmt->execute([$groupDbId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                sendError('Group not found', 404);
            }

            if (!$group['is_active']) {
                sendError('Cannot send message to inactive group');
            }

            // Send message via LINE API
            try {
                $manager = new LineAccountManager($db);
                $line = $manager->getLineAPI($group['line_account_id']);
                
                $result = $line->pushMessage($group['group_id'], $message);

                // Save message to database
                $stmt = $db->prepare("
                    INSERT INTO line_group_messages 
                    (group_id, line_user_id, message_type, content, message_id, created_at)
                    VALUES (?, ?, 'text', ?, ?, NOW())
                ");
                
                // Use bot's user ID or a system identifier
                $botUserId = 'system'; // You can get actual bot user ID from LINE API if needed
                $messageId = $result['sentMessages'][0]['id'] ?? uniqid('msg_');
                
                $stmt->execute([
                    $groupDbId,
                    $botUserId,
                    $message,
                    $messageId
                ]);

                // Update group's total messages count
                $stmt = $db->prepare("
                    UPDATE line_groups 
                    SET total_messages = total_messages + 1, 
                        last_activity_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$groupDbId]);

                sendResponse([
                    'success' => true,
                    'message' => 'Message sent successfully',
                    'data' => [
                        'messageId' => $messageId
                    ]
                ]);
            } catch (Exception $e) {
                error_log("send_message error: " . $e->getMessage());
                sendError('Failed to send message: ' . $e->getMessage(), 500);
            }
            break;

        // ============================================
        // LEAVE GROUP - Bot leaves group
        // ============================================
        case 'leave_group':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?: $_POST;

            $groupDbId = (int)($data['group_id'] ?? 0);

            if (!$groupDbId) {
                sendError('Group ID is required');
            }

            // Get group info
            $stmt = $db->prepare("SELECT * FROM line_groups WHERE id = ?");
            $stmt->execute([$groupDbId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                sendError('Group not found', 404);
            }

            // Leave group via LINE API
            try {
                $manager = new LineAccountManager($db);
                $line = $manager->getLineAPI($group['line_account_id']);
                
                if ($group['group_type'] === 'group') {
                    $line->leaveGroup($group['group_id']);
                } else {
                    $line->leaveRoom($group['group_id']);
                }

                // Update database
                $stmt = $db->prepare("UPDATE line_groups SET is_active = 0, left_at = NOW() WHERE id = ?");
                $stmt->execute([$groupDbId]);

                sendResponse([
                    'success' => true,
                    'message' => 'Left group successfully'
                ]);
            } catch (Exception $e) {
                sendError('Failed to leave group: ' . $e->getMessage(), 500);
            }
            break;

        // ============================================
        // SYNC GROUP - Sync group info from LINE API
        // ============================================
        case 'sync_group':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?: $_POST;

            $groupDbId = (int)($data['group_id'] ?? 0);

            if (!$groupDbId) {
                sendError('Group ID is required');
            }

            // Get group info
            $stmt = $db->prepare("SELECT * FROM line_groups WHERE id = ?");
            $stmt->execute([$groupDbId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                sendError('Group not found', 404);
            }

            // Sync from LINE API
            try {
                $manager = new LineAccountManager($db);
                $line = $manager->getLineAPI($group['line_account_id']);
                
                // Get group summary
                $summary = $line->getGroupSummary($group['group_id']);
                
                // Get member count
                $memberCount = $line->getGroupMemberCount($group['group_id']);

                // Update database
                $stmt = $db->prepare("
                    UPDATE line_groups 
                    SET group_name = ?, picture_url = ?, member_count = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $summary['groupName'] ?? $group['group_name'],
                    $summary['pictureUrl'] ?? $group['picture_url'],
                    $memberCount['count'] ?? $group['member_count'],
                    $groupDbId
                ]);

                sendResponse([
                    'success' => true,
                    'message' => 'Group synced successfully',
                    'data' => [
                        'groupName' => $summary['groupName'] ?? null,
                        'memberCount' => $memberCount['count'] ?? 0
                    ]
                ]);
            } catch (Exception $e) {
                sendError('Failed to sync group: ' . $e->getMessage(), 500);
            }
            break;

        // ============================================
        // SYNC MEMBERS - Sync group members from LINE API
        // ============================================
        case 'sync_members':
            if ($method !== 'POST') {
                sendError('Method not allowed', 405);
            }

            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?: $_POST;

            $groupDbId = (int)($data['group_id'] ?? 0);

            if (!$groupDbId) {
                sendError('Group ID is required');
            }

            // Get group info
            $stmt = $db->prepare("SELECT * FROM line_groups WHERE id = ?");
            $stmt->execute([$groupDbId]);
            $group = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$group) {
                sendError('Group not found', 404);
            }

            if (!$group['is_active']) {
                sendError('Cannot sync members for inactive group');
            }

            // Sync members from LINE API
            try {
                $manager = new LineAccountManager($db);
                $line = $manager->getLineAPI($group['line_account_id']);
                
                $allMemberIds = [];
                $start = null;
                $pageCount = 0;
                $maxPages = 100; // Safety limit (100 pages * 300 members = 30,000 max)

                // Get all member IDs (paginated)
                do {
                    $result = $line->getGroupMemberIds($group['group_id'], $start);
                    $memberIds = $result['memberIds'] ?? [];
                    $start = $result['next'] ?? null;
                    
                    $allMemberIds = array_merge($allMemberIds, $memberIds);
                    $pageCount++;
                    
                    error_log("sync_members: Page {$pageCount}, got " . count($memberIds) . " members, total: " . count($allMemberIds));
                    
                    if ($pageCount >= $maxPages) {
                        error_log("sync_members: Reached max pages limit ({$maxPages})");
                        break;
                    }
                } while ($start !== null);

                if (empty($allMemberIds)) {
                    sendError('No members found in group');
                }

                // Mark all existing members as inactive first
                $stmt = $db->prepare("UPDATE line_group_members SET is_active = 0 WHERE group_id = ?");
                $stmt->execute([$groupDbId]);

                // Fetch profiles and save to database
                $syncedCount = 0;
                $errorCount = 0;
                
                foreach ($allMemberIds as $userId) {
                    try {
                        // Get member profile
                        $profile = $line->getGroupMemberProfile($group['group_id'], $userId);
                        
                        if (empty($profile) || !isset($profile['userId'])) {
                            error_log("sync_members: Failed to get profile for user {$userId}");
                            $errorCount++;
                            continue;
                        }

                        // Insert or update member
                        $stmt = $db->prepare("
                            INSERT INTO line_group_members 
                            (group_id, line_user_id, display_name, picture_url, is_active, joined_at, created_at, updated_at)
                            VALUES (?, ?, ?, ?, 1, NOW(), NOW(), NOW())
                            ON DUPLICATE KEY UPDATE
                                display_name = VALUES(display_name),
                                picture_url = VALUES(picture_url),
                                is_active = 1,
                                updated_at = NOW()
                        ");
                        
                        $stmt->execute([
                            $groupDbId,
                            $profile['userId'],
                            $profile['displayName'] ?? 'Unknown',
                            $profile['pictureUrl'] ?? null
                        ]);
                        
                        $syncedCount++;
                        
                        // Rate limiting: Sleep 50ms between API calls to avoid hitting rate limits
                        usleep(50000);
                        
                    } catch (Exception $e) {
                        error_log("sync_members: Error syncing member {$userId}: " . $e->getMessage());
                        $errorCount++;
                    }
                }

                // Update group member count
                $stmt = $db->prepare("
                    UPDATE line_groups 
                    SET member_count = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$syncedCount, $groupDbId]);

                sendResponse([
                    'success' => true,
                    'message' => "Synced {$syncedCount} members successfully",
                    'data' => [
                        'syncedCount' => $syncedCount,
                        'errorCount' => $errorCount,
                        'totalFound' => count($allMemberIds)
                    ]
                ]);
                
            } catch (Exception $e) {
                error_log("sync_members: Exception: " . $e->getMessage());
                sendError('Failed to sync members: ' . $e->getMessage(), 500);
            }
            break;

        default:
            sendError('Invalid action', 400);
    }

} catch (PDOException $e) {
    error_log("Database error in inbox-groups.php: " . $e->getMessage());
    sendError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("Error in inbox-groups.php: " . $e->getMessage());
    sendError('Server error: ' . $e->getMessage(), 500);
}
