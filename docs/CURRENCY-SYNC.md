# Currency Synchronization Documentation

## Overview

The Digitalogic WooCommerce Extension maintains exchange rates for USD and CNY currencies, which are fully synchronized with WordPress options, ACF fields, and integrated with WooCommerce currency settings.

## Storage Locations

### 1. WordPress Options
The plugin stores exchange rates in WordPress options table:
- `dollar_price` - Exchange rate for 1 USD in local currency
- `yuan_price` - Exchange rate for 1 CNY in local currency
- `update_date` - Last update date in YYMMDD format

### 2. ACF Options (if ACF is installed)
When Advanced Custom Fields (ACF) is installed, the plugin also syncs to:
- `options_dollar_price`
- `options_yuan_price`
- `options_update_date`

These are stored using ACF's `options_` prefix convention, allowing usage of `get_field()` and `update_field()` functions.

### 3. WooCommerce Currency
WooCommerce has its own currency setting (`woocommerce_currency`) which defines the base currency for the store (e.g., USD, IRR, CNY, EUR, etc.).

## How Synchronization Works

### Bidirectional Sync (WordPress Options ↔ ACF)

The plugin implements complete bidirectional synchronization:

1. **When you update via WordPress options:**
   ```php
   update_option('dollar_price', 42500);
   // Automatically syncs to options_dollar_price (ACF storage)
   ```

2. **When you update via ACF:**
   ```php
   update_field('dollar_price', 42500, 'option');
   // Automatically syncs to dollar_price (direct option)
   ```

3. **When you use plugin methods:**
   ```php
   $options = Digitalogic_Options::instance();
   $options->set_dollar_price(42500);
   // Updates both storage locations
   ```

### Integration with WooCommerce Currency

The plugin monitors WooCommerce's currency setting and provides integration:

1. **Currency Change Monitoring:**
   - When WooCommerce currency changes (e.g., from IRR to USD), the plugin logs this change
   - Logged to activity log for audit trail
   - Triggers `digitalogic_woocommerce_currency_changed` action hook

2. **Status Display:**
   - Currency settings page shows current WooCommerce currency
   - Smart warnings if WooCommerce uses USD or CNY (exchange rates may not be needed)
   - Status page shows currency integration status

3. **API Integration:**
   - REST API `/currency` endpoint includes WooCommerce currency info
   - CLI `currency get` command shows WooCommerce currency
   - All currency data includes integration status

## Usage Examples

### Get Current Currency Information

**PHP:**
```php
$options = Digitalogic_Options::instance();

// Get exchange rates
$usd_rate = $options->get_dollar_price();
$cny_rate = $options->get_yuan_price();

// Get WooCommerce currency
$wc_currency = $options->get_woocommerce_currency(); // e.g., 'IRR'
$wc_symbol = $options->get_woocommerce_currency_symbol(); // e.g., '﷼'

// Get full currency status
$status = $options->get_currency_status();
// Returns: woocommerce_currency, woocommerce_symbol, dollar_rate, yuan_rate,
//          is_usd, is_cny, needs_exchange_rates
```

**WP-CLI:**
```bash
wp digitalogic currency get
# Output:
# Currency Rates:
# USD: 42500
# CNY: 6100
# Last Update: 241213
# 
# WooCommerce Currency:
# Base Currency: IRR (﷼)
```

**REST API:**
```bash
curl https://yoursite.com/wp-json/digitalogic/v1/currency
# Returns full currency status including WooCommerce integration
```

### Update Exchange Rates

**PHP:**
```php
$options = Digitalogic_Options::instance();
$options->set_dollar_price(42500);
$options->set_yuan_price(6100);
// Both methods auto-sync to all storage locations
```

**WP-CLI:**
```bash
wp digitalogic currency update --usd=42500 --cny=6100
```

**REST API:**
```bash
curl -X POST https://yoursite.com/wp-json/digitalogic/v1/currency \
  -H "Content-Type: application/json" \
  -d '{"dollar_price": 42500, "yuan_price": 6100}'
```

**Admin Interface:**
- Navigate to Digitalogic → Currency
- Update values in form
- Click "Update Currency Rates"

### ACF Integration

If you're using ACF for your theme/other plugins:

```php
// These work seamlessly with the plugin
$usd = get_field('dollar_price', 'option');
update_field('dollar_price', 42500, 'option');

// Both sync with the plugin's storage
```

## Understanding Currency vs Exchange Rates

### Important Distinction

**WooCommerce Currency** and **Exchange Rates** serve different purposes:

1. **WooCommerce Currency (woocommerce_currency)**
   - The base currency your store uses for pricing
   - Examples: IRR (Iranian Rial), USD (US Dollar), EUR (Euro)
   - Set in WooCommerce → Settings → General

2. **Exchange Rates (dollar_price, yuan_price)**
   - Conversion rates from USD/CNY to your base currency
   - Used for dynamic pricing when product prices are in USD or CNY
   - Only needed if your base currency is different from USD/CNY

### Configuration Scenarios

**Scenario 1: Store uses Iranian Rial (IRR)**
- WooCommerce Currency: IRR
- USD Exchange Rate: 42500 (1 USD = 42500 IRR) ✓ Needed
- CNY Exchange Rate: 6100 (1 CNY = 6100 IRR) ✓ Needed
- Status: Exchange rates are used for dynamic pricing

**Scenario 2: Store uses US Dollars (USD)**
- WooCommerce Currency: USD
- USD Exchange Rate: N/A ⚠️ Not needed (already in USD)
- CNY Exchange Rate: 0.14 (1 CNY = 0.14 USD) ✓ May be needed
- Status: Plugin warns that USD rate is unnecessary

**Scenario 3: Store uses Chinese Yuan (CNY)**
- WooCommerce Currency: CNY
- USD Exchange Rate: 7.2 (1 USD = 7.2 CNY) ✓ May be needed
- CNY Exchange Rate: N/A ⚠️ Not needed (already in CNY)
- Status: Plugin warns that CNY rate is unnecessary

## Hooks and Filters

### Actions

**digitalogic_woocommerce_currency_changed**
Triggered when WooCommerce currency setting changes.

```php
add_action('digitalogic_woocommerce_currency_changed', function($old_currency, $new_currency) {
    // Do something when currency changes
    error_log("Currency changed from {$old_currency} to {$new_currency}");
}, 10, 2);
```

### Filters

**digitalogic_rest_api_permission**
Filter API permission checks.

```php
add_filter('digitalogic_rest_api_permission', function($allowed) {
    // Custom permission logic
    return $allowed;
});
```

## Activity Logging

All currency changes are logged to the activity log:

- **Currency Rate Updates:** Logged with old/new values
- **WooCommerce Currency Changes:** Logged for audit trail
- **Source Tracking:** Shows whether change was via admin, API, or CLI

View logs at: Digitalogic → Logs

## Troubleshooting

### Issue: Exchange rates not syncing

**Check:**
1. Verify WordPress options are writable
2. Check for plugin conflicts affecting option updates
3. Review activity logs for errors
4. Test direct option update: `update_option('dollar_price', 42500)`

### Issue: ACF fields not showing updated values

**Solution:**
1. The plugin syncs automatically, but ACF may cache values
2. Use plugin methods: `Digitalogic_Options::instance()->get_dollar_price()`
3. Clear any caching plugins
4. Verify ACF is installed and active

### Issue: Wrong WooCommerce currency displayed

**Check:**
1. WooCommerce → Settings → General → Currency
2. Ensure WooCommerce is active and up to date
3. Check if any currency switcher plugins are installed

## Best Practices

1. **Always use plugin methods** for currency operations
2. **Monitor activity logs** when making bulk changes
3. **Set appropriate exchange rates** for your base currency
4. **Update rates regularly** to reflect current exchange rates
5. **Use the recalculate option** when changing rates to update product prices

## Technical Details

### Storage Implementation

The plugin uses WordPress hooks to maintain synchronization:

- `pre_option_*` - Intercepts get_option() calls
- `update_option_*` - Intercepts update_option() calls
- `add_option_*` - Intercepts add_option() calls
- `acf/update_value` - Intercepts ACF updates
- `acf/load_value` - Intercepts ACF reads
- `update_option_woocommerce_currency` - Monitors WooCommerce changes

### Infinite Loop Prevention

The plugin includes built-in protection against infinite loops:

```php
static $updating = false;
if ($updating) return;
$updating = true;
// ... perform update ...
$updating = false;
```

This ensures that syncing between storage locations doesn't cause recursion.

## See Also

- [README.md](../README.md) - Main documentation
- [API.md](API.md) - REST API reference
- [EXAMPLES.md](EXAMPLES.md) - Usage examples
