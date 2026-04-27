<?php

/**
 * Unit Tests: AutoActionService
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 4-5, §9
 *
 * Uses SQLite in-memory database to isolate tests from production MySQL.
 * Table schemas mirror the production DDL column names and semantics.
 * All values are synthetic — no production data is read or written.
 *
 * Test cases:
 *   T1: shouldRun returns false when soft_launch=1
 *   T2: shouldRun returns false when system_enabled=0
 *   T3: shouldRun returns true when system_enabled=1 AND soft_launch=0
 *   T4: frequencyCapAllows returns false when last entry is within cap window
 *   T5: frequencyCapAllows returns true when no prior entry exists
 *   T6: frequencyCapAllows returns true when last entry is older than cap
 *   T7: enqueueCallLog inserts with correct channel and segment_at_call
 *   T8: enqueueCallLog falls back to channel='other' on invalid channel value
 */

declare(strict_types=1);

namespace Tests\CRM;

use Classes\CRM\AutoActionService;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutoActionServiceTest extends TestCase
{
    private PDO $db;
    private AutoActionService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // ── churn_settings ────────────────────────────────────────────────────
        $this->db->exec(
            'CREATE TABLE churn_settings (
                id                              INTEGER PRIMARY KEY,
                system_enabled                  INTEGER NOT NULL DEFAULT 0,
                soft_launch                     INTEGER NOT NULL DEFAULT 1,
                notification_frequency_cap_days INTEGER NOT NULL DEFAULT 14
            )'
        );
        $this->db->exec(
            'INSERT INTO churn_settings
                (id, system_enabled, soft_launch, notification_frequency_cap_days)
             VALUES (1, 0, 1, 14)'
        );

        // ── customer_call_log ─────────────────────────────────────────────────
        $this->db->exec(
            "CREATE TABLE customer_call_log (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                odoo_partner_id  INTEGER NOT NULL,
                admin_id         INTEGER,
                channel          TEXT    NOT NULL DEFAULT 'phone',
                outcome          TEXT,
                notes            TEXT,
                segment_at_call  TEXT,
                called_at        TEXT    NOT NULL,
                created_at       TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        // ── dev_logs stub (used by devLog helpers) ────────────────────────────
        $this->db->exec(
            'CREATE TABLE dev_logs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type   TEXT NOT NULL,
                source     TEXT NOT NULL,
                message    TEXT NOT NULL,
                data       TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        // ── line_accounts stub (pushLineText path — not exercised in unit tests)
        $this->db->exec(
            'CREATE TABLE line_accounts (
                id                   INTEGER PRIMARY KEY,
                channel_access_token TEXT
            )'
        );

        // ── odoo_line_users stub ──────────────────────────────────────────────
        $this->db->exec(
            'CREATE TABLE odoo_line_users (
                odoo_partner_id INTEGER NOT NULL,
                line_user_id    TEXT
            )'
        );

        $this->service = new AutoActionService($this->db);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // shouldRun
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * T1: soft_launch=1 must block auto-actions regardless of system_enabled.
     * Spec §9: "Soft launch: ระบบอ่านอย่างเดียว — ไม่มี notification ออก"
     */
    public function testShouldRunReturnsFalseWhenSoftLaunchIsOne(): void
    {
        $this->db->exec(
            'UPDATE churn_settings SET system_enabled=1, soft_launch=1 WHERE id=1'
        );

        $this->assertFalse(
            $this->service->shouldRun(),
            'shouldRun must return false when soft_launch=1'
        );
    }

    /**
     * T2: system_enabled=0 is the kill switch — must block all auto-actions.
     * Spec §9: "Kill switch: churn_settings.system_enabled=0 หยุดทุก auto-action ทันที"
     */
    public function testShouldRunReturnsFalseWhenSystemDisabled(): void
    {
        $this->db->exec(
            'UPDATE churn_settings SET system_enabled=0, soft_launch=0 WHERE id=1'
        );

        $this->assertFalse(
            $this->service->shouldRun(),
            'shouldRun must return false when system_enabled=0'
        );
    }

    /**
     * T3: shouldRun returns true only when both guards pass.
     */
    public function testShouldRunReturnsTrueWhenFullyEnabled(): void
    {
        $this->db->exec(
            'UPDATE churn_settings SET system_enabled=1, soft_launch=0 WHERE id=1'
        );

        $this->assertTrue(
            $this->service->shouldRun(),
            'shouldRun must return true when system_enabled=1 AND soft_launch=0'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // frequencyCapAllows
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * T4: cap blocks when last entry is within the cap window.
     * Spec §9: "ลูกค้าหนึ่งราย ห้ามรับ auto check-in ซ้ำใน 14 วัน"
     */
    public function testFrequencyCapDisallowsRecentEntry(): void
    {
        $partnerId = 12345;
        $capDays   = 14;

        // 5 days ago — inside the 14-day cap window.
        $calledAt = date('Y-m-d H:i:s', strtotime('-5 days'));
        $this->db->prepare(
            "INSERT INTO customer_call_log
                (odoo_partner_id, channel, called_at, created_at)
             VALUES (?, 'line', ?, CURRENT_TIMESTAMP)"
        )->execute([$partnerId, $calledAt]);

        $this->assertFalse(
            $this->service->frequencyCapAllows($partnerId, $capDays),
            'frequencyCapAllows must return false when last entry is within cap window'
        );
    }

    /**
     * T5: cap allows when no prior entry exists for this partner.
     */
    public function testFrequencyCapAllowsWhenNoPriorEntry(): void
    {
        $this->assertTrue(
            $this->service->frequencyCapAllows(99999, 14),
            'frequencyCapAllows must return true when no prior call log entry exists'
        );
    }

    /**
     * T6: cap allows when last entry is older than cap days.
     */
    public function testFrequencyCapAllowsWhenEntryIsOlderThanCap(): void
    {
        $partnerId = 12345;
        $capDays   = 14;

        // 20 days ago — outside the 14-day cap window.
        $calledAt = date('Y-m-d H:i:s', strtotime('-20 days'));
        $this->db->prepare(
            "INSERT INTO customer_call_log
                (odoo_partner_id, channel, called_at, created_at)
             VALUES (?, 'phone', ?, CURRENT_TIMESTAMP)"
        )->execute([$partnerId, $calledAt]);

        $this->assertTrue(
            $this->service->frequencyCapAllows($partnerId, $capDays),
            'frequencyCapAllows must return true when last entry is older than cap days'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // enqueueCallLog
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * T7: enqueueCallLog inserts a row with correct channel and segment_at_call.
     * Spec: "outcome=NULL (= queued for admin approval)"
     */
    public function testEnqueueCallLogInsertsCorrectRow(): void
    {
        $partnerId = 12345;
        $channel   = 'line';
        $segment   = 'At-Risk';
        $notes     = 'Auto-draft check-in (synthetic test fixture)';

        $rowId = $this->service->enqueueCallLog(
            $partnerId,
            $channel,
            $segment,
            $notes
        );

        $this->assertIsInt($rowId, 'enqueueCallLog must return an integer row ID');
        $this->assertGreaterThan(0, $rowId, 'Returned row ID must be > 0');

        $stmt = $this->db->prepare(
            'SELECT * FROM customer_call_log WHERE id = ?'
        );
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Row must exist in customer_call_log after insert');
        $this->assertSame($partnerId, (int) $row['odoo_partner_id']);
        $this->assertSame($channel,   $row['channel']);
        $this->assertSame($segment,   $row['segment_at_call']);
        $this->assertSame($notes,     $row['notes']);
        $this->assertNull(
            $row['outcome'],
            'outcome must be NULL — row is queued for admin approval'
        );
        $this->assertNull(
            $row['admin_id'],
            'admin_id must be NULL for auto-generated rows'
        );
    }

    /**
     * T8: enqueueCallLog falls back to channel='other' when an invalid channel
     * value is supplied, without throwing an exception.
     */
    public function testEnqueueCallLogFallsBackOnInvalidChannel(): void
    {
        $rowId = $this->service->enqueueCallLog(
            12345,
            'carrier_pigeon', // invalid — not in ENUM list
            'Lost',
            'synthetic test notes'
        );

        // SQLite has no ENUM constraint, so the insert succeeds with fallback value.
        $this->assertIsInt($rowId);

        $stmt = $this->db->prepare(
            'SELECT channel FROM customer_call_log WHERE id = ?'
        );
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame(
            'other',
            $row['channel'],
            'Invalid channel must be silently normalised to "other"'
        );
    }
}
