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

Production acceptance verified the empty field before/after focus and clearing,
the missing registration email input, the hidden country-code overlay, required
field validation, and unchanged user count. No account was created and no OTP
was requested. Existing branding/sidebar tests passed (21 tests).
