# Requirements Document

## Introduction

ระบบ Public Landing Page สำหรับ LINE Telepharmacy Platform เพื่อแยกหน้าแรกสำหรับผู้เยี่ยมชมทั่วไปออกจากหน้า Admin Dashboard โดยหน้า Landing Page จะแสดงข้อมูลร้านค้า บริการ และมีปุ่มนำทางไปยัง LIFF App สำหรับลูกค้า และลิงก์ไปยังหน้า Admin Login สำหรับผู้ดูแลระบบ

## Glossary

- **Landing_Page**: หน้าเว็บแรกที่ผู้เยี่ยมชมเห็นเมื่อเข้าถึง URL หลักของระบบ
- **LIFF_App**: LINE Front-end Framework Application สำหรับลูกค้าใช้งานผ่าน LINE
- **Admin_Dashboard**: หน้าควบคุมระบบสำหรับผู้ดูแลระบบ
- **Shop_Settings**: ข้อมูลการตั้งค่าร้านค้าจากฐานข้อมูล
- **LINE_Account**: บัญชี LINE Official Account ที่เชื่อมต่อกับระบบ
- **CTA_Button**: Call-to-Action Button ปุ่มกระตุ้นให้ผู้ใช้ดำเนินการ

## Requirements

### Requirement 1

**User Story:** As a visitor, I want to see an attractive landing page when I visit the main URL, so that I can understand what services the pharmacy offers.

#### Acceptance Criteria

1. WHEN a visitor accesses the root URL THE Landing_Page SHALL display within 2 seconds
2. WHEN the Landing_Page loads THE Landing_Page SHALL display the shop logo and name from Shop_Settings
3. WHEN the Landing_Page loads THE Landing_Page SHALL display a hero section with pharmacy branding
4. WHEN the Landing_Page loads THE Landing_Page SHALL display service highlights including shop, consultation, and appointments
5. WHEN Shop_Settings contains custom colors THE Landing_Page SHALL apply those colors to the theme

### Requirement 2

**User Story:** As a customer, I want to easily access the LINE LIFF App from the landing page, so that I can start shopping or using pharmacy services.

#### Acceptance Criteria

1. WHEN the Landing_Page displays THE Landing_Page SHALL show a prominent CTA_Button to open LIFF_App
2. WHEN a visitor clicks the LIFF CTA_Button THE Landing_Page SHALL redirect to the LIFF_App URL
3. WHEN LINE_Account has a valid LIFF ID THE Landing_Page SHALL use that LIFF ID for the redirect URL
4. WHEN the visitor is on mobile THE Landing_Page SHALL display a LINE-styled button for better recognition

### Requirement 3

**User Story:** As an administrator, I want to access the admin dashboard from the landing page, so that I can manage the system without remembering a separate URL.

#### Acceptance Criteria

1. WHEN the Landing_Page displays THE Landing_Page SHALL show a subtle admin login link in the footer
2. WHEN an administrator clicks the admin link THE Landing_Page SHALL redirect to the Admin_Dashboard login page
3. WHEN the admin link is displayed THE Landing_Page SHALL style it discretely to not distract customers

### Requirement 4

**User Story:** As a business owner, I want the landing page to be mobile-responsive, so that customers on any device have a good experience.

#### Acceptance Criteria

1. WHEN a visitor accesses from mobile THE Landing_Page SHALL display a mobile-optimized layout
2. WHEN a visitor accesses from desktop THE Landing_Page SHALL display a desktop-optimized layout
3. WHEN the viewport changes THE Landing_Page SHALL adapt the layout responsively

### Requirement 5

**User Story:** As a business owner, I want the landing page to show key features and promotions, so that visitors are encouraged to use our services.

#### Acceptance Criteria

1. WHEN the Landing_Page loads THE Landing_Page SHALL display feature cards for main services
2. WHEN active promotions exist THE Landing_Page SHALL display a promotions section
3. WHEN the Landing_Page loads THE Landing_Page SHALL display contact information and operating hours

### Requirement 6

**User Story:** As a system administrator, I want the existing admin dashboard to be accessible via a dedicated URL, so that the admin functionality remains intact.

#### Acceptance Criteria

1. WHEN an administrator accesses /admin URL THE system SHALL display the Admin_Dashboard login
2. WHEN the admin is already logged in THE system SHALL redirect to the Admin_Dashboard
3. WHEN the original index.php is moved THE system SHALL maintain all existing admin functionality
