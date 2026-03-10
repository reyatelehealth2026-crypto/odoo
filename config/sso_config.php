<?php
/**
 * SSO Configuration - Shared between PHP and Next.js
 * 
 * ⚠️ IMPORTANT: The SSO_SECRET_KEY must be the SAME value
 * in both this file AND the Next.js .env file (SSO_SECRET_KEY)
 */

// Shared secret key for HMAC-SHA256 signing
// ⚠️ เปลี่ยนค่านี้ใน production และเก็บเป็นความลับ
define('SSO_SECRET_KEY', '2P3ML7fnhGIwqQ1jj+8xb0wrxQL/8JFXTfdWt48yJcg=');

// Next.js Inbox URL (Cloudflare Pages deployment)
// ⚠️ เปลี่ยนเป็น URL จริงของ Next.js inbox
define('NEXT_INBOX_URL', 'https://inbox.re-ya.com');

// SSO Token expiry in seconds (30 seconds - short for security)
define('SSO_TOKEN_EXPIRY', 30);
