-- Migration: Video Calls Tables
-- ระบบ Video Call สำหรับลูกค้าโทรหาแอดมิน

-- ตารางหลักเก็บข้อมูลการโทร
CREATE TABLE IF NOT EXISTS video_calls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id VARCHAR(100) NOT NULL UNIQUE,
    user_id INT NULL,
    line_user_id VARCHAR(50) NULL,
    display_name VARCHAR(255) NULL,
    picture_url VARCHAR(500) NULL,
    line_account_id INT NULL,
    status ENUM('pending', 'ringing', 'active', 'completed', 'missed', 'rejected') DEFAULT 'pending',
    duration INT DEFAULT 0 COMMENT 'Duration in seconds',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    answered_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    notes TEXT NULL,
    INDEX idx_room (room_id),
    INDEX idx_status (status),
    INDEX idx_account (line_account_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตารางเก็บ WebRTC Signaling Data
CREATE TABLE IF NOT EXISTS video_call_signals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id INT NOT NULL,
    signal_type VARCHAR(50) NOT NULL COMMENT 'offer, answer, ice-candidate',
    signal_data LONGTEXT NOT NULL,
    sender_type ENUM('admin', 'customer') DEFAULT 'customer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_call (call_id),
    FOREIGN KEY (call_id) REFERENCES video_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตารางตั้งค่า Video Call
CREATE TABLE IF NOT EXISTS video_call_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT NULL,
    is_enabled TINYINT(1) DEFAULT 1,
    auto_answer TINYINT(1) DEFAULT 0,
    max_duration INT DEFAULT 3600 COMMENT 'Max call duration in seconds',
    working_hours_start TIME DEFAULT '09:00:00',
    working_hours_end TIME DEFAULT '18:00:00',
    offline_message TEXT DEFAULT 'ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลาทำการ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_account (line_account_id),
    FOREIGN KEY (line_account_id) REFERENCES line_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO video_call_settings (line_account_id, is_enabled) VALUES (NULL, 1)
ON DUPLICATE KEY UPDATE is_enabled = is_enabled;


-- Add appointment_id column to video_calls (if not exists)
-- This links video calls to appointments
ALTER TABLE video_calls ADD COLUMN IF NOT EXISTS appointment_id INT NULL AFTER line_account_id;
ALTER TABLE video_calls ADD INDEX IF NOT EXISTS idx_appointment (appointment_id);
