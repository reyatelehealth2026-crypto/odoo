-- Migration Script: Performance Optimization Data Population
-- Purpose: Pre-populate cache tables with historical data for optimal performance
-- Requirements: TC-3.2, TC-3.3

-- Create migration tracking table
CREATE TEMPORARY TABLE cache_population_log (
    cache_type VARCHAR(50),
    date_key DATE,
    records_created INT,
    processing_time_ms INT,
    status ENUM('success', 'failed'),
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Set session variables for performance
SET SESSION sql_mode = 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';
SET SESSION innodb_lock_wait_timeout = 300;

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
        'cancelled_count', SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END),
        'top_customer', (
            SELECT customer_ref 
            FROM odoo_orders o2 
            WHERE o2.line_account_id = o.line_account_id 
                AND DATE(o2.created_at) = DATE(o.created_at)
            GROUP BY customer_ref 
            ORDER BY COUNT(*) DESC 
            LIMIT 1
        )
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

-- Log order metrics population
INSERT INTO cache_population_log (cache_type, records_created, status)
SELECT 'order_metrics', ROW_COUNT(), 'success';

-- Payment metrics cache
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
    p.line_account_id,
    'payments' as metric_type,
    DATE(p.created_at) as date_key,
    JSON_OBJECT(
        'total_slips', COUNT(*),
        'processed_slips', SUM(CASE WHEN p.status = 'processed' THEN 1 ELSE 0 END),
        'pending_slips', SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END),
        'matched_slips', SUM(CASE WHEN p.matched_order_id IS NOT NULL THEN 1 ELSE 0 END),
        'total_amount', COALESCE(SUM(p.amount), 0),
        'average_amount', COALESCE(AVG(p.amount), 0),
        'matching_rate', ROUND(
            (SUM(CASE WHEN p.matched_order_id IS NOT NULL THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 
            2
        ),
        'average_processing_time', COALESCE(
            AVG(TIMESTAMPDIFF(MINUTE, p.created_at, p.processed_at)), 
            0
        )
    ) as data,
    DATE_ADD(DATE(p.created_at), INTERVAL 1 DAY) as expires_at,
    NOW() as created_at,
    NOW() as updated_at
FROM odoo_slip_uploads p
WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND p.line_account_id IS NOT NULL
GROUP BY p.line_account_id, DATE(p.created_at)
ON DUPLICATE KEY UPDATE
    data = VALUES(data),
    updated_at = NOW();

-- Log payment metrics population
INSERT INTO cache_population_log (cache_type, records_created, status)
SELECT 'payment_metrics', ROW_COUNT(), 'success';

-- Webhook metrics cache
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
    w.line_account_id,
    'webhooks' as metric_type,
    DATE(w.created_at) as date_key,
    JSON_OBJECT(
        'total_webhooks', COUNT(*),
        'successful_webhooks', SUM(CASE WHEN w.status = 'success' THEN 1 ELSE 0 END),
        'failed_webhooks', SUM(CASE WHEN w.status = 'failed' THEN 1 ELSE 0 END),
        'retry_webhooks', SUM(CASE WHEN w.retry_count > 0 THEN 1 ELSE 0 END),
        'success_rate', ROUND(
            (SUM(CASE WHEN w.status = 'success' THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 
            2
        ),
        'average_response_time', COALESCE(AVG(w.response_time_ms), 0),
        'most_common_event', (
            SELECT event_type 
            FROM odoo_webhooks_log w2 
            WHERE w2.line_account_id = w.line_account_id 
                AND DATE(w2.created_at) = DATE(w.created_at)
            GROUP BY event_type 
            ORDER BY COUNT(*) DESC 
            LIMIT 1
        )
    ) as data,
    DATE_ADD(DATE(w.created_at), INTERVAL 1 DAY) as expires_at,
    NOW() as created_at,
    NOW() as updated_at
FROM odoo_webhooks_log w
WHERE w.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND w.line_account_id IS NOT NULL
GROUP BY w.line_account_id, DATE(w.created_at)
ON DUPLICATE KEY UPDATE
    data = VALUES(data),
    updated_at = NOW();

-- Log webhook metrics population
INSERT INTO cache_population_log (cache_type, records_created, status)
SELECT 'webhook_metrics', ROW_COUNT(), 'success';

-- Customer metrics cache
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
    c.line_account_id,
    'customers' as metric_type,
    DATE(c.created_at) as date_key,
    JSON_OBJECT(
        'new_customers', COUNT(*),
        'active_customers', SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END),
        'line_connected', SUM(CASE WHEN c.line_user_id IS NOT NULL THEN 1 ELSE 0 END),
        'total_credit', COALESCE(SUM(c.credit_limit), 0),
        'average_credit', COALESCE(AVG(c.credit_limit), 0),
        'top_customer_by_orders', (
            SELECT c2.customer_ref
            FROM customers c2
            INNER JOIN odoo_orders o ON c2.customer_ref = o.customer_ref
            WHERE c2.line_account_id = c.line_account_id 
                AND DATE(c2.created_at) = DATE(c.created_at)
            GROUP BY c2.customer_ref
            ORDER BY COUNT(o.id) DESC
            LIMIT 1
        )
    ) as data,
    DATE_ADD(DATE(c.created_at), INTERVAL 1 DAY) as expires_at,
    NOW() as created_at,
    NOW() as updated_at
FROM customers c
WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
    AND c.line_account_id IS NOT NULL
GROUP BY c.line_account_id, DATE(c.created_at)
ON DUPLICATE KEY UPDATE
    data = VALUES(data),
    updated_at = NOW();

-- Log customer metrics population
INSERT INTO cache_population_log (cache_type, records_created, status)
SELECT 'customer_metrics', ROW_COUNT(), 'success';

-- Populate API cache with frequently accessed data
INSERT INTO api_cache (
    cache_key,
    data,
    expires_at,
    created_at
)
SELECT 
    CONCAT('dashboard_overview_', line_account_id, '_', DATE(NOW())) as cache_key,
    JSON_OBJECT(
        'orders', JSON_OBJECT(
            'today_count', COALESCE(
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.total_count'))
                 FROM dashboard_metrics_cache 
                 WHERE line_account_id = la.id 
                   AND metric_type = 'orders' 
                   AND date_key = DATE(NOW())
                 LIMIT 1), 0
            ),
            'today_total', COALESCE(
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.total_amount'))
                 FROM dashboard_metrics_cache 
                 WHERE line_account_id = la.id 
                   AND metric_type = 'orders' 
                   AND date_key = DATE(NOW())
                 LIMIT 1), 0
            )
        ),
        'payments', JSON_OBJECT(
            'pending_slips', COALESCE(
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.pending_slips'))
                 FROM dashboard_metrics_cache 
                 WHERE line_account_id = la.id 
                   AND metric_type = 'payments' 
                   AND date_key = DATE(NOW())
                 LIMIT 1), 0
            )
        ),
        'webhooks', JSON_OBJECT(
            'success_rate', COALESCE(
                (SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.success_rate'))
                 FROM dashboard_metrics_cache 
                 WHERE line_account_id = la.id 
                   AND metric_type = 'webhooks' 
                   AND date_key = DATE(NOW())
                 LIMIT 1), 100
            )
        ),
        'last_updated', NOW()
    ) as data,
    DATE_ADD(NOW(), INTERVAL 30 MINUTE) as expires_at,
    NOW() as created_at
FROM line_accounts la
WHERE la.status = 'active'
ON DUPLICATE KEY UPDATE
    data = VALUES(data),
    expires_at = VALUES(expires_at);

-- Log API cache population
INSERT INTO cache_population_log (cache_type, records_created, status)
SELECT 'api_cache', ROW_COUNT(), 'success';

-- Create summary report
SELECT 
    'Cache Population Summary' as report_type,
    cache_type,
    SUM(records_created) as total_records,
    COUNT(*) as cache_entries,
    AVG(processing_time_ms) as avg_processing_time_ms,
    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_operations,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_operations
FROM cache_population_log
GROUP BY cache_type
ORDER BY total_records DESC;

-- Verify cache effectiveness
SELECT 
    'Cache Effectiveness Check' as check_type,
    metric_type,
    COUNT(*) as cached_entries,
    COUNT(DISTINCT line_account_id) as accounts_covered,
    MIN(date_key) as earliest_date,
    MAX(date_key) as latest_date,
    AVG(JSON_LENGTH(data)) as avg_data_complexity
FROM dashboard_metrics_cache
GROUP BY metric_type
ORDER BY cached_entries DESC;

-- Update migration statistics
INSERT INTO migration_stats (
    migration_type,
    total_records,
    successful_records,
    failed_records,
    migration_date
) 
SELECT 
    'performance_cache',
    SUM(records_created),
    SUM(CASE WHEN status = 'success' THEN records_created ELSE 0 END),
    SUM(CASE WHEN status = 'failed' THEN records_created ELSE 0 END),
    NOW()
FROM cache_population_log;