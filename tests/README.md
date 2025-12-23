# Digitalogic Plugin Tests

This directory contains test files for the Digitalogic WooCommerce Extension.

## Manual Tests

### Currency Synchronization Test

**File:** `manual-currency-sync-test.php`

This test verifies that currency settings are properly synchronized between:
- WordPress options (`dollar_price`, `yuan_price`)
- ACF fields (`options_dollar_price`, `options_yuan_price`)
- WooCommerce currency integration (monitored)

**How to run:**

Via WP-CLI (recommended):
```bash
wp eval-file tests/manual-currency-sync-test.php
```

Or directly (if WordPress is accessible):
```bash
php tests/manual-currency-sync-test.php
```

**What it tests:**

1. **Current values check** - Displays current currency settings and WooCommerce integration status
2. **Plugin method update** - Updates via `Digitalogic_Options::instance()->set_*()` and verifies sync
3. **Direct option update** - Updates via `update_option()` and verifies sync
4. **ACF function update** (if available) - Updates via `update_field()` and verifies sync
5. **WooCommerce currency monitoring** - Informational check of WooCommerce currency

**Expected output:**
```
=== Digitalogic Currency Synchronization Test ===

Test 1: Check current values
-----------------------------
Current Dollar Price: 42500
Current Yuan Price: 6100
WooCommerce Currency: IRR
...

Test 2: Update via plugin method
---------------------------------
...
Test 2: PASSED ✓

Test 3: Update via direct WordPress option
-------------------------------------------
...
Test 3: PASSED ✓

...

=== Summary ===
Tests Passed: 3/3

✓ All currency synchronization tests PASSED!
```

## Automated Tests

Currently, there are no automated PHPUnit tests configured. The `phpunit.xml.dist` file is present for future test implementation.

To set up automated tests:

1. Install PHPUnit and WordPress test suite
2. Create test bootstrap file
3. Add unit test cases in this directory
4. Run tests via: `vendor/bin/phpunit`

## Testing Guidelines

When testing the plugin:

1. **Always test currency synchronization** after making changes to currency handling
2. **Test all three update methods:**
   - Plugin methods (`set_dollar_price`, `set_yuan_price`)
   - Direct WordPress options (`update_option`)
   - ACF functions (`update_field`)
3. **Verify WooCommerce integration:**
   - Check currency status display
   - Test with different WooCommerce currency settings
   - Verify logging of currency changes
4. **Test edge cases:**
   - When WooCommerce currency is USD
   - When WooCommerce currency is CNY
   - When WooCommerce currency is different (e.g., IRR)
5. **Check activity logs** to ensure all changes are logged properly

## CI/CD Testing

The plugin uses GitHub Actions for continuous integration. See `.github/workflows/ci-cd.yml` for the automated pipeline.

Current CI pipeline includes:
- PHP Code Sniffer (PHPCS)
- PHP compatibility checks (8.0, 8.1, 8.2, 8.3)
- Security scanning

## See Also

- [TESTING.md](../docs/TESTING.md) - General testing guidelines
- [TESTING-GUIDE.md](../docs/TESTING-GUIDE.md) - Comprehensive testing guide
- [CURRENCY-SYNC.md](../docs/CURRENCY-SYNC.md) - Currency synchronization documentation
