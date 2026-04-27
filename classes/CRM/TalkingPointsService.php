<?php
/**
 * TalkingPointsService — generates and caches AI talking points for churned customers.
 *
 * Called by:
 *   - api/churn-talking-points.php  (~line 80, $service->getForPartner($partnerId))
 *
 * Cache flow (spec §6.4, §9):
 *   1. SELECT FROM churn_talking_points_cache WHERE odoo_partner_id=? AND expires_at > NOW()
 *   2. Hit  → return payload_json (cached=true)
 *   3. Miss → check daily cap, call Gemini, validate schema,
 *             INSERT/REPLACE cache row (TTL 24h), increment counter, return (cached=false)
 *
 * Guardrails in prompt (spec §9 safety):
 *   - No fabricated product/SKU names beyond what context provides
 *   - No invented discount codes or pricing figures
 *   - No PII beyond what is already in DB context
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §6.4, §9
 */

declare(strict_types=1);

namespace Classes\CRM;

class TalkingPointsService
{
    private \PDO $db;
    private \GeminiAI $gemini;
    private PartnerContextLoader $contextLoader;

    /** Required keys in every valid Gemini payload */
    private const REQUIRED_KEYS = [
        'opener',
        'context_reminder',
        'discovery_questions',
        'objection_handlers',
        'next_best_offer',
        'warning',
    ];

    /**
     * Fallback template returned when Gemini output fails schema validation.
     * Counter is NOT incremented for fallback responses.
     */
    private const FALLBACK_TEMPLATE = [
        'opener'              => 'สวัสดีครับ ผมโทรมาติดตามสถานะและสอบถามความต้องการของทางร้านครับ',
        'context_reminder'    => 'ลูกค้ารายนี้ยังไม่มีข้อมูล talking points เพียงพอ กรุณาตรวจสอบประวัติการสั่งซื้อก่อนโทร',
        'discovery_questions' => [
            'ช่วงนี้ทางร้านมีความต้องการสินค้าใดเป็นพิเศษไหมครับ?',
            'มีปัญหาหรือข้อติดขัดใดๆ จากออเดอร์ครั้งก่อนไหมครับ?',
        ],
        'objection_handlers'  => [
            'ถ้าบอกว่าไม่ต้องการ: รับทราบครับ ขอโทษที่รบกวน จะโทรกลับมาในภายหลังนะครับ',
        ],
        'next_best_offer'     => 'ไม่มีข้อเสนอพิเศษในขณะนี้ กรุณาติดต่อฝ่ายการตลาด',
        'warning'             => 'ข้อมูลไม่เพียงพอสำหรับการสร้าง talking points อัตโนมัติ',
    ];

    public function __construct(
        \PDO $db,
        \GeminiAI $gemini,
        PartnerContextLoader $contextLoader
    ) {
        $this->db            = $db;
        $this->gemini        = $gemini;
        $this->contextLoader = $contextLoader;
    }

    /**
     * Return cached or freshly generated talking points for a partner.
     *
     * @return array{payload: array<string, mixed>, cached: bool, tokens_used: int}
     * @throws \RuntimeException with code 429 when daily cap is reached
     */
    public function getForPartner(int $partnerId): array
    {
        // 1. Cache lookup — return immediately on hit
        $cached = $this->fetchFromCache($partnerId);
        if ($cached !== null) {
            return ['payload' => $cached, 'cached' => true, 'tokens_used' => 0];
        }

        // 2. Daily cap check (before calling Gemini to avoid wasted calls)
        $this->assertCapNotReached();

        // 3. Load context and build prompt
        $context = $this->contextLoader->loadContext($partnerId);
        $prompt  = $this->buildPrompt($context);

        // 4. Call Gemini
        $geminiResult = $this->callGemini($prompt);
        $rawText      = $geminiResult['text'];
        $tokensUsed   = $geminiResult['tokens_used'];

        // 5. Parse and validate output
        $payload = $this->parseJson($rawText);
        if ($payload !== null && $this->validateOutput($payload)) {
            $this->writeCache($partnerId, $payload, $tokensUsed);
            $this->incrementCounter();
            return ['payload' => $payload, 'cached' => false, 'tokens_used' => $tokensUsed];
        }

        // 6. Invalid output — fallback template; counter NOT incremented; not cached
        $this->logToDevLogs(
            'warn',
            'talking_points_invalid_output',
            "Partner {$partnerId}: Gemini output failed schema validation — using fallback",
            ['partner_id' => $partnerId, 'raw_snippet' => substr($rawText, 0, 300)]
        );

        return ['payload' => self::FALLBACK_TEMPLATE, 'cached' => false, 'tokens_used' => 0];
    }

    /**
     * Build system + user prompt with safety guardrails.
     *
     * @param array<string, mixed> $context output of PartnerContextLoader::loadContext()
     */
    public function buildPrompt(array $context): string
    {
        $segment      = (string) ($context['segment'] ?? 'Unknown');
        $recencyRatio = $context['recency_ratio'] !== null
            ? round((float) $context['recency_ratio'], 2)
            : 'N/A';
        $ltv          = number_format((float) ($context['lifetime_value'] ?? 0), 0);
        $cycledays    = $context['avg_order_cycle_days'] !== null
            ? ((int) $context['avg_order_cycle_days']) . ' วัน'
            : 'ไม่ทราบ';
        $salesperson  = (string) ($context['salesperson_name'] ?? 'ทีมขาย');
        $custType     = (string) ($context['customer_type'] ?? 'other');
        $totalOrders  = (int) ($context['total_orders'] ?? 0);

        // Top SKUs — only names that come from the DB (no fabrication)
        $skuBlock = '';
        $topSkus  = $context['top_skus'] ?? [];
        if (!empty($topSkus)) {
            $skuBlock = "สินค้าที่ซื้อบ่อย (90 วันที่แล้ว):\n";
            foreach ($topSkus as $i => $sku) {
                $rank      = $i + 1;
                $name      = htmlspecialchars((string) ($sku['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $qty       = (float) ($sku['qty_total'] ?? 0);
                $skuBlock .= "  {$rank}. {$name} (รวม {$qty} หน่วย)\n";
            }
        } else {
            $skuBlock = "ไม่มีข้อมูลสินค้าในช่วง 90 วันที่แล้ว\n";
        }

        // Recent orders
        $orderBlock   = '';
        $recentOrders = $context['recent_orders'] ?? [];
        if (!empty($recentOrders)) {
            $orderBlock = "ออเดอร์ล่าสุด:\n";
            foreach ($recentOrders as $ord) {
                $oName      = htmlspecialchars((string) ($ord['order_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oDate      = (string) ($ord['date_order'] ?? '');
                $oAmt       = number_format((float) ($ord['amount_total'] ?? 0), 0);
                $oState     = (string) ($ord['state'] ?? '');
                $orderBlock .= "  - {$oName} ({$oDate}) ฿{$oAmt} [{$oState}]\n";
            }
        } else {
            $orderBlock = "ไม่มีข้อมูลออเดอร์\n";
        }

        $guardrails = <<<GUARDRAILS
คุณเป็นผู้ช่วย Sales ของ CNY Wholesale ที่ขายส่งให้ร้านยา คลินิก และโรงพยาบาล
กฎเหล็ก (ต้องปฏิบัติตามเสมอ):
1. ห้ามสร้างชื่อสินค้าขึ้นมาเอง — ใช้เฉพาะชื่อสินค้าที่ระบุในบริบทด้านล่างเท่านั้น
2. ห้ามสร้างรหัสส่วนลด โปรโมชั่น หรือราคาที่ไม่มีในบริบท
3. ห้ามระบุข้อมูลส่วนตัวนอกเหนือจากที่ให้ไว้
4. ตอบเป็น JSON เท่านั้น ไม่มี markdown code block
5. discovery_questions และ objection_handlers ต้องเป็น array ของ string ที่ไม่ว่าง
GUARDRAILS;

        $userPrompt = <<<USERPROMPT
สร้าง talking points สำหรับ Sales โทรหาลูกค้า CNY Wholesale ที่เข้า segment "{$segment}"

--- บริบทลูกค้า ---
ประเภทลูกค้า       : {$custType}
Recency Ratio       : {$recencyRatio}
รอบสั่งเฉลี่ย      : {$cycledays}
ยอดซื้อสะสม (LTV)  : ฿{$ltv}
จำนวนออเดอร์รวม    : {$totalOrders}
Sales ที่ดูแล       : {$salesperson}

{$skuBlock}
{$orderBlock}
--- รูปแบบ JSON ที่ต้องการ ---
{
  "opener": "ประโยคเปิดการสนทนา (1-2 ประโยค)",
  "context_reminder": "สรุปสิ่งที่ Sales ควรรู้ก่อนโทร",
  "discovery_questions": ["คำถาม 1", "คำถาม 2", "คำถาม 3"],
  "objection_handlers": ["วิธีรับมือ 1", "วิธีรับมือ 2"],
  "next_best_offer": "ข้อเสนอถัดไป (หากไม่มีข้อมูลให้บอกว่าติดต่อฝ่ายการตลาด)",
  "warning": "ข้อควรระวัง หรือ null ถ้าไม่มี"
}
USERPROMPT;

        return $guardrails . "\n\n" . $userPrompt;
    }

    /**
     * Validate all required keys are present with correct non-empty values.
     *
     * @param array<string, mixed> $payload
     */
    public function validateOutput(array $payload): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                return false;
            }

            $value = $payload[$key];

            if (in_array($key, ['discovery_questions', 'objection_handlers'], true)) {
                if (!is_array($value) || empty($value)) {
                    return false;
                }
                foreach ($value as $item) {
                    if (!is_string($item) || trim($item) === '') {
                        return false;
                    }
                }
                continue;
            }

            // 'warning' may be null or empty string — that is acceptable
            if ($key === 'warning') {
                continue;
            }

            if (!is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Atomic daily counter increment with automatic reset.
     *
     * Uses PHP date() for today's date — works on both MySQL and SQLite.
     * A single parameterised UPDATE avoids concurrent-write races on MySQL InnoDB.
     */
    public function incrementCounter(): void
    {
        $today = date('Y-m-d');
        $stmt  = $this->db->prepare(
            "UPDATE churn_settings
             SET
               gemini_calls_today = CASE
                 WHEN gemini_counter_reset_at IS NULL
                   OR gemini_counter_reset_at < ?
                 THEN 1
                 ELSE gemini_calls_today + 1
               END,
               gemini_counter_reset_at = CASE
                 WHEN gemini_counter_reset_at IS NULL
                   OR gemini_counter_reset_at < ?
                 THEN ?
                 ELSE gemini_counter_reset_at
               END
             WHERE id = 1"
        );
        $stmt->execute([$today, $today, $today]);
    }

    // ────────────────────── private helpers ──────────────────────

    /**
     * Fetch a valid non-expired cache row. Returns null on cache miss.
     *
     * Uses PHP datetime for expiry comparison — works on both MySQL and SQLite.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFromCache(int $partnerId): ?array
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "SELECT payload_json
             FROM churn_talking_points_cache
             WHERE odoo_partner_id = ?
               AND expires_at > ?
             LIMIT 1"
        );
        $stmt->execute([$partnerId, $now]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $decoded = json_decode((string) $row['payload_json'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write (INSERT OR REPLACE) cache row with 24-hour TTL.
     *
     * Uses PHP datetime strings — works on both MySQL and SQLite.
     *
     * @param array<string, mixed> $payload
     */
    private function writeCache(int $partnerId, array $payload, int $tokensUsed): void
    {
        $now     = date('Y-m-d H:i:s');
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $stmt    = $this->db->prepare(
            "REPLACE INTO churn_talking_points_cache
               (odoo_partner_id, payload_json, generated_at, expires_at, gemini_tokens_used)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $partnerId,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $now,
            $expires,
            $tokensUsed,
        ]);
    }

    /**
     * Read churn_settings and throw RuntimeException(429) if cap is reached.
     *
     * Handles stale counter (date from previous day treated as 0 usage).
     *
     * @throws \RuntimeException with code 429
     */
    private function assertCapNotReached(): void
    {
        $stmt = $this->db->query(
            "SELECT gemini_daily_cap_calls, gemini_calls_today, gemini_counter_reset_at
             FROM churn_settings
             WHERE id = 1
             LIMIT 1"
        );

        if ($stmt === false) {
            return; // No settings row — no cap enforced
        }

        $settings = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($settings === false) {
            return;
        }

        $cap         = (int) ($settings['gemini_daily_cap_calls'] ?? 200);
        $resetDate   = (string) ($settings['gemini_counter_reset_at'] ?? '');
        $storedCount = (int) ($settings['gemini_calls_today'] ?? 0);
        $todayDate   = date('Y-m-d');

        // Counter from a prior day is effectively 0
        $effective = ($resetDate === $todayDate) ? $storedCount : 0;

        if ($effective >= $cap) {
            throw new \RuntimeException(
                "Daily Gemini cap reached ({$effective}/{$cap}). Try again tomorrow.",
                429
            );
        }
    }

    /**
     * Invoke GeminiAI and normalise the response.
     *
     * Adapts to the existing GeminiAI::generateBroadcast($topic, $tone, $target)
     * signature (classes/GeminiAI.php). The full prompt is passed as $topic so
     * no new method is needed on GeminiAI.
     *
     * @return array{text: string, tokens_used: int}
     * @throws \RuntimeException on API failure
     */
    private function callGemini(string $prompt): array
    {
        $result = $this->gemini->generateBroadcast($prompt, 'professional', 'B2B sales');

        if (!isset($result['success']) || $result['success'] !== true) {
            $errMsg = (string) ($result['error'] ?? 'Gemini API returned failure');
            throw new \RuntimeException("Gemini call failed: {$errMsg}");
        }

        return [
            'text'        => (string) ($result['text'] ?? ''),
            'tokens_used' => (int) ($result['tokens_used'] ?? 0),
        ];
    }

    /**
     * Parse a JSON string from Gemini's response, stripping markdown fences.
     *
     * @return array<string, mixed>|null
     */
    private function parseJson(string $raw): ?array
    {
        // Strip ```json ... ``` or ``` ... ```
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $cleaned = preg_replace('/\s*```$/', '', (string) $cleaned);

        // Extract first {...} block if prose surrounds the JSON
        if (preg_match('/\{.*\}/s', (string) $cleaned, $matches)) {
            $cleaned = $matches[0];
        }

        $decoded = json_decode((string) $cleaned, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write a structured audit entry to dev_logs.
     *
     * Failures here must never propagate — log to error_log as last resort.
     *
     * @param array<string, mixed> $data
     */
    private function logToDevLogs(
        string $logType,
        string $source,
        string $message,
        array $data = []
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO dev_logs (log_type, source, message, data, created_at)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $logType,
                $source,
                $message,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            error_log("dev_logs write failed in TalkingPointsService: " . $e->getMessage());
        }
    }
}
