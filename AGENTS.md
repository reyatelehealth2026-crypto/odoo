# AGENTS.md

## Cursor Cloud specific instructions

### Services overview

| Service | Port | How to run | Notes |
|---------|------|-----------|-------|
| PHP legacy app | 8080 | `php -S 0.0.0.0:8080 -t .` from repo root | Main CRM/e-commerce platform; requires MySQL for most pages; install wizard (`/install/wizard.php`) works without DB |
| WebSocket server | 3001 | `npm run dev` from repo root | Requires Redis (`redis-server`) running on localhost:6379 |
| Backend (Fastify) | 4000 | `npm run dev` from `backend/` | Requires MySQL + Redis; needs `backend/.env` (copy from `backend/.env.example`) |
| Frontend (Next.js) | 3000 | `npm run dev` from `frontend/` | Pre-existing ESM bug in `next.config.mjs` (line 81 uses `require('path')` in `.mjs`); blocks both dev and build |

### Commands reference

See `CLAUDE.md` for the full command table. Key commands:

- **PHP lint**: `composer lint` (PSR-12 dry-run via php-cs-fixer)
- **PHP analysis**: `composer analyse` (PHPStan level 0 on `classes/` and `app/`)
- **PHP tests**: `composer test` (PHPUnit); individual suites: `./vendor/bin/phpunit tests/LandingPage/`
- **Backend lint**: backend has no `.eslintrc` config, so `npm run lint` in `backend/` exits with "all files ignored"
- **Frontend lint**: `npm run lint` in `frontend/` (Next.js ESLint)
- **Frontend tests**: `npm test` in `frontend/` (Jest)
- **Backend tests**: `npm test` in `backend/` (Vitest); requires local MySQL

### Gotchas

- **PHP version**: The VM needs PHP 8.0+ installed via `sudo apt-get install -y php php-cli php-pdo php-mysql php-mbstring php-curl php-xml php-zip php-tokenizer php-bcmath php-gd php-sqlite3`. Also install Composer: `curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer`.
- **Composer lock out of date**: The `composer.lock` may be missing newly added dev dependencies (phpstan, php-cs-fixer). Run `composer update --no-interaction` if `composer install` fails with lock file errors.
- **PHPUnit test suites that pass without DB**: `LandingPage`, `FileConsolidation`, `GoodsReceiveDisposal` (property-based tests that don't need MySQL).
- **PHPUnit test suites that have pre-existing failures**: `AIChat` (assertion mismatches), `VibeSelling` (missing services), `AdminMenu` (risky tests with no assertions), `InboxChat` (may need DB).
- **Backend tests need MySQL**: The Vitest setup (`backend/src/test/setup.ts`) shells out to `mysql` CLI to create test databases. Without a local MySQL server, all backend tests fail.
- **Frontend Next.js config bug**: `next.config.mjs` line 81 uses CommonJS `require('path')` inside an ES module. This causes `ReferenceError: require is not defined` on both `next dev` and `next build`. This is a pre-existing codebase issue.
- **Node.js engine**: Root `package.json` requires `>=14.0.0`, `backend/` requires `>=18.0.0`. The VM has Node.js 22 which satisfies both but produces engine warnings for `fast-jwt` (requires `<22`).
