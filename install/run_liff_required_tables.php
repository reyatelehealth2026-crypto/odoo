<?php
/**
 * Run LIFF Required Tables Migration
 * Creates all necessary tables for LIFF app functionality
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

echo "<h1>LIFF Required Tables Migration</h1>";
echo "<pre>";

// Tables to create
$tables = [
    'cart_items' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'appointments' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'pharmacists' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'pharmacist_schedules' => "
        CREATE TABLE IF NOT EXISTS pharmacist_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pharmacist_id INT NOT NULL,
            day_of_week TINYINT NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            is_available TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pharmacist (pharmacist_id),
            UNIQUE KEY unique_schedule (pharmacist_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'pharmacist_holidays' => "
        CREATE TABLE IF NOT EXISTS pharmacist_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pharmacist_id INT NOT NULL,
            holiday_date DATE NOT NULL,
            reason VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pharmacist (pharmacist_id),
            UNIQUE KEY unique_holiday (pharmacist_id, holiday_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'point_rewards' => "
        CREATE TABLE IF NOT EXISTS point_rewards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_account_id INT DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            points_required INT NOT NULL,
            type ENUM('discount', 'shipping', 'gift', 'coupon') DEFAULT 'discount',
            value DECIMAL(10,2) DEFAULT 0,
            image_url VARCHAR(500),
            stock INT DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active),
            INDEX idx_points (points_required)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'points_history' => "
        CREATE TABLE IF NOT EXISTS points_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            line_account_id INT DEFAULT 1,
            points INT NOT NULL,
            type ENUM('earn', 'redeem', 'expire', 'adjust', 'bonus') DEFAULT 'earn',
            description VARCHAR(255),
            reference_type VARCHAR(50),
            reference_id INT,
            balance_after INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_type (type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'wishlist' => "
        CREATE TABLE IF NOT EXISTS wishlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            product_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_product (product_id),
            UNIQUE KEY unique_wishlist (user_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'user_notification_settings' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'medication_reminders' => "
        CREATE TABLE IF NOT EXISTS medication_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            line_account_id INT DEFAULT 1,
            medication_name VARCHAR(255) NOT NULL,
            dosage VARCHAR(100),
            frequency VARCHAR(50),
            times JSON,
            start_date DATE,
            end_date DATE,
            notes TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'transactions' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'transaction_items' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    
    'payment_slips' => "
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "
];

// Create tables
foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "✅ Table '{$name}' created/verified\n";
    } catch (Exception $e) {
        echo "❌ Error creating '{$name}': " . $e->getMessage() . "\n";
    }
}

// Add columns to users table if missing
$userColumns = [
    'member_id' => "VARCHAR(50)",
    'is_registered' => "TINYINT(1) DEFAULT 0",
    'first_name' => "VARCHAR(100)",
    'last_name' => "VARCHAR(100)",
    'birthday' => "DATE",
    'gender' => "ENUM('male', 'female', 'other')",
    'phone' => "VARCHAR(20)",
    'weight' => "DECIMAL(5,2)",
    'height' => "DECIMAL(5,2)",
    'drug_allergies' => "TEXT",
    'medical_conditions' => "TEXT",
    'address' => "TEXT",
    'district' => "VARCHAR(100)",
    'province' => "VARCHAR(100)",
    'postal_code' => "VARCHAR(10)",
    'points' => "INT DEFAULT 0",
    'tier' => "VARCHAR(50) DEFAULT 'Silver'"
];

echo "\n--- Adding columns to users table ---\n";

// Get existing columns
try {
    $existingCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $existingCols = array_flip($existingCols);
    
    foreach ($userColumns as $col => $type) {
        if (!isset($existingCols[$col])) {
            try {
                $db->exec("ALTER TABLE users ADD COLUMN {$col} {$type}");
                echo "✅ Added column 'users.{$col}'\n";
            } catch (Exception $e) {
                echo "⚠️ Column 'users.{$col}': " . $e->getMessage() . "\n";
            }
        } else {
            echo "✓ Column 'users.{$col}' exists\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error checking users table: " . $e->getMessage() . "\n";
}

// Add columns to pharmacists table if missing
$pharmacistColumns = [
    'consultation_fee' => "DECIMAL(10,2) DEFAULT 0",
    'consultation_duration' => "INT DEFAULT 15",
    'is_available' => "TINYINT(1) DEFAULT 1",
    'is_active' => "TINYINT(1) DEFAULT 1",
    'rating' => "DECIMAL(2,1) DEFAULT 5.0",
    'review_count' => "INT DEFAULT 0"
];

echo "\n--- Adding columns to pharmacists table ---\n";

try {
    $existingCols = $db->query("SHOW COLUMNS FROM pharmacists")->fetchAll(PDO::FETCH_COLUMN);
    $existingCols = array_flip($existingCols);
    
    foreach ($pharmacistColumns as $col => $type) {
        if (!isset($existingCols[$col])) {
            try {
                $db->exec("ALTER TABLE pharmacists ADD COLUMN {$col} {$type}");
                echo "✅ Added column 'pharmacists.{$col}'\n";
            } catch (Exception $e) {
                echo "⚠️ Column 'pharmacists.{$col}': " . $e->getMessage() . "\n";
            }
        } else {
            echo "✓ Column 'pharmacists.{$col}' exists\n";
        }
    }
} catch (Exception $e) {
    echo "⚠️ Pharmacists table: " . $e->getMessage() . "\n";
}

// Add columns to transactions table if missing
$transactionColumns = [
    'total_amount' => "DECIMAL(10,2) DEFAULT 0",
    'delivery_info' => "JSON"
];

echo "\n--- Adding columns to transactions table ---\n";

try {
    $existingCols = $db->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
    $existingCols = array_flip($existingCols);
    
    foreach ($transactionColumns as $col => $type) {
        if (!isset($existingCols[$col])) {
            try {
                $db->exec("ALTER TABLE transactions ADD COLUMN {$col} {$type}");
                echo "✅ Added column 'transactions.{$col}'\n";
            } catch (Exception $e) {
                echo "⚠️ Column 'transactions.{$col}': " . $e->getMessage() . "\n";
            }
        } else {
            echo "✓ Column 'transactions.{$col}' exists\n";
        }
    }
} catch (Exception $e) {
    echo "⚠️ Transactions table: " . $e->getMessage() . "\n";
}

// Add columns to transaction_items table if missing
$transactionItemColumns = [
    'product_price' => "DECIMAL(10,2) DEFAULT 0",
    'subtotal' => "DECIMAL(10,2) DEFAULT 0"
];

echo "\n--- Adding columns to transaction_items table ---\n";

try {
    $existingCols = $db->query("SHOW COLUMNS FROM transaction_items")->fetchAll(PDO::FETCH_COLUMN);
    $existingCols = array_flip($existingCols);
    
    foreach ($transactionItemColumns as $col => $type) {
        if (!isset($existingCols[$col])) {
            try {
                $db->exec("ALTER TABLE transaction_items ADD COLUMN {$col} {$type}");
                echo "✅ Added column 'transaction_items.{$col}'\n";
            } catch (Exception $e) {
                echo "⚠️ Column 'transaction_items.{$col}': " . $e->getMessage() . "\n";
            }
        } else {
            echo "✓ Column 'transaction_items.{$col}' exists\n";
        }
    }
} catch (Exception $e) {
    echo "⚠️ Transaction_items table: " . $e->getMessage() . "\n";
}

// Insert sample rewards
echo "\n--- Inserting sample data ---\n";

try {
    $stmt = $db->query("SELECT COUNT(*) FROM point_rewards");
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        $db->exec("
            INSERT INTO point_rewards (name, description, points_required, type, value, is_active) VALUES
            ('ส่วนลด 50 บาท', 'คูปองส่วนลด 50 บาท', 100, 'discount', 50, 1),
            ('ส่วนลด 100 บาท', 'คูปองส่วนลด 100 บาท', 200, 'discount', 100, 1),
            ('จัดส่งฟรี', 'ฟรีค่าจัดส่ง 1 ครั้ง', 150, 'shipping', 0, 1),
            ('ของขวัญพิเศษ', 'รับของขวัญพิเศษจากร้าน', 500, 'gift', 0, 1)
        ");
        echo "✅ Inserted sample rewards\n";
    } else {
        echo "✓ Rewards already exist ({$count} records)\n";
    }
} catch (Exception $e) {
    echo "⚠️ Sample rewards: " . $e->getMessage() . "\n";
}

// Insert sample pharmacist schedules
try {
    $stmt = $db->query("SELECT id FROM pharmacists WHERE is_active = 1 LIMIT 1");
    $pharmacist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($pharmacist) {
        $pharmacistId = $pharmacist['id'];
        
        // Check if schedules exist
        $stmt = $db->prepare("SELECT COUNT(*) FROM pharmacist_schedules WHERE pharmacist_id = ?");
        $stmt->execute([$pharmacistId]);
        $scheduleCount = $stmt->fetchColumn();
        
        if ($scheduleCount == 0) {
            $schedules = [
                [1, '09:00:00', '17:00:00'],
                [2, '09:00:00', '17:00:00'],
                [3, '09:00:00', '17:00:00'],
                [4, '09:00:00', '17:00:00'],
                [5, '09:00:00', '17:00:00'],
                [6, '09:00:00', '12:00:00']
            ];
            
            $stmt = $db->prepare("INSERT INTO pharmacist_schedules (pharmacist_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, 1)");
            
            foreach ($schedules as $s) {
                $stmt->execute([$pharmacistId, $s[0], $s[1], $s[2]]);
            }
            echo "✅ Inserted schedules for pharmacist #{$pharmacistId}\n";
        } else {
            echo "✓ Pharmacist schedules already exist\n";
        }
    } else {
        echo "⚠️ No active pharmacist found to create schedules\n";
    }
} catch (Exception $e) {
    echo "⚠️ Pharmacist schedules: " . $e->getMessage() . "\n";
}

echo "\n</pre>";
echo "<h2>✅ Migration Complete!</h2>";
echo "<p><a href='../liff/'>Go to LIFF App</a></p>";
