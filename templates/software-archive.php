<?php
/**
 * Public software-library archive.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main dgl-khub-page">
	<div class="container">
		<?php echo do_shortcode( '[dgl_software_library]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode escapes all stored fields. ?>
	</div>
</main>
<?php
get_footer();
