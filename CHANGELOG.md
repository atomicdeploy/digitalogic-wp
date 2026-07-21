# Changelog

All notable changes to the Digitalogic WordPress Plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Added a persisted, optional sticky first product column that follows the first visible/reordered column in RTL and LTR while keeping the selection control frozen beside it.
- Added reusable Digitalogic browser error pages with responsive light/dark styling, English and Persian copy, RTL/LTR layout, safe recovery actions, and stable support references.
- Added an opt-in Google Sheets product/pricing control workspace with bounded preview/apply writeback, exact Patris identity and revisions, append-only audit rows, guarded WooCommerce product writes, and an inactive credential-placeholder n8n proxy template.
- Added an idempotent professional Google Sheets control-center builder with live catalog KPIs, charts, a landed-price calculator, bilingual guidance, editable non-secret settings, protected reference tabs, and one-command synchronization and scheduling.

### Security
- Google Sheets writeback uses exact-decimal optimistic revisions, idempotent requests, a shared WooCommerce product lock, and transactional shipping compare-and-set apply/compensation so concurrent changes are preserved.

### Fixed
- Styled the reserved `Changes` and `Audit` support rows as professional workflow panels while preserving staged values, append-only audit data, legacy layouts, and frozen table headers.
- Made the n8n Google Sheets writeback template return the actual Digitalogic JSON envelope on n8n 2.x instead of ending the webhook early with an empty HTTP 200 response.
- Repaired critical `/panel/` rendering and inline-edit regressions: title direction is always callable, pointer edits place a collapsed caret at the clicked text position, Patris currency uses a canonical clearable selector, and render/bootstrap failures now show a localized recovery screen with structured, deduplicated console diagnostics.
- Corrected Persian product-table geometry, including the `Ctrl+K` hint, exact checkbox centering, compact/mobile metadata containment, stable action-column sizing, and removal of the empty row tail caused by responsive column tracks.
- Made the current Patris reconciliation report usable across warning and price-list views with bounded 50-row pages, category filters, request deduplication, stale-response protection, client-language labels, visible generation details, catalog-generation invalidation that cannot republish stale in-flight data, and a server-side build lock against concurrent forced refreshes.
- Prevented the private storefront request post type from registering `manage_woocommerce` as an object meta capability, which caused WordPress to deny valid administrator and shop-manager access across the panel, admin, REST, and WebSocket paths.
- Centralized panel authorization across the browser shell, in-process Laravel bridge, AJAX command dispatcher, and authenticated WebSocket path, including a safe WordPress-administrator fallback without granting storefront customers access.
- Replaced the panel's raw WordPress `wp_die()` authorization response with a scoped, escaped Digitalogic 403 document that does not expose a Query Monitor call stack.
- Made the `/panel/` and nested panel rewrite rules self-healing when WordPress retains the plugin's rewrite-version marker but another deployment or permalink refresh drops the stored routes.
- Made panel launches strictly same-origin and in-process using the existing WordPress session; removed the panel token, session handoff, external-panel mode, and copied identity headers.
- Prevented the custom Digits login and registration footer from blocking a PHP-FPM worker on remote WordPress.org translation discovery while preserving the locale already selected by WordPress.

### Changed
- Patris catalog publication now fails closed and returns a managed leaf to hidden draft unless source and WooCommerce prices, stock and weight, reviewed WooCommerce media, currency-qualified freight, markup, exchange rate, pricing assignment, canonical shipping, identity, category, enrichment, and source warnings all pass their readiness gates.
- Canonical `air_express` assignment now remains on the in-memory WooCommerce product through its final materializer save instead of risking a stale-object overwrite.
- Product JSON-LD adds the reviewed English Patris leaf or family name as `alternateName`, removes impossible offers for empty or zero WooCommerce prices, and atomically converts complete Toman offer subtrees to their exact ISO `IRR` equivalent without float drift or partial currency relabelling.
- Standardized user-facing Persian product identity labels as `کد کالا` and `سریال کالا` across translated notices, ACF fields, WooCommerce attributes, cart/checkout data, order and invoice metadata, rendered content, and the Google Sheets catalog while preserving internal source identifiers.
- Shipping rates now carry an explicit `CNY` or `IRR` currency. Method objects use `price_per_kg` plus `currency`, while product-sync records use the required pair `shipping_price_per_kg` plus `shipping_price_per_kg_currency`.
- Final-price validation converts CNY freight with the effective CNY-to-IRT rate and converts IRR freight to IRT before applying markup and a single final rounding step.
- Shipping amounts, minimums, divisors, and tier bounds/rates now remain canonical decimal strings through storage and every outward projection, without exponent notation or binary-float loss.
- Product sync preserves missing versus explicitly null freight fields, and the one-time installed-data migration bypasses stale option caches and verifies persistence before marking completion.

## [1.6.5] - 2026-07-21

### Fixed
- Restored the narrowly scoped Woodmart/Digits sidebar compatibility layer so only the active login step is visible and the singleton call-verification control mounts immediately beneath the OTP resend action.
- Restored Persian caller-ID guidance, keyboard semantics, RTL/mobile containment, scoped notice styling, and cache-busted sidebar assets without duplicating forms or verification widgets.

## [1.6.4] - 2026-07-21

### Fixed
- Replaced the temporary public inbound verification menu with a caller-ID-gated shortcut for active 120-second challenges, preserving the original direct-to-operator call flow for everyone else.

### Changed
- Poll verified calls every 500 ms for immediate browser-bound login completion, with cancellation, expiry, replay, rate-limit, and stale-request safeguards.
- Mix all verification speech with the reviewed low-volume PBX background music and keep code collection private inside AGI.

## [1.6.3] - 2026-07-21

### Added
- Exposed the existing inbound-call login verification beneath the active Digits OTP resend control in the Woodmart sidebar, with singleton AJAX remounting, Persian/RTL and mobile containment, keyboard disclosure semantics, and live dial instructions.

## [1.6.2] - 2026-07-21

### Fixed
- Restored the Digits password/verification-code layout in the guest Woodmart login sidebar, including scoped RTL, responsive, OTP-help, loading, error, honeypot, and keyboard-accessibility safeguards.

## [1.6.1] - 2026-07-21

### Fixed
- Preserved WordPress account-policy authentication filters while exempting only WP Zero Spam's core form honeypot from a signed, browser-bound PBX login consume request.

## [1.6.0] - 2026-07-21

### Added
- Secure six-digit phone verification by inbound call on `021-66754123`, IVR option `2`, as a Digits-independent login alternative and a way to verify supplemental Iranian mobile or fixed-line contacts.
- Multiple supplemental phone and email contacts in WooCommerce My Account and WordPress user profiles, with per-phone voice consent and order-event preferences.
- Disabled-by-default, asynchronous WooCommerce status announcements through the loopback PBX callout service, including a global kill switch, per-status Persian templates, strict placeholders, quiet hours, rate limits, consent rechecks, and idempotent jobs.
- Signed, replay-resistant PBX callback contract at `/wp-json/digitalogic/v1/call-verification/pbx-confirm`, encrypted contact storage, database-backed verification rate limits, and focused PHP protocol tests.
- Transactional consent audit records, reason-required administrative consent expansion, bounded retention, per-canonical-number outbound limits, and a reconciled voice-job queue.

### Security
- Phone ownership codes are stored only as keyed MACs, browser challenges use opaque HttpOnly bindings, and verified challenges are consumed atomically once.
- PBX verification secrets and callout credentials are read only from `wp-config.php`; callback bodies, identifiers, timestamps, DID/ANI values, and exact HMAC signatures are bounded and validated before use.
- PBX schema availability is fail-closed until every required InnoDB table, column, index, cleanup task, and recovery schedule verifies successfully; signed callback attempt limits reserve capacity atomically before code matching.

## [1.5.2] - 2026-07-21

### Added
- Displayed the canonical Patris Code explicitly on product loops, Woodmart single-product layouts, selected variations, cart/checkout lines, order details, homepage product cards, and the table catalog.
- Kept unrelated WooCommerce SKUs labeled as SKU, hid only exact duplicate SKU output, and showed legacy child Codes as registered model references without implying that they are directly purchasable.
- Let table-catalog searches for a published variation Code return the parent product while blocking misleading quick-add actions for legacy code-less parents with coded child records.

## [1.5.1] - 2026-07-21

### Changed
- Prioritized products with real photos in the default table view while keeping explicit popularity, price, name, and date sorting available.
- Routed homepage category entry points into the professional table catalog instead of the legacy product grid.
- Added a touch- and keyboard-friendly carousel pause control, earlier stylesheet loading, hardened public form inputs, Persian/Arabic phone-digit normalization, and quick-add network failure recovery.

## [1.5.0] - 2026-07-21

### Added
- Restored a high-contrast, keyboard-accessible homepage carousel with original generated artwork for stocked modules, foreign sourcing, and two-/four-layer PCB production.
- Added two non-duplicated, inventory-backed product carousels with improved responsive controls.
- Added a professional RTL product-table catalog with search, category and sort filters, pagination, intentional image fallbacks, and native WooCommerce quick add.
- Added complete guest-friendly foreign-sourcing and PCB quote forms with repeatable line items, strict validation, private uploads, tracking codes, admin records, notifications, and request statuses.
- Added temporary openly licensed product-photo attribution support and a public image-credit register.

## [1.4.3] - 2026-07-21

### Fixed
- Read variation options through WooCommerce's variation-attribute API so reviewed children remain idempotent after creation and duplicate options are still rejected.

## [1.4.2] - 2026-07-20

### Added
- Hid product categories on the public storefront when they contain no catalog-visible products while preserving authoritative admin, CLI, and integration queries.
- Added an inventory-backed Persian homepage showcase with a varied in-stock product hero, focused category paths, China sourcing, and two-/four-layer PCB services without duplicated product rails.

## [1.4.1] - 2026-07-20

### Fixed
- Added an escaped, idempotent Woodmart single-product fallback so the reviewed English Patris identity renders immediately below the Persian product title even when the theme bypasses WooCommerce's standard summary hook.

## [1.4.0] - 2026-07-20

### Added
- Added an administrator-reviewed, dry-run-first Patris catalog materializer for positive-stock records from the living product-sync contract, including explicit simple-product adoption/creation and variation children under reviewed existing variable parents.
- Added stable Patris category ownership, additive category assignment, optional reviewed Persian category names, and explicitly referenced Digitalogic-only categories without overwriting unrelated manual taxonomy work.
- Added reviewed Persian product and category SEO metadata, short descriptions, part/model metadata, Rank Math sitemap cache invalidation, and publication readiness gates.
- Added a second storefront identity line for the original English Patris name, selected-variation identity updates, and product structured-data SKU/MPN enrichment.
- Added storefront and panel search coverage for Persian names, Patris names, exact Codes/SKUs, serials, part numbers, models, variation records, and product categories.

### Changed
- Reused the existing Patris feed writer for price, positive stock, weight, warehouse, warning, and pricing metadata, and assigned the canonical `air_express` supplier shipping method to materialized leaves.
- Kept newly created products and previously nonpublic reviewed targets as drafts unless every source, pricing, freight, category, identity, enrichment, and SEO publication gate passes and an administrator explicitly supplies `--publish-ready`; preserved already-published reviewed targets without counting them as newly published.

### Security
- Require strict manifests with exact source identity, reviewed target IDs, duplicate-key rejection, bounded input size, positive-stock filtering, and a named apply lock; refuse implicit variable-parent conversion or unreviewed leaf ownership.

## [1.3.6] - 2026-07-20

### Added
- Read-only, bounded Google Sheets catalog pages that reuse the canonical product, pricing, shipping-method, warehouse-stock, and category services.
- An import-ready Google Apps Script with separate Products/Categories tabs, exact Patris Code or display-only `woo:<id>` key upserts, bilingual RTL/LTR headers, manual refresh, idempotent scheduled synchronization, and Script Properties-only credentials.
- Standalone REST, WP-CLI, and n8n integration guidance with explicit status/error columns and per-record/page revisions.

### Changed
- Made Google Sheets catalog rows follow the living sparse response: missing keys mean no source/reference value, explicit upstream null remains `null`, and Patris matching never falls back to SKU.
- Apps Script now validates the requested dataset, column and row arrays, and pagination object directly.

## [1.3.5] - 2026-07-20

### Changed
- Replaced the product-sync payload families with one sparse living contract, including category and exclusion projections and explicit missing-versus-null semantics.
- Moved the four Patris-facing routes to the `digitalogic` REST namespace and removed raw-feed and pricing aliases.
- Standardized supplier shipping inputs, storage, events, and responses on one canonical field set without mirrored keys.

## [1.3.4] - 2026-07-20

### Added
- Added versioned `[dollar_rate]` and `[yuan_rate]` storefront cards that share the same currency effective-date service used by the WordPress admin and external panel.
- Added strict regression coverage for legacy YYMMDD storage, ISO dates and date-times, invalid/empty values, Persian and English locales, and the production `260629` case.

### Fixed
- Prevented legacy YYMMDD currency dates from being interpreted as Unix timestamps and displaying an epoch-era Jalali year such as `۱۳۴۸`.
- Read the raw `options_update_date` value before ACF/wp-parsidate formatting, return a blank value for invalid dates instead of silently substituting today, and provide deterministic built-in Jalali conversion with Persian digits for `fa*` locales.

## [1.3.3] - 2026-07-20

### Added
- Added product `category_code`, the complete typed category projection, and excluded catalog Codes, including Go-compatible category, source, and event identity verification.
- Persisted the catalog projection in receiver state, exposed bounded category/exclusion counts in receiver responses and status output, and mirrored each product's category Code to `_digitalogic_patris_category_code` through the shared WooCommerce writer.
- Added a Go-compatible golden fixture and focused catalog, tamper, identity, and sparse-value coverage.
- Added the production-proven pre-save WooCommerce change capture to `product.updated` webhooks, with compact `changed_fields` values and date/scalar normalization.

### Changed
- Reduced peak memory during transactional receiver readback by comparing the exact stored serialization digest instead of serializing both the expected and read-back state again.
- Ported the live login loading-state fixes so button text is hidden behind a centered spinner, loading stripes loop cleanly in LTR/RTL, and Persian retry messages no longer claim the form was released.

## [1.3.2] - 2026-07-17

### Changed
- Added grouped Dependabot maintenance for Composer and GitHub Actions so dependency updates remain reviewable and independently testable.
- Updated the release and CI workflows to current `actions/checkout`, `actions/cache`, `actions/download-artifact`, and `actions/upload-artifact` generations.
- Updated the WordPress Coding Standards development dependency from 3.3.0 to 3.4.0 without changing the production dependency set.

## [1.3.1] - 2026-07-17

### Fixed
- Restored read-only WooCommerce minimum/maximum price ranges in the product grid without removing the current editable price fields or reintroducing per-row database queries.

## [1.3.0] - 2026-07-17

### Added
- Exact product and variation access by canonical WooCommerce ID or case-sensitive SKU across REST and WP-CLI.
- Read-only product metadata diagnostics showing effective WooCommerce values, raw lookup-source values, and stale derived rows.
- Safe one-product lookup refresh on WooCommerce versions exposing the public row API, with no catalog-wide fallback.
- Server-side product table filtering, sorting, persistent views, aligned configurable columns, and bounded pagination across transports.
- Native WordPress currency-page postboxes and locale-aware zero-decimal currency summaries.
- Read-only WooCommerce base-currency monitoring with explicit IRT/Toman metadata, catalog compatibility warnings, shared REST/CLI/panel/webhook status, audit events, and non-destructive automated coverage.
- Optional signed `patris.product_sync.applied` observer summaries through the existing webhook fan-out, without exposing product payloads or affecting direct sync outcomes.
- Bounded product-sync deferred reconciliation state and administrator WP-CLI status/retry commands, separating terminal missing/ambiguous Codes from transient HTTP/database retries.
- Canonical nullable global default percentage markup with exact decimal storage, REST/command/admin controls, catalog revisioning, and result-aware delivery events.
- **WordPress Admin Bar integration** with quicklinks menu
  - Parent menu item with WooCommerce-focused cart icon (dashicons-cart)
  - Quicklinks to Dashboard, Products, Currency, Import/Export, Logs, and Status pages
  - Contextually appropriate Dashicons for each menu item (dashboard, products, money-alt, database-import, list-view, info)
  - Capability checks to ensure only authorized users see the menu
  - Works on both front-end and back-end when admin bar is displayed
  - Optimized CSS styling for admin bar menu items
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
- Preserved the historical positional-ID `--sku` setter while introducing explicit `--set-sku` selection semantics.
- Refreshed Persian translation catalogs and removed the stale tracked binary catalog from source control.
- Made WooCommerce base-currency and Patris IRT readiness visible without mutating the store currency.
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

## [1.2.0] - 2026-07-16

### Added
- Dedicated authenticated `patris.product-sync` REST receiver.
- Strict typed envelope validation, recursive raw-field rejection, receiver-side exact `landed_price` evaluation, Go-compatible record/source/event hash verification, and duplicate-key-safe JSON decoding.
- Ordered per-source snapshots, timestamp-bound event identities, update merging, bounded replay protection, quarantine preservation, and deletion-only tombstones that never delete WooCommerce products.
- Dedicated header-only receiver secrets with optional exact source scopes, plus a durable per-product WooCommerce outbox with record-hash CAS recovery.
- Receiver contract and staged rollout documentation.

### Changed
- The normalized Patris WooCommerce writer is shared by all current ingestion paths so Code resolution, weight, stock, price, and metadata behavior stay aligned.

## [1.1.0] - 2026-07-16

### Added
- Canonical supplier shipping-method catalog, immutable method IDs, and exact product/variation assignment APIs.
- Shared exact product identifier resolver with WooCommerce ID, SKU, and Patris Code precedence.
- `landed_price` integration catalog and percentage-markup contract for Patris Export.
- Result-aware durable panel queue, Redis/WebSocket, and multi-destination webhook delivery reporting.

### Changed
- Patris gram weights are converted into the configured WooCommerce store weight unit.
- Supplier shipping-method writes use verified InnoDB transactions, authoritative rollback, and cache invalidation.

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

## Roadmap

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
