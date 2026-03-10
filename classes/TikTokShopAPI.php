<?php
/**
 * TikTok Shop Customer Service / Messaging API
 *
 * Handles sending/receiving messages via TikTok Shop Partner API v2.
 * All requests are signed with HmacSHA256 as required by TikTok.
 *
 * Reference: https://partner.tiktokshop.com/docv2/page/customer-service-api-overview
 */

declare(strict_types=1);

class TikTokShopAPI
{
    private string $shopId;
    private string $appKey;
    private string $appSecret;
    private string $accessToken;
    private ?string $shopCipher;

    private const API_BASE_URL = 'https://open-api.tiktokglobalshop.com';
    private const API_VERSION  = '202309';

    public function __construct(array $account)
    {
        $this->shopId      = $account['shop_id'];
        $this->appKey      = $account['app_key'];
        $this->appSecret   = $account['app_secret'];
        $this->accessToken = $account['access_token'];
        $this->shopCipher  = $account['shop_cipher'] ?? null;
    }

    // -----------------------------------------------------------------------
    // Webhook validation
    // -----------------------------------------------------------------------

    /**
     * Verify a TikTok Shop webhook request.
     *
     * TikTok signs the body with HmacSHA256 using app_secret and sends
     * the signature in the "Webhook-Signature" header.
     *
     * @param string $rawBody   Raw request body
     * @param string $signature Value of Webhook-Signature header
     * @param string $timestamp Value of Webhook-Timestamp header
     */
    public function validateWebhook(string $rawBody, string $signature, string $timestamp): bool
    {
        // TikTok signature format: HMAC-SHA256(timestamp + rawBody, app_secret)
        $message  = $timestamp . $rawBody;
        $expected = hash_hmac('sha256', $message, $this->appSecret);
        return hash_equals($expected, strtolower($signature));
    }

    // -----------------------------------------------------------------------
    // Messaging
    // -----------------------------------------------------------------------

    /**
     * Send a text message to a buyer in an existing conversation.
     *
     * @param string $conversationId TikTok conversation ID
     * @param string $content        Message text
     */
    public function sendMessage(string $conversationId, string $content): array
    {
        $path = '/customer_service/conversations/messages/send';
        $body = [
            'conversation_id' => $conversationId,
            'message'         => [
                'content_type' => 'TEXT',
                'content'      => $content,
            ],
        ];

        return $this->post($path, $body);
    }

    /**
     * Send an image message to a buyer.
     *
     * @param string $conversationId TikTok conversation ID
     * @param string $imageUrl       Public URL of the image
     */
    public function sendImageMessage(string $conversationId, string $imageUrl): array
    {
        $path = '/customer_service/conversations/messages/send';
        $body = [
            'conversation_id' => $conversationId,
            'message'         => [
                'content_type' => 'IMAGE',
                'content'      => $imageUrl,
            ],
        ];

        return $this->post($path, $body);
    }

    /**
     * Get messages in a conversation (paginated).
     *
     * @param string      $conversationId TikTok conversation ID
     * @param int         $pageSize       Number of messages per page (max 50)
     * @param string|null $cursor         Pagination cursor from previous response
     */
    public function getConversationMessages(
        string $conversationId,
        int $pageSize = 20,
        ?string $cursor = null
    ): array {
        $path   = '/customer_service/conversations/messages';
        $params = [
            'conversation_id' => $conversationId,
            'page_size'       => $pageSize,
        ];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get($path, $params);
    }

    /**
     * List open conversations for the shop (paginated).
     *
     * @param int         $pageSize Number of conversations per page (max 50)
     * @param string|null $cursor   Pagination cursor
     */
    public function getConversations(int $pageSize = 20, ?string $cursor = null): array
    {
        $path   = '/customer_service/conversations';
        $params = ['page_size' => $pageSize];
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        return $this->get($path, $params);
    }

    /**
     * Get a buyer's profile information.
     *
     * @param string $buyerUserId TikTok buyer_user_id
     */
    public function getBuyerProfile(string $buyerUserId): array
    {
        $path   = '/customer_service/buyers/profile';
        $params = ['buyer_user_id' => $buyerUserId];

        return $this->get($path, $params);
    }

    // -----------------------------------------------------------------------
    // Token management
    // -----------------------------------------------------------------------

    /**
     * Refresh the access token using the stored refresh token.
     *
     * @param string $refreshToken Current refresh token
     */
    public function refreshToken(string $refreshToken): array
    {
        $path = '/api/v2/token/refresh';
        $body = [
            'app_key'       => $this->appKey,
            'app_secret'    => $this->appSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ];

        // Token refresh uses a different base URL and does not require signing
        $url      = 'https://auth.tiktok-shops.com' . $path;
        $response = $this->rawPost($url, $body);

        if (isset($response['data']['access_token'])) {
            $this->accessToken = $response['data']['access_token'];
        }

        return $response;
    }

    // -----------------------------------------------------------------------
    // Request signing
    // -----------------------------------------------------------------------

    /**
     * Build the HMAC-SHA256 signature required by TikTok Shop API v2.
     *
     * Signature = HMAC-SHA256(
     *   app_secret,
     *   path + sorted_query_params_string + body_string
     * )
     *
     * @param string $path        API path (e.g. /customer_service/conversations)
     * @param array  $queryParams Query parameters (excluding access_token, sign, timestamp)
     * @param string $bodyString  JSON-encoded request body (empty string for GET)
     * @param string $timestamp   Unix timestamp string
     */
    public function signRequest(
        string $path,
        array $queryParams,
        string $bodyString,
        string $timestamp
    ): string {
        // Sort params by key
        ksort($queryParams);

        // Build param string: key1value1key2value2...
        $paramStr = '';
        foreach ($queryParams as $k => $v) {
            $paramStr .= $k . $v;
        }

        $toSign = $path . $paramStr . $bodyString;
        return hash_hmac('sha256', $toSign, $this->appSecret);
    }

    // -----------------------------------------------------------------------
    // Internal HTTP helpers
    // -----------------------------------------------------------------------

    /**
     * Perform a signed GET request.
     */
    private function get(string $path, array $params = []): array
    {
        $timestamp = (string) time();

        // Common params added to every request
        $allParams = array_merge($params, [
            'app_key'      => $this->appKey,
            'access_token' => $this->accessToken,
            'timestamp'    => $timestamp,
            'version'      => self::API_VERSION,
        ]);

        if ($this->shopCipher !== null) {
            $allParams['shop_cipher'] = $this->shopCipher;
        }

        // Sign (exclude access_token from signature params per TikTok docs)
        $signParams = $allParams;
        unset($signParams['access_token']);
        $allParams['sign'] = $this->signRequest($path, $signParams, '', $timestamp);

        $url = self::API_BASE_URL . $path . '?' . http_build_query($allParams);

        return $this->rawGet($url);
    }

    /**
     * Perform a signed POST request.
     */
    private function post(string $path, array $body = []): array
    {
        $timestamp  = (string) time();
        $bodyString = json_encode($body);

        $queryParams = [
            'app_key'      => $this->appKey,
            'access_token' => $this->accessToken,
            'timestamp'    => $timestamp,
            'version'      => self::API_VERSION,
        ];

        if ($this->shopCipher !== null) {
            $queryParams['shop_cipher'] = $this->shopCipher;
        }

        // Sign (exclude access_token from signature params)
        $signParams = $queryParams;
        unset($signParams['access_token']);
        $queryParams['sign'] = $this->signRequest($path, $signParams, $bodyString, $timestamp);

        $url = self::API_BASE_URL . $path . '?' . http_build_query($queryParams);

        return $this->rawPost($url, $body);
    }

    private function rawGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return $this->parseResponse($response, $httpCode, $curlErr);
    }

    private function rawPost(string $url, array $body): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return $this->parseResponse($response, $httpCode, $curlErr);
    }

    private function parseResponse(string|false $response, int $httpCode, string $curlErr): array
    {
        if ($curlErr) {
            error_log("TikTokShopAPI cURL error: {$curlErr}");
            return ['error' => $curlErr, 'success' => false];
        }

        $decoded = json_decode((string) $response, true) ?? [];
        $decoded['http_code'] = $httpCode;

        // TikTok uses code=0 for success
        $decoded['success'] = ($httpCode >= 200 && $httpCode < 300)
            && (($decoded['code'] ?? -1) === 0 || ($decoded['code'] ?? -1) === '0');

        if (!$decoded['success']) {
            error_log("TikTokShopAPI error ({$httpCode}): {$response}");
        }

        return $decoded;
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    public function getShopId(): string
    {
        return $this->shopId;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }
}
