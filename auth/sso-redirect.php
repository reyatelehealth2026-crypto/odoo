<?php
/**
 * SSO Redirect - สร้าง SSO Token และ Redirect ไป Next.js Inbox
 * 
 * ใช้เมื่อต้องการส่งผู้ใช้จากระบบ PHP ไป Next.js โดยไม่ต้อง login ใหม่
 * 
 * Flow:
 * 1. ตรวจสอบว่า user login แล้วใน PHP session
 * 2. สร้าง SSO token (HMAC-SHA256 signed)
 * 3. Redirect ไป Next.js /api/auth/sso?token=xxx
 */

require_once __DIR__ . '/../config/sso_config.php';

/**
 * สร้าง SSO Token สำหรับ user ที่ login แล้ว
 * 
 * @param array $user ข้อมูลผู้ใช้จาก $_SESSION['admin_user']
 * @return string Base64-encoded token
 */
function generateSSOToken(array $user): string
{
    $now = time();

    // Payload data
    $payload = [
        'user_id' => (int) $user['id'],
        'username' => $user['username'] ?? '',
        'email' => $user['email'] ?? '',
        'role' => $user['role'] ?? 'admin',
        'line_account_id' => (int) ($user['line_account_id'] ?? 0),
        'iat' => $now,                          // issued at
        'exp' => $now + SSO_TOKEN_EXPIRY,       // expiry
        'nonce' => bin2hex(random_bytes(16)),      // unique nonce for replay protection
    ];

    // Encode payload
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $payloadBase64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');

    // Create HMAC-SHA256 signature
    $signature = hash_hmac('sha256', $payloadBase64, SSO_SECRET_KEY);

    // Token = payload.signature
    $token = $payloadBase64 . '.' . $signature;

    return $token;
}

/**
 * สร้าง SSO URL สำหรับ redirect ไป Next.js
 * 
 * @param array $user ข้อมูลผู้ใช้จาก $_SESSION['admin_user']
 * @param string|null $redirectPath path ปลายทางหลัง login (เช่น /dashboard)
 * @return string Full URL สำหรับ redirect
 */
function buildSSORedirectUrl(array $user, ?string $redirectPath = null): string
{
    $token = generateSSOToken($user);

    $url = NEXT_INBOX_URL . '/api/auth/sso?token=' . urlencode($token);

    if ($redirectPath) {
        $url .= '&redirect=' . urlencode($redirectPath);
    }

    return $url;
}

/**
 * ทำ SSO redirect ไป Next.js
 * ต้องเรียกก่อน output ใดๆ (ก่อน echo/print/HTML)
 * 
 * @param array $user ข้อมูลผู้ใช้จาก $_SESSION['admin_user']
 * @param string|null $redirectPath path ปลายทางหลัง login
 */
function performSSORedirect(array $user, ?string $redirectPath = null): void
{
    $url = buildSSORedirectUrl($user, $redirectPath);
    header('Location: ' . $url);
    exit;
}
