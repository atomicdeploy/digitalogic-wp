# Patris Product Sync Living Contract

Digitalogic and Patris Export use one living contract. Because both ends are deployed together, contract changes replace the current shape instead of adding compatibility branches.

## Endpoints

- `POST /wp-json/digitalogic/patris/product-sync`
- `GET /wp-json/digitalogic/integration/catalog`
- `POST /wp-json/digitalogic/integration/pricing-assignments/batch`
- `GET /wp-json/digitalogic/integration/products/by-code/{code}/pricing`

The product-sync request uses `X-Patris-Product-Sync-Secret`. It may be restricted to exact `{id,dataset}` source pairs.

Product delivery and pricing-assignment lookups match the case-sensitive
`_digitalogic_patris_product_code` value only. A WooCommerce SKU is never used
as a fallback for Patris integration traffic.

## Sparse null semantics

- Omit a key when Patris has no source or reference data for it.
- Send `null` only when the source explicitly contains null.
- Preserve explicit empty strings, arrays, and objects.
- `final_price` is never null: emit it only when all inputs are available.

The receiver stores the distinction between missing and explicit-null fields and clears stale WooCommerce operational values for both cases.

## Automatic WooCommerce materialization

Every exact, identity-safe source product is materialized during normal
receiver delivery, even when price, stock, weight, freight, markup, image, or
SEO data is missing. The receiver creates at most 25 products per request and
keeps the remainder as retryable pending work for the next event or
`wp digitalogic product-sync reconcile` run.

The automatic path creates only a simple leaf with the exact source name, or the
exact Code when the source name is absent, and exact Code/SKU. It does not infer
taxonomy, a variable parent, Persian copy, an image, or SEO content. It then
runs the canonical Patris feed writer, verifies the record hash and source
ownership, and makes the product public and visible. If canonical price is
missing or nonpositive, WooCommerce price fields remain blank and the product
is out of stock and non-purchasable. Missing stock also resolves conservatively
to out of stock instead of preserving stale inventory. A later complete record
updates that same product ID and normal pricing/stock behavior resumes without
creating a duplicate.

Upgrades with older public products can backfill the exact source owner,
per-product source revision, sorted missing-field snapshot, and canonical feed
projection without placing hundreds of writes inside one atomic currency job:

```bash
wp digitalogic product-sync reconcile \
  --source-id=<id> --dataset=<dataset> \
  --materialize-current --limit=25 \
  --user=<administrator>
```

Repeat the bounded command until `materialization_queued` is zero. It reuses the
normal resolver, receiver lock, delivery ledger, and exact readback. For an
already-current legacy feed, the same canonical staging logic is compared with
fresh database state while both the source and product locks are held. Only the
five exact ownership, revision, and missing-field metadata rows are then
backfilled, without a WooCommerce object save. Any mismatch or unavailable
proof falls back to the normal full Patris feed writer and materializer. It
never invents a price. A legacy row with no selected-price triple may receive
the canonical `air_express` supplier assignment only when its exact raw facts
are positive CNY and the existing site assignment is empty; the write uses
compare-and-set and a conflicting assignment fails closed. A later canonical
currency reconcile selects that raw CNY fact only after exact identity and
assignment readback and only with positive weight; the normal freight, markup,
exchange-rate, and rounding formula remains authoritative.

Only concrete identity/corruption hazards remain deferred: duplicate or split
Code/SKU ownership, quarantined or unsafe identity, and conflicting variable
leaf/parent ownership. Resolver availability, database, lock, and write errors
remain pending and retryable. Deployments upgrading from the former behavior
must run bounded reconciliation until safe legacy `missing` entries and pending
rows are zero; identity hazards require manual ownership repair.

Legacy simple parents that already contain variation children are not repaired
by normal reconciliation. `Digitalogic_Patris_Topology_Repair` accepts only an
explicit reviewed plan pinned to the current source revision and complete raw
parent/child maps. Its default mode is a no-write dry-run. Apply holds the shared
source lock plus every existing product lock, performs one database transaction,
and proves the final blank-container/leaf ownership before commit. A failed or
uncertain commit requires exact audit and cannot be retried blindly. After a
successful topology transaction, normal bounded reconciliation writes and
publishes the newly created source leaf through the canonical feed.

For canonical unpriced products, Rank Math price fields and schema `offers` are
omitted rather than emitting a zero placeholder. Product title and availability
remain present, and priced products retain normal social/schema price metadata.

After a verified product commit and lock release, the receiver emits
`digitalogic_patris_materializer_product_committed`. Its snapshot exposes only
the product/source identity, revision, visibility, purchasability, price status,
and the sorted canonical missing-field names `price`, `stock`, `weight`,
`freight`, `markup`, `image`, and `seo`. Alert-listener failures cannot block or
roll back product creation. Once a nonempty request-local commit batch has been
dispatched, the receiver emits the no-payload
`digitalogic_patris_materializer_product_commits_complete` action, also outside
all source locks, so alert adapters can perform one durable batch flush.

## Product-sync envelope

The envelope contains:

```json
{
  "schema": "patris.product-sync",
  "event_type": "snapshot",
  "event_id": "sha256:...",
  "local_currency": "IRT",
  "formula_id": "landed_price",
  "source": {
    "id": "patris-export",
    "dataset": "ALLANBAR",
    "revision": "sha256:..."
  },
  "generated_at": "2026-07-20T12:00:00Z",
  "products": [],
  "categories": [],
  "excluded_codes": [],
  "quarantined_codes": [],
  "warnings": []
}
```

`products`, `categories`, `excluded_codes`, `quarantined_codes`, and `warnings` are required arrays, including when empty. `deleted_codes` is optional and is valid only for update events. `local_currency` and `formula_id` are either both present or both absent. Product pricing fields are permitted only when they are present.

A product requires `product_code`, `warnings`, and `record_hash`. Its optional sparse superset is:

`category_code`, `name`, `serial`, `unit`, `sale_price_source`, `partner_price_source`, `purchase_price_source`, `warehouse_stock`, `total_stock`, `minimum_stock`, `foreign_currency`, `foreign_price`, `price_source_amount`, `price_source_currency`, `price_source_kind`, `weight_grams`, `location`, `shipping_method_id`, `shipping_price_per_kg`, `shipping_price_per_kg_currency`, `markup_percent`, `irt_per_cny`, `price_rounding_digits`, `price_rounding_mode`, `pricing_catalog_revision`, `pricing_catalog_status`, `currency_effective_date`, `final_price`, `source_updated_at`, and `warnings`.

`shipping_price_per_kg` and `shipping_price_per_kg_currency` are a required key pair whenever either is present. A non-null currency is uppercase `CNY` or `IRR`. The two present values independently preserve explicit source nulls, so a numeric amount with null currency and a null amount with `CNY` or `IRR` are both valid representations. Either null makes calculation incomplete and therefore requires `final_price` to be omitted.

`price_source_amount`, `price_source_currency`, and `price_source_kind` are an
atomic selected-source triple: all three are present or all three are omitted.
The selected fields are derived provenance and are never null. A positive CNY
`foreign_price` selects `{CNY,foreign_price}` only when strictly positive source
weight and a usable non-domestic supplier method are both available. A raw zero
weight remains representable but cannot select the foreign freight route. Otherwise
the distinct positive `partner_price_source` fact selects
`{IRR,partner_price}` and carries the canonical `domestic` method with a zero
IRR rate. The last `{IRR,sale_price_direct}` route reads
`sale_price_source`/`FOROSH`; it is produced only when the explicitly opt-in
`use_sale_price_direct_fallback` setting is enabled and the preceding routes
cannot calculate. Raw missing, explicit-null, zero, and negative facts remain
distinct even though none is selectable.

With pricing active, foreign and partner routes carry
`price_rounding_digits` from 0 through 9 and
`price_rounding_mode=nearest_half_up`. An explicit source/config null for the
digit count remains `price_rounding_digits: null`; in that state the mode and
`final_price` are omitted. A direct-sale row omits rounding, markup, freight FX,
and CNY FX metadata because it does not consume them. A selected direct-sale
row carrying `markup_percent`, `price_rounding_digits`,
`price_rounding_mode`, or `irt_per_cny` is rejected rather than silently
ignoring inputs that cannot affect its final price.

A category requires `category_code`, `name`, `parent_code`, `depth`, `warnings`, and `record_hash`. `name` accepts a string or explicit null. `parent_code` and `depth` are derived non-null values; root `parent_code` is the empty string.

## Identity rules

Product and category record hashes are SHA-256 hashes of Go-compatible JSON after removing `record_hash` and sorting object keys lexicographically. `warehouse_stock` keys are also sorted.

Event identity includes `schema`, `event_type`, `source`, `generated_at`, sorted product hashes, category hashes, `excluded_codes`, and `quarantined_codes`. The three catalog arrays are included even when empty. Non-empty `deleted_codes` is included; warnings are not. `local_currency` and `formula_id` participate only when pricing context is present; absent keys are not replaced by placeholders.

## Pricing

The selected CNY path uses exact bounded decimals:

```text
goods IRT = foreign_price × irt_per_cny

freight IRT when shipping_price_per_kg_currency = CNY:
  weight_grams / 1000 × shipping_price_per_kg × irt_per_cny

freight IRT when shipping_price_per_kg_currency = IRR:
  weight_grams / 1000 × shipping_price_per_kg / 10

unrounded final IRT = (goods IRT + freight IRT) × (1 + markup_percent / 100)
```

The partner-price fallback does not consume weight, freight, or the CNY exchange
rate:

```text
unrounded final IRT = (price_source_amount IRR / 10)
                    × (1 + markup_percent / 100)
```

Both paths round exactly once after markup to the nearest
`10 ^ price_rounding_digits` IRT using half-up behavior. For example, 123,456
with two rounding digits becomes 123,500.

The disabled-by-default direct-sale fallback performs no commercial
modification:

```text
final IRT = sale_price_source IRR / 10
```

It requires the canonical `domestic` supplier method, a zero
`shipping_price_per_kg`, and `shipping_price_per_kg_currency=IRR`, but that
zero-rate provenance is never added to the price.

## Catalog and assignments

The public catalog is exactly:

```text
{schema, revision, currency, pricing, selected_warehouses, shipping_methods}
```

`schema` is `digitalogic.integration-catalog`; `pricing` contains
`formula_id=landed_price`, `rounding_digits` from 0 through 9, and
`rounding_mode=nearest_half_up`. Currency fields are sparse and never filled
with null placeholders. Each shipping method contains canonical-string
`price_per_kg` and a required `currency` (`CNY` or `IRR`); configured minimums,
divisors, and tier bounds/rates use the same exact decimal-string
representation. Product assignments expose that selection through
`shipping_method_id` and, when a method is assigned, the paired flattened
shipping-price fields.

The batch response is exactly:

```text
{schema, requested_count, resolved_count, error_count, maximum_codes,
 default_percentage_markup, results}
```

An assignment contains the required exact `code` (identical to its enclosing
successful result Code), optional `shipping_method_id`, optional
`profit_percent`, required `profit_percent_source`, and required
`pricing_warnings`. The nested Code is the normalized Product Code resolved by
the existing exact Code path; it never falls back to WooCommerce SKU.
