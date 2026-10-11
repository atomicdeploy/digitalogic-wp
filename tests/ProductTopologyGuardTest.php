<?php
/**
 * Product topology guard contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify permanent catalogue title and ESP32-family safeguards. */
final class ProductTopologyGuardTest extends TestCase {

	/** Selection instructions are removed from product names. */
	public function test_product_titles_never_publish_model_selection_instructions(): void {
		$this->assertSame(
			'بردهای توسعه ESP32',
			Digitalogic_Product_Topology_Guard::clean_product_title( 'بردهای توسعه ESP32؛ انتخاب مدل C3 یا S3' )
		);
		$this->assertSame(
			'برد توسعه ESP32-C3',
			Digitalogic_Product_Topology_Guard::clean_product_title( 'برد توسعه ESP32-C3' )
		);

		$filtered = Digitalogic_Product_Topology_Guard::sanitize_product_title(
			array(
				'post_type'  => 'product',
				'post_title' => 'برد ESP32 - انتخاب مدل C3 یا S3',
			)
		);
		$this->assertSame( 'برد ESP32', $filtered['post_title'] );
	}

	/** Silicon families remain distinct even when their labels look similar. */
	public function test_esp32_silicon_families_are_distinct(): void {
		$this->assertSame( array( 'ESP32-C3' ), Digitalogic_Product_Topology_Guard::esp32_families( 'c3' ) );
		$this->assertSame( array( 'ESP32-S3' ), Digitalogic_Product_Topology_Guard::esp32_families( 'ESP32-S3' ) );
		$this->assertSame( array( 'ESP32' ), Digitalogic_Product_Topology_Guard::esp32_families( 'ESP32-WROOM-32' ) );
		$this->assertSame(
			array( 'ESP32-C3', 'ESP32-S3' ),
			Digitalogic_Product_Topology_Guard::esp32_families( 'ESP32 C3 یا S3' )
		);
	}

	/** Legacy links keep explicit family intent and a safe generic fallback. */
	public function test_retired_mixed_family_url_keeps_specific_models_and_generic_search(): void {
		$this->assertSame(
			home_url( '/product/esp32-c3-development-board/' ),
			Digitalogic_Product_Topology_Guard::legacy_esp32_redirect_url( '/product/esp-32/?attribute_pa_model=c3' )
		);
		$this->assertSame(
			home_url( '/product/esp32-s3-development-board/' ),
			Digitalogic_Product_Topology_Guard::legacy_esp32_redirect_url( '/product/esp-32/?attribute_pa_model=s3' )
		);
		$this->assertStringContainsString( 's=ESP32', Digitalogic_Product_Topology_Guard::legacy_esp32_redirect_url( '/product/esp-32/' ) );
	}
}
