<?php
/**
 * Test User Notifications
 * ทดสอบระบบแจ้งเตือนผู้ใช้ (Price Drop, Restock)
 */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/LineAPI.php';

$db = Database::getInstance()->getConnection();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test User Notifications</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">🔔 Test User Notifications</h1>
        
        <?php
        // Handle test actions
        $message = '';
        $error = '';
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'test_price_drop') {
                // Test price drop notification
                $lineUserId = $_POST['line_user_id'] ?? '';
                $productId = $_POST['product_id'] ?? 0;
                $lineAccountId = $_POST['line_account_id'] ?? 1;
                
                if ($lineUserId && $productId) {
                    // Get product and user info
                    $stmt = $db->prepare("SELECT * FROM business_items WHERE id = ?");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
                    $stmt->execute([$lineAccountId]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product && $account) {
                        $currentPrice = $product['sale_price'] ?: $product['price'];
                        $oldPrice = $currentPrice * 1.2; // Simulate 20% discount
                        $discount = 20;
                        
                        $flexMessage = [
                            'type' => 'flex',
                            'altText' => "🔥 {$product['name']} ลดราคา {$discount}%!",
                            'contents' => [
                                'type' => 'bubble',
                                'size' => 'mega',
                                'hero' => [
                                    'type' => 'image',
                                    'url' => $product['image_url'] ?: 'https://via.placeholder.com/400x300?text=No+Image',
                                    'size' => 'full',
                                    'aspectRatio' => '4:3',
                                    'aspectMode' => 'cover'
                                ],
                                'body' => [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        [
                                            'type' => 'text',
                                            'text' => '🔥 [ทดสอบ] ลดราคาแล้ว!',
                                            'weight' => 'bold',
                                            'color' => '#EF4444',
                                            'size' => 'sm'
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => $product['name'],
                                            'weight' => 'bold',
                                            'size' => 'lg',
                                            'wrap' => true,
                                            'margin' => 'md'
                                        ],
                                        [
                                            'type' => 'box',
                                            'layout' => 'horizontal',
                                            'margin' => 'lg',
                                            'contents' => [
                                                [
                                                    'type' => 'text',
                                                    'text' => '฿' . number_format($currentPrice),
                                                    'weight' => 'bold',
                                                    'size' => 'xl',
                                                    'color' => '#EF4444'
                                                ],
                                                [
                                                    'type' => 'text',
                                                    'text' => '฿' . number_format($oldPrice),
                                                    'size' => 'md',
                                                    'color' => '#AAAAAA',
                                                    'decoration' => 'line-through',
                                                    'align' => 'end',
                                                    'gravity' => 'bottom'
                                                ]
                                            ]
                                        ]
                                    ]
                                ],
                                'footer' => [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        [
                                            'type' => 'button',
                                            'action' => [
                                                'type' => 'uri',
                                                'label' => '🛒 ซื้อเลย',
                                                'uri' => rtrim(BASE_URL, '/') . "/liff-product-detail.php?id={$productId}"
                                            ],
                                            'style' => 'primary',
                                            'color' => '#EF4444'
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        
                        $line = new LineAPI($account['channel_access_token']);
                        $result = $line->pushMessage($lineUserId, [$flexMessage]);
                        
                        if ($result) {
                            $message = "✅ ส่งแจ้งเตือนลดราคาสำเร็จ!";
                        } else {
                            $error = "❌ ส่งไม่สำเร็จ";
                        }
                    } else {
                        $error = "❌ ไม่พบสินค้าหรือ LINE Account";
                    }
                } else {
                    $error = "❌ กรุณากรอกข้อมูลให้ครบ";
                }
            }
            
            if ($action === 'test_restock') {
                // Test restock notification
                $lineUserId = $_POST['line_user_id'] ?? '';
                $productId = $_POST['product_id'] ?? 0;
                $lineAccountId = $_POST['line_account_id'] ?? 1;
                
                if ($lineUserId && $productId) {
                    $stmt = $db->prepare("SELECT * FROM business_items WHERE id = ?");
                    $stmt->execute([$productId]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $db->prepare("SELECT channel_access_token FROM line_accounts WHERE id = ?");
                    $stmt->execute([$lineAccountId]);
                    $account = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product && $account) {
                        $currentPrice = $product['sale_price'] ?: $product['price'];
                        $stock = $product['stock'] ?: 10;
                        
                        $flexMessage = [
                            'type' => 'flex',
                            'altText' => "📦 {$product['name']} กลับมามีสต็อกแล้ว!",
                            'contents' => [
                                'type' => 'bubble',
                                'size' => 'mega',
                                'hero' => [
                                    'type' => 'image',
                                    'url' => $product['image_url'] ?: 'https://via.placeholder.com/400x300?text=No+Image',
                                    'size' => 'full',
                                    'aspectRatio' => '4:3',
                                    'aspectMode' => 'cover'
                                ],
                                'body' => [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        [
                                            'type' => 'text',
                                            'text' => '📦 [ทดสอบ] สินค้าเข้าแล้ว!',
                                            'weight' => 'bold',
                                            'color' => '#10B981',
                                            'size' => 'sm'
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => $product['name'],
                                            'weight' => 'bold',
                                            'size' => 'lg',
                                            'wrap' => true,
                                            'margin' => 'md'
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => "เหลือ {$stock} ชิ้น",
                                            'size' => 'sm',
                                            'color' => '#10B981',
                                            'margin' => 'md'
                                        ],
                                        [
                                            'type' => 'text',
                                            'text' => '฿' . number_format($currentPrice),
                                            'weight' => 'bold',
                                            'size' => 'xl',
                                            'color' => '#11B0A6',
                                            'margin' => 'lg'
                                        ]
                                    ]
                                ],
                                'footer' => [
                                    'type' => 'box',
                                    'layout' => 'vertical',
                                    'contents' => [
                                        [
                                            'type' => 'button',
                                            'action' => [
                                                'type' => 'uri',
                                                'label' => '🛒 ซื้อเลย',
                                                'uri' => rtrim(BASE_URL, '/') . "/liff-product-detail.php?id={$productId}"
                                            ],
                                            'style' => 'primary',
                                            'color' => '#10B981'
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        
                        $line = new LineAPI($account['channel_access_token']);
                        $result = $line->pushMessage($lineUserId, [$flexMessage]);
                        
                        if ($result) {
                            $message = "✅ ส่งแจ้งเตือนสินค้าเข้าสำเร็จ!";
                        } else {
                            $error = "❌ ส่งไม่สำเร็จ";
                        }
                    } else {
                        $error = "❌ ไม่พบสินค้าหรือ LINE Account";
                    }
                } else {
                    $error = "❌ กรุณากรอกข้อมูลให้ครบ";
                }
            }
        }
        
        // Get LINE accounts
        $accounts = $db->query("SELECT id, name FROM line_accounts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        
        // Get some products
        $products = $db->query("SELECT id, name, price, sale_price FROM business_items WHERE is_active = 1 ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        
        // Get users with wishlist
        $usersWithWishlist = $db->query("
            SELECT DISTINCT u.id, u.display_name, u.line_user_id, COUNT(w.id) as wishlist_count
            FROM users u
            JOIN user_wishlist w ON u.id = w.user_id
            GROUP BY u.id
            ORDER BY wishlist_count DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        
        <?php if ($message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?= $message ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?= $error ?>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Test Price Drop -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold mb-4 text-red-600">🔥 ทดสอบแจ้งเตือนลดราคา</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="test_price_drop">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">LINE Account</label>
                        <select name="line_account_id" class="w-full border rounded px-3 py-2">
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">LINE User ID</label>
                        <input type="text" name="line_user_id" class="w-full border rounded px-3 py-2" 
                               placeholder="U..." required>
                        <?php if (!empty($usersWithWishlist)): ?>
                        <p class="text-xs text-gray-500 mt-1">
                            ผู้ใช้ที่มี wishlist: 
                            <?php foreach (array_slice($usersWithWishlist, 0, 3) as $u): ?>
                            <button type="button" onclick="document.querySelector('[name=line_user_id]').value='<?= $u['line_user_id'] ?>'" 
                                    class="text-blue-500 hover:underline"><?= htmlspecialchars($u['display_name']) ?></button>
                            <?php endforeach; ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">สินค้า</label>
                        <select name="product_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">-- เลือกสินค้า --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['name']) ?> - ฿<?= number_format($p['sale_price'] ?: $p['price']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="w-full bg-red-500 text-white py-2 rounded hover:bg-red-600">
                        📤 ส่งทดสอบ
                    </button>
                </form>
            </div>
            
            <!-- Test Restock -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold mb-4 text-green-600">📦 ทดสอบแจ้งเตือนสินค้าเข้า</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="test_restock">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">LINE Account</label>
                        <select name="line_account_id" class="w-full border rounded px-3 py-2">
                            <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>"><?= htmlspecialchars($acc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">LINE User ID</label>
                        <input type="text" name="line_user_id" class="w-full border rounded px-3 py-2" 
                               placeholder="U..." required>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium mb-1">สินค้า</label>
                        <select name="product_id" class="w-full border rounded px-3 py-2" required>
                            <option value="">-- เลือกสินค้า --</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>">
                                <?= htmlspecialchars($p['name']) ?> - ฿<?= number_format($p['sale_price'] ?: $p['price']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="w-full bg-green-500 text-white py-2 rounded hover:bg-green-600">
                        📤 ส่งทดสอบ
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Wishlist Stats -->
        <div class="bg-white rounded-lg shadow p-6 mt-6">
            <h2 class="text-lg font-semibold mb-4">📊 สถิติ Wishlist</h2>
            
            <?php
            // Get wishlist stats
            $totalWishlist = $db->query("SELECT COUNT(*) FROM user_wishlist")->fetchColumn();
            $usersWithWishlistCount = $db->query("SELECT COUNT(DISTINCT user_id) FROM user_wishlist")->fetchColumn();
            $notifyOnSale = $db->query("SELECT COUNT(*) FROM user_wishlist WHERE notify_on_sale = 1")->fetchColumn();
            $notifyOnRestock = $db->query("SELECT COUNT(*) FROM user_wishlist WHERE notify_on_restock = 1")->fetchColumn();
            ?>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-4 bg-gray-50 rounded">
                    <div class="text-2xl font-bold text-blue-600"><?= number_format($totalWishlist) ?></div>
                    <div class="text-sm text-gray-500">รายการโปรดทั้งหมด</div>
                </div>
                <div class="text-center p-4 bg-gray-50 rounded">
                    <div class="text-2xl font-bold text-purple-600"><?= number_format($usersWithWishlistCount) ?></div>
                    <div class="text-sm text-gray-500">ผู้ใช้ที่มี wishlist</div>
                </div>
                <div class="text-center p-4 bg-gray-50 rounded">
                    <div class="text-2xl font-bold text-red-600"><?= number_format($notifyOnSale) ?></div>
                    <div class="text-sm text-gray-500">เปิดแจ้งเตือนลดราคา</div>
                </div>
                <div class="text-center p-4 bg-gray-50 rounded">
                    <div class="text-2xl font-bold text-green-600"><?= number_format($notifyOnRestock) ?></div>
                    <div class="text-sm text-gray-500">เปิดแจ้งเตือนสินค้าเข้า</div>
                </div>
            </div>
        </div>
        
        <!-- Users with Wishlist -->
        <?php if (!empty($usersWithWishlist)): ?>
        <div class="bg-white rounded-lg shadow p-6 mt-6">
            <h2 class="text-lg font-semibold mb-4">👥 ผู้ใช้ที่มี Wishlist</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50">
                            <th class="px-4 py-2 text-left">ชื่อ</th>
                            <th class="px-4 py-2 text-left">LINE User ID</th>
                            <th class="px-4 py-2 text-center">จำนวน Wishlist</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usersWithWishlist as $u): ?>
                        <tr class="border-t">
                            <td class="px-4 py-2"><?= htmlspecialchars($u['display_name']) ?></td>
                            <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($u['line_user_id']) ?></td>
                            <td class="px-4 py-2 text-center"><?= $u['wishlist_count'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Cron Commands -->
        <div class="bg-white rounded-lg shadow p-6 mt-6">
            <h2 class="text-lg font-semibold mb-4">⏰ Cron Commands</h2>
            <p class="text-sm text-gray-600 mb-4">ตั้ง cron job เพื่อส่งแจ้งเตือนอัตโนมัติ:</p>
            
            <div class="space-y-3">
                <div class="bg-gray-100 p-3 rounded font-mono text-sm">
                    <p class="text-gray-500 text-xs mb-1"># แจ้งเตือนลดราคา (ทุกชั่วโมง)</p>
                    <code>0 * * * * php <?= __DIR__ ?>/../cron/wishlist_notification.php</code>
                </div>
                
                <div class="bg-gray-100 p-3 rounded font-mono text-sm">
                    <p class="text-gray-500 text-xs mb-1"># แจ้งเตือนสินค้าเข้า (ทุกชั่วโมง)</p>
                    <code>0 * * * * php <?= __DIR__ ?>/../cron/restock_notification.php</code>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
