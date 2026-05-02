# Chat Monitoring System — Spec

**เริ่มงาน:** 2026-05-02
**Branch:** `claude/chat-monitoring-system-cRHsb`
**Author:** chat-ops / inbox quality
**Goal:** ตรวจ + แจ้งเตือน chat LINE ที่ยังไม่ได้รับการตอบ และ chat ที่มีแนวโน้มเป็นปัญหา พร้อม report SLA / complaint
**Constraint (สำคัญที่สุด):** ระบบเก็บได้เฉพาะ **ฝั่งขาเข้า (incoming)** จากลูกค้า — ไม่สามารถยึดเวลา outgoing เป็น ground truth ของการตอบกลับได้

---

## 1. โจทย์ (Requirements)

| # | Requirement | Source signal |
|---|---|---|
| R1 | ตรวจและแจ้งว่า "LINE ไหนยังไม่ได้รับการ respond" | inbound timestamp + read-receipt absent > threshold |
| R2 | ตรวจและแจ้ง "chat ทำท่าจะมีปัญหา ต้องเฝ้าระวัง" | InboxSentinel sentiment (red / orange / yellow_urgent) บน inbound |
| R3 | Report — `respond_time` (median / p95 / avg) | `messages.is_read_on_line` flip time − inbound `created_at` |
| R4 | Report — สรุปจำนวน chat ทั้งหมด (ราย OA / ราย day / ราย agent) | `messages` aggregate |
| R5 | Report — `% chat ตอบช้าเกิน N นาที` | configurable `chat_monitor_settings.slow_threshold_minutes` |
| R6 | Report — จำนวน complaint | InboxSentinel red count |
| R7 | Report อื่นๆ ที่แนะนำ | ดูข้อ §7 |

---

## 2. ข้อจำกัด — "Inbound-only" และวิธีอนุมาน "ตอบแล้ว"

โจทย์บอกว่า **เก็บได้แค่ขาเข้า** เพราะ staff หลายคนตอบผ่าน **LINE Official Account Manager** หรือ **LINE Chat app** โดยตรง — ข้อความขาออกอาจไม่ผ่าน webhook ของเรา ดังนั้น `messages.direction='outgoing'` row อาจไม่มาเลย

### Signal ที่เชื่อถือได้ (มีอยู่แล้วในระบบ)

| Signal | Source | ตีความ |
|---|---|---|
| `messages.is_read_on_line = 1` | ตั้งโดย [`api/inbox-v2.php`](../../api/inbox-v2.php) เมื่อ admin เปิดอ่านใน inbox-v2 หรือเรียก LINE Mark-as-Read API ([`LineAPI::markAsRead`](../../classes/LineAPI.php)) | "staff เห็นข้อความแล้ว" — ใช้เป็น **proxy ของ first response** |
| `messages.mark_as_read_token` | webhook `event.message.markAsReadToken` ([`webhook.php:793`](../../webhook.php)) | token ที่ใช้เรียก LINE API เพื่อ mark read |
| ข้อความ inbound ถัดไปจาก user เดียวกัน | `messages` direction='incoming' | conversation ดำเนินต่อ → เคสปิดโดยพฤตินัย (fallback) |
| Admin ack ปุ่ม "ทำเครื่องหมายว่าตอบแล้ว" (ใหม่) | UI action → `chat_monitor_acks` (ตารางใหม่) | manual override โดย staff |

### Definition of "responded"

```
respond_at = COALESCE(
    เวลาที่ flip is_read_on_line=1 ครั้งแรกหลัง inbound,
    เวลาที่ admin กด ack manual,
    เวลาที่ outgoing message ถูกบันทึก (ถ้ามี),
    เวลาที่ inbound ใหม่ของ user เดียวกันมาถึง  -- soft fallback, มี flag
)
```

ใช้ลำดับนี้เป็น strict precedence; ค่า `respond_source` ('read_receipt' / 'manual_ack' / 'outgoing' / 'next_inbound') จะถูกบันทึกเพื่อ audit ความแม่นยำของ KPI

> **ข้อควรระวัง:** `is_read_on_line` เปลี่ยนเป็น 1 เฉพาะเมื่อมีคน **เปิดอ่านในระบบเรา** หรือเรียก LINE API ตามนั้น ถ้า staff ตอบผ่าน LINE Chat ตรงๆ โดยไม่เปิด inbox-v2 → flag ไม่เปลี่ยน → จะถูก count เป็น "ยังไม่ตอบ" จริงๆ ทั้งที่อาจตอบไปแล้ว
>
> **Mitigation:** ดู §11 — ใช้ LINE Insight API หรือ "next-inbound after long gap" เป็น sanity check เสริม

---

## 3. Architecture (re-use existing layers)

```
LINE Webhook  ────────►  webhook.php (existing)
                            │
                            ├─► messages (existing) — incoming + markAsReadToken
                            ├─► InboxSentinel::classify (existing) — bulk-classify on insert
                            └─► chat_monitor_pending (NEW) — open-conversation tracker

CRON (every 5 min) ─────►  cron/chat_monitor_scan.php (NEW)
                            ├─► reads messages + chat_monitor_pending
                            ├─► flags overdue: now - inbound_at > slow_threshold AND is_read_on_line=0
                            └─► dispatches via NotificationRouter (Telegram primary, LINE secondary)

CRON (every 15 min existing) ─► cron/inbox-response-time-collector.php (extend)
                            └─► writes message_analytics.response_time_seconds (existing, business-hours adjusted)

CRON (nightly 00:30) ───►  cron/chat_monitor_aggregate.php (NEW)
                            └─► writes chat_monitor_daily_kpi (NEW) — pre-aggregated for dashboard

Dashboard ─────────────►  chat-monitor.php (NEW) — admin page
                            ├─► KPI strip + slow-response watchlist
                            ├─► sentiment watchlist (P0/P1/P2)
                            ├─► drill-down → existing inbox-v2.php?user_id=X
                            └─► reads via api/chat-monitor-data.php (NEW)

Settings ──────────────►  chat-monitor-settings.php (NEW, super_admin only)
                            └─► thresholds + recipients (writes chat_monitor_settings)
```

### Layer mapping (reuse table)

| Concern | Reuse | New |
|---|---|---|
| Storage of inbound messages | `messages` ✅ | — |
| Sentiment classification | `Classes\CRM\InboxSentinel` ✅ | — |
| Response time calc (business hours) | `cron/inbox-response-time-collector.php` ✅ extend | — |
| Multi-account scoping | `line_accounts.id` everywhere ✅ | — |
| Singleton DB | `Database::getInstance()` ✅ | — |
| Notifications | `Classes\NotificationRouter` ✅ (Telegram + LINE) | — |
| Open-chat tracker | — | `chat_monitor_pending` |
| Daily KPI rollup | — | `chat_monitor_daily_kpi` |
| Settings (thresholds, recipients) | — | `chat_monitor_settings` |
| Manual ack audit | — | `chat_monitor_acks` |
| Alert dedup | — | `chat_monitor_alerts_sent` |

---

## 4. Database Schema (migration `database/migration_chat_monitor.sql`)

```sql
-- 4.1 Settings (single-row config, ตามแบบ churn_settings)
CREATE TABLE IF NOT EXISTS `chat_monitor_settings` (
  `id` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `system_enabled` TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'master switch',
  `soft_launch` TINYINT(1) NOT NULL DEFAULT 1     COMMENT 'shadow mode — log แต่ไม่แจ้งเตือน',
  `slow_threshold_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `critical_threshold_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `business_hours_start` TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `business_hours_end` TINYINT UNSIGNED NOT NULL DEFAULT 18,
  `business_days` VARCHAR(20) NOT NULL DEFAULT 'mon,tue,wed,thu,fri,sat',
  `notification_recipients` JSON NOT NULL COMMENT '[{channel:telegram|line, target_id, role}]',
  `alert_cooldown_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `sentiment_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `slow_alert_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `chk_singleton_row` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `chat_monitor_settings` (id, notification_recipients)
VALUES (1, JSON_ARRAY());

-- 4.2 Open-conversation tracker (one row per "user has unread inbound")
CREATE TABLE IF NOT EXISTS `chat_monitor_pending` (
  `user_id` INT NOT NULL,
  `line_account_id` INT NOT NULL,
  `first_inbound_message_id` INT NOT NULL,
  `first_inbound_at` DATETIME NOT NULL,
  `latest_inbound_at` DATETIME NOT NULL,
  `inbound_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `worst_sentiment` ENUM('red','orange','yellow_urgent','yellow','green') DEFAULT NULL,
  `is_overdue_slow` TINYINT(1) NOT NULL DEFAULT 0,
  `is_overdue_critical` TINYINT(1) NOT NULL DEFAULT 0,
  `last_alert_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `closed_via` ENUM('read_receipt','manual_ack','outgoing','next_inbound_stale','expired') DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `line_account_id`),
  KEY `idx_open_overdue` (`closed_at`, `is_overdue_slow`, `first_inbound_at`),
  KEY `idx_account_open` (`line_account_id`, `closed_at`, `first_inbound_at`),
  KEY `idx_sentiment` (`worst_sentiment`, `closed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.3 Manual ack audit
CREATE TABLE IF NOT EXISTS `chat_monitor_acks` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `line_account_id` INT NOT NULL,
  `acked_by` INT NOT NULL COMMENT 'admin user id',
  `acked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `inbound_message_id` INT DEFAULT NULL,
  `note` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `acked_at`),
  KEY `idx_admin_time` (`acked_by`, `acked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.4 Alert dedup
CREATE TABLE IF NOT EXISTS `chat_monitor_alerts_sent` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `line_account_id` INT NOT NULL,
  `alert_type` ENUM('slow','critical','sentiment_red','sentiment_orange','sentiment_urgent') NOT NULL,
  `channel` ENUM('telegram','line','email') NOT NULL,
  `recipient_target` VARCHAR(255) NOT NULL,
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `success` TINYINT(1) NOT NULL DEFAULT 1,
  `error_message` VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dedup` (`user_id`, `alert_type`, `sent_at`),
  KEY `idx_account_time` (`line_account_id`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.5 Daily KPI rollup
CREATE TABLE IF NOT EXISTS `chat_monitor_daily_kpi` (
  `kpi_date` DATE NOT NULL,
  `line_account_id` INT NOT NULL,
  `total_chats` INT UNSIGNED NOT NULL DEFAULT 0       COMMENT 'distinct user_id with ≥1 inbound',
  `total_inbound_messages` INT UNSIGNED NOT NULL DEFAULT 0,
  `responded_count` INT UNSIGNED NOT NULL DEFAULT 0   COMMENT 'chats with respond_at within day',
  `slow_count` INT UNSIGNED NOT NULL DEFAULT 0        COMMENT 'response > slow_threshold',
  `critical_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `unanswered_eod_count` INT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'still open at end of day',
  `complaint_count` INT UNSIGNED NOT NULL DEFAULT 0   COMMENT 'sentiment=red',
  `dissatisfied_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sentiment=orange',
  `urgent_followup_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sentiment=yellow_urgent',
  `respond_time_p50_seconds` INT UNSIGNED DEFAULT NULL,
  `respond_time_p95_seconds` INT UNSIGNED DEFAULT NULL,
  `respond_time_avg_seconds` INT UNSIGNED DEFAULT NULL,
  `slow_pct` DECIMAL(5,2) DEFAULT NULL                COMMENT '% slow / responded',
  `complaint_pct` DECIMAL(5,2) DEFAULT NULL           COMMENT '% red / total_chats',
  `computed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`kpi_date`, `line_account_id`),
  KEY `idx_date` (`kpi_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> **เหตุผลที่ไม่แตะ `messages` schema:** ตารางนี้ใหญ่ (>6,900 rows live + heavy index) — ทุกอย่างที่ต้องการคำนวณได้แล้วจาก columns เดิม การเพิ่ม column ใหม่จะเสี่ยง lock ตอน migrate

---

## 5. Service Classes (`classes/ChatMonitor/`, PSR-4 namespace `Classes\ChatMonitor`)

| Class | Responsibility |
|---|---|
| `PendingTracker.php` | upsert `chat_monitor_pending` เมื่อมี inbound; close เมื่อ `is_read_on_line` flip |
| `OverdueScanner.php` | เลือก row ที่ `closed_at IS NULL` + `now − first_inbound_at > threshold` (business-hour adjusted) |
| `KpiAggregator.php` | สร้าง `chat_monitor_daily_kpi` จาก `messages` + `message_analytics` + `chat_monitor_pending` |
| `AlertDispatcher.php` | ใช้ `NotificationRouter` ส่ง Telegram + LINE; เช็ค `chat_monitor_alerts_sent` กัน duplicate ผ่าน cooldown |
| `BusinessHoursCalculator.php` | reuse logic จาก [`cron/inbox-response-time-collector.php`](../../cron/inbox-response-time-collector.php) (extract เป็น class) |
| `MonitorSettingsRepository.php` | read/write `chat_monitor_settings` |

> **กฎ:** ไม่ลอก code จาก `inbox-response-time-collector.php` — extract เป็น `BusinessHoursCalculator` แล้วให้ทั้ง cron เก่าและ class ใหม่เรียกใช้ (DRY); ถ้าทำไม่ได้ใน Phase 1 → ก๊อป + comment ชี้ว่าจะ unify ใน Phase 4

---

## 6. Webhook Hook (minimal — keep `webhook.php` lean)

ใน [`webhook.php`](../../webhook.php) message-event handler (≈ line 369–425):

```php
// AFTER existing INSERT INTO messages
if (file_exists(__DIR__ . '/classes/ChatMonitor/PendingTracker.php')) {
    require_once __DIR__ . '/classes/ChatMonitor/PendingTracker.php';
    try {
        \Classes\ChatMonitor\PendingTracker::onInbound(
            $db, $lineAccountId, $dbUserId, $messageId,
            $event['timestamp'] ?? null,
            $textContent
        );
    } catch (\Throwable $e) {
        logWebhookException($db, 'chat_monitor', $e);  // ห้ามทำให้ webhook fail
    }
}
```

ใน [`api/inbox-v2.php`](../../api/inbox-v2.php) (จุดที่ flip `is_read_on_line=1`) ให้เรียก `PendingTracker::onRead($userId, $lineAccountId, 'read_receipt')` หลัง UPDATE สำเร็จ

> ทุก hook ต้อง **try/catch** และ swallow error — ห้ามทำให้ flow webhook/inbox เดิม fail

---

## 7. Reports (R3–R7)

### 7.1 Report ที่โจทย์ขอตรง

| Report | สูตร | Source |
|---|---|---|
| ระยะเวลา respond time | `p50 / p95 / avg` ของ `message_analytics.response_time_seconds` แยก OA + day | existing table |
| สรุปจำนวน chat ทั้งหมด | `COUNT(DISTINCT user_id)` จาก `messages` direction=incoming ต่อวัน/OA | `chat_monitor_daily_kpi.total_chats` |
| % chat ตอบช้าเกิน N นาที | `slow_count / responded_count * 100`; N = `slow_threshold_minutes` | `chat_monitor_daily_kpi.slow_pct` |
| จำนวน complaint | `COUNT(DISTINCT user_id)` ที่ InboxSentinel = `red` ต่อวัน | `chat_monitor_daily_kpi.complaint_count` |

### 7.2 Report เสริมที่แนะนำ

| Report | เหตุผล |
|---|---|
| **Unanswered at end-of-day** (รายวัน) | เห็น backlog สะสมที่ขาดหาย ไม่ใช่แค่ slow |
| **First-response time distribution histogram** (รายสัปดาห์) | จับ outlier — chat ที่ตอบเร็วมาก vs ช้ามาก |
| **Sentiment trend** (รายสัปดาห์) | ratio red/orange/yellow_urgent ต่อ total — แนวโน้ม service quality |
| **Peak-hour heatmap** (วัน × ชั่วโมง) | บอกว่า staffing พอไหม ช่วงไหนต้องเพิ่มคน |
| **Top-N customers by complaint count** (รายเดือน) | ลูกค้าที่บ่นบ่อย → ต่อ churn dashboard ผ่าน `odoo_partner_id` (existing bridge) |
| **Repeat-inbound before response** (count ของ inbound ที่ลูกค้าทักซ้ำก่อนได้คำตอบ) | proxy ของความหงุดหงิดและ effort ของลูกค้า |
| **Per-account leaderboard** | ถ้า super_admin ดูหลาย OA — จัดอันดับ SLA |

> **ไม่แนะนำ** report ที่ต้อง split per agent — เพราะเรา **ไม่มีข้อมูล outgoing reliable** (โจทย์ข้อจำกัด) → ระบุ agent ตอบไม่ได้

---

## 8. API Endpoints (`api/chat-monitor-*.php`)

| Endpoint | Method | Output |
|---|---|---|
| `api/chat-monitor-data.php?action=kpi&date_from=&date_to=&line_account_id=` | GET | KPI strip + chart data |
| `api/chat-monitor-data.php?action=watchlist_overdue&line_account_id=` | GET | open chats currently slow/critical |
| `api/chat-monitor-data.php?action=watchlist_sentiment&days=7` | GET | red/orange ใน N วันล่าสุด |
| `api/chat-monitor-data.php?action=histogram&date=` | GET | response-time histogram (10 buckets) |
| `api/chat-monitor-data.php?action=heatmap&week_start=` | GET | day×hour matrix |
| `api/chat-monitor-ack.php` | POST | `{user_id, line_account_id, note?}` → `chat_monitor_acks` + close pending |
| `api/chat-monitor-settings-update.php` | POST | super_admin only — update thresholds |

ทั้งหมด: auth ผ่าน `auth_check.php` (existing), JSON output, `Cache-Control: private, max-age=30`

---

## 9. Cron Jobs

| File | Schedule | งาน |
|---|---|---|
| `cron/chat_monitor_scan.php` | every 5 min | scan `chat_monitor_pending` หา overdue + dispatch alert |
| `cron/chat_monitor_aggregate.php` | nightly 00:30 | rebuild `chat_monitor_daily_kpi` ของ "เมื่อวาน" + recompute 7 วันล่าสุด (idempotent) |
| `cron/chat_monitor_cleanup.php` | weekly Sun 03:00 | ปิด `pending` ที่ค้างเกิน 7 วัน → `closed_via='expired'` |
| `cron/inbox-response-time-collector.php` (existing) | every 15 min | **คงเดิม** — ใช้ผลของมันใน aggregator |

ทุก cron: `set_time_limit(120)`, log ที่ `logs/chat_monitor_*.log`, fail-safe (no exception leaks)

---

## 10. Notifications (alert payload format)

ใช้ `Classes\NotificationRouter` (existing). Telegram = primary (ทีมใช้ 24/7), LINE OA push = secondary

```
🚨 Chat Overdue (CRITICAL)
OA: CNY Wholesale (#3)
ลูกค้า: คุณ X (LINE: U1234…)
รอตอบ: 67 นาที (เกิน critical 60 นาที)
ข้อความล่าสุด: "ของยังไม่ได้รับเลยค่ะ"
Sentiment: 🔴 ร้องเรียน
👉 https://cny.re-ya.com/inbox-v2.php?user_id=42&line_account_id=3
```

**Cooldown:** ลูกค้าเดียวกัน + alert_type เดียวกัน → ไม่ส่งซ้ำใน `alert_cooldown_minutes` (default 30) — เก็บใน `chat_monitor_alerts_sent`

**Escalation ladder:**
1. `slow_threshold` (15m) → Telegram channel "ops"
2. `critical_threshold` (60m) → Telegram channel "ops" + manager DM
3. `sentiment=red` → Telegram channel "ops" ทันที (ไม่รอ overdue)

---

## 11. Edge Cases / Limitations (ต้องเขียนกำกับ UI)

| เคส | ผลกระทบ | Mitigation |
|---|---|---|
| Staff ตอบใน LINE Chat ตรงๆ ไม่เคยเปิด inbox-v2 | ระบบนับว่ายังไม่ตอบ | ปุ่ม "manual ack" + เตือนทีมว่าให้เปิด inbox อย่างน้อย 1 ครั้ง |
| User ทักนอกเวลา business hours | ไม่ควรนับเป็น slow | `BusinessHoursCalculator` skip — เริ่มนับเมื่อเปิดเวลาทำการ |
| User unsend ข้อความ | inbound ยังอยู่ใน DB | เพิ่ม listener event `unsend` → mark `closed_via='unsend'` |
| User ทักหลายข้อความติดกัน | รอบเดียว ไม่ใช่ N | `chat_monitor_pending` เป็น 1 row / user; `inbound_count` += 1 |
| Multi-account: user เดียว แต่หลาย OA | row แยกต่อ `(user_id, line_account_id)` | unique key composite — schema ใน §4.2 ถูกต้องแล้ว |
| InboxSentinel false-positive (เช่น "หมดอายุเมื่อไรคะ") | alert ผิด | ใช้ class existing — เคยทดสอบบน 23k messages แล้ว |

---

## 12. UI Pages

### 12.1 `chat-monitor.php` (admin dashboard, role: super_admin/admin/staff)

Layout เลียนแบบ [`customer-churn.php`](../../customer-churn.php) (light theme + tab nav):

```
[ Tab: 📨 Inbox SLA ] [ 📊 Reports ] [ ⚠️ Watchlist ] [ ⚙️ Settings (admin only) ]

🚨 Critical Overdue (top card) — N ราย รอตอบเกิน 60 นาที + ปุ่ม "เปิด inbox"
📊 KPI Strip — Total chats / Responded / Slow% / Complaint / Avg respond
📈 Response-time chart (last 14 days) — p50 / p95 line
📋 Watchlist table — sortable, ทั้ง overdue + sentiment red/orange
   columns: user, OA, รอ (mm:ss), sentiment pill, last message preview, [📬 Inbox] [✓ Ack]
🌡️ Heatmap (day × hour) — last 7 days
📅 Daily KPI table — last 30 days
```

**Cache busting:** `<script src="assets/js/chat-monitor.js?v=<?= filemtime(...) ?>">`
**Deep link out:** ไปที่ `inbox-v2.php?user_id=X&line_account_id=Y` (existing)

### 12.2 `chat-monitor-settings.php` (super_admin only)

| Setting | Field |
|---|---|
| System enabled | toggle |
| Soft launch (shadow mode) | toggle |
| Slow threshold | minutes input |
| Critical threshold | minutes input |
| Business hours | start/end + days checkbox |
| Notification recipients | repeater {channel, target_id, role} |
| Alert cooldown | minutes |

---

## 13. Phases / Milestones

| Phase | ขอบเขต | DoD |
|---|---|---|
| **P0 — DB + tracker** | migration, `PendingTracker`, webhook hook | inbound → row appears; read → row closes; tests pass |
| **P1 — Aggregator + KPI** | `KpiAggregator`, nightly cron, `chat_monitor_daily_kpi` populated | 14-day backfill; numbers tally manually with `messages` |
| **P2 — Dashboard read-only** | `chat-monitor.php` + `api/chat-monitor-data.php` (KPI + watchlist + chart) | 4 reports จากโจทย์ทำงานครบ |
| **P3 — Alerts (soft-launch)** | scanner cron + `AlertDispatcher`, **soft_launch=1** → log only | alert ปรากฏใน `chat_monitor_alerts_sent` แต่ไม่มี outbound |
| **P4 — Settings UI + manual ack** | `chat-monitor-settings.php` + ack button | super_admin แก้ค่าได้, ack ทำงานครบ |
| **P5 — Go-live** | `system_enabled=1`, `soft_launch=0`, recipients ตั้งค่าจริง | 7-day soft-launch review pass |
| **P6 — Reports เสริม** | histogram + heatmap + repeat-inbound + leaderboard | §7.2 ครบทุกข้อ |

---

## 14. Acceptance Criteria

- [ ] Webhook hook ไม่เพิ่ม latency เกิน 50 ms (measure ก่อน/หลัง)
- [ ] `chat_monitor_pending` มี row สำหรับ inbound ทุกข้อความใน sample 100 รายการ
- [ ] `respond_at` ถูกต้องเทียบกับ `is_read_on_line` flip time ในการทดสอบ 50 case
- [ ] KPI 30-day backfill match (±2%) กับ manual SQL count บน `messages`
- [ ] Slow alert ส่งใน ≤ 6 นาที หลัง `slow_threshold` ตัด
- [ ] No duplicate alerts ใน 30 นาที สำหรับ user เดียวกัน + alert_type เดียวกัน
- [ ] Dashboard load < 1s (ใช้ pre-aggregated table)
- [ ] Tests: ≥ 80% coverage ของ `Classes\ChatMonitor\*` (PHPUnit, property-based ตามแบบ `tests/CRM/`)

---

## 15. Tests (`tests/ChatMonitor/`)

ตามแบบ `tests/CRM/` — property-based + fixtures sqlite

| File | ครอบคลุม |
|---|---|
| `PendingTrackerTest.php` | onInbound idempotent / onRead closes / multi-account scoping |
| `BusinessHoursCalculatorTest.php` | edge: ข้าม weekend, ข้าม EOD, holiday list |
| `OverdueScannerTest.php` | threshold boundary (slow vs critical), business-hour adjustment |
| `KpiAggregatorTest.php` | numeric correctness บน fixture 100 messages |
| `AlertDispatcherTest.php` | cooldown ทำงาน, route ตามค่า settings |
| `ChatMonitorIntegrationTest.php` | end-to-end: webhook event → pending → overdue → alert log |

---

## 16. Safety Guards (CRITICAL — เลียน churn pattern)

| Guard | Default | ผล |
|---|---|---|
| `chat_monitor_settings.system_enabled` | **0** | scanner cron no-op |
| `chat_monitor_settings.soft_launch` | **1** | log alert ใน DB แต่ไม่ส่งจริง |
| `chat_monitor_settings.notification_recipients` | `[]` | ไม่มีคนรับ |
| Crontab | **0 lines** until P5 | ไม่ scheduled |
| Webhook hook | swallow error always | ไม่กระทบ flow LINE หลัก |

**ห้ามเปิด `system_enabled=1` จนกว่า:** stakeholder ยืนยัน recipients + ผ่าน 7-day soft-launch review

---

## 17. Open Questions (รอ stakeholder)

- [ ] Slow threshold เริ่มที่กี่นาที? (default ในสเปค = 15m, critical = 60m)
- [ ] Business hours ของ CNY คือ 08:00–18:00 จันทร์–เสาร์ ใช่ไหม? (default)
- [ ] ใครรับ Telegram alert? (ops channel + manager DM?)
- [ ] รวมวันหยุดนักขัตฤกษ์ไทยใน `BusinessHoursCalculator` หรือไม่?
- [ ] Alert cooldown ที่ 30 นาที พอไหม หรือควรสั้น/ยาวกว่า?
- [ ] Manual ack ให้ role ไหนบ้าง? (default: admin + staff)
- [ ] เก็บ history `chat_monitor_pending` (closed) นานแค่ไหนก่อน archive?

---

## 18. Cross-References (existing assets)

| Asset | Path | ใช้อย่างไร |
|---|---|---|
| Messages table | `database/install_complete_latest.sql` | source of truth ของ inbound |
| Webhook | [`webhook.php`](../../webhook.php) | hook point §6 |
| InboxSentinel | [`classes/CRM/InboxSentinel.php`](../../classes/CRM/InboxSentinel.php) | sentiment classification |
| Response-time cron | [`cron/inbox-response-time-collector.php`](../../cron/inbox-response-time-collector.php) | คงเดิม, เป็น input ของ aggregator |
| Inbox v2 | [`api/inbox-v2.php`](../../api/inbox-v2.php) + [`inbox-v2.php`](../../inbox-v2.php) | จุด flip is_read_on_line + deep-link target |
| LINE markAsRead | [`classes/LineAPI.php`](../../classes/LineAPI.php) line 1126 | trigger ของ read_receipt signal |
| NotificationRouter | [`classes/NotificationRouter.php`](../../classes/NotificationRouter.php) | dispatch alerts |
| Auth helpers | `includes/header.php` | role checks (`isSuperAdmin`, `isAdmin`, `isStaff`) |
| Churn pattern (สเปคใกล้เคียง) | [`docs/plans/2026-04-27-customer-churn-tracker.md`](./2026-04-27-customer-churn-tracker.md) | template ของ safety guards + soft-launch |

---

## 19. Server / Deploy

- **Production:** `root@47.82.233.152:/www/wwwroot/cny.re-ya.com`
- **Pull:** `cd /www/wwwroot/cny.re-ya.com && git pull origin main --ff-only`
- **Migration:** `mysql -u cny_re_ya_com -pcny_re_ya_com cny_re_ya_com < database/migration_chat_monitor.sql`
- **Cron entries (เพิ่มเมื่อถึง P5):**
  ```
  */5 * * * *  /www/server/php/83/bin/php cron/chat_monitor_scan.php >> logs/chat_monitor_scan.log 2>&1
  30 0 * * *   /www/server/php/83/bin/php cron/chat_monitor_aggregate.php >> logs/chat_monitor_aggregate.log 2>&1
  0 3 * * 0    /www/server/php/83/bin/php cron/chat_monitor_cleanup.php >> logs/chat_monitor_cleanup.log 2>&1
  ```

---

## 20. Conventions (จาก CLAUDE.md)

- Singleton DB: `Database::getInstance()->getConnection()`
- Multi-account: ทุก query ต้อง scope ด้วย `line_account_id`
- Charset `utf8mb4_unicode_ci`, timezone `Asia/Bangkok` / MySQL `+07:00`
- Commit: Conventional Commits — `feat(chat-monitor):`, `fix(chat-monitor):`, etc.
- Tests: property-based, bootstrap `tests/bootstrap.php`
- ไม่ hardcode threshold — อ่านจาก `chat_monitor_settings`
- Cache buster: `?v=filemtime` ใน admin page assets

