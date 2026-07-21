# Security invariants

1. Current TCI s/_X inbound paths must not invoke preflight; they route directly
   to extension 101. A future approved IVR must snapshot ANI before
   `prefix-tci-callerid` mutates it and use trusted literal DID `+982166754123`.
2. The dormant helper must remain unreachable from public and internal paths. If
   enabled in the future, initialize `PBX_VERIFY_PENDING=0` before AGI and make
   every lookup/config/network/protocol failure reach the operator without audio.
3. Pending lookup is a separate, path-bound signed POST. Its request has exactly
   `schema`, `site_id`, `event_id`, `call_id`, `called_number`, `caller_number`, and
   `occurred_at`; schema is `phone-verification-pending.v1`. Its successful response
   has exactly one boolean key, `pending`.
4. Confirmation uses its distinct path/schema and remains fail closed. A pending
   response never verifies an account and never returns a challenge ID or code.
5. Shortcut DTMF is collected only through AGI `STREAM FILE`/`WAIT FOR DIGIT`;
   normal private verification uses AGI `GET DATA`. Never place a code in a channel
   variable, wrapper argument, `NoOp`, `Verbose`, `System`, CDR, URL, or log.
6. No current inbound path answers for verification. If the shortcut is enabled
   in a future IVR, answer only after a positive pending result, then run
   `StopMixMonitor()` before private collection. Keep AGI/DTMF debug off.
7. The helper contexts must not be included from `from-internal` or exposed as a
   SIP feature code. Digitalogic has no approved public IVR digit.
8. Keep the per-site HMAC key root-owned, non-symlinked, inaccessible to others,
   and separate from outbound `/call` credentials and every other site.
9. HTTPS validation stays enabled; redirects are rejected; timestamps, nonces,
   event IDs, body size, response size, and timeouts are bounded.
10. WordPress must atomically check an unexpired 120-second challenge for the exact
    normalized ANI. Browser-bound one-time consumption remains required for login.

Outbound `/call` remains independently authenticated, loopback-only, opt-in, rate
limited, and redacted. Never reuse the inbound HMAC key as an outbound bearer token.
