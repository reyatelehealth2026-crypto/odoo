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
