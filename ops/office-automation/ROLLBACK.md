# Rollback runbook

Script and n8n rollback are separate operations. Decide which revision is faulty before changing either one.

## Script release

List the immutable release directories locally on the host, choose the last known-good reviewed release, and inspect the plan:

```sh
sudo ops/office-automation/scripts/rollback.sh --plan <known-good-release-id>
```

Apply only after the owner confirms the target:

```sh
sudo ops/office-automation/scripts/rollback.sh --apply <known-good-release-id>
```

The rollback verifies the stored SHA-256 manifest and both environment configurations before switching `current`. It restarts and checks both services. If the target fails, it restores the previously active link and restarts the services again.

## n8n workflow

1. Deactivate the faulty workflow with the host's supported n8n UI or CLI.
2. Import the private pre-deployment export captured by the deployment runbook.
3. Compare credential bindings and topology without copying their values into shared logs.
4. Keep the restored import inactive until the owner reviews it, then publish/activate it using the same supported host procedure.
5. Repeat the negative allow-list probe and confirm the SIP node receives zero items before declaring rollback complete.

Do not delete immutable releases, current exports, subscriber state, preference state, or root-owned configuration during incident response. Evidence should include only revision/checksum, timestamps, boolean routing results, skip reasons, counts, and service status.
