# Enhanced Audit Logging System

## Overview

The Enhanced Audit Logging System provides comprehensive tracking of all sensitive operations within the Odoo Dashboard modernization project. This system ensures compliance with security requirements BR-5.4 and NFR-3.4 by maintaining detailed audit trails and secure session management.

## Features

### 1. Comprehensive Audit Logging
- **All sensitive operations tracked**: Login/logout, order updates, payment processing, data modifications
- **Detailed context capture**: IP addresses, user agents, session IDs, request IDs
- **Before/after value tracking**: Complete change history with old and new values
- **Success/failure tracking**: Comprehensive error logging and failure analysis
- **Metadata support**: Flexible JSON metadata for operation-specific context

### 2. JWT Session Management
- **Secure token generation**: HS256 signed JWT tokens with configurable expiration
- **Refresh token rotation**: Automatic token refresh with secure rotation
- **Session tracking**: Complete session lifecycle management
- **Multi-device support**: Track and manage sessions across multiple devices
- **Revocation capabilities**: Individual and bulk session revocation

### 3. Security Event Monitoring
- **Threat detection**: Automated logging of suspicious activities
- **Severity classification**: Low, medium, high, and critical event categorization
- **Real-time alerting**: Integration points for security monitoring systems
- **Incident tracking**: Complete security event lifecycle management

## Database Schema

### Audit Logs Table
```sql
CREATE TABLE audit_logs (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50) NOT NULL,
    resource_id VARCHAR(36) NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    session_id VARCHAR(36) NULL,
    request_id VARCHAR(36) NULL,
    success BOOLEAN NOT NULL DEFAULT TRUE,
    error_message TEXT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Optimized indexes for common queries
    INDEX idx_user_action (user_id, action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created (created_at)
);
```

### User Sessions Table
```sql
CREATE TABLE user_sessions (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    refresh_token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    refresh_expires_at TIMESTAMP NOT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_info JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    revoked_at TIMESTAMP NULL,
    revoked_by VARCHAR(36) NULL,
    revoke_reason VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Optimized indexes for token validation
    UNIQUE KEY uk_token_hash (token_hash),
    INDEX idx_user_active (user_id, is_active)
);
```

### Security Events Table
```sql
CREATE TABLE security_events (
    id VARCHAR(36) PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    user_id VARCHAR(36) NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    details JSON NOT NULL,
    resolved BOOLEAN NOT NULL DEFAULT FALSE,
    resolved_by VARCHAR(36) NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Indexes for security monitoring
    INDEX idx_event_type (event_type, created_at),
    INDEX idx_severity (severity, created_at)
);
```

## Usage Examples

### Basic Audit Logging

```php
<?php
require_once 'classes/AuditLogger.php';

$auditLogger = new AuditLogger();

// Log a successful order update
$auditLogger->logOrderUpdate(
    'order_123',
    ['status' => 'pending', 'amount' => 1500.00],
    ['status' => 'processing', 'amount' => 1500.00]
);

// Log a failed payment processing
$auditLogger->logPaymentProcessing(
    'payment_456',
    ['amount' => 2000.00, 'method' => 'bank_transfer'],
    false,
    'Insufficient funds'
);

// Log a security event
$auditLogger->logSecurityEvent(
    'failed_login_attempt',
    'medium',
    [
        'attempted_username' => 'admin',
        'ip_address' => '192.168.1.100',
        'attempts_count' => 3
    ]
);
```

### Session Management

```php
<?php
require_once 'classes/SessionManager.php';

$sessionManager = new SessionManager();

// Create new session
$sessionData = $sessionManager->createSession('user_123', [
    'device' => 'iPhone 12',
    'browser' => 'Safari 14.0'
]);

// Validate token
$userInfo = $sessionManager->validateToken($sessionData['access_token']);
if ($userInfo) {
    echo "User authenticated: " . $userInfo['username'];
}

// Refresh token
$newTokens = $sessionManager->refreshToken($sessionData['refresh_token']);

// Revoke session
$sessionManager->revokeSession($sessionData['session_id'], 'user_123', 'user_logout');
```

### Retrieving Audit Data

```php
<?php
// Get audit trail for specific resource
$trail = $auditLogger->getAuditTrail('order', 'order_123');

// Get recent activities for user
$activities = $auditLogger->getRecentActivities(50, 'user_123');

// Get user sessions
$sessions = $sessionManager->getUserSessions('user_123');
```

## API Endpoints

### GET /api/audit-logs.php?path=logs
Retrieve audit logs with pagination and filtering.

**Parameters:**
- `page` (int): Page number (default: 1)
- `limit` (int): Records per page (max: 100, default: 50)
- `user_id` (string): Filter by user ID
- `resource_type` (string): Filter by resource type
- `resource_id` (string): Filter by resource ID

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": "log_123",
            "user_id": "user_456",
            "action": "order_update",
            "resource_type": "order",
            "resource_id": "order_789",
            "old_values": {"status": "pending"},
            "new_values": {"status": "processing"},
            "success": true,
            "created_at": "2026-03-16 10:30:00",
            "username": "john_doe"
        }
    ],
    "meta": {
        "page": 1,
        "limit": 50,
        "total": 1
    }
}
```

### GET /api/audit-logs.php?path=sessions
Retrieve active user sessions.

**Parameters:**
- `user_id` (string): User ID (optional, defaults to current user)

### GET /api/audit-logs.php?path=stats
Get audit statistics and metrics.

### POST /api/audit-logs.php?path=security-event
Log a security event.

**Request Body:**
```json
{
    "event_type": "suspicious_activity",
    "severity": "high",
    "details": {
        "description": "Multiple failed login attempts",
        "ip_address": "192.168.1.100",
        "attempts": 5
    }
}
```

## Security Considerations

### 1. Token Security
- **Secure storage**: Tokens are hashed using SHA-256 before database storage
- **Short expiration**: Access tokens expire in 15 minutes
- **Refresh rotation**: Refresh tokens are rotated on each use
- **Revocation support**: Immediate token revocation capability

### 2. Data Protection
- **IP address tracking**: Full IPv6 support with proxy detection
- **User agent logging**: Complete browser/device fingerprinting
- **Sensitive data handling**: PII is never logged in plain text
- **Encryption ready**: JSON fields support encrypted storage

### 3. Access Control
- **Role-based access**: Audit data access based on user roles
- **User isolation**: Users can only access their own audit data
- **Admin oversight**: Administrators have full audit visibility
- **API authentication**: All endpoints require valid JWT tokens

## Performance Optimization

### 1. Database Indexes
- **Composite indexes**: Optimized for common query patterns
- **Covering indexes**: Reduce disk I/O for frequent queries
- **Partitioning ready**: Schema supports date-based partitioning
- **Archive strategy**: Built-in cleanup for old audit data

### 2. Caching Strategy
- **Session caching**: Redis integration for session data
- **Query optimization**: Materialized views for complex aggregations
- **Connection pooling**: Efficient database connection management
- **Batch operations**: Bulk insert capabilities for high-volume logging

### 3. Scalability Features
- **Horizontal scaling**: Stateless design supports load balancing
- **Microservice ready**: Clean separation of concerns
- **Event-driven**: Integration points for message queues
- **Monitoring hooks**: Built-in metrics and health checks

## Compliance Features

### 1. Data Retention
- **Configurable retention**: Flexible data retention policies
- **Automated cleanup**: Scheduled cleanup of old audit data
- **Archive support**: Export capabilities for long-term storage
- **Legal hold**: Ability to preserve data for legal requirements

### 2. Audit Trail Integrity
- **Immutable logs**: Audit entries cannot be modified after creation
- **Chain of custody**: Complete tracking of data access and modifications
- **Digital signatures**: Ready for cryptographic signing
- **Tamper detection**: Mechanisms to detect unauthorized changes

### 3. Reporting and Analytics
- **Standard reports**: Pre-built compliance reports
- **Custom queries**: Flexible query interface for investigations
- **Export formats**: CSV, JSON, and PDF export capabilities
- **Real-time monitoring**: Live dashboards for security teams

## Testing

Run the comprehensive test suite:

```bash
# Run all audit logging tests
./vendor/bin/phpunit tests/AuditLoggingTest.php

# Run specific test methods
./vendor/bin/phpunit tests/AuditLoggingTest.php --filter testBasicAuditLogging
./vendor/bin/phpunit tests/AuditLoggingTest.php --filter testSessionManagement
```

## Migration and Setup

1. **Run the migration script:**
   ```bash
   mysql -u username -p database_name < database/migration_enhanced_audit_logging.sql
   ```

2. **Configure JWT secret:**
   ```php
   // In config/config.php
   define('JWT_SECRET', 'your-secure-jwt-secret-key');
   
   // Or use environment variable
   $_ENV['JWT_SECRET'] = 'your-secure-jwt-secret-key';
   ```

3. **Set up scheduled cleanup:**
   ```bash
   # Add to crontab for daily cleanup
   0 2 * * * php /path/to/cron/cleanup_audit_logs.php
   ```

## Monitoring and Alerting

### Key Metrics to Monitor
- **Failed authentication attempts**: Threshold: >5 per minute
- **Suspicious IP activity**: Multiple users from same IP
- **Token validation failures**: High rate indicates attack
- **Session anomalies**: Unusual session patterns
- **Audit log volume**: Sudden spikes in activity

### Integration Points
- **SIEM systems**: JSON export for security information systems
- **Monitoring tools**: Prometheus metrics endpoints
- **Alert managers**: Webhook notifications for critical events
- **Log aggregation**: ELK stack integration ready

## Troubleshooting

### Common Issues

1. **Database connection errors**
   - Check database credentials and connectivity
   - Verify table creation and permissions
   - Review error logs for specific issues

2. **JWT token validation failures**
   - Verify JWT secret configuration
   - Check token expiration settings
   - Validate token format and signature

3. **Performance issues**
   - Review database indexes and query performance
   - Monitor audit log volume and cleanup frequency
   - Check session cleanup scheduling

### Debug Mode
Enable debug logging by setting:
```php
define('AUDIT_DEBUG', true);
```

This will log additional information to help diagnose issues.

## Future Enhancements

### Planned Features
- **Blockchain integration**: Immutable audit trail using blockchain
- **Machine learning**: Anomaly detection for security events
- **Advanced analytics**: Predictive security analytics
- **Mobile SDK**: Native mobile app audit logging
- **Compliance automation**: Automated compliance report generation

### Integration Roadmap
- **GDPR compliance**: Enhanced privacy controls and data portability
- **SOX compliance**: Financial audit trail enhancements
- **ISO 27001**: Information security management integration
- **PCI DSS**: Payment card industry compliance features

---

For technical support or questions about the audit logging system, please refer to the project documentation or contact the development team.