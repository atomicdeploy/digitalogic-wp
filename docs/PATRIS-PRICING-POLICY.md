# Managed storefront pricing policy

Digitalogic has one customer-visible selling price for every managed simple
product or exact-code variation:

- `_digitalogic_patris_final_price` records the coordinator's canonical result.
- WooCommerce `_regular_price` equals that canonical result.
- WooCommerce `_price` equals `_regular_price`.
- WooCommerce `_sale_price` is empty.

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

The audit reports canonical, regular, sale, and effective values separately.
A managed simple product or variation matches only when canonical, regular,
and effective values are equal and the sale field is empty. Missing or invalid
formula inputs clear all three WooCommerce price fields so an old customer
price cannot survive.
