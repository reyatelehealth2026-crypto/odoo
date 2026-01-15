# Technology Stack

## Core Stack
- **Language**: PHP 7.4+
- **Database**: MySQL 5.7+ / MariaDB 10.2+
- **Frontend**: Tailwind CSS, Alpine.js, vanilla JavaScript
- **Server**: Apache/Nginx with SSL (required for LINE webhooks)

## Dependencies (composer.json)
- PHPUnit 9.6 for testing
- PSR-4 autoloading for `App\`, `Classes\`, `Modules\`, `Tests\`

## Key Libraries & APIs
- LINE Messaging API & LIFF SDK
- Google Gemini AI / OpenAI
- Chart.js for analytics
- Font Awesome icons
- WebRTC for video calls

## Architecture Pattern
- Service classes in `/classes/` (e.g., `POSService.php`, `InventoryService.php`)
- API endpoints in `/api/` returning JSON
- Database singleton via `Database::getInstance()->getConnection()`
- Tab-based UI with includes in `/includes/{module}/`
- LIFF SPA with client-side routing (`liff/assets/js/router.js`)

## Common Commands

```bash
# Run all tests
composer test
# or
./vendor/bin/phpunit

# Run specific test suite
./vendor/bin/phpunit tests/VibeSelling/
./vendor/bin/phpunit tests/InboxChat/

# Install dependencies
php composer.phar install

# Run specific migration
php install/run_{migration_name}_migration.php

# Examples:
php install/run_pos_migration.php
php install/run_accounting_migration.php
php install/run_wms_migration.php
php install/run_vibe_selling_v2_migration.php
```

## Database Migrations
- Located in `/database/migration_*.sql`
- Run via `/install/run_*_migration.php` scripts
- Schema in `/database/schema_complete.sql`

Key migrations:
- `migration_pos.sql` - POS tables
- `migration_accounting.sql` - AP/AR/Expenses
- `migration_wms.sql` - Pick-pack-ship
- `migration_vibe_selling_v2.sql` - AI pharmacy features
- `migration_put_away_location.sql` - Warehouse locations
- `migration_inbox_chat.sql` - Chat templates/analytics

## Configuration
- Copy `config/config.example.php` to `config/config.php`
- Set database credentials, LINE API keys, AI API keys
- Timezone: Asia/Bangkok (Thai locale)

## Coding Conventions
- UTF-8 encoding throughout (Thai language support)
- PDO with prepared statements for all queries
- JSON responses from API endpoints with `success`, `message`, `data` structure
- Session-based authentication for admin panel
- LINE user ID for customer identification in LIFF
- Role-based menu access (owner, admin, pharmacist, staff, marketing, tech)

## Testing Approach
- Property-based tests in `/tests/{Feature}/` folders
- Tests validate requirements from specs
- Run tests after implementing features: `composer test`

## Key Service Classes by Domain

### POS & Sales
- `POSService`, `POSPaymentService`, `POSShiftService`, `POSReturnService`, `POSReceiptService`, `POSReportService`

### Inventory & Warehouse
- `InventoryService`, `BatchService`, `LocationService`, `PutAwayService`, `WMSService`, `WMSPrintService`

### Procurement
- `PurchaseOrderService`, `SupplierService`

### Accounting
- `AccountPayableService`, `AccountReceivableService`, `ExpenseService`, `PaymentVoucherService`, `ReceiptVoucherService`, `AccountingDashboardService`

### AI & Pharmacy
- `GeminiAI`, `GeminiChat`, `DrugPricingEngineService`, `DrugRecommendEngineService`, `CustomerHealthEngineService`, `PharmacyGhostDraftService`, `ConsultationAnalyzerService`, `PharmacyImageAnalyzerService`

### Communication
- `LineAPI`, `InboxService`, `TemplateService`, `NotificationService`, `TelegramAPI`

### Landing & SEO
- `LandingSEOService`, `FAQService`, `TestimonialService`, `TrustBadgeService`, `SitemapGenerator`
