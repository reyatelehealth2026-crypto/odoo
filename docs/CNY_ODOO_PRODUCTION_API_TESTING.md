# CNY Odoo Production API Testing Guide

**วันที่:** 2026-02-11  
**Module:** cny_reya_connector v11.0.1.2.0  
**Base URL:** `https://erp.cnyrxapp.com`  
**Webhook URL:** `https://stg.re-ya.com/api/webhook/odoo.php`

---

## 📋 สารบัญ

1. [Credentials & Environment](#1-credentials--environment)
2. [Health Check](#2-health-check)
3. [User Link](#3-user-link)
4. [User Unlink](#4-user-unlink)
5. [User Profile](#5-user-profile)
6. [Notification Settings](#6-notification-settings)
7. [Get Orders](#7-get-orders)
8. [Order Detail](#8-order-detail)
9. [Order Tracking](#9-order-tracking)
10. [Get Invoices](#10-get-invoices)
11. [Credit Status](#11-credit-status)
12. [Slip Upload](#12-slip-upload)
13. [Payment Status](#13-payment-status)
14. [Webhook Verification](#14-webhook-verification)
15. [Error Code Reference](#15-error-code-reference)

---

## 1. Credentials & Environment

```
Base URL:        https://erp.cnyrxapp.com
API Key:         5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A
Webhook Secret:  cny_reya_webhook_3226e48c7c053b4af44011651f2d3cfb
```

> **⚠️ Postman Correction:** ทุก request (ยกเว้น Health Check) ต้องมี header:  
> `X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A`

> **⚠️ Postman Correction:** ทุก request body ต้องเป็น **JSON-RPC 2.0** format:
> ```json
> {
>   "jsonrpc": "2.0",
>   "params": { ... }
> }
> ```

---

## 2. Health Check

**Endpoint:** `POST /reya/health`  
**Auth:** ไม่ต้องใช้ API Key

### curl
```bash
curl -X POST "https://erp.cnyrxapp.com/reya/health" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","params":{}}'
```

### Postman Corrections
- Method: `POST` (ไม่ใช่ GET)
- ไม่ต้องใส่ `X-Api-Key` header
- Body type: `raw` → `JSON`

### Expected Response
```json
{
  "jsonrpc": "2.0",
  "result": {
    "status": "ok",
    "version": "11.0.1.2.0",
    "module": "cny_reya_connector"
  }
}
```

---

## 3. User Link

**Endpoint:** `POST /reya/user/link`  
**Auth:** API Key required

### ⚠️ v11.0.1.2.0 Change
เมื่อใช้ `customer_code` ต้องส่ง `phone` ด้วย**เสมอ**

### 3.1 Link ด้วย customer_code + phone (แนะนำ)

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/link" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "customer_code": "PC200134",
      "phone": "0849915142"
    }
  }'
```

### Postman Corrections
- Header `X-Api-Key` ใส่ค่า API Key (ไม่ใช่ Bearer token)
- `phone` field เป็น **required** เมื่อใช้ `customer_code`
- phone ต้องตรงกับในระบบ Odoo มิฉะนั้นจะได้ `PHONE_MISMATCH`

### 3.2 Link ด้วย phone อย่างเดียว

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/link" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "phone": "0849915142"
    }
  }'
```

### 3.3 ❌ Error Test: ขาด phone เมื่อใช้ customer_code

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/link" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "customer_code": "PC200134"
    }
  }'
```

**Expected Error:**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "success": false,
    "error": {
      "code": "PHONE_REQUIRED",
      "message": "เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน"
    }
  }
}
```

### 3.4 ❌ Error Test: phone ไม่ตรง

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/link" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "customer_code": "PC200134",
      "phone": "0800000000"
    }
  }'
```

**Expected Error:**
```json
{
  "jsonrpc": "2.0",
  "result": {
    "success": false,
    "error": {
      "code": "PHONE_MISMATCH",
      "message": "เบอร์โทรศัพท์ไม่ตรงกับข้อมูลลูกค้า กรุณาตรวจสอบรหัสลูกค้าและเบอร์โทรศัพท์"
    }
  }
}
```

### Success Response
```json
{
  "jsonrpc": "2.0",
  "result": {
    "success": true,
    "partner_id": 12345,
    "partner_name": "บริษัท ตัวอย่าง จำกัด",
    "customer_code": "PC200134"
  }
}
```

---

## 4. User Unlink

**Endpoint:** `POST /reya/user/unlink`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/unlink" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456"
    }
  }'
```

### Postman Corrections
- ต้อง link ก่อนถึงจะ unlink ได้
- ถ้ายังไม่ link จะได้ error `NOT_LINKED`

---

## 5. User Profile

**Endpoint:** `POST /reya/user/profile`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/profile" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456"
    }
  }'
```

### Postman Corrections
- ต้อง link ก่อนถึงจะดู profile ได้
- Response จะมี credit_limit, credit_used

---

## 6. Notification Settings

**Endpoint:** `POST /reya/user/notification`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/user/notification" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "enabled": true
    }
  }'
```

### Postman Corrections
- `enabled` เป็น boolean (`true`/`false`) ไม่ใช่ string

---

## 7. Get Orders

**Endpoint:** `POST /reya/orders`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/orders" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "limit": 10
    }
  }'
```

### Postman Corrections
- `limit` เป็น optional (default: 20)
- Optional params: `state`, `date_from`, `date_to`, `offset`

---

## 8. Order Detail

**Endpoint:** `POST /reya/order/detail`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/order/detail" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "order_id": 1234
    }
  }'
```

### Postman Corrections
- `order_id` ต้องเป็น ID จริงจาก Get Orders response
- จะได้ `CUSTOMER_MISMATCH` ถ้า order ไม่ใช่ของ user นี้

---

## 9. Order Tracking

**Endpoint:** `POST /reya/order/tracking`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/order/tracking" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "order_id": 1234
    }
  }'
```

---

## 10. Get Invoices

**Endpoint:** `POST /reya/invoices`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/invoices" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "limit": 10
    }
  }'
```

### Postman Corrections
- Optional params: `state`, `limit`, `offset`

---

## 11. Credit Status

**Endpoint:** `POST /reya/credit-status`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/credit-status" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456"
    }
  }'
```

---

## 12. Slip Upload

**Endpoint:** `POST /reya/slip/upload`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/slip/upload" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "slip_image": "BASE64_ENCODED_IMAGE_STRING",
      "bdo_id": 1,
      "amount": 15000.00,
      "transfer_date": "2026-02-11"
    }
  }'
```

### Postman Corrections
- `slip_image` ต้องเป็น base64 string ของรูปภาพ
- ห้ามใส่ prefix `data:image/jpeg;base64,` ใน base64 string
- Optional params: `bdo_id`, `invoice_id`, `amount`, `transfer_date`

---

## 13. Payment Status

**Endpoint:** `POST /reya/payment/status`  
**Auth:** API Key required

```bash
curl -X POST "https://erp.cnyrxapp.com/reya/payment/status" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: 5pG-doAH1EEqHXurM3t466WAgKRYemmK7FANAB74o5A" \
  -d '{
    "jsonrpc": "2.0",
    "params": {
      "line_user_id": "Utest123456",
      "order_id": 1234
    }
  }'
```

### Postman Corrections
- สามารถ query ด้วย `order_id`, `bdo_id`, หรือ `invoice_id` อย่างใดอย่างหนึ่ง

---

## 14. Webhook Verification

### Odoo ส่ง Headers
```
X-Odoo-Signature: sha256=xxxxxxxxxxxxxx
X-Odoo-Timestamp: 1707200000
X-Odoo-Event: order.validated
X-Odoo-Delivery-Id: wh_abc123
```

### PHP Signature Verification
```php
$secret = 'cny_reya_webhook_3226e48c7c053b4af44011651f2d3cfb';
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ODOO_SIGNATURE'] ?? '';

// Calculate expected signature
$expected_sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($expected_sig, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

### Postman Pre-request Script สำหรับ Webhook
```javascript
// ใส่ใน Pre-request Script ของ Postman
const secret = pm.collectionVariables.get('webhook_secret');
const body = pm.request.body.raw;
const hmac = CryptoJS.HmacSHA256(body, secret).toString();
pm.request.headers.upsert({
    key: 'X-Odoo-Signature', 
    value: 'sha256=' + hmac
});
pm.request.headers.upsert({
    key: 'X-Odoo-Timestamp', 
    value: Math.floor(Date.now()/1000).toString()
});
```

### curl สำหรับ Webhook Test (ต้องคำนวณ signature ก่อน)
```bash
# Step 1: สร้าง payload
PAYLOAD='{"event":"order.validated","data":{"order_id":1234,"order_name":"SO/2026/001","order_ref":"SO/2026/001","amount_total":15000,"customer":{"partner_id":100,"name":"Test Customer"},"salesperson":{"partner_id":200,"name":"Test Sales"}},"notify":{"customer":true,"salesperson":true},"message_template":{"customer":{"th":"ออเดอร์ {order_name} ยืนยันแล้ว"},"salesperson":{"th":"ออเดอร์ใหม่ {order_name}"}}}'

# Step 2: คำนวณ signature
SECRET='cny_reya_webhook_3226e48c7c053b4af44011651f2d3cfb'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
TIMESTAMP=$(date +%s)

# Step 3: ส่ง webhook
curl -X POST "https://stg.re-ya.com/api/webhook/odoo.php" \
  -H "Content-Type: application/json" \
  -H "X-Odoo-Signature: sha256=$SIGNATURE" \
  -H "X-Odoo-Timestamp: $TIMESTAMP" \
  -H "X-Odoo-Event: order.validated" \
  -H "X-Odoo-Delivery-Id: wh_test_$(date +%s)" \
  -d "$PAYLOAD"
```

### Postman Corrections สำหรับ Webhook
1. **Signature ต้องคำนวณจาก body ทั้งหมด** — ไม่ใช่ hardcode
2. ใช้ **Pre-request Script** ข้างบนเพื่อคำนวณอัตโนมัติ
3. `X-Odoo-Delivery-Id` ต้องไม่ซ้ำ (ใช้ `{{$randomUUID}}` ใน Postman)
4. Timestamp ต้องอยู่ในช่วง 5 นาที

---

## 15. Error Code Reference

### User Link Errors (v11.0.1.2.0)

| Code | HTTP | Message (TH) | สาเหตุ |
|------|------|---------------|--------|
| `PHONE_REQUIRED` | 200 | เมื่อใช้รหัสลูกค้า ต้องระบุเบอร์โทรศัพท์เพื่อยืนยันตัวตน | ส่ง customer_code แต่ไม่ส่ง phone |
| `PHONE_MISMATCH` | 200 | เบอร์โทรศัพท์ไม่ตรงกับข้อมูลลูกค้า | phone ไม่ตรงกับในระบบ |
| `CUSTOMER_CODE_NOT_FOUND` | 200 | ไม่พบรหัสลูกค้า | customer_code ไม่มีในระบบ |
| `ALREADY_LINKED` | 200 | บัญชี LINE นี้เชื่อมต่อกับบัญชีอื่นแล้ว | line_user_id ผูกไว้แล้ว |
| `NOT_LINKED` | 200 | กรุณาเชื่อมต่อบัญชี Odoo ก่อน | ยังไม่ได้ผูกบัญชี |

### General API Errors

| Code | Message (TH) |
|------|---------------|
| `MISSING_API_KEY` | ไม่พบ API Key |
| `INVALID_API_KEY` | API Key ไม่ถูกต้อง |
| `MISSING_PARAMETER` | ข้อมูลไม่ครบถ้วน |
| `LINE_USER_NOT_LINKED` | กรุณาเชื่อมต่อบัญชี Odoo ก่อน |
| `PARTNER_NOT_FOUND` | ไม่พบข้อมูลลูกค้า |
| `ORDER_NOT_FOUND` | ไม่พบออเดอร์ |
| `CUSTOMER_MISMATCH` | ออเดอร์ไม่ใช่ของ user นี้ |
| `RATE_LIMIT_EXCEEDED` | เรียกใช้มากเกินไป |

---

## Webhook Events Reference

| Event | Description | Key Payload Fields |
|-------|-------------|-------------------|
| `order.validated` | ยืนยัน SO | order_id, order_name, amount |
| `order.picker_assigned` | มอบหมาย Picker | order_id, picker_name |
| `order.picking` | กำลังหยิบสินค้า | order_id, state |
| `order.packed` | แพ็คเรียบร้อย | order_id |
| `order.to_delivery` | รอจัดส่ง | order_id |
| `order.in_delivery` | กำลังส่ง | order_id, driver |
| `order.delivered` | ส่งสำเร็จ | order_id |
| `invoice.created` | สร้างใบแจ้งหนี้ | invoice_id, amount, due_date |
| `invoice.overdue` | เกินกำหนด | invoice_id, days_overdue |
| `invoice.paid` | ชำระแล้ว | invoice_id, amount |
| `bdo.confirmed` | ยืนยัน BDO | bdo_id, qr_code |
| `delivery.departed` | รถออก | delivery_id, driver, orders |
| `delivery.completed` | ส่งเสร็จ | delivery_id |

---

## Postman Import Instructions

1. เปิด Postman → **Import** → **File**
2. เลือกไฟล์ `CNY_Odoo_Production_Postman.json`
3. Collection จะถูก import พร้อม variables
4. Variables ที่ต้องตรวจสอบ:
   - `base_url` = `https://erp.cnyrxapp.com`
   - `api_key` = API Key ที่ได้รับ
   - `webhook_secret` = Webhook secret
   - `test_line_user_id` = LINE User ID สำหรับทดสอบ

---

*เอกสารนี้สร้างอัตโนมัติ เมื่อ 2026-02-11 — CNY Odoo v11.0.1.2.0*
