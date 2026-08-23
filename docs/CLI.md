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

## List products

```bash
wp digitalogic products list --limit=20 --format=table
wp digitalogic products list --limit=-1 --format=json
```

The source-neutral `Product Code` field is the exact
`_digitalogic_patris_product_code` integration identifier. `SKU` is the
separate WooCommerce SKU. Both remain exact strings, including leading zeroes.
When only SKU exists, `Product Code` stays empty: product-sync reconciliation
never falls back to SKU.

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

## Repair stale product-type cache prefixes

First run the administrator-only command without `--apply`. It rebuilds the
current report and prints the exact candidates plus their factory class,
durable `product_type` taxonomy, and variation IDs. It does not accept product
IDs, so an old historical list can never become a repair input.

```bash
wp digitalogic product-type-cache repair \
  --source-id=patris-office \
  --dataset=kala.db \
  --user=<administrator>
```

After the dry-run count and source scope have been reviewed, apply a ceiling
equal to that reviewed count:

```bash
wp digitalogic product-type-cache repair \
  --source-id=patris-office \
  --dataset=kala.db \
  --apply \
  --max-candidates=15 \
  --user=<administrator>
```

The example ceiling reflects one reviewed deployment and is not a universal ID
or count. The command refuses truncated/non-current reports, an unexpected
source, unrelated integrity warnings, unsupported drift shapes, more
candidates than the ceiling, non-product posts, anything other than exactly
one durable `variable` term, and parents without a bounded variation readback.
It validates every candidate before the first cache change.

Apply removes the optional WooCommerce product-object cache entry, clears the
WordPress product term-relationship cache, and rotates only
`WC_Cache_Helper`'s `product_<id>` prefix. Clearing the term cache matters on
WooCommerce 10.8 because a fresh product-type prefix can otherwise be
repopulated from an older cached `product_type` relationship. The command does
not write a post, term relationship, product type, variation, price, or product
metadata. JSON output records before/after factory class and type, durable type,
variation IDs, exact invalidated groups, zero catalog-write counts, and the
remaining drift count from a fresh report. Repeating the bounded command after
a successful repair reports zero candidates and performs no further cache
invalidation.

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

A CNY-selected source record must carry `shipping_price_per_kg` together with
an explicit `shipping_price_per_kg_currency` of `CNY` or `IRR`; no freight
currency is inferred during materialization. An IRR `partner_price` source
uses the distinct `partner_price_source`, adds margin only, and carries the
zero-rate `domestic`/`خرید داخلی` method. The disabled-by-default
`sale_price_direct` last fallback instead reads `sale_price_source`/`FOROSH`
without margin or rounding and uses the same domestic provenance.

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
