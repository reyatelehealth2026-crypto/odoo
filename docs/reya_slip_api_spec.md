# Re-Ya ↔ Odoo API Specification — Slip Payment + BDO Integration

**Version:** 2.0 (March 2026)
**Base URL:** `https://stg-erp.cnyrxapp.com` (Staging) / `https://cny.cnyrxapp.com` (Production)
**Auth:** Header `X-Api-Key: <api_key>`
**Format:** JSON-RPC (`Content-Type: application/json`)

> **Legacy Reference:** บางระบบยังอ้างอิง `https://erp.cnyrxapp.com` อยู่ชั่วคราว
> **Related Doc:** [reya_bdo_matching_workflow.md](reya_bdo_matching_workflow.md) — Workflow ละเอียด + ทุก Case + UI Mockups + Dashboard/Manual Matching Endpoints ที่พร้อมทดสอบบน staging
> **Staging-ready Endpoints:** `/reya/bdo/list`, `/reya/bdo/detail`, `/reya/bdo/statement-pdf/{id}`, `/reya/slip/match-bdo`, `/reya/slip/unmatch`

---

## 1. Upload Payment Slip — `POST /reya/slip/upload`

ลูกค้าส่งสลิปผ่าน LINE → Re-Ya เรียก endpoint นี้ → Odoo สร้าง Slip Inbox + auto-match

### Request

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "slip_image": "<base64_encoded_image>",
        "amount": 7929.00,
        "transfer_date": "2026-03-06",
        "bdo_id": 35576,
        "invoice_id": null,
        "order_id": null
    }
}
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `line_user_id` | string | **YES** | LINE User ID (Uxxxxxxxxx) |
| `slip_image` | string | **YES** | รูปสลิปเป็น Base64 encoded (JPEG/PNG) |
| `amount` | number | แนะนำ | ยอดเงินที่โอน — ถ้าส่งมา ระบบจะ match อัตโนมัติ |
| `transfer_date` | string | แนะนำ | วันที่โอน format `YYYY-MM-DD` |
| `bdo_id` | integer | ถ้ามี | Odoo BDO ID (ใบแจ้งยอดก่อนส่งของ) — **ใหม่!** |
| `invoice_id` | integer | ถ้ามี | Odoo Invoice ID |
| `order_id` | integer | ถ้ามี | Odoo Sale Order ID |

**ลำดับความสำคัญ:** `bdo_id` > `invoice_id` > `order_id` (ส่งอันเดียวก็ได้)

### Response — Success

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "success": true,
        "data": {
            "slip": {
                "id": 741458,
                "name": "slip_122974_20260306_120000.jpg",
                "partner_id": 122974,
                "partner_name": "ข้ามโขงเอ็กซ์เพรส (Inphiew pharmacy)",
                "amount": 7929.00,
                "transfer_date": "2026-03-06",
                "bdo_id": 35576,
                "invoice_id": null,
                "order_id": null,
                "status": "matched",
                "created_at": "2026-03-06T12:00:00Z",
                "slip_inbox_id": 111,
                "slip_inbox_name": "SLIP-2603-00111",
                "match_confidence": "bdo_prepayment",
                "bdo_name": "BDO2511-01778",
                "delivery_type": "private",
                "bdo_amount": 7929.00
            },
            "match_result": {
                "matched": true,
                "confidence": "bdo_prepayment",
                "amount_matched": 7929.00,
                "invoices_matched": 0,
                "bdo_matched": true,
                "reason": "Matched with BDO amount (prepayment)"
            }
        }
    }
}
```

### Response Fields — `slip` object

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Attachment ID |
| `slip_inbox_id` | int | Slip Inbox record ID |
| `slip_inbox_name` | string | เลขที่สลิป เช่น `SLIP-2603-00111` |
| `partner_id` | int | Customer ID |
| `partner_name` | string | ชื่อลูกค้า |
| `amount` | number | ยอดเงินที่โอน |
| `status` | string | สถานะ: `new`, `matched`, `payment_created`, `posted`, `done` |
| `match_confidence` | string | ระดับความตรง (ดูตารางด้านล่าง) |
| `bdo_id` | int/null | BDO ID ที่เชื่อมกัน |
| `bdo_name` | string | เลขที่ BDO เช่น `BDO2511-01778` (เฉพาะเมื่อส่ง bdo_id) |
| `delivery_type` | string | ประเภทขนส่ง: `company` (สายส่ง) / `private` (ขนส่งเอกชน) |
| `bdo_amount` | number | ยอดสุทธิที่ต้องชำระตาม BDO |

### Match Confidence Values

| Value | คำอธิบาย | status |
|-------|----------|--------|
| `exact` | ยอดตรง 100% กับ invoice | `matched` |
| `partial` | ชำระบางส่วน | `matched` |
| `multi` | ตรงหลาย invoice รวมกัน | `matched` |
| `bdo_prepayment` | **ใหม่!** ตรงกับยอด BDO (ขนส่งเอกชน จ่ายก่อนส่ง) | `matched` |
| `manual` | ต้อง match ด้วยมือ | `new` |
| `unmatched` | ไม่พบ invoice/BDO ที่ตรง | `new` |

### Response — Error

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "success": false,
        "error": {
            "code": "LINE_USER_NOT_LINKED",
            "message": "LINE user not linked to any account"
        }
    }
}
```

### Error Codes

| Code | Description |
|------|-------------|
| `UNAUTHORIZED` | API key ไม่ถูกต้อง |
| `MISSING_PARAMETER` | ขาด parameter ที่จำเป็น (line_user_id, slip_image) |
| `LINE_USER_NOT_LINKED` | LINE user ยังไม่ได้ link กับลูกค้าในระบบ |
| `BDO_NOT_FOUND` | ไม่พบ BDO ID ที่ส่งมา |
| `INVOICE_NOT_FOUND` | ไม่พบ Invoice ID |
| `ORDER_NOT_FOUND` | ไม่พบ Sale Order ID |
| `INVALID_IMAGE` | Base64 image data ไม่ถูกต้อง |

---

## 2. Check Payment Status — `POST /reya/payment/status`

เช็คสถานะการชำระเงินของ BDO/Invoice/Order

### Request

```json
{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "bdo_id": 35576
    }
}
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `line_user_id` | string | **YES** | LINE User ID |
| `bdo_id` | integer | ส่งอย่างน้อย 1 | BDO ID |
| `invoice_id` | integer | ส่งอย่างน้อย 1 | Invoice ID |
| `order_id` | integer | ส่งอย่างน้อย 1 | Sale Order ID |

### Response

```json
{
    "jsonrpc": "2.0",
    "id": 2,
    "result": {
        "success": true,
        "data": {
            "status": "unpaid",
            "status_display": "รอชำระเงิน",
            "amount_total": 7929.00,
            "amount_paid": 0.00,
            "amount_residual": 7929.00,
            "currency": "THB",
            "invoices": [
                {
                    "id": 50123,
                    "number": "HS26030001",
                    "state": "open",
                    "amount_total": 7929.00,
                    "amount_residual": 7929.00
                }
            ]
        }
    }
}
```

### Payment Status Values

| status | status_display | Description |
|--------|---------------|-------------|
| `paid` | ชำระเงินเรียบร้อยแล้ว | ชำระครบแล้ว |
| `partial` | ชำระบางส่วน | ชำระไม่ครบ |
| `unpaid` | รอชำระเงิน | ยังไม่ได้ชำระ |

---

## 3. Typical Flows

### Flow A: สายส่ง (Company Delivery) — จ่ายทีหลัง

```
1. Sales สร้าง BDO → ส่งของ → สร้าง Invoice
2. ลูกค้าได้รับของ → จ่ายเงินทีหลัง → ถ่ายสลิป
3. LINE Chat → Re-Ya รับรูป + ดึง bdo_id จาก context
4. Re-Ya → POST /reya/slip/upload
   {line_user_id, slip_image, amount, bdo_id}
5. Odoo: match กับ invoice ค้างชำระ → confidence=exact
6. Re-Ya แจ้งลูกค้า: "ได้รับสลิปแล้ว ✅ ยอด xxx บาท ตรงกับใบแจ้งหนี้"
```

### Flow B: ขนส่งเอกชน (Kerry/DHL) — จ่ายก่อนส่ง ⭐ ใหม่

```
1. Sales สร้าง BDO → ยังไม่มี Invoice (ยังไม่ส่งของ)
2. Re-Ya/LINE แจ้งลูกค้า: "กรุณาชำระเงินก่อนส่ง ยอด xxx บาท"
   พร้อมส่ง QR Code + bdo_id
3. ลูกค้าโอนเงิน → ถ่ายสลิป → ส่งกลับ LINE
4. Re-Ya → POST /reya/slip/upload
   {line_user_id, slip_image, amount, bdo_id}
5. Odoo: ไม่มี invoice → match กับ BDO amount → confidence=bdo_prepayment
6. Re-Ya แจ้งลูกค้า: "ได้รับสลิปแล้ว ✅ กำลังจัดส่งสินค้า"
7. Sales เห็น "ยืนยันชำระแล้ว" ใน BDO → กดส่งของได้
```

### Flow C: ส่งสลิปไม่มี reference (unmatched)

```
1. ลูกค้าส่งสลิปผ่าน LINE โดยไม่มี bdo_id/invoice_id
2. Re-Ya → POST /reya/slip/upload
   {line_user_id, slip_image, amount}
3. Odoo: ค้นหา open invoice ที่ตรงยอด → ถ้าเจอ → confidence=exact
   ถ้าไม่เจอ → confidence=unmatched → เจ้าหน้าที่ match ด้วยมือ
4. Re-Ya แจ้งลูกค้า: "ได้รับสลิปแล้ว รอตรวจสอบ"
```

---

## 4. How to Get BDO ID

BDO ID มาจากตอน Sales สร้างใบแจ้งยอด (BDO) ในระบบ Odoo

**วิธีที่ Re-Ya จะได้ bdo_id:**
- เมื่อ Sales กด "ยืนยัน" BDO → Odoo ส่ง webhook/notification ไป Re-Ya
  พร้อม `bdo_id`, `partner_id`, `amount`, `customer_name`
- Re-Ya เก็บ `bdo_id` ไว้ใน context ของ LINE chat กับลูกค้า
- เมื่อลูกค้าส่งสลิป → Re-Ya แนบ `bdo_id` ไปด้วย

**ถ้าลูกค้าส่งสลิปโดยไม่มี context:**
- ส่งแค่ `line_user_id` + `slip_image` + `amount`
- Odoo จะพยายาม match อัตโนมัติจาก partner + amount

---

## 5. Testing on Staging

```bash
# Base URL
STAGING_URL="https://stg-erp.cnyrxapp.com"
API_KEY="<staging_api_key>"

# Upload slip
curl -s -X POST $STAGING_URL/reya/slip/upload \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $API_KEY" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "call",
    "params": {
      "line_user_id": "U_test_user",
      "slip_image": "<base64_image>",
      "amount": 5000,
      "bdo_id": 41945
    }
  }'

# Check status
curl -s -X POST $STAGING_URL/reya/payment/status \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: $API_KEY" \
  -d '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "call",
    "params": {
      "line_user_id": "U_test_user",
      "bdo_id": 41945
    }
  }'
```

---

## 6. Notes for Re-Ya Developer

1. **ส่ง `bdo_id` เสมอถ้ามี** — ช่วยให้ Odoo match ได้แม่นยำที่สุด
2. **`amount` สำคัญมาก** — ถ้าไม่ส่ง amount ระบบ match ไม่ได้
3. **`slip_image` ต้องเป็น pure base64** — ไม่ต้องมี `data:image/jpeg;base64,` prefix
4. **Response เสมอมี `"jsonrpc": "2.0"` wrapper** — result อยู่ใน `result.data`
5. **Error อยู่ใน `result.error`** ไม่ใช่ HTTP error code (JSON-RPC format)
6. **ขนส่งเอกชน** — ลูกค้าต้องจ่ายก่อนส่ง ดังนั้นต้องส่ง bdo_id + amount ไปก่อน
   ถ้า match สำเร็จ (confidence=bdo_prepayment) → แจ้งลูกค้าว่าจะจัดส่งเร็วๆ นี้

---

## 7. Webhook จาก Odoo → Re-Ya (Existing)

เมื่อ BDO เปลี่ยนสถานะ Odoo จะส่ง webhook ไป Re-Ya โดยอัตโนมัติ

### Events ที่ส่ง

| Event | Trigger | Payload สำคัญ |
|-------|---------|--------------|
| `bdo.confirmed` | Sales กด "ยืนยัน" BDO | **bdo_id**, amount, customer, payment info, QR Code, Statement PDF |
| `bdo.done` | BDO เสร็จสิ้น (ส่งของแล้ว) | bdo_id, amount, customer |
| `bdo.cancelled` | BDO ถูกยกเลิก | bdo_id, customer |

### Webhook Payload — `bdo.confirmed` (สำคัญที่สุด)

```json
{
    "event": "bdo.confirmed",
    "timestamp": "2026-03-06T12:00:00Z",
    "data": {
        "bdo_id": 35576,
        "bdo_name": "BDO2511-01778",
        "old_state": "draft",
        "new_state": "waiting",
        "sale_order": {
            "id": 45678,
            "name": "SO/2026/12345"
        },
        "customer": {
            "id": 122974,
            "ref": "C00123",
            "name": "ร้านยาตัวอย่าง",
            "line_user_id": "U1234567890abcdef",
            "phone": "081-xxx-xxxx"
        },
        "salesperson": {
            "id": 15,
            "name": "สมชาย",
            "line_user_id": "Uabc..."
        },
        "amount_total": 7929.00,
        "currency": "THB",
        "financial_summary": {
            "outstanding_invoice_count": 2,
            "amount_outstanding_invoice": 5000.00,
            "credit_note_count": 0,
            "amount_credit_note": 0,
            "amount_deposit": 0,
            "amount_so_this_round": 2929.00,
            "selected_invoices": [
                {
                    "invoice_id": 50123,
                    "number": "HS26030001",
                    "amount_total": 3000.00,
                    "residual": 3000.00
                }
            ]
        },
        "payment": {
            "method": "promptpay",
            "method_display": "พร้อมเพย์",
            "amount": 7929.00,
            "reference": "BDO2511-01778",
            "promptpay": {
                "account": "0105564093141",
                "account_name": "บจก. ซี เอ็น วาย เฮลท์แคร์",
                "account_type": "TAX_ID",
                "qr_data": {
                    "raw_payload": "000201010212...",
                    "account": "0105564093141",
                    "amount": 7929.00,
                    "reference": "BDO2511-01778"
                }
            },
            "bank_transfer": {
                "bank_name": "ธนาคารกสิกรไทย",
                "bank_code": "KBANK",
                "account_number": "066-8-24681-6",
                "account_name": "บจก. ซี เอ็น วาย เฮลท์แคร์"
            }
        },
        "invoice": {
            "available": true,
            "invoice_id": 50123,
            "invoice_number": "HS26030001",
            "amount_total": 3000.00,
            "amount_residual": 3000.00
        },
        "statement_pdf": {
            "available": true,
            "filename": "BDO_BDO2511-01778_Statement.pdf",
            "content_type": "application/pdf",
            "data": "<base64_pdf_data>"
        }
    },
    "notify": {
        "customer": true,
        "salesperson": true
    },
    "message_template": {
        "customer": {
            "th": "ยืนยันจัดส่งออเดอร์ {order_name} กรุณาชำระเงิน {amount} บาท"
        }
    }
}
```

> **Key:** `data.bdo_id` = ต้องเก็บไว้ เพื่อส่งกลับตอน upload slip

---

## 8. สิ่งที่ Re-Ya Developer ต้องทำ ⭐

### 8A. เก็บ `bdo_id` จาก Webhook

เมื่อรับ webhook `bdo.confirmed`:
1. ดึง `data.bdo_id` + `data.customer.line_user_id`
2. **เก็บ `bdo_id` ไว้ใน context ของ LINE chat** กับลูกค้ารายนั้น
3. เมื่อลูกค้าส่งรูปสลิปกลับมา → ดึง `bdo_id` จาก context → ส่งไปกับ `/reya/slip/upload`

```
Webhook: bdo.confirmed → data.bdo_id = 35576, customer.line_user_id = "U1234..."
                                ↓
                    เก็บ context: {"U1234...": {bdo_id: 35576, amount: 7929}}
                                ↓
ลูกค้าส่งสลิป → POST /reya/slip/upload
                 {line_user_id: "U1234...", slip_image: "...", amount: 7929, bdo_id: 35576}
```

### 8B. Dashboard: แสดง `delivery_type` + `bdo_prepayment`

Slip API response ตอนนี้ return fields ใหม่:
- `delivery_type`: `"company"` (สายส่ง) / `"private"` (ขนส่งเอกชน)
- `match_confidence`: `"bdo_prepayment"` (ขนส่งเอกชน จ่ายก่อนส่ง)
- `bdo_name`: เลขที่ BDO
- `bdo_amount`: ยอดสุทธิตาม BDO

**แนะนำ:** เพิ่ม badge/column ใน dashboard สำหรับ `delivery_type` + `match_confidence`

### 8C. แจ้งลูกค้าตาม Confidence

| match_confidence | ข้อความแนะนำ |
|-----------------|-------------|
| `exact` | "ได้รับสลิปแล้ว ✅ ยอด {amount} บาท ตรงกับใบแจ้งหนี้" |
| `bdo_prepayment` | "ได้รับสลิปแล้ว ✅ กำลังจัดเตรียมสินค้าเพื่อจัดส่ง" |
| `partial` | "ได้รับสลิปแล้ว ⚠️ ยอดชำระ {amount} บาท ยังไม่ครบ" |
| `unmatched` | "ได้รับสลิปแล้ว ⏳ รอเจ้าหน้าที่ตรวจสอบ" |

### 8D. ส่ง QR Code ให้ลูกค้า (จาก Webhook)

เมื่อรับ `bdo.confirmed` webhook:
- ถ้า `data.payment.promptpay.qr_data` มี → generate QR image จาก `raw_payload`
- ส่ง QR + ข้อความ "กรุณาชำระเงิน {amount} บาท" ไปทาง LINE
- แนบ Statement PDF (`data.statement_pdf.data`) ถ้า `available: true`

### 8E. Check Payment Status (Optional)

หลังส่งสลิปไปแล้ว Re-Ya สามารถ poll สถานะ:
- `POST /reya/payment/status` + `bdo_id`
- ดู `status`: `paid` / `partial` / `unpaid`
- แจ้งลูกค้าเมื่อ status เปลี่ยน

---

## 9. Re-Ya Dashboard: Current Architecture

จากการวิเคราะห์ `odoo-dashboard.js`:

### Current Data Flow
```
Re-Ya Dashboard (JS) → whApiCall({action:'odoo_slips'}) → PHP Backend → Odoo
                      → whApiCall({action:'odoo_bdos'})  → PHP Backend → Odoo
```

### Current Slip Matching (Client-side)
- `matchSlipsToItems()` → 2-pass: exact → 5% tolerance
- Match by: `amount` + `transfer_date` proximity (≤180 days)
- **ยังไม่ใช้ `bdo_id` ในการ match** → ควรเพิ่ม

### แนะนำ: ใช้ `bdo_id` ในการ match
ถ้า slip มี `bdo_id` → match กับ BDO ตรง ไม่ต้อง fuzzy match
```javascript
// Instead of fuzzy matching:
if (slip.bdo_id && bdo.bdo_id === slip.bdo_id) {
    // Direct match — no need for amount/date comparison
    matchMap.set(bdoIndex, slip);
}
```

### Fields เพิ่มจาก Odoo Slip API
| Field | Type | ต้องแสดงใน Dashboard |
|-------|------|---------------------|
| `bdo_id` | int | ใช้ match กับ BDO |
| `bdo_name` | string | แสดงใน slip detail |
| `delivery_type` | string | badge: "สายส่ง" / "ขนส่งเอกชน" |
| `match_confidence` | string | badge: status ของ matching |
| `bdo_amount` | number | ยอดตาม BDO |

---

## 10. Staging Test Checklist for Re-Ya

```
✅ = พร้อมทดสอบบน Staging (stg-erp.cnyrxapp.com)

1. [ ] Webhook: Odoo → Re-Ya
   - สร้าง BDO บน staging → กดยืนยัน → Re-Ya ได้รับ bdo.confirmed?
   - bdo_id, amount, customer.line_user_id ครบ?

2. [ ] Slip Upload: Re-Ya → Odoo
   - POST /reya/slip/upload + bdo_id → ได้ bdo_prepayment?
   - POST /reya/slip/upload ไม่มี bdo_id → ได้ exact/unmatched?

3. [ ] Payment Status: Re-Ya → Odoo
   - POST /reya/payment/status + bdo_id → ได้ status?

4. [ ] Dashboard: ข้อมูลครบ
   - BDO tab แสดง BDO ที่สร้าง?
   - Slip tab แสดง slip ที่ upload + bdo_name?
```

---

*Document generated: 2026-03-06*
*Updated: 2026-03-06 — Added webhook payload, Re-Ya action items, dashboard analysis*
*Contact: consdevs | SOMZAA*
