# Pricing option cutover before activation

The shared pricing runtime reads `digitalogic_pricing_settings` and
`digitalogic_pricing_audit`. Before activating it on a site that has the old
`digitalogic_excel_pricing_sync_settings` / `digitalogic_excel_pricing_sync_audit`
options, run the explicit one-time script below. The plugin has no runtime alias
or automatic migration. This procedure does not migrate the receiver ledger;
its separate input-baseline prerequisite still applies.

1. Back up the site's database using the normal protected backup process. Stage
   `scripts/cutover-pricing-options.php` outside the active plugin directory.
2. Quiesce **all pricing writers**: web/admin requests, workers, scheduled jobs,
   CLI processes and upstream deliveries. Keep them paused through the script
   **and activation of the new plugin code**. Advisory locks protect the script's
   transaction; an old process could recreate an old option after it releases
   its locks. Maintenance-page routing alone does not stop background writers.
3. Use the deployment host's WP-CLI with the correct installation path. For
   multisite, also supply `--url=<site-url>` to select the intended site's prefix.
   Run the default dry-run and inspect only its operation statuses:

   ```sh
   wp --path=/var/www/wp --skip-plugins --skip-themes eval-file /staged/cutover-pricing-options.php --use-include
   ```

4. Apply only after the dry-run succeeds, then repeat the dry-run to confirm
   `already_cutover` for existing destination options:

   ```sh
   wp --path=/var/www/wp --skip-plugins --skip-themes eval-file /staged/cutover-pricing-options.php apply --use-include
   wp --path=/var/www/wp --skip-plugins --skip-themes eval-file /staged/cutover-pricing-options.php --use-include
   ```

5. Activate the new code while writers remain paused, verify the pricing settings
   and downstream pricing through the normal acceptance procedure, then resume
   writers. Do not reactivate old code against renamed options without restoring
   the protected backup under the same paused-writer deployment procedure.

`apply` is a positional argument, not `--apply`. WP-CLI supplies these arguments
in `$args`; `--use-include` includes the script directly, preserving its scope.
See the official [eval-file documentation](https://developer.wordpress.org/cli/commands/eval-file/).
`--skip-plugins` does not skip must-use plugins; ensure those cannot start a pricing
writer during this window either. Never paste option contents or rates into logs.

The script takes the old pricing, new pricing and receiver advisory locks in that
order, using the runtime's `substr(base . '_' . md5($wpdb->prefix), 0, 64)` names.
It requires an InnoDB options table and uses one transaction. It copies exact
stored `option_value` bytes and `autoload`, preserving rates, independent dates,
provenance, revisions, existing schema metadata and the complete audit without
decoding or recalculating. It verifies **both** destinations before removing any
old key, verifies the final rows before commit, and evicts affected option caches
before releasing the locks. It never prints option payloads.

Different destination values or autoload flags stop the entire operation before
any write. Identical duplicates may be removed; an already completed cutover is
safe to rerun. If neither name exists, `absent` is reported and nothing is created.
Database failures roll back the transaction; any connection/commit/rollback
uncertainty requires inspecting protected database state before retrying, while
writers remain paused. Do not resolve a conflict by deleting either option
without establishing which protected value is authoritative.

Offline tests cover preservation, conflicts, duplicates, dry-run, repeat apply,
copy/readback/delete/commit failures, writer-lock loss, connection changes and
nontransactional tables. They use a transaction double; executing WP-CLI against
a real database is a deployment gate, not something this change has performed.
