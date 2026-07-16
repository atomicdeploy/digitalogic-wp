# WooCommerce base-currency status

Digitalogic observes the WooCommerce base currency as a read-only integration
input. It never calls `update_option()` for `woocommerce_currency`, never
rewrites an exchange rate in response to a base-currency change, and never
recalculates products from the monitoring hook.

## IRT and Toman contract

Patris emits final prices in `IRT`. In this integration, `IRT` means Toman:

- `1 IRT` is `1 Toman`;
- `1 Toman` is `10 IRR`;
- `IRR` is not treated as an alias for `IRT`;
- transformed Patris prices can be applied only while the WooCommerce base
  currency is exactly `IRT`.

This distinction prevents a tenfold pricing error. A store configured as
`IRR`, `USD`, `CNY`, or any other code reports
`base_currency_mismatch`; Digitalogic does not attempt a conversion or change
the setting automatically.

## Shared status model

`Digitalogic_WooCommerce_Currency_Status` is the single source used by the
versioned integration catalog, product-sync validation, REST, the command
dispatcher, WP-CLI, panel events, webhooks, and administrator screens. Its
status is nonsecret and includes:

```json
{
  "source": "woocommerce",
  "option": "woocommerce_currency",
  "code": "IRT",
  "unit": "toman",
  "irr_per_unit": 10,
  "price_decimals": 0,
  "pricing_output_currency": "IRT",
  "pricing_output_unit": "toman",
  "pricing_output_irr_per_unit": 10,
  "compatible": true,
  "status": "ready",
  "read_only": true,
  "warnings": []
}
```

On mismatch, `compatible` is `false`, `status` is
`base_currency_mismatch`, and `warnings` contains
`woocommerce_base_currency_must_be_irt`.

## Integration catalog

`GET /wp-json/digitalogic/v1/integration/catalog` publishes schema version
`1.1.0` (still major version 1). The existing fields remain available, and
`currency` now also contains:

- `woocommerce_base`: observed code, unit, IRR scale, and display precision;
- `pricing_output`: the required IRT/Toman output contract;
- `compatibility`: structured readiness, required base code, and read-only
  marker;
- `warnings`: the mismatch warning alongside rate warnings.

`currency.cny_to_irt` remains populated only when the WooCommerce base is
`IRT`; otherwise it is `null`. The base-currency metadata is covered by the
catalog revision, so consumers invalidate cached compatibility state when the
setting changes.

The read-only `GET /wp-json/digitalogic/v1/currency` response and shared
`digitalogic_get_currency` command expose the same status under
`woocommerce_base`. Operators can also inspect it with:

```bash
wp digitalogic currency get
```

## Change monitoring

The observer listens to the committed `updated_option_woocommerce_currency`
hook. A real change:

1. writes an audit entry with action `woocommerce_currency_change`;
2. fires `digitalogic_woocommerce_currency_changed` with the old code, new
   code, and full new status;
3. publishes the existing `currency.updated` panel/WebSocket and webhook
   event with the shared status payload.

Equivalent normalized codes are idempotent and produce no duplicate audit
event. Audit failures are isolated from the WooCommerce settings update.

Automated tests use WordPress/WooCommerce stubs and call the observer directly.
They do not change a real site option or real exchange rates.
