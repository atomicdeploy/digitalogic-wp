# Live bidirectional PHP bootstrap acceptance

Read-only checks on the actual Digitalogic server on2026-09-08 verified both bootstrap orders:

- WordPress-first: `digitalogic_integrated_runtime()` booted Laravel in the existing process. WordPress plugins were loaded, Laravel was bootstrapped, and repeated application/calculator resolutions returned the same instances.
- Laravel-first: a fresh PHP CLI process loaded the deployed Composer autoloader and called `digitalogic_laravel()`. WordPress was not eagerly loaded. `digitalogic_wordpress()` then loaded real `/var/www/wp/wp-load.php` and active plugins, preserving the existing Laravel application.

Both paths resolved `Digitalogic\Pricing\Calculator` from the deployed shared plugin's `includes/pricing/Calculator.php` and read canonical CNY34000. The WordPress-first check also confirmed PHP authority and the deployed pricing-service class provenance. Neither helper used an HTTP bridge or a second PHP engine.

This proves live same-process boot and read-only owner access. It does not prove authenticated panel interaction, Laravel-originated changed-price delivery, every interface, Go arithmetic parity, or the full pricing rebuild. No price input or product was changed by these checks.
