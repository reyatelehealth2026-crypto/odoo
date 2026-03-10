<?php
/**
 * Odoo Webhook Handler
 * รับ webhook events จาก Odoo ERP และบันทึกลง Database
 * 
 * Endpoint: POST /api/odoo-webhook.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-Odoo-Signature');

require_once __DIR__ . '/../config/database.php';

/**
 * Webhook Handler Class
 */
class OdooWebhookHandler {
    private $db;
    private $webhookSecret;
    
    // Order states mapping
    private $orderStates = [
        'draft' => ['display' => 'แบบร่าง', 'type' => 'draft'],
        'sent' => ['display' => 'ส่งใบเสนอราคา', 'type' => 'draft'],
        'validated' => ['display' => 'ตรวจสอบแล้ว', 'type' => 'progress'],
        'picker_assign' => ['display' => 'เตรียมจัดสินค้า', 'type' => 'progress'],
        'picking' => ['display' => 'กำลังจัดสินค้า', 'type' => 'progress'],
        'picked' => ['display' => 'จัดเสร็จแล้ว', 'type' => 'progress'],
        'packing' => ['display' => 'กำลังแพ็ค', 'type' => 'progress'],
        'packed' => ['display' => 'แพ็คเสร็จ', 'type' => 'progress'],
        'reserved' => ['display' => 'จองสินค้าแล้ว', 'type' => 'progress'],
        'awaiting_payment' => ['display' => 'รอชำระเงิน', 'type' => 'progress'],
        'paid' => ['display' => 'ชำระแล้ว', 'type' => 'progress'],
        'to_delivery' => ['display' => 'เตรียมส่ง', 'type' => 'progress'],
        'in_delivery' => ['display' => 'กำลังจัดส่ง', 'type' => 'progress'],
        'delivered' => ['display' => 'จัดส่งแล้ว', 'type' => 'done'],
        'cancel' => ['display' => 'ยกเลิก', 'type' => 'cancel'],
        'cancelled' => ['display' => 'ยกเลิก', 'type' => 'cancel']
    ];
    
    public function __construct($db) {
        $this->db = $db;
        $this->webhookSecret = $_ENV['ODOO_WEBHOOK_SECRET'] ?? 'your-secret-key';
    }
    
    /**
     * Verify webhook signature
     */
    public function verifySignature($payload, $signature, $timestamp) {
        // Check timestamp (prevent replay attacks - 5 min window)
        $now = time();
        if (abs($now - intval($timestamp)) > 300) {
            return false;
        }
        
        // Verify HMAC
        $expectedSig = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        return hash_equals($expectedSig, str_replace('sha256=', '', $signature));
    }
    
    /**
     * Process webhook
     */
    public function process($eventType, $data) {
        // Log the webhook first
        $logId = $this->logWebhook($eventType, $data);
        
        try {
            switch ($eventType) {
                // Order Events
                case 'order.validated':
                case 'order.picker_assigned':
                case 'order.picking':
                case 'order.picked':
                case 'order.packing':
                case 'order.packed':
                case 'order.reserved':
                case 'order.awaiting_payment':
                case 'order.paid':
                case 'order.to_delivery':
                case 'order.in_delivery':
                case 'order.delivered':
                    $this->handleOrderEvent($eventType, $data);
                    break;
                    
                // BDO Events
                case 'bdo.confirmed':
                case 'bdo.done':
                case 'bdo.cancelled':
                    $this->handleBdoEvent($eventType, $data);
                    break;
                    
                // Delivery Events
                case 'delivery.departed':
                case 'delivery.completed':
                    $this->handleDeliveryEvent($eventType, $data);
                    break;
                    
                // Invoice Events
                case 'invoice.created':
                case 'invoice.paid':
                case 'invoice.overdue':
                    $this->handleInvoiceEvent($eventType, $data);
                    break;
                    
                // Payment Events
                case 'payment.confirmed':
                    $this->handlePaymentEvent($eventType, $data);
                    break;
                    
                default:
                    throw new Exception("Unknown event type: {$eventType}");
            }
            
            // Mark as processed
            $this->markProcessed($logId);
            
            return [
                'success' => true,
                'message' => "Event {$eventType} processed successfully",
                'log_id' => $logId
            ];
            
        } catch (Exception $e) {
            $this->markError($logId, $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Log webhook
     */
    private function logWebhook($eventType, $data) {
        $stmt = $this->db->prepare("INSERT INTO odoo_webhook_logs 
            (event_type, event_id, odoo_order_id, odoo_invoice_id, odoo_payment_id, odoo_bdo_id, 
             line_user_id, partner_id, payload) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $eventId = $data['event_id'] ?? null;
        $orderId = $data['data']['order_id'] ?? $data['data']['sale_order']['id'] ?? null;
        $invoiceId = $data['data']['invoice']['invoice_id'] ?? $data['data']['invoice_id'] ?? null;
        $paymentId = $data['data']['payment_id'] ?? null;
        $bdoId = $data['data']['bdo_id'] ?? null;
        $lineUserId = $data['data']['customer']['line_user_id'] ?? null;
        $partnerId = $data['data']['customer']['id'] ?? null;
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $stmt->execute([$eventType, $eventId, $orderId, $invoiceId, $paymentId, $bdoId, 
                        $lineUserId, $partnerId, $payload]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Mark webhook as processed
     */
    private function markProcessed($logId) {
        $stmt = $this->db->prepare("UPDATE odoo_webhook_logs 
            SET processed = TRUE, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$logId]);
    }
    
    /**
     * Mark webhook with error
     */
    private function markError($logId, $errorMessage) {
        $stmt = $this->db->prepare("UPDATE odoo_webhook_logs 
            SET error_message = ? WHERE id = ?");
        $stmt->execute([$errorMessage, $logId]);
    }
    
    /**
     * Handle Order events
     */
    private function handleOrderEvent($eventType, $data) {
        $orderData = $data['data'] ?? $data;
        
        $orderId = $orderData['order_id'];
        $orderName = $orderData['order_name'];
        $newState = $orderData['new_state'] ?? $this->getStateFromEvent($eventType);
        $oldState = $orderData['old_state'] ?? null;
        
        // Upsert order
        $this->upsertOrder($orderData);
        
        // Add state history
        $this->addOrderStateHistory($orderId, $newState, $orderData);
        
        // Update LINE notification if needed
        $this->notifyLineUser($orderData);
    }
    
    /**
     * Handle BDO events
     */
    private function handleBdoEvent($eventType, $data) {
        $bdoData = $data['data'] ?? $data;
        
        // Create or update invoice if BDO confirmed
        if ($eventType === 'bdo.confirmed' && isset($bdoData['invoice'])) {
            $this->upsertInvoice($bdoData['invoice'], $bdoData);
        }
        
        // Update order state
        if (isset($bdoData['sale_order']['id'])) {
            $this->updateOrderState($bdoData['sale_order']['id'], 'awaiting_payment', $bdoData);
        }
    }
    
    /**
     * Handle Delivery events
     */
    private function handleDeliveryEvent($eventType, $data) {
        $deliveryData = $data['data'] ?? $data;
        
        foreach ($deliveryData['orders'] ?? [] as $order) {
            $state = ($eventType === 'delivery.departed') ? 'in_delivery' : 'delivered';
            $this->updateOrderState($order['order_id'], $state, $deliveryData);
        }
    }
    
    /**
     * Handle Invoice events
     */
    private function handleInvoiceEvent($eventType, $data) {
        $invoiceData = $data['data'] ?? $data;
        
        if ($eventType === 'invoice.paid') {
            $this->updateInvoiceState($invoiceData['invoice_id'], 'paid', $invoiceData);
        } elseif ($eventType === 'invoice.created') {
            $this->upsertInvoice($invoiceData, null);
        }
    }
    
    /**
     * Handle Payment events
     */
    private function handlePaymentEvent($eventType, $data) {
        $paymentData = $data['data'] ?? $data;
        
        $this->upsertPayment($paymentData);
        
        // Update invoice state
        if (isset($paymentData['invoice_id'])) {
            $this->updateInvoiceState($paymentData['invoice_id'], 'paid', $paymentData);
        }
        
        // Update order state
        if (!empty($paymentData['related_orders'])) {
            foreach ($paymentData['related_orders'] as $orderName) {
                $orderId = $this->getOrderIdByName($orderName);
                if ($orderId) {
                    $this->updateOrderState($orderId, 'paid', $paymentData);
                }
            }
        }
    }
    
    /**
     * Upsert Order
     */
    private function upsertOrder($orderData) {
        $stmt = $this->db->prepare("INSERT INTO odoo_orders 
            (odoo_order_id, order_name, order_ref, partner_id, partner_code, partner_name, line_user_id,
             state, state_display, amount_total, amount_untaxed, amount_tax,
             payment_method, payment_state, marketplace, marketplace_shop_name,
             order_date, confirmed_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            state_display = VALUES(state_display),
            amount_total = VALUES(amount_total),
            payment_state = VALUES(payment_state),
            confirmed_at = COALESCE(confirmed_at, VALUES(confirmed_at)),
            updated_at = NOW()");
        
        $state = $orderData['new_state'] ?? $orderData['state'] ?? 'draft';
        $stateInfo = $this->orderStates[$state] ?? ['display' => $state, 'type' => 'draft'];
        
        $confirmedAt = null;
        if ($state === 'validated' || in_array($state, ['picker_assign', 'picking', 'picked', 'packing', 'packed'])) {
            $confirmedAt = date('Y-m-d H:i:s');
        }
        
        $stmt->execute([
            $orderData['order_id'],
            $orderData['order_name'],
            $orderData['order_ref'] ?? null,
            $orderData['customer']['id'] ?? null,
            $orderData['customer']['partner_code'] ?? null,
            $orderData['customer']['name'] ?? null,
            $orderData['customer']['line_user_id'] ?? null,
            $state,
            $stateInfo['display'],
            $orderData['amount_total'] ?? 0,
            $orderData['amount_untaxed'] ?? 0,
            $orderData['amount_tax'] ?? 0,
            $orderData['payment_method'] ?? null,
            $orderData['payment_state'] ?? 'not_paid',
            $orderData['marketplace'] ?? null,
            $orderData['marketplace_shop_name'] ?? null,
            $orderData['order_date'] ?? date('Y-m-d'),
            $confirmedAt
        ]);
    }
    
    /**
     * Upsert Invoice
     */
    private function upsertInvoice($invoiceData, $parentData) {
        $stmt = $this->db->prepare("INSERT INTO odoo_invoices 
            (odoo_invoice_id, invoice_number, odoo_order_id, order_name,
             partner_id, partner_name, line_user_id,
             state, state_display, amount_total, amount_untaxed, amount_tax, amount_residual,
             invoice_date, due_date, pdf_url,
             payment_method, promptpay_qr_data, bank_account, bank_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            state_display = VALUES(state_display),
            amount_residual = VALUES(amount_residual),
            pdf_url = VALUES(pdf_url),
            updated_at = NOW()");
        
        $payment = $parentData['payment'] ?? [];
        $promptpay = $payment['promptpay'] ?? [];
        $bankTransfer = $payment['bank_transfer'] ?? [];
        
        $stmt->execute([
            $invoiceData['invoice_id'],
            $invoiceData['invoice_number'],
            $parentData['sale_order']['id'] ?? null,
            $parentData['sale_order']['name'] ?? null,
            $parentData['customer']['id'] ?? null,
            $parentData['customer']['name'] ?? null,
            $parentData['customer']['line_user_id'] ?? null,
            $invoiceData['state'] ?? 'open',
            $invoiceData['state'] === 'open' ? 'ค้างชำระ' : ($invoiceData['state'] === 'paid' ? 'ชำระแล้ว' : $invoiceData['state']),
            $invoiceData['amount_total'] ?? 0,
            $invoiceData['amount_untaxed'] ?? 0,
            $invoiceData['amount_tax'] ?? 0,
            $invoiceData['amount_residual'] ?? 0,
            $invoiceData['invoice_date'] ?? date('Y-m-d'),
            $invoiceData['due_date'] ?? null,
            $invoiceData['pdf_url'] ?? null,
            $payment['method'] ?? null,
            $promptpay['qr_data'] ?? null,
            $bankTransfer['account_number'] ?? null,
            $bankTransfer['bank_name'] ?? null
        ]);
    }
    
    /**
     * Upsert Payment
     */
    private function upsertPayment($paymentData) {
        $stmt = $this->db->prepare("INSERT INTO odoo_payments 
            (odoo_payment_id, payment_name, odoo_invoice_id, invoice_number,
             odoo_order_id, order_name, partner_id, partner_name, line_user_id,
             state, state_display, amount, currency, method, method_display,
             reference, slip_image_url, bank_name, bank_account, payment_date, posted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            state = VALUES(state),
            state_display = VALUES(state_display),
            posted_at = COALESCE(posted_at, VALUES(posted_at)),
            updated_at = NOW()");
        
        $payment = $paymentData['payment'] ?? $paymentData;
        $methodDisplay = [
            'cash' => 'เงินสด',
            'bank_transfer' => 'โอนเงิน',
            'promptpay' => 'พร้อมเพย์',
            'cheque' => 'เช็ค',
            'credit_card' => 'บัตรเครดิต'
        ][$payment['method'] ?? 'cash'] ?? $payment['method'];
        
        $postedAt = ($paymentData['new_state'] ?? '') === 'post' ? date('Y-m-d H:i:s') : null;
        
        $stmt->execute([
            $paymentData['payment_id'],
            $paymentData['payment_name'],
            $paymentData['invoice_id'] ?? null,
            null, // invoice_number - need to lookup
            null, // order_id
            null, // order_name
            $paymentData['customer']['id'] ?? null,
            $paymentData['customer']['name'] ?? null,
            $paymentData['customer']['line_user_id'] ?? null,
            $paymentData['new_state'] ?? 'draft',
            $paymentData['new_state'] === 'post' ? 'ผ่านรายการ' : 'แบบร่าง',
            $payment['amount'] ?? 0,
            $payment['currency'] ?? 'THB',
            $payment['method'] ?? 'cash',
            $methodDisplay,
            $payment['reference'] ?? null,
            $payment['slip_image_url'] ?? null,
            $payment['bank_name'] ?? null,
            $payment['bank_account'] ?? null,
            $payment['date'] ?? date('Y-m-d'),
            $postedAt
        ]);
    }
    
    /**
     * Update Order State
     */
    private function updateOrderState($orderId, $state, $data) {
        $stateInfo = $this->orderStates[$state] ?? ['display' => $state, 'type' => 'progress'];
        
        $stmt = $this->db->prepare("UPDATE odoo_orders 
            SET state = ?, state_display = ?, updated_at = NOW() 
            WHERE odoo_order_id = ?");
        $stmt->execute([$state, $stateInfo['display'], $orderId]);
        
        // Add state history
        $this->addOrderStateHistory($orderId, $state, $data);
    }
    
    /**
     * Update Invoice State
     */
    private function updateInvoiceState($invoiceId, $state, $data) {
        $paidAt = ($state === 'paid') ? date('Y-m-d H:i:s') : null;
        $amountResidual = ($state === 'paid') ? 0 : ($data['amount_residual'] ?? null);
        
        $stmt = $this->db->prepare("UPDATE odoo_invoices 
            SET state = ?, state_display = ?, amount_residual = ?, paid_at = COALESCE(paid_at, ?), updated_at = NOW() 
            WHERE odoo_invoice_id = ?");
        $stmt->execute([
            $state, 
            $state === 'paid' ? 'ชำระแล้ว' : ($state === 'open' ? 'ค้างชำระ' : $state),
            $amountResidual,
            $paidAt,
            $invoiceId
        ]);
    }
    
    /**
     * Add Order State History
     */
    private function addOrderStateHistory($orderId, $state, $data) {
        $stateInfo = $this->orderStates[$state] ?? ['display' => $state, 'type' => 'progress'];
        
        $stmt = $this->db->prepare("INSERT INTO odoo_order_states 
            (odoo_order_id, state, state_display, state_type, assignee_id, assignee_name, assignee_type, notes, changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $assignee = $data['picker'] ?? $data['driver'] ?? null;
        
        $stmt->execute([
            $orderId,
            $state,
            $stateInfo['display'],
            $stateInfo['type'],
            $assignee['id'] ?? null,
            $assignee['name'] ?? null,
            isset($data['picker']) ? 'picker' : (isset($data['driver']) ? 'driver' : null),
            $data['note'] ?? null
        ]);
    }
    
    /**
     * Get Order ID by Name
     */
    private function getOrderIdByName($orderName) {
        $stmt = $this->db->prepare("SELECT odoo_order_id FROM odoo_orders WHERE order_name = ? LIMIT 1");
        $stmt->execute([$orderName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['odoo_order_id'] : null;
    }
    
    /**
     * Get state from event type
     */
    private function getStateFromEvent($eventType) {
        $mapping = [
            'order.validated' => 'validated',
            'order.picker_assigned' => 'picker_assign',
            'order.picking' => 'picking',
            'order.picked' => 'picked',
            'order.packing' => 'packing',
            'order.packed' => 'packed',
            'order.reserved' => 'reserved',
            'order.awaiting_payment' => 'awaiting_payment',
            'order.paid' => 'paid',
            'order.to_delivery' => 'to_delivery',
            'order.in_delivery' => 'in_delivery',
            'order.delivered' => 'delivered'
        ];
        return $mapping[$eventType] ?? 'draft';
    }
    
    /**
     * Notify LINE User
     */
    private function notifyLineUser($orderData) {
        // This will be implemented to send notification via LINE API
        // For now, just log that notification should be sent
        $lineUserId = $orderData['customer']['line_user_id'] ?? null;
        if ($lineUserId) {
            // TODO: Send LINE message
            error_log("Should notify LINE user: {$lineUserId} about order {$orderData['order_name']}");
        }
    }
}

// =====================================================
// Main Handler
// =====================================================

try {
    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    
    // Get headers
    $signature = $_SERVER['HTTP_X_ODOO_SIGNATURE'] ?? '';
    $timestamp = $_SERVER['HTTP_X_ODOO_TIMESTAMP'] ?? '';
    $eventType = $_SERVER['HTTP_X_ODOO_EVENT'] ?? '';
    
    // Get payload
    $payload = file_get_contents('php://input');
    $data = json_decode($payload, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }
    
    // If no event type in header, try to get from body
    if (empty($eventType) && isset($data['event'])) {
        $eventType = $data['event'];
    }
    
    if (empty($eventType)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing event type']);
        exit;
    }
    
    // Initialize database
    $db = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", 
                  $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Process webhook
    $handler = new OdooWebhookHandler($db);
    
    // Optional: Verify signature (uncomment when ready)
    // if (!$handler->verifySignature($payload, $signature, $timestamp)) {
    //     http_response_code(401);
    //     echo json_encode(['success' => false, 'error' => 'Invalid signature']);
    //     exit;
    // }
    
    $result = $handler->process($eventType, $data);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
