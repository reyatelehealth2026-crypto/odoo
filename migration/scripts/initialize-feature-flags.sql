-- Migration Script: Feature Flag System Initialization
-- Purpose: Set up feature flag infrastructure for gradual rollout
-- Requirements: TC-3.1

-- Create feature flags configuration table
CREATE TABLE IF NOT EXISTS feature_flags (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    flag_name VARCHAR(100) NOT NULL UNIQUE,
    display_name VARCHAR(200) NOT NULL,
    description TEXT,
    enabled BOOLEAN DEFAULT FALSE,
    rollout_percentage INT DEFAULT 0 CHECK (rollout_percentage >= 0 AND rollout_percentage <= 100),
    user_groups JSON,
    start_date TIMESTAMP NULL,
    end_date TIMESTAMP NULL,
    created_by VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_flag_name (flag_name),
    INDEX idx_enabled (enabled),
    INDEX idx_rollout_percentage (rollout_percentage)
);

-- Create A/B test configuration table
CREATE TABLE IF NOT EXISTS ab_tests (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    test_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    variants JSON NOT NULL,
    start_date TIMESTAMP NOT NULL,
    end_date TIMESTAMP NOT NULL,
    target_user_groups JSON,
    status ENUM('draft', 'active', 'paused', 'completed') DEFAULT 'draft',
    created_by VARCHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_test_name (test_name),
    INDEX idx_status (status),
    INDEX idx_date_range (start_date, end_date)
);

-- Create user feature flag assignments table
CREATE TABLE IF NOT EXISTS user_feature_assignments (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    user_id VARCHAR(36) NOT NULL,
    flag_name VARCHAR(100) NOT NULL,
    assigned_value BOOLEAN NOT NULL,
    assignment_reason ENUM('rollout', 'manual', 'ab_test', 'user_group') NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    INDEX idx_user_flag (user_id, flag_name),
    INDEX idx_flag_name (flag_name),
    INDEX idx_expires_at (expires_at),
    FOREIGN KEY (flag_name) REFERENCES feature_flags(flag_name) ON DELETE CASCADE
);

-- Create feature flag audit log
CREATE TABLE IF NOT EXISTS feature_flag_audit (
    id VARCHAR(36) PRIMARY KEY DEFAULT (UUID()),
    flag_name VARCHAR(100) NOT NULL,
    action ENUM('created', 'updated', 'deleted', 'rollout_changed') NOT NULL,
    old_values JSON,
    new_values JSON,
    changed_by VARCHAR(36) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_flag_name (flag_name),
    INDEX idx_action (action),
    INDEX idx_changed_at (changed_at)
);

-- Initialize core feature flags for Odoo Dashboard migration
INSERT INTO feature_flags (
    flag_name,
    display_name,
    description,
    enabled,
    rollout_percentage,
    user_groups,
    created_by
) VALUES 
(
    'useNewDashboard',
    'New Dashboard System',
    'Use the modernized Next.js dashboard instead of legacy PHP dashboard',
    TRUE,
    5, -- Start with 5% rollout
    JSON_ARRAY('beta_testers', 'admin'),
    'system'
),
(
    'useNewOrderManagement',
    'New Order Management',
    'Use the modernized order management system with real-time updates',
    FALSE,
    0, -- Start disabled
    JSON_ARRAY('admin'),
    'system'
),
(
    'useNewPaymentProcessing',
    'New Payment Processing',
    'Use the modernized payment slip processing system with automatic matching',
    FALSE,
    0, -- Start disabled
    JSON_ARRAY('admin'),
    'system'
),
(
    'useNewWebhookManagement',
    'New Webhook Management',
    'Use the modernized webhook monitoring and management system',
    FALSE,
    0, -- Start disabled
    JSON_ARRAY('admin'),
    'system'
),
(
    'useNewCustomerManagement',
    'New Customer Management',
    'Use the modernized customer management system with LINE integration',
    FALSE,
    0, -- Start disabled
    JSON_ARRAY('admin'),
    'system'
),
(
    'enableRealTimeUpdates',
    'Real-time Updates',
    'Enable WebSocket-based real-time updates for dashboard and notifications',
    TRUE,
    10, -- Start with 10% rollout
    JSON_ARRAY('beta_testers', 'admin'),
    'system'
),
(
    'enablePerformanceOptimizations',
    'Performance Optimizations',
    'Enable advanced caching and performance optimizations',
    TRUE,
    25, -- Start with 25% rollout
    JSON_ARRAY('all'),
    'system'
),
(
    'enableAdvancedAuditLogging',
    'Advanced Audit Logging',
    'Enable comprehensive audit logging for all user actions',
    TRUE,
    50, -- Start with 50% rollout
    JSON_ARRAY('all'),
    'system'
);

-- Create role-based feature flag overrides
INSERT INTO user_feature_assignments (
    user_id,
    flag_name,
    assigned_value,
    assignment_reason
)
SELECT 
    u.id,
    'useNewDashboard',
    TRUE,
    'user_group'
FROM users u
WHERE u.role IN ('super_admin', 'admin')
    AND u.status = 'active';

-- Create beta tester assignments
INSERT INTO user_feature_assignments (
    user_id,
    flag_name,
    assigned_value,
    assignment_reason
)
SELECT 
    u.id,
    ff.flag_name,
    TRUE,
    'user_group'
FROM users u
CROSS JOIN feature_flags ff
WHERE u.email LIKE '%@company.com' -- Internal users
    AND u.status = 'active'
    AND ff.flag_name IN ('useNewDashboard', 'enableRealTimeUpdates')
    AND JSON_CONTAINS(ff.user_groups, '"beta_testers"');

-- Initialize A/B test for dashboard comparison
INSERT INTO ab_tests (
    test_name,
    description,
    variants,
    start_date,
    end_date,
    target_user_groups,
    status,
    created_by
) VALUES (
    'dashboard_comparison_v1',
    'Compare user engagement between legacy and modern dashboard',
    JSON_ARRAY(
        JSON_OBJECT(
            'name', 'legacy',
            'percentage', 90,
            'config', JSON_OBJECT('useNewDashboard', false)
        ),
        JSON_OBJECT(
            'name', 'modern',
            'percentage', 10,
            'config', JSON_OBJECT('useNewDashboard', true)
        )
    ),
    NOW(),
    DATE_ADD(NOW(), INTERVAL 30 DAY),
    JSON_ARRAY('staff', 'pharmacist'),
    'active',
    'system'
);

-- Create stored procedure for feature flag evaluation
DELIMITER //

CREATE PROCEDURE GetUserFeatureFlags(
    IN p_user_id VARCHAR(36),
    IN p_user_role VARCHAR(50),
    IN p_line_account_id VARCHAR(36)
)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE flag_name VARCHAR(100);
    DECLARE flag_enabled BOOLEAN;
    DECLARE rollout_percentage INT;
    DECLARE user_groups JSON;
    DECLARE assigned_value BOOLEAN DEFAULT NULL;
    
    DECLARE flag_cursor CURSOR FOR
        SELECT ff.flag_name, ff.enabled, ff.rollout_percentage, ff.user_groups
        FROM feature_flags ff
        WHERE ff.enabled = TRUE
            AND (ff.start_date IS NULL OR ff.start_date <= NOW())
            AND (ff.end_date IS NULL OR ff.end_date >= NOW());
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Create temporary table for results
    CREATE TEMPORARY TABLE user_flags (
        flag_name VARCHAR(100),
        enabled BOOLEAN,
        assignment_reason VARCHAR(50)
    );
    
    OPEN flag_cursor;
    
    flag_loop: LOOP
        FETCH flag_cursor INTO flag_name, flag_enabled, rollout_percentage, user_groups;
        IF done THEN
            LEAVE flag_loop;
        END IF;
        
        -- Check for explicit user assignment
        SELECT ufa.assigned_value INTO assigned_value
        FROM user_feature_assignments ufa
        WHERE ufa.user_id = p_user_id
            AND ufa.flag_name = flag_name
            AND (ufa.expires_at IS NULL OR ufa.expires_at > NOW())
        LIMIT 1;
        
        IF assigned_value IS NOT NULL THEN
            -- User has explicit assignment
            INSERT INTO user_flags VALUES (flag_name, assigned_value, 'explicit');
        ELSEIF JSON_CONTAINS(user_groups, CONCAT('"', p_user_role, '"')) THEN
            -- User role is in target groups
            INSERT INTO user_flags VALUES (flag_name, TRUE, 'user_group');
        ELSEIF rollout_percentage > 0 THEN
            -- Check rollout percentage using consistent hashing
            SET @user_hash = CRC32(CONCAT(p_user_id, flag_name)) % 100;
            IF @user_hash < rollout_percentage THEN
                INSERT INTO user_flags VALUES (flag_name, TRUE, 'rollout');
            ELSE
                INSERT INTO user_flags VALUES (flag_name, FALSE, 'rollout');
            END IF;
        ELSE
            -- Default to disabled
            INSERT INTO user_flags VALUES (flag_name, FALSE, 'default');
        END IF;
        
        SET assigned_value = NULL;
    END LOOP;
    
    CLOSE flag_cursor;
    
    -- Return results
    SELECT * FROM user_flags;
    
    -- Clean up
    DROP TEMPORARY TABLE user_flags;
END //

DELIMITER ;

-- Create function for rollout percentage calculation
DELIMITER //

CREATE FUNCTION CalculateUserRollout(
    p_user_id VARCHAR(36),
    p_flag_name VARCHAR(100)
) RETURNS BOOLEAN
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE rollout_percentage INT DEFAULT 0;
    DECLARE user_hash INT;
    
    -- Get rollout percentage for flag
    SELECT ff.rollout_percentage INTO rollout_percentage
    FROM feature_flags ff
    WHERE ff.flag_name = p_flag_name
        AND ff.enabled = TRUE;
    
    IF rollout_percentage IS NULL OR rollout_percentage = 0 THEN
        RETURN FALSE;
    END IF;
    
    IF rollout_percentage = 100 THEN
        RETURN TRUE;
    END IF;
    
    -- Calculate consistent hash
    SET user_hash = CRC32(CONCAT(p_user_id, p_flag_name)) % 100;
    
    RETURN user_hash < rollout_percentage;
END //

DELIMITER ;

-- Create triggers for audit logging
DELIMITER //

CREATE TRIGGER feature_flags_audit_insert
    AFTER INSERT ON feature_flags
    FOR EACH ROW
BEGIN
    INSERT INTO feature_flag_audit (
        flag_name,
        action,
        new_values,
        changed_by
    ) VALUES (
        NEW.flag_name,
        'created',
        JSON_OBJECT(
            'enabled', NEW.enabled,
            'rollout_percentage', NEW.rollout_percentage,
            'user_groups', NEW.user_groups
        ),
        NEW.created_by
    );
END //

CREATE TRIGGER feature_flags_audit_update
    AFTER UPDATE ON feature_flags
    FOR EACH ROW
BEGIN
    INSERT INTO feature_flag_audit (
        flag_name,
        action,
        old_values,
        new_values,
        changed_by
    ) VALUES (
        NEW.flag_name,
        CASE 
            WHEN OLD.rollout_percentage != NEW.rollout_percentage THEN 'rollout_changed'
            ELSE 'updated'
        END,
        JSON_OBJECT(
            'enabled', OLD.enabled,
            'rollout_percentage', OLD.rollout_percentage,
            'user_groups', OLD.user_groups
        ),
        JSON_OBJECT(
            'enabled', NEW.enabled,
            'rollout_percentage', NEW.rollout_percentage,
            'user_groups', NEW.user_groups
        ),
        COALESCE(NEW.created_by, 'system')
    );
END //

DELIMITER ;

-- Create views for easy feature flag management
CREATE VIEW feature_flag_summary AS
SELECT 
    ff.flag_name,
    ff.display_name,
    ff.enabled,
    ff.rollout_percentage,
    COUNT(ufa.id) as explicit_assignments,
    ff.created_at,
    ff.updated_at
FROM feature_flags ff
LEFT JOIN user_feature_assignments ufa ON ff.flag_name = ufa.flag_name
    AND (ufa.expires_at IS NULL OR ufa.expires_at > NOW())
GROUP BY ff.id;

-- Create view for rollout statistics
CREATE VIEW rollout_statistics AS
SELECT 
    ff.flag_name,
    ff.rollout_percentage,
    COUNT(DISTINCT u.id) as total_eligible_users,
    COUNT(DISTINCT CASE 
        WHEN CalculateUserRollout(u.id, ff.flag_name) = TRUE 
        THEN u.id 
    END) as users_in_rollout,
    ROUND(
        (COUNT(DISTINCT CASE 
            WHEN CalculateUserRollout(u.id, ff.flag_name) = TRUE 
            THEN u.id 
        END) * 100.0) / COUNT(DISTINCT u.id),
        2
    ) as actual_rollout_percentage
FROM feature_flags ff
CROSS JOIN users u
WHERE ff.enabled = TRUE
    AND u.status = 'active'
GROUP BY ff.id;

-- Insert initial migration record
INSERT INTO migration_stats (
    migration_type,
    total_records,
    successful_records,
    failed_records,
    migration_date,
    notes
) VALUES (
    'feature_flags_init',
    (SELECT COUNT(*) FROM feature_flags),
    (SELECT COUNT(*) FROM feature_flags),
    0,
    NOW(),
    'Feature flag system initialized with gradual rollout configuration'
);

-- Generate initialization report
SELECT 
    'Feature Flag Initialization Summary' as report_type,
    COUNT(*) as total_flags_created,
    SUM(CASE WHEN enabled = TRUE THEN 1 ELSE 0 END) as enabled_flags,
    AVG(rollout_percentage) as average_rollout_percentage,
    (SELECT COUNT(*) FROM user_feature_assignments) as explicit_assignments,
    (SELECT COUNT(*) FROM ab_tests) as ab_tests_created
FROM feature_flags;

SELECT 'Feature flag system initialized successfully' as final_status;