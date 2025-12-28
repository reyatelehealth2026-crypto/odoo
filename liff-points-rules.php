<?php
/**
 * LIFF Points Rules - กฎการสะสมคะแนน
 * 
 * Requirement 25.10: Display earning rules to user - show current active rules and bonus campaigns
 * 
 * Features:
 * - Display base earning rate (points per baht)
 * - Show minimum order requirement
 * - Display points expiry period
 * - Show active and upcoming bonus campaigns
 * - Display tier multipliers and benefits
 * - Show category-specific bonuses
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();
$lineAccountId = $_GET['account'] ?? 1;
$companyName = 'ร้านค้า';

require_once 'includes/liff-helper.php';

$liffData = getUnifiedLiffId($db, $lineAccountId);
$liffId = $liffData['liff_id'];
$lineAccountId = $liffData['line_account_id'];
$companyName = $liffData['account_name'];

$shopSettings = getShopSettings($db, $lineAccountId);
if (!empty($shopSettings['shop_name'])) $companyName = $shopSettings['shop_name'];

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>กฎการสะสมคะแนน - <?= htmlspecialchars($companyName) ?></title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/liff/assets/css/liff-app.css">
    <style>
        body { 
            font-family: 'Sarabun', sans-serif; 
            background: #F8FAFC; 
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        
        /* Header */
        .rules-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            background: white;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .back-btn {
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #374151;
            font-size: 18px;
            cursor: pointer;
            border-radius: 50%;
            transition: background 0.2s;
        }
        .back-btn:hover { background: #f3f4f6; }
        .page-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        .header-spacer { width: 44px; }
        
        /* Hero Section */
        .rules-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 24px 16px;
            color: white;
            text-align: center;
        }
        .hero-icon {
            width: 64px;
            height: 64px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }
        .hero-icon i { font-size: 28px; }
        .hero-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 8px;
        }
        .hero-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        
        /* Content Container */
        .rules-content {
            padding: 16px;
            padding-bottom: 100px;
        }
        
        /* Section Card */
        .section-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .section-icon.purple { background: #f3e8ff; color: #7c3aed; }
        .section-icon.green { background: #dcfce7; color: #16a34a; }
        .section-icon.blue { background: #dbeafe; color: #2563eb; }
        .section-icon.orange { background: #ffedd5; color: #ea580c; }
        .section-icon.pink { background: #fce7f3; color: #db2777; }
        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        
        /* Base Rules */
        .rule-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .rule-item:last-child { border-bottom: none; }
        .rule-label {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #6b7280;
            font-size: 14px;
        }
        .rule-label i { 
            width: 20px; 
            text-align: center;
            color: #9ca3af;
        }
        .rule-value {
            font-size: 14px;
            font-weight: 600;
            color: #1f2937;
        }
        .rule-value.highlight {
            color: #7c3aed;
            background: #f3e8ff;
            padding: 4px 10px;
            border-radius: 8px;
        }
        
        /* Campaign Card */
        .campaign-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            position: relative;
            overflow: hidden;
        }
        .campaign-card.active {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
        }
        .campaign-card.upcoming {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }
        .campaign-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .campaign-badge.active {
            background: #16a34a;
            color: white;
        }
        .campaign-badge.upcoming {
            background: #2563eb;
            color: white;
        }
        .campaign-name {
            font-size: 16px;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 8px;
            padding-right: 80px;
        }
        .campaign-multiplier {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0,0,0,0.1);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .campaign-dates {
            font-size: 12px;
            color: #6b7280;
        }
        .campaign-dates i { margin-right: 4px; }
        
        /* Tier Card */
        .tier-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            background: #f9fafb;
            border-radius: 12px;
            margin-bottom: 10px;
        }
        .tier-card:last-child { margin-bottom: 0; }
        .tier-badge {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .tier-badge.silver { 
            background: linear-gradient(135deg, #e5e7eb 0%, #9ca3af 100%);
            color: white;
        }
        .tier-badge.gold { 
            background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%);
            color: white;
        }
        .tier-badge.platinum { 
            background: linear-gradient(135deg, #374151 0%, #111827 100%);
            color: white;
        }
        .tier-info { flex: 1; }
        .tier-name {
            font-size: 15px;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 4px;
        }
        .tier-requirement {
            font-size: 12px;
            color: #6b7280;
        }
        .tier-multiplier {
            background: #f3e8ff;
            color: #7c3aed;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
        }
        
        /* Category Bonus */
        .category-bonus-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: #f9fafb;
            border-radius: 10px;
            margin-bottom: 8px;
        }
        .category-bonus-item:last-child { margin-bottom: 0; }
        .category-name {
            font-size: 14px;
            color: #374151;
        }
        .category-multiplier {
            background: #dcfce7;
            color: #16a34a;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 24px;
            color: #9ca3af;
        }
        .empty-state i {
            font-size: 32px;
            margin-bottom: 8px;
            opacity: 0.5;
        }
        .empty-state p {
            margin: 0;
            font-size: 14px;
        }
        
        /* Skeleton Loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: 8px;
        }
        @keyframes skeleton-loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .skeleton-rule {
            height: 48px;
            margin-bottom: 8px;
        }
        .skeleton-campaign {
            height: 100px;
            margin-bottom: 12px;
            border-radius: 12px;
        }
        .skeleton-tier {
            height: 76px;
            margin-bottom: 10px;
            border-radius: 12px;
        }
        
        /* Info Box */
        .info-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            background: #eff6ff;
            border-radius: 12px;
            margin-top: 16px;
        }
        .info-box i {
            color: #3b82f6;
            font-size: 18px;
            margin-top: 2px;
        }
        .info-box p {
            margin: 0;
            font-size: 13px;
            color: #1e40af;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div id="app">
        <!-- Header -->
        <div class="rules-header">
            <button class="back-btn" onclick="goBack()">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1 class="page-title">กฎการสะสมคะแนน</h1>
            <div class="header-spacer"></div>
        </div>
        
        <!-- Hero Section -->
        <div class="rules-hero">
            <div class="hero-icon">
                <i class="fas fa-coins"></i>
            </div>
            <h2 class="hero-title">สะสมคะแนนรับสิทธิพิเศษ</h2>
            <p class="hero-subtitle">ยิ่งช้อป ยิ่งได้คะแนน แลกรับของรางวัลมากมาย</p>
        </div>
        
        <!-- Content -->
        <div class="rules-content">
            <!-- Loading State -->
            <div id="loading-state">
                <div class="section-card">
                    <div class="skeleton skeleton-rule"></div>
                    <div class="skeleton skeleton-rule"></div>
                    <div class="skeleton skeleton-rule"></div>
                </div>
                <div class="section-card">
                    <div class="skeleton skeleton-campaign"></div>
                </div>
                <div class="section-card">
                    <div class="skeleton skeleton-tier"></div>
                    <div class="skeleton skeleton-tier"></div>
                    <div class="skeleton skeleton-tier"></div>
                </div>
            </div>
            
            <!-- Main Content (rendered by JS) -->
            <div id="main-content" style="display: none;"></div>
        </div>
    </div>

    <script>
    const BASE_URL = '<?= $baseUrl ?>';
    const LIFF_ID = '<?= $liffId ?>';
    const ACCOUNT_ID = <?= (int)$lineAccountId ?>;
    
    let rulesData = null;

    document.addEventListener('DOMContentLoaded', init);

    async function init() {
        if (!LIFF_ID) {
            showError('ไม่พบการตั้งค่า LIFF');
            return;
        }
        
        try {
            await liff.init({ liffId: LIFF_ID });
            await loadRulesData();
        } catch (e) {
            console.error('LIFF init error:', e);
            // Still try to load rules even if LIFF fails
            await loadRulesData();
        }
    }

    async function loadRulesData() {
        try {
            const response = await fetch(`${BASE_URL}/api/points-rules.php?line_account_id=${ACCOUNT_ID}`);
            const data = await response.json();
            
            if (data.success) {
                rulesData = data.data;
                renderContent();
            } else {
                showError(data.message || 'ไม่สามารถโหลดข้อมูลได้');
            }
        } catch (e) {
            console.error('Load rules error:', e);
            showError('ไม่สามารถโหลดข้อมูลได้');
        }
    }

    function renderContent() {
        document.getElementById('loading-state').style.display = 'none';
        const mainContent = document.getElementById('main-content');
        mainContent.style.display = 'block';
        
        let html = '';
        
        // Base Earning Rules Section
        html += renderBaseRules(rulesData.earning_rules);
        
        // Active Campaigns Section
        html += renderCampaigns(rulesData.active_campaigns);
        
        // Tier Benefits Section
        html += renderTierBenefits(rulesData.tier_benefits);
        
        // Category Bonuses Section
        if (rulesData.category_bonuses && rulesData.category_bonuses.length > 0) {
            html += renderCategoryBonuses(rulesData.category_bonuses);
        }
        
        // Info Box
        html += `
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <p>คะแนนจะถูกเพิ่มเข้าบัญชีหลังจากคำสั่งซื้อเสร็จสมบูรณ์ และจะหมดอายุตามระยะเวลาที่กำหนด กรุณาใช้คะแนนก่อนหมดอายุ</p>
            </div>
        `;
        
        mainContent.innerHTML = html;
    }

    function renderBaseRules(rules) {
        if (!rules) return '';
        
        const pointsPerBaht = rules.points_per_baht || 1;
        const bahtPerPoint = pointsPerBaht > 0 ? Math.round(1 / pointsPerBaht) : 1;
        const minOrder = rules.min_order_for_points || 0;
        const expiryMonths = rules.points_expiry_months || 12;
        
        return `
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon purple">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3 class="section-title">อัตราการสะสมคะแนน</h3>
                </div>
                
                <div class="rule-item">
                    <span class="rule-label">
                        <i class="fas fa-coins"></i>
                        ทุกการซื้อ
                    </span>
                    <span class="rule-value highlight">
                        ${bahtPerPoint} บาท = 1 คะแนน
                    </span>
                </div>
                
                ${minOrder > 0 ? `
                <div class="rule-item">
                    <span class="rule-label">
                        <i class="fas fa-shopping-cart"></i>
                        ยอดขั้นต่ำ
                    </span>
                    <span class="rule-value">
                        ฿${numberFormat(minOrder)}
                    </span>
                </div>
                ` : ''}
                
                <div class="rule-item">
                    <span class="rule-label">
                        <i class="fas fa-calendar-alt"></i>
                        อายุคะแนน
                    </span>
                    <span class="rule-value">
                        ${expiryMonths} เดือน
                    </span>
                </div>
            </div>
        `;
    }

    function renderCampaigns(campaigns) {
        let html = `
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon orange">
                        <i class="fas fa-fire"></i>
                    </div>
                    <h3 class="section-title">โปรโมชั่นพิเศษ</h3>
                </div>
        `;
        
        if (!campaigns || campaigns.length === 0) {
            html += `
                <div class="empty-state">
                    <i class="fas fa-gift"></i>
                    <p>ไม่มีโปรโมชั่นในขณะนี้</p>
                </div>
            `;
        } else {
            campaigns.forEach(campaign => {
                const isActive = campaign.status === 'active';
                const statusClass = isActive ? 'active' : 'upcoming';
                const statusLabel = isActive ? 'กำลังใช้งาน' : 'เร็วๆ นี้';
                
                html += `
                    <div class="campaign-card ${statusClass}">
                        <span class="campaign-badge ${statusClass}">${statusLabel}</span>
                        <h4 class="campaign-name">${escapeHtml(campaign.name)}</h4>
                        <div class="campaign-multiplier">
                            <i class="fas fa-times"></i>
                            ${campaign.multiplier}x คะแนน
                        </div>
                        <div class="campaign-dates">
                            <i class="fas fa-clock"></i>
                            ${formatDate(campaign.start_date)} - ${formatDate(campaign.end_date)}
                        </div>
                    </div>
                `;
            });
        }
        
        html += '</div>';
        return html;
    }

    function renderTierBenefits(tiers) {
        if (!tiers || tiers.length === 0) return '';
        
        let html = `
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon blue">
                        <i class="fas fa-crown"></i>
                    </div>
                    <h3 class="section-title">สิทธิพิเศษตามระดับสมาชิก</h3>
                </div>
        `;
        
        tiers.forEach(tier => {
            const tierClass = tier.name.toLowerCase();
            const tierIcon = getTierIcon(tierClass);
            const requirement = tier.min_points > 0 
                ? `สะสม ${numberFormat(tier.min_points)} คะแนนขึ้นไป`
                : 'ระดับเริ่มต้น';
            
            html += `
                <div class="tier-card">
                    <div class="tier-badge ${tierClass}">
                        <i class="fas ${tierIcon}"></i>
                    </div>
                    <div class="tier-info">
                        <h4 class="tier-name">${escapeHtml(tier.name)}</h4>
                        <p class="tier-requirement">${requirement}</p>
                    </div>
                    <div class="tier-multiplier">
                        ${tier.multiplier}x
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        return html;
    }

    function renderCategoryBonuses(bonuses) {
        if (!bonuses || bonuses.length === 0) return '';
        
        let html = `
            <div class="section-card">
                <div class="section-header">
                    <div class="section-icon green">
                        <i class="fas fa-tags"></i>
                    </div>
                    <h3 class="section-title">โบนัสตามหมวดหมู่</h3>
                </div>
        `;
        
        bonuses.forEach(bonus => {
            html += `
                <div class="category-bonus-item">
                    <span class="category-name">${escapeHtml(bonus.category_name)}</span>
                    <span class="category-multiplier">${bonus.multiplier}x คะแนน</span>
                </div>
            `;
        });
        
        html += '</div>';
        return html;
    }

    function getTierIcon(tierClass) {
        const icons = {
            'silver': 'fa-medal',
            'gold': 'fa-crown',
            'platinum': 'fa-gem'
        };
        return icons[tierClass] || 'fa-star';
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr);
        return date.toLocaleDateString('th-TH', {
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    function numberFormat(num) {
        return new Intl.NumberFormat('th-TH').format(num || 0);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }

    function goBack() {
        if (typeof liff !== 'undefined' && liff.isInClient && liff.isInClient()) {
            window.location.href = `${BASE_URL}/liff-member-card.php?account=${ACCOUNT_ID}`;
        } else {
            window.history.back();
        }
    }

    function showError(msg) {
        document.getElementById('loading-state').style.display = 'none';
        const mainContent = document.getElementById('main-content');
        mainContent.style.display = 'block';
        mainContent.innerHTML = `
            <div class="section-card">
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color: #ef4444;"></i>
                    <p>${msg}</p>
                    <button onclick="location.reload()" style="margin-top: 16px; padding: 10px 20px; background: #7c3aed; color: white; border: none; border-radius: 8px; cursor: pointer;">
                        <i class="fas fa-redo"></i> ลองใหม่
                    </button>
                </div>
            </div>
        `;
    }
    </script>
    
    <?php include 'includes/liff-nav.php'; ?>
</body>
</html>
