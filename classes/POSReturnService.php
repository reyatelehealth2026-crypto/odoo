<?php
/**
 * POSReturnService - จัดการการคืนสินค้า POS
 * 
 * Handles returns and refunds including:
 * - Finding original transactions
 * - Creating return records
 * - Processing refunds
 * - Stock restoration
 * - Points reversal
 * 
 * Requirements: 12.1-12.10
 */

class POSReturnService {
    private $db;
    private $lineAccountId;
    private $inventoryService;
    private $batchService;
    private $loyaltyPoints;
    
    // Return time limit in days (configurable)
    const RETURN_TIME_LIMIT_DAYS = 7;
    
    /**
     * Constructor
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
    }
    
    /**
     * Set InventoryService
     */
    public function setInventoryService(InventoryService $service): void {
        $this->inventoryService = $service;
    }
    
    /**
     * Set BatchService
     */
    public function setBatchService(BatchService $service): void {
        $this->batchService = $service;
    }
    
    /**
     * Set LoyaltyPoints
     */
    public function setLoyaltyPoints(LoyaltyPoints $service): void {
        $this->loyaltyPoints = $service;
    }
    
    /**
     * Find transaction by receipt number
     * Requirements: 12.1, 12.2
     * 
     * @param string $receiptNumber Receipt/transaction number
     * @return array|null Transaction data or null
     */
    public function findTransaction(string $receiptNumber): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   u.display_name as customer_name,
                   u.phone as customer_phone
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            WHERE t.transaction_number = ? 
            AND t.line_account_id = ?
            AND t.status = 'completed'
        ");
        $stmt->execute([$receiptNumber, $this->lineAccountId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($transaction) {
            $transaction['items'] = $this->getReturnableItems($transaction['id']);
            $transaction['can_return'] = $this->canReturn($transaction);
            $transaction['requires_authorization'] = $this->requiresAuthorization($transaction);
        }
        
        return $transaction ?: null;
    }
    
    /**
     * Get returnable items from transaction
     * Requirements: 12.2, 12.3
     * 
     * @param int $transactionId Transaction ID
     * @return array Items available for return
     */
    public function getReturnableItems(int $transactionId): array {
        $stmt = $this->db->prepare("
            SELECT ti.*,
                   bi.name as product_name,
                   bi.sku as product_sku,
                   bi.image_url as product_image,
                   (ti.quantity - ti.returned_quantity) as returnable_quantity
            FROM pos_transaction_items ti
            LEFT JOIN business_items bi ON ti.product_id = bi.id
            WHERE ti.transaction_id = ?
            AND (ti.quantity - ti.returned_quantity) > 0
        ");
        $stmt->execute([$transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Create a return
     * Requirements: 12.3, 12.4
     * 
     * @param int $originalTransactionId Original transaction ID
     * @param array $items Items to return [{item_id, quantity}]
     * @param string $reason Return reason
     * @param int $processedBy Staff ID processing the return
     * @return array Created return data
     */
    public function createReturn(int $originalTransactionId, array $items, string $reason, int $processedBy): array {
        // Validate original transaction
        $transaction = $this->getOriginalTransaction($originalTransactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขายเดิม', 404);
        }
        
        if ($transaction['status'] !== 'completed') {
            throw new Exception('สามารถคืนได้เฉพาะรายการที่ชำระเงินแล้ว', 400);
        }
        
        // Validate items
        $returnItems = $this->validateReturnItems($originalTransactionId, $items);
        if (empty($returnItems)) {
            throw new Exception('ไม่มีสินค้าที่สามารถคืนได้', 400);
        }
        
        // Get current shift
        $stmt = $this->db->prepare("
            SELECT id FROM pos_shifts 
            WHERE cashier_id = ? AND status = 'open' AND line_account_id = ?
            LIMIT 1
        ");
        $stmt->execute([$processedBy, $this->lineAccountId]);
        $shiftId = $stmt->fetchColumn();
        
        if (!$shiftId) {
            throw new Exception('ไม่มีกะที่เปิดอยู่', 400);
        }
        
        // Calculate totals
        $totalAmount = 0;
        foreach ($returnItems as $item) {
            $totalAmount += $item['line_total'];
        }
        
        // Calculate points to deduct
        $pointsToDeduct = 0;
        if ($transaction['customer_id'] && $transaction['points_earned'] > 0) {
            // Proportional points deduction
            $returnRatio = $totalAmount / $transaction['total_amount'];
            $pointsToDeduct = (int)floor($transaction['points_earned'] * $returnRatio);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Generate return number
            $returnNumber = $this->generateReturnNumber();
            
            // Create return record
            $stmt = $this->db->prepare("
                INSERT INTO pos_returns 
                (line_account_id, return_number, original_transaction_id, shift_id, 
                 total_amount, refund_amount, refund_method, points_deducted, 
                 reason, processed_by, status)
                VALUES (?, ?, ?, ?, ?, ?, 'cash', ?, ?, ?, 'pending')
            ");
            
            $stmt->execute([
                $this->lineAccountId,
                $returnNumber,
                $originalTransactionId,
                $shiftId,
                $totalAmount,
                $totalAmount,
                $pointsToDeduct,
                $reason,
                $processedBy
            ]);
            
            $returnId = (int)$this->db->lastInsertId();
            
            // Create return items
            foreach ($returnItems as $item) {
                $stmt = $this->db->prepare("
                    INSERT INTO pos_return_items 
                    (return_id, original_item_id, product_id, batch_id, quantity, unit_price, line_total)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $returnId,
                    $item['original_item_id'],
                    $item['product_id'],
                    $item['batch_id'],
                    $item['quantity'],
                    $item['unit_price'],
                    $item['line_total']
                ]);
            }
            
            $this->db->commit();
            
            return $this->getReturn($returnId);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Process a return (complete it)
     * Requirements: 12.5, 12.6, 12.7, 12.9, 12.10
     * 
     * @param int $returnId Return ID
     * @param int $authorizedBy Manager ID (if required)
     * @return array Processed return
     */
    public function processReturn(int $returnId, int $authorizedBy = null): array {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new Exception('ไม่พบรายการคืนสินค้า', 404);
        }
        
        if ($return['status'] !== 'pending') {
            throw new Exception('รายการนี้ดำเนินการแล้ว', 400);
        }
        
        // Check if authorization required
        $transaction = $this->getOriginalTransaction($return['original_transaction_id']);
        if ($this->requiresAuthorization($transaction) && !$authorizedBy) {
            throw new Exception('ต้องได้รับอนุมัติจากผู้จัดการ', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // 1. Restore stock (Requirements: 12.5)
            $this->restoreStock($returnId);
            
            // 2. Deduct points if applicable (Requirements: 12.7)
            if ($return['points_deducted'] > 0 && $transaction['customer_id'] && $this->loyaltyPoints) {
                $this->loyaltyPoints->deductPoints(
                    $transaction['customer_id'],
                    $return['points_deducted'],
                    'pos_return',
                    $returnId,
                    "หักแต้มจากการคืนสินค้า #{$return['return_number']}"
                );
            }
            
            // 3. Update original transaction items returned_quantity
            $this->updateReturnedQuantities($returnId);
            
            // 4. Update return status
            $stmt = $this->db->prepare("
                UPDATE pos_returns 
                SET status = 'completed',
                    authorized_by = ?,
                    completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$authorizedBy, $returnId]);
            
            // 5. Update shift totals
            $stmt = $this->db->prepare("
                UPDATE pos_shifts 
                SET total_refunds = total_refunds + ?
                WHERE id = ?
            ");
            $stmt->execute([$return['refund_amount'], $return['shift_id']]);
            
            $this->db->commit();
            
            return $this->getReturn($returnId);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get return by ID
     */
    public function getReturn(int $returnId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.*,
                   t.transaction_number as original_receipt,
                   t.customer_id,
                   u.display_name as customer_name,
                   a.display_name as processed_by_name,
                   m.display_name as authorized_by_name
            FROM pos_returns r
            LEFT JOIN pos_transactions t ON r.original_transaction_id = t.id
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users a ON r.processed_by = a.id
            LEFT JOIN admin_users m ON r.authorized_by = m.id
            WHERE r.id = ? AND r.line_account_id = ?
        ");
        $stmt->execute([$returnId, $this->lineAccountId]);
        $return = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($return) {
            $return['items'] = $this->getReturnItems($returnId);
        }
        
        return $return ?: null;
    }
    
    /**
     * Get return items
     */
    public function getReturnItems(int $returnId): array {
        $stmt = $this->db->prepare("
            SELECT ri.*,
                   bi.name as product_name,
                   bi.sku as product_sku,
                   bi.image_url as product_image
            FROM pos_return_items ri
            LEFT JOIN business_items bi ON ri.product_id = bi.id
            WHERE ri.return_id = ?
        ");
        $stmt->execute([$returnId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // =========================================
    // Helper Methods
    // =========================================
    
    /**
     * Get original transaction
     */
    private function getOriginalTransaction(int $transactionId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_transactions WHERE id = ? AND line_account_id = ?
        ");
        $stmt->execute([$transactionId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Check if transaction can be returned
     */
    private function canReturn(array $transaction): bool {
        // Check if within time limit
        $completedAt = strtotime($transaction['completed_at']);
        $daysSince = (time() - $completedAt) / 86400;
        
        return $daysSince <= self::RETURN_TIME_LIMIT_DAYS;
    }
    
    /**
     * Check if return requires manager authorization
     * Requirements: 12.8
     */
    private function requiresAuthorization(array $transaction): bool {
        $completedAt = strtotime($transaction['completed_at']);
        $daysSince = (time() - $completedAt) / 86400;
        
        // Require authorization if beyond time limit
        return $daysSince > self::RETURN_TIME_LIMIT_DAYS;
    }
    
    /**
     * Validate return items
     * Requirements: 12.3
     */
    private function validateReturnItems(int $transactionId, array $items): array {
        $validItems = [];
        
        foreach ($items as $item) {
            $itemId = $item['item_id'];
            $quantity = (int)$item['quantity'];
            
            if ($quantity <= 0) continue;
            
            // Get original item
            $stmt = $this->db->prepare("
                SELECT * FROM pos_transaction_items 
                WHERE id = ? AND transaction_id = ?
            ");
            $stmt->execute([$itemId, $transactionId]);
            $originalItem = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$originalItem) continue;
            
            // Check returnable quantity
            $returnableQty = $originalItem['quantity'] - $originalItem['returned_quantity'];
            if ($quantity > $returnableQty) {
                $quantity = $returnableQty;
            }
            
            if ($quantity <= 0) continue;
            
            $validItems[] = [
                'original_item_id' => $itemId,
                'product_id' => $originalItem['product_id'],
                'batch_id' => $originalItem['batch_id'],
                'quantity' => $quantity,
                'unit_price' => $originalItem['unit_price'],
                'line_total' => $quantity * $originalItem['unit_price']
            ];
        }
        
        return $validItems;
    }
    
    /**
     * Restore stock for return
     * Requirements: 12.5
     */
    private function restoreStock(int $returnId): void {
        $return = $this->getReturn($returnId);
        $items = $this->getReturnItems($returnId);
        
        foreach ($items as $item) {
            // Restore batch if tracked
            if ($item['batch_id'] && $this->batchService) {
                $batch = $this->batchService->getBatch($item['batch_id']);
                if ($batch) {
                    $this->batchService->updateBatch($item['batch_id'], [
                        'quantity_available' => $batch['quantity_available'] + $item['quantity']
                    ]);
                }
            }
            
            // Restore main stock
            if ($this->inventoryService) {
                $this->inventoryService->updateStock(
                    $item['product_id'],
                    $item['quantity'],
                    'return_restore',
                    'pos_return',
                    $returnId,
                    $return['return_number'],
                    "คืน Stock จากการคืนสินค้า #{$return['return_number']}",
                    $return['processed_by'],
                    $item['unit_price']
                );
            } else {
                $stmt = $this->db->prepare("
                    UPDATE business_items SET stock = stock + ? WHERE id = ?
                ");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
        }
    }
    
    /**
     * Update returned quantities on original items
     */
    private function updateReturnedQuantities(int $returnId): void {
        $items = $this->getReturnItems($returnId);
        
        foreach ($items as $item) {
            $stmt = $this->db->prepare("
                UPDATE pos_transaction_items 
                SET returned_quantity = returned_quantity + ?
                WHERE id = ?
            ");
            $stmt->execute([$item['quantity'], $item['original_item_id']]);
        }
    }
    
    /**
     * Generate return number
     */
    private function generateReturnNumber(): string {
        $date = date('Ymd');
        $prefix = "RTN-{$date}-";
        
        $stmt = $this->db->prepare("
            SELECT return_number FROM pos_returns 
            WHERE return_number LIKE ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute(["{$prefix}%"]);
        $last = $stmt->fetchColumn();
        
        if ($last) {
            $seq = (int)substr($last, -4) + 1;
        } else {
            $seq = 1;
        }
        
        return sprintf("%s%04d", $prefix, $seq);
    }
    
    /**
     * Get returns list
     */
    public function getReturns(array $filters = []): array {
        $sql = "
            SELECT r.*,
                   t.transaction_number as original_receipt,
                   u.display_name as customer_name,
                   a.display_name as processed_by_name
            FROM pos_returns r
            LEFT JOIN pos_transactions t ON r.original_transaction_id = t.id
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users a ON r.processed_by = a.id
            WHERE r.line_account_id = ?
        ";
        $params = [$this->lineAccountId];
        
        if (isset($filters['date'])) {
            $sql .= " AND DATE(r.created_at) = ?";
            $params[] = $filters['date'];
        }
        
        if (isset($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['shift_id'])) {
            $sql .= " AND r.shift_id = ?";
            $params[] = $filters['shift_id'];
        }
        
        $sql .= " ORDER BY r.created_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        } else {
            $sql .= " LIMIT 50";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Cancel a pending return
     */
    public function cancelReturn(int $returnId): bool {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new Exception('ไม่พบรายการคืนสินค้า', 404);
        }
        
        if ($return['status'] !== 'pending') {
            throw new Exception('สามารถยกเลิกได้เฉพาะรายการที่รอดำเนินการ', 400);
        }
        
        $stmt = $this->db->prepare("
            UPDATE pos_returns SET status = 'cancelled' WHERE id = ?
        ");
        return $stmt->execute([$returnId]);
    }
}
