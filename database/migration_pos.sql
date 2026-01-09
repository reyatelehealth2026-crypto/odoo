-- POS System Migration
-- Version: 1.0
-- Date: 2026-01-10

-- =====================================================
-- POS Shifts Table
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_shifts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    line_account_id INT,
    cashier_id INT NOT NULL,
    shift_number VARCHAR(50) UNIQUE NOT NULL,
    
    -- Cash tracking
    opening_cash DECIMAL(12,2) NOT NULL,
    closing_cash DECIMAL(12,2) NULL,
    expected_cash DECIMAL(12,2) NULL,
    variance DECIMAL(12,2) NULL,
    
    -- Summary
    total_sales DECIMAL(12,2) DEFAULT 0,
    total_transactions INT DEFAULT 0,
    total_refunds DECIMAL(12,2) DEFAULT 0,
    
    -- Status
    status ENUM('open', 'closed') DEFAULT 'open',
    opened_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    
    INDEX idx_cashier (cashier_id),
    INDEX idx_status (status),
    INDEX idx_line_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- POS Transactions Table
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    line_account_id INT,
    transaction_number VARCHAR(50) UNIQUE NOT NULL,
    shift_id INT NOT NULL,
    cashier_id INT NOT NULL,
    customer_id INT NULL,
    customer_type ENUM('walk_in', 'member') DEFAULT 'walk_in',
    
    -- Amounts
    subtotal DECIMAL(12,2) DEFAULT 0,
    discount_type ENUM('percent', 'fixed') NULL,
    discount_value DECIMAL(12,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    vat_amount DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) DEFAULT 0,
    
    -- Points
    points_earned INT DEFAULT 0,
    points_redeemed INT DEFAULT 0,
    points_value DECIMAL(12,2) DEFAULT 0,
    
    -- Status
    status ENUM('draft', 'completed', 'voided') DEFAULT 'draft',
    voided_at DATETIME NULL,
    voided_by INT NULL,
    void_reason VARCHAR(255) NULL,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    
    INDEX idx_shift (shift_id),
    INDEX idx_cashier (cashier_id),
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    INDEX idx_date (created_at),
    INDEX idx_line_account (line_account_id),
    
    FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- POS Transaction Items Table
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_transaction_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id INT NULL,
    
    -- Product info snapshot
    product_name VARCHAR(255) NULL,
    product_sku VARCHAR(100) NULL,
    
    -- Quantities
    quantity INT NOT NULL,
    returned_quantity INT DEFAULT 0,
    
    -- Pricing
    unit_price DECIMAL(12,2) NOT NULL,
    cost_price DECIMAL(12,2) NULL,
    discount_type ENUM('percent', 'fixed') NULL,
    discount_value DECIMAL(12,2) DEFAULT 0,
    discount_amount DECIMAL(12,2) DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- POS Payments Table
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    transaction_id INT NOT NULL,
    payment_method ENUM('cash', 'transfer', 'card', 'points', 'credit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    
    -- Cash specific
    cash_received DECIMAL(12,2) NULL,
    change_amount DECIMAL(12,2) NULL,
    
    -- Transfer/Card specific
    reference_number VARCHAR(100) NULL,
    
    -- Points specific
    points_used INT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (transaction_id) REFERENCES pos_transactions(id) ON DELETE CASCADE,
    INDEX idx_method (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- POS Returns Table
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_returns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    line_account_id INT,
    return_number VARCHAR(50) UNIQUE NOT NULL,
    original_transaction_id INT NOT NULL,
    shift_id INT NOT NULL,
    
    -- Amounts
    total_amount DECIMAL(12,2) NOT NULL,
    refund_amount DECIMAL(12,2) NOT NULL,
    refund_method ENUM('cash', 'original', 'credit') NOT NULL,
    
    -- Points reversal
    points_deducted INT DEFAULT 0,
    
    -- Details
    reason VARCHAR(255) NOT NULL,
    processed_by INT NOT NULL,
    authorized_by INT NULL,
    
    -- Status
    status ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    
    FOREIGN KEY (original_transaction_id) REFERENCES pos_transactions(id) ON DELETE RESTRICT,
    INDEX idx_original (original_transaction_id),
    INDEX idx_line_account (line_account_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- POS Return Items Table
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_return_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    return_id INT NOT NULL,
    original_item_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id INT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    
    FOREIGN KEY (return_id) REFERENCES pos_returns(id) ON DELETE CASCADE,
    FOREIGN KEY (original_item_id) REFERENCES pos_transaction_items(id) ON DELETE RESTRICT,
    INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- POS Daily Summary Table (for accounting integration)
-- =====================================================
CREATE TABLE IF NOT EXISTS pos_daily_summary (
    id INT PRIMARY KEY AUTO_INCREMENT,
    line_account_id INT,
    summary_date DATE NOT NULL,
    
    -- Sales totals
    total_sales DECIMAL(12,2) DEFAULT 0,
    total_transactions INT DEFAULT 0,
    total_items_sold INT DEFAULT 0,
    
    -- Payment breakdown
    cash_sales DECIMAL(12,2) DEFAULT 0,
    transfer_sales DECIMAL(12,2) DEFAULT 0,
    card_sales DECIMAL(12,2) DEFAULT 0,
    points_sales DECIMAL(12,2) DEFAULT 0,
    credit_sales DECIMAL(12,2) DEFAULT 0,
    
    -- Returns
    total_returns DECIMAL(12,2) DEFAULT 0,
    return_count INT DEFAULT 0,
    
    -- VAT
    total_vat DECIMAL(12,2) DEFAULT 0,
    
    -- Net
    net_sales DECIMAL(12,2) DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY idx_date_account (summary_date, line_account_id),
    INDEX idx_line_account (line_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
