<?php
/**
 * Product Detail - Business Items
 * Display single product details from business_items table
 * UI เหมือน product-detail-cny.php
 */
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Get product ID or SKU from URL
$productId = $_GET['id'] ?? '';
$sku = $_GET['sku'] ?? '';

if (empty($productId) && empty($sku)) {
    header('Location: products.php');
    exit;
}

// Determine table
$productsTable = 'business_items';
try {
    $db->query("SELECT 1 FROM {$productsTable} LIMIT 1");
} catch (PDOException $e) {
    $productsTable = 'products';
}

// Fetch product from database
if ($productId) {
    $stmt = $db->prepare("SELECT * FROM {$productsTable} WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $productId]);
} else {
    $stmt = $db->prepare("SELECT * FROM {$productsTable} WHERE sku = :sku LIMIT 1");
    $stmt->execute([':sku' => $sku]);
}
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: products.php');
    exit;
}

// Decode JSON fields
if (!empty($product['product_price']) && is_string($product['product_price'])) {
    $product['product_price'] = json_decode($product['product_price'], true);
}
if (!is_array($product['product_price'] ?? null)) {
    $product['product_price'] = [];
}

$pageTitle = $product['name'] ?? 'รายละเอียดสินค้า';

require_once __DIR__ . '/../includes/header.php';

$stock = (int)($product['stock'] ?? 0);
$inStock = $stock > 0;
$imageUrl = $product['photo_path'] ?? $product['image_url'] ?? '';
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <!-- Breadcrumb -->
    <div class="mb-6 flex justify-between items-center">
        <a href="products.php" class="text-blue-600 hover:underline">
            <i class="fas fa-arrow-left mr-2"></i>กลับไปหน้าสินค้า
        </a>
        <a href="/inventory?tab=products&search=<?= urlencode($product['sku'] ?? $product['name']) ?>" 
           class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
            <i class="fas fa-edit mr-2"></i>แก้ไขสินค้า
        </a>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6">
            <!-- Product Image -->
            <div class="aspect-square bg-gray-100 rounded-lg overflow-hidden">
                <?php if (!empty($imageUrl)): ?>
                <img src="<?= htmlspecialchars($imageUrl) ?>" 
                     alt="<?= htmlspecialchars($product['name']) ?>"
                     class="w-full h-full object-contain"
                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 400 400%22%3E%3Crect fill=%22%23f3f4f6%22 width=%22400%22 height=%22400%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2230%22%3ENo Image%3C/text%3E%3C/svg%3E'">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-pills text-gray-300 text-9xl"></i>
                </div>
                <?php endif; ?>
            </div>

            <!-- Product Info -->
            <div>
                <div class="mb-4">
                    <?php if (!empty($product['sku'])): ?>
                    <div class="text-sm text-gray-500 mb-2">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['barcode'])): ?>
                    <div class="text-sm text-gray-500 mb-2">Barcode: <?= htmlspecialchars($product['barcode']) ?></div>
                    <?php endif; ?>
                    
                    <h1 class="text-3xl font-bold text-gray-800 mb-2"><?= htmlspecialchars($product['name']) ?></h1>
                    
                    <?php if (!empty($product['name_en'])): ?>
                    <p class="text-lg text-gray-600 mb-2"><?= htmlspecialchars($product['name_en']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['generic_name'])): ?>
                    <p class="text-sm text-blue-600 font-medium"><?= htmlspecialchars($product['generic_name']) ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($product['manufacturer'])): ?>
                    <p class="text-sm text-gray-500 mt-1">
                        <i class="fas fa-industry mr-1"></i><?= htmlspecialchars($product['manufacturer']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Stock Status -->
                <div class="mb-6">
                    <?php if ($inStock): ?>
                        <?php if ($stock <= 5): ?>
                        <div class="inline-flex items-center px-4 py-2 bg-yellow-100 text-yellow-700 rounded-lg">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <span class="font-medium">สินค้าใกล้หมด (คงเหลือ <?= number_format($stock) ?> หน่วย)</span>
                        </div>
                        <?php else: ?>
                        <div class="inline-flex items-center px-4 py-2 bg-green-100 text-green-700 rounded-lg">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span class="font-medium">มีสินค้า (คงเหลือ <?= number_format($stock) ?> หน่วย)</span>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="inline-flex items-center px-4 py-2 bg-red-100 text-red-700 rounded-lg">
                        <i class="fas fa-times-circle mr-2"></i>
                        <span class="font-medium">สินค้าหมด</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Prices -->
                <?php if (!empty($product['product_price'])): ?>
                <div class="mb-6">
                    <h3 class="font-semibold text-gray-800 mb-3">ราคาตามกลุ่มลูกค้า</h3>
                    <div class="space-y-2">
                        <?php foreach ($product['product_price'] as $priceInfo): 
                            if (($priceInfo['enable'] ?? '1') !== '1') continue;
                        ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <div>
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($priceInfo['customer_group'] ?? 'ทั่วไป') ?></div>
                                <div class="text-sm text-gray-500"><?= htmlspecialchars($priceInfo['unit'] ?? '') ?></div>
                            </div>
                            <div class="text-2xl font-bold text-blue-600">
                                ฿<?= number_format((float)($priceInfo['price'] ?? 0), 2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <!-- Single Price -->
                <div class="mb-6">
                    <div class="flex items-baseline gap-3">
                        <?php if (!empty($product['sale_price']) && $product['sale_price'] < $product['price']): ?>
                        <div class="text-3xl font-bold text-red-600">฿<?= number_format($product['sale_price'], 2) ?></div>
                        <div class="text-xl text-gray-400 line-through">฿<?= number_format($product['price'], 2) ?></div>
                        <?php else: ?>
                        <div class="text-3xl font-bold text-blue-600">฿<?= number_format($product['price'] ?? 0, 2) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($product['unit'])): ?>
                    <div class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($product['unit']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Product Details Tabs -->
        <div class="border-t">
            <div class="flex border-b overflow-x-auto">
                <button class="tab-btn active px-6 py-3 font-medium border-b-2 border-blue-600 text-blue-600 whitespace-nowrap" data-tab="description">
                    <i class="fas fa-info-circle mr-2"></i>รายละเอียด
                </button>
                <button class="tab-btn px-6 py-3 font-medium text-gray-600 hover:text-blue-600 whitespace-nowrap" data-tab="usage">
                    <i class="fas fa-pills mr-2"></i>วิธีใช้
                </button>
                <button class="tab-btn px-6 py-3 font-medium text-gray-600 hover:text-blue-600 whitespace-nowrap" data-tab="properties">
                    <i class="fas fa-flask mr-2"></i>สรรพคุณ
                </button>
            </div>

            <div class="p-6">
                <!-- Description Tab -->
                <div class="tab-content" id="description">
                    <?php 
                    $description = $product['description'] ?? '';
                    if (!empty($description)): 
                        if (stripos($description, '<!doctype') !== false || stripos($description, '<html') !== false):
                            $iframeId = 'desc-iframe-' . uniqid();
                    ?>
                        <iframe id="<?= $iframeId ?>" srcdoc="<?= htmlspecialchars($description) ?>" 
                              style="width:100%; min-height:600px; border:1px solid #e5e7eb; border-radius:8px;"
                              sandbox="allow-same-origin" onload="resizeIframe(this)"></iframe>
                    <?php else: ?>
                        <div class="prose max-w-none"><?= nl2br(htmlspecialchars($description)) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-gray-500">ไม่มีรายละเอียด</p>
                    <?php endif; ?>
                </div>

                <!-- Usage Tab -->
                <div class="tab-content hidden" id="usage">
                    <?php 
                    $usageInstructions = $product['usage_instructions'] ?? $product['how_to_use'] ?? '';
                    if (!empty($usageInstructions)): 
                        if (stripos($usageInstructions, '<!doctype') !== false || stripos($usageInstructions, '<html') !== false):
                            $iframeId = 'usage-iframe-' . uniqid();
                    ?>
                        <iframe id="<?= $iframeId ?>" srcdoc="<?= htmlspecialchars($usageInstructions) ?>" 
                              style="width:100%; min-height:600px; border:1px solid #e5e7eb; border-radius:8px;"
                              sandbox="allow-same-origin" onload="resizeIframe(this)"></iframe>
                    <?php else: ?>
                        <div class="prose max-w-none"><?= nl2br(htmlspecialchars($usageInstructions)) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-gray-500">ไม่มีข้อมูลวิธีใช้</p>
                    <?php endif; ?>
                </div>

                <!-- Properties Tab -->
                <div class="tab-content hidden" id="properties">
                    <?php 
                    $propertiesOther = $product['properties_other'] ?? '';
                    if (!empty($propertiesOther)): 
                        if (stripos($propertiesOther, '<!doctype') !== false || stripos($propertiesOther, '<html') !== false):
                            $iframeId = 'prop-iframe-' . uniqid();
                    ?>
                        <iframe id="<?= $iframeId ?>" srcdoc="<?= htmlspecialchars($propertiesOther) ?>" 
                              style="width:100%; min-height:600px; border:1px solid #e5e7eb; border-radius:8px;"
                              sandbox="allow-same-origin" onload="resizeIframe(this)"></iframe>
                    <?php else: ?>
                        <div class="prose max-w-none"><?= nl2br(htmlspecialchars($propertiesOther)) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="text-gray-500">ไม่มีข้อมูลสรรพคุณ</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-resize iframe to fit content
function resizeIframe(iframe) {
    try {
        if (iframe.contentWindow && iframe.contentWindow.document) {
            const height = iframe.contentWindow.document.documentElement.scrollHeight;
            iframe.style.height = (height + 50) + 'px';
        }
    } catch (e) {
        console.log('Cannot resize iframe due to cross-origin policy');
    }
}

// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        
        // Update buttons
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active', 'border-blue-600', 'text-blue-600', 'border-b-2');
            b.classList.add('text-gray-600');
        });
        btn.classList.add('active', 'border-blue-600', 'text-blue-600', 'border-b-2');
        btn.classList.remove('text-gray-600');
        
        // Update content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });
        document.getElementById(tabId).classList.remove('hidden');
        
        // Resize iframes in the active tab
        setTimeout(() => {
            document.getElementById(tabId).querySelectorAll('iframe').forEach(iframe => {
                resizeIframe(iframe);
            });
        }, 100);
    });
});
</script>

<style>
.tab-btn.active {
    border-bottom-width: 2px;
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
