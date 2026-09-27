# Digitalogic plugin consolidation

`digitalogic-wp` is the only deployable owner of Digitalogic application code.
The following production-era plugins and MU files are represented by canonical
modules in this repository. Legacy files may coexist only during the bounded
deployment transition and must then be archived outside the WordPress plugin
directories.

| Former runtime | Canonical `digitalogic-wp` owner |
| --- | --- |
| `digitalogic-product-experience` | `includes/integrations/class-digitalogic-product-experience.php` |
| `digitalogic-admin` | `includes/integrations/class-digitalogic-admin-suite.php` and `includes/integrations/admin-suite/` |
| `digitalogic-viewer-bridge` | `includes/integrations/viewer-bridge/` |
| `digitalogic-currency-storefront-freshness` | `includes/integrations/class-digitalogic-currency-storefront-freshness.php` |
| `digitalogic-http-resilience` | `includes/integrations/class-digitalogic-http-resilience.php` |
| `digitalogic-human-contacts` | `includes/cli/class-digitalogic-human-contacts-cli.php` |
| `digitalogic-patris-catalog-backfill` | `includes/class-patris-catalog-backfill.php` |
| `digitalogic-patris-incomplete-alert-adapter` | `includes/integrations/class-digitalogic-patris-incomplete-alert-adapter.php` |
| `digitalogic-price-updated-display` | `includes/integrations/class-digitalogic-price-updated-display.php` |
| `digitalogic-shatel-sms` | `includes/integrations/class-digitalogic-shatel-sms.php` |
| `digitalogic-smsir` | `includes/integrations/class-smsir-integration.php` |
| `digitalogic-woo-sku-guard` | `includes/class-digitalogic-sku-guard.php` |
| `organizer-login-proxy` | `includes/integrations/class-digitalogic-login-proxy.php` (generic owner; Organizer is one consumer) |
| `wp-rocket-cloudflare-intkey-fix` | `includes/integrations/class-digitalogic-wp-rocket-cloudflare-fix.php` |

Secrets, gateway destinations, tokens, and private routing remain in external
server-owned configuration or non-autoloaded private WordPress runtime options.
They are not copied into this repository, public markup, or the release archive.

The deployment order is: install the coherent main release, restart persistent
workers, verify each canonical owner and the rendered storefront, deactivate the
former standard plugins, then move the former MU files into a rollback archive
outside `wp-content/mu-plugins`. A production migration is incomplete while any
former custom plugin or MU file is still active.
