#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd -- "$script_dir/.." && pwd)"
phpcs="$root/vendor/bin/phpcs"

if [[ ! -x "$phpcs" ]]; then
    printf 'PHPCS is unavailable; install development dependencies first.\n' >&2
    exit 1
fi

# The repository predates its current WordPress coding-standard setup. Keep
# releases from adding debt while the existing violations are paid down.
max_errors=43221
max_warnings=3016
report="$(mktemp "${TMPDIR:-/tmp}/digitalogic-phpcs.XXXXXX.json")"
trap 'rm -f -- "$report"' EXIT

set +e
php -d memory_limit=512M "$phpcs" \
    --standard=WordPress \
    --extensions=php \
    --ignore=vendor/ \
    --report=json \
    --report-file="$report" \
    "$root"
phpcs_status=$?
set -e

if ((phpcs_status > 3)); then
    printf 'PHPCS failed before producing a usable report (exit %d).\n' "$phpcs_status" >&2
    exit "$phpcs_status"
fi

# shellcheck disable=SC2016
php -r '
    $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $errors = (int) ($report["totals"]["errors"] ?? -1);
    $warnings = (int) ($report["totals"]["warnings"] ?? -1);
    $maxErrors = (int) $argv[2];
    $maxWarnings = (int) $argv[3];
    if ($errors < 0 || $warnings < 0) {
        fwrite(STDERR, "PHPCS report is missing violation totals.\n");
        exit(1);
    }
    printf(
        "PHPCS baseline: %d errors (max %d), %d warnings (max %d).\n",
        $errors,
        $maxErrors,
        $warnings,
        $maxWarnings
    );
    if ($errors > $maxErrors || $warnings > $maxWarnings) {
        fwrite(STDERR, "WordPress coding-standard debt increased.\n");
        exit(1);
    }
' "$report" "$max_errors" "$max_warnings"
