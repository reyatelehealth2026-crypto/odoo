# Chat Monitoring System — Standalone Project Spec

**เริ่มงาน:** 2026-05-02
**Stack:** **PHP 8.1+** (standalone), MySQL 8 / MariaDB 10.6+, jQuery + Chart.js (light theme)
**Branch ของสเปค:** `claude/chat-monitoring-system-cRHsb` (ใน repo `odoo`)
**Repo เป้าหมายของโปรเจคจริง:** **แยก repo ใหม่** — ชื่อตัวอย่าง `chat-sla-monitor`
**Constraint:** เก็บข้อมูลได้เฉพาะ **inbound** (ฝั่งที่ลูกค้าทักเข้ามา)

> **หมายเหตุสำคัญ:** โปรเจคนี้ **ไม่ใช้ table / service / webhook ของระบบเดิม** ใน `cny.re-ya.com` — แต่ **ยืม pattern การออกแบบ** (ชั้น service, multi-tenant scoping, safety flags, light-theme dashboard, sentiment regex, BusinessHoursCalculator) เพื่อความ consistent กับวิธีทำงานของทีม

---

## 1. ทำไมต้องแยก

| เหตุผล | ผลที่ได้ |
|---|---|
| ระบบเดิมใหญ่ (104 admin pages, 60 APIs) — แตะ webhook.php มี risk | โปรเจคใหม่ deploy/rollback ได้อิสระ |
| Customer ต่างคนต่างต้องการ monitor หลายเจ้า | multi-tenant ตั้งแต่ day-1 — ไม่ผูก `cny.re-ya.com` |
| ปลอดภัยจาก migration / schema lock ของระบบเดิม | DB คนละก้อน, downtime ไม่กระทบกัน |
| ทดสอบ + ขายเป็น product แยกได้ | repo, billing, support แยกกัน |

> **ระบบเดิมไม่ต้องแก้แม้แต่บรรทัดเดียว** ใน `webhook.php` หรือ `inbox-v2.php` ของ `cny.re-ya.com`

---

## 2. โจทย์ (Requirements) — ย้ำ

| # | Requirement |
|---|---|
| R1 | ตรวจ + แจ้งเตือนว่า LINE chat ไหน "ยังไม่ได้รับการ respond" |
| R2 | ตรวจ + แจ้งว่า chat ไหน "ทำท่าจะมีปัญหา" ต้องเฝ้าระวัง |
| R3 | Report — `respond time` (median / p95 / avg) |
| R4 | Report — สรุปจำนวน chat ทั้งหมด |
| R5 | Report — % chat ตอบช้าเกิน N นาที (config ได้) |
| R6 | Report — จำนวน complaint |
| R7 | Report เสริมตามคำแนะนำ |
| C1 | **Constraint:** เก็บได้แค่ inbound — ต้องอนุมาน "ตอบแล้ว" จาก signal ทางอ้อม |

---

## 3. Inbound-only — นิยาม "ตอบแล้ว"

โปรเจคใหม่รับ webhook ของ LINE ตรง (ไม่ผ่าน webhook.php เดิม) แต่ก็ยัง **ไม่มี outgoing event** เพราะ:
- staff ส่วนใหญ่ตอบผ่าน **LINE Official Account Manager** หรือ **LINE Chat app** → outgoing ไม่ผ่าน webhook ของใคร
- API ของ LINE **ไม่เปิด** ให้ดึง outgoing history ย้อนหลัง

### Signal ที่ใช้แทน "ตอบแล้ว" (priority chain)

| Priority | Signal | วิธีได้ | Source ใน LINE |
|---|---|---|---|
| 1 | LINE Mark-as-Read API | ระบบเรียก `markAsRead` เมื่อ admin เปิดอ่านใน dashboard | webhook ส่ง `event.message.markAsReadToken` มากับทุก message event ([LINE doc](https://developers.line.biz/en/reference/messaging-api/#message-event)) |
| 2 | Manual ack ใน UI | ปุ่ม "ทำเครื่องหมายว่าตอบแล้ว" ใน watchlist | DB row ใน `acks` |
| 3 | Outgoing ที่ผ่าน Push API ของระบบ (ถ้ามี) | optional — ถ้าลูกค้าใช้ระบบเรา push reply ด้วย | webhook ของเราเอง |
| 4 | Inbound ใหม่ของ user เดียวกันหลังเงียบไป N นาที | soft fallback | timestamp diff |

ทุก row บันทึก `respond_source` (`read_receipt` / `manual_ack` / `our_push` / `next_inbound_stale`) เพื่อ audit ความน่าเชื่อถือของ KPI

> **คำเตือน UI:** dashboard ต้องแสดง disclaimer ชัดเจน — "ระบบนี้นับ respond จากเวลาที่ admin เปิดอ่านใน dashboard นี้ หรือกดปุ่ม Mark as Read; การตอบผ่าน LINE Chat โดยตรงจะไม่ถูกนับ"

---

## 4. Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  LINE Platform (ของลูกค้า — แต่ละ tenant 1+ OA)                 │
│      │                                                           │
│      │  webhook (HTTPS, HMAC signed)                             │
│      ▼                                                           │
│  POST /webhook/line?tenant_id={id}                               │
└──────────────────────────────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────────────────────────┐
│  chat-sla-monitor (PHP 8.1)                                      │
│                                                                  │
│  public/                                                         │
│    webhook/line.php       ← ingest inbound, store, classify,    │
│                             upsert pending tracker               │
│    api/*.php              ← dashboard JSON (KPI, watchlist)     │
│    *.php                  ← admin pages (login, dashboard,      │
│                             tenants, settings, reports)          │
│                                                                  │
│  classes/  (PSR-4 \App\)                                         │
│    Core/{Database,Config,Auth,Router}.php                        │
│    Ingest/LineWebhookController.php                              │
│    Ingest/MessageStore.php                                       │
│    Sla/PendingTracker.php                                        │
│    Sla/OverdueScanner.php                                        │
│    Sla/BusinessHoursCalculator.php                               │
│    Sentiment/InboxSentinel.php  (ported regex)                   │
│    Reports/KpiAggregator.php                                     │
│    Notify/NotificationRouter.php                                 │
│    Notify/TelegramChannel.php                                    │
│    Notify/LineChannel.php          ← push API ใช้ token tenant  │
│                                                                  │
│  cron/                                                           │
│    scan_overdue.php       (every 5 min)                          │
│    aggregate_kpi.php      (nightly 00:30)                        │
│    cleanup.php            (weekly Sun 03:00)                     │
│    poll_read_state.php    (every 10 min, optional)               │
│                                                                  │
│  database/migrations/*.sql                                       │
│  composer.json | tests/ (PHPUnit)                                │
└──────────────────────────────────────────────────────────────────┘
                       │
                       ▼
                  ┌──────────┐
                  │  MySQL   │
                  └──────────┘
```

### Pattern ที่ "ยืม" จากทีม (ไม่ใช่ import code)

| Pattern จาก `cny.re-ya.com` | นำมาใช้ใน chat-sla-monitor |
|---|---|
| Singleton DB (`Database::getInstance()->getConnection()`) | `App\Core\Database::pdo()` |
| Multi-account scoping (`line_account_id`) | `tenant_id` + `oa_id` (composite) |
| Settings single-row + `system_enabled` + `soft_launch` flags | `monitor_settings` แบบเดียวกัน |
| InboxSentinel regex (red/orange/yellow/yellow_urgent/green) | port code ตรง — ระบุ origin commit ใน docblock |
| BusinessHoursCalculator (skip 18:00–08:00 + Sunday) | implementation ใหม่ตาม logic เดิม |
| Light-theme admin dashboard + tab nav | reuse layout pattern (Bootstrap 5 + jQuery) |
| Cron schedule: scanner (5m) + aggregator (nightly) | identical pattern |
| Conventional Commits | `feat(sla):`, `fix(sentiment):` |
| Property-based tests (`tests/`) | PHPUnit + same approach |

---

## 5. Multi-Tenant Model

โปรเจคใหม่ถูกออกแบบเป็น **SaaS-ready** — รองรับลูกค้าหลายเจ้าตั้งแต่แรก

```
tenant (บริษัทลูกค้า)              ── 1 : N ──> oa_account (LINE OA)
    │                                                  │
    │                                                  ├─ 1 : N ──> chat_user (LINE userId)
    │                                                  │                  │
    └─ 1 : N ──> admin_user (login)                    └─ 1 : N ──> message_inbound
                                                                          │
                                                                          └─ 1 : 1 ──> sla_tracking
```

**Tenant isolation:** ทุก query MUST scope `WHERE tenant_id = ?`; ไม่มี global table ที่ไม่ scope

---

## 6. Database Schema (`database/migrations/001_initial.sql`)

```sql
-- ============================================================
-- 6.1 Tenants & OA accounts
-- ============================================================
CREATE TABLE `tenants` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `status` ENUM('active','suspended','trial') NOT NULL DEFAULT 'trial',
  `timezone` VARCHAR(40) NOT NULL DEFAULT 'Asia/Bangkok',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oa_accounts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `channel_id` VARCHAR(40) NOT NULL,
  `channel_secret` VARCHAR(80) NOT NULL  COMMENT 'encrypted at rest (AES-256-GCM)',
  `channel_access_token` TEXT NOT NULL    COMMENT 'encrypted at rest',
  `webhook_secret_path` VARCHAR(40) NOT NULL UNIQUE COMMENT 'opaque path token; webhook URL = /webhook/line/{token}',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_active` (`tenant_id`, `is_active`),
  CONSTRAINT `fk_oa_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.2 LINE users (chat partners)
-- ============================================================
CREATE TABLE `chat_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `oa_id` INT UNSIGNED NOT NULL,
  `line_user_id` VARCHAR(64) NOT NULL  COMMENT 'LINE userId (Uxxxxx)',
  `display_name` VARCHAR(120) DEFAULT NULL,
  `picture_url` VARCHAR(500) DEFAULT NULL,
  `first_seen_at` DATETIME NOT NULL,
  `last_inbound_at` DATETIME NOT NULL,
  `total_inbound_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_complaint_count` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_oa` (`oa_id`, `line_user_id`),
  KEY `idx_tenant_last` (`tenant_id`, `last_inbound_at`),
  CONSTRAINT `fk_user_oa` FOREIGN KEY (`oa_id`) REFERENCES `oa_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.3 Inbound messages — main ingestion table
-- ============================================================
CREATE TABLE `messages_inbound` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `oa_id` INT UNSIGNED NOT NULL,
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `line_message_id` VARCHAR(40) NOT NULL,
  `message_type` ENUM('text','image','sticker','video','audio','file','location','other') NOT NULL,
  `content_text` TEXT DEFAULT NULL,
  `content_meta` JSON DEFAULT NULL  COMMENT 'sticker_id, file_url, etc.',
  `mark_as_read_token` VARCHAR(255) DEFAULT NULL,
  `received_at` DATETIME(3) NOT NULL  COMMENT 'event.timestamp from LINE',
  `ingested_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `sentiment` ENUM('red','orange','yellow_urgent','yellow','green','neutral') DEFAULT 'neutral',
  `sentiment_matched_term` VARCHAR(80) DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL  COMMENT 'when LINE Mark-as-Read returned 200',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_line_msg` (`oa_id`, `line_message_id`)  COMMENT 'webhook idempotency',
  KEY `idx_user_received` (`chat_user_id`, `received_at` DESC),
  KEY `idx_oa_received` (`oa_id`, `received_at` DESC),
  KEY `idx_tenant_received` (`tenant_id`, `received_at` DESC),
  KEY `idx_sentiment_received` (`sentiment`, `received_at` DESC),
  CONSTRAINT `fk_msg_user` FOREIGN KEY (`chat_user_id`) REFERENCES `chat_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.4 SLA pending tracker — one row per "user has unresponded inbound"
-- ============================================================
CREATE TABLE `sla_pending` (
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `oa_id` INT UNSIGNED NOT NULL,
  `first_inbound_at` DATETIME NOT NULL,
  `first_inbound_msg_id` BIGINT UNSIGNED NOT NULL,
  `latest_inbound_at` DATETIME NOT NULL,
  `inbound_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `worst_sentiment` ENUM('red','orange','yellow_urgent','yellow','green','neutral') NOT NULL DEFAULT 'neutral',
  `business_seconds_waited` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'recomputed by scanner',
  `is_overdue_slow` TINYINT(1) NOT NULL DEFAULT 0,
  `is_overdue_critical` TINYINT(1) NOT NULL DEFAULT 0,
  `last_alert_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_via` ENUM('read_receipt','manual_ack','our_push','next_inbound_stale','expired') DEFAULT NULL,
  `respond_seconds_business` INT UNSIGNED DEFAULT NULL  COMMENT 'set on close',
  PRIMARY KEY (`chat_user_id`),
  KEY `idx_open_overdue` (`closed_at`, `is_overdue_slow`, `first_inbound_at`),
  KEY `idx_tenant_open` (`tenant_id`, `closed_at`, `first_inbound_at`),
  KEY `idx_sentiment_open` (`worst_sentiment`, `closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.5 Manual acks (audit)
-- ============================================================
CREATE TABLE `acks` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `acked_by_admin_id` INT UNSIGNED NOT NULL,
  `acked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_time` (`tenant_id`, `acked_at`),
  KEY `idx_user` (`chat_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.6 Alerts dispatched (dedup)
-- ============================================================
CREATE TABLE `alerts_sent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `chat_user_id` BIGINT UNSIGNED NOT NULL,
  `alert_type` ENUM('slow','critical','sentiment_red','sentiment_orange','sentiment_urgent') NOT NULL,
  `channel` ENUM('telegram','line_push','email','webhook') NOT NULL,
  `recipient_target` VARCHAR(255) NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dedup` (`chat_user_id`, `alert_type`, `sent_at`),
  KEY `idx_tenant_time` (`tenant_id`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.7 Daily KPI rollup (per tenant + per OA)
-- ============================================================
CREATE TABLE `kpi_daily` (
  `kpi_date` DATE NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
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
  PRIMARY KEY (`kpi_date`, `tenant_id`, `oa_id`),
  KEY `idx_tenant_date` (`tenant_id`, `kpi_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.8 Settings (per tenant)
-- ============================================================
CREATE TABLE `monitor_settings` (
  `tenant_id` INT UNSIGNED NOT NULL,
  `system_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `soft_launch` TINYINT(1) NOT NULL DEFAULT 1,
  `slow_threshold_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `critical_threshold_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `business_hours_start` TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `business_hours_end` TINYINT UNSIGNED NOT NULL DEFAULT 18,
  `business_days` VARCHAR(20) NOT NULL DEFAULT 'mon,tue,wed,thu,fri,sat',
  `holiday_dates` JSON NOT NULL  COMMENT '["2026-04-13","2026-04-14"]',
  `notification_recipients` JSON NOT NULL  COMMENT '[{channel,target,role}]',
  `alert_cooldown_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `sentiment_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `slow_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_mark_as_read_on_view` TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'call LINE markAsRead when admin opens chat',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `fk_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.9 Admin users (login + RBAC)
-- ============================================================
CREATE TABLE `admin_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `role` ENUM('owner','admin','viewer') NOT NULL DEFAULT 'viewer',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tenant_email` (`tenant_id`, `email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6.10 Webhook event log (forensics; auto-prune > 30 day)
-- ============================================================
CREATE TABLE `webhook_events_raw` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `oa_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `payload` JSON NOT NULL,
  `signature_valid` TINYINT(1) NOT NULL,
  `received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_oa_received` (`oa_id`, `received_at`),
  KEY `idx_event_type` (`event_type`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 7. Webhook Endpoint — `public/webhook/line.php`

URL pattern: `https://<host>/webhook/line/{webhook_secret_path}`
(แต่ละ OA ได้ path opaque ของตัวเอง — ไม่ต้องส่ง tenant_id ใน query)

### Flow

```
1. Read raw body + X-Line-Signature
2. Extract webhook_secret_path from URL
3. Lookup oa_account → get channel_secret (decrypt)
4. HMAC-SHA256 verify signature  → ถ้า fail: 200 + log + return
5. INSERT webhook_events_raw (forensics)
6. Foreach event:
   - if event.type == "message" and source.type == "user":
       a. Upsert chat_users
       b. INSERT messages_inbound (ON DUPLICATE KEY = idempotent)
       c. Run InboxSentinel::classify() → update sentiment
       d. Call LINE markAsRead (async via queue OR sync if soft_launch=0 + auto_mark=0)
            ↓ stored read_at when 200 OK
       e. Upsert sla_pending (this is the "new conversation cycle")
   - if event.type == "follow"  → update chat_users
   - if event.type == "unfollow" → mark chat_users.is_followed=0
7. Return HTTP 200 within 1 sec (LINE retry on 5xx)
```

### Security

- Signature verify เป็น **HARD requirement** — fail = drop event
- Path token (`webhook_secret_path`) เป็น **secondary defense** กัน enumeration (ไม่ใช่ replacement signature)
- Body size limit 1 MB (LINE max ~ 100 KB)
- Rate limit per oa_id: 100 events/sec (ใส่ token bucket ใน `App\Core\RateLimit`)

---

## 8. Service Classes (`classes/`, namespace `App\`)

| Class | Responsibility |
|---|---|
| `Core\Database` | PDO singleton; force `+07:00` timezone; `utf8mb4` |
| `Core\Config` | load `.env` + `config.php` |
| `Core\Auth` | session-based login + RBAC (owner/admin/viewer) |
| `Core\Crypto` | AES-256-GCM for OA secrets at rest |
| `Ingest\LineWebhookController` | webhook endpoint logic |
| `Ingest\MessageStore` | upsert chat_users + insert messages_inbound (idempotent) |
| `Ingest\LineApi` | thin wrapper: markAsRead, getProfile, push (optional) |
| `Sla\PendingTracker` | onInbound() / onRead() / onAck() / onPush() |
| `Sla\OverdueScanner` | scan open rows + tag overdue + emit alerts |
| `Sla\BusinessHoursCalculator` | compute business seconds between 2 timestamps |
| `Sentiment\InboxSentinel` | port of regex classifier (red/orange/yellow_urgent/yellow/green) |
| `Reports\KpiAggregator` | rebuild kpi_daily for date range (idempotent) |
| `Reports\WatchlistQuery` | dashboard read queries |
| `Notify\NotificationRouter` | dispatch via channels with cooldown |
| `Notify\TelegramChannel` | bot API |
| `Notify\LineChannel` | push API (uses tenant's OA token) |
| `Notify\EmailChannel` | SMTP (optional) |

> **InboxSentinel — port not fork:** ระบุใน docblock ว่ามาจาก commit `2a5fdd4` ของ `cny.re-ya.com` (verified บน 23,291 messages, 157 P1 + 15 P0); ถ้าต้นทางอัพเดท regex → ทำ `composer require` ผ่าน private package เพื่อกัน drift (Phase 6)

---

## 9. Reports

### 9.1 Reports หลักตามโจทย์

| Report | สูตร | Source |
|---|---|---|
| `respond time` (p50/p95/avg) | `sla_pending.respond_seconds_business` ของ row ที่ closed_at IN (date range) | aggregated → `kpi_daily.respond_p*_sec` |
| สรุปจำนวน chat ทั้งหมด | `COUNT(DISTINCT chat_user_id)` ที่มี inbound ใน range | `kpi_daily.total_chats` |
| % chat ตอบช้าเกิน N นาที | `slow_count / responded_count * 100` | `kpi_daily.slow_pct` |
| จำนวน complaint | `COUNT(DISTINCT chat_user_id)` ที่ worst_sentiment='red' | `kpi_daily.complaint_count` |

### 9.2 Reports เสริมที่แนะนำ

| Report | คุณค่า |
|---|---|
| **Unanswered EOD** ต่อวัน | backlog ที่หลุดออกจากสายตา ไม่ใช่แค่ slow |
| **Response-time histogram** (10 buckets, รายสัปดาห์) | จับ outlier; เห็น distribution shape |
| **Sentiment trend** (% red/orange/yellow_urgent ต่อ total, รายสัปดาห์) | service-quality direction |
| **Heatmap วัน × ชั่วโมง** | บอก staffing — peak hour ไหน slow บ่อย |
| **Top-N customers by complaint** (รายเดือน) | repeat complainers → ส่งต่อให้ทีม CS |
| **Repeat-inbound count before response** | proxy ความหงุดหงิด — ลูกค้าทักซ้ำกี่ครั้งก่อนได้คำตอบ |
| **Per-OA leaderboard** (ถ้า tenant มีหลาย OA) | benchmark ภายในของลูกค้าเอง |
| **Webhook health** (% events accepted vs rejected) | ระบบ infra เอง |

> **ไม่ทำ:** "per-agent SLA" — เพราะไม่มี outgoing reliable, ระบุ agent ตอบไม่ได้

---

## 10. Cron Jobs

| File | Schedule | งาน |
|---|---|---|
| `cron/scan_overdue.php` | every 5 min | scan `sla_pending` หา overdue (business-hour adjusted) → tag + dispatch alert |
| `cron/aggregate_kpi.php` | nightly 00:30 | rebuild `kpi_daily` ของ "เมื่อวาน" + recompute 7 วันล่าสุด |
| `cron/cleanup.php` | weekly Sun 03:00 | close pending > 7 วัน เป็น `expired`; prune `webhook_events_raw` > 30 วัน |
| `cron/poll_read_state.php` (optional) | every 10 min | ถ้า settings เปิด — poll LINE Insight API แทน real-time markAsRead |

ทุก cron: `set_time_limit(180)`, log เป็น JSON line ใน `logs/<file>.log`, fail-safe

---

## 11. Notifications (Alert Format)

Channel: Telegram primary, LINE Push secondary (ใช้ token ของ tenant), Email tertiary

```
🚨 Chat Overdue (CRITICAL)
Tenant: ACME Pharmacy
OA: ACME Main (#7)
ลูกค้า: คุณ X (LINE: U1234…)
รอตอบ: 67 นาที (เกิน critical 60 นาที)
ข้อความล่าสุด: "ของยังไม่ได้รับเลยค่ะ"
Sentiment: 🔴 ร้องเรียน
👉 https://chat-sla.example.com/conversation/4521
```

**Cooldown:** ลูกค้าเดียว + alert_type เดียว → ไม่ส่งซ้ำใน `alert_cooldown_minutes`
**Escalation ladder:**
1. `slow_threshold` (15m) → Telegram ops channel
2. `critical_threshold` (60m) → Telegram ops + manager DM
3. `sentiment=red` ทันทีที่ inbound → Telegram ops (ไม่รอ overdue)

---

## 12. Admin UI

### 12.1 Login (`public/login.php`)
- Email + password (`password_hash` / `password_verify`)
- Session via PHP native; CSRF token ทุก POST
- Rate limit: 5 fail attempts / 15 min

### 12.2 Dashboard (`public/dashboard.php`)

```
┌──────────────────────────────────────────────────────────────┐
│ Tabs: [📨 Inbox SLA] [📊 Reports] [⚠️ Watchlist] [⚙️ Settings] │
├──────────────────────────────────────────────────────────────┤
│ 🚨 CRITICAL OVERDUE  — N chats > 60min wait                  │
├──────────────────────────────────────────────────────────────┤
│ KPI Strip                                                     │
│  Total | Responded | Slow% | Complaint | Avg Respond | EOD   │
├──────────────────────────────────────────────────────────────┤
│ 📈 Response-time chart (14d, p50/p95)                        │
├──────────────────────────────────────────────────────────────┤
│ 📋 Watchlist table — sortable                                 │
│  Cols: User | OA | Wait | Sentiment | Last msg | [📬] [✓]    │
├──────────────────────────────────────────────────────────────┤
│ 🌡️ Heatmap (day × hour, 7d)                                   │
└──────────────────────────────────────────────────────────────┘
```

- Polling: 60s (`document.hidden` guard ตามแบบทีม)
- Cache busting: `?v=<filemtime>` ทุก asset
- ไม่ใช้ `innerHTML` กับ user content (XSS-safe DOM construction)

### 12.3 Conversation viewer (`public/conversation.php?user_id=`)

แสดง inbound messages timeline 30d — bubble + day divider + sentiment tint
ปุ่ม: `Mark as Read` (เรียก LINE API), `Manual Ack` (สำหรับเคสที่ตอบนอกระบบไปแล้ว)

### 12.4 Settings (`public/settings.php`, role: admin/owner)

ตามฟิลด์ใน `monitor_settings` (§6.8) — threshold, business hours, recipients, holiday list

### 12.5 Tenant onboarding (`public/onboarding.php`, role: owner)

| Step | Action |
|---|---|
| 1 | สร้าง LINE OA Channel + Messaging API |
| 2 | กรอก `channel_id`, `channel_secret`, `channel_access_token` |
| 3 | ระบบสร้าง `webhook_secret_path` random | ให้ user copy webhook URL ไปวางใน LINE Developers Console |
| 4 | Test event → ระบบรอ verify event ภายใน 5 นาที |
| 5 | เปิด `system_enabled=1` หลัง verify ผ่าน |

---

## 13. API Endpoints (JSON, auth required)

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/kpi.php?date_from=&date_to=&oa_id=` | GET | KPI strip + chart |
| `/api/watchlist.php?type=overdue|sentiment` | GET | watchlist tables |
| `/api/conversation.php?user_id=&days=` | GET | timeline messages |
| `/api/ack.php` | POST | manual ack a chat |
| `/api/mark-read.php` | POST | trigger LINE Mark-as-Read |
| `/api/settings.php` | GET/POST | read/update monitor_settings |
| `/api/oa.php` | GET/POST/DELETE | manage OA accounts (owner only) |
| `/api/admin-users.php` | GET/POST/DELETE | manage admin team (owner only) |
| `/api/health.php` | GET | uptime + queue depth + last cron run |

ทั้งหมด: `Cache-Control: private, max-age=30`, JSON response, error envelope `{ok: false, error: {code, message}}`

---

## 14. Project Layout

```
chat-sla-monitor/
├── composer.json              # PSR-4: App\ → classes/
├── README.md
├── .env.example               # DB_DSN, APP_KEY, encryption key
├── docker-compose.yml         # php-fpm + nginx + mysql + redis (optional)
├── Dockerfile
├── public/                    # web root
│   ├── index.php              # router → dashboard or login
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── conversation.php
│   ├── settings.php
│   ├── onboarding.php
│   ├── webhook/
│   │   └── line.php
│   ├── api/
│   │   └── *.php
│   └── assets/
│       ├── css/app.css
│       └── js/dashboard.js
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
│   ├── cleanup.php
│   └── poll_read_state.php
├── database/
│   └── migrations/
│       └── 001_initial.sql
├── tests/                     # PHPUnit
│   ├── bootstrap.php
│   ├── Sla/
│   ├── Sentiment/
│   ├── Reports/
│   └── Ingest/
├── config/
│   └── config.php             # gitignored; .env loaded
└── logs/                      # gitignored
```

---

## 15. Phases / Milestones

| Phase | ขอบเขต | DoD |
|---|---|---|
| **P0 — Bootstrap** | repo skeleton + composer + DB migration + auth + 1 tenant + 1 OA seed | login ได้, schema apply, webhook URL พร้อม |
| **P1 — Webhook ingest** | LineWebhookController + signature verify + MessageStore + InboxSentinel port | inbound message สร้าง row ใน `messages_inbound` + `chat_users` + `sla_pending`; idempotent |
| **P2 — Dashboard read-only** | dashboard.php + watchlist + conversation viewer + KPI display (live calc, ไม่มี cron) | 4 reports หลักของโจทย์แสดงผลครบ |
| **P3 — KPI cron** | aggregate_kpi.php + kpi_daily backfill | 30-day backfill numbers tally manual SQL ±2% |
| **P4 — SLA + alerts (soft-launch)** | scan_overdue.php + NotificationRouter + alerts_sent dedup; **soft_launch=1** → log only | alert ปรากฏใน DB แต่ไม่ส่งจริง |
| **P5 — Settings UI + manual ack** | settings.php + ack button + tenant onboarding wizard | owner ตั้งค่าจาก UI ได้ครบ |
| **P6 — Go-live** | `system_enabled=1`, `soft_launch=0`, recipients ตั้งค่าจริง | 7-day soft-launch review pass |
| **P7 — Reports เสริม** | histogram + heatmap + repeat-inbound + leaderboard | §9.2 ครบ |
| **P8 — Multi-tenant onboarding self-serve** | สมัครเอง + ใส่ OA token เอง + verify อัตโนมัติ | 0 manual ops จาก team |

---

## 16. Acceptance Criteria

- [ ] Webhook signature verify ผ่าน 100% (replay test, tampered body test)
- [ ] Webhook ingest latency p95 < 200 ms (raw body → 200 OK)
- [ ] Idempotency: ส่ง event เดิม 5 ครั้ง → DB มี 1 row
- [ ] InboxSentinel ผลตรงกับต้นฉบับ บน fixture 23k message (ลอกจาก golden test ของ `cny.re-ya.com`)
- [ ] `respond_seconds_business` ตรงกับ manual calc บน 50 case
- [ ] KPI 30-day backfill match (±2%) กับ manual SQL count
- [ ] Slow alert ส่งภายใน ≤ 6 นาที หลัง threshold ตัด
- [ ] No duplicate alerts ใน cooldown window
- [ ] Dashboard load < 1 sec (pre-aggregated)
- [ ] Multi-tenant: ลูกค้า A ไม่เห็นข้อมูลลูกค้า B (penetration test)
- [ ] Tests: ≥ 80% coverage ของ `classes/Sla`, `classes/Sentiment`, `classes/Reports`

---

## 17. Tests (`tests/`, PHPUnit)

| File | ครอบคลุม |
|---|---|
| `Ingest/LineWebhookControllerTest.php` | signature verify, idempotency, multi-tenant scoping |
| `Ingest/MessageStoreTest.php` | upsert chat_users, sentinel call ordering |
| `Sla/PendingTrackerTest.php` | onInbound/onRead/onAck idempotent |
| `Sla/BusinessHoursCalculatorTest.php` | weekend, EOD spillover, holiday |
| `Sla/OverdueScannerTest.php` | threshold boundary, business-hour adjustment |
| `Sentiment/InboxSentinelTest.php` | golden file 23k message, false-positive guards |
| `Reports/KpiAggregatorTest.php` | numeric correctness on 100-msg fixture |
| `Notify/NotificationRouterTest.php` | cooldown, multi-channel, failure logging |
| `Core/AuthTest.php` | RBAC, session, CSRF |
| `Integration/EndToEndTest.php` | webhook → pending → overdue → alert (in-memory sqlite ไม่ได้ ใช้ MySQL test DB) |

---

## 18. Safety Guards (CRITICAL)

| Guard | Default | ผล |
|---|---|---|
| `monitor_settings.system_enabled` | **0** | scanner cron no-op |
| `monitor_settings.soft_launch` | **1** | log alert ใน DB แต่ไม่ส่งจริง |
| `monitor_settings.notification_recipients` | `[]` | ไม่มี recipient |
| Crontab | **0 lines** until P6 | ไม่ scheduled |
| OA secrets | encrypted at rest (AES-256-GCM, key ใน `.env`) | leak DB ≠ leak token |
| Webhook `webhook_secret_path` opaque random 40 char | rotate ได้ผ่าน UI | กัน enumeration |
| Webhook `try/catch` swallow + return 200 | LINE จะไม่ retry storm | resilient |

---

## 19. Open Questions (รอ stakeholder)

- [ ] Slow threshold default = 15m, critical = 60m — ใช่ไหม?
- [ ] Business hours default = 08:00–18:00, จันทร์–เสาร์ — ใช่ไหม?
- [ ] รวมวันหยุดนักขัตฤกษ์ไทย? ดึงจากไหน (manual list ใน settings, หรือ API)?
- [ ] Notification — Telegram bot ใช้บัญชีกลางของ chat-sla หรือลูกค้าตั้งเอง?
- [ ] Manual ack ให้ role ไหน? (default: admin + viewer NO; owner + admin YES)
- [ ] เก็บ `messages_inbound` กี่วัน? (default 90; หลังจากนั้น aggregate-only)
- [ ] OA limit ต่อ tenant? (default unlimited; pricing tier ตอน commercialize)
- [ ] แยก deploy หรือ multi-tenant ก้อนเดียว? (default — ก้อนเดียว, scale horizontal เมื่อโต)
- [ ] SLA goal — ระบบเองตอบ 99.9% uptime ใช่ไหม?
- [ ] LINE markAsRead — เรียกอัตโนมัติเมื่อ admin เปิด chat (auto) หรือต้องกดปุ่ม (manual)?
- [ ] Holiday list = ของไทยอย่างเดียว หรือแยกตาม tenant?

---

## 20. Conventions (ยืมจากทีม `cny.re-ya.com`)

- PHP 8.1+, `declare(strict_types=1)` ทุกไฟล์
- PSR-4 autoload (`App\` → `classes/`), PSR-12 code style (`composer lint`)
- PHPStan level 5+ (เข้มกว่า monolith เดิม level 0 — โปรเจคใหม่ ตั้ง bar สูง)
- Singleton DB, multi-tenant scoping ทุก query
- Charset `utf8mb4_unicode_ci`, timezone `Asia/Bangkok` / MySQL `+07:00`
- Conventional Commits — `feat(sla):`, `fix(webhook):`, `docs(readme):`
- ไม่ hardcode threshold — อ่านจาก `monitor_settings`
- Tests property-based + fixture, bootstrap `tests/bootstrap.php`
- Cache buster: `?v=<filemtime>`
- ไม่ commit secret — `.env` + `config/config.php` gitignored
- CI/CD: GitHub Actions (lint + test + phpstan + sql syntax check)

---

## 21. Cross-References

- **Pattern source:** [`cny.re-ya.com` repo](https://github.com/reyatelehealth2026-crypto/odoo) (private)
  - InboxSentinel logic: `classes/CRM/InboxSentinel.php` commit `2a5fdd4`
  - Soft-launch pattern: `classes/CRM/AutoActionService.php` + `customer-churn.php`
  - Light theme + tab nav: `customer-churn.php`
  - Business-hour calc: `cron/inbox-response-time-collector.php`
- **Spec ของ churn (template):** [`docs/plans/2026-04-27-customer-churn-tracker.md`](./2026-04-27-customer-churn-tracker.md) (gitignored, local-only)
- **LINE Messaging API doc:** https://developers.line.biz/en/reference/messaging-api/

---

## 22. Decision Log

| Date | Decision | Rationale |
|---|---|---|
| 2026-05-02 | สร้างเป็น standalone PHP project ไม่ใช่ feature ใน `cny.re-ya.com` | ลด blast radius ระบบเดิม + เปิดทางเป็น product แยก |
| 2026-05-02 | Stack = PHP 8.1 (ไม่ใช่ Node/TS) | ทีมคุ้น, ports knowledge มาตรง, ลด onboarding cost |
| 2026-05-02 | Multi-tenant ตั้งแต่แรก | ออกแบบครั้งเดียว ดีกว่า refactor ตอน scale |
| 2026-05-02 | LINE webhook = แยก channel ของลูกค้า ไม่ forward จาก `cny.re-ya.com` | ไม่มี coupling แม้แต่บรรทัดเดียว |
| 2026-05-02 | InboxSentinel — port code, ไม่ import package | ทดสอบบน corpus จริง 23k แล้ว มูลค่า > drift risk |

