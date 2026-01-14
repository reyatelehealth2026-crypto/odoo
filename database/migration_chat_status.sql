-- Migration: Add chat_status column to users table
-- Date: 2026-01-15
-- Description: เพิ่มสถานะแชทสำหรับการจัดการงาน

-- Add chat_status column to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS chat_status VARCHAR(50) DEFAULT NULL COMMENT 'สถานะแชท: pending, completed, shipping, tracking, billing';

-- Add index for faster filtering
CREATE INDEX IF NOT EXISTS idx_users_chat_status ON users(chat_status);

-- Create chat_status_history table for tracking status changes
CREATE TABLE IF NOT EXISTS chat_status_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    line_account_id INT NOT NULL,
    old_status VARCHAR(50) DEFAULT NULL,
    new_status VARCHAR(50) NOT NULL,
    changed_by INT DEFAULT NULL COMMENT 'admin_user_id who changed',
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    note TEXT DEFAULT NULL,
    INDEX idx_user_status (user_id, line_account_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
