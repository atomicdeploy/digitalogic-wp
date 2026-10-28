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
