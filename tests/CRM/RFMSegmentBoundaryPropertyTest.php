<?php
/**
 * Property-Based Test: RFM Segment Boundary Invariants (RED phase)
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §3, §7
 * Phase: 0 (test stubs — implementation arrives in Phase 1)
 *
 * Properties verified (100 random cases each):
 *   P1: Every customer has exactly one segment (no overlap, no gap).
 *   P2: Without hysteresis (previous_segment=null), ratio↑ → segment moves
 *       monotonically toward Hibernating/Churned (never improves).
 *   P3: Champion segment ⇔ ratio < 1.0.
 *   P4: Hysteresis keeps At-Risk customer in At-Risk while ratio ∈ [1.3, 1.5).
 *   P5: High-Value flag flips Churned ↔ Hibernating only at ratio ≥ 3.0.
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use Classes\CRM\RFMCalculator;

final class RFMSegmentBoundaryPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /** All valid segment outputs */
    private const VALID_SEGMENTS = [
        'Champion', 'Watchlist', 'At-Risk', 'Lost', 'Churned', 'Hibernating'
    ];

    private RFMCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RFMCalculator();
    }

    /** P1: Output must always be one of the 6 valid segments */
    public function testPropertyP1_OutputIsAlwaysExactlyOneValidSegment(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $ratio = $this->randomRatio();
            $highValue = (bool) random_int(0, 1);
            $previous = $this->randomPreviousSegment();

            $segment = $this->calc->computeSegment($ratio, $highValue, $previous);

            $this->assertContains(
                $segment,
                self::VALID_SEGMENTS,
                "Invalid segment '$segment' for ratio=$ratio hv=" . ($highValue ? '1' : '0')
            );
        }
    }

    /** P2: Without hysteresis, segment ordering is monotonic in ratio */
    public function testPropertyP2_MonotonicSegmentProgressionWithoutHysteresis(): void
    {
        $segmentRank = [
            'Champion'    => 0,
            'Watchlist'   => 1,
            'At-Risk'     => 2,
            'Lost'        => 3,
            'Hibernating' => 4,
            'Churned'     => 4, // Churned and Hibernating sit at the same recency tier
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $ratioLow = $this->randomRatio();
            $ratioHigh = $ratioLow + (mt_rand(1, 300) / 100); // strictly greater
            $highValue = (bool) random_int(0, 1);

            $segLow  = $this->calc->computeSegment($ratioLow,  $highValue, null);
            $segHigh = $this->calc->computeSegment($ratioHigh, $highValue, null);

            $this->assertLessThanOrEqual(
                $segmentRank[$segHigh],
                $segmentRank[$segLow],
                sprintf('Non-monotonic: ratio %s -> %s but %s -> %s', $ratioLow, $segLow, $ratioHigh, $segHigh)
            );
        }
    }

    /** P3: Champion ⇔ ratio < 1.0 (when no hysteresis) */
    public function testPropertyP3_ChampionExactlyWhenRatioBelowOne(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $ratio = mt_rand(1, 999) / 1000; // (0, 1)
            $segment = $this->calc->computeSegment($ratio, false, null);
            $this->assertSame('Champion', $segment, "Ratio $ratio should be Champion");
        }
    }

    /** P4: Hysteresis — At-Risk persists while ratio ∈ [1.3, 1.5) */
    public function testPropertyP4_HysteresisKeepsAtRiskWithinBuffer(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $ratio = 1.3 + (mt_rand(0, 199) / 1000); // [1.3, 1.499]
            $segment = $this->calc->computeSegment($ratio, false, 'At-Risk');
            $this->assertSame(
                'At-Risk',
                $segment,
                "Hysteresis broken at ratio=$ratio (previous=At-Risk)"
            );
        }
    }

    /** P5: At ratio ≥ 3.0, high_value flag determines Churned vs Hibernating */
    public function testPropertyP5_HighValueDetermineChurnedVsHibernatingAtR3(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $ratio = 3.0 + (mt_rand(0, 700) / 100); // [3.0, 10.0]

            $segHigh = $this->calc->computeSegment($ratio, true,  null);
            $segLow  = $this->calc->computeSegment($ratio, false, null);

            $this->assertSame('Churned', $segHigh,    "ratio=$ratio HV=true should be Churned");
            $this->assertSame('Hibernating', $segLow, "ratio=$ratio HV=false should be Hibernating");
        }
    }

    // ─── helpers ───────────────────────────────────────────────

    private function randomRatio(): float
    {
        return mt_rand(0, 5000) / 1000; // [0.0, 5.0]
    }

    private function randomPreviousSegment(): ?string
    {
        $pool = array_merge([null], self::VALID_SEGMENTS);
        return $pool[array_rand($pool)];
    }
}
