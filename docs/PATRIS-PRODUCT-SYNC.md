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

`category_code`, `name`, `serial`, `unit`, `sale_price_source`, `purchase_price_source`, `warehouse_stock`, `total_stock`, `minimum_stock`, `foreign_currency`, `foreign_price`, `price_source_amount`, `price_source_currency`, `price_source_kind`, `weight_grams`, `location`, `shipping_method_id`, `shipping_price_per_kg`, `shipping_price_per_kg_currency`, `markup_percent`, `irt_per_cny`, `price_rounding_digits`, `price_rounding_mode`, `pricing_catalog_revision`, `pricing_catalog_status`, `currency_effective_date`, `final_price`, `source_updated_at`, and `warnings`.

`shipping_price_per_kg` and `shipping_price_per_kg_currency` are a required key pair whenever either is present. A non-null currency is uppercase `CNY` or `IRR`. The two present values independently preserve explicit source nulls, so a numeric amount with null currency and a null amount with `CNY` or `IRR` are both valid representations. Either null makes calculation incomplete and therefore requires `final_price` to be omitted.

`price_source_amount`, `price_source_currency`, and `price_source_kind` are an
atomic selected-source triple: all three are present or all three are omitted.
The selected fields are derived provenance and are never null. A positive CNY
`foreign_price` has priority and selects `{CNY,foreign_price}`. Only when that
source is unusable may a positive `sale_price_source` (`FOROSH`, the partner
price in IRR) select `{IRR,partner_price}`. Raw missing, explicit-null, zero,
and negative facts remain distinct even though none is selectable.

With pricing active, `price_rounding_digits` is an integer from 0 through 9 and
`price_rounding_mode` is exactly `nearest_half_up`. An explicit source/config
null for the digit count remains `price_rounding_digits: null`; in that state
the mode and `final_price` are omitted.

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
