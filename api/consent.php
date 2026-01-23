<?php
/**
 * Consent API
 * จัดการความยินยอมของผู้ใช้ตาม PDPA
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance()->getConnection();

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Handle JSON body
$jsonInput = json_decode(file_get_contents('php://input'), true);
if ($jsonInput) {
    $action = $jsonInput['action'] ?? $action;
}

try {
    switch ($action) {
        case 'check':
            handleCheckConsent();
            break;
        case 'save':
            handleSaveConsent($jsonInput);
            break;
        case 'withdraw':
            handleWithdrawConsent($jsonInput);
            break;
        case 'history':
            handleGetHistory();
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage());
}

function jsonResponse($success, $message = '', $data = [])
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get user ID from LINE user ID
 */
function getUserFromLineId($lineUserId)
{
    global $db;

    if (!$lineUserId)
        return null;

    $stmt = $db->prepare("SELECT * FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if user has given all required consents
 */
function handleCheckConsent()
{
    global $db;

    $lineUserId = $_GET['line_user_id'] ?? null;

    if (!$lineUserId) {
        jsonResponse(false, 'LINE User ID required');
    }

    $user = getUserFromLineId($lineUserId);

    if (!$user) {
        jsonResponse(true, 'New user', [
            'is_new_user' => true,
            'all_consented' => false,
            'consents' => []
        ]);
    }

    // Check consent status
    $stmt = $db->prepare("
        SELECT consent_type, is_accepted, consent_version, accepted_at 
        FROM user_consents 
        WHERE user_id = ?
    ");
    $stmt->execute([$user['id']]);
    $consents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $consentMap = [];
    foreach ($consents as $c) {
        $consentMap[$c['consent_type']] = [
            'accepted' => (bool) $c['is_accepted'],
            'version' => $c['consent_version'],
            'date' => $c['accepted_at']
        ];
    }

    // Check if all required consents are given
    $requiredConsents = ['privacy_policy', 'terms_of_service'];
    $allConsented = true;
    foreach ($requiredConsents as $type) {
        if (empty($consentMap[$type]['accepted'])) {
            $allConsented = false;
            break;
        }
    }

    jsonResponse(true, '', [
        'is_new_user' => false,
        'all_consented' => $allConsented,
        'consents' => $consentMap,
        'user_id' => $user['id']
    ]);
}

/**
 * Save user consent
 */
function handleSaveConsent($data)
{
    global $db;

    $lineUserId = $data['line_user_id'] ?? null;
    $consents = $data['consents'] ?? [];

    if (!$lineUserId) {
        jsonResponse(false, 'LINE User ID required');
    }

    // Get or create user
    $user = getUserFromLineId($lineUserId);

    if (!$user) {
        // Create new user
        $stmt = $db->query("SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1");
        $account = $stmt->fetch();
        $lineAccountId = $account['id'] ?? 1;

        $stmt = $db->prepare("INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (?, ?, 'LIFF User')");
        $stmt->execute([$lineAccountId, $lineUserId]);
        $userId = $db->lastInsertId();
    } else {
        $userId = $user['id'];
    }

    // Get client info
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Get current versions from shop_settings (handle missing columns)
    $settings = ['privacy_policy_version' => '1.0', 'terms_version' => '1.0'];
    try {
        $checkCol = $db->query("SHOW COLUMNS FROM shop_settings LIKE 'privacy_policy_version'");
        if ($checkCol->rowCount() > 0) {
            $stmt = $db->query("SELECT privacy_policy_version, terms_version FROM shop_settings LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: $settings;
        }
    } catch (Exception $e) {
    }

    $db->beginTransaction();

    try {
        // Map consent types to versions
        $versionMap = [
            'privacy_policy' => $settings['privacy_policy_version'] ?? '1.0',
            'terms_of_service' => $settings['terms_version'] ?? '1.0',
            'health_data' => '1.0',
            'marketing' => '1.0'
        ];

        foreach ($consents as $type => $accepted) {
            $version = $versionMap[$type] ?? '1.0';

            // Upsert consent
            $stmt = $db->prepare("
                INSERT INTO user_consents (user_id, consent_type, consent_version, is_accepted, accepted_at, ip_address, user_agent)
                VALUES (?, ?, ?, ?, NOW(), ?, ?)
                ON DUPLICATE KEY UPDATE 
                    consent_version = VALUES(consent_version),
                    is_accepted = VALUES(is_accepted),
                    accepted_at = IF(VALUES(is_accepted) = 1, NOW(), accepted_at),
                    withdrawn_at = IF(VALUES(is_accepted) = 0, NOW(), NULL),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    updated_at = NOW()
            ");
            $stmt->execute([$userId, $type, $version, $accepted ? 1 : 0, $ipAddress, $userAgent]);


        }

        // Log consent action using ActivityLogger
        require_once __DIR__ . '/../classes/ActivityLogger.php';
        $activityLogger = ActivityLogger::getInstance($db);
        foreach ($consents as $type => $accepted) {
            $activityLogger->logConsent(
                $accepted ? ActivityLogger::ACTION_APPROVE : ActivityLogger::ACTION_REJECT,
                ($accepted ? 'ยอมรับ ' : 'ปฏิเสธ ') . $type,
                [
                    'user_id' => $userId,
                    'line_user_id' => $lineUserId,
                    'consent_type' => $type,
                    'version' => $versionMap[$type] ?? '1.0'
                ]
            );
        }

        // Update user consent flags (handle missing columns)
        try {
            $checkCol = $db->query("SHOW COLUMNS FROM users LIKE 'consent_privacy'");
            if ($checkCol->rowCount() > 0) {
                $stmt = $db->prepare("
                    UPDATE users SET 
                        consent_privacy = ?,
                        consent_terms = ?,
                        consent_health_data = ?,
                        consent_date = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    !empty($consents['privacy_policy']) ? 1 : 0,
                    !empty($consents['terms_of_service']) ? 1 : 0,
                    !empty($consents['health_data']) ? 1 : 0,
                    $userId
                ]);
            }
        } catch (Exception $e) {
            // Ignore if columns don't exist
        }

        $db->commit();

        jsonResponse(true, 'Consent saved', ['user_id' => $userId]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Withdraw consent
 */
function handleWithdrawConsent($data)
{
    global $db;

    $lineUserId = $data['line_user_id'] ?? null;
    $consentType = $data['consent_type'] ?? null;

    if (!$lineUserId || !$consentType) {
        jsonResponse(false, 'Missing required fields');
    }

    $user = getUserFromLineId($lineUserId);

    if (!$user) {
        jsonResponse(false, 'User not found');
    }

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Update consent
    $stmt = $db->prepare("
        UPDATE user_consents 
        SET is_accepted = 0, withdrawn_at = NOW(), updated_at = NOW()
        WHERE user_id = ? AND consent_type = ?
    ");
    $stmt->execute([$user['id'], $consentType]);

    // Log withdrawal
    $stmt = $db->prepare("
        INSERT INTO consent_logs (user_id, consent_type, action, consent_version, ip_address, user_agent)
        VALUES (?, ?, 'withdraw', '1.0', ?, ?)
    ");
    $stmt->execute([$user['id'], $consentType, $ipAddress, $userAgent]);

    jsonResponse(true, 'Consent withdrawn');
}

/**
 * Get consent history for user
 */
function handleGetHistory()
{
    global $db;

    $lineUserId = $_GET['line_user_id'] ?? null;

    if (!$lineUserId) {
        jsonResponse(false, 'LINE User ID required');
    }

    $user = getUserFromLineId($lineUserId);

    if (!$user) {
        jsonResponse(false, 'User not found');
    }

    $stmt = $db->prepare("
        SELECT consent_type, action, consent_version, created_at
        FROM consent_logs
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$user['id']]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true, '', ['history' => $history]);
}
