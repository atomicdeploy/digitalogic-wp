# Final-price rounding

The shared PHP calculator and Go consumer use the same configurable policy. Only the final total is rounded; Patris amounts, SKU/Product Code, stock, freight, markup and currency rates retain their existing ownership and values.

Select **پلکانی — متناسب با مبلغ نهایی** in the Patris report's rounding settings. Edit the starting IRT amount and trailing-digit count in each row. Empty both cells to remove a tier; fill a blank row to add one. The continuation checkbox adds one rounding digit for every further decade above the final tier. Fixed rounding remains a selectable alternative.

The initial magnitude policy is:

| Minimum exact total (IRT) | Trailing digits |
|---:|---:|
| 1,000 | 1 |
| 10,000 | 2 |
| 100,000 | 3 |
| 1,000,000 | 4 |
| 10,000,000 | 5 |

Below the first threshold, round to a whole IRT. Select the tier from the **unrounded** exact total, then round half up once. IRR uses the equivalent IRT threshold and one additional trailing digit. The same final policy applies to foreign, partner and direct-sale routes. Missing weight or missing authoritative identity still means no final price, independently of stock availability.

CLI:

```sh
wp digitalogic pricing rounding                    # read current settings
wp digitalogic pricing rounding --magnitude        # activate standard editable tiers and reprice
wp digitalogic pricing rounding --fixed-digits=2   # select fixed rounding and reprice
```

Settings are saved through the existing coordinated pricing operation. A failed operation must not leave partially changed settings or prices. An omitted `price_rounding_policy` preserves the current policy; an explicit null selects fixed mode. The owner catalog publishes `pricing.rounding_policy`. Priced records publish the policy and resolved effective `price_rounding_digits`, which consumers verify with the final price and record hash.

Deploy the policy-capable Go consumer before activating this PHP setting. Older consumers reject policy-rounded direct-sale records. Go spreadsheet exports preserve the verified owner final value for magnitude-policy rows, including when formula output was requested; a fixed-digit formula must not silently replace a magnitude result.

Validation covers exact boundaries, half ties, IRT/IRR equivalence, all three price routes, configurable tiers, policy transport, PHP-to-Go record hashes, atomic settings failure and preservation of source inputs. Production acceptance additionally compares every mapped price against an independent rational-arithmetic calculation and reads the Go consumer and storefront.

## Production acceptance — 10 October 2026

PHP 2.4.18 activated the standard policy in 45.451 seconds. Independent rational arithmetic matched all 1,085 previously mapped products, including the 769 predicted price changes. Existing pending product 113003157 was also applied and independently verified. Eight public AT25/NRF24L01 variations matched expected prices or their existing stock-based hiding rule.

Go 2.0.5 then served all 1,087 current owner records, including 975 prices, with no independent price, hash, weight, stock or source-field differences. The live input feed advanced during acceptance, so these later counts refer to their current owner capture. No currency rates were changed by this work.

The separate initial Woo delivery failure for newly arriving product 109054 is tracked in [issue 402](https://github.com/atomicdeploy/digitalogic-wp/issues/402). It is not a reason to bypass identity, source or price verification.
