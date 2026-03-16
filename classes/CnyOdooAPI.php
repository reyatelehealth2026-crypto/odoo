<?php
/**
 * CNY Odoo ERP API Client
 * สำหรับเชื่อมต่อกับ CNY Odoo 11 ERP API (erp.cnyrxapp.com)
 * Module: ineco_pps_sale_order_api
 *
 * @version 2.0.0
 * @updated 2026-03-16 — Added retry with exponential backoff, circuit breaker,
 *          connection reuse, connect timeout, and optional logging.
 */

require_once __DIR__ . '/OdooCircuitBreaker.php';

class CnyOdooAPI
{
    private $baseUrl;
    private $apiUser;
    private $userToken;
    private $timeout = 30;
    private $connectTimeout = 5;
    private $db;

    /** @var OdooCircuitBreaker */
    private $circuitBreaker;

    /** Reusable cURL handle */
    private $curlHandle = null;

    private const MAX_RETRIES = 2;
    private const RETRY_BASE_DELAY_MS = 250;

    public function __construct($db = null)
    {
        $this->db = $db;
        $this->baseUrl = rtrim(defined('ODOO_API_BASE_URL') ? ODOO_API_BASE_URL : '', '/');
        $this->apiUser = defined('CNY_ODOO_API_USER') ? CNY_ODOO_API_USER : '';
        $this->userToken = defined('CNY_ODOO_USER_TOKEN') ? CNY_ODOO_USER_TOKEN : '';

        $this->circuitBreaker = new OdooCircuitBreaker(
            'odoo_cny',
            5,   // open after 5 failures
            45,  // probe again after 45s
            2
        );
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    /**
     * Set custom API credentials
     */
    public function setCredentials($baseUrl, $apiUser, $userToken)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiUser = $apiUser;
        $this->userToken = $userToken;
        return $this;
    }

    /**
     * Make API request with retry, circuit breaker, and connection reuse.
     */
    private function request($method, $endpoint, $data = null)
    {
        if (!$this->circuitBreaker->isAvailable()) {
            return [
                'success' => false,
                'error' => 'ระบบ Odoo CNY ไม่พร้อมใช้งานชั่วคราว กรุณาลองใหม่ในอีกสักครู่',
                'circuit_breaker' => 'open'
            ];
        }

        $lastResult = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $this->sleepWithBackoff($attempt);
            }

            $startTime = microtime(true);
            $result = $this->doRequest($method, $endpoint, $data);
            $duration = round((microtime(true) - $startTime) * 1000);

            // Log the API call
            $this->logApiCall($endpoint, $data, $result, $duration);

            if ($result['success'] ?? false) {
                $this->circuitBreaker->recordSuccess();
                return $result;
            }

            $lastResult = $result;

            // Determine if the error is retriable
            $httpCode = $result['http_code'] ?? 0;
            $errorMsg = $result['error'] ?? '';

            if (!$this->isRetriable($httpCode, $errorMsg)) {
                break;
            }

            if ($attempt === self::MAX_RETRIES) {
                $this->circuitBreaker->recordFailure($errorMsg);
            }
        }

        return $lastResult ?? ['success' => false, 'error' => 'Unknown error'];
    }

    /**
     * Execute a single HTTP request.
     */
    private function doRequest($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;

        $ch = $this->getCurlHandle();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Api-User: ' . $this->apiUser,
                'User-Token: ' . $this->userToken,
                'Connection: keep-alive',
            ],
            CURLOPT_ENCODING => '', // Accept gzip/deflate
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
            CURLOPT_TCP_KEEPINTVL => 15,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error, 'http_code' => 0];
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'raw_response' => substr($response, 0, 500)
            ];
        }

        // Handle Odoo JSON-RPC format
        if (isset($decoded['jsonrpc']) && isset($decoded['result'])) {
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $decoded['result']
            ];
        }

        // Handle Odoo error format
        if (isset($decoded['jsonrpc']) && isset($decoded['error'])) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'error' => $decoded['error']['message'] ?? 'Unknown error',
                'data' => $decoded['error']
            ];
        }

        return [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'data' => $decoded
        ];
    }

    private function getCurlHandle()
    {
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }
        curl_reset($this->curlHandle);
        return $this->curlHandle;
    }

    private function isRetriable(int $httpCode, string $errorMsg): bool
    {
        if ($httpCode >= 500 || $httpCode === 429 || $httpCode === 0) {
            return true;
        }

        $retriablePatterns = ['timeout', 'timed out', 'connection reset', 'connection refused', 'curl error'];
        $lowerError = strtolower($errorMsg);
        foreach ($retriablePatterns as $pattern) {
            if (str_contains($lowerError, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function sleepWithBackoff(int $attempt): void
    {
        $baseMs = self::RETRY_BASE_DELAY_MS * pow(2, $attempt - 1);
        $jitterMs = mt_rand(0, (int) ($baseMs * 0.3));
        $sleepMs = min($baseMs + $jitterMs, 3000);
        usleep($sleepMs * 1000);
    }

    private function logApiCall($endpoint, $data, $result, $duration)
    {
        if ($this->db === null) {
            return;
        }

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

            $stmt->execute([
                null,
                $endpoint,
                'POST',
                json_encode($data ?? []),
                json_encode(array_intersect_key($result, array_flip(['success', 'http_code', 'error']))),
                $result['http_code'] ?? 0,
                ($result['success'] ?? false) ? null : ($result['error'] ?? null),
                $duration
            ]);
        } catch (Exception $e) {
            // Don't let logging failures affect main flow
        }
    }

    /**
     * Test API connection
     */
    public function testConnection()
    {
        $result = $this->getProduct('0001');
        return [
            'success' => $result['success'] ?? false,
            'message' => $result['success'] ? 'เชื่อมต่อสำเร็จ' : ($result['error'] ?? 'ไม่สามารถเชื่อมต่อได้'),
            'base_url' => $this->baseUrl,
            'api_user' => $this->apiUser,
            'circuit_breaker' => $this->circuitBreaker->getStatus(),
        ];
    }

    /**
     * Get circuit breaker status for monitoring
     */
    public function getCircuitBreakerStatus(): array
    {
        return $this->circuitBreaker->getStatus();
    }

    // ==================== PRODUCT APIs ====================

    public function getProduct($productCode)
    {
        return $this->request('POST', '/ineco_gc/get_product', [
            'PRODUCT_CODE' => $productCode
        ]);
    }

    public function getSku($productCode)
    {
        return $this->request('POST', '/ineco_gc/get_sku', [
            'PRODUCT_CODE' => $productCode
        ]);
    }

    // ==================== PARTNER APIs ====================

    public function getPartner($partnerCode)
    {
        return $this->request('POST', '/ineco_gc/get_partner', [
            'PARTNER_CODE' => $partnerCode
        ]);
    }

    public function getPartnerDetails($partnerId)
    {
        return $this->request('POST', '/ineco_gc/get_partner_details', [
            'PARTNER_ID' => intval($partnerId)
        ]);
    }

    // ==================== SALE ORDER APIs ====================

    public function createSaleOrder($orderData)
    {
        $required = ['order_ref', 'marketplace', 'customer_order', 'order_line'];
        foreach ($required as $field) {
            if (empty($orderData[$field])) {
                return ['success' => false, 'error' => "Missing required field: {$field}"];
            }
        }

        $order = [
            'order_ref' => $orderData['order_ref'],
            'marketplace' => $orderData['marketplace'] ?? 'WEBSITE',
            'marketplace_shop_name' => $orderData['marketplace_shop_name'] ?? 'CNYPHARMACY.COM',
            'payment_data' => $orderData['payment_data'] ?? 'COD',
            'customer_order' => $orderData['customer_order'],
            'customer_delivery_address' => $orderData['customer_delivery_address'] ?? [],
            'order_line' => $orderData['order_line'],
            'order_bottom_amount' => $orderData['order_bottom_amount'] ?? []
        ];

        return $this->request('POST', '/ineco_gc/create_sale_order', $order);
    }

    public function createSimpleSaleOrder($orderRef, $partnerId, $partnerCode, $items, $options = [])
    {
        $sumSubtotal = 0;
        $orderLines = [];

        foreach ($items as $item) {
            $subtotal = ($item['qty'] ?? 1) * ($item['price_unit'] ?? 0);
            $discount = $item['discount'] ?? 0;
            $subtotalAfterDiscount = $subtotal * (1 - $discount / 100);

            $orderLines[] = [
                'product_id' => $item['product_id'],
                'qty' => $item['qty'] ?? 1,
                'price_unit' => $item['price_unit'] ?? 0,
                'discount' => $discount,
                'price_subtotal' => $subtotalAfterDiscount
            ];

            $sumSubtotal += $subtotalAfterDiscount;
        }

        $discountAmount = $options['discount_amount'] ?? 0;
        $amountAfterDiscount = $sumSubtotal - $discountAmount;
        $amountUntax = round($amountAfterDiscount / 1.07, 2);
        $taxed = round($amountAfterDiscount - $amountUntax, 2);

        $orderData = [
            'order_ref' => $orderRef,
            'marketplace' => $options['marketplace'] ?? 'LINE',
            'marketplace_shop_name' => $options['marketplace_shop_name'] ?? 'LINE OA',
            'payment_data' => $options['payment_data'] ?? 'COD',
            'customer_order' => [
                'partner_id' => intval($partnerId),
                'partner_code' => $partnerCode
            ],
            'customer_delivery_address' => [
                'partner_shipping_address_id' => $options['shipping_address_id'] ?? $partnerId,
                'partner_shipping_address_code' => $options['shipping_address_code'] ?? $partnerCode . '-01'
            ],
            'order_line' => $orderLines,
            'order_bottom_amount' => [
                [
                    'sum_price_subtotal' => $sumSubtotal,
                    'discount_amount' => $discountAmount,
                    'amount_after_discount' => $amountAfterDiscount,
                    'amount_untax' => $amountUntax,
                    'taxed' => $taxed,
                    'total_amount' => $amountAfterDiscount
                ]
            ]
        ];

        return $this->createSaleOrder($orderData);
    }

    public function getSaleOrder($orderRef)
    {
        return $this->request('POST', '/ineco_gc/get_sale_order', [
            'ORDER_REF' => $orderRef
        ]);
    }

    // ==================== INVOICE APIs ====================

    public function getSaleInvoice($invoiceNumber)
    {
        return $this->request('POST', '/ineco_gc/get_sale_invoice', [
            'INVOICE_NUMBER' => $invoiceNumber
        ]);
    }

    // ==================== DELIVERY APIs ====================

    public function updateDeliveryFee($orderId, $deliveryFee)
    {
        return $this->request('POST', '/ineco_gc/update_delivery_fee', [
            'order_id' => intval($orderId),
            'delivery_fee' => floatval($deliveryFee)
        ]);
    }

    public function calculateDeliveryFee($province, $weight)
    {
        return $this->request('POST', '/ineco_gc/calculate_delivery_fee', [
            'province' => $province,
            'weight' => floatval($weight)
        ]);
    }

    // ==================== UTILITY METHODS ====================

    public function getApiInfo()
    {
        return [
            'name' => 'CNY Odoo ERP API',
            'version' => '2.0',
            'base_url' => $this->baseUrl,
            'api_user' => $this->apiUser,
            'circuit_breaker' => $this->circuitBreaker->getStatus(),
            'endpoints' => [
                ['name' => 'Get Product', 'path' => '/ineco_gc/get_product', 'method' => 'POST'],
                ['name' => 'Get SKU', 'path' => '/ineco_gc/get_sku', 'method' => 'POST'],
                ['name' => 'Get Partner', 'path' => '/ineco_gc/get_partner', 'method' => 'POST'],
                ['name' => 'Get Partner Details', 'path' => '/ineco_gc/get_partner_details', 'method' => 'POST'],
                ['name' => 'Create Sale Order', 'path' => '/ineco_gc/create_sale_order', 'method' => 'POST'],
                ['name' => 'Get Sale Order', 'path' => '/ineco_gc/get_sale_order', 'method' => 'POST'],
                ['name' => 'Get Sale Invoice', 'path' => '/ineco_gc/get_sale_invoice', 'method' => 'POST'],
                ['name' => 'Update Delivery Fee', 'path' => '/ineco_gc/update_delivery_fee', 'method' => 'POST'],
                ['name' => 'Calculate Delivery Fee', 'path' => '/ineco_gc/calculate_delivery_fee', 'method' => 'POST']
            ]
        ];
    }
}
