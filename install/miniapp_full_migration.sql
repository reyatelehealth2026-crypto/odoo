-- =============================================================================
-- Mini App Full Migration (clinicya DB)
-- Creates missing tables + columns + seed data so LINE Mini App renders fully
-- Safe to re-run (IF NOT EXISTS / IGNORE guards)
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- -----------------------------------------------------------------------------
-- 1. miniapp_banners
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS miniapp_banners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200),
    subtitle VARCHAR(500),
    description TEXT,
    image_url VARCHAR(500) NOT NULL,
    image_mobile_url VARCHAR(500),
    link_type ENUM('url','miniapp','liff','line_chat','deep_link','none') DEFAULT 'none',
    link_value VARCHAR(500),
    link_label VARCHAR(100),
    surface ENUM('home','shop') DEFAULT 'home',
    position ENUM('home_top','home_middle','home_bottom') DEFAULT 'home_top',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    bg_color VARCHAR(20) DEFAULT NULL,
    start_date DATETIME DEFAULT NULL,
    end_date DATETIME DEFAULT NULL,
    line_account_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_position (position),
    INDEX idx_active (is_active),
    INDEX idx_order (display_order),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_line_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. miniapp_home_sections
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS miniapp_home_sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_key VARCHAR(50) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    subtitle VARCHAR(500),
    section_style ENUM('flash_sale','horizontal_scroll','grid','banner_list') DEFAULT 'horizontal_scroll',
    bg_color VARCHAR(20) DEFAULT NULL,
    text_color VARCHAR(20) DEFAULT NULL,
    icon_url VARCHAR(500) DEFAULT NULL,
    countdown_ends_at DATETIME DEFAULT NULL,
    surface ENUM('home','shop') DEFAULT 'home',
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    start_date DATETIME DEFAULT NULL,
    end_date DATETIME DEFAULT NULL,
    line_account_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_style (section_style),
    INDEX idx_active (is_active),
    INDEX idx_order (display_order),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_line_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. miniapp_home_products
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS miniapp_home_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    title VARCHAR(500) NOT NULL,
    short_description VARCHAR(500),
    image_url VARCHAR(500) NOT NULL,
    image_gallery JSON,
    original_price DECIMAL(12,2) DEFAULT NULL,
    sale_price DECIMAL(12,2) DEFAULT NULL,
    discount_percent DECIMAL(5,2) DEFAULT NULL,
    price_unit VARCHAR(50) DEFAULT NULL,
    promotion_tags JSON,
    promotion_label VARCHAR(100),
    badges JSON,
    custom_label VARCHAR(200),
    stock_qty INT DEFAULT NULL,
    limit_qty INT DEFAULT NULL,
    show_stock_badge TINYINT(1) DEFAULT 0,
    delivery_options JSON,
    link_type ENUM('url','miniapp','liff','line_chat','deep_link','none') DEFAULT 'none',
    link_value VARCHAR(500),
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    start_date DATETIME DEFAULT NULL,
    end_date DATETIME DEFAULT NULL,
    line_account_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_section (section_id),
    INDEX idx_active (is_active),
    INDEX idx_order (display_order),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_line_account (line_account_id),
    CONSTRAINT fk_miniapp_product_section FOREIGN KEY (section_id) REFERENCES miniapp_home_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. member_tiers (fallback tier table used by api/member.php?action=get_tiers)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_tiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_account_id INT DEFAULT NULL,
    tier_code VARCHAR(50) NOT NULL,
    tier_name VARCHAR(100) NOT NULL,
    min_points INT NOT NULL DEFAULT 0,
    color VARCHAR(20) DEFAULT '#6B7280',
    icon VARCHAR(10) DEFAULT '🏅',
    discount_percent DECIMAL(5,2) DEFAULT 0.00,
    benefits TEXT,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tier (line_account_id, tier_code),
    INDEX idx_active (is_active),
    INDEX idx_min_points (min_points)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. member_notification_preferences (api/member-notifications.php)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_notification_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    line_user_id VARCHAR(64) NOT NULL,
    line_account_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_account (line_user_id, line_account_id),
    INDEX idx_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
