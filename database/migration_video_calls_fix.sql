-- Migration: Fix video_calls table - add missing columns
-- Date: 2025-12-26

-- Add line_user_id column if not exists
ALTER TABLE video_calls ADD COLUMN IF NOT EXISTS line_user_id VARCHAR(50) NULL AFTER user_id;

-- Add display_name column if not exists  
ALTER TABLE video_calls ADD COLUMN IF NOT EXISTS display_name VARCHAR(255) NULL AFTER line_user_id;

-- Add picture_url column if not exists
ALTER TABLE video_calls ADD COLUMN IF NOT EXISTS picture_url VARCHAR(500) NULL AFTER display_name;

-- Add line_account_id column if not exists
ALTER TABLE video_calls ADD COLUMN IF NOT EXISTS line_account_id INT NULL AFTER picture_url;

-- Add indexes if not exist
CREATE INDEX IF NOT EXISTS idx_line_user ON video_calls(line_user_id);
