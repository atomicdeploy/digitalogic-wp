# Digitalogic product workbook

Digitalogic's Excel transport is a header-driven product workbook. Export and
import use the same canonical column schema as the Google Sheets catalog
projection, so operators can rearrange columns or remove columns that are not
part of a particular update.

## Export

The workbook has `Products`, `Instructions`, and `Schema` worksheets. Product
headers can be generated in English, Persian, or bilingual form, and an empty
template can be downloaded without exporting catalog rows.

- WordPress admin: **Digitalogic > Import/Export**
- WP-CLI: `wp digitalogic export --format=excel --locale=bilingual --template --output=/path/to/products-template.xlsx`
- REST: `GET /wp-json/digitalogic/v1/export?format=excel&locale=bilingual&template=true`

`locale` accepts `en`, `fa`, or `bilingual`. The `template` flag is optional;
when true, only the schema and guidance are exported.

The workbook includes editable WooCommerce and established Digitalogic/Patris
fields, plus read-only operational context such as effective pricing, pricing
status, promotion policy, shipping, profit, and one scalar column for each
known warehouse. Text identifiers such as SKU and Patris code are written as
text so leading zeroes are retained.

## Import contract

Imports match columns by their header rather than their position. Accepted
headers include English, Persian, bilingual, machine-key, and supported legacy
aliases. Columns may be reordered and a subset may be supplied, subject to two
requirements:

1. A WooCommerce ID column is present.
2. At least one writable product column is present.

Unknown or duplicate canonical headers reject the workbook. Read-only context
columns are accepted but never written back. Existing 17-column XLS/XLSX
workbooks remain supported through their legacy aliases.

Formula cells are deliberately unsupported. Digitalogic scans the complete
worksheet before making the first product update and rejects the whole import
if any formula is present. This keeps imports deterministic and prevents a
partially applied workbook.

## XLSM boundary

No owner-provided XLSM reference artifact is currently attached to the tracked
issue. Consequently, this implementation does not invent macros or claim exact
visual parity with an unavailable workbook. Macro-enabled files may be read by
the installed PhpSpreadsheet reader when supported, but Digitalogic exports
XLSX and does not preserve or execute VBA. Attach the approved XLSM reference
before any macro-preservation or exact-style requirement is accepted.
