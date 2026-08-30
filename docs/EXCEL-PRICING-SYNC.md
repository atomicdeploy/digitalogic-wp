# Excel adapter and universal pricing synchronization

The canonical Digitalogic workbook synchronizes global pricing inputs through
the local Patris companion. Excel/VBA never stores a WordPress, WooCommerce, or
Patris credential.

The local `/api/pricing-sync` path is intentionally Excel-specific: it translates
workbook/VBA actions into a transport-neutral request. The companion then calls
three universal WordPress mutation routes:

- `/wp-json/digitalogic/pricing/sync/state`
- `/wp-json/digitalogic/pricing/sync/preview`
- `/wp-json/digitalogic/pricing/sync/apply`

An accepted apply is observed or cancelled through the same unversioned Living
surface:

- `GET /wp-json/digitalogic/pricing/sync/jobs/{request_id-or-job_id}`
- `DELETE /wp-json/digitalogic/pricing/sync/jobs/{request_id-or-job_id}`

There is one Living remote surface. No versioned or deprecated pricing-sync
alias is registered; clients must use `/pricing/sync/*`.

The installed Patris provider adapter accepts only the existing
`X-Patris-Product-Sync-Secret`. The secret must have a non-empty exact
`{id, dataset}` source scope, and every request repeats that identity. A
syntactically valid `sha256:` revision is optional when the provider does not
advertise revision capability. When present, the revision remains bound
to preview, idempotency, settings metadata, and audit records. A local revision
that differs from the materialized WordPress product-sync revision is visible
as a non-blocking Persian warning; it is not an authentication failure. There
is no WordPress-session, WooCommerce-key, or administrator-capability fallback
on this machine surface.

The earlier workbook “credentials missing” error was expected fail-closed
behavior: the workbook intentionally had no secret, and the trusted companion
runtime either was bypassed or did not have its server-side product-sync secret
available. The corrected boundary is:

1. VBA calls the loopback Patris `/api/pricing-sync` adapter without a WordPress secret.
2. The companion reads the secret from protected runtime configuration.
3. The companion calls `/wp-json/digitalogic/pricing/sync/*` with
   `X-Patris-Product-Sync-Secret` and the exact configured `{id,dataset}` scope.

The secret never enters the workbook, a cell, VBA, an audit row, or logs.

## Additive immutable projection snapshots

The WordPress side also provides an optional revision/snapshot transport for a
large reconciled catalog. It computes the complete WooCommerce/Patris union
once per exact composite revision, then serves immutable bulk or 250-row pages
with ETags, progress, cancellation, and fast `429`/`503` responses. This does
not replace or change the three mutation routes above.

The Patris companion must translate this remote contract into its local
workbook schema; VBA must still never receive the remote credential. Until that
separate adapter release is installed and proven, the companion may retain its
existing paged state fallback. See [Pricing projection snapshot
API](PRICING-SNAPSHOT-API.md) for the exact routes, revision meanings,
idempotency, integrity fields, and adapter mapping requirements.

## Ownership

- Patris `kala` owns product code, foreign price, weight, stock, and other
  upstream product facts.
- WooCommerce owns public product identity, publication state, URL, and the
  persisted storefront record.
- The pricing coordinator owns USD/CNY rates, their effective dates, the one
  shared profit margin, the shared final-price rounding policy, and the derived
  selling price.
- Excel and Google Sheets are synchronized view/edit interfaces, not competing
  authorities.
- The `source` envelope identifies the exact component/dataset and revision for
  replay protection; it does not express source precedence.
- Final product prices are derived and never accepted as direct workbook
  settings. For each managed simple product or exact-code variation,
  `_regular_price == _price == canonical selling price` and `_sale_price` is
  empty.

## Request envelope

The descriptive Living request schema is `digitalogic.pricing-sync-request`.
`operation` matches the route name, and canonical `source` contains `id`,
`dataset`, and an optional `sha256:` revision. Unknown bounded provider
metadata and object-key order are tolerated. Versioned route/schema selectors
are not registered as a second dialect.

State accepts optional `page` and `limit`; the maximum page size is 250.
Catalog locale is always Persian. `fa` and `fa_IR` are accepted as request
locale values.

```json
{
  "schema": "digitalogic.pricing-sync-request",
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
  "schema": "digitalogic.pricing-sync-state",
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
    "price_rounding_digits": 2,
    "price_rounding_mode": "nearest_half_up",
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
  "price_rounding": {
    "configured": true,
    "rounding_digits": 2,
    "rounding_mode": "nearest_half_up",
    "revision": "sha256:PRICE_ROUNDING_REVISION"
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

Preview requires the complete eleven-field settings document shown below. Rates
are positive integer IRT values, dates are strict Gregorian `YYYY-MM-DD`,
`effective_date` equals `cny_effective_date`, and
`profit_margin_percent` is a base-10 percentage from 0 through 1000. The
air-express price, currency, and shipping-catalog revision are submitted
together. `price_rounding_digits` is an integer from 0 through 9 and
`price_rounding_mode` is exactly `nearest_half_up`; rounding is applied once,
after markup, to a quantum of `10^price_rounding_digits` IRT.

The `Idempotency-Key` header must exactly equal body `idempotency_key`.
`If-Match` must contain the quoted body revision, for example
`"sha256:..."`.

```json
{
  "schema": "digitalogic.pricing-sync-request",
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
    "price_rounding_digits": 2,
    "price_rounding_mode": "nearest_half_up",
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

Only `profit_margin_percent` is part of the active request and response
contract. The removed `default_profit_percent` and `default_markup` dialect is
not accepted or emitted.

For compatibility, a legacy client may omit both rounding fields and inherit
the current site policy. Supplying only one of them is rejected. New clients
must always submit both fields so preview and apply are bound to the same
explicit rounding policy.

## Apply

Apply repeats the exact source, expected revision, and settings used by the
preview. It adds the unexpired preview digest and the explicit confirmation
string `APPLY`.

```json
{
  "schema": "digitalogic.pricing-sync-request",
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
    "price_rounding_digits": 2,
    "price_rounding_mode": "nearest_half_up",
    "shipping_catalog_revision": "sha256:SHIPPING_CATALOG_REVISION"
  },
  "product_changes": [],
  "preview_digest": "sha256:PREVIEW_DIGEST",
  "confirmation": "APPLY"
}
```

Apply first persists a durable job and then returns HTTP `202 Accepted`; it does
not update settings or WooCommerce products in the request process. The
response has `Location`, `Retry-After`, and `Cache-Control: no-store` headers.
Its body includes the stable `job_id`, original `request_id`, status and phase,
bounded progress, and the same `status_url`/`cancel_url`:

```json
{
  "schema": "digitalogic.pricing-apply-job",
  "job_id": "job_0123456789abcdef0123456789abcdef",
  "request_id": "excel-apply-20260726-0001",
  "status": "queued",
  "phase": "settings",
  "terminal": false,
  "progress": {
    "total_products": 757,
    "processed_products": 0,
    "rollback_products": 0,
    "completed_percent": 0
  },
  "status_url": "/wp-json/digitalogic/pricing/sync/jobs/excel-apply-20260726-0001?source_id=configured-source-id&source_dataset=configured-dataset&source_revision=sha256%3ACURRENT_SOURCE_REVISION",
  "cancel_url": "/wp-json/digitalogic/pricing/sync/jobs/excel-apply-20260726-0001?source_id=configured-source-id&source_dataset=configured-dataset&source_revision=sha256%3ACURRENT_SOURCE_REVISION"
}
```

The worker commits settings in one short transaction with an exact durable
receipt, then reprices deterministic batches of at most 25 Product Codes. Each
batch has its own receipt and exact readback before the cursor advances. The
admitted Product Codes, receipts, results, and acknowledgement consumer are
bound to the authenticated source ID and dataset; another configured source is
neither actuated nor exposed. Stores that do not advertise the optional bounded
job capability fail admission before any effect.

After forward readback, the exact configured consumer receives the existing
pricing confirmation transaction. The job remains nonterminal with
`status`/`phase` equal to `awaiting_ack`; it does not report `completed` and no
terminal event is emitted yet. The matching `POST /pricing/sync/ack` durably
resumes that same job and only then permits `completed`. If the 90-second ACK
deadline expires, the confirmation timeout signals the job instead of running a
catalog-wide rollback. The same receipt-backed settings and at-most-25-code
phases restore and verify every processed effect before `rolled_back` is
published.

A failure or cancellation after an effect starts likewise enters finite compensation;
settings and every processed Product Code are restored and verified before a
`rolled_back` or `cancelled` result is published. A killed worker is recovered
by independent Action Scheduler and WP-Cron wakes. Unresolved outcomes fail
closed with `outcome_unknown` and require authoritative readback before another
fresh apply can be admitted.

Preview response data uses:

```json
{
  "schema": "digitalogic.pricing-sync-preview",
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

The exact `request_id` must equal `Idempotency-Key`. An apply job and its replay
record are retained for seven days. Repeating the identical POST returns the
same job before any live source, state, or preview read; this is the recovery
path after an unknown or lost HTTP response, even if the preview has since
expired. Never invent a new request identity for that retry. Reusing the
identity with different settings, source, preview binding, or expected revision
is a `409` conflict. A fresh request with a stale settings revision is `412`.

Poll the returned URL with the same scoped secret and source query until HTTP
`200` and `terminal: true`. Pending status is HTTP `202`. Only a completed job
contains the established apply response in `result`, after exact settings and
storefront readback, the Excel acknowledgement, and terminal-event persistence
have all succeeded. While `awaiting_ack`, the confirmation identity is visible
in job status but `result` remains absent. `DELETE` is cooperative: a pristine queued job remains
effect-free, while an in-progress job compensates before becoming terminal.

Every terminal outcome is durably recorded as `pricing.apply.terminal` in the
existing notification/event system with the same job, request, source, and
event identities. Even `outcome_unknown` retains a pending terminal event until
durable storage acknowledges it. Provider revision stays optional; source ID and dataset are
always exact, and a submitted revision is bound when the provider advertises
that capability.

That readback succeeds only when every managed simple product or exact-code
variation has an empty WooCommerce sale field and identical canonical,
regular, and effective selling prices. Unsupported variable-parent or shipping
states fail closed instead of retaining a stale customer price.

## Security and logging

The companion injects the product-sync secret from its protected runtime
configuration. The workbook receives neither the secret nor an authenticated
WordPress cookie. Authorization headers and secrets must not be logged. Audit
and job records contain source identity, request/job identity, preview digest,
revisions, phase receipts, and applied nonsecret pricing settings only.
