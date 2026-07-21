#!/usr/bin/env bash
set -euo pipefail

install_root="${OFFICE_AUTOMATION_ROOT:-/opt/office-automation}"
sip_env="${OFFICE_AUTOMATION_SIP_ENV_FILE:-/etc/office-automation-sip-notifier.env}"
watchdog_env="${APACHE_SITE_WATCHDOG_ENV_FILE:-/etc/apache-site-watchdog.env}"
sip_service="${OFFICE_AUTOMATION_SIP_SERVICE:-office-automation-sip-notifier.service}"
watchdog_service="${APACHE_SITE_WATCHDOG_SERVICE:-apache-site-watchdog.service}"

usage() {
	printf 'Usage: %s [--plan|--apply] <existing-release-id>\n' "$0" >&2
}

mode="${1:---plan}"
release_id="${2:-}"
if [[ "$mode" != "--plan" && "$mode" != "--apply" ]]; then
	usage
	exit 2
fi
if [[ -z "$release_id" || ! "$release_id" =~ ^[A-Za-z0-9._-]+$ ]]; then
	usage
	exit 2
fi
if [[ "$install_root" != /* || "$install_root" == / ]]; then
	printf 'OFFICE_AUTOMATION_ROOT must be an absolute non-root path.\n' >&2
	exit 2
fi

release_dir="$install_root/releases/$release_id"
[[ -d "$release_dir" ]] || { printf 'Release does not exist: %s\n' "$release_dir" >&2; exit 1; }
(
	cd -- "$release_dir"
	sha256sum -c manifest.sha256
)
OFFICE_AUTOMATION_SIP_ENV="$sip_env" node "$release_dir/lib/office-automation-sip-notifier.cjs" check-config >/dev/null
APACHE_SITE_WATCHDOG_ENV="$watchdog_env" node "$release_dir/lib/apache-site-watchdog-to-n8n.cjs" check-config >/dev/null

printf 'Rollback plan: atomically switch %s/current to release %s and restart both services.\n' "$install_root" "$release_id"
printf 'The n8n workflow is not changed by this script; restore its separately exported backup as documented.\n'
if [[ "$mode" == "--plan" ]]; then
	exit 0
fi

previous_target="$(readlink "$install_root/current" 2>/dev/null || true)"
temporary_link="$install_root/.current-$release_id-$$"

restore_previous() {
	set +e
	rm -f -- "$temporary_link"
	if [[ -n "$previous_target" ]]; then
		ln -s "$previous_target" "$temporary_link"
		mv -Tf -- "$temporary_link" "$install_root/current"
		ln -sfn current/lib/office-automation-sip-notifier.cjs "$install_root/office-automation-sip-notifier.cjs"
		ln -sfn current/lib/apache-site-watchdog-to-n8n.cjs "$install_root/apache-site-watchdog-to-n8n.cjs"
	else
		rm -f -- \
			"$install_root/current" \
			"$install_root/office-automation-sip-notifier.cjs" \
			"$install_root/apache-site-watchdog-to-n8n.cjs"
	fi
	systemctl restart "$sip_service" "$watchdog_service" || true
}

rollback_armed=true
on_rollback_error() {
	status=$?
	trap - ERR
	if [[ "$rollback_armed" == true ]]; then
		printf 'Rollback failed before verification; restoring the prior current link.\n' >&2
		restore_previous
	fi
	exit "$status"
}
trap on_rollback_error ERR

ln -s "releases/$release_id" "$temporary_link"
mv -Tf -- "$temporary_link" "$install_root/current"
ln -sfn current/lib/office-automation-sip-notifier.cjs "$install_root/office-automation-sip-notifier.cjs"
ln -sfn current/lib/apache-site-watchdog-to-n8n.cjs "$install_root/apache-site-watchdog-to-n8n.cjs"

if ! systemctl restart "$sip_service" "$watchdog_service" \
	|| ! systemctl is-active --quiet "$sip_service" \
	|| ! systemctl is-active --quiet "$watchdog_service"; then
	printf 'Rollback target failed service checks; restoring the prior current link.\n' >&2
	rollback_armed=false
	trap - ERR
	restore_previous
	exit 1
fi

rollback_armed=false
trap - ERR
printf 'Rolled back office-automation scripts to release %s.\n' "$release_id"
