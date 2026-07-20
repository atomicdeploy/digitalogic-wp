# Laravel Panel Interoperability

The Laravel panel should treat WordPress as the identity and WooCommerce data
source. Users enter the temporary in-site panel from WordPress after
`wp-login.php` through **Digitalogic > Panel**.

The reserved `panel.digitalogic.ir` host is not used by default. Until the
standalone platform rewrite is ready, the live panel route is:

```text
https://digitalogic.ir/panel/
```

## Integrated Authentication Flow

The same-origin `/panel/` route uses the existing WordPress login cookie and
capability system directly. It does not create a one-time handoff code, pass a
panel token, or create a second Laravel identity/session.

1. WordPress handles `/panel/` and restores the normal WordPress user session.
2. The plugin verifies `manage_woocommerce` before rendering or booting panel
   code.
3. A bundled Laravel application can be booted in the same PHP process through
   `bootstrap/app.php`; WordPress functions, the current user, WooCommerce, and
   the existing WebSocket configuration remain available to that process.
4. Panel commands continue through the shared command dispatcher, whether the
   transport is WebSocket, AJAX, or an in-process Laravel kernel call.

The former one-time handoff endpoint remains only as compatibility for an
explicitly configured different-host panel. It is not used by the integrated
Digitalogic application and should be removed after consumers have migrated.

## Minimal WordPress Bootstrap

If Laravel becomes the outer request entry point, it must bootstrap WordPress
without rendering the front-end theme and then use WordPress authorization
directly:

```php
define('WP_USE_THEMES', false);
require base_path('../wp/wp-load.php');

if (! is_user_logged_in() || ! current_user_can('manage_woocommerce')) {
    abort(403);
}
```

Do not copy WordPress users into a Laravel guard or pass WordPress identity in
application tokens. Avoid loading front-end routes or templates from Laravel;
boot only the WordPress runtime needed for authentication, capabilities,
WooCommerce services, the command dispatcher, and WebSocket configuration.

## Shared UI

Laravel should call `GET /wp-json/digitalogic-panel/v1/theme` with the panel
token and use those values for:

- logo and site icon
- RTL/LTR direction
- locale
- color tokens
- shared Apache UI assets under `https://digitalogic.ir/digitalogic-ui/`

The Laravel UI should not embed WordPress admin chrome. It should use the shared
Digitalogic identity while providing a denser operations interface for products,
pricing, inventory, users, logs, and panel-only workflows.

## Vue Panel Transport

The temporary `/panel/` panel is a Vue.js SPA with Persian/English labels,
RTL/LTR support, system/light/dark theme modes, and history-based routing for
paths such as `/panel/products/11307`.

It sends commands to `/wordpress-ws` first. If the socket is unavailable, it
falls back to authenticated `admin-ajax.php` through the same command dispatcher.

## Direct Laravel Loading

For direct WordPress-to-Laravel calls, configure the local app path with either:

- the `digitalogic_laravel_app_path` option
- the `digitalogic_laravel_app_path` filter

When configured, the bridge can boot `bootstrap/app.php` and invoke Laravel's
HTTP kernel without using a public vhost or reserved port.

Token-protected bridge endpoints:

- `GET /wp-json/digitalogic-panel/v1/laravel/status`
- `POST /wp-json/digitalogic-panel/v1/laravel/request`

Example request:

```json
{
  "method": "POST",
  "path": "/internal/products/sync",
  "data": {"limit": 50}
}
```

If no Laravel app path is configured, the status endpoint reports
`available: false` and request calls return `503`.
