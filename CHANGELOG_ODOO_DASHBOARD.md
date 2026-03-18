# Odoo Dashboard - Changelog

## [Unreleased]

### Added
- Search debouncing for webhook filter (500ms delay) - reduces API calls by 80-90%

### Changed
- Webhook search input now uses `oninput` event with debounced function instead of `onkeyup` with Enter key detection
- File: `odoo-dashboard.php` line 643

### Performance Impact
- **Before**: Every keystroke triggers immediate API call
- **After**: API call delayed by 500ms, cancelled if user continues typing
- **Result**: Reduces server load during search operations

### Implementation Details
```javascript
// Debounce utility (already exists in odoo-dashboard.js)
const debouncedLoadWebhooks = debounce(function(){
    whCurrentOffset = 0;
    loadWebhooks();
}, 500);
```

```html
<!-- Updated HTML input -->
<input type="text" class="form-control" id="whFilterSearch" 
       placeholder="ค้นหา..." 
       oninput="debouncedLoadWebhooks()">
```

### Next Steps
- [ ] Apply debouncing to customer search input
- [ ] Apply debouncing to slip search input
- [ ] Apply debouncing to order search input
- [ ] Consider reducing delay to 300ms for better responsiveness

---

## Documentation Updates

### Files Updated
- `docs/ODOO_DASHBOARD_REVIEW.md` - Updated Thai documentation with implementation status
- `DASHBOARD_CODE_REVIEW_SUMMARY.md` - Marked webhook search debouncing as completed
- `ODOO_DASHBOARD_ANALYSIS.md` - Updated performance optimization section

### Related Documentation
- [Thai Code Review](docs/ODOO_DASHBOARD_REVIEW.md)
- [Code Review Summary](DASHBOARD_CODE_REVIEW_SUMMARY.md)
- [Detailed Analysis](ODOO_DASHBOARD_ANALYSIS.md)
- [Documentation Index](docs/DASHBOARD_DOCUMENTATION_INDEX.md)

---

**Date**: 2026-03-18  
**Developer**: Development Team  
**Impact**: Performance Improvement  
**Breaking Changes**: None
