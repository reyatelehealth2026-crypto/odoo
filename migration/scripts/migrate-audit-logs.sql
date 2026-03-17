-- Migration Script: Audit Logs from Legacy System
-- Purpose: Migrate existing audit trail to new comprehensive audit logging system
-- Requirements: TC-3.2, TC-3.3

-- Create temporary table for audit migration tracking
CREATE TEMPORARY TABLE audit_migration_log (
    legacy_audit_id VARCHAR(255),
    new_audit_id VARCHAR(36),
    migration_status ENUM('success', 'failed', 'skipped'),
    migration_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT
);

-- Migrate audit logs from legacy system with data transformation
INSERT INTO audit_logs (
    id,
    user_id,
    action,
    resource_type,
    resource_id,
    old_values,
    new_values,
    ip_address,
    user_agent,
    created_at
)
SELECT 
    UUID() as id,
    la.user_id,
    CASE 
        WHEN la.action_type = 'order_update' THEN 'UPDATE_ORDER'
        WHEN la.action_type = 'payment_process' THEN 'PROCESS_PAYMENT'
        WHEN la.action_type = 'status_change' THEN 'UPDATE_STATUS'
        WHEN la.action_type = 'user_login' THEN 'USER_LOGIN'
        WHEN la.action_type = 'user_logout' THEN 'USER_LOGOUT'
        WHEN la.action_type = 'webhook_retry' THEN 'RETRY_WEBHOOK'
        WHEN la.action_type = 'slip_upload' THEN 'UPLOAD_PAYMENT_SLIP'
        WHEN la.action_type = 'slip_match' THEN 'MATCH_PAYMENT_SLIP'
        ELSE UPPER(REPLACE(la.action_type, '_', '_'))
    END as action,
    CASE 
        WHEN la.table_name = 'odoo_orders' THEN 'order'
        WHEN la.table_name = 'odoo_slip_uploads' THEN 'payment_slip'
        WHEN la.table_name = 'odoo_webhooks_log' THEN 'webhook'
        WHEN la.table_name = 'users' THEN 'user'
        WHEN la.table_name = 'customers' THEN 'customer'
        ELSE la.table_name
    END as resource_type,
    la.record_id as resource_id,
    CASE 
        WHEN la.old_data IS NOT NULL AND JSON_VALID(la.old_data) THEN la.old_data
        WHEN la.old_data IS NOT NULL THEN JSON_OBJECT('legacy_data', la.old_data)
        ELSE NULL
    END as old_values,
    CASE 
        WHEN la.new_data IS NOT NULL AND JSON_VALID(la.new_data) THEN la.new_data
        WHEN la.new_data IS NOT NULL THEN JSON_OBJECT('legacy_data', la.new_data)
        ELSE NULL
    END as new_values,
    la.ip_address,
    la.user_agent,
    la.created_at
FROM legacy_audit_trail la
INNER JOIN users u ON la.user_id = u.id
WHERE la.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) -- Only migrate last year's data
    AND u.status = 'active';

-- Log successful migrations
INSERT INTO audit_migration_log (legacy_audit_id, new_audit_id, migration_status)
SELECT 
    la.id,
    al.id,
    'success'
FROM legacy_audit_trail la
INNER JOIN audit_logs al ON la.record_id = al.resource_id 
    AND la.user_id = al.user_id
    AND la.created_at = al.created_at
WHERE la.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Log failed migrations
INSERT INTO audit_migration_log (legacy_audit_id, migration_status, error_message)
SELECT 
    la.id,
    'failed',
    CASE 
        WHEN u.id IS NULL THEN 'User not found'
        WHEN u.status != 'active' THEN 'User inactive'
        WHEN la.old_data IS NOT NULL AND NOT JSON_VALID(la.old_data) THEN 'Invalid JSON in old_data'
        WHEN la.new_data IS NOT NULL AND NOT JSON_VALID(la.new_data) THEN 'Invalid JSON in new_data'
        ELSE 'Unknown error'
    END
FROM legacy_audit_trail la
LEFT JOIN users u ON la.user_id = u.id
LEFT JOIN audit_logs al ON la.record_id = al.resource_id 
    AND la.user_id = al.user_id
    AND la.created_at = al.created_at
WHERE la.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
    AND al.id IS NULL;

-- Create performance indexes for audit logs
CREATE INDEX IF NOT EXISTS idx_audit_logs_user_action ON audit_logs(user_id, action);
CREATE INDEX IF NOT EXISTS idx_audit_logs_resource ON audit_logs(resource_type, resource_id);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_user_created ON audit_logs(user_id, created_at);

-- Migrate sensitive operation flags
UPDATE audit_logs al
INNER JOIN legacy_audit_trail la ON al.resource_id = la.record_id 
    AND al.user_id = la.user_id
    AND al.created_at = la.created_at
SET al.new_values = JSON_SET(
    COALESCE(al.new_values, JSON_OBJECT()),
    '$.is_sensitive',
    CASE 
        WHEN la.action_type IN ('payment_process', 'slip_match', 'user_login') THEN true
        ELSE false
    END,
    '$.requires_approval',
    CASE 
        WHEN la.action_type IN ('payment_process', 'slip_match') AND la.amount > 10000 THEN true
        ELSE false
    END
)
WHERE la.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Validation queries
SELECT 
    'Audit Migration Summary' as report_type,
    COUNT(*) as total_legacy_audits,
    SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END) as successful_migrations,
    SUM(CASE WHEN migration_status = 'failed' THEN 1 ELSE 0 END) as failed_migrations,
    ROUND(
        (SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 
        2
    ) as success_rate_percent
FROM audit_migration_log;

-- Verify data integrity
SELECT 
    'Data Integrity Check' as check_type,
    resource_type,
    COUNT(*) as total_records,
    COUNT(DISTINCT user_id) as unique_users,
    MIN(created_at) as earliest_record,
    MAX(created_at) as latest_record
FROM audit_logs 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)
GROUP BY resource_type
ORDER BY total_records DESC;

-- Update migration statistics
INSERT INTO migration_stats (
    migration_type,
    total_records,
    successful_records,
    failed_records,
    migration_date
) 
SELECT 
    'audit_logs',
    COUNT(*),
    SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END),
    SUM(CASE WHEN migration_status = 'failed' THEN 1 ELSE 0 END),
    NOW()
FROM audit_migration_log;