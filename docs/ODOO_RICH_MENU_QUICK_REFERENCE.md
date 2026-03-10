# Odoo Rich Menu - Quick Reference Card

**Last Updated:** 2026-02-03  
**Status:** Ready for Implementation

---

## 🚀 Quick Start (5 Minutes)

### Step 1: Get LIFF ID
1. Go to: https://developers.line.biz/console/
2. Select your Channel → LIFF tab
3. Copy LIFF ID (format: `1234567890-abcdefgh`)

### Step 2: Create Rich Menu
1. Go to: `https://cny.re-ya.com/rich-menu.php`
2. Click "สร้าง Rich Menu"
3. Fill in:
   - Name: "Odoo Menu"
   - Chat Bar Text: "เมนู"
   - Size: 1686px (recommended)

### Step 3: Add Buttons
Add these two buttons:

**Button 1: Odoo Orders**
- Label: "📦 ออเดอร์ Odoo"
- Action: URI
- URL: `https://liff.line.me/{LIFF_ID}#/odoo-orders`

**Button 2: Link Account**
- Label: "🔗 เชื่อมต่อบัญชี"
- Action: URI
- URL: `https://liff.line.me/{LIFF_ID}#/odoo-link`

### Step 4: Upload Image
- Size: 2500 x 1686 pixels
- Format: JPG or PNG
- Max size: 1MB

### Step 5: Set Default
Click "ตั้งเป็น Default" button

---

## 📱 LIFF URLs

### Orders List
```
https://liff.line.me/{LIFF_ID}#/odoo-orders
```

### Account Linking
```
https://liff.line.me/{LIFF_ID}#/odoo-link
```

### Order Detail (with ID)
```
https://liff.line.me/{LIFF_ID}#/odoo-order/{ORDER_ID}
```

### Order Tracking (with ID)
```
https://liff.line.me/{LIFF_ID}#/odoo-order-tracking/{ORDER_ID}
```

---

## 🎨 Recommended Layout (2x2)

```
┌─────────────┬─────────────┐
│   ร้านค้า    │  ออเดอร์ Odoo │
│   🛒 Shop   │  📦 Orders   │
├─────────────┼─────────────┤
│  เชื่อมบัญชี  │   โปรไฟล์    │
│  🔗 Link    │  👤 Profile  │
└─────────────┴─────────────┘
```

**Areas (2500x1686):**
- Top-left: (0, 0, 1250, 843) - Shop
- Top-right: (1250, 0, 1250, 843) - Odoo Orders ⭐
- Bottom-left: (0, 843, 1250, 843) - Odoo Link ⭐
- Bottom-right: (1250, 843, 1250, 843) - Profile

---

## 🔧 Troubleshooting

### Button doesn't open LIFF
- ✅ Check LIFF ID is correct
- ✅ Check Endpoint URL: `https://cny.re-ya.com/liff/`
- ✅ Verify LIFF app is "Published"

### LIFF opens but shows blank page
- ✅ Open Browser Console (F12)
- ✅ Check for JavaScript errors
- ✅ Verify route exists in `router.js`

### Rich Menu doesn't show
- ✅ Upload image first
- ✅ Click "ตั้งเป็น Default"
- ✅ Close and reopen chat

---

## 📊 Testing Checklist

- [ ] LIFF ID configured
- [ ] Rich Menu created
- [ ] Image uploaded (<1MB)
- [ ] Set as default
- [ ] Click "ออเดอร์ Odoo" → opens orders page
- [ ] Click "เชื่อมต่อบัญชี" → opens link page
- [ ] Unlinked users redirected to link page
- [ ] Linked users see order list

---

## 📚 Full Documentation

For detailed guide, see:
- `/re-ya/docs/TASK_16.5_RICH_MENU_INTEGRATION_GUIDE.md`
- `/re-ya/docs/TASK_16.5_COMPLETION_SUMMARY.md`

---

## 🎯 Key Points

1. **All code is ready** - No coding needed
2. **Admin task only** - Use Rich Menu admin panel
3. **30-60 minutes** - Quick to implement
4. **Test thoroughly** - Use checklist above

---

**Need Help?** Check the full implementation guide or contact dev team.
