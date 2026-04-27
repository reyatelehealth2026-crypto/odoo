<?php
/**
 * RFMRepository — database adapter for the CNY Wholesale churn tracker.
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.2, §13
 *
 * All reads from `odoo_orders` (per CLAUDE.md: never call Odoo API directly).
 * All writes to `customer_rfm_profile` and `customer_segment_history`.
 *
 * State allowlist from §13.2 spike findings:
 *   delivered, to_delivery, sale, done, completed, validated,
 *   packed, picked, picking, picker_assign, packing
 *
 * Customer key is `odoo_partner_id` (INT UNSIGNED) matching:
 *   odoo_orders.partner_id, odoo_customer_projection.odoo_partner_id
 */

declare(strict_types=1);

namespace Classes\CRM;

use PDO;

class RFMRepository
{
    /** States that represent real completed/in-progress orders (§13.2). */
    protected const ORDER_STATES = [
        'delivered',
        'to_delivery',
        'sale',
        'done',
        'completed',
        'validated',
        'packed',
        'picked',
        'picking',
        'picker_assign',
        'packing',
    ];

    public function __construct(protected readonly PDO $pdo)
    {
    }

    /**
     * Load order dates (Y-m-d) for a partner, filtered to valid states,
     * ordered ascending. Returns empty array when no eligible orders exist.
     *
     * @return string[] ISO Y-m-d strings, ascending order
     */
    public function loadOrderDates(int $partnerId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ORDER_STATES), '?'));
        $sql = "
            SELECT DATE_FORMAT(date_order, '%Y-%m-%d') AS order_date
            FROM   odoo_orders
            WHERE  partner_id = ?
              AND  state IN ({$placeholders})
            ORDER  BY date_order ASC
        ";

        $params = array_merge([$partnerId], self::ORDER_STATES);
        $stmt   = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'order_date');
    }

    /**
     * Return partner_ids that have >=3 eligible orders and a date span of >=30 days.
     * This is the "addressable cohort" per §13.3 spike findings (415 partners).
     *
     * @return int[]
     */
    public function loadEligiblePartnerIds(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ORDER_STATES), '?'));
        $sql = "
            SELECT   partner_id
            FROM     odoo_orders
            WHERE    state IN ({$placeholders})
              AND    partner_id IS NOT NULL
            GROUP BY partner_id
            HAVING   COUNT(*) >= 3
              AND    DATEDIFF(MAX(date_order), MIN(date_order)) >= 30
            ORDER BY partner_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(self::ORDER_STATES);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'partner_id'));
    }

    /**
     * Sum amount_total for all eligible orders by this partner.
     * Used for Lifetime Value (LTV) calculation.
     */
    public function loadLifetimeValue(int $partnerId): float
    {
        $placeholders = implode(',', array_fill(0, count(self::ORDER_STATES), '?'));
        $sql = "
            SELECT COALESCE(SUM(amount_total), 0.00) AS ltv
            FROM   odoo_orders
            WHERE  partner_id = ?
              AND  state IN ({$placeholders})
        ";

        $params = array_merge([$partnerId], self::ORDER_STATES);
        $stmt   = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (float) ($row['ltv'] ?? 0.0);
    }

    /**
     * Upsert a row in `customer_rfm_profile`.
     *
     * $fields must be a flat key-value map of column names to values.
     * `odoo_partner_id` must be included in $fields.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE so re-running the cron is idempotent.
     *
     * @param array<string, mixed> $fields
     */
    public function upsertProfile(int $partnerId, array $fields): void
    {
        // Ensure the PK is always part of the payload.
        $fields['odoo_partner_id'] = $partnerId;

        $columns      = array_keys($fields);
        $placeholders = array_fill(0, count($columns), '?');
        $values       = array_values($fields);

        // Build SET clause for the ON DUPLICATE KEY UPDATE portion.
        // Exclude the PK from the update list.
        $updateParts = [];
        foreach ($columns as $col) {
            if ($col !== 'odoo_partner_id') {
                $updateParts[] = "`{$col}` = VALUES(`{$col}`)";
            }
        }

        $colList    = '`' . implode('`, `', $columns) . '`';
        $phList     = implode(', ', $placeholders);
        $updateList = implode(', ', $updateParts);

        $sql = "
            INSERT INTO customer_rfm_profile ({$colList})
            VALUES ({$phList})
            ON DUPLICATE KEY UPDATE {$updateList}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * Append one row to the append-only `customer_segment_history` table.
     * Called when a segment transition is detected.
     */
    public function appendSegmentHistory(
        int $partnerId,
        ?string $from,
        string $to,
        ?float $ratio
    ): void {
        $sql = "
            INSERT INTO customer_segment_history
                (odoo_partner_id, from_segment, to_segment, recency_ratio, changed_at)
            VALUES
                (?, ?, ?, ?, NOW())
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$partnerId, $from, $to, $ratio]);
    }

    /**
     * Determine the high-value LTV threshold (THB).
     *
     * Algorithm (per spec §13.4 and §12.2):
     *   - If churn_settings.high_value_use_top_percent = 1:
     *       compute the Nth-percentile cutoff live from eligible partners' LTV.
     *   - Otherwise use churn_settings.high_value_threshold_thb (fixed).
     *   - Falls back to 100,000 THB if churn_settings row is missing.
     */
    public function loadHighValueThreshold(): float
    {
        $settings = $this->fetchChurnSettings();

        $useTopPercent  = (bool)  ($settings['high_value_use_top_percent'] ?? 1);
        $fixedThreshold = (float) ($settings['high_value_threshold_thb']   ?? 100000.0);
        $topPercent     = (int)   ($settings['high_value_top_percent']      ?? 20);

        if (!$useTopPercent) {
            return $fixedThreshold;
        }

        $cutoff = $this->computeTopPercentileLtv($topPercent);
        return $cutoff ?? $fixedThreshold;
    }

    // ───────────────────────── private helpers ─────────────────────────

    /**
     * Fetch the single row from churn_settings.
     * Returns empty array on failure (graceful degradation — table may not
     * exist in test environments using SQLite fixtures).
     *
     * @return array<string, mixed>
     */
    private function fetchChurnSettings(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT * FROM churn_settings WHERE id = 1 LIMIT 1'
            );
            if ($stmt === false) {
                return [];
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Compute the value at the (100-N)th percentile of LTV across eligible partners,
     * which serves as the lower bound of the top-N% tier.
     * Returns null when no eligible data exists.
     */
    private function computeTopPercentileLtv(int $topPercent): ?float
    {
        $placeholders = implode(',', array_fill(0, count(self::ORDER_STATES), '?'));

        // Aggregate LTV per eligible partner, sorted ascending.
        // Percentile computed in PHP to avoid MySQL/MariaDB version-specific
        // PERCENTILE_CONT syntax incompatibilities.
        $sql = "
            SELECT   SUM(amount_total) AS ltv
            FROM     odoo_orders
            WHERE    state IN ({$placeholders})
              AND    partner_id IS NOT NULL
            GROUP BY partner_id
            HAVING   COUNT(*) >= 3
              AND    DATEDIFF(MAX(date_order), MIN(date_order)) >= 30
            ORDER BY ltv ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(self::ORDER_STATES);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($rows)) {
            return null;
        }

        $ltvList = array_map('floatval', $rows);
        sort($ltvList);

        $count = count($ltvList);
        // Cutoff index: last entry of the bottom (100-topPercent)% slice.
        $cutoffIndex = (int) ceil($count * (100 - $topPercent) / 100) - 1;
        $cutoffIndex = max(0, min($cutoffIndex, $count - 1));

        return $ltvList[$cutoffIndex];
    }
}
