#!/usr/bin/env bash
set -euo pipefail

root="${1:-.}"
found=()
while IFS= read -r -d '' path; do
    found+=("$path")
done < <(
    find "$root" -type f \
        \( -iname '*.bak*' -o -iname '*.backup*' -o -iname '*.old*' -o -iname '*.orig*' -o -iname '*.save*' -o -name '*~' \) \
        -not -path '*/.git/*' \
        -print0
)

if ((${#found[@]} > 0)); then
    printf 'Refusing to package public backup artifacts:\n' >&2
    printf '  %s\n' "${found[@]}" >&2
    exit 1
fi

printf 'No public backup artifacts found.\n'
