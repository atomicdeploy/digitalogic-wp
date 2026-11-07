<?php
/**
 * Manufacturer chip reference read contract.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-chip-reference.php';

/** Verify conditional chip data reads without a product assignment. */
final class ChipReferenceTest extends TestCase {

	/** An unprovisioned chip does not resolve as an inventory or board record. */
	public function test_unprovisioned_reference_returns_not_found(): void {
		$reference = Digitalogic_Chip_Reference::rest_chip( new WP_REST_Request( array( 'chip' => 'microchip-enc28j60' ) ) );

		$this->assertInstanceOf( WP_Error::class, $reference );
		$this->assertSame( 404, $reference->get_error_data()['status'] );
	}

	/** Preserve distinct conditions, exact decimals and datasheet locators. */
	public function test_read_returns_conditional_specs_with_manufacturer_provenance(): void {
		$manifest = require dirname( __DIR__ ) . '/scripts/enc28j60-chip-reference.php';
		$rows     = array();
		foreach ( $manifest['specifications'] as $spec ) {
			$spec['magnitude'] = number_format( (float) $spec['magnitude'], 6, '.', '' );
			$rows[]            = $manifest['part'] + $manifest['source'] + $spec;
		}

		global $wpdb;
		$original_db     = $wpdb;
		$original_values = $GLOBALS['digitalogic_test_options'];
		$original_cache  = $GLOBALS['digitalogic_test_option_cache'];
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate WordPress database access for the REST callback.
		$wpdb = new class( $rows ) {
			/**
			 * Test table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';

			/**
			 * Bound chip slug.
			 *
			 * @var string|null
			 */
			public ?string $requested = null;

			/**
			 * Joined result rows.
			 *
			 * @var array
			 */
			private array $rows;

			/**
			 * Save fixture data.
			 *
			 * @param array $rows Database result fixture.
			 */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			/**
			 * Simulate a prepared statement's bound slug.
			 *
			 * @param string $sql  SQL template.
			 * @param string $slug Slug.
			 * @return string
			 */
			public function prepare( string $sql, string $slug ): string {
				$this->requested = $slug;
				return $sql;
			}

			/**
			 * Return a fixture only for the known chip and associative mode.
			 *
			 * @param string $sql  SQL template.
			 * @param string $mode Result format.
			 * @return array
			 */
			public function get_results( string $sql, string $mode ): array {
				return ARRAY_A === $mode && 'microchip-enc28j60' === $this->requested && str_contains( $sql, 'wp_digitalogic_hw_chip_parts' ) ? $this->rows : array();
			}
		};
		try {
			update_option( 'digitalogic_chip_reference_schema', '1' );
			$reference = Digitalogic_Chip_Reference::rest_chip( new WP_REST_Request( array( 'chip' => 'microchip-enc28j60' ) ) );
			$this->assertSame( 'chip', $reference['scope'] );
			$this->assertSame( 'ENC28J60', $reference['part_number'] );
			$this->assertArrayNotHasKey( 'sku', $reference );
			$this->assertArrayNotHasKey( 'pins', $reference );
			$this->assertArrayNotHasKey( 'revision', $reference );
			$this->assertCount( 10, $reference['specifications'] );

			$current = array();
			foreach ( $reference['specifications'] as $spec ) {
				if ( 'supply_current' === $spec['parameter'] && 'typ' === $spec['qualifier'] ) {
					$current[ $spec['condition_key'] ] = $spec;
				}
			}
			$this->assertSame( '160.000000', $current['ethernet_tx']['magnitude'] );
			$this->assertSame( '120.000000', $current['active_no_tx']['magnitude'] );
			$this->assertSame( '1.200000', $current['sleep']['magnitude'] );
			$this->assertSame( 'mA', $current['sleep']['unit'] );
			$this->assertStringContainsString( '25 °C', $current['sleep']['conditions'] );
			$this->assertSame( 'DS39662E', $current['sleep']['source']['document_key'] );
			$this->assertSame( '§16.1, IDDS', $current['sleep']['source']['locator'] );
			$this->assertSame( array(), Digitalogic_Chip_Reference::read( '113011001' ) );
		} finally {
			$wpdb                                     = $original_db;
			$GLOBALS['digitalogic_test_options']      = $original_values;
			$GLOBALS['digitalogic_test_option_cache'] = $original_cache;
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
