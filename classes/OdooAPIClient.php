<?php
/**
 * Odoo API Client
 * 
 * Handles all API communication with Odoo ERP using JSON-RPC 2.0 format.
 * Includes rate limiting, error handling, retry logic, and comprehensive logging.
 * 
 * @version 1.0.0
 * @created 2026-02-03
 */

require_once __DIR__ . '/SecurityHelper.php';

class OdooAPIClient
{
    private $db;
    /** @var SecurityHelper */
    private $securityHelper;
    private $lineAccountId;
    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $rateLimit;

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
        'UNKNOWN_ERROR' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ กรุณาติดต่อผู้ดูแลระบบ',
        // v11.0.1.2.0 - Security Enhancement: customer_code requires phone
        'PHONE_REQUIRED' => 'เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน',
        'PHONE_MISMATCH' => 'เบอร์โทรศัพท์ไม่ตรงกับข้อมูลลูกค้า กรุณาตรวจสอบรหัสลูกค้าและเบอร์โทรศัพท์',
        'CUSTOMER_CODE_NOT_FOUND' => 'ไม่พบรหัสลูกค้า กรุณาตรวจสอบรหัสอีกครั้ง'
    ];

    /**
     * Constructor
     * 
     * @param PDO $db Database connection
     * @param int|null $lineAccountId LINE account ID (nullable for shared mode)
     */
    public function __construct($db, $lineAccountId = null)
    {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
        $this->apiKey = ODOO_API_KEY;
        $this->baseUrl = rtrim(ODOO_API_BASE_URL, '/');
        $this->timeout = ODOO_API_TIMEOUT;
        $this->rateLimit = ODOO_API_RATE_LIMIT;

        if (empty($this->apiKey)) {
            throw new Exception('MISSING_API_KEY');
        }
    }

    /**
     * Make API call to Odoo using JSON-RPC 2.0 format
     * 
     * @param string $endpoint API endpoint (e.g., '/reya/orders')
     * @param array $params Request parameters
     * @param int $retryCount Number of retries (default: 3)
     * @return array API response data
     * @throws Exception on error
     */
    public function call($endpoint, $params = [], $retryCount = 3)
    {
        $startTime = microtime(true);

        try {
            // Check rate limit
            if (!$this->checkRateLimit()) {
                throw new Exception('RATE_LIMIT_EXCEEDED');
            }

            // Build JSON-RPC 2.0 request
            $requestBody = [
                'jsonrpc' => '2.0',
                'params' => $params
            ];

            // Make HTTP request
            $ch = curl_init($this->baseUrl . $endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Api-Key: ' . $this->apiKey
                ],
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $duration = round((microtime(true) - $startTime) * 1000); // milliseconds

            // Handle cURL errors
            if ($response === false) {
                if ($retryCount > 0) {
                    usleep(500000); // Wait 500ms before retry
                    return $this->call($endpoint, $params, $retryCount - 1);
                }
                throw new Exception('NETWORK_ERROR: ' . $curlError);
            }

            // Parse JSON response
            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("OdooAPI Invalid JSON from $endpoint: " . $response);
                throw new Exception('Invalid JSON response: ' . json_last_error_msg());
            }

            // Log API call (optional)
            $this->logApiCall($endpoint, $params, $data, $httpCode, $duration);

            // Debug: Log successful response structure
            if ($httpCode === 200) {
                error_log("OdooAPI 200 response from $endpoint: " . json_encode($data));
            }

            // Handle HTTP errors
            if ($httpCode >= 400) {
                return $this->handleError($data, $httpCode);
            }

            // Handle JSON-RPC errors - but not for HTTP 200 (might be warning-level errors)
            if (isset($data['error']) && $httpCode >= 400) {
                return $this->handleError($data);
            }

            // For HTTP 200 responses with error field, log but don't throw
            if ($httpCode === 200 && isset($data['error'])) {
                error_log("OdooAPI 200 with error field from $endpoint: " . json_encode($data['error']));
                // Return empty array for API errors in successful HTTP responses
                return [];
            }

            // For successful responses (200), check if it has expected structure
            if ($httpCode === 200) {
                // If response is empty or doesn't have result/data, return empty array instead of error
                if (empty($data)) {
                    error_log("OdooAPI empty response from $endpoint, returning []");
                    return [];
                }
                
                // If it's a non-JSON-RPC response, return as-is
                if (!isset($data['jsonrpc']) && !isset($data['result'])) {
                    error_log("OdooAPI non-JSON-RPC response from $endpoint, returning as-is");
                    return $data;
                }
            }

            // Return result
            return $data['result'] ?? $data;

        } catch (Exception $e) {
            // Log error
            $this->logApiCall(
                $endpoint,
                $params,
                ['error' => $e->getMessage()],
                0,
                round((microtime(true) - $startTime) * 1000),
                $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Health check - test connection to Odoo
     * 
     * @return array Health status
     */
    public function health()
    {
        try {
            $result = $this->call('/reya/health', []);
            return [
                'success' => true,
                'status' => $result['status'] ?? 'ok',
                'message' => 'Connected to Odoo successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    // ========================================================================
    // User Linking Methods
    // ========================================================================

    /**
     * Link LINE user to Odoo partner account
     * 
     * @param string $lineUserId LINE user ID
     * @param string|null $phone Phone number
     * @param string|null $customerCode Customer code
     * @param string|null $email Email address
     * @return array Partner information
     */
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

    /**
     * Unlink LINE user from Odoo partner
     * 
     * @param string $lineUserId LINE user ID
     * @return array Success status
     */
    public function unlinkUser($lineUserId)
    {
        return $this->call('/reya/user/unlink', ['line_user_id' => $lineUserId]);
    }

    /**
     * Get user profile from Odoo
     * 
     * @param string $lineUserId LINE user ID
     * @return array User profile
     */
    public function getUserProfile($lineUserId)
    {
        return $this->call('/reya/user/profile', ['line_user_id' => $lineUserId]);
    }

    /**
     * Update notification settings
     * 
     * @param string $lineUserId LINE user ID
     * @param bool $enabled Enable/disable notifications
     * @return array Success status
     */
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

    /**
     * Get orders list
     * 
     * @param string $lineUserId LINE user ID
     * @param array $options Filter options (state, date_from, date_to, limit, offset)
     * @return array Orders list
     */
    public function getOrders($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/orders', $params);
    }

    /**
     * Get order detail
     * 
     * @param int $orderId Order ID
     * @param string $lineUserId LINE user ID
     * @return array Order details
     */
    public function getOrderDetail($orderId, $lineUserId)
    {
        return $this->call('/reya/order/detail', [
            'order_id' => $orderId,
            'line_user_id' => $lineUserId
        ]);
    }

    /**
     * Get order tracking timeline
     * 
     * @param int $orderId Order ID
     * @param string $lineUserId LINE user ID
     * @return array Tracking timeline
     */
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

    /**
     * Get invoices list
     * 
     * @param string $lineUserId LINE user ID
     * @param array $options Filter options (state, limit, offset)
     * @return array Invoices list
     */
    public function getInvoices($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/invoices', $params);
    }

    /**
     * Get credit status
     * 
     * @param string $lineUserId LINE user ID
     * @return array Credit status
     */
    public function getCreditStatus($lineUserId)
    {
        return $this->call('/reya/credit-status', ['line_user_id' => $lineUserId]);
    }

    // ========================================================================
    // Payment Methods
    // ========================================================================

    /**
     * Upload payment slip
     * 
     * @param string $lineUserId LINE user ID
     * @param string $slipImageBase64 Base64 encoded slip image
     * @param array $options Additional options (bdo_id, invoice_id, amount, transfer_date)
     * @return array Upload result with auto-match status
     */
    public function uploadSlip($lineUserId, $slipImageBase64, $options = [])
    {
        $params = array_merge([
            'line_user_id' => $lineUserId,
            'slip_image' => $slipImageBase64
        ], $options);

        return $this->call('/reya/slip/upload', $params);
    }

    /**
     * Upload payment slip via multipart/form-data (matches Odoo spec: POST /reya/slip/upload)
     *
     * @param string $lineUserId  LINE User ID ของลูกค้า
     * @param string $imageData   Binary image data (jpg/png/pdf)
     * @param string $filename    ชื่อไฟล์ เช่น slip.jpg
     * @param string $mimeType    MIME type เช่น image/jpeg
     * @param array  $options     Optional: amount, transfer_date, invoice_id, order_id
     * @return array Upload result from Odoo
     */
    public function uploadSlipMultipart($lineUserId, $imageData, $filename = 'slip.jpg', $mimeType = 'image/jpeg', $options = [])
    {
        $startTime = microtime(true);

        // Write image data to a temp file so CURLFile can reference it
        $tmpFile = tempnam(sys_get_temp_dir(), 'slip_');
        file_put_contents($tmpFile, $imageData);

        try {
            $postFields = [
                'line_user_id' => $lineUserId,
                'file'         => new CURLFile($tmpFile, $mimeType, $filename),
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
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $postFields,
                CURLOPT_HTTPHEADER     => [
                    'X-Api-Key: ' . $this->apiKey,
                    'X-Requested-With: XMLHttpRequest',
                    'X-CSRF-Token: 1',   // Odoo Werkzeug: non-empty value bypasses CSRF check
                ],
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);

            $response     = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError    = curl_error($ch);
            $contentType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            $duration = round((microtime(true) - $startTime) * 1000);

            if ($response === false) {
                throw new Exception('NETWORK_ERROR: ' . $curlError);
            }

            // Always log raw response for diagnosis
            error_log("[OdooAPIClient::uploadSlipMultipart] URL=$uploadUrl HTTP=$httpCode CT=$contentType RAW=" . mb_substr($response, 0, 500));

            $data = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("HTTP $httpCode (Content-Type: $contentType): Odoo returned non-JSON — " . mb_substr($response, 0, 500));
            }

            $this->logApiCall('/reya/slip/upload', ['line_user_id' => $lineUserId], $data, $httpCode, $duration);

            if ($httpCode >= 400) {
                $odooMsg = $data['message'] ?? ($data['error'] ?? null);
                $detail  = $odooMsg ? "$odooMsg" : mb_substr($response, 0, 300);
                throw new Exception("HTTP $httpCode: $detail");
            }

            return $data;

        } finally {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * Get payment status
     * 
     * @param string $lineUserId LINE user ID
     * @param int|null $orderId Order ID
     * @param int|null $bdoId BDO ID
     * @param int|null $invoiceId Invoice ID
     * @return array Payment status
     */
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
    // BDO Methods (Staging-ready endpoints)
    // ========================================================================

    /**
     * Get BDO list for a customer
     * 
     * @param string $lineUserId LINE user ID
     * @param array $options Filter options (state, limit, offset)
     * @return array BDO list
     */
    public function getBdoList($lineUserId, $options = [])
    {
        $params = array_merge(['line_user_id' => $lineUserId], $options);
        return $this->call('/reya/bdo/list', $params);
    }

    /**
     * Get BDO detail (SO lines, invoices, credit notes, deposits, summary)
     * 
     * @param string $lineUserId LINE user ID
     * @param int $bdoId BDO ID
     * @return array BDO detail
     */
    public function getBdoDetail($lineUserId, $bdoId)
    {
        return $this->call('/reya/bdo/detail', [
            'line_user_id' => $lineUserId,
            'bdo_id' => (int) $bdoId
        ]);
    }

    /**
     * Download BDO Statement PDF from Odoo
     * 
     * Uses GET with api_key query param (binary response).
     * Returns raw PDF binary or throws on error.
     * 
     * @param int $bdoId BDO ID
     * @return string Raw PDF binary data
     * @throws Exception on error
     */
    public function getStatementPdf($bdoId)
    {
        $url = $this->baseUrl . '/reya/bdo/statement-pdf/' . (int) $bdoId . '?api_key=' . urlencode($this->apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
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
            throw new Exception("Statement PDF download failed: HTTP $httpCode");
        }

        if (strpos($contentType, 'application/pdf') === false && strpos($contentType, 'application/octet-stream') === false) {
            throw new Exception("Unexpected content type: $contentType");
        }

        return $response;
    }

    // ========================================================================
    // Slip ↔ BDO Matching Methods (Staging-ready endpoints)
    // ========================================================================

    /**
     * Match a slip to one or more BDOs
     * 
     * Sales กดจับคู่ใน Re-Ya Dashboard → ส่งผลไป Odoo
     * 
     * @param string $lineUserId LINE user ID
     * @param int $slipInboxId Slip Inbox record ID from Odoo
     * @param array $matches Array of [{bdo_id: int, amount: float}, ...]
     * @param string $note Optional note
     * @return array Match result from Odoo
     */
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

    /**
     * Unmatch a slip (ยกเลิกการจับคู่)
     * 
     * @param string $lineUserId LINE user ID
     * @param int $slipInboxId Slip Inbox record ID from Odoo
     * @param string $reason Reason for unmatch
     * @return array Unmatch result from Odoo
     */
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

    /**
     * Get salesperson dashboard
     * 
     * @param string $lineUserId LINE user ID
     * @param string $period Period (today, week, month)
     * @return array Dashboard data
     */
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
     * Handle API error response
     * 
     * @param array $response Error response
     * @param int $httpCode HTTP status code
     * @throws Exception
     */
    private function handleError($response, $httpCode = 0)
    {
        $errorCode = $response['error']['code'] ?? 'UNKNOWN_ERROR';
        $errorMessage = $response['error']['message'] ?? 'Unknown error';

        // Get Thai error message
        $thaiMessage = self::ERROR_MESSAGES[$errorCode] ?? self::ERROR_MESSAGES['UNKNOWN_ERROR'];

        throw new Exception($thaiMessage . ' (' . $errorCode . ')', $httpCode);
    }

    /**
     * Check rate limit
     * 
     * @return bool True if within rate limit
     */
    private function checkRateLimit()
    {
        try {
            $key = 'odoo_api_rate_limit';
            $now = time();
            $window = 60; // 1 minute

            // Get current count from database
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count 
                FROM odoo_api_logs 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $result['count'] ?? 0;

            return $count < $this->rateLimit;

        } catch (Exception $e) {
            // If rate limit check fails, allow the request
            return true;
        }
    }

    /**
     * Log API call to database (optional)
     * 
     * @param string $endpoint Endpoint called
     * @param array $params Request parameters
     * @param array $response Response data
     * @param int $statusCode HTTP status code
     * @param int $duration Duration in milliseconds
     * @param string|null $error Error message if failed
     */
    private function logApiCall($endpoint, $params, $response, $statusCode, $duration, $error = null)
    {
        try {
            // Check if odoo_api_logs table exists
            $stmt = $this->db->query("SHOW TABLES LIKE 'odoo_api_logs'");
            if ($stmt->rowCount() === 0) {
                return; // Table doesn't exist, skip logging
            }

            $stmt = $this->db->prepare("
                INSERT INTO odoo_api_logs 
                (line_account_id, endpoint, method, request_params, response_data, 
                 status_code, error_message, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt->execute([
                $this->lineAccountId,
                $endpoint,
                'POST',
                json_encode(class_exists('SecurityHelper') ? SecurityHelper::sanitizeForLog($params) : $params),
                json_encode(class_exists('SecurityHelper') ? SecurityHelper::sanitizeForLog($response) : $response),
                $statusCode,
                $error,
                $duration
            ]);
        } catch (Exception $e) {
            // Logging failed, but don't throw error
            error_log('Failed to log Odoo API call: ' . $e->getMessage());
        }
    }
}
