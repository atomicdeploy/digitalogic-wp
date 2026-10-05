<?php
/**
 * Public software-library archive.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="primary" class="wd-content-area site-content dgl-khub-page">
	<?php echo do_shortcode( '[dgl_software_library]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode escapes all stored fields. ?>
</div>
<?php
get_footer();
