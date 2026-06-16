# Laravel Panel Interoperability

The Laravel panel should treat WordPress as the identity and WooCommerce data
source. Users enter the panel from WordPress after `wp-login.php` through
**Digitalogic > Laravel Panel**.

## Authentication Flow

1. WordPress verifies the current admin session and capability.
2. WordPress creates a one-time handoff code valid for 120 seconds.
3. WordPress redirects to `https://panel.digitalogic.ir/auth/wordpress?code=...`.
4. Laravel consumes the code with `POST /wp-json/digitalogic-panel/v1/session/consume`.
5. Laravel creates its own app session mapped to the returned WordPress user.

Laravel request:

```php
$base = 'https://digitalogic.ir/wp-json/digitalogic-panel/v1';

$session = Http::withHeaders([
    'X-Digitalogic-Panel-Token' => config('services.digitalogic.token'),
])->post($base . '/session/consume', [
    'code' => $request->query('code'),
])->throw()->json('data');
```

The response includes the WordPress user, selected capabilities, return target,
WordPress site URLs, cookie names/domain, and shared theme tokens.

## Minimal WordPress Bootstrap

For routes that need to validate WordPress cookies directly, Laravel should load
WordPress without rendering the front-end theme:

```php
define('WP_USE_THEMES', false);
require base_path('../wp/wp-load.php');
```

Avoid loading front-end routes or templates from Laravel. For high-frequency
panel actions, prefer the bridge endpoints and command dispatcher instead of
booting the full front-end request path. That avoids theme rendering, Elementor
frontend work, and unnecessary WooCommerce page hooks.

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
