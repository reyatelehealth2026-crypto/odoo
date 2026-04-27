<?php
/**
 * RFMService — orchestrator for the CNY Wholesale churn tracker.
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.1, §6.2, §6.3
 *
 * Wires RFMCalculator (pure logic) + RFMRepository (I/O adapter).
 * No direct DB access here — all DB calls go through RFMRepository,
 * except two lightweight lookups (soft_launch flag + existing segment)
 * that need the PDO instance for the hysteresis read.
 *
 * Soft-launch mode (churn_settings.soft_launch = 1):
 *   Segments are computed and written to customer_rfm_profile, but
 *   segment_changed_at is NOT updated and no history row is appended.
 *   This lets the dashboard read profiles without triggering downstream
 *   auto-actions during the first 7-day review window.
 */

declare(strict_types=1);

namespace Classes\CRM;

use PDO;

final class RFMService
{
    public function __construct(
        private readonly PDO           $pdo,
        private readonly RFMCalculator $calculator,
        private readonly RFMRepository $repository
    ) {
    }

    /**
     * Recompute RFM segments for every eligible partner and persist results.
     *
     * Eligible = >=3 orders with >=30-day span (per §13.3 spike).
     * Idempotent: re-running produces the same output for the same input.
     *
     * @return array{processed: int, segment_changes: int}
     */
    public function recomputeAll(): array
    {
        $softLaunch  = $this->isSoftLaunch();
        $hvThreshold = $this->repository->loadHighValueThreshold();
        $eligibleIds = $this->repository->loadEligiblePartnerIds();

        $processed      = 0;
        $segmentChanges = 0;

        foreach ($eligibleIds as $partnerId) {
            $changed = $this->recomputeOne($partnerId, $hvThreshold, $softLaunch);
            $processed++;
            if ($changed) {
                $segmentChanges++;
            }
        }

        return [
            'processed'       => $processed,
            'segment_changes' => $segmentChanges,
        ];
    }

    // ───────────────────────── private helpers ─────────────────────────

    /**
     * Compute and persist one partner's RFM profile.
     * Returns true when the segment changed from the previous run.
     */
    private function recomputeOne(
        int $partnerId,
        float $hvThreshold,
        bool $softLaunch
    ): bool {
        $orderDates  = $this->repository->loadOrderDates($partnerId);
        $cycleResult = $this->calculator->computeAvgCycle($orderDates);
        $ltv         = $this->repository->loadLifetimeValue($partnerId);
        $isHighValue = $this->calculator->computeHighValue($ltv, $hvThreshold);

        // Determine last order date for recency calculation.
        $lastOrderDate = empty($orderDates) ? null : end($orderDates);
        $daysSinceLast = $lastOrderDate !== null
            ? $this->daysSince($lastOrderDate)
            : null;

        // Compute recency ratio (null when cycle is unknown or zero).
        $recencyRatio = null;
        if (
            $cycleResult->cycleDays !== null
            && $cycleResult->cycleDays > 0.0
            && $daysSinceLast !== null
        ) {
            $recencyRatio = $daysSinceLast / $cycleResult->cycleDays;
        }

        // Detect seasonal pattern from consecutive unique-day gaps.
        $isSeasonal = false;
        if (count($orderDates) >= 2) {
            $uniqueDates = array_values(array_unique($orderDates));
            sort($uniqueDates);
            $gaps = [];
            for ($i = 1, $n = count($uniqueDates); $i < $n; $i++) {
                $gaps[] = $this->daysBetween($uniqueDates[$i - 1], $uniqueDates[$i]);
            }
            $isSeasonal = $this->calculator->isSeasonal($gaps);
        }

        // Fetch the existing profile row so we can apply hysteresis.
        $existing        = $this->loadExistingProfile($partnerId);
        $previousSegment = $existing['current_segment'] ?? null;

        // Determine new segment (null when recency ratio is unavailable).
        $newSegment = null;
        if ($recencyRatio !== null) {
            $newSegment = $this->calculator->computeSegment(
                $recencyRatio,
                $isHighValue,
                $previousSegment
            );
        }

        $segmentChanged = ($newSegment !== null) && ($newSegment !== $previousSegment);

        // Build profile payload — immutable construction, no state mutation.
        $now    = date('Y-m-d H:i:s');
        $fields = [
            'total_orders'         => count($orderDates),
            'avg_order_cycle_days' => $cycleResult->cycleDays,
            'cycle_confidence'     => $cycleResult->confidence,
            'is_seasonal'          => $isSeasonal ? 1 : 0,
            'last_order_date'      => $lastOrderDate,
            'lifetime_value'       => $ltv,
            'recency_ratio'        => $recencyRatio,
            'is_high_value'        => $isHighValue ? 1 : 0,
            'current_segment'      => $newSegment,
            'previous_segment'     => $previousSegment,
            'computed_at'          => $now,
        ];

        // In soft-launch mode: do not stamp segment_changed_at or append history,
        // so no downstream auto-actions are triggered.
        if ($segmentChanged && !$softLaunch) {
            $fields['segment_changed_at'] = $now;
        }

        $this->repository->upsertProfile($partnerId, $fields);

        // Append segment history entry (skipped in soft-launch mode).
        if ($segmentChanged && !$softLaunch) {
            $this->repository->appendSegmentHistory(
                $partnerId,
                $previousSegment,
                (string) $newSegment,
                $recencyRatio
            );
        }

        return $segmentChanged;
    }

    /**
     * Fetch the stored current_segment for hysteresis.
     * Returns [] on miss or when table does not exist (test fixtures).
     *
     * @return array<string, mixed>
     */
    private function loadExistingProfile(int $partnerId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT current_segment FROM customer_rfm_profile
                 WHERE odoo_partner_id = ? LIMIT 1'
            );
            $stmt->execute([$partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Read soft_launch flag from churn_settings.
     * Defaults to true (safe: no auto-actions) when row is missing.
     */
    private function isSoftLaunch(): bool
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT soft_launch FROM churn_settings WHERE id = 1 LIMIT 1'
            );
            if ($stmt === false) {
                return true;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (bool) ($row['soft_launch'] ?? 1);
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function daysSince(string $date): int
    {
        $then = new \DateTimeImmutable($date);
        $now  = new \DateTimeImmutable('today');
        return (int) $then->diff($now)->days;
    }

    private function daysBetween(string $earlier, string $later): int
    {
        $a = new \DateTimeImmutable($earlier);
        $b = new \DateTimeImmutable($later);
        return (int) $a->diff($b)->days;
    }
}
