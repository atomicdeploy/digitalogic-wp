# Shared pricing runtime

## Executable PHP path

`Digitalogic\Pricing\Calculator::evaluate()` is the shared, framework-independent
selected-price calculation. The WordPress receiver and coordinated repricer use
it, as does `Digitalogic\Integrations\Paradox\CanonicalProductReport`.
Laravel resolves the same implementation from the bundled Composer runtime.
There is no HTTP call between these PHP consumers.

The calculator accepts owner-resolved inputs. It does not fetch FX, choose a
source, write a product or announce completion. Exact decimal arithmetic keeps
IRR/IRT conversion, freight, markup and final half-up rounding deterministic.
An incomplete route returns `available: false`; it must not silently become a
zero selling price. Provider extensions do not affect calculation or identity.

The coordinated repricer reuses its computed result for internal validation.
Incoming provider prices are independently checked. This avoids calculating
each internally repriced product twice without weakening the input boundary.

## Existing report command

```sh
wp digitalogic patris report --view=price_list --format=html > pricing-report.html
```

The HTML uses the current report engine's source selection, identity quarantine,
filters and provenance. A report is read-only; it is not a refresh completion
receipt. Table, JSON and CSV remain output interfaces to the same report.

## Coordinated price persistence

```sh
wp digitalogic pricing write-mode
wp digitalogic pricing write-mode adapter
wp digitalogic pricing write-mode direct_db
wp digitalogic currency update --recalculate
```

The setting applies to coordinated PHP repricing, including single-product and
bulk operations. It is pinned under the pricing lock for each operation and
returned as `pricing_results.write_mode`. `direct_db` is the default existing
batched pricing path; structural product creation and unsafe batch targets still
use WooCommerce. `adapter` routes priced leaves through WooCommerce saves. Both
retain identity checks, transactions, lookup/cache maintenance and final readback.
The command changes persistence only; it does not select the pricing authority.
Per-request UI controls and the Go delivery writer remain integration work.

## Release requirements still open

Both pricing implementations admit plain non-negative decimal inputs with at
most 15 integer digits and 12 fractional digits. Exponents, trailing whitespace
and newlines are invalid. This bounded calculation-input policy does not alter
raw report decimal values. Out-of-range inputs must not produce a deliverable
price in one engine while the other rejects them.

Authority integration uses `pricing.authority` in the existing owner catalog
identity. Incoming `input_source`/`input_products` retain the source's delta
baseline; the applied `source`/`products` hold the final pricing projection.
Do not apply a PHP price and then write an upstream Go price over it, or validate
the next upstream delta against derived PHP hashes. Existing stored sources need
an explicit fresh full-snapshot cutover; no permanent dual-layout reader is intended.

The existing snapshot revision endpoint can resolve an exact `input_source` to
the final `source`. Its response binds both identities and `owner_catalog_revision`.
The latter is the integration pricing catalog revision; the existing
`catalog_revision` is the report projection revision and must not be confused
with it. Subsequent snapshot build/page checks remain pinned to the discovered
final source. Discovery holds the receiver lock and its ETag includes the input
baseline, even when that input change leaves final prices unchanged.

Snapshot rows include `canonical_product` for source-owned products, preserving
the exact stored decimal values and record identity. Consumers verify the
existing page digest and final source before publishing these final products.
Report-only WooCommerce rows do not imply an upstream product record.

Go-selected delivery checks each owner-dependent product's
`pricing_catalog_revision` against the current site owner catalog before writes,
including replay and pending-delivery paths. An obsolete revision returns
`digitalogic_pricing_owner_catalog_changed`; refresh owner inputs and recalculate.
Selecting Go does not transfer ownership of site currency, freight or rounding.
Standalone Go local catalogs do not authorize writes using unrelated owner
inputs into this WordPress deployment.

PHP owner projection resolves identities together and loads assignments in
batches of up to 500. The batch includes `woocommerce_id` so identity agreement
is checked without a second scalar assignment fetch for every product.

Go-selected owner settings commit with `awaiting_delivery`, the expected owner
catalog revision and every affected source identity. They do not calculate a
PHP price or claim final completion. Publication runs after the pricing locks
release; the existing authenticated outbound Go WebSocket observes owner changes.
Async confirmation must wait for matching durable delivery receipts for all
affected sources. The existing revision response includes `delivery`, and its
ETag changes with receipt progress even when product prices do not change.
Activation remains a paired release gate until event actuation, receipt recovery
and downstream acceptance are complete.

- Select exactly one final pricing authority, PHP or Go, per deployment. The
  shared calculator alone does not implement that deployment selection.
- Complete owner-aware Go/PHP parity and propagation through the existing
  refresh wait/queue paths; do not introduce another refresh pipeline.
- Measure from accepted request through destination writes and required
  application/notification receipts: single product under one second, fresh
  bulk around or below sixty seconds. A bulk duration above sixty seconds is
  critical. Calculator microbenchmarks and queue admission are not acceptance.
- Support adapter and direct database writes with equivalent readback,
  invalidation and progress semantics; preserve service readiness after release.
- Coordinate breaking caller and stored-setting changes in one staged cutover.
  Do not deploy the renamed pricing service storage keys without carrying
  forward current values. No permanent compatibility aliases are intended.

Local test and package evidence does not prove production readiness. Retained
package snapshots must be rebuilt after subsequent source changes.
