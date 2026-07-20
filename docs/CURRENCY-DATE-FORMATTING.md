# Currency effective-date formatting

Digitalogic stores the exchange-rate effective date in WordPress options. The
canonical source is the raw ACF option `options_update_date`; `update_date` is
used only when the prefixed option does not exist. Reading the option directly
is intentional: asking ACF for the formatted field can send a six-digit value
through wp-parsidate as though it were a Unix timestamp.

## Accepted storage formats

- Legacy `YYMMDD`, interpreted as a Gregorian date in the 2000s. For example,
  `260629` means `2026-06-29`.
- Strict ISO dates such as `2026-06-29`.
- Strict ISO date-times such as `2026-06-29T12:30:00+03:30`. The encoded
  calendar day is the effective date; an offset does not shift it to another
  day before display.
- Persian and Arabic-Indic input digits are normalized before validation.

Calendar-invalid dates, invalid ISO times or offsets, non-scalar values, and
empty values format as an empty string. They never fall back to the current
date or the Unix epoch.

## Locale behavior

`Digitalogic_Currency_Date_Formatter` is the sole parsing and calendar service.
English and other non-`fa` locales display Gregorian values. `fa_IR`, `fa_AF`,
and other `fa*` locales display the Jalali equivalent with Persian digits. The
numeric `Y`, `y`, `m`, `n`, `d`, and `j` tokens and Persian `F`/`M` month and
`l`/`D` weekday names are supported; other PHP date-format characters keep
their standard behavior.

`Digitalogic_Options::get_update_date_formatted()` delegates to this service.
The WordPress admin, status view, external panel, and storefront currency cards
all call that options method, keeping their date output aligned.

## Storefront shortcodes

The plugin owns the existing `[dollar_rate]` and `[yuan_rate]` shortcodes. Their
HTML class names remain `currency-box`, `flag-circle`, `currency-info`, `price`,
and `date`, preserving the live child-theme styling. The implementation is
registered on `init` at priority 20 so it supersedes the temporary theme-level
mitigation during a versioned deployment.

Two filters allow site-specific presentation without forking the formatter:

- `digitalogic_currency_card_date_format` changes the PHP display format.
- `digitalogic_currency_card_flag_url` changes the flag URL for a currency.

The default flags use the existing site-relative uploads paths
`/wp-content/uploads/2025/10/us.svg` and `cn.svg`, so non-production hostnames do
not make requests back to the production domain.

After deploying this release and verifying both shortcodes, remove the old
`digitalogic_format_currency_update_date`, `show_dollar_price`, and
`show_yuan_price` definitions from the child theme to eliminate duplicate
unversioned code. The plugin callback already remains authoritative until that
cleanup is performed.
