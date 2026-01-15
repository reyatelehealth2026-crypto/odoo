# Project Structure

## Entry Points
- `/index.php` - Public landing page (SEO optimized)
- `/liff/index.php` - LIFF SPA for LINE users (main customer app)
- `/webhook.php` - LINE webhook handler
- `/admin/index.php` - Admin panel entry
- `/pos.php` - Point of sale interface
- `/inbox-v2.php` - Vibe Selling OS v2 inbox

## Directory Layout

```
/
├── api/                    # REST API endpoints (JSON responses)
│   ├── checkout.php        # Cart & order operations
│   ├── inventory.php       # Stock management
│   ├── pos.php             # POS transactions
│   ├── accounting.php      # AP/AR/Expenses
│   ├── wms.php             # Pick-pack-ship
│   ├── inbox-v2.php        # Vibe Selling v2 API
│   └── ...
│
├── classes/                # Service classes (business logic)
│   ├── Database.php        # DB singleton
│   ├── LineAPI.php         # LINE messaging
│   ├── POSService.php      # POS operations
│   ├── InventoryService.php
│   ├── WMSService.php      # Pick-pack-ship
│   ├── AccountPayableService.php
│   ├── AccountReceivableService.php
│   ├── BatchService.php    # Batch/lot tracking
│   ├── LocationService.php # Warehouse locations
│   ├── PutAwayService.php  # Put away logic
│   └── *Service.php        # Domain services
│
├── includes/               # PHP includes & UI components
│   ├── header.php          # Admin header/sidebar (role-based menu)
│   ├── footer.php
│   ├── auth_check.php      # Authentication guard
│   ├── {module}/           # Module-specific includes (tab content)
│   │   ├── inventory/      # products, stock, movements, wms, batches, locations
│   │   ├── pharmacy/       # dashboard, pharmacists, interactions, dispense
│   │   ├── pos/            # sales, cart, payment, reports, modals
│   │   ├── accounting/     # dashboard, ap, ar, expenses
│   │   ├── procurement/    # po, gr, suppliers
│   │   ├── landing/        # SEO, FAQ, testimonials, banners
│   │   └── ...
│
├── config/                 # Configuration files
│   ├── config.php          # Main config (gitignored)
│   └── database.php        # DB connection helper
│
├── database/               # SQL files
│   ├── schema_complete.sql # Full schema
│   └── migration_*.sql     # Incremental migrations
│
├── install/                # Installation & migration runners
│   ├── run_*_migration.php
│   └── debug_*.php         # Debug utilities
│
├── cron/                   # Scheduled tasks
│   ├── medication_reminder.php
│   ├── sync_worker.php
│   └── ...
│
├── liff/                   # LIFF SPA application
│   ├── index.php           # SPA entry (client-side routing)
│   └── assets/             # LIFF-specific CSS/JS
│       ├── js/store.js     # State management
│       ├── js/router.js    # Client-side router
│       └── js/liff-app.js  # Main controller
│
├── assets/                 # Static assets
│   ├── css/
│   ├── js/
│   └── images/
│
├── tests/                  # PHPUnit tests
│   ├── VibeSelling/        # Vibe Selling property tests
│   ├── InboxChat/          # Inbox chat tests
│   ├── LandingPage/        # Landing page tests
│   ├── GoodsReceiveDisposal/
│   └── ...
│
├── modules/                # Modular components (PSR-4)
│   ├── AIChat/             # AI chat adapters
│   └── Onboarding/         # Setup assistant
│
├── .kiro/specs/            # Feature specifications
│   ├── {feature}/
│   │   ├── requirements.md
│   │   ├── design.md
│   │   └── tasks.md
│
└── shop/, inventory/, admin/, auth/, user/  # Page folders
```

## Page Patterns
- Main pages at root: `dashboard.php`, `inbox.php`, `pharmacy.php`, `accounting.php`
- Tab-based UI: `?tab=products`, `?tab=orders`, `?tab=ap`
- Includes loaded per tab: `includes/{module}/{tab}.php`

## API Pattern
- Endpoint: `/api/{resource}.php?action={action}`
- JSON input via `php://input` or `$_POST`
- Response: `{"success": bool, "message": string, "data": {...}}`

## Service Class Pattern
```php
class SomeService {
    private $db;
    private $lineAccountId;
    
    public function __construct($db, $lineAccountId = null) {
        $this->db = $db;
        $this->lineAccountId = $lineAccountId;
    }
}
```

## Spec-Driven Development
Features are developed using specs in `.kiro/specs/`:
1. `requirements.md` - User stories and acceptance criteria
2. `design.md` - Technical design and data models
3. `tasks.md` - Implementation checklist with property tests
