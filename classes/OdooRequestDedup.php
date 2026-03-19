<?php
/**
 * OdooRequestDedup — Request Deduplication via File Locking
 *
 * ปัญหา: ถ้า 3 user เปิดหน้า dashboard พร้อมกัน → PHP-FPM ยิง Odoo API 3 ครั้ง
 *         ทั้งที่ผลเหมือนกันและ Odoo รับ load ฟรี
 *
 * วิธีแก้: ใช้ flock(LOCK_EX|LOCK_NB) บน lock file
 *   - Worker แรกที่ได้ lock = "leader" → เรียก Odoo → เขียนผลลง result file
 *   - Worker หลังที่ lock ไม่ได้ = "follower" → รอผลจาก result file (poll 100ms)
 *   - ถ้ารอเกิน $maxWaitMs → proceed กับ request ของตัวเอง (fallback graceful)
 *
 * ใช้งานกับ shared hosting ได้ทันที:
 *   - ไม่ต้องการ Redis, APCu, หรือ OPcache
 *   - PHP-FPM workers ทั้งหมดบน same server share /tmp/ directory
 *   - OS จัดการ flock atomically (ไม่มี race condition)
 *
 * Integration ใน odoo-dashboard-api.php:
 *   require_once __DIR__ . '/../classes/OdooRequestDedup.php';
 *   $dedup = new OdooRequestDedup();
 *   [$acquired, $fp] = $dedup->tryAcquire($cacheKey);
 *   if (!$acquired) {
 *       $result = $dedup->waitResult($cacheKey);
 *       if ($result !== null) { echo json_encode(...); exit; }
 *       [$acquired, $fp] = $dedup->tryAcquire($cacheKey); // retry after timeout
 *   }
 *   // ... compute result ...
 *   if ($fp !== null) { $dedup->complete($cacheKey, $fp, $result); }
 *
 * @version 1.0.0
 * @created 2026-03-19
 */
class OdooRequestDedup
{
    private string $dir;

    /** Result files older than this are considered stale and ignored (seconds) */
    private const RESULT_TTL = 30;

    /** Lock files older than this indicate a crashed leader process (seconds) */
    private const LOCK_STALE_AFTER = 15;

    public function __construct()
    {
        $this->dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'cny_odoo_inflight';

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /**
     * Try to acquire an exclusive lock for a request key.
     *
     * Uses non-blocking flock so PHP-FPM workers don't stall on the syscall.
     * If a previous leader crashed (lock file is stale), we clean up and re-acquire.
     *
     * @param  string        $key  Unique key for this request (e.g. dashboardApiBuildCacheKey output)
     * @return array{bool, resource|null}  [true, $fp] if acquired, [false, null] if another holds it
     */
    public function tryAcquire(string $key): array
    {
        $lockPath = $this->lockPath($key);

        // If lock file is older than LOCK_STALE_AFTER, the leader likely crashed.
        // Delete it so we can re-acquire cleanly.
        if (is_file($lockPath) && (time() - (int) filemtime($lockPath)) > self::LOCK_STALE_AFTER) {
            @unlink($lockPath);
        }

        $fp = @fopen($lockPath, 'c');
        if ($fp === false) {
            return [false, null];
        }

        $acquired = flock($fp, LOCK_EX | LOCK_NB);
        if (!$acquired) {
            fclose($fp);
            return [false, null];
        }

        // Touch the lock file timestamp so stale detection above works correctly.
        @touch($lockPath);

        return [true, $fp];
    }

    /**
     * Wait (via polling) for the leader worker to finish and write a result.
     *
     * Blocks the current PHP-FPM worker in a tight 100ms poll loop.
     * This is acceptable on shared hosting because:
     *   - typical Odoo calls take 1–5s
     *   - we save the follower from making its own identical call
     *   - max wait is bounded (default 5s) so no worker hangs indefinitely
     *
     * @param  string     $key        Same key passed to tryAcquire()
     * @param  int        $maxWaitMs  Maximum milliseconds to wait (default 5000)
     * @return array|null             Result array on success, null on timeout
     */
    public function waitResult(string $key, int $maxWaitMs = 5000): ?array
    {
        $resultPath = $this->resultPath($key);
        $waited = 0;
        $interval = 100; // ms per poll

        while ($waited < $maxWaitMs) {
            usleep($interval * 1000);
            $waited += $interval;

            $data = $this->readResult($resultPath);
            if ($data !== null) {
                return $data;
            }
        }

        return null; // timeout — caller should proceed with its own request
    }

    /**
     * Store the result and release the exclusive lock.
     * Called by the leader worker after computing the Odoo response.
     *
     * @param string   $key     Same key passed to tryAcquire()
     * @param resource $fp      File handle returned by tryAcquire()
     * @param array    $result  The computed result to share with follower workers
     */
    public function complete(string $key, $fp, array $result): void
    {
        $resultPath = $this->resultPath($key);
        $tmpPath = $resultPath . '.' . getmypid() . '.tmp';

        $payload = json_encode([
            't' => time(),
            'r' => $result,
        ], JSON_UNESCAPED_UNICODE);

        // Atomic write: write to tmp → rename (prevents followers reading partial file)
        if ($payload !== false) {
            if (@file_put_contents($tmpPath, $payload, LOCK_EX) !== false) {
                @rename($tmpPath, $resultPath);
            }
            @unlink($tmpPath); // cleanup if rename failed
        }

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    /**
     * Release the lock without writing a result (used when the leader errors out).
     * Follower workers will timeout and proceed with their own requests.
     *
     * @param resource $fp  File handle returned by tryAcquire()
     */
    public function releaseOnError($fp): void
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────────────────────────────────

    private function readResult(string $resultPath): ?array
    {
        if (!is_file($resultPath)) {
            return null;
        }

        $raw = @file_get_contents($resultPath);
        if ($raw === false || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['t'], $payload['r'])) {
            return null;
        }

        // Ignore stale results
        if ((time() - (int) $payload['t']) > self::RESULT_TTL) {
            return null;
        }

        return $payload['r'];
    }

    private function lockPath(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.lock';
    }

    private function resultPath(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.result';
    }
}
