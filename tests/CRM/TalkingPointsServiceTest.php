<?php
/**
 * TalkingPointsServiceTest — unit tests for TalkingPointsService.
 *
 * Discovered and run by PHPUnit via `composer test`.
 * Tests use SQLite in-memory fixture — no live DB, no real Gemini calls.
 *
 * Test cases:
 *   T1  Cache hit  → Gemini NOT called, cached=true
 *   T2  Cache miss + valid Gemini output → payload cached + counter incremented
 *   T3  Cache miss + invalid Gemini output → fallback template, counter NOT incremented
 *   T4  Counter at cap → RuntimeException(429) before calling Gemini
 *   T5  Same partner within 24h → second call is cache hit (no Gemini call)
 *   T6  validateOutput rejects missing required key
 *   T7  validateOutput rejects empty discovery_questions array
 *   T8  validateOutput accepts null warning field
 *   T9  buildPrompt contains guardrail text and real context values
 *   T10 incrementCounter resets stale date then sets count to 1
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.4, §9
 */

declare(strict_types=1);

namespace Tests\CRM;

use PHPUnit\Framework\TestCase;
use Classes\CRM\TalkingPointsService;
use Classes\CRM\PartnerContextLoader;

// GeminiAI is a legacy non-namespaced class in classes/ not covered by PSR-4 autoload.
// Require it explicitly so PHPUnit mock builder can find the type.
if (!class_exists(\GeminiAI::class)) {
    require_once __DIR__ . '/../../classes/GeminiAI.php';
}

final class TalkingPointsServiceTest extends TestCase
{
    private \PDO $db;

    /** A minimal payload that satisfies validateOutput() */
    private const VALID_PAYLOAD = [
        'opener'              => 'สวัสดีครับ',
        'context_reminder'    => 'ลูกค้าห่างหายมา 60 วัน',
        'discovery_questions' => ['สนใจสินค้าใดบ้างครับ?', 'มีปัญหาอะไรไหมครับ?'],
        'objection_handlers'  => ['เข้าใจครับ จะโทรกลับภายหลัง'],
        'next_best_offer'     => 'ส่วนลด 5% สำหรับออเดอร์ถัดไป',
        'warning'             => null,
    ];

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createFixtureTables();
        $this->seedSettings(cap: 200, usedToday: 0, resetAt: date('Y-m-d'));
    }

    // ── T1: Cache hit — Gemini never called ─────────────────────────────

    public function testCacheHitDoesNotCallGemini(): void
    {
        $partnerId = 1001;
        $this->seedCache($partnerId, self::VALID_PAYLOAD, expiresInHours: 23);

        $gemini = $this->createMock(\GeminiAI::class);
        $gemini->expects($this->never())->method('generateBroadcast');

        $result = $this->buildService($gemini)->getForPartner($partnerId);

        $this->assertTrue($result['cached'], 'Expected cache hit');
        $this->assertSame('สวัสดีครับ', $result['payload']['opener']);
        $this->assertSame(0, $result['tokens_used']);
    }

    // ── T2: Cache miss + valid output → cache written + counter++ ────────

    public function testCacheMissValidOutputCachesAndIncrementsCounter(): void
    {
        $partnerId  = 1002;
        $jsonOutput = json_encode(self::VALID_PAYLOAD, JSON_UNESCAPED_UNICODE);

        $gemini = $this->createMock(\GeminiAI::class);
        $gemini->expects($this->once())
               ->method('generateBroadcast')
               ->willReturn(['success' => true, 'text' => $jsonOutput, 'tokens_used' => 250]);

        $result = $this->buildService($gemini)->getForPartner($partnerId);

        $this->assertFalse($result['cached'], 'Expected cache miss');
        $this->assertSame('สวัสดีครับ', $result['payload']['opener']);

        // Cache row must now exist
        $this->assertNotNull(
            $this->fetchCacheRow($partnerId),
            'Cache row should have been written after valid Gemini response'
        );

        // Counter must be incremented to 1
        $settings = $this->fetchSettings();
        $this->assertSame(1, (int) $settings['gemini_calls_today']);
    }

    // ── T3: Invalid output → fallback template, counter NOT incremented ──

    public function testCacheMissInvalidOutputReturnsFallbackWithoutCounterIncrement(): void
    {
        $partnerId = 1003;

        $gemini = $this->createMock(\GeminiAI::class);
        $gemini->expects($this->once())
               ->method('generateBroadcast')
               ->willReturn(['success' => true, 'text' => '{"broken":true}', 'tokens_used' => 100]);

        $result = $this->buildService($gemini)->getForPartner($partnerId);

        $this->assertFalse($result['cached']);
        $this->assertSame(0, $result['tokens_used'], 'tokens_used must be 0 for fallback');

        // Fallback opener contains recognisable phrase
        $this->assertStringContainsString('โทรมาติดตาม', $result['payload']['opener']);

        // Counter must NOT be incremented
        $settings = $this->fetchSettings();
        $this->assertSame(0, (int) $settings['gemini_calls_today']);

        // Nothing should be cached
        $this->assertNull(
            $this->fetchCacheRow($partnerId),
            'Fallback result must not be written to cache'
        );
    }

    // ── T4: Cap reached → RuntimeException(429), Gemini not called ───────

    public function testCounterAtCapThrows429WithoutCallingGemini(): void
    {
        $this->db->exec(
            "UPDATE churn_settings
             SET gemini_calls_today = 200, gemini_daily_cap_calls = 200
             WHERE id = 1"
        );

        $gemini = $this->createMock(\GeminiAI::class);
        $gemini->expects($this->never())->method('generateBroadcast');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(429);

        $this->buildService($gemini)->getForPartner(1004);
    }

    // ── T5: Same partner within 24h → second call is cache hit ───────────

    public function testSamePartnerWithin24hSecondCallIsCacheHit(): void
    {
        $partnerId  = 1005;
        $jsonOutput = json_encode(self::VALID_PAYLOAD, JSON_UNESCAPED_UNICODE);

        $gemini = $this->createMock(\GeminiAI::class);
        // Gemini called exactly once — only for the first (miss) call
        $gemini->expects($this->once())
               ->method('generateBroadcast')
               ->willReturn(['success' => true, 'text' => $jsonOutput, 'tokens_used' => 300]);

        $service = $this->buildService($gemini);

        $first  = $service->getForPartner($partnerId);
        $second = $service->getForPartner($partnerId);

        $this->assertFalse($first['cached'],  'First call should be a cache miss');
        $this->assertTrue($second['cached'],  'Second call within 24h should be a cache hit');
    }

    // ── T6: validateOutput rejects missing key ────────────────────────────

    public function testValidateOutputRejectsMissingKey(): void
    {
        $service = $this->buildService($this->createMock(\GeminiAI::class));

        $incomplete = self::VALID_PAYLOAD;
        unset($incomplete['opener']);

        $this->assertFalse($service->validateOutput($incomplete));
    }

    // ── T7: validateOutput rejects empty discovery_questions ─────────────

    public function testValidateOutputRejectsEmptyDiscoveryQuestions(): void
    {
        $service = $this->buildService($this->createMock(\GeminiAI::class));

        $bad                        = self::VALID_PAYLOAD;
        $bad['discovery_questions'] = [];

        $this->assertFalse($service->validateOutput($bad));
    }

    // ── T8: validateOutput accepts null warning ───────────────────────────

    public function testValidateOutputAcceptsNullWarning(): void
    {
        $service = $this->buildService($this->createMock(\GeminiAI::class));

        $payload            = self::VALID_PAYLOAD;
        $payload['warning'] = null;

        $this->assertTrue($service->validateOutput($payload));
    }

    // ── T9: buildPrompt contains guardrails and context values ────────────

    public function testBuildPromptContainsGuardrailsAndContextValues(): void
    {
        $service = $this->buildService($this->createMock(\GeminiAI::class));

        $context = [
            'partner_id'           => 9999,
            'segment'              => 'Lost',
            'previous_segment'     => 'At-Risk',
            'recency_ratio'        => 2.5,
            'lifetime_value'       => 85000.0,
            'is_high_value'        => true,
            'avg_order_cycle_days' => 14.0,
            'last_order_date'      => '2026-02-01',
            'customer_type'        => 'pharmacy',
            'total_orders'         => 30,
            'salesperson_name'     => 'สมชาย',
            'latest_order_at'      => '2026-02-01 09:00:00',
            'spend_total'          => 85000.0,
            'top_skus'             => [
                ['product_name' => 'Paracetamol 500mg', 'order_appearances' => 8, 'qty_total' => 240.0],
            ],
            'recent_orders' => [],
        ];

        $prompt = $service->buildPrompt($context);

        // Guardrail: no fabrication instruction must be present
        $this->assertStringContainsString('ห้ามสร้างชื่อสินค้า', $prompt);

        // Segment name from context
        $this->assertStringContainsString('Lost', $prompt);

        // Real SKU name from context (must appear verbatim)
        $this->assertStringContainsString('Paracetamol 500mg', $prompt);

        // Salesperson name
        $this->assertStringContainsString('สมชาย', $prompt);

        // JSON schema template must be present for structured output
        $this->assertStringContainsString('discovery_questions', $prompt);
    }

    // ── T10: incrementCounter resets stale date before incrementing ───────

    public function testIncrementCounterResetsStaleDate(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $this->db->exec(
            "UPDATE churn_settings
             SET gemini_calls_today = 150, gemini_counter_reset_at = '{$yesterday}'
             WHERE id = 1"
        );

        $service = $this->buildService($this->createMock(\GeminiAI::class));
        $service->incrementCounter();

        $settings = $this->fetchSettings();
        $this->assertSame(1, (int) $settings['gemini_calls_today'],
            'Counter should reset to 1 (not 151) after stale date');
        $this->assertSame(date('Y-m-d'), $settings['gemini_counter_reset_at'],
            'Reset date should be updated to today');
    }

    // ────────────────────── private helpers ──────────────────────────────

    private function buildService(\GeminiAI $gemini): TalkingPointsService
    {
        $contextLoader = $this->buildStubContextLoader();
        return new TalkingPointsService($this->db, $gemini, $contextLoader);
    }

    /**
     * Stub PartnerContextLoader that returns a minimal valid context
     * for any partner_id, without touching any database.
     */
    private function buildStubContextLoader(): PartnerContextLoader
    {
        $stub = $this->createMock(PartnerContextLoader::class);
        $stub->method('loadContext')->willReturn([
            'partner_id'           => 9999,
            'segment'              => 'Lost',
            'previous_segment'     => 'At-Risk',
            'recency_ratio'        => 2.1,
            'lifetime_value'       => 55000.0,
            'is_high_value'        => false,
            'avg_order_cycle_days' => 21.0,
            'last_order_date'      => '2026-01-10',
            'customer_type'        => 'pharmacy',
            'total_orders'         => 15,
            'salesperson_name'     => 'สมชาย ทดสอบ',
            'latest_order_at'      => '2026-01-10 10:00:00',
            'spend_total'          => 55000.0,
            'top_skus'             => [],
            'recent_orders'        => [],
        ]);
        return $stub;
    }

    private function createFixtureTables(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS churn_talking_points_cache (
                odoo_partner_id    INTEGER NOT NULL PRIMARY KEY,
                payload_json       TEXT    NOT NULL,
                generated_at       TEXT    NOT NULL,
                expires_at         TEXT    NOT NULL,
                gemini_tokens_used INTEGER NULL
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS churn_settings (
                id                     INTEGER NOT NULL PRIMARY KEY DEFAULT 1,
                gemini_daily_cap_calls INTEGER NOT NULL DEFAULT 200,
                gemini_calls_today     INTEGER NOT NULL DEFAULT 0,
                gemini_counter_reset_at TEXT   NULL
            )"
        );

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS dev_logs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                log_type   TEXT,
                source     TEXT,
                message    TEXT,
                data       TEXT,
                created_at TEXT
            )"
        );
    }

    private function seedSettings(int $cap, int $usedToday, string $resetAt): void
    {
        $this->db->exec("DELETE FROM churn_settings");
        $stmt = $this->db->prepare(
            "INSERT INTO churn_settings
               (id, gemini_daily_cap_calls, gemini_calls_today, gemini_counter_reset_at)
             VALUES (1, ?, ?, ?)"
        );
        $stmt->execute([$cap, $usedToday, $resetAt]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function seedCache(int $partnerId, array $payload, int $expiresInHours): void
    {
        $expires = date('Y-m-d H:i:s', strtotime("+{$expiresInHours} hours"));
        $stmt    = $this->db->prepare(
            "INSERT OR REPLACE INTO churn_talking_points_cache
               (odoo_partner_id, payload_json, generated_at, expires_at, gemini_tokens_used)
             VALUES (?, ?, datetime('now'), ?, 0)"
        );
        $stmt->execute([$partnerId, json_encode($payload), $expires]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCacheRow(int $partnerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM churn_talking_points_cache
             WHERE odoo_partner_id = ?
             LIMIT 1"
        );
        $stmt->execute([$partnerId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSettings(): array
    {
        $stmt = $this->db->query("SELECT * FROM churn_settings WHERE id = 1 LIMIT 1");
        if ($stmt === false) {
            return [];
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}
