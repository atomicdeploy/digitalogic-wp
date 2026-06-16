<?php
/**
 * Digitalogic in-site panel shell.
 *
 * @var string $panel_path
 */

if (!defined('ABSPATH')) {
    exit;
}

$config = Digitalogic_Panel::instance()->client_config();
$lang = strpos($config['locale'], 'fa') === 0 ? 'fa' : 'en';
$dir = $config['i18n'][$lang]['dir'];
?><!doctype html>
<html <?php language_attributes(); ?> dir="<?php echo esc_attr($dir); ?>">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html(sprintf(__('Digitalogic Panel - %s', 'digitalogic'), get_bloginfo('name'))); ?></title>
    <?php wp_print_styles('digitalogic-panel'); ?>
</head>
<body class="digitalogic-panel-body">
    <div id="digitalogic-panel" data-path="<?php echo esc_attr($panel_path); ?>">
        <div class="dlp-boot">
            <div class="dlp-boot-mark">D</div>
            <div><?php esc_html_e('Loading...', 'digitalogic'); ?></div>
        </div>
    </div>
    <?php wp_print_scripts(array('vue', 'digitalogic-panel')); ?>
</body>
</html>
