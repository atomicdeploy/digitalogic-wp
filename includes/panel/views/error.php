<?php
/**
 * Digitalogic panel error view.
 *
 * @package Digitalogic
 * @var array $view Digitalogic panel error view model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_embedded = Digitalogic_Panel_Error_Page::MODE_ADMIN_EMBEDDED === $view['mode'];

if ( ! $is_embedded ) :
	?><!doctype html>
<html lang="<?php echo esc_attr( $view['locale'] ); ?>" dir="<?php echo esc_attr( $view['direction'] ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title><?php echo esc_html( $view['title'] . ' — ' . $view['site_name'] ); ?></title>
	<?php wp_print_styles( $view['style_handle'] ); ?>
</head>
<body class="digitalogic-error-body">
	<?php include DIGITALOGIC_PLUGIN_DIR . 'includes/panel/views/error-content.php'; ?>
</body>
</html>
	<?php
else :
	wp_print_styles( $view['style_handle'] );
	?>
<div class="wrap digitalogic-error-embedded" lang="<?php echo esc_attr( $view['locale'] ); ?>" dir="<?php echo esc_attr( $view['direction'] ); ?>">
	<?php include DIGITALOGIC_PLUGIN_DIR . 'includes/panel/views/error-content.php'; ?>
</div>
	<?php
endif;
