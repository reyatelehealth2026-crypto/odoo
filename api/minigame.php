<?php
/**
 * Minigame API - มินิเกมลุ้นรางวัล
 *
 * Actions:
 *   check_played  GET  ตรวจสอบว่าผู้ใช้เคยเล่นแล้วหรือยัง
 *   play          POST สุ่มรางวัลและบันทึก (เล่นได้ครั้งเดียว)
 *   claim         POST ยืนยันรับของ (เพิ่มคิว)
 *   admin_list    GET  รายการผู้รอรับของ (admin)
 *   admin_receive POST เจ้าหน้าที่กด "รับแล้ว" (admin)
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Run migration once (idempotent)
ensureTableExists($db);

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? $_POST['action'] ?? $action;
} else {
    $body = [];
}

try {
    switch ($action) {
        case 'check_played':
            handleCheckPlayed($db);
            break;
        case 'play':
            handlePlay($db, $body);
            break;
        case 'claim':
            handleClaim($db, $body);
            break;
        case 'admin_list':
            handleAdminList($db);
            break;
        case 'admin_receive':
            handleAdminReceive($db, $body);
            break;
        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('Minigame API error: ' . $e->getMessage());
    jsonResponse(false, $e->getMessage());
}

// ─────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────

/** ตรวจสอบว่าเล่นแล้วหรือยัง */
function handleCheckPlayed(PDO $db): void
{
    $lineUserId    = $_GET['line_user_id']    ?? '';
    $lineAccountId = (int)($_GET['line_account_id'] ?? 1);

    if (!$lineUserId) {
        jsonResponse(false, 'line_user_id required');
    }

    $stmt = $db->prepare(
        "SELECT id, reward_key, reward_name, reward_desc, reward_icon,
                queue_number, claimed, staff_received, played_at
         FROM   minigame_plays
         WHERE  line_user_id = ? AND line_account_id = ?
         LIMIT  1"
    );
    $stmt->execute([$lineUserId, $lineAccountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        jsonResponse(true, 'already_played', ['played' => true, 'play' => $row]);
    } else {
        jsonResponse(true, 'not_played', ['played' => false]);
    }
}

/** เล่นเกม — สุ่มรางวัลและบันทึก (1 ครั้งต่อ 1 user/account) */
function handlePlay(PDO $db, array $body): void
{
    $lineUserId    = $body['line_user_id']    ?? '';
    $lineAccountId = (int)($body['line_account_id'] ?? 1);
    $displayName   = mb_substr($body['display_name'] ?? '', 0, 255);

    if (!$lineUserId) {
        jsonResponse(false, 'line_user_id required');
    }

    // ตรวจสอบซ้ำ
    $check = $db->prepare(
        "SELECT id FROM minigame_plays WHERE line_user_id = ? AND line_account_id = ? LIMIT 1"
    );
    $check->execute([$lineUserId, $lineAccountId]);
    if ($check->fetch()) {
        jsonResponse(false, 'already_played');
    }

    // สุ่มรางวัล
    $reward = randomReward();

    $stmt = $db->prepare(
        "INSERT INTO minigame_plays
             (line_account_id, line_user_id, display_name,
              reward_key, reward_name, reward_desc, reward_icon, played_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $lineAccountId,
        $lineUserId,
        $displayName,
        $reward['key'],
        $reward['name'],
        $reward['desc'],
        $reward['icon'],
    ]);

    jsonResponse(true, 'ok', ['reward' => $reward]);
}

/** ยืนยันรับของ — เพิ่มหมายเลขคิว */
function handleClaim(PDO $db, array $body): void
{
    $lineUserId    = $body['line_user_id']    ?? '';
    $lineAccountId = (int)($body['line_account_id'] ?? 1);

    if (!$lineUserId) {
        jsonResponse(false, 'line_user_id required');
    }

    $db->beginTransaction();
    try {
        // ล็อกแถวเพื่อป้องกัน race condition
        $stmt = $db->prepare(
            "SELECT id, claimed, queue_number FROM minigame_plays
             WHERE line_user_id = ? AND line_account_id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$lineUserId, $lineAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $db->rollBack();
            jsonResponse(false, 'play_not_found');
        }

        if ($row['claimed']) {
            $db->rollBack();
            jsonResponse(true, 'already_claimed', ['queue_number' => $row['queue_number']]);
        }

        // ดึงและเพิ่ม sequence
        $db->prepare(
            "INSERT INTO minigame_queue_seq (line_account_id, last_seq)
             VALUES (?, 1)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + 1"
        )->execute([$lineAccountId]);

        $seqRow = $db->prepare(
            "SELECT last_seq FROM minigame_queue_seq WHERE line_account_id = ? LIMIT 1"
        );
        $seqRow->execute([$lineAccountId]);
        $queueNum = (int)$seqRow->fetchColumn();

        $db->prepare(
            "UPDATE minigame_plays
             SET claimed = 1, claimed_at = NOW(), queue_number = ?
             WHERE id = ?"
        )->execute([$queueNum, $row['id']]);

        $db->commit();
        jsonResponse(true, 'ok', ['queue_number' => $queueNum]);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Admin: รายการรอรับของ */
function handleAdminList(PDO $db): void
{
    $lineAccountId = (int)($_GET['line_account_id'] ?? 1);
    $filter        = $_GET['filter'] ?? 'pending'; // pending | all

    $where = 'line_account_id = ?';
    if ($filter === 'pending') {
        $where .= ' AND claimed = 1 AND staff_received = 0';
    } elseif ($filter === 'received') {
        $where .= ' AND staff_received = 1';
    }

    $stmt = $db->prepare(
        "SELECT id, line_user_id, display_name,
                reward_name, reward_desc, reward_icon,
                queue_number, claimed, claimed_at,
                staff_received, staff_received_at, played_at
         FROM   minigame_plays
         WHERE  {$where}
         ORDER  BY queue_number ASC, claimed_at ASC
         LIMIT  200"
    );
    $stmt->execute([$lineAccountId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // summary counts
    $summary = $db->prepare(
        "SELECT
            COUNT(*) AS total_played,
            SUM(claimed) AS total_claimed,
            SUM(staff_received) AS total_received
         FROM minigame_plays
         WHERE line_account_id = ?"
    );
    $summary->execute([$lineAccountId]);
    $counts = $summary->fetch(PDO::FETCH_ASSOC);

    jsonResponse(true, 'ok', ['list' => $rows, 'summary' => $counts]);
}

/** Admin: กด "รับแล้ว" */
function handleAdminReceive(PDO $db, array $body): void
{
    $playId     = (int)($body['play_id']     ?? 0);
    $staffUserId = (int)($body['staff_user_id'] ?? 0);

    if (!$playId) {
        jsonResponse(false, 'play_id required');
    }

    $stmt = $db->prepare(
        "UPDATE minigame_plays
         SET    staff_received = 1,
                staff_received_at = NOW(),
                staff_user_id = ?
         WHERE  id = ? AND claimed = 1"
    );
    $stmt->execute([$staffUserId ?: null, $playId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(false, 'not_found_or_not_claimed');
    }

    jsonResponse(true, 'ok');
}

// ─────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────

/** สุ่มรางวัลตาม weight */
function randomReward(): array
{
    $rewards = [
        ['key' => 'discount_10',  'name' => 'ส่วนลด 10%',         'desc' => 'คูปองส่วนลด 10% สำหรับการสั่งซื้อครั้งถัดไป', 'icon' => '🎟️', 'weight' => 30],
        ['key' => 'discount_20',  'name' => 'ส่วนลด 20%',         'desc' => 'คูปองส่วนลด 20% สำหรับการสั่งซื้อครั้งถัดไป', 'icon' => '🎫', 'weight' => 15],
        ['key' => 'points_50',    'name' => 'แต้มโบนัส 50 แต้ม',  'desc' => 'แต้มสะสมโบนัสเพิ่มพิเศษ 50 แต้ม',             'icon' => '⭐', 'weight' => 25],
        ['key' => 'points_100',   'name' => 'แต้มโบนัส 100 แต้ม', 'desc' => 'แต้มสะสมโบนัสเพิ่มพิเศษ 100 แต้ม',            'icon' => '🌟', 'weight' => 12],
        ['key' => 'free_item',    'name' => 'ของฟรี 1 ชิ้น',       'desc' => 'รับสินค้าฟรี 1 ชิ้น (ตามที่ร้านกำหนด)',        'icon' => '🎁', 'weight' => 8],
        ['key' => 'health_check', 'name' => 'ตรวจสุขภาพฟรี',       'desc' => 'บริการตรวจสุขภาพเบื้องต้นฟรี 1 ครั้ง',         'icon' => '💊', 'weight' => 5],
        ['key' => 'grand_prize',  'name' => 'รางวัลใหญ่พิเศษ',     'desc' => 'ของรางวัลพิเศษมูลค่าสูง ติดต่อเจ้าหน้าที่',    'icon' => '🏆', 'weight' => 5],
    ];

    $total = array_sum(array_column($rewards, 'weight'));
    $rand  = mt_rand(1, $total);
    $cum   = 0;
    foreach ($rewards as $r) {
        $cum += $r['weight'];
        if ($rand <= $cum) {
            unset($r['weight']);
            return $r;
        }
    }
    // fallback
    unset($rewards[0]['weight']);
    return $rewards[0];
}

/** Ensure DB tables exist (auto-migrate) */
function ensureTableExists(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS `minigame_plays` (
            `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `line_account_id` INT NOT NULL,
            `line_user_id`    VARCHAR(64) NOT NULL,
            `display_name`    VARCHAR(255) DEFAULT NULL,
            `reward_key`      VARCHAR(64) NOT NULL,
            `reward_name`     VARCHAR(255) NOT NULL,
            `reward_desc`     VARCHAR(255) DEFAULT NULL,
            `reward_icon`     VARCHAR(16) DEFAULT '🎁',
            `queue_number`    INT UNSIGNED DEFAULT NULL,
            `claimed`         TINYINT(1) NOT NULL DEFAULT 0,
            `claimed_at`      DATETIME DEFAULT NULL,
            `staff_received`  TINYINT(1) NOT NULL DEFAULT 0,
            `staff_received_at` DATETIME DEFAULT NULL,
            `staff_user_id`   INT DEFAULT NULL,
            `played_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_account` (`line_user_id`, `line_account_id`),
            KEY `idx_account_claimed` (`line_account_id`, `claimed`),
            KEY `idx_account_staff`   (`line_account_id`, `staff_received`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `minigame_queue_seq` (
            `line_account_id` INT NOT NULL,
            `last_seq`        INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/** ส่ง JSON response */
function jsonResponse(bool $success, string $message, array $data = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data),
        JSON_UNESCAPED_UNICODE);
    exit;
}
