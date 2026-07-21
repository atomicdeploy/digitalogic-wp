# Security invariants

1. Only both exact `[from-tci]` PSTN s/_X paths may enter verification. Set trusted
   literal `__PBX_VERIFY_DID=+982166754123`; never include the helper context from
   `from-internal`, where SIP caller ID is not ownership proof.
2. Snapshot carrier ANI into `__PBX_VERIFY_ANI` in both paths before
   `[prefix-tci-callerid]` prepends routing digit `9`. The modified number is rejected.
3. Run the verification menu before `Gosub(record-call)`. Digit 2 must terminate after
   verification and must never reach recording; only timeout/0/invalid fallback returns
   to the existing recording and operator path. `StopMixMonitor()` is also run before
   AGI as a final guard against an unexpected upstream recording change.
4. Do not enable bare ANI unless a reviewed carrier capture requires it. Anonymous,
   malformed, foreign, and ambiguous values fail before any callback. The fixed `21`
   conversion for observed eight-digit Tehran ANI and optional observed `098` carrier
   form exist only in this signed PSTN AGI configuration, never public input handling.
5. Keep `agi set debug off`, Asterisk core/debug at normal production levels, DTMF
   logging off, and never add caller/code/body values to `NoOp`, `Verbose`, CDR user
   fields, query strings, or shell commands.
6. The callback origin/path, DID, context, prompts, and key ID are validated. HTTPS
   certificate validation is on; redirects are refused; request and response sizes and
   timeouts are bounded.
7. Store the base64 HMAC key only in the root-owned secret file. The helper rejects a
   symlink, non-root owner, group-writable file, any access for `other`, and keys shorter
   than 256 bits. Use a different secret per site; `v1` is a site-local key-version label.
8. WordPress must verify the signature over the exact raw request bytes before JSON
   parsing, allow at most 60 seconds of clock skew, atomically store nonce/event IDs,
   require called DID and ANI/code challenge matching, and never log the raw request.
9. Successful PBX confirmation only marks a challenge verified. The browser-bound,
   one-time consume request remains required to attach the phone or complete login.

## Existing outbound `/call`

`/call` is a separate trust boundary. It must bind to loopback (or a Unix socket), accept
only server-side POST, require an independently generated Bearer token, reject redirects
and arbitrary callback URLs, validate the local `from-internal` target format, limit TTS
length/rate, and redact target/text/token from logs. WordPress must resolve a currently
verified opted-in contact at dispatch time; the browser can never choose the raw target.

Keep the token outside the database in a root-owned environment/credential file readable
only by the service and WordPress worker group. Rotate it independently of the inbound
HMAC key. If the service supports only one token, add a site-scoped loopback wrapper or
token support before onboarding another site rather than sharing credentials.
