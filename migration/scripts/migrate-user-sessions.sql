-- Migration Script: User Sessions from Legacy System
-- Purpose: Migrate active user sessions to new JWT-based system
-- Requirements: TC-3.2, TC-3.3

-- Create temporary table for session migration tracking
CREATE TEMPORARY TABLE session_migration_log (
    legacy_session_id VARCHAR(255),
    new_session_id VARCHAR(36),
    user_id VARCHAR(36),
    migration_status ENUM('success', 'failed', 'skipped'),
    migration_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    error_message TEXT
);

-- Set session variables for optimal performance
SET SESSION sql_mode = 'NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';
SET SESSION innodb_lock_wait_timeout = 300;

-- Start transaction for session migration
START TRANSACTION;

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
    AND u.status = 'active'
    AND NOT EXISTS (
        SELECT 1 FROM user_sessions us 
        WHERE us.user_id = u.id 
        AND us.expires_at > NOW()
    ); -- Avoid duplicates

-- Log successful migrations
INSERT INTO session_migration_log (legacy_session_id, new_session_id, user_id, migration_status)
SELECT 
    ls.session_id,
    us.id,
    us.user_id,
    'success'
FROM legacy_sessions ls
INNER JOIN users u ON ls.user_id = u.id
INNER JOIN user_sessions us ON us.user_id = u.id
WHERE ls.expires_at > NOW() 
    AND ls.is_active = 1
    AND u.status = 'active'
    AND us.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR); -- Recently created sessions

-- Handle special cases for admin users (extend session duration)
UPDATE user_sessions us
INNER JOIN users u ON us.user_id = u.id
SET us.expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE u.role IN ('super_admin', 'admin')
    AND us.expires_at < DATE_ADD(NOW(), INTERVAL 7 DAY);

-- Log admin session extensions
INSERT INTO session_migration_log (new_session_id, user_id, migration_status, error_message)
SELECT 
    us.id,
    us.user_id,
    'success',
    'Admin session extended to 30 days'
FROM user_sessions us
INNER JOIN users u ON us.user_id = u.id
WHERE u.role IN ('super_admin', 'admin')
    AND us.expires_at > DATE_ADD(NOW(), INTERVAL 7 DAY);

-- Log failed migrations
INSERT INTO session_migration_log (legacy_session_id, user_id, migration_status, error_message)
SELECT 
    ls.session_id,
    ls.user_id,
    'failed',
    CASE 
        WHEN u.id IS NULL THEN 'User not found'
        WHEN u.status != 'active' THEN 'User inactive'
        WHEN ls.expires_at <= NOW() THEN 'Session expired'
        WHEN ls.is_active = 0 THEN 'Session inactive'
        ELSE 'Unknown error'
    END
FROM legacy_sessions ls
LEFT JOIN users u ON ls.user_id = u.id
LEFT JOIN user_sessions us ON us.user_id = ls.user_id AND us.expires_at > NOW()
WHERE us.id IS NULL
    AND ls.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY); -- Only check recent sessions

-- Create performance indexes for user sessions
CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id ON user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_token_hash ON user_sessions(token_hash);
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires_at ON user_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_user_sessions_last_activity ON user_sessions(last_activity);

-- Clean up expired sessions from new system
DELETE FROM user_sessions WHERE expires_at < NOW();

-- Update session statistics
UPDATE users u
SET last_login = (
    SELECT MAX(us.last_activity)
    FROM user_sessions us
    WHERE us.user_id = u.id
    AND us.expires_at > NOW()
)
WHERE EXISTS (
    SELECT 1 FROM user_sessions us
    WHERE us.user_id = u.id
    AND us.expires_at > NOW()
);

-- Commit transaction
COMMIT;

-- Generate migration report
SELECT 
    'Session Migration Summary' as report_type,
    COUNT(*) as total_legacy_sessions,
    SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END) as successful_migrations,
    SUM(CASE WHEN migration_status = 'failed' THEN 1 ELSE 0 END) as failed_migrations,
    SUM(CASE WHEN migration_status = 'skipped' THEN 1 ELSE 0 END) as skipped_migrations,
    ROUND(
        (SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END) * 100.0) / COUNT(*), 
        2
    ) as success_rate_percent
FROM session_migration_log;

-- Verify session integrity
SELECT 
    'Session Integrity Check' as check_type,
    COUNT(*) as total_active_sessions,
    COUNT(DISTINCT user_id) as unique_users,
    MIN(expires_at) as earliest_expiry,
    MAX(expires_at) as latest_expiry,
    AVG(TIMESTAMPDIFF(HOUR, NOW(), expires_at)) as avg_hours_until_expiry
FROM user_sessions 
WHERE expires_at > NOW();

-- Check for potential issues
SELECT 
    'Potential Issues' as issue_type,
    'Duplicate sessions for same user' as issue_description,
    user_id,
    COUNT(*) as session_count
FROM user_sessions
WHERE expires_at > NOW()
GROUP BY user_id
HAVING COUNT(*) > 3
ORDER BY session_count DESC;

-- Session distribution by user role
SELECT 
    'Session Distribution' as report_type,
    u.role,
    COUNT(us.id) as active_sessions,
    AVG(TIMESTAMPDIFF(HOUR, NOW(), us.expires_at)) as avg_hours_until_expiry
FROM user_sessions us
INNER JOIN users u ON us.user_id = u.id
WHERE us.expires_at > NOW()
GROUP BY u.role
ORDER BY active_sessions DESC;

-- Update migration statistics
INSERT INTO migration_stats (
    migration_type,
    total_records,
    successful_records,
    failed_records,
    migration_date,
    notes
) 
SELECT 
    'user_sessions',
    COUNT(*),
    SUM(CASE WHEN migration_status = 'success' THEN 1 ELSE 0 END),
    SUM(CASE WHEN migration_status = 'failed' THEN 1 ELSE 0 END),
    NOW(),
    CONCAT('Skipped: ', SUM(CASE WHEN migration_status = 'skipped' THEN 1 ELSE 0 END))
FROM session_migration_log;

-- Reset session variables
SET SESSION sql_mode = DEFAULT;
SET SESSION innodb_lock_wait_timeout = DEFAULT;

SELECT 'User session migration completed successfully' as final_status;