#!/usr/bin/env bash
set -euo pipefail

root="${1:-.}"
if [[ -x /usr/bin/find ]]; then
    find_bin=/usr/bin/find
else
    find_bin="$(command -v find || true)"
fi
if [[ -z "$find_bin" ]] || [[ "${find_bin,,}" == *.exe ]]; then
    printf 'A Unix find implementation is required for the public-tree check.\n' >&2
    exit 1
fi

scan_file="$(mktemp "${TMPDIR:-/tmp}/digitalogic-public-tree.XXXXXX")"
trap 'rm -f -- "$scan_file"' EXIT
if ! "$find_bin" "$root" -type f \
    \( -iname '*.bak*' -o -iname '*.backup*' -o -iname '*.old*' -o -iname '*.orig*' -o -iname '*.save*' -o -name '*~' \
	   -o -iname 'kala.json' -o -iname 'kala.db' -o -iname '*.sqlite' -o -iname '*.sqlite3' \
	   -o -iname 'reportfinal.php' -o -iname 'reportproduts.php' -o -iname 'reportproducts.php' \) \
    -not -path '*/.git/*' \
    -print0 >"$scan_file"; then
    printf 'Public-tree scan failed; refusing to continue.\n' >&2
    exit 1
fi

found=()
while IFS= read -r -d '' path; do
    found+=("$path")
done <"$scan_file"

if ((${#found[@]} > 0)); then
	printf 'Refusing to package backup, production data, or standalone report artifacts:\n' >&2
    printf '  %s\n' "${found[@]}" >&2
    exit 1
fi

printf 'No public backup, production data, or standalone report artifacts found.\n'
