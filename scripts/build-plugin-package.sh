#!/usr/bin/env bash
set -euo pipefail

if (($# != 1)); then
    printf 'Usage: %s <output.zip>\n' "$0" >&2
    exit 2
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$script_dir/.." && pwd)"
output="$1"
if [[ "$output" != /* ]]; then
    output="$(pwd)/$output"
fi
if [[ "$output" != *.zip ]]; then
    printf 'Package output must end in .zip: %s\n' "$output" >&2
    exit 2
fi

source_date_epoch="${SOURCE_DATE_EPOCH:-}"
if [[ -z "$source_date_epoch" ]]; then
    source_date_epoch="$(git -C "$root" log -1 --format=%ct)"
fi
if [[ ! "$source_date_epoch" =~ ^[0-9]+$ ]]; then
    printf 'SOURCE_DATE_EPOCH must be an integer: %s\n' "$source_date_epoch" >&2
    exit 2
fi
if ((source_date_epoch < 315532800)); then
    printf 'SOURCE_DATE_EPOCH predates the ZIP timestamp range: %s\n' "$source_date_epoch" >&2
    exit 2
fi
# ZIP stores timestamps at two-second precision. Normalizing here makes the
# archive hash unambiguous for odd commit epochs as well as even ones.
source_date_epoch=$((source_date_epoch - (source_date_epoch % 2)))

bash "$root/scripts/check-public-tree.sh" "$root"

# The PHP snippets are intentionally single quoted so Bash cannot interpolate
# PHP variables before they reach the interpreter.
# shellcheck disable=SC2016
php -r '
    $installed = require $argv[1];
    if (($installed["root"]["dev"] ?? true) !== false) {
        fwrite(STDERR, "Composer vendor tree contains development dependencies.\n");
        exit(1);
    }
    foreach (($installed["versions"] ?? []) as $name => $package) {
        if (($package["dev_requirement"] ?? false) === true) {
            fwrite(STDERR, "Development package reached vendor: {$name}\n");
            exit(1);
        }
    }
' "$root/vendor/composer/installed.php"

work_dir="$(mktemp -d "${TMPDIR:-/tmp}/digitalogic-package.XXXXXX")"
trap 'rm -rf -- "$work_dir"' EXIT
plugin_slug="digitalogic-wp"
stage="$work_dir/stage/$plugin_slug"
mkdir -p "$stage"

runtime_entries=(
    digitalogic.php
    assets
    includes
    languages
    vendor
    LICENSE
    README.md
    CHANGELOG.md
    .htaccess
)

for entry in "${runtime_entries[@]}"; do
    if [[ ! -e "$root/$entry" ]]; then
        printf 'Required package entry is missing: %s\n' "$entry" >&2
        exit 1
    fi
    cp -a -- "$root/$entry" "$stage/"
done

for required in digitalogic.php vendor/autoload.php; do
    if [[ ! -f "$stage/$required" ]]; then
        printf 'Required runtime file is missing: %s\n' "$required" >&2
        exit 1
    fi
done

# Composer's --no-dev flag removes development packages, but distribution
# archives can still contain their own tests, examples, and tooling metadata.
find "$stage/vendor" -type d \
    \( -iname test -o -iname tests -o -iname testdata \
       -o -iname doc -o -iname docs \
       -o -iname guide -o -iname guides \
       -o -iname example -o -iname examples \
       -o -iname benchmark -o -iname benchmarks \
       -o -iname phpstan -o -iname phpunit -o -iname psalm -o -iname phan \
       -o -name .github -o -name .gitlab -o -name .circleci \
       -o -name .phive -o -name .phpdoc -o -name .idea -o -name .vscode \) \
    -prune -exec rm -rf -- {} +
rm -rf -- "$stage/vendor/bin"
find "$stage/vendor" -type f \
    \( -iname 'phpunit.xml*' -o -iname 'phpcs.xml*' \
       -o -iname '*.neon' -o -iname 'psalm.xml*' \
       -o -iname 'infection.json*' -o -name '.travis.yml' \
       -o -iname 'phpdoc*.xml*' -o -iname 'phpdox*.xml*' \
       -o -iname 'phpbench.json*' -o -iname 'mkdocs*.yml' \
       -o -iname 'mkdocs*.yaml' -o -name rector.php -o -name ecs.php \
       -o -name '.editorconfig' -o -name '.gitattributes' \
       -o -name '.gitignore' -o -name '.php-cs-fixer*' \
       -o -name '.readthedocs.yaml' -o -name '.tool-versions' \
       -o -name composer.json -o -name composer.lock -o -name Makefile \
       -o -iname 'README*' -o -iname 'CHANGELOG*' \
       -o -iname 'CONTRIBUTING*' -o -iname 'UPGRADE*' \) \
    -delete
find "$stage/assets" "$stage/includes" "$stage/languages" -type f \
    \( -iname 'README*' -o -iname '*.md' \) -delete

if find "$stage" -type l -print -quit | grep -q .; then
    printf 'Symlinks are not allowed in the plugin package.\n' >&2
    find "$stage" -type l -print >&2
    exit 1
fi

find "$stage" -type d -exec chmod 0755 {} +
find "$stage" -type f -exec chmod 0644 {} +
find "$stage" -exec touch -h -d "@$source_date_epoch" {} +

create_archive() {
    local destination="$1"
    rm -f -- "$destination"
    (
        cd "$work_dir/stage"
        export TZ=UTC
        find "$plugin_slug" -type f -print0 |
            LC_ALL=C sort -z |
            xargs -0 zip -X -q "$destination"
    )
}

first_archive="$work_dir/first.zip"
second_archive="$work_dir/second.zip"
create_archive "$first_archive"
create_archive "$second_archive"

first_hash="$(sha256sum "$first_archive" | awk '{print $1}')"
second_hash="$(sha256sum "$second_archive" | awk '{print $1}')"
if [[ "$first_hash" != "$second_hash" ]]; then
    printf 'Package build is not reproducible: %s != %s\n' "$first_hash" "$second_hash" >&2
    exit 1
fi

mapfile -t archive_entries < <(unzip -Z1 "$second_archive")
if (("${#archive_entries[@]}" == 0)); then
    printf 'Package archive is empty.\n' >&2
    exit 1
fi

declare -A archive_entry_set=()
for archive_entry in "${archive_entries[@]}"; do
    archive_entry_set["$archive_entry"]=1
done

mapfile -t archive_roots < <(
    printf '%s\n' "${archive_entries[@]}" |
        cut -d/ -f1 |
        LC_ALL=C sort -u
)
if (("${#archive_roots[@]}" != 1)) || [[ "${archive_roots[0]}" != "$plugin_slug" ]]; then
    printf 'Package must have exactly one %s/ root; found: %s\n' \
        "$plugin_slug" "${archive_roots[*]:-(none)}" >&2
    exit 1
fi

for required_entry in \
    "$plugin_slug/digitalogic.php" \
    "$plugin_slug/vendor/autoload.php"; do
    if [[ -z "${archive_entry_set[$required_entry]+present}" ]]; then
        printf 'Required archive entry is missing: %s\n' "$required_entry" >&2
        exit 1
    fi
done

duplicates="$(printf '%s\n' "${archive_entries[@]}" | LC_ALL=C sort | uniq -d)"
if [[ -n "$duplicates" ]]; then
    printf 'Package contains duplicate entries:\n%s\n' "$duplicates" >&2
    exit 1
fi

for entry in "${archive_entries[@]}"; do
    case "$entry" in
        "$plugin_slug/digitalogic.php" | \
        "$plugin_slug/LICENSE" | \
        "$plugin_slug/README.md" | \
        "$plugin_slug/CHANGELOG.md" | \
        "$plugin_slug/.htaccess" | \
        "$plugin_slug/assets/"* | \
        "$plugin_slug/includes/"* | \
        "$plugin_slug/languages/"* | \
        "$plugin_slug/vendor/"*)
            ;;
        *)
            printf 'Unexpected non-runtime package entry: %s\n' "$entry" >&2
            exit 1
            ;;
    esac

    lower="${entry,,}"
    case "/$lower/" in
        */test/* | */tests/* | */testdata/* | \
        */doc/* | */docs/* | \
        */guide/* | */guides/* | \
        */example/* | */examples/* | \
        */benchmark/* | */benchmarks/* | \
        */phpstan/* | */phpunit/* | */psalm/* | */phan/* | \
        */.github/* | */.gitlab/* | */.circleci/* | \
        */.phive/* | */.phpdoc/* | */.idea/* | */.vscode/* | */vendor/bin/*)
            printf 'Development directory reached package: %s\n' "$entry" >&2
            exit 1
            ;;
    esac

    basename="${lower##*/}"
    case "$basename" in
        phpunit.xml* | phpcs.xml* | *.neon | psalm.xml* | \
        infection.json* | phpdoc*.xml* | phpdox*.xml* | phpbench.json* | \
        mkdocs*.yml | mkdocs*.yaml | rector.php | ecs.php | \
        .travis.yml | .editorconfig | .gitattributes | \
        .gitignore | .php-cs-fixer* | .readthedocs.yaml | .tool-versions)
            printf 'Development metadata reached package: %s\n' "$entry" >&2
            exit 1
            ;;
    esac

    if [[ "$lower" == "$plugin_slug/vendor/"* ]]; then
        case "$basename" in
            composer.json | composer.lock | makefile | readme* | changelog* | \
            contributing* | upgrade*)
                printf 'Vendor development metadata reached package: %s\n' "$entry" >&2
                exit 1
                ;;
        esac
    fi

    case "$lower" in
        *.bak* | *.backup* | *.old* | *.orig* | *.save* | *~)
            printf 'Backup/editor artifact reached package: %s\n' "$entry" >&2
            exit 1
            ;;
    esac
done

verify_dir="$work_dir/verify"
mkdir -p "$verify_dir"
unzip -q "$second_archive" -d "$verify_dir"

php_count=0
while IFS= read -r -d '' php_file; do
    if ! php -l "$php_file" >/dev/null; then
        printf 'Packaged PHP syntax check failed: %s\n' "$php_file" >&2
        exit 1
    fi
    php_count=$((php_count + 1))
done < <(find "$verify_dir/$plugin_slug" -type f -name '*.php' -print0)

# shellcheck disable=SC2016
PACKAGE_ROOT="$verify_dir/$plugin_slug" php -r '
    require getenv("PACKAGE_ROOT") . "/vendor/autoload.php";
    foreach ([
        "PhpOffice\\PhpSpreadsheet\\Spreadsheet",
        "Composer\\Pcre\\Preg",
    ] as $class) {
        if (!class_exists($class)) {
            fwrite(STDERR, "Packaged Composer autoload is missing {$class}.\n");
            exit(1);
        }
    }
'

mkdir -p -- "$(dirname -- "$output")"
install -m 0644 "$second_archive" "$output"
printf 'Created and verified %s (%s; %d PHP files; epoch %d)\n' \
    "$output" "$second_hash" "$php_count" "$source_date_epoch"
