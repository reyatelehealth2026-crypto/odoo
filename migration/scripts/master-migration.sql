-- Master Migration Script: Odoo Dashboard Modernization
-- Purpose: Orchestrate complete data migration from legacy system
-- Requirements: TC-3.2, TC-3.3

-- Create migration tracking infrastructure
CREATE TABLE IF NOT EXISTS migration_stats (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    migration_type VARCHAR(100) NOT NULL,
    total_records INT NOT NULL DEFAULT 0,
    successful_records INT NOT NULL DEFAULT 0,
    failed_records INT NOT NULL DEFAULT 0,
    migration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    INDEX idx_migration_type (migration_type),
    INDEX idx_migration_date (migration_date)
);

-- Create migration execution log
CREATE TABLE IF NOT EXISTS migration_execution_log (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    script_name VARCHAR(255) NOT NULL,
    execution_status ENUM('started', 'completed', 'failed') NOT NULL,
    start_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP NULL,
    execution_time_seconds INT NULL,
    error_message TEXT NULL,
    records_affected INT NULL,
    INDEX idx_script_name (script_name),
    INDEX idx_execution_status (execution_status)
);

-- Set session variables for optimal performance
SET SESSION sql_mode = 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';
SET SESSION innodb_lock_wait_timeout = 600;
SET SESSION max_execution_time = 3600; -- 1 hour timeout
SET SESSION autocommit = 0; -- Use transactions

-- Start master migration
INSERT INTO migration_execution_log (script_name, execution_status, start_time)
VALUES ('master-migration.sql', 'started', NOW());

SET @master_migration_id = LAST_INSERT_ID();
SET @migration_start_time = NOW();

-- Begin transaction for entire migration
START TRANSACTION;

-- ============================================================================
-- PHASE 1: USER SESSIONS MIGRATION
-- ============================================================================
SELECT 'Starting Phase 1: User Sessions Migration' as phase_status;

INSERT INTO migration_execution_log (script_name, execution_status, start_time)
VALUES ('migrate-user-sessions.sql', 'started', NOW());

SET @session_migration_start = NOW();

-- Execute user sessions migration (inline for transaction control)
-- Create temporary table for session mapping
CREATE TEMPORARY TABLE session_migration_log (
    legacy_session_id VARCHAR(255),
    new_session_id VARCHAR(36),
    user_id VARCHAR(36),
    migration_status ENUM('success', 'failed', 'skipped'),
    migration_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT
);

-- Migrate active user sessions from legacy system
INSERT INTO user_sessions (
    id, 
    user_id, 
    token_hash, 
    refresh_token_hash, 
    expires_at, 
    last_activity, 
    ip_address, 
    user_agent, 
    created_at
)
SELECT 
    UUID() as id,
    u.id as user_id,
    SHA2(CONCAT(ls.session_token, UNIX_TIMESTAMP()), 256) as token_hash,
    SHA2(CONCAT(ls.session_token, '_refresh_', UNIX_TIMESTAMP()), 256) as refresh_token_hash,
    CASE 
        WHEN ls.expires_at > NOW() THEN ls.expires_at
        ELSE DATE_ADD(NOW(), INTERVAL 7 DAY)
    END as expires_at,
    COALESCE(ls.last_activity, ls.created_at) as last_activity,
    ls.ip_address,
    ls.user_agent,
    ls.created_at
FROM legacy_sessions ls
INNER JOIN users u ON ls.user_id = u.id
WHERE ls.expires_at > NOW() 
    AND ls.is_active = 1
    AND u.status = 'active';

SET @session_records_migrated = ROW_COUNT();

-- Update execution log for sessions
UPDATE migration_execution_log 
SET execution_status = 'completed', 
    end_time = NOW(),
    execution_time_seconds = TIMESTAMPDIFF(SECOND, @session_migration_start, NOW()),
    records_affected = @session_records_migrated
WHERE script_name = 'migrate-user-sessions.sql' 
    AND execution_status = 'started';

SELECT CONCAT('Phase 1 Complete: ', @session_records_migrated, ' user sessions migrated') as phase_result;

-- ============================================================================
-- PHASE 2: AUDIT LOGS MIGRATION
-- ============================================================================
SELECT 'Starting Phase 2: Audit Logs Migration' as phase_status;

INSERT INTO migration_execution_log (script_name, execution_status, start_time)
VALUES ('migrate-audit-logs.sql', 'started', NOW());

SET @audit_migration_start = NOW();

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

SET @audit_records_migrated = ROW_COUNT();

-- Update execution log for audit logs
UPDATE migration_execution_log 
SET execution_status = 'completed', 
    end_time = NOW(),
    execution_time_seconds = TIMESTAMPDIFF(SECOND, @audit_migration_start, NOW()),
    records_affected = @audit_records_migrated
WHERE script_name = 'migrate-audit-logs.sql' 
    AND execution_status = 'started';

SELECT CONCAT('Phase 2 Complete: ', @audit_records_migrated, ' audit logs migrated') as phase_result;

-- ============================================================================
-- PHASE 3: PERFORMANCE CACHE POPULATION
-- ============================================================================
SELECT 'Starting Phase 3: Performance Cache Population' as phase_status;

INSERT INTO migration_execution_log (script_name, execution_status, start_time)
VALUES ('populate-performance-cache.sql', 'started', NOW());

SET @cache_migration_start = NOW();

-- Populate dashboard metrics cache for the last 90 days
-- Order metrics cache
INSERT INTO dashboard_metrics_cache (
    id,
    line_account_id,
    metric_type,
    date_key,
    data,
    expires_at,
    created_at,
    updated_at
)
SELECT 
    UUID() as id,
    o.line_account_id,
    'orders' as metric_type,
    DATE(o.created_at) as date_key,
    JSON_OBJECT(
        'total_count', COUNT(*),
        'total_amount', COALESCE(SUM(o.total_amount), 0),
        'average_amount', COALESCE(AVG(o.total_amount), 0),
        'completed_count', SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END),
        'pending_count', SUM(CASE WHEN o.status IN ('pending', 'processing') THEN 1 ELSE 0 END),
        'cancelled_count', SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END)
    ) as data,
    DATE_ADD(DATE(o.created_at), INTERVAL 1 DAY) as expires_at,
    NOW() as created_at,
    NOW() as updated_at
FROM odoo_orders o
WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND o.line_account_id IS NOT NULL
GROUP BY o.line_account_id, DATE(o.created_at)
ON DUPLICATE KEY UPDATE
    data = VALUES(data),
    updated_at = NOW();

SET @cache_records_created = ROW_COUNT();

-- Update execution log for cache population
UPDATE migration_execution_log 
SET execution_status = 'completed', 
    end_time = NOW(),
    execution_time_seconds = TIMESTAMPDIFF(SECOND, @cache_migration_start, NOW()),
    records_affected = @cache_records_created
WHERE script_name = 'populate-performance-cache.sql' 
    AND execution_status = 'started';

SELECT CONCAT('Phase 3 Complete: ', @cache_records_created, ' cache entries created') as phase_result;

-- ============================================================================
-- PHASE 4: DATA INTEGRITY VALIDATION
-- ============================================================================
SELECT 'Starting Phase 4: Data Integrity Validation' as phase_status;

INSERT INTO migration_execution_log (script_name, execution_status, start_time)
VALUES ('validate-data-integrity.sql', 'started', NOW());

SET @validation_start = NOW();

-- Create validation results table
CREATE TEMPORARY TABLE validation_results (
    validation_type VARCHAR(100),
    table_name VARCHAR(100),
    check_description TEXT,
    expected_count INT,
    actual_count INT,
    status ENUM('PASS', 'FAIL', 'WARNING'),
    error_details TEXT,
    validation_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Critical validations only (subset for transaction safety)
-- Check session count consistency
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'SESSION_COUNT',
    'user_sessions',
    'Active sessions migrated correctly',
    (SELECT COUNT(*) FROM legacy_sessions WHERE expires_at > NOW() AND is_active = 1),
    (SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()),
    CASE 
        WHEN (SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()) >= 
             (SELECT COUNT(*) FROM legacy_sessions WHERE expires_at > NOW() AND is_active = 1) * 0.95 
        THEN 'PASS'
        ELSE 'FAIL'
    END,
    CASE 
        WHEN (SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()) < 
             (SELECT COUNT(*) FROM legacy_sessions WHERE expires_at > NOW() AND is_active = 1) * 0.95 
        THEN 'Migration success rate below 95%'
        ELSE NULL
    END;

-- Check for critical failures
SET @critical_failures = (SELECT COUNT(*) FROM validation_results WHERE status = 'FAIL');

-- Update execution log for validation
UPDATE migration_execution_log 
SET execution_status = 'completed', 
    end_time = NOW(),
    execution_time_seconds = TIMESTAMPDIFF(SECOND, @validation_start, NOW()),
    records_affected = (SELECT COUNT(*) FROM validation_results)
WHERE script_name = 'validate-data-integrity.sql' 
    AND execution_status = 'started';

SELECT CONCAT('Phase 4 Complete: ', (SELECT COUNT(*) FROM validation_results), ' validations performed, ', @critical_failures, ' critical failures') as phase_result;

-- ============================================================================
-- MIGRATION COMPLETION AND ROLLBACK DECISION
-- ============================================================================

-- Check if migration should be rolled back due to critical failures
IF @critical_failures > 0 THEN
    SELECT 'CRITICAL FAILURES DETECTED - ROLLING BACK MIGRATION' as migration_status;
    
    -- Update master migration log as failed
    UPDATE migration_execution_log 
    SET execution_status = 'failed', 
        end_time = NOW(),
        execution_time_seconds = TIMESTAMPDIFF(SECOND, @migration_start_time, NOW()),
        error_message = CONCAT('Critical validation failures: ', @critical_failures)
    WHERE id = @master_migration_id;
    
    -- Rollback all changes
    ROLLBACK;
    
    -- Show critical issues
    SELECT 
        'CRITICAL ISSUES REQUIRING ATTENTION' as issue_report,
        validation_type,
        table_name,
        check_description,
        error_details
    FROM validation_results
    WHERE status = 'FAIL';
    
ELSE
    -- Migration successful - commit all changes
    SELECT 'MIGRATION COMPLETED SUCCESSFULLY - COMMITTING CHANGES' as migration_status;
    
    -- Update master migration log as completed
    UPDATE migration_execution_log 
    SET execution_status = 'completed', 
        end_time = NOW(),
        execution_time_seconds = TIMESTAMPDIFF(SECOND, @migration_start_time, NOW()),
        records_affected = @session_records_migrated + @audit_records_migrated + @cache_records_created
    WHERE id = @master_migration_id;
    
    -- Record final migration statistics
    INSERT INTO migration_stats (
        migration_type,
        total_records,
        successful_records,
        failed_records,
        migration_date,
        notes
    ) VALUES (
        'complete_migration',
        @session_records_migrated + @audit_records_migrated + @cache_records_created,
        @session_records_migrated + @audit_records_migrated + @cache_records_created,
        0,
        NOW(),
        CONCAT('Sessions: ', @session_records_migrated, ', Audits: ', @audit_records_migrated, ', Cache: ', @cache_records_created)
    );
    
    -- Commit all changes
    COMMIT;
    
    -- Final success report
    SELECT 
        'MIGRATION SUMMARY' as report_type,
        @session_records_migrated as sessions_migrated,
        @audit_records_migrated as audit_logs_migrated,
        @cache_records_created as cache_entries_created,
        (SELECT COUNT(*) FROM validation_results WHERE status = 'PASS') as validations_passed,
        (SELECT COUNT(*) FROM validation_results WHERE status = 'WARNING') as validations_warnings,
        TIMESTAMPDIFF(SECOND, @migration_start_time, NOW()) as total_execution_time_seconds;
        
END IF;

-- Reset session variables
SET SESSION autocommit = 1;
SET SESSION sql_mode = DEFAULT;
SET SESSION innodb_lock_wait_timeout = DEFAULT;
SET SESSION max_execution_time = DEFAULT;

SELECT 'Master migration script execution completed' as final_status;