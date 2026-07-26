# Excel pricing-settings synchronization

The canonical Digitalogic workbook synchronizes global pricing inputs through
the local Patris companion. Excel/VBA never stores a WordPress, WooCommerce, or
Patris credential.

The companion calls three POST-only routes:

- `/wp-json/digitalogic/excel/pricing-sync/state`
- `/wp-json/digitalogic/excel/pricing-sync/preview`
- `/wp-json/digitalogic/excel/pricing-sync/apply`

These routes accept only the existing `X-Patris-Product-Sync-Secret`. The
secret must have a non-empty exact `{id, dataset}` source scope, and every
request must repeat that exact `{id, dataset}` plus a syntactically valid
`sha256:` revision from the local canonical source. The revision remains bound
to preview, idempotency, settings metadata, and audit records. A local revision
that differs from the materialized WordPress product-sync revision is visible
as a non-blocking Persian warning; it is not an authentication failure. There
is no WordPress-session, WooCommerce-key, or administrator-capability fallback
on this machine surface.

## Ownership

- Patris owns product code, foreign price, weight, stock, and other upstream
  product facts.
- WooCommerce owns the public product, URL, and deliberate sale/promotion
  state.
- This API versions the shared dollar rate, yuan rate, effective date, and
  catalog-wide default percentage profit.
- Final product prices are derived. They are never accepted through the Excel
  settings API. After a successful settings apply, the companion regenerates
  the canonical Patris product payload and sends it through the normal product
  sync receiver.

## Request envelope

The request schema is `digitalogic.excel-pricing-sync-request/v1`.
`schema_version` is `1`, `operation` matches the route name, and `source`
contains exactly `id`, `dataset`, and a `sha256:` revision.

State accepts optional `page` and `limit`; the maximum page size is 100.
Catalog locale is always Persian. `fa` and `fa_IR` are accepted as request
locale values.

```json
{
  "schema": "digitalogic.excel-pricing-sync-request/v1",
  "schema_version": 1,
  "operation": "state",
  "source": {
    "id": "configured-source-id",
    "dataset": "configured-dataset",
    "revision": "sha256:CURRENT_SOURCE_REVISION"
  },
  "page": 1,
  "limit": 100,
  "locale": "fa"
}
```

The state data has this stable top-level shape:

```json
{
  "schema": "digitalogic.excel-pricing-sync-state/v1",
  "state_revision": "sha256:GLOBAL_SETTINGS_REVISION",
  "generated_at": "2026-07-26T12:00:00+00:00",
  "source": {
    "id": "configured-source-id",
    "dataset": "configured-dataset",
    "submitted_revision": "sha256:LOCAL_SOURCE_REVISION",
    "current_revision": "sha256:WORDPRESS_SOURCE_REVISION",
    "revision_matches_current": false
  },
  "warnings": [
    {
      "code": "source_revision_out_of_sync",
      "severity": "warning",
      "message_fa": "نسخهٔ منبع محلی با آخرین نسخهٔ ثبت‌شده در سایت یکسان نیست؛ همگام‌سازی تنظیمات برای همین شناسه و مجموعه ادامه یافت.",
      "details": {
        "source_id": "configured-source-id",
        "dataset": "configured-dataset",
        "submitted_revision": "sha256:LOCAL_SOURCE_REVISION",
        "current_revision": "sha256:WORDPRESS_SOURCE_REVISION"
      }
    }
  ],
  "currency": {
    "dollar_price": 170000,
    "yuan_price": 25300,
    "effective_date": "2026-06-29",
    "revision": "sha256:CURRENCY_REVISION",
    "age_days": 27,
    "stale": true
  },
  "default_markup": {
    "configured": true,
    "profit_percent": "30",
    "revision": "sha256:MARKUP_REVISION",
    "updated_at": "2026-06-29 12:00:00"
  },
  "catalog": {
    "dataset": "products",
    "locale": "fa",
    "columns": [],
    "rows": [],
    "pagination": {}
  }
}
```

## Preview

Preview requires a complete settings document. Rates are positive integer
IRT values, the date is strict Gregorian `YYYY-MM-DD`, and default profit is a
base-10 percentage from 0 through 1000.

The `Idempotency-Key` header must exactly equal body `idempotency_key`.
`If-Match` must contain the quoted body revision, for example
`"sha256:..."`.

```json
{
  "schema": "digitalogic.excel-pricing-sync-request/v1",
  "schema_version": 1,
  "operation": "preview",
  "source": {
    "id": "configured-source-id",
    "dataset": "configured-dataset",
    "revision": "sha256:CURRENT_SOURCE_REVISION"
  },
  "idempotency_key": "excel-preview-20260726-0001",
  "expected_state_revision": "sha256:GLOBAL_SETTINGS_REVISION",
  "settings": {
    "dollar_price": 170000,
    "yuan_price": 25300,
    "effective_date": "2026-07-26",
    "default_profit_percent": "30"
  },
  "product_changes": []
}
```

Preview returns a server-bound `preview_digest` with a ten-minute expiry.
Warnings have stable codes, severity, a Persian operator message in
`message_fa`, and bounded comparison details. A date older than seven days and
a currency or profit difference above seven percent are surfaced as critical
warnings. When source revisions differ, `source_revision_out_of_sync` exposes
both submitted and current revisions without blocking preview.

## Apply

Apply repeats the exact source, expected revision, and settings used by the
preview. It adds the unexpired preview digest and the explicit confirmation
string `APPLY`.

```json
{
  "schema": "digitalogic.excel-pricing-sync-request/v1",
  "schema_version": 1,
  "operation": "apply",
  "source": {
    "id": "configured-source-id",
    "dataset": "configured-dataset",
    "revision": "sha256:CURRENT_SOURCE_REVISION"
  },
  "idempotency_key": "excel-apply-20260726-0001",
  "expected_state_revision": "sha256:GLOBAL_SETTINGS_REVISION",
  "settings": {
    "dollar_price": 170000,
    "yuan_price": 25300,
    "effective_date": "2026-07-26",
    "default_profit_percent": "30"
  },
  "product_changes": [],
  "preview_digest": "sha256:PREVIEW_DIGEST",
  "confirmation": "APPLY"
}
```

Apply uses a site-scoped database advisory lock and one SQL transaction. It
updates the direct and ACF-compatible currency options, legacy currency date,
the established default-markup contract, version metadata, and a bounded
nonsecret audit together. Every option is read back exactly before commit. A
failed write/readback rolls the transaction back.

Preview and apply response data use:

```json
{
  "schema": "digitalogic.excel-pricing-sync-preview/v1",
  "mode": "preview",
  "status": "confirmation_required",
  "state_revision": "sha256:GLOBAL_SETTINGS_REVISION",
  "source": {
    "id": "configured-source-id",
    "dataset": "configured-dataset",
    "submitted_revision": "sha256:LOCAL_SOURCE_REVISION",
    "current_revision": "sha256:WORDPRESS_SOURCE_REVISION",
    "revision_matches_current": false
  },
  "preview_digest": "sha256:PREVIEW_DIGEST",
  "expires_at": "2026-07-26T12:10:00+00:00",
  "warnings": [],
  "product_results": []
}
```

An apply idempotency result is retained for 24 hours. Reusing a key with the
same request returns the recorded result; reusing it with another request is a
`409` conflict. A stale settings revision is `412`. The companion must fetch
state again after apply, regenerate canonical products, send product sync, and
perform final WooCommerce storefront readback.

## Security and logging

The companion injects the product-sync secret from its protected runtime
configuration. The workbook receives neither the secret nor an authenticated
WordPress cookie. Authorization headers and secrets must not be logged. Audit
records contain source identity, idempotency key, preview digest, revisions,
and applied nonsecret pricing settings only.
