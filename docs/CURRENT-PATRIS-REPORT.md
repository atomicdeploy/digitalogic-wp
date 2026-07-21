# Current Patris Report

Digitalogic has one current Patris report. It reads the canonical living
receiver option, `digitalogic_product_sync_state`; it does not read the former
normalized feed snapshot or a standalone PHP report.

## Surfaces

- **Digitalogic → Patris Reports** is authenticated with the shared panel
  access policy.
- `GET /wp-json/digitalogic/reports` uses the diagnostic permission policy.
- `wp digitalogic patris report` reads the same engine.
- `wp digitalogic patris inspect` validates and compares a reviewed static
  canonical `kala.json` without changing receiver or WooCommerce state.
- `wp digitalogic patris ingest ... --yes` explicitly applies the reviewed
  file through the living receiver and then reads the same engine.

The report accepts `view=warnings|price_list`, `page`, `per_page` (1–100), an
optional warning `category`, and an optional exact `source_id` plus `dataset`
pair. Without an explicit pair, the most recently generated valid source state
is selected deterministically.

## Matching and scope

The join key is the exact, case-sensitive
`_digitalogic_patris_product_code` metadata value. SKU is never considered.
Simple products and variations are reportable; variable parents are excluded
so a family container cannot be mistaken for a sellable source record.

The engine reads at most 10,000 source records and 10,000 WooCommerce leaf
records in batches. A response contains at most 100 rows and exposes its
pagination and truncation state. The normal report does not inspect customer
records, personal data, or image files.

## Sparse values and findings

Each row's `source` object retains the stored canonical shape:

- a missing key remains absent;
- an explicit source null remains present with `null`;
- empty strings and arrays remain present;
- the report never fills unavailable source fields with null placeholders.

Warnings distinguish missing keys from explicit nulls for CNY price, currency,
weight, stock, freight inputs, profit margin, exchange rate, calculated price,
and `source_updated_at`. The report also identifies source-only and
positive-stock source-only products, WooCommerce-only products, duplicate exact
Codes, upstream warnings, stale source timestamps, nonpositive stock/prices,
and active/regular/sale price, stock quantity, stock-management, availability,
weight, `source_updated_at`, and `record_hash` drift. If no valid source is
available, reconciliation rows and findings are withheld instead of treating
every WooCommerce product as source-only.

## Static current snapshot

Store the transformed canonical file in a private directory outside the
WordPress webroot:

```bash
wp digitalogic patris inspect \
  --file=/srv/digitalogic-private/kala.json \
  --user=<administrator> \
  --format=json
```

The command requires the WordPress administrator role and `manage_options`, an
absolute regular nonsymlink path, the exact filename `kala.json`, and a file no
larger than 8 MiB. It uses the receiver's canonical validation and exact Code
rules, but it does not lock, persist, or write WooCommerce.

After reviewing the inspection, explicitly apply the same private file with:

```bash
wp digitalogic patris ingest \
  --file=/srv/digitalogic-private/kala.json \
  --user=<administrator> \
  --yes
```

The apply command refuses to mutate anything unless `--yes` is present.

Production `kala.json`, database files, and standalone report scripts must
never be placed in this repository or a plugin package. The public-tree and
package checks reject those artifacts.
