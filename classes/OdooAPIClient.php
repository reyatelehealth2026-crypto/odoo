<?php
/**
 * Odoo API Client
 * 
 * Handles all API communication with Odoo ERP using JSON-RPC 2.0 format.
 * Includes circuit breaker, exponential backoff retry, connection reuse,
 * in-memory rate limiting, and comprehensive logging.
 * 
 * @version 2.0.0
 * @created 2026-02-03
 * @updated 2026-03-16 — Performance overhaul: circuit breaker, exponential
 *          backoff, cURL handle reuse, in-memory rate limiting, reduced
 *          connect timeout.
 */

require_once __DIR__ . '/SecurityHelper.php';
require_once __DIR__ . '/OdooCircuitBreaker.php';

class OdooAPIClient
{
    private $db;
    /** @var SecurityHelper */
    private $securityHelper;
    private $lineAccountId;
    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $connectTimeout;
    private $rateLimit;

    /** @var OdooCircuitBreaker */
    private $circuitBreaker;

    /** Reusable cURL handle for keep-alive connections */
    private $curlHandle = null;

    /** In-memory rate limit tracking (per-request process) */
    private static $rateLimitCounter = 0;
    private static $rateLimitWindowStart = 0;

    /** Max retries for transient failures */
    private const MAX_RETRIES = 3;

    /** Base delay in ms for exponential backoff */
    private const RETRY_BASE_DELAY_MS = 300;

    /**
     * Error code to Thai message mapping
     */
    private const ERROR_MESSAGES = [
        'MISSING_API_KEY' => 'ไม่พบ API Key กรุณาติดต่อผู้ดูแลระบบ',
        'INVALID_API_KEY' => 'API Key ไม่ถูกต้อง',
        'MISSING_PARAMETER' => 'ข้อมูลไม่ครบถ้วน',
        'LINE_USER_NOT_LINKED' => 'กรุณาเชื่อมต่อบัญชี Odoo ก่อนใช้งาน',
        'PARTNER_NOT_FOUND' => 'ไม่พบข้อมูลลูกค้า กรุณาตรวจสอบข้อมูลอีกครั้ง',
        'ORDER_NOT_FOUND' => 'ไม่พบออเดอร์',
        'INVOICE_NOT_FOUND' => 'ไม่พบใบแจ้งหนี้',
        'BDO_NOT_FOUND' => 'ไม่พบข้อมูล BDO',
        'SLIP_NOT_FOUND' => 'ไม่พบสลิป',
        'CUSTOMER_MISMATCH' => 'ออเดอร์นี้ไม่ใช่ของคุณ',
        'ALREADY_LINKED' => 'บัญชี LINE นี้เชื่อมต่อกับบัญชีอื่นแล้ว',
        'NOT_LINKED' => 'กรุณาเชื่อมต่อบัญชี Odoo ก่อน',
        'INVALID_IMAGE' => 'รูปภาพไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง',
        'RATE_LIMIT_EXCEEDED' => 'มีการเรียกใช้งานมากเกินไป กรุณารอสักครู่',
        'NETWORK_ERROR' => 'เกิดข้อผิดพลาดในการเชื่อมต่อ กรุณาลองใหม่อีกครั้ง',
        'TIMEOUT_ERROR' => 'การเชื่อมต่อหมดเวลา กรุณาลองใหม่อีกครั้ง',
        'CIRCUIT_OPEN' => 'ระบบ Odoo ไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ในอีกสักครู่',
        'UNKNOWN_ERROR' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ กรุณาติดต่อผู้ดูแลระบบ',
        'PHONE_REQUIRED' => 'เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน',
        'PHONE_MISMATCH' => 'เบอร์โทรศัพท์ไม่ตรงกับข้อมูลลูกค้า กรุณาตรวจสอบรหัสลูกค้าและเบอร์โทรศัพท์',
        'CUSTOMER_CODE_NOT_FOUND' => 'ไม่พบรหัสลูกค้า กรุณาตรวจสอบรหัสอีกครั้ง'
    ];

    /**
     * HTTP status codes that should NOT be retried
     */
    private const NON_RETRIABLE_HTTP_CODES = [400, 401, 403, 404, 405, 409, 422];

    /**
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID (nullable for shared mode)
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->apiKey = ODOO_API_KEY;
        $this->baseUrl = rtrim(ODOO_API_BASE_URL, '/');
        $this->timeout = defined('ODOO_API_TIMEOUT') ? (int) ODOO_API_TIMEOUT : 30;
        $this->connectTimeout = 5; // Reduced from 10s — fail fast on unreachable hosts
        $this->rateLimit = defined('ODOO_API_RATE_LIMIT') ? (int) ODOO_API_RATE_LIMIT : 60;

        $this->circuitBreaker = new OdooCircuitBreaker(
            'odoo_reya',
            5,   // open after 5 consecutive failures
            30,  // try again after 30s
            2    // allow 2 half-open probes
        );

        if (empty($this->apiKey)) {
            throw new Exception('MISSING_API_KEY');
        }
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    /**
     * Make API call to Odoo using JSON-RPC 2.0 format
     *
     * @param string $endpoint API endpoint (e.g., '/reya/orders')
     * @param array $params Request parameters
     * @param int $maxRetries Max retry attempts (default: 3)
     * @return array API response data
     * @throws Exception on error
     */
    public function call($endpoint, $params = [], $maxRetries = self::MAX_RETRIES)
    {
        $startTime = microtime(true);

        // Circuit breaker check — fail fast when Odoo is unresponsive
        if (!$this->circuitBreaker->isAvailable()) {
            $this->logApiCall($endpoint, $params, ['error' => 'CIRCUIT_OPEN'], 0, 0, 'CIRCUIT_OPEN');
            throw new Exception('CIRCUIT_OPEN');
        }

        // In-memory rate limiting (avoids DB query on every call)
        if (!$this->checkRateLimitFast()) {
            throw new Exception('RATE_LIMIT_EXCEEDED');
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->sleepWithBackoff($attempt);
            }

            try {
                $result = $this->doRequest($endpoint, $params);
                $duration = round((microtime(true) - $startTime) * 1000);

                $this->circuitBreaker->recordSuccess();
                $this->logApiCall($endpoint, $params, $result, 200, $duration);

                return $result;

            } catch (OdooRetriableException $e) {
                $lastException = $e;
                $duration = round((microtime(true) - $startTime) * 1000);
                error_log("[OdooAPIClient] Retriable error on {$endpoint} (attempt " . ($attempt + 1) . "/{$maxRetries}): " . $e->getMessage());

                if ($attempt === $maxRetries) {
                    $this->circuitBreaker->recordFailure($e->getMessage());
                    $this->logApiCall($endpoint, $params, ['error' => $e->getMessage()], $e->getCode(), $duration, $e->getMessage());
                }

            } catch (OdooNonRetriableException $e) {
                $duration = round((microtime(true) - $startTime) * 1000);
                $this->logApiCall($endpoint, $params, ['error' => $e->getMessage()], $e->getCode(), $duration, $e->getMessage());
                throw new Exception($e->getMessage(), $e->getCode());
            }
        }

        throw $lastException
            ? new Exception($lastException->getMessage(), $lastException->getCode())
            : new Exception('NETWORK_ERROR');
    }

    /**
     * Execute a single HTTP request to Odoo.
     * Throws OdooRetriableException or OdooNonRetriableException.
     */
    private function doRequest(string $endpoint, array $params): array
    {
        $requestBody = [
            'jsonrpc' => '2.0',
            'params' => $params
        ];

        $ch = $this->getCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $this->apiKey,
                'Connection: keep-alive',
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
            CURLOPT_TCP_KEEPINTVL => 15,
            CURLOPT_ENCODING => '', // Accept gzip/deflate
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // cURL transport error
        if ($response === false || $curlErrno !== 0) {
            throw new OdooRetriableException(
                'NETWORK_ERROR: ' . ($curlError ?: "cURL errno {$curlErrno}"),
                0
            );
        }

        // Parse JSON
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($httpCode >= 500) {
                throw new OdooRetriableException("Invalid JSON (HTTP {$httpCode}): " . json_last_error_msg(), $httpCode);
            }
            throw new OdooNonRetriableException("Invalid JSON response: " . json_last_error_msg(), $httpCode);
        }

        // Server errors -> retriable
        if ($httpCode >= 500) {
            $msg = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new OdooRetriableException($msg, $httpCode);
        }

        // Client errors -> non-retriable
        if ($httpCode >= 400) {
            return $this->handleError($data, $httpCode);
        }

        // 200 with error field
        if ($httpCode === 200 && isset($data['error'])) {
            error_log("[OdooAPIClient] 200 with error field from {$endpoint}: " . json_encode($data['error']));
            return [];
        }

        // Success path
        if ($httpCode === 200) {
            if (empty($data)) {
                return [];
            }
            if (!isset($data['jsonrpc']) && !isset($data['result'])) {
                return $data;
            }
        }

        return $data['result'] ?? $data;
    }

    /**
     * Get or create a reusable cURL handle.
     */
    private function getCurlHandle()
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }
        curl_reset($this->curlHandle);
        return $this->curlHandle;
    }

    /**
     * Health check - test connection to Odoo
     */
    public function health()
    {
        try {
            $result = $this->call('/reya/health', [], 1); // Only 1 retry for health check
            return [
                'success' => true,
                'status' => $result['status'] ?? 'ok',
                'message' => 'Connected to Odoo successfully',
                'circuit_breaker' => $this->circuitBreaker->getStatus(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
                'circuit_breaker' => $this->circuitBreaker->getStatus(),
            ];
        }
    }

    /**
     * Get circuit breaker status for monitoring
     */
    public function getCircuitBreakerStatus(): array
    {
        return $this->circuitBreaker->getStatus();
    }

    /**
     * Reset circuit breaker manually
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreaker->reset();
    }

    // ========================================================================
    // User Linking Methods
    // ========================================================================

    public function linkUser($lineUserId, $phone = null, $customerCode = null, $email = null)
    {
        $params = ['line_user_id' => $lineUserId];

        if ($phone)
            $params['phone'] = $phone;
        if ($customerCode)
            $params['customer_code'] = $customerCode;
        if ($email)
            $params['email'] = $email;

        return $this->call('/reya/user/link', $params);
    }

    public function unlinkUser($lineUserId)
    {
        return $this->call('/reya/user/unlink', ['line_user_id' => $lineUserId]);
    }

    public function getUserProfile($lineUserId)
    {
        return $this->call('/reya/user/profile', ['line_user_id' => $lineUserId]);
    }

    public function updateNotification($lineUserId, $enabled)
    {
        return $this->call('/reya/user/notification', [
            'line_user_id' => $lineUserId,
            'enabled' => $enabled
        ]);
    }

    // ========================================================================
    // Order Methods
    // ========================================================================

    public function getOrders($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/orders', $params);
    }

    public function getOrderDetail($orderId, $lineUserId)
    {
        return $this->call('/reya/order/detail', [
            'order_id' => $orderId,
            'line_user_id' => $lineUserId
        ]);
    }

    public function getOrderTracking($orderId, $lineUserId)
    {
        return $this->call('/reya/order/tracking', [
            'order_id' => $orderId,
            'line_user_id' => $lineUserId
        ]);
    }

    // ========================================================================
    // Invoice Methods
    // ========================================================================

    public function getInvoices($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/invoices', $params);
    }

    public function getCreditStatus($lineUserId)
    {
        return $this->call('/reya/credit-status', ['line_user_id' => $lineUserId]);
    }

    // ========================================================================
    // Payment Methods
    // ========================================================================

    public function uploadSlip($lineUserId, $slipImageBase64, $options = [])
    {
        $params = array_merge([
            'line_user_id' => $lineUserId,
            'slip_image' => $slipImageBase64
        ], $options);

        return $this->call('/reya/slip/upload', $params);
    }

    /**
     * Upload payment slip via multipart/form-data
     * Uses a separate cURL handle (not the shared JSON-RPC one).
     */
    public function uploadSlipMultipart($lineUserId, $imageData, $filename = 'slip.jpg', $mimeType = 'image/jpeg', $options = [])
    {
        $startTime = microtime(true);

        if (!$this->circuitBreaker->isAvailable()) {
            throw new Exception('CIRCUIT_OPEN');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'slip_');
        file_put_contents($tmpFile, $imageData);

        try {
            $postFields = [
                'line_user_id' => $lineUserId,
                'file' => new CURLFile($tmpFile, $mimeType, $filename),
            ];

            if (!empty($options['amount']))
                $postFields['amount'] = (float) $options['amount'];
            if (!empty($options['transfer_date']))
                $postFields['transfer_date'] = $options['transfer_date'];
            if (!empty($options['invoice_id']))
                $postFields['invoice_id'] = (int) $options['invoice_id'];
            if (!empty($options['order_id']))
                $postFields['order_id'] = (int) $options['order_id'];

            $uploadUrl = $this->baseUrl . '/reya/slip/upload';
            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_HTTPHEADER => [
                    'X-Api-Key: ' . $this->apiKey,
                    'X-Requested-With: XMLHttpRequest',
                    'X-CSRF-Token: 1',
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $duration = round((microtime(true) - $startTime) * 1000);

            if ($response === false) {
                $this->circuitBreaker->recordFailure('NETWORK_ERROR: ' . $curlError);
                throw new Exception('NETWORK_ERROR: ' . $curlError);
            }

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("HTTP {$httpCode} (Content-Type: {$contentType}): Odoo returned non-JSON — " . mb_substr($response, 0, 500));
            }

            $this->logApiCall('/reya/slip/upload', ['line_user_id' => $lineUserId], $data, $httpCode, $duration);

            if ($httpCode >= 500) {
                $this->circuitBreaker->recordFailure("HTTP {$httpCode}");
            } else {
                $this->circuitBreaker->recordSuccess();
            }

            if ($httpCode >= 400) {
                $odooMsg = $data['message'] ?? ($data['error'] ?? null);
                $detail = $odooMsg ? "{$odooMsg}" : mb_substr($response, 0, 300);
                throw new Exception("HTTP {$httpCode}: {$detail}");
            }

            return $data;

        } finally {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    public function getPaymentStatus($lineUserId, $orderId = null, $bdoId = null, $invoiceId = null)
    {
        $params = ['line_user_id' => $lineUserId];

        if ($orderId)
            $params['order_id'] = $orderId;
        if ($bdoId)
            $params['bdo_id'] = $bdoId;
        if ($invoiceId)
            $params['invoice_id'] = $invoiceId;

        return $this->call('/reya/payment/status', $params);
    }

    // ========================================================================
    // BDO Methods
    // ========================================================================

    public function getBdoList($lineUserId = null, $options = [])
    {
        $params = $options;
        if ($lineUserId !== null && $lineUserId !== '') {
            $params['line_user_id'] = $lineUserId;
        }
        return $this->call('/reya/bdo/list', $params);
    }

    public function getBdoDetail($lineUserId, $bdoId)
    {
        return $this->call('/reya/bdo/detail', [
            'line_user_id' => $lineUserId,
            'bdo_id' => (int) $bdoId
        ]);
    }

    public function getStatementPdf($bdoId)
    {
        $url = $this->baseUrl . '/reya/bdo/statement-pdf/' . (int) $bdoId . '?api_key=' . urlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception('NETWORK_ERROR: ' . $curlError);
        }

        if ($httpCode >= 400) {
            throw new Exception("Statement PDF download failed: HTTP {$httpCode}");
        }

        if (strpos($contentType, 'application/pdf') === false && strpos($contentType, 'application/octet-stream') === false) {
            throw new Exception("Unexpected content type: {$contentType}");
        }

        return $response;
    }

    // ========================================================================
    // Slip Matching Methods
    // ========================================================================

    public function matchSlipBdo($lineUserId, $slipInboxId, array $matches, $note = '')
    {
        $params = [
            'line_user_id' => $lineUserId,
            'slip_inbox_id' => (int) $slipInboxId,
            'matches' => $matches
        ];

        if ($note !== '') {
            $params['note'] = $note;
        }

        return $this->call('/reya/slip/match-bdo', $params);
    }

    public function unmatchSlip($lineUserId, $slipInboxId, $reason = '')
    {
        $params = [
            'line_user_id' => $lineUserId,
            'slip_inbox_id' => (int) $slipInboxId
        ];

        if ($reason !== '') {
            $params['reason'] = $reason;
        }

        return $this->call('/reya/slip/unmatch', $params);
    }

    // ========================================================================
    // Salesperson Methods
    // ========================================================================

    public function getSalespersonDashboard($lineUserId, $period = 'today')
    {
        return $this->call('/reya/salesperson/dashboard', [
            'line_user_id' => $lineUserId,
            'period' => $period
        ]);
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Fast in-memory rate limit check (no DB query).
     * Resets window every 60 seconds.
     */
    private function checkRateLimitFast(): bool
    {
        $now = time();

        if ($now - self::$rateLimitWindowStart >= 60) {
            self::$rateLimitCounter = 0;
            self::$rateLimitWindowStart = $now;
        }

        self::$rateLimitCounter++;

        return self::$rateLimitCounter <= $this->rateLimit;
    }

    /**
     * Sleep with exponential backoff and jitter.
     */
    private function sleepWithBackoff(int $attempt): void
    {
        $baseMs = self::RETRY_BASE_DELAY_MS * pow(2, $attempt - 1);
        $jitterMs = mt_rand(0, (int) ($baseMs * 0.3));
        $sleepMs = min($baseMs + $jitterMs, 5000); // cap at 5s
        usleep($sleepMs * 1000);
    }

    /**
     * Determine if an HTTP status code is retriable.
     */
    private function isRetriableHttpCode(int $code): bool
    {
        return $code >= 500 || $code === 429;
    }

    private function handleError($response, $httpCode = 0)
    {
        $errorCode = $response['error']['code'] ?? 'UNKNOWN_ERROR';
        $errorMessage = $response['error']['message'] ?? 'Unknown error';

        $thaiMessage = self::ERROR_MESSAGES[$errorCode] ?? self::ERROR_MESSAGES['UNKNOWN_ERROR'];

        if (in_array($httpCode, self::NON_RETRIABLE_HTTP_CODES, true)) {
            throw new OdooNonRetriableException($thaiMessage . ' (' . $errorCode . ')', $httpCode);
        }

        throw new Exception($thaiMessage . ' (' . $errorCode . ')', $httpCode);
    }

    private function logApiCall($endpoint, $params, $response, $statusCode, $duration, $error = null)
    {
        try {
            static $tableChecked = null;
            if ($tableChecked === null) {
                $stmt = $this->db->query("SHOW TABLES LIKE 'odoo_api_logs'");
                $tableChecked = $stmt->rowCount() > 0;
            }
            if (!$tableChecked) {
                return;
            }

            $stmt = $this->db->prepare("
                INSERT INTO odoo_api_logs 
                (line_account_id, endpoint, method, request_params, response_data, 
                 status_code, error_message, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $sanitizedParams = class_exists('SecurityHelper') ? SecurityHelper::sanitizeForLog($params) : $params;
            $sanitizedResponse = class_exists('SecurityHelper') ? SecurityHelper::sanitizeForLog($response) : $response;

            $stmt->execute([
                $this->lineAccountId,
                $endpoint,
                'POST',
                json_encode($sanitizedParams),
                json_encode($sanitizedResponse),
                $statusCode,
                $error,
                $duration
            ]);
        } catch (Exception $e) {
            error_log('Failed to log Odoo API call: ' . $e->getMessage());
        }
    }
}

/**
 * Exception indicating the error is transient and the request can be retried.
 */
class OdooRetriableException extends Exception
{
}

/**
 * Exception indicating the error is permanent and should NOT be retried.
 */
class OdooNonRetriableException extends Exception
{
}
