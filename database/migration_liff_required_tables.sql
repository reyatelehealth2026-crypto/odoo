-- Migration: Required Tables for LIFF App
-- Run this to ensure all required tables exist

-- ==================== Cart Items Table ====================
CREATE TABLE IF NOT EXISTS cart_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    line_account_id INT DEFAULT 1,
    product_id INT NOT NULL,
    quantity INT DEFAULT 1,
    price DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_product (product_id),
    UNIQUE KEY unique_user_product (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Appointments Table ====================
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT 1,
    appointment_id VARCHAR(50) UNIQUE,
    user_id INT NOT NULL,
    pharmacist_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    end_time TIME,
    duration INT DEFAULT 15,
    type ENUM('instant', 'scheduled') DEFAULT 'scheduled',
    symptoms TEXT,
    consultation_fee DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show') DEFAULT 'pending',
    notes TEXT,
    video_room_id VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_pharmacist (pharmacist_id),
    INDEX idx_date (appointment_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Pharmacists Table ====================
CREATE TABLE IF NOT EXISTS pharmacists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT 1,
    name VARCHAR(255) NOT NULL,
    title VARCHAR(100),
    specialty VARCHAR(255) DEFAULT 'เภสัชกรทั่วไป',
    sub_specialty VARCHAR(255),
    hospital VARCHAR(255),
    license_no VARCHAR(50),
    bio TEXT,
    consulting_areas TEXT,
    work_experience TEXT,
    image_url VARCHAR(500),
    rating DECIMAL(2,1) DEFAULT 5.0,
    review_count INT DEFAULT 0,
    consultation_fee DECIMAL(10,2) DEFAULT 0,
    consultation_duration INT DEFAULT 15,
    is_available TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_available (is_available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Pharmacist Schedules Table ====================
CREATE TABLE IF NOT EXISTS pharmacist_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pharmacist_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pharmacist (pharmacist_id),
    UNIQUE KEY unique_schedule (pharmacist_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Pharmacist Holidays Table ====================
CREATE TABLE IF NOT EXISTS pharmacist_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pharmacist_id INT NOT NULL,
    holiday_date DATE NOT NULL,
    reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pharmacist (pharmacist_id),
    UNIQUE KEY unique_holiday (pharmacist_id, holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Point Rewards Table ====================
CREATE TABLE IF NOT EXISTS point_rewards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    points_required INT NOT NULL,
    type ENUM('discount', 'shipping', 'gift', 'coupon') DEFAULT 'discount',
    value DECIMAL(10,2) DEFAULT 0,
    image_url VARCHAR(500),
    stock INT DEFAULT NULL COMMENT 'NULL = unlimited',
    is_active TINYINT(1) DEFAULT 1,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_points (points_required)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Points History Table ====================
CREATE TABLE IF NOT EXISTS points_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    line_account_id INT DEFAULT 1,
    points INT NOT NULL COMMENT 'Positive = earned, Negative = used',
    type ENUM('earn', 'redeem', 'expire', 'adjust', 'bonus') DEFAULT 'earn',
    description VARCHAR(255),
    reference_type VARCHAR(50) COMMENT 'order, reward, manual, etc.',
    reference_id INT,
    balance_after INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Wishlist Table ====================
CREATE TABLE IF NOT EXISTS wishlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_product (product_id),
    UNIQUE KEY unique_wishlist (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== User Notifications Settings Table ====================
CREATE TABLE IF NOT EXISTS user_notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    order_updates TINYINT(1) DEFAULT 1,
    promotions TINYINT(1) DEFAULT 1,
    appointment_reminders TINYINT(1) DEFAULT 1,
    drug_reminders TINYINT(1) DEFAULT 1,
    health_tips TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Medication Reminders Table ====================
CREATE TABLE IF NOT EXISTS medication_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    line_account_id INT DEFAULT 1,
    medication_name VARCHAR(255) NOT NULL,
    dosage VARCHAR(100),
    frequency VARCHAR(50) COMMENT 'daily, twice_daily, etc.',
    times JSON COMMENT '["08:00", "20:00"]',
    start_date DATE,
    end_date DATE,
    notes TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Transactions Table (Orders) ====================
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT 1,
    order_number VARCHAR(50) UNIQUE,
    user_id INT NOT NULL,
    transaction_type ENUM('purchase', 'refund', 'exchange') DEFAULT 'purchase',
    total_amount DECIMAL(10,2) DEFAULT 0,
    subtotal DECIMAL(10,2) DEFAULT 0,
    discount DECIMAL(10,2) DEFAULT 0,
    shipping_fee DECIMAL(10,2) DEFAULT 0,
    grand_total DECIMAL(10,2) DEFAULT 0,
    delivery_info JSON,
    payment_method VARCHAR(50),
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    status ENUM('pending', 'confirmed', 'processing', 'shipped', 'delivered', 'completed', 'cancelled') DEFAULT 'pending',
    shipping_address TEXT,
    tracking_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Transaction Items Table ====================
CREATE TABLE IF NOT EXISTS transaction_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255),
    product_price DECIMAL(10,2) DEFAULT 0,
    quantity INT DEFAULT 1,
    price DECIMAL(10,2) DEFAULT 0,
    subtotal DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) DEFAULT 0,
    image_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction (transaction_id),
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Payment Slips Table ====================
CREATE TABLE IF NOT EXISTS payment_slips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    transaction_id INT,
    user_id INT,
    image_url VARCHAR(500) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    verified_by INT,
    verified_at TIMESTAMP NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_transaction (transaction_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==================== Add missing columns to users table ====================
-- Run these ALTER statements if columns don't exist

-- Check and add columns (MySQL will error if column exists, that's OK)
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS member_id VARCHAR(50);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS is_registered TINYINT(1) DEFAULT 0;
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(100);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS birthday DATE;
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS gender ENUM('male', 'female', 'other');
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS weight DECIMAL(5,2);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS height DECIMAL(5,2);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS drug_allergies TEXT;
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS medical_conditions TEXT;
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT;
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS district VARCHAR(100);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS province VARCHAR(100);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS postal_code VARCHAR(10);
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS points INT DEFAULT 0;
-- ALTER TABLE users ADD COLUMN IF NOT EXISTS tier VARCHAR(50) DEFAULT 'Silver';

-- ==================== Insert Sample Data ====================

-- Sample Rewards (if table is empty)
INSERT IGNORE INTO point_rewards (id, name, description, points_required, type, value, is_active) VALUES
(1, 'ส่วนลด 50 บาท', 'คูปองส่วนลด 50 บาท สำหรับการสั่งซื้อครั้งถัดไป', 100, 'discount', 50, 1),
(2, 'ส่วนลด 100 บาท', 'คูปองส่วนลด 100 บาท สำหรับการสั่งซื้อครั้งถัดไป', 200, 'discount', 100, 1),
(3, 'จัดส่งฟรี', 'ฟรีค่าจัดส่ง 1 ครั้ง', 150, 'shipping', 0, 1),
(4, 'ของขวัญพิเศษ', 'รับของขวัญพิเศษจากร้าน', 500, 'gift', 0, 1);

-- Sample Pharmacist Schedule (for pharmacist_id = 1, if exists)
INSERT IGNORE INTO pharmacist_schedules (pharmacist_id, day_of_week, start_time, end_time, is_available) VALUES
(1, 1, '09:00:00', '17:00:00', 1),
(1, 2, '09:00:00', '17:00:00', 1),
(1, 3, '09:00:00', '17:00:00', 1),
(1, 4, '09:00:00', '17:00:00', 1),
(1, 5, '09:00:00', '17:00:00', 1),
(1, 6, '09:00:00', '12:00:00', 1);
