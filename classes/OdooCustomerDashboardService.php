<?php
/**
 * Odoo Customer Dashboard Service
 *
 * Aggregates Odoo + webhook data into a single Customer 360 payload.
 *
 * @version 2.0.0
 * @updated 2026-03-16 — Parallel API execution via OdooAPIPool, response caching,
 *          optimized DB queries with prepared statement reuse, schema detection caching.
 */

require_once __DIR__ . '/OdooAPIClient.php';
require_once __DIR__ . '/OdooAPIPool.php';

class OdooCustomerDashboardService
{
    private $db;
    private $lineAccountId;
    private $odooClient;

    /** Cached schema metadata to avoid repeated information_schema queries */
    private static $tableExistsCache = [];
    private static $columnExistsCache = [];

    /** Short-lived response cache for API results (per-process) */
    private static $responseCache = [];
    private const RESPONSE_CACHE_TTL = 30;

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->odooClient = null;

        try {
            $this->odooClient = new OdooAPIClient($db, $lineAccountId);
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService: cannot init OdooAPIClient - ' . $e->getMessage());
        }
    }

    /**
     * Build Customer 360 dashboard from Odoo + webhook + projections.
     * Uses parallel API calls to reduce total latency.
     */
    public function buildByLineUserId($lineUserId, array $options = [])
    {
        $ordersLimit = max(1, min((int) ($options['orders_limit'] ?? 10), 50));
        $invoicesLimit = max(1, min((int) ($options['invoices_limit'] ?? 10), 50));
        $timelineLimit = max(1, min((int) ($options['timeline_limit'] ?? 20), 100));
        $topProducts = max(1, min((int) ($options['top_products'] ?? 5), 20));

        $dashboard = $this->emptyDashboard($lineUserId);

        $link = $this->getLinkByLineUserId($lineUserId);
        if (!$link) {
            return $dashboard;
        }

        $dashboard['linked'] = true;
        $dashboard['link'] = $link;

        $odooPartnerId = (int) ($link['odoo_partner_id'] ?? 0);
        $odooCustomerCode = trim((string) ($link['odoo_customer_code'] ?? ''));

        // ── Phase 1: Parallel API calls ──────────────────────────────────
        $apiResults = $this->fetchApiDataParallel($lineUserId, $ordersLimit, $invoicesLimit);

        $profile = $apiResults['profile'] ?? null;
        $credit = $apiResults['credit'] ?? null;
        $ordersResult = $apiResults['orders'] ?? null;
        $invoicesResult = $apiResults['invoices'] ?? null;

        // Track API warnings
        foreach (['profile', 'credit', 'orders', 'invoices'] as $key) {
            if (isset($apiResults[$key . '_error'])) {
                $dashboard['warnings'][] = "{$key}_api: " . $apiResults[$key . '_error'];
            }
        }

        // ── Phase 2: Profile ─────────────────────────────────────────────
        if ($profile && is_array($profile)) {
            $dashboard['profile'] = $profile;
        }

        // ── Phase 3: Credit — webhook override ───────────────────────────
        try {
            $creditFromWebhook = $this->getCreditFromWebhook($lineUserId, $odooPartnerId, $odooCustomerCode);
            if ($creditFromWebhook) {
                $credit = $creditFromWebhook;
                $dashboard['warnings'][] = 'credit_fallback: ใช้ข้อมูลจาก webhook logs';
            }
        } catch (Exception $e) {
            $dashboard['warnings'][] = 'credit_webhook_fallback_failed: ' . $e->getMessage();
        }

        $dashboard['credit'] = $this->normalizeCredit($credit, $profile);

        // ── Phase 4: Orders ──────────────────────────────────────────────
        $normalizedOrders = $this->normalizeOrders($ordersResult);
        $orders = $normalizedOrders['orders'];
        $ordersTotal = $normalizedOrders['total'];

        if (empty($orders)) {
            try {
                $ordersFromWebhook = $this->getOrdersFromWebhook($lineUserId, $odooPartnerId, $ordersLimit, $odooCustomerCode);
                if (!empty($ordersFromWebhook['orders'])) {
                    $orders = $ordersFromWebhook['orders'];
                    $ordersTotal = $ordersFromWebhook['total'];
                    $dashboard['warnings'][] = 'orders_fallback: ใช้ข้อมูลจาก webhook logs';
                }
            } catch (Exception $e) {
                $dashboard['warnings'][] = 'orders_webhook_fallback_failed: ' . $e->getMessage();
            }
        }

        if (empty($orders) && $this->tableExists('odoo_order_projection')) {
            $projection = $this->getProjectionOrders($lineUserId, $odooPartnerId, $ordersLimit);
            if (!empty($projection)) {
                $orders = $projection;
                $ordersTotal = count($projection);
            }
        }

        $dashboard['orders'] = [
            'total' => $ordersTotal,
            'recent' => $orders
        ];

        $dashboard['latest_order'] = $this->getLatestOrder($orders, $lineUserId, $odooPartnerId);

        // ── Phase 5: Invoices — webhook override ─────────────────────────
        try {
            $invoicesFromWebhook = $this->getInvoicesFromWebhook($lineUserId, $odooPartnerId, $invoicesLimit, $odooCustomerCode);
            if (!empty($invoicesFromWebhook['invoices'])) {
                $invoicesResult = $invoicesFromWebhook;
                $dashboard['warnings'][] = 'invoices_fallback: ใช้ข้อมูลจาก webhook logs';

                if ($this->shouldUseCreditFallback($credit)) {
                    $derivedDue = 0.0;
                    $derivedOverdue = 0.0;
                    foreach ($invoicesFromWebhook['invoices'] as $inv) {
                        $residual = (float) ($inv['amount_residual'] ?? $inv['amount_total'] ?? 0);
                        $state = strtolower((string) ($inv['state'] ?? ''));
                        if (in_array($state, ['paid', 'cancel', 'cancelled'], true)) {
                            continue;
                        }
                        $derivedDue += $residual;
                        if (!empty($inv['is_overdue'])) {
                            $derivedOverdue += $residual;
                        }
                    }

                    if ($derivedDue > 0 || $derivedOverdue > 0) {
                        $credit = is_array($credit) ? $credit : [];
                        $credit['total_due'] = $derivedDue;
                        $credit['overdue_amount'] = $derivedOverdue;
                        $dashboard['warnings'][] = 'credit_fallback: ใช้ยอดค้างจากใบแจ้งหนี้ webhook';
                    }
                }
            }
        } catch (Exception $e) {
            $dashboard['warnings'][] = 'invoices_webhook_fallback_failed: ' . $e->getMessage();
        }

        $dashboard['invoices'] = $this->normalizeInvoices($invoicesResult);

        // ── Phase 6: Timeline + summary + products ───────────────────────
        $timelineBundle = $this->getTimelineAndSummary(
            $lineUserId,
            $odooPartnerId,
            $dashboard['latest_order']['order_name'] ?? null,
            $dashboard['latest_order']['order_id'] ?? null,
            $timelineLimit
        );
        $dashboard['timeline'] = $timelineBundle['timeline'];
        $dashboard['webhook_summary'] = $timelineBundle['summary'];

        $dashboard['frequent_products'] = $this->buildFrequentProducts($lineUserId, $odooPartnerId, $orders, $topProducts);

        return $dashboard;
    }

    // ========================================================================
    // Parallel API Fetch
    // ========================================================================

    /**
     * Fetch profile, credit, orders, and invoices in parallel using curl_multi.
     */
    private function fetchApiDataParallel(string $lineUserId, int $ordersLimit, int $invoicesLimit): array
    {
        $results = [];

        if (!$this->odooClient) {
            return $results;
        }

        // Check response cache first
        $cacheKey = 'dashboard_' . md5($lineUserId . $ordersLimit . $invoicesLimit);
        if (isset(self::$responseCache[$cacheKey])) {
            $cached = self::$responseCache[$cacheKey];
            if ((time() - $cached['_ts']) < self::RESPONSE_CACHE_TTL) {
                return $cached;
            }
            unset(self::$responseCache[$cacheKey]);
        }

        try {
            $pool = new OdooAPIPool(
                ODOO_API_KEY,
                ODOO_API_BASE_URL,
                15,  // 15s timeout per request (reduced from 30s for dashboard)
                5
            );

            $pool->add('profile', '/reya/user/profile', ['line_user_id' => $lineUserId]);
            $pool->add('credit', '/reya/credit-status', ['line_user_id' => $lineUserId]);
            $pool->add('orders', '/reya/orders', ['line_user_id' => $lineUserId, 'limit' => $ordersLimit]);
            $pool->add('invoices', '/reya/invoices', ['line_user_id' => $lineUserId, 'limit' => $invoicesLimit]);

            $poolResults = $pool->execute();

            foreach (['profile', 'credit', 'orders', 'invoices'] as $key) {
                $r = $poolResults[$key] ?? null;
                if ($r && ($r['success'] ?? false)) {
                    $results[$key] = $r['data'] ?? [];
                } else {
                    $results[$key] = null;
                    if ($r && isset($r['error'])) {
                        $results[$key . '_error'] = $r['error'];
                    }
                }
            }

            // Cache the result
            $results['_ts'] = time();
            self::$responseCache[$cacheKey] = $results;

        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService parallel fetch failed: ' . $e->getMessage());
            // Fall back to sequential calls
            $results = $this->fetchApiDataSequential($lineUserId, $ordersLimit, $invoicesLimit);
        }

        return $results;
    }

    /**
     * Fallback: sequential API calls when parallel execution fails.
     */
    private function fetchApiDataSequential(string $lineUserId, int $ordersLimit, int $invoicesLimit): array
    {
        $results = [];

        if (!$this->odooClient) {
            return $results;
        }

        foreach ([
            'profile' => fn() => $this->odooClient->getUserProfile($lineUserId),
            'credit' => fn() => $this->odooClient->getCreditStatus($lineUserId),
            'orders' => fn() => $this->odooClient->getOrders($lineUserId, ['limit' => $ordersLimit]),
            'invoices' => fn() => $this->odooClient->getInvoices($lineUserId, ['limit' => $invoicesLimit]),
        ] as $key => $fn) {
            try {
                $results[$key] = $fn();
            } catch (Exception $e) {
                $results[$key] = null;
                $results[$key . '_error'] = $e->getMessage();
            }
        }

        return $results;
    }

    // ========================================================================
    // Data Normalization
    // ========================================================================

    private function emptyDashboard(string $lineUserId): array
    {
        return [
            'line_user_id' => $lineUserId,
            'generated_at' => date('c'),
            'linked' => false,
            'link' => null,
            'profile' => null,
            'credit' => [
                'credit_limit' => null,
                'credit_used' => null,
                'credit_remaining' => null,
                'total_due' => null,
                'overdue_amount' => null
            ],
            'latest_order' => null,
            'orders' => ['total' => 0, 'recent' => []],
            'timeline' => [],
            'frequent_products' => [],
            'invoices' => ['total' => 0, 'recent' => []],
            'webhook_summary' => [
                'total' => 0, 'success' => 0, 'failed' => 0,
                'retry' => 0, 'dead_letter' => 0, 'duplicate' => 0,
                'last_event_at' => null
            ],
            'warnings' => []
        ];
    }

    private function normalizeCredit($credit, $profile)
    {
        $credit = is_array($credit) ? $credit : [];
        $profile = is_array($profile) ? $profile : [];

        $creditLimit = $credit['credit_limit'] ?? $profile['credit_limit'] ?? null;
        $creditUsed = $credit['credit_used'] ?? null;
        $creditRemaining = $credit['credit_remaining'] ?? null;

        if ($creditUsed === null && $creditLimit !== null && $creditRemaining !== null) {
            $creditUsed = (float) $creditLimit - (float) $creditRemaining;
        }

        if ($creditRemaining === null && $creditLimit !== null && $creditUsed !== null) {
            $creditRemaining = (float) $creditLimit - (float) $creditUsed;
        }

        return [
            'credit_limit' => $creditLimit !== null ? (float) $creditLimit : null,
            'credit_used' => $creditUsed !== null ? (float) $creditUsed : null,
            'credit_remaining' => $creditRemaining !== null ? (float) $creditRemaining : null,
            'total_due' => isset($credit['total_due'])
                ? (float) $credit['total_due']
                : (isset($profile['total_due']) ? (float) $profile['total_due'] : null),
            'overdue_amount' => isset($credit['overdue_amount']) ? (float) $credit['overdue_amount'] : null
        ];
    }

    private function normalizeOrders($result)
    {
        if (!is_array($result)) {
            return ['orders' => [], 'total' => 0];
        }

        // Try common response shapes in priority order
        $extractors = [
            fn($r) => isset($r['orders']) && is_array($r['orders'])
                ? [$r['orders'], (int) ($r['total'] ?? $r['total_count'] ?? count($r['orders']))]
                : null,
            fn($r) => isset($r['data']['orders']) && is_array($r['data']['orders'])
                ? [$r['data']['orders'], (int) ($r['meta']['total'] ?? $r['data']['total'] ?? count($r['data']['orders']))]
                : null,
            fn($r) => isset($r['data']) && is_array($r['data']) && isset($r['data'][0])
                ? [$r['data'], count($r['data'])]
                : null,
            fn($r) => isset($r['result']['orders']) && is_array($r['result']['orders'])
                ? [$r['result']['orders'], (int) ($r['result']['total'] ?? count($r['result']['orders']))]
                : null,
            fn($r) => isset($r['result']) && is_array($r['result']) && isset($r['result'][0])
                ? [$r['result'], count($r['result'])]
                : null,
            fn($r) => isset($r[0])
                ? [$r, count($r)]
                : null,
        ];

        foreach ($extractors as $extractor) {
            $pair = $extractor($result);
            if ($pair !== null) {
                return ['orders' => $pair[0], 'total' => $pair[1]];
            }
        }

        return ['orders' => [], 'total' => 0];
    }

    private function normalizeInvoices($result)
    {
        $invoices = [];
        $total = 0;

        if (!is_array($result)) {
            return ['total' => 0, 'recent' => []];
        }

        if (isset($result['invoices']) && is_array($result['invoices'])) {
            $invoices = $result['invoices'];
            $total = (int) ($result['total'] ?? $result['total_count'] ?? count($invoices));
        } elseif (isset($result['data']['invoices']) && is_array($result['data']['invoices'])) {
            $invoices = $result['data']['invoices'];
            $total = (int) ($result['meta']['total'] ?? $result['data']['total'] ?? count($invoices));
        } elseif (isset($result['data']) && is_array($result['data']) && isset($result['data'][0])) {
            $invoices = $result['data'];
            $total = count($invoices);
        } elseif (isset($result[0])) {
            $invoices = $result;
            $total = count($invoices);
        }

        $normalized = [];
        foreach ($invoices as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }

            $amountTotal = isset($invoice['amount_total']) ? (float) $invoice['amount_total'] : 0.0;
            $amountResidual = $invoice['amount_residual'] ?? null;
            if ($amountResidual === null || $amountResidual === '') {
                $amountResidual = $amountTotal;
            }

            $state = strtolower((string) ($invoice['state'] ?? ''));
            $isOverdue = isset($invoice['is_overdue'])
                ? (bool) $invoice['is_overdue']
                : (!empty($invoice['due_date']) && !in_array($state, ['paid', 'cancel', 'cancelled'], true) && strtotime($invoice['due_date']) < time());

            $invoice['amount_total'] = $amountTotal;
            $invoice['amount_residual'] = (float) $amountResidual;
            $invoice['is_overdue'] = $isOverdue;
            $normalized[] = $invoice;
        }

        return [
            'total' => $total,
            'recent' => $normalized
        ];
    }

    private function shouldUseCreditFallback($credit)
    {
        if (!is_array($credit) || empty($credit)) {
            return true;
        }

        foreach (['credit_limit', 'credit_used', 'credit_remaining', 'total_due', 'overdue_amount'] as $key) {
            if (isset($credit[$key]) && $credit[$key] !== '' && $credit[$key] !== null && (float) $credit[$key] !== 0.0) {
                return false;
            }
        }

        return true;
    }

    // ========================================================================
    // Data Retrieval — Webhook Fallbacks
    // ========================================================================

    private function getLatestOrder(array $orders, $lineUserId, $odooPartnerId)
    {
        if (!empty($orders)) {
            $first = $orders[0];
            return [
                'order_id' => $first['id'] ?? $first['order_id'] ?? null,
                'order_name' => $first['name'] ?? $first['order_name'] ?? null,
                'state' => $first['state'] ?? $first['new_state'] ?? null,
                'state_display' => $first['state_display'] ?? $first['new_state_display'] ?? null,
                'amount_total' => isset($first['amount_total']) ? (float) $first['amount_total'] : null,
                'date_order' => $first['date_order'] ?? $first['create_date'] ?? null,
                'order_lines' => $first['order_lines'] ?? $first['order_line'] ?? []
            ];
        }

        if (!$this->tableExists('odoo_order_projection')) {
            return null;
        }

        try {
            $where = [];
            $params = [];

            if ($lineUserId) {
                $where[] = 'line_user_id = ?';
                $params[] = $lineUserId;
            }
            if ($odooPartnerId) {
                $where[] = 'odoo_partner_id = ?';
                $params[] = (int) $odooPartnerId;
            }

            if (empty($where)) {
                return null;
            }

            $stmt = $this->db->prepare(
                'SELECT order_id, order_name, latest_state, latest_state_display, amount_total, last_webhook_at
                 FROM odoo_order_projection
                 WHERE ' . implode(' OR ', $where) . '
                 ORDER BY last_webhook_at DESC
                 LIMIT 1'
            );
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return [
                'order_id' => $row['order_id'] ?? null,
                'order_name' => $row['order_name'] ?? null,
                'state' => $row['latest_state'] ?? null,
                'state_display' => $row['latest_state_display'] ?? null,
                'amount_total' => isset($row['amount_total']) ? (float) $row['amount_total'] : null,
                'date_order' => $row['last_webhook_at'] ?? null,
                'order_lines' => []
            ];
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService latest order fallback error: ' . $e->getMessage());
            return null;
        }
    }

    private function getProjectionOrders($lineUserId, $odooPartnerId, $limit)
    {
        try {
            $where = [];
            $params = [];

            if ($lineUserId) {
                $where[] = 'line_user_id = ?';
                $params[] = $lineUserId;
            }
            if ($odooPartnerId) {
                $where[] = 'odoo_partner_id = ?';
                $params[] = (int) $odooPartnerId;
            }

            if (empty($where)) {
                return [];
            }

            $stmt = $this->db->prepare(
                'SELECT order_id as id, order_name as name, latest_state as state, latest_state_display as state_display,
                        amount_total, last_webhook_at as date_order
                 FROM odoo_order_projection
                 WHERE ' . implode(' OR ', $where) . '
                 ORDER BY last_webhook_at DESC
                 LIMIT ' . (int) $limit
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService projection orders fallback error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Build timeline and webhook summary using a single optimized query.
     */
    private function getTimelineAndSummary($lineUserId, $odooPartnerId, $orderName = null, $orderId = null, $limit = 20)
    {
        $hasErrorCode = $this->hasWebhookColumn('odoo_webhooks_log', 'last_error_code');

        $where = [];
        $params = [];

        if ($lineUserId) {
            $where[] = 'line_user_id = ?';
            $params[] = $lineUserId;

            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')) = ?";
            $params[] = $lineUserId;
        }

        if ($odooPartnerId) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')) = ?";
            $params[] = (string) $odooPartnerId;

            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')) = ?";
            $params[] = (string) $odooPartnerId;
        }

        if ($orderId) {
            $where[] = 'order_id = ?';
            $params[] = $orderId;
        }

        if ($orderName) {
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) = ?";
            $params[] = $orderName;
            $where[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_ref')) = ?";
            $params[] = $orderName;
        }

        $emptySummary = [
            'total' => 0, 'success' => 0, 'failed' => 0,
            'retry' => 0, 'dead_letter' => 0, 'duplicate' => 0,
            'last_event_at' => null
        ];

        if (empty($where)) {
            return ['timeline' => [], 'summary' => $emptySummary];
        }

        $whereSql = implode(' OR ', $where);

        $timeline = [];
        $summary = $emptySummary;

        try {
            $summaryStmt = $this->db->prepare(
                "SELECT status, COUNT(*) as cnt, MAX(processed_at) as last_event_at
                 FROM odoo_webhooks_log
                 WHERE {$whereSql}
                 GROUP BY status"
            );
            $summaryStmt->execute($params);
            $rows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

            $lastEventAt = null;
            foreach ($rows as $row) {
                $status = $row['status'] ?? 'unknown';
                $count = (int) ($row['cnt'] ?? 0);
                $summary['total'] += $count;
                if (isset($summary[$status])) {
                    $summary[$status] = $count;
                }
                if (!empty($row['last_event_at']) && ($lastEventAt === null || $row['last_event_at'] > $lastEventAt)) {
                    $lastEventAt = $row['last_event_at'];
                }
            }
            $summary['last_event_at'] = $lastEventAt;

            $errorCodeCol = $hasErrorCode ? 'last_error_code' : 'NULL';
            $timelineStmt = $this->db->prepare(
                "SELECT id, event_type, status, processed_at, error_message, {$errorCodeCol} as last_error_code,
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) as order_name,
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')) as new_state_display,
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')) as amount_total
                 FROM odoo_webhooks_log
                 WHERE {$whereSql}
                 ORDER BY processed_at DESC
                 LIMIT " . (int) $limit
            );
            $timelineStmt->execute($params);
            $timelineRows = $timelineStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($timelineRows as $row) {
                $timeline[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'event_type' => $row['event_type'] ?? null,
                    'status' => $row['status'] ?? null,
                    'processed_at' => $row['processed_at'] ?? null,
                    'order_name' => $row['order_name'] ?? null,
                    'state_display' => $row['new_state_display'] ?? null,
                    'amount_total' => isset($row['amount_total']) ? (float) $row['amount_total'] : null,
                    'error_message' => $row['error_message'] ?? null,
                    'error_code' => $row['last_error_code'] ?? null
                ];
            }
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService timeline error: ' . $e->getMessage());
        }

        return [
            'timeline' => $timeline,
            'summary' => $summary
        ];
    }

    /**
     * Build optimized WHERE clause for customer matching in webhook queries.
     * Combines line_user_id, partner_id, and customer_code in a single clause.
     */
    private function buildCustomerWhere(string $lineUserId, int $odooPartnerId, ?string $odooCustomerCode): array
    {
        $partnerId = (string) $odooPartnerId;
        $customerCode = ($odooCustomerCode !== null && trim($odooCustomerCode) !== '') ? trim($odooCustomerCode) : null;

        $conditions = [
            'line_user_id = ?',
            "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')) = ?",
            "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')) = ?",
            "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')) = ?",
        ];
        $params = [$lineUserId, $lineUserId, $partnerId, $partnerId];

        if ($customerCode !== null) {
            $conditions[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')) = ?";
            $conditions[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.code')) = ?";
            $conditions[] = "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.customer_code')) = ?";
            $params[] = $customerCode;
            $params[] = $customerCode;
            $params[] = $customerCode;
        }

        return [
            'sql' => '(' . implode(' OR ', $conditions) . ')',
            'params' => $params,
        ];
    }

    private function getOrdersFromWebhook($lineUserId, $odooPartnerId, $limit = 10, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        $processedAtExpr = $this->hasWebhookColumn('odoo_webhooks_log', 'processed_at') ? 'processed_at' : 'NOW()';
        $custWhere = $this->buildCustomerWhere($lineUserId, (int) $odooPartnerId, $odooCustomerCode ?: null);

        $stmt = $this->db->prepare("
            SELECT
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.id'))
                ) as order_id,
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.name'))
                ) as order_name,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state'))        as state,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.new_state_display')) as state_display,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total'))      as amount_total,
                {$processedAtExpr}                                         as date_order
            FROM odoo_webhooks_log
            WHERE (status IS NULL OR LOWER(status) IN ('success', 'done', 'ok'))
              AND {$custWhere['sql']}
              AND (
                  JSON_EXTRACT(payload, '$.order_id') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.order_name') IS NOT NULL
              )
            ORDER BY {$processedAtExpr} DESC
            LIMIT ?
        ");
        $stmt->execute(array_merge($custWhere['params'], [(int) $limit]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $seen = [];
        $orders = [];
        foreach ($rows as $row) {
            $key = $row['order_id'] ?: $row['order_name'];
            if ($key === null || $key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $orders[] = [
                'id' => $row['order_id'],
                'name' => $row['order_name'],
                'order_id' => $row['order_id'],
                'order_name' => $row['order_name'],
                'state' => $row['state'],
                'state_display' => $row['state_display'],
                'amount_total' => $row['amount_total'] !== null ? (float) $row['amount_total'] : null,
                'date_order' => $row['date_order'],
                'order_lines' => []
            ];
        }

        return ['orders' => $orders, 'total' => count($orders)];
    }

    private function getCreditFromWebhook($lineUserId, $odooPartnerId, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        $processedAtExpr = $this->hasWebhookColumn('odoo_webhooks_log', 'processed_at') ? 'processed_at' : 'NOW()';
        $custWhere = $this->buildCustomerWhere($lineUserId, (int) $odooPartnerId, $odooCustomerCode ?: null);

        $stmt = $this->db->prepare("
            SELECT COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.credit_limit')),
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.credit_limit')),
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer_credit_limit'))
                   ) as credit_limit,
                   COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.total_due')),
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.total_due'))
                   ) as total_due,
                   COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.overdue_amount')),
                        JSON_UNQUOTE(JSON_EXTRACT(payload, '$.overdue_amount'))
                   ) as overdue_amount
            FROM odoo_webhooks_log
            WHERE (status IS NULL OR LOWER(status) IN ('success', 'done', 'ok'))
              AND {$custWhere['sql']}
              AND (
                  JSON_EXTRACT(payload, '$.customer.credit_limit') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.credit_limit') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.customer_credit_limit') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.customer.total_due') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.total_due') IS NOT NULL
              )
            ORDER BY {$processedAtExpr} DESC
            LIMIT 1
        ");
        $stmt->execute($custWhere['params']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        return [
            'credit_limit' => $row['credit_limit'] ? (float) $row['credit_limit'] : null,
            'total_due' => $row['total_due'] ? (float) $row['total_due'] : null,
            'overdue_amount' => $row['overdue_amount'] ? (float) $row['overdue_amount'] : null
        ];
    }

    private function getInvoicesFromWebhook($lineUserId, $odooPartnerId, $limit = 10, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        $processedAtExpr = $this->hasWebhookColumn('odoo_webhooks_log', 'processed_at') ? 'processed_at' : 'NOW()';
        $custWhere = $this->buildCustomerWhere($lineUserId, (int) $odooPartnerId, $odooCustomerCode ?: null);

        $stmt = $this->db->prepare("
            SELECT DISTINCT
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_number')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.name')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice.number'))
                ) as invoice_number,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_name')) as order_name,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_total')) as amount_total,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount_residual')) as amount_residual,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.invoice_date')) as invoice_date,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.due_date')) as due_date,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.state')) as state,
                {$processedAtExpr} as processed_at
            FROM odoo_webhooks_log
            WHERE (status IS NULL OR LOWER(status) IN ('success', 'done', 'ok'))
              AND {$custWhere['sql']}
              AND (
                  JSON_EXTRACT(payload, '$.invoice_number') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.invoice.name') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.invoice.number') IS NOT NULL
                  OR LOWER(COALESCE(event_type, '')) LIKE '%invoice%'
              )
            ORDER BY processed_at DESC
            LIMIT ?
        ");
        $stmt->execute(array_merge($custWhere['params'], [(int) $limit]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $invoices = [];
        foreach ($rows as $row) {
            $invoices[] = [
                'invoice_number' => $row['invoice_number'] ?: ($row['order_name'] ?: '-'),
                'name' => $row['invoice_number'] ?: ($row['order_name'] ?: '-'),
                'order_name' => $row['order_name'],
                'amount_total' => $row['amount_total'] ? (float) $row['amount_total'] : null,
                'amount_residual' => $row['amount_residual'] !== null && $row['amount_residual'] !== ''
                    ? (float) $row['amount_residual']
                    : ($row['amount_total'] ? (float) $row['amount_total'] : 0.0),
                'invoice_date' => $row['invoice_date'],
                'due_date' => $row['due_date'],
                'state' => $row['state'],
                'state_display' => $row['state'] ?: '-',
                'is_overdue' => !empty($row['due_date'])
                    && !in_array(strtolower((string) ($row['state'] ?? '')), ['paid', 'cancel', 'cancelled'], true)
                    && strtotime($row['due_date']) < time()
            ];
        }

        return ['invoices' => $invoices, 'total' => count($invoices)];
    }

    private function buildFrequentProducts($lineUserId, $odooPartnerId, array $orders, $topProducts)
    {
        $stats = [];

        foreach ($orders as $order) {
            $orderLines = $order['order_lines'] ?? $order['order_line'] ?? $order['lines'] ?? [];
            if (!is_array($orderLines)) {
                continue;
            }

            foreach ($orderLines as $line) {
                if (!is_array($line)) {
                    continue;
                }

                $name = trim((string) ($line['product_name'] ?? $line['name'] ?? $line['product']['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $qty = (float) ($line['product_uom_qty'] ?? $line['qty'] ?? $line['quantity'] ?? 1);
                if ($qty <= 0) {
                    $qty = 1;
                }

                $amount = (float) ($line['price_subtotal'] ?? ($qty * (float) ($line['price_unit'] ?? 0)));

                if (!isset($stats[$name])) {
                    $stats[$name] = ['product_name' => $name, 'qty' => 0, 'amount' => 0];
                }

                $stats[$name]['qty'] += $qty;
                $stats[$name]['amount'] += $amount;
            }
        }

        if (!empty($stats)) {
            usort($stats, function ($a, $b) {
                return $b['amount'] <=> $a['amount'] ?: $b['qty'] <=> $a['qty'];
            });
            return array_slice(array_values($stats), 0, $topProducts);
        }

        if (!$this->tableExists('odoo_customer_product_stats')) {
            return [];
        }

        try {
            $where = [];
            $params = [];

            if ($lineUserId) {
                $where[] = 'line_user_id = ?';
                $params[] = $lineUserId;
            }
            if ($odooPartnerId) {
                $where[] = 'odoo_partner_id = ?';
                $params[] = (int) $odooPartnerId;
            }

            if (empty($where)) {
                return [];
            }

            $stmt = $this->db->prepare(
                'SELECT product_name, qty_90d as qty, amount_90d as amount, last_purchased_at
                 FROM odoo_customer_product_stats
                 WHERE ' . implode(' OR ', $where) . '
                 ORDER BY amount_90d DESC, qty_90d DESC
                 LIMIT ' . (int) $topProducts
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService frequent products fallback error: ' . $e->getMessage());
            return [];
        }
    }

    // ========================================================================
    // Schema & Link Helpers (with static caching)
    // ========================================================================

    private function getLinkByLineUserId($lineUserId)
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM odoo_line_users WHERE line_user_id = ? LIMIT 1');
            $stmt->execute([$lineUserId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('OdooCustomerDashboardService link lookup error: ' . $e->getMessage());
            return null;
        }
    }

    private function tableExists($table)
    {
        if (isset(self::$tableExistsCache[$table])) {
            return self::$tableExistsCache[$table];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            self::$tableExistsCache[$table] = (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            self::$tableExistsCache[$table] = false;
        }

        return self::$tableExistsCache[$table];
    }

    private function hasWebhookColumn($table, $column)
    {
        $key = "{$table}.{$column}";
        if (isset(self::$columnExistsCache[$key])) {
            return self::$columnExistsCache[$key];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            self::$columnExistsCache[$key] = (bool) $stmt->fetchColumn();
        } catch (Exception $e) {
            self::$columnExistsCache[$key] = false;
        }

        return self::$columnExistsCache[$key];
    }
}
