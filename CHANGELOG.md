# Changelog

All notable changes to the Digitalogic WordPress Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-12-08

### Added
- Initial release of Digitalogic WooCommerce Extension
- Multi-currency support (USD and CNY exchange rates)
- Dynamic pricing engine with markup support
- Interactive product management with DataTables
- Real-time AJAX updates with 30-second polling
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
- Added options: `digitalogic_dollar_price`, `digitalogic_yuan_price`, `digitalogic_update_date`
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
