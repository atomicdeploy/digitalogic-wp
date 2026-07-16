<?php
/**
 * Exact product metadata diagnostics.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$display_value = static function ( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	if ( null === $value ) {
		return 'null';
	}

	return (string) $value;
};
?>
<div class="wrap digitalogic-product-diagnostics">
	<h1><?php echo esc_html__( 'Product Metadata Diagnostics', 'digitalogic' ); ?></h1>
	<p><?php echo esc_html__( 'Resolve one exact WooCommerce ID or SKU and compare current product meta with WooCommerce’s derived lookup row.', 'digitalogic' ); ?></p>

	<?php if ( '' !== $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>
	<?php if ( is_wp_error( $diagnostic_error ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $diagnostic_error->get_error_message() ); ?></p></div>
	<?php endif; ?>

	<form method="get" class="digitalogic-section">
		<input type="hidden" name="page" value="digitalogic-product-diagnostics">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="digitalogic-product-id"><?php echo esc_html__( 'WooCommerce ID', 'digitalogic' ); ?></label></th>
				<td><input id="digitalogic-product-id" name="product_id" type="text" inputmode="numeric" dir="ltr" value="<?php echo 'woocommerce_id' === $selector_type ? esc_attr( $selector_value ) : ''; ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="digitalogic-product-sku"><?php echo esc_html__( 'Exact SKU', 'digitalogic' ); ?></label></th>
				<td><input id="digitalogic-product-sku" name="sku" type="text" dir="ltr" value="<?php echo 'sku' === $selector_type ? esc_attr( $selector_value ) : ''; ?>"></td>
			</tr>
		</table>
		<?php submit_button( __( 'Inspect Product', 'digitalogic' ), 'primary', '', false ); ?>
	</form>

	<?php if ( is_array( $metadata ) ) : ?>
		<div class="digitalogic-section">
			<h2><?php echo esc_html__( 'Resolution', 'digitalogic' ); ?></h2>
			<table class="widefat striped"><tbody>
				<?php foreach ( array( 'product_id', 'sku', 'patris_code', 'post_type', 'resolved_by', 'identifier', 'source_of_truth', 'lookup_table_role' ) as $key ) : ?>
					<tr><th scope="row"><?php echo esc_html( $key ); ?></th><td dir="ltr"><code><?php echo esc_html( $display_value( $metadata[ $key ] ?? null ) ); ?></code></td></tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>

		<div class="digitalogic-section">
			<h2><?php echo esc_html__( 'Consistency', 'digitalogic' ); ?></h2>
			<?php if ( $metadata['is_consistent'] ) : ?>
				<div class="notice notice-success inline"><p><?php echo esc_html__( 'No mismatch was found in the compared fields.', 'digitalogic' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-warning inline"><p><?php /* translators: %d: number of metadata mismatches. */ echo esc_html( sprintf( __( '%d lookup mismatch(es) detected.', 'digitalogic' ), $metadata['inconsistency_count'] ) ); ?></p></div>
				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Field', 'digitalogic' ); ?></th><th><?php echo esc_html__( 'Post meta', 'digitalogic' ); ?></th><th><?php echo esc_html__( 'Lookup row', 'digitalogic' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $metadata['inconsistencies'] as $inconsistency ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $inconsistency['field'] ); ?></th>
							<td dir="ltr"><code><?php echo esc_html( $display_value( $inconsistency['postmeta_value'] ) ); ?></code></td>
							<td dir="ltr"><code><?php echo esc_html( $display_value( $inconsistency['lookup_value'] ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php
		foreach ( array(
			'postmeta'     => __( 'Current post meta', 'digitalogic' ),
			'lookup_table' => __( 'Derived WooCommerce lookup row', 'digitalogic' ),
		) as $source => $section_title ) :
			?>
			<div class="digitalogic-section">
				<h2><?php echo esc_html( $section_title ); ?></h2>
				<table class="widefat striped"><tbody>
					<?php foreach ( $metadata[ $source ] as $key => $value ) : ?>
						<tr><th scope="row"><?php echo esc_html( $key ); ?></th><td dir="ltr"><code><?php echo esc_html( $display_value( $value ) ); ?></code></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			</div>
		<?php endforeach; ?>

		<form method="post" class="digitalogic-section">
			<?php wp_nonce_field( 'digitalogic_refresh_product_lookup' ); ?>
			<input type="hidden" name="selector_type" value="<?php echo esc_attr( $selector_type ); ?>">
			<input type="hidden" name="selector_value" value="<?php echo esc_attr( $selector_value ); ?>">
			<p><?php echo esc_html__( 'Refresh only this product’s derived lookup row using WooCommerce’s supported data-store API.', 'digitalogic' ); ?></p>
			<?php submit_button( __( 'Refresh Lookup Row', 'digitalogic' ), 'secondary', 'digitalogic_refresh_product_lookup', false ); ?>
		</form>
	<?php endif; ?>
</div>
