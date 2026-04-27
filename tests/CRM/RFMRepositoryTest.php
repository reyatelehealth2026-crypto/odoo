<?php
/**
 * Integration Test: RFMRepository
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.2, §7
 *
 * Uses an SQLite :memory: fixture that mirrors the MariaDB schema.
 * DATE_FORMAT() is not available in SQLite, so the fixture stores
 * date_order as plain 'Y-m-d' TEXT — RFMRepository's DATE_FORMAT call
 * works in MariaDB; the SQLite path is tested via a subclass that
 * overrides the SQL to use substr() instead. This lets us verify all
 * business logic without a live MariaDB connection.
 *
 * Coverage target: 80%
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use Classes\CRM\RFMRepository;
use PDO;

/**
 * SQLite-compatible subclass of RFMRepository for integration tests.
 *
 * MariaDB uses DATE_FORMAT(date_order, '%Y-%m-%d') and DATEDIFF().
 * SQLite uses substr(date_order, 1, 10) and julianday() arithmetic.
 *
 * This subclass overrides the two dialect-specific query methods,
 * keeping all other business logic identical to production code.
 * Possible because RFMRepository is not final and ORDER_STATES + pdo
 * are protected.
 */
final class SqliteRFMRepository extends RFMRepository
{
    /**
     * Return order dates using SQLite substr() instead of DATE_FORMAT().
     *
     * @return string[]
     */
    public function loadOrderDates(int $partnerId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ORDER_STATES), '?'));
        $sql          = "
            SELECT substr(date_order, 1, 10) AS order_date
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
     * Return eligible partner IDs using SQLite julianday() instead of DATEDIFF().
     *
     * @return int[]
     */
    public function loadEligiblePartnerIds(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ORDER_STATES), '?'));
        $sql          = "
            SELECT   partner_id
            FROM     odoo_orders
            WHERE    state IN ({$placeholders})
              AND    partner_id IS NOT NULL
            GROUP BY partner_id
            HAVING   COUNT(*) >= 3
              AND    CAST((julianday(MAX(date_order)) - julianday(MIN(date_order))) AS INTEGER) >= 30
            ORDER BY partner_id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(self::ORDER_STATES);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'partner_id'));
    }

    /**
     * Upsert using SQLite INSERT OR REPLACE instead of ON DUPLICATE KEY UPDATE.
     *
     * @param array<string, mixed> $fields
     */
    public function upsertProfile(int $partnerId, array $fields): void
    {
        $fields['odoo_partner_id'] = $partnerId;

        $columns      = array_keys($fields);
        $placeholders = array_fill(0, count($columns), '?');
        $values       = array_values($fields);

        $colList = '`' . implode('`, `', $columns) . '`';
        $phList  = implode(', ', $placeholders);

        $sql = "INSERT OR REPLACE INTO customer_rfm_profile ({$colList}) VALUES ({$phList})";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * Append segment history using SQLite datetime('now') instead of NOW().
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
                (?, ?, ?, ?, datetime('now'))
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$partnerId, $from, $to, $ratio]);
    }
}

final class RFMRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SqliteRFMRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createFixtureTables();
        $this->repo = new SqliteRFMRepository($this->pdo);
    }

    // ─────────────────────────────────────────────────────────────
    // loadOrderDates
    // ─────────────────────────────────────────────────────────────

    public function testLoadOrderDatesFiltersExcludedStates(): void
    {
        $this->insertOrder(1, 101, '2026-01-01', 'delivered', 1000.00);
        $this->insertOrder(2, 101, '2026-01-15', 'to_delivery', 2000.00);
        // NULL state is not in the allowlist — must be excluded.
        $this->pdo->exec(
            "INSERT INTO odoo_orders (id, partner_id, date_order, state, amount_total)
             VALUES (3, 101, '2026-02-01', NULL, 3000.00)"
        );

        $dates = $this->repo->loadOrderDates(101);

        $this->assertCount(2, $dates);
        $this->assertSame('2026-01-01', $dates[0]);
        $this->assertSame('2026-01-15', $dates[1]);
    }

    public function testLoadOrderDatesReturnsAscendingOrder(): void
    {
        // Insert in reverse chronological order to verify ORDER BY.
        $this->insertOrder(10, 202, '2026-03-01', 'done', 500.00);
        $this->insertOrder(11, 202, '2026-01-01', 'sale', 500.00);
        $this->insertOrder(12, 202, '2026-02-01', 'delivered', 500.00);

        $dates = $this->repo->loadOrderDates(202);

        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], $dates);
    }

    public function testLoadOrderDatesReturnsEmptyArrayForUnknownPartner(): void
    {
        $dates = $this->repo->loadOrderDates(99999);
        $this->assertSame([], $dates);
    }

    public function testLoadOrderDatesIncludesAllAllowedStates(): void
    {
        $allowedStates = [
            'delivered', 'to_delivery', 'sale', 'done', 'completed',
            'validated', 'packed', 'picked', 'picking', 'picker_assign', 'packing',
        ];
        $id = 100;
        foreach ($allowedStates as $i => $state) {
            $date = date('Y-m-d', strtotime("2026-01-01 +{$i} days"));
            $this->insertOrder($id++, 303, $date, $state, 100.00);
        }

        $dates = $this->repo->loadOrderDates(303);
        $this->assertCount(count($allowedStates), $dates);
    }

    // ─────────────────────────────────────────────────────────────
    // loadEligiblePartnerIds
    // ─────────────────────────────────────────────────────────────

    public function testLoadEligiblePartnerIdsRequiresThreeOrMoreOrders(): void
    {
        // Partner 401: only 2 orders with wide span — not eligible (< 3 orders).
        $this->insertOrder(20, 401, '2026-01-01', 'delivered', 100.00);
        $this->insertOrder(21, 401, '2026-03-01', 'delivered', 100.00);

        // Partner 402: 3 orders with >=30-day span — eligible.
        $this->insertOrder(22, 402, '2026-01-01', 'delivered', 100.00);
        $this->insertOrder(23, 402, '2026-02-01', 'delivered', 100.00);
        $this->insertOrder(24, 402, '2026-03-15', 'delivered', 100.00);

        $ids = $this->repo->loadEligiblePartnerIds();

        $this->assertNotContains(401, $ids);
        $this->assertContains(402, $ids);
    }

    public function testLoadEligiblePartnerIdsRequiresThirtyDaySpan(): void
    {
        // Partner 501: 3 orders but span is only 10 days — not eligible.
        $this->insertOrder(30, 501, '2026-01-01', 'delivered', 100.00);
        $this->insertOrder(31, 501, '2026-01-05', 'delivered', 100.00);
        $this->insertOrder(32, 501, '2026-01-11', 'delivered', 100.00);

        // Partner 502: 3 orders, 60-day span — eligible.
        $this->insertOrder(33, 502, '2026-01-01', 'delivered', 100.00);
        $this->insertOrder(34, 502, '2026-02-01', 'delivered', 100.00);
        $this->insertOrder(35, 502, '2026-03-02', 'delivered', 100.00);

        $ids = $this->repo->loadEligiblePartnerIds();

        $this->assertNotContains(501, $ids);
        $this->assertContains(502, $ids);
    }

    // ─────────────────────────────────────────────────────────────
    // upsertProfile — insert then update same row
    // ─────────────────────────────────────────────────────────────

    public function testUpsertProfileInsertsNewRow(): void
    {
        $this->repo->upsertProfile(601, [
            'total_orders'     => 5,
            'lifetime_value'   => 50000.00,
            'current_segment'  => 'Champion',
            'cycle_confidence' => 'high',
            'computed_at'      => '2026-04-27 00:00:00',
        ]);

        $row = $this->fetchProfile(601);
        $this->assertNotNull($row);
        $this->assertSame('5', (string) $row['total_orders']);
        $this->assertSame('Champion', $row['current_segment']);
    }

    public function testUpsertProfileUpdatesExistingRowInPlace(): void
    {
        // First insert.
        $this->repo->upsertProfile(602, [
            'total_orders'     => 3,
            'lifetime_value'   => 10000.00,
            'current_segment'  => 'Watchlist',
            'cycle_confidence' => 'low',
            'computed_at'      => '2026-04-26 00:00:00',
        ]);

        // Second upsert on same PK — must update, not duplicate.
        $this->repo->upsertProfile(602, [
            'total_orders'     => 4,
            'lifetime_value'   => 15000.00,
            'current_segment'  => 'Champion',
            'cycle_confidence' => 'high',
            'computed_at'      => '2026-04-27 00:00:00',
        ]);

        $row = $this->fetchProfile(602);
        $this->assertNotNull($row);
        $this->assertSame('4', (string) $row['total_orders']);
        $this->assertSame('Champion', $row['current_segment']);
        $this->assertSame('2026-04-27 00:00:00', $row['computed_at']);

        // Exactly one row must exist (idempotent upsert).
        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM customer_rfm_profile WHERE odoo_partner_id = 602"
        )->fetchColumn();
        $this->assertSame('1', (string) $count);
    }

    // ─────────────────────────────────────────────────────────────
    // appendSegmentHistory — one row per call
    // ─────────────────────────────────────────────────────────────

    public function testAppendSegmentHistoryCreatesOneRowPerCall(): void
    {
        $this->repo->appendSegmentHistory(701, null, 'Champion', 0.8);
        $this->repo->appendSegmentHistory(701, 'Champion', 'Watchlist', 1.2);

        $count = $this->pdo->query(
            "SELECT COUNT(*) FROM customer_segment_history WHERE odoo_partner_id = 701"
        )->fetchColumn();

        $this->assertSame('2', (string) $count);
    }

    public function testAppendSegmentHistoryStoresCorrectFields(): void
    {
        $this->repo->appendSegmentHistory(702, 'Watchlist', 'At-Risk', 1.55);

        $stmt = $this->pdo->query(
            "SELECT * FROM customer_segment_history WHERE odoo_partner_id = 702 LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($row);
        $this->assertSame('Watchlist', $row['from_segment']);
        $this->assertSame('At-Risk', $row['to_segment']);
        $this->assertEqualsWithDelta(1.55, (float) $row['recency_ratio'], 0.001);
    }

    public function testAppendSegmentHistoryAcceptsNullFromSegment(): void
    {
        $this->repo->appendSegmentHistory(703, null, 'Champion', 0.5);

        $stmt = $this->pdo->query(
            "SELECT from_segment FROM customer_segment_history WHERE odoo_partner_id = 703"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNull($row['from_segment']);
    }

    // ─────────────────────────────────────────────────────────────
    // loadLifetimeValue
    // ─────────────────────────────────────────────────────────────

    public function testLoadLifetimeValueSumsEligibleOrdersOnly(): void
    {
        // Two valid orders: 10000 + 20000 = 30000.
        $this->insertOrder(50, 801, '2026-01-01', 'delivered', 10000.00);
        $this->insertOrder(51, 801, '2026-02-01', 'done', 20000.00);
        // NULL state — must not be included in sum.
        $this->pdo->exec(
            "INSERT INTO odoo_orders (id, partner_id, date_order, state, amount_total)
             VALUES (52, 801, '2026-03-01', NULL, 5000.00)"
        );

        $ltv = $this->repo->loadLifetimeValue(801);
        $this->assertEqualsWithDelta(30000.0, $ltv, 0.01);
    }

    public function testLoadLifetimeValueReturnsZeroForUnknownPartner(): void
    {
        $ltv = $this->repo->loadLifetimeValue(99998);
        $this->assertSame(0.0, $ltv);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function createFixtureTables(): void
    {
        // SQLite-compatible DDL mirroring the production MariaDB schema.
        $this->pdo->exec("
            CREATE TABLE odoo_orders (
                id            INTEGER PRIMARY KEY,
                partner_id    INTEGER,
                date_order    TEXT,
                state         TEXT,
                amount_total  REAL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE customer_rfm_profile (
                odoo_partner_id      INTEGER PRIMARY KEY,
                customer_type        TEXT    NOT NULL DEFAULT 'other',
                total_orders         INTEGER NOT NULL DEFAULT 0,
                avg_order_cycle_days REAL,
                cycle_confidence     TEXT    NOT NULL DEFAULT 'fallback',
                is_seasonal          INTEGER NOT NULL DEFAULT 0,
                last_order_date      TEXT,
                last_order_amount    REAL,
                lifetime_value       REAL    NOT NULL DEFAULT 0,
                recency_ratio        REAL,
                recency_score        INTEGER,
                frequency_score      INTEGER,
                monetary_score       INTEGER,
                is_high_value        INTEGER NOT NULL DEFAULT 0,
                current_segment      TEXT,
                previous_segment     TEXT,
                segment_changed_at   TEXT,
                computed_at          TEXT    NOT NULL
            )
        ");

        $this->pdo->exec("
            CREATE TABLE customer_segment_history (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                odoo_partner_id INTEGER NOT NULL,
                from_segment    TEXT,
                to_segment      TEXT    NOT NULL,
                recency_ratio   REAL,
                changed_at      TEXT    NOT NULL
            )
        ");
    }

    private function insertOrder(
        int $id,
        int $partnerId,
        string $date,
        string $state,
        float $amount
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO odoo_orders (id, partner_id, date_order, state, amount_total)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$id, $partnerId, $date, $state, $amount]);
    }

    /** @return array<string, mixed>|null */
    private function fetchProfile(int $partnerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM customer_rfm_profile WHERE odoo_partner_id = ? LIMIT 1"
        );
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
