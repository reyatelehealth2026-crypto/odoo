<?php
/**
 * InboxSentinel — context-aware Thai sentiment classifier for LINE inbox messages.
 *
 * Ported from the historical-scan Python prototype proven on 23,291 messages
 * (2026-01-10 → 2026-04-28) which surfaced 157 P1 complaints, 8 P2 dissatisfied,
 * and 15 P0 critical-overlap customers (inbox complaint + churn-flag).
 *
 * Called by:
 *   - api/churn-inbox-issues.php   (per-partner issue enrichment for dashboard)
 *   - customer-churn.php           (server-side watchlist row enrichment)
 *
 * Two surfaces:
 *   1. classify(string $text): ?string
 *      Pure function. Returns 'red' (complaint) / 'orange' (dissatisfied) /
 *      'yellow' (follow-up) / 'yellow_urgent' / 'green' / null.
 *      Context-aware: "หมดอายุเมื่อไร" (question) is NOT a complaint;
 *      "ใกล้หมดอายุ / ของหมดอายุ / หมดอายุแล้ว" IS.
 *
 *   2. getInboxFlagsForPartners(array $partnerIds, int $daysBack): array
 *      Read-only DB scan. Returns map partner_id → flag summary
 *      {severity, count, latest_at, latest_text} for partners that have any
 *      red/orange/yellow_urgent flag in the lookback window.
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md (cross-reference layer)
 */

declare(strict_types=1);

namespace Classes\CRM;

class InboxSentinel
{
    /** Severity ranking — lower number = higher priority. */
    public const SEVERITY_RANK = [
        'red'           => 1,
        'orange'        => 2,
        'yellow_urgent' => 3,
        'yellow'        => 4,
        'green'         => 6,
    ];

    /** Real complaints. */
    private const RED_PATTERNS = [
        'ส่งผิด', 'ส่งของผิด', 'ผิดยา', 'ผิดสินค้า', 'ผิดรุ่น',
        'เสียหาย', 'ของเสีย', 'ชำรุด',
        'ใกล้หมดอายุ', 'ของหมดอายุ', 'หมดอายุแล้ว', 'ตัวหมดอายุ', 'ที่หมดอายุ', 'หมดอายุก่อน',
        'ไม่ตรง', 'ไม่ถึง', 'ไม่ได้รับ', 'ของหาย', 'ของขาด',
        'ขอยกเลิก', 'แจ้งยกเลิก', 'คืนสินค้า', 'ขอคืนเงิน', 'เคลม',
        'ร้องเรียน', 'อย\.',
    ];

    /** Excluded — "หมดอายุเมื่อไร" is a benign expiry question. */
    private const EXPIRY_QUESTION_RE  = '/หมดอายุ\s*เมื่อไ[หร]/u';
    /** Genuine expiry complaint markers (override the question carve-out). */
    private const EXPIRY_COMPLAINT_RE = '/(ใกล้หมดอายุ|ของหมดอายุ|หมดอายุแล้ว|ตัวหมดอายุ)/u';

    /** Customer dissatisfaction. */
    private const ORANGE_PATTERNS = [
        'ไม่พอใจ', 'ผิดหวัง', 'น้อยใจ', 'โกรธ', 'หงุดหงิด', 'รำคาญ',
        'แย่มาก', 'ช้ามาก', 'นานมาก', 'ทำไมช้า', 'ทำไมไม่ตอบ', 'รอนาน',
        'เมื่อไหร่จะได้', 'ไม่ได้สักที',
    ];

    /** Follow-up / chasing. */
    private const YELLOW_PATTERNS = [
        'ตามผล', 'ตามสินค้า', 'ของยังไม่', 'ยังไม่ได้ของ', 'ยังไม่ส่ง',
        'ส่งไม่ครบ', 'ขาดอยู่', 'เลขพัสดุ', 'อัพเดท', 'อัปเดต',
        'ขอเปลี่ยน', 'ขอเพิ่ม', 'หักลบ',
    ];

    private const URGENT_RE   = '/(ด่วน|เร่ง|รีบ|ทันไหม)/u';
    private const POSITIVE_RE = '/(ขอบคุณ|ดีมาก|ถูกใจ|รักเลย|เยี่ยม|ประทับใจ|ไวมาก|ดีใจ|น่ารัก)/u';

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Classify a single Thai inbox message.
     * @return string|null  'red' | 'orange' | 'yellow_urgent' | 'yellow' | 'green' | null
     */
    public function classify(string $text): ?string
    {
        if ($text === '' || mb_strlen($text) < 4) {
            return null;
        }

        $isExpiryQuestion = (bool) preg_match(self::EXPIRY_QUESTION_RE, $text);
        $hasRed = $this->matchesAny($text, self::RED_PATTERNS);

        if ($hasRed && $isExpiryQuestion) {
            return preg_match(self::EXPIRY_COMPLAINT_RE, $text) ? 'red' : null;
        }
        if ($hasRed) {
            return 'red';
        }
        if ($this->matchesAny($text, self::ORANGE_PATTERNS)) {
            return 'orange';
        }
        if ($this->matchesAny($text, self::YELLOW_PATTERNS)) {
            return preg_match(self::URGENT_RE, $text) ? 'yellow_urgent' : 'yellow';
        }
        if (preg_match(self::URGENT_RE, $text)) {
            return 'yellow_urgent';
        }
        if (preg_match(self::POSITIVE_RE, $text)) {
            return 'green';
        }
        return null;
    }

    /**
     * Scan recent incoming messages for the given Odoo partners; return per-partner
     * worst-severity summary plus latest evidence message.
     *
     * @param int[] $partnerIds
     * @return array<int, array{severity:string, severity_rank:int, count:int, latest_at:string, latest_text:string}>
     */
    public function getInboxFlagsForPartners(array $partnerIds, int $daysBack = 30): array
    {
        $partnerIds = array_values(array_filter(
            array_map('intval', $partnerIds),
            static fn (int $v): bool => $v > 0
        ));
        if ($partnerIds === []) {
            return [];
        }
        $daysBack = max(1, min(365, $daysBack));

        // Pre-compute cutoff in PHP so the SQL is portable to SQLite test fixtures
        // (SQLite does not support `INTERVAL ? DAY`). MySQL/MariaDB compares
        // DATETIME against an ISO string just fine.
        $cutoff = (new \DateTimeImmutable('-' . $daysBack . ' days'))->format('Y-m-d H:i:s');

        $placeholders = implode(',', array_fill(0, count($partnerIds), '?'));
        $sql = "
            SELECT m.id, m.created_at, m.content,
                   CAST(c.partner_id AS UNSIGNED) AS partner_id
            FROM   messages m
            JOIN   users u                ON u.id = m.user_id
            JOIN   odoo_customers_cache c ON c.line_user_id = u.line_user_id
            WHERE  m.direction = 'incoming'
              AND  m.message_type = 'text'
              AND  m.content IS NOT NULL
              AND  m.content != ''
              AND  m.created_at >= ?
              AND  CAST(c.partner_id AS UNSIGNED) IN ($placeholders)
            ORDER BY m.created_at DESC
        ";

        $params = array_merge([$cutoff], $partnerIds);
        $stmt   = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $cat = $this->classify((string) $row['content']);
            if ($cat === null || $cat === 'green') {
                continue;
            }
            $pid  = (int) $row['partner_id'];
            $rank = self::SEVERITY_RANK[$cat] ?? 99;

            if (!isset($out[$pid])) {
                $out[$pid] = [
                    'severity'      => $cat,
                    'severity_rank' => $rank,
                    'count'         => 1,
                    'latest_at'     => (string) $row['created_at'],
                    'latest_text'   => mb_substr((string) $row['content'], 0, 240),
                ];
                continue;
            }

            $existing = $out[$pid];
            $existing['count']++;
            if ($rank < $existing['severity_rank']) {
                $existing['severity']      = $cat;
                $existing['severity_rank'] = $rank;
                $existing['latest_at']     = (string) $row['created_at'];
                $existing['latest_text']   = mb_substr((string) $row['content'], 0, 240);
            }
            $out[$pid] = $existing;
        }

        return $out;
    }

    /**
     * Pull the recent two-way conversation between a customer and the team.
     *
     * Joins outgoing messages with their admin sender so Sales/CS can see who
     * said what. Each message is annotated with classify() so the UI can show
     * sentiment per message.
     *
     * @return array<int, array{
     *   id:int, direction:string, message_type:string, content:string,
     *   sent_by:?string, created_at:string, classification:?string
     * }>  Oldest → newest within the window.
     */
    public function getConversation(int $partnerId, int $daysBack = 30, int $limit = 100): array
    {
        if ($partnerId <= 0) {
            return [];
        }
        $daysBack = max(1, min(365, $daysBack));
        $limit    = max(1, min(500, $limit));
        $cutoff   = (new \DateTimeImmutable('-' . $daysBack . ' days'))->format('Y-m-d H:i:s');

        // Step 1: resolve user_id from partner_id via users + odoo_customers_cache
        $stmt = $this->db->prepare("
            SELECT u.id AS user_id
            FROM   odoo_customers_cache c
            JOIN   users u ON u.line_user_id = c.line_user_id
            WHERE  CAST(c.partner_id AS UNSIGNED) = ?
            LIMIT 1
        ");
        $stmt->execute([$partnerId]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
        if ($userId <= 0) {
            return [];
        }

        // Step 2: pull both directions, oldest first
        $stmt = $this->db->prepare("
            SELECT id, direction, message_type, content, sent_by, created_at
            FROM   messages
            WHERE  user_id = ?
              AND  created_at >= ?
              AND  message_type IN ('text','image','sticker','file','flex','video','audio','location')
            ORDER BY created_at ASC
            LIMIT  ?
        ");
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $cutoff, \PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out  = [];
        foreach ($rows as $row) {
            $content = (string) ($row['content'] ?? '');
            $type    = (string) ($row['message_type'] ?? 'text');
            $cls     = ($type === 'text' && $row['direction'] === 'incoming')
                ? $this->classify($content)
                : null;
            $out[] = [
                'id'             => (int) $row['id'],
                'direction'      => (string) $row['direction'],
                'message_type'   => $type,
                'content'        => $content,
                'sent_by'        => $row['sent_by'] !== null ? (string) $row['sent_by'] : null,
                'created_at'     => (string) $row['created_at'],
                'classification' => $cls,
            ];
        }
        return $out;
    }

    private function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (preg_match('/' . $p . '/u', $text) === 1) {
                return true;
            }
        }
        return false;
    }
}
