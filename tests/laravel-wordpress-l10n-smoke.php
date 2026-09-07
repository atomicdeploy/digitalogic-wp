<?php

declare(strict_types=1);

// Supply a read-only copy of an actual WordPress wp-includes/l10n.php. This test
// proves the real global translation boundary; it does not claim a full WP/DB boot.
$wordpressL10n = $argv[1] ?? '';
if (!is_file($wordpressL10n)) {
    throw new RuntimeException('Pass the path to the installed WordPress l10n.php source.');
}
require dirname(__DIR__) . '/vendor/autoload.php';
$app = digitalogic_laravel();
if (function_exists('__')) {
    throw new RuntimeException('Laravel reserved the WordPress translation symbol.');
}

require $wordpressL10n;
if (realpath((new ReflectionFunction('__'))->getFileName()) !== realpath($wordpressL10n)) {
    throw new RuntimeException('The global translation function is not the actual WordPress implementation.');
}

// Only the hook registry and translation catalogue are fixtures. __(), translate()
// and get_translations_for_domain() execute the unmodified installed WP source.
function apply_filters($hook, $value, ...$arguments) { return $value; }
$GLOBALS['l10n']['bridge-proof'] = new class {
    public function translate(string $text): string { return 'WordPress: ' . $text; }
};
if (__('working', 'bridge-proof') !== 'WordPress: working') {
    throw new RuntimeException('WordPress translation did not run correctly after Laravel.');
}
if (trans('laravel-proof') !== 'laravel-proof' || digitalogic_laravel() !== $app) {
    throw new RuntimeException('The Laravel translator or shared application changed after WordPress loaded.');
}
echo "Actual WordPress l10n after Laravel: passed (", hash_file('sha256', $wordpressL10n), ")\n";
