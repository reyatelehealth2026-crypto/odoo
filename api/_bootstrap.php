<?php
/**
 * Shared API bootstrap — extracts the CORS, JSON header, and OPTIONS preflight
 * boilerplate previously copy-pasted across 50+ api/*.php files.
 *
 * Usage at the top of any api/*.php file (before any output):
 *
 *   require_once __DIR__ . '/_bootstrap.php';
 *   api_bootstrap();
 *   // ... or with overrides:
 *   api_bootstrap(['cors_origins' => ['https://liff.line.me']]);
 *
 * Options:
 *   - cors_origins : array<string>|'*'   Allowed origins. Default: restrict to
 *                                         APP_URL + LIFF host. Pass '*' only
 *                                         for truly public read-only endpoints.
 *   - methods      : array<string>        HTTP methods advertised in preflight.
 *                                         Default: GET, POST, OPTIONS.
 *   - headers      : array<string>        Accepted request headers.
 *                                         Default: Content-Type, Authorization,
 *                                         X-Requested-With.
 *   - credentials  : bool                 Set Access-Control-Allow-Credentials.
 *                                         Default: false.
 *   - max_age      : int                  Preflight cache TTL (seconds).
 *                                         Default: 600.
 *   - json         : bool                 Emit Content-Type: application/json.
 *                                         Default: true.
 *
 * Security response headers (X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy) are emitted unconditionally.
 */

if (!function_exists('api_bootstrap')) {

    function api_bootstrap(array $opts = []): void
    {
        $defaults = [
            'cors_origins' => null,
            'methods'      => ['GET', 'POST', 'OPTIONS'],
            'headers'      => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'credentials'  => false,
            'max_age'      => 600,
            'json'         => true,
        ];
        $opts = array_merge($defaults, $opts);

        if ($opts['json'] && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigin = api_resolve_cors_origin($origin, $opts['cors_origins']);

        if ($allowedOrigin !== null && !headers_sent()) {
            header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: ' . implode(', ', $opts['methods']));
            header('Access-Control-Allow-Headers: ' . implode(', ', $opts['headers']));
            header('Access-Control-Max-Age: ' . (int) $opts['max_age']);
            if ($opts['credentials']) {
                header('Access-Control-Allow-Credentials: true');
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    function api_resolve_cors_origin(string $origin, $allowlist): ?string
    {
        if ($allowlist === '*') {
            return '*';
        }

        if ($allowlist === null) {
            $allowlist = [];
            if (defined('APP_URL') && APP_URL !== '') {
                $allowlist[] = rtrim(APP_URL, '/');
            }
            $allowlist[] = 'https://liff.line.me';
        }

        if (!is_array($allowlist) || $origin === '') {
            return null;
        }

        $normalizedOrigin = rtrim($origin, '/');
        foreach ($allowlist as $allowed) {
            if (rtrim((string) $allowed, '/') === $normalizedOrigin) {
                return $origin;
            }
        }

        return null;
    }
}
