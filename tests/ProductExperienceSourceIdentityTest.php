<?php
/**
 * Public identity widgets preserve the SKU without presenting unreviewed source labels.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-product-experience.php';

/** Exercise the two public identity widgets against the same product. */
final class ProductExperienceSourceIdentityTest extends TestCase {

	/** Hide raw source fields on pending review and retain them for internal readers. */
	public function test_pending_source_identity_keeps_sku_and_hides_unreviewed_aliases(): void {
		global $product;
		$previous_product = $product;
		$id               = 92714;
		$previous_post    = $GLOBALS['digitalogic_test_posts'][ $id ] ?? null;
		$metadata         = array(
			'_sku'                     => '113003110',
			'_digitalogic_model'       => '5-12V.5A',
			'_digitalogic_patris_name' => '5-12V.5A PROXIMITY IR SWITCH',
			'_digitalogic_part_number' => 'TEST-LEGACY-PART',
		);

		try {
			$GLOBALS['digitalogic_test_posts'][ $id ] = array(
				'post_type'    => 'product',
				'product_type' => 'simple',
				'meta'         => $metadata,
			);
			$product                                  = new class( $id ) extends WC_Product {
				/** The test product has no physical dimensions. */
				public function has_dimensions(): bool {
					return false;
				}
			};

			$highlights = Digitalogic_Product_Experience::shortcode_highlights();
			$specs      = Digitalogic_Product_Experience::shortcode_specs();
			$this->assertStringContainsString( $metadata['_digitalogic_model'], $highlights );
			$this->assertStringContainsString( $metadata['_digitalogic_patris_name'], $specs );
			$this->assertStringContainsString( $metadata['_digitalogic_part_number'], $specs );

			$GLOBALS['digitalogic_test_posts'][ $id ]['meta'][ Digitalogic_Product_Experience::SOURCE_IDENTITY_REVIEW_META ] = 'pending';

			$highlights = Digitalogic_Product_Experience::shortcode_highlights();
			$specs      = Digitalogic_Product_Experience::shortcode_specs();
			$this->assertStringContainsString( $metadata['_sku'], $specs );
			foreach ( $metadata as $key => $raw_value ) {
				$this->assertSame( $raw_value, get_post_meta( $id, $key, true ) );
				if ( '_sku' !== $key ) {
					$this->assertStringNotContainsString( $raw_value, $highlights . $specs );
				}
			}
		} finally {
			$product = $previous_product;
			if ( null === $previous_post ) {
				unset( $GLOBALS['digitalogic_test_posts'][ $id ] );
			} else {
				$GLOBALS['digitalogic_test_posts'][ $id ] = $previous_post;
			}
		}
	}
}
