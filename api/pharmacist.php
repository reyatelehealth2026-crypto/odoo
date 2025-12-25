<?php
/**
 * Pharmacist API
 * API สำหรับ Pharmacist Dashboard
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_detail':
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                exit;
            }
            
            try {
                $stmt = $db->prepare("
                    SELECT pn.*, u.display_name, u.picture_url, u.phone, u.drug_allergies,
                           u.medical_conditions, ts.triage_data, ts.current_state
                    FROM pharmacist_notifications pn
                    LEFT JOIN users u ON pn.user_id = u.id
                    LEFT JOIN triage_sessions ts ON pn.triage_session_id = ts.id
                    WHERE pn.id = ?
                ");
                $stmt->execute([$id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    $data['triage_data'] = json_decode($data['triage_data'] ?? '{}', true);
                    $data['notification_data'] = json_decode($data['notification_data'] ?? '{}', true);
                    echo json_encode(['success' => true, 'data' => $data]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Not found']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_stats':
            $lineAccountId = $_GET['line_account_id'] ?? null;
            
            try {
                $stats = [];
                
                // Pending
                $stmt = $db->prepare("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending' AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$lineAccountId]);
                $stats['pending'] = $stmt->fetchColumn();
                
                // Urgent
                $stmt = $db->prepare("SELECT COUNT(*) FROM pharmacist_notifications WHERE status = 'pending' AND priority = 'urgent' AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$lineAccountId]);
                $stats['urgent'] = $stmt->fetchColumn();
                
                echo json_encode(['success' => true, 'stats' => $stats]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    switch ($action) {
        case 'update_status':
            $id = (int)($input['id'] ?? 0);
            $status = $input['status'] ?? '';
            
            if (!$id || !in_array($status, ['read', 'handled', 'dismissed'])) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                $stmt = $db->prepare("UPDATE pharmacist_notifications SET status = ?, handled_at = NOW() WHERE id = ?");
                $stmt->execute([$status, $id]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'send_message':
            $userId = (int)($input['user_id'] ?? 0);
            $message = $input['message'] ?? '';
            
            if (!$userId || !$message) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                // Load PharmacistNotifier
                require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
                $notifier = new \Modules\AIChat\Services\PharmacistNotifier();
                
                $result = $notifier->sendToCustomer($userId, $message);
                
                // Log message
                $stmt = $db->prepare("INSERT INTO messages (user_id, message_type, content, direction, sent_by) VALUES (?, 'text', ?, 'outgoing', 'pharmacist')");
                $stmt->execute([$userId, $message]);
                
                echo json_encode(['success' => $result, 'message' => $result ? 'Message sent' : 'Failed to send']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'approve_drugs':
            $notificationId = (int)($input['notification_id'] ?? 0);
            $userId = (int)($input['user_id'] ?? 0);
            $drugs = $input['drugs'] ?? [];
            $pharmacistNote = $input['note'] ?? '';
            
            if (!$notificationId || !$userId || empty($drugs)) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                exit;
            }
            
            try {
                // Get triage data
                $stmt = $db->prepare("
                    SELECT ts.triage_data 
                    FROM pharmacist_notifications pn
                    LEFT JOIN triage_sessions ts ON pn.triage_session_id = ts.id
                    WHERE pn.id = ?
                ");
                $stmt->execute([$notificationId]);
                $notifData = $stmt->fetch(PDO::FETCH_ASSOC);
                $triageData = json_decode($notifData['triage_data'] ?? '{}', true);
                
                // Load PharmacistNotifier
                require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
                $notifier = new \Modules\AIChat\Services\PharmacistNotifier();
                
                // Send approval to customer
                $result = $notifier->sendApprovalToCustomer($userId, $triageData, $drugs);
                
                // Update notification status
                $stmt = $db->prepare("UPDATE pharmacist_notifications SET status = 'handled', handled_at = NOW() WHERE id = ?");
                $stmt->execute([$notificationId]);
                
                // Update triage session
                $stmt = $db->prepare("UPDATE triage_sessions SET status = 'completed', completed_at = NOW() WHERE id = (SELECT triage_session_id FROM pharmacist_notifications WHERE id = ?)");
                $stmt->execute([$notificationId]);
                
                // Save medical history
                $stmt = $db->prepare("
                    INSERT INTO medical_history (user_id, triage_session_id, symptoms, medications_prescribed, pharmacist_notes)
                    SELECT ?, pn.triage_session_id, ?, ?, ?
                    FROM pharmacist_notifications pn WHERE pn.id = ?
                ");
                $stmt->execute([
                    $userId,
                    json_encode($triageData['symptoms'] ?? [], JSON_UNESCAPED_UNICODE),
                    json_encode($drugs, JSON_UNESCAPED_UNICODE),
                    $pharmacistNote,
                    $notificationId
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Drugs approved and sent to customer']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'reject':
            $notificationId = (int)($input['notification_id'] ?? 0);
            $userId = (int)($input['user_id'] ?? 0);
            $reason = $input['reason'] ?? 'ไม่สามารถแนะนำยาได้ กรุณาพบแพทย์';
            
            if (!$notificationId) {
                echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
                exit;
            }
            
            try {
                // Send rejection message
                require_once __DIR__ . '/../modules/AIChat/Services/PharmacistNotifier.php';
                $notifier = new \Modules\AIChat\Services\PharmacistNotifier();
                
                $message = "⚠️ เภสัชกรแจ้ง:\n{$reason}\n\nกรุณาพบแพทย์หรือติดต่อเภสัชกรโดยตรง";
                $notifier->sendToCustomer($userId, $message);
                
                // Update status
                $stmt = $db->prepare("UPDATE pharmacist_notifications SET status = 'dismissed', handled_at = NOW() WHERE id = ?");
                $stmt->execute([$notificationId]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_drugs':
            // Get available drugs for recommendation
            $lineAccountId = $input['line_account_id'] ?? null;
            
            try {
                $stmt = $db->prepare("
                    SELECT id, name, price, generic_name, description, usage_instructions
                    FROM business_items 
                    WHERE is_active = 1 
                    AND (line_account_id = ? OR line_account_id IS NULL)
                    ORDER BY category_id, name
                    LIMIT 100
                ");
                $stmt->execute([$lineAccountId]);
                $drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'drugs' => $drugs]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request method']);
