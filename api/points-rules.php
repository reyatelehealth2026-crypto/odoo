<?php
/**
 * Public Points Rules API
 * Requirement 25.10: Display earning rules to user - show current active rules and bonus campaigns
 * 
 * This API provides read-only access to points earning rules for LIFF users
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_GET['line_account_id'] ?? $_GET['account'] ?? 1;

try {
    // Get earning rules, active campaigns, tier settings, and category bonuses
    $response = [
        'success' => true,
        'data' => [
            'earning_rules' => getEarningRules($db, $lineAccountId),
            'active_campaigns' => getActiveCampaigns($db, $lineAccountId),
            'tier_benefits' => getTierBenefits($db, $lineAccountId),
            'category_bonuses' => getCategoryBonuses($db, $lineAccountId)
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการโหลดข้อมูล'
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Get base earning rules
 * Requirement 25.10: Show current active rules
 */
function getEarningRules($db, $lineAccountId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                points_per_baht,
                min_order_for_points,
                points_expiry_days,
                is_active
            FROM points_settings 
            WHERE (line_account_id = ? OR line_account_id IS NULL) AND is_active = 1
            ORDER BY line_account_id DESC 
            LIMIT 1
        ");
        $stmt->execute([$lineAccountId]);
        $rules = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($rules) {
            return [
                'points_per_baht' => floatval($rules['points_per_baht']),
                'min_order_for_points' => floatval($rules['min_order_for_points']),
                'points_expiry_months' => intval($rules['points_expiry_days'] / 30),
                'is_active' => (bool)$rules['is_active']
            ];
        }
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Return defaults
    return [
        'points_per_baht' => 1.0,
        'min_order_for_points' => 0,
        'points_expiry_months' => 12,
        'is_active' => true
    ];
}

/**
 * Get active and upcoming campaigns
 * Requirement 25.10: Show any bonus campaigns
 */
function getActiveCampaigns($db, $lineAccountId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                id,
                name,
                multiplier,
                start_date,
                end_date,
                applicable_categories,
                CASE 
                    WHEN NOW() BETWEEN start_date AND end_date THEN 'active'
                    WHEN start_date > NOW() THEN 'upcoming'
                    ELSE 'expired'
                END as status
            FROM points_campaigns
            WHERE (line_account_id = ? OR line_account_id IS NULL)
                AND is_active = 1
                AND end_date >= NOW()
            ORDER BY 
                CASE WHEN NOW() BETWEEN start_date AND end_date THEN 0 ELSE 1 END,
                start_date ASC
            LIMIT 10
        ");
        $stmt->execute([$lineAccountId]);
        $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format campaigns for display
        return array_map(function($campaign) {
            return [
                'id' => intval($campaign['id']),
                'name' => $campaign['name'],
                'multiplier' => floatval($campaign['multiplier']),
                'start_date' => $campaign['start_date'],
                'end_date' => $campaign['end_date'],
                'status' => $campaign['status'],
                'applicable_categories' => $campaign['applicable_categories'] 
                    ? json_decode($campaign['applicable_categories'], true) 
                    : null
            ];
        }, $campaigns);
        
    } catch (Exception $e) {
        // Table might not exist
        return [];
    }
}

/**
 * Get tier benefits for display
 * Requirement 25.10: Show tier multipliers
 */
function getTierBenefits($db, $lineAccountId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                name,
                min_points,
                multiplier,
                benefits
            FROM tier_settings 
            WHERE line_account_id = ? OR line_account_id IS NULL
            ORDER BY min_points ASC
        ");
        $stmt->execute([$lineAccountId]);
        $tiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($tiers)) {
            return array_map(function($tier) {
                return [
                    'name' => $tier['name'],
                    'min_points' => intval($tier['min_points']),
                    'multiplier' => floatval($tier['multiplier']),
                    'benefits' => $tier['benefits']
                ];
            }, $tiers);
        }
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Return defaults
    return [
        ['name' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0, 'benefits' => null],
        ['name' => 'Gold', 'min_points' => 2000, 'multiplier' => 1.5, 'benefits' => null],
        ['name' => 'Platinum', 'min_points' => 5000, 'multiplier' => 2.0, 'benefits' => null]
    ];
}

/**
 * Get category bonuses for display
 * Requirement 25.10: Show category-specific earning rates
 */
function getCategoryBonuses($db, $lineAccountId) {
    try {
        $stmt = $db->prepare("
            SELECT 
                cb.category_id,
                cb.multiplier,
                ic.name as category_name
            FROM category_points_bonus cb
            LEFT JOIN item_categories ic ON cb.category_id = ic.id
            WHERE (cb.line_account_id = ? OR cb.line_account_id IS NULL)
                AND cb.multiplier > 1
            ORDER BY cb.multiplier DESC
            LIMIT 10
        ");
        $stmt->execute([$lineAccountId]);
        $bonuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(function($bonus) {
            return [
                'category_id' => intval($bonus['category_id']),
                'category_name' => $bonus['category_name'] ?? 'หมวดหมู่',
                'multiplier' => floatval($bonus['multiplier'])
            ];
        }, $bonuses);
        
    } catch (Exception $e) {
        // Table might not exist
        return [];
    }
}
