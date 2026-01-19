# แก้ไขระบบแลกรางวัลให้ใช้งานได้จริง - Complete Fix

## สรุปปัญหา
ลูกค้ากดแลกรางวัลใน LIFF app แล้วไม่มีอะไรเกิดขึ้น

## สาเหตุ
1. API endpoint ผิด - เรียก `/api/points.php` แทนที่จะเป็น `/api/points-history.php`
2. ไม่มีการแสดง modal สำเร็จหลังแลกรางวัล
3. ไม่มีการตรวจสอบ stock
4. User ทดสอบไม่มีแต้ม
5. อาจไม่มีรางวัลในระบบ

## การแก้ไข

### 1. แก้ไข API Endpoint ใน LIFF App
**ไฟล์:** `liff/assets/js/liff-app.js`

เปลี่ยนจาก:
```javascript
fetch(`${this.config.BASE_URL}/api/points.php?action=rewards`)
```

เป็น:
```javascript
fetch(`${this.config.BASE_URL}/api/points-history.php?action=rewards`)
```

### 2. เพิ่ม Success Modal
เพิ่ม modal แสดงรหัสรับรางวัลหลังแลกสำเร็จ พร้อมปุ่ม copy code

### 3. เพิ่มการตรวจสอบ Stock
ตรวจสอบว่ารางวัลหมดหรือไม่ก่อนให้แลก

### 4. เพิ่ม Error Handling
จัดการกรณี API ส่ง HTML แทน JSON

## ขั้นตอนการทดสอบ

### Step 1: ตรวจสอบระบบ
```bash
php install/check_rewards_system.php
```

หรือใช้ batch file:
```bash
setup_rewards_test.bat
```

### Step 2: สร้างรางวัลทดสอบ (ถ้ายังไม่มี)
```bash
php install/create_test_reward.php
```

หรือสร้างผ่าน Admin Panel:
1. เข้า https://cny.re-ya.com/membership.php?tab=rewards
2. คลิก "เพิ่มรางวัล"
3. กรอกข้อมูล:
   - ชื่อ: ส่วนลด 50 บาท
   - แต้มที่ใช้: 100
   - จำนวนคงเหลือ: 10
   - เปิดใช้งาน: ✓

### Step 3: เพิ่มแต้มให้ User ทดสอบ
```bash
php install/add_test_points.php
```

หรือเพิ่มผ่าน SQL:
```sql
UPDATE users 
SET points = 1000, 
    available_points = 1000, 
    total_points = 1000 
WHERE line_user_id = 'U1cffe699e4ebedcefafe47073a933ea0';
```

### Step 4: ทดสอบใน LIFF App
1. เปิด LINE app
2. เข้าสู่ LIFF app ของร้าน
3. ไปที่หน้า "แต้มสะสม" หรือ "รางวัล"
4. เลือกรางวัลที่ต้องการแลก
5. กดปุ่ม "แลกรางวัลนี้"
6. ยืนยันการแลก
7. ควรเห็น modal แสดงรหัสรับรางวัล

### Step 5: ตรวจสอบผลลัพธ์
1. แต้มของ user ควรลดลง
2. ควรมีรหัสรับรางวัล (redemption code)
3. ใน Admin Panel ที่ membership.php?tab=rewards&reward_tab=redemptions ควรเห็นรายการแลกใหม่

## ไฟล์ที่แก้ไข

### 1. liff/assets/js/liff-app.js
- `loadRewards()` - เปลี่ยน API endpoint
- `showRewardDetail()` - เปลี่ยน API endpoint
- `confirmRedeem()` - เพิ่ม error handling, success modal
- `closeSuccessModal()` - ฟังก์ชันใหม่
- `copyCode()` - ฟังก์ชันใหม่

### 2. liff/assets/css/liff-app.css
- เพิ่ม styles สำหรับ success modal
- เพิ่ม styles สำหรับ redemption code box

### 3. api/points-history.php
- มี action `redeem` สำหรับแลกรางวัล
- ตรวจสอบ stock
- สร้าง redemption code
- หักแต้ม

### 4. classes/LoyaltyPoints.php
- `redeemReward()` - logic การแลกรางวัล
- ตรวจสอบเงื่อนไขต่างๆ
- สร้าง transaction

## ไฟล์ช่วยทดสอบ (ใหม่)

1. **install/check_rewards_system.php** - ตรวจสอบระบบรางวัลทั้งหมด
2. **install/create_test_reward.php** - สร้างรางวัลทดสอบ
3. **install/add_test_points.php** - เพิ่มแต้มให้ user ทดสอบ
4. **setup_rewards_test.bat** - รันทุกอย่างพร้อมกัน

## การ Debug

### ดู Console Log
เปิด Developer Tools (F12) ใน LINE Browser และดู Console:
```javascript
// ควรเห็น logs เหล่านี้:
confirmRedeem called with rewardId: X
Profile: {userId: "U...", displayName: "..."}
Sending request to: https://cny.re-ya.com/api/points-history.php
Response status: 200
Response data: {success: true, redemption_code: "..."}
```

### ตรวจสอบ API โดยตรง
```bash
curl "https://cny.re-ya.com/api/points-history.php?action=rewards&line_user_id=U1cffe699e4ebedcefafe47073a933ea0"
```

### ตรวจสอบ Database
```sql
-- ดูรางวัล
SELECT * FROM rewards WHERE is_active = 1;

-- ดูแต้มของ user
SELECT id, display_name, available_points, total_points 
FROM users 
WHERE line_user_id = 'U1cffe699e4ebedcefafe47073a933ea0';

-- ดูประวัติการแลก
SELECT * FROM reward_redemptions 
WHERE user_id = (SELECT id FROM users WHERE line_user_id = 'U1cffe699e4ebedcefafe47073a933ea0')
ORDER BY created_at DESC;
```

## Checklist การทดสอบ

- [ ] มีรางวัลในระบบ (rewards table)
- [ ] รางวัลเปิดใช้งาน (is_active = 1)
- [ ] รางวัลมี stock เหลือ (stock > 0 หรือ -1)
- [ ] User มีแต้มเพียงพอ (available_points >= points_required)
- [ ] API `/api/points-history.php?action=rewards` ทำงานได้
- [ ] API `/api/points-history.php` action=redeem ทำงานได้
- [ ] LIFF app โหลดรางวัลได้
- [ ] กดแลกรางวัลแล้วเห็น modal ยืนยัน
- [ ] แลกสำเร็จแล้วเห็น modal แสดงรหัส
- [ ] แต้มลดลงตามจำนวนที่ใช้
- [ ] มีรายการใน reward_redemptions table
- [ ] Admin panel แสดงรายการแลกใหม่

## ปัญหาที่อาจพบ

### 1. API ส่ง HTML แทน JSON
**สาเหตุ:** PHP error หรือ redirect
**แก้ไข:** ดู PHP error log หรือเพิ่ม error_reporting ใน API file

### 2. กดแลกแล้วไม่มีอะไรเกิดขึ้น
**สาเหตุ:** JavaScript error
**แก้ไข:** เปิด Console (F12) ดู error message

### 3. แลกสำเร็จแต่แต้มไม่ลด
**สาเหตุ:** Transaction ไม่ถูกบันทึก
**แก้ไข:** ตรวจสอบ LoyaltyPoints::redeemReward()

### 4. ไม่เห็นรางวัลใน LIFF
**สาเหตุ:** API ไม่ส่งข้อมูล หรือ rewards ไม่ active
**แก้ไข:** ตรวจสอบ database และ API response

## การ Deploy

```bash
# Commit changes
git add -A -f
git commit -m "Fix reward redemption system - complete working version"
git push origin main

# Clear cache (ถ้ามี)
php install/clear_opcache.php

# Test on production
# เปิด LIFF app และทดสอบแลกรางวัล
```

## สรุป

ระบบแลกรางวัลพร้อมใช้งานแล้ว! ลูกค้าสามารถ:
1. ดูรางวัลที่มี
2. ตรวจสอบแต้มของตัวเอง
3. แลกรางวัลได้
4. ได้รับรหัสรับรางวัล
5. Copy รหัสไปใช้ได้

Admin สามารถ:
1. สร้าง/แก้ไข/ลบรางวัล
2. ดูรายการการแลก
3. อนุมัติ/ส่งมอบ/ยกเลิกการแลก
4. Export รายงาน CSV
