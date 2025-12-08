# Changelog

All notable changes to the Digitalogic WordPress Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Complete ACF function hooks** for total bidirectional synchronization
  - Hook into ACF's `acf/update_value` filter to sync back to direct options
  - Hook into ACF's `acf/load_value` filter to ensure consistency
  - Even direct ACF function calls now trigger synchronization
- **Fallback ACF-compatible functions** when ACF is not installed
  - Provides `get_field()` function if ACF not present
  - Provides `update_field()` function if ACF not present
  - Plugin now works standalone without ACF dependency
- **ACF availability detection** with `is_acf_available()` method
- **Clickable dashboard stat boxes** with hover effects and navigation
  - Total Products box links to Products page
  - USD/CNY Price boxes link to Currency page
  - Last Update box links to Currency page
  - Smooth hover animations with shadow elevation
- **Enhanced option hooks** to use plugin methods for complete control
  - All `get_option()` calls redirect to plugin methods
  - All `update_option()` calls redirect to plugin methods with logging
- **Infinite loop prevention** in all synchronization hooks
- **Activity logging** for all option updates, even when using direct WordPress functions
- Custom Digitalogic branding icon integrated throughout the plugin
- Square (1:1) SVG icon for WordPress admin menu
- Monochrome version for dashicons compatibility
- Icon assets added to repository for documentation and branding
- WooCommerce High-Performance Order Storage (HPOS) compatibility
- Full support for WooCommerce 8.2+ custom order tables
- Declaration of HPOS compatibility via FeaturesUtil
- Status & Diagnostics page with system information and HPOS status
- Global `get_field()` and `update_field()` functions for ACF-style compatibility
- Option synchronization hooks to ensure `get_option()` and `get_field()` always return the same values
- Persian date formatting support via parsidate plugin integration
- Helper functions for consistent date formatting throughout the plugin
- Automatic Persian (Jalali) calendar support when locale is fa_IR

### Changed
- **Plugin now works both WITH and WITHOUT ACF installed**
  - Checks ACF availability on initialization
  - All get/set methods adapt based on ACF presence
  - Graceful degradation when ACF not available
  - Full functionality maintained in both scenarios
- **CORRECTED**: Full synchronization between `get_option()` and `get_field()`
  - ACF stores options with `options_` prefix (e.g., `options_dollar_price`)
  - Added filters to redirect `get_option('dollar_price')` to `get_option('options_dollar_price')`
  - Both `get_option('dollar_price')` and `get_field('dollar_price', 'option')` now return the SAME value
  - All write operations (`update_option`, `add_option`) synchronize to both storages
  - Automatic bidirectional synchronization ensures consistency
- Automatic migration: removes incorrect `digitalogic_` prefix and syncs with ACF storage
- True field sharing with ACF when installed
- Updated product meta data handling to use WooCommerce CRUD methods
- Date formatting now supports Persian calendar via parsidate plugin
- Update date display now shows formatted date based on user's language
- Replaced direct `get_post_meta`/`update_post_meta` calls with `$product->get_meta()`/`$product->update_meta_data()`
- Updated bulk price recalculation to use WooCommerce product queries
- Improved import/export functions to be HPOS-compatible
- Fixed Persian brand name spelling: "دیجیتالوجیک" → "دیجیتالاجیک"
- Disabled dark mode to force light mode for consistent UI
- Enhanced plugin page with action links and row meta links
- Fixed WP-CLI command registration to prevent "can't have subcommands" error

## [1.0.0] - 2024-12-08

### Added
- Initial release of Digitalogic WooCommerce Extension
- Multi-currency support (USD and CNY exchange rates)
- Dynamic pricing engine with markup support
- Interactive product management with DataTables
- Real-time AJAX updates with 60-second polling
- Bulk product update capabilities
- REST API endpoints for external integrations
  - Products CRUD operations
  - Currency rate management
  - Bulk operations
  - Export functionality
- Webhook notifications for real-time updates
  - Product created/updated events
  - Currency rate change events
  - HMAC signature verification
- WP-CLI commands
  - Product management
  - Currency rate updates
  - Import/Export operations
  - Activity log viewing
- Import/Export functionality
  - CSV format support
  - JSON format support
  - Excel support (via composer packages)
- Activity logging and audit trail
  - User action tracking
  - IP address logging
  - Change history
- Admin interface
  - Dashboard with statistics
  - Product management page
  - Currency settings page
  - Import/Export page
  - Activity logs viewer
- UI/UX features
  - Light/dark mode support
  - RTL/LTR language support
  - Responsive design
  - Inline editing
- Internationalization support
  - POT template file
  - Persian (primary) language support
  - English (secondary) language support
- Security features
  - CSRF protection with nonces
  - Capability checks
  - SQL injection prevention
  - XSS prevention
  - Input sanitization and validation
- CI/CD workflows
  - GitHub Actions for testing
  - PHP 8.0, 8.1, 8.2, 8.3 compatibility testing
  - Code quality checks (PHPCS)
  - Automated deployment workflow
- Documentation
  - Comprehensive README
  - API documentation
  - Installation guide
  - Contributing guide
  - Code examples

### Technical Details
- Minimum PHP version: 8.0
- Minimum WordPress version: 6.0
- Minimum WooCommerce version: 7.0
- Database schema for activity logs
- Custom options for currency rates
- Product meta for dynamic pricing

### Database
- Created `wp_digitalogic_logs` table for activity logging
- Added options: `dollar_price`, `yuan_price`, `update_date`
- Added product meta keys: `_digitalogic_dynamic_pricing`, `_digitalogic_currency_type`, `_digitalogic_base_price`, `_digitalogic_markup`, `_digitalogic_markup_type`

## [Unreleased]

### Planned Features
- Excel import/export with PhpSpreadsheet library
- Advanced pricing rules (quantity-based, customer-based)
- Support for additional currencies (EUR, GBP, etc.)
- Integration with popular accounting software
- Mobile app API endpoints
- Advanced reporting and analytics
- Scheduled currency rate updates
- Price history tracking
- Product comparison tool
- Bulk edit templates
- Custom fields for products
- Integration with POS systems
- Barcode scanning support
- Stock alerts and notifications
- Automatic backup of product data
- Multi-warehouse support

### Known Issues
- None reported

---

## Version History

- **1.0.0** (2024-12-08) - Initial release

---

## Migration Notes

### From Custom Solutions
If migrating from a custom solution:
1. Export your existing product data to CSV
2. Install and activate Digitalogic plugin
3. Configure currency rates
4. Import your product data
5. Configure dynamic pricing rules as needed

### Upgrading
- Always backup your database before upgrading
- Test upgrades in a staging environment first
- Review changelog for breaking changes

---

## Support and Feedback

- Report bugs: https://github.com/atomicdeploy/digitalogic-wp/issues
- Feature requests: https://github.com/atomicdeploy/digitalogic-wp/discussions
- Documentation: https://github.com/atomicdeploy/digitalogic-wp/wiki
