<?php
/**
 * Webhook Monitoring API
 * 
 * Comprehensive webhook monitoring and management API endpoint.
 * Provides statistics, retry management, DLQ handling, and performance metrics.
 * 
 * @version 1.0.0
 * @created 2026-01-23
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/WebhookLoggingService.php';

// Authentication check (optional - uncomment if needed)
// require_once __DIR__ . '/../includes/auth_check.php';

try {
    $db = Database::getInstance()->getConnection();
    $startTime = microtime(true);
    
    // Get input data
    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_GET;
    }
    
    $action = trim((string) ($input['action'] ?? ''));
    $lineAccountId = !empty($input['line_account_id']) ? (int) $input['line_account_id'] : null;
    
    // Initialize webhook service
    $webhookService = new WebhookLoggingService($db, $lineAccountId);
    
    $result = [];
    
    switch ($action) {
        case 'health':
            $result = [
                'status' => 'ok',
                'service' => 'webhook-monitoring',
                'version' => '1.0.0',
                'timestamp' => date('c')
            ];
            break;
            
        case 'statistics':
            $filters = [
                'date_from' => $input['date_from'] ?? date('Y-m-d', strtotime('-7 days')),
                'date_to' => $input['date_to'] ?? date('Y-m-d'),
                'webhook_type' => $input['webhook_type'] ?? null
            ];
            $result = $webhookService->getWebhookStatistics($filters);
            break;
            
        case 'list':
            $result = getWebhookList($db, $input);
            break;
            
        case 'detail':
            $result = getWebhookDetail($db, $input);
            break;
            
        case 'retry':
            $webhookId = $input['webhook_id'] ?? null;
            if (!$webhookId) {
                throw new Exception('Missing webhook_id parameter');
            }
            $success = $webhookService->retryWebhook($webhookId);
            $result = ['success' => $success, 'webhook_id' => $webhookId];
            break;
            
        case 'dlq_list':
            $filters = [
                'limit' => min((int) ($input['limit'] ?? 50), 200),
                'offset' => max((int) ($input['offset'] ?? 0), 0),
                'webhook_type' => $input['webhook_type'] ?? null
            ];
            $result = $webhookService->getDeadLetterQueueItems($filters);
            break;
            
        case 'dlq_retry':
            $webhookId = $input['webhook_id'] ?? null;
            if (!$webhookId) {
                throw new Exception('Missing webhook_id parameter');
            }
            // First move back from DLQ to retry status
            $stmt = $db->prepare("
                UPDATE odoo_webhooks_log 
                SET status = ?, dlq_at = NULL, dlq_reason = NULL, retry_count = 0, updated_at = NOW()
                WHERE id = ? AND status = ?
            ");
            $stmt->execute([WebhookLoggingService::STATUS_RETRY, $webhookId, WebhookLoggingService::STATUS_DLQ]);
            
            if ($stmt->rowCount() > 0) {
                $success = $webhookService->retryWebhook($webhookId);
                $result = ['success' => $success, 'webhook_id' => $webhookId, 'moved_from_dlq' => true];
            } else {
                $result = ['success' => false, 'error' => 'Webhook not found in DLQ'];
            }
            break;
            
        case 'performance_metrics':
            $result = getPerformanceMetrics($db, $input);
            break;
            
        case 'alerts':
            $result = getPerformanceAlerts($db, $input);
            break;
            
        case 'resolve_alert':
            $alertId = (int) ($input['alert_id'] ?? 0);
            if (!$alertId) {
                throw new Exception('Missing alert_id parameter');
            }
            $result = resolveAlert($db, $alertId);
            break;
            
        case 'event_types':
            $result = getEventTypes($db, $input);
            break;
            
        case 'webhook_timeline':
            $webhookId = $input['webhook_id'] ?? null;
            if (!$webhookId) {
                throw new Exception('Missing webhook_id parameter');
            }
            $result = getWebhookTimeline($db, $webhookId);
            break;
            
        case 'bulk_retry':
            $filters = $input['filters'] ?? [];
            $result = bulkRetryWebhooks($db, $webhookService, $filters);
            break;
            
        case 'cleanup_logs':
            $daysToKeep = max((int) ($input['days_to_keep'] ?? 90), 30);
            $deletedCount = $webhookService->cleanupOldLogs($daysToKeep);
            $result = ['deleted_count' => $deletedCount, 'days_to_keep' => $daysToKeep];
            break;
            
        default:
            throw new Exception("Unknown action: {$action}");
    }
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        '_meta' => [
            'duration_ms' => $duration,
            'action' => $action,
            'timestamp' => date('c')
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        '_meta' => [
            'duration_ms' => $duration,
            'action' => $action ?? 'unknown',
            'timestamp' => date('c')
        ]
    ], JSON_UNESCAPED_UNICODE);
    
    // Log error
    try {
        $stmt = $db->prepare("
            INSERT INTO dev_logs (log_type, source, message, data, created_at) 
            VALUES ('error', 'webhook_monitoring_api', ?, ?, NOW())
        ");
        
        $stmt->execute([
            'Webhook monitoring API error: ' . $e->getMessage(),
            json_encode([
                'action' => $action ?? 'unknown',
                'input' => $input,
                'trace' => $e->getTraceAsString()
            ], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Exception $logError) {
        error_log("Failed to log webhook monitoring API error: " . $logError->getMessage());
    }
}

/**
 * Get webhook list with advanced filtering
 */
function getWebhookList($db, $input)
{
    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $webhookType = $input['webhook_type'] ?? null;
    $eventType = $input['event_type'] ?? null;
    $status = $input['status'] ?? null;
    $search = $input['search'] ?? null;
    $dateFrom = $input['date_from'] ?? null;
    $dateTo = $input['date_to'] ?? null;
    $lineAccountId = !empty($input['line_account_id']) ? (int) $input['line_account_id'] : null;
    
    $whereConditions = [];
    $params = [];
    
    if ($lineAccountId) {
        $whereConditions[] = 'line_account_id = ?';
        $params[] = $lineAccountId;
    }
    
    if ($webhookType) {
        $whereConditions[] = 'webhook_type = ?';
        $params[] = $webhookType;
    }
    
    if ($eventType) {
        $whereConditions[] = 'event_type = ?';
        $params[] = $eventType;
    }
    
    if ($status) {
        $whereConditions[] = 'status = ?';
        $params[] = $status;
    }
    
    if ($search) {
        $whereConditions[] = '(id LIKE ? OR delivery_id LIKE ? OR customer_name LIKE ? OR customer_ref LIKE ?)';
        $searchParam = "%{$search}%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
    }
    
    if ($dateFrom) {
        $whereConditions[] = 'DATE(created_at) >= ?';
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereConditions[] = 'DATE(created_at) <= ?';
        $params[] = $dateTo;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM odoo_webhooks_log {$whereClause}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    
    // Get records
    $stmt = $db->prepare("
        SELECT 
            id, webhook_type, event_type, status, error_message, last_error_code,
            line_user_id, order_id, invoice_id, payment_id, delivery_id,
            customer_id, customer_name, customer_ref,
            retry_count, process_latency_ms,
            received_at, processing_started_at, processing_completed_at, 
            failed_at, dlq_at, created_at, updated_at,
            CASE 
                WHEN processing_completed_at IS NOT NULL AND processing_started_at IS NOT NULL 
                THEN TIMESTAMPDIFF(MICROSECOND, processing_started_at, processing_completed_at) / 1000
                ELSE process_latency_ms 
            END as actual_processing_time_ms
        FROM odoo_webhooks_log 
        {$whereClause}
        ORDER BY created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    
    $stmt->execute($params);
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'webhooks' => $webhooks,
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset,
        'filters' => [
            'webhook_type' => $webhookType,
            'event_type' => $eventType,
            'status' => $status,
            'search' => $search,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]
    ];
}

/**
 * Get detailed webhook information
 */
function getWebhookDetail($db, $input)
{
    $webhookId = $input['webhook_id'] ?? null;
    if (!$webhookId) {
        throw new Exception('Missing webhook_id parameter');
    }
    
    $stmt = $db->prepare("
        SELECT *,
            CASE 
                WHEN processing_completed_at IS NOT NULL AND processing_started_at IS NOT NULL 
                THEN TIMESTAMPDIFF(MICROSECOND, processing_started_at, processing_completed_at) / 1000
                ELSE process_latency_ms 
            END as actual_processing_time_ms
        FROM odoo_webhooks_log 
        WHERE id = ?
    ");
    
    $stmt->execute([$webhookId]);
    $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$webhook) {
        throw new Exception('Webhook not found');
    }
    
    // Parse JSON fields
    if ($webhook['payload']) {
        $webhook['payload'] = json_decode($webhook['payload'], true);
    }
    if ($webhook['metadata']) {
        $webhook['metadata'] = json_decode($webhook['metadata'], true);
    }
    if ($webhook['processing_data']) {
        $webhook['processing_data'] = json_decode($webhook['processing_data'], true);
    }
    
    return $webhook;
}

/**
 * Get performance metrics
 */
function getPerformanceMetrics($db, $input)
{
    $dateFrom = $input['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateTo = $input['date_to'] ?? date('Y-m-d');
    $webhookType = $input['webhook_type'] ?? null;
    $lineAccountId = !empty($input['line_account_id']) ? (int) $input['line_account_id'] : null;
    
    $whereConditions = ['DATE(created_at) BETWEEN ? AND ?'];
    $params = [$dateFrom, $dateTo];
    
    if ($lineAccountId) {
        $whereConditions[] = 'line_account_id = ?';
        $params[] = $lineAccountId;
    }
    
    if ($webhookType) {
        $whereConditions[] = 'webhook_type = ?';
        $params[] = $webhookType;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Processing time percentiles
    $stmt = $db->prepare("
        SELECT 
            AVG(process_latency_ms) as avg_processing_time,
            MIN(process_latency_ms) as min_processing_time,
            MAX(process_latency_ms) as max_processing_time,
            COUNT(CASE WHEN process_latency_ms IS NOT NULL THEN 1 END) as measured_count
        FROM odoo_webhooks_log 
        {$whereClause}
        AND process_latency_ms IS NOT NULL
    ");
    
    $stmt->execute($params);
    $processingStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Throughput by hour
    $stmt = $db->prepare("
        SELECT 
            DATE(created_at) as date,
            HOUR(created_at) as hour,
            COUNT(*) as count,
            COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed,
            COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed,
            AVG(process_latency_ms) as avg_processing_time
        FROM odoo_webhooks_log 
        {$whereClause}
        GROUP BY DATE(created_at), HOUR(created_at)
        ORDER BY date DESC, hour DESC
        LIMIT 168
    ");
    
    $stmt->execute($params);
    $throughput = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Error patterns
    $stmt = $db->prepare("
        SELECT 
            last_error_code,
            COUNT(*) as count,
            COUNT(CASE WHEN status = 'DLQ' THEN 1 END) as dlq_count,
            MAX(created_at) as last_occurrence
        FROM odoo_webhooks_log 
        {$whereClause}
        AND last_error_code IS NOT NULL
        GROUP BY last_error_code
        ORDER BY count DESC
        LIMIT 10
    ");
    
    $stmt->execute($params);
    $errorPatterns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'processing_stats' => $processingStats,
        'throughput' => $throughput,
        'error_patterns' => $errorPatterns,
        'date_range' => ['from' => $dateFrom, 'to' => $dateTo]
    ];
}

/**
 * Get performance alerts
 */
function getPerformanceAlerts($db, $input)
{
    $limit = min((int) ($input['limit'] ?? 50), 200);
    $offset = max((int) ($input['offset'] ?? 0), 0);
    $severity = $input['severity'] ?? null;
    $resolved = isset($input['resolved']) ? (bool) $input['resolved'] : null;
    $lineAccountId = !empty($input['line_account_id']) ? (int) $input['line_account_id'] : null;
    
    $whereConditions = [];
    $params = [];
    
    if ($lineAccountId) {
        $whereConditions[] = 'line_account_id = ?';
        $params[] = $lineAccountId;
    }
    
    if ($severity) {
        $whereConditions[] = 'severity = ?';
        $params[] = $severity;
    }
    
    if ($resolved !== null) {
        $whereConditions[] = 'is_resolved = ?';
        $params[] = $resolved ? 1 : 0;
    }
    
    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
    
    // Get total count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM webhook_performance_alerts {$whereClause}");
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    
    // Get alerts
    $stmt = $db->prepare("
        SELECT *
        FROM webhook_performance_alerts 
        {$whereClause}
        ORDER BY 
            CASE severity 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                WHEN 'low' THEN 4 
            END,
            created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'alerts' => $alerts,
        'total' => $totalCount,
        'limit' => $limit,
        'offset' => $offset
    ];
}

/**
 * Resolve performance alert
 */
function resolveAlert($db, $alertId)
{
    $stmt = $db->prepare("
        UPDATE webhook_performance_alerts 
        SET is_resolved = TRUE, resolved_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    
    $stmt->execute([$alertId]);
    
    if ($stmt->rowCount() > 0) {
        return ['resolved' => true, 'alert_id' => $alertId];
    } else {
        throw new Exception('Alert not found');
    }
}

/**
 * Get available event types
 */
function getEventTypes($db, $input)
{
    $webhookType = $input['webhook_type'] ?? null;
    $lineAccountId = !empty($input['line_account_id']) ? (int) $input['line_account_id'] : null;
    
    $whereConditions = ['event_type IS NOT NULL', "event_type != ''"];
    $params = [];
    
    if ($lineAccountId) {
        $whereConditions[] = 'line_account_id = ?';
        $params[] = $lineAccountId;
    }
    
    if ($webhookType) {
        $whereConditions[] = 'webhook_type = ?';
        $params[] = $webhookType;
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $stmt = $db->prepare("
        SELECT 
            event_type,
            COUNT(*) as count,
            COUNT(CASE WHEN status = 'PROCESSED' THEN 1 END) as processed,
            COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed,
            MAX(created_at) as last_occurrence
        FROM odoo_webhooks_log 
        {$whereClause}
        GROUP BY event_type
        ORDER BY count DESC
    ");
    
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get webhook processing timeline
 */
function getWebhookTimeline($db, $webhookId)
{
    $stmt = $db->prepare("
        SELECT 
            id, status, error_message, retry_count,
            received_at, processing_started_at, processing_completed_at,
            failed_at, dlq_at, created_at, updated_at
        FROM odoo_webhooks_log 
        WHERE id = ?
    ");
    
    $stmt->execute([$webhookId]);
    $webhook = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$webhook) {
        throw new Exception('Webhook not found');
    }
    
    // Build timeline events
    $timeline = [];
    
    if ($webhook['received_at']) {
        $timeline[] = [
            'event' => 'received',
            'timestamp' => $webhook['received_at'],
            'description' => 'Webhook received'
        ];
    }
    
    if ($webhook['processing_started_at']) {
        $timeline[] = [
            'event' => 'processing_started',
            'timestamp' => $webhook['processing_started_at'],
            'description' => 'Processing started'
        ];
    }
    
    if ($webhook['processing_completed_at']) {
        $timeline[] = [
            'event' => 'processing_completed',
            'timestamp' => $webhook['processing_completed_at'],
            'description' => 'Processing completed successfully'
        ];
    }
    
    if ($webhook['failed_at']) {
        $timeline[] = [
            'event' => 'failed',
            'timestamp' => $webhook['failed_at'],
            'description' => 'Processing failed' . ($webhook['error_message'] ? ': ' . $webhook['error_message'] : ''),
            'retry_count' => $webhook['retry_count']
        ];
    }
    
    if ($webhook['dlq_at']) {
        $timeline[] = [
            'event' => 'moved_to_dlq',
            'timestamp' => $webhook['dlq_at'],
            'description' => 'Moved to Dead Letter Queue'
        ];
    }
    
    // Sort timeline by timestamp
    usort($timeline, function($a, $b) {
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });
    
    return [
        'webhook_id' => $webhookId,
        'current_status' => $webhook['status'],
        'timeline' => $timeline
    ];
}

/**
 * Bulk retry webhooks based on filters
 */
function bulkRetryWebhooks($db, $webhookService, $filters)
{
    $whereConditions = ["status IN ('FAILED', 'DLQ')"];
    $params = [];
    
    if (!empty($filters['webhook_type'])) {
        $whereConditions[] = 'webhook_type = ?';
        $params[] = $filters['webhook_type'];
    }
    
    if (!empty($filters['event_type'])) {
        $whereConditions[] = 'event_type = ?';
        $params[] = $filters['event_type'];
    }
    
    if (!empty($filters['date_from'])) {
        $whereConditions[] = 'DATE(created_at) >= ?';
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $whereConditions[] = 'DATE(created_at) <= ?';
        $params[] = $filters['date_to'];
    }
    
    $maxRetries = min((int) ($filters['max_retries'] ?? 100), 500);
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions) . " LIMIT {$maxRetries}";
    
    $stmt = $db->prepare("SELECT id FROM odoo_webhooks_log {$whereClause}");
    $stmt->execute($params);
    $webhookIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $successCount = 0;
    $failureCount = 0;
    
    foreach ($webhookIds as $webhookId) {
        try {
            // Reset DLQ status if needed
            $resetStmt = $db->prepare("
                UPDATE odoo_webhooks_log 
                SET status = 'FAILED', dlq_at = NULL, dlq_reason = NULL, retry_count = 0
                WHERE id = ? AND status = 'DLQ'
            ");
            $resetStmt->execute([$webhookId]);
            
            // Retry webhook
            if ($webhookService->retryWebhook($webhookId)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        } catch (Exception $e) {
            $failureCount++;
        }
    }
    
    return [
        'total_found' => count($webhookIds),
        'retry_scheduled' => $successCount,
        'failed_to_schedule' => $failureCount,
        'filters' => $filters
    ];
}