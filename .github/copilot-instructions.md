# GitHub Copilot Instructions for Digitalogic WooCommerce Plugin

## Project Overview

This is a WordPress/WooCommerce plugin providing dynamic pricing, stock management, and external system integration for Digitalogic electronic components shop. The plugin supports multi-currency pricing (USD/CNY), bulk operations, import/export functionality, REST API, webhooks, and WP-CLI commands.

## Technology Stack

- **Language**: PHP 8.0+ (currently targeting PHP 8.3)
- **Framework**: WordPress 6.0+, WooCommerce 7.0+ (HPOS compatible)
- **Dependencies**: PhpSpreadsheet for Excel operations
- **Database**: MySQL 5.7+
- **Frontend**: Vanilla JavaScript, jQuery, DataTables
- **CSS**: Custom CSS with light/dark mode support

## Coding Standards

### PHP Standards

- Follow **WordPress Coding Standards** strictly
- Use **4 spaces** for indentation (no tabs)
- Follow **PSR-4** autoloading standards
- Add **PHPDoc blocks** to all functions, methods, and classes
- Use WordPress functions for sanitization and escaping (e.g., `sanitize_text_field()`, `esc_html()`, `esc_url()`)
- Use WordPress database methods with prepared statements (e.g., `$wpdb->prepare()`)
- All WordPress hooks should use the `digitalogic_` prefix

### Class Structure

- Class names: `Digitalogic_ClassName` (WordPress style)
- File names: `class-classname.php` (lowercase with hyphens)
- Method visibility: Always specify (`public`, `private`, `protected`)
- Static methods: Use for utility functions only

### Security Requirements

- **CSRF Protection**: Use WordPress nonces for all forms and AJAX requests
- **Capability Checks**: Always verify user permissions before operations (e.g., `manage_woocommerce`)
- **Input Validation**: Sanitize all user input using WordPress functions
- **Output Escaping**: Escape all output using appropriate WordPress functions
- **SQL Injection Prevention**: Use `$wpdb->prepare()` for all database queries
- **XSS Prevention**: Escape all HTML output

### Naming Conventions

- Functions: `digitalogic_function_name()`
- Hooks/Filters: `digitalogic_hook_name`
- Options: `digitalogic_option_name`
- Post Meta: `_digitalogic_meta_key` (prefix with underscore for private meta)
- REST API namespace: `digitalogic/v1`
- WP-CLI commands: `wp digitalogic command`
- Database tables: `{$wpdb->prefix}digitalogic_table_name`

## Architecture & File Structure

### Core Classes

1. **Digitalogic_Options** (`includes/class-options.php`): Currency and options management with ACF-style API
2. **Digitalogic_Logger** (`includes/class-logger.php`): Activity logging and audit trail
3. **Digitalogic_Product_Manager** (`includes/class-product-manager.php`): Product CRUD operations
4. **Digitalogic_Pricing** (`includes/class-pricing.php`): Dynamic pricing calculations
5. **Digitalogic_Import_Export** (`includes/class-import-export.php`): CSV/JSON/Excel import/export
6. **Digitalogic_Admin** (`includes/admin/class-admin.php`): Admin interface and AJAX handlers
7. **Digitalogic_REST_API** (`includes/api/class-rest-api.php`): REST API endpoints
8. **Digitalogic_Webhooks** (`includes/api/class-webhooks.php`): Webhook notifications
9. **Digitalogic_CLI_Commands** (`includes/cli/class-cli-commands.php`): WP-CLI commands
10. **Digitalogic_Product_Table** (`includes/admin/class-product-table.php`): Product table utilities

### Directory Structure

```
digitalogic-wp/
├── digitalogic.php              # Main plugin file (entry point)
├── includes/
│   ├── admin/                   # Admin interface
│   │   ├── class-admin.php
│   │   ├── class-product-table.php
│   │   └── views/               # Admin view templates
│   ├── api/                     # API classes
│   │   ├── class-rest-api.php
│   │   └── class-webhooks.php
│   ├── cli/                     # WP-CLI commands
│   │   └── class-cli-commands.php
│   └── class-*.php              # Core classes
├── assets/
│   ├── css/admin.css            # Admin styles
│   └── js/admin.js              # Admin scripts
├── languages/                   # i18n files
└── docs/                        # Documentation
```

## Database Schema

### Options
- `dollar_price`: USD exchange rate
- `yuan_price`: CNY exchange rate
- `update_date`: Last update date (YYMMDD format)

### Product Meta Keys
- `_digitalogic_dynamic_pricing`: Enable dynamic pricing (yes/no)
- `_digitalogic_currency_type`: Currency type (usd/cny)
- `_digitalogic_base_price`: Base price in foreign currency
- `_digitalogic_markup`: Markup value
- `_digitalogic_markup_type`: Markup type (percentage/fixed)

### Custom Tables
- `{$wpdb->prefix}digitalogic_logs`: Activity logging with user tracking

## Development Workflows

### Running Tests

```bash
# Check code style
composer phpcs

# Auto-fix code style
composer phpcbf

# Run PHP syntax check
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;

# Run unit tests
composer test
```

### Adding New Features

1. **REST API Endpoint**:
   - Add method to `Digitalogic_REST_API` class
   - Register route in `register_routes()`
   - Add permission callback
   - Update `docs/API.md`

2. **WP-CLI Command**:
   - Add method to `Digitalogic_CLI_Commands` class
   - Register with `WP_CLI::add_command()`
   - Add synopsis in PHPDoc
   - Update README.md

3. **Admin Page**:
   - Add menu in `Digitalogic_Admin::add_menu_pages()`
   - Create view file in `includes/admin/views/`
   - Add AJAX handlers if needed
   - Enqueue assets

### Common Patterns

#### WooCommerce HPOS Compatibility
- Use `wc_get_product()` instead of `new WC_Product()`
- Use `$product->get_id()` instead of `$product->id`
- Use `$product->save()` for updates
- Use WooCommerce CRUD methods for all operations

#### Currency and Pricing
- Use `Digitalogic_Options::get_field()` for currency rates
- Use `Digitalogic_Pricing::calculate_price()` for price calculations
- Always recalculate prices when currency rates change

#### Logging
- Use `Digitalogic_Logger::log()` for all important actions
- Include user_id, action, object_type, object_id, old_value, new_value

#### AJAX Operations
- Register handlers in `Digitalogic_Admin::register_ajax_handlers()`
- Verify nonce: `check_ajax_referer('digitalogic_admin_nonce')`
- Check capability: `current_user_can('manage_woocommerce')`
- Return JSON: `wp_send_json_success()` or `wp_send_json_error()`

## Testing Guidelines

### Manual Testing
- Test with WooCommerce HPOS enabled and disabled
- Test with simple and variable products
- Test multi-currency price calculations
- Test import/export with large datasets
- Test REST API with authentication
- Test WP-CLI commands

### Code Quality Checks
- All code must pass PHPCS WordPress standards
- No PHP syntax errors
- PHPUnit tests should pass (when available)

## Internationalization

- Text domain: `digitalogic`
- Use `__()`, `_e()`, `esc_html__()`, `esc_html_e()` for all strings
- Primary language: Persian (fa_IR)
- Secondary language: English (en_US)
- Support RTL/LTR layouts

## Common Commands

```bash
# Install dependencies
composer install

# Update dependencies
composer update

# Code quality
composer phpcs              # Check standards
composer phpcbf             # Fix standards

# Create plugin zip
zip -r digitalogic-wp.zip . -x ".*" "vendor/*" "node_modules/*"

# WP-CLI examples
wp digitalogic currency get
wp digitalogic currency update --usd=42000 --cny=6000
wp digitalogic products list --limit=20
wp digitalogic export --format=excel --output=products.xlsx
wp digitalogic import products.csv
```

## Important Notes

### Performance Considerations
- Use transients for caching expensive operations
- Batch operations for bulk updates
- Use AJAX polling (60s intervals) for real-time updates
- Optimize database queries with proper indexing

### WordPress Integration
- Hook into WooCommerce product save: `woocommerce_update_product`
- Hook into product deletion: `woocommerce_delete_product`
- Use WordPress admin notices: `admin_notices` hook
- Enqueue scripts properly with dependencies

### Error Handling
- Use `WP_Error` for error returns
- Log errors to `Digitalogic_Logger`
- Provide user-friendly error messages
- Include debug information in logs

### API Design
- Follow RESTful principles
- Use proper HTTP status codes
- Validate all input parameters
- Return consistent JSON responses
- Include pagination for list endpoints

## Contribution Guidelines

- Small, focused commits
- Follow conventional commit format (feat:, fix:, docs:, etc.)
- Update CHANGELOG.md
- Add tests for new features
- Update documentation
- No breaking changes without major version bump

## Resources

- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- [WooCommerce CRUD](https://github.com/woocommerce/woocommerce/wiki/CRUD-Objects)
- [WP-CLI Documentation](https://developer.wordpress.org/cli/commands/)
- [REST API Handbook](https://developer.wordpress.org/rest-api/)

## Project-Specific Context

This plugin is specifically designed for Digitalogic electronic components shop with:
- Focus on B2B operations
- Multi-currency support for international sourcing
- Integration with external POS systems
- High volume of SKUs (thousands of products)
- Real-time inventory management
- Persian as primary language with RTL support
