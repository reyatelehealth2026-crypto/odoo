<?php
/**
 * TalkingPointsService — generates and caches an INTERNAL ANALYST BRIEF
 * about a churned customer, for the sales / CS team to follow up on.
 *
 * NOTE (2026-04-27 pivot): Output is no longer a customer-facing script
 * (opener / questions / objections). It is now a structured analysis note
 * summarising ordering behaviour, RFM health signals, risk factors,
 * opportunities, and recommended internal actions. Nothing here is meant
 * to be sent to the customer directly — Sales reads it and decides what
 * to do.
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
 *   - Output is for INTERNAL CRM use only — must NOT be a customer-facing message
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

    /** Required keys in every valid Gemini analyst-brief payload */
    private const REQUIRED_KEYS = [
        'executive_summary',
        'health_signals',
        'behavior_pattern',
        'risk_factors',
        'opportunities',
        'recommended_actions',
        'data_quality_caveats',
        'internal_note_for_sales',
    ];

    /** Allowed severity levels in health_signals[].severity */
    private const SEVERITY_VALUES = ['low', 'medium', 'high', 'critical'];

    /**
     * Fallback analyst brief returned when Gemini output fails schema validation
     * or context is too thin to analyse. Counter is NOT incremented.
     */
    private const FALLBACK_TEMPLATE = [
        'executive_summary'       => 'ข้อมูลลูกค้ารายนี้ยังไม่เพียงพอสำหรับการวิเคราะห์อัตโนมัติ — Sales กรุณาตรวจประวัติการสั่งซื้อด้วยตนเองก่อนตัดสินใจ',
        'health_signals'          => [
            ['label' => 'Recency',   'severity' => 'medium', 'detail' => 'ระบบไม่สามารถประเมินค่า recency ได้ เพราะข้อมูลออเดอร์ไม่ครบ'],
            ['label' => 'Frequency', 'severity' => 'medium', 'detail' => 'ไม่สามารถสรุปรอบสั่งซื้อได้ — ข้อมูลน้อยกว่าที่ควรจะเป็น'],
            ['label' => 'Monetary',  'severity' => 'medium', 'detail' => 'ยอดซื้อสะสมยังประเมินเทียบกับ baseline ไม่ได้'],
        ],
        'behavior_pattern'        => 'ยังไม่พบรูปแบบการสั่งซื้อที่ชัดเจน เนื่องจากบริบทที่โหลดมาไม่ครบ',
        'risk_factors'            => [
            'ข้อมูลออเดอร์/ใบแจ้งหนี้ในระบบไม่พอสำหรับการสรุปสาเหตุ',
        ],
        'opportunities'           => [
            'แนะนำให้ Sales ตรวจสอบสถานะลูกค้ารายนี้ในระบบ Odoo และ LINE inbox ก่อนติดต่อ',
        ],
        'recommended_actions'     => [
            ['priority' => 'P2', 'action' => 'ตรวจประวัติการสั่งซื้อใน odoo-customer-detail.php', 'owner' => 'Sales ที่ดูแล'],
        ],
        'data_quality_caveats'    => [
            'fallback template — Gemini ไม่ได้สร้างผลลัพธ์ใหม่ในรอบนี้ (อาจเพราะ schema fail หรือ quota เต็ม)',
        ],
        'internal_note_for_sales' => 'ข้อมูลลูกค้ารายนี้ยังไม่พอวิเคราะห์อัตโนมัติ ขอให้ Sales เปิดดูประวัติใน Odoo และ inbox ก่อนตัดสินใจติดต่อ',
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
คุณคือนักวิเคราะห์ลูกค้า (Customer Analyst) ภายในของ CNY Wholesale (ขายส่งให้ร้านยา คลินิก โรงพยาบาล)
หน้าที่: สรุปสถานการณ์ลูกค้าจากข้อมูลในระบบเป็น "บันทึกภายใน" ให้ Sales/CS ใช้ตัดสินใจติดตามต่อ
ผลลัพธ์ของคุณ "จะไม่ถูกส่งหาลูกค้า" — เป็นโน้ตภายในเท่านั้น

กฎเหล็ก (ต้องปฏิบัติตามเสมอ):
1. ห้ามสร้างชื่อสินค้า รหัสคูปอง โปรโมชั่น หรือราคาขึ้นเอง — ใช้เฉพาะที่อยู่ในบริบทด้านล่าง
2. ห้ามอ้างข้อมูลส่วนบุคคลนอกเหนือจากที่ให้
3. ห้ามเขียน "บทพูดที่จะส่งหาลูกค้า" หรือ "ข้อความ DM" — ผลลัพธ์เป็นโน้ตวิเคราะห์ภายในล้วน
4. ตอบเป็น JSON เท่านั้น ไม่มี markdown code fence ไม่มีข้อความนำหน้า/ต่อท้าย
5. health_signals ต้องเป็น array ของ object {label, severity, detail} โดย severity ∈ {low, medium, high, critical}
6. recommended_actions ต้องเป็น array ของ object {priority, action, owner} โดย priority ∈ {P1, P2, P3}
7. risk_factors / opportunities / data_quality_caveats เป็น array ของ string ที่ไม่ว่าง
8. ถ้าข้อมูลไม่พอจริง ๆ ให้ระบุไว้ใน data_quality_caveats แทนที่จะเดา
GUARDRAILS;

        $userPrompt = <<<USERPROMPT
วิเคราะห์ลูกค้า CNY Wholesale รายนี้ที่เข้า segment "{$segment}" และสรุปเป็น "บันทึกภายใน" ให้ Sales/CS อ่านเพื่อตัดสินใจติดตามต่อ

--- บริบทลูกค้า (ข้อมูลจริงจากระบบ) ---
ประเภทลูกค้า       : {$custType}
Segment             : {$segment}
Recency Ratio       : {$recencyRatio} (ค่า ≥ 1.5 = ผิดปกติ; ≥ 2.0 = หลุดรอบ; ≥ 3.0 = หายขาด)
รอบสั่งเฉลี่ย      : {$cycledays} วัน
ยอดซื้อสะสม (LTV)  : ฿{$ltv}
จำนวนออเดอร์รวม    : {$totalOrders}
Sales ที่ดูแล       : {$salesperson}

{$skuBlock}
{$orderBlock}
--- งานของคุณ ---
อ่านบริบทด้านบน แล้วเขียนบันทึกวิเคราะห์ตาม schema นี้ (ภาษาไทยกระชับ ตรงประเด็น เน้นสิ่งที่ Sales ใช้ตัดสินใจได้):

{
  "executive_summary": "สรุป 1-2 ประโยค: ลูกค้านี้คือใคร พฤติกรรมเด่น และสถานการณ์ปัจจุบัน",
  "health_signals": [
    {"label": "Recency",   "severity": "low|medium|high|critical", "detail": "อธิบายตัวเลข + เปรียบเทียบกับ baseline ของลูกค้ารายนี้เอง"},
    {"label": "Frequency", "severity": "...", "detail": "..."},
    {"label": "Monetary",  "severity": "...", "detail": "..."}
  ],
  "behavior_pattern": "พฤติกรรมการซื้อ: รอบสั่งซื้อเป็นยังไง basket size ใหญ่/เล็ก มี seasonality หรือไม่ ชอบ SKU ตระกูลไหน",
  "risk_factors": ["สาเหตุ/สมมติฐานที่ทำให้ลูกค้าหาย เช่น เปลี่ยนซัพพลายเออร์ ราคา ปัญหาคุณภาพ ฤดูกาล", "..."],
  "opportunities": ["โอกาสที่เห็นจากข้อมูล เช่น cross-sell SKU X เพราะเคยซื้อ Y ประจำ, หรือ restock seasonal", "..."],
  "recommended_actions": [
    {"priority": "P1|P2|P3", "action": "สิ่งที่ควรทำ 1 ข้อ — ระบุชัดเจน", "owner": "Sales/CS/Manager/Marketing"},
    {"priority": "...", "action": "...", "owner": "..."}
  ],
  "data_quality_caveats": ["ข้อจำกัดของข้อมูลที่ใช้วิเคราะห์ เช่น 'ไม่มีข้อมูล product_stats', 'ออเดอร์ < 5 รายการ', 'ขาดข้อมูลใบแจ้งหนี้'"],
  "internal_note_for_sales": "บันทึกย่อ 2-4 ประโยค ภาษาธรรมชาติ พร้อมส่งต่อให้ Sales ที่ดูแลอ่านเพื่อรู้ว่าต้องโฟกัสอะไร — เน้น actionable ไม่ใช่บทพูด"
}
USERPROMPT;

        return $guardrails . "\n\n" . $userPrompt;
    }

    /**
     * Validate the analyst-brief shape returned by Gemini.
     *
     * Schema (all keys required):
     *   - executive_summary       : non-empty string
     *   - health_signals          : non-empty array of {label:string, severity:enum, detail:string}
     *   - behavior_pattern        : non-empty string
     *   - risk_factors            : non-empty array of non-empty strings
     *   - opportunities           : non-empty array of non-empty strings
     *   - recommended_actions     : non-empty array of {priority:string, action:string, owner:string}
     *   - data_quality_caveats    : array of strings (may be empty array, but values non-empty)
     *   - internal_note_for_sales : non-empty string
     *
     * @param array<string, mixed> $payload
     */
    public function validateOutput(array $payload): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $payload)) {
                return false;
            }
        }

        // 1. Plain non-empty strings
        foreach (['executive_summary', 'behavior_pattern', 'internal_note_for_sales'] as $key) {
            if (!is_string($payload[$key]) || trim($payload[$key]) === '') {
                return false;
            }
        }

        // 2. health_signals — non-empty array of {label, severity ∈ enum, detail}
        if (!is_array($payload['health_signals']) || empty($payload['health_signals'])) {
            return false;
        }
        foreach ($payload['health_signals'] as $sig) {
            if (!is_array($sig)) {
                return false;
            }
            foreach (['label', 'severity', 'detail'] as $f) {
                if (!isset($sig[$f]) || !is_string($sig[$f]) || trim($sig[$f]) === '') {
                    return false;
                }
            }
            if (!in_array(strtolower((string) $sig['severity']), self::SEVERITY_VALUES, true)) {
                return false;
            }
        }

        // 3. recommended_actions — non-empty array of {priority, action, owner}
        if (!is_array($payload['recommended_actions']) || empty($payload['recommended_actions'])) {
            return false;
        }
        foreach ($payload['recommended_actions'] as $act) {
            if (!is_array($act)) {
                return false;
            }
            foreach (['priority', 'action', 'owner'] as $f) {
                if (!isset($act[$f]) || !is_string($act[$f]) || trim($act[$f]) === '') {
                    return false;
                }
            }
        }

        // 4. risk_factors / opportunities — non-empty arrays of non-empty strings
        foreach (['risk_factors', 'opportunities'] as $key) {
            if (!is_array($payload[$key]) || empty($payload[$key])) {
                return false;
            }
            foreach ($payload[$key] as $item) {
                if (!is_string($item) || trim($item) === '') {
                    return false;
                }
            }
        }

        // 5. data_quality_caveats — array of strings (may be empty array; values must be non-empty if present)
        if (!is_array($payload['data_quality_caveats'])) {
            return false;
        }
        foreach ($payload['data_quality_caveats'] as $item) {
            if (!is_string($item) || trim($item) === '') {
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
