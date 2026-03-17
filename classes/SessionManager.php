<?php

/**
 * JWT Session Manager Service
 * 
 * Manages JWT tokens and user sessions with comprehensive tracking
 * Requirements: BR-5.4 (Security & Access Control), NFR-3.4 (Security compliance)
 * 
 * @author Kiro AI Assistant
 * @version 1.0.0
 */
class SessionManager
{
    private $db;
    private $auditLogger;
    private $jwtSecret;
    private $accessTokenTTL = 900; // 15 minutes
    private $refreshTokenTTL = 604800; // 7 days

    public function __construct($database = null, $auditLogger = null)
    {
        $this->db = $database ?: Database::getInstance()->getConnection();
        $this->auditLogger = $auditLogger ?: new AuditLogger($this->db);
        $this->jwtSecret = $this->getJWTSecret();
    }

    /**
     * Create a new user session with JWT tokens
     */
    public function createSession(string $userId, array $deviceInfo = []): array
    {
        try {
            // Generate tokens
            $accessToken = $this->generateAccessToken($userId);
            $refreshToken = $this->generateRefreshToken($userId);
            
            // Hash tokens for storage
            $tokenHash = hash('sha256', $accessToken);
            $refreshTokenHash = hash('sha256', $refreshToken);
            
            // Calculate expiration times
            $expiresAt = date('Y-m-d H:i:s', time() + $this->accessTokenTTL);
            $refreshExpiresAt = date('Y-m-d H:i:s', time() + $this->refreshTokenTTL);
            
            // Store session in database
            $sessionId = $this->generateUUID();
            $stmt = $this->db->prepare("
                INSERT INTO user_sessions (
                    id, user_id, token_hash, refresh_token_hash,
                    expires_at, refresh_expires_at, ip_address, user_agent, device_info
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $sessionId,
                $userId,
                $tokenHash,
                $refreshTokenHash,
                $expiresAt,
                $refreshExpiresAt,
                $this->getClientIpAddress(),
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                !empty($deviceInfo) ? json_encode($deviceInfo, JSON_UNESCAPED_UNICODE) : null
            ]);
            
            // Log session creation
            $this->auditLogger->setUserContext($userId, $sessionId);
            $this->auditLogger->logLogin($userId, true);
            
            return [
                'session_id' => $sessionId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $this->accessTokenTTL,
                'token_type' => 'Bearer'
            ];
            
        } catch (Exception $e) {
            // Log failed session creation
            $this->auditLogger->logLogin($userId, false, $e->getMessage());
            throw new Exception('Failed to create session: ' . $e->getMessage());
        }
    }

    /**
     * Validate and refresh an access token
     */
    public function validateToken(string $token): ?array
    {
        try {
            // Decode JWT token
            $payload = $this->decodeJWT($token);
            if (!$payload) {
                return null;
            }
            
            // Check if token exists in database and is active
            $tokenHash = hash('sha256', $token);
            $stmt = $this->db->prepare("
                SELECT s.*, u.username, u.email, u.role, u.line_account_id
                FROM user_sessions s
                JOIN users u ON s.user_id = u.id
                WHERE s.token_hash = ? 
                AND s.is_active = TRUE 
                AND s.expires_at > NOW()
                AND s.revoked_at IS NULL
            ");
            
            $stmt->execute([$tokenHash]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return null;
            }
            
            // Update last activity
            $this->updateLastActivity($session['id']);
            
            return [
                'user_id' => $session['user_id'],
                'username' => $session['username'],
                'email' => $session['email'],
                'role' => $session['role'],
                'line_account_id' => $session['line_account_id'],
                'session_id' => $session['id'],
                'expires_at' => $session['expires_at']
            ];
            
        } catch (Exception $e) {
            error_log("Token validation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Refresh an access token using refresh token
     */
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $refreshTokenHash = hash('sha256', $refreshToken);
            
            // Find active session with this refresh token
            $stmt = $this->db->prepare("
                SELECT s.*, u.username, u.email, u.role, u.line_account_id
                FROM user_sessions s
                JOIN users u ON s.user_id = u.id
                WHERE s.refresh_token_hash = ? 
                AND s.is_active = TRUE 
                AND s.refresh_expires_at > NOW()
                AND s.revoked_at IS NULL
            ");
            
            $stmt->execute([$refreshTokenHash]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return null;
            }
            
            // Generate new tokens
            $newAccessToken = $this->generateAccessToken($session['user_id']);
            $newRefreshToken = $this->generateRefreshToken($session['user_id']);
            
            $newTokenHash = hash('sha256', $newAccessToken);
            $newRefreshTokenHash = hash('sha256', $newRefreshToken);
            
            // Update session with new tokens
            $newExpiresAt = date('Y-m-d H:i:s', time() + $this->accessTokenTTL);
            $newRefreshExpiresAt = date('Y-m-d H:i:s', time() + $this->refreshTokenTTL);
            
            $stmt = $this->db->prepare("
                UPDATE user_sessions 
                SET token_hash = ?, refresh_token_hash = ?,
                    expires_at = ?, refresh_expires_at = ?,
                    last_activity = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $newTokenHash,
                $newRefreshTokenHash,
                $newExpiresAt,
                $newRefreshExpiresAt,
                $session['id']
            ]);
            
            // Log token refresh
            $this->auditLogger->setUserContext($session['user_id'], $session['id']);
            $this->auditLogger->logTokenRefresh(
                $session['user_id'],
                $session['token_hash'],
                $newTokenHash
            );
            
            return [
                'access_token' => $newAccessToken,
                'refresh_token' => $newRefreshToken,
                'expires_in' => $this->accessTokenTTL,
                'token_type' => 'Bearer'
            ];
            
        } catch (Exception $e) {
            error_log("Token refresh failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Revoke a user session
     */
    public function revokeSession(string $sessionId, string $revokedBy, string $reason = 'user_logout'): bool
    {
        try {
            // Get session info before revoking
            $stmt = $this->db->prepare("SELECT user_id FROM user_sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return false;
            }
            
            // Revoke session
            $stmt = $this->db->prepare("
                UPDATE user_sessions 
                SET is_active = FALSE, 
                    revoked_at = NOW(), 
                    revoked_by = ?, 
                    revoke_reason = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$revokedBy, $reason, $sessionId]);
            
            // Log session revocation
            $this->auditLogger->setUserContext($session['user_id'], $sessionId);
            $this->auditLogger->logLogout($session['user_id'], $sessionId);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Session revocation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Revoke all sessions for a user
     */
    public function revokeAllUserSessions(string $userId, string $revokedBy, string $reason = 'security_action'): int
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_sessions 
                SET is_active = FALSE, 
                    revoked_at = NOW(), 
                    revoked_by = ?, 
                    revoke_reason = ?
                WHERE user_id = ? AND is_active = TRUE
            ");
            
            $stmt->execute([$revokedBy, $reason, $userId]);
            $revokedCount = $stmt->rowCount();
            
            // Log mass session revocation
            $this->auditLogger->logAction(
                'mass_session_revoke',
                'authentication',
                $userId,
                null,
                ['revoked_count' => $revokedCount, 'reason' => $reason],
                true,
                null,
                ['revoked_by' => $revokedBy]
            );
            
            return $revokedCount;
            
        } catch (Exception $e) {
            error_log("Mass session revocation failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get active sessions for a user
     */
    public function getUserSessions(string $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, ip_address, user_agent, device_info, 
                       expires_at, refresh_expires_at, last_activity, created_at
                FROM user_sessions 
                WHERE user_id = ? AND is_active = TRUE AND expires_at > NOW()
                ORDER BY last_activity DESC
            ");
            
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Failed to get user sessions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions(): int
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE user_sessions 
                SET is_active = FALSE, 
                    revoked_at = NOW(), 
                    revoke_reason = 'expired'
                WHERE (expires_at < NOW() OR refresh_expires_at < NOW()) 
                AND is_active = TRUE
            ");
            
            $stmt->execute();
            $cleanedCount = $stmt->rowCount();
            
            // Log cleanup action
            $this->auditLogger->logAction(
                'session_cleanup',
                'system',
                null,
                null,
                ['cleaned_count' => $cleanedCount],
                true,
                null,
                ['cleanup_type' => 'expired_sessions']
            );
            
            return $cleanedCount;
            
        } catch (Exception $e) {
            error_log("Session cleanup failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Generate JWT access token
     */
    private function generateAccessToken(string $userId): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + $this->accessTokenTTL,
            'type' => 'access'
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $this->jwtSecret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }

    /**
     * Generate JWT refresh token
     */
    private function generateRefreshToken(string $userId): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + $this->refreshTokenTTL,
            'type' => 'refresh',
            'jti' => $this->generateUUID() // Unique token ID
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $this->jwtSecret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }

    /**
     * Decode and validate JWT token
     */
    private function decodeJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$header, $payload, $signature] = $parts;
        
        // Verify signature
        $validSignature = str_replace(['+', '/', '='], ['-', '_', ''], 
            base64_encode(hash_hmac('sha256', $header . "." . $payload, $this->jwtSecret, true))
        );
        
        if (!hash_equals($signature, $validSignature)) {
            return null;
        }
        
        // Decode payload
        $decodedPayload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);
        
        // Check expiration
        if (!$decodedPayload || $decodedPayload['exp'] < time()) {
            return null;
        }
        
        return $decodedPayload;
    }

    /**
     * Update last activity timestamp
     */
    private function updateLastActivity(string $sessionId): void
    {
        try {
            $stmt = $this->db->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE id = ?");
            $stmt->execute([$sessionId]);
        } catch (Exception $e) {
            // Don't throw exception for activity update failures
            error_log("Failed to update last activity: " . $e->getMessage());
        }
    }

    /**
     * Get JWT secret from configuration
     */
    private function getJWTSecret(): string
    {
        // Try to get from environment or config
        $secret = $_ENV['JWT_SECRET'] ?? null;
        
        if (!$secret && defined('JWT_SECRET')) {
            $secret = JWT_SECRET;
        }
        
        if (!$secret) {
            // Generate a default secret (should be replaced in production)
            $secret = 'default_jwt_secret_' . hash('sha256', __DIR__ . time());
            error_log("Warning: Using default JWT secret. Set JWT_SECRET in environment or config.");
        }
        
        return $secret;
    }

    /**
     * Get client IP address
     */
    private function getClientIpAddress(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Generate UUID v4
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}