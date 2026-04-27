<?php
/**
 * InboxSentinelTest — unit + integration tests for the Thai sentiment classifier.
 *
 * Discovered by PHPUnit via `composer test`.
 * Tests use SQLite in-memory fixture — no live DB.
 *
 * Cases:
 *   T1  classify: red — direct complaint
 *   T2  classify: red — confirmed expiry complaint ("ใกล้หมดอายุ")
 *   T3  classify: null — benign expiry question ("หมดอายุเมื่อไรคะ")
 *   T4  classify: orange — explicit dissatisfaction
 *   T5  classify: yellow — follow-up
 *   T6  classify: yellow_urgent — follow-up + urgent marker
 *   T7  classify: green — positive
 *   T8  classify: null — too short / empty
 *   T9  classify: null — neutral text
 *   T10 getInboxFlagsForPartners — picks worst severity per partner
 *   T11 getInboxFlagsForPartners — empty input → empty output
 *   T12 getInboxFlagsForPartners — green-only customers excluded
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use Classes\CRM\InboxSentinel;

final class InboxSentinelTest extends TestCase
{
    private \PDO $db;
    private InboxSentinel $sentinel;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createFixtureTables();
        $this->sentinel = new InboxSentinel($this->db);
    }

    // ── classify() unit tests ─────────────────────────────────────────────

    public function testClassifyRedDirectComplaint(): void
    {
        $this->assertSame('red', $this->sentinel->classify('ส่งของผิดยา รับมาเป็นยาคนละตัวค่ะ'));
        $this->assertSame('red', $this->sentinel->classify('ขอยกเลิกออเดอร์นี้ค่ะ'));
    }

    public function testClassifyRedConfirmedExpiryComplaint(): void
    {
        $this->assertSame('red', $this->sentinel->classify('ยาที่ส่งมาใกล้หมดอายุ ขอเปลี่ยนค่ะ'));
        $this->assertSame('red', $this->sentinel->classify('ของหมดอายุแล้วใช้ไม่ได้'));
    }

    public function testClassifyExpiryQuestionIsBenign(): void
    {
        $this->assertNull($this->sentinel->classify('หมดอายุเมื่อไรคะ'));
        $this->assertNull($this->sentinel->classify('ตัวนี้หมดอายุเมื่อไหร่'));
    }

    public function testClassifyOrangeDissatisfaction(): void
    {
        $this->assertSame('orange', $this->sentinel->classify('ทำไมช้าจังเลยคะ'));
        $this->assertSame('orange', $this->sentinel->classify('แย่มากเลยรอบนี้'));
    }

    public function testClassifyYellowFollowUp(): void
    {
        // "ขอเปลี่ยน" — explicit follow-up keyword (no complaint trigger)
        $this->assertSame('yellow', $this->sentinel->classify('ขอเปลี่ยนกล่องบรรจุภัณฑ์ค่ะ'));
        // "ตามผล" — chasing
        $this->assertSame('yellow', $this->sentinel->classify('แอดมินคะ ขอตามผลออเดอร์เมื่อวานหน่อยค่ะ'));
        // "ของยังไม่ได้รับ" actually contains "ไม่ได้รับ" → red (correct: customer didn't get goods)
        $this->assertSame('red', $this->sentinel->classify('ของยังไม่ได้รับเลย'));
    }

    public function testClassifyYellowUrgent(): void
    {
        $this->assertSame('yellow_urgent', $this->sentinel->classify('ขอเลขพัสดุด่วนนะคะ'));
        $this->assertSame('yellow_urgent', $this->sentinel->classify('รีบใช้มากๆ ขอด่วนค่ะ'));
    }

    public function testClassifyGreenPositive(): void
    {
        $this->assertSame('green', $this->sentinel->classify('ขอบคุณมากค่ะ'));
        $this->assertSame('green', $this->sentinel->classify('ไวมากเลยค่ะ ประทับใจ'));
    }

    public function testClassifyNullForShortOrEmpty(): void
    {
        $this->assertNull($this->sentinel->classify(''));
        $this->assertNull($this->sentinel->classify('ค่ะ'));   // 2 chars
        $this->assertNull($this->sentinel->classify('ครับ')); // 4 chars but neutral
    }

    public function testClassifyNullForNeutralText(): void
    {
        $this->assertNull($this->sentinel->classify('สวัสดีครับ พี่เบียร์'));
        $this->assertNull($this->sentinel->classify('ขอสั่ง paracetamol 500 จำนวน 2 กล่อง'));
    }

    // ── getInboxFlagsForPartners() integration tests ──────────────────────

    public function testGetInboxFlagsPicksWorstSeverityPerPartner(): void
    {
        $this->seedUserAndCustomer(userId: 100, lineId: 'U_A', partnerId: 1932);
        $this->seedMessage(100, 'ขอเลขพัสดุหน่อยคะ',                '-2 days');
        $this->seedMessage(100, 'ใกล้หมดอายุ ขอเปลี่ยนค่ะ',         '-1 days');
        $this->seedMessage(100, 'ขอบคุณค่ะ',                       '-3 hours');

        $this->seedUserAndCustomer(userId: 200, lineId: 'U_B', partnerId: 4242);
        $this->seedMessage(200, 'ขอเปลี่ยนสีค่ะ', '-5 days');

        $flags = $this->sentinel->getInboxFlagsForPartners([1932, 4242, 9999], 30);

        $this->assertArrayHasKey(1932, $flags);
        $this->assertSame('red', $flags[1932]['severity']);
        $this->assertSame(2, $flags[1932]['count']);    // green excluded from count
        $this->assertStringContainsString('ใกล้หมดอายุ', $flags[1932]['latest_text']);

        $this->assertArrayHasKey(4242, $flags);
        $this->assertSame('yellow', $flags[4242]['severity']);

        $this->assertArrayNotHasKey(9999, $flags);
    }

    public function testGetInboxFlagsEmptyInputReturnsEmpty(): void
    {
        $this->assertSame([], $this->sentinel->getInboxFlagsForPartners([], 30));
        $this->assertSame([], $this->sentinel->getInboxFlagsForPartners([0, -1], 30));
    }

    public function testGetInboxFlagsExcludesGreenOnlyCustomers(): void
    {
        $this->seedUserAndCustomer(userId: 300, lineId: 'U_C', partnerId: 5555);
        $this->seedMessage(300, 'ขอบคุณค่ะ',         '-1 days');
        $this->seedMessage(300, 'ดีมากเลยค่ะ',       '-2 days');

        $flags = $this->sentinel->getInboxFlagsForPartners([5555], 30);

        $this->assertArrayNotHasKey(5555, $flags,
            'Customers with only positive messages must not appear in flag list');
    }

    // ── getConversation() integration tests ─────────────────────────────────

    public function testGetConversationReturnsBothDirectionsOldestFirst(): void
    {
        $this->seedUserAndCustomer(userId: 400, lineId: 'U_D', partnerId: 7777);
        $this->seedMessage(400, 'ของยังไม่ได้รับเลย', '-72 hours', 'incoming');
        $this->seedOutgoingMessage(400, 'ขออภัยค่ะ จะรีบจัดส่งให้',  '-60 hours', 'admin:Beer');
        $this->seedMessage(400, 'ขอบคุณค่ะ',         '-48 hours', 'incoming');

        $convo = $this->sentinel->getConversation(7777, 30, 100);

        $this->assertCount(3, $convo);
        $this->assertSame('incoming', $convo[0]['direction']);
        $this->assertSame('outgoing', $convo[1]['direction']);
        $this->assertSame('incoming', $convo[2]['direction']);
        $this->assertSame('admin:Beer', $convo[1]['sent_by']);
        // Customer "ของยังไม่ได้รับ" → red
        $this->assertSame('red', $convo[0]['classification']);
        // Outgoing not classified
        $this->assertNull($convo[1]['classification']);
        // "ขอบคุณ" → green
        $this->assertSame('green', $convo[2]['classification']);
    }

    public function testGetConversationEmptyWhenPartnerInvalid(): void
    {
        $this->assertSame([], $this->sentinel->getConversation(0, 30));
        $this->assertSame([], $this->sentinel->getConversation(999999, 30));
    }

    public function testGetConversationRespectsDayWindow(): void
    {
        $this->seedUserAndCustomer(userId: 500, lineId: 'U_E', partnerId: 8888);
        $this->seedMessage(500, 'ส่งของผิดยา',  '-45 days', 'incoming');
        $this->seedMessage(500, 'ขอเปลี่ยนค่ะ', '-1 days', 'incoming');

        // 30-day window excludes the 45-day-old message
        $convo30 = $this->sentinel->getConversation(8888, 30);
        $this->assertCount(1, $convo30);

        // 60-day window includes both
        $convo60 = $this->sentinel->getConversation(8888, 60);
        $this->assertCount(2, $convo60);
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function createFixtureTables(): void
    {
        $this->db->exec("
            CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                direction TEXT NOT NULL,
                message_type TEXT NOT NULL,
                content TEXT,
                sent_by TEXT,
                created_at TEXT NOT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                line_user_id TEXT NOT NULL
            )
        ");
        $this->db->exec("
            CREATE TABLE odoo_customers_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                line_user_id TEXT NOT NULL,
                partner_id TEXT NOT NULL
            )
        ");
    }

    private function seedUserAndCustomer(int $userId, string $lineId, int $partnerId): void
    {
        $this->db->prepare('INSERT INTO users (id, line_user_id) VALUES (?, ?)')
                 ->execute([$userId, $lineId]);
        $this->db->prepare('INSERT INTO odoo_customers_cache (line_user_id, partner_id) VALUES (?, ?)')
                 ->execute([$lineId, (string) $partnerId]);
    }

    private function seedMessage(int $userId, string $content, string $relativeWhen, string $direction = 'incoming'): void
    {
        $ts = (new \DateTimeImmutable($relativeWhen))->format('Y-m-d H:i:s');
        $this->db->prepare("
            INSERT INTO messages (user_id, direction, message_type, content, sent_by, created_at)
            VALUES (?, ?, 'text', ?, NULL, ?)
        ")->execute([$userId, $direction, $content, $ts]);
    }

    private function seedOutgoingMessage(int $userId, string $content, string $relativeWhen, string $sentBy): void
    {
        $ts = (new \DateTimeImmutable($relativeWhen))->format('Y-m-d H:i:s');
        $this->db->prepare("
            INSERT INTO messages (user_id, direction, message_type, content, sent_by, created_at)
            VALUES (?, 'outgoing', 'text', ?, ?, ?)
        ")->execute([$userId, $content, $sentBy, $ts]);
    }
}
