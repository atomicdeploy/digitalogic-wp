#!/usr/bin/env bash
set -euo pipefail
umask 022

if [[ "$(id -u)" -ne 0 ]]; then
	printf '%s\n' 'Run as root.' >&2
	exit 20
fi

site="${1:-}"
version="${2:-}"
source_dir="${3:-}"
sound_root="${PBX_ASTERISK_SOUND_ROOT:-/var/lib/asterisk/sounds/custom/call-verification}"

[[ "$site" == digitalogic ]]
[[ "$version" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$ ]]
[[ -d "$source_dir" ]]
[[ "$sound_root" == /* ]]

release_dir="$sound_root/$site/releases/$version"
if [[ -e "$release_dir" ]]; then
	printf '%s\n' "Release already exists: $release_dir" >&2
	exit 20
fi

install -d -o asterisk -g asterisk -m 0755 "$release_dir"
for name in pending-code enter-code verified invalid temporary-failure; do
	test -s "$source_dir/$name.wav"
	test -s "$source_dir/$name.wav16"
	install -o asterisk -g asterisk -m 0644 "$source_dir/$name.wav" "$release_dir/$name.wav"
	install -o asterisk -g asterisk -m 0644 "$source_dir/$name.wav16" "$release_dir/$name.wav16"
done

link_tmp="$sound_root/$site/.current.$$.tmp"
ln -s "releases/$version" "$link_tmp"
mv -Tf "$link_tmp" "$sound_root/$site/current"
printf '%s\n' "Activated prompt release $site/$version"
