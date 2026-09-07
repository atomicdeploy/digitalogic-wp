# Paradox canonical report integration

`Digitalogic\Integrations\Paradox\CanonicalProductReport` contains the canonical
snapshot loader, exact-code WooCommerce read adapter, reconciliation, provenance,
and Persian HTML report previously in `atomicdeploy/paradox-dg` at commit
`29cda041a8d2a2a53d49bb2a050b0a05eaf5e71e` (`core/CanonicalProductReport.php`).
The two bundled Vazir WOFF2 files are the same report assets from that source.
The historical repository and its deployment are unchanged.

All selling-price evaluation now calls `Digitalogic\Pricing\Calculator` directly
in the same PHP process. There is no HTTP call, separate formula, or legacy
scalar-price adapter. `evaluatePrice(array $canonicalRow)` returns the calculator's
`available`, `missing`, and optional integer `value`, or propagates its
`InvalidArgumentException`. `analyze()` records invalid/missing pricing as report
issues and omits `expected_final_price`; it never uses the supplied `final_price`
as a substitute for calculation.

The existing command now exposes the integrated HTML report:

```text
wp digitalogic patris report --view=price_list --format=html
wp digitalogic patris report --view=warnings --format=html --source-id=patris-office --dataset=kala.db
```

HTML renders the complete selected view (page size flags do not truncate it).
The current engine still owns source selection, identity quarantine, warning
counts, filters, generation/freshness guards and WooCommerce reads. Its accumulated
source may end in a delta event, so `renderCurrentReport()` consumes that existing
report shape directly; it does not invent a signed full snapshot, reread through
PDO, or change the table/CSV/JSON semantics. Canonical reference prices appear next
to the unchanged source and WooCommerce values. Freshness/integrity/truncation
limitations remain visible. The HTML is emitted to CLI stdout and is not published.

Direct consumers should require this module (or use the shared Composer classmap),
then call `analyze($snapshot, $wooRows, $provenance, $store)` and `renderHtml()`.
`loadSnapshotFile()`, `externalIdentityVerifier()`, `fetchWooProducts()`,
`assertSnapshotScope()`, and `writeHtml()` retain their existing argument contracts.
No WordPress hooks, public routes, scheduled jobs, or writes are registered here.
`Digitalogic_Report_Engine::render_html_report()` is the shared callable facade
used by that command; this module does not expose an unauthenticated report endpoint.

## Intentional contract changes

- The old global class name is replaced by the namespace above.
- `calculateFinalPrice(foreign, weight, freight, currency, markup, fx)` is removed.
  The source tree used it only inside the old report and its tests. Callers must
  supply an explicit canonical selected-source row to `evaluatePrice()`.
- Missing selected source or rounding policy is not reconstructed from legacy
  foreign-price metadata. The selected amount/currency/kind controls evaluation.
- Foreign and partner prices respect each row's final nearest-half-up rounding
  digits. Direct-sale IRR prices require an exact integer IRT conversion and do
  not silently acquire markup, freight, or rounding.
- Domestic rows no longer get missing-foreign-input errors. Any calculator
  rejection becomes an explicit pricing-input issue rather than a guessed price.

## Arithmetic boundary

The report retains its 24-integer-digit / 12-fractional-digit parser for
non-pricing comparisons and display. Core price inputs retain the calculator's
15-digit bound. `ReportArithmetic` exposes the shared `ExactDecimalArithmetic`
compare/normalization methods on pre-parsed parts. Its small unsigned subtraction
is only for displayed WooCommerce-minus-reference differences; multiplication,
addition, final rounding and selling-price formulas are not copied here.
The integration owner may move subtraction into a shared decimal helper later;
no calculator API addition is required.

Validation: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/ParadoxCanonicalProductReportTest.php`.
Tests cover CNY/IRR freight parity, selected-source precedence, row rounding,
partner/direct prices, unavailable/invalid inputs, exact drift, snapshot identity,
private file boundaries, HTML escaping, and variable/duplicate identity handling.
