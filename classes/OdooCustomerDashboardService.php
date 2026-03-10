<?php
/**
 * Odoo Customer Dashboard Service
 *
 * Aggregates Odoo + webhook data into a single Customer 360 payload.
 */

require_once __DIR__ . '/OdooAPIClient.php';

class OdooCustomerDashboardService
{
    private $db;
    private $lineAccountId;
    private $odooClient;

    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->odooClient = null;

        try {
            $this->odooClient = new OdooAPIClient($db, $lineAccountId);
        } catch (Exception $e) {
            // Keep running with local data when Odoo credentials are unavailable.
            error_log('OdooCustomerDashboardService: cannot init OdooAPIClient - ' . $e->getMessage());
        }
    }

    /**
     * Build Customer 360 dashboard from Odoo + webhook + projections.
     *
     * @param string $lineUserId
     * @param array $options
     * @return array
     */
    public function buildByLineUserId($lineUserId, array $options = [])
    {
        $ordersLimit = max(1, min((int) ($options['orders_limit'] ?? 10), 50));
        $invoicesLimit = max(1, min((int) ($options['invoices_limit'] ?? 10), 50));
        $timelineLimit = max(1, min((int) ($options['timeline_limit'] ?? 20), 100));
        $topProducts = max(1, min((int) ($options['top_products'] ?? 5), 20));

        $dashboard = [
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
            'orders' => [
                'total' => 0,
                'recent' => []
            ],
            'timeline' => [],
            'frequent_products' => [],
            'invoices' => [
                'total' => 0,
                'recent' => []
            ],
            'webhook_summary' => [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'retry' => 0,
                'dead_letter' => 0,
                'duplicate' => 0,
                'last_event_at' => null
            ],
            'warnings' => []
        ];

        $link = $this->getLinkByLineUserId($lineUserId);
        if (!$link) {
            return $dashboard;
        }

        $dashboard['linked'] = true;
        $dashboard['link'] = $link;

        $odooPartnerId = (int) ($link['odoo_partner_id'] ?? 0);
        $odooCustomerCode = trim((string) ($link['odoo_customer_code'] ?? ''));

        // Profile
        $profile = null;
        if ($this->odooClient) {
            try {
                $profile = $this->odooClient->getUserProfile($lineUserId);
            } catch (Exception $e) {
                $dashboard['warnings'][] = 'profile_api: ' . $e->getMessage();
            }
        }

        if ($profile && is_array($profile)) {
            $dashboard['profile'] = $profile;
        }

        // Credit
        $credit = null;
        if ($this->odooClient) {
            try {
                $credit = $this->odooClient->getCreditStatus($lineUserId);
            } catch (Exception $e) {
                $dashboard['warnings'][] = 'credit_api: ' . $e->getMessage();
                // Debug: Show full error details
                $dashboard['warnings'][] = 'credit_api_debug: ' . $e->getTraceAsString();
            }
        }

        // Webhook override: always try webhook data and override API/profile when found.
        try {
            $creditFromWebhook = $this->getCreditFromWebhook($lineUserId, $odooPartnerId, $odooCustomerCode);
            if ($creditFromWebhook) {
                // User-approved behavior: webhook values override profile/API values when available.
                $credit = $creditFromWebhook;
                $dashboard['warnings'][] = 'credit_fallback: ใช้ข้อมูลจาก webhook logs';
            }
        } catch (Exception $e) {
            $dashboard['warnings'][] = 'credit_webhook_fallback_failed: ' . $e->getMessage();
        }

        $dashboard['credit'] = $this->normalizeCredit($credit, $profile);

        // Orders
        $ordersResult = null;
        if ($this->odooClient) {
            try {
                $ordersResult = $this->odooClient->getOrders($lineUserId, ['limit' => $ordersLimit]);
            } catch (Exception $e) {
                $dashboard['warnings'][] = 'orders_api: ' . $e->getMessage();
            }
        }

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

        // Invoices
        $invoicesResult = null;
        if ($this->odooClient) {
            try {
                $invoicesResult = $this->odooClient->getInvoices($lineUserId, ['limit' => $invoicesLimit]);
            } catch (Exception $e) {
                $dashboard['warnings'][] = 'invoices_api: ' . $e->getMessage();
                // Debug: Show full error details
                $dashboard['warnings'][] = 'invoices_api_debug: ' . $e->getTraceAsString();
            }
        }

        // Webhook override: always try webhook invoices and override when rows are found.
        try {
            $invoicesFromWebhook = $this->getInvoicesFromWebhook($lineUserId, $odooPartnerId, $invoicesLimit, $odooCustomerCode);
            if (!empty($invoicesFromWebhook['invoices'])) {
                $invoicesResult = $invoicesFromWebhook;
                $dashboard['warnings'][] = 'invoices_fallback: ใช้ข้อมูลจาก webhook logs';

                // If credit payload is unavailable, derive due figures from invoices so credit card is not all zeros.
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

        // Timeline and webhook summary
        $timelineBundle = $this->getTimelineAndSummary(
            $lineUserId,
            $odooPartnerId,
            $dashboard['latest_order']['order_name'] ?? null,
            $dashboard['latest_order']['order_id'] ?? null,
            $timelineLimit
        );
        $dashboard['timeline'] = $timelineBundle['timeline'];
        $dashboard['webhook_summary'] = $timelineBundle['summary'];

        // Frequent products
        $dashboard['frequent_products'] = $this->buildFrequentProducts($lineUserId, $odooPartnerId, $orders, $topProducts);

        return $dashboard;
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
        $orders = [];
        $total = 0;

        if (!is_array($result)) {
            return ['orders' => [], 'total' => 0];
        }

        if (isset($result['orders']) && is_array($result['orders'])) {
            $orders = $result['orders'];
            $total = (int) ($result['total'] ?? $result['total_count'] ?? count($orders));
            return ['orders' => $orders, 'total' => $total];
        }

        if (isset($result['data']) && is_array($result['data'])) {
            if (isset($result['data']['orders']) && is_array($result['data']['orders'])) {
                $orders = $result['data']['orders'];
                $total = (int) ($result['meta']['total'] ?? $result['data']['total'] ?? count($orders));
                return ['orders' => $orders, 'total' => $total];
            }
            if (isset($result['data'][0])) {
                $orders = $result['data'];
                return ['orders' => $orders, 'total' => count($orders)];
            }
        }

        if (isset($result['result']) && is_array($result['result'])) {
            if (isset($result['result']['orders']) && is_array($result['result']['orders'])) {
                $orders = $result['result']['orders'];
                $total = (int) ($result['result']['total'] ?? count($orders));
                return ['orders' => $orders, 'total' => $total];
            }
            if (isset($result['result'][0])) {
                $orders = $result['result'];
                return ['orders' => $orders, 'total' => count($orders)];
            }
        }

        if (isset($result[0])) {
            $orders = $result;
            return ['orders' => $orders, 'total' => count($orders)];
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
                // User-approved rule: use amount_total when residual is absent.
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

        $keys = ['credit_limit', 'credit_used', 'credit_remaining', 'total_due', 'overdue_amount'];
        $hasAnyNonZero = false;
        foreach ($keys as $key) {
            if (!isset($credit[$key]) || $credit[$key] === '' || $credit[$key] === null) {
                continue;
            }

            if ((float) $credit[$key] !== 0.0) {
                $hasAnyNonZero = true;
                break;
            }
        }

        // Use webhook fallback when API returns all-zero credit values.
        return !$hasAnyNonZero;
    }

    private function shouldUseInvoicesFallback($invoicesResult)
    {
        if (!is_array($invoicesResult) || empty($invoicesResult)) {
            return true;
        }

        // Use the same normalization logic as renderer to avoid missing nested formats (e.g. result.data.*).
        $normalized = $this->normalizeInvoices($invoicesResult);
        return empty($normalized['recent']);
    }

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

        if (empty($where)) {
            return [
                'timeline' => [],
                'summary' => [
                    'total' => 0,
                    'success' => 0,
                    'failed' => 0,
                    'retry' => 0,
                    'dead_letter' => 0,
                    'duplicate' => 0,
                    'last_event_at' => null
                ]
            ];
        }

        $whereSql = implode(' OR ', $where);

        $timeline = [];
        $summary = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'retry' => 0,
            'dead_letter' => 0,
            'duplicate' => 0,
            'last_event_at' => null
        ];

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

            $timelineStmt = $this->db->prepare(
                'SELECT id, event_type, status, processed_at, error_message, ' . ($hasErrorCode ? 'last_error_code,' : 'NULL as last_error_code,') . '
                        JSON_UNQUOTE(JSON_EXTRACT(payload, "$.order_name")) as order_name,
                        JSON_UNQUOTE(JSON_EXTRACT(payload, "$.new_state_display")) as new_state_display,
                        JSON_UNQUOTE(JSON_EXTRACT(payload, "$.amount_total")) as amount_total
                 FROM odoo_webhooks_log
                 WHERE ' . $whereSql . '
                 ORDER BY processed_at DESC
                 LIMIT ' . (int) $limit
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
     * Get orders from webhook logs (fallback when API is unavailable)
     */
    private function getOrdersFromWebhook($lineUserId, $odooPartnerId, $limit = 10, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        $processedAtExpr = $this->hasWebhookColumn('odoo_webhooks_log', 'processed_at') ? 'processed_at' : 'NOW()';
        $partnerId = (string) ((int) $odooPartnerId);
        $customerCode = trim((string) $odooCustomerCode);
        if ($customerCode === '') {
            $customerCode = null;
        }

        $stmt = $this->db->prepare("
            SELECT
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.order_id')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.id'))
                ) as order_id,
                COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.order_name')),
                    JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.name'))
                ) as order_name,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.new_state'))        as state,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.new_state_display')) as state_display,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.amount_total'))      as amount_total,
                {$processedAtExpr}                                          as date_order
            FROM odoo_webhooks_log
            WHERE (
                    status IS NULL
                    OR LOWER(status) IN ('success', 'done', 'ok')
                  )
              AND (
                  line_user_id = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.customer.line_user_id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.customer.id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.customer.partner_id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.customer.ref')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.customer.code')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '\$.customer.customer_code')) = ?
              )
              AND (
                  JSON_EXTRACT(payload, '\$.order_id') IS NOT NULL
                  OR JSON_EXTRACT(payload, '\$.order_name') IS NOT NULL
              )
            ORDER BY {$processedAtExpr} DESC
            LIMIT ?
        ");
        $stmt->execute([$lineUserId, $lineUserId, $partnerId, $partnerId, $partnerId, $customerCode, $customerCode, (int) $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // De-duplicate by order_id / order_name keeping the latest state per order
        $seen = [];
        $orders = [];
        foreach ($rows as $row) {
            $key = $row['order_id'] ?: $row['order_name'];
            if ($key === null || $key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $orders[] = [
                'id'           => $row['order_id'],
                'name'         => $row['order_name'],
                'order_id'     => $row['order_id'],
                'order_name'   => $row['order_name'],
                'state'        => $row['state'],
                'state_display' => $row['state_display'],
                'amount_total' => $row['amount_total'] !== null ? (float) $row['amount_total'] : null,
                'date_order'   => $row['date_order'],
                'order_lines'  => []
            ];
        }

        return ['orders' => $orders, 'total' => count($orders)];
    }

    /**
     * Get credit information from webhook logs
     */
    private function getCreditFromWebhook($lineUserId, $odooPartnerId, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        $processedAtExpr = $this->hasWebhookColumn('odoo_webhooks_log', 'processed_at') ? 'processed_at' : 'NOW()';

        $partnerId = (string) ((int) $odooPartnerId);
        $customerCode = trim((string) $odooCustomerCode);
        if ($customerCode === '') {
            $customerCode = null;
        }
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
            WHERE (
                    status IS NULL
                    OR LOWER(status) IN ('success', 'done', 'ok')
                  )
              AND (
                  line_user_id = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.code')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.customer_code')) = ?
              )
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
        $stmt->execute([$lineUserId, $lineUserId, $partnerId, $partnerId, $partnerId, $customerCode, $customerCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) return null;
        
        return [
            'credit_limit' => $row['credit_limit'] ? (float) $row['credit_limit'] : null,
            'total_due' => $row['total_due'] ? (float) $row['total_due'] : null,
            'overdue_amount' => $row['overdue_amount'] ? (float) $row['overdue_amount'] : null
        ];
    }

    /**
     * Get invoices from webhook logs
     */
    private function getInvoicesFromWebhook($lineUserId, $odooPartnerId, $limit = 10, $odooCustomerCode = '')
    {
        if (!$this->tableExists('odoo_webhooks_log')) {
            return null;
        }

        $processedAtExpr = $this->hasWebhookColumn('odoo_webhooks_log', 'processed_at') ? 'processed_at' : 'NOW()';
        $partnerId = (string) ((int) $odooPartnerId);
        $customerCode = trim((string) $odooCustomerCode);
        if ($customerCode === '') {
            $customerCode = null;
        }

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
            WHERE (
                    status IS NULL
                    OR LOWER(status) IN ('success', 'done', 'ok')
                  )
              AND (
                  line_user_id = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.line_user_id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.ref')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.partner_id')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.code')) = ? OR
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.customer.customer_code')) = ?
              )
              AND (
                  JSON_EXTRACT(payload, '$.invoice_number') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.invoice.name') IS NOT NULL
                  OR JSON_EXTRACT(payload, '$.invoice.number') IS NOT NULL
                  OR LOWER(COALESCE(event_type, '')) LIKE '%invoice%'
              )
            ORDER BY processed_at DESC
            LIMIT ?
        ");
        $stmt->execute([$lineUserId, $lineUserId, $partnerId, $partnerId, $partnerId, $customerCode, $customerCode, (int) $limit]);
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
                    $stats[$name] = [
                        'product_name' => $name,
                        'qty' => 0,
                        'amount' => 0
                    ];
                }

                $stats[$name]['qty'] += $qty;
                $stats[$name]['amount'] += $amount;
            }
        }

        if (!empty($stats)) {
            usort($stats, function ($a, $b) {
                if ($a['amount'] === $b['amount']) {
                    return $b['qty'] <=> $a['qty'];
                }
                return $b['amount'] <=> $a['amount'];
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
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function hasWebhookColumn($table, $column)
    {
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
            return ((int) $stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}
