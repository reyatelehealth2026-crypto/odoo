<?php
/**
 * LIFF Member Card & Services - หน้าหลัก (ฉบับปรับปรุงใหม่)
 * รวมบัตรสมาชิก, เมนูบริการ 6 รายการ และระบบนัดหมายแพทย์
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_GET['account'] ?? 1;
$companyName = 'MedCare';
$companyLogo = '';

// Include LIFF helper
require_once 'includes/liff-helper.php';

// Get Unified LIFF ID (ใช้ liff_id เดียวสำหรับทุกหน้า)
$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
$liffShopId = $liffId;
$liffCheckoutId = $liffId;
$liffConsentId = $liffId;
$liffRegisterId = $liffId;
$lineAccountId = $liffData['line_account_id'];
$companyName = $liffData['account_name'];

try {
    $stmt = $db->prepare("SELECT shop_name, logo_url FROM shop_settings WHERE line_account_id = ? LIMIT 1");
    $stmt->execute([$lineAccountId]);
    $shop = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($shop) {
        $companyName = $shop['shop_name'] ?: $companyName;
        $companyLogo = $shop['logo_url'] ?? '';
    }
} catch (Exception $e) {}

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($companyName) ?> - บริการสุขภาพ</title>
    <meta name="description" content="<?= htmlspecialchars($companyName) ?> บริการสุขภาพครบวงจร ร้านขายยา ปรึกษาเภสัชกร สะสมแต้ม แลกของรางวัล">
    <meta name="keywords" content="<?= htmlspecialchars($companyName) ?>, ร้านขายยา, บริการสุขภาพ, สมาชิก, สะสมแต้ม">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="<?= htmlspecialchars($companyName) ?> - บริการสุขภาพ">
    <meta property="og:description" content="บริการสุขภาพครบวงจร ร้านขายยา ปรึกษาเภสัชกร">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="th_TH">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #11B0A6;
            --secondary-color: #FF5A5F;
            --bg-light: #F8FAFC;
        }
        body { 
            font-family: 'Sarabun', -apple-system, sans-serif; 
            background-color: var(--bg-light);
            color: #334155;
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Card Gradients */
        .card-gradient-platinum { background: linear-gradient(135deg, #334155 0%, #0F172A 100%); }
        .card-gradient-gold { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); }
        .card-gradient-silver { background: linear-gradient(135deg, #9CA3AF 0%, #6B7280 100%); }
        .card-gradient-bronze { background: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%); }
        .card-gradient-vip { background: linear-gradient(135deg, #9333EA 0%, #6B21A8 100%); }
        
        .member-card { 
            border-radius: 24px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.12); 
            position: relative; 
            overflow: hidden; 
        }
        .member-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -30%;
            width: 80%;
            height: 150%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 60%);
            pointer-events: none;
        }
        
        .skeleton { 
            animation: pulse 2s infinite; 
            background: linear-gradient(90deg, rgba(255,255,255,0.1) 25%, rgba(255,255,255,0.2) 50%, rgba(255,255,255,0.1) 75%); 
            background-size: 200% 100%; 
        }
        @keyframes pulse { 
            0% { background-position: 200% 0; } 
            100% { background-position: -200% 0; } 
        }
        
        /* Service Grid Styles */
        .service-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 0;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .service-btn:active { transform: scale(0.92); }
        .icon-box {
            width: 62px;
            height: 62px;
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        
        /* Tab Styles */
        .tab-btn {
            padding-bottom: 8px;
            font-weight: 700;
            color: #94A3B8;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        .tab-btn.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        /* Doctor Card */
        .doctor-card {
            background: white;
            border-radius: 24px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            border: 1px solid #F1F5F9;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .doctor-card:active {
            transform: scale(0.98);
        }
        .rating-badge {
            background: var(--secondary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 10px 4px 10px 4px;
            font-size: 11px;
            font-weight: bold;
        }
        
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            display: flex;
            justify-content: space-around;
            padding: 12px 0 max(12px, env(safe-area-inset-bottom));
            box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
            z-index: 50;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #94A3B8;
            font-size: 10px;
            text-decoration: none;
            cursor: pointer;
        }
        .nav-item.active { color: var(--primary-color); }
        .nav-item i { font-size: 20px; margin-bottom: 2px; }
        
        /* Auto-resize text to fit container */
        .auto-text-size {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            max-width: 100%;
        }
        .truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body class="pb-24">

<!-- Header & Member Card Section -->
<div class="bg-white px-4 pt-6 pb-6 rounded-b-[32px] shadow-sm mb-6">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <?php if ($companyLogo): ?>
            <img src="<?= htmlspecialchars($companyLogo) ?>" class="w-10 h-10 rounded-full object-cover border border-gray-100">
            <?php else: ?>
            <div class="w-10 h-10 rounded-full bg-teal-500 flex items-center justify-center text-white">
                <i class="fas fa-plus-circle"></i>
            </div>
            <?php endif; ?>
            <div>
                <h1 class="font-bold text-gray-800 text-lg leading-none mb-1"><?= htmlspecialchars($companyName) ?></h1>
                <p class="text-[11px] text-gray-400 font-medium uppercase tracking-wider">Health & Wellness</p>
            </div>
        </div>
        <div class="flex gap-4 text-gray-400">
            <i class="far fa-bell text-xl cursor-pointer hover:text-gray-600" onclick="sendMessage('แจ้งเตือน')"></i>
            <i class="far fa-comment-dots text-xl cursor-pointer hover:text-gray-600" onclick="openChat()"></i>
        </div>
    </div>

    <!-- Member Card - Telecare Style -->
    <div id="memberCard" class="member-card-telecare relative overflow-hidden" style="border-radius: 20px; box-shadow: 0 8px 30px rgba(0,0,0,0.12);">
        <!-- Loading Skeleton -->
        <div id="cardSkeleton" class="p-5" style="background: linear-gradient(135deg, #87CEEB 0%, #5BB5E0 50%, #E8F4F8 50%, #FFFFFF 100%);">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-20 h-20 skeleton rounded-full bg-white/30"></div>
                <div class="flex-1">
                    <div class="h-6 w-40 skeleton rounded mb-2 bg-white/30"></div>
                    <div class="h-4 w-32 skeleton rounded bg-white/30"></div>
                </div>
            </div>
        </div>
        
        <!-- Card Content (Registered Member) -->
        <div id="cardContent" class="hidden">
            <!-- Two-tone background -->
            <div class="flex" style="min-height: 180px;">
                <!-- Left side - Blue gradient with mascot -->
                <div class="w-2/5 relative" style="background: linear-gradient(180deg, #5BB5E0 0%, #87CEEB 100%);">
                    <!-- Mascot Robot Doctor -->
                    <div class="absolute bottom-0 left-2 right-0">
                        <img id="mascotImage" src="<?= $baseUrl ?>/assets/images/telecare-mascot.png" 
                             onerror="this.src='https://cdn-icons-png.flaticon.com/512/4712/4712109.png'" 
                             class="w-28 h-auto object-contain" style="filter: drop-shadow(2px 4px 6px rgba(0,0,0,0.2));">
                    </div>
                    <!-- Company Name on top -->
                    <div class="absolute top-4 left-4 right-2 text-white">
                        <h2 class="font-bold text-xl leading-tight" id="companyTitle">Telecare</h2>
                        <p class="text-xs opacity-90" id="companySubtitle">CNY Health Care</p>
                    </div>
                </div>
                
                <!-- Right side - White/Light blue with card info -->
                <div class="w-3/5 p-4" style="background: linear-gradient(180deg, #E8F4F8 0%, #FFFFFF 100%);">
                    <!-- Member Card Header -->
                    <div class="flex items-center gap-2 mb-4">
                        <h3 class="font-bold text-gray-700 text-lg">MEMBER CARD</h3>
                        <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center shadow-sm">
                            <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                                <path d="M7 10h10M12 7v6" stroke="white" stroke-width="1.5" fill="none"/>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Member Info -->
                    <div class="space-y-2">
                        <div class="flex items-center">
                            <span class="text-gray-500 text-sm w-14 flex-shrink-0">Name</span>
                            <span class="text-gray-800 font-semibold flex-1 border-b border-gray-300 pb-1 ml-2 truncate auto-text-size" id="memberName" style="font-size: 14px;">-</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-gray-500 text-sm w-14 flex-shrink-0">ID</span>
                            <span class="text-gray-800 font-mono text-sm flex-1 border-b border-gray-300 pb-1 ml-2" id="memberId">-</span>
                        </div>
                        <div class="flex items-center">
                            <span class="text-gray-500 text-sm w-14 flex-shrink-0">Expiry</span>
                            <span class="text-gray-800 text-sm flex-1 border-b border-gray-300 pb-1 ml-2" id="memberExpiry">-</span>
                        </div>
                    </div>
                    
                    <!-- Points Badge -->
                    <div class="mt-4 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center">
                                <i class="fas fa-coins text-amber-500 text-sm"></i>
                            </div>
                            <div>
                                <p class="text-[10px] text-gray-400 leading-none">Points</p>
                                <p class="font-bold text-amber-600" id="memberPoints">0</p>
                            </div>
                        </div>
                        <span class="text-[10px] px-2 py-1 rounded-full font-bold" id="tierBadge" style="background: linear-gradient(135deg, #FFD700, #FFA500); color: white;">GOLD</span>
                    </div>
                </div>
            </div>
            
            <!-- Progress bar at bottom -->
            <div class="px-4 pb-3 pt-2" style="background: #FFFFFF;">
                <div class="bg-gray-100 rounded-full h-1.5 overflow-hidden">
                    <div id="progressBar" class="h-full rounded-full transition-all duration-1000" style="width: 0%; background: linear-gradient(90deg, #5BB5E0, #11B0A6);"></div>
                </div>
                <p class="text-[9px] text-gray-400 text-right mt-1" id="nextTierLabel">Loading...</p>
            </div>
        </div>
        
        <!-- Not Registered Card -->
        <div id="notRegisteredCard" class="hidden">
            <div class="flex" style="min-height: 180px;">
                <div class="w-2/5 relative" style="background: linear-gradient(180deg, #5BB5E0 0%, #87CEEB 100%);">
                    <div class="absolute bottom-0 left-2 right-0">
                        <img src="https://cdn-icons-png.flaticon.com/512/4712/4712109.png" class="w-28 h-auto object-contain opacity-50">
                    </div>
                    <div class="absolute top-4 left-4 right-2 text-white">
                        <h2 class="font-bold text-xl leading-tight">Telecare</h2>
                        <p class="text-xs opacity-90">CNY Health Care</p>
                    </div>
                </div>
                <div class="w-3/5 p-4 flex flex-col justify-center" style="background: linear-gradient(180deg, #E8F4F8 0%, #FFFFFF 100%);">
                    <div class="text-center">
                        <div class="w-12 h-12 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
                            <i class="fas fa-user-plus text-xl text-gray-400"></i>
                        </div>
                        <p class="text-gray-500 text-sm mb-3">ลงทะเบียนเพื่อรับสิทธิพิเศษ</p>
                        <button onclick="openRegister()" class="px-4 py-2 bg-gradient-to-r from-teal-500 to-cyan-500 text-white rounded-xl font-bold text-sm shadow-md">
                            ลงทะเบียนเลย
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Guest Card (Not logged in) -->
        <div id="guestCard" class="hidden">
            <div class="flex" style="min-height: 180px;">
                <div class="w-2/5 relative" style="background: linear-gradient(180deg, #5BB5E0 0%, #87CEEB 100%);">
                    <div class="absolute bottom-0 left-2 right-0">
                        <img src="https://cdn-icons-png.flaticon.com/512/4712/4712109.png" class="w-28 h-auto object-contain opacity-50">
                    </div>
                    <div class="absolute top-4 left-4 right-2 text-white">
                        <h2 class="font-bold text-xl leading-tight">Telecare</h2>
                        <p class="text-xs opacity-90">CNY Health Care</p>
                    </div>
                </div>
                <div class="w-3/5 p-4 flex flex-col justify-center" style="background: linear-gradient(180deg, #E8F4F8 0%, #FFFFFF 100%);">
                    <div class="text-center">
                        <p class="text-gray-500 text-sm mb-3">เข้าสู่ระบบเพื่อดูบัตรสมาชิก</p>
                        <button onclick="liffLogin()" class="px-4 py-2 bg-green-500 text-white rounded-xl font-bold text-sm shadow-md flex items-center justify-center gap-2 mx-auto">
                            <i class="fab fa-line"></i> เข้าสู่ระบบ LINE
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Service Grid (6 เมนู) -->
<div class="px-4 mb-8">
    <div class="grid grid-cols-3 gap-y-6 gap-x-3">
        <!-- 1. ร้านค้า -->
        <div onclick="goToShop()" class="service-btn">
            <div class="icon-box bg-emerald-50 text-emerald-500">
                <i class="fas fa-store text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ร้านค้า</span>
        </div>
        
        <!-- 2. ตะกร้า -->
        <div onclick="goToCheckout()" class="service-btn">
            <div class="icon-box bg-orange-50 text-orange-500 relative">
                <i class="fas fa-shopping-cart text-2xl"></i>
                <span id="cartBadge" class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-[10px] rounded-full items-center justify-center font-bold hidden">0</span>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ตะกร้า</span>
        </div>
        
        <!-- 3. ออเดอร์ -->
        <div onclick="goToMyOrders()" class="service-btn">
            <div class="icon-box bg-blue-50 text-blue-500">
                <i class="fas fa-box-open text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ออเดอร์</span>
        </div>
        
        <!-- 4. แชท -->
        <div onclick="openChat()" class="service-btn">
            <div class="icon-box bg-indigo-50 text-indigo-500">
                <i class="fas fa-comments text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">แชท</span>
        </div>
        
        <!-- 5. ประวัติแต้ม -->
        <div onclick="goToPointsHistory()" class="service-btn">
            <div class="icon-box bg-amber-50 text-amber-500">
                <i class="fas fa-history text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">ประวัติแต้ม</span>
        </div>
        
        <!-- 6. แลกแต้ม -->
        <div onclick="goToRedeemPoints()" class="service-btn">
            <div class="icon-box bg-purple-50 text-purple-500">
                <i class="fas fa-gift text-2xl"></i>
            </div>
            <span class="text-[12px] font-bold text-gray-600">แลกแต้ม</span>
        </div>
    </div>
</div>

<!-- Available Pharmacists Today -->
<div class="px-4 py-4">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold text-gray-800 text-lg flex items-center">
            <i class="fas fa-user-md text-teal-500 mr-2"></i>เภสัชกรว่างวันนี้
        </h2>
        <a href="<?= $baseUrl ?>/liff-appointment.php?account=<?= $lineAccountId ?>" class="text-sm text-teal-600 font-medium">ดูทั้งหมด</a>
    </div>
    <div id="pharmacistsList" class="space-y-3">
        <div class="text-center py-8 text-gray-400">
            <i class="fas fa-spinner fa-spin text-2xl mb-2"></i>
            <p>กำลังโหลด...</p>
        </div>
    </div>
</div>

<!-- User Profile Section -->
<div class="px-4">
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-bold text-gray-800 text-lg">ข้อมูลของฉัน</h2>
        <a onclick="editProfile()" class="text-sm text-teal-600 font-medium cursor-pointer"><i class="fas fa-edit mr-1"></i>แก้ไข</a>
    </div>
    
    <div id="userProfileSection" class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
        <!-- Loading Skeleton -->
        <div id="profileSkeleton">
            <div class="space-y-4">
                <div class="h-4 w-32 skeleton rounded bg-gray-200"></div>
                <div class="h-4 w-48 skeleton rounded bg-gray-200"></div>
                <div class="h-4 w-40 skeleton rounded bg-gray-200"></div>
            </div>
        </div>
        
        <!-- Profile Content -->
        <div id="profileContent" class="hidden space-y-4">
            <div class="flex items-center gap-3 text-gray-600">
                <div class="w-9 h-9 rounded-xl bg-teal-50 flex items-center justify-center">
                    <i class="fas fa-user text-teal-500"></i>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400">ชื่อ-นามสกุล</p>
                    <p class="font-semibold text-gray-800" id="profileFullName">-</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 text-gray-600">
                <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-phone text-blue-500"></i>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400">เบอร์โทรศัพท์</p>
                    <p class="font-semibold text-gray-800" id="profilePhone">-</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 text-gray-600">
                <div class="w-9 h-9 rounded-xl bg-purple-50 flex items-center justify-center">
                    <i class="fas fa-envelope text-purple-500"></i>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400">อีเมล</p>
                    <p class="font-semibold text-gray-800" id="profileEmail">-</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 text-gray-600">
                <div class="w-9 h-9 rounded-xl bg-pink-50 flex items-center justify-center">
                    <i class="fas fa-birthday-cake text-pink-500"></i>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400">วันเกิด</p>
                    <p class="font-semibold text-gray-800" id="profileBirthday">-</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3 text-gray-600">
                <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center">
                    <i class="fas fa-map-marker-alt text-amber-500"></i>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400">ที่อยู่</p>
                    <p class="font-semibold text-gray-800 text-sm leading-relaxed" id="profileAddress">-</p>
                </div>
            </div>
            
            <!-- Gender -->
            <div class="flex items-center gap-3 text-gray-600">
                <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center">
                    <i class="fas fa-venus-mars text-indigo-500"></i>
                </div>
                <div>
                    <p class="text-[11px] text-gray-400">เพศ</p>
                    <p class="font-semibold text-gray-800" id="profileGender">-</p>
                </div>
            </div>
            
            <!-- Health Info Section -->
            <div id="healthInfoSection" class="hidden">
                <div class="border-t border-gray-100 my-4 pt-4">
                    <p class="text-[11px] text-gray-400 mb-3 flex items-center gap-1">
                        <i class="fas fa-heartbeat text-red-400"></i> ข้อมูลสุขภาพ
                    </p>
                    
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div class="bg-gray-50 rounded-xl p-3 text-center">
                            <p class="text-[10px] text-gray-400">น้ำหนัก</p>
                            <p class="font-bold text-gray-800" id="profileWeight">-</p>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-3 text-center">
                            <p class="text-[10px] text-gray-400">ส่วนสูง</p>
                            <p class="font-bold text-gray-800" id="profileHeight">-</p>
                        </div>
                    </div>
                    
                    <div id="medicalConditionsRow" class="hidden mb-2">
                        <div class="flex items-start gap-2 bg-orange-50 rounded-xl p-3">
                            <i class="fas fa-notes-medical text-orange-500 mt-0.5"></i>
                            <div>
                                <p class="text-[10px] text-orange-600">โรคประจำตัว</p>
                                <p class="text-sm text-gray-800" id="profileMedicalConditions">-</p>
                            </div>
                        </div>
                    </div>
                    
                    <div id="drugAllergiesRow" class="hidden">
                        <div class="flex items-start gap-2 bg-red-50 rounded-xl p-3">
                            <i class="fas fa-allergies text-red-500 mt-0.5"></i>
                            <div>
                                <p class="text-[10px] text-red-600">ยาที่แพ้</p>
                                <p class="text-sm text-gray-800" id="profileDrugAllergies">-</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Not Registered Profile -->
        <div id="profileNotRegistered" class="hidden text-center py-6">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 flex items-center justify-center">
                <i class="fas fa-user-plus text-2xl text-gray-400"></i>
            </div>
            <p class="text-gray-500 mb-4">ยังไม่มีข้อมูลสมาชิก</p>
            <button onclick="openRegister()" class="px-6 py-2.5 bg-teal-500 text-white rounded-xl font-bold text-sm">
                ลงทะเบียนเลย
            </button>
        </div>
    </div>
</div>

<!-- Bottom Navigation -->
<nav class="bottom-nav">
    <a class="nav-item active">
        <i class="fas fa-home"></i>
        <span>หน้าหลัก</span>
    </a>
    <a class="nav-item" onclick="goToMyAppointments()">
        <i class="far fa-calendar-alt"></i>
        <span>นัดหมาย</span>
    </a>
    <a class="nav-item" onclick="goToShop()">
        <i class="fas fa-shopping-basket"></i>
        <span>ร้านยา</span>
    </a>
    <a class="nav-item" onclick="editProfile()">
        <i class="far fa-user"></i>
        <span>โปรไฟล์</span>
    </a>
</nav>

<script>
const BASE_URL = '<?= $baseUrl ?>';
const LIFF_ID = '<?= $liffId ?>';
const LIFF_SHOP_ID = '<?= $liffShopId ?>';
const LIFF_CHECKOUT_ID = '<?= $liffCheckoutId ?>';
const LIFF_CONSENT_ID = '<?= $liffConsentId ?>';
const ACCOUNT_ID = <?= (int)$lineAccountId ?>;

let userId = null;
let profile = null;
let memberData = null;

document.addEventListener('DOMContentLoaded', init);

async function init() {
    if (!LIFF_ID) {
        showGuestCard();
        return;
    }
    
    try {
        await liff.init({ liffId: LIFF_ID });
        
        if (liff.isLoggedIn()) {
            profile = await liff.getProfile();
            userId = profile.userId;
            
            // Load member data directly (consent check is optional)
            await loadMemberData();
        } else {
            // ไม่ว่าจะเปิดใน LINE App หรือ Browser ก็ให้ login ได้
            // liff.login() จะ redirect ไป LINE Login page โดยอัตโนมัติ
            liff.login();
        }
    } catch (e) {
        console.error('Init error:', e);
        showGuestCard();
    }
}

async function checkConsent(lineUserId) {
    try {
        const response = await fetch(`${BASE_URL}/api/consent.php?action=check&line_user_id=${lineUserId}`);
        const data = await response.json();
        return data.success && data.all_consented;
    } catch (e) {
        return true;
    }
}

async function loadMemberData() {
    try {
        console.log('Loading member data for userId:', userId, 'accountId:', ACCOUNT_ID);
        const response = await fetch(`${BASE_URL}/api/member.php?action=get_card&line_user_id=${userId}&line_account_id=${ACCOUNT_ID}`);
        const data = await response.json();
        console.log('Member API response:', data);
        
        if (data.success && data.member) {
            memberData = data;
            renderCard(data);
        } else {
            console.log('Not registered or error:', data.message, 'is_registered:', data.is_registered);
            showNotRegisteredCard();
        }
    } catch (e) {
        console.error('Load member error:', e);
        showNotRegisteredCard();
    }
    
    // Load pharmacists after member data
    loadAvailablePharmacists();
}

async function loadAvailablePharmacists() {
    const listContainer = document.getElementById('pharmacistsList');
    if (!listContainer) return;
    
    try {
        const response = await fetch(`${BASE_URL}/api/appointments.php?action=pharmacists&line_account_id=${ACCOUNT_ID}`);
        const data = await response.json();
        console.log('Pharmacists API response:', data);
        
        if (data.success && data.pharmacists && data.pharmacists.length > 0) {
            // Filter available pharmacists
            const available = data.pharmacists.filter(p => p.is_available == 1 || p.is_available === true || p.is_available === '1');
            const pharmacists = available.length > 0 ? available : data.pharmacists;
            
            let html = '';
            pharmacists.slice(0, 5).forEach(p => {
                const isAvailable = p.is_available == 1 || p.is_available === true || p.is_available === '1';
                html += `
                    <div class="doctor-card ${!isAvailable ? 'opacity-60' : ''}" onclick="${isAvailable ? `goToAppointment()` : ''}">
                        <div class="flex gap-3">
                            <div class="relative">
                                <img src="${p.image_url || 'https://api.dicebear.com/7.x/avataaars/svg?seed=' + p.id}" 
                                    class="w-14 h-14 rounded-full object-cover bg-gray-100">
                                <span class="rating-badge">${p.rating || '5.0'}</span>
                            </div>
                            <div class="flex-1">
                                <h3 class="font-bold text-gray-800">${p.title || ''}${p.name || ''}</h3>
                                <p class="text-xs text-gray-500">${p.specialty || 'เภสัชกรทั่วไป'}</p>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs text-gray-400"><i class="fas fa-clock mr-1"></i>${p.consultation_duration || 15} นาที</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-lg font-bold text-teal-600">${p.consultation_fee > 0 ? '฿' + numberFormat(p.consultation_fee) : 'ฟรี'}</p>
                                ${!isAvailable ? '<span class="text-xs text-red-500">ไม่ว่าง</span>' : '<span class="text-xs text-green-500">ว่าง</span>'}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            listContainer.innerHTML = html;
        } else {
            listContainer.innerHTML = `
                <div class="text-center py-8 text-gray-400">
                    <i class="fas fa-user-md text-3xl mb-2"></i>
                    <p>ยังไม่มีเภสัชกรในระบบ</p>
                </div>
            `;
        }
    } catch (e) {
        console.error('Load pharmacists error:', e);
        listContainer.innerHTML = `
            <div class="text-center py-8 text-gray-400">
                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                <p>ไม่สามารถโหลดข้อมูลได้</p>
            </div>
        `;
    }
}

function renderCard(data) {
    const { member, tier, next_tier } = data;
    
    document.getElementById('cardSkeleton').classList.add('hidden');
    document.getElementById('cardContent').classList.remove('hidden');
    document.getElementById('notRegisteredCard').classList.add('hidden');
    document.getElementById('guestCard').classList.add('hidden');
    
    // Update member info for Telecare style card
    const fullName = (member.first_name || profile?.displayName || '-') + (member.last_name ? ' ' + member.last_name : '');
    const nameEl = document.getElementById('memberName');
    nameEl.textContent = fullName;
    
    // Auto-resize name text to fit
    autoResizeText(nameEl, 14, 10);
    
    document.getElementById('memberId').textContent = member.member_id || '000000';
    document.getElementById('memberPoints').textContent = numberFormat(member.points || 0);
    
    // Expiry date (1 year from registration or custom)
    if (member.expiry_date) {
        const expiry = new Date(member.expiry_date);
        document.getElementById('memberExpiry').textContent = expiry.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } else if (member.created_at) {
        const created = new Date(member.created_at);
        created.setFullYear(created.getFullYear() + 1);
        document.getElementById('memberExpiry').textContent = created.toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit', year: 'numeric' });
    } else {
        document.getElementById('memberExpiry').textContent = '-';
    }
    
    // Tier badge
    const tierBadge = document.getElementById('tierBadge');
    const tierName = tier?.tier_name || 'MEMBER';
    tierBadge.textContent = tierName.toUpperCase();
    
    // Tier badge colors
    const tierColors = {
        'platinum': 'linear-gradient(135deg, #334155, #0F172A)',
        'gold': 'linear-gradient(135deg, #FFD700, #FFA500)',
        'silver': 'linear-gradient(135deg, #C0C0C0, #A0A0A0)',
        'bronze': 'linear-gradient(135deg, #CD7F32, #8B4513)',
        'vip': 'linear-gradient(135deg, #9333EA, #6B21A8)'
    };
    const tierCode = tier?.tier_code || 'gold';
    tierBadge.style.background = tierColors[tierCode] || tierColors['gold'];
    
    // Progress
    if (next_tier && tier) {
        const progress = ((member.points - tier.min_points) / (next_tier.min_points - tier.min_points)) * 100;
        document.getElementById('progressBar').style.width = Math.min(100, progress) + '%';
        document.getElementById('nextTierLabel').textContent = `อีก ${numberFormat(next_tier.min_points - member.points)} แต้มถึง ${next_tier.tier_name}`;
    } else {
        document.getElementById('progressBar').style.width = '100%';
        document.getElementById('nextTierLabel').textContent = 'ระดับสูงสุดแล้ว 🎉';
    }
    
    // Render user profile section
    renderUserProfile(member);
}

function renderUserProfile(member) {
    document.getElementById('profileSkeleton').classList.add('hidden');
    document.getElementById('profileContent').classList.remove('hidden');
    document.getElementById('profileNotRegistered').classList.add('hidden');
    
    // Full name
    const fullName = [member.first_name, member.last_name].filter(Boolean).join(' ') || profile?.displayName || '-';
    document.getElementById('profileFullName').textContent = fullName;
    
    // Phone
    document.getElementById('profilePhone').textContent = member.phone || '-';
    
    // Email
    document.getElementById('profileEmail').textContent = member.email || '-';
    
    // Birthday
    if (member.birthday) {
        const bday = new Date(member.birthday);
        const options = { year: 'numeric', month: 'long', day: 'numeric' };
        document.getElementById('profileBirthday').textContent = bday.toLocaleDateString('th-TH', options);
    } else {
        document.getElementById('profileBirthday').textContent = '-';
    }
    
    // Address
    const addressParts = [member.address, member.district, member.province, member.postal_code].filter(Boolean);
    document.getElementById('profileAddress').textContent = addressParts.length > 0 ? addressParts.join(' ') : '-';
    
    // Gender
    const genderMap = { 'male': 'ชาย', 'female': 'หญิง', 'other': 'ไม่ระบุ' };
    document.getElementById('profileGender').textContent = genderMap[member.gender] || member.gender || '-';
    
    // Health Info
    const hasHealthInfo = member.weight || member.height || member.medical_conditions || member.drug_allergies;
    if (hasHealthInfo) {
        document.getElementById('healthInfoSection').classList.remove('hidden');
        
        // Weight & Height
        document.getElementById('profileWeight').textContent = member.weight ? member.weight + ' กก.' : '-';
        document.getElementById('profileHeight').textContent = member.height ? member.height + ' ซม.' : '-';
        
        // Medical Conditions
        if (member.medical_conditions) {
            document.getElementById('medicalConditionsRow').classList.remove('hidden');
            document.getElementById('profileMedicalConditions').textContent = member.medical_conditions;
        }
        
        // Drug Allergies
        if (member.drug_allergies) {
            document.getElementById('drugAllergiesRow').classList.remove('hidden');
            document.getElementById('profileDrugAllergies').textContent = member.drug_allergies;
        }
    }
}

function showNotRegisteredCard() {
    document.getElementById('cardSkeleton').classList.add('hidden');
    document.getElementById('cardContent').classList.add('hidden');
    document.getElementById('guestCard').classList.add('hidden');
    document.getElementById('notRegisteredCard').classList.remove('hidden');
    
    // Show not registered profile section
    document.getElementById('profileSkeleton').classList.add('hidden');
    document.getElementById('profileContent').classList.add('hidden');
    document.getElementById('profileNotRegistered').classList.remove('hidden');
}

function showGuestCard() {
    document.getElementById('cardSkeleton').classList.add('hidden');
    document.getElementById('cardContent').classList.add('hidden');
    document.getElementById('notRegisteredCard').classList.add('hidden');
    document.getElementById('guestCard').classList.remove('hidden');
    
    // Show not registered profile section
    document.getElementById('profileSkeleton').classList.add('hidden');
    document.getElementById('profileContent').classList.add('hidden');
    document.getElementById('profileNotRegistered').classList.remove('hidden');
}

function openRegister() {
    window.location.href = BASE_URL + '/liff-register.php?account=' + ACCOUNT_ID;
}

function liffLogin() {
    // ใช้ liff.login() ได้ทั้งใน LINE App และ External Browser
    // จะ redirect ไป LINE Login page โดยอัตโนมัติ
    if (typeof liff !== 'undefined' && liff.isReady) {
        liff.login();
    } else {
        // Fallback: redirect ไป LINE Login URL โดยตรง
        window.location.href = `https://liff.line.me/${LIFF_ID}`;
    }
}

function editProfile() {
    window.location.href = BASE_URL + '/liff-register.php?account=' + ACCOUNT_ID + '&edit=1';
}

function goToShop() {
    window.location.href = BASE_URL + '/liff-shop.php?account=' + ACCOUNT_ID;
}

function goToCheckout() {
    window.location.href = BASE_URL + '/liff-checkout.php?account=' + ACCOUNT_ID;
}

function goToMyOrders() {
    window.location.href = BASE_URL + '/liff-my-orders.php?account=' + ACCOUNT_ID;
}

function goToPointsHistory() {
    window.location.href = BASE_URL + '/liff-points-history.php?account=' + ACCOUNT_ID;
}

function goToRedeemPoints() {
    window.location.href = BASE_URL + '/liff-redeem-points.php?account=' + ACCOUNT_ID;
}

function goToPointsRules() {
    window.location.href = BASE_URL + '/liff-points-rules.php?account=' + ACCOUNT_ID;
}

function goToAppointment() {
    window.location.href = BASE_URL + '/liff-appointment.php?account=' + ACCOUNT_ID;
}

function goToMyAppointments() {
    window.location.href = BASE_URL + '/liff-my-appointments.php?account=' + ACCOUNT_ID;
}

function openChat() {
    if (liff.isInClient()) {
        liff.closeWindow();
    } else {
        Swal.fire({ 
            icon: 'info', 
            title: 'กรุณาเปิดผ่าน LINE App', 
            confirmButtonColor: '#11B0A6' 
        });
    }
}

function switchTab(btn, type) {
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    btn.classList.add('active');
}

function bookDoctor(name) {
    Swal.fire({
        title: `นัดพบ ${name}`,
        text: 'ยืนยันการนัดหมายแพทย์ทันที?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#11B0A6',
        confirmButtonText: 'ตกลง',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            sendMessage(`นัดพบแพทย์: ${name}`);
        }
    });
}

async function sendMessage(text) {
    if (liff.isInClient()) {
        try {
            await liff.sendMessages([{ type: 'text', text: text }]);
            liff.closeWindow();
        } catch (e) { 
            console.error(e); 
        }
    } else {
        Swal.fire({ 
            icon: 'info', 
            title: 'เปิดใน LINE', 
            text: `คุณกดเมนู: ${text}`, 
            confirmButtonColor: '#11B0A6' 
        });
    }
}

function numberFormat(num) {
    return new Intl.NumberFormat('th-TH').format(num);
}

// Auto-resize text to fit container without wrapping
function autoResizeText(element, maxSize = 14, minSize = 9) {
    if (!element) return;
    
    const parent = element.parentElement;
    if (!parent) return;
    
    // Reset to max size first
    element.style.fontSize = maxSize + 'px';
    
    // Get available width (parent width minus label width and margin)
    const labelWidth = 56 + 8; // w-14 (56px) + ml-2 (8px)
    const availableWidth = parent.offsetWidth - labelWidth - 4; // 4px buffer
    
    // Reduce font size until text fits or reaches minimum
    let currentSize = maxSize;
    while (element.scrollWidth > availableWidth && currentSize > minSize) {
        currentSize -= 0.5;
        element.style.fontSize = currentSize + 'px';
    }
    
    // If still doesn't fit at min size, add ellipsis
    if (element.scrollWidth > availableWidth) {
        element.style.textOverflow = 'ellipsis';
        element.style.overflow = 'hidden';
        element.style.whiteSpace = 'nowrap';
    }
}
</script>

<?php include 'includes/liff-nav.php'; ?>
</body>
</html>
