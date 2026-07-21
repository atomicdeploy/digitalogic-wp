# Office automation notifications

This directory is the secret-free source of truth for the production PHP-FPM/Apache watchdog, SIP notifier, and n8n event-routing workflow. Runtime credentials, webhook paths, chat identifiers, PBX destinations, aliases, and endpoint lists belong only in root-owned configuration outside the repository.

## Routing contract

The same channel decision is enforced twice:

1. The watchdog writes its configured allow-list to both `channels` and `notify_channels`.
2. n8n calls `POST /v1/route` and passes each notification node through its matching route gate. There is no direct edge from the routing-policy node to the SIP notification node.
3. `POST /v1/notify` independently rejects SIP when an explicit channel list is present and does not contain `sip`. It returns a successful skipped result with `reason: sip_channel_not_requested` and performs zero sends. Successful responses expose counts and coarse route types, never destination identities.

Payloads that omit both channel fields retain the legacy routing behavior. When SIP is explicitly requested, the notifier still applies the channel category policy, endpoint category/mute policy, and configured subscriber/default-endpoint eligibility before sending.

The slowlog reader counts canonical PHP-FPM request headers. Stack frames and diagnostic lines remain available as bounded samples but never inflate the request count. Before the n8n workflow writes its audit, Redis, or Sheets records, it redacts credential-bearing and private phone-routing fields and bearer/query-style secrets.

## Contents

- `lib/apache-site-watchdog-to-n8n.cjs`: watchdog and canonical slowlog request parser.
- `lib/office-automation-sip-notifier.cjs`: route policy, endpoint policy, HTTP API, and SIP sender.
- `n8n/office-automation-events.template.json`: inactive workflow template with placeholders.
- `scripts/render-n8n-workflow.cjs`: renders a populated workflow only to an absolute path outside the repository.
- `scripts/deploy.sh` and `scripts/rollback.sh`: plan-first immutable script-release switches.
- `config/*.env.example`: names and safe defaults only; sensitive values are deliberately blank.

## Local verification

```sh
npm --prefix ops/office-automation run check
npm --prefix ops/office-automation test
bash -n ops/office-automation/scripts/deploy.sh
bash -n ops/office-automation/scripts/rollback.sh
node ops/office-automation/scripts/render-n8n-workflow.cjs --check
```

The renderer check requires every `OFFICE_N8N_*` variable listed in the renderer source. Use synthetic values in CI and a protected runtime environment in production. A rendered workflow is always inactive.

See [DEPLOYMENT.md](DEPLOYMENT.md) and [ROLLBACK.md](ROLLBACK.md) for the owner-run procedures.
