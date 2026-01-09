<?php
/**
 * POSPaymentService - จัดการการชำระเงิน POS
 * 
 * Handles payment processing including:
 * - Single and split payments
 * - Cash, transfer, card, points
 * - Change calculation
 * - Refund processing
 * 
 * Requirements: 4.1-4.7
 */

class POSPaymentService {
    private $db;
    private $lineAccountId;
    private $loyaltyPoints;
    
    // Points to Baht conversion rate
    const POINTS_TO_BAHT = 0.1; // 10 points = 1 baht
    
    /**
     * Constructor
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    /**
     * Set LoyaltyPoints service
     */
    public function setLoyaltyPoints(LoyaltyPoints $service): void {
        $this->loyaltyPoints = $service;
    }
    
    /**
     * Process a single payment
     * Requirements: 4.1, 4.2, 4.3, 4.4
     * 
     * @param int $transactionId Transaction ID
     * @param array $paymentData Payment data
     * @return array Payment result
     */
    public function processPayment(int $transactionId, array $paymentData): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขาย', 404);
        }
        
        if ($transaction['status'] !== 'draft') {
            throw new Exception('รายการนี้ไม่สามารถชำระเงินได้', 400);
        }
        
        $method = $paymentData['method'];
        $amount = (float)$paymentData['amount'];
        
        // Validate amount
        if ($amount <= 0) {
            throw new Exception('จำนวนเงินต้องมากกว่า 0', 400);
        }
        
        $result = [
            'transaction_id' => $transactionId,
            'method' => $method,
            'amount' => $amount,
            'success' => true
        ];
        
        switch ($method) {
            case 'cash':
                $result = $this->processCashPayment($transactionId, $paymentData);
                break;
                
            case 'transfer':
                $result = $this->processTransferPayment($transactionId, $paymentData);
                break;
                
            case 'card':
                $result = $this->processCardPayment($transactionId, $paymentData);
                break;
                
            case 'points':
                $result = $this->processPointsPayment($transactionId, $paymentData);
                break;
                
            case 'credit':
                $result = $this->processCreditPayment($transactionId, $paymentData);
                break;
                
            default:
                throw new Exception('วิธีชำระเงินไม่ถูกต้อง', 400);
        }
        
        return $result;
    }
    
    /**
     * Process split payment (multiple methods)
     * Requirements: 4.5
     * 
     * @param int $transactionId Transaction ID
     * @param array $payments Array of payment data
     * @return array Payment results
     */
    public function processSplitPayment(int $transactionId, array $payments): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขาย', 404);
        }
        
        // Validate total payments equal transaction total
        $totalPayment = array_sum(array_column($payments, 'amount'));
        if (abs($totalPayment - $transaction['total_amount']) > 0.01) {
            throw new Exception('ยอดชำระไม่ตรงกับยอดรวม', 400);
        }
        
        $results = [];
        
        $this->db->beginTransaction();
        
        try {
            foreach ($payments as $payment) {
                $result = $this->processPayment($transactionId, $payment);
                $results[] = $result;
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'transaction_id' => $transactionId,
                'total_paid' => $totalPayment,
                'payments' => $results
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Process points redemption as payment
     * Requirements: 4.6
     * 
     * @param int $transactionId Transaction ID
     * @param int $points Points to redeem
     * @return array Redemption result
     */
    public function processPointsRedemption(int $transactionId, int $points): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขาย', 404);
        }
        
        if (!$transaction['customer_id']) {
            throw new Exception('ต้องเลือกสมาชิกก่อนใช้แต้ม', 400);
        }
        
        if (!$this->loyaltyPoints) {
            throw new Exception('ระบบแต้มไม่พร้อมใช้งาน', 500);
        }
        
        // Check available points
        $userPoints = $this->loyaltyPoints->getUserPoints($transaction['customer_id']);
        if ($userPoints['available_points'] < $points) {
            throw new Exception('แต้มไม่เพียงพอ', 400);
        }
        
        // Calculate value
        $pointsValue = $points * self::POINTS_TO_BAHT;
        
        // Cap at transaction total
        if ($pointsValue > $transaction['total_amount']) {
            $pointsValue = $transaction['total_amount'];
            $points = (int)ceil($pointsValue / self::POINTS_TO_BAHT);
        }
        
        return [
            'points' => $points,
            'value' => $pointsValue,
            'remaining_points' => $userPoints['available_points'] - $points,
            'remaining_amount' => $transaction['total_amount'] - $pointsValue
        ];
    }
    
    /**
     * Calculate change for cash payment
     * Requirements: 4.1, 4.2
     * 
     * @param float $totalAmount Total amount due
     * @param float $cashReceived Cash received
     * @return float Change amount
     */
    public function calculateChange(float $totalAmount, float $cashReceived): float {
        if ($cashReceived < $totalAmount) {
            return 0; // No change, still owes money
        }
        return round($cashReceived - $totalAmount, 2);
    }
    
    /**
     * Process refund for return
     * Requirements: 12.6
     * 
     * @param int $returnId Return ID
     * @param string $method Refund method (cash, original, credit)
     * @return array Refund result
     */
    public function processRefund(int $returnId, string $method): array {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new Exception('ไม่พบรายการคืนสินค้า', 404);
        }
        
        if ($return['status'] !== 'pending') {
            throw new Exception('รายการนี้ดำเนินการแล้ว', 400);
        }
        
        $refundAmount = $return['refund_amount'];
        
        $this->db->beginTransaction();
        
        try {
            // Update return status
            $stmt = $this->db->prepare("
                UPDATE pos_returns 
                SET status = 'completed', 
                    refund_method = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$method, $returnId]);
            
            // If credit, create AR record
            if ($method === 'credit') {
                $this->createCreditNote($return);
            }
            
            // Update daily summary
            $this->updateDailySummaryForRefund($refundAmount);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'return_id' => $returnId,
                'refund_amount' => $refundAmount,
                'refund_method' => $method
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    // =========================================
    // Private Payment Methods
    // =========================================
    
    /**
     * Process cash payment
     * Requirements: 4.1, 4.2
     */
    private function processCashPayment(int $transactionId, array $data): array {
        $amount = (float)$data['amount'];
        $cashReceived = (float)($data['cash_received'] ?? $amount);
        
        $change = $this->calculateChange($amount, $cashReceived);
        
        // Save payment record
        $paymentId = $this->savePayment($transactionId, [
            'method' => 'cash',
            'amount' => $amount,
            'cash_received' => $cashReceived,
            'change_amount' => $change
        ]);
        
        return [
            'payment_id' => $paymentId,
            'method' => 'cash',
            'amount' => $amount,
            'cash_received' => $cashReceived,
            'change' => $change,
            'success' => true
        ];
    }
    
    /**
     * Process transfer/QR payment
     * Requirements: 4.3
     */
    private function processTransferPayment(int $transactionId, array $data): array {
        $amount = (float)$data['amount'];
        $reference = $data['reference_number'] ?? null;
        
        $paymentId = $this->savePayment($transactionId, [
            'method' => 'transfer',
            'amount' => $amount,
            'reference_number' => $reference
        ]);
        
        return [
            'payment_id' => $paymentId,
            'method' => 'transfer',
            'amount' => $amount,
            'reference_number' => $reference,
            'success' => true
        ];
    }
    
    /**
     * Process card payment
     * Requirements: 4.4
     */
    private function processCardPayment(int $transactionId, array $data): array {
        $amount = (float)$data['amount'];
        $reference = $data['reference_number'] ?? null;
        
        $paymentId = $this->savePayment($transactionId, [
            'method' => 'card',
            'amount' => $amount,
            'reference_number' => $reference
        ]);
        
        return [
            'payment_id' => $paymentId,
            'method' => 'card',
            'amount' => $amount,
            'reference_number' => $reference,
            'success' => true
        ];
    }
    
    /**
     * Process points payment
     * Requirements: 4.6
     */
    private function processPointsPayment(int $transactionId, array $data): array {
        $transaction = $this->getTransaction($transactionId);
        
        if (!$transaction['customer_id']) {
            throw new Exception('ต้องเลือกสมาชิกก่อนใช้แต้ม', 400);
        }
        
        $points = (int)$data['points'];
        $amount = $points * self::POINTS_TO_BAHT;
        
        // Deduct points
        if ($this->loyaltyPoints) {
            $success = $this->loyaltyPoints->deductPoints(
                $transaction['customer_id'],
                $points,
                'pos_payment',
                $transactionId,
                "ใช้แต้มชำระ #{$transaction['transaction_number']}"
            );
            
            if (!$success) {
                throw new Exception('ไม่สามารถหักแต้มได้', 400);
            }
        }
        
        $paymentId = $this->savePayment($transactionId, [
            'method' => 'points',
            'amount' => $amount,
            'points_used' => $points
        ]);
        
        // Update transaction points_redeemed
        $stmt = $this->db->prepare("
            UPDATE pos_transactions 
            SET points_redeemed = points_redeemed + ?, points_value = points_value + ?
            WHERE id = ?
        ");
        $stmt->execute([$points, $amount, $transactionId]);
        
        return [
            'payment_id' => $paymentId,
            'method' => 'points',
            'amount' => $amount,
            'points_used' => $points,
            'success' => true
        ];
    }
    
    /**
     * Process credit payment (AR)
     */
    private function processCreditPayment(int $transactionId, array $data): array {
        $amount = (float)$data['amount'];
        
        $paymentId = $this->savePayment($transactionId, [
            'method' => 'credit',
            'amount' => $amount
        ]);
        
        return [
            'payment_id' => $paymentId,
            'method' => 'credit',
            'amount' => $amount,
            'success' => true
        ];
    }
    
    // =========================================
    // Helper Methods
    // =========================================
    
    /**
     * Save payment record
     */
    private function savePayment(int $transactionId, array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO pos_payments 
            (transaction_id, payment_method, amount, cash_received, change_amount, reference_number, points_used)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $transactionId,
            $data['method'],
            $data['amount'],
            $data['cash_received'] ?? null,
            $data['change_amount'] ?? null,
            $data['reference_number'] ?? null,
            $data['points_used'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get transaction
     */
    private function getTransaction(int $transactionId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_transactions WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$transactionId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get return
     */
    private function getReturn(int $returnId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_returns WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$returnId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get payments for transaction
     */
    public function getPaymentsForTransaction(int $transactionId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_payments WHERE transaction_id = ? ORDER BY id ASC
        ");
        $stmt->execute([$transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Create credit note for refund
     */
    private function createCreditNote(array $return): void {
        // Get original transaction customer
        $stmt = $this->db->prepare("
            SELECT customer_id FROM pos_transactions WHERE id = ?
        ");
        $stmt->execute([$return['original_transaction_id']]);
        $customerId = $stmt->fetchColumn();
        
        if ($customerId) {
            // Create AR credit record if AccountReceivableService exists
            // This would integrate with existing accounting system
        }
    }
    
    /**
     * Update daily summary for refund
     */
    private function updateDailySummaryForRefund(float $amount): void {
        $today = date('Y-m-d');
        
        $stmt = $this->db->prepare("
            UPDATE pos_daily_summary 
            SET total_returns = total_returns + ?,
                return_count = return_count + 1,
                net_sales = net_sales - ?
            WHERE summary_date = ? AND line_account_id = ?
        ");
        $stmt->execute([$amount, $amount, $today, $this->lineAccountId]);
    }
    
    /**
     * Generate QR code data for PromptPay
     * 
     * @param float $amount Amount
     * @param string $promptPayId PromptPay ID (phone or tax ID)
     * @return string QR code data
     */
    public function generatePromptPayQR(float $amount, string $promptPayId): string {
        // PromptPay QR format (simplified)
        // In production, use proper EMVCo QR generation
        $data = [
            'promptpay_id' => $promptPayId,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'THB'
        ];
        
        return json_encode($data);
    }
}
