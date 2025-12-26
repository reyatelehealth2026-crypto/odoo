<?php
/**
 * LIFF Shop V3 - Marketplace Style (Lazada/Shopee)
 * Features:
 * - Search bar with button
 * - Quick menu icons
 * - Flash Sale with countdown
 * - Product grid with sale badges, sold count, ratings
 * - Bottom navigation
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Get params
$userId = $_GET['user'] ?? null;
$lineAccountId = $_GET['account'] ?? null;
$filterCategory = $_GET['category'] ?? null;
$filterSearch = trim($_GET['search'] ?? '');

// Get line_account_id from user
if (!$lineAccountId && $userId && strpos($userId, 'U') === 0) {
    try {
        $stmt = $db->prepare("SELECT line_account_id FROM users WHERE line_user_id = ?");
        $stmt->execute([$userId]);
        $lineAccountId = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

require_once 'includes/liff-helper.php';
$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
if (!$lineAccountId) $lineAccountId = $liffData['line_account_id'];

// Get shop settings
$shopSettings = [];
try {
    $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$shopName = $shopSettings['shop_name'] ?? 'ร้านค้าออนไลน์';
$shopLogo = $shopSettings['shop_logo'] ?? '';

// Get promotion settings
function getPromoSetting($db, $lineAccountId, $key, $default = null) {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM promotion_settings WHERE line_account_id = ? AND setting_key = ?");
        $stmt->execute([$lineAccountId, $key]);
        $value = $stmt->fetchColumn();
        if ($value === false) return $default;
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : $value;
    } catch (Exception $e) { return $default; }
}

$promoSettings = [
    'primary_color' => getPromoSetting($db, $lineAccountId, 'primary_color', '#F85606'),
    'secondary_color' => getPromoSetting($db, $lineAccountId, 'secondary_color', '#FFE4D6'),
    'sale_badge_color' => getPromoSetting($db, $lineAccountId, 'sale_badge_color', '#EE4D2D'),
    'bestseller_badge_color' => getPromoSetting($db, $lineAccountId, 'bestseller_badge_color', '#FFAA00'),
    'show_flash_sale' => getPromoSetting($db, $lineAccountId, 'show_flash_sale', '1'),
    'show_quick_menu' => getPromoSetting($db, $lineAccountId, 'show_quick_menu', '1'),
    'show_sold_count' => getPromoSetting($db, $lineAccountId, 'show_sold_count', '1'),
    'show_rating' => getPromoSetting($db, $lineAccountId, 'show_rating', '1'),
];

// Get categories for quick menu
$categories = [];
try {
    $catTable = 'item_categories';
    try { $db->query("SELECT 1 FROM item_categories LIMIT 1"); } 
    catch (Exception $e) { $catTable = 'business_categories'; }
    $stmt = $db->query("SELECT * FROM $catTable WHERE is_active = 1 ORDER BY sort_order, id LIMIT 10");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Category icons
$categoryIcons = [
    'default' => '📦', 'VIT' => '💊', 'HER' => '🌿', 'SKI' => '🧴', 'COS' => '💄',
    'FMC' => '🛒', 'MSJ' => '💪', 'RIS' => '🫁', 'GIS' => '🍽️', 'CNS' => '🧠'
];

// Get Flash Sale products (products with sale_price)
$flashSaleProducts = [];
try {
    $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, 
                   COALESCE(sold_count, FLOOR(RAND() * 500 + 100)) as sold_count
            FROM business_items 
            WHERE is_active = 1 AND sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price
            ORDER BY (price - sale_price) / price DESC
            LIMIT 10";
    $flashSaleProducts = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get "Just For You" products
$recommendedProducts = [];
try {
    $where = "is_active = 1";
    $params = [];
    
    if ($filterCategory) {
        $where .= " AND category_id = ?";
        $params[] = $filterCategory;
    }
    if ($filterSearch) {
        $where .= " AND (name LIKE ? OR sku LIKE ?)";
        $params[] = "%{$filterSearch}%";
        $params[] = "%{$filterSearch}%";
    }
    
    $sql = "SELECT id, name, sku, price, sale_price, stock, image_url, category_id,
                   COALESCE(sold_count, FLOOR(RAND() * 1000 + 50)) as sold_count,
                   COALESCE(rating, ROUND(3.5 + RAND() * 1.5, 1)) as rating,
                   COALESCE(review_count, FLOOR(RAND() * 500 + 10)) as review_count
            FROM business_items WHERE $where
            ORDER BY RAND()
            LIMIT 20";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $recommendedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$baseUrl = rtrim(BASE_URL, '/');
$primaryColor = $promoSettings['primary_color'];
$secondaryColor = $promoSettings['secondary_color'];
$saleColor = $promoSettings['sale_badge_color'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($shopName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?= $primaryColor ?>;
            --secondary: <?= $secondaryColor ?>;
            --sale: <?= $saleColor ?>;
        }
        * { -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Sarabun', -apple-system, sans-serif; background: #f5f5f5; padding-bottom: 70px; }
        
        /* Search Bar */
        .search-bar { background: linear-gradient(135deg, var(--primary), #ff7043); padding: 12px 16px; }
        .search-input { background: white; border-radius: 8px; padding: 10px 16px; width: 100%; display: flex; align-items: center; gap: 8px; }
        .search-input input { flex: 1; border: none; outline: none; font-size: 14px; }
        .search-btn { background: var(--primary); color: white; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 13px; }
        
        /* Quick Menu */
        .quick-menu { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; padding: 16px; background: white; }
        .menu-item { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .menu-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; background: var(--secondary); }
        .menu-label { font-size: 11px; color: #666; text-align: center; line-height: 1.2; }
        
        /* Flash Sale */
        .flash-sale { background: linear-gradient(135deg, var(--sale), #ff6b6b); padding: 12px 16px; margin: 8px 0; }
        .flash-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .flash-title { color: white; font-weight: bold; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .flash-title span { background: white; color: var(--sale); padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .countdown { display: flex; gap: 4px; }
        .countdown-item { background: #1a1a1a; color: white; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 14px; min-width: 32px; text-align: center; }
        .flash-scroll { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 8px; scroll-snap-type: x mandatory; }
        .flash-scroll::-webkit-scrollbar { display: none; }
        .flash-card { flex-shrink: 0; width: 120px; background: white; border-radius: 8px; overflow: hidden; scroll-snap-align: start; }
        .flash-img { position: relative; aspect-ratio: 1; background: #f5f5f5; }
        .flash-img img { width: 100%; height: 100%; object-fit: cover; }
        .save-badge { position: absolute; top: 0; left: 0; background: var(--sale); color: white; padding: 2px 8px; font-size: 10px; font-weight: bold; border-radius: 0 0 8px 0; }
        .flash-info { padding: 8px; }
        .flash-price { color: var(--sale); font-weight: bold; font-size: 14px; }
        .flash-original { color: #999; font-size: 11px; text-decoration: line-through; }
        .sold-bar { height: 16px; background: #ffe0e0; border-radius: 8px; margin-top: 6px; position: relative; overflow: hidden; }
        .sold-fill { height: 100%; background: linear-gradient(90deg, var(--sale), #ff8a80); border-radius: 8px; }
        .sold-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 10px; color: white; font-weight: 600; text-shadow: 0 1px 2px rgba(0,0,0,0.3); }
        
        /* Product Grid */
        .section-title { padding: 16px; font-weight: bold; font-size: 16px; background: white; border-bottom: 1px solid #eee; }
        .product-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 8px; }
        .product-card { background: white; border-radius: 8px; overflow: hidden; }
        .product-img { position: relative; aspect-ratio: 1; background: #f5f5f5; }
        .product-img img { width: 100%; height: 100%; object-fit: cover; }
        .product-badge { position: absolute; top: 8px; left: 8px; }
        .badge-choice { background: #1a1a1a; color: #ffaa00; padding: 2px 6px; font-size: 9px; font-weight: bold; border-radius: 2px; }
        .product-info { padding: 10px; }
        .product-name { font-size: 12px; color: #333; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 34px; }
        .product-price { color: var(--primary); font-weight: bold; font-size: 16px; margin-top: 6px; }
        .product-original { color: #999; font-size: 12px; text-decoration: line-through; margin-left: 4px; }
        .product-meta { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-size: 11px; color: #999; }
        .product-rating { color: #ffaa00; }
        
        /* Bottom Nav */
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: white; display: flex; justify-content: space-around; padding: 8px 0; padding-bottom: max(8px, env(safe-area-inset-bottom)); box-shadow: 0 -2px 10px rgba(0,0,0,0.1); z-index: 100; }
        .nav-item { display: flex; flex-direction: column; align-items: center; gap: 2px; color: #999; font-size: 10px; cursor: pointer; position: relative; }
        .nav-item.active { color: var(--primary); }
        .nav-item i { font-size: 20px; }
        .nav-badge { position: absolute; top: -4px; right: -8px; background: var(--sale); color: white; font-size: 10px; min-width: 16px; height: 16px; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
    </style>
</head>
<body>

    <!-- Search Bar -->
    <div class="search-bar">
        <form action="" method="GET" class="search-input">
            <input type="hidden" name="user" value="<?= htmlspecialchars($userId ?? '') ?>">
            <input type="hidden" name="account" value="<?= htmlspecialchars($lineAccountId ?? '') ?>">
            <i class="fas fa-search text-gray-400"></i>
            <input type="text" name="search" placeholder="ค้นหาสินค้า..." value="<?= htmlspecialchars($filterSearch) ?>">
            <button type="submit" class="search-btn">ค้นหา</button>
        </form>
    </div>

    <?php if ($promoSettings['show_quick_menu'] == '1' && !empty($categories)): ?>
    <!-- Quick Menu -->
    <div class="quick-menu">
        <?php foreach (array_slice($categories, 0, 10) as $cat): 
            $code = $cat['cny_code'] ?? '';
            $icon = $categoryIcons[$code] ?? $categoryIcons['default'];
            $name = $cat['name'];
            if (strpos($name, '-') !== false) {
                $parts = explode('-', $name, 2);
                $name = $parts[1] ?? $name;
            }
        ?>
        <a href="?user=<?= $userId ?>&account=<?= $lineAccountId ?>&category=<?= $cat['id'] ?>" class="menu-item">
            <div class="menu-icon"><?= $icon ?></div>
            <span class="menu-label"><?= mb_substr(htmlspecialchars($name), 0, 8) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($promoSettings['show_flash_sale'] == '1' && !empty($flashSaleProducts) && !$filterSearch && !$filterCategory): ?>
    <!-- Flash Sale Section -->
    <div class="flash-sale">
        <div class="flash-header">
            <div class="flash-title">
                <i class="fas fa-bolt"></i>
                Flash Sale
                <span>HOT</span>
            </div>
            <div class="countdown">
                <div class="countdown-item" id="hours">02</div>
                <div class="countdown-item">:</div>
                <div class="countdown-item" id="minutes">18</div>
                <div class="countdown-item">:</div>
                <div class="countdown-item" id="seconds">31</div>
            </div>
        </div>
        
        <div class="flash-scroll">
            <?php foreach ($flashSaleProducts as $p): 
                $discount = round((($p['price'] - $p['sale_price']) / $p['price']) * 100);
                $soldPercent = min(95, rand(50, 95));
            ?>
            <div class="flash-card" onclick="showProduct(<?= $p['id'] ?>)">
                <div class="flash-img">
                    <div class="save-badge">SAVE <?= $discount ?>%</div>
                    <?php if ($p['image_url']): ?>
                    <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy" alt="">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center"><i class="fas fa-image text-gray-300 text-2xl"></i></div>
                    <?php endif; ?>
                </div>
                <div class="flash-info">
                    <div class="flash-price">฿<?= number_format($p['sale_price']) ?></div>
                    <div class="flash-original">฿<?= number_format($p['price']) ?></div>
                    <div class="sold-bar">
                        <div class="sold-fill" style="width: <?= $soldPercent ?>%"></div>
                        <div class="sold-text"><?= $p['sold_count'] ?> sold</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Products Section -->
    <div class="section-title">
        <?php if ($filterSearch): ?>
        🔍 ผลการค้นหา "<?= htmlspecialchars($filterSearch) ?>"
        <?php elseif ($filterCategory): ?>
        📦 สินค้าในหมวดหมู่
        <?php else: ?>
        ✨ Just For You
        <?php endif; ?>
    </div>
    
    <div class="product-grid">
        <?php if (empty($recommendedProducts)): ?>
        <div class="col-span-2 text-center py-12 text-gray-400">
            <i class="fas fa-box-open text-4xl mb-3"></i>
            <p>ไม่พบสินค้า</p>
        </div>
        <?php else: ?>
        <?php foreach ($recommendedProducts as $p): 
            $hasDiscount = $p['sale_price'] && $p['sale_price'] < $p['price'];
            $displayPrice = $hasDiscount ? $p['sale_price'] : $p['price'];
            $rating = number_format($p['rating'] ?? 4.5, 1);
            $reviewCount = $p['review_count'] ?? rand(100, 5000);
        ?>
        <div class="product-card" onclick="showProduct(<?= $p['id'] ?>)">
            <div class="product-img">
                <?php if ($hasDiscount): ?>
                <div class="product-badge">
                    <span class="badge-choice">CHOICE</span>
                </div>
                <?php endif; ?>
                <?php if ($p['image_url']): ?>
                <img src="<?= htmlspecialchars($p['image_url']) ?>" loading="lazy" alt="">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center bg-gray-100"><i class="fas fa-image text-gray-300 text-3xl"></i></div>
                <?php endif; ?>
            </div>
            <div class="product-info">
                <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="flex items-baseline">
                    <span class="product-price">฿<?= number_format($displayPrice) ?></span>
                    <?php if ($hasDiscount): ?>
                    <span class="product-original">฿<?= number_format($p['price']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="product-meta">
                    <?php if ($promoSettings['show_rating'] == '1'): ?>
                    <span class="product-rating"><i class="fas fa-star"></i> <?= $rating ?></span>
                    <span>(<?= number_format($reviewCount) ?>)</span>
                    <?php endif; ?>
                    <?php if ($promoSettings['show_sold_count'] == '1'): ?>
                    <span><?= number_format($p['sold_count'] ?? 0) ?> sold</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="?user=<?= $userId ?>&account=<?= $lineAccountId ?>" class="nav-item active">
            <i class="fas fa-home"></i>
            <span>Home</span>
        </a>
        <a href="#" class="nav-item" onclick="showCategories()">
            <i class="fas fa-th-large"></i>
            <span>Categories</span>
        </a>
        <a href="#" class="nav-item" onclick="openCart()">
            <i class="fas fa-shopping-cart"></i>
            <span id="cartBadge" class="nav-badge hidden">0</span>
            <span>Cart</span>
        </a>
        <a href="#" class="nav-item" onclick="openAccount()">
            <i class="fas fa-user"></i>
            <span>Account</span>
        </a>
    </nav>

    <!-- Product Modal -->
    <div id="productModal" class="fixed inset-0 bg-black/60 z-50 hidden">
        <div class="min-h-screen flex items-end justify-center">
            <div class="bg-white w-full rounded-t-3xl max-h-[90vh] overflow-hidden flex flex-col">
                <div class="relative flex-shrink-0">
                    <button onclick="closeModal()" class="absolute top-4 right-4 z-20 w-10 h-10 bg-black/40 rounded-full flex items-center justify-center text-white">
                        <i class="fas fa-times"></i>
                    </button>
                    <div id="modalImage" class="aspect-square bg-gray-100"></div>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <h2 id="modalName" class="font-bold text-lg text-gray-800 mb-1"></h2>
                    <p id="modalSku" class="text-sm text-gray-400 mb-3"></p>
                    <div class="flex items-center gap-3 mb-4">
                        <span id="modalPrice" class="text-2xl font-bold" style="color: var(--primary)"></span>
                        <span id="modalOriginalPrice" class="text-gray-400 line-through hidden"></span>
                        <span id="modalDiscount" class="px-2 py-0.5 text-white text-xs font-bold rounded hidden" style="background: var(--sale)"></span>
                    </div>
                    <div id="modalStock" class="flex items-center gap-2 text-sm text-gray-500 mb-4"></div>
                    <div id="modalDescSection" class="hidden mb-4">
                        <h3 class="font-bold text-gray-700 mb-2"><i class="fas fa-pills mr-2" style="color: var(--primary)"></i>สรรพคุณ</h3>
                        <p id="modalDesc" class="text-sm text-gray-600"></p>
                    </div>
                </div>
                <div class="flex-shrink-0 border-t p-4 flex items-center gap-3">
                    <div class="flex items-center border-2 rounded-xl overflow-hidden">
                        <button onclick="changeQty(-1)" class="w-10 h-10 flex items-center justify-center"><i class="fas fa-minus"></i></button>
                        <span id="modalQty" class="w-10 text-center font-bold">1</span>
                        <button onclick="changeQty(1)" class="w-10 h-10 flex items-center justify-center"><i class="fas fa-plus"></i></button>
                    </div>
                    <button onclick="addToCart()" id="addToCartBtn" class="flex-1 py-3 text-white rounded-xl font-bold" style="background: var(--primary)">
                        <i class="fas fa-cart-plus mr-2"></i>เพิ่มลงตะกร้า
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    const userId = '<?= $userId ?>';
    const lineAccountId = '<?= $lineAccountId ?>';
    const baseUrl = '<?= $baseUrl ?>';
    let currentProduct = null;
    let cart = JSON.parse(localStorage.getItem('cart_' + lineAccountId) || '[]');
    
    updateCartBadge();
    
    // Countdown timer
    function updateCountdown() {
        const now = new Date();
        const endOfDay = new Date();
        endOfDay.setHours(23, 59, 59, 999);
        const diff = endOfDay - now;
        
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        document.getElementById('hours').textContent = String(hours).padStart(2, '0');
        document.getElementById('minutes').textContent = String(minutes).padStart(2, '0');
        document.getElementById('seconds').textContent = String(seconds).padStart(2, '0');
    }
    setInterval(updateCountdown, 1000);
    updateCountdown();
    
    async function showProduct(id) {
        try {
            const res = await fetch(`${baseUrl}/api/shop-products.php?action=get&id=${id}`);
            const data = await res.json();
            if (data.success) {
                currentProduct = data.product;
                const p = currentProduct;
                
                document.getElementById('modalName').textContent = p.name;
                document.getElementById('modalSku').textContent = p.sku ? `SKU: ${p.sku}` : '';
                
                const price = p.sale_price || p.price;
                document.getElementById('modalPrice').textContent = `฿${Number(price).toLocaleString()}`;
                
                if (p.sale_price && p.sale_price < p.price) {
                    document.getElementById('modalOriginalPrice').textContent = `฿${Number(p.price).toLocaleString()}`;
                    document.getElementById('modalOriginalPrice').classList.remove('hidden');
                    const discount = Math.round(((p.price - p.sale_price) / p.price) * 100);
                    document.getElementById('modalDiscount').textContent = `-${discount}%`;
                    document.getElementById('modalDiscount').classList.remove('hidden');
                } else {
                    document.getElementById('modalOriginalPrice').classList.add('hidden');
                    document.getElementById('modalDiscount').classList.add('hidden');
                }
                
                const stock = p.stock || 999;
                const stockEl = document.getElementById('modalStock');
                if (stock > 10) {
                    stockEl.innerHTML = `<i class="fas fa-check-circle text-green-500"></i><span class="text-green-600">มีสินค้า</span>`;
                } else if (stock > 0) {
                    stockEl.innerHTML = `<i class="fas fa-exclamation-circle text-orange-500"></i><span class="text-orange-600">เหลือ ${stock} ชิ้น</span>`;
                } else {
                    stockEl.innerHTML = `<i class="fas fa-times-circle text-red-500"></i><span class="text-red-600">สินค้าหมด</span>`;
                    document.getElementById('addToCartBtn').disabled = true;
                }
                
                document.getElementById('modalQty').textContent = '1';
                
                if (p.description) {
                    document.getElementById('modalDesc').textContent = p.description;
                    document.getElementById('modalDescSection').classList.remove('hidden');
                } else {
                    document.getElementById('modalDescSection').classList.add('hidden');
                }
                
                const imgDiv = document.getElementById('modalImage');
                if (p.image_url) {
                    imgDiv.innerHTML = `<img src="${p.image_url}" class="w-full h-full object-contain bg-white">`;
                } else {
                    imgDiv.innerHTML = '<div class="w-full h-full flex items-center justify-center bg-gray-100"><i class="fas fa-image text-5xl text-gray-300"></i></div>';
                }
                
                document.getElementById('productModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        } catch (e) { console.error(e); }
    }
    
    function closeModal() {
        document.getElementById('productModal').classList.add('hidden');
        document.body.style.overflow = '';
    }
    
    function changeQty(delta) {
        const el = document.getElementById('modalQty');
        let qty = parseInt(el.textContent) + delta;
        if (qty < 1) qty = 1;
        if (qty > 99) qty = 99;
        el.textContent = qty;
    }
    
    function addToCart() {
        if (!currentProduct) return;
        const qty = parseInt(document.getElementById('modalQty').textContent);
        const existing = cart.find(item => item.id === currentProduct.id);
        if (existing) {
            existing.qty += qty;
        } else {
            cart.push({ ...currentProduct, qty });
        }
        localStorage.setItem('cart_' + lineAccountId, JSON.stringify(cart));
        updateCartBadge();
        closeModal();
        showToast('เพิ่มลงตะกร้าแล้ว');
    }
    
    function updateCartBadge() {
        const total = cart.reduce((sum, item) => sum + item.qty, 0);
        const badge = document.getElementById('cartBadge');
        if (total > 0) {
            badge.textContent = total;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }
    
    function openCart() {
        window.location.href = `${baseUrl}/liff-checkout.php?user=${userId}&account=${lineAccountId}`;
    }
    
    function openAccount() {
        window.location.href = `${baseUrl}/liff-member-card.php?user=${userId}&account=${lineAccountId}`;
    }
    
    function showCategories() {
        // Could open a category modal
        alert('หมวดหมู่สินค้า');
    }
    
    function showToast(msg) {
        const toast = document.createElement('div');
        toast.className = 'fixed top-20 left-1/2 -translate-x-1/2 bg-gray-800 text-white px-4 py-2 rounded-lg text-sm z-50';
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    }
    </script>
</body>
</html>