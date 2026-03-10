<?php
/**
 * Odoo Daily Summary Auto-Send Settings API
 * 
 * Actions:
 * - get_settings: Retrieve current settings
 * - save_settings: Update settings
 * - get_logs: Get execution history
 * 
 * @version 1.0.0
 * @created 2026-02-26
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_GET;
    }
    
    $action = $input['action'] ?? 'get_settings';
    
    switch ($action) {
        case 'get_settings':
            $result = getSettings($db);
            break;
            
        case 'save_settings':
            $result = saveSettings($db, $input);
            break;
            
        case 'get_logs':
            $result = getLogs($db, $input);
            break;
            
        case 'test_send':
            $result = testSend($db);
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Get current settings
 */
function getSettings($db)
{
    if (!tableExists($db, 'odoo_daily_summary_settings')) {
        return [
            'available' => false,
            'message' => 'Settings table not found. Please run migration.'
        ];
    }
    
    $stmt = $db->query("
        SELECT setting_key, setting_value, enabled 
        FROM odoo_daily_summary_settings
        ORDER BY setting_key
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'enabled' => (bool) $row['enabled']
        ];
    }
    
    // Get last execution info
    $lastExec = null;
    if (tableExists($db, 'odoo_daily_summary_auto_log')) {
        $stmt = $db->query("
            SELECT * FROM odoo_daily_summary_auto_log 
            ORDER BY execution_time DESC 
            LIMIT 1
        ");
        $lastExec = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return [
        'available' => true,
        'settings' => $settings,
        'last_execution' => $lastExec
    ];
}

/**
 * Save settings
 */
function saveSettings($db, $input)
{
    if (!tableExists($db, 'odoo_daily_summary_settings')) {
        throw new Exception('Settings table not found');
    }
    
    $autoSendEnabled = isset($input['auto_send_enabled']) ? (int) $input['auto_send_enabled'] : null;
    $sendTime = isset($input['send_time']) ? trim($input['send_time']) : null;
    $lookbackDays = isset($input['lookback_days']) ? (int) $input['lookback_days'] : null;
    
    $updated = [];
    
    if ($autoSendEnabled !== null) {
        $stmt = $db->prepare("
            UPDATE odoo_daily_summary_settings 
            SET setting_value = ?, updated_at = NOW() 
            WHERE setting_key = 'auto_send_enabled'
        ");
        $stmt->execute([$autoSendEnabled]);
        $updated[] = 'auto_send_enabled';
    }
    
    if ($sendTime !== null) {
        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $sendTime)) {
            throw new Exception('Invalid time format. Use HH:MM (24-hour)');
        }
        $stmt = $db->prepare("
            UPDATE odoo_daily_summary_settings 
            SET setting_value = ?, updated_at = NOW() 
            WHERE setting_key = 'send_time'
        ");
        $stmt->execute([$sendTime]);
        $updated[] = 'send_time';
    }
    
    if ($lookbackDays !== null && $lookbackDays > 0 && $lookbackDays <= 7) {
        $stmt = $db->prepare("
            UPDATE odoo_daily_summary_settings 
            SET setting_value = ?, updated_at = NOW() 
            WHERE setting_key = 'lookback_days'
        ");
        $stmt->execute([$lookbackDays]);
        $updated[] = 'lookback_days';
    }
    
    return [
        'updated' => $updated,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Get execution logs
 */
function getLogs($db, $input)
{
    if (!tableExists($db, 'odoo_daily_summary_auto_log')) {
        return [
            'available' => false,
            'logs' => []
        ];
    }
    
    $limit = min((int) ($input['limit'] ?? 30), 100);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    
    $countStmt = $db->query("SELECT COUNT(*) FROM odoo_daily_summary_auto_log");
    $total = (int) $countStmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT * FROM odoo_daily_summary_auto_log 
        ORDER BY execution_time DESC 
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'available' => true,
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Test send (manual trigger for testing)
 */
function testSend($db)
{
    require_once __DIR__ . '/odoo-webhooks-dashboard.php';
    
    $previewData = getDailySummaryPreview($db);
    $records = $previewData['records'];
    
    $eligibleUsers = array_filter($records, function($r) {
        return !$r['sent_today'] && !empty($r['orders']);
    });
    
    return [
        'eligible_count' => count($eligibleUsers),
        'total_count' => count($records),
        'preview' => array_slice($eligibleUsers, 0, 5)
    ];
}

/**
 * Check table existence
 */
function tableExists($db, $table)
{
    static $cache = [];
    
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
    if ($table === '') {
        return false;
    }
    
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    
    try {
        $stmt = $db->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        $cache[$table] = (bool) $stmt->fetchColumn();
    } catch (Exception $e) {
        $quoted = $db->quote($table);
        $stmt = $db->query("SHOW TABLES LIKE {$quoted}");
        $cache[$table] = $stmt ? ($stmt->rowCount() > 0) : false;
    }
    
    return $cache[$table];
}
