<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/OdooAPIClient.php';

// Initialize
$db = Database::getInstance();
$pdo = $db->getConnection();
$odoo = new OdooAPIClient($pdo);

// Check Odoo Connection
$odooStatus = 'unknown';
$odooVersion = '';
$odooError = '';

try {
    $versionInfo = $odoo->getVersion();
    if ($versionInfo) {
        $odooStatus = 'connected';
        $odooVersion = json_encode($versionInfo);
    } else {
        $odooStatus = 'error';
        $odooError = 'Failed to get version';
    }
} catch (Exception $e) {
    $odooStatus = 'error';
    $odooError = $e->getMessage();
}

// Get Recent Webhook Logs
$webhookLogs = [];
try {
    $stmt = $pdo->query("SELECT * FROM odoo_webhooks_log ORDER BY id DESC LIMIT 10");
    $webhookLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist yet
}

// Get Base URL for links
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$liffUrl = $baseUrl . '/liff/odoo-link.php';
$webhookUrl = $baseUrl . '/api/webhook/odoo.php';

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odoo Integration Dashboard - Re-Ya</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: none; }
        .card-header { background-color: #fff; border-bottom: 1px solid #eee; font-weight: bold; }
        .status-badge { font-size: 0.9em; padding: 5px 10px; border-radius: 20px; }
        .status-connected { background-color: #d1e7dd; color: #0f5132; }
        .status-error { background-color: #f8d7da; color: #842029; }
        .log-table { font-size: 0.85em; }
        .log-payload { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    </style>
</head>
<body>
    <div class="container py-4">
        <header class="mb-4 d-flex justify-content-between align-items-center">
            <h1><i class="fa-brands fa-line text-success"></i> Odoo Integration Dashboard</h1>
            <div>
                <span class="text-muted">Environment: <?php echo ucfirst(ODOO_ENVIRONMENT); ?></span>
            </div>
        </header>

        <!-- System Status -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0">Odoo Connection Status</h5>
                            <small class="text-muted"><?php echo ODOO_PRODUCTION_API_BASE_URL; ?></small>
                        </div>
                        <div>
                            <?php if ($odooStatus === 'connected'): ?>
                                <span class="status-badge status-connected"><i class="fas fa-check-circle"></i> Connected</span>
                            <?php else: ?>
                                <span class="status-badge status-error"><i class="fas fa-exclamation-triangle"></i> Error: <?php echo $odooError; ?></span>
                            <?php endif; ?>
                            <a href="?refresh=1" class="btn btn-sm btn-outline-secondary ms-2"><i class="fas fa-sync"></i> Test Connection</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Developer Tools -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-code"></i> Developer Tools</div>
                    <div class="card-body">
                        <div class="list-group">
                            <a href="test_odoo_webhook.php" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">Webhook Simulator</h5>
                                    <small>Testing</small>
                                </div>
                                <p class="mb-1">Simulate incoming webhooks from Odoo.</p>
                            </a>
                            <div class="list-group-item">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">Webhook Endpoint</h5>
                                    <small>API</small>
                                </div>
                                <p class="mb-1 code user-select-all"><code><?php echo $webhookUrl; ?></code></p>
                            </div>
                            <a href="run_odoo_migration.php" class="list-group-item list-group-item-action list-group-item-warning">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">Database Migration</h5>
                                    <small>Admin</small>
                                </div>
                                <p class="mb-1">Run/Check database schema updates.</p>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales Staff Tools -->
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-users"></i> Sales Staff Tools</div>
                    <div class="card-body">
                        <div class="list-group">
                            <a href="liff/odoo-link.php" target="_blank" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">User Linking Page (LIFF)</h5>
                                    <small>Frontend</small>
                                </div>
                                <p class="mb-1">Open the user linking page used in LINE.</p>
                            </a>
                            <div class="list-group-item text-center">
                                <p class="mb-2"><strong>QR Code for User Linking</strong></p>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($liffUrl); ?>" alt="QR Code" class="img-thumbnail">
                                <p class="mt-2 small text-muted"><?php echo $liffUrl; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Logs -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-history"></i> Recent Webhook Logs</span>
                        <small>Last 10 events</small>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0 log-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Event</th>
                                        <th>Delivery ID</th>
                                        <th>Status</th>
                                        <th>Payload</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($webhookLogs)): ?>
                                        <tr><td colspan="6" class="text-center py-3">No logs found</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($webhookLogs as $log): ?>
                                            <tr>
                                                <td><?php echo $log['id']; ?></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($log['event_type']); ?></span></td>
                                                <td><small><?php echo htmlspecialchars(substr($log['delivery_id'], 0, 8)); ?>...</small></td>
                                                <td>
                                                    <?php if ($log['status'] === 'success'): ?>
                                                        <span class="badge bg-success">Success</span>
                                                    <?php elseif ($log['status'] === 'duplicate'): ?>
                                                        <span class="badge bg-warning text-dark">Duplicate</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Failed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="log-payload" title="<?php echo htmlspecialchars($log['payload']); ?>">
                                                    <?php echo htmlspecialchars($log['payload']); ?>
                                                </td>
                                                <td><?php echo $log['processed_at']; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>