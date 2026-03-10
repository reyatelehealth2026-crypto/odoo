<?php
/**
 * CNY Odoo ERP API Client
 * สำหรับเชื่อมต่อกับ CNY Odoo 11 ERP API (erp.cnyrxapp.com)
 * Module: ineco_pps_sale_order_api
 */

class CnyOdooAPI
{
    private $baseUrl;
    private $apiUser;
    private $userToken;
    private $timeout = 30;
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db;
        $this->baseUrl = rtrim(defined('ODOO_API_BASE_URL') ? ODOO_API_BASE_URL : 'https://erp.cnyrxapp.com', '/');
        $this->apiUser = defined('CNY_ODOO_API_USER') ? CNY_ODOO_API_USER : 'webapi_user2@cny.co';
        $this->userToken = defined('CNY_ODOO_USER_TOKEN') ? CNY_ODOO_USER_TOKEN : '@ewNI*4X*/4t9vgMds2Gzs3j=VG%q%ERYM-1A/utT0#CUZ&UR&$pwvuxj!MNUcruJ@RZ/p7$uN*fdqE6xktQdGxGov%?L0@@CekhyzeROSv2/qmj&%G-vlHq$4V8&AHC/XkxI$Tgkbq3p/6faPvf8wjIP#hfZM7GimVkXbvpvsrfZKOCGk?ldTpL9=5qI-eCVs29xIyr0';
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
     * Make API request
     */
    private function request($method, $endpoint, $data = null)
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Api-User: ' . $this->apiUser,
                'User-Token: ' . $this->userToken
            ]
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg(),
                'raw_response' => substr($response, 0, 500)
            ];
        }

        // Handle Odoo JSON-RPC format
        // Response format: {"jsonrpc": "2.0", "id": null, "result": {...}}
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
            'api_user' => $this->apiUser
        ];
    }

    // ==================== PRODUCT APIs ====================

    /**
     * 1. Get Product by product code
     */
    public function getProduct($productCode)
    {
        return $this->request('POST', '/ineco_gc/get_product', [
            'PRODUCT_CODE' => $productCode
        ]);
    }

    /**
     * 2. Get SKU information by product code
     */
    public function getSku($productCode)
    {
        return $this->request('POST', '/ineco_gc/get_sku', [
            'PRODUCT_CODE' => $productCode
        ]);
    }

    // ==================== PARTNER APIs ====================

    /**
     * 3. Get Partner by partner code
     */
    public function getPartner($partnerCode)
    {
        return $this->request('POST', '/ineco_gc/get_partner', [
            'PARTNER_CODE' => $partnerCode
        ]);
    }

    /**
     * 4. Get Partner Details by partner ID
     */
    public function getPartnerDetails($partnerId)
    {
        return $this->request('POST', '/ineco_gc/get_partner_details', [
            'PARTNER_ID' => intval($partnerId)
        ]);
    }

    // ==================== SALE ORDER APIs ====================

    /**
     * 5. Create Sale Order
     */
    public function createSaleOrder($orderData)
    {
        // Validate required fields
        $required = ['order_ref', 'marketplace', 'customer_order', 'order_line'];
        foreach ($required as $field) {
            if (empty($orderData[$field])) {
                return ['success' => false, 'error' => "Missing required field: {$field}"];
            }
        }

        // Build order structure
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

    /**
     * Helper: Create simple sale order
     */
    public function createSimpleSaleOrder($orderRef, $partnerId, $partnerCode, $items, $options = [])
    {
        // Calculate totals
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

        // Calculate tax (7% VAT)
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

    /**
     * 6. Get Sale Order by order reference
     */
    public function getSaleOrder($orderRef)
    {
        return $this->request('POST', '/ineco_gc/get_sale_order', [
            'ORDER_REF' => $orderRef
        ]);
    }

    // ==================== INVOICE APIs ====================

    /**
     * 7. Get Sale Invoice by invoice number
     */
    public function getSaleInvoice($invoiceNumber)
    {
        return $this->request('POST', '/ineco_gc/get_sale_invoice', [
            'INVOICE_NUMBER' => $invoiceNumber
        ]);
    }

    // ==================== DELIVERY APIs ====================

    /**
     * 8. Update Delivery Fee for an order
     */
    public function updateDeliveryFee($orderId, $deliveryFee)
    {
        return $this->request('POST', '/ineco_gc/update_delivery_fee', [
            'order_id' => intval($orderId),
            'delivery_fee' => floatval($deliveryFee)
        ]);
    }

    /**
     * 9. Calculate Delivery Fee based on province and weight
     */
    public function calculateDeliveryFee($province, $weight)
    {
        return $this->request('POST', '/ineco_gc/calculate_delivery_fee', [
            'province' => $province,
            'weight' => floatval($weight)
        ]);
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Get API info
     */
    public function getApiInfo()
    {
        return [
            'name' => 'CNY Odoo ERP API',
            'version' => '1.0',
            'base_url' => $this->baseUrl,
            'api_user' => $this->apiUser,
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
