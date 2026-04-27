<?php
/**
 * PartnerContextLoaderTest — integration tests for PartnerContextLoader.
 *
 * Discovered and run by PHPUnit via `composer test`.
 * Uses SQLite in-memory fixture — no live DB, no external calls.
 *
 * Test cases:
 *   L1  loadContext returns correct RFM fields from customer_rfm_profile
 *   L2  loadContext returns salesperson_name from odoo_customer_projection
 *   L3  top_skus loaded from odoo_order_lines sorted by order_appearances DESC
 *   L4  top_skus limited to 5 entries even when more rows exist
 *   L5  empty product history → top_skus is [] but struct is valid
 *   L6  recent_orders limited to last 5, ordered date DESC
 *   L7  partner with no rfm profile returns zeroed defaults (no exception)
 *   L8  NULL product_name rows excluded from top_skus
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §13.3
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use Classes\CRM\PartnerContextLoader;

final class PartnerContextLoaderTest extends TestCase
{
    private \PDO $db;

    /** Auto-incrementing synthetic order_id counter */
    private int $orderSeq = 9000;

    protected function setUp(): void
    {
        $this->db       = new \PDO('sqlite::memory:');
        $this->orderSeq = 9000;
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createFixtureTables();
    }

    // ── L1: RFM fields loaded correctly ──────────────────────────────────

    public function testLoadContextReturnsRfmFields(): void
    {
        $partnerId = 2001;
        $this->insertRfmProfile($partnerId, [
            'current_segment'      => 'Lost',
            'lifetime_value'       => 75000.0,
            'recency_ratio'        => 2.3,
            'is_high_value'        => 0,
            'avg_order_cycle_days' => 21.0,
            'last_order_date'      => '2026-01-10',
            'customer_type'        => 'pharmacy',
            'total_orders'         => 18,
        ]);
        $this->insertProjection($partnerId);

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);

        $this->assertSame($partnerId, $context['partner_id']);
        $this->assertSame('Lost', $context['segment']);
        $this->assertEqualsWithDelta(75000.0, $context['lifetime_value'], 0.01);
        $this->assertEqualsWithDelta(2.3, (float) $context['recency_ratio'], 0.001);
        $this->assertFalse($context['is_high_value']);
        $this->assertEqualsWithDelta(21.0, (float) $context['avg_order_cycle_days'], 0.01);
        $this->assertSame('2026-01-10', $context['last_order_date']);
        $this->assertSame('pharmacy', $context['customer_type']);
        $this->assertSame(18, $context['total_orders']);
    }

    // ── L2: salesperson_name from odoo_customer_projection ───────────────

    public function testLoadContextReturnsSalespersonFromProjection(): void
    {
        $partnerId = 2002;
        $this->insertRfmProfile($partnerId);
        $this->insertProjection($partnerId, '2026-02-15 14:00:00', 'วิชัย ขายดี', 50000.0);

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);

        $this->assertSame('วิชัย ขายดี', $context['salesperson_name']);
        $this->assertSame('2026-02-15 14:00:00', $context['latest_order_at']);
        $this->assertEqualsWithDelta(50000.0, $context['spend_total'], 0.01);
    }

    // ── L3: top_skus sorted by order_appearances DESC ─────────────────────

    public function testTopSkusLoadedFromOrderLinesSortedByAppearancesDesc(): void
    {
        $partnerId = 2003;
        $this->insertRfmProfile($partnerId);
        $this->insertProjection($partnerId);

        // Two orders: ProductA in both → appearances=2; ProductB in one → appearances=1
        $oid1 = $this->insertOrder($partnerId, '2026-03-10', 'S00201', 'delivered');
        $oid2 = $this->insertOrder($partnerId, '2026-03-20', 'S00202', 'delivered');
        $this->insertOrderLine($oid1, 'ProductA', 10.0);
        $this->insertOrderLine($oid2, 'ProductA', 5.0);
        $this->insertOrderLine($oid1, 'ProductB', 20.0);

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);
        $skus    = $context['top_skus'];

        $this->assertNotEmpty($skus);
        $this->assertSame('ProductA', $skus[0]['product_name'],
            'ProductA (2 appearances) should rank first');
        $this->assertSame(2, $skus[0]['order_appearances']);
        $this->assertEqualsWithDelta(15.0, $skus[0]['qty_total'], 0.01);
        $this->assertSame('ProductB', $skus[1]['product_name']);
        $this->assertSame(1, $skus[1]['order_appearances']);
    }

    // ── L4: top_skus limited to 5 ────────────────────────────────────────

    public function testTopSkusLimitedToFive(): void
    {
        $partnerId = 2004;
        $this->insertRfmProfile($partnerId);
        $this->insertProjection($partnerId);

        $oid = $this->insertOrder($partnerId, '2026-03-15', 'S00301', 'delivered');
        for ($i = 1; $i <= 8; $i++) {
            $this->insertOrderLine($oid, "Product{$i}", (float) $i);
        }

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);
        $this->assertCount(5, $context['top_skus'], 'top_skus must be capped at 5 entries');
    }

    // ── L5: empty product history → top_skus is [] with valid struct ──────

    public function testEmptyProductHistoryReturnsEmptySkusWithValidStruct(): void
    {
        $partnerId = 2005;
        $this->insertRfmProfile($partnerId);
        $this->insertProjection($partnerId);
        // No orders, no order_lines inserted

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);

        $this->assertIsArray($context['top_skus']);
        $this->assertEmpty($context['top_skus']);
        $this->assertIsArray($context['recent_orders']);
        $this->assertEmpty($context['recent_orders']);
    }

    // ── L6: recent_orders limited to 5 and ordered by date DESC ──────────

    public function testRecentOrdersLimitedToFiveOrderedByDateDesc(): void
    {
        $partnerId = 2006;
        $this->insertRfmProfile($partnerId);
        $this->insertProjection($partnerId);

        $dates = [
            '2026-01-05', '2026-01-10', '2026-01-15',
            '2026-01-20', '2026-01-25', '2026-01-30', '2026-02-05',
        ];
        foreach ($dates as $i => $date) {
            $this->insertOrder($partnerId, $date, "S0040{$i}", 'delivered');
        }

        $context      = (new PartnerContextLoader($this->db))->loadContext($partnerId);
        $recentOrders = $context['recent_orders'];

        $this->assertCount(5, $recentOrders, 'recent_orders must be capped at 5');
        $this->assertSame('2026-02-05', substr((string) $recentOrders[0]['date_order'], 0, 10),
            'Most recent order should be first');
        $this->assertSame('2026-01-15', substr((string) $recentOrders[4]['date_order'], 0, 10),
            'Fifth-most-recent should be last in list');
    }

    // ── L7: partner with no RFM profile returns zeroed defaults ──────────

    public function testPartnerWithNoRfmProfileReturnsZeroedDefaults(): void
    {
        $partnerId = 2007;
        // No rfm profile — only projection
        $this->insertProjection($partnerId, '2026-02-01 08:00:00', 'ปรีชา', 30000.0);

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);

        $this->assertSame($partnerId, $context['partner_id']);
        $this->assertNull($context['segment'], 'segment should be null when no RFM profile exists');
        $this->assertSame(0.0, $context['lifetime_value']);
        $this->assertSame(0, $context['total_orders']);
        // Projection data should still be present
        $this->assertSame('ปรีชา', $context['salesperson_name']);
    }

    // ── L8: NULL product_name rows excluded from top_skus ─────────────────

    public function testNullProductNameRowsExcludedFromTopSkus(): void
    {
        $partnerId = 2008;
        $this->insertRfmProfile($partnerId);
        $this->insertProjection($partnerId);

        $oid = $this->insertOrder($partnerId, '2026-03-01', 'S00501', 'delivered');
        $this->insertOrderLine($oid, 'ValidProduct', 5.0);
        $this->insertOrderLineNullName($oid, 10.0);

        $context = (new PartnerContextLoader($this->db))->loadContext($partnerId);
        $skus    = $context['top_skus'];

        $this->assertCount(1, $skus, 'NULL product_name rows must be excluded');
        $this->assertSame('ValidProduct', $skus[0]['product_name']);
    }

    // ────────────────────── private fixture helpers ───────────────────────

    private function createFixtureTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS customer_rfm_profile (
                odoo_partner_id      INTEGER NOT NULL PRIMARY KEY,
                customer_type        TEXT    NOT NULL DEFAULT 'other',
                total_orders         INTEGER NOT NULL DEFAULT 0,
                avg_order_cycle_days REAL    NULL,
                cycle_confidence     TEXT    NOT NULL DEFAULT 'fallback',
                is_seasonal          INTEGER NOT NULL DEFAULT 0,
                last_order_date      TEXT    NULL,
                last_order_amount    REAL    NULL,
                lifetime_value       REAL    NOT NULL DEFAULT 0,
                recency_ratio        REAL    NULL,
                recency_score        INTEGER NULL,
                frequency_score      INTEGER NULL,
                monetary_score       INTEGER NULL,
                is_high_value        INTEGER NOT NULL DEFAULT 0,
                current_segment      TEXT    NULL,
                previous_segment     TEXT    NULL,
                segment_changed_at   TEXT    NULL,
                computed_at          TEXT    NOT NULL DEFAULT (datetime('now'))
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS odoo_customer_projection (
                odoo_partner_id  INTEGER NOT NULL PRIMARY KEY,
                latest_order_at  TEXT    NULL,
                salesperson_name TEXT    NULL,
                spend_total      REAL    NOT NULL DEFAULT 0
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS odoo_orders (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id     INTEGER NOT NULL,
                partner_id   INTEGER NOT NULL,
                order_name   TEXT    NOT NULL,
                date_order   TEXT    NOT NULL,
                amount_total REAL    NOT NULL DEFAULT 0,
                state        TEXT    NOT NULL
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS odoo_order_lines (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id     INTEGER NOT NULL,
                product_name TEXT    NULL,
                product_qty  REAL    NOT NULL DEFAULT 0
            )"
        );

        // Empty by design (spec §13.3 confirms odoo_customer_product_stats is unpopulated)
        // so loader falls through to odoo_order_lines fallback
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS odoo_customer_product_stats (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                odoo_partner_id INTEGER NOT NULL,
                product_name    TEXT    NULL,
                qty_90d         REAL    NOT NULL DEFAULT 0
            )"
        );

        // Needed for projection fallback path in PartnerContextLoader
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS odoo_customers_cache (
                odoo_partner_id INTEGER NOT NULL PRIMARY KEY,
                latest_order_at TEXT    NULL
            )"
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertRfmProfile(int $partnerId, array $overrides = []): void
    {
        $defaults = [
            'customer_type'        => 'other',
            'total_orders'         => 0,
            'avg_order_cycle_days' => null,
            'last_order_date'      => null,
            'lifetime_value'       => 0.0,
            'recency_ratio'        => null,
            'is_high_value'        => 0,
            'current_segment'      => null,
            'previous_segment'     => null,
        ];
        $data = array_merge($defaults, $overrides);

        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO customer_rfm_profile
               (odoo_partner_id, customer_type, total_orders, avg_order_cycle_days,
                last_order_date, lifetime_value, recency_ratio, is_high_value,
                current_segment, previous_segment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $partnerId,
            $data['customer_type'],
            $data['total_orders'],
            $data['avg_order_cycle_days'],
            $data['last_order_date'],
            $data['lifetime_value'],
            $data['recency_ratio'],
            $data['is_high_value'],
            $data['current_segment'],
            $data['previous_segment'],
        ]);
    }

    private function insertProjection(
        int $partnerId,
        string $latestOrderAt = '2026-01-01 00:00:00',
        ?string $salesperson = null,
        float $spendTotal = 0.0
    ): void {
        $stmt = $this->db->prepare(
            "INSERT OR REPLACE INTO odoo_customer_projection
               (odoo_partner_id, latest_order_at, salesperson_name, spend_total)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$partnerId, $latestOrderAt, $salesperson, $spendTotal]);
    }

    /**
     * Insert an order row and return the synthetic order_id used as FK in order_lines.
     */
    private function insertOrder(
        int $partnerId,
        string $dateOrder,
        string $orderName,
        string $state
    ): int {
        $orderId = ++$this->orderSeq;
        $stmt    = $this->db->prepare(
            "INSERT INTO odoo_orders
               (order_id, partner_id, order_name, date_order, amount_total, state)
             VALUES (?, ?, ?, ?, 1000.0, ?)"
        );
        $stmt->execute([$orderId, $partnerId, $orderName, $dateOrder, $state]);
        return $orderId;
    }

    private function insertOrderLine(int $orderId, string $productName, float $qty): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO odoo_order_lines (order_id, product_name, product_qty)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$orderId, $productName, $qty]);
    }

    private function insertOrderLineNullName(int $orderId, float $qty): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO odoo_order_lines (order_id, product_name, product_qty)
             VALUES (?, NULL, ?)"
        );
        $stmt->execute([$orderId, $qty]);
    }
}
