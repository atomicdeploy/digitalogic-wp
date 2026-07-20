#!/usr/bin/env bash
set -euo pipefail

if (($# != 3)); then
    printf 'Usage: %s <vX.Y.Z-tag> <source-commit> <output.md>\n' "$0" >&2
    exit 2
fi

tag="$1"
source_commit="$2"
output="$3"
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$script_dir/.." && pwd)"

header_version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]+Version:[[:space:]]*([^[:space:]]+).*/\1/p' "$root/digitalogic.php" | head -n 1)"
constant_version="$(sed -nE "s/.*define\([[:space:]]*'DIGITALOGIC_VERSION',[[:space:]]*'([^']+)'[[:space:]]*\).*/\1/p" "$root/digitalogic.php" | head -n 1)"

if [[ "$tag" == "auto" ]]; then
    tag="v${header_version}"
fi
if [[ ! "$tag" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
    printf 'Release tag must use vX.Y.Z syntax: %s\n' "$tag" >&2
    exit 2
fi
version="${BASH_REMATCH[1]}"

if [[ "$version" != "$header_version" || "$version" != "$constant_version" ]]; then
    printf 'Tag %s does not match plugin versions (header=%s, constant=%s).\n' \
        "$tag" "${header_version:-missing}" "${constant_version:-missing}" >&2
    exit 1
fi

changelog="$(awk -v wanted="$version" '
    $0 == "## [" wanted "]" || index($0, "## [" wanted "] - ") == 1 {
        found = 1
        next
    }
    found && /^## \[/ { exit }
    found { print }
    END { if (!found) exit 1 }
' "$root/CHANGELOG.md")" || {
    printf 'CHANGELOG.md has no section for %s.\n' "$version" >&2
    exit 1
}

if [[ -z "${changelog//[[:space:]]/}" ]]; then
    printf 'CHANGELOG.md section for %s is empty.\n' "$version" >&2
    exit 1
fi

mkdir -p -- "$(dirname -- "$output")"
cat > "$output" <<EOF
# Digitalogic WooCommerce Extension ${version}

This release is an installable, production-only WordPress plugin package built and verified directly from source commit [\`${source_commit}\`](https://github.com/atomicdeploy/digitalogic-wp/commit/${source_commit}). It provides the Digitalogic product, pricing, currency, inventory, shipping-method, Patris integration, REST, webhook, polling, and administrative panel capabilities documented in the repository.

## Download and install

- **WordPress admin:** download \`digitalogic-wp.zip\`, then use **Plugins -> Add New Plugin -> Upload Plugin**.
- **WP-CLI:** run \`wp plugin install digitalogic-wp.zip --force --activate\`.
- **Versioned deployment:** \`digitalogic-wp-${tag}.zip\` contains the same verified bytes under an immutable release-specific name.

Both ZIP files have a single \`digitalogic-wp/\` plugin root and include production Composer dependencies. Development dependencies, tests, repository metadata, backup files, and editor artifacts are excluded.

## Verify the download

Download \`SHA256SUMS\` beside the ZIP files. To verify only the stable installer, run \`grep ' digitalogic-wp.zip$' SHA256SUMS | sha256sum -c -\`. To verify the complete release set, download both ZIPs and run \`sha256sum -c SHA256SUMS\`. On Windows, compare \`Get-FileHash .\\digitalogic-wp.zip -Algorithm SHA256\` with the matching line in \`SHA256SUMS\`.

## Changelog

${changelog}

## Source and build provenance

- Tag: \`${tag}\`
- Source commit: \`${source_commit}\`
- Builder: GitHub Actions from the tagged source tree
- Verification: Composer validation/audit, PHP and JavaScript syntax, PHPUnit, WordPress coding-standard baseline, public-tree policy, production dependency checks, package layout checks, packaged PHP linting, autoload smoke tests, and deterministic double-build comparison
- GitHub's automatically generated source archives remain available in the **Assets** section alongside the installable plugin ZIPs.
EOF

printf 'Generated release notes for %s at %s\n' "$tag" "$output"
