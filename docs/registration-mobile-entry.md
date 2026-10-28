# Mobile signup and checkout profile

Production Digits 9.2.4.2 registration collects only the required mobile number.
Ordinary login does not require a name, email or address. At purchase,
WooCommerce requires first name, last name, email, phone, country, province,
city, address and postcode, as requested by the shop owner. Company and the
second address line remain optional. Existing email-based login remains enabled.

The native settings are:

| Option | Value |
| --- | --- |
| `dig_reg_fields.dig_reg_mobilenumber` | `2` (required) |
| `dig_reg_fields.dig_reg_name` | `0` (disabled) |
| `dig_reg_fields.dig_reg_uname` | `0` (disabled) |
| `dig_reg_fields.dig_reg_email` | `0` (disabled) |
| `dig_reg_fields.dig_reg_password` | `0` (disabled) |
| `dig_reg_custom_field_data` | Encoded empty object; removed the signup-only last-name field |
| `dig_mobilein_uname` | `1` (native phone-with-country-code identifier) |
| `dig_reg_skip_otp_verification` | `0` (verification remains required) |
| `dig_autofill_wc_billing` | `1` (preserved) |
| `woocommerce_enable_guest_checkout` | `no` (preserved) |

The removed custom last-name field's `dig_cs_lastname` sort entry is also
removed; leaving it behind causes native Digits rendering warnings. Existing
customer names, email addresses and other metadata are untouched. The internal
username comes from the verified phone using Digits' native deterministic mode;
the random-number mode is not enabled. No new password is requested at signup.

The existing checkout login guard sends guests to the canonical login page with
the checkout return URL. Native `WC_Checkout::process_customer()` persists the
billing/shipping details to the logged-in customer during checkout, including
previously empty names. Native Digits billing autofill remains enabled. No extra
profile-completion screen is inserted into ordinary login.

The existing branding stylesheet hides the separate Digits country-code
control only within registration forms on the canonical authentication pages.
It retains the enabled country-code input for Digits' normal submission and
verification, removes the oversized input padding, and lets the phone row span
the form. The editable phone input starts empty and keeps its required status.
No Digits vendor file, authentication handler, or OTP verification is changed.

The branding adapter supports a shared autocomplete section with `given-name`,
`family-name`, `username`, `tel-national`, and `new-password` tokens. Missing
phone/password IDs receive stable IDs. Existing values and existing IDs are
preserved, including values supplied by a password manager. With the current
native settings only `tel-national` is rendered in the registration form.

Digits sends `serializeArray()` payloads. The AJAX guard recognizes that native
array format as well as strings, objects and FormData. Loading starts in the
request's `beforeSend`, not merely on a click: native client validation can
reject an otherwise HTML-valid form before sending anything. Requests use a
30-second default network timeout and a 35-second final loading fail-safe.
Cancelled requests, malformed payloads and completion clear the loading state;
unrelated AJAX does not unlock an in-flight Digits form. No retry is automatic.

Production acceptance on 2026-10-28 verified the single empty mobile input,
hidden country-code overlay, autocomplete metadata, immediate empty-form error
without stuck loading, and guest checkout redirect. An isolated server-local
HTTP-context probe submitted an impossible mobile number with no name,
username, email or password. The actual Digits handler returned its structured
invalid-mobile error without a missing-profile/password error or PHP warnings.
Outbound HTTP/mail and user creation were blocked in that process.

The normal HTTP-context checkout schema required all the billing fields listed
above. The actual WooCommerce validator rejected missing required fields,
empty country and invalid email. The existing Iranian postcode validation
requires a value but does not enforce a ten-digit-only format; no stronger
postcode-format guarantee is claimed. User count stayed at 42. No real account,
order, payment or OTP was created, and customer persistence was inspected in the
native source rather than exercised on a real customer.

The original native options are backed up in the root-only production directory
`/var/backups/digitalogic-mobile-onboarding-20261028/options-before.json`.
Registration custom-field changes use native options; no Digits vendor file,
new plugin, or checkout handler was added.

## Digits 9.2.4.2 compatibility

The native passwordless/passkey button `.digits-form_passwordless_login` owns
its WebAuthn flow. Custom login click capture must let that button reach Digits
before ordinary identity validation or event suppression. An explicit submit
event from that exact submitter delegates to its native click; merely having a
secondary passwordless button does not change ordinary Enter behavior.

The updated native registration and passkey requests still use `serializeArray`
and nonempty WordPress success/data payloads, which the existing guard supports.
Thirty branding/sidebar regression tests passed after this compatibility change.
The compatibility check confirmed empty mobile entry and autofill metadata.
Before the mobile-only policy change, isolated native-handler validation
returned proper missing-password and invalid-mobile errors with outbound
delivery and account writes blocked. The final mobile-only validation is
described above. Real OTP delivery and real hardware/passkey completion were
not performed during these checks.

The canonical `/login/` alias must also be excluded from WP Rocket page cache.
The native `cache_reject_uri` setting contains `/login|/login/`: this exact-path
alternation survives WP Rocket's trailing-slash normalization and excludes both
aliases. Cache configuration was regenerated and only the login URL cache was
purged with `rocket_clean_files`. Repeated public requests returned the current
branding asset version and `no-cache, no-store, private` headers, with no login
cache file recreated. Other page-cache settings were preserved.
