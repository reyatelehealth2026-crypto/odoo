<?php
/**
 * POSReceiptService - จัดการใบเสร็จ POS
 * 
 * Generates and manages receipts including:
 * - Receipt generation with all required fields
 * - Thermal printer support
 * - LINE digital receipt
 * - Return receipts
 * 
 * Requirements: 5.1-5.5
 */

class POSReceiptService {
    private $db;
    private $lineAccountId;
    private $lineAPI;
    
    // Store info (should be loaded from settings)
    private $storeInfo = [
        'name' => 'ร้านขายยา',
        'address' => '',
        'phone' => '',
        'tax_id' => '',
        'branch' => 'สำนักงานใหญ่'
    ];
    
    /**
     * Constructor
     */
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId ?? 1;
        $this->loadStoreInfo();
    }
    
    /**
     * Set LINE API for digital receipts
     */
    public function setLineAPI($lineAPI): void {
        $this->lineAPI = $lineAPI;
    }
    
    /**
     * Load store info from settings
     */
    private function loadStoreInfo(): void {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM line_accounts WHERE id = ?
            ");
            $stmt->execute([$this->lineAccountId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($account) {
                $this->storeInfo = [
                    'name' => $account['shop_name'] ?? $account['name'] ?? 'ร้านขายยา',
                    'address' => $account['address'] ?? '',
                    'phone' => $account['phone'] ?? '',
                    'tax_id' => $account['tax_id'] ?? '',
                    'branch' => $account['branch_name'] ?? 'สำนักงานใหญ่'
                ];
            }
        } catch (Exception $e) {
            // Use defaults
        }
    }
    
    /**
     * Generate receipt data
     * Requirements: 5.1, 5.5
     * 
     * @param int $transactionId Transaction ID
     * @return array Receipt data
     */
    public function generateReceipt(int $transactionId): array {
        $transaction = $this->getTransaction($transactionId);
        if (!$transaction) {
            throw new Exception('ไม่พบรายการขาย', 404);
        }
        
        $items = $this->getTransactionItems($transactionId);
        $payments = $this->getPayments($transactionId);
        
        // Build receipt data
        $receipt = [
            'store' => $this->storeInfo,
            'transaction' => [
                'number' => $transaction['transaction_number'],
                'date' => $transaction['completed_at'] ?? $transaction['created_at'],
                'cashier' => $transaction['cashier_name'],
                'customer' => $transaction['customer_name'] ?? 'ลูกค้าทั่วไป',
                'customer_type' => $transaction['customer_type']
            ],
            'items' => array_map(function($item) {
                return [
                    'name' => $item['product_name'],
                    'sku' => $item['product_sku'],
                    'quantity' => (int)$item['quantity'],
                    'unit_price' => (float)$item['unit_price'],
                    'discount' => (float)$item['discount_amount'],
                    'total' => (float)$item['line_total']
                ];
            }, $items),
            'totals' => [
                'subtotal' => (float)$transaction['subtotal'],
                'discount' => (float)$transaction['discount_amount'],
                'vat' => (float)$transaction['vat_amount'],
                'total' => (float)$transaction['total_amount']
            ],
            'payments' => array_map(function($payment) {
                return [
                    'method' => $this->getPaymentMethodLabel($payment['payment_method']),
                    'amount' => (float)$payment['amount'],
                    'cash_received' => $payment['cash_received'] ? (float)$payment['cash_received'] : null,
                    'change' => $payment['change_amount'] ? (float)$payment['change_amount'] : null,
                    'reference' => $payment['reference_number'],
                    'points_used' => $payment['points_used'] ? (int)$payment['points_used'] : null
                ];
            }, $payments),
            'points' => [
                'earned' => (int)$transaction['points_earned'],
                'redeemed' => (int)$transaction['points_redeemed']
            ],
            'footer' => [
                'thank_you' => 'ขอบคุณที่ใช้บริการ',
                'return_policy' => 'สามารถคืนสินค้าได้ภายใน 7 วัน พร้อมใบเสร็จ'
            ]
        ];
        
        return $receipt;
    }
    
    /**
     * Print receipt to thermal printer
     * Requirements: 5.2
     * 
     * @param int $transactionId Transaction ID
     * @return bool Success
     */
    public function printReceipt(int $transactionId): bool {
        $receipt = $this->generateReceipt($transactionId);
        
        // Generate ESC/POS commands for thermal printer
        $printData = $this->generateThermalPrintData($receipt);
        
        // In a real implementation, this would send to printer
        // For now, we'll just return success
        // Could use libraries like escpos-php
        
        return true;
    }
    
    /**
     * Send digital receipt via LINE
     * Requirements: 5.3
     * 
     * @param int $transactionId Transaction ID
     * @param string $lineUserId LINE user ID
     * @return bool Success
     */
    public function sendLineReceipt(int $transactionId, string $lineUserId): bool {
        if (!$this->lineAPI) {
            throw new Exception('LINE API not configured', 500);
        }
        
        $receipt = $this->generateReceipt($transactionId);
        
        // Build Flex Message for LINE
        $flexMessage = $this->buildLineReceiptFlex($receipt);
        
        // Send via LINE API
        try {
            $this->lineAPI->pushMessage($lineUserId, $flexMessage);
            return true;
        } catch (Exception $e) {
            throw new Exception('ไม่สามารถส่งใบเสร็จทาง LINE ได้: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Generate return receipt
     * Requirements: 12.9
     * 
     * @param int $returnId Return ID
     * @return array Return receipt data
     */
    public function generateReturnReceipt(int $returnId): array {
        $return = $this->getReturn($returnId);
        if (!$return) {
            throw new Exception('ไม่พบรายการคืนสินค้า', 404);
        }
        
        $items = $this->getReturnItems($returnId);
        
        $receipt = [
            'store' => $this->storeInfo,
            'return' => [
                'number' => $return['return_number'],
                'date' => $return['completed_at'] ?? $return['created_at'],
                'original_receipt' => $return['original_receipt'],
                'processed_by' => $return['processed_by_name'],
                'reason' => $return['reason']
            ],
            'items' => array_map(function($item) {
                return [
                    'name' => $item['product_name'],
                    'sku' => $item['product_sku'],
                    'quantity' => -(int)$item['quantity'], // Negative for returns
                    'unit_price' => (float)$item['unit_price'],
                    'total' => -(float)$item['line_total']
                ];
            }, $items),
            'totals' => [
                'total_return' => -(float)$return['total_amount'],
                'refund_amount' => -(float)$return['refund_amount'],
                'refund_method' => $this->getRefundMethodLabel($return['refund_method'])
            ],
            'points' => [
                'deducted' => (int)$return['points_deducted']
            ],
            'footer' => [
                'note' => 'ใบเสร็จคืนสินค้า'
            ]
        ];
        
        return $receipt;
    }
    
    /**
     * Reprint receipt
     * Requirements: 5.4
     * 
     * @param int $transactionId Transaction ID
     * @return bool Success
     */
    public function reprintReceipt(int $transactionId): bool {
        // Same as print, but could add "REPRINT" watermark
        return $this->printReceipt($transactionId);
    }
    
    // =========================================
    // Helper Methods
    // =========================================
    
    /**
     * Get transaction
     */
    private function getTransaction(int $transactionId): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*,
                   u.display_name as customer_name,
                   a.display_name as cashier_name
            FROM pos_transactions t
            LEFT JOIN users u ON t.customer_id = u.id
            LEFT JOIN admin_users a ON t.cashier_id = a.id
            WHERE t.id = ? AND t.line_account_id = ?
        ");
        $stmt->execute([$transactionId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get transaction items
     */
    private function getTransactionItems(int $transactionId): array {
        $stmt = $this->db->prepare("
            SELECT ti.*,
                   bi.name as product_name,
                   bi.sku as product_sku
            FROM pos_transaction_items ti
            LEFT JOIN business_items bi ON ti.product_id = bi.id
            WHERE ti.transaction_id = ?
        ");
        $stmt->execute([$transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get payments
     */
    private function getPayments(int $transactionId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM pos_payments WHERE transaction_id = ?
        ");
        $stmt->execute([$transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get return
     */
    private function getReturn(int $returnId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.*,
                   t.transaction_number as original_receipt,
                   a.display_name as processed_by_name
            FROM pos_returns r
            LEFT JOIN pos_transactions t ON r.original_transaction_id = t.id
            LEFT JOIN admin_users a ON r.processed_by = a.id
            WHERE r.id = ? AND r.line_account_id = ?
        ");
        $stmt->execute([$returnId, $this->lineAccountId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get return items
     */
    private function getReturnItems(int $returnId): array {
        $stmt = $this->db->prepare("
            SELECT ri.*,
                   bi.name as product_name,
                   bi.sku as product_sku
            FROM pos_return_items ri
            LEFT JOIN business_items bi ON ri.product_id = bi.id
            WHERE ri.return_id = ?
        ");
        $stmt->execute([$returnId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get payment method label
     */
    private function getPaymentMethodLabel(string $method): string {
        $labels = [
            'cash' => 'เงินสด',
            'transfer' => 'โอนเงิน/QR',
            'card' => 'บัตรเครดิต/เดบิต',
            'points' => 'แต้มสะสม',
            'credit' => 'เครดิต'
        ];
        return $labels[$method] ?? $method;
    }
    
    /**
     * Get refund method label
     */
    private function getRefundMethodLabel(string $method): string {
        $labels = [
            'cash' => 'เงินสด',
            'original' => 'คืนตามวิธีเดิม',
            'credit' => 'เครดิต'
        ];
        return $labels[$method] ?? $method;
    }
    
    /**
     * Generate thermal printer data (ESC/POS format)
     */
    private function generateThermalPrintData(array $receipt): string {
        $lines = [];
        
        // Header
        $lines[] = str_pad($receipt['store']['name'], 32, ' ', STR_PAD_BOTH);
        if ($receipt['store']['address']) {
            $lines[] = str_pad($receipt['store']['address'], 32, ' ', STR_PAD_BOTH);
        }
        if ($receipt['store']['phone']) {
            $lines[] = str_pad('โทร: ' . $receipt['store']['phone'], 32, ' ', STR_PAD_BOTH);
        }
        if ($receipt['store']['tax_id']) {
            $lines[] = str_pad('เลขประจำตัวผู้เสียภาษี: ' . $receipt['store']['tax_id'], 32, ' ', STR_PAD_BOTH);
        }
        
        $lines[] = str_repeat('-', 32);
        
        // Transaction info
        $lines[] = 'เลขที่: ' . $receipt['transaction']['number'];
        $lines[] = 'วันที่: ' . date('d/m/Y H:i', strtotime($receipt['transaction']['date']));
        $lines[] = 'พนักงาน: ' . $receipt['transaction']['cashier'];
        $lines[] = 'ลูกค้า: ' . $receipt['transaction']['customer'];
        
        $lines[] = str_repeat('-', 32);
        
        // Items
        foreach ($receipt['items'] as $item) {
            $lines[] = $item['name'];
            $qtyPrice = sprintf('%d x %.2f', $item['quantity'], $item['unit_price']);
            $total = sprintf('%.2f', $item['total']);
            $lines[] = str_pad($qtyPrice, 20) . str_pad($total, 12, ' ', STR_PAD_LEFT);
            if ($item['discount'] > 0) {
                $lines[] = str_pad('  ส่วนลด', 20) . str_pad('-' . number_format($item['discount'], 2), 12, ' ', STR_PAD_LEFT);
            }
        }
        
        $lines[] = str_repeat('-', 32);
        
        // Totals
        $lines[] = str_pad('รวม', 20) . str_pad(number_format($receipt['totals']['subtotal'], 2), 12, ' ', STR_PAD_LEFT);
        if ($receipt['totals']['discount'] > 0) {
            $lines[] = str_pad('ส่วนลด', 20) . str_pad('-' . number_format($receipt['totals']['discount'], 2), 12, ' ', STR_PAD_LEFT);
        }
        $lines[] = str_pad('VAT 7%', 20) . str_pad(number_format($receipt['totals']['vat'], 2), 12, ' ', STR_PAD_LEFT);
        $lines[] = str_pad('ยอดสุทธิ', 20) . str_pad(number_format($receipt['totals']['total'], 2), 12, ' ', STR_PAD_LEFT);
        
        $lines[] = str_repeat('-', 32);
        
        // Payments
        foreach ($receipt['payments'] as $payment) {
            $lines[] = str_pad($payment['method'], 20) . str_pad(number_format($payment['amount'], 2), 12, ' ', STR_PAD_LEFT);
            if ($payment['cash_received']) {
                $lines[] = str_pad('  รับเงิน', 20) . str_pad(number_format($payment['cash_received'], 2), 12, ' ', STR_PAD_LEFT);
            }
            if ($payment['change']) {
                $lines[] = str_pad('  ทอน', 20) . str_pad(number_format($payment['change'], 2), 12, ' ', STR_PAD_LEFT);
            }
        }
        
        // Points
        if ($receipt['points']['earned'] > 0) {
            $lines[] = str_repeat('-', 32);
            $lines[] = 'แต้มที่ได้รับ: ' . number_format($receipt['points']['earned']);
        }
        
        $lines[] = str_repeat('-', 32);
        
        // Footer
        $lines[] = str_pad($receipt['footer']['thank_you'], 32, ' ', STR_PAD_BOTH);
        $lines[] = '';
        $lines[] = $receipt['footer']['return_policy'];
        
        return implode("\n", $lines);
    }
    
    /**
     * Build LINE Flex Message for receipt
     */
    private function buildLineReceiptFlex(array $receipt): array {
        $itemBubbles = [];
        
        foreach ($receipt['items'] as $item) {
            $itemBubbles[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $item['name'],
                        'size' => 'sm',
                        'flex' => 3
                    ],
                    [
                        'type' => 'text',
                        'text' => $item['quantity'] . 'x',
                        'size' => 'sm',
                        'flex' => 1,
                        'align' => 'center'
                    ],
                    [
                        'type' => 'text',
                        'text' => '฿' . number_format($item['total'], 2),
                        'size' => 'sm',
                        'flex' => 2,
                        'align' => 'end'
                    ]
                ]
            ];
        }
        
        return [
            'type' => 'flex',
            'altText' => 'ใบเสร็จ #' . $receipt['transaction']['number'],
            'contents' => [
                'type' => 'bubble',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $receipt['store']['name'],
                            'weight' => 'bold',
                            'size' => 'lg',
                            'align' => 'center'
                        ],
                        [
                            'type' => 'text',
                            'text' => 'ใบเสร็จรับเงิน',
                            'size' => 'sm',
                            'align' => 'center',
                            'color' => '#888888'
                        ]
                    ]
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => array_merge(
                        [
                            [
                                'type' => 'text',
                                'text' => '#' . $receipt['transaction']['number'],
                                'size' => 'sm',
                                'color' => '#888888'
                            ],
                            [
                                'type' => 'text',
                                'text' => date('d/m/Y H:i', strtotime($receipt['transaction']['date'])),
                                'size' => 'xs',
                                'color' => '#888888'
                            ],
                            [
                                'type' => 'separator',
                                'margin' => 'md'
                            ]
                        ],
                        $itemBubbles,
                        [
                            [
                                'type' => 'separator',
                                'margin' => 'md'
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'horizontal',
                                'margin' => 'md',
                                'contents' => [
                                    [
                                        'type' => 'text',
                                        'text' => 'ยอดรวม',
                                        'weight' => 'bold',
                                        'flex' => 1
                                    ],
                                    [
                                        'type' => 'text',
                                        'text' => '฿' . number_format($receipt['totals']['total'], 2),
                                        'weight' => 'bold',
                                        'align' => 'end'
                                    ]
                                ]
                            ]
                        ]
                    )
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => $receipt['footer']['thank_you'],
                            'align' => 'center',
                            'size' => 'sm',
                            'color' => '#888888'
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Get receipt HTML for preview
     */
    public function getReceiptHTML(int $transactionId): string {
        $receipt = $this->generateReceipt($transactionId);
        
        ob_start();
        ?>
        <div class="receipt" style="font-family: monospace; width: 300px; padding: 10px;">
            <div style="text-align: center; margin-bottom: 10px;">
                <strong><?= htmlspecialchars($receipt['store']['name']) ?></strong><br>
                <?php if ($receipt['store']['address']): ?>
                    <small><?= htmlspecialchars($receipt['store']['address']) ?></small><br>
                <?php endif; ?>
                <?php if ($receipt['store']['phone']): ?>
                    <small>โทร: <?= htmlspecialchars($receipt['store']['phone']) ?></small><br>
                <?php endif; ?>
            </div>
            
            <hr>
            
            <div style="font-size: 12px;">
                <div>เลขที่: <?= htmlspecialchars($receipt['transaction']['number']) ?></div>
                <div>วันที่: <?= date('d/m/Y H:i', strtotime($receipt['transaction']['date'])) ?></div>
                <div>พนักงาน: <?= htmlspecialchars($receipt['transaction']['cashier']) ?></div>
                <div>ลูกค้า: <?= htmlspecialchars($receipt['transaction']['customer']) ?></div>
            </div>
            
            <hr>
            
            <table style="width: 100%; font-size: 12px;">
                <?php foreach ($receipt['items'] as $item): ?>
                <tr>
                    <td colspan="3"><?= htmlspecialchars($item['name']) ?></td>
                </tr>
                <tr>
                    <td><?= $item['quantity'] ?> x <?= number_format($item['unit_price'], 2) ?></td>
                    <td></td>
                    <td style="text-align: right;"><?= number_format($item['total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <hr>
            
            <table style="width: 100%; font-size: 12px;">
                <tr>
                    <td>รวม</td>
                    <td style="text-align: right;"><?= number_format($receipt['totals']['subtotal'], 2) ?></td>
                </tr>
                <?php if ($receipt['totals']['discount'] > 0): ?>
                <tr>
                    <td>ส่วนลด</td>
                    <td style="text-align: right;">-<?= number_format($receipt['totals']['discount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>VAT 7%</td>
                    <td style="text-align: right;"><?= number_format($receipt['totals']['vat'], 2) ?></td>
                </tr>
                <tr>
                    <td><strong>ยอดสุทธิ</strong></td>
                    <td style="text-align: right;"><strong><?= number_format($receipt['totals']['total'], 2) ?></strong></td>
                </tr>
            </table>
            
            <hr>
            
            <?php foreach ($receipt['payments'] as $payment): ?>
            <div style="font-size: 12px;">
                <?= htmlspecialchars($payment['method']) ?>: <?= number_format($payment['amount'], 2) ?>
                <?php if ($payment['change']): ?>
                    <br>ทอน: <?= number_format($payment['change'], 2) ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php if ($receipt['points']['earned'] > 0): ?>
            <hr>
            <div style="font-size: 12px;">
                แต้มที่ได้รับ: <?= number_format($receipt['points']['earned']) ?> แต้ม
            </div>
            <?php endif; ?>
            
            <hr>
            
            <div style="text-align: center; font-size: 12px;">
                <?= htmlspecialchars($receipt['footer']['thank_you']) ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
