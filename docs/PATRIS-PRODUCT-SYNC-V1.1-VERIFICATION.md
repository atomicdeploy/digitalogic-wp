# Patris Product Sync v1.1 Verification

This note records the sanitized compatibility run used for the v1.3.3 release.
The production envelope remains outside the repository; no product, category,
path, credential, event identity, or source identity is included here.

## Command shape

The local envelope was read into the lightweight WordPress/WooCommerce test
bootstrap and passed, unchanged, to the public JSON receiver:

```powershell
@'
<?php
require 'tests/bootstrap.php';
$json = file_get_contents('<local-production-envelope.json>');
$result = Digitalogic_Product_Sync_Receiver::instance()->receive_json($json);
$state = Digitalogic_Product_Sync_Receiver::instance()->get_state();
$serialized = maybe_serialize($state);
// Print aggregate counts, byte sizes, elapsed time, and peak memory only.
'@ | php -d memory_limit=128M
```

The receiver therefore used the same strict duplicate-key decoder, exact Go
record/source/event identity checks, formula validation, transactional option
writer, state readback, and mocked WooCommerce delivery path as PHPUnit.

## Sanitized result

```json
{
  "status": "accepted",
  "input_bytes": 4698058,
  "received_products": 3495,
  "stored_products": 3495,
  "received_categories": 1779,
  "stored_categories": 1779,
  "excluded_codes": 7,
  "quarantined_codes": 231,
  "serialized_state_bytes": 5856731,
  "state_limit_bytes": 16777216,
  "state_headroom_bytes": 10920485,
  "state_limit_percent": 34.91,
  "elapsed_seconds": 0.837,
  "peak_memory_bytes": 130023424,
  "php_memory_limit_bytes": 134217728
}
```

The 128 MiB PHP ceiling is exactly 134,217,728 bytes, leaving 4,194,304 bytes
between the observed peak and that isolated CLI ceiling. That is not adequate
deployment headroom for WordPress, WooCommerce, and other active plugins;
production should use at least a 256 MiB effective PHP/WordPress memory limit
and monitor the first full snapshot. The durable receiver state itself uses
34.91% of its separate 16 MiB cap.

The original readback implementation exceeded the 128 MiB test ceiling by
serializing both full expected and read-back states again. v1.3.3 hashes the
already available exact serialized strings instead; the focused state readback
failure tests still pass.

## Automated coverage

- Product-sync receiver: 34 tests, 304 assertions.
- All non-socket-platform plugin tests: 191 tests, 1,591 assertions.
- Twelve portable WebSocket lifecycle tests: 12 tests, 41 assertions.

The remaining WebSocket lifecycle cases require Unix `pcntl_fork`, Unix-domain
socket pairs, or Unix bind-failure behavior and cannot complete on the Windows
PHP runner. They are unrelated to this receiver change and should run in the
Linux CI job before publishing the release artifact.
