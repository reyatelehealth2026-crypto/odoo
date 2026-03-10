# Troubleshooting Odoo Sync Issues

## Problem: High Error Rate in Backfill

จากการรัน backfill ครั้งแรก พบปัญหา:
- **Errors: 3,241 / 3,512 (92% error rate)**
- Orders synced: 0
- Invoices synced: 2,033 (แต่มาจากที่มีอยู่แล้ว ไม่ใช่จาก backfill)
- BDOs synced: 271

## Root Causes

### 1. Missing ID Fields
Webhook payloads มี structure ที่หลากหลาย:
- บาง webhooks ใช้ `order_id`, บางใช้ `id`
- บาง webhooks ซ้อน ID ใน `order.id` หรือ `invoice.id`
- ถ้าไม่เจอ ID → sync ล้มเหลวทันที

### 2. Event Type Filtering
Backfill query กรองเฉพาะ event types บางตัว แต่:
- `delivery.*` และ `payment.*` events ก็มี order data
- ควรรวม events เหล่านี้ด้วย

### 3. NULL Value Handling
SQL INSERT/UPDATE ล้มเหลวถ้ามี NULL values ใน fields บางตัว

## Solutions Applied

### Fix 1: Enhanced ID Detection
```php
// Before
$orderId = (int) ($payload['order_id'] ?? 0);
if (!$orderId) return false;

// After
$orderId = (int) ($payload['order_id'] ?? $payload['id'] ?? 0);
if (!$orderId && isset($payload['order']['id'])) {
    $orderId = (int) $payload['order']['id'];
}
```

### Fix 2: Expanded Event Type Coverage
```php
// Now includes delivery.* and payment.* events
if (str_starts_with($eventType, 'order.') || 
    str_starts_with($eventType, 'sale.') || 
    str_starts_with($eventType, 'delivery.') || 
    str_starts_with($eventType, 'payment.')) {
    return $this->syncOrder($payload, $eventType, $webhookId);
}
```

### Fix 3: NULL Filtering
```php
// Filter out NULL values before INSERT/UPDATE
$data = array_filter($data, function($value) {
    return $value !== null;
});
```

### Fix 4: Better Error Logging
```php
error_log("[OdooSyncService] syncOrder: No order_id found in payload for event {$eventType}");
error_log("[OdooSyncService] upsertOrder failed for order_id {$orderId}: " . $e->getMessage());
```

## How to Fix Your Data

### Step 1: Run Analysis Script
```bash
cd install
php analyze_sync_errors.php
```

This will show you:
- Which event types are failing
- Sample payloads for each type
- Specific error messages

### Step 2: Check Error Logs
```bash
# Linux/Mac
tail -f /var/log/php_errors.log

# Windows (check php.ini for error_log location)
Get-Content C:\xampp\php\logs\php_error_log.txt -Tail 50
```

Look for lines starting with `[OdooSyncService]`

### Step 3: Run Test Sync (Small Batch)
```bash
php reset_and_rerun_sync.php --limit=100
```

This will:
- Process only 100 webhooks
- Show detailed success/failure for each
- Update table counts

### Step 4: Full Re-sync (if test successful)
```bash
# Option A: Re-sync all (keeps existing synced records)
php backfill_odoo_sync_tables.php --batch=1000

# Option B: Reset and re-sync everything
php reset_and_rerun_sync.php --reset --limit=10000
```

## Debugging Tools

### 1. Debug Webhook Structure
```bash
php debug_webhook_structure.php
```
Shows:
- Event type distribution
- Sample payloads
- Available fields in each event type

### 2. Analyze Sync Errors
```bash
php analyze_sync_errors.php
```
Shows:
- Top failing event types
- Payload structure analysis
- Attempts to sync samples and reports results

### 3. Reset and Re-run
```bash
php reset_and_rerun_sync.php --limit=100
```
Options:
- `--reset` - Reset all synced_to_tables flags first
- `--limit=N` - Process only N records

## Expected Results After Fix

After applying fixes, you should see:
```
=== Backfill Complete ===
Total processed: 15,408
Orders synced: ~8,000-10,000
Invoices synced: ~3,000-4,000
BDOs synced: ~500-1,000
Errors: <500 (mostly invalid/incomplete webhooks)
```

## Common Issues

### Issue: "No order_id found"
**Cause**: Webhook payload doesn't have order_id field
**Solution**: Check if it's a valid order event or if ID is nested

### Issue: "Duplicate entry for key 'uk_order_id'"
**Cause**: Trying to insert order that already exists
**Solution**: This is normal - upsert logic will UPDATE instead

### Issue: SQL errors about NULL values
**Cause**: Required fields are NULL
**Solution**: Fixed by NULL filtering in upsert methods

### Issue: Invoices sync but orders don't
**Cause**: Invoice events have invoice_id, but order events might use different field names
**Solution**: Enhanced ID detection now handles multiple field names

## Verification Queries

After successful sync, run these to verify:

```sql
-- Check sync coverage
SELECT 
  COUNT(*) as total,
  SUM(synced_to_tables) as synced,
  SUM(NOT synced_to_tables) as unsynced,
  ROUND(SUM(synced_to_tables) / COUNT(*) * 100, 2) as sync_percentage
FROM odoo_webhooks_log;

-- Check table populations
SELECT 
  (SELECT COUNT(*) FROM odoo_orders) as orders,
  (SELECT COUNT(*) FROM odoo_invoices) as invoices,
  (SELECT COUNT(*) FROM odoo_bdos) as bdos;

-- Check recent syncs
SELECT event_type, COUNT(*) as count
FROM odoo_webhooks_log
WHERE synced_to_tables = TRUE
GROUP BY event_type
ORDER BY count DESC
LIMIT 20;

-- Sample synced orders
SELECT order_id, order_name, state, payment_status, amount_total, updated_at
FROM odoo_orders
ORDER BY updated_at DESC
LIMIT 10;
```

## Next Steps

1. ✅ Run `analyze_sync_errors.php` to understand current state
2. ✅ Run `reset_and_rerun_sync.php --limit=100` to test fixes
3. ✅ Check error logs for any remaining issues
4. ✅ Run full backfill: `backfill_odoo_sync_tables.php`
5. ✅ Verify data with SQL queries above
6. ✅ Test APIs to ensure they query from new tables correctly

## Contact

If issues persist after following this guide, check:
- PHP error logs for detailed stack traces
- MySQL slow query log
- Database table structures match migration SQL
