# Shared Digitalogic PHP runtime

WordPress, this Laravel application and the pricing core run in one PHP process.
Composer loads class definitions and helper functions; it boots neither framework.
There is no separate Laravel site, credential handoff or transport between them.

- `digitalogic_laravel()` boots Laravel providers once and returns the shared application. It does not load WordPress.
- `digitalogic_wordpress()` directly includes the installed `wp-load.php` when needed and exposes WordPress and active plugin functions. For scripts outside the normal plugin installation, set `DIGITALOGIC_WORDPRESS_LOAD` to the trusted absolute path before booting.
- Requiring `laravel/bootstrap/integration.php` boots both and returns their handles. `bootstrap/app.php` loads only Laravel; `bootstrap/wordpress.php` loads only WordPress.
- `Digitalogic_Laravel_Bridge::instance()->call($callable, $parameters)` resolves trusted server-side services directly. The caller owns authorization for the operation it exposes.
- User-facing panel kernel calls check `Digitalogic_Access_Control::can_access_panel()` on every invocation. Laravel-side code uses normal WordPress identity and capability functions. No Laravel session middleware is installed.
- The Laravel container binds `Digitalogic\Pricing\Calculator` as a singleton. This is the same class used by WordPress and standalone PHP; it does not contain another formula.

The application is published only after successful provider boot. Recursive boot
fails without exposing a half-initialized container; failed initialization restores
the previous container/facade globals. Boot omits Laravel's global error-handler
bootstrap so WordPress keeps its error handling. Runtime files use a per-install
temporary directory with private directory permissions, outside the plugin tree;
set `DIGITALOGIC_LARAVEL_RUNTIME_PATH` for a persistent private location.

## Composer translation-symbol preparation

Unmodified Laravel 13 defines global `__()` during Composer autoload. WordPress
defines the same function unconditionally in `wp-includes/l10n.php`, causing a
fatal error when WordPress loads second. WordPress owns this symbol; Laravel code
uses `trans()` or its translator service.

The tracked `prepare-runtime.php` Composer post-autoload script removes only that
Laravel shortcut. It accepts the SHA-256 of the locked upstream helper file or
the reviewed prepared result, validates the exact resulting hash, is idempotent,
and reports its action. It never modifies WordPress or arbitrary vendor files.
A Laravel dependency upgrade that changes the file requires explicit review of
this preparation. Do not use `composer install --no-scripts` for a release.
The package verifier rejects a runtime that still claims `__()` and boots the
extracted Laravel application and shared calculator before producing the ZIP.

## Focused verification

```text
php -d memory_limit=512M vendor/bin/phpunit tests/LaravelRuntimeTest.php tests/LaravelBridgeIntegratedAuthTest.php
php tests/laravel-runtime-smoke.php
php tests/laravel-wordpress-l10n-smoke.php /private/evidence/wp-l10n.php
```

The fresh-process smoke uses the WordPress unit-test fixture. The l10n smoke
executes an unmodified copy of actual installed WordPress translation source;
it is not a claim of a full WordPress database/plugin bootstrap. Production
`/panel/` acceptance remains a combined-release check.
