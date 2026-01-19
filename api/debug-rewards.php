<?php
/**
 * Debug Rewards API - For testing without authentication
 * This endpoint allows testing rewards functionality without LIFF login
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $lineAccountId = (int)($_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1);
    
    switch ($action) {
        case 'get_config':
            // Get LIFF configuration
            $stmt = $db->prepare("SELECT id, liff_id, name FROM line_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$lineAccountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$account) {
                // Get default account
                $stmt = $db->prepare("SELECT id, liff_id, name FROM line_accounts ORDER BY id LIMIT 1");
                $stmt->execute();
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'config' => [
                    'account_id' => $account['id'] ?? 1,
                    'liff_id' => $account['liff_id'] ?? null,
                    'shop_name' => $account['name'] ?? 'ร้านค้า'
                ]
            ]);
            break;
            
        case 'rewards':
            // Get all active rewards (no authentication required for debug)
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $rewards = $loyalty->getActiveRewards();
            
            echo json_encode([
                'success' => true,
                'rewards' => $rewards
            ]);
            break;
            
        case 'test_user':
            // Get or create a test user for debugging
            $testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);
            
            // Check if test user exists
            $stmt = $db->prepare("SELECT id, display_name FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1");
            $stmt->execute([$testUserId, $lineAccountId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                // Create test user
                $stmt = $db->prepare("INSERT INTO users (line_user_id, line_account_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$testUserId, $lineAccountId, 'Test User (Debug)']);
                $userId = $db->lastInsertId();
                
                $user = [
                    'id' => $userId,
                    'display_name' => 'Test User (Debug)'
                ];
            }
            
            // Get member data
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $member = $loyalty->getMemberByUserId($user['id']);
            
            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'line_user_id' => $testUserId,
                    'display_name' => $user['display_name']
                ],
                'member' => $member
            ]);
            break;
            
        case 'redeem':
            // Test redemption with test user
            $rewardId = (int)($_POST['reward_id'] ?? 0);
            $testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);
            
            if (!$rewardId) {
                echo json_encode(['success' => false, 'error' => 'Missing reward_id']);
                exit;
            }
            
            // Get or create test user
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1");
            $stmt->execute([$testUserId, $lineAccountId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $stmt = $db->prepare("INSERT INTO users (line_user_id, line_account_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$testUserId, $lineAccountId, 'Test User (Debug)']);
                $userId = $db->lastInsertId();
            } else {
                $userId = $user['id'];
            }
            
            // Redeem reward
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $result = $loyalty->redeemReward($userId, $rewardId);
            
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'แลกรางวัลสำเร็จ!',
                    'redemption_code' => $result['redemption_code'],
                    'reward_name' => $result['reward']['name'] ?? ''
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $result['message'] ?? 'ไม่สามารถแลกรางวัลได้'
                ]);
            }
            break;
            
        case 'add_points':
            // Add test points to test user
            $points = (int)($_POST['points'] ?? 1000);
            $testUserId = 'U' . str_pad($lineAccountId, 32, '0', STR_PAD_LEFT);
            
            // Get or create test user
            $stmt = $db->prepare("SELECT id FROM users WHERE line_user_id = ? AND line_account_id = ? LIMIT 1");
            $stmt->execute([$testUserId, $lineAccountId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $stmt = $db->prepare("INSERT INTO users (line_user_id, line_account_id, display_name, created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$testUserId, $lineAccountId, 'Test User (Debug)']);
                $userId = $db->lastInsertId();
            } else {
                $userId = $user['id'];
            }
            
            // Add points
            $loyalty = new LoyaltyPoints($db, $lineAccountId);
            $loyalty->addPoints($userId, $points, 'debug_test', 'Debug test points');
            
            $member = $loyalty->getMemberByUserId($userId);
            
            echo json_encode([
                'success' => true,
                'message' => "เพิ่ม {$points} แต้มสำเร็จ",
                'member' => $member
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Debug Rewards API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
