# Supplier Shipping Method Integration Contract

Digitalogic supplier shipping methods describe how inventory is brought from a
supplier to Digitalogic. They are not WooCommerce shipping zones, checkout
rates, or customer delivery methods.

## Canonical methods and migration

The first migration reads the existing ACF option values and creates immutable
IDs:

| Legacy ACF value | Canonical ID | Default live rate |
| --- | --- | ---: |
| `express` | `air_express` | 85 CNY/kg |
| `aerial` | `air_freight` | 80 CNY/kg |
| `marine` | `sea_freight` | 50 CNY/kg |

Existing product values in `shipping_method` and
`_digitalogic_import_freight_method_id` are copied to
`_digitalogic_shipping_method_id`. The `_shipping_method` ACF field-key
reference is preserved. During the compatibility window, canonical assignments
dual-write the former keys so a plugin rollback remains safe.

The ACF radio choices are populated from the canonical catalog. The three
legacy methods keep their existing `express`, `aerial`, and `marine` values;
custom methods use their canonical ID as the radio value. Disabled choices
remain visible for existing products but cannot be selected for a new
assignment. The three legacy-seeded methods are disable-only so those field
values and historical assignments cannot be orphaned.

The old free-form `shipping_methods` member of
`digitalogic_patris_feed_settings` is deprecated and ignored.

## REST endpoints

All routes require an authenticated identity with the plugin's read or write
integration permission (normally `manage_woocommerce`). The catalog and bounded
pricing-assignment batch additionally accept the dedicated header-only Patris
pricing-input credential documented in
[PRICING-INPUT-CREDENTIAL.md](PRICING-INPUT-CREDENTIAL.md). That machine identity
is confined to those two exact route/method pairs.

- `GET /wp-json/digitalogic/v1/integration/catalog`
- `GET|PUT /wp-json/digitalogic/v1/pricing/default-markup`
- `GET|POST /wp-json/digitalogic/v1/shipping-methods`
- `GET|PUT|DELETE /wp-json/digitalogic/v1/shipping-methods/{id}`
- `GET|PUT /wp-json/digitalogic/v1/products/by-code/{code}/shipping-method`
- `POST /wp-json/digitalogic/v1/pricing-assignments/batch` (read only)
- `POST /wp-json/digitalogic/v1/products/shipping-methods/batch`

The former import-freight routes remain explicit deprecated aliases. They add
the `Deprecation: true` response header, accept old field names at input only,
and still emit canonical shipping-method response keys.

Method records use `shipping_price_per_kg_cny` at every public transport
boundary, including tier rows. The former `price_per_kg_cny` input is accepted
temporarily with a machine-readable deprecation notice; conflicting old and new
values are rejected. Stable IDs such as `air_freight` and `sea_freight` are
retained because IDs are immutable data identifiers, not display terminology.

Method IDs are immutable. An assigned method returns HTTP 409 when deleted; it
may be disabled with `{"enabled": false}` and existing assignments remain
readable. Disabled methods cannot be assigned to new products, while replaying
the same disabled assignment is an allowed idempotent no-op. Custom IDs cannot
use the legacy ACF values `express`, `aerial`, or `marine`.

Product assignment uses the shared exact identifier resolver. Explicit
WooCommerce ID, SKU, and Patris Code inputs retain their named namespaces. The
generic `{code}` route treats exact `_digitalogic_patris_product_code` as
canonical and uses exact `_sku` only when no Patris Code exists; if the same
text names distinct SKU and Patris Code targets, resolution is ambiguous and
no write occurs. Identifiers remain strings, so a code such as `00123` is never
coerced to an integer. Missing and same-source duplicate identifiers fail
without a write; names are never used for automatic matching. Trash and
auto-draft records are excluded.

### Versioned batch assignment reads

Patris and other read-only pricing clients should prefetch with
`POST /wp-json/digitalogic/v1/pricing-assignments/batch`:

```json
{"codes":["113007045","113007046"]}
```

The operation accepts an ordered JSON list of 1-500 unique non-empty Codes;
associative objects are rejected rather than having their keys discarded. Codes remain strings,
retain trimmed request order, and are resolved by the same collision-safe
service as the single-Code endpoint. Duplicate Codes reject the whole request;
not-found or ambiguous products become typed per-Code results so unrelated
Codes still resolve. The response is
`digitalogic.pricing-assignment-batch` version `1.0.0`, includes the exact
default-markup snapshot read once for the request, and emits effective
percentage values as canonical base-10 strings. Each success is a deliberately
minimal machine projection: requested Code, method ID, effective percentage,
percentage source, and pricing warnings. No internal WooCommerce identity or
localized error message is exposed. No product, price, assignment, option,
event, or lock is written, and this read never invokes migration; activation or
upgrade must complete migration separately.

```json
{
  "schema": "digitalogic.pricing-assignment-batch",
  "schema_version": "1.0.0",
  "requested_count": 2,
  "resolved_count": 1,
  "error_count": 1,
  "maximum_codes": 500,
  "default_percentage_markup": {"configured": true, "profit_percent": "30"},
  "results": [
    {
      "code": "113007045",
      "status": "ok",
      "assignment": {
        "code": "113007045",
        "shipping_method_id": "air_express",
        "profit_percent": "30",
        "profit_percent_source": "global_default",
        "pricing_warnings": []
      }
    },
    {
      "code": "MISSING",
      "status": "error",
      "error": {
        "code": "digitalogic_product_code_not_found",
        "http_status": 404,
        "retryable": false
      }
    }
  ]
}
```

This POST is a read operation. A human identity with `manage_woocommerce` keeps
normal access. Patris uses the same dedicated Bearer credential as
`GET /integration/catalog`; the verifier itself enforces both exact route and
method tuples. It deliberately does not accept the broad read filter, a
WooCommerce Basic credential as the Patris machine identity, the Patris push
token, or the transformed product-sync write secret.

The same service operation is exposed as the
`digitalogic_get_product_import_pricing_batch` command and through WP-CLI:

```bash
wp digitalogic pricing assignments 113007045 113007046
```

Example assignment:

```json
{
  "shipping_method_id": "air_express"
}
```

The method field is required on assignment commands. An explicitly empty or
`null` method ID clears the assignment; omitting the field is an error. A batch is preflighted and
does not change any product when one of its rows is invalid:

```json
{
  "assignments": [
    {"code": "113007045", "shipping_method_id": "air_express"},
    {"code": "113007046", "shipping_method_id": "sea_freight"}
  ]
}
```

Single and batch responses include `changed`; batch responses also include
`updated` and `unchanged`. Retrying an identical assignment is a no-op and does
not publish a duplicate domain event. A batch also rejects two different codes
that resolve to the same product, such as its SKU and Patris Code.

Catalog and assignment mutations are serialized with one site-scoped database
advisory lock. Every option and post-meta write is verified by exact readback.
Multi-key and multi-row writes use an InnoDB database transaction, so a failed
write is restored by the authoritative database `ROLLBACK` and emits no domain
event. The application performs no post-rollback snapshot rewrites that could
overwrite a newer writer. Touched caches are invalidated after rollback, and a
failed `ROLLBACK` query is returned as a storage error. Migration is stamped
only after the catalog and every legacy assignment have been verified.

Transactional reads and writes bypass the shared WordPress/Redis object cache.
Touched caches and compatibility hooks are invalidated/dispatched only after
commit, and are invalidated after rollback as well. Post-write legacy ACF/option
hooks reconcile with uncached `FOR UPDATE` reads and compare-and-swap behavior,
so a stale hook never restores over a newer writer.

Committed method and assignment changes use independent, result-aware delivery
channels for the durable panel queue, Redis/WebSocket publication, and every
configured outbound webhook. Each channel must explicitly confirm success;
void results, queue/Redis failures, webhook transport/non-2xx failures, and
exceptions become machine-readable `delivery_warnings`. Every channel and every
webhook destination is attempted even when an earlier one fails. The stable
WordPress domain actions are still emitted for compatibility after result-aware
channels run. A committed mutation remains successful, while no-op retries do
not emit another event. An empty webhook destination list is an intentionally
disabled, confirmed-success channel.

The same delivery contract applies to `shipping_method.default_markup.updated`.
Updating or clearing the default changes only the canonical catalog option; it
does not recalculate or write any WooCommerce product price.

## Catalog and formula

The read-only integration catalog contains the CNY-to-local (currently IRT)
rate, effective currency date, selected Patris warehouses, enabled/disabled
method records, WooCommerce base-currency compatibility, and a deterministic
revision. Consumers can cache by revision. Catalog schema `1.2.0` remains
compatible with major-version-1 consumers.

`currency.cny_to_local` is the currency-neutral rate. The compatibility field
`currency.cny_to_irt` is populated only when the WooCommerce local currency is
IRT. `currency.effective_date` is ISO `YYYY-MM-DD`; the original compact value
is retained as `source_effective_date` with format `ymd`.

`currency.woocommerce_base`, `currency.pricing_output`, and
`currency.compatibility` make the unit boundary explicit. IRT is Toman in this
contract (`1 IRT = 1 Toman = 10 IRR`). IRR is not accepted as an alias. A
non-IRT WooCommerce base sets `cny_to_irt` to `null` and adds
`woocommerce_base_currency_must_be_irt`; the plugin observes this state but
never changes WooCommerce's setting. See
[WooCommerce base-currency status](WOOCOMMERCE-CURRENCY-STATUS.md).

`landed_price_v1` is:

```text
((weight_g * shipping_price_per_kg_cny / 1000) + foreign_price_cny)
  * (1 + profit_percent / 100)
  * cny_to_irt
```

The reference values `24.5 CNY`, `240 g`, `120 CNY/kg`, `30%`, and
`29,000 IRT/CNY` produce exactly `2,009,410 IRT` after one final half-up round.

The catalog exposes the nullable default at
`pricing.default_percentage_markup`:

```json
{
  "schema": "digitalogic.default-percentage-markup",
  "schema_version": "1.0.0",
  "configured": true,
  "type": "percentage",
  "profit_percent": "30",
  "source": "global_default",
  "revision": "sha256:...",
  "bounds": {
    "minimum": "0",
    "maximum": "1000",
    "maximum_fraction_digits": 12
  },
  "warnings": []
}
```

The stored percentage is a canonical base-10 string: no exponent or grouping
notation, no redundant leading/trailing zeroes, and at most 12 fractional
digits. JSON clients should send a string to preserve every decimal digit:

```json
{"profit_percent": "30.125"}
```

Use `{"profit_percent": null}` to clear it. JSON `null` is the only destructive
API/command value: a blank or whitespace-only value is invalid with HTTP 400,
and the administrator UI provides a separate clear action. The absence of the
option is the only unset state; there is no hard-coded fallback. The workbook's current 30%
is a proposed reviewed production action, not an automatic migration or seed.
The setting revision covers its configured state and exact decimal. The parent
integration-catalog revision includes the full setting metadata, so Patris can
invalidate caches deterministically.

The method schema reserves validated fields for minimum charge, actual versus
volumetric billable weight, volumetric divisor, transit-day bounds, metadata,
and tiered rates. Version 1 exposes these fields but does not silently apply a
reserved rule to the formula.

Patris payloads express product weight in grams. When a feed updates a
WooCommerce product, Digitalogic converts that positive finite gram value with
`wc_get_weight()` into the configured `woocommerce_weight_unit` (for example,
`240 g` remains `240` in a gram store and becomes `0.24` in a kilogram store).
The original gram value remains available in Patris metadata for formula input.

Feed ingestion uses the same shared generic Code/SKU resolver as shipping-method
assignment. A Patris-Code-only product can therefore be updated, SKU is a
compatibility fallback only when that Code is absent, and cross-namespace
collisions fail as ambiguous. Not-found rows are counted as
`missing_in_woocommerce`; ambiguous or invalid identifiers are counted as
failed with non-sensitive errors and never write a product. Every valid
normalized upstream row is still retained in the feed snapshot for reporting
and later reconciliation, including missing or ambiguous rows.

Product assignment reads expose the markup mapping used by the formula:

```json
{
  "markup": {
    "type": "percentage",
    "value": 12.5,
    "profit_percent": 12.5,
    "source": "product_override",
    "default_revision": null,
    "warning": null
  },
  "profit_percent": 12.5,
  "profit_percent_source": "product_override",
  "pricing_warnings": []
}
```

Only a valid product `percentage` maps directly to `profit_percent`, with
`source: product_override`. Product markup is semantically unconfigured when
both metadata rows are absent, or when both rows exist and both values are
empty. In either state, the configured default is returned as an exact decimal
string with `source: global_default` and its setting revision. Treating paired
stored-empty rows this way preserves the current live product data contract.

One-sided metadata rows never fall back. Empty one-sided rows report
`markup_metadata_value_absent` or `markup_metadata_type_absent`; malformed raw
types report `markup_type_malformed`. Fixed, unsupported, nonempty malformed,
or incomplete percentage states likewise return `profit_percent: null` and a
machine-readable warning instead of treating a fixed amount as a percent. If
the product is semantically unconfigured and the global default is absent, the
existing `markup_missing` warning remains.

Administrators can manage the nullable default, methods, and assignments by exact Patris Code/SKU
on the existing Patris Reports screen. This UI and all integration routes are
for supplier shipping methods only and do not read or mutate WooCommerce customer
delivery methods.

The same `Digitalogic_Shipping_Method_Service` backs REST and the shared command
dispatcher used by AJAX/WebSocket transports. The former class name is retained
as a PHP compatibility alias rather than a parallel implementation.
