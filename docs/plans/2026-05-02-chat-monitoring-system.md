# Chat Monitoring System — Internal Tool Spec

**เริ่มงาน:** 2026-05-02
**Stack:** **PHP 8.1+** standalone, MySQL 8 / MariaDB 10.6+, jQuery + Chart.js (light theme)
**Mode:** **Internal use** — ใช้เองในทีม ไม่ใช่ product
**Repo:** สร้างใหม่แยก — ตัวอย่าง `chat-sla-monitor`
**Deploy:** server แยก / subdomain แยกจาก `cny.re-ya.com`
**LINE OA:** ใช้ OA ใหม่ ไม่ใช้ของ `cny.re-ya.com`
**Constraint:** เก็บข้อมูลได้เฉพาะ **inbound** (ฝั่งลูกค้าทักเข้ามา)

> **ขอบเขต:** standalone tool ภายใน — ไม่ multi-tenant, ไม่มี self-serve onboarding, ไม่ encrypt-at-rest แบบหนัก, ไม่ pricing tier; แค่ "ใช้เองให้ได้งาน"

---

## 1. โจทย์

| # | Requirement |
|---|---|
| R1 | ตรวจ + แจ้งเตือน LINE chat ที่ "ยังไม่ได้รับการ respond" |
| R2 | ตรวจ + แจ้ง chat ที่ "ทำท่าจะมีปัญหา" ต้องเฝ้าระวัง |
| R3 | Report — `respond time` (median / p95 / avg) |
| R4 | Report — สรุปจำนวน chat ทั้งหมด |
| R5 | Report — % chat ตอบช้าเกิน N นาที |
| R6 | Report — จำนวน complaint |
| R7 | Report เสริมตามคำแนะนำ |
| C1 | เก็บได้แค่ inbound — ต้องอนุมาน "ตอบแล้ว" จาก signal ทางอ้อม |

---

## 2. Inbound-only — นิยาม "ตอบแล้ว" (priority chain)

| Priority | Signal | Source |
|---|---|---|
| 1 | LINE Mark-as-Read API ตอบ 200 | ระบบเรียกเมื่อ admin เปิด chat ใน dashboard นี้ ใช้ `markAsReadToken` จาก webhook |
| 2 | Manual ack (ปุ่มในหน้า watchlist) | DB row ใน `acks` |
| 3 | Inbound ใหม่ของ user เดียวกันหลังเงียบ ≥ N นาที | timestamp diff (soft fallback, มี flag) |

ทุก row ใน `sla_pending` บันทึก `closed_via` เพื่อ audit ความน่าเชื่อถือ

> **UI disclaimer:** "ระบบนับ respond จากเวลา admin เปิดอ่านใน dashboard นี้ หรือกด Mark as Read; การตอบผ่าน LINE Chat โดยตรงจะไม่ถูกนับ"

---

## 3. Architecture

```
LINE Platform (OA ใหม่, แยกจาก cny.re-ya.com)
    │  webhook (HTTPS, HMAC signed)
    ▼
POST /webhook/line.php?oa_id={id}
    │
    ▼
┌──────────────────────────────────────────────┐
│  chat-sla-monitor (PHP 8.1, standalone)      │
│                                              │
│  public/                                     │
│    webhook/line.php                          │
│    login.php / logout.php                    │
│    dashboard.php                             │
│    conversation.php                          │
│    settings.php                              │
│    api/{kpi,watchlist,ack,oa}.php            │
│                                              │
│  classes/  (PSR-4 \App\)                     │
│    Core/{Database,Auth,Config}.php           │
│    Ingest/{LineWebhookController,            │
│            MessageStore,LineApi}.php         │
│    Sla/{PendingTracker,OverdueScanner,       │
│         BusinessHoursCalculator}.php         │
│    Sentiment/InboxSentinel.php  (port)       │
│    Reports/KpiAggregator.php                 │
│    Notify/{Router,Telegram,LineChannel}.php  │
│                                              │
│  cron/                                       │
│    scan_overdue.php       (every 5 min)      │
│    aggregate_kpi.php      (nightly 00:30)    │
│    cleanup.php            (weekly Sun 03:00) │
│                                              │
│  database/migrations/001_initial.sql         │
│  tests/  (PHPUnit)                           │
└──────────────────────────────────────────────┘
    │
    ▼
MySQL (own DB, own user — ไม่แชร์กับ cny.re-ya.com)
```

### Patterns ที่ "ยืม" จากทีม `cny.re-ya.com` (ไม่ import code)

| Pattern | นำมาใช้ |
|---|---|
| Singleton DB | `App\Core\Database::pdo()` |
| Settings single-row + `system_enabled` + `soft_launch` flags | `monitor_settings` |
| InboxSentinel regex (red/orange/yellow/yellow_urgent/green) | port code ตรง — ระบุ origin commit ใน docblock |
| Business-hour calc (skip 18:00–08:00 + Sunday) | `Sla\BusinessHoursCalculator` |
| Light-theme dashboard + tab nav | reuse layout (Bootstrap 5 + jQuery) |
| Cron: scanner 5m + aggregator nightly | identical rhythm |
| Conventional Commits | `feat(sla):`, `fix(sentiment):` |
| PHPUnit property-based + fixture | same approach |

---

## 4. Database Schema (`database/migrations/001_initial.sql`)

```sql
-- 4.1 LINE OA accounts ที่จะ monitor (1+ OAs)
CREATE TABLE `oa_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `display_name` VARCHAR(120) NOT NULL,
  `channel_id` VARCHAR(40) NOT NULL,
  `channel_secret` VARCHAR(80) NOT NULL,
  `channel_access_token` TEXT NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.2 LINE users (chat partners)
CREATE TABLE `chat_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `oa_id` INT UNSIGNED NOT NULL,
  `line_user_id` VARCHAR(64) NOT NULL,
  `display_name` VARCHAR(120) DEFAULT NULL,
  `picture_url` VARCHAR(500) DEFAULT NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_inbound_at` DATETIME NOT NULL,
  `total_inbound_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_complaint_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_oa` (`oa_id`, `line_user_id`),
  KEY `idx_oa_last` (`oa_id`, `last_inbound_at` DESC),
  CONSTRAINT `fk_user_oa` FOREIGN KEY (`oa_id`) REFERENCES `oa_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.3 Inbound messages
CREATE TABLE `messages_inbound` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `oa_id` INT UNSIGNED NOT NULL,
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `line_message_id` VARCHAR(40) NOT NULL,
  `message_type` ENUM('text','image','sticker','video','audio','file','location','other') NOT NULL,
  `content_text` TEXT DEFAULT NULL,
  `content_meta` JSON DEFAULT NULL,
  `mark_as_read_token` VARCHAR(255) DEFAULT NULL,
  `received_at` DATETIME(3) NOT NULL,
  `ingested_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `sentiment` ENUM('red','orange','yellow_urgent','yellow','green','neutral') DEFAULT 'neutral',
  `sentiment_matched_term` VARCHAR(80) DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_line_msg` (`oa_id`, `line_message_id`),
  KEY `idx_user_received` (`chat_user_id`, `received_at` DESC),
  KEY `idx_oa_received` (`oa_id`, `received_at` DESC),
  KEY `idx_sentiment` (`sentiment`, `received_at` DESC),
  CONSTRAINT `fk_msg_user` FOREIGN KEY (`chat_user_id`) REFERENCES `chat_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.4 SLA pending tracker
CREATE TABLE `sla_pending` (
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `oa_id` INT UNSIGNED NOT NULL,
  `first_inbound_at` DATETIME NOT NULL,
  `first_inbound_msg_id` BIGINT UNSIGNED NOT NULL,
  `latest_inbound_at` DATETIME NOT NULL,
  `inbound_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `worst_sentiment` ENUM('red','orange','yellow_urgent','yellow','green','neutral') NOT NULL DEFAULT 'neutral',
  `business_seconds_waited` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_overdue_slow` TINYINT(1) NOT NULL DEFAULT 0,
  `is_overdue_critical` TINYINT(1) NOT NULL DEFAULT 0,
  `last_alert_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_via` ENUM('read_receipt','manual_ack','next_inbound_stale','expired') DEFAULT NULL,
  `respond_seconds_business` INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`chat_user_id`),
  KEY `idx_open_overdue` (`closed_at`, `is_overdue_slow`, `first_inbound_at`),
  KEY `idx_oa_open` (`oa_id`, `closed_at`, `first_inbound_at`),
  KEY `idx_sentiment_open` (`worst_sentiment`, `closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.5 Manual acks (audit)
CREATE TABLE `acks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `acked_by_admin_id` INT UNSIGNED NOT NULL,
  `acked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`chat_user_id`),
  KEY `idx_admin_time` (`acked_by_admin_id`, `acked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.6 Alerts dispatched (dedup)
CREATE TABLE `alerts_sent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `alert_type` ENUM('slow','critical','sentiment_red','sentiment_orange','sentiment_urgent') NOT NULL,
  `channel` ENUM('telegram','line_push','email') NOT NULL,
  `recipient_target` VARCHAR(255) NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dedup` (`chat_user_id`, `alert_type`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.7 Daily KPI rollup
CREATE TABLE `kpi_daily` (
  `kpi_date` DATE NOT NULL,
  `oa_id` INT UNSIGNED NOT NULL,
  `total_chats` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_inbound_messages` INT UNSIGNED NOT NULL DEFAULT 0,
  `responded_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `slow_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `critical_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `unanswered_eod_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `complaint_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `dissatisfied_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `urgent_followup_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `respond_p50_sec` INT UNSIGNED DEFAULT NULL,
  `respond_p95_sec` INT UNSIGNED DEFAULT NULL,
  `respond_avg_sec` INT UNSIGNED DEFAULT NULL,
  `slow_pct` DECIMAL(5,2) DEFAULT NULL,
  `complaint_pct` DECIMAL(5,2) DEFAULT NULL,
  `computed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kpi_date`, `oa_id`),
  KEY `idx_date` (`kpi_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.8 Settings (single row)
CREATE TABLE `monitor_settings` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `system_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `soft_launch` TINYINT(1) NOT NULL DEFAULT 1,
  `slow_threshold_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `critical_threshold_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `business_hours_start` TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `business_hours_end` TINYINT UNSIGNED NOT NULL DEFAULT 18,
  `business_days` VARCHAR(20) NOT NULL DEFAULT 'mon,tue,wed,thu,fri,sat',
  `holiday_dates` JSON NOT NULL,
  `notification_recipients` JSON NOT NULL,
  `alert_cooldown_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `sentiment_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `slow_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_mark_as_read_on_view` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_singleton_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `monitor_settings` (id, holiday_dates, notification_recipients)
VALUES (1, JSON_ARRAY(), JSON_ARRAY());

-- 4.9 Admin users (own login, ไม่ SSO)
CREATE TABLE `admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `role` ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.10 Webhook event log (auto-prune > 30d)
CREATE TABLE `webhook_events_raw` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `oa_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `payload` JSON NOT NULL,
  `signature_valid` TINYINT(1) NOT NULL,
  `received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_oa_received` (`oa_id`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Total: 9 ตาราง** (เดิม 10) — ตัด `tenants`, รวม `messages_inbound` เป็น single-tenant

---

## 5. Webhook — `public/webhook/line.php`

URL: `https://chat-sla.example.com/webhook/line.php?oa_id={id}`

**Flow:**
1. Read raw body + `X-Line-Signature`
2. Lookup `oa_accounts` ตาม `oa_id` query → ได้ `channel_secret`
3. HMAC-SHA256 verify → fail = 200 + log `signature_valid=0` + return
4. INSERT `webhook_events_raw` (forensics)
5. Foreach event:
   - `event.type == 'message'` + `source.type == 'user'`:
     - Upsert `chat_users`
     - INSERT `messages_inbound` (idempotent ผ่าน UNIQUE)
     - `InboxSentinel::classify()` → update `sentiment`
     - Upsert `sla_pending` (open conversation cycle)
     - Trigger sentiment alert ทันทีถ้า red (ผ่าน NotificationRouter)
   - `event.type == 'unfollow'` → close pending if any
6. Return 200 ภายใน 1 วินาที

**Security:**
- Signature verify เป็น hard requirement
- `oa_id` query ใช้เป็น routing เท่านั้น — ความปลอดภัยมาจาก signature
- Body limit 1 MB
- ไม่เรียก LINE markAsRead ใน webhook flow — ทำเมื่อ admin เปิด chat ใน UI เท่านั้น (กัน LINE retry storm)

---

## 6. Service Classes (`classes/`, namespace `App\`)

| Class | Responsibility |
|---|---|
| `Core\Database` | PDO singleton; force `+07:00` timezone, `utf8mb4` |
| `Core\Auth` | session login + RBAC (admin/viewer); CSRF token |
| `Core\Config` | load `.env` + `config/config.php` |
| `Ingest\LineWebhookController` | endpoint logic |
| `Ingest\MessageStore` | upsert chat_users + insert messages_inbound (idempotent) |
| `Ingest\LineApi` | thin wrapper: `markAsRead`, `getProfile` |
| `Sla\PendingTracker` | onInbound() / onRead() / onAck() |
| `Sla\OverdueScanner` | scan open rows + tag overdue + emit alerts |
| `Sla\BusinessHoursCalculator` | compute business seconds |
| `Sentiment\InboxSentinel` | port regex classifier |
| `Reports\KpiAggregator` | rebuild kpi_daily idempotent |
| `Reports\WatchlistQuery` | dashboard read queries |
| `Notify\Router` | dispatch with cooldown |
| `Notify\TelegramChannel` / `LineChannel` / `EmailChannel` | per-channel senders |

---

## 7. Reports

### 7.1 หลัก (ตามโจทย์)

| Report | สูตร | Source |
|---|---|---|
| Respond time (p50/p95/avg) | `sla_pending.respond_seconds_business` | `kpi_daily.respond_p*_sec` |
| สรุปจำนวน chat | `COUNT(DISTINCT chat_user_id)` | `kpi_daily.total_chats` |
| % ตอบช้าเกิน N นาที | `slow_count / responded_count * 100` | `kpi_daily.slow_pct` |
| จำนวน complaint | `worst_sentiment='red'` count | `kpi_daily.complaint_count` |

### 7.2 เสริมที่แนะนำ

| Report | คุณค่า |
|---|---|
| **Unanswered EOD** ต่อวัน | backlog ที่ตกหล่น |
| **Response-time histogram** (10 buckets, รายสัปดาห์) | distribution shape |
| **Sentiment trend** (% red/orange ต่อ total, รายสัปดาห์) | service quality direction |
| **Heatmap วัน × ชั่วโมง** (7 วันล่าสุด) | บอก staffing — peak hour ไหน slow |
| **Top-N customers by complaint** (รายเดือน) | repeat complainers |
| **Repeat-inbound count before response** | ลูกค้าทักซ้ำกี่ครั้งก่อนได้คำตอบ |

> **ไม่ทำ:** "per-agent SLA" — outgoing data ไม่มี ระบุ agent ตอบไม่ได้

---

## 8. Cron Jobs

| File | Schedule | งาน |
|---|---|---|
| `cron/scan_overdue.php` | every 5 min | tag overdue + dispatch alert |
| `cron/aggregate_kpi.php` | nightly 00:30 | rebuild `kpi_daily` ของ "เมื่อวาน" + recompute 7d |
| `cron/cleanup.php` | weekly Sun 03:00 | close `sla_pending` > 7d เป็น `expired`; prune `webhook_events_raw` > 30d |

ทุก cron: `set_time_limit(180)`, JSON-line log ใน `logs/`, fail-safe

---

## 9. Notifications

Channel: Telegram primary, LINE Push secondary, Email tertiary

```
🚨 Chat Overdue (CRITICAL)
OA: ACME Main
ลูกค้า: คุณ X (LINE: U1234…)
รอตอบ: 67 นาที (เกิน 60 นาที)
ข้อความล่าสุด: "ของยังไม่ได้รับเลยค่ะ"
Sentiment: 🔴 ร้องเรียน
👉 https://chat-sla.example.com/conversation.php?user_id=4521
```

**Cooldown:** ลูกค้าเดียว + alert_type เดียว → ไม่ส่งซ้ำใน `alert_cooldown_minutes`

**Escalation:**
1. `slow_threshold` (15m) → Telegram ops
2. `critical_threshold` (60m) → Telegram ops + manager
3. `sentiment=red` ทันที → Telegram ops (ไม่รอ overdue)

---

## 10. Admin UI

### 10.1 Login (`public/login.php`)
- Email + password (`password_hash` / `password_verify`)
- PHP native session, CSRF token, rate limit 5 fail / 15 min
- Logout = `session_destroy()`

### 10.2 Dashboard (`public/dashboard.php`)

```
[📨 Inbox SLA] [📊 Reports] [⚠️ Watchlist] [⚙️ Settings (admin only)]

🚨 Critical Overdue card — N chats > 60min
KPI Strip: Total | Responded | Slow% | Complaint | Avg Respond | EOD
📈 Response-time chart (14d, p50/p95)
📋 Watchlist table — sortable
   Cols: User | OA | Wait | Sentiment | Last msg | [📬 Open] [✓ Ack]
🌡️ Heatmap (day × hour, 7d)
📅 Daily KPI table (30d)
```

- Polling 60s + `document.hidden` guard
- Cache buster `?v=<filemtime>`
- DOM construction (no `innerHTML` กับ user content)

### 10.3 Conversation viewer (`public/conversation.php?user_id=`)
- Inbound timeline 30d (bubbles, day dividers, sentiment tint)
- ปุ่ม `Mark as Read` → เรียก `LineApi::markAsRead`
- ปุ่ม `Manual Ack` → INSERT `acks` + close `sla_pending`

### 10.4 Settings (`public/settings.php`, role: admin)
- ฟิลด์ตาม `monitor_settings` (§4.8)
- Admin user CRUD
- OA accounts CRUD (เพิ่ม/ลบ OA, แก้ token)
- Show webhook URL ให้ copy ไปวางใน LINE Developers Console

---

## 11. API Endpoints (JSON, auth required)

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/kpi.php?date_from=&date_to=&oa_id=` | GET | KPI strip + chart |
| `/api/watchlist.php?type=overdue\|sentiment` | GET | watchlist |
| `/api/conversation.php?user_id=&days=` | GET | timeline |
| `/api/ack.php` | POST | manual ack |
| `/api/mark-read.php` | POST | trigger LINE Mark-as-Read |
| `/api/settings.php` | GET/POST | settings (admin only) |
| `/api/oa.php` | GET/POST/DELETE | manage OAs (admin only) |
| `/api/admin-users.php` | GET/POST/DELETE | manage admins (admin only) |

ทั้งหมด: `Cache-Control: private, max-age=30`, JSON envelope `{ok, data?, error?}`

---

## 12. Project Layout

```
chat-sla-monitor/
├── composer.json              # PSR-4: App\ → classes/
├── README.md
├── .env.example
├── public/
│   ├── index.php              # router → dashboard or login
│   ├── login.php / logout.php
│   ├── dashboard.php
│   ├── conversation.php
│   ├── settings.php
│   ├── webhook/line.php
│   ├── api/*.php
│   └── assets/{css,js}
├── classes/                   # PSR-4 App\
│   ├── Core/
│   ├── Ingest/
│   ├── Sla/
│   ├── Sentiment/
│   ├── Reports/
│   └── Notify/
├── cron/
│   ├── scan_overdue.php
│   ├── aggregate_kpi.php
│   └── cleanup.php
├── database/migrations/001_initial.sql
├── tests/                     # PHPUnit
│   ├── bootstrap.php
│   └── {Sla,Sentiment,Reports,Ingest,Notify,Core}/
├── config/config.php          # gitignored
└── logs/                      # gitignored
```

---

## 13. Phases (ลด → 5 phase, ~2 สัปดาห์)

| Phase | ขอบเขต | DoD |
|---|---|---|
| **P1 — Bootstrap** | repo + composer + DB migration + auth + 1 OA seed | login ได้, schema apply, webhook URL พร้อม |
| **P2 — Webhook ingest + sentiment** | LineWebhookController + MessageStore + InboxSentinel + PendingTracker | inbound → row ใน 3 ตาราง; idempotent; sentiment ถูก class |
| **P3 — Dashboard read-only** | dashboard.php + watchlist + conversation viewer + KPI live calc | 4 reports หลักของโจทย์แสดงผล |
| **P4 — KPI cron + alerts (soft-launch)** | aggregate_kpi.php + scan_overdue.php + Notify\Router; **`soft_launch=1`** → log only | alerts row ใน DB ไม่มี outbound |
| **P5 — Go-live** | settings UI + manual ack + holiday list; เปิด `system_enabled=1`, `soft_launch=0` | 7-day soft-launch review pass |

หลัง P5 ค่อยทำ §7.2 reports เสริมตามต้องการ

---

## 14. Acceptance Criteria

- [ ] Webhook signature verify ผ่าน 100% (replay + tampered tests)
- [ ] Webhook ingest p95 < 200 ms
- [ ] Idempotency: ส่ง event เดิม 5 ครั้ง → 1 row
- [ ] InboxSentinel ผลตรงต้นฉบับบน fixture (port test ของ `cny.re-ya.com`)
- [ ] `respond_seconds_business` ตรง manual calc บน 50 case
- [ ] KPI 30-day backfill match (±2%) กับ manual SQL
- [ ] Slow alert ส่งภายใน ≤ 6 นาทีหลัง threshold
- [ ] No duplicate alerts ใน cooldown
- [ ] Dashboard load < 1s
- [ ] Tests: ≥ 80% coverage `classes/Sla`, `classes/Sentiment`, `classes/Reports`

---

## 15. Tests (`tests/`)

| File | ครอบคลุม |
|---|---|
| `Ingest/LineWebhookControllerTest.php` | signature verify, idempotency |
| `Ingest/MessageStoreTest.php` | upsert + sentiment ordering |
| `Sla/PendingTrackerTest.php` | onInbound/onRead/onAck idempotent |
| `Sla/BusinessHoursCalculatorTest.php` | weekend, EOD spillover, holiday |
| `Sla/OverdueScannerTest.php` | threshold boundary |
| `Sentiment/InboxSentinelTest.php` | port golden test ของ `cny.re-ya.com` (23k corpus) |
| `Reports/KpiAggregatorTest.php` | numeric correctness |
| `Notify/RouterTest.php` | cooldown, multi-channel |
| `Core/AuthTest.php` | RBAC, session, CSRF |
| `Integration/EndToEndTest.php` | webhook → pending → overdue → alert (MySQL test DB) |

---

## 16. Safety Guards

| Guard | Default | ผล |
|---|---|---|
| `monitor_settings.system_enabled` | **0** | scanner cron no-op |
| `monitor_settings.soft_launch` | **1** | log alert ใน DB ไม่ส่งจริง |
| `monitor_settings.notification_recipients` | `[]` | ไม่มี recipient |
| Crontab | **0 lines** until P5 | ไม่ scheduled |
| OA token storage | DB user permission + filesystem mode 600 | ไม่มี encryption-at-rest แบบหนัก |
| Webhook try/catch swallow + return 200 | LINE ไม่ retry storm | resilient |

> **ไม่เปิด `system_enabled=1` จน:** ผ่าน 7-day soft-launch + ตั้ง `notification_recipients` ครบ

---

## 17. Open Questions (รอ confirm)

- [ ] Slow threshold = 15m, critical = 60m — ใช่?
- [ ] Business hours = 08:00–18:00 จันทร์–เสาร์ — ใช่?
- [ ] Holiday list — ใช้ของไทยทั้งประเทศ หรือ custom ทีม? (default: ใส่เองใน settings UI)
- [ ] Telegram bot — สร้างใหม่ หรือ reuse bot เดิมของทีม?
- [ ] Manual ack — admin ได้ทุกคน, viewer ห้าม — ใช่?
- [ ] เก็บ `messages_inbound` กี่วัน? (default 90; aggregate-only หลังจากนั้น)
- [ ] Server / subdomain ที่จะ deploy?
- [ ] LINE markAsRead — auto เมื่อเปิด chat (default), หรือต้องกดปุ่ม?

---

## 18. Conventions (ยืมจาก `cny.re-ya.com`)

- PHP 8.1+, `declare(strict_types=1)`
- PSR-4 autoload `App\` → `classes/`, PSR-12 (`composer lint`)
- PHPStan level 5+
- Charset `utf8mb4_unicode_ci`, timezone `Asia/Bangkok` / MySQL `+07:00`
- Conventional Commits — `feat(sla):`, `fix(webhook):`
- ไม่ hardcode threshold — อ่าน `monitor_settings`
- Tests: PHPUnit + property-based
- `.env` + `config/config.php` gitignored
- CI: GitHub Actions (lint + test + phpstan)

---

## 19. Cross-References

- Pattern source: `cny.re-ya.com` repo (ส่วนตัว)
  - InboxSentinel logic: `classes/CRM/InboxSentinel.php` commit `2a5fdd4`
  - Soft-launch pattern: `customer-churn.php` + `classes/CRM/AutoActionService.php`
  - Light theme + tab nav: `customer-churn.php`
  - Business-hour calc: `cron/inbox-response-time-collector.php`
- Spec template (churn): `docs/plans/2026-04-27-customer-churn-tracker.md`
- LINE Messaging API: https://developers.line.biz/en/reference/messaging-api/

---

## 20. Decision Log

| Date | Decision | Rationale |
|---|---|---|
| 2026-05-02 | Standalone PHP project, repo แยก | "แยก" ชัดเจน — ไม่แตะระบบเดิม |
| 2026-05-02 | Stack PHP 8.1 (ไม่ Node/Python) | ทีมคุ้น, ส่งเร็วสุด ~2 สัปดาห์ |
| 2026-05-02 | Internal use, single-tenant | ไม่ขายเป็น product → ตัด multi-tenant ออก |
| 2026-05-02 | LINE OA แยก, deploy แยก, login แยก | ใช้คำตอบ "แยกทั้งหมด" |
| 2026-05-02 | InboxSentinel — port code (ไม่ import package) | regex ทดสอบบน 23k corpus แล้ว มูลค่า > drift risk |
| 2026-05-02 | DB encryption-at-rest แบบเบา (ไม่ AES-GCM) | internal — file/DB permission พอ |

