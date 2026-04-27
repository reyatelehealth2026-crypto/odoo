<?php
/**
 * Unit Test: RFM Calculator (RED phase)
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §3, §6.1
 * Phase: 0 (test stubs — implementation arrives in Phase 1)
 *
 * Coverage target: 100% (core business logic)
 *
 * NOTE: These tests are intentionally RED until Classes\CRM\RFMCalculator
 *       is implemented in Phase 1. Run with `composer test` to verify
 *       expected failures (class-not-found / method-not-found).
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use Classes\CRM\RFMCalculator;

final class RFMCalculatorTest extends TestCase
{
    private RFMCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RFMCalculator();
    }

    // ─────────────────────────────────────────────────────────────
    // Avg Order Cycle
    // ─────────────────────────────────────────────────────────────

    /** E4: ≥3 orders → trimmed median, confidence=high */
    public function testComputeAvgCycleWithThreeRegularOrders(): void
    {
        $dates = ['2026-01-01', '2026-01-15', '2026-01-29']; // gaps: 14, 14
        $result = $this->calc->computeAvgCycle($dates);

        $this->assertSame(14.0, $result->cycleDays);
        $this->assertSame('high', $result->confidence);
    }

    /** E3: 2 orders → single gap, confidence=low */
    public function testComputeAvgCycleWithTwoOrders(): void
    {
        $dates = ['2026-01-01', '2026-01-21']; // gap: 20
        $result = $this->calc->computeAvgCycle($dates);

        $this->assertSame(20.0, $result->cycleDays);
        $this->assertSame('low', $result->confidence);
    }

    /** E2: 1 order → not in RFM scope */
    public function testComputeAvgCycleWithSingleOrderReturnsNull(): void
    {
        $result = $this->calc->computeAvgCycle(['2026-01-01']);
        $this->assertNull($result->cycleDays);
        $this->assertSame('fallback', $result->confidence);
    }

    /** E1: 0 orders → null */
    public function testComputeAvgCycleWithNoOrdersReturnsNull(): void
    {
        $result = $this->calc->computeAvgCycle([]);
        $this->assertNull($result->cycleDays);
        $this->assertSame('fallback', $result->confidence);
    }

    /** E5: same-day orders merged before gap calculation */
    public function testSameDayOrdersAreMergedBeforeCycleCalculation(): void
    {
        $dates = ['2026-01-01', '2026-01-01', '2026-01-15', '2026-01-29'];
        $result = $this->calc->computeAvgCycle($dates);
        $this->assertSame(14.0, $result->cycleDays);
        $this->assertSame('high', $result->confidence);
    }

    /** E6: outlier > 3σ excluded from median */
    public function testOutlierGapExcludedFromCycle(): void
    {
        // gaps: 14, 14, 14, 14, 365 — outlier should be trimmed
        $dates = ['2026-01-01', '2026-01-15', '2026-01-29', '2026-02-12', '2026-02-26', '2027-02-26'];
        $result = $this->calc->computeAvgCycle($dates);
        $this->assertEqualsWithDelta(14.0, $result->cycleDays, 0.5);
    }

    /** E8: high variance → seasonal flag */
    public function testIsSeasonalDetectedFromHighVariance(): void
    {
        // variance > 50% of mean
        $gaps = [10, 60, 12, 55, 14];
        $this->assertTrue($this->calc->isSeasonal($gaps));
    }

    public function testIsSeasonalFalseWhenGapsConsistent(): void
    {
        $gaps = [14, 15, 13, 14, 16];
        $this->assertFalse($this->calc->isSeasonal($gaps));
    }

    // ─────────────────────────────────────────────────────────────
    // Segment Boundaries (§3 of spec)
    // ─────────────────────────────────────────────────────────────

    public function testSegmentChampionWhenRatioBelow1(): void
    {
        $this->assertSame('Champion', $this->calc->computeSegment(0.5, false, null));
        $this->assertSame('Champion', $this->calc->computeSegment(0.99, false, null));
    }

    public function testSegmentWatchlistAtRatio1to1_5(): void
    {
        $this->assertSame('Watchlist', $this->calc->computeSegment(1.0, false, null));
        $this->assertSame('Watchlist', $this->calc->computeSegment(1.49, false, null));
    }

    public function testSegmentAtRiskAt1_5to2(): void
    {
        $this->assertSame('At-Risk', $this->calc->computeSegment(1.5, false, null));
        $this->assertSame('At-Risk', $this->calc->computeSegment(1.99, false, null));
    }

    public function testSegmentLostAt2to3(): void
    {
        $this->assertSame('Lost', $this->calc->computeSegment(2.0, false, null));
        $this->assertSame('Lost', $this->calc->computeSegment(2.99, false, null));
    }

    public function testSegmentChurnedWhenHighValueAndRatioOver3(): void
    {
        $this->assertSame('Churned', $this->calc->computeSegment(3.0, true, null));
        $this->assertSame('Churned', $this->calc->computeSegment(5.0, true, null));
    }

    public function testSegmentHibernatingWhenNotHighValueAndRatioOver3(): void
    {
        $this->assertSame('Hibernating', $this->calc->computeSegment(3.0, false, null));
        $this->assertSame('Hibernating', $this->calc->computeSegment(10.0, false, null));
    }

    // ─────────────────────────────────────────────────────────────
    // Hysteresis (§3 spec — buffer 0.2 below downgrade threshold)
    // ─────────────────────────────────────────────────────────────

    /** P4: At-Risk customer at ratio 1.4 stays At-Risk (not yet < 1.3) */
    public function testHysteresisKeepsAtRiskWhenRatioStillAboveBuffer(): void
    {
        $this->assertSame('At-Risk', $this->calc->computeSegment(1.4, false, 'At-Risk'));
        $this->assertSame('At-Risk', $this->calc->computeSegment(1.31, false, 'At-Risk'));
    }

    public function testHysteresisRevertsAtRiskToWatchlistBelowBuffer(): void
    {
        $this->assertSame('Watchlist', $this->calc->computeSegment(1.29, false, 'At-Risk'));
    }

    public function testHysteresisRevertsLostToAtRiskBelowBuffer(): void
    {
        // Lost downgrade threshold = 2.0, buffer = 0.2 → revert at < 1.8
        $this->assertSame('Lost', $this->calc->computeSegment(1.85, false, 'Lost'));
        $this->assertSame('At-Risk', $this->calc->computeSegment(1.79, false, 'Lost'));
    }

    // ─────────────────────────────────────────────────────────────
    // High-Value flag
    // ─────────────────────────────────────────────────────────────

    public function testHighValueAboveThreshold(): void
    {
        $this->assertTrue($this->calc->computeHighValue(150000.0, 100000.0));
    }

    public function testHighValueExactlyAtThreshold(): void
    {
        $this->assertTrue($this->calc->computeHighValue(100000.0, 100000.0));
    }

    public function testHighValueBelowThreshold(): void
    {
        $this->assertFalse($this->calc->computeHighValue(50000.0, 100000.0));
    }
}
