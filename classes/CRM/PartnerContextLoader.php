<?php
/**
 * PartnerContextLoader — assembles customer context for Gemini talking points.
 *
 * Called by:
 *   - classes/CRM/TalkingPointsService.php  (getForPartner, ~line 60)
 *   - api/churn-talking-points.php           (instantiation + injection)
 *
 * Reads:
 *   - customer_rfm_profile        (RFM segment, LTV, recency ratio)
 *   - odoo_customer_projection    (latest_order_at, salesperson_name, spend_total)
 *   - odoo_orders                 (last 5 orders summary)
 *   - odoo_customer_product_stats (top-5 SKUs 90d)
 *     Fallback: odoo_order_lines JOIN odoo_orders per spec §13.3
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §13.3
 *
 * No writes. No I/O side-effects beyond SELECT queries.
 */

declare(strict_types=1);

namespace Classes\CRM;

class PartnerContextLoader
{
    private \PDO $db;

    /** Valid order states included in RFM analysis (spec §13.2) */
    private const VALID_STATES = [
        'delivered', 'to_delivery', 'sale', 'done', 'completed',
        'validated', 'packed', 'picked', 'picking', 'picker_assign', 'packing',
    ];

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Load full customer context for a given Odoo partner ID.
     *
     * Returns a structured array:
     * [
     *   'partner_id'           => int,
     *   'segment'              => string|null,
     *   'previous_segment'     => string|null,
     *   'recency_ratio'        => float|null,
     *   'lifetime_value'       => float,
     *   'is_high_value'        => bool,
     *   'avg_order_cycle_days' => float|null,
     *   'last_order_date'      => string|null,   // Y-m-d
     *   'customer_type'        => string,
     *   'total_orders'         => int,
     *   'salesperson_name'     => string|null,
     *   'latest_order_at'      => string|null,   // Y-m-d H:i:s
     *   'spend_total'          => float,
     *   'top_skus'             => array,         // up to 5 items, 90d window
     *   'recent_orders'        => array,         // last 5 orders
     * ]
     */
    public function loadContext(int $partnerId): array
    {
        $rfm          = $this->loadRfmProfile($partnerId);
        $projection   = $this->loadProjection($partnerId);
        $recentOrders = $this->loadRecentOrders($partnerId, 5);
        $topSkus      = $this->loadTopSkus($partnerId, 90, 5);

        return [
            'partner_id'           => $partnerId,
            'segment'              => $rfm['current_segment'] ?? null,
            'previous_segment'     => $rfm['previous_segment'] ?? null,
            'recency_ratio'        => (isset($rfm['recency_ratio']) && $rfm['recency_ratio'] !== null)
                ? (float) $rfm['recency_ratio']
                : null,
            'lifetime_value'       => (float) ($rfm['lifetime_value'] ?? 0.0),
            'is_high_value'        => (bool) ($rfm['is_high_value'] ?? false),
            'avg_order_cycle_days' => (isset($rfm['avg_order_cycle_days']) && $rfm['avg_order_cycle_days'] !== null)
                ? (float) $rfm['avg_order_cycle_days']
                : null,
            'last_order_date'      => $rfm['last_order_date'] ?? null,
            'customer_type'        => (string) ($rfm['customer_type'] ?? 'other'),
            'total_orders'         => (int) ($rfm['total_orders'] ?? 0),
            'salesperson_name'     => $projection['salesperson_name'] ?? null,
            'latest_order_at'      => $projection['latest_order_at'] ?? null,
            'spend_total'          => (float) ($projection['spend_total'] ?? 0.0),
            'top_skus'             => $topSkus,
            'recent_orders'        => $recentOrders,
        ];
    }

    // ────────────────────── private helpers ──────────────────────

    /**
     * Load RFM profile row. Returns [] if not yet computed.
     *
     * @return array<string, mixed>
     */
    private function loadRfmProfile(int $partnerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT odoo_partner_id, customer_type, total_orders,
                    avg_order_cycle_days, cycle_confidence, is_seasonal,
                    last_order_date, lifetime_value, recency_ratio,
                    is_high_value, current_segment, previous_segment
             FROM customer_rfm_profile
             WHERE odoo_partner_id = ?
             LIMIT 1'
        );
        $stmt->execute([$partnerId]);
        return (array) ($stmt->fetch(\PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Load projection row (latest_order_at, salesperson_name, spend_total).
     * Falls back to odoo_customers_cache if projection row is missing.
     *
     * @return array<string, mixed>
     */
    private function loadProjection(int $partnerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT latest_order_at, salesperson_name, spend_total
             FROM odoo_customer_projection
             WHERE odoo_partner_id = ?
             LIMIT 1'
        );
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }

        // Fallback: odoo_customers_cache for at least latest_order_at
        try {
            $stmt2 = $this->db->prepare(
                'SELECT latest_order_at,
                        NULL AS salesperson_name,
                        0    AS spend_total
                 FROM odoo_customers_cache
                 WHERE odoo_partner_id = ?
                 LIMIT 1'
            );
            $stmt2->execute([$partnerId]);
            $row2 = $stmt2->fetch(\PDO::FETCH_ASSOC);
            return $row2 !== false ? $row2 : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Load the last N completed orders for the partner.
     *
     * @return array<int, array{order_name: string, date_order: string, amount_total: float, state: string}>
     */
    private function loadRecentOrders(int $partnerId, int $limit): array
    {
        $placeholders = implode(',', array_fill(0, count(self::VALID_STATES), '?'));

        $stmt = $this->db->prepare(
            "SELECT order_name, date_order, amount_total, state
             FROM odoo_orders
             WHERE partner_id = ?
               AND state IN ({$placeholders})
             ORDER BY date_order DESC
             LIMIT ?"
        );

        $params = array_merge([$partnerId], self::VALID_STATES, [$limit]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            return [
                'order_name'   => (string) ($r['order_name'] ?? ''),
                'date_order'   => (string) ($r['date_order'] ?? ''),
                'amount_total' => (float) ($r['amount_total'] ?? 0.0),
                'state'        => (string) ($r['state'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * Load top-N SKUs by order appearances in the last $days days.
     *
     * Primary:  odoo_customer_product_stats (pre-aggregated, fast)
     * Fallback: odoo_order_lines JOIN odoo_orders (spec §13.3 / §19.8)
     * Final:    [] if neither source has data
     *
     * @return array<int, array{product_name: string, order_appearances: int, qty_total: float}>
     */
    private function loadTopSkus(int $partnerId, int $days, int $limit): array
    {
        // Try pre-aggregated stats table first
        try {
            $stmt = $this->db->prepare(
                'SELECT product_name,
                        1        AS order_appearances,
                        qty_90d  AS qty_total
                 FROM odoo_customer_product_stats
                 WHERE odoo_partner_id = ?
                   AND qty_90d > 0
                 ORDER BY qty_90d DESC
                 LIMIT ?'
            );
            $stmt->execute([$partnerId, $limit]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                return $this->normaliseSkuRows($rows);
            }
        } catch (\Exception $e) {
            // Table missing or empty — fall through to line-level query
        }

        // Fallback: odoo_order_lines per spec §13.3
        return $this->loadTopSkusFromOrderLines($partnerId, $days, $limit);
    }

    /**
     * Spec §13.3 fallback: aggregate product_name from odoo_order_lines
     * joined to odoo_orders, restricted to the last $days days.
     *
     * Groups by product_name, counts distinct order_id appearances,
     * sums product_qty.
     *
     * @return array<int, array{product_name: string, order_appearances: int, qty_total: float}>
     */
    private function loadTopSkusFromOrderLines(int $partnerId, int $days, int $limit): array
    {
        try {
            $placeholders = implode(',', array_fill(0, count(self::VALID_STATES), '?'));
            // Compute cutoff in PHP so the query works on both MySQL and SQLite
            $cutoff = date('Y-m-d', strtotime("-{$days} days"));

            $stmt = $this->db->prepare(
                "SELECT
                     ol.product_name,
                     COUNT(DISTINCT ol.order_id) AS order_appearances,
                     SUM(ol.product_qty)          AS qty_total
                 FROM odoo_order_lines ol
                 INNER JOIN odoo_orders o ON o.order_id = ol.order_id
                 WHERE o.partner_id = ?
                   AND o.state IN ({$placeholders})
                   AND o.date_order >= ?
                   AND ol.product_name IS NOT NULL
                   AND ol.product_name <> ''
                 GROUP BY ol.product_name
                 ORDER BY order_appearances DESC, qty_total DESC
                 LIMIT ?"
            );

            $params = array_merge([$partnerId], self::VALID_STATES, [$cutoff, $limit]);
            $stmt->execute($params);
            return $this->normaliseSkuRows($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            // Table does not exist or query fails — return empty gracefully
            return [];
        }
    }

    /**
     * Cast and normalise raw SKU rows to typed structs.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{product_name: string, order_appearances: int, qty_total: float}>
     */
    private function normaliseSkuRows(array $rows): array
    {
        return array_map(static function (array $r): array {
            return [
                'product_name'      => (string) ($r['product_name'] ?? ''),
                'order_appearances' => (int) ($r['order_appearances'] ?? 0),
                'qty_total'         => (float) ($r['qty_total'] ?? 0.0),
            ];
        }, $rows);
    }
}
