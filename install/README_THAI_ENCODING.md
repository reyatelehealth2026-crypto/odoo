# แก้ปัญหาภาษาไทยแสดงเป็น ??????? (Thai Encoding Fix)

## ปัญหา
เมื่อขึ้นระบบใหม่ ภาษาไทยในฐานข้อมูลแสดงเป็น `???????` แทนที่จะเป็นตัวอักษรไทย

## สาเหตุ
- Database หรือ table ไม่ได้ตั้งค่า charset เป็น `utf8mb4`
- ข้อมูลถูกบันทึกด้วย encoding ผิด

## วิธีแก้ไข

### วิธีที่ 1: ใช้สคริปต์อัตโนมัติ (แนะนำ)

เรียกไฟล์นี้ผ่าน browser หรือ command line:

**ผ่าน Browser:**
```
http://yourdomain.com/install/fix_thai_encoding_new_system.php
```

**ผ่าน Command Line:**
```bash
php install/fix_thai_encoding_new_system.php
```

สคริปต์จะทำการ:
1. ✓ ตั้งค่า database charset เป็น utf8mb4
2. ✓ แปลงตารางทั้งหมดเป็น utf8mb4
3. ✓ แก้ไข text columns เฉพาะที่มีภาษาไทย
4. ✓ ทดสอบการบันทึกและอ่านภาษาไทย

### วิธีที่ 2: แก้ไข Manual ผ่าน phpMyAdmin

1. เข้า phpMyAdmin
2. เลือก database ของคุณ
3. ไปที่แท็บ "Operations"
4. ในส่วน "Collation" เลือก `utf8mb4_unicode_ci`
5. คลิก "Go"

จากนั้นแก้แต่ละตาราง:
```sql
ALTER TABLE activity_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE contacts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- ทำซ้ำกับตารางอื่นๆ
```

### วิธีที่ 3: ใช้ SQL Command

รัน SQL commands เหล่านี้:

```sql
-- 1. แก้ database
ALTER DATABASE your_database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. แก้ตารางสำคัญ
ALTER TABLE activity_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE admin_users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE contacts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE business_items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE line_accounts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE templates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3. แก้ text columns เฉพาะ
ALTER TABLE activity_logs MODIFY description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE activity_logs MODIFY user_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE activity_logs MODIFY admin_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## ตรวจสอบว่าแก้ไขสำเร็จ

1. ไปที่หน้า Activity Logs: `http://yourdomain.com/activity-logs.php`
2. ทำ action ใหม่ (เช่น login, แก้ไขข้อมูล)
3. ดูว่าภาษาไทยแสดงถูกต้องหรือไม่

## หมายเหตุสำคัญ

⚠️ **ข้อมูลเก่าที่แสดง ???????**
- ข้อมูลเก่าที่ถูกบันทึกด้วย encoding ผิดไปแล้ว **ไม่สามารถกู้คืนได้**
- ต้องกรอกข้อมูลใหม่อีกครั้ง
- ข้อมูลใหม่ที่บันทึกหลังจากแก้ไขจะถูกต้อง

✓ **ข้อมูลใหม่**
- หลังจากแก้ไขแล้ว ข้อมูลใหม่จะบันทึกภาษาไทยได้ถูกต้อง
- ไม่ต้องทำอะไรเพิ่มเติม

## การป้องกันปัญหานี้ในอนาคต

ระบบได้ตั้งค่าไว้แล้วใน `config/database.php`:

```php
$this->conn = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
);
```

ตราบใดที่ไฟล์นี้ถูกต้อง ข้อมูลใหม่จะไม่มีปัญหา

## ตารางที่ได้รับการแก้ไข

สคริปต์จะแก้ไขตารางเหล่านี้:
- activity_logs
- admin_activity_log
- admin_users
- auto_reply_rules
- broadcast_messages
- business_items
- contacts
- customer_notes
- dev_logs
- faq_items
- health_articles
- landing_banners
- landing_seo_settings
- line_accounts
- messages
- pos_sales
- pos_sale_items
- products
- product_batches
- purchase_orders
- stock_movements
- suppliers
- templates
- testimonials
- transactions
- warehouse_locations
- wms_activity_logs

## ต้องการความช่วยเหลือ?

ถ้ายังมีปัญหา:
1. ตรวจสอบ error log ใน browser console หรือ PHP error log
2. ตรวจสอบว่า MySQL version รองรับ utf8mb4 (MySQL 5.5.3+)
3. ตรวจสอบสิทธิ์ในการแก้ไข database structure
