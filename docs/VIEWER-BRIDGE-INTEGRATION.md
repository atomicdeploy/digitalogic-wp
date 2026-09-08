# Shared ViewerBridge runtime

The live catalog and action integration belongs to the Digitalogic PHP codebase under `includes/integrations/viewer-bridge`. The initial nine class files were captured from the running Digitalogic Live Viewer Bridge 0.4.5 plugin, including the production-tested post-commit event and request-local product reuse corrections. The earlier repository origin was not found; this is a live-source import, not a claim of recovered Git history.

`Digitalogic::init_early_integrations()` invokes `Runtime::register()` once per process. Composer class loading remains side-effect-free. WordPress owns hook registration, including when Laravel uses the same process. REST routes, trash status registration and post-commit event hooks keep their existing priorities. Main plugin activation delegates storage provisioning to `Store::activate()`.

## Deployment boundary

Do not deploy the new bootstrap while the standalone `digitalogic-viewer-bridge/digitalogic-viewer-bridge.php` plugin remains active. Both declare the same classes. Stage the shared files first, fence new web/worker requests for the cutover, remove the standalone active-plugin entry and replace the main bootstrap together, then resume requests and verify service health, class provenance, routes and changed-price delivery. Account for persistent WebSocket workers and PHP opcode caches. Retain an operational recovery copy outside active plugin loading until acceptance; no compatibility shim is part of the runtime.

Existing installations already have the ViewerBridge tables and capabilities. Verify that state during cutover; provision deliberately if missing. Do not run table installation on every pricing request. New installations use main plugin activation.

## Acceptance

The isolated server WP-CLI boot excludes the standalone plugin only in that process. Shared hook registration matches the standalone operational hooks, even when `Runtime::register()` is called twice. This does not prove deployed HTTP behavior. Compare full product projections, then verify the actual deployed class paths, authenticated routes and changed-price publication before committing release claims or merging the PR. Neither unchanged prices nor event-stream advancement alone proves complete destination acceptance.

The completed pre-cutover comparison returned 902 products from each runtime with identical complete payload SHA-256 digests, no projection errors and zero product revision mismatches. All ten staged PHP files match their local SHA-256 values and pass server PHP lint. The live main bootstrap differs only by the integration registration and activation additions. These checks prove the staged candidate; the standalone plugin remains active until cutover. Home HTTP 200 and anonymous state API HTTP 401 were observed before cutover.

### Production cutover completed

The controlled cutover subsequently committed the active-plugin change from 54 to 53 entries and replaced the main bootstrap. Fresh WP-CLI acceptance confirmed the standalone plugin inactive, main plugin active, schema ready, and Runtime/Live_State/Events/Rest/Store classes loaded from the shared directory. Event and REST hooks were present. Apache, PHP-FPM, WebSocket and the cron timer resumed; the home page returned HTTP 200 and target price remained 814600. The inactive standalone files remain on disk pending explicit removal; there is no active compatibility loader. Post-cutover changed-rate acceptance is recorded separately and must not be inferred from these boot checks.

### Post-cutover native adapter result
The changed-rate run completed successfully but took 65.297 seconds for 901 WooCommerce saves, exceeding the 60-second critical threshold. Save callback time was 24.909 seconds versus 21.674 seconds in the earlier 59.468-second run; this explains only part of the increase and does not establish causation by integration. Eight panel writes, none under pricing locks. CNY and direct_db mode restored to 34000 and target price 814600. Performance acceptance remains open; do not merge on the basis of boot parity or one earlier sub-60 run.
