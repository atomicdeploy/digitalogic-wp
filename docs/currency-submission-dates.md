# Currency submission dates

Changed USD and CNY values default independently to the submission day in the WordPress deployment timezone. Unchanged currency values keep their dates. Queued jobs freeze resolved dates at admission, after request replay resolution, so later execution cannot move the date across midnight. Stored `submitted_currency` and `submitted_at` retain the submitted fields and timestamp separately from resolved `desired_currency`.

REST, command, CLI and ACF admission share this PHP owner policy. Go must consume the owner's resolved dates rather than recompute them. An explicit currency-specific date overrides its default; a common `effective_date` applies to the submitted currencies. Reconciliation alone does not refresh rate dates.

ACF submits untouched fields automatically. An unchanged displayed date is omitted unless the operator selects the visible date-override checkbox. Editing the date remains an explicit override. This prevents a normal rate edit from silently reusing yesterday's displayed date.

Validation so far: 155 coordinator checks, four timezone/override/frozen-date scenarios executed on production, and 11 ACF-related checks. A production hook probe verified untouched-date omission and explicit override retention without enqueueing work. Full external app/workbook consumption and an actual delayed queue across midnight remain unverified. Do not treat this as closing all-interface acceptance in issue 289.
