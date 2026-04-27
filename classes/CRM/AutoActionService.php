<?php

/**
 * AutoActionService — shared logic for churn auto-action cron jobs.
 *
 * Spec: docs/plans/2026-04-27-customer-churn-tracker.md §8 Phase 4-5, §9
 *
 * Callers:
 *   cron/churn_auto_checkin.php  — shouldRun, frequencyCapAllows, enqueueCallLog
 *   cron/churn_assign_sales.php  — shouldRun, frequencyCapAllows, enqueueCallLog,
 *                                   resolveLineUserId, pushLineText
 *   cron/churn_escalate.php      — shouldRun, frequencyCapAllows, enqueueCallLog,
 *                                   loadSettings, devLog, pushLineText
 *
 * This class is intentionally stateless: it holds no per-request mutable state
 * beyond the injected PDO handle. All writes go through enqueueCallLog() which
 * is the single insert path for customer_call_log.
 *
 * NotificationRouter note: NotificationRouter is designed for order-event routing
 * and would no-op for unknown event types. For the churn notification use-case we
 * push plain LINE text directly via the LINE push API (same token fallback logic
 * that NotificationRouter->findLineUser() already uses). This is documented as a
 * clean mock boundary per the worker spec.
 */

declare(strict_types=1);

namespace Classes\CRM;

use PDO;

final class AutoActionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Guard helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns true when the churn system is fully operational.
     * Crons must call this first and exit immediately if it returns false.
     *
     * Rules (spec §9):
     *   system_enabled = 1  AND  soft_launch = 0
     */
    public function shouldRun(): bool
    {
        try {
            $stmt = $this->db->query(
                'SELECT system_enabled, soft_launch FROM churn_settings WHERE id = 1 LIMIT 1'
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return false;
            }
            return ((int) $row['system_enabled'] === 1)
                && ((int) $row['soft_launch']     === 0);
        } catch (\Throwable $e) {
            error_log('[AutoActionService] shouldRun DB error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns true when enough time has elapsed since the last call-log entry
     * for this partner so that we may create a new one.
     *
     * @param int $partnerId   odoo_partner_id
     * @param int $capDays     minimum days between entries (from churn_settings)
     */
    public function frequencyCapAllows(int $partnerId, int $capDays): bool
    {
        if ($capDays <= 0) {
            return true;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT called_at FROM customer_call_log
                  WHERE odoo_partner_id = ?
                  ORDER BY called_at DESC
                  LIMIT 1'
            );
            $stmt->execute([$partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return true; // No prior contact — allowed.
            }
            $lastCalled    = new \DateTimeImmutable($row['called_at']);
            $now           = new \DateTimeImmutable('now');
            $daysSinceLast = (int) $now->diff($lastCalled)->days;
            return $daysSinceLast >= $capDays;
        } catch (\Throwable $e) {
            error_log('[AutoActionService] frequencyCapAllows DB error: ' . $e->getMessage());
            // Fail-open: do not block on DB error; let the caller decide.
            return true;
        }
    }

    /**
     * Insert one row into customer_call_log (the single insert path).
     *
     * outcome is always NULL on insertion (= queued for admin approval).
     * Admin UI releases the queue by setting outcome.
     *
     * @param int         $partnerId   odoo_partner_id
     * @param string      $channel     ENUM: 'phone','line','email','visit','other'
     * @param string      $segment     current_segment value at time of log
     * @param string|null $notes       free-text; max TEXT column limit
     * @param int|null    $adminId     NULL for auto-generated rows
     * @param string|null $calledAt    'Y-m-d H:i:s'; NULL defaults to NOW()
     *
     * @return int|null   Inserted row ID, or null on error.
     */
    public function enqueueCallLog(
        int $partnerId,
        string $channel,
        string $segment,
        ?string $notes = null,
        ?int $adminId = null,
        ?string $calledAt = null
    ): ?int {
        $validChannels = ['phone', 'line', 'email', 'visit', 'other'];
        if (!in_array($channel, $validChannels, true)) {
            error_log("[AutoActionService] Invalid channel '{$channel}' — defaulting to 'other'");
            $channel = 'other';
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO customer_call_log
                    (odoo_partner_id, admin_id, channel, outcome, notes,
                     segment_at_call, called_at, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
            );
            $ts = $calledAt ?? date('Y-m-d H:i:s');
            $stmt->execute([
                $partnerId,
                $adminId,
                $channel,
                $notes,
                $segment,
                $ts,
                date('Y-m-d H:i:s'),
            ]);
            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log(
                '[AutoActionService] enqueueCallLog failed for partner '
                . $partnerId . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Settings loader
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load the single churn_settings row.
     *
     * @return array<string, mixed>|null
     */
    public function loadSettings(): ?array
    {
        try {
            $stmt = $this->db->query(
                'SELECT * FROM churn_settings WHERE id = 1 LIMIT 1'
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($row !== false) ? $row : null;
        } catch (\Throwable $e) {
            error_log('[AutoActionService] loadSettings DB error: ' . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // dev_logs helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write an info row to dev_logs.
     *
     * Schema: log_type VARCHAR, source VARCHAR, message TEXT,
     *         data JSON, created_at DATETIME.
     *
     * @param string              $source  cron file name, e.g. 'churn_auto_checkin'
     * @param string              $message human-readable summary
     * @param array<string,mixed> $data    arbitrary context (JSON-encoded)
     */
    public function devLog(string $source, string $message, array $data = []): void
    {
        $this->writeDevLog('info', $source, $message, $data);
    }

    /**
     * Write an error row to dev_logs.
     */
    public function devLogError(string $source, string $message, array $data = []): void
    {
        $this->writeDevLog('error', $source, $message, $data);
    }

    private function writeDevLog(
        string $logType,
        string $source,
        string $message,
        array $data
    ): void {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO dev_logs (log_type, source, message, data, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $logType,
                $source,
                $message,
                json_encode($data, JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[AutoActionService] writeDevLog failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LINE push helper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a plain-text LINE push message to a line_user_id.
     *
     * Token resolution: we fall back to the first active line_accounts row —
     * identical to NotificationRouter->findLineUser() fallback (line 388).
     * This is intentional: churn notifications are account-agnostic.
     *
     * Returns true on HTTP 2xx, false otherwise.
     */
    public function pushLineText(string $lineUserId, string $text): bool
    {
        try {
            $stmt = $this->db->query(
                "SELECT channel_access_token FROM line_accounts
                  WHERE channel_access_token IS NOT NULL
                    AND channel_access_token != ''
                  LIMIT 1"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                error_log('[AutoActionService] pushLineText: no active line_accounts token');
                return false;
            }
            $token = $row['channel_access_token'];
            $body  = json_encode([
                'to'       => $lineUserId,
                'messages' => [['type' => 'text', 'text' => $text]],
            ]);
            $ch = curl_init('https://api.line.me/v2/bot/message/push');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT        => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode < 200 || $httpCode >= 300) {
                error_log(
                    "[AutoActionService] pushLineText HTTP {$httpCode}: {$response}"
                );
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            error_log('[AutoActionService] pushLineText exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve LINE user_id for an odoo_partner_id via odoo_line_users bridge.
     *
     * Per spec §13.1: odoo_line_users is the authoritative bridge table.
     * UNIQUE on line_user_id, INDEX on odoo_partner_id.
     *
     * @return string|null
     */
    public function resolveLineUserId(int $partnerId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT line_user_id FROM odoo_line_users
                  WHERE odoo_partner_id = ?
                  LIMIT 1'
            );
            $stmt->execute([$partnerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return ($row && !empty($row['line_user_id']))
                ? (string) $row['line_user_id']
                : null;
        } catch (\Throwable $e) {
            error_log('[AutoActionService] resolveLineUserId error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve salesperson LINE user_id from odoo_customer_projection.
     *
     * salesperson_id in odoo_customer_projection is an Odoo res.users ID.
     * We join to odoo_line_users.odoo_partner_id assuming salesperson partner_id
     * is stored there. If not found we fall back to null and the cron logs it.
     *
     * @return string|null
     */
    public function resolveSalespersonLineUserId(int $salespersonPartnerId): ?string
    {
        // Salesperson partner_id is the same join key as customer partner_id in
        // odoo_line_users — they are both Odoo res.partner IDs.
        return $this->resolveLineUserId($salespersonPartnerId);
    }
}
