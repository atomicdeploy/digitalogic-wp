#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
source_root="$(cd -- "$script_dir/.." && pwd)"
install_root="${OFFICE_AUTOMATION_ROOT:-/opt/office-automation}"
sip_env="${OFFICE_AUTOMATION_SIP_ENV_FILE:-/etc/office-automation-sip-notifier.env}"
watchdog_env="${APACHE_SITE_WATCHDOG_ENV_FILE:-/etc/apache-site-watchdog.env}"
sip_service="${OFFICE_AUTOMATION_SIP_SERVICE:-office-automation-sip-notifier.service}"
watchdog_service="${APACHE_SITE_WATCHDOG_SERVICE:-apache-site-watchdog.service}"

usage() {
	printf 'Usage: %s [--plan|--apply] <release-id>\n' "$0" >&2
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

for file in \
	lib/office-automation-sip-notifier.cjs \
	lib/apache-site-watchdog-to-n8n.cjs; do
	[[ -f "$source_root/$file" ]] || { printf 'Missing release source: %s\n' "$file" >&2; exit 1; }
	node --check "$source_root/$file"
done

release_dir="$install_root/releases/$release_id"
printf 'Release plan: install reviewed scripts as %s and atomically switch %s/current.\n' "$release_id" "$install_root"
printf 'The n8n workflow is not imported or activated by this script.\n'
if [[ "$mode" == "--plan" ]]; then
	exit 0
fi

if [[ -e "$release_dir" ]]; then
	printf 'Release already exists; refusing to overwrite: %s\n' "$release_dir" >&2
	exit 1
fi

install -d -o root -g root -m 0755 "$install_root/releases" "$release_dir/lib" "$install_root/backups"
install -o root -g root -m 0755 "$source_root/lib/office-automation-sip-notifier.cjs" "$release_dir/lib/office-automation-sip-notifier.cjs"
install -o root -g root -m 0755 "$source_root/lib/apache-site-watchdog-to-n8n.cjs" "$release_dir/lib/apache-site-watchdog-to-n8n.cjs"
(
	cd -- "$release_dir"
	sha256sum lib/office-automation-sip-notifier.cjs lib/apache-site-watchdog-to-n8n.cjs > manifest.sha256
)
chmod 0644 "$release_dir/manifest.sha256"

OFFICE_AUTOMATION_SIP_ENV="$sip_env" node "$release_dir/lib/office-automation-sip-notifier.cjs" check-config >/dev/null
APACHE_SITE_WATCHDOG_ENV="$watchdog_env" node "$release_dir/lib/apache-site-watchdog-to-n8n.cjs" check-config >/dev/null

backup_dir="$install_root/backups/$release_id"
install -d -o root -g root -m 0700 "$backup_dir"
for name in office-automation-sip-notifier.cjs apache-site-watchdog-to-n8n.cjs; do
	legacy="$install_root/$name"
	if [[ -e "$legacy" && ! -L "$legacy" ]]; then
		mv -- "$legacy" "$backup_dir/$name"
		chmod 0700 "$backup_dir/$name"
	fi
done

previous_target="$(readlink "$install_root/current" 2>/dev/null || true)"
temporary_link="$install_root/.current-$release_id-$$"

restore_previous() {
	set +e
	rm -f -- "$temporary_link"
	if [[ -n "$previous_target" ]]; then
		ln -s "$previous_target" "$temporary_link"
		mv -Tf -- "$temporary_link" "$install_root/current"
	else
		rm -f -- "$install_root/current"
	fi
	for name in office-automation-sip-notifier.cjs apache-site-watchdog-to-n8n.cjs; do
		stable="$install_root/$name"
		if [[ -f "$backup_dir/$name" ]]; then
			rm -f -- "$stable"
			mv -- "$backup_dir/$name" "$stable"
		elif [[ -n "$previous_target" ]]; then
			ln -sfn "current/lib/$name" "$stable"
		else
			rm -f -- "$stable"
		fi
	done
	systemctl restart "$sip_service" "$watchdog_service" || true
}

rollback_armed=true
on_deploy_error() {
	status=$?
	trap - ERR
	if [[ "$rollback_armed" == true ]]; then
		printf 'Deployment failed before verification; restoring the previous script release.\n' >&2
		restore_previous
	fi
	exit "$status"
}
trap on_deploy_error ERR

ln -s "releases/$release_id" "$temporary_link"
mv -Tf -- "$temporary_link" "$install_root/current"
ln -sfn current/lib/office-automation-sip-notifier.cjs "$install_root/office-automation-sip-notifier.cjs"
ln -sfn current/lib/apache-site-watchdog-to-n8n.cjs "$install_root/apache-site-watchdog-to-n8n.cjs"

if ! systemctl restart "$sip_service" "$watchdog_service" \
	|| ! systemctl is-active --quiet "$sip_service" \
	|| ! systemctl is-active --quiet "$watchdog_service"; then
	printf 'Service verification failed; restoring the previous script release.\n' >&2
	rollback_armed=false
	trap - ERR
	restore_previous
	exit 1
fi

rollback_armed=false
trap - ERR
printf 'Installed office-automation release %s. Validate the dry-run routing probes before any separate n8n activation.\n' "$release_id"
