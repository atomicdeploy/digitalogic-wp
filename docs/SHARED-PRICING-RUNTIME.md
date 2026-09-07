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

## Release requirements still open

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
