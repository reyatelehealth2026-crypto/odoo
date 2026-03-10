<?php
/**
 * LIFF: Notification Settings
 * 
 * User interface for managing notification preferences
 */

require_once __DIR__ . '/../config/config.php';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งค่าการแจ้งเตือน</title>
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 16px;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            color: #333;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .pref-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        
        .pref-item:last-child {
            border-bottom: none;
        }
        
        .pref-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .pref-label {
            font-size: 15px;
            color: #333;
            font-weight: 500;
        }
        
        .pref-event {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }
        
        .toggle {
            position: relative;
            display: inline-block;
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
            transition: .3s;
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
            transition: .3s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #06C755;
        }
        
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .badge-batch {
            background: #E3F2FD;
            color: #1976D2;
        }
        
        .badge-milestone {
            background: #FFF3E0;
            color: #F57C00;
        }
        
        .badge-high {
            background: #FFEBEE;
            color: #C62828;
        }
        
        .quiet-hours {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }
        
        .quiet-hours input[type="time"] {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .save-btn {
            width: 100%;
            padding: 16px;
            background: #06C755;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 16px;
        }
        
        .save-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .reset-btn {
            width: 100%;
            padding: 12px;
            background: white;
            color: #666;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 8px;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .success-message {
            background: #E8F5E9;
            color: #2E7D32;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: none;
        }
        
        .error-message {
            background: #FFEBEE;
            color: #C62828;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-message" id="successMessage">✓ บันทึกการตั้งค่าเรียบร้อย</div>
        <div class="error-message" id="errorMessage">เกิดข้อผิดพลาด กรุณาลองใหม่</div>
        
        <div class="header">
            <h1>🔔 ตั้งค่าการแจ้งเตือน</h1>
            <p>เลือกว่าจะรับแจ้งเตือนสถานะไหนบ้าง</p>
        </div>
        
        <div id="loading" class="loading">
            <p>กำลังโหลด...</p>
        </div>
        
        <div id="content" style="display: none;">
            <div class="section">
                <div class="section-title">
                    ⚙️ การตั้งค่าทั่วไป
                </div>
                
                <div class="pref-item">
                    <div class="pref-header">
                        <div>
                            <div class="pref-label">เปิดใช้งาน Quiet Hours</div>
                            <div class="pref-event">ไม่รบกวนในช่วงเวลาที่กำหนด</div>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" id="quietHoursEnabled">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="quiet-hours">
                        <input type="time" id="quietHoursStart" value="22:00">
                        <span>ถึง</span>
                        <input type="time" id="quietHoursEnd" value="08:00">
                    </div>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">
                    📦 การแจ้งเตือนออเดอร์
                </div>
                <div id="orderPreferences"></div>
            </div>
            
            <div class="section">
                <div class="section-title">
                    💰 การแจ้งเตือนการเงิน
                </div>
                <div id="financialPreferences"></div>
            </div>
            
            <button class="save-btn" id="saveBtn">บันทึกการตั้งค่า</button>
            <button class="reset-btn" id="resetBtn">รีเซ็ตเป็นค่าเริ่มต้น</button>
        </div>
    </div>

    <script>
        let lineUserId = null;
        let preferences = [];
        
        const eventLabels = {
            'order.validated': { label: 'ยืนยันออเดอร์', icon: '✅', batch: true },
            'order.picker_assigned': { label: 'เตรียมจัดสินค้า', icon: '👤', batch: true },
            'order.picking': { label: 'กำลังจัดสินค้า', icon: '📦', batch: true },
            'order.picked': { label: 'จัดเสร็จแล้ว', icon: '✅', batch: true },
            'order.packing': { label: 'กำลังแพ็ค', icon: '📦', batch: true },
            'order.packed': { label: 'แพ็คเสร็จ (Milestone)', icon: '✅', batch: true, milestone: true },
            'order.awaiting_payment': { label: 'รอชำระเงิน', icon: '💰', priority: 'high' },
            'order.paid': { label: 'ชำระเงินแล้ว', icon: '💳' },
            'order.to_delivery': { label: 'เตรียมส่ง', icon: '🚚', batch: true },
            'order.in_delivery': { label: 'กำลังจัดส่ง', icon: '🚚', batch: true },
            'order.delivered': { label: 'จัดส่งสำเร็จ', icon: '✅', batch: true },
            'bdo.confirmed': { label: 'แจ้งชำระเงิน BDO', icon: '💳', priority: 'high' },
            'invoice.created': { label: 'ออกใบแจ้งหนี้', icon: '📄' },
            'invoice.overdue': { label: 'ใบแจ้งหนี้เกินกำหนด', icon: '⚠️', priority: 'high' }
        };
        
        const orderEvents = [
            'order.validated', 'order.picker_assigned', 'order.picking', 
            'order.picked', 'order.packing', 'order.packed',
            'order.awaiting_payment', 'order.paid', 'order.to_delivery',
            'order.in_delivery', 'order.delivered'
        ];
        
        const financialEvents = ['bdo.confirmed', 'invoice.created', 'invoice.overdue'];
        
        // Initialize LIFF
        liff.init({ liffId: '<?php echo LIFF_NOTIFICATION_SETTINGS_ID ?? "YOUR_LIFF_ID"; ?>' })
            .then(() => {
                if (!liff.isLoggedIn()) {
                    liff.login();
                    return;
                }
                
                return liff.getProfile();
            })
            .then(profile => {
                if (profile) {
                    lineUserId = profile.userId;
                    loadPreferences();
                }
            })
            .catch(err => {
                console.error('LIFF init error:', err);
                showError('ไม่สามารถเชื่อมต่อกับ LINE ได้');
            });
        
        async function loadPreferences() {
            try {
                const response = await fetch(`/api/notification-preferences.php?line_user_id=${lineUserId}`);
                const data = await response.json();
                
                if (data.success) {
                    preferences = data.preferences;
                    renderPreferences();
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('content').style.display = 'block';
                } else {
                    showError('ไม่สามารถโหลดการตั้งค่าได้');
                }
            } catch (error) {
                console.error('Load error:', error);
                showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            }
        }
        
        function renderPreferences() {
            const orderContainer = document.getElementById('orderPreferences');
            const financialContainer = document.getElementById('financialPreferences');
            
            orderContainer.innerHTML = orderEvents.map(eventType => 
                renderPreferenceItem(eventType)
            ).join('');
            
            financialContainer.innerHTML = financialEvents.map(eventType => 
                renderPreferenceItem(eventType)
            ).join('');
        }
        
        function renderPreferenceItem(eventType) {
            const pref = preferences.find(p => p.event_type === eventType) || { enabled: true };
            const meta = eventLabels[eventType] || { label: eventType, icon: '📌' };
            
            let badges = '';
            if (meta.batch) badges += '<span class="badge badge-batch">BATCH</span>';
            if (meta.milestone) badges += '<span class="badge badge-milestone">MILESTONE</span>';
            if (meta.priority === 'high') badges += '<span class="badge badge-high">สำคัญ</span>';
            
            return `
                <div class="pref-item">
                    <div class="pref-header">
                        <div>
                            <div class="pref-label">${meta.icon} ${meta.label} ${badges}</div>
                            <div class="pref-event">${eventType}</div>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" 
                                   data-event="${eventType}" 
                                   ${pref.enabled ? 'checked' : ''}>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            `;
        }
        
        document.getElementById('saveBtn').addEventListener('click', async () => {
            const button = document.getElementById('saveBtn');
            button.disabled = true;
            button.textContent = 'กำลังบันทึก...';
            
            try {
                const updatedPrefs = [];
                
                document.querySelectorAll('input[data-event]').forEach(input => {
                    updatedPrefs.push({
                        event_type: input.dataset.event,
                        enabled: input.checked
                    });
                });
                
                const response = await fetch('/api/notification-preferences.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        line_user_id: lineUserId,
                        preferences: updatedPrefs
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showSuccess();
                } else {
                    showError(data.errors ? data.errors.join(', ') : 'เกิดข้อผิดพลาด');
                }
            } catch (error) {
                console.error('Save error:', error);
                showError('ไม่สามารถบันทึกได้');
            } finally {
                button.disabled = false;
                button.textContent = 'บันทึกการตั้งค่า';
            }
        });
        
        document.getElementById('resetBtn').addEventListener('click', async () => {
            if (!confirm('ต้องการรีเซ็ตการตั้งค่าเป็นค่าเริ่มต้นใช่หรือไม่?')) {
                return;
            }
            
            try {
                const response = await fetch(`/api/notification-preferences.php?line_user_id=${lineUserId}`, {
                    method: 'DELETE'
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showSuccess('รีเซ็ตเรียบร้อย');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showError('ไม่สามารถรีเซ็ตได้');
                }
            } catch (error) {
                console.error('Reset error:', error);
                showError('เกิดข้อผิดพลาด');
            }
        });
        
        function showSuccess(message = '✓ บันทึกการตั้งค่าเรียบร้อย') {
            const el = document.getElementById('successMessage');
            el.textContent = message;
            el.style.display = 'block';
            setTimeout(() => el.style.display = 'none', 3000);
        }
        
        function showError(message) {
            const el = document.getElementById('errorMessage');
            el.textContent = message;
            el.style.display = 'block';
            setTimeout(() => el.style.display = 'none', 5000);
        }
    </script>
</body>
</html>
