# Testing Guide for Digitalogic Plugin

This guide helps you test the plugin installation and verify all features are working correctly.

## Prerequisites

- WordPress 6.0 or higher
- WooCommerce 7.0 or higher (8.2+ recommended)
- PHP 8.0 or higher
- WP-CLI installed (optional, for CLI testing)

## Installation Testing

### 1. Install via WP-CLI

```bash
# Navigate to WordPress root
cd /path/to/wordpress

# Install the plugin
wp plugin install /path/to/digitalogic-wp.zip --activate

# Verify plugin is active
wp plugin list | grep digitalogic
```

Expected output:
```
digitalogic    1.0.0    active
```

### 2. Check HPOS Compatibility

```bash
# Check if HPOS is enabled
wp option get woocommerce_custom_orders_table_enabled
```

**No warnings should appear in:**
- WooCommerce → Status → System Status
- WooCommerce → Status → Logs

### 3. Verify Database Tables

```bash
# Check if log table was created
wp db query "SHOW TABLES LIKE 'wp_digitalogic_logs';"
```

Expected: Table name should be listed.

---

## Admin UI Testing

### 1. Dashboard Page

1. Navigate to **Digitalogic** → **Dashboard**
2. Verify all statistics display correctly:
   - Total Products count
   - USD Price
   - CNY Price
   - Last Update date

**✅ Success**: No "Loading..." stuck, all values visible

### 2. Products Page

1. Navigate to **Digitalogic** → **Products**
2. Wait for DataTable to load (should be < 5 seconds)
3. Verify:
   - Products list appears
   - Search box works
   - Inline editing works (change a price, should turn yellow)
   - Save Changes button works

**Test Case**: 
```
1. Change a product's price
2. Click "Save Changes"
3. Reload page
4. Verify price persisted
```

**✅ Success**: No infinite "Loading...", products appear, edits save

### 3. Currency Settings

1. Navigate to **Digitalogic** → **Currency**
2. Update USD Price: `42000`
3. Update CNY Price: `6000`
4. Check "Recalculate Prices" checkbox
5. Click "Update Currency Rates"

**✅ Success**: Success message appears, products with dynamic pricing update

### 4. Import/Export

1. Navigate to **Digitalogic** → **Import/Export**
2. Click "Export All Products" (CSV format)
3. Verify file downloads

**Test with WP-CLI**:
```bash
wp digitalogic export --format=csv
```

**✅ Success**: CSV file created in `/wp-content/uploads/digitalogic-exports/`

### 5. Activity Logs

1. Navigate to **Digitalogic** → **Logs**
2. Verify logs table loads
3. Check recent activities are listed

**✅ Success**: Logs table shows data, no loading issues

---

## Translation Testing

### 1. Change WordPress Language

```bash
# Install Persian language
wp language core install fa_IR

# Switch to Persian
wp site switch-language fa_IR
```

### 2. Verify Translation

1. Visit **Digitalogic** pages
2. Verify all text is in Persian
3. Verify RTL layout works correctly

**Expected**:
- Menu items in Persian
- Form labels in Persian
- Button text in Persian
- RTL text direction

**✅ Success**: UI displays in Persian with proper RTL layout

---

## WP-CLI Testing

### 1. Currency Commands

```bash
# Get current rates
wp digitalogic currency get

# Expected output:
# USD: 42000
# CNY: 6000
# Last updated: 241208

# Update rates
wp digitalogic currency update --usd=43000 --cny=6200

# Update with recalculation
wp digitalogic currency update --usd=43000 --recalculate
```

### 2. Product Commands

```bash
# List products
wp digitalogic products list --limit=10

# Search products
wp digitalogic products list --search=arduino

# Update product
wp digitalogic products update 123 --price=250000 --stock=50
```

### 3. Import/Export Commands

```bash
# Export to CSV
wp digitalogic export --format=csv --output=/tmp/products.csv

# Export to JSON
wp digitalogic export --format=json

# Import from CSV
wp digitalogic import /tmp/products.csv
```

### 4. View Logs

```bash
# View recent logs
wp digitalogic logs --limit=20

# Filter by action
wp digitalogic logs --action=update_product --limit=50
```

**✅ Success**: All commands execute without errors

---

## REST API Testing

### 1. Get API Credentials

1. Go to **WooCommerce** → **Settings** → **Advanced** → **REST API**
2. Click **Add Key**
3. Generate consumer key and secret

### 2. Test Endpoints

```bash
# Set credentials
KEY="ck_xxxxxxxxxxxx"
SECRET="cs_xxxxxxxxxxxx"

# Get products
curl -u $KEY:$SECRET \
  "https://yoursite.com/wp-json/digitalogic/v1/products?limit=10"

# Get currency rates
curl -u $KEY:$SECRET \
  "https://yoursite.com/wp-json/digitalogic/v1/currency"

# Update product
curl -X PUT -u $KEY:$SECRET \
  -H "Content-Type: application/json" \
  -d '{"regular_price": 275000}' \
  "https://yoursite.com/wp-json/digitalogic/v1/products/123"

# Bulk update
curl -X POST -u $KEY:$SECRET \
  -H "Content-Type: application/json" \
  -d '{"123": {"regular_price": 275000}, "124": {"stock_quantity": 100}}' \
  "https://yoursite.com/wp-json/digitalogic/v1/products/batch"
```

**✅ Success**: All endpoints return JSON responses with `success: true`

---

## HPOS Specific Testing

### 1. Enable HPOS

1. Go to **WooCommerce** → **Settings** → **Advanced** → **Features**
2. Enable "High-Performance Order Storage"
3. Save changes

### 2. Verify Plugin Still Works

1. Test all admin pages (should work normally)
2. Update products (should save correctly)
3. Check WooCommerce → Status (no compatibility warnings)

### 3. Check HPOS Status Programmatically

```php
// Add to theme's functions.php temporarily
add_action('init', function() {
    if (class_exists('Digitalogic')) {
        $plugin = digitalogic();
        $status = $plugin->get_hpos_status();
        error_log('HPOS Status: ' . print_r($status, true));
    }
});
```

Check debug.log:
```bash
tail -f /path/to/wp-content/debug.log
```

**Expected**:
```
HPOS Status: Array
(
    [hpos_enabled] => 1
    [plugin_compatible] => 1
    [using_custom_tables] => 1
)
```

**✅ Success**: All values are `1` or `true`, no warnings

---

## Performance Testing

### 1. Test with Large Dataset

```bash
# Test bulk price recalculation with many products
time wp digitalogic currency update --usd=43000 --recalculate
```

**Expected**: Should complete within reasonable time (< 5 minutes for 1000 products)

### 2. Monitor Memory Usage

```bash
# Add to wp-config.php
define('WP_MEMORY_LIMIT', '256M');

# Check memory usage during bulk operations
wp eval "echo 'Memory: ' . memory_get_peak_usage(true) / 1024 / 1024 . ' MB';"
```

**✅ Success**: Memory usage reasonable, no timeout errors

---

## Common Issues & Solutions

### Issue: "Loading..." stuck on Products page

**Check**:
1. Browser console for JavaScript errors
2. WordPress admin-ajax.php responses
3. PHP error logs

**Solution**: Already fixed in commit `19c9689`

### Issue: Translation not appearing

**Check**:
```bash
# Verify language files exist
ls -la wp-content/plugins/digitalogic/languages/

# Should show:
# digitalogic.pot
# digitalogic-fa_IR.po
```

**Solution**: Ensure WordPress language is set to Persian

### Issue: HPOS warnings

**Check**:
```bash
# Verify compatibility declaration
wp plugin get digitalogic --field=wc_tested_up_to
```

**Solution**: Already implemented in commit `35bf8d8`

---

## Final Verification Checklist

- [ ] Plugin installs without errors
- [ ] Dashboard loads and shows statistics
- [ ] Products table loads without "Loading..." stuck
- [ ] Currency update works and recalculates prices
- [ ] Import/Export creates valid files
- [ ] Activity logs display correctly
- [ ] Persian translation displays properly
- [ ] RTL layout works correctly
- [ ] WP-CLI commands execute successfully
- [ ] REST API endpoints respond correctly
- [ ] HPOS enabled without warnings
- [ ] No PHP errors in debug.log
- [ ] No JavaScript errors in console
- [ ] Memory usage is reasonable
- [ ] Performance is acceptable

**All items checked?** ✅ Plugin is working correctly!

---

## Support

If any issues are found:

1. Check debug.log: `wp-content/debug.log`
2. Check browser console (F12)
3. Verify WooCommerce version: >= 7.0
4. Verify PHP version: >= 8.0
5. Check HPOS status in WooCommerce settings

For more help, see:
- `docs/HPOS-COMPATIBILITY.md`
- `docs/EXAMPLES.md`
- `docs/INSTALLATION.md`
