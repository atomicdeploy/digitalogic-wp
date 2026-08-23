# Autonomous WordPress cron runner

The pricing snapshot API persists exact build and watchdog one-shots in both
Action Scheduler and WP-Cron. Neither store is an executor: a low-traffic site
or a server-origin loopback rejected by the public WAF can leave both records
pending beyond the 30-second build-start deadline. These source-controlled
systemd units execute due WordPress events every 10 seconds without polling
pricing state or invoking a snapshot build directly.

The oneshot timeout is 35 minutes: five minutes beyond the plugin's fixed
30-minute build lifetime. The plugin's own lease, heartbeat, cancellation, and
watchdog fences therefore remain authoritative for long cold projections; the
service cannot kill an otherwise healthy worker at the historical two-minute
Excel load boundary.

The timer is the sole WordPress core cron executor. Production must define
`DISABLE_WP_CRON=true` before the timer is enabled, preventing a later public
request from starting a second all-due runner after the core lock expires.
The service has an `ExecStartPre` guard and fails without running callbacks if
that constant is absent or false. Action Scheduler remains an independent
durable path, while the snapshot worker lease makes any late sibling a no-op.

## Render and review

Resolve the WordPress root, WP-CLI binary, and the existing WordPress runtime
user/group on the server without printing credentials or private routing data.
Require the resolved runtime UID and GID to be nonzero, the WP-CLI binary to be
executable by that identity, and `wp-config.php` to be readable before render.
The renderer rejects the literal `root` user or group.
Render into a new root-owned temporary directory:

```bash
node render-units.cjs \
  --wordpress-root "$WORDPRESS_ROOT" \
  --wp-cli "$WP_CLI_BIN" \
  --runtime-user "$WORDPRESS_RUNTIME_USER" \
  --runtime-group "$WORDPRESS_RUNTIME_GROUP" \
  --output-dir "$RENDER_DIR"
systemd-analyze verify \
  "$RENDER_DIR/digitalogic-wordpress-cron.service" \
  "$RENDER_DIR/digitalogic-wordpress-cron.timer"
```

The renderer rejects whitespace, traversal, relative paths, unsafe identities,
duplicate arguments, and unresolved placeholders. It never prints the rendered
paths. Keep the rendered units and any backups outside the public web root.

## Rollback-bracketed install

Before a write, record whether each exact unit exists and capture its SHA-256,
active/enabled state, `MainPID`, and timer next-elapse fields. Copy any existing
unit and `wp-config.php` to a root-only rollback directory; the configuration
backup contains credentials and must never enter logs or artifacts. Confirm
there is no existing systemd, crontab, or external WordPress cron executor and
record the due-event count without hook arguments. Set `DISABLE_WP_CRON=true`
without changing any other configuration, install only these exact two reviewed
files under `/etc/systemd/system/`, then run `systemctl daemon-reload` and
`systemctl enable --now digitalogic-wordpress-cron.timer`.

Do not manually start the oneshot for acceptance. Read back the installed file
hashes, rendered `User`, `Group`, `WorkingDirectory`, nonzero runtime UID/GID,
binary executability, `wp-config.php` readability, timer enabled/active state,
last/next trigger, service result, and journal exit status after the timer fires naturally. A live
snapshot gate must then prove a worker lease within 30 seconds, one terminal
event, no duplicate work after 60 seconds, cleanup of both scheduler siblings
and outboxes, and no new failed unrelated cron hook. Include `Result`,
`ExecMainStatus`, and a readback showing neither exact unit is failed.

## Exact rollback

Disable and stop only `digitalogic-wordpress-cron.timer` so no new oneshot can
start. If the service or a snapshot job is active, do not replace its unit or
kill its process: wait for the terminal event and inactive service. When urgent
rollback cannot wait, first request the exact authenticated snapshot
cancellation and wait for its terminal acknowledgement. A forced service stop
is an exceptional destructive fallback that requires an explicit record of the
interrupted job and resulting terminal recovery.

Only after the service is inactive, restore the two exact unit backups when
they existed (otherwise remove only the newly installed unit files), and
restore the exact private `wp-config.php` backup before running `systemctl
daemon-reload`. Restore the recorded enable/active state and verify the timer,
service, `Result`, `ExecMainStatus`, failed-unit state, and `DISABLE_WP_CRON`
readback afterward. Plugin rollback remains
the separate immutable previous-release ZIP; the cron runner contains no plugin
or database migration.
