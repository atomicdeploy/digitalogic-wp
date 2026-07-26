# Digitalogic event mesh deployment assets

This directory is the secret-free source for the n8n subworkflow and RouterOS DHCP lease hook.

The parent `Office - Automation Events` workflow should call the rendered subworkflow directly from the raw webhook item, before its audit normalizer redacts caller fields. The supplied deterministic patch waits for the child so failures reach the parent's existing error workflow, disables n8n success, error, progress, and manual execution-data retention for that raw-input workflow, and removes routine RouterOS presence items from its existing audit/alert branch. Device and caller identities are therefore not retained as completed n8n executions or dispatched as routine notifications. The subworkflow records only mapped RouterOS DHCP/Wi-Fi evidence and acts only on Asterisk calls already marked `incoming_confirmed` by `DialBegin` direction evidence.

Runtime-only values:

- n8n workflow and credential IDs/names, including the explicit parent-workflow caller allowlist;
- the explicit workstation notification audience;
- the private Office Automation webhook URL;
- RouterOS credentials and device identities;
- the n8n event key;
- the RouterOS subject pepper used to pseudonymize a device before it leaves the router.

Do not put those values in Git, issue/PR text, logs, or rendered artifacts retained outside protected server storage.

RouterOS lease events are intentionally sent for all DHCP transitions. Before transmission, the router combines its private pepper with the lease MAC, transforms that value with SHA-512, and omits the raw MAC, IP, and hostname. The Digitalogic node hashes that opaque subject again and WordPress accepts only identities in its private operator mapping. Unmapped devices return a quiet `unmapped_router_subject` skip.

The generated hook requires a RouterOS 7 release that supports
`:convert transform=sha512`. Confirm that command in a bounded router canary
before installing the hook. Build the private WordPress subject mapping from
the same pepper and the router's canonical lease-MAC text outside the
repository; neither input belongs in source, workflow exports, or logs.

Presence remains evidence-based:

- fresh Windows unlock is high-confidence presence;
- a fresh mapped Android lease is medium-confidence presence;
- Windows lock alone stays unknown;
- locked/suspended plus a fresh mapped-phone departure becomes away.

For Google Sheets or another CRM source, add the existing credentialed source node before `Resolve Caller and Asterisk History` and place its bounded candidates in `external_candidates`. No Google or customer-source credentials belong in the Digitalogic node.
