-- Migration Script: Data Integrity Validation
-- Purpose: Comprehensive validation of migrated data integrity
-- Requirements: TC-3.2, TC-3.3

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

-- 1. User Sessions Validation
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
        THEN CONCAT('Migration success rate below 95%: ', 
                   ROUND(((SELECT COUNT(*) FROM user_sessions WHERE expires_at > NOW()) * 100.0) / 
                         (SELECT COUNT(*) FROM legacy_sessions WHERE expires_at > NOW() AND is_active = 1), 2), '%')
        ELSE NULL
    END;

-- Check for orphaned sessions
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'SESSION_ORPHANS',
    'user_sessions',
    'No orphaned sessions (sessions without valid users)',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' orphaned sessions') ELSE NULL END
FROM user_sessions us
LEFT JOIN users u ON us.user_id = u.id
WHERE u.id IS NULL;

-- Check session token uniqueness
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'SESSION_UNIQUENESS',
    'user_sessions',
    'All session tokens are unique',
    (SELECT COUNT(*) FROM user_sessions),
    (SELECT COUNT(DISTINCT token_hash) FROM user_sessions),
    CASE 
        WHEN (SELECT COUNT(*) FROM user_sessions) = (SELECT COUNT(DISTINCT token_hash) FROM user_sessions)
        THEN 'PASS' 
        ELSE 'FAIL' 
    END,
    CASE 
        WHEN (SELECT COUNT(*) FROM user_sessions) != (SELECT COUNT(DISTINCT token_hash) FROM user_sessions)
        THEN 'Duplicate token hashes found'
        ELSE NULL
    END;

-- 2. Audit Logs Validation
-- Check audit log count consistency
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'AUDIT_COUNT',
    'audit_logs',
    'Audit logs migrated for last year',
    (SELECT COUNT(*) FROM legacy_audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)),
    (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)),
    CASE 
        WHEN (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)) >= 
             (SELECT COUNT(*) FROM legacy_audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)) * 0.95 
        THEN 'PASS'
        ELSE 'FAIL'
    END,
    CASE 
        WHEN (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)) < 
             (SELECT COUNT(*) FROM legacy_audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)) * 0.95 
        THEN CONCAT('Audit migration success rate below 95%: ', 
                   ROUND(((SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)) * 100.0) / 
                         (SELECT COUNT(*) FROM legacy_audit_trail WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)), 2), '%')
        ELSE NULL
    END;

-- Check for invalid JSON in audit logs
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'AUDIT_JSON_VALIDITY',
    'audit_logs',
    'All JSON fields are valid',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' records with invalid JSON') ELSE NULL END
FROM audit_logs
WHERE (old_values IS NOT NULL AND NOT JSON_VALID(old_values))
   OR (new_values IS NOT NULL AND NOT JSON_VALID(new_values));

-- Check audit log referential integrity
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'AUDIT_REFERENTIAL_INTEGRITY',
    'audit_logs',
    'All audit logs reference valid users',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'WARNING' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' audit logs with invalid user references') ELSE NULL END
FROM audit_logs al
LEFT JOIN users u ON al.user_id = u.id
WHERE u.id IS NULL;

-- 3. Performance Cache Validation
-- Check cache coverage for active accounts
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'CACHE_COVERAGE',
    'dashboard_metrics_cache',
    'Cache coverage for active LINE accounts',
    (SELECT COUNT(*) FROM line_accounts WHERE status = 'active'),
    (SELECT COUNT(DISTINCT line_account_id) FROM dashboard_metrics_cache WHERE date_key >= DATE_SUB(NOW(), INTERVAL 7 DAY)),
    CASE 
        WHEN (SELECT COUNT(DISTINCT line_account_id) FROM dashboard_metrics_cache WHERE date_key >= DATE_SUB(NOW(), INTERVAL 7 DAY)) >= 
             (SELECT COUNT(*) FROM line_accounts WHERE status = 'active') * 0.8 
        THEN 'PASS'
        ELSE 'WARNING'
    END,
    CASE 
        WHEN (SELECT COUNT(DISTINCT line_account_id) FROM dashboard_metrics_cache WHERE date_key >= DATE_SUB(NOW(), INTERVAL 7 DAY)) < 
             (SELECT COUNT(*) FROM line_accounts WHERE status = 'active') * 0.8 
        THEN CONCAT('Cache coverage below 80%: ', 
                   ROUND(((SELECT COUNT(DISTINCT line_account_id) FROM dashboard_metrics_cache WHERE date_key >= DATE_SUB(NOW(), INTERVAL 7 DAY)) * 100.0) / 
                         (SELECT COUNT(*) FROM line_accounts WHERE status = 'active'), 2), '%')
        ELSE NULL
    END;

-- Check cache data validity
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'CACHE_DATA_VALIDITY',
    'dashboard_metrics_cache',
    'All cache data is valid JSON',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' cache entries with invalid JSON') ELSE NULL END
FROM dashboard_metrics_cache
WHERE NOT JSON_VALID(data);

-- Check for expired cache entries
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'CACHE_EXPIRY',
    'dashboard_metrics_cache',
    'No expired cache entries',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'WARNING' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' expired cache entries') ELSE NULL END
FROM dashboard_metrics_cache
WHERE expires_at < NOW();

-- 4. Foreign Key Integrity Validation
-- Check order-customer relationships
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'ORDER_CUSTOMER_INTEGRITY',
    'odoo_orders',
    'All orders have valid customer references',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'WARNING' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' orders with invalid customer references') ELSE NULL END
FROM odoo_orders o
LEFT JOIN customers c ON o.customer_ref = c.customer_ref AND o.line_account_id = c.line_account_id
WHERE c.customer_ref IS NULL AND o.customer_ref IS NOT NULL;

-- Check payment slip-order relationships
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'PAYMENT_ORDER_INTEGRITY',
    'odoo_slip_uploads',
    'All matched payment slips reference valid orders',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' payment slips with invalid order references') ELSE NULL END
FROM odoo_slip_uploads p
LEFT JOIN odoo_orders o ON p.matched_order_id = o.id
WHERE p.matched_order_id IS NOT NULL AND o.id IS NULL;

-- 5. Data Consistency Validation
-- Check for negative amounts
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'NEGATIVE_AMOUNTS',
    'odoo_orders',
    'No orders with negative amounts',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' orders with negative amounts') ELSE NULL END
FROM odoo_orders
WHERE total_amount < 0;

-- Check payment slip amounts
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'PAYMENT_AMOUNTS',
    'odoo_slip_uploads',
    'No payment slips with negative or zero amounts',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' payment slips with invalid amounts') ELSE NULL END
FROM odoo_slip_uploads
WHERE amount <= 0;

-- Check date consistency
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'DATE_CONSISTENCY',
    'user_sessions',
    'Session created_at <= expires_at',
    0,
    COUNT(*),
    CASE WHEN COUNT(*) = 0 THEN 'PASS' ELSE 'FAIL' END,
    CASE WHEN COUNT(*) > 0 THEN CONCAT('Found ', COUNT(*), ' sessions with invalid date ranges') ELSE NULL END
FROM user_sessions
WHERE created_at > expires_at;

-- 6. Index Performance Validation
-- Check if required indexes exist
INSERT INTO validation_results (validation_type, table_name, check_description, expected_count, actual_count, status, error_details)
SELECT 
    'INDEX_EXISTENCE',
    'INFORMATION_SCHEMA.STATISTICS',
    'Required indexes exist',
    12, -- Expected number of critical indexes
    COUNT(*),
    CASE WHEN COUNT(*) >= 12 THEN 'PASS' ELSE 'WARNING' END,
    CASE WHEN COUNT(*) < 12 THEN CONCAT('Missing some required indexes. Found: ', COUNT(*), ' Expected: 12') ELSE NULL END
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND INDEX_NAME IN (
    'idx_user_sessions_user_id',
    'idx_user_sessions_token_hash',
    'idx_user_sessions_expires_at',
    'idx_audit_logs_user_action',
    'idx_audit_logs_resource',
    'idx_audit_logs_created_at',
    'idx_dashboard_metrics_lookup',
    'idx_api_cache_expiry',
    'idx_odoo_orders_customer_ref',
    'idx_odoo_orders_status',
    'idx_odoo_slip_uploads_status',
    'idx_odoo_webhooks_log_status'
  );

-- Generate final validation report
SELECT 
    'VALIDATION SUMMARY' as report_section,
    validation_type,
    table_name,
    check_description,
    status,
    CASE 
        WHEN status = 'PASS' THEN '✓'
        WHEN status = 'WARNING' THEN '⚠'
        WHEN status = 'FAIL' THEN '✗'
    END as result_icon,
    error_details,
    validation_timestamp
FROM validation_results
ORDER BY 
    CASE status 
        WHEN 'FAIL' THEN 1 
        WHEN 'WARNING' THEN 2 
        WHEN 'PASS' THEN 3 
    END,
    validation_type;

-- Summary statistics
SELECT 
    'VALIDATION STATISTICS' as report_section,
    COUNT(*) as total_validations,
    SUM(CASE WHEN status = 'PASS' THEN 1 ELSE 0 END) as passed,
    SUM(CASE WHEN status = 'WARNING' THEN 1 ELSE 0 END) as warnings,
    SUM(CASE WHEN status = 'FAIL' THEN 1 ELSE 0 END) as failed,
    ROUND(
        (SUM(CASE WHEN status = 'PASS' THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 
        2
    ) as pass_rate_percent
FROM validation_results;

-- Critical issues that must be resolved
SELECT 
    'CRITICAL ISSUES' as report_section,
    validation_type,
    table_name,
    check_description,
    error_details
FROM validation_results
WHERE status = 'FAIL'
ORDER BY validation_type;

-- Record validation results in migration stats
INSERT INTO migration_stats (
    migration_type,
    total_records,
    successful_records,
    failed_records,
    migration_date,
    notes
) 
SELECT 
    'data_validation',
    COUNT(*),
    SUM(CASE WHEN status = 'PASS' THEN 1 ELSE 0 END),
    SUM(CASE WHEN status = 'FAIL' THEN 1 ELSE 0 END),
    NOW(),
    CONCAT('Warnings: ', SUM(CASE WHEN status = 'WARNING' THEN 1 ELSE 0 END))
FROM validation_results;