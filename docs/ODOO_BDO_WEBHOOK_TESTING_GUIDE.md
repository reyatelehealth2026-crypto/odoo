# Odoo BDO Webhook Testing Guide

Quick guide for testing the BDO webhook handler.

---

## Quick Start

### Run Complete Test Suite

```bash
cd re-ya
php test-bdo-webhook-complete.php
```

### View Results

Open in browser:
```
re-ya/test-bdo-webhook-preview.html
```

---

## Test Files

| File | Purpose |
|------|---------|
| `test-bdo-webhook-complete.php` | Complete test suite (Task 12.3) |
| `test-bdo-handler.php` | Handler unit test (Task 12.1) |
| `test-bdo-flex-complete.php` | Flex message test (Task 12.2) |
| `test-qr-generation.php` | QR code generation test (Task 11.2) |

---

## What Gets Tested

### 1. Mock BDO Event (12.3.1)

- ✅ Webhook payload structure
- ✅ Required fields validation
- ✅ Signature generation
- ✅ Timestamp validation
- ✅ Event routing

### 2. QR Code Display (12.3.2)

- ✅ QR code generation from EMVCo
- ✅ File creation and storage
- ✅ Image format validation
- ✅ QR code in Flex message
- ✅ Amount display
- ✅ Bank account info

### 3. Button Functionality (12.3.3)

- ✅ Invoice PDF button
- ✅ Slip upload button
- ✅ URI validation
- ✅ LIFF integration
- ✅ Button labels

---

## Expected Output

```
=== Complete BDO Webhook Test ===
Task 12.3: Test BDO webhook
================================

Test 12.3.1: Mock BDO Confirmed Event
--------------------------------------
✓ Mock webhook payload created
  Event: bdo.confirmed
  BDO Ref: BDO-2026-TEST-001
  Order Ref: SO-2026-TEST-001
  Amount: ฿15,750.50

✓ Webhook headers generated
✓ Signature verification PASSED

Test 12.3.2: Verify QR Code Display
------------------------------------
✓ QR Code generated successfully
  ✓ File exists: 2500 bytes
  ✓ Valid PNG image: 300x300px
  ✓ QR Code image found in body
  ✓ Amount displayed: ฿15,750.50
  ✓ Bank account info found

Test 12.3.3: Verify Button Functionality
-----------------------------------------
✓ Found 2 buttons

Button 1:
  Label: 📄 ดูใบแจ้งหนี้
  ✓ Invoice PDF button
  ✓ Links to invoice PDF

Button 2:
  Label: 📤 อัพโหลดสลิป
  ✓ Slip upload button
  ✓ Opens LIFF app

=== Test Summary ===
✓ Task 12.3.1: Mock BDO confirmed event - PASSED
✓ Task 12.3.2: QR Code display verification - PASSED
✓ Task 12.3.3: Button functionality verification - PASSED

All tests completed successfully! ✅
```

---

## Generated Files

After running tests, you'll find:

```
re-ya/
├── uploads/qr/
│   └── qr_BDO-2026-TEST-001_{timestamp}.png
├── test-bdo-webhook-flex.json
├── test-bdo-webhook-payload.json
└── test-bdo-webhook-preview.html
```

---

## Testing with LINE Simulator

### Step 1: Get Flex JSON

```bash
cat re-ya/test-bdo-webhook-flex.json
```

### Step 2: Open Simulator

Visit: https://developers.line.biz/flex-simulator/

### Step 3: Upload JSON

1. Click "Import"
2. Paste JSON from `test-bdo-webhook-flex.json`
3. Click "View"

### Step 4: Verify Display

- ✅ Header shows "💳 แจ้งชำระเงิน"
- ✅ QR code displays
- ✅ Amount shows ฿15,750.50
- ✅ Bank info visible
- ✅ Two buttons at bottom

---

## Testing QR Code Scanning

### Step 1: Open QR Image

```
re-ya/uploads/qr/qr_BDO-2026-TEST-001_{timestamp}.png
```

### Step 2: Scan with Banking App

Use any Thai mobile banking app:
- Bangkok Bank
- Kasikorn Bank (K PLUS)
- SCB Easy
- Krungthai NEXT

### Step 3: Verify Payment Details

- ✅ Amount: ฿15,750.50
- ✅ Merchant: บริษัท ซีเอ็นวาย จำกัด
- ✅ Reference: BDO-2026-TEST-001

---

## Integration Testing

### Prerequisites

1. Database tables created:
   ```sql
   odoo_line_users
   odoo_webhooks_log
   ```

2. Test user linked:
   ```sql
   INSERT INTO odoo_line_users 
   (line_user_id, odoo_partner_id, linked_via, line_notification_enabled)
   VALUES ('U1234567890abcdef', 100, 'phone', 1);
   ```

3. LINE channel configured:
   ```php
   define('LINE_CHANNEL_ACCESS_TOKEN', 'your_token');
   ```

### Send Test Webhook

```bash
curl -X POST https://cny.re-ya.com/api/webhook/odoo.php \
  -H "Content-Type: application/json" \
  -H "X-Odoo-Signature: sha256=..." \
  -H "X-Odoo-Timestamp: 1234567890" \
  -H "X-Odoo-Delivery-Id: test-123" \
  -H "X-Odoo-Event: bdo.confirmed" \
  -d @test-bdo-webhook-payload.json
```

### Verify Response

```json
{
  "success": true,
  "received_at": "2026-02-03T10:30:00+07:00",
  "delivery_id": "test-123",
  "event": "bdo.confirmed",
  "sent_to": ["customer"]
}
```

---

## Troubleshooting

### QR Code Not Generated

**Problem:** QR code file not created

**Solution:**
```bash
# Check directory permissions
chmod 755 re-ya/uploads/qr/

# Check GD library
php -m | grep gd
```

### Signature Verification Failed

**Problem:** Invalid webhook signature

**Solution:**
```php
// Check webhook secret
echo ODOO_WEBHOOK_SECRET;

// Verify signature generation
$data = $timestamp . '.' . $payload;
$signature = 'sha256=' . hash_hmac('sha256', $data, ODOO_WEBHOOK_SECRET);
```

### Flex Message Not Displaying

**Problem:** Flex message structure invalid

**Solution:**
```bash
# Validate JSON
cat test-bdo-webhook-flex.json | jq .

# Check in LINE Simulator
# https://developers.line.biz/flex-simulator/
```

### Buttons Not Working

**Problem:** Button URIs invalid

**Solution:**
```php
// Check LIFF ID
echo LIFF_ID;

// Verify invoice URL
echo $data['invoice']['pdf_url'];
```

---

## Performance Benchmarks

| Operation | Expected Time |
|-----------|---------------|
| QR generation | < 100ms |
| Flex creation | < 50ms |
| Signature verify | < 10ms |
| Total webhook | < 500ms |
| LINE API call | < 2000ms |

---

## Success Criteria

### All Tests Pass

- [x] Mock event created
- [x] Signature verified
- [x] QR code generated
- [x] QR code in Flex
- [x] Amount displayed
- [x] Bank info shown
- [x] Invoice button works
- [x] Upload button works

### Integration Works

- [ ] Webhook received from Odoo
- [ ] LINE message sent
- [ ] QR code scannable
- [ ] Buttons clickable
- [ ] Slip upload opens

---

## Next Steps

1. **Complete Task 12.3** ✅
   - All sub-tasks verified
   - Tests documented
   - Ready for integration

2. **Move to Task 13.1**
   - Implement slip upload API
   - Handle image download
   - Call Odoo API

3. **Integration Testing**
   - Test with Odoo staging
   - Verify end-to-end flow
   - Monitor webhook logs

---

## Related Tasks

- ✅ Task 11.1: QR library installation
- ✅ Task 11.2: QR generation implementation
- ✅ Task 11.3: QR generation testing
- ✅ Task 12.1: BDO handler implementation
- ✅ Task 12.2: BDO Flex template
- ✅ Task 12.3: BDO webhook testing
- ⏭️ Task 13.1: Slip upload API

