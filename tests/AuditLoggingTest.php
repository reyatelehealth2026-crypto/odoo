<?php

/**
 * Audit Logging Test Suite
 * 
 * Tests the enhanced audit logging and session management functionality
 * Requirements: BR-5.4 (Security & Access Control), NFR-3.4 (Security compliance)
 * 
 * @author Kiro AI Assistant
 * @version 1.0.0
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/AuditLogger.php';
require_once __DIR__ . '/../classes/SessionManager.php';

use PHPUnit\Framework\TestCase;

class AuditLoggingTest extends TestCase
{
    private $db;
    private $auditLogger;
    private $sessionManager;
    private $testUserId;

    protected function setUp(): void
    {
        $this->db = Database::getInstance()->getConnection();
        $this->auditLogger = new AuditLogger($this->db);
        $this->sessionManager = new SessionManager($this->db, $this->auditLogger);
        $this->testUserId = 'test_user_' . uniqid();
        
        // Create test user if needed
        $this->createTestUser();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->cleanupTestData();
    }

    /**
     * Test basic audit logging functionality
     */
    public function testBasicAuditLogging()
    {
        $logId = $this->auditLogger->logAction(
            'test_action',
            'test_resource',
            'test_resource_id',
            ['old_value' => 'old'],
            ['new_value' => 'new'],
            true,
            null,
            ['test' => true]
        );
        
        $this->assertNotEmpty($logId, 'Audit log ID should not be empty');
        
        // Verify log was created
        $stmt = $this->db->prepare("SELECT * FROM audit_logs WHERE id = ?");
        $stmt->execute([$logId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotNull($log, 'Audit log should exist in database');
        $this->assertEquals('test_action', $log['action']);
        $this->assertEquals('test_resource', $log['resource_type']);
        $this->assertEquals('test_resource_id', $log['resource_id']);
        $this->assertEquals(1, $log['success']);
    }

    /**
     * Test audit trail retrieval
     */
    public function testAuditTrailRetrieval()
    {
        $resourceId = 'test_resource_' . uniqid();
        
        // Create multiple audit entries for the same resource
        for ($i = 1; $i <= 3; $i++) {
            $this->auditLogger->logAction(
                "test_action_$i",
                'test_resource',
                $resourceId,
                ['step' => $i - 1],
                ['step' => $i],
                true,
                null,
                ['iteration' => $i]
            );
        }
        
        // Retrieve audit trail
        $trail = $this->auditLogger->getAuditTrail('test_resource', $resourceId);
        
        $this->assertCount(3, $trail, 'Should retrieve all 3 audit entries');
        $this->assertEquals('test_action_3', $trail[0]['action'], 'Most recent action should be first');
        $this->assertEquals('test_action_1', $trail[2]['action'], 'Oldest action should be last');
    }

    /**
     * Test session creation and validation
     */
    public function testSessionManagement()
    {
        // Create session
        $sessionData = $this->sessionManager->createSession($this->testUserId, [
            'device' => 'test_device',
            'browser' => 'test_browser'
        ]);
        
        $this->assertArrayHasKey('access_token', $sessionData);
        $this->assertArrayHasKey('refresh_token', $sessionData);
        $this->assertArrayHasKey('session_id', $sessionData);
        
        // Validate token
        $userInfo = $this->sessionManager->validateToken($sessionData['access_token']);
        
        $this->assertNotNull($userInfo, 'Token validation should succeed');
        $this->assertEquals($this->testUserId, $userInfo['user_id']);
        
        // Test token refresh
        $refreshedTokens = $this->sessionManager->refreshToken($sessionData['refresh_token']);
        
        $this->assertNotNull($refreshedTokens, 'Token refresh should succeed');
        $this->assertArrayHasKey('access_token', $refreshedTokens);
        $this->assertNotEquals($sessionData['access_token'], $refreshedTokens['access_token']);
    }

    /**
     * Test session revocation
     */
    public function testSessionRevocation()
    {
        // Create session
        $sessionData = $this->sessionManager->createSession($this->testUserId);
        
        // Verify session is active
        $userInfo = $this->sessionManager->validateToken($sessionData['access_token']);
        $this->assertNotNull($userInfo, 'Session should be active');
        
        // Revoke session
        $revoked = $this->sessionManager->revokeSession(
            $sessionData['session_id'], 
            $this->testUserId, 
            'test_revocation'
        );
        
        $this->assertTrue($revoked, 'Session revocation should succeed');
        
        // Verify session is no longer valid
        $userInfo = $this->sessionManager->validateToken($sessionData['access_token']);
        $this->assertNull($userInfo, 'Revoked session should not validate');
    }

    /**
     * Test security event logging
     */
    public function testSecurityEventLogging()
    {
        $eventId = $this->auditLogger->logSecurityEvent(
            'test_security_event',
            'high',
            [
                'ip_address' => '192.168.1.100',
                'attempted_action' => 'unauthorized_access',
                'details' => 'Test security event'
            ],
            $this->testUserId
        );
        
        $this->assertNotEmpty($eventId, 'Security event ID should not be empty');
        
        // Verify event was logged
        $stmt = $this->db->prepare("SELECT * FROM security_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotNull($event, 'Security event should exist in database');
        $this->assertEquals('test_security_event', $event['event_type']);
        $this->assertEquals('high', $event['severity']);
        $this->assertEquals($this->testUserId, $event['user_id']);
    }

    /**
     * Test authentication event logging
     */
    public function testAuthenticationEventLogging()
    {
        // Test successful login
        $loginLogId = $this->auditLogger->logLogin($this->testUserId, true);
        $this->assertNotEmpty($loginLogId, 'Login log ID should not be empty');
        
        // Test failed login
        $failedLoginId = $this->auditLogger->logLogin($this->testUserId, false, 'Invalid password');
        $this->assertNotEmpty($failedLoginId, 'Failed login log ID should not be empty');
        
        // Verify logs were created
        $stmt = $this->db->prepare("
            SELECT * FROM audit_logs 
            WHERE user_id = ? AND action = 'login' 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$this->testUserId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->assertCount(2, $logs, 'Should have 2 login logs');
        $this->assertEquals(0, $logs[0]['success'], 'Most recent should be failed login');
        $this->assertEquals(1, $logs[1]['success'], 'First should be successful login');
    }

    /**
     * Test order-related audit logging
     */
    public function testOrderAuditLogging()
    {
        $orderId = 'test_order_' . uniqid();
        
        // Test order creation
        $createLogId = $this->auditLogger->logOrderCreation($orderId, [
            'customer_id' => 'test_customer',
            'total_amount' => 1500.00,
            'status' => 'pending'
        ]);
        
        $this->assertNotEmpty($createLogId, 'Order creation log ID should not be empty');
        
        // Test order update
        $updateLogId = $this->auditLogger->logOrderUpdate($orderId, 
            ['status' => 'pending'], 
            ['status' => 'processing']
        );
        
        $this->assertNotEmpty($updateLogId, 'Order update log ID should not be empty');
        
        // Verify order audit trail
        $trail = $this->auditLogger->getAuditTrail('order', $orderId);
        $this->assertCount(2, $trail, 'Should have 2 order audit entries');
    }

    /**
     * Test payment-related audit logging
     */
    public function testPaymentAuditLogging()
    {
        $paymentId = 'test_payment_' . uniqid();
        
        // Test payment processing
        $processLogId = $this->auditLogger->logPaymentProcessing($paymentId, [
            'amount' => 1500.00,
            'method' => 'bank_transfer',
            'order_id' => 'test_order_123'
        ], true);
        
        $this->assertNotEmpty($processLogId, 'Payment processing log ID should not be empty');
        
        // Test payment slip upload
        $slipId = 'test_slip_' . uniqid();
        $uploadLogId = $this->auditLogger->logPaymentSlipUpload($slipId, [
            'file_name' => 'payment_slip.jpg',
            'file_size' => 1024000,
            'file_type' => 'image/jpeg'
        ]);
        
        $this->assertNotEmpty($uploadLogId, 'Payment slip upload log ID should not be empty');
    }

    /**
     * Test cleanup functionality
     */
    public function testCleanupFunctionality()
    {
        // Create old audit logs (simulate by directly inserting with old dates)
        $oldDate = date('Y-m-d H:i:s', strtotime('-400 days'));
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (id, user_id, action, resource_type, success, created_at)
            VALUES (?, ?, 'old_action', 'test_resource', 1, ?)
        ");
        
        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute([
                'old_log_' . $i,
                $this->testUserId,
                $oldDate
            ]);
        }
        
        // Run cleanup
        $deletedCount = $this->auditLogger->cleanupOldLogs(365);
        
        $this->assertEquals(5, $deletedCount, 'Should delete 5 old audit logs');
        
        // Clean up expired sessions
        $cleanedSessions = $this->sessionManager->cleanupExpiredSessions();
        
        $this->assertIsInt($cleanedSessions, 'Cleanup should return integer count');
    }

    /**
     * Create test user for testing
     */
    private function createTestUser()
    {
        try {
            // Check if users table exists
            $stmt = $this->db->query("SHOW TABLES LIKE 'users'");
            if ($stmt->rowCount() > 0) {
                // Insert test user
                $stmt = $this->db->prepare("
                    INSERT IGNORE INTO users (id, username, email, role, line_account_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $this->testUserId,
                    'test_user',
                    'test@example.com',
                    'staff',
                    'test_line_account'
                ]);
            }
        } catch (Exception $e) {
            // Users table might not exist yet, that's okay for testing
        }
    }

    /**
     * Clean up test data
     */
    private function cleanupTestData()
    {
        try {
            // Clean up audit logs
            $stmt = $this->db->prepare("DELETE FROM audit_logs WHERE user_id = ? OR id LIKE 'old_log_%'");
            $stmt->execute([$this->testUserId]);
            
            // Clean up sessions
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
            $stmt->execute([$this->testUserId]);
            
            // Clean up security events
            $stmt = $this->db->prepare("DELETE FROM security_events WHERE user_id = ?");
            $stmt->execute([$this->testUserId]);
            
            // Clean up test user
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$this->testUserId]);
            
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
}