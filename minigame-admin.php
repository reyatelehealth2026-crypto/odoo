<?php
/**
 * Minigame Admin — จัดการคิวรับของรางวัล
 * Staff page: ดูรายการผู้รอรับของ และกด "รับแล้ว"
 */
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/header.php';

$db = Database::getInstance()->getConnection();

// Run migration (idempotent)
try {
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
} catch (Exception $e) {
    // ignore if already exists
}

$lineAccountId = (int)($_GET['account'] ?? $currentUser['line_account_id'] ?? 1);
$filter        = $_GET['filter'] ?? 'pending';

// Summary counts
try {
    $sumStmt = $db->prepare(
        "SELECT
            COUNT(*) AS total_played,
            SUM(claimed) AS total_claimed,
            SUM(staff_received) AS total_received,
            SUM(claimed = 1 AND staff_received = 0) AS pending_count
         FROM minigame_plays
         WHERE line_account_id = ?"
    );
    $sumStmt->execute([$lineAccountId]);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_played' => 0, 'total_claimed' => 0, 'total_received' => 0, 'pending_count' => 0];
} catch (Exception $e) {
    $summary = ['total_played' => 0, 'total_claimed' => 0, 'total_received' => 0, 'pending_count' => 0];
}

// List
$where = 'line_account_id = ?';
$params = [$lineAccountId];
if ($filter === 'pending') {
    $where .= ' AND claimed = 1 AND staff_received = 0';
} elseif ($filter === 'received') {
    $where .= ' AND staff_received = 1';
} elseif ($filter === 'all') {
    // no extra filter
}

try {
    $listStmt = $db->prepare(
        "SELECT id, line_user_id, display_name, reward_icon, reward_name, reward_desc,
                queue_number, claimed, claimed_at, staff_received, staff_received_at, played_at
         FROM   minigame_plays
         WHERE  {$where}
         ORDER  BY CASE WHEN staff_received = 0 AND claimed = 1 THEN 0 ELSE 1 END,
                   queue_number ASC,
                   claimed_at ASC
         LIMIT  300"
    );
    $listStmt->execute($params);
    $plays = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $plays = [];
}

$baseUrl = rtrim(BASE_URL, '/');
?>

<div class="page-content">
    <div class="container-fluid">

        <!-- Page header -->
        <div class="page-header d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="page-title">🎮 มินิเกมลุ้นรางวัล</h1>
                <p class="text-muted mb-0">จัดการคิวรับของรางวัล / Minigame Reward Queue</p>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= cleanUrl('minigame-admin') ?>?account=<?= $lineAccountId ?>&filter=pending"
                   class="btn btn-<?= $filter === 'pending' ? 'primary' : 'outline-secondary' ?> btn-sm">
                    รอรับของ <span class="badge bg-danger ms-1"><?= (int)$summary['pending_count'] ?></span>
                </a>
                <a href="<?= cleanUrl('minigame-admin') ?>?account=<?= $lineAccountId ?>&filter=received"
                   class="btn btn-<?= $filter === 'received' ? 'success' : 'outline-secondary' ?> btn-sm">
                    รับแล้ว
                </a>
                <a href="<?= cleanUrl('minigame-admin') ?>?account=<?= $lineAccountId ?>&filter=all"
                   class="btn btn-<?= $filter === 'all' ? 'secondary' : 'outline-secondary' ?> btn-sm">
                    ทั้งหมด
                </a>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-2 fw-bold text-primary"><?= (int)$summary['total_played'] ?></div>
                    <div class="text-muted small">เล่นแล้วทั้งหมด</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-2 fw-bold text-warning"><?= (int)$summary['pending_count'] ?></div>
                    <div class="text-muted small">รอรับของ</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-2 fw-bold text-success"><?= (int)$summary['total_received'] ?></div>
                    <div class="text-muted small">รับแล้ว</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-2 fw-bold text-info"><?= (int)$summary['total_claimed'] ?></div>
                    <div class="text-muted small">กดรับของแล้ว</div>
                </div>
            </div>
        </div>

        <!-- Queue table -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php if ($filter === 'pending'): ?>คิวรอรับของ
                    <?php elseif ($filter === 'received'): ?>รายการรับแล้ว
                    <?php else: ?>ทุกรายการ<?php endif; ?>
                </h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> รีเฟรช
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($plays)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                        <?= $filter === 'pending' ? 'ไม่มีรายการรอรับของ' : 'ไม่มีข้อมูล' ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:60px">คิว</th>
                                    <th>ผู้ใช้</th>
                                    <th>รางวัล</th>
                                    <th style="width:140px">เวลาที่กดรับ</th>
                                    <th style="width:120px">สถานะ</th>
                                    <?php if (isAdmin() || isSuperAdmin()): ?>
                                    <th style="width:120px">จัดการ</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="queue-tbody">
                                <?php foreach ($plays as $p): ?>
                                <tr id="row-<?= $p['id'] ?>" class="<?= $p['staff_received'] ? 'table-success' : ($p['claimed'] ? '' : 'table-light') ?>">
                                    <td class="text-center fw-bold fs-5">
                                        <?= $p['queue_number'] ? '#' . $p['queue_number'] : '<span class="text-muted">-</span>' ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($p['display_name'] ?: 'ผู้ใช้') ?></div>
                                        <div class="text-muted small font-monospace"><?= htmlspecialchars(substr($p['line_user_id'], 0, 12)) ?>...</div>
                                    </td>
                                    <td>
                                        <span style="font-size:20px;"><?= htmlspecialchars($p['reward_icon']) ?></span>
                                        <span class="fw-semibold ms-1"><?= htmlspecialchars($p['reward_name']) ?></span>
                                        <?php if ($p['reward_desc']): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($p['reward_desc']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?= $p['claimed_at'] ? date('d/m H:i', strtotime($p['claimed_at'])) : '-' ?>
                                    </td>
                                    <td>
                                        <?php if ($p['staff_received']): ?>
                                            <span class="badge bg-success">✅ รับแล้ว</span>
                                        <?php elseif ($p['claimed']): ?>
                                            <span class="badge bg-warning text-dark">⏳ รอรับ</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">ยังไม่กดรับ</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (isAdmin() || isSuperAdmin()): ?>
                                    <td>
                                        <?php if ($p['claimed'] && !$p['staff_received']): ?>
                                        <button class="btn btn-success btn-sm"
                                                onclick="markReceived(<?= $p['id'] ?>, <?= $lineAccountId ?>)"
                                                id="rcv-btn-<?= $p['id'] ?>">
                                            <i class="fas fa-check"></i> รับแล้ว
                                        </button>
                                        <?php elseif ($p['staff_received']): ?>
                                            <small class="text-muted"><?= $p['staff_received_at'] ? date('d/m H:i', strtotime($p['staff_received_at'])) : '' ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /container -->
</div><!-- /page-content -->

<script>
async function markReceived(playId, accountId) {
    const btn = document.getElementById('rcv-btn-' + playId);
    if (!btn) return;
    if (!confirm('ยืนยันว่าลูกค้ารับของแล้ว?')) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const res  = await fetch('<?= $baseUrl ?>/api/minigame.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'admin_receive',
                play_id: playId,
                staff_user_id: <?= (int)($currentUser['id'] ?? 0) ?>
            })
        });
        const data = await res.json();

        if (data.success) {
            const row = document.getElementById('row-' + playId);
            if (row) {
                row.classList.remove('table-warning');
                row.classList.add('table-success');
                btn.closest('td').innerHTML = '<span class="badge bg-success">✅ รับแล้ว</span>';
                row.querySelector('.badge.bg-warning')?.remove();
                const statusCell = row.querySelectorAll('td')[4];
                if (statusCell) statusCell.innerHTML = '<span class="badge bg-success">✅ รับแล้ว</span>';
            }
            // Update summary badge
            const pendingBadge = document.querySelector('.badge.bg-danger');
            if (pendingBadge) {
                const cur = parseInt(pendingBadge.textContent) || 0;
                if (cur > 0) pendingBadge.textContent = cur - 1;
            }
        } else {
            alert('เกิดข้อผิดพลาด: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> รับแล้ว';
        }
    } catch (e) {
        alert('เกิดข้อผิดพลาด: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> รับแล้ว';
    }
}

// Auto-refresh every 30 seconds when on pending tab
<?php if ($filter === 'pending'): ?>
setInterval(() => {
    if (!document.hidden) location.reload();
}, 30000);
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
