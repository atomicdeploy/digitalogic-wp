# Registration mobile entry

The production Digits `dig_reg_fields.dig_reg_email` setting is `0` (disabled).
The previous value was `1` (optional). Registration does not collect an email;
the existing account/profile workflow can collect it later when needed. Other
registration settings are preserved, including required mobile (`2`). Existing
email-based login remains enabled.

The existing branding stylesheet hides the separate Digits country-code
control only within registration forms on the canonical authentication pages.
It retains the enabled country-code input for Digits' normal submission and
verification, removes the oversized input padding, and lets the phone row span
the form. The editable phone input starts empty and keeps its required status.
No Digits vendor file, authentication handler, or OTP verification is changed.

Registration inputs use a shared autocomplete section with `given-name`,
`family-name`, `username`, `tel-national`, and `new-password` tokens. Missing
phone/password IDs receive stable IDs. Existing values and existing IDs are
preserved, including values supplied by a password manager.

Digits sends `serializeArray()` payloads. The AJAX guard recognizes that native
array format as well as strings, objects and FormData. Loading starts in the
request's `beforeSend`, not merely on a click: native client validation can
reject an otherwise HTML-valid form before sending anything. Requests use a
30-second default network timeout and a 35-second final loading fail-safe.
Cancelled requests, malformed payloads and completion clear the loading state;
unrelated AJAX does not unlock an in-flight Digits form. No retry is automatic.

Production acceptance verified the empty field before/after focus and clearing,
the missing registration email input, the hidden country-code overlay, required
field validation, and unchanged user count. No account was created and no OTP
was requested. Existing and focused branding/sidebar tests passed (26 tests).
An isolated server-local HTTP-context probe also exercised the actual Digits
handler with missing password and an impossible mobile number, returning its
structured validation errors. Outbound HTTP/mail and user creation were blocked
in that process. A successful real-account signup or real OTP delivery was not
performed as part of these tests.

## Digits 9.2.4.2 compatibility

The native passwordless/passkey button `.digits-form_passwordless_login` owns
its WebAuthn flow. Custom login click capture must let that button reach Digits
before ordinary identity validation or event suppression. An explicit submit
event from that exact submitter delegates to its native click; merely having a
secondary passwordless button does not change ordinary Enter behavior.

The updated native registration and passkey requests still use `serializeArray`
and nonempty WordPress success/data payloads, which the existing guard supports.
Thirty branding/sidebar regression tests passed after this compatibility change.
Production acceptance confirmed preserved email/mobile settings, empty mobile
entry and autofill metadata. Isolated native-handler validation returned proper
missing-password and invalid-mobile errors with outbound delivery and account
writes blocked. Real OTP delivery and real hardware/passkey completion were not
performed during this check.

The canonical `/login/` alias must also be excluded from WP Rocket page cache.
The native `cache_reject_uri` setting contains `/login|/login/`: this exact-path
alternation survives WP Rocket's trailing-slash normalization and excludes both
aliases. Cache configuration was regenerated and only the login URL cache was
purged with `rocket_clean_files`. Repeated public requests returned the current
branding asset version and `no-cache, no-store, private` headers, with no login
cache file recreated. Other page-cache settings were preserved.
