# Re-Ya ↔ Odoo: BDO Matching Workflow — Complete Design

**Version:** 1.0 (March 2026)
**สำหรับ:** Re-Ya Developer + CNY Sales Team
**เป้าหมาย:** Sales ทำ matching ที่ Re-Ya ที่เดียว → Odoo auto-process + validate

---

## 1. ภาพรวม (Overview)

### ระบบเกี่ยวข้อง

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│   ลูกค้า (LINE)   │────▶│    Re-Ya Bot      │────▶│    Odoo ERP       │
│                  │     │  + Dashboard      │     │  (BDO, Invoice,  │
│  ส่งสลิป/ถ่ายรูป  │     │  (Sales ทำ match)  │     │   Payment)       │
└──────────────────┘     └──────────────────┘     └──────────────────┘
```

### หลักการ

| ส่วน | หน้าที่ | ใคร |
|------|---------|-----|
| **Re-Ya Dashboard** | จับคู่สลิป ↔ BDO (Primary Workplace) | Sales |
| **Odoo Slip Inbox** | Auto-process + validate + สร้าง payment | ระบบ (auto/semi) |
| **Odoo BDO Form** | ตรวจสอบสถานะ + payment guard | Admin/Sales (view only) |

---

## 2. BDO คืออะไร? (สำหรับ Re-Ya Dev)

**BDO (Bill/ใบแจ้งยอดก่อนส่งของ)** = เอกสารสรุปยอดที่ลูกค้าต้องชำระ ก่อนหรือหลังส่งของ

### BDO ประกอบด้วย

```
BDO2603-00437 (ร้านศูนย์ยาเติมสุข)
├── รายการสั่งซื้อ (SO Lines)
│   ├── SO2602-05900: ยา A x 10, ยา B x 5 = ฿8,500
│   └── SO2602-05901: ยา C x 20         = ฿4,200
│
├── ใบแจ้งหนี้ค้างชำระ (Outstanding Invoices)
│   ├── HS26020001: ฿3,000 (ค้างจากรอบก่อน)
│   └── HS26020002: ฿1,450 (ค้างจากรอบก่อน)
│
├── ใบลดหนี้ (Credit Notes)
│   └── CN26020001: -฿500 (คืนสินค้า)
│
├── เงินมัดจำ (Deposits)
│   └── DP26020001: -฿700
│
└── สรุปยอดสุทธิ (Net to Pay)
    = ฿8,500 + ฿4,200 + ฿3,000 + ฿1,450 - ฿500 - ฿700
    = ฿15,950
```

### BDO States

```
draft (แบบร่าง) → waiting (ยืนยันแล้ว/รอจัดส่ง) → done (ส่งของแล้ว)
                                                  → cancel (ยกเลิก)
```

### ประเภทขนส่ง (สำคัญ!)

| ประเภท | ชื่อ | จ่ายเงิน | BDO Flow |
|--------|------|---------|----------|
| **สายส่ง** (company) | รถส่งของบริษัท | **ทีหลัง** (เครดิต 3-7 วัน) | BDO → ส่งของ → Invoice → ลูกค้าจ่าย |
| **ขนส่งเอกชน** (private) | Kerry, Flash, DHL | **ก่อนส่ง** (prepayment) | BDO → ลูกค้าจ่าย → ส่งของ → Invoice |

---

## 3. ทุก Case ที่เป็นไปได้ ⭐

### Case 1: ✅ Happy Path — 1 สลิป = 1 BDO ยอดตรง

```
สถานการณ์: ลูกค้าโอน ฿15,950 ตรงกับ BDO ยอด ฿15,950
ผลลัพธ์:   Auto-match → confidence = bdo_prepayment (ขนส่งเอกชน) หรือ exact (สายส่ง)

ขั้นตอน:
1. ลูกค้าส่งสลิป LINE → Re-Ya รับ + มี bdo_id ใน context
2. Re-Ya → POST /reya/slip/upload {bdo_id: 437, amount: 15950}
3. Odoo: amount ≈ bdo.amount_net_to_pay → AUTO MATCH ✅
4. Re-Ya Dashboard: แสดง ✅ "จับคู่อัตโนมัติ"
5. Sales: ไม่ต้องทำอะไร

ฝั่ง Sales: ไม่ต้องทำอะไร (auto)
```

### Case 2: ⚠️ โอนขาด — สลิป < ยอด BDO

```
สถานการณ์: BDO = ฿15,950 แต่ลูกค้าโอน ฿15,000 (ขาด ฿950)
ผลลัพธ์:   ยอดไม่ตรง → confidence = manual → ต้อง Sales ตัดสินใจ

ขั้นตอน:
1. Odoo: amount ≠ bdo.amount_net_to_pay → ไม่ auto match
2. Re-Ya Dashboard: แสดง ⚠️ "ยอดไม่ตรง (ขาด ฿950)"
3. Sales ตัดสินใจ:
   a) ✅ ยอมรับ → กดจับคู่ → Re-Ya ส่ง `/reya/slip/match-bdo` ไป Odoo
   b) ❌ ไม่ยอมรับ → แจ้งลูกค้าผ่าน LINE "กรุณาโอนเพิ่ม ฿950"
   c) 📝 บันทึกหมายเหตุ → "ลูกค้าจะโอนเพิ่มทีหลัง"

ฝั่ง Sales: ต้องตัดสินใจ (manual)
```

### Case 3: ⚠️ โอนเกิน — สลิป > ยอด BDO

```
สถานการณ์: BDO = ฿15,950 แต่ลูกค้าโอน ฿16,000 (เกิน ฿50)
ผลลัพธ์:   ยอดไม่ตรง → ต้อง Sales ตัดสินใจ

ขั้นตอน:
1. Odoo: amount > bdo.amount_net_to_pay → ไม่ auto match (ถ้าเกิน tolerance)
2. Re-Ya Dashboard: แสดง ⚠️ "ยอดเกิน (เกิน ฿50)"
3. Sales ตัดสินใจ:
   a) ✅ ยอมรับ + ยอดเกินเป็น credit → กดจับคู่
   b) ✅ ยอมรับ + ไม่สนยอดเกิน → กดจับคู่ (ปัดเศษ)

ฝั่ง Sales: ต้องตัดสินใจ (manual)
หมายเหตุ: ถ้าเกินไม่เกิน ฿10 → อาจ auto-accept ได้ (configurable tolerance)
```

### Case 4: 🔀 1 สลิป → หลาย BDO (โอนรวม)

```
สถานการณ์: ลูกค้ามี 2 BDO
  - BDO2603-00437: ฿15,950
  - BDO2603-00438: ฿8,200
  ลูกค้าโอนรวม 1 ครั้ง ฿24,150

ผลลัพธ์:   ไม่ auto match (ไม่ตรงกับ BDO ไหนเลย) → Sales จับคู่ด้วยมือ

ขั้นตอน:
1. Odoo: amount ≠ any single BDO → confidence = unmatched
2. Re-Ya Dashboard: แสดง "ยังไม่ได้จับคู่"
3. Sales เห็น:
   - สลิป ฿24,150
   - BDO-437: ฿15,950 ⬜
   - BDO-438: ฿8,200  ⬜
4. Sales เลือก ✅ ทั้ง 2 BDO → ระบบคำนวณ: 15,950 + 8,200 = 24,150 ✅ ตรง!
5. กดยืนยัน → Re-Ya ส่ง match ไป Odoo
   POST /reya/slip/match-bdo
   {line_user_id: "U1234567890abcdef", slip_inbox_id: 113, matches: [{bdo_id: 437, amount: 15950}, {bdo_id: 438, amount: 8200}]}
6. Odoo: validate + process

ฝั่ง Sales: ต้องเลือก BDO ที่จะจับคู่ (manual)
```

### Case 5: 📑 หลายสลิป → 1 BDO (โอนหลายครั้ง)

```
สถานการณ์: BDO = ฿15,950
  ลูกค้าโอน 2 ครั้ง:
  - สลิป A: ฿10,000 (วันที่ 5)
  - สลิป B: ฿5,950  (วันที่ 7)

ผลลัพธ์:   แต่ละสลิปไม่ตรงยอด BDO → ต้อง Sales จับคู่ทีละใบ

ขั้นตอน:
1. สลิป A มา → amount ≠ BDO → unmatched
2. Sales จับคู่ สลิป A → BDO-437 (partial: ฿10,000/฿15,950)
3. สลิป B มา → Sales จับคู่ สลิป B → BDO-437 (remaining: ฿5,950)
4. ระบบคำนวณ: 10,000 + 5,950 = 15,950 ✅ ครบ!

ฝั่ง Sales: จับคู่ทีละสลิป (manual)
ฝั่ง Odoo: ติดตามยอดสะสมต่อ BDO
```

### Case 6: ❓ ไม่มี BDO context (ส่งสลิปเปล่า)

```
สถานการณ์: ลูกค้าส่งสลิปผ่าน LINE โดย Re-Ya ไม่รู้ว่าจ่ายอะไร
  (ไม่มี bdo_id ใน context)

ผลลัพธ์:   Odoo พยายาม match จาก partner + amount

ขั้นตอน:
1. Re-Ya → POST /reya/slip/upload {line_user_id, slip_image, amount}
   (ไม่มี bdo_id)
2. Odoo: หา partner จาก line_user_id → หา open invoice ที่ตรงยอด
   a) เจอ invoice ตรง → confidence = exact
   b) เจอ BDO ตรงยอด → confidence = bdo_prepayment
   c) ไม่เจอเลย → confidence = unmatched
3. ถ้า unmatched → Sales ต้องจับคู่ใน Re-Ya Dashboard

ฝั่ง Sales: อาจต้องจับคู่ (ถ้า auto match ไม่เจอ)
```

### Case 7: ❌ จับคู่ผิด (ต้อง unmatch)

```
สถานการณ์: Sales จับคู่สลิปกับ BDO ผิดตัว

ขั้นตอน:
1. Sales เห็นว่าจับคู่ผิด
2. กด "ยกเลิกการจับคู่" ใน Re-Ya
3. Re-Ya → POST /reya/slip/unmatch {line_user_id: "U1234567890abcdef", slip_inbox_id: 113, reason: "จับคู่ผิด BDO"}
4. Odoo: reset slip state → new, ลบ bdo_id
5. Sales จับคู่ใหม่

ฝั่ง Odoo: ต้อง validate ว่า payment ยังไม่ถูก post → ถ้า post แล้ว ยกเลิกไม่ได้
```

### Case 8: 🕐 สลิปมาก่อน BDO (ลูกค้าจ่ายล่วงหน้า)

```
สถานการณ์: ลูกค้าโอนเงินก่อน Sales สร้าง BDO
  (เช่น ลูกค้าโทรสั่งแล้วโอนทันที)

ขั้นตอน:
1. สลิปมา → ไม่มี BDO → confidence = unmatched
2. Sales สร้าง BDO ทีหลัง
3. Sales เปิด Re-Ya Dashboard → เห็นสลิปที่ยัง unmatched
4. จับคู่สลิปกับ BDO ที่เพิ่งสร้าง

ฝั่ง Sales: จับคู่ย้อนหลัง (manual)
```

---

## 4. Workflow ละเอียด — คน + ระบบ ⭐

### 4A. Flow หลัก: ขนส่งเอกชน (Prepayment)

```
เวลา │ ใคร          │ ทำอะไร                           │ ระบบ
─────┼──────────────┼──────────────────────────────────┼─────────────────────
 T0  │ Sales        │ สร้าง BDO ใน Odoo                  │ BDO state=draft
     │              │ ดึง SO + ข้อมูลการเงิน              │
     │              │ กด "ยืนยัน"                        │ BDO state=waiting
     │              │                                  │ ↓
 T1  │ ระบบ (Odoo)   │                                  │ Webhook → Re-Ya
     │              │                                  │ bdo.confirmed
     │              │                                  │ + bdo_id, amount
     │              │                                  │ + QR Code
     │              │                                  │ + Statement PDF
     │              │                                  │ ↓
 T2  │ ระบบ (Re-Ya)  │                                  │ เก็บ bdo_id ใน context
     │              │ ส่งข้อความ LINE ถึงลูกค้า:            │ LINE Message
     │              │ "กรุณาชำระ ฿15,950"                │ + QR Code image
     │              │ + QR Code + Statement PDF          │ + PDF attachment
     │              │                                  │ ↓
 T3  │ ลูกค้า        │ สแกน QR / โอนเงิน                  │
     │              │ ถ่ายสลิป → ส่งกลับ LINE              │
     │              │                                  │ ↓
 T4  │ ระบบ (Re-Ya)  │                                  │ รับรูปสลิป
     │              │                                  │ ดึง bdo_id จาก context
     │              │                                  │ POST /reya/slip/upload
     │              │                                  │ {bdo_id, slip_image, amount}
     │              │                                  │ ↓
 T5  │ ระบบ (Odoo)   │                                  │ สร้าง Slip Inbox
     │              │                                  │ Auto-match กับ BDO
     │              │                                  │ ↓
     │              │            ┌────────────────────────┤
     │              │            │ ยอดตรง?                │
     │              │            ├── ✅ ตรง               │ confidence=bdo_prepayment
     │              │            │   Auto-match!         │ state=matched
     │              │            │                      │ ↓
     │              │            │   Re-Ya แจ้งลูกค้า:     │ "ได้รับสลิปแล้ว ✅
     │              │            │                      │  กำลังจัดส่งสินค้า"
     │              │            │                      │
     │              │            └── ❌ ไม่ตรง            │ confidence=manual
     │              │                Sales ต้องจับคู่      │ state=new
     │              │                ใน Re-Ya Dashboard  │
     │              │                (ดู Case 2-5)       │
     │              │                                  │ ↓
 T6  │ Sales        │ (ถ้า auto-match)                   │
     │              │ เปิด Re-Ya Dashboard              │ เห็น ✅ จับคู่แล้ว
     │              │ ตรวจสอบสถานะ (ถ้าต้องการ)          │ ไม่มี API call เพิ่ม
     │              │                                  │ ↓
     │   หรือ       │ (ถ้า manual)                       │
     │              │ เลือกสลิป + เลือก BDO              │
     │              │ กด "จับคู่" → กด "ยืนยัน"           │ POST /reya/slip/match-bdo
     │              │                                  │ ↓
 T7  │ ระบบ (Odoo)   │                                  │ Validate match
     │              │                                  │ Slip state=confirmed
     │              │                                  │ BDO: payment_slip_confirmed ✅
     │              │                                  │ ↓
 T8  │ Sales        │ เปิด BDO ใน Odoo                   │ เห็น ✅ "ยืนยันชำระแล้ว"
     │              │ กด "ยืนยันจัดส่ง"                   │ Payment guard: PASS ✅
     │              │                                  │ BDO state=done
     │              │                                  │ → สร้าง DO + ส่งของ
     │              │                                  │ → สร้าง Invoice อัตโนมัติ
     │              │                                  │ ↓
 T9  │ ระบบ (Odoo)   │                                  │ Invoice สร้างแล้ว
     │              │                                  │ Slip re-match → invoice
     │              │                                  │ สร้าง payment → reconcile
```

### 4B. Flow: สายส่ง (จ่ายทีหลัง)

```
เวลา │ ใคร          │ ทำอะไร                           │ ระบบ
─────┼──────────────┼──────────────────────────────────┼─────────────────────
 T0  │ Sales        │ สร้าง BDO ใน Odoo                  │ BDO state=draft
     │              │ ดึง SO + ข้อมูลการเงิน              │
     │              │ กด "ยืนยัน"                        │ BDO state=waiting
     │              │ กด "ยืนยันจัดส่ง" ต่อทันที           │ BDO state=done
     │              │ (สายส่ง ไม่ต้องรอจ่ายเงิน)          │ → สร้าง DO + ส่งของ
     │              │                                  │ → สร้าง Invoice อัตโนมัติ
     │              │                                  │ ↓
 T1  │ ระบบ         │                                  │ Webhook → Re-Ya
     │              │                                  │ bdo.confirmed + bdo.done
     │              │                                  │ ↓
 T2  │ ระบบ (Re-Ya)  │ ส่ง LINE:                         │
     │              │ "ส่งสินค้าเรียบร้อย ยอด ฿15,950"    │
     │              │ + Statement PDF                   │
     │              │                                  │ ↓
  …  │              │ (3-7 วัน ผ่านไป)                   │
     │              │                                  │ ↓
 T3  │ ลูกค้า        │ โอนเงิน → ส่งสลิป LINE              │
     │              │                                  │ ↓
 T4  │ ระบบ (Re-Ya)  │                                  │ POST /reya/slip/upload
     │              │                                  │ {bdo_id, slip_image, amount}
     │              │                                  │ ↓
 T5  │ ระบบ (Odoo)   │                                  │ Match กับ invoice ค้างชำระ
     │              │                                  │ (ไม่ใช่ BDO เพราะมี invoice แล้ว)
     │              │                                  │ confidence = exact/partial
     │              │                                  │ ↓
 T6  │ Sales        │ ตรวจสอบ + ยืนยัน ใน Re-Ya          │
```

---

## 5. Re-Ya Dashboard UI ที่ต้องสร้าง 

### 5A. หน้าลูกค้า — เพิ่ม "จับคู่สลิป" Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ ร้านศูนย์ยาเติมสุข (ID: 74728)                            [X]  │
├─────────────────────────────────────────────────────────────────┤
│ ยอดรวม    ค้างชำระ    เครดิตลิมิต   เครดิตใช้ไป  เครดิตเหลือ     │
│ ฿17,150   ฿0         -            -           -              │
├─────────────────────────────────────────────────────────────────┤
│ ออเดอร์(6) ใบแจ้งหนี้(7) BDO(2) สลิป(1) โปรไฟล์ Timeline      │
│ ──────────────────────────────────────────────────             │
│                                                               │
│ ┌── สลิปที่ยังไม่ได้จับคู่ ──────────────────────────────┐      │
│ │ ⬜ สลิป ฿15,950  05 มี.ค. 69  [รูป]  ⏳ รอจับคู่      │      │
│ │ ⬜ สลิป ฿8,200   06 มี.ค. 69  [รูป]  ⏳ รอจับคู่      │      │
│ └────────────────────────────────────────────────────┘      │
│                                                               │
│ ┌── BDO ที่รอชำระ ──────────────────────────────────────┐      │
│ │ ⬜ BDO2603-00437  ฿15,950  ขนส่งเอกชน  [ดูรายละเอียด]  │      │
│ │ ⬜ BDO2603-00438  ฿8,200   สายส่ง      [ดูรายละเอียด]  │      │
│ └────────────────────────────────────────────────────────┘      │
│                                                               │
│         [🔗 จับคู่สลิป ↔ BDO]    [ยกเลิกการจับคู่]               │
│                                                               │
│ ┌── จับคู่แล้ว ─────────────────────────────────────────┐      │
│ │ ✅ สลิป ฿24,150 → BDO2602-02417 (exact)              │      │
│ └────────────────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────────────┘
```

### 5B. BDO Detail Modal — คลิกดูรายละเอียด

```
┌─────────────────────────────────────────────────────────────────┐
│ BDO2603-00437                                     [X]          │
│ ร้านศูนย์ยาเติมสุข | 05 มี.ค. 69 | ขนส่งเอกชน (Kerry)          │
│ สถานะ: [waiting] รอจัดส่ง                                       │
├─────────────────────────────────────────────────────────────────┤
│                                                               │
│ 📦 รายการสั่งซื้อ                                               │
│ ┌──────────────────────────────────────────────────────┐      │
│ │ SO          สินค้า                    จำนวน    ยอด    │      │
│ │ SO2602-05900 ยา Paracetamol 500mg     10 กล่อง ฿5,000 │      │
│ │              ยา Amoxicillin 250mg      5 กล่อง  ฿3,500 │      │
│ │ SO2602-05901 ยา Omeprazole 20mg       20 กล่อง ฿4,200 │      │
│ │                                      รวม SO:  ฿12,700│      │
│ └──────────────────────────────────────────────────────┘      │
│                                                               │
│ 📄 ใบแจ้งหนี้ค้างชำระ                                          │
│ ┌──────────────────────────────────────────────────────┐      │
│ │ ✅ HS26020001  26 ก.พ. 69  SO2601-05800  ฿3,000      │      │
│ │ ✅ HS26020002  28 ก.พ. 69  SO2601-05850  ฿1,450      │      │
│ │                                    รวม:  ฿4,450      │      │
│ └──────────────────────────────────────────────────────┘      │
│                                                               │
│ 📝 หักลด                                                      │
│ ┌──────────────────────────────────────────────────────┐      │
│ │ ใบลดหนี้  CN26020001  ฿500                            │      │
│ │ เงินมัดจำ  DP26020001  ฿700                            │      │
│ │                                    รวมหัก: -฿1,200    │      │
│ └──────────────────────────────────────────────────────┘      │
│                                                               │
│ ═══════════════════════════════════════════════════════       │
│ ยอดสุทธิที่ต้องชำระ:                          ฿15,950         │
│ ═══════════════════════════════════════════════════════       │
│                                                               │
│ 💳 สลิปที่จับคู่แล้ว: ยังไม่มี                                   │
│                                                               │
│ 🔗 ลิงก์:                                                     │
│   [📄 ดูใบแจ้งยอด (PDF)]  [🔗 เปิดใน Odoo]                     │
│                                                               │
└─────────────────────────────────────────────────────────────────┘
```

### 5C. Matching Interface (หน้าจับคู่)

```
┌─────────────────────────────────────────────────────────────────┐
│ จับคู่สลิป ↔ BDO                                               │
├──────────────────────────┬──────────────────────────────────────┤
│ 📸 สลิปที่เลือก           │ 📋 BDO ที่เลือก                      │
│                          │                                    │
│ ✅ สลิป ฿24,150          │ ✅ BDO2603-00437  ฿15,950           │
│    [รูปสลิป thumbnail]    │ ✅ BDO2603-00438  ฿8,200            │
│    05 มี.ค. 69           │                                    │
│                          │ รวม BDO: ฿24,150                   │
│                          │                                    │
├──────────────────────────┴──────────────────────────────────────┤
│                                                               │
│ สลิป: ฿24,150  vs  BDO: ฿24,150  →  ✅ ยอดตรง! (ส่วนต่าง ฿0)  │
│                                                               │
│ 📝 หมายเหตุ: _________________________                        │
│                                                               │
│         [✅ ยืนยันจับคู่]    [❌ ยกเลิก]                         │
│                                                               │
│ ⚠️ เมื่อยืนยัน:                                                │
│ - Odoo จะบันทึกการจับคู่อัตโนมัติ                                │
│ - สลิปจะเปลี่ยนสถานะเป็น "จับคู่แล้ว"                           │
│ - BDO จะแสดง "ยืนยันชำระแล้ว" (ถ้ายอดครบ)                      │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. API Endpoints สำหรับ Dashboard / Manual Matching 

> **หมายเหตุ:** Endpoints ใน section นี้ **พร้อมทดสอบบน staging** แล้วที่ `https://stg-erp.cnyrxapp.com`
> Endpoints พื้นฐานที่ใช้ได้แล้วก่อนหน้านี้: ดู `reya_slip_api_spec.md` Section 1-2 (`/reya/slip/upload`, `/reya/payment/status`)
> Production host ปัจจุบัน: `https://cny.cnyrxapp.com` และยังคง `https://erp.cnyrxapp.com` เป็น legacy reference

### 6A. `POST /reya/bdo/list` — รายการ BDO ของลูกค้า

**Purpose:** Re-Ya Dashboard ดึงรายการ BDO เพื่อแสดงใน tab

```json
// Request
{
    "jsonrpc": "2.0", "id": 1, "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "state": "waiting",
        "limit": 50, "offset": 0
    }
}

// Response
{
    "jsonrpc": "2.0", "id": 1,
    "result": {
        "success": true,
        "data": {
            "total": 2,
            "bdos": [
                {
                    "id": 437,
                    "name": "BDO2603-00437",
                    "doc_date": "2026-03-05",
                    "state": "waiting",
                    "state_display": "รอจัดส่ง",
                    "partner_id": 74728,
                    "partner_name": "ร้านศูนย์ยาเติมสุข",
                    "delivery_type": "private",
                    "delivery_type_display": "ขนส่งเอกชน",
                    "amount_net_to_pay": 15950.00,
                    "amount_so_this_round": 12700.00,
                    "amount_outstanding_invoice": 4450.00,
                    "amount_credit_note": 500.00,
                    "amount_deposit": 700.00,
                    "slip_count": 0,
                    "payment_slip_confirmed": false,
                    "sale_orders": [
                        {"id": 5900, "name": "SO2602-05900"},
                        {"id": 5901, "name": "SO2602-05901"}
                    ],
                    "statement_pdf_url": "/reya/bdo/statement-pdf/437",
                    "odoo_url": "https://cny.cnyrxapp.com/web#id=437&model=cny.bill.invoice.before.delivery&view_type=form"
                }
            ]
        }
    }
}
```

### 6B. `POST /reya/bdo/detail` — รายละเอียด BDO (คลิกดู)

**Purpose:** Re-Ya Dashboard คลิก BDO → เห็นรายการทั้งหมด

```json
// Request
{
    "jsonrpc": "2.0", "id": 1, "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "bdo_id": 437
    }
}

// Response
{
    "jsonrpc": "2.0", "id": 1,
    "result": {
        "success": true,
        "data": {
            "bdo": {
                "id": 437,
                "name": "BDO2603-00437",
                "doc_date": "2026-03-05",
                "state": "waiting",
                "delivery_type": "private",
                "amount_net_to_pay": 15950.00,
                "qr_payment_data": {
                    "raw_payload": "000201010212...",
                    "amount": 15950.00
                }
            },
            "sale_orders": [
                {
                    "id": 5900,
                    "name": "SO2602-05900",
                    "amount_total": 8500.00,
                    "lines": [
                        {
                            "product_name": "Paracetamol 500mg",
                            "product_code": "MED-0001",
                            "quantity": 10,
                            "uom": "กล่อง",
                            "unit_price": 500.00,
                            "subtotal": 5000.00
                        },
                        {
                            "product_name": "Amoxicillin 250mg",
                            "product_code": "MED-0002",
                            "quantity": 5,
                            "uom": "กล่อง",
                            "unit_price": 700.00,
                            "subtotal": 3500.00
                        }
                    ]
                },
                {
                    "id": 5901,
                    "name": "SO2602-05901",
                    "amount_total": 4200.00,
                    "lines": [
                        {
                            "product_name": "Omeprazole 20mg",
                            "product_code": "MED-0003",
                            "quantity": 20,
                            "uom": "กล่อง",
                            "unit_price": 210.00,
                            "subtotal": 4200.00
                        }
                    ]
                }
            ],
            "outstanding_invoices": [
                {
                    "id": 50123,
                    "number": "HS26020001",
                    "date": "2026-02-26",
                    "origin": "SO2601-05800",
                    "amount_total": 3000.00,
                    "residual": 3000.00,
                    "selected": true
                },
                {
                    "id": 50124,
                    "number": "HS26020002",
                    "date": "2026-02-28",
                    "origin": "SO2601-05850",
                    "amount_total": 1450.00,
                    "residual": 1450.00,
                    "selected": true
                }
            ],
            "credit_notes": [
                {
                    "id": 50200,
                    "number": "CN26020001",
                    "amount_total": 500.00,
                    "residual": 500.00,
                    "selected": true
                }
            ],
            "deposits": [
                {
                    "id": 1001,
                    "name": "DP26020001",
                    "amount": 700.00
                }
            ],
            "summary": {
                "so_amount": 12700.00,
                "outstanding_amount": 4450.00,
                "credit_note_amount": -500.00,
                "deposit_amount": -700.00,
                "net_to_pay": 15950.00
            },
            "slips": [],
            "statement_pdf_url": "/reya/bdo/statement-pdf/437",
            "odoo_url": "https://cny.cnyrxapp.com/web#id=437&model=cny.bill.invoice.before.delivery&view_type=form"
        }
    }
}
```

### 6C. `GET /reya/bdo/statement-pdf/{bdo_id}` — ดาวน์โหลด Statement PDF

**Purpose:** Re-Ya Dashboard กดดู/ดาวน์โหลด PDF ใบแจ้งยอด

```
GET /reya/bdo/statement-pdf/437
Header: X-Api-Key: <api_key>
หรือ GET /reya/bdo/statement-pdf/437?api_key=<api_key>
Response: application/pdf binary (Content-Disposition: attachment)
```

### 6D. `POST /reya/slip/match-bdo` — จับคู่สลิป ↔ BDO 

**Purpose:** Sales กดจับคู่ใน Re-Ya → ส่งผลไป Odoo

```json
// Request — Case: 1 slip → 1 BDO
{
    "jsonrpc": "2.0", "id": 1, "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "slip_inbox_id": 113,
        "matches": [
            {"bdo_id": 437, "amount": 15950.00}
        ],
        "note": "ลูกค้าโอนตรงยอด"
    }
}

// Request — Case: 1 slip → 2 BDOs (โอนรวม)
{
    "jsonrpc": "2.0", "id": 1, "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "slip_inbox_id": 113,
        "matches": [
            {"bdo_id": 437, "amount": 15950.00},
            {"bdo_id": 438, "amount": 8200.00}
        ],
        "note": "ลูกค้าโอนรวม 2 BDO"
    }
}

// Request — Case: 2 slips → 1 BDO (โอนหลายครั้ง)
// (เรียก endpoint นี้ 2 ครั้ง แต่ละสลิป)
{
    "jsonrpc": "2.0", "id": 1, "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "slip_inbox_id": 114,
        "matches": [
            {"bdo_id": 437, "amount": 5950.00}
        ],
        "note": "โอนครั้งที่ 2 (ครบแล้ว)"
    }
}

// Response
{
    "jsonrpc": "2.0", "id": 1,
    "result": {
        "success": true,
        "data": {
            "slip_inbox_id": 113,
            "slip_inbox_name": "SLIP-2603-00113",
            "state": "matched",
            "match_confidence": "bdo_prepayment",
            "matched_bdos": [
                {
                    "bdo_id": 437,
                    "bdo_name": "BDO2603-00437",
                    "amount_matched": 15950.00,
                    "payment_slip_confirmed": true,
                    "bdo_fully_paid": true
                }
            ],
            "total_matched": 15950.00,
            "slip_amount": 15950.00,
            "difference": 0.00
        }
    }
}
```

### 6E. `POST /reya/slip/unmatch` — ยกเลิกการจับคู่

```json
// Request
{
    "jsonrpc": "2.0", "id": 1, "method": "call",
    "params": {
        "line_user_id": "U1234567890abcdef",
        "slip_inbox_id": 113,
        "reason": "จับคู่ผิด BDO"
    }
}

// Response
{
    "jsonrpc": "2.0", "id": 1,
    "result": {
        "success": true,
        "data": {
            "slip_inbox_id": 113,
            "state": "new",
            "previous_bdo_ids": [437],
            "message": "ยกเลิกการจับคู่เรียบร้อย"
        }
    }
}

// Error — ถ้า payment ถูก post แล้ว
{
    "result": {
        "success": false,
        "error": {
            "code": "PAYMENT_ALREADY_POSTED",
            "message": "ไม่สามารถยกเลิกได้ — payment ถูกบันทึกแล้ว กรุณาติดต่อฝ่ายบัญชี"
        }
    }
}
```

---

## 7. Cross-Link ระหว่าง Re-Ya ↔ Odoo

### 7A. Re-Ya → Odoo (Sales กดดูใน Odoo)

Re-Ya Dashboard ควรมีลิงก์ไป Odoo form:

| Object | URL Pattern | ตัวอย่าง |
|--------|------------|---------|
| BDO | `{base}/web#id={bdo_id}&model=cny.bill.invoice.before.delivery&view_type=form` | `/web#id=437&model=cny.bill.invoice.before.delivery&view_type=form` |
| Invoice | `{base}/web#id={inv_id}&model=account.invoice&view_type=form` | `/web#id=50123&model=account.invoice&view_type=form` |
| SO | `{base}/web#id={so_id}&model=sale.order&view_type=form` | `/web#id=5900&model=sale.order&view_type=form` |
| Slip | `{base}/web#id={slip_id}&model=cny.payment.slip&view_type=form` | `/web#id=113&model=cny.payment.slip&view_type=form` |

**base URL:** `https://cny.cnyrxapp.com` (production) / `https://stg-erp.cnyrxapp.com` (staging)
**legacy reference:** `https://erp.cnyrxapp.com`

### 7B. Odoo → Re-Ya (Admin ดูสถานะ matching)

Odoo Slip Inbox form ควรแสดง:
- **Re-Ya Match Status:** ✅ จับคู่แล้วใน Re-Ya / ⏳ รอจับคู่
- **Matched By:** Sales ชื่อ xxx (จาก Re-Ya)
- **Matched At:** วันเวลาที่จับคู่

Odoo BDO form ควรแสดง:
- **Slip Count:** จำนวนสลิปที่จับคู่
- **Payment Confirmed:** ✅/❌
- **Total Slip Amount:** ยอดรวมสลิป vs ยอด BDO

---

## 8. Validation Rules ฝั่ง Odoo

### 8A. เมื่อรับ match จาก Re-Ya (`/reya/slip/match-bdo`)

```
Odoo Validation Checklist:
1. ✅ slip_inbox_id exists and state = 'new' or 'matched'
2. ✅ bdo_id(s) exist and state = 'waiting' (ยังไม่ done)
3. ✅ slip.partner_id = bdo.partner_id (ลูกค้าเดียวกัน)
4. ✅ sum(match amounts) ≤ slip.amount (ไม่จับคู่เกินยอดสลิป)
5. ⚠️ sum(match amounts for bdo) ≤ bdo.amount_net_to_pay (แจ้งเตือนถ้าเกิน)
6. ✅ slip not already matched to different BDOs (ป้องกัน double-match)
```

### 8B. เมื่อรับ unmatch (`/reya/slip/unmatch`)

```
Odoo Validation:
1. ✅ slip exists
2. ✅ slip state NOT in ('posted', 'done') — ถ้า posted แล้ว unmatch ไม่ได้
3. ✅ Reset: slip.bdo_id = False, slip.state = 'new'
```

---

## 9. สรุป สิ่งที่ต้องทำ

### ฝั่ง Odoo (SOMZAA)

| # | Task | Priority |
|---|------|----------|
| 1 | ทดสอบ endpoint `/reya/bdo/list` บน staging และเตรียม production rollout | High |
| 2 | ทดสอบ endpoint `/reya/bdo/detail` (พร้อม SO lines, invoices, CNs) | High |
| 3 | ทดสอบ endpoint `/reya/bdo/statement-pdf/{id}` ทั้งแบบ header และ query param | Medium |
| 4 | ทดสอบ endpoint `/reya/slip/match-bdo` (Sales จับคู่จาก Re-Ya) | High |
| 5 | ทดสอบ endpoint `/reya/slip/unmatch` | Medium |
| 6 | เพิ่ม fields: `reya_matched_by`, `reya_matched_at` ใน slip model | Low |
| 7 | เพิ่ม Odoo URL ใน BDO/Slip API responses | Low |

### ฝั่ง Re-Ya (Developer)

| # | Task | Priority |
|---|------|----------|
| 1 | เก็บ bdo_id จาก webhook ใน LINE chat context | High |
| 2 | ส่ง bdo_id กลับมากับ slip upload | High |
| 3 | Dashboard: เพิ่ม BDO Detail modal (เรียก `/reya/bdo/detail`) | High |
| 4 | Dashboard: สร้าง Matching Interface (เลือกสลิป ↔ BDO) | High |
| 5 | Dashboard: เรียก `/reya/slip/match-bdo` เมื่อ Sales กดยืนยัน | High |
| 6 | Dashboard: เรียก `/reya/slip/unmatch` เมื่อ Sales กดยกเลิก | Medium |
| 7 | Dashboard: แสดง delivery_type badge (สายส่ง/ขนส่งเอกชน) | Medium |
| 8 | Dashboard: แสดง Statement PDF link | Medium |
| 9 | LINE Bot: แจ้งลูกค้าตาม match_confidence | Medium |

---

*Document: reya_bdo_matching_workflow.md*
*Version: 1.0 — 2026-03-06*
*Contact: consdevs | SOMZAA*
