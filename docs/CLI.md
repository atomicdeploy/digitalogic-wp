# WP-CLI product commands

Run commands with a WordPress user that has `manage_woocommerce`. Mutating
commands should use an administrator account appropriate for the environment.

## Exact selectors

Product commands accept exactly one selector:

- `--id=<id>` for a canonical positive WooCommerce product or variation ID
- `--sku=<sku>` for an exact, case-sensitive SKU
- the existing positional ID remains supported by `products update`

SKUs are never converted to integers, so values such as `001230` remain exact.
Duplicate SKUs fail with an ambiguity error rather than selecting one record.

## Read one product

```bash
wp digitalogic products get --id=123
wp digitalogic products get --sku=001230 --format=json
```

Supported formats are `table` and `json`.

## Inspect metadata

```bash
wp digitalogic products metadata --id=123
wp digitalogic products metadata --sku=001230 --format=json
```

The command shows both effective WooCommerce values (including variation
inheritance) and a whitelisted current post-meta snapshot. It compares the
derived product lookup row against WooCommerce's raw lookup-source semantics,
so inherited variation values do not create false mismatches. It reports
structured mismatches but never writes or rebuilds data.

An administrator can inspect and explicitly refresh one product's derived row
from **Digitalogic → Product Diagnostics**.

## Update one product

```bash
wp digitalogic products update 123 --price=250000 --stock=5
wp digitalogic products update --id=123 --sale-price=225000
wp digitalogic products update --sku=001230 --set-sku=001231
```

Without a positional ID, `--sku` selects the current product. Use `--set-sku`
to change its SKU. This separation prevents an update from accidentally
selecting by the replacement value.

For backward compatibility, the historical positional form
`products update 123 --sku=NEW-SKU` still treats `--sku` as the replacement
value and emits a deprecation warning. New integrations should use
`--id=123 --set-sku=NEW-SKU`.

Available update fields:

- `--price=<amount>`
- `--sale-price=<amount>`
- `--stock=<quantity>`
- `--set-sku=<sku>`
