<?php
/**
 * BDO Context Manager
 *
 * Manages the per-customer BDO context stored in odoo_bdo_context.
 * This context is used to auto-populate bdo_id when a customer sends a slip
 * without explicitly referencing a BDO.
 *
 * KEY DESIGN DECISIONS:
 *  - One row per (line_user_id, bdo_id) — a customer can have multiple open BDOs.
 *  - Context is OPENED on bdo.confirmed and CLOSED on bdo.done / bdo.cancelled.
 *  - Statement PDF is stored locally from the webhook payload (base64 → file).
 *  - selected_invoices and selected_credit_notes are stored as JSON for the UI.
 *  - When a customer sends a slip and has multiple open BDOs, the caller must
 *    resolve the correct bdo_id explicitly — we never silently pick the "latest".
 *
 * @version 2.0.0 (March 2026 — cny_reya_connector v11.0.1.3.0)
 */

require_once __DIR__ . '/BdoSlipContract.php';

class BdoContextManager
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Open or refresh BDO context from a bdo.confirmed webhook payload.
     *
     * Stores all fields needed by the UI and slip-upload flow:
     *  - bdo_id, bdo_name, amount, delivery_type, state
     *  - qr_payload (PromptPay EMVCo raw payload)
     *  - statement_pdf_path (saved to disk from base64)
     *  - selected_invoices_json, selected_credit_notes_json
     *  - financial_summary_json (full breakdown for UI)
     *  - webhook_delivery_id (for deduplication)
     *
     * @param array  $data             Webhook event data (the 'data' key from payload)
     * @param string $deliveryId       X-Odoo-Delivery-Id header value
     * @return bool  True on success
     */
    public function openContext(array $data, string $deliveryId = ''): bool
    {
        if (!$this->tableExists()) {
            error_log('[BdoContextManager] odoo_bdo_context table does not exist');
            return false;
        }

        $lineUserId   = $data['customer']['line_user_id'] ?? null;
        $bdoId        = isset($data['bdo_id']) ? (int) $data['bdo_id'] : null;
        $bdoName      = $data['bdo_name'] ?? $data['bdo_ref'] ?? null;
        $amount       = isset($data['amount_total']) ? (float) $data['amount_total'] : null;
        $deliveryType = $data['delivery_type'] ?? null;
        $newState     = $data['new_state'] ?? BdoSlipContract::BDO_STATE_WAITING;
        $qrPayload    = $data['payment']['promptpay']['qr_data']['raw_payload'] ?? null;

        if (!$lineUserId || !$bdoId) {
            error_log('[BdoContextManager] openContext: missing line_user_id or bdo_id');
            return false;
        }

        // ── Save statement PDF ──────────────────────────────────────────────
        $statementPdfPath = $this->saveStatementPdf($data, $bdoName ?? (string) $bdoId);

        // ── Build financial summary JSON ────────────────────────────────────
        $financialSummary = $data['financial_summary'] ?? [];
        $selectedInvoices = $financialSummary['selected_invoices'] ?? [];
        $selectedCreditNotes = $financialSummary['selected_credit_notes'] ?? [];

        $financialSummaryJson = !empty($financialSummary)
            ? json_encode($financialSummary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $selectedInvoicesJson = !empty($selectedInvoices)
            ? json_encode($selectedInvoices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $selectedCreditNotesJson = !empty($selectedCreditNotes)
            ? json_encode($selectedCreditNotes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        try {
            $this->ensureColumns();

            $this->db->prepare("
                INSERT INTO odoo_bdo_context
                    (line_user_id, bdo_id, bdo_name, amount, delivery_type, state,
                     qr_payload, statement_pdf_path, webhook_delivery_id,
                     financial_summary_json, selected_invoices_json, selected_credit_notes_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    bdo_name                  = COALESCE(VALUES(bdo_name), bdo_name),
                    amount                    = COALESCE(VALUES(amount), amount),
                    delivery_type             = COALESCE(VALUES(delivery_type), delivery_type),
                    state                     = VALUES(state),
                    qr_payload                = COALESCE(VALUES(qr_payload), qr_payload),
                    statement_pdf_path        = COALESCE(VALUES(statement_pdf_path), statement_pdf_path),
                    webhook_delivery_id       = VALUES(webhook_delivery_id),
                    financial_summary_json    = COALESCE(VALUES(financial_summary_json), financial_summary_json),
                    selected_invoices_json    = COALESCE(VALUES(selected_invoices_json), selected_invoices_json),
                    selected_credit_notes_json = COALESCE(VALUES(selected_credit_notes_json), selected_credit_notes_json),
                    updated_at                = NOW()
            ")->execute([
                $lineUserId,
                $bdoId,
                $bdoName,
                $amount,
                $deliveryType,
                $newState,
                $qrPayload,
                $statementPdfPath,
                $deliveryId ?: null,
                $financialSummaryJson,
                $selectedInvoicesJson,
                $selectedCreditNotesJson,
            ]);

            error_log("[BdoContextManager] openContext: line_user_id={$lineUserId}, bdo_id={$bdoId}, amount={$amount}, delivery_type={$deliveryType}");
            return true;

        } catch (Exception $e) {
            error_log('[BdoContextManager] openContext error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Close BDO context when bdo.done or bdo.cancelled is received.
     * Sets state to 'done' or 'cancel' so the context is no longer used for auto-attach.
     *
     * @param array  $data      Webhook event data
     * @param string $newState  'done' or 'cancel'
     * @return bool
     */
    public function closeContext(array $data, string $newState): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $bdoId      = isset($data['bdo_id']) ? (int) $data['bdo_id'] : null;
        $lineUserId = $data['customer']['line_user_id'] ?? null;

        if (!$bdoId) {
            error_log('[BdoContextManager] closeContext: missing bdo_id');
            return false;
        }

        try {
            if ($lineUserId) {
                $this->db->prepare(
                    "UPDATE odoo_bdo_context SET state = ?, updated_at = NOW()
                     WHERE bdo_id = ? AND line_user_id = ?"
                )->execute([$newState, $bdoId, $lineUserId]);
            } else {
                $this->db->prepare(
                    "UPDATE odoo_bdo_context SET state = ?, updated_at = NOW()
                     WHERE bdo_id = ?"
                )->execute([$newState, $bdoId]);
            }

            error_log("[BdoContextManager] closeContext: bdo_id={$bdoId}, state={$newState}");
            return true;

        } catch (Exception $e) {
            error_log('[BdoContextManager] closeContext error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all open (waiting) BDO contexts for a customer.
     * Returns multiple rows if the customer has multiple open BDOs.
     *
     * @param string $lineUserId
     * @return array Array of context rows
     */
    public function getOpenContexts(string $lineUserId): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT bdo_id, bdo_name, amount, delivery_type, state,
                       qr_payload, statement_pdf_path,
                       selected_invoices_json, selected_credit_notes_json,
                       financial_summary_json, updated_at
                FROM odoo_bdo_context
                WHERE line_user_id = ?
                  AND state = ?
                ORDER BY updated_at DESC
            ");
            $stmt->execute([$lineUserId, BdoSlipContract::BDO_STATE_WAITING]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('[BdoContextManager] getOpenContexts error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a single BDO context by (line_user_id, bdo_id).
     *
     * @param string $lineUserId
     * @param int    $bdoId
     * @return array|null
     */
    public function getContext(string $lineUserId, int $bdoId): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM odoo_bdo_context
                WHERE line_user_id = ? AND bdo_id = ?
                LIMIT 1
            ");
            $stmt->execute([$lineUserId, $bdoId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('[BdoContextManager] getContext error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve the bdo_id to use for a slip upload.
     *
     * Rules:
     *  1. If exactly 1 open BDO → return that bdo_id
     *  2. If 0 open BDOs → return null (Odoo will auto-match by partner+amount)
     *  3. If >1 open BDOs → return null (caller must ask customer to specify)
     *     and populate $ambiguousBdos with the list for the caller to present
     *
     * @param string $lineUserId
     * @param array  &$ambiguousBdos  Populated when multiple open BDOs exist
     * @return int|null
     */
    public function resolveSlipBdoId(string $lineUserId, array &$ambiguousBdos = []): ?int
    {
        $open = $this->getOpenContexts($lineUserId);

        if (count($open) === 1) {
            return (int) $open[0]['bdo_id'];
        }

        if (count($open) > 1) {
            $ambiguousBdos = $open;
            return null;
        }

        return null;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Save statement PDF from base64 to disk.
     * Returns relative path or null on failure.
     */
    private function saveStatementPdf(array $data, string $identifier): ?string
    {
        if (empty($data['statement_pdf']['available']) || empty($data['statement_pdf']['data'])) {
            return null;
        }

        try {
            $pdfDir = __DIR__ . '/../uploads/bdo_statements';
            if (!is_dir($pdfDir)) {
                mkdir($pdfDir, 0755, true);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
            $filename = 'BDO_' . $safeName . '_Statement.pdf';
            $path     = $pdfDir . '/' . $filename;

            $pdfData = base64_decode($data['statement_pdf']['data'], true);
            if ($pdfData === false || strlen($pdfData) < 100) {
                error_log('[BdoContextManager] saveStatementPdf: invalid base64 data');
                return null;
            }

            file_put_contents($path, $pdfData);
            return 'uploads/bdo_statements/' . $filename;

        } catch (Exception $e) {
            error_log('[BdoContextManager] saveStatementPdf error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if odoo_bdo_context table exists.
     */
    private function tableExists(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM odoo_bdo_context LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Ensure new columns exist (idempotent, safe to call on every run).
     * Adds financial_summary_json, selected_invoices_json, selected_credit_notes_json
     * if they don't exist yet.
     */
    private function ensureColumns(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;

        $newColumns = [
            'financial_summary_json'     => "MEDIUMTEXT DEFAULT NULL COMMENT 'Full financial breakdown JSON from bdo.confirmed'",
            'selected_invoices_json'      => "TEXT DEFAULT NULL COMMENT 'selected_invoices array from financial_summary'",
            'selected_credit_notes_json'  => "TEXT DEFAULT NULL COMMENT 'selected_credit_notes array from financial_summary'",
        ];

        foreach ($newColumns as $col => $def) {
            try {
                $exists = $this->db->query("
                    SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'odoo_bdo_context'
                      AND COLUMN_NAME = '{$col}'
                ")->fetchColumn();

                if (!(int) $exists) {
                    $this->db->exec("ALTER TABLE odoo_bdo_context ADD COLUMN {$col} {$def}");
                    error_log("[BdoContextManager] Added column odoo_bdo_context.{$col}");
                }
            } catch (Exception $e) {
                error_log("[BdoContextManager] ensureColumns({$col}) error: " . $e->getMessage());
            }
        }
    }
}
