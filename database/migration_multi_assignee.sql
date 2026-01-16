-- Multi-Assignee Support Migration
-- Version: 1.0
-- Date: 2026-01-16
-- Description: Adds support for assigning conversations to multiple admins

-- =====================================================
-- Conversation Multi-Assignees Table (Many-to-Many)
-- =====================================================
CREATE TABLE IF NOT EXISTS conversation_multi_assignees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'Customer user ID',
    admin_id INT NOT NULL COMMENT 'Admin user ID assigned',
    assigned_by INT NULL COMMENT 'Who assigned this admin',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'resolved') DEFAULT 'active',
    resolved_at DATETIME NULL,
    UNIQUE KEY uk_user_admin (user_id, admin_id),
    INDEX idx_user (user_id),
    INDEX idx_admin (admin_id),
    INDEX idx_status (status),
    INDEX idx_assigned_at (assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Supports multiple admins assigned to one conversation';

-- =====================================================
-- Migrate existing single assignments to multi-assignees
-- =====================================================
INSERT INTO conversation_multi_assignees (user_id, admin_id, assigned_by, assigned_at, status, resolved_at)
SELECT 
    user_id,
    assigned_to as admin_id,
    assigned_by,
    assigned_at,
    status,
    resolved_at
FROM conversation_assignments
WHERE assigned_to IS NOT NULL
ON DUPLICATE KEY UPDATE 
    assigned_at = VALUES(assigned_at),
    status = VALUES(status);

-- Note: Keep conversation_assignments table for backward compatibility
-- New code will use conversation_multi_assignees
