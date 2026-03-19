<?php
/**
 * RedisCache — Singleton wrapper around Predis client
 *
 * Adds Redis Cloud as L0 cache (shared across all PHP workers and processes).
 * Gracefully falls back to null on any connection/command error so callers
 * can fall through to APCu / file cache without crashing.
 *
 * Usage:
 *   $redis = RedisCache::getInstance();
 *   $data  = $redis->get('dash:some_key');
 *   $redis->set('dash:some_key', $data, 60);
 *
 * Key prefix: REDIS_KEY_PREFIX constant (default 'cny:')
 *
 * @version 1.0.0
 * @created 2026-03-19
 */
class RedisCache
{
    private static ?RedisCache $instance = null;

    private ?\Predis\Client $client = null;

    /** false once a fatal connection/command error is detected */
    private bool $available = false;

    private string $prefix = 'cny:';

    // ── constructor ──────────────────────────────────────────────────────────

    private function __construct()
    {
        if (!class_exists(\Predis\Client::class)) {
            return; // predis not installed — stay unavailable
        }

        $this->prefix = defined('REDIS_KEY_PREFIX') ? (string) REDIS_KEY_PREFIX : 'cny:';

        try {
            $scheme = (defined('REDIS_TLS') && REDIS_TLS) ? 'tls' : 'tcp';

            $this->client = new \Predis\Client(
                [
                    'scheme'   => $scheme,
                    'host'     => defined('REDIS_HOST')     ? REDIS_HOST     : '127.0.0.1',
                    'port'     => defined('REDIS_PORT')     ? (int) REDIS_PORT : 6379,
                    'password' => defined('REDIS_PASSWORD') ? REDIS_PASSWORD : null,
                    'username' => defined('REDIS_USERNAME') ? REDIS_USERNAME : 'default',
                ],
                [
                    'parameters' => [
                        'ssl' => [
                            'verify_peer'      => false,
                            'verify_peer_name' => false,
                        ],
                    ],
                    'connections' => [
                        'tcp' => \Predis\Connection\StreamConnection::class,
                        'tls' => \Predis\Connection\StreamConnection::class,
                    ],
                ]
            );

            // Eager PING — triggers actual TCP connect so we know immediately
            // if the server is reachable.  Timeout is handled by Predis default (5s).
            $this->client->ping();
            $this->available = true;
        } catch (\Exception $e) {
            $this->client    = null;
            $this->available = false;
        }
    }

    // ── singleton ────────────────────────────────────────────────────────────

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** Reset singleton (useful for tests or after config changes). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ── public helpers ───────────────────────────────────────────────────────

    public function isAvailable(): bool
    {
        return $this->available && $this->client !== null;
    }

    /**
     * GET a JSON-encoded array stored by set().
     * Returns null on miss, expiry, or error.
     */
    public function get(string $key): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $raw = $this->client->get($this->prefix . $key);

            if ($raw === null) {
                return null;
            }

            $data = json_decode((string) $raw, true);

            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            $this->available = false;

            return null;
        }
    }

    /**
     * SET an array value with TTL seconds.
     * Serialises to JSON. Returns true on success.
     */
    public function set(string $key, array $data, int $ttl): bool
    {
        if (!$this->isAvailable() || $ttl <= 0) {
            return false;
        }

        try {
            $raw = json_encode($data, JSON_UNESCAPED_UNICODE);

            if ($raw === false) {
                return false;
            }

            $this->client->setex($this->prefix . $key, $ttl, $raw);

            return true;
        } catch (\Exception $e) {
            $this->available = false;

            return false;
        }
    }

    /**
     * DELETE one key.
     */
    public function delete(string $key): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        try {
            $this->client->del([$this->prefix . $key]);
        } catch (\Exception $e) {
            $this->available = false;
        }
    }

    /**
     * GET remaining TTL in seconds (-1 = no expiry, -2 = not found, null = error).
     */
    public function ttl(string $key): ?int
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            return (int) $this->client->ttl($this->prefix . $key);
        } catch (\Exception $e) {
            $this->available = false;

            return null;
        }
    }

    /**
     * Flush all keys matching the configured prefix (pattern: prefix*).
     * Uses SCAN to avoid blocking on large keyspaces.
     */
    public function flushPrefix(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $deleted = 0;
        $cursor  = '0';
        $pattern = $this->prefix . '*';

        try {
            do {
                [$cursor, $keys] = $this->client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 200]);

                if (!empty($keys)) {
                    $this->client->del($keys);
                    $deleted += count($keys);
                }
            } while ($cursor !== '0');
        } catch (\Exception $e) {
            $this->available = false;
        }

        return $deleted;
    }
}
