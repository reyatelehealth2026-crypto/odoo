# Design Document: Public Landing Page

## Overview

สร้างหน้า Landing Page สาธารณะสำหรับ LINE Telepharmacy Platform เพื่อแยกหน้าแรกสำหรับผู้เยี่ยมชมทั่วไปออกจาก Admin Dashboard โดยจะ:

1. สร้างหน้า Landing Page ใหม่ที่ `index.php` (แทนที่ dashboard เดิม)
2. ย้าย Admin Dashboard ไปที่ `/admin/` folder
3. แสดงข้อมูลร้านค้า บริการ และปุ่มนำทางไป LIFF App
4. มีลิงก์ไปหน้า Admin Login สำหรับผู้ดูแลระบบ

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    URL Structure                             │
├─────────────────────────────────────────────────────────────┤
│  /                    → Landing Page (public)                │
│  /liff/               → LIFF App (LINE users)                │
│  /admin/              → Admin Dashboard (authenticated)      │
│  /admin/login         → Admin Login Page                     │
│  /auth/login.php      → Redirect to /admin/login             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│                    File Structure                            │
├─────────────────────────────────────────────────────────────┤
│  index.php (NEW)      → Landing Page                         │
│  admin/               → New folder for admin                 │
│    ├── index.php      → Admin Dashboard (moved)              │
│    ├── .htaccess      → Auth protection                      │
│    └── ...            → Other admin files (symlinks/includes)│
└─────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. Landing Page (`index.php`)

```php
// Main sections:
- Hero Section: Shop branding, tagline, CTA buttons
- Services Section: Feature cards (Shop, Consultation, Appointments)
- Promotions Section: Active promotions (if any)
- Contact Section: Operating hours, contact info
- Footer: Admin login link, copyright
```

### 2. Admin Folder Structure

```
admin/
├── index.php          # Dashboard (include from original)
├── .htaccess          # Protect admin routes
└── login.php          # Admin login page
```

### 3. Component Interfaces

```php
interface LandingPageData {
    shopName: string;
    shopLogo: string;
    shopDescription: string;
    primaryColor: string;
    liffUrl: string;
    services: Service[];
    promotions: Promotion[];
    contactInfo: ContactInfo;
}

interface Service {
    icon: string;
    title: string;
    description: string;
    url: string;
}

interface ContactInfo {
    phone: string;
    email: string;
    address: string;
    operatingHours: string;
}
```

## Data Models

### Shop Settings (Existing Table)

```sql
-- shop_settings table (existing)
- id
- line_account_id
- shop_name
- shop_logo
- shop_description
- primary_color
- secondary_color
- phone
- email
- address
- operating_hours
```

### LINE Accounts (Existing Table)

```sql
-- line_accounts table (existing)
- id
- name
- liff_id
- is_default
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system-essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Shop data display consistency
*For any* shop settings in the database, the Landing Page SHALL display the shop name and logo that match the stored values exactly.
**Validates: Requirements 1.2**

### Property 2: LIFF URL correctness
*For any* LINE Account with a valid LIFF ID, the CTA button's redirect URL SHALL contain that exact LIFF ID.
**Validates: Requirements 2.2, 2.3**

### Property 3: Theme color application
*For any* custom primary color in Shop Settings, the Landing Page CSS SHALL include that color value in the theme styles.
**Validates: Requirements 1.5**

### Property 4: Responsive layout adaptation
*For any* viewport width, the Landing Page layout SHALL adapt appropriately (mobile layout for width < 768px, desktop layout for width >= 768px).
**Validates: Requirements 4.1, 4.2, 4.3**

### Property 5: Promotions conditional display
*For any* set of promotions, the promotions section SHALL be visible if and only if at least one active promotion exists.
**Validates: Requirements 5.2**

### Property 6: Admin redirect when authenticated
*For any* user with a valid admin session, accessing /admin/ SHALL redirect to the dashboard without showing the login page.
**Validates: Requirements 6.2**

## Error Handling

| Scenario | Handling |
|----------|----------|
| No shop settings found | Use default values (generic pharmacy name/logo) |
| No LIFF ID configured | Hide LIFF button, show "Coming Soon" message |
| Database connection error | Show static landing page with cached data |
| No promotions | Hide promotions section entirely |
| Invalid admin session | Redirect to login page |

## Testing Strategy

### Unit Testing
- Test data fetching functions for shop settings
- Test URL generation for LIFF redirect
- Test conditional rendering logic for promotions

### Property-Based Testing
Using PHPUnit with data providers for property-based testing:

1. **Property 1 (Shop data display)**: Generate random shop names/logos, verify they appear in rendered HTML
2. **Property 2 (LIFF URL)**: Generate random LIFF IDs, verify they appear in button href
3. **Property 3 (Theme colors)**: Generate random hex colors, verify they appear in CSS
4. **Property 4 (Responsive)**: Test at various viewport widths, verify correct CSS classes
5. **Property 5 (Promotions)**: Generate 0-N promotions, verify section visibility
6. **Property 6 (Admin redirect)**: Test with/without session, verify redirect behavior

### Integration Testing
- Test full page load with database
- Test admin folder routing
- Test LIFF redirect flow

### Test Framework
- PHPUnit for PHP unit tests
- Property-based tests using PHPUnit data providers with random data generation
- Each property test should run minimum 100 iterations
