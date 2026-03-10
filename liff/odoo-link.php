<?php
/**
 * Odoo User Linking - LIFF Page
 * หน้าสำหรับเชื่อมต่อบัญชี LINE กับ Odoo Partner
 */

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../classes/LandingSEOService.php';

$db = Database::getInstance()->getConnection();

// Get parameters
$lineAccountId = $_GET['account'] ?? null;
$liffIdParam = $_GET['liff_id'] ?? null;

// Get LIFF ID and shop settings
$liffId = '';
$shopName = 'ร้านค้า';
$shopLogo = '';
$companyName = 'MedCare';
$v = '202602141500'; // Cache buster

try {
    if ($liffIdParam) {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE liff_id = ? LIMIT 1");
        $stmt->execute([$liffIdParam]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($lineAccountId) {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$lineAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
        $stmt->execute();
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            $stmt = $db->prepare("SELECT * FROM line_accounts ORDER BY id LIMIT 1");
            $stmt->execute();
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if ($account) {
        $lineAccountId = $account['id'];
        $liffId = $account['liff_id'] ?? '';
        $shopName = $account['name'];
        $companyName = $shopName;

        try {
            $stmt2 = $db->prepare("SELECT shop_name, shop_logo FROM shop_settings WHERE line_account_id = ? LIMIT 1");
            $stmt2->execute([$lineAccountId]);
            $shop = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($shop) {
                if ($shop['shop_name']) {
                    $shopName = $shop['shop_name'];
                    $companyName = $shopName;
                }
                if ($shop['shop_logo']) {
                    $shopLogo = $shop['shop_logo'];
                }
            }
        } catch (Exception $e2) {
        }
    } else {
        $lineAccountId = 1;
    }
} catch (Exception $e) {
    error_log("odoo-link.php: Error getting account: " . $e->getMessage());
    $lineAccountId = 1;
}

$baseUrl = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เชื่อมต่อบัญชี Odoo</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 500px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
        }

        .tab {
            flex: 1;
            padding: 12px;
            background: #f5f5f5;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            font-family: 'Sarabun', sans-serif;
        }

        .tab.active {
            background: #667eea;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            font-family: 'Sarabun', sans-serif;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            width: 100%;
            padding: 14px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Sarabun', sans-serif;
        }

        .btn:hover {
            background: #5568d3;
        }

        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .btn-danger {
            background: #ef4444;
        }

        .btn-danger:hover {
            background: #dc2626;
        }

        .profile-info {
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .profile-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .profile-row:last-child {
            border-bottom: none;
        }

        .profile-label {
            color: #6b7280;
            font-size: 14px;
        }

        .profile-value {
            color: #111827;
            font-weight: 500;
            font-size: 14px;
        }

        .toggle-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .toggle-label {
            font-size: 14px;
            color: #333;
        }

        .toggle {
            position: relative;
            width: 48px;
            height: 28px;
        }

        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 28px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.slider {
            background-color: #667eea;
        }

        input:checked+.slider:before {
            transform: translateX(20px);
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .nav-back {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 16px;
            width: auto;
            padding: 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <button class="nav-back" onclick="goBack()" id="backBtn" style="display: none;">
            ← ย้อนกลับ
        </button>

        <div class="card">
            <h1>เชื่อมต่อบัญชี Odoo</h1>
            <p class="subtitle">เชื่อมต่อบัญชี LINE กับบัญชีลูกค้าใน Odoo เพื่อรับการแจ้งเตือนและดูข้อมูลออเดอร์</p>

            <div id="loading" class="loading">
                <p>กำลังโหลด...</p>
            </div>

            <div id="linkForm" style="display: none;">
                <div class="tabs">
                    <button class="tab active" onclick="switchTab('phone')">เบอร์โทร</button>
                    <button class="tab" onclick="switchTab('code')">รหัสลูกค้า</button>
                    <button class="tab" onclick="switchTab('email')">อีเมล</button>
                </div>

                <div id="alert" style="display: none;"></div>

                <div id="phoneTab" class="tab-content active">
                    <div class="form-group">
                        <label>เบอร์โทรศัพท์</label>
                        <input type="tel" id="phoneInput" placeholder="081-234-5678">
                    </div>
                    <button class="btn" onclick="linkAccount('phone')">เชื่อมต่อด้วยเบอร์โทร</button>
                </div>

                <div id="codeTab" class="tab-content">
                    <div class="form-group">
                        <label>รหัสลูกค้า</label>
                        <input type="text" id="codeInput" placeholder="PC200134">
                    </div>
                    <div class="form-group">
                        <label>เบอร์โทรศัพท์ (จำเป็น)</label>
                        <input type="tel" id="codePhoneInput" placeholder="0849915142">
                        <small style="color: #888; font-size: 12px; margin-top: 4px; display: block;">
                            ต้องเป็นเบอร์ที่ลงทะเบียนไว้กับรหัสลูกค้า
                        </small>
                    </div>
                    <button class="btn" onclick="linkAccount('code')">เชื่อมต่อด้วยรหัสลูกค้า</button>
                </div>

                <div id="emailTab" class="tab-content">
                    <div class="form-group">
                        <label>อีเมล</label>
                        <input type="email" id="emailInput" placeholder="example@email.com">
                    </div>
                    <button class="btn" onclick="linkAccount('email')">เชื่อมต่อด้วยอีเมล</button>
                </div>
            </div>

            <div id="profileView" style="display: none;">
                <div class="profile-info" id="profileInfo"></div>

                <div class="toggle-container">
                    <span class="toggle-label">รับการแจ้งเตือน</span>
                    <label class="toggle">
                        <input type="checkbox" id="notificationToggle" onchange="toggleNotification()">
                        <span class="slider"></span>
                    </label>
                </div>

                <button class="btn btn-danger" onclick="unlinkAccount()">ยกเลิกการเชื่อมต่อ</button>
            </div>
        </div>
    </div>

    <script>
        window.APP_CONFIG = {
            BASE_URL: '<?= $baseUrl ?>',
            LIFF_ID: '<?= $liffId ?>',
            ACCOUNT_ID: <?= (int) $lineAccountId ?>
        };

        let userId = null;

        async function initLiff() {
            try {
                if (!window.APP_CONFIG.LIFF_ID) {
                    showAlert('error', 'ไม่พบการตั้งค่า LIFF ID');
                    return;
                }

                await liff.init({ liffId: window.APP_CONFIG.LIFF_ID });

                if (liff.isLoggedIn()) {
                    const profile = await liff.getProfile();
                    userId = profile.userId;
                    checkLinkStatus();
                } else {
                    liff.login();
                }

                if (document.referrer || history.length > 1) {
                    document.getElementById('backBtn').style.display = 'flex';
                }

            } catch (err) {
                console.error('LIFF init error:', err);
                showAlert('error', 'เกิดข้อผิดพลาด: ' + err.message);
                document.getElementById('loading').style.display = 'none';
            }
        }

        initLiff();

        async function checkLinkStatus() {
            try {
                const response = await fetch(`${window.APP_CONFIG.BASE_URL}/api/odoo-user-link.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'profile',
                        line_user_id: userId
                    })
                });

                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid server response');
                }

                document.getElementById('loading').style.display = 'none';
                if (data.success) {
                    showProfile(data.data);
                } else {
                    document.getElementById('linkForm').style.display = 'block';
                }
            } catch (err) {
                console.error('Check status error:', err);
                document.getElementById('loading').style.display = 'none';
                document.getElementById('linkForm').style.display = 'block';
            }
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById(tab + 'Tab').classList.add('active');
        }

        async function linkAccount(method) {
            let value;
            let codePhone = null;
            if (method === 'phone') value = document.getElementById('phoneInput').value;
            else if (method === 'code') {
                value = document.getElementById('codeInput').value;
                codePhone = document.getElementById('codePhoneInput').value;
            }
            else value = document.getElementById('emailInput').value;

            if (!value) {
                showAlert('error', 'กรุณากรอกข้อมูล');
                return;
            }

            // v11.0.1.2.0: Phone is required when using customer_code
            if (method === 'code' && !codePhone) {
                showAlert('error', 'เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน');
                return;
            }

            const btn = event.target;
            const originalText = btn.innerText;
            btn.disabled = true;
            btn.innerText = 'กำลังเชื่อมต่อ...';

            try {
                const body = {
                    action: 'link',
                    line_user_id: userId,
                    account_id: window.APP_CONFIG.ACCOUNT_ID
                };
                if (method === 'phone') body.phone = value.replace(/\D/g, '');
                else if (method === 'code') {
                    body.customer_code = value;
                    body.phone = codePhone ? codePhone.replace(/\D/g, '') : null;
                }
                else body.email = value;

                const response = await fetch(`${window.APP_CONFIG.BASE_URL}/api/odoo-user-link.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });

                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    if (response.status !== 200) {
                        showAlert('error', `Server Error (${response.status}): ${text.substring(0, 100)}...`);
                    }
                    throw new Error('Invalid server response');
                }

                btn.disabled = false;
                btn.innerText = originalText;

                if (data.success) {
                    showAlert('success', data.data.message);
                    setTimeout(() => checkLinkStatus(), 1000);
                } else {
                    showAlert('error', data.error);
                }
            } catch (err) {
                console.error('Link error:', err);
                btn.disabled = false;
                btn.innerText = originalText;
                if (err.message !== 'Invalid server response') {
                    showAlert('error', 'เกิดข้อผิดพลาด: ' + err.message);
                }
            }
        }

        function showProfile(profile) {
            document.getElementById('linkForm').style.display = 'none';
            document.getElementById('profileView').style.display = 'block';

            const html = `
                <div class="profile-row">
                    <span class="profile-label">ชื่อ</span>
                    <span class="profile-value">${profile.partner_name || '-'}</span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">รหัสลูกค้า</span>
                    <span class="profile-value">${profile.customer_code || '-'}</span>
                </div>
                <div class="profile-row">
                    <span class="profile-label">เชื่อมต่อด้วย</span>
                    <span class="profile-value">${profile.linked_via === 'phone' ? 'เบอร์โทร' : profile.linked_via === 'customer_code' ? 'รหัสลูกค้า' : 'อีเมล'}</span>
                </div>
                ${profile.credit_limit ? `
                <div class="profile-row">
                    <span class="profile-label">วงเงินเครดิต</span>
                    <span class="profile-value">฿${parseFloat(profile.credit_limit).toLocaleString()}</span>
                </div>
                ` : ''}
            `;
            document.getElementById('profileInfo').innerHTML = html;
            document.getElementById('notificationToggle').checked = profile.notification_enabled;
        }

        function toggleNotification() {
            const enabled = document.getElementById('notificationToggle').checked;

            fetch(`${window.APP_CONFIG.BASE_URL}/api/odoo-user-link.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'notification',
                    line_user_id: userId,
                    enabled: enabled
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showAlert('success', data.data.message);
                    } else {
                        showAlert('error', data.error);
                        document.getElementById('notificationToggle').checked = !enabled;
                    }
                })
                .catch(() => {
                    document.getElementById('notificationToggle').checked = !enabled;
                });
        }

        function unlinkAccount() {
            if (!confirm('ต้องการยกเลิกการเชื่อมต่อบัญชีหรือไม่?')) return;

            fetch(`${window.APP_CONFIG.BASE_URL}/api/odoo-user-link.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'unlink',
                    line_user_id: userId
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showAlert('success', data.data.message);
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert('error', data.error);
                    }
                });
        }

        function showAlert(type, message) {
            const alertDiv = document.getElementById('alert');
            alertDiv.className = 'alert alert-' + type;
            alertDiv.textContent = message;
            alertDiv.style.display = 'block';
            setTimeout(() => alertDiv.style.display = 'none', 5000);
        }

        function goBack() {
            if (liff.isInClient()) {
                liff.closeWindow();
            } else {
                history.back();
            }
        }
    </script>
</body>

</html>
