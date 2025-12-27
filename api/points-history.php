<?php
/**
 * Points History API
 * API สำหรับดูประวัติคะแนนสะสม
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LoyaltyPoints.php';

$db = Database::getInstance()->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? 'history';
$lineUserId = $_GET['line_user_id'] ?? $_POST['line_user_id'] ?? null;

if (!$lineUserId) {
    echo json_encode(['success' => false, 'error' => 'Missing line_user_id']);
    exit;
}

try {
    // Get user info
    $stmt = $db->prepare("SELECT id, line_account_id, total_points, available_points, used_points, display_name FROM users WHERE line_user_id = ?");
    $stmt->execute([$lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }
    
    $loyalty = new LoyaltyPoints($db, $user['line_account_id']);
    
    switch ($action) {
        case 'history':
            $limit = (int)($_GET['limit'] ?? 20);
            $history = $loyalty->getPointsHistory($user['id'], $limit);
            
            // Format history
            $formatted = array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'type' => $item['type'],
                    'points' => $item['points'],
                    'balance_after' => $item['balance_after'],
                    'description' => $item['description'],
                    'reference_type' => $item['reference_type'],
                    'created_at' => $item['created_at'],
                    'formatted_date' => date('d/m/Y H:i', strtotime($item['created_at']))
                ];
            }, $history);
            
            echo json_encode([
                'success' => true,
                'user' => [
                    'name' => $user['display_name'],
                    'total_points' => (int)$user['total_points'],
                    'available_points' => (int)$user['available_points'],
                    'used_points' => (int)$user['used_points']
                ],
                'history' => $formatted
            ]);
            break;
            
        case 'rewards':
            $rewards = $loyalty->getRewards(true);
            $userRedemptions = $loyalty->getUserRedemptions($user['id']);
            
            echo json_encode([
                'success' => true,
                'available_points' => (int)$user['available_points'],
                'rewards' => $rewards,
                'my_redemptions' => $userRedemptions
            ]);
            break;
            
        case 'redeem':
            $rewardId = (int)($_POST['reward_id'] ?? 0);
            if (!$rewardId) {
                echo json_encode(['success' => false, 'error' => 'Missing reward_id']);
                exit;
            }
            
            $result = $loyalty->redeemReward($user['id'], $rewardId);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Points History API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
