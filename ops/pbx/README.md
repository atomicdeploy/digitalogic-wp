# Digitalogic PBX operational assets

Version 1.0.0 provides a dependency-free Node 18+ AGI helper for inbound phone
verification at `+982166754123`, a safe digit-2 dialplan include, Persian prompt
sources, offline tests, and deployment/rollback instructions. It contains no
credentials and makes no live change merely by existing in the plugin repository.

The helper is intentionally an AGI process rather than `System()` with interpolated
arguments. It reads sanitized channel variables, collects DTMF with `GET DATA`,
validates all values, sends exact signed JSON, and never logs caller number, code,
request body, response body, or authorization material.

The WordPress callback contract is:

```text
POST /wp-json/digitalogic/v1/call-verification/pbx-confirm
X-PBX-Key-Id: v1
X-PBX-Timestamp: <10-digit Unix time>
X-PBX-Nonce: <base64url random value>
X-PBX-Signature: <64 lowercase hex HMAC-SHA256>
```

Canonical bytes, with no trailing newline, are:

```text
v1\nPOST\n<callback path>\n<timestamp>\n<nonce>\n<sha256(raw JSON body)>
```

The HMAC key is the decoded random bytes from the canonical base64 secret file, not
the printable base64 text itself.

A 2xx JSON response must explicitly contain `verified: true` or `status: "verified"`.
`success: true` alone is deliberately insufficient because it can mean only that the
request was processed. Invalid/expired challenges return a generic rejected response;
authentication, throttling, malformed responses, and timeouts fail closed.

Deterministic process exit codes are `0` verified, `10` untrusted/invalid call,
`11` code attempts exhausted, `20` configuration/secret failure, `30` network/timeout,
`40` remote authentication rejection, `50` AGI/HTTP protocol failure, and `60` hangup.
The helper also sets `PBX_VERIFY_RESULT` to a non-sensitive fixed status where the channel
is still available; the numeric exit remains authoritative.

See [DEPLOYMENT.md](DEPLOYMENT.md), [SECURITY.md](SECURITY.md), and
[ROLLBACK.md](ROLLBACK.md). Run locally with:

```bash
cd ops/pbx
npm run check
npm test
```
