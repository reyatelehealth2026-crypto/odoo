# Inbox Flex Message Display Fix

## Problem
Inbox was displaying Flex Messages as raw JSON text instead of rendering them as nice previews.

## Root Cause
When auto-reply sent Flex Messages, they were being saved to the database with `message_type = 'text'` instead of `message_type = 'flex'`. The inbox checks this field to determine how to render messages.

## Solution Applied

### 1. Fixed Auto-Reply Quick Reply Validation (webhook.php)
**Lines 1690-1780**: Added validation for Quick Reply items
- Skip items without `label` field
- Skip URI buttons without `uri` field
- Prevents "Undefined array key 'label'" error

### 2. Message Type Detection Already Fixed (webhook.php)
**Line 1042**: Already has correct type detection
```php
$messageType = $autoReply['type'] ?? 'text';
saveOutgoingMessage($db, $user['id'], json_encode($autoReply), 'system', $messageType);
```

### 3. Fix Existing Messages
**New Scripts Created:**
- `install/check_message_types.php` - Web UI to view messages with wrong types
- `install/fix_message_types.php` - CLI script to fix existing messages

## How to Fix Existing Messages

### Option 1: Web Interface (Recommended)
1. Open browser: `http://your-domain.com/install/check_message_types.php`
2. Review messages with wrong types
3. Click "Fix All Message Types" button

### Option 2: Command Line
```bash
php install/fix_message_types.php
```

## What Gets Fixed
The script will:
1. Find all messages with `message_type = 'text'` that contain Flex JSON
2. Detect the actual message type from the JSON content
3. Update the `message_type` field to the correct value ('flex', 'image', etc.)

## Verification
After running the fix:
1. Refresh your inbox page
2. Flex Messages should now show as nice previews instead of JSON
3. Text messages remain as text
4. Images show as images

## Prevention
New messages sent after this fix will automatically be saved with the correct type. No further action needed.

## Technical Details

### Message Type Detection Logic
```php
if (isset($decoded['type'])) {
    $actualType = $decoded['type'];
    
    // Normalize type names
    if ($actualType === 'bubble' || $actualType === 'carousel') {
        $actualType = 'flex';
    }
}
```

### Inbox Rendering Logic (inbox.php)
- Line 1478: Gets `$type = $msg['message_type']`
- Line 1489: Checks `if ($type === 'text')` - shows plain text
- Line 1527: Checks `if ($type === 'flex')` - shows flex preview
- Line 1565-1590: Renders flex preview with header, body, items, footer

## Files Modified
1. `webhook.php` - Quick Reply validation (lines 1690-1780)
2. `install/check_message_types.php` - NEW: Web UI to check messages
3. `install/fix_message_types.php` - NEW: CLI script to fix messages
4. `install/INBOX_FLEX_MESSAGE_FIX.md` - NEW: This documentation

## Status
✅ Quick Reply validation fixed
✅ Message type detection working correctly for new messages
⚠️ Existing messages need to be fixed using the provided scripts
