<?php
/**
 * Circuit Breaker for Odoo API calls
 *
 * Prevents cascading failures by tracking error rates and temporarily
 * blocking requests when the upstream service is unresponsive.
 *
 * States:
 *   CLOSED   -> normal operation, requests pass through
 *   OPEN     -> service is down, requests fail fast without network call
 *   HALF_OPEN -> testing if service recovered (allows limited requests)
 *
 * File-based state (works across PHP-FPM workers without shared memory).
 * Falls back to APCu when available for lower latency.
 */
class OdooCircuitBreaker
{
    private string $serviceName;
    private int $failureThreshold;
    private int $recoveryTimeout;
    private int $halfOpenMaxAttempts;
    private string $stateDir;
    /** @var \PDO|null */
    private $db;

    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    /**
     * @param string    $serviceName         Unique key per upstream (e.g. 'odoo_reya', 'odoo_cny')
     * @param int       $failureThreshold    Consecutive failures before opening circuit
     * @param int       $recoveryTimeout     Seconds to wait before half-open probe
     * @param int       $halfOpenMaxAttempts Max probe requests in half-open state
     * @param \PDO|null $db                  Optional PDO for MySQL-backed shared state (PHP ↔ Node.js)
     *                                        When provided, state transitions are written to
     *                                        `odoo_circuit_breaker_state` so the Node.js stack
     *                                        sees the same circuit state.
     */
    public function __construct(
        string $serviceName = 'odoo_api',
        int $failureThreshold = 5,
        int $recoveryTimeout = 30,
        int $halfOpenMaxAttempts = 2,
        $db = null
    ) {
        $this->serviceName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $serviceName);
        $this->failureThreshold = max(1, $failureThreshold);
        $this->recoveryTimeout = max(5, $recoveryTimeout);
        $this->halfOpenMaxAttempts = max(1, $halfOpenMaxAttempts);
        $this->db = $db instanceof \PDO ? $db : null;

        $this->stateDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'odoo_circuit_breaker';
        if (!is_dir($this->stateDir)) {
            @mkdir($this->stateDir, 0775, true);
        }
    }

    /**
     * Check whether a request is allowed.
     *
     * @return bool true if the request may proceed
     */
    public function isAvailable(): bool
    {
        $state = $this->loadState();

        if ($state['status'] === self::STATE_CLOSED) {
            return true;
        }

        if ($state['status'] === self::STATE_OPEN) {
            if ((time() - $state['opened_at']) >= $this->recoveryTimeout) {
                $this->transitionTo(self::STATE_HALF_OPEN, $state);
                return true;
            }
            return false;
        }

        // HALF_OPEN: allow limited probes
        return ($state['half_open_attempts'] ?? 0) < $this->halfOpenMaxAttempts;
    }

    /**
     * Record a successful response from the upstream.
     */
    public function recordSuccess(): void
    {
        $state = $this->loadState();

        if ($state['status'] === self::STATE_HALF_OPEN || $state['status'] === self::STATE_OPEN) {
            $this->transitionTo(self::STATE_CLOSED);
            return;
        }

        if (($state['consecutive_failures'] ?? 0) > 0) {
            $state['consecutive_failures'] = 0;
            $state['last_success_at'] = time();
            $this->saveState($state);
        }
    }

    /**
     * Record a failure from the upstream.
     *
     * @param string $error Optional error description for debugging
     */
    public function recordFailure(string $error = ''): void
    {
        $state = $this->loadState();

        if ($state['status'] === self::STATE_HALF_OPEN) {
            $state['half_open_attempts'] = ($state['half_open_attempts'] ?? 0) + 1;
            if ($state['half_open_attempts'] >= $this->halfOpenMaxAttempts) {
                $this->transitionTo(self::STATE_OPEN);
                error_log("[CircuitBreaker:{$this->serviceName}] Half-open probe failed, reopening circuit. Error: {$error}");
                return;
            }
            $this->saveState($state);
            return;
        }

        $state['consecutive_failures'] = ($state['consecutive_failures'] ?? 0) + 1;
        $state['last_failure_at'] = time();
        $state['last_error'] = mb_substr($error, 0, 200);

        if ($state['consecutive_failures'] >= $this->failureThreshold) {
            $this->transitionTo(self::STATE_OPEN, $state);
            error_log("[CircuitBreaker:{$this->serviceName}] Circuit OPENED after {$state['consecutive_failures']} failures. Last error: {$error}");
        } else {
            $this->saveState($state);
        }
    }

    /**
     * Get current circuit state for monitoring/dashboard display.
     */
    public function getStatus(): array
    {
        $state = $this->loadState();
        return [
            'service' => $this->serviceName,
            'status' => $state['status'],
            'consecutive_failures' => $state['consecutive_failures'] ?? 0,
            'failure_threshold' => $this->failureThreshold,
            'recovery_timeout' => $this->recoveryTimeout,
            'opened_at' => $state['opened_at'] ?? null,
            'last_failure_at' => $state['last_failure_at'] ?? null,
            'last_success_at' => $state['last_success_at'] ?? null,
            'last_error' => $state['last_error'] ?? null,
            'time_until_probe' => $state['status'] === self::STATE_OPEN
                ? max(0, $this->recoveryTimeout - (time() - ($state['opened_at'] ?? time())))
                : null,
        ];
    }

    /**
     * Manually reset the circuit to closed state.
     */
    public function reset(): void
    {
        $this->transitionTo(self::STATE_CLOSED);
    }

    // ──────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────

    private function transitionTo(string $newStatus, array $state = []): void
    {
        $state['status'] = $newStatus;

        if ($newStatus === self::STATE_CLOSED) {
            $state['consecutive_failures'] = 0;
            $state['opened_at'] = null;
            $state['half_open_attempts'] = 0;
            $state['last_success_at'] = time();
        } elseif ($newStatus === self::STATE_OPEN) {
            $state['opened_at'] = time();
            $state['half_open_attempts'] = 0;
        } elseif ($newStatus === self::STATE_HALF_OPEN) {
            $state['half_open_attempts'] = 0;
        }

        $this->saveState($state);

        // Sync state transition to MySQL so Node.js backend sees the same state.
        // Only called on transitions (OPEN/CLOSED/HALF_OPEN), not on every failure count update.
        $this->syncTransitionToMySQL($state);
    }

    private function loadState(): array
    {
        $default = [
            'status' => self::STATE_CLOSED,
            'consecutive_failures' => 0,
            'opened_at' => null,
            'half_open_attempts' => 0,
            'last_failure_at' => null,
            'last_success_at' => null,
            'last_error' => null,
        ];

        // L1: APCu (sub-ms, per-process)
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch('cb_' . $this->serviceName, $found);
            if ($found && is_array($data)) {
                return array_merge($default, $data);
            }
        }

        // L2: File (shared across all PHP-FPM workers on same host)
        $path = $this->statePath();
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $fileState = array_merge($default, $data);
                    // File is fresh — no need to hit MySQL
                    if ((time() - (int) filemtime($path)) < 8) {
                        return $fileState;
                    }
                    // File is stale (>8s) — fall through to MySQL
                }
            }
        }

        // L3: MySQL (shared across PHP + Node.js stacks)
        // Only used as fallback when APCu is absent AND file is missing/stale.
        $mysqlState = $this->loadStateFromMySQL();
        if ($mysqlState !== null) {
            $merged = array_merge($default, $mysqlState);
            // Backfill local caches to avoid hitting MySQL on next request
            $this->saveState($merged);
            return $merged;
        }

        return $default;
    }

    private function saveState(array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);

        if (function_exists('apcu_store')) {
            apcu_store('cb_' . $this->serviceName, $state, $this->recoveryTimeout * 3);
        }

        @file_put_contents($this->statePath(), $json, LOCK_EX);
    }

    /**
     * Write state transition to shared MySQL table.
     * Called only on OPEN/CLOSED/HALF_OPEN transitions — not on every failure counter update.
     * Safe to fail silently (circuit breaker still works via APCu+file).
     */
    private function syncTransitionToMySQL(array $state): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO `odoo_circuit_breaker_state`
                    (`service_name`, `status`, `consecutive_failures`, `opened_at`,
                     `half_open_attempts`, `last_failure_at`, `last_success_at`, `last_error`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `status`               = VALUES(`status`),
                    `consecutive_failures` = VALUES(`consecutive_failures`),
                    `opened_at`            = VALUES(`opened_at`),
                    `half_open_attempts`   = VALUES(`half_open_attempts`),
                    `last_failure_at`      = VALUES(`last_failure_at`),
                    `last_success_at`      = VALUES(`last_success_at`),
                    `last_error`           = VALUES(`last_error`),
                    `updated_at`           = NOW()
            ");
            $stmt->execute([
                $this->serviceName,
                $state['status'],
                (int) ($state['consecutive_failures'] ?? 0),
                $state['opened_at'] ?? null,
                (int) ($state['half_open_attempts'] ?? 0),
                $state['last_failure_at'] ?? null,
                $state['last_success_at'] ?? null,
                isset($state['last_error']) ? mb_substr((string) $state['last_error'], 0, 200) : null,
            ]);
        } catch (\Exception $e) {
            // MySQL sync failed — circuit breaker still functions via file/APCu
            error_log("[CircuitBreaker:{$this->serviceName}] MySQL sync failed: " . $e->getMessage());
        }
    }

    /**
     * Read state from MySQL shared table.
     * Returns null if table does not exist or query fails.
     */
    private function loadStateFromMySQL(): ?array
    {
        if ($this->db === null) {
            return null;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT `status`, `consecutive_failures`, `opened_at`,
                       `half_open_attempts`, `last_failure_at`, `last_success_at`, `last_error`
                FROM `odoo_circuit_breaker_state`
                WHERE `service_name` = ?
                LIMIT 1
            ");
            $stmt->execute([$this->serviceName]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return [
                'status'               => $row['status'],
                'consecutive_failures' => (int) $row['consecutive_failures'],
                'opened_at'            => $row['opened_at'] !== null ? (int) $row['opened_at'] : null,
                'half_open_attempts'   => (int) $row['half_open_attempts'],
                'last_failure_at'      => $row['last_failure_at'] !== null ? (int) $row['last_failure_at'] : null,
                'last_success_at'      => $row['last_success_at'] !== null ? (int) $row['last_success_at'] : null,
                'last_error'           => $row['last_error'],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function statePath(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . $this->serviceName . '.json';
    }
}
