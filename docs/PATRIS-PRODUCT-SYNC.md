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

`category_code`, `name`, `serial`, `unit`, `sale_price_source`, `purchase_price_source`, `warehouse_stock`, `total_stock`, `minimum_stock`, `foreign_currency`, `foreign_price`, `weight_grams`, `location`, `shipping_method_id`, `shipping_price_per_kg_cny`, `markup_percent`, `irt_per_cny`, `pricing_catalog_revision`, `pricing_catalog_status`, `currency_effective_date`, `final_price`, `source_updated_at`, and `warnings`.

A category requires `category_code`, `name`, `parent_code`, `depth`, `warnings`, and `record_hash`. `name` accepts a string or explicit null. `parent_code` and `depth` are derived non-null values; root `parent_code` is the empty string.

## Identity rules

Product and category record hashes are SHA-256 hashes of Go-compatible JSON after removing `record_hash` and sorting object keys lexicographically. `warehouse_stock` keys are also sorted.

Event identity includes `schema`, `event_type`, `local_currency`, `formula_id`, `source`, `generated_at`, sorted product hashes, category hashes, `excluded_codes`, and `quarantined_codes`. The three catalog arrays are included even when empty. Non-empty `deleted_codes` is included; warnings are not. When pricing context is absent, `local_currency` and `formula_id` are hashed as empty strings.

## Pricing

`landed_price` uses exact bounded decimals and rounds half up once to an integer IRT final price:

```text
landed CNY = foreign_price + (weight_grams / 1000 × shipping_price_per_kg_cny)
final IRT  = landed CNY × (1 + markup_percent / 100) × irt_per_cny
```

## Catalog and assignments

The public catalog is exactly:

```text
{schema, revision, currency, pricing, selected_warehouses, shipping_methods}
```

`schema` is `digitalogic.integration-catalog`; `pricing.formula_id` is `landed_price`. Currency fields are sparse and never filled with null placeholders. Shipping methods use only `shipping_method_id` and `shipping_price_per_kg_cny` terminology.

The batch response is exactly:

```text
{schema, requested_count, resolved_count, error_count, maximum_codes,
 default_percentage_markup, results}
```

An assignment contains only optional `shipping_method_id`, optional `profit_percent`, required `profit_percent_source`, and required `pricing_warnings`.
