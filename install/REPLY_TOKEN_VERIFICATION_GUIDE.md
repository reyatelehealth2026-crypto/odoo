# คู่มือตรวจสอบ Reply Token Storage

## สถานการณ์ปัจจุบัน

ระบบได้อัพเดทโค้ดใน `webhook.php` เพื่อบันทึก reply_token ลงใน users table แล้ว แต่จากการตรวจสอบพบว่า:

- ✅ โค้ดใหม่ถูก commit และ push ไปยัง production แล้ว
- ✅ Messages table มี reply_token บันทึกอยู่ (ข้อความเก่าวันที่ 16 ม.ค.)
- ❌ Messages ใหม่ (วันที่ 18 ม.ค.) **ไม่มี** reply_token
- ❌ Users table **ไม่มี** reply_token เลย

**สรุป:** Webhook บน production ยังคงรันโค้ดเวอร์ชันเก่าอยู่

## เครื่องมือตรวจสอบที่สร้างไว้

### 1. `install/verify_webhook_version.php`
ตรวจสอบว่า webhook.php บน production มีโค้ดเวอร์ชันล่าสุดหรือไม่

**วิธีใช้:**
```
https://emp.re-ya.net/install/verify_webhook_version.php
```

**จะตรวจสอบ:**
- ✓ มีโค้ด error logging ใหม่หรือไม่
- ✓ ใช้เวลา expiry 50 วินาทีหรือไม่
- ✓ ลบ SHOW COLUMNS check ออกแล้วหรือไม่
- ✓ ตรวจสอบ error log ว่ามีข้อความ "Reply token saved" หรือไม่
- ✓ ตรวจสอบ database ว่ามี token บันทึกหรือไม่

### 2. `install/test_reply_token_live.php`
ทดสอบการบันทึก reply_token โดยตรงเพื่อยืนยันว่า database และ query ทำงานได้

**วิธีใช้:**
```
https://emp.re-ya.net/install/test_reply_token_live.php
```

**จะทดสอบ:**
- ✓ บันทึก token ลง messages table
- ✓ บันทึก token ลง users table
- ✓ ตรวจสอบเวลา expiry
- ✓ Verify ว่า token ถูกบันทึกถูกต้อง

## ขั้นตอนการแก้ไข

### ขั้นตอนที่ 1: ตรวจสอบเวอร์ชันโค้ด
1. เปิด `https://emp.re-ya.net/install/verify_webhook_version.php`
2. ดูว่าโค้ดเวอร์ชันใหม่ถูกโหลดหรือไม่
3. ถ้าโค้ดยังเป็นเวอร์ชันเก่า → ไปขั้นตอนที่ 2

### ขั้นตอนที่ 2: ทดสอบ Database
1. เปิด `https://emp.re-ya.net/install/test_reply_token_live.php`
2. ดูว่า database สามารถบันทึก token ได้หรือไม่
3. ถ้าทดสอบผ่าน แต่ webhook ยังไม่บันทึก → ปัญหาอยู่ที่ webhook cache

### ขั้นตอนที่ 3: Clear Cache (ถ้าจำเป็น)

**วิธีที่ 1: Clear OPcache ผ่าน cPanel**
1. เข้า cPanel → MultiPHP INI Editor
2. เลือก domain: emp.re-ya.net
3. หา `opcache.enable` และเปลี่ยนเป็น `Off`
4. Save
5. รอ 1-2 นาที
6. เปลี่ยนกลับเป็น `On`

**วิธีที่ 2: Clear OPcache ผ่าน Script**
```
https://emp.re-ya.net/install/clear_opcache.php
```

**วิธีที่ 3: Restart PHP-FPM (ต้องใช้ SSH)**
```bash
ssh zrismpsz@z129720-ri35sm.ps09.zwhhosting.com -p 9922
cd ~/public_html/emp.re-ya.net
# ใน cPanel อาจต้องใช้ "Restart PHP-FPM" จาก MultiPHP Manager
```

**วิธีที่ 4: Touch webhook.php (บังคับให้ reload)**
```bash
ssh zrismpsz@z129720-ri35sm.ps09.zwhhosting.com -p 9922
cd ~/public_html/emp.re-ya.net
touch webhook.php
```

### ขั้นตอนที่ 4: ทดสอบด้วยข้อความจริง
1. ส่งข้อความทดสอบผ่าน LINE app
2. รอ 5-10 วินาที
3. Refresh `verify_webhook_version.php`
4. ดูว่า:
   - Messages table มี reply_token หรือไม่
   - Users table มี reply_token หรือไม่
   - Error log มีข้อความ "Reply token saved for user..." หรือไม่

### ขั้นตอนที่ 5: ตรวจสอบ Error Log

**ตำแหน่ง Error Log ที่เป็นไปได้:**
- `/home/zrismpsz/public_html/emp.re-ya.net/error_log`
- `/home/zrismpsz/logs/error_log`
- ใน cPanel → Errors

**ข้อความที่ควรเห็น (ถ้าโค้ดใหม่ทำงาน):**
```
Reply token saved for user 123, expires: 2026-01-18 12:34:56
```

**ข้อความที่บ่งบอกปัญหา:**
```
Reply token save failed: [error message]
```

## สาเหตุที่เป็นไปได้

### 1. OPcache ยังคง Cache โค้ดเก่า
**อาการ:** โค้ดใน file ถูกต้อง แต่ PHP ยังรันโค้ดเก่า
**วิธีแก้:** Clear OPcache (ดูขั้นตอนที่ 3)

### 2. Auto-deployment ไม่ทำงาน
**อาการ:** Git push สำเร็จ แต่ไฟล์บน server ไม่อัพเดท
**วิธีแก้:** 
- ตรวจสอบ cPanel → Git Version Control → Pull/Deploy
- หรือ SSH เข้าไปทำ `git pull` เอง

### 3. Webhook ใช้ไฟล์คนละที่
**อาการ:** แก้ไข webhook.php แล้ว แต่ LINE เรียกไฟล์อื่น
**วิธีแก้:** 
- ตรวจสอบ LINE webhook URL ว่าชี้ไปที่ไหน
- อาจมี webhook.php หลายไฟล์ (เช่น `/webhook.php` และ `/api/webhook.php`)

### 4. PHP Error ทำให้โค้ดไม่ทำงาน
**อาการ:** Webhook รับ event แต่ไม่บันทึก token
**วิธีแก้:** 
- ตรวจสอบ error_log
- ดู `verify_webhook_version.php` ว่ามี error อะไร

## การตรวจสอบว่าแก้ไขสำเร็จ

เมื่อแก้ไขเสร็จ จะเห็น:

1. ✅ `verify_webhook_version.php` แสดงว่าโค้ดเวอร์ชันใหม่ถูกโหลด
2. ✅ ส่งข้อความทดสอบแล้ว messages table มี reply_token
3. ✅ Users table มี reply_token พร้อม expiry time ~50 วินาที
4. ✅ Error log มีข้อความ "Reply token saved for user..."

## ประโยชน์ของการแก้ไข

เมื่อ reply_token ถูกบันทึกใน users table:

- ✅ ระบบสามารถใช้ Reply API (ฟรี) แทน Push API (เสียเงิน)
- ✅ ตอบกลับข้อความได้ทันทีภายใน 1 นาที
- ✅ ประหยัด LINE message quota
- ✅ ปรับปรุง user experience (ตอบเร็วขึ้น)

## ติดต่อ/ขอความช่วยเหลือ

ถ้ายังแก้ไม่ได้ ให้:

1. เปิด `verify_webhook_version.php` และ screenshot
2. เปิด `test_reply_token_live.php` และ screenshot
3. ส่งข้อความทดสอบผ่าน LINE
4. ตรวจสอบ error_log และ copy ข้อความล่าสุด 20 บรรทัด
5. แจ้งผลการตรวจสอบทั้งหมด

---

**สร้างเมื่อ:** 2026-01-18  
**เวอร์ชัน:** 1.0  
**ไฟล์ที่เกี่ยวข้อง:** `webhook.php`, `install/verify_webhook_version.php`, `install/test_reply_token_live.php`
