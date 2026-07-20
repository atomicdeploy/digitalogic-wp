# Patris pricing-input machine credential

Digitalogic exposes one least-privilege machine identity for the two read-only
contracts needed by Patris pricing:

- `GET /wp-json/digitalogic/integration/catalog`
- `POST /wp-json/digitalogic/integration/pricing-assignments/batch`

The second operation uses POST only because it accepts a bounded JSON Code
list. It does not mutate products, assignments, prices, options, events, or
locks.

The catalog GET is also strictly non-mutating. If activation/init migration has
not run yet, it projects the canonical default shipping methods in memory; it
does not use the request to migrate or persist them.

The credential is not accepted by any other route or method. It is also not a
Patris push token, product-sync receiver secret, webhook secret, WooCommerce
consumer key, WordPress login, panel token, or WebSocket token. Only an exact
`Authorization: Bearer ...` header is read. Query parameters, cookies, URL
userinfo, Basic authentication, legacy headers, and product-sync headers do not
act as this machine identity.

## Administrator lifecycle

Run lifecycle commands with an explicit WordPress administrator. A shop manager
is intentionally insufficient even though shop managers retain normal
`manage_woocommerce` access to the two REST contracts.

```bash
wp digitalogic pricing-input-credential create --user=<administrator-login>
wp digitalogic pricing-input-credential status --user=<administrator-login>
wp digitalogic pricing-input-credential rotate --user=<administrator-login>
wp digitalogic pricing-input-credential revoke --user=<administrator-login>
```

Create and rotate print the new Bearer value on the first output line exactly
once, followed by nonsecret JSON metadata. Move that first line directly into
the protected Patris process environment. Status and revoke output metadata
only. Rotation and revocation invalidate the preceding value immediately.

Lifecycle mutations use a bounded, site-specific MySQL advisory lock plus a
row-locked transaction and exact readback. Create and rotate compare the
authoritative record they observed before waiting with the record inside the
lock, so overlapping callers cannot both reveal credentials. Revoke always
acts on the current locked generation, which prevents a waiting rotation from
undoing it. A secret is printed only after its transaction commits and passes
readback; retry a reported busy or concurrent-change error as a new command.

WordPress stores only a SHA-256 verifier for the high-entropy generated value,
its nonsecret ID/generation, state, and UTC lifecycle timestamps. The verifier
is compared in constant time. Repeated failed supplied-Authorization attempts
are throttled in a token-free client/generation bucket; a correct credential is
verified before that bucket and clears it, so unrelated failures cannot lock
Patris out. Tokens are not written to application logs or throttle state.
Verification and status bypass WordPress/Redis option caches, so a stale cached
verifier cannot extend an old credential after rotation or revocation.

## Patris environment wiring

Use a secret environment variable for the value and configure Patris by the
variable's name. Do not put the credential in Patris JSON/TOML/YAML, source
control, service arguments, screenshots, or shell history.

```text
PATRIS_EXPORT_DIGITALOGIC_URL=https://digitalogic.ir/wp-json/digitalogic/
PATRIS_EXPORT_DIGITALOGIC_BEARER_ENV=DIGITALOGIC_PRICING_INPUT_TOKEN
DIGITALOGIC_PRICING_INPUT_TOKEN=<value emitted once by WP-CLI>
```

`PATRIS_EXPORT_DIGITALOGIC_BEARER_ENV` is the environment-name setting consumed
by Patris `BearerTokenEnv`; it is not the credential itself.

## Deployment canaries

After deployment and protected environment configuration, verify both exact
contracts. The examples intentionally read the token from the process
environment and contain no literal credential.

```bash
curl -fsS \
  -H "Authorization: Bearer ${DIGITALOGIC_PRICING_INPUT_TOKEN}" \
  https://digitalogic.ir/wp-json/digitalogic/integration/catalog

curl -fsS \
  -H "Authorization: Bearer ${DIGITALOGIC_PRICING_INPUT_TOKEN}" \
  -H 'Content-Type: application/json' \
  --data '{"codes":["113001002"]}' \
  https://digitalogic.ir/wp-json/digitalogic/integration/pricing-assignments/batch
```

Before enabling Patris, also confirm that absent and deliberately wrong Bearer
headers are denied on both routes, and that the correct Bearer is denied on a
representative unrelated route such as `GET /products`. After rotation, repeat
with the old value to confirm immediate invalidation, then restart Patris with
the new protected environment value.
