<?php
/**
 * Facebook Messenger Platform API
 *
 * Handles sending/receiving messages via Meta Graph API.
 * Mirrors the interface of LineAPI so it can be used interchangeably
 * in the platform-routing layer.
 */

declare(strict_types=1);

class FacebookMessengerAPI
{
    private string $pageId;
    private string $appId;
    private string $appSecret;
    private string $pageAccessToken;
    private string $verifyToken;

    private const GRAPH_API_VERSION = 'v19.0';
    private const GRAPH_BASE_URL    = 'https://graph.facebook.com';

    public function __construct(array $account)
    {
        $this->pageId           = $account['page_id'];
        $this->appId            = $account['app_id'];
        $this->appSecret        = $account['app_secret'];
        $this->pageAccessToken  = $account['page_access_token'];
        $this->verifyToken      = $account['verify_token'];
    }

    // -----------------------------------------------------------------------
    // Webhook helpers
    // -----------------------------------------------------------------------

    /**
     * Validate the X-Hub-Signature-256 header sent by Meta.
     *
     * @param string $rawBody   Raw request body (before json_decode)
     * @param string $signature Value of X-Hub-Signature-256 header
     */
    public function validateSignature(string $rawBody, string $signature): bool
    {
        if (!str_starts_with($signature, 'sha256=')) {
            return false;
        }
        $hash     = substr($signature, 7);
        $expected = hash_hmac('sha256', $rawBody, $this->appSecret);
        return hash_equals($expected, $hash);
    }

    /**
     * Validate the hub.verify_token during webhook subscription.
     */
    public function validateVerifyToken(string $token): bool
    {
        return hash_equals($this->verifyToken, $token);
    }

    // -----------------------------------------------------------------------
    // Sending messages
    // -----------------------------------------------------------------------

    /**
     * Send a text message to a user identified by their Page-Scoped ID (PSID).
     */
    public function sendTextMessage(string $psid, string $text): array
    {
        return $this->sendMessage($psid, [
            'text' => $text,
        ]);
    }

    /**
     * Send an image message by URL.
     */
    public function sendImageMessage(string $psid, string $imageUrl): array
    {
        return $this->sendMessage($psid, [
            'attachment' => [
                'type'    => 'image',
                'payload' => [
                    'url'         => $imageUrl,
                    'is_reusable' => true,
                ],
            ],
        ]);
    }

    /**
     * Send a file (document) message by URL.
     */
    public function sendFileMessage(string $psid, string $fileUrl, string $filename = ''): array
    {
        return $this->sendMessage($psid, [
            'attachment' => [
                'type'    => 'file',
                'payload' => [
                    'url'         => $fileUrl,
                    'is_reusable' => true,
                ],
            ],
        ]);
    }

    /**
     * Core send method – POST to /me/messages Graph API endpoint.
     *
     * @param string $psid    Recipient Page-Scoped ID
     * @param array  $message Message object (text or attachment)
     */
    public function sendMessage(string $psid, array $message): array
    {
        $url  = $this->buildUrl('/me/messages');
        $body = [
            'recipient'      => ['id' => $psid],
            'message'        => $message,
            'messaging_type' => 'RESPONSE',
        ];

        return $this->request('POST', $url, $body);
    }

    /**
     * Send a read receipt (marks messages as seen).
     */
    public function markAsRead(string $psid): array
    {
        $url  = $this->buildUrl('/me/messages');
        $body = [
            'recipient'     => ['id' => $psid],
            'sender_action' => 'mark_seen',
        ];

        return $this->request('POST', $url, $body);
    }

    /**
     * Send a typing indicator.
     */
    public function sendTypingOn(string $psid): array
    {
        $url  = $this->buildUrl('/me/messages');
        $body = [
            'recipient'     => ['id' => $psid],
            'sender_action' => 'typing_on',
        ];

        return $this->request('POST', $url, $body);
    }

    // -----------------------------------------------------------------------
    // User profile
    // -----------------------------------------------------------------------

    /**
     * Retrieve a user's public profile from the Graph API.
     *
     * Returns: name, first_name, last_name, profile_pic
     */
    public function getProfile(string $psid): array
    {
        $url = $this->buildUrl("/{$psid}");
        $url .= '&fields=name,first_name,last_name,profile_pic';

        return $this->request('GET', $url);
    }

    // -----------------------------------------------------------------------
    // Internal HTTP helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Graph API URL with the page access token appended.
     */
    private function buildUrl(string $path): string
    {
        $base = self::GRAPH_BASE_URL . '/' . self::GRAPH_API_VERSION . $path;
        $sep  = str_contains($path, '?') ? '&' : '?';
        return $base . $sep . 'access_token=' . urlencode($this->pageAccessToken);
    }

    /**
     * Execute an HTTP request against the Graph API.
     */
    private function request(string $method, string $url, array $body = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("FacebookMessengerAPI cURL error: {$curlErr}");
            return ['error' => $curlErr, 'success' => false];
        }

        $decoded = json_decode($response, true) ?? [];
        $decoded['http_code'] = $httpCode;
        $decoded['success']   = $httpCode >= 200 && $httpCode < 300 && !isset($decoded['error']);

        if (!$decoded['success']) {
            error_log("FacebookMessengerAPI error ({$httpCode}): {$response}");
        }

        return $decoded;
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    public function getPageId(): string
    {
        return $this->pageId;
    }
}
