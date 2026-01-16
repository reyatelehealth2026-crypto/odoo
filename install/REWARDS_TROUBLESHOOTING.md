# 🎁 คู่มือแก้ปัญหาระบบแลกรางวัล

## ปัญหา: หน้าแลกรางวัลแสดง "เร็วๆนี" หรือ "ยังไม่มีของรางวัล"

### สาเหตุที่เป็นไปได้

1. **ยังไม่มีรางวัลในระบบ** ⭐ (สาเหตุหลัก)
2. API ไม่ทำงาน
3. Database schema ไม่ครบ
4. LIFF ID ไม่ถูกต้อง

---

## วิธีแก้ไข

### 1. ตรวจสอบว่ามีรางวัลในระบบหรือไม่

```bash
# เข้าไปที่
https://your-domain.com/install/test_rewards_api.php
```

หรือตรวจสอบใน database:

```sql
SELECT * FROM rewards WHERE is_active = 1;
```

**ถ้าไม่มีรางวัล** → ไปขั้นตอนที่ 2

---

### 2. สร้างรางวัลตัวอย่าง

#### วิธีที่ 1: ใช้สคริปต์อัตโนมัติ (แนะนำ)

```bash
# เข้าไปที่
https://your-domain.com/install/create_sample_rewards.php
```

กดปุ่ม "สร้างรางวัลทั้งหมด" จะได้รางวัล 5 รายการ:
- 🎫 ส่วนลด 50 บาท (500 แต้ม)
- 🎫 ส่วนลด 100 บาท (1,000 แต้ม)
- 🚚 จัดส่งฟรี (300 แต้ม)
- 🎁 ของขวัญพิเศษ (2,000 แต้ม)
- 💊 ส่วนลด 20% (1,500 แต้ม)

#### วิธีที่ 2: เพิ่มผ่านหน้า Admin

```bash
# เข้าไปที่
https://your-domain.com/admin-rewards.php
```

กดปุ่ม "+ เพิ่มรางวัล" และกรอกข้อมูล:
- ชื่อรางวัล
- รายละเอียด
- แต้มที่ใช้แลก
- ประเภท (discount/shipping/gift/product/coupon)
- จำนวนสต็อก (-1 = ไม่จำกัด)

---

### 3. ตรวจสอบ API

```bash
# ทดสอบ API
https://your-domain.com/api/points-history.php?action=rewards&line_user_id=Utest123
```

**Response ที่ถูกต้อง:**
```json
{
  "success": true,
  "available_points": 0,
  "rewards": [
    {
      "id": 1,
      "name": "ส่วนลด 50 บาท",
      "points_required": 500,
      ...
    }
  ],
  "my_redemptions": []
}
```

**ถ้า API error:**
- ตรวจสอบ `classes/LoyaltyPoints.php` มีไฟล์หรือไม่
- ตรวจสอบ database connection
- ดู error log ใน browser console (F12)

---

### 4. ตรวจสอบ Database Schema

ตรวจสอบว่าตารางเหล่านี้มีหรือไม่:

```sql
SHOW TABLES LIKE 'rewards';
SHOW TABLES LIKE 'reward_redemptions';
SHOW TABLES LIKE 'points_transactions';
```

**ถ้าไม่มี** → รัน migration:

```bash
# เข้าไปที่
https://your-domain.com/install/run_loyalty_migration.php
```

หรือ import SQL:

```bash
mysql -u username -p database_name < database/migration_loyalty_points.sql
```

---

### 5. ตรวจสอบ LIFF Configuration

เปิด browser console (F12) ในหน้า LIFF และดู error:

**Error ที่พบบ่อย:**
- `LIFF ID not found` → ตั้งค่า LIFF ID ใน `shop_settings` table
- `User not found` → ต้อง login ผ่าน LINE LIFF
- `CORS error` → ตรวจสอบ domain ใน LIFF console

---

### 6. เพิ่มแต้มให้ User ทดสอบ

```sql
-- เพิ่มแต้มให้ user
UPDATE users 
SET available_points = 5000, total_points = 5000 
WHERE line_user_id = 'YOUR_LINE_USER_ID';
```

หรือใช้หน้า Admin:
```bash
https://your-domain.com/admin-points-settings.php
```

---

## การทดสอบ

### 1. ทดสอบ API
```bash
https://your-domain.com/install/test_rewards_api.php
```

### 2. ทดสอบหน้า LIFF
```bash
https://your-domain.com/liff-redeem-points.php?account=1
```

### 3. ตรวจสอบ Console Log
เปิด F12 → Console → ดู log:
```
Rewards API Response: {...}
User Points: 5000
Rewards Count: 5
```

---

## Checklist การแก้ปัญหา

- [ ] มีรางวัลในตาราง `rewards` (is_active = 1)
- [ ] API `/api/points-history.php?action=rewards` ทำงาน
- [ ] ตาราง database ครบ (rewards, reward_redemptions, points_transactions)
- [ ] LIFF ID ถูกต้องใน `shop_settings`
- [ ] User มีแต้มเพียงพอ (available_points > 0)
- [ ] Browser console ไม่มี error

---

## ไฟล์ที่เกี่ยวข้อง

- **LIFF Page:** `liff-redeem-points.php`
- **API:** `api/points-history.php`
- **Service Class:** `classes/LoyaltyPoints.php`
- **Admin Page:** `admin-rewards.php`
- **Migration:** `database/migration_loyalty_points.sql`

---

## ติดต่อ Support

หากยังแก้ไขไม่ได้ กรุณาส่งข้อมูลเหล่านี้:

1. Screenshot หน้า LIFF
2. Browser console log (F12)
3. API response จาก `test_rewards_api.php`
4. Database schema: `SHOW CREATE TABLE rewards;`

---

**อัพเดทล่าสุด:** 2026-01-16
