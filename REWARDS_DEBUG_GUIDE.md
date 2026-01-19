# Rewards Debug System - Complete Guide

## 🎯 Overview

This guide helps you debug and troubleshoot the LIFF rewards redemption system when errors occur.

## 🔧 Tools Available

### 1. Debug Page
**URL:** `https://cny.re-ya.com/liff/debug-rewards.html`

**Features:**
- Real-time console logging
- System status display
- Test actions for rewards loading and redemption
- Detailed error messages with stack traces
- No LIFF login required (uses test user)

### 2. Debug API
**Endpoint:** `https://cny.re-ya.com/api/debug-rewards.php`

**Actions:**
- `get_config` - Get LIFF configuration
- `rewards` - Get all active rewards
- `test_user` - Get or create test user
- `redeem` - Test reward redemption
- `add_points` - Add test points to user

**Features:**
- Comprehensive error logging
- Detailed error responses with file/line/trace
- Auto-creates test users
- No authentication required

### 3. Sample Rewards Creator
**Script:** `php install/check_and_create_sample_rewards.php`

**What it does:**
- Checks if rewards table exists
- Lists existing rewards
- Creates 4 sample rewards if none exist
- Verifies test user setup

## 🚀 Quick Start

### Step 1: Check if rewards exist
```bash
cd /path/to/project
php install/check_and_create_sample_rewards.php
```

### Step 2: Open debug page
Navigate to: `https://cny.re-ya.com/liff/debug-rewards.html`

### Step 3: Check system status
The page will automatically:
- Load configuration
- Initialize LIFF (if available)
- Load test user
- Load rewards

### Step 4: Test redemption
Click "Test Redeem (First Reward)" to test the redemption flow.

## 🐛 Troubleshooting

### Issue: HTTP 500 Error

**Symptoms:**
- API returns status 500
- Empty response body
- No JSON returned

**Solution:**
1. Check PHP error logs: `tail -f /path/to/error.log`
2. Check debug error log: `cat debug_rewards_errors.log`
3. Look for:
   - Missing files (config.php, database.php, LoyaltyPoints.php)
   - Database connection errors
   - Missing database tables

**Common causes:**
- Config file not found
- Database credentials incorrect
- Rewards table doesn't exist
- LoyaltyPoints class file missing

### Issue: No rewards displayed

**Symptoms:**
- "No rewards available" message
- Empty rewards list

**Solution:**
1. Run: `php install/check_and_create_sample_rewards.php`
2. Check if rewards table exists
3. Verify line_account_id matches (default: 3)

### Issue: "Not enough points" error

**Symptoms:**
- Redemption fails with points error
- User has 0 points

**Solution:**
1. Click "Add 1000 Test Points" button
2. Or run SQL:
```sql
UPDATE users 
SET total_points = 1000, available_points = 1000 
WHERE line_user_id = 'U00000000000000000000000000000003';
```

### Issue: LIFF initialization fails

**Symptoms:**
- "LIFF app not found" error
- LIFF ID is null

**Solution:**
1. Check line_accounts table has LIFF ID for account 3
2. Update LIFF ID in database:
```sql
UPDATE line_accounts 
SET liff_id = 'YOUR_LIFF_ID' 
WHERE id = 3;
```
3. Debug page will work without LIFF (uses test mode)

## 📊 Understanding Error Messages

### API Error Response Format
```json
{
  "success": false,
  "error": "Error message",
  "file": "/path/to/file.php",
  "line": 123,
  "trace": [
    "Stack trace line 1",
    "Stack trace line 2"
  ]
}
```

### Console Log Types
- **Info (Blue):** Normal operations
- **Success (Green):** Successful operations
- **Warning (Yellow):** Non-critical issues
- **Error (Red):** Critical errors

## 🔍 Debug Checklist

When rewards redemption fails, check:

- [ ] Rewards table exists and has data
- [ ] User has enough points
- [ ] Reward is active (`is_active = 1`)
- [ ] Reward has stock available (or stock = -1 for unlimited)
- [ ] Database connection is working
- [ ] LoyaltyPoints class is loaded correctly
- [ ] API returns valid JSON
- [ ] No PHP fatal errors in logs

## 📝 Log Files

### PHP Error Log
Location: Server-specific (check php.ini)
```bash
tail -f /var/log/php_errors.log
```

### Debug Rewards Error Log
Location: `debug_rewards_errors.log` (project root)
```bash
tail -f debug_rewards_errors.log
```

### Browser Console
Open browser DevTools (F12) → Console tab

## 🎓 Testing Workflow

1. **Initial Setup**
   ```bash
   php install/check_and_create_sample_rewards.php
   ```

2. **Open Debug Page**
   - Navigate to debug page
   - Check system status
   - Verify rewards loaded

3. **Add Test Points**
   - Click "Add 1000 Test Points"
   - Verify points added successfully

4. **Test Redemption**
   - Click "Test Redeem (First Reward)"
   - Check console logs for errors
   - Verify redemption code generated

5. **Test in LIFF App**
   - Open actual LIFF app
   - Navigate to rewards page
   - Try redeeming with real user

## 🔗 Related Files

- `api/debug-rewards.php` - Debug API endpoint
- `liff/debug-rewards.html` - Debug testing page
- `classes/LoyaltyPoints.php` - Rewards business logic
- `liff/assets/js/liff-app.js` - LIFF app rewards code
- `install/check_and_create_sample_rewards.php` - Setup script

## 💡 Tips

1. **Always check console logs first** - Most errors are visible in browser console
2. **Use debug page for testing** - Faster than testing in LIFF app
3. **Check database directly** - Verify data exists in tables
4. **Test with sample rewards** - Don't test with production rewards
5. **Clear browser cache** - If seeing old data

## 🆘 Still Having Issues?

If you're still experiencing problems:

1. Check all log files for errors
2. Verify database schema is up to date
3. Run loyalty points migration: `php install/run_loyalty_points_migration.php`
4. Check PHP version (requires 7.4+)
5. Verify all required extensions are installed (PDO, JSON)

## 📞 Support

For additional help:
- Check `REWARDS_COMPLETE_FIX.md` for implementation details
- Check `REWARDS_REDEMPTION_FIX.md` for redemption flow
- Review `install/REWARDS_TROUBLESHOOTING.md` for common issues
