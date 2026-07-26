# Digitalogic event mesh deployment assets

This directory is the secret-free source for the n8n subworkflow and RouterOS DHCP lease hook.

The parent `Office - Automation Events` workflow should call the rendered subworkflow directly from the raw webhook item, before its audit normalizer redacts caller fields. The supplied deterministic patch disables n8n success, error, and progress execution-data retention for that raw-input workflow and removes routine RouterOS presence items from its existing audit/alert branch, so device and caller identities are not retained in n8n execution history or dispatched as routine notifications. The subworkflow records only mapped RouterOS DHCP/Wi-Fi evidence and acts only on Asterisk calls already marked `incoming_confirmed` by `DialBegin` direction evidence.

Runtime-only values:

- n8n workflow and credential IDs/names;
- the explicit workstation notification audience;
- the private Office Automation webhook URL;
- RouterOS credentials and device identities;
- the n8n event key.

Do not put those values in Git, issue/PR text, logs, or rendered artifacts retained outside protected server storage.

RouterOS lease events are intentionally sent for all DHCP transitions. The Digitalogic node hashes the device identity and WordPress accepts only identities in its private operator mapping. Unmapped devices return a quiet `unmapped_router_subject` skip.

Presence remains evidence-based:

- fresh Windows unlock is high-confidence presence;
- a fresh mapped Android lease is medium-confidence presence;
- Windows lock alone stays unknown;
- locked/suspended plus a fresh mapped-phone departure becomes away.

For Google Sheets or another CRM source, add the existing credentialed source node before `Resolve Caller and Asterisk History` and place its bounded candidates in `external_candidates`. No Google or customer-source credentials belong in the Digitalogic node.
