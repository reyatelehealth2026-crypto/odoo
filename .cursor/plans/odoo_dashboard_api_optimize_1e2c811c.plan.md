---
name: Odoo Dashboard API Optimize
overview: แผนปรับปรุงประสิทธิภาพ Odoo Dashboard โดยลดจำนวนการเรียก API ต่อการโหลด/รีเฟรช เน้นรวม overview เป็น 1 request และใช้ cache อย่างมีประสิทธิภาพ พร้อมทางเลือกแยกโปรเจกต์ถ้าต้องการ
todos:
  - id: todo-1773620514978-nzs9j2q3a
    content: ""
    status: completed
isProject: false
---

# แผน Optimize Odoo Dashboard (odoo-dashboard.js / odoo-dashboard.php)

## สาเหตุความช้าที่พบ

- **Overview (แท็บเริ่มต้น):** เปิดหน้ามีการเรียก API **6 ครั้ง** แบ่งเป็น 2 รอบ  
- รอบ 1: `stats`, `order_grouped_today`, `api/slips-list.php?status=pending` (3 ขนาน)  
- รอบ 2: `customer_list` (overdue), `pending_bdo_orders`, `api/slips-list.php?status=matched` (3 ขนาน)  
→ รอบ 2 เริ่มหลังรอบ 1 เสร็จ จึงเกิด **waterfall** และรอบละหลาย request
- **Backend:** แต่ละ `action` เป็นการรัน PHP แยกกัน มีแค่ cache แบบต่อ action (เช่น stats 20s, order_grouped_today 20s) ไม่มี endpoint เดียวที่รวมข้อมูล overview
- **Frontend:** มี session cache 3 นาที แต่วันที่ cache หมดหรือรีเฟรช จะกลับมา 6 requests ทุกครั้ง
- **หน้าอื่น:** แท็บ Webhooks = stats + order_grouped/list (2 ครั้ง), Matching = customer_list + slips-list + odoo_bdo_list (3 ครั้ง), เปิด modal ลูกค้า = 6 ครั้ง (ขนานแต่หนัก)

ดังนั้นจุดที่กระทบมากที่สุดคือ **Overview ใช้ 6 requests ต่อโหลด** และไม่มี batch endpoint ฝั่ง API

---

## แนวทาง Optimize (ในโปรเจกต์เดิม)

### 1. เพิ่ม Backend endpoint เดียวสำหรับ Overview (ลดจาก 6 → 1 request)

- **ไฟล์:** [api/odoo-dashboard-api.php](api/odoo-dashboard-api.php)
- **เพิ่ม action ใหม่:** เช่น `overview_today`
- ภายในเรียก logic เดิมของ: `getStats`, `getOrderGroupedToday` (limit 5), `getCustomerList` (overdue, limit 5), `getPendingBdoOrdersApi` (limit 20), และถ้าได้ ให้รวมข้อมูลสลิป (pending count + matched today) จาก DB หรือจาก `slips-list` logic ที่มีอยู่
- คืนค่าเป็น object เดียว เช่น  
`{ stats, orders, overdue_customers, overdue_total, pending_bdo, matched_today_slips_or_summary }`
- **Cache ฝั่ง API:** ใส่ `overview_today` ใน `$cacheTtls` (เช่น 30–60 วินาที) แล้วใช้ `dashboardApiBuildCacheKey` / `dashboardApiCacheGet` เหมือน action อื่น

ผล: หน้า Overview โหลดครั้งเดียวได้ข้อมูลครบ ลดทั้งจำนวน request และการรอรอบที่ 2

### 2. ปรับ Frontend ให้ใช้ endpoint เดียวสำหรับ Overview

- **ไฟล์:** [odoo-dashboard.js](odoo-dashboard.js)
- **ฟังก์ชัน `loadTodayOverview` และ `_loadTodayOverviewSecondary`:**
- แทนที่การ `Promise.all([stats, order_grouped_today, slips-list]) `และตามด้วย `_loadTodayOverviewSecondary` ที่เรียกอีก 3 ครั้ง  
- ให้เรียก **ครั้งเดียว** `whApiCall({ action: 'overview_today' })` แล้วจาก response เดียว:
- อัปเดต KPI ทั้งหมด (ออเดอร์วันนี้, ยอดขาย, สลิปรอ, ลูกค้าค้าง, BDO รอ, ยอดชำระวันนี้)
- เติมบล็อก: ออเดอร์ล่าสุด, สลิปรอจับคู่, ลูกค้าค้างชำระเร่งด่วน, แจ้งเตือน LINE
- เก็บการแสดงผลจาก cache (sessionStorage) และปุ่มรีเฟรชเหมือนเดิม
- **สลิป:** ถ้า backend `overview_today` รวมจำนวน/รายการสลิป (pending + matched today) ไว้แล้ว ก็ไม่ต้องเรียก `api/slips-list.php` เพิ่มสำหรับ overview; ถ้ายังแยกไว้ ก็เรียก slips-list แค่ 1 ครั้งสำหรับ “รายการสลิป” ถ้าต้องการ

ผล: เปิดหน้า / กดรีเฟรช overview เหลือ **1 ครั้ง** (หรือ 2 ถ้าเก็บ slips-list แยก) แทน 6 ครั้ง

### 3. Cache และการโหลดให้รู้สึกเร็วขึ้น

- **Backend:** ใช้ TTL cache สำหรับ `overview_today` (30–60s) เพื่อลดโหลด DB เมื่อมีการรีเฟรชบ่อย
- **Frontend (ถ้าต้องการ):**  
- แสดงผลจาก cache ก่อน (เหมือนเดิม) แล้วเรียก `overview_today` ในพื้นหลัง แล้วอัปเดตเมื่อได้ response (stale-while-revalidate)  
- หรืออย่างน้อยเก็บ session cache ไว้และให้ปุ่ม “รีเฟรชภาพรวม” ล้าง cache แล้วเรียก `overview_today` ครั้งเดียว

### 4. จุดอื่นที่ช่วยได้ (รองลงมา)

- **Webhooks:** ตอนเปิดแท็บถ้าโหมด grouped ยังโหลด stats + order_grouped แยกกัน อาจพิจารณา endpoint รวม “webhook_overview” (stats + order_grouped หน้าแรก) ในอนาคต
- **Matching:** ตอนเปิดแท็บจับคู่สลิป เรียก 3 ขนานอยู่แล้ว; ถ้ามี endpoint “matching_overview” (customer_list + slip count + bdo count) จะลดเหลือ 1 request ได้
- **Customer modal:** ยังคง 6 calls ขนาน; ถ้าต้องการลด latency มาก อาจออกแบบ “customer_360” หรือ batch หลาย action ใน request เดียว (ต้องเปลี่ยนทั้ง API และ frontend)

แนะนำทำ **ข้อ 1 + 2** ก่อน จะลดความช้าจาก API ได้ชัดที่สุด

---

## ทางเลือก: แยก Odoo Dashboard เป็นอีกโปรเจกต์

**เมื่อไหร่ถึงควรแยก**

- ต้องการ deploy dashboard แยกจาก LINE/Webhook (ทีมหรือรอบ deploy คนละชุด)
- ต้องการ scale ฝั่ง dashboard แยก (cache layer, CDN, server)
- ต้องการให้ dashboard อยู่ subdomain/domain อื่นและจัดการ security แยก

**แนวทางแยก**

- โปรเจกต์ใหม่ (เช่น `cny-odoo-dashboard`) มีเฉพาะ:
- ฟรอนต์: หน้าเดียว (หรือ SPA) โหลดจาก API ของโปรเจกต์เดิมหรือจาก **API gateway ของ dashboard เอง**
- API: สร้าง gateway บางส่วนที่เรียก backend เดิม (odoo-dashboard-api.php) หรือ copy เฉพาะ action ที่ dashboard ใช้ แล้วใส่ cache/CDN หน้ากateway
- Dashboard ยังเรียกข้อมูลจาก API เดิม (หรือจาก gateway) ดังนั้น logic ฝั่ง DB/Odoo ยังอยู่ที่โปรเจกต์หลัก
- ข้อดี: แยก deploy, แยก scale, โฟกัส repo  
ข้อเสีย: ต้องดูแล 2 ที่, config และ auth ต้องเชื่อมกัน

**สรุป:** ถ้าเป้าหมายคือ “ให้โหลดเร็วขึ้น” การ optimize ด้วย **overview_today + cache** ในโปรเจกต์เดิมเพียงพอและทำได้เร็วกว่า การแยกโปรเจกต์เหมาะเมื่อมีเหตุผลด้านการ deploy หรือ scale แยกจริงๆ

---

## ลำดับการทำที่แนะนำ

1. **Backend:** เพิ่ม action `overview_today` ใน [api/odoo-dashboard-api.php](api/odoo-dashboard-api.php) รวม stats, order_grouped_today, overdue customers, pending_bdo, และข้อมูลสลิป (pending + matched today) ให้ครบที่ overview ใช้ + ใส่ cache TTL
2. **Frontend:** แก้ [odoo-dashboard.js](odoo-dashboard.js) ให้ `loadTodayOverview` เรียก `overview_today` ครั้งเดียว และเลิกใช้ `_loadTodayOverviewSecondary` สำหรับ overview (หรือรวม logic เข้าใน response เดียว)
3. ทดสอบ: เปิดหน้า overview / กดรีเฟรช ตรวจว่าเหลือ 1 (หรือ 2) request และข้อมูลตรงเดิม
4. (ถ้าต้องการ) ทำ stale-while-revalidate หรือเพิ่ม cache ฝั่ง frontend ให้รู้สึกเร็วขึ้นอีก
5. (ถ้าต้องการในอนาคต) พิจารณา batch สำหรับ Webhooks หรือ Matching ตามความจำเป็น

---

## สรุป

- **ต้นเหตุหลัก:** หน้า Overview เรียก API 6 ครั้งใน 2 รอบ ทำให้รู้สึกช้าทุกครั้งที่โหลด/รีเฟรช
- **แนวทางหลัก:** สร้าง endpoint `overview_today` ใน [api/odoo-dashboard-api.php](api/odoo-dashboard-api.php) แล้วให้ [odoo-dashboard.js](odoo-dashboard.js) ใช้ endpoint นี้เพียงครั้งเดียวสำหรับภาพรวมวันนี้
- **การแยกโปรเจกต์:** ทำเมื่อมีเหตุผลเรื่อง deploy/scale แยก ไม่จำเป็นสำหรับการแก้ปัญหา “ช้าจาก API” โดยตรง