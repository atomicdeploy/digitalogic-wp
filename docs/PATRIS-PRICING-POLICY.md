# Managed storefront pricing policy

Digitalogic has one customer-visible selling price for every managed simple
product or exact-code variation:

- **Canonical Patris final price** is stored in
  `_digitalogic_patris_final_price` and records the coordinator's reviewed
  source calculation.
- **Selected source provenance** stores the exact amount, `CNY` or `IRR`
  currency, and `foreign_price`, `partner_price`, or `sale_price_direct` kind
  used by that calculation. A complete CNY freight route wins. The distinct
  `partner_price_source` fact is the margin-bearing IRR fallback. The final
  `sale_price_direct` fallback reads `sale_price_source`/`FOROSH` unchanged
  except for IRR-to-IRT unit conversion; its producer configuration
  `use_sale_price_direct_fallback` is disabled by default.
- WooCommerce `_regular_price` equals that canonical result.
- WooCommerce `_price` equals `_regular_price`.
- WooCommerce `_sale_price` is empty.
- Therefore the effective storefront price is always the same canonical price
  for each managed simple product or exact-code variation.

The fixed policy identifier is `canonical_sale`. The historical
`preserve_sale` and `replace_sale` settings are ignored and cannot change this
invariant. The CLI command is read-only:

```text
wp digitalogic pricing policy
```

Passing `--set` fails without changing state. Variable parents remain
fail-closed: their storefront price is derived by WooCommerce from exact
variations, and the coordinator never copies a parent lookup price into a
canonical product row.

Inspect a bounded page without mutation:

```text
wp digitalogic pricing audit --limit=100 --page=1 --format=table
```

The audit reports canonical, regular, sale, and effective values separately,
along with the price source, active policy, and review status. The product panel
uses the same explicit projection and exposes effective price, policy status,
and promotion policy as read-only columns.

A managed simple product or variation matches only when canonical, regular,
and effective values are equal and the sale field is empty. Missing or invalid
formula inputs clear all three WooCommerce price fields so an old customer
price cannot survive.

The global rounding digit count is managed in **Digitalogic → Patris Reports**.
It accepts 0–9 and rounds once, after markup, to the nearest
`10 ^ digits` IRT using `nearest_half_up`. Changing the configuration alone
does not rewrite WooCommerce prices; the next reviewed source calculation and
sync carries the new value and its provenance.
