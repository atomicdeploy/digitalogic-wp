# Digitalogic PBX operational assets

Version 1.1.0 provides a dependency-free Node 18+ AGI helper for caller-aware
phone verification at `+982166754123`. Both TCI entry routes check for a live
website challenge before caller ID normalization and operator routing. A matching
challenge opens the private keypad prompt. Other callers continue to the operator.
No public verification digit exists.
Before the existing internal callback prefix is added, inbound caller IDs beginning
with `989`, `0989`, or `00989` are canonicalized to the Iranian local `09` form.
Tehran landlines also lose the same-city `021` prefix, so extension redial uses a
number the TCI trunk accepts.

The wrapper forwards one reviewed mode to the helper:

- `preflight`: signed ANI lookup with no audio. It sets only
  `PBX_VERIFY_PENDING=0|1`.
- `shortcut`: a BGM-mixed private code flow. Star, no input, partial input, and
  service failure return immediately to the operator flow; a rejected six-digit
  typo gets one bounded retry before fallback.
- `verify`: retained only as an unrouted building block for a future approved IVR.

The helper never places a code in a dialplan variable, AGI argument, URL, log, or
recording. It sends two path-bound HMAC-SHA256 request types. The pending request
uses this exact envelope and receives exactly `{ "pending": <boolean> }`:

```text
POST /wp-json/digitalogic/v1/call-verification/pbx-pending
schema, site_id, event_id, call_id, called_number, caller_number, occurred_at
schema = phone-verification-pending.v1
```

Confirmation remains:

```text
POST /wp-json/digitalogic/v1/call-verification/pbx-confirm
schema = phone-verification.v1
```

Both requests carry `X-PBX-Key-Id`, timestamp, nonce, and signature headers. The
canonical bytes, without a trailing newline, are:

```text
v1\nPOST\n<exact path>\n<timestamp>\n<nonce>\n<sha256(raw JSON body)>
```

The HMAC key is the decoded random bytes in the root-owned base64 secret file.
Redirects are refused and response size/time are bounded. Preflight errors
fall through to the operator. Only successful confirmation verifies the code.
Every dedicated verification prompt uses the established filtered, limited BGM
mix so retries, success, invalid-code, and failure audio remain stylistically
consistent without masking the speech.

Run:

```bash
cd ops/pbx
npm run check
npm test
bash -n bin/pbx-verification-digitalogic scripts/*.sh
```

See [DEPLOYMENT.md](DEPLOYMENT.md), [SECURITY.md](SECURITY.md), and
[ROLLBACK.md](ROLLBACK.md).
