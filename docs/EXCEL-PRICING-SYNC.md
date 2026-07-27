# Excel adapter and universal pricing synchronization

The canonical Digitalogic workbook synchronizes global pricing inputs through
the local Patris companion. Excel/VBA never stores a WordPress, WooCommerce, or
Patris credential.

The local `/api/excel` path is intentionally Excel-specific: it translates
workbook/VBA actions into a transport-neutral request. The companion then calls
three universal, POST-only WordPress routes:

- `/wp-json/digitalogic/pricing/sync/state`
- `/wp-json/digitalogic/pricing/sync/preview`
- `/wp-json/digitalogic/pricing/sync/apply`

The old remote `/wp-json/digitalogic/excel/pricing-sync/*` paths are deprecated
compatibility aliases. They return `Deprecation: true` and a successor `Link`
header; new clients must use `/pricing/sync/*`.

These routes accept only the existing `X-Patris-Product-Sync-Secret`. The
secret must have a non-empty exact `{id, dataset}` source scope, and every
request must repeat that exact `{id, dataset}` plus a syntactically valid
`sha256:` revision from the local canonical source. The revision remains bound
to preview, idempotency, settings metadata, and audit records. A local revision
that differs from the materialized WordPress product-sync revision is visible
as a non-blocking Persian warning; it is not an authentication failure. There
is no WordPress-session, WooCommerce-key, or administrator-capability fallback
on this machine surface.

The earlier workbook “credentials missing” error was expected fail-closed
behavior: the workbook intentionally had no secret, and the trusted companion
runtime either was bypassed or did not have its server-side product-sync secret
available. The corrected boundary is:

1. VBA calls the loopback Patris `/api/excel` adapter without a WordPress secret.
2. The companion reads the secret from protected runtime configuration.
3. The companion calls `/wp-json/digitalogic/pricing/sync/*` with
   `X-Patris-Product-Sync-Secret` and the exact configured `{id,dataset}` scope.

The secret never enters the workbook, a cell, VBA, an audit row, or logs.

## Ownership

- Patris `kala` owns product code, foreign price, weight, stock, and other
  upstream product facts.
- WooCommerce owns public product identity, publication state, URL, and the
  persisted storefront record.
- The pricing coordinator owns USD/CNY rates, their effective dates, the one
  shared profit margin, and the derived selling price.
- Excel and Google Sheets are synchronized view/edit interfaces, not competing
  authorities.
- The `source` envelope identifies the exact component/dataset and revision for
  replay protection; it does not express source precedence.
- Final product prices are derived and never accepted as direct workbook
  settings. For each managed simple product or exact-code variation,
  `_regular_price == _price == canonical selling price` and `_sale_price` is
  empty.

## Request envelope

The request schema is `digitalogic.pricing-sync-request/v1`.
`schema_version` is `1`, `operation` matches the route name, and `source`
contains exactly `id`, `dataset`, and a `sha256:` revision.

State accepts optional `page` and `limit`; the maximum page size is 250.
Catalog locale is always Persian. `fa` and `fa_IR` are accepted as request
locale values.

```json
{
  "schema": "digitalogic.pricing-sync-request/v1",
  "schema_version": 1,
  "operation": "state",
  "source": {
    "id": "configured-source-id",
    "dataset": "configured-dataset",
    "revision": "sha256:CURRENT_SOURCE_REVISION"
  },
  "page": 1,
  "limit": 250,
  "locale": "fa"
}
```

The state data has this stable top-level shape:

```json
{
  "schema": "digitalogic.pricing-sync-state/v1",
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
  "settings": {
    "dollar_price": 170000,
    "yuan_price": 25300,
    "effective_date": "2026-07-26",
    "usd_effective_date": "2026-07-25",
    "cny_effective_date": "2026-07-26",
    "profit_margin_percent": "30",
    "air_express_price_per_kg": "120",
    "air_express_currency": "CNY",
    "shipping_catalog_revision": "sha256:SHIPPING_CATALOG_REVISION"
  },
  "currency": {
    "dollar_price": 170000,
    "yuan_price": 25300,
    "effective_date": "2026-06-29",
    "usd_effective_date": "2026-06-28",
    "cny_effective_date": "2026-06-29",
    "revision": "sha256:CURRENCY_REVISION",
    "age_days": 27,
    "stale": true
  },
  "profit_margin": {
    "configured": true,
    "profit_margin_percent": "30",
    "revision": "sha256:MARKUP_REVISION",
    "updated_at": "2026-06-29 12:00:00"
  },
  "shipping": {
    "method_id": "air_express",
    "price_per_kg": "120",
    "currency": "CNY",
    "catalog_revision": "sha256:SHIPPING_CATALOG_REVISION"
  },
  "default_markup": {
    "configured": true,
    "profit_percent": "30",
    "revision": "sha256:MARKUP_REVISION",
    "updated_at": "2026-06-29 12:00:00",
    "deprecated": true,
    "replacement": "profit_margin"
  },
  "catalog": {
    "dataset": "reconciled_products",
    "locale": "fa",
    "columns": [],
    "rows": [],
    "pagination": {}
  }
}
```

## Preview

Preview requires the complete nine-field settings document shown below. Rates
are positive integer IRT values, dates are strict Gregorian `YYYY-MM-DD`,
`effective_date` equals `cny_effective_date`, and
`profit_margin_percent` is a base-10 percentage from 0 through 1000. The
air-express price, currency, and shipping-catalog revision are submitted
together.

The `Idempotency-Key` header must exactly equal body `idempotency_key`.
`If-Match` must contain the quoted body revision, for example
`"sha256:..."`.

```json
{
  "schema": "digitalogic.pricing-sync-request/v1",
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
    "usd_effective_date": "2026-07-25",
    "cny_effective_date": "2026-07-26",
    "profit_margin_percent": "30",
    "air_express_price_per_kg": "120",
    "air_express_currency": "CNY",
    "shipping_catalog_revision": "sha256:SHIPPING_CATALOG_REVISION"
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

The old request field `default_profit_percent` is accepted only as a deprecated
alias of `profit_margin_percent`. If both are present they must be exactly
equivalent or the request fails. New responses and clients use only
`profit_margin_percent`; the state-only `default_markup.profit_percent` output
is explicitly marked deprecated and equals
`profit_margin.profit_margin_percent`.

## Apply

Apply repeats the exact source, expected revision, and settings used by the
preview. It adds the unexpired preview digest and the explicit confirmation
string `APPLY`.

```json
{
  "schema": "digitalogic.pricing-sync-request/v1",
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
    "usd_effective_date": "2026-07-25",
    "cny_effective_date": "2026-07-26",
    "profit_margin_percent": "30",
    "air_express_price_per_kg": "120",
    "air_express_currency": "CNY",
    "shipping_catalog_revision": "sha256:SHIPPING_CATALOG_REVISION"
  },
  "product_changes": [],
  "preview_digest": "sha256:PREVIEW_DIGEST",
  "confirmation": "APPLY"
}
```

Apply uses a site-scoped database advisory lock and one SQL transaction. It
updates the direct and ACF-compatible currency options, legacy currency date,
the compatibility storage record for the shared profit margin, version
metadata, and a bounded nonsecret audit together. Every option is read back
exactly before commit. A failed write/readback rolls the transaction back.

Preview and apply response data use:

```json
{
  "schema": "digitalogic.pricing-sync-preview/v1",
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

That readback succeeds only when every managed simple product or exact-code
variation has an empty WooCommerce sale field and identical canonical,
regular, and effective selling prices. Unsupported variable-parent or shipping
states fail closed instead of retaining a stale customer price.

## Security and logging

The companion injects the product-sync secret from its protected runtime
configuration. The workbook receives neither the secret nor an authenticated
WordPress cookie. Authorization headers and secrets must not be logged. Audit
records contain source identity, idempotency key, preview digest, revisions,
and applied nonsecret pricing settings only.
