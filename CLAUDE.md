# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP 8.0+ multi-tenant CRM/e-commerce platform for Thai pharmacies integrating LINE Official Accounts, Odoo ERP, AI (Gemini/OpenAI), and telepharmacy. All UI text and DB comments are bilingual Thai/English. Timezone is always `Asia/Bangkok` (`+07:00`).

## Commands

```bash
# PHP dependencies
composer install

# Run all tests (PHPUnit, property-based)
composer test

# Run a single test file
./vendor/bin/phpunit tests/LandingPage/ShopDataDisplayPropertyTest.php

# Static analysis (PHPStan level 0)
composer analyse

# Code style check (PSR-12, dry-run)
composer lint

# Apply code style fixes
composer lint:fix

# Node.js WebSocket server (dev)
npm install && npm run dev

# Modern backend API — Fastify + Prisma + TypeScript
cd backend && npm install && npm run dev   # dev server (tsx watch)
cd backend && npm test                     # Vitest
cd backend && npm run prisma:studio        # Prisma Studio UI

# Admin dashboard — Next.js 16
cd frontend && npm install && npm run dev
cd frontend && npm test                    # Jest
cd frontend && npm run test:coverage       # Jest with coverage

# LINE Mini App — Next.js 15 (active LIFF client)
cd line-mini-app && npm install && npm run dev

# Legacy LIFF app — React + Vite (read-only reference)
cd liff-app && npm install && npm run dev

# Docker — development
make dev-start    # start all containers (nginx, backend, frontend, mysql, redis)
make dev-stop
make dev-logs
make db-migrate   # run Prisma migrations inside backend container
make db-studio    # open Prisma Studio inside backend container

# Docker — production (blue-green)
make prod-deploy
make prod-logs

# Deploy to production (force — discards server-side changes)
bash force_deploy_testry.sh

# Deploy preserving local changes in stash
bash deploy_testry_branch.sh
```

## Architecture

### Entry Points

| Path | Purpose |
|------|---------|
| `webhook.php?account={id}` | LINE Messaging API webhook (multi-account) |
| `line-mini-app/` | **Active LINE Mini App** (Next.js 15) — shop (`/shop`), cart, App Router UI. This is the deployed LIFF experience. |
| `liff-app/` | **Legacy React+Vite LIFF** — read-only reference; do not add features here. |
| `liff/` | **Oldest legacy LIFF bundle** (`liff/index.php`, `liff/assets/js/liff-app.js`). Not used for routine production. |
| `api/*.php` | ~60 REST API endpoints |
| Root `*.php` files | Admin panel pages (104 files) |
| `cron/*.php` | ~30 scheduled background tasks |
| `index.php` | Public landing page |
| `backend/src/server.ts` | Modern Fastify + Prisma API (dashboard modernisation layer) |
| `frontend/src/app/` | Next.js 16 admin dashboard UI (TanStack Query) |
| `websocket-server.js` | Real-time inbox updates — Socket.io + Redis |
| `retail-api/` | Separate retail API with own routing, endpoints, and sync logic |

**LINE in-app UI:** The deployed LIFF experience is **`line-mini-app/`**. The older `liff/` SPA and `liff-app/` remain for reference/compat only — **do not add new shop features there.**

### Database — Always Use Singleton

```php
$db = Database::getInstance()->getConnection(); // returns PDO
```

`classes/Database.php` is a backward-compat wrapper around `modules/Core/Database.php`. Never instantiate PDO directly. Charset is `utf8mb4_unicode_ci`; MySQL timezone forced to `+07:00`. The backend Prisma schema also connects to MySQL (not PostgreSQL).

### Multi-Account LINE OA

Every LINE feature is scoped to a `line_account_id` FK against `line_accounts`. Pass `$lineAccountId` to service constructors (e.g., `new BusinessBot($db, $line, $lineAccountId)`). Webhook identifies account via `?account={id}` + HMAC-SHA256 signature validation.

### Service Class Patterns

- `classes/` — plain PHP classes, no namespace (legacy). Settings loaded from DB first, fall back to `config/config.php`, then hardcoded defaults.
- `modules/` — PSR-4 namespaced (`Modules\Core\`, `Modules\AIChat\`, `Modules\Onboarding\`).
- `app/` — `App\` namespace for Controllers, Models, Services, Views.

Autoloading declared in `composer.json`: `App\` → `app/`, `Classes\` → `classes/`, `Modules\` → `modules/`.

### Modern Services (added during dashboard modernisation)

| Service | Location | Stack |
|---------|----------|-------|
| REST API | `backend/` | TypeScript + Fastify + Prisma (MySQL) |
| Admin UI | `frontend/` | Next.js 16 + React 18 + TanStack Query |
| LINE Mini App | `line-mini-app/` | Next.js 15 + React 19 + TanStack Query |

These are independent Node.js apps containerised in `docker-compose.dev.yml` / `docker-compose.prod.yml`. The PHP monolith remains the source of truth for LINE events, shop orders, and all `line_account_id`-scoped features.

### Backend Route Structure

Routes in `backend/src/routes/` are: `audit.ts`, `auth.ts`, `customers.ts`, `dashboard.ts`, `health.ts`, `orders.ts`, `payments.ts`, `performance.ts`, `security.ts`. Middleware in `backend/src/middleware/`. Prisma schema at `backend/prisma/schema.prisma`.

### Docker — Blue-Green Deployment

- `docker-compose.blue.yml` / `docker-compose.green.yml` — blue-green production configs
- `docker-compose.dev.yml` — development environment
- `docker-compose.prod.yml` — production compose
- `docker/scripts/` — shell scripts for deploy, start, stop
- `docker/nginx/` — nginx configs
- Health check endpoints: `:8080/health` (nginx), `:4000/health` (backend), `:3001/health` (websocket)

### Standard Admin Page Template

```php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/header.php'; // pulls in auth_check.php, session, $currentUser
// ... page logic ...
require_once 'includes/footer.php';
```

Role helpers available after header: `isSuperAdmin()`, `isAdmin()`, `isStaff()`.
Role hierarchy: `super_admin` → `admin` → `pharmacist` / `marketing` / `tech` → `staff`

### Key Integrations

- **LINE API** — `classes/LineAPI.php`. Always pass token + secret from the `line_accounts` DB row, not from constants.
- **Odoo ERP** — `classes/OdooAPIClient.php` (JSON-RPC 2.0, circuit breaker + exponential backoff). Sync flow: Odoo webhook → `api/odoo-webhook.php` → `OdooSyncService` → cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`). Use `OdooAPIPool.php` for parallel fan-out queries.
- **AI** — `classes/GeminiAI.php` (primary), `classes/OpenAI.php`. Settings per `line_account_id` in `ai_settings` table.
- **Notifications** — `classes/NotificationRouter.php` fans out to LINE, Telegram, email.
- **Real-time** — Node.js + Socket.io WebSocket server (`websocket-server.js`).

## Commit Convention

Conventional Commits format: `type(scope): description`

Common types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`

Examples from this repo: `fix(checkout): …`, `feat(line-mini-app): …`, `feat(storefront): …`

## Conventions & Gotchas

- **`file_exists()` guards** — Optional classes (`BusinessBot`, `WebSocketNotifier`) are `require_once`-d only after a `file_exists()` check in `webhook.php`. Do the same for new optional integrations.
- **AI settings from DB** — Never hardcode AI model names. Stored in `ai_settings.model` (default `gemini-2.0-flash`).
- **Cache buster** — Bump build/version in `line-mini-app` when changing its assets. Legacy: `liff/index.php` has `$v` for the old SPA.
- **Odoo dashboard queries** — Always query cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`); never hit the Odoo API directly for dashboard reads.
- **`dev_logs` table** — Fatal errors in `webhook.php` are written here. For debug logging in webhook context: `INSERT INTO dev_logs (log_type, source, message, data, created_at)`.
- **Clean URLs** — `.htaccess` strips `.php` extensions. Use `cleanUrl()` from `includes/header.php` when building admin nav links.
- **Cron jobs** — New reminder/broadcast jobs go in `cron/` as separate files. Do not add to `scheduled.php` (admin-triggered only).
- **Tests** — Property-based; each test generates 100+ random cases per property. Bootstrap: `tests/bootstrap.php`.
- **Database schema** — 223 tables. Main migration: `database/install_complete_latest.sql`. Incremental changes go in `database/migration_*.sql`.
- **Server path** — `/home/zrismpsz/public_html/cny.re-ya.com` on production.

## Odoo Dashboard (`odoo-dashboard.php`)

### Frontend Architecture — Three-Tier Caching

```
sessionStorage (JS) → fast endpoint (<500ms) → heavy endpoint (cached, 30–60s TTL)
```

| Tier | File | TTL | หมายเหตุ |
|------|------|-----|---------|
| Client cache | `sessionStorage` via `_cacheGet/_cacheSet` | 5 min | instant, ไม่มี network |
| Fast endpoint | `api/odoo-dashboard-fast.php` | — | query เฉพาะ cache tables ที่มี index |
| Heavy endpoint | `api/odoo-dashboard-api.php` | 20–300s ต่อ action | PHP in-memory cache, fallback เต็ม |

### Fast Endpoint Actions (`WH_FAST_ACTIONS` ใน `odoo-dashboard.js`)

Actions ใน Set นี้จะถูก route ไป lightweight endpoint ก่อนเสมอ:
```javascript
const WH_FAST_ACTIONS = new Set([
    'health', 'overview_fast', 'orders_today_fast', 'customers_fast',
    'circuit_breaker_status', 'circuit_breaker_reset'
]);
```
**กฎ:** ถ้า `api/odoo-dashboard-fast.php` รองรับ action ใหม่ → ต้องเพิ่มใน `WH_FAST_ACTIONS` ด้วยเสมอ

### Performance Rules

- **CDN preconnect** — `odoo-dashboard.php` ต้องมี `<link rel="preconnect">` สำหรับ `fonts.googleapis.com`, `fonts.gstatic.com`, `cdn.jsdelivr.net` ก่อน `<link rel="stylesheet">` ทุกครั้ง
- **ห้าม query Odoo API โดยตรงใน dashboard** — ใช้ cache tables (`odoo_orders`, `odoo_invoices`, `odoo_bdos`, `odoo_customer_projection`) เท่านั้น
- **ห้าม query `odoo_webhooks_log` แบบ full scan** — ใช้ aggregate query ที่มี WHERE บน indexed column เท่านั้น
- **polling guard** — `setInterval` ที่เรียก API ต้องตรวจ `document.hidden` ก่อนทุกครั้ง

### JS Files ใน Dashboard

| ไฟล์ | สถานะ | หมายเหตุ |
|------|--------|---------|
| `odoo-dashboard.js` | active (~267KB) | ไฟล์หลัก โหลดโดย PHP |
| `odoo-dashboard-fast.php` | active | lightweight API endpoint |
| `odoo-dashboard-api.php` | active (~182KB) | heavy endpoint, PHP parse ~1.3s ถ้าไม่มี OPcache |
| `odoo-dashboard-optimized.js` | stub | สำรองสำหรับ optimization patches อนาคต |
| `odoo-dashboard-fixes.js` | stub | สำรองสำหรับ bug fix patches อนาคต |

### `whApiCall(data)` — ลำดับการ fallback

1. ถ้า action อยู่ใน `WH_FAST_ACTIONS` → ลอง `odoo-dashboard-fast.php` (timeout 5s)
2. ถ้า fast endpoint ตอบ `fallback: true` หรือ timeout → ต่อไปที่ heavy endpoint
3. Heavy endpoint: ลอง `WH_API_ACTIVE` ก่อน → fallback ไปทุก URL ใน `WH_API_CANDIDATES`

---

## Customer Churn Tracker (CRM) — Phase-1 to Conversation Viewer

> **เริ่มงาน: 2026-04-27. Live ที่ `https://cny.re-ya.com/customer-churn.php`** (auth-gated)
> Spec: `docs/plans/2026-04-27-customer-churn-tracker.md` (gitignored, local-only)
> PR: `https://github.com/reyatelehealth2026-crypto/odoo/pull/5`

ระบบ B2B churn detection สำหรับ CNY Wholesale ใช้ RFM segmentation + Gemini analyst brief + Inbox sentiment cross-reference + conversation viewer

### Architecture (เลเยอร์ที่สร้างเสร็จ)

```
RFM Engine        (Phase 1)  classes/CRM/RFMCalculator.php (pure functions)
   ↓
RFMRepository     (Phase 2)  reads odoo_orders → writes customer_rfm_profile
RFMService                   orchestrates Calculator + Repository
cron/calculate_rfm.php       nightly 02:00 (idempotent batch)
   ↓
Dashboard         (Phase 3)  customer-churn.php (light theme, tab nav)
api/churn-dashboard-data.php JSON endpoint for KPI/watchlist/cohort/health
assets/js/customer-churn.js  60s polling, modals, DOM-construction (XSS-safe)
   ↓
Talking Points    (Phase 4)  classes/CRM/TalkingPointsService.php
                             classes/CRM/PartnerContextLoader.php
                             api/churn-talking-points.php (Gemini analyst brief)
   ↓
Auto-Actions      (Phase 5)  classes/CRM/AutoActionService.php
                             cron/churn_{auto_checkin,assign_sales,escalate}.php
                             (DEACTIVATED: system_enabled=0, soft_launch=1)
   ↓
Settings/Feedback (Phase 6)  customer-churn-settings.php + api/churn-settings-update.php
   ↓
Inbox Sentinel    (Phase 7)  classes/CRM/InboxSentinel.php
                             api/churn-conversation.php
                             — context-aware Thai sentiment classifier
                             — getInboxFlagsForPartners() → P0 overlap card
                             — getConversation() → "💬 บทสนทนา" modal
```

### Database Schema (apply via `database/migration_customer_churn.sql`)

7 tables (gitignored migration, applied to prod 2026-04-27):

| Table | Role |
|---|---|
| `customer_rfm_profile` | one row per `odoo_partner_id` — segment, ratio, LTV, hysteresis state |
| `customer_segment_history` | append-only segment-transition log |
| `churn_talking_points_cache` | 24h Gemini cache (keyed by partner_id) |
| `customer_call_log` | sales outreach + auto-action queue (currently empty) |
| `winback_campaigns` + `winback_offers` | campaign + per-customer coupon |
| `churn_settings` | single-row feature flags + thresholds |

**Customer key:** ใช้ `odoo_partner_id INT UNSIGNED` ตลอด (ไม่ใช่ `customer_id`). Bridge ผ่าน table `odoo_line_users` (LINE↔Odoo) — **ห้ามสร้าง `customer_identity_map` ใหม่**

### Segments (boundary spec — exact)
- **Champion**: ratio < 1.0
- **Watchlist**: 1.0 ≤ ratio < 1.5
- **At-Risk**: 1.5 ≤ ratio < 2.0
- **Lost**: 2.0 ≤ ratio < 3.0
- **Churned**: ratio ≥ 3.0 + high-value (top-20% LTV, ≈ ฿79,285 cutoff)
- **Hibernating**: ratio ≥ 3.0 + not high-value
- **Hysteresis buffer**: 0.2 (At-Risk → Watchlist เมื่อ ratio < 1.3)

### Production Live Numbers (verified 2026-04-27)
- 415 RFM-eligible partners (≥3 orders + ≥30-day span)
- Distribution: Champion 295 / Watchlist 58 / At-Risk 25 / Lost 22 / Churned-VIP 2 / Hibernating 13
- LTV total ฿28.8M, top-20% cutoff ≈ ฿79,285
- Inbox 3.5-month historical scan: 23,291 messages, 157 P1 complaints, 15 P0 critical-overlap (live system shows 1 P0 in 30-day window)

### Gemini Integration

- **Key location:** `ai_settings` table (DB-backed, NOT `.env`); row `WHERE line_account_id IS NULL` is the default for churn talking-points
- **Model:** `gemini-flash-latest` (alias → `gemini-3-flash-preview` as of 2026-04-27)
- **Fallback chain in `classes/GeminiAI.php`:** flash-latest → 2.5-flash → 2.0-flash-lite → 2.0-flash → 1.5-flash → 1.5-pro → pro
- **Gemini-3 quirk:** reasoning tokens count toward `maxOutputTokens`. Class sets `maxOutputTokens=1500` + `thinkingConfig.thinkingBudget=0` so visible reply gets full budget.
- **Cost guard:** `churn_settings.gemini_daily_cap_calls` (default 200/day), per-partner cache 24h, response audit-logged in `dev_logs`

### Output Schema (analyst brief, NOT customer-facing script)

`TalkingPointsService` produces JSON with **8 required keys**:
- `executive_summary` (string)
- `health_signals` (array of `{label, severity ∈ low/medium/high/critical, detail}`)
- `behavior_pattern` (string)
- `risk_factors` (string[])
- `opportunities` (string[])
- `recommended_actions` (array of `{priority ∈ P1/P2/P3, action, owner}`)
- `data_quality_caveats` (string[])
- `internal_note_for_sales` (string, copyable)

Pivoted from "talking points" → "internal analyst brief" 2026-04-27 — **ไม่มีการ generate ข้อความที่จะส่งหาลูกค้า**

### Inbox Sentinel — Sentiment Classifier (Thai)

`classes/CRM/InboxSentinel::classify(string)` returns `red|orange|yellow|yellow_urgent|green|null`

**Context-aware rules (verified on 23k message corpus):**
- `"หมดอายุเมื่อไรคะ"` = คำถาม → `null` (benign)
- `"ใกล้หมดอายุ" / "ของหมดอายุ" / "หมดอายุแล้ว"` → `red` (real complaint)
- `"ของยังไม่ได้รับ"` → `red` (matches `"ไม่ได้รับ"` pattern)
- `"ขอเปลี่ยน" / "ตามผล"` → `yellow`
- `"ขอเปลี่ยน...ด่วน"` → `yellow_urgent`

**P0 priority matrix:**
| Inbox sentiment | Churn segment | Priority |
|---|---|---|
| 🔴 ร้องเรียน | Churned-VIP | 🚨 P0 — Manager escalate |
| 🔴 ร้องเรียน | Lost / At-Risk | 🚨 P0 — Sales ตามด่วน |
| 🟠 ไม่พอใจ | Lost / Churned | 🚨 P1 — escalate |
| 🟡 ตามผลด่วน | any | P2 — ตอบใน 1 ชม. |

### Dashboard UI (`customer-churn.php`)

**Roles:** super_admin / admin / sales / pharmacist

| Element | Purpose |
|---|---|
| 🚨 P0 Critical Overlap card (top) | ลูกค้าที่ทั้งบ่นใน inbox 30d + อยู่ใน Churned/Lost/At-Risk |
| 6-card KPI strip | Champion / Watchlist / At-Risk / Lost / Churned-VIP / Hibernating |
| Watchlist table | sorted by priority |
| 📬 Inbox column (per row) | sentiment pill + tooltip |
| ↗ ดู Odoo (per row) | → `odoo-customer-detail.php?partner_id=&ref=&name=` |
| 🤖 AI วิเคราะห์ (per row) | Gemini-backed analyst brief modal |
| 💬 บทสนทนา (per row) | conversation timeline 30d — bubbles + day dividers + sentiment tint |
| Cohort chart | segment distribution (Chart.js, light theme) |
| System Health strip | total_eligible, last_computed_at, gemini_calls_today, mode |

**Tab nav linked:** `📊 รายงานกล่องข้อความ` (`inbox-intelligence.html`) ↔ `🚩 ลูกค้าหลุดรอบ` (`customer-churn.php`) ↔ `⚙️ ตั้งค่าระบบ` (super_admin only)

**Cache busting:** `<script src="assets/js/customer-churn.js?v=<?= filemtime(...) ?>">` — bump on each deploy

### Safety Guards (CRITICAL — ห้ามแตะโดยไม่ confirm)

| Guard | Default | Effect |
|---|---|---|
| `churn_settings.system_enabled` | **0** | ทุก auto-action no-op |
| `churn_settings.soft_launch` | **1** | double safety — ไม่ส่ง notification แม้เปิด system_enabled |
| `churn_settings.notification_recipients` | `[]` | ไม่มี Manager รับ escalation |
| Crontab auto-actions | **0 lines** | `churn_auto_checkin/assign_sales/escalate` ไม่ scheduled |
| Active cron | `calculate_rfm.php` 02:00 | read-only RFM compute เท่านั้น |
| Modal/UI buttons | read-only | ไม่มีปุ่ม "ส่งหาลูกค้า" |

**ห้ามเปิด auto-action จนกว่า:** stakeholder ตอบ notification_recipients + post 7-day soft-launch review

### Tests

`tests/CRM/` — **117 tests / 1,051 assertions / 100% pass** (run via `composer test`)

ครอบคลุม: RFMCalculator boundaries + property-based, RFMRepository (sqlite fixture), TalkingPointsService cache+validate, AutoActionService, ChurnSettingsValidation, CustomerChurnDashboard structural, **InboxSentinel classifier + getConversation**

### Key Files Reference

| Component | Path |
|---|---|
| Spec doc | `docs/plans/2026-04-27-customer-churn-tracker.md` (gitignored) |
| Migration | `database/migration_customer_churn.sql` (gitignored, applied to prod) |
| Core logic | `classes/CRM/{RFMCalculator,CycleResult,RFMRepository,RFMService,TalkingPointsService,PartnerContextLoader,AutoActionService,InboxSentinel}.php` |
| Cron | `cron/{calculate_rfm,churn_auto_checkin,churn_assign_sales,churn_escalate}.php` |
| API | `api/churn-{dashboard-data,talking-points,settings-update,conversation,inbox-issues}.php` |
| Pages | `customer-churn.php`, `customer-churn-settings.php` |
| Assets | `assets/js/customer-churn.js` (light theme, no innerHTML) |
| Tests | `tests/CRM/*.php` (8 files) |

### Server / Deploy

- **Production:** `root@47.82.233.152:/www/wwwroot/cny.re-ya.com`
- **SSH key (verified working):** `~/.ssh/id_rsa` (skill docs ระบุ id_ed25519/id_cny_secure_2026 — ทั้ง 2 ผิดบนเครื่อง dev นี้)
- **Pull:** `cd /www/wwwroot/cny.re-ya.com && git pull origin main --ff-only`
- **PHP CLI:** `/www/server/php/83/bin/php`
- **MySQL:** `/www/server/mysql/bin/mysql -u cny_re_ya_com -pcny_re_ya_com -S /tmp/mysql.sock cny_re_ya_com`
- **Cron entry (active):** `0 2 * * * php cron/calculate_rfm.php >> logs/calculate_rfm.log 2>&1`

### Recent Commits (chronological)
- `2f237fa` Phase 1 — RFMCalculator core
- `9e560a9` Phase 2-6 parallel-worker delivery
- `73f1a7c` defensive NULL date_order
- `872dc33` dev_logs.log_type enum fix
- `e821df7` light theme + tab nav + Odoo deep-link
- `ab2a179` align odoo-customer-detail.js with primary API
- `082d426` Gemini fallback chain (2.5/flash-lite)
- `a1293f0` gemini-flash-latest + Gemini-3 thinking budget
- `a930a67` pivot to internal analyst brief + UI button
- `bd86e74` fix envelope shape (data.payload → flat)
- `00c8e3f` cache-bust customer-churn.js
- `2a5fdd4` integrate InboxSentinel + P0 overlap card
- `a512866` conversation viewer (💬 บทสนทนา modal)

### TODO / รอ stakeholder

- [ ] `churn_settings.notification_recipients` — ใครรับ Manager escalation?
- [ ] Hibernating newsletter content — reuse template เดิม หรือสร้างใหม่?
- [ ] Investigate ทำไม production data ไม่มีลูกค้า > 90 วันเลย (RFM cron บอก 0 ใน range 91+) — Odoo cache filter หรือ data limitation?
- [ ] หลัง 7 วัน soft-launch review → `UPDATE churn_settings SET soft_launch=0, system_enabled=1` (รอ confirm)
- [ ] Schedule auto-action crons เมื่อ system_enabled=1 (3 cron lines)
- [ ] Coverage report บน CI ที่มี xdebug/pcov (local ไม่มี)
- [ ] Promote `docs/plans/*.md` + `database/migration_*.sql` ออกจาก gitignore (ยัง local-only)
