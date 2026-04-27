<?php
/**
 * Unit Test: RFMService
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.3, §7
 *
 * Uses PHPUnit mock objects for RFMRepository so no live DB is required.
 * An SQLite :memory: PDO is passed for the two lightweight internal queries
 * that RFMService performs directly on the PDO connection:
 *   1. churn_settings.soft_launch flag
 *   2. customer_rfm_profile.current_segment (hysteresis read)
 *
 * Coverage target: 80%
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Classes\CRM\RFMService;
use Classes\CRM\RFMCalculator;
use Classes\CRM\RFMRepository;
use PDO;

final class RFMServiceTest extends TestCase
{
    private PDO $pdo;
    private RFMCalculator $calculator;

    protected function setUp(): void
    {
        // SQLite in-memory DB for the two PDO queries RFMService makes directly.
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE churn_settings (
                id          INTEGER PRIMARY KEY,
                soft_launch INTEGER NOT NULL DEFAULT 1
            )
        ");

        $this->pdo->exec("
            CREATE TABLE customer_rfm_profile (
                odoo_partner_id      INTEGER PRIMARY KEY,
                current_segment      TEXT,
                total_orders         INTEGER NOT NULL DEFAULT 0,
                avg_order_cycle_days REAL,
                cycle_confidence     TEXT    NOT NULL DEFAULT 'fallback',
                is_seasonal          INTEGER NOT NULL DEFAULT 0,
                last_order_date      TEXT,
                lifetime_value       REAL    NOT NULL DEFAULT 0,
                recency_ratio        REAL,
                is_high_value        INTEGER NOT NULL DEFAULT 0,
                previous_segment     TEXT,
                segment_changed_at   TEXT,
                computed_at          TEXT    NOT NULL
            )
        ");

        $this->calculator = new RFMCalculator();
    }

    // ─────────────────────────────────────────────────────────────
    // recomputeAll — calls calculator + persists for each partner
    // ─────────────────────────────────────────────────────────────

    public function testRecomputeAllCallsCalculatorAndPersistsForEachEligiblePartner(): void
    {
        $this->insertChurnSettings(softLaunch: 0);

        // Three synthetic dates with ~30-day gaps — gives high-confidence cycle.
        $dates = ['2026-01-27', '2026-02-27', '2026-04-26'];

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([101, 102]);
        $repo->method('loadOrderDates')->willReturn($dates);
        $repo->method('loadLifetimeValue')->willReturn(50000.0);

        // upsertProfile must be called once per partner (2 partners).
        $repo->expects($this->exactly(2))->method('upsertProfile');

        $svc    = new RFMService($this->pdo, $this->calculator, $repo);
        $result = $svc->recomputeAll();

        $this->assertSame(2, $result['processed']);
        $this->assertIsInt($result['segment_changes']);
        $this->assertGreaterThanOrEqual(0, $result['segment_changes']);
        $this->assertLessThanOrEqual(2, $result['segment_changes']);
    }

    public function testRecomputeAllReturnsZeroWhenNoEligiblePartners(): void
    {
        $this->insertChurnSettings(softLaunch: 0);

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([]);

        $repo->expects($this->never())->method('loadOrderDates');
        $repo->expects($this->never())->method('upsertProfile');

        $svc    = new RFMService($this->pdo, $this->calculator, $repo);
        $result = $svc->recomputeAll();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['segment_changes']);
    }

    // ─────────────────────────────────────────────────────────────
    // No-op for partners with <3 orders (filtered by repository)
    // ─────────────────────────────────────────────────────────────

    public function testNoOpForPartnersWithFewerThanThreeOrders(): void
    {
        // Partners with <3 orders are excluded by loadEligiblePartnerIds()
        // before RFMService is involved. Verify upsertProfile is never called.
        $this->insertChurnSettings(softLaunch: 0);

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([]);

        $repo->expects($this->never())->method('upsertProfile');
        $repo->expects($this->never())->method('appendSegmentHistory');

        $svc    = new RFMService($this->pdo, $this->calculator, $repo);
        $result = $svc->recomputeAll();

        $this->assertSame(0, $result['processed']);
    }

    // ─────────────────────────────────────────────────────────────
    // Soft-launch mode
    // ─────────────────────────────────────────────────────────────

    public function testSoftLaunchModeDoesNotAppendSegmentHistory(): void
    {
        // soft_launch = 1: history must NOT be appended even if segment changes.
        $this->insertChurnSettings(softLaunch: 1);

        $dates = ['2026-01-27', '2026-02-27', '2026-04-26'];

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([201]);
        $repo->method('loadOrderDates')->willReturn($dates);
        $repo->method('loadLifetimeValue')->willReturn(50000.0);
        $repo->method('upsertProfile'); // profile write is still allowed

        // appendSegmentHistory must never fire in soft-launch mode.
        $repo->expects($this->never())->method('appendSegmentHistory');

        $svc = new RFMService($this->pdo, $this->calculator, $repo);
        $svc->recomputeAll();
    }

    public function testSoftLaunchDefaultsToTrueWhenSettingsRowMissing(): void
    {
        // No row in churn_settings → isSoftLaunch() returns true by default.
        // appendSegmentHistory must never be called.

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([301]);
        $repo->method('loadOrderDates')->willReturn(['2026-01-27', '2026-02-27', '2026-04-26']);
        $repo->method('loadLifetimeValue')->willReturn(50000.0);
        $repo->method('upsertProfile');

        $repo->expects($this->never())->method('appendSegmentHistory');

        $svc = new RFMService($this->pdo, $this->calculator, $repo);
        $svc->recomputeAll();
    }

    // ─────────────────────────────────────────────────────────────
    // Segment history IS appended when not in soft-launch
    // ─────────────────────────────────────────────────────────────

    public function testSegmentHistoryIsAppendedOnFirstRunWhenNotSoftLaunch(): void
    {
        // soft_launch = 0 and no prior profile row → segment changes from null
        // to a real segment → appendSegmentHistory should be called once.
        $this->insertChurnSettings(softLaunch: 0);

        // Dates with large gap so ratio > 1.0 (moves out of Champion baseline).
        // partner has no prior row → previous_segment = null → any non-null new
        // segment counts as a change.
        $dates = ['2026-01-01', '2026-02-01', '2026-04-26'];

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([401]);
        $repo->method('loadOrderDates')->willReturn($dates);
        $repo->method('loadLifetimeValue')->willReturn(50000.0);
        $repo->method('upsertProfile');

        // appendSegmentHistory called exactly once for the one partner.
        $repo->expects($this->once())->method('appendSegmentHistory');

        $svc = new RFMService($this->pdo, $this->calculator, $repo);
        $svc->recomputeAll();
    }

    // ─────────────────────────────────────────────────────────────
    // Return shape contract
    // ─────────────────────────────────────────────────────────────

    public function testRecomputeAllReturnShapeIsCorrect(): void
    {
        $this->insertChurnSettings(softLaunch: 1);

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([]);

        $svc    = new RFMService($this->pdo, $this->calculator, $repo);
        $result = $svc->recomputeAll();

        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('segment_changes', $result);
        $this->assertIsInt($result['processed']);
        $this->assertIsInt($result['segment_changes']);
    }

    // ─────────────────────────────────────────────────────────────
    // Idempotency
    // ─────────────────────────────────────────────────────────────

    public function testRecomputeAllIsIdempotentWhenCalledTwice(): void
    {
        $this->insertChurnSettings(softLaunch: 0);

        $dates = ['2026-01-27', '2026-02-27', '2026-04-26'];

        /** @var RFMRepository&MockObject $repo */
        $repo = $this->createMock(RFMRepository::class);
        $repo->method('loadHighValueThreshold')->willReturn(100000.0);
        $repo->method('loadEligiblePartnerIds')->willReturn([501]);
        $repo->method('loadOrderDates')->willReturn($dates);
        $repo->method('loadLifetimeValue')->willReturn(50000.0);
        $repo->method('upsertProfile');
        $repo->method('appendSegmentHistory');

        $svc = new RFMService($this->pdo, $this->calculator, $repo);

        $result1 = $svc->recomputeAll();
        $result2 = $svc->recomputeAll();

        // Both runs must process the same number of partners.
        $this->assertSame($result1['processed'], $result2['processed']);
        $this->assertSame(1, $result1['processed']);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function insertChurnSettings(int $softLaunch): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR REPLACE INTO churn_settings (id, soft_launch) VALUES (1, ?)"
        );
        $stmt->execute([$softLaunch]);
    }
}
