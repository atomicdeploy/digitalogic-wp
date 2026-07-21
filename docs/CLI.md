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

## Inspect the current Patris report

The report reads the living `digitalogic_product_sync_state` projection and
matches only exact `_digitalogic_patris_product_code` metadata. It never falls
back to SKU. Warning and price-list output is paginated to at most 100 rows.

```bash
wp digitalogic patris report --view=warnings --page=1 --per-page=100
wp digitalogic patris report --view=price_list --format=json
```

For a reviewed static transformed snapshot, keep `kala.json` outside the
WordPress webroot and run the administrator-only inspection command:

```bash
wp digitalogic patris inspect \
  --file=/srv/digitalogic-private/kala.json \
  --user=<administrator> \
  --view=warnings
```

The command accepts only an absolute, readable, nonsymlinked file named
`kala.json`, rejects webroot paths and files larger than 8 MiB, validates it
with the living receiver rules, and compares it without persisting source state
or writing WooCommerce. To apply the reviewed file, use the separately named
command and its mandatory confirmation:

```bash
wp digitalogic patris ingest \
  --file=/srv/digitalogic-private/kala.json \
  --user=<administrator> \
  --yes
```

`ingest` persists the source and performs the receiver's WooCommerce writes;
without `--yes` it exits without mutation. See
[Current Patris Report](CURRENT-PATRIS-REPORT.md).

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

## Materialize reviewed Patris catalog products

The catalog materializer consumes the current validated living product-sync
state plus a strict administrator-reviewed Persian enrichment manifest. It is
a dry run unless `--apply` is present. New products and previously nonpublic
reviewed targets remain drafts unless `--publish-ready` is also present and
every readiness gate passes. An exact reviewed target that was already
published keeps that status and is reported as `preserved_published` instead of
being counted as newly published.

The current source record must carry `shipping_price_per_kg` together with an
explicit `shipping_price_per_kg_currency` of `CNY` or `IRR`; no freight currency
is inferred during materialization.

```bash
wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --user=<administrator>

wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --codes=10001,10002 \
  --apply \
  --user=<administrator>

wp digitalogic product-sync materialize \
  --manifest=/secure/reviewed-patris-catalog.json \
  --apply \
  --publish-ready \
  --user=<administrator>
```

Optional `--source-id` and `--dataset` arguments must exactly match the
manifest when supplied. `--codes` selects exact Patris Codes and `--limit`
bounds the sorted positive-stock selection. An omitted limit or exact
`--limit=0` means unlimited; every other supplied value must be a canonical
positive integer. See
[Patris Catalog Materializer](PATRIS-CATALOG-MATERIALIZER.md) for the manifest
contract, variation rules, two-phase rollout, and publication gates.
