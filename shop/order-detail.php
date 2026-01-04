<?php
/**
 * Shop - รายละเอียดคำสั่งซื้อ
 * V2.5 - รองรับทั้ง orders และ transactions + Multi-bot
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/LineAccountManager.php';

$db = Database::getInstance()->getConnection();
$pageTitle = 'รายละเอียดคำสั่งซื้อ';

// Get current bot ID from session
$currentBotId = $_SESSION['current_bot_id'] ?? 1;

// Get LineAPI for current bot
$lineManager = new LineAccountManager($db);
$line = $lineManager->getLineAPI($currentBotId);

$orderId = (int)($_GET['id'] ?? 0);

// Redirect if no order ID
if (!$orderId) {
    header('Location: orders.php');
    exit;
}

// Use transactions table (unified with LIFF checkout)
$useTransactions = true;
$ordersTable = 'transactions';
$itemsTable = 'transaction_items';
$itemsFk = 'transaction_id';

/**
 * Build Flex Order Status Message
 */
function buildOrderStatusFlex($order, $items, $newStatus, $tracking = null) {
    $statusConfig = [
        'pending' => ['icon' => '⏳', 'label' => 'รอยืนยัน', 'color' => '#F59E0B', 'msg' => 'รอการยืนยันจากร้านค้า'],
        'confirmed' => ['icon' => '✅', 'label' => 'ยืนยันแล้ว', 'color' => '#3B82F6', 'msg' => 'ออเดอร์ได้รับการยืนยันแล้ว'],
        'paid' => ['icon' => '💰', 'label' => 'ชำระเงินแล้ว', 'color' => '#10B981', 'msg' => 'ยืนยันการชำระเงินเรียบร้อย'],
        'shipping' => ['icon' => '🚚', 'label' => 'กำลังจัดส่ง', 'color' => '#8B5CF6', 'msg' => 'สินค้าถูกจัดส่งแล้ว'],
        'delivered' => ['icon' => '📦', 'label' => 'จัดส่งแล้ว', 'color' => '#059669', 'msg' => 'สินค้าถึงปลายทางแล้ว'],
        'cancelled' => ['icon' => '❌', 'label' => 'ยกเลิก', 'color' => '#EF4444', 'msg' => 'ออเดอร์ถูกยกเลิก']
    ];
    
    $status = $statusConfig[$newStatus] ?? $statusConfig['pending'];
    
    // Build item list
    $itemList = [];
    foreach ($items as $item) {
        $itemList[] = [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => $item['product_name'], 'size' => 'sm', 'color' => '#555555', 'flex' => 4, 'wrap' => true],
                ['type' => 'text', 'text' => 'x' . $item['quantity'], 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 1],
                ['type' => 'text', 'text' => '฿' . number_format($item['subtotal'], 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end', 'flex' => 2]
            ]
        ];
    }
    
    // Get delivery info
    $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
    $addrParts = [];
    if (!empty($deliveryInfo['name'])) $addrParts[] = $deliveryInfo['name'];
    if (!empty($deliveryInfo['phone'])) $addrParts[] = $deliveryInfo['phone'];
    if (!empty($deliveryInfo['address'])) $addrParts[] = $deliveryInfo['address'];
    $addr = implode("\n", $addrParts) ?: 'ไม่ระบุที่อยู่';
    
    // Body contents
    $bodyContents = [
        [
            'type' => 'box',
            'layout' => 'horizontal',
            'contents' => [
                ['type' => 'text', 'text' => $status['icon'] . ' ' . $status['label'], 'weight' => 'bold', 'size' => 'xl', 'color' => $status['color']],
            ]
        ],
        ['type' => 'text', 'text' => $status['msg'], 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
        ['type' => 'separator', 'margin' => 'lg'],
        ['type' => 'text', 'text' => '📋 Order #' . $order['order_number'], 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
        ['type' => 'text', 'text' => '📅 ' . date('d/m/Y H:i'), 'size' => 'xs', 'color' => '#aaaaaa', 'margin' => 'sm'],
    ];
    
    // Add tracking number if shipping
    if ($newStatus === 'shipping' && $tracking) {
        $bodyContents[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'margin' => 'lg',
            'paddingAll' => 'md',
            'backgroundColor' => '#F3E8FF',
            'cornerRadius' => 'md',
            'contents' => [
                ['type' => 'text', 'text' => '🚚 เลขพัสดุ', 'weight' => 'bold', 'size' => 'sm', 'color' => '#7C3AED'],
                ['type' => 'text', 'text' => $tracking, 'size' => 'lg', 'weight' => 'bold', 'color' => '#5B21B6', 'margin' => 'sm']
            ]
        ];
    }
    
    // Add items section
    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'text', 'text' => '🛒 รายการสินค้า', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm', 'contents' => $itemList];
    
    // Add totals
    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = [
        'type' => 'box',
        'layout' => 'horizontal',
        'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => 'ยอดสินค้า', 'size' => 'sm', 'color' => '#555555'],
            ['type' => 'text', 'text' => '฿' . number_format($order['total_amount'], 0), 'size' => 'sm', 'color' => '#111111', 'align' => 'end']
        ]
    ];
    $bodyContents[] = [
        'type' => 'box',
        'layout' => 'horizontal',
        'margin' => 'sm',
        'contents' => [
            ['type' => 'text', 'text' => 'ค่าจัดส่ง', 'size' => 'sm', 'color' => '#555555'],
            ['type' => 'text', 'text' => $order['shipping_fee'] > 0 ? '฿' . number_format($order['shipping_fee'], 0) : 'ฟรี!', 'size' => 'sm', 'color' => $order['shipping_fee'] > 0 ? '#111111' : '#10B981', 'align' => 'end']
        ]
    ];
    $bodyContents[] = ['type' => 'separator', 'margin' => 'md'];
    $bodyContents[] = [
        'type' => 'box',
        'layout' => 'horizontal',
        'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => 'ยอดสุทธิ', 'weight' => 'bold', 'size' => 'md'],
            ['type' => 'text', 'text' => '฿' . number_format($order['grand_total'], 0), 'weight' => 'bold', 'size' => 'xl', 'align' => 'end', 'color' => $status['color']]
        ]
    ];
    
    // Add address section
    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'text', 'text' => '📦 ที่อยู่จัดส่ง', 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'];
    $bodyContents[] = ['type' => 'text', 'text' => $addr, 'size' => 'xs', 'color' => '#666666', 'wrap' => true, 'margin' => 'sm'];
    
    $bubble = [
        'type' => 'bubble',
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => $bodyContents
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'contents' => [
                ['type' => 'text', 'text' => '🙏 ขอบคุณที่ใช้บริการ', 'align' => 'center', 'color' => '#aaaaaa', 'size' => 'xs']
            ]
        ]
    ];
    
    return [
        'type' => 'flex',
        'altText' => $status['icon'] . ' อัพเดทสถานะ #' . $order['order_number'] . ' - ' . $status['label'],
        'contents' => $bubble
    ];
}

/**
 * Send Flex Order Status to customer
 * ใช้ sendMessage เพื่อเช็ค replyToken ก่อน (ฟรี!) หรือ fallback ไป pushMessage
 */
function sendOrderStatusFlex($line, $db, $orderId, $newStatus, $tracking = null) {
    // Get order with items and reply token
    $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM transactions o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order || !$order['line_user_id']) return false;
    
    // Get items
    $stmt = $db->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build flex message
    $flexMessage = buildOrderStatusFlex($order, $items, $newStatus, $tracking);
    
    // ใช้ sendMessage ถ้ามี หรือ fallback ไป pushMessage
    if (method_exists($line, 'sendMessage')) {
        return $line->sendMessage($order['line_user_id'], [$flexMessage], $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
    } else {
        return $line->pushMessage($order['line_user_id'], [$flexMessage]);
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        try {
            $newStatus = $_POST['status'];
            $stmt = $db->prepare("UPDATE {$ordersTable} SET status = ? WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$newStatus, $orderId, $currentBotId]);
            
            // Update tracking if provided
            $tracking = null;
            if (!empty($_POST['tracking'])) {
                $tracking = $_POST['tracking'];
                $stmt = $db->prepare("UPDATE {$ordersTable} SET shipping_tracking = ? WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
                $stmt->execute([$tracking, $orderId, $currentBotId]);
            }
            
            // Send Flex notification to customer (with error handling)
            try {
                if ($line) {
                    sendOrderStatusFlex($line, $db, $orderId, $newStatus, $tracking);
                }
            } catch (Exception $e) {
                error_log('sendOrderStatusFlex error: ' . $e->getMessage());
            }
            
            // V2.5: Auto-fulfill digital items when paid (optional)
            try {
                if ($newStatus === 'paid' && $useTransactions && file_exists(__DIR__ . '/../classes/BusinessBot.php')) {
                    require_once __DIR__ . '/../classes/BusinessBot.php';
                    if (class_exists('BusinessBot') && method_exists('BusinessBot', 'autoFulfillDigitalItems')) {
                        $businessBot = new BusinessBot($db, $line, $currentBotId);
                        $businessBot->autoFulfillDigitalItems($orderId);
                    }
                }
            } catch (Exception $e) {
                error_log('BusinessBot error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            error_log('update_status error: ' . $e->getMessage());
        }
        
        header("Location: order-detail.php?id={$orderId}&updated=1");
        exit;
    }
    
    if ($action === 'approve_payment') {
        try {
            $stmt = $db->prepare("UPDATE {$ordersTable} SET payment_status = 'paid', status = 'paid' WHERE id = ? AND (line_account_id = ? OR line_account_id IS NULL)");
            $stmt->execute([$orderId, $currentBotId]);
            
            $stmt = $db->prepare("UPDATE payment_slips SET status = 'approved' WHERE transaction_id = ? AND status = 'pending'");
            $stmt->execute([$orderId]);
            
            // Send Flex notification (with error handling)
            try {
                if ($line) {
                    sendOrderStatusFlex($line, $db, $orderId, 'paid');
                }
            } catch (Exception $e) {
                error_log('sendOrderStatusFlex error: ' . $e->getMessage());
            }
            
            // V2.5: Auto-fulfill digital items (optional)
            try {
                if ($useTransactions && file_exists(__DIR__ . '/../classes/BusinessBot.php')) {
                    require_once __DIR__ . '/../classes/BusinessBot.php';
                    if (class_exists('BusinessBot') && method_exists('BusinessBot', 'autoFulfillDigitalItems')) {
                        $businessBot = new BusinessBot($db, $line, $currentBotId);
                        $businessBot->autoFulfillDigitalItems($orderId);
                    }
                }
            } catch (Exception $e) {
                error_log('BusinessBot error: ' . $e->getMessage());
            }
            
            // Award loyalty points (unified system)
            try {
                $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.points as current_points FROM {$ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();
                
                if ($order && $order['user_id']) {
                    // Get points settings
                    $pointsPerBaht = 1; // Default: 1 แต้มต่อ 1 บาท
                    try {
                        $stmt = $db->prepare("SELECT points_per_baht FROM points_settings WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY line_account_id DESC LIMIT 1");
                        $stmt->execute([$currentBotId]);
                        $settings = $stmt->fetch();
                        if ($settings) $pointsPerBaht = (float)$settings['points_per_baht'];
                    } catch (Exception $e) {}
                    
                    // Calculate points
                    $earnedPoints = (int)floor($order['grand_total'] * $pointsPerBaht);
                    
                    if ($earnedPoints > 0) {
                        $newBalance = ($order['current_points'] ?? 0) + $earnedPoints;
                        
                        // Update users.points (for LIFF system)
                        $stmt = $db->prepare("UPDATE users SET points = points + ? WHERE id = ?");
                        $stmt->execute([$earnedPoints, $order['user_id']]);
                        
                        // Log to points_history (for LIFF system)
                        try {
                            $stmt = $db->prepare("INSERT INTO points_history (line_account_id, user_id, points, type, description, reference_type, reference_id, balance_after) VALUES (?, ?, ?, 'earn', ?, 'order', ?, ?)");
                            $stmt->execute([$currentBotId, $order['user_id'], $earnedPoints, "แต้มจากออเดอร์ #{$order['order_number']}", $orderId, $newBalance]);
                        } catch (Exception $e) {
                            error_log('points_history insert error: ' . $e->getMessage());
                        }
                        
                        // Also log to points_transactions (for legacy LoyaltyPoints system)
                        try {
                            $stmt = $db->prepare("INSERT INTO points_transactions (user_id, line_account_id, type, points, balance_after, reference_type, reference_id, description) VALUES (?, ?, 'earn', ?, ?, 'order', ?, ?)");
                            $stmt->execute([$order['user_id'], $currentBotId, $earnedPoints, $newBalance, $orderId, "Points from order #{$order['order_number']}"]);
                        } catch (Exception $e) {}
                        
                        // ⚠️ ไม่ส่งแจ้งเตือนแต้มแยก - จะรวมในข้อความสถานะออเดอร์ด้านบน
                    }
                }
            } catch (Exception $e) {
                error_log('Award points error: ' . $e->getMessage());
            }
        } catch (Exception $e) {
            error_log('approve_payment error: ' . $e->getMessage());
        }
        
        header("Location: order-detail.php?id={$orderId}&updated=1");
        exit;
    }
    
    if ($action === 'update_shipping') {
        try {
            $stmt = $db->prepare("UPDATE {$ordersTable} SET shipping_name=?, shipping_phone=?, shipping_address=? WHERE id=?");
            $stmt->execute([$_POST['shipping_name'] ?? '', $_POST['shipping_phone'] ?? '', $_POST['shipping_address'] ?? '', $orderId]);
        } catch (Exception $e) {
            error_log('update_shipping error: ' . $e->getMessage());
        }
        
        header("Location: order-detail.php?id={$orderId}&updated=1");
        exit;
    }
    
    if ($action === 'reject_payment') {
        $stmt = $db->prepare("UPDATE payment_slips SET status = 'rejected' WHERE transaction_id = ? AND status = 'pending'");
        $stmt->execute([$orderId]);
        
        // Send rejection Flex message - ดึง reply_token ด้วย
        $stmt = $db->prepare("SELECT o.*, u.line_user_id, u.reply_token, u.reply_token_expires FROM {$ordersTable} o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if ($order && $order['line_user_id']) {
            // Build rejection Flex
            $rejectFlex = [
                'type' => 'flex',
                'altText' => '❌ หลักฐานการชำระเงินไม่ถูกต้อง #' . $order['order_number'],
                'contents' => [
                    'type' => 'bubble',
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => '❌ สลิปไม่ถูกต้อง', 'weight' => 'bold', 'size' => 'xl', 'color' => '#EF4444'],
                            ['type' => 'text', 'text' => 'หลักฐานการชำระเงินไม่ถูกต้อง', 'size' => 'sm', 'color' => '#888888', 'margin' => 'sm'],
                            ['type' => 'separator', 'margin' => 'lg'],
                            ['type' => 'text', 'text' => '📋 Order #' . $order['order_number'], 'weight' => 'bold', 'size' => 'sm', 'margin' => 'lg'],
                            ['type' => 'text', 'text' => '💰 ยอดที่ต้องชำระ: ฿' . number_format($order['grand_total'], 0), 'size' => 'sm', 'color' => '#555555', 'margin' => 'md'],
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'margin' => 'lg',
                                'paddingAll' => 'md',
                                'backgroundColor' => '#FEF2F2',
                                'cornerRadius' => 'md',
                                'contents' => [
                                    ['type' => 'text', 'text' => '⚠️ กรุณาตรวจสอบและส่งหลักฐานใหม่', 'size' => 'sm', 'color' => '#DC2626', 'wrap' => true]
                                ]
                            ]
                        ]
                    ],
                    'footer' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => 'หากมีข้อสงสัย กรุณาติดต่อร้านค้า', 'align' => 'center', 'color' => '#aaaaaa', 'size' => 'xs']
                        ]
                    ]
                ]
            ];
            // ใช้ sendMessage ถ้ามี หรือ fallback ไป pushMessage
            if (method_exists($line, 'sendMessage')) {
                $line->sendMessage($order['line_user_id'], [$rejectFlex], $order['reply_token'] ?? null, $order['reply_token_expires'] ?? null, $db);
            } else {
                $line->pushMessage($order['line_user_id'], [$rejectFlex]);
            }
        }
        
        header("Location: order-detail.php?id={$orderId}&rejected=1");
        exit;
    }
    
    if ($action === 'add_tracking') {
        try {
            $tracking = trim($_POST['tracking'] ?? '');
            if ($tracking) {
                // Update without line_account_id filter to ensure it works
                $stmt = $db->prepare("UPDATE {$ordersTable} SET shipping_tracking = ?, status = 'shipping' WHERE id = ?");
                $stmt->execute([$tracking, $orderId]);
                $affected = $stmt->rowCount();
                error_log("add_tracking: orderId={$orderId}, tracking={$tracking}, affected={$affected}");
                
                // Send Flex notification with tracking
                try {
                    if ($line) {
                        sendOrderStatusFlex($line, $db, $orderId, 'shipping', $tracking);
                    }
                } catch (Exception $e) {
                    error_log('sendOrderStatusFlex error: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log('add_tracking error: ' . $e->getMessage());
        }
        
        header("Location: order-detail.php?id={$orderId}&tracking_added=1");
        exit;
    }
}

// Get order (filtered by current bot)
$stmt = $db->prepare("SELECT o.*, u.display_name, u.picture_url, u.line_user_id 
                      FROM {$ordersTable} o 
                      JOIN users u ON o.user_id = u.id 
                      WHERE o.id = ? AND (o.line_account_id = ? OR o.line_account_id IS NULL)");
$stmt->execute([$orderId, $currentBotId]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: orders.php');
    exit;
}

// Get order items
$stmt = $db->prepare("SELECT * FROM {$itemsTable} WHERE {$itemsFk} = ?");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

// Get payment slips by transaction_id
$stmt = $db->prepare("SELECT * FROM payment_slips WHERE transaction_id = ? ORDER BY created_at DESC");
$stmt->execute([$orderId]);
$slips = $stmt->fetchAll();

// Transaction type info for V2.5
$transactionTypes = [
    'purchase' => ['icon' => '🛒', 'label' => 'ซื้อสินค้า'],
    'booking' => ['icon' => '📅', 'label' => 'จองคิว'],
    'subscription' => ['icon' => '🔄', 'label' => 'สมัครสมาชิก'],
    'redemption' => ['icon' => '🎁', 'label' => 'แลกของรางวัล']
];
$transType = $order['transaction_type'] ?? 'purchase';
$typeInfo = $transactionTypes[$transType] ?? $transactionTypes['purchase'];

$pageTitle = "รายการ #{$order['order_number']}";

require_once __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['updated'])): ?>
<div class="mb-4 p-4 bg-green-100 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i>อัพเดทสำเร็จ!
</div>
<?php endif; ?>

<div class="mb-4">
    <a href="orders.php" class="text-green-600 hover:underline"><i class="fas fa-arrow-left mr-2"></i>กลับไปรายการคำสั่งซื้อ</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Order Info -->
    <div class="lg:col-span-2 space-y-6">
        <!-- Header -->
        <div class="bg-white rounded-xl shadow p-6">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-xl font-bold">#<?= $order['order_number'] ?></h3>
                        <?php if ($useTransactions && $transType !== 'purchase'): ?>
                        <span class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs"><?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-gray-500"><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></p>
                </div>
                <?php
                $statusColors = [
                    'pending' => 'bg-yellow-100 text-yellow-600',
                    'confirmed' => 'bg-blue-100 text-blue-600',
                    'paid' => 'bg-green-100 text-green-600',
                    'shipping' => 'bg-purple-100 text-purple-600',
                    'delivered' => 'bg-gray-100 text-gray-600',
                    'cancelled' => 'bg-red-100 text-red-600'
                ];
                $statusLabels = [
                    'pending' => 'รอยืนยัน',
                    'confirmed' => 'ยืนยันแล้ว',
                    'paid' => 'ชำระแล้ว',
                    'shipping' => 'กำลังส่ง',
                    'delivered' => 'ส่งแล้ว',
                    'cancelled' => 'ยกเลิก'
                ];
                // สำหรับ COD ที่ status = confirmed แสดงว่ารอจัดส่ง
                $isCOD = ($order['payment_method'] ?? '') === 'cod';
                $currentStatus = $order['status'] ?? 'pending';
                if ($isCOD && $currentStatus === 'confirmed') {
                    $statusLabel = 'รอจัดส่ง (COD)';
                } else {
                    $statusLabel = $statusLabels[$currentStatus] ?? 'รอดำเนินการ';
                }
                ?>
                <span class="px-4 py-2 rounded-full text-sm font-medium <?= $statusColors[$order['status'] ?? 'pending'] ?? 'bg-gray-100 text-gray-600' ?>">
                    <?= $statusLabel ?>
                </span>
            </div>
            
            <!-- Customer -->
            <a href="../user-detail.php?id=<?= $order['user_id'] ?>" class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition">
                <img src="<?= $order['picture_url'] ?: 'https://via.placeholder.com/48' ?>" class="w-12 h-12 rounded-full mr-4">
                <div class="flex-1">
                    <p class="font-medium"><?= htmlspecialchars($order['display_name']) ?></p>
                    <p class="text-sm text-gray-500">LINE User</p>
                </div>
                <i class="fas fa-chevron-right text-gray-400"></i>
            </a>
        </div>
        
        <!-- Items -->
        <div class="bg-white rounded-xl shadow p-6">
            <h4 class="font-semibold mb-4">รายการสินค้า</h4>
            <div class="space-y-3">
                <?php foreach ($items as $item): ?>
                <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="font-medium"><?= htmlspecialchars($item['product_name']) ?></p>
                        <p class="text-sm text-gray-500">฿<?= number_format($item['product_price'], 2) ?> x <?= $item['quantity'] ?></p>
                    </div>
                    <p class="font-medium">฿<?= number_format($item['subtotal'], 2) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="border-t mt-4 pt-4 space-y-2">
                <div class="flex justify-between text-gray-600">
                    <span>ยอดสินค้า</span>
                    <span>฿<?= number_format($order['total_amount'], 2) ?></span>
                </div>
                <div class="flex justify-between text-gray-600">
                    <span>ค่าจัดส่ง</span>
                    <span><?= $order['shipping_fee'] > 0 ? '฿' . number_format($order['shipping_fee'], 2) : 'ฟรี' ?></span>
                </div>
                <?php if ($order['discount_amount'] > 0): ?>
                <div class="flex justify-between text-green-600">
                    <span>ส่วนลด</span>
                    <span>-฿<?= number_format($order['discount_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between text-lg font-bold pt-2 border-t">
                    <span>รวมทั้งหมด</span>
                    <span class="text-green-600">฿<?= number_format($order['grand_total'], 2) ?></span>
                </div>
            </div>
        </div>
        
        <!-- Shipping Info -->
        <?php 
        // Parse delivery_info from LIFF
        $deliveryInfo = json_decode($order['delivery_info'] ?? '{}', true);
        $shippingName = $order['shipping_name'] ?? $deliveryInfo['name'] ?? '';
        $shippingPhone = $order['shipping_phone'] ?? $deliveryInfo['phone'] ?? '';
        // Use full_address if available, otherwise combine parts or use address field
        $liffAddress = $deliveryInfo['full_address'] ?? '';
        if (empty($liffAddress)) {
            $liffAddress = trim(implode(' ', array_filter([
                $deliveryInfo['address'] ?? '',
                $deliveryInfo['subdistrict'] ?? '',
                $deliveryInfo['district'] ?? '',
                $deliveryInfo['province'] ?? '',
                $deliveryInfo['postcode'] ?? ''
            ])));
        }
        $shippingAddress = $order['shipping_address'] ?? $liffAddress;
        ?>
        <div class="bg-white rounded-xl shadow p-6">
            <h4 class="font-semibold mb-4"><i class="fas fa-truck text-blue-500 mr-2"></i>ข้อมูลจัดส่ง</h4>
            
            <?php if (!empty($deliveryInfo['name']) || !empty($deliveryInfo['phone']) || !empty($liffAddress)): ?>
            <!-- LIFF Delivery Info (Read-only) -->
            <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-center mb-2">
                    <span class="px-2 py-0.5 bg-blue-500 text-white text-xs rounded mr-2">จาก LIFF</span>
                    <span class="text-sm text-blue-600">ข้อมูลที่ลูกค้ากรอกตอนสั่งซื้อ</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                    <?php if (!empty($deliveryInfo['name'])): ?>
                    <div>
                        <span class="text-gray-500">ผู้รับ:</span>
                        <span class="font-medium ml-1"><?= htmlspecialchars($deliveryInfo['name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($deliveryInfo['phone'])): ?>
                    <div>
                        <span class="text-gray-500">โทร:</span>
                        <span class="font-medium ml-1"><?= htmlspecialchars($deliveryInfo['phone']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($liffAddress)): ?>
                    <div class="md:col-span-2">
                        <span class="text-gray-500">ที่อยู่:</span>
                        <span class="font-medium ml-1"><?= htmlspecialchars($liffAddress) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Editable Shipping Form -->
            <form method="POST">
                <input type="hidden" name="action" value="update_shipping">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">ชื่อผู้รับ</label>
                        <input type="text" name="shipping_name" value="<?= htmlspecialchars($shippingName) ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">เบอร์โทร</label>
                        <input type="text" name="shipping_phone" value="<?= htmlspecialchars($shippingPhone) ?>" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">ที่อยู่จัดส่ง</label>
                    <textarea name="shipping_address" rows="3" class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500"><?= htmlspecialchars($shippingAddress) ?></textarea>
                </div>
                <button type="submit" class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    <i class="fas fa-save mr-2"></i>บันทึกที่อยู่
                </button>
            </form>
            
            <?php if ($order['shipping_tracking']): ?>
            <div class="mt-4 p-4 bg-purple-50 rounded-lg">
                <p class="text-sm text-purple-600"><i class="fas fa-truck mr-2"></i>เลขพัสดุ: <strong><?= htmlspecialchars($order['shipping_tracking']) ?></strong></p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Sidebar -->
    <div class="space-y-6">
        <!-- Quick Actions -->
        <div class="bg-white rounded-xl shadow p-6">
            <h4 class="font-semibold mb-4">⚡ Quick Actions</h4>
            
            <!-- Status Flow -->
            <div class="space-y-2 mb-6">
                <?php if ($order['status'] === 'pending'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="confirmed">
                    <button type="submit" class="w-full py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium">
                        <i class="fas fa-check mr-2"></i>ยืนยันออเดอร์
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if (in_array($order['status'], ['confirmed', 'pending']) && $order['payment_status'] !== 'paid'): ?>
                <div class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-center">
                    <i class="fas fa-clock text-yellow-500 mr-1"></i>
                    <span class="text-yellow-700 text-sm">รอลูกค้าชำระเงิน</span>
                </div>
                <?php endif; ?>
                
                <?php if ($order['payment_status'] === 'paid' && $order['status'] !== 'shipping' && $order['status'] !== 'delivered'): ?>
                <form method="POST" id="trackingForm">
                    <input type="hidden" name="action" value="add_tracking">
                    <div class="mb-2">
                        <input type="text" name="tracking" required placeholder="กรอกเลขพัสดุ เช่น TH123456789" 
                               class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    <button type="submit" class="w-full py-3 bg-purple-500 text-white rounded-lg hover:bg-purple-600 font-medium">
                        <i class="fas fa-truck mr-2"></i>ส่งเลขพัสดุ
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($order['status'] === 'shipping'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="delivered">
                    <button type="submit" class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                        <i class="fas fa-box-open mr-2"></i>ยืนยันส่งถึงแล้ว
                    </button>
                </form>
                <?php endif; ?>
                
                <?php if ($order['status'] === 'delivered'): ?>
                <div class="p-4 bg-green-50 border border-green-200 rounded-lg text-center">
                    <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
                    <p class="text-green-700 font-medium">ออเดอร์เสร็จสมบูรณ์</p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Tracking Info -->
            <?php if ($order['shipping_tracking']): ?>
            <div class="p-3 bg-purple-50 border border-purple-200 rounded-lg mb-4">
                <p class="text-sm text-purple-700">
                    <i class="fas fa-truck mr-1"></i>เลขพัสดุ: 
                    <strong class="font-mono"><?= htmlspecialchars($order['shipping_tracking']) ?></strong>
                </p>
            </div>
            <?php endif; ?>
            
            <!-- Cancel Order -->
            <?php if (!in_array($order['status'], ['delivered', 'cancelled'])): ?>
            <form method="POST" class="mt-4 pt-4 border-t">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="status" value="cancelled">
                <button type="submit" onclick="return confirm('ยกเลิกออเดอร์นี้?')" 
                        class="w-full py-2 border border-red-300 text-red-500 rounded-lg hover:bg-red-50">
                    <i class="fas fa-times mr-2"></i>ยกเลิกออเดอร์
                </button>
            </form>
            <?php endif; ?>
        </div>
        
        <!-- Manual Status Change -->
        <div class="bg-white rounded-xl shadow p-6">
            <h4 class="font-semibold mb-4">🔧 เปลี่ยนสถานะ (Manual)</h4>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="update_status">
                <select name="status" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-500">
                    <option value="pending" <?= $order['status'] === 'pending' ? 'selected' : '' ?>>⏳ รอยืนยัน</option>
                    <option value="confirmed" <?= $order['status'] === 'confirmed' ? 'selected' : '' ?>>✅ ยืนยันแล้ว</option>
                    <option value="paid" <?= $order['status'] === 'paid' ? 'selected' : '' ?>>💰 ชำระแล้ว</option>
                    <option value="shipping" <?= $order['status'] === 'shipping' ? 'selected' : '' ?>>🚚 กำลังจัดส่ง</option>
                    <option value="delivered" <?= $order['status'] === 'delivered' ? 'selected' : '' ?>>📦 จัดส่งแล้ว</option>
                    <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>❌ ยกเลิก</option>
                </select>
                <button type="submit" class="w-full py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600">
                    <i class="fas fa-save mr-2"></i>อัพเดท
                </button>
            </form>
        </div>
        
        <!-- Payment Slips -->
        <div class="bg-white rounded-xl shadow p-6">
            <h4 class="font-semibold mb-4">💳 หลักฐานการชำระเงิน</h4>
            
            <!-- Payment Status -->
            <div class="mb-4 p-3 rounded-lg <?= $order['payment_status'] === 'paid' ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200' ?>">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-medium">สถานะการชำระ:</span>
                    <span class="px-3 py-1 rounded-full text-sm <?= $order['payment_status'] === 'paid' ? 'bg-green-500 text-white' : 'bg-yellow-500 text-white' ?>">
                        <?= $order['payment_status'] === 'paid' ? '✅ ชำระแล้ว' : '⏳ รอชำระ' ?>
                    </span>
                </div>
            </div>
            
            <?php if (empty($slips)): ?>
            <div class="text-center py-6 bg-gray-50 rounded-lg">
                <i class="fas fa-receipt text-4xl text-gray-300 mb-2"></i>
                <p class="text-gray-500">ยังไม่มีหลักฐานการชำระเงิน</p>
            </div>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($slips as $index => $slip): ?>
                <div class="border-2 rounded-xl overflow-hidden <?= $slip['status'] === 'approved' ? 'border-green-300' : ($slip['status'] === 'rejected' ? 'border-red-300' : 'border-yellow-300') ?>">
                    <!-- Slip Image -->
                    <div class="relative bg-gray-100">
                        <img src="<?= htmlspecialchars($slip['image_url']) ?>" 
                             class="w-full max-h-64 object-contain cursor-pointer hover:opacity-90 transition" 
                             onclick="openSlipModal('<?= htmlspecialchars($slip['image_url']) ?>')">
                        <div class="absolute top-2 right-2">
                            <span class="px-3 py-1 rounded-full text-sm font-medium shadow <?= $slip['status'] === 'approved' ? 'bg-green-500 text-white' : ($slip['status'] === 'rejected' ? 'bg-red-500 text-white' : 'bg-yellow-500 text-white') ?>">
                                <?= $slip['status'] === 'approved' ? '✅ อนุมัติแล้ว' : ($slip['status'] === 'rejected' ? '❌ ปฏิเสธ' : '⏳ รอตรวจสอบ') ?>
                            </span>
                        </div>
                        <button onclick="openSlipModal('<?= htmlspecialchars($slip['image_url']) ?>')" 
                                class="absolute bottom-2 right-2 px-3 py-1 bg-black/50 text-white rounded-lg text-sm hover:bg-black/70">
                            <i class="fas fa-expand mr-1"></i>ขยาย
                        </button>
                    </div>
                    
                    <!-- Slip Info -->
                    <div class="p-3 bg-white">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500">
                                <i class="fas fa-clock mr-1"></i><?= date('d/m/Y H:i', strtotime($slip['created_at'])) ?>
                            </span>
                            <?php if ($slip['amount']): ?>
                            <span class="font-medium text-green-600">฿<?= number_format($slip['amount'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($slip['admin_note']): ?>
                        <p class="text-xs text-gray-500 mt-1"><i class="fas fa-sticky-note mr-1"></i><?= htmlspecialchars($slip['admin_note']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Action Buttons -->
            <?php if ($order['payment_status'] !== 'paid'): ?>
            <div class="mt-4 grid grid-cols-2 gap-2">
                <form method="POST">
                    <input type="hidden" name="action" value="approve_payment">
                    <button type="submit" onclick="return confirm('ยืนยันการชำระเงิน?')" 
                            class="w-full py-3 bg-green-500 text-white rounded-lg hover:bg-green-600 font-medium">
                        <i class="fas fa-check-circle mr-2"></i>อนุมัติ
                    </button>
                </form>
                <form method="POST">
                    <input type="hidden" name="action" value="reject_payment">
                    <button type="submit" onclick="return confirm('ปฏิเสธหลักฐานนี้?')" 
                            class="w-full py-3 bg-red-500 text-white rounded-lg hover:bg-red-600 font-medium">
                        <i class="fas fa-times-circle mr-2"></i>ปฏิเสธ
                    </button>
                </form>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Note -->
        <?php if ($order['note']): ?>
        <div class="bg-white rounded-xl shadow p-6">
            <h4 class="font-semibold mb-2">หมายเหตุ</h4>
            <p class="text-gray-600"><?= nl2br(htmlspecialchars($order['note'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Slip Modal -->
<div id="slipModal" class="fixed inset-0 bg-black/90 z-50 hidden items-center justify-center p-4">
    <button onclick="closeSlipModal()" class="absolute top-4 right-4 text-white text-3xl hover:text-gray-300">
        <i class="fas fa-times"></i>
    </button>
    <img id="slipModalImage" src="" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl">
</div>

<script>
function openSlipModal(imageUrl) {
    document.getElementById('slipModalImage').src = imageUrl;
    document.getElementById('slipModal').classList.remove('hidden');
    document.getElementById('slipModal').classList.add('flex');
}

function closeSlipModal() {
    document.getElementById('slipModal').classList.add('hidden');
    document.getElementById('slipModal').classList.remove('flex');
}

// Close modal on click outside
document.getElementById('slipModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeSlipModal();
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeSlipModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
