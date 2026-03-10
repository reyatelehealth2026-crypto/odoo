# CNY Odoo Production Integration Summary

**วันที่ดำเนินการ:** 2026-02-11  
**Module:** cny_reya_connector v11.0.1.2.0  
**Status:** ✅ Code Changes Complete — Pending Deployment & E2E Testing

---

## สรุปการเปลี่ยนแปลงทั้งหมด

### Task 1: ✅ อัพเดต Production Credentials

**ไฟล์ที่แก้ไข:** `config/config.php`

| รายการ | เดิม | ใหม่ |
|--------|------|------|
| ODOO_ENVIRONMENT | `staging` | `production` |
| ODOO_PRODUCTION_API_KEY | _(ว่าง)_ | `5pG-doAH1EEq...` |
| ODOO_PRODUCTION_WEBHOOK_SECRET | _(ว่าง)_ | `cny_reya_webhook_3226...` |
| ODOO_PRODUCTION_API_BASE_URL | `https://erp.cnyrxapp.com` | _(ไม่เปลี่ยน)_ |

**ผลลัพธ์:** ระบบเชื่อมต่อกับ Odoo Production Server โดยอัตโนมัติ

---

### Task 2: ✅ บังคับส่ง phone เมื่อใช้ customer_code (Security Enhancement)

**เหตุผล:** ป้องกันการผูกบัญชีผิดคน เมื่อลูกค้าพิมพ์รหัสลูกค้าผิด

#### ไฟล์ที่แก้ไข

| ไฟล์ | การเปลี่ยนแปลง |
|------|----------------|
| `api/odoo-user-link.php` | เพิ่ม validation: ถ้ามี `customer_code` ต้องมี `phone` ด้วย |
| `classes/OdooAPIClient.php` | เพิ่ม error codes: `PHONE_REQUIRED`, `PHONE_MISMATCH`, `CUSTOMER_CODE_NOT_FOUND` |
| `liff/odoo-link.php` | เพิ่ม input field "เบอร์โทรศัพท์" ใน tab รหัสลูกค้า + frontend validation |

#### Error Codes ใหม่

| Code | Message |
|------|---------|
| `PHONE_REQUIRED` | เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน |
| `PHONE_MISMATCH` | เบอร์โทรศัพท์ไม่ตรงกับข้อมูลลูกค้า |
| `CUSTOMER_CODE_NOT_FOUND` | ไม่พบรหัสลูกค้า |

#### Flow ใหม่
```
ลูกค้ากรอกรหัสลูกค้า + เบอร์โทร
  → Re-Ya validate ว่ามี phone
  → ส่งไป Odoo API /reya/user/link
  → Odoo ตรวจสอบ customer_code + phone ตรงกัน
  → ผูกบัญชีสำเร็จ ✅
```

---

### Task 3: ✅ ตั้งค่า Webhook Secret & Signature Verification

**ไฟล์ที่แก้ไข:** `classes/OdooWebhookHandler.php`

#### การเปลี่ยนแปลง

| รายการ | เดิม | ใหม่ |
|--------|------|------|
| Signature format | `timestamp.payload` | `payload` only (ตาม Odoo docs) |
| Fallback | ไม่มี | รองรับ legacy format ด้วย |
| Error logging | แค่ return false | เพิ่ม detailed error log |

#### Signature Verification Flow
```
1. คำนวณ sha256=hash_hmac('sha256', $payload, $secret)  ← Primary
2. ถ้าไม่ตรง → ลอง sha256=hash_hmac('sha256', timestamp.payload, $secret)  ← Fallback
3. ถ้าไม่ตรงทั้ง 2 → return false + log error
```

---

### Task 4: ✅ Postman Collection & API Testing Documentation

#### ไฟล์ที่สร้างใหม่

| ไฟล์ | รายละเอียด |
|------|-----------|
| `docs/CNY_Odoo_Production_Postman.json` | Postman Collection v2.1 — 14 requests, 5 folders |
| `docs/CNY_ODOO_PRODUCTION_API_TESTING.md` | API Testing Guide ครบทุก endpoint |

#### Postman Collection ประกอบด้วย

| Folder | Requests |
|--------|----------|
| 1. Health Check | Health Check (No Auth) |
| 2. User Management | Link (code+phone), Link (phone), Link Error Test, Unlink, Profile, Notification |
| 3. Customer Data | Orders, Order Detail, Order Tracking, Invoices, Credit Status |
| 4. Payment | Slip Upload, Payment Status |
| 5. Webhook Simulation | order.validated, invoice.created (พร้อม Pre-request Script คำนวณ HMAC) |

#### Postman Correction Notes สำคัญ

1. **ทุก request ใช้ `POST`** — ไม่มี GET
2. **Body ต้องเป็น JSON-RPC 2.0** format (`jsonrpc` + `params`)
3. **Auth ใช้ `X-Api-Key` header** — ไม่ใช่ Bearer token
4. **Webhook signature คำนวณจาก body ทั้งหมด** — ใช้ Pre-request Script
5. **`phone` เป็น required** เมื่อใช้ `customer_code` (v11.0.1.2.0)

---

## สรุปไฟล์ทั้งหมดที่เปลี่ยนแปลง

### Modified (4 ไฟล์)
| ไฟล์ | ประเภท |
|------|--------|
| `config/config.php` | Production credentials |
| `api/odoo-user-link.php` | Phone validation |
| `classes/OdooAPIClient.php` | Error codes |
| `classes/OdooWebhookHandler.php` | Signature verification |
| `liff/odoo-link.php` | LIFF UI + JS logic |

### New (2 ไฟล์)
| ไฟล์ | ประเภท |
|------|--------|
| `docs/CNY_Odoo_Production_Postman.json` | Postman Collection |
| `docs/CNY_ODOO_PRODUCTION_API_TESTING.md` | API Testing Guide |

---

## Next Steps

| # | Task | Owner | Status |
|---|------|-------|--------|
| 1 | ~~อัพเดต Re-Ya ให้ส่ง phone เมื่อใช้ customer_code~~ | Re-Ya | ✅ Done |
| 2 | ~~ตั้งค่า Webhook Secret ใน Re-Ya~~ | Re-Ya | ✅ Done |
| 3 | ~~ทดสอบ Webhook signature verification~~ | Re-Ya | ✅ Code Ready |
| 4 | Deploy to staging & ทดสอบ End-to-end flow | ทั้งสองฝ่าย | ⏳ Pending |
| 5 | Import Postman Collection & ทดสอบทุก endpoint | Re-Ya | ⏳ Pending |

---

## Contact

**CNY Development Team**
- Module: cny_reya_connector v11.0.1.2.0
- Server: https://erp.cnyrxapp.com

**Re-Ya Integration**
- Webhook: https://stg.re-ya.com/api/webhook/odoo.php
- Updated: 2026-02-11

---

> **⚠️ หมายเหตุ:** เอกสารนี้มีข้อมูล credentials สำคัญ กรุณาเก็บรักษาอย่างปลอดภัย
