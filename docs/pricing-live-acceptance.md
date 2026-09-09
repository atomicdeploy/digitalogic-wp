# Live pricing acceptance — 9 September 2026 (Tehran)

The configured authority is **PHP with direct database publication**. Selected-Go publication has also passed a live all-product check; this is not completion of the entire PHP/Go and interface unification project.

## Latest execution and performance evidence

- Clean installed Go source provenance was established, followed by live selected-Go publication in 39,805 ms and PHP restoration in 29,388 ms. Independent checks passed all 1,022 owners in both states. These authority transitions are not controlled engine benchmarks or a changed-CNY Go test.
- Post-commit product publication was measured at approximately 15 seconds; SQL COMMIT was only 10–14 ms. Avoid rebuilding already emitted product values after checking the current exact event revision. Changed-CNY acceptance verified all 896 changed-price events after that change.
- Tier Pricing's intermediate base-price calculation consumed about 4.15 seconds across 1,022 price getters before the canonical final filter replaced its result. Skip that exact callback only for managed, non-variable products with a nonempty stored regular price. Other products retain the original callback; mapping/weight and final canonical filters still execute. This intentionally skips the redundant callback's internal hooks; it does not disable tier UI or regular/sale getter hooks.
- The installed optimization reduced the full price-getter sweep from 4.60 to 1.00 seconds with identical price/stock tuple hashes. Changed-CNY acceptance then completed in 32,613 ms and restoration in 30,434 ms. All 1,022 prices/stocks passed the independent oracle, all 896 changed-price events matched price/stock/identity/revision, and complete original settings and product values were restored. No large end-to-end speedup is claimed.
- Latest fresh single observation: 1,220 ms, delivered and ready afterward. The sub-second target remains open. CNY is restored to 34,500 and relevant services are active.

Evidence and limitations are recorded in [the pricing goal issue](https://github.com/atomicdeploy/digitalogic-wp/issues/288). Earlier complete public-page acceptance below was not repeated as a full sweep for each diagnostic pass.

## Current catalog

- 1,022 valid Patris source owners; their existing Woo IDs remain authoritative.
- 36 of the original 118 missing-mapping cases resolved: 21 duplicate leaves consolidated and 15 containers connected through exact source-model choices.
- 82 unresolved critical/unpriced leaves remain: 43 model/variant reviews and 39 without an exact candidate in the reviewed source catalog. Their disposition requires actual model/source evidence; no guessed Code assignments or removal merely to improve the count.
- Latest complete reconciled projection: 1,104 sale leaves, with no observed price leaks.
- TFT 3.5-inch, Pico and ESP8266 families now use existing canonical owners. Woo-generated titles/excerpts on unresolved Pico/ESP8266 children may change with the parent selector; raw identity/attribute metadata, other post fields, terms and commerce data were preserved. A byte-for-byte requirement on generated display text is inappropriate here.

The [current unresolved list](https://github.com/atomicdeploy/digitalogic-wp/issues/314#issuecomment-5592415542) records each remaining Woo ID and review finding. Names in that review include original labels, not necessarily current generated variation titles.

## Independent changed-CNY acceptance

The owner CNY rate was changed from 34,500 to 34,600 through the existing shared pricing service and then restored to 34,500. Complete owner settings were compared with a freshly verified baseline before mutation; restoration compared the complete actual post-change settings before restoring original inputs. This was a one-time acceptance probe, not a new runtime rollback/revision subsystem.

| Check | Verified result |
|---|---|
| Change command through terminal PHP delivery receipts | 31,499 ms |
| Restore command through the same boundary | 32,409 ms |
| Independent arithmetic/database checks before, during and after | All 1,022 owners; 901 valid prices and 121 unpriced rows; zero errors |
| Prices that actually changed | 896; no stock changes |
| Fresh external HTTP checks at the changed rate | All 967 public pages representing the 1,022 source owners passed |
| Restored inputs, stored prices/stock and complete owner settings | All values equal the original baseline; object-key ordering ignored |
| Fresh external checks after restoration | All 23 affected family forms and six simple-product samples passed |
| Service readiness after restoration | Apache, PHP-FPM and WordPress WebSocket active |

Arithmetic used an independent Node rational-number implementation plus a positive-weight gate for **all** price routes, including domestic purchase. No production calculator was imported into the arithmetic oracle. This validates the captured normalized inputs; exact owner identity remains a separate mapping requirement.

Command timing includes SSH, WordPress bootstrap, locks, repricing/publication and terminal receipt readback. The complete external sweep took additional verification time. These measurements do not establish an observed per-page propagation deadline for every interface. The full 967-page sweep was performed at the changed rate and was not repeated after restoration.

Stock remains independent from price eligibility. Positive quantity means in stock even when price is absent. Mapped, published, positive-stock unpriced variations remain visible with null numeric price fields and blank price HTML; purchasing remains disabled. Missing weight must never force an otherwise positive quantity out of stock.

The [full production proof](https://github.com/atomicdeploy/digitalogic-wp/issues/288#issuecomment-5592699514) records scope and limits. Stakeholder SMS summaries were accepted by the provider; handset delivery/read was not verified.

## Still open

This checkpoint does not prove Go-authority parity, the sub-second single-product target, all adapter/queue combinations, or end-to-end propagation through every interface. It does not prove n8n destination delivery from a PHP receipt.

The remaining mapping evidence, Go/shared-PHP unification and immediate pricing requirements remain active. Full bidirectional synchronization, interface progress work, full Excel acceptance, notifications, engine comparison and snapshot/revision/rollback redesign remain in their agreed backlog. Before re-enabling deferred features, reconcile them with current policy and expose consumer configuration; do not reintroduce competing pricing owners or permanent compatibility paths.

## Adapter bulk latency — 9 September 2026

Actual changed-CNY adapter execution took141.201s before the deferred-sync repair and76.626s afterward. This remains above the60s requirement; adapter performance is not accepted. Native Woo deferred parent synchronization now runs within coordinated delivery before final readback, retaining scoped publication and preserving pre-existing request queue work.

All1,022 mapped rows passed independent price/stock checks at CNY34,600 and restored34,500. All896 changed-price events matched;901 mapped product events were checked,936 product events observed overall. Direct-DB restoration completed32.018s. Production stays PHP/direct_db. Two existing focused queue/readback checks passed (27 assertions); these supplement rather than replace live evidence.

The callback profile removed64.334s of shutdown work (0.003ms afterward); product_updated webhook callback time fell from42.209s to2.352ms. These spans overlap and must not be added. Remaining adapter save/readback time still needs attribution.

Tracking and detailed evidence: https://github.com/atomicdeploy/digitalogic-wp/issues/324#issuecomment-5593955034

Follow-up adapter observation: removing duplicate post-save cache invalidation and using staged edit-context prices for source completeness yielded71.678s uninstrumented. No additional end-to-end gain is established against the preceding71.732s span run. All1,022 rows and896 changed-price events passed; exact original settings/rows restored, direct_db restoration36.349s. Adapter remains above60s. Temporary profiling code is absent from the deployed materializer.
