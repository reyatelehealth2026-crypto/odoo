# Implementation Plan

## Tasks

- [x] 1. เพิ่ม Role Checking Functions




  - [x] 1.1 สร้าง function `getCurrentUserRole()` ใน `includes/header.php`

    - Return role: owner, admin, pharmacist, staff, marketing, tech
    - ใช้ functions ที่มีอยู่แล้ว: `isSuperAdmin()`, `isAdmin()`, `isPharmacist()`
    - _Requirements: 7.1_

  - [x] 1.2 สร้าง function `hasMenuAccess($menuItem)` ใน `includes/header.php`

    - ตรวจสอบ `$menuItem['roles']` กับ user role ปัจจุบัน
    - ถ้าไม่มี roles key = ทุกคนเข้าได้
    - _Requirements: 7.1, 7.2_
  - [x] 1.3 Write property test for Role-Based Menu Visibility
























    - **Property 1: Role-Based Menu Visibility**
    - **Validates: Requirements 2.2, 2.3, 3.2, 3.3, 4.2, 4.3, 4.4, 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 7.1, 7.2**

- [x] 2. ปรับโครงสร้าง Menu Sections - กลุ่ม 1 & 2






  - [x] 2.1 สร้างกลุ่ม Insights & Overview

    - Executive Dashboard (roles: owner, admin)
    - Clinical Analytics - Triage Analytics, Drug Interactions (roles: pharmacist, owner)
    - Audit Logs (roles: owner)
    - _Requirements: 2.1, 2.2, 2.3_
  - [x] 2.2 สร้างกลุ่ม Clinical Station
  

    - Unified Care Chat - Inbox, Video Call, Auto-reply (roles: pharmacist, staff)
    - Roster & Shifts - Pharmacist Dashboard, Pharmacists (roles: all)
    - Medical Copilot AI - AI Chat Settings, AI Studio, AI Pharmacy Settings (roles: pharmacist)
    - _Requirements: 3.1, 3.2, 3.3_

- [x] 3. ปรับโครงสร้าง Menu Sections - กลุ่ม 3 & 4






  - [x] 3.1 สร้างกลุ่ม Patient & Journey






    - EHR - Users, User Tags (roles: pharmacist)
    - Membership - Members, Rewards, Points Settings (roles: all)
    - Care Journey - Broadcast, Catalog, Drip Campaign (roles: admin, marketing)
    - Digital Front Door - Rich Menu, Dynamic Rich Menu, LIFF Settings (roles: admin, marketing)
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

  - [x] 3.2 สร้างกลุ่ม Supply & Revenue

    - Billing & Orders - Orders, Promotions (roles: admin, staff)
    - Inventory - Products, Categories, Stock Management (roles: admin, pharmacist)
    - Procurement - PO, GR, Suppliers (roles: admin, owner)
    - _Requirements: 5.1, 5.2, 5.3, 5.4_



- [x] 4. ปรับโครงสร้าง Menu Sections - กลุ่ม 5 & Cleanup




  - [x] 4.1 สร้างกลุ่ม Facility Setup






    - Facility Profile - Shop Settings (roles: admin, owner)
    - Staff & Roles - Admin Users (roles: owner, admin)
    - Integrations - LINE Accounts, Telegram, AI Settings (roles: admin, tech)
    - Consent & PDPA (roles: admin)
    - _Requirements: 6.1, 6.2, 6.3, 6.4_

  - [x] 4.2 ลบกลุ่มเมนูเก่าที่ไม่ใช้แล้ว

    - ลบ: messaging, broadcast, shop, inventory, membership, pharmacy, ai, tools, analytics, settings
    - เก็บ: quick, main (Dashboard only)
    - _Requirements: 1.1_




  - [x] 4.3 Write property test for Menu Structure Completeness



















    - **Property 3: Menu Structure Completeness**
    - **Validates: Requirements 2.1, 3.1, 4.1, 5.1, 6.1**





- [x] 5. ปรับ Menu Rendering Logic







  - [x] 5.1 แก้ไข menu rendering loop ให้ใช้ `hasMenuAccess()` filter








    - Filter menu items ก่อน render
    - ซ่อน group ถ้าไม่มี items ที่ user เข้าถึงได้
    - _Requirements: 7.1, 7.2_

  - [x] 5.2 ปรับ auto-expand logic ให้ทำงานกับโครงสร้างใหม่







    - ตรวจสอบ current page กับ menu items ในแต่ละ group
    - Expand group ที่มี active menu item
    - _Requirements: 8.3_





  - [x] 5.3 Write property test for Menu Group Auto-Expand






    
    - **Property 2: Menu Group Auto-Expand**
    - **Validates: Requirements 8.3**


- [x] 6. Checkpoint - ทดสอบระบบ




  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. ปรับ Quick Access Section


















  - [x] 7.1 อัพเดท `$quickAccessMenus` array ให้ตรงกับ URL ใหม่











    - ตรวจสอบ URL paths ยังถูกต้อง
    - เพิ่ม roles ใน quick access items
    - _Requirements: 9.1, 9.2, 9.3_


- [x] 8. Final Checkpoint




  - Ensure all tests pass, ask the user if questions arise.
