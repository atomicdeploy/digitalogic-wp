# Targeted cache cleanup comparison

The adapter marks planned coordinated rows as batch_write, so it reaches the modified cleanup branch.

Separate live CLI processes selected the same 901 positive owner-source products, primed post and metadata caches, then invoked only final cache cleanup. No exchange-rate or product-price change was requested. The old receiver was bind-mounted only inside a private mount namespace; serving workers retained the new version.

| Version | Cleanup ms | Queries |
|---|---:|---:|
| Targeted key retry | 7722.769295 | 2172 |
| Previous clean_post_cache fallback | 8228.611916 | 2735 |

The observed reduction is 563 queries and 505.843 ms. This sequential component comparison is not a controlled end-to-end speed guarantee: concurrent workload and cache conditions can differ. The latest uninstrumented changed-rate operation still took 65.576 seconds and restored the original rate/mode/target price. Performance acceptance remains open.

Existing partial persistent-cache deletion regression passed: one test, nine assertions. Correctness of all changed-price destination events after this patch remains to be independently checked before a release claim.

Independent changed-price event acceptance after the cache patch: 901 computable source products, 10 unchanged by rounding, 891 changed and all 891 event prices matched. No changed-price omissions or mismatches. This checks the captured Redis event interval, not all rendered storefront surfaces.
