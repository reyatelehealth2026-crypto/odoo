# แก้ไขปัญหาการแลกของรางวัล

## ปัญหาที่พบ

เมื่อผู้ใช้กดปุ่มแลกของรางวัลในแอป LIFF ไม่มีอะไรเกิดขึ้น เนื่องจาก:

1. **API endpoint ผิด** - LIFF app เรียก `/api/points.php` แต่ควรเรียก `/api/points-history.php`
2. **ไม่มี success modal** - เมื่อแลกสำเร็จไม่มีการแสดงรหัสรับรางวัล
3. **ไม่มีฟังก์ชัน copy code** - ไม่สามารถคัดลอกรหัสรับรางวัลได้
4. **ไม่ตรวจสอบ stock** - ไม่แสดงสถานะสินค้าหมด

## การแก้ไข

### 1. แก้ไข API Endpoint (liff/assets/js/liff-app.js)

**ก่อนแก้:**
```javascript
const response = await fetch(`${this.config.BASE_URL}/api/points.php?action=rewards...`);
```

**หลังแก้:**
```javascript
const response = await fetch(`${this.config.BASE_URL}/api/points-history.php?action=rewards...`);
```

**เปลี่ยนแปลง:**
- ✅ `loadRewards()` - เปลี่ยนจาก `/api/points.php` เป็น `/api/points-history.php`
- ✅ `showRewardDetail()` - เปลี่ยนจาก `/api/points.php` เป็น `/api/points-history.php`
- ✅ `confirmRedeem()` - เปลี่ยนจาก JSON POST เป็น FormData POST ไปที่ `/api/points-history.php`

### 2. เพิ่มการตรวจสอบ Stock

**เพิ่มใน `loadRewards()`:**
```javascript
const canRedeem = userPoints >= reward.points_required && 
                  (reward.stock === null || reward.stock === -1 || reward.stock > 0);
const isOutOfStock = reward.stock !== null && reward.stock !== -1 && reward.stock <= 0;
```

แสดงสถานะ:
- ✅ "หมดแล้ว" - เมื่อสินค้าหมด
- ✅ "แต้มไม่พอ" - เมื่อแต้มไม่เพียงพอ
- ✅ ปิดการคลิก - เมื่อไม่สามารถแลกได้

### 3. เพิ่ม Success Modal

**เพิ่มใน `confirmRedeem()`:**
```javascript
// Show success with redemption code
const successHtml = `
    <div class="modal-overlay success-modal" id="successModal">
        <div class="modal-content success-modal-content">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2>แลกรางวัลสำเร็จ!</h2>
            <p class="success-subtitle">รหัสรับรางวัลของคุณ</p>
            <div class="redemption-code-box">
                <code class="redemption-code">${data.redemption_code}</code>
                <button class="copy-code-btn" onclick="window.liffApp.copyCode('${data.redemption_code}')">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
            <p class="success-note">กรุณาแสดงรหัสนี้เพื่อรับรางวัล</p>
            <button class="btn btn-primary btn-block" onclick="window.liffApp.closeSuccessModal()">
                เข้าใจแล้ว
            </button>
        </div>
    </div>
`;
```

### 4. เพิ่มฟังก์ชัน Helper

**เพิ่มฟังก์ชันใหม่:**

```javascript
// ปิด success modal
closeSuccessModal() {
    const modal = document.getElementById('successModal');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(() => modal.remove(), 300);
    }
}

// คัดลอกรหัสรับรางวัล
async copyCode(code) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(code);
            this.showToast('คัดลอกรหัสแล้ว', 'success');
        } else {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = code;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showToast('คัดลอกรหัสแล้ว', 'success');
        }
    } catch (error) {
        console.error('Error copying code:', error);
        this.showToast('ไม่สามารถคัดลอกได้', 'error');
    }
}
```

### 5. เพิ่ม CSS (liff/assets/css/liff-app.css)

เพิ่ม CSS สำหรับ success modal:
- ✅ Modal overlay และ animation
- ✅ Success icon พร้อม pulse animation
- ✅ Redemption code box พร้อม copy button
- ✅ Responsive design สำหรับมือถือ

## การทดสอบ

### ขั้นตอนการทดสอบ:

1. **เปิดแอป LIFF**
   - เข้าไปที่หน้า "แลกแต้ม" (`/redeem`)

2. **ตรวจสอบรายการรางวัล**
   - ✅ แสดงรางวัลทั้งหมดที่เปิดใช้งาน
   - ✅ แสดงจำนวนแต้มที่ต้องใช้
   - ✅ แสดง "แต้มไม่พอ" สำหรับรางวัลที่แต้มไม่เพียงพอ
   - ✅ แสดง "หมดแล้ว" สำหรับรางวัลที่หมดสต็อก

3. **กดดูรายละเอียดรางวัล**
   - ✅ แสดง modal รายละเอียดรางวัล
   - ✅ แสดงรูปภาพ, ชื่อ, รายละเอียด, เงื่อนไข
   - ✅ แสดงแต้มที่ต้องใช้
   - ✅ แสดงสถานะสต็อก (ถ้ามี)

4. **กดปุ่มแลกรางวัล**
   - ✅ แสดง confirmation dialog
   - ✅ เรียก API `/api/points-history.php?action=redeem`
   - ✅ หักแต้มจากบัญชีผู้ใช้
   - ✅ ลดจำนวนสต็อกรางวัล (ถ้ามี)
   - ✅ สร้างรหัสรับรางวัล (redemption code)

5. **แสดง Success Modal**
   - ✅ แสดง modal สำเร็จพร้อม animation
   - ✅ แสดงรหัสรับรางวัล
   - ✅ มีปุ่มคัดลอกรหัส
   - ✅ กดปุ่มคัดลอกแล้วแสดง toast "คัดลอกรหัสแล้ว"

6. **ปิด Modal**
   - ✅ กดปุ่ม "เข้าใจแล้ว" ปิด modal
   - ✅ รีเฟรชรายการรางวัล
   - ✅ อัปเดตแต้มคงเหลือ

### กรณีทดสอบ Error:

1. **แต้มไม่เพียงพอ**
   - ✅ แสดง error message "แต้มไม่เพียงพอ"
   - ✅ ไม่หักแต้ม

2. **รางวัลหมด**
   - ✅ แสดง error message "ของรางวัลหมดแล้ว"
   - ✅ ไม่หักแต้ม

3. **Network Error**
   - ✅ แสดง error message "เกิดข้อผิดพลาด กรุณาลองใหม่"
   - ✅ คืนสถานะปุ่มเดิม

## API Endpoint ที่เกี่ยวข้อง

### `/api/points-history.php`

**Actions ที่รองรับ:**

1. **`action=rewards`** (GET)
   - ดึงรายการรางวัลทั้งหมดที่เปิดใช้งาน
   - ดึงประวัติการแลกรางวัลของผู้ใช้
   - Response:
     ```json
     {
       "success": true,
       "available_points": 1000,
       "rewards": [...],
       "my_redemptions": [...]
     }
     ```

2. **`action=redeem`** (POST)
   - แลกรางวัลด้วยแต้ม
   - Parameters:
     - `line_user_id`: LINE User ID
     - `reward_id`: ID ของรางวัล
   - Response:
     ```json
     {
       "success": true,
       "message": "แลกรางวัลสำเร็จ!",
       "redemption_code": "RW240119ABC123",
       "reward_name": "ส่วนลด 50 บาท"
     }
     ```

## ไฟล์ที่แก้ไข

1. ✅ `liff/assets/js/liff-app.js` - แก้ไข API endpoint และเพิ่มฟังก์ชัน
2. ✅ `liff/assets/css/liff-app.css` - เพิ่ม CSS สำหรับ success modal

## ไฟล์ที่ไม่ต้องแก้ไข

- ✅ `api/points-history.php` - มี action `redeem` อยู่แล้ว
- ✅ `classes/LoyaltyPoints.php` - มี method `redeemReward()` อยู่แล้ว
- ✅ Database tables - มีตาราง `rewards` และ `reward_redemptions` อยู่แล้ว

## สรุป

การแก้ไขนี้แก้ปัญหาการแลกของรางวัลที่ไม่ทำงานโดย:
1. เปลี่ยน API endpoint ให้ถูกต้อง
2. เพิ่มการแสดงผล success modal พร้อมรหัสรับรางวัล
3. เพิ่มฟังก์ชันคัดลอกรหัส
4. เพิ่มการตรวจสอบสต็อกและแต้ม
5. ปรับปรุง UX ให้ดีขึ้น

ผู้ใช้สามารถแลกของรางวัลได้อย่างสมบูรณ์และได้รับรหัสรับรางวัลทันที
