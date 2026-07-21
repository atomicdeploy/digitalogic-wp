# Deployment runbook

Deployment is intentionally split into two independently reversible changes: the notifier/watchdog scripts and the n8n workflow. The repository scripts never import, publish, or activate n8n and never change notification destinations.

## 1. Prepare and verify

1. Use an exact reviewed commit SHA as the release identifier.
2. Run the checks in `README.md` on that checkout.
3. Export the currently active n8n workflow through the host's supported UI or CLI, store the export outside the repository with owner-only permissions, and record its checksum privately.
4. Confirm both current script services are active. Back up the root-owned environment files and current subscriber/preference data without printing their contents.
5. Copy each `.env.example` to its documented root-owned location, mode `0600`, and supply all required values there. The notifier requires `HTTP_PORT`, `API_TOKEN`, `MESSAGE_FROM`, `AMI_PORT`, `AMI_USER`, and `AMI_SECRET`. If inter-office forwarding is configured, `INTEROFFICE_FORWARD_TARGET` is also required. The watchdog requires `WEBHOOK_URL`, `SITE_URLS`, and `NOTIFY_CHANNELS`.

Keep `NOTIFY_CHANNELS=ntfy,telegram` when SIP is not intended. Never put populated configuration in a command line, shell history, issue, PR, workflow export committed to Git, or test fixture.

## 2. Deploy notifier and watchdog sources

Review the plan first:

```sh
sudo ops/office-automation/scripts/deploy.sh --plan <reviewed-commit-sha>
```

Then perform the owner-approved switch:

```sh
sudo ops/office-automation/scripts/deploy.sh --apply <reviewed-commit-sha>
```

The script validates syntax and configuration, creates an immutable release with a SHA-256 manifest, preserves legacy regular-file entrypoints on the first deployment, atomically switches `current`, restarts both existing services, and verifies both are active. A failed service check restores the prior release or first-deployment files automatically.

This procedure assumes the existing systemd units already invoke the stable paths under `/opt/office-automation`. Override paths and service names only with the documented `OFFICE_AUTOMATION_*` and `APACHE_SITE_WATCHDOG_*` environment variables.

## 3. Render and review n8n

Load all `OFFICE_N8N_*` values from a protected owner-only environment and render to a protected absolute path outside the checkout:

```sh
node ops/office-automation/scripts/render-n8n-workflow.cjs --check
node ops/office-automation/scripts/render-n8n-workflow.cjs --output /var/lib/office-automation/private/office-events.review.json
```

The renderer refuses repository destinations, unresolved placeholders, non-HTTP(S) endpoints, active workflows, a missing SIP gate, or a direct route-to-SIP bypass. It creates output mode `0600` and refuses overwrite unless `--force` is explicitly supplied.

Diff topology and non-secret settings against the private export. Import the rendered file with the host's supported n8n procedure. It remains inactive after import; publish/activate it only after the owner verifies credentials, webhook ownership, and every node connection in the n8n UI. Preserve the old workflow until validation finishes.

## 4. Production validation

Use request bodies from protected files and read the bearer token from protected configuration so neither appears in shell history or logs.

1. `POST /v1/route` with a synthetic `web_health` warning and `notify_channels: ["ntfy", "telegram"]`. Expect `channels.sip` to be `false`.
2. `POST /v1/notify` with the same payload. Expect HTTP success, `skipped: true`, `reason: sip_channel_not_requested`, empty endpoints/results, and zero AMI send activity.
3. Run the n8n workflow with the same negative fixture. Its execution view must show the SIP gate emitting zero items and the SIP notification node not executing.
4. Verify a new slowlog batch reports the number of canonical request headers, not its stack-trace line count.
5. The automated positive fixture proves explicit SIP reaches only subscribed/default endpoints. Perform any live positive SIP probe only with an owner-approved test destination, and keep its identity out of shared evidence.

Record only timestamps, commit/workflow revision, checksums, boolean route outcomes, skip reasons, counts, and service health in the issue or PR. Do not paste payloads, endpoints, aliases, tokens, credential identifiers, private webhook paths, or notification destinations.
