# PBX call verification and voice order updates

Digitalogic exposes an inbound verification option alongside the existing Digits login. A user enters an Iranian mobile or fixed-line number, receives a six-digit code in the browser, calls `021-66754123`, selects IVR option `2`, and enters the code. The signed PBX callback must match both the canonical number and code before the browser can consume the challenge.

The same proof can add a verified supplemental phone to WooCommerce My Account. Customers can also keep multiple supplemental email addresses. A verified phone is eligible for order-announcement consent, but consent, the global switch, and every order status are all off by default.

## Required server-only constants

Put credentials in `wp-config.php` or an earlier root-owned configuration include. Do not store them in WordPress options, commit them, expose them to JavaScript, or reuse one value for both trust boundaries.

```php
define( 'DIGITALOGIC_PBX_VERIFY_SECRET', 'BASE64_STANDARD_TEXT_HERE' );
define( 'DIGITALOGIC_PBX_CALLOUT_URL', 'http://127.0.0.1:8789/call' );
define( 'DIGITALOGIC_PBX_CALLOUT_TOKEN', 'INDEPENDENT_HIGH_ENTROPY_BEARER_TOKEN' );
```

Generate independent values, for example:

```bash
openssl rand -base64 32
openssl rand -base64 48
```

`DIGITALOGIC_PBX_VERIFY_SECRET` is standard base64 text. The plugin validates canonical base64, decodes it strictly, and requires at least 32 decoded bytes before HMAC use. The callout URL must be exactly `http://127.0.0.1:8789/call`. The printable-ASCII bearer token must contain 32–512 characters.

## Signed callback contract

The exact endpoint is:

```text
POST /wp-json/digitalogic/v1/call-verification/pbx-confirm
```

Required headers are `X-PBX-Key-Id`, `X-PBX-Timestamp`, `X-PBX-Nonce`, and `X-PBX-Signature`. Key id is `v1`; the timestamp has a 60-second acceptance skew; the nonce is single-use; and the signature is lowercase 64-character hexadecimal without a `sha256=` prefix.

The canonical string is byte-exact:

```text
v1
POST
/wp-json/digitalogic/v1/call-verification/pbx-confirm
<timestamp>
<nonce>
<sha256(raw request body)>
```

The JSON body has an exact key allowlist and a 4 KiB maximum:

```json
{
  "schema": "phone-verification.v1",
  "site_id": "digitalogic.ir",
  "event_id": "123e4567-e89b-42d3-a456-426614174000",
  "call_id": "1721491200.42",
  "called_number": "+982166754123",
  "caller_number": "+989123456789",
  "code": "381624",
  "occurred_at": "2026-07-21T00:00:00.000Z"
}
```

Only an atomic ANI/code match returns `{"success":true,"verified":true}`. Invalid, expired, limited, or non-matching callbacks return `{"success":false,"verified":false}`. Event IDs are UUIDv4 values claimed with a processing sentinel; completed results are idempotent, exact concurrent retries converge under row locks, and reuse of an event ID with a different call or body is rejected as a conflict.

The trusted callback accepts the carrier ANI forms observed on the Digitalogic TCI line: national `09…`, international `98…`/`+98…`, carrier `098` plus ten significant digits, and exactly eight local Tehran digits. The eight-digit exception exists only in the HMAC-authenticated callback. Public user input always requires the landline area code. Routing-prefixed outbound forms such as `9` plus a national number are rejected as ANI.

## Outbound order announcements

WooCommerce status transitions create idempotent jobs only when all three controls are enabled: the global switch, the particular status, and consent on a currently verified phone. Consent must explicitly name the exact order-status event; a missing or empty event list denies every call. The worker rechecks all controls, administrative suppression, event preferences, account ownership, and the order's current status immediately before dispatch.

Jobs use Action Scheduler when available and WP-Cron otherwise. Action Scheduler actions are deliberately non-unique because supported WooCommerce versions de-duplicate a “unique” hook/group without considering job arguments; the job row's atomic claim remains the delivery guard. A five-minute due-job reconciler and hourly watchdog recover direct scheduling failures. Calls are limited per installation-keyed canonical phone number (including the same number attached to different accounts), delayed outside the configured Tehran calling window, and sent to the local PBX as `9` plus the Iranian national number. The request disables redirects and sends `max_retries: 0`. A job is marked sent only after an HTTP 2xx response containing exact JSON boolean `{"ok":true}` from the existing callout service. Network/protocol failures are terminal and do not create an application retry.

Administrators configure the global switch, status switches, calling window, limits, and Persian templates under **Digitalogic → Voice notifications**. Customers manage each verified number and its order-event preferences in **WooCommerce → My Account → Account details**. Administrators with both user-edit and WooCommerce-management capabilities can enable or suppress a verified number on the WordPress user profile. Any administrative expansion requires a written reason; customer and administrator consent changes are appended to an immutable audit table in the same database transaction as the contact update.

The feature gate opens only after all plugin-owned PBX tables verify their required columns and indexes, use InnoDB, and have both cleanup and recovery schedules installed. A failed migration leaves the schema version unset, hides call verification, disables voice dispatch, and displays an administrator error. Terminal challenges are retained for seven days, callback events for 30 days, revoked contacts for 30 days, terminal voice jobs for 90 days, consent audit rows for one year, and rate/replay records only for their bounded operational windows.

## Deployment order

1. Back up WordPress and deploy plugin version 1.6.0. Activation/update creates and verifies the plugin-owned challenge, contact, consent-audit, replay, event, rate, and job tables. Do not continue if WordPress reports the migration/recovery-schedule gate as incomplete.
2. Install the inbound AGI/dialplan and prompts from `ops/pbx/` using its deployment and rollback documents.
3. Add independent verification and callout credentials to server-only configuration; configure the AGI with the same decoded HMAC key bytes.
4. Keep outbound voice settings disabled. Test a mobile and an eight-digit Tehran ANI through the signed callback, then test browser consumption.
5. Harden the loopback `/call` bearer-token boundary. Enable one administrator-selected status and one verified test contact before expanding use.

All verification and contact REST responses carry `Cache-Control: no-store`. Codes, contact plaintext, call targets, messages, and credentials must not be written to application logs.
