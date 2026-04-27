<?php
/**
 * RFMCalculator — core RFM segmentation logic for CNY Wholesale churn tracker.
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §3, §6.1
 *
 * Pure functions, no I/O. Repository layer feeds order-date arrays in;
 * cron persists outputs. Coverage target: 100% (core business logic).
 */

declare(strict_types=1);

namespace Classes\CRM;

final class RFMCalculator
{
    // Segment boundaries (spec §3) — inclusive lower, exclusive upper
    private const T_WATCHLIST = 1.0;
    private const T_AT_RISK   = 1.5;
    private const T_LOST      = 2.0;
    private const T_CHURNED   = 3.0;

    // Hysteresis buffer — once segment downgrades, must drop this much below
    // the threshold before we let it upgrade again (prevents flap).
    private const HYSTERESIS_BUFFER = 0.2;

    // Seasonal detection — gaps with stddev > 50% of mean are treated as seasonal.
    private const SEASONAL_CV_THRESHOLD = 0.5;

    // Outlier exclusion in cycle calc — drop gaps farther than 3σ from median.
    private const OUTLIER_SIGMA = 3.0;

    // Segment rank for hysteresis comparison (worse → higher rank).
    private const SEGMENT_RANK = [
        'Champion'    => 0,
        'Watchlist'   => 1,
        'At-Risk'     => 2,
        'Lost'        => 3,
        'Hibernating' => 4,
        'Churned'     => 4,
    ];

    /**
     * Compute average order cycle from a list of order dates.
     *
     * Rules (spec §3 E1–E6):
     *   - 0 orders   → null, fallback
     *   - 1 order    → null, fallback
     *   - 2 orders   → single gap, low confidence
     *   - ≥3 orders  → trimmed median, high confidence (after merging same-day)
     *
     * @param string[] $orderDates ISO `Y-m-d` strings (any order)
     */
    public function computeAvgCycle(array $orderDates): CycleResult
    {
        // Defensive: drop nulls/empties (production data may carry NULL date_order rows).
        $clean = [];
        foreach ($orderDates as $d) {
            if (is_string($d) && $d !== '') {
                $clean[] = $d;
            }
        }

        // Merge same-day orders (E5) and sort ascending.
        $unique = array_values(array_unique(array_map(
            static fn (string $d): string => substr($d, 0, 10),
            $clean
        )));
        sort($unique);

        $n = count($unique);
        if ($n < 2) {
            return new CycleResult(null, 'fallback');
        }

        // Compute consecutive gaps in days.
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = $this->daysBetween($unique[$i - 1], $unique[$i]);
        }

        if ($n === 2) {
            return new CycleResult((float) $gaps[0], 'low');
        }

        // ≥3 orders: drop outliers > 3σ from median, then median again.
        $trimmed = $this->trimOutliers($gaps);
        $median  = $this->median($trimmed);

        return new CycleResult($median, 'high');
    }

    /**
     * Decide segment for a customer given recency ratio and high-value flag.
     *
     * Hysteresis: if the customer was already in a worse segment, require
     * the ratio to drop HYSTERESIS_BUFFER below the threshold of that segment
     * before promoting (prevents segment flap).
     */
    public function computeSegment(
        float $ratio,
        bool $isHighValue,
        ?string $previousSegment
    ): string {
        $natural = $this->naturalSegment($ratio, $isHighValue);

        if ($previousSegment === null || !isset(self::SEGMENT_RANK[$previousSegment])) {
            return $natural;
        }

        $prevRank    = self::SEGMENT_RANK[$previousSegment];
        $naturalRank = self::SEGMENT_RANK[$natural];

        // If natural is same/worse, no hysteresis needed.
        if ($prevRank <= $naturalRank) {
            return $natural;
        }

        // Customer was worse; check whether ratio has dropped enough to revert.
        $downgradeFloor = $this->downgradeThresholdFor($previousSegment);
        if ($downgradeFloor === null) {
            return $natural;
        }

        // Stay in previous segment until ratio < (downgrade threshold − buffer).
        if ($ratio >= $downgradeFloor - self::HYSTERESIS_BUFFER) {
            return $previousSegment;
        }

        return $natural;
    }

    /**
     * High variance in inter-order gaps suggests seasonal buying.
     * Coefficient of variation = stddev / mean.
     *
     * @param array<int|float> $gaps
     */
    public function isSeasonal(array $gaps): bool
    {
        $count = count($gaps);
        if ($count < 2) {
            return false;
        }

        $mean = array_sum($gaps) / $count;
        if ($mean <= 0.0) {
            return false;
        }

        $variance = 0.0;
        foreach ($gaps as $g) {
            $variance += (((float) $g) - $mean) ** 2;
        }
        $stddev = sqrt($variance / $count);

        return ($stddev / $mean) > self::SEASONAL_CV_THRESHOLD;
    }

    /**
     * High-value flag: LTV must reach the configured threshold.
     */
    public function computeHighValue(float $ltv, float $threshold): bool
    {
        return $ltv >= $threshold;
    }

    // ───────────────────────── private helpers ─────────────────────────

    private function naturalSegment(float $ratio, bool $isHighValue): string
    {
        if ($ratio < self::T_WATCHLIST) {
            return 'Champion';
        }
        if ($ratio < self::T_AT_RISK) {
            return 'Watchlist';
        }
        if ($ratio < self::T_LOST) {
            return 'At-Risk';
        }
        if ($ratio < self::T_CHURNED) {
            return 'Lost';
        }
        return $isHighValue ? 'Churned' : 'Hibernating';
    }

    /**
     * Lower threshold of a segment — used so hysteresis knows where to release back.
     * Returns null for segments that never downgrade (Champion, Watchlist).
     */
    private function downgradeThresholdFor(string $segment): ?float
    {
        return match ($segment) {
            'At-Risk'                => self::T_AT_RISK,   // released when ratio < 1.5 − 0.2 = 1.3
            'Lost'                   => self::T_LOST,      // released when ratio < 2.0 − 0.2 = 1.8
            'Churned', 'Hibernating' => self::T_CHURNED,   // released when ratio < 3.0 − 0.2 = 2.8
            default                  => null,
        };
    }

    private function daysBetween(string $earlier, string $later): int
    {
        $a = new \DateTimeImmutable($earlier);
        $b = new \DateTimeImmutable($later);
        return (int) $a->diff($b)->days;
    }

    /** @param array<int|float> $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = (int) ($n / 2);
        if ($n % 2 === 1) {
            return (float) $values[$mid];
        }
        return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
    }

    /**
     * Drop values farther than OUTLIER_SIGMA stddevs from the median.
     * If trimming would leave fewer than 2 values, keep the original list.
     *
     * @param array<int|float> $values
     * @return array<int|float>
     */
    private function trimOutliers(array $values): array
    {
        $count = count($values);
        if ($count < 4) {
            return $values; // not enough data to safely trim
        }

        $median = $this->median($values);
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += (((float) $v) - $median) ** 2;
        }
        $stddev = sqrt($variance / $count);
        if ($stddev <= 0.0) {
            return $values;
        }

        $kept = [];
        foreach ($values as $v) {
            if (abs(((float) $v) - $median) <= self::OUTLIER_SIGMA * $stddev) {
                $kept[] = $v;
            }
        }

        return count($kept) >= 2 ? $kept : $values;
    }
}
