<?php
/**
 * Reject storage that cannot honor pricing rollback before admitting writes.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Storage capability checks must precede the transaction callback. */
final class PricingTransactionalStorageTest extends TestCase {

	/** Reject missing, duplicate, failed and nontransactional engine evidence. */
	public function test_invalid_storage_does_not_start_or_enter_transaction(): void {
		$saved = $GLOBALS['wpdb'];
		try {
			$db                            = $this->database();
			$valid                         = $db->rows;
			$nontransactional              = $valid;
			$nontransactional[0]['engine'] = 'MyISAM';
			$duplicate                     = $valid;
			$duplicate[1]                  = $duplicate[0];
			$method                        = new ReflectionMethod( Digitalogic_Pricing_Service::class, 'run_transaction' );
			foreach ( array( null, array_slice( $valid, 1 ), $nontransactional, $duplicate ) as $rows ) {
				$db->rows        = $rows;
				$GLOBALS['wpdb'] = $db;
				$called          = false;
				$result          = $method->invoke(
					Digitalogic_Pricing_Service::instance(),
					static function () use ( &$called ) {
						$called = true;
						return true;
					}
				);
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( 'digitalogic_pricing_transactional_storage_required', $result->get_error_code() );
				$this->assertFalse( $called );
				$this->assertSame( array(), $db->writes );
			}
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}
	}

	/** A prior successful check must not hide a later engine change. */
	public function test_storage_is_rechecked_for_long_lived_processes(): void {
		$saved = $GLOBALS['wpdb'];
		try {
			$db              = $this->database();
			$GLOBALS['wpdb'] = $db;
			$service         = Digitalogic_Pricing_Service::instance();
			$this->assertTrue( $service->assert_transactional_pricing_storage() );
			$db->rows[0]['engine'] = 'MyISAM';
			$this->assertInstanceOf( WP_Error::class, $service->assert_transactional_pricing_storage() );
		} finally {
			$GLOBALS['wpdb'] = $saved;
		}
	}

	/** Return a database double that records any attempted transactional write. */
	private function database() {
		$db = new class() extends Digitalogic_Test_WPDB {
			/**
			 * Simulated schema evidence.
			 *
			 * @var array|null
			 */
			public $rows = array();
			/**
			 * Attempted transaction/write statements.
			 *
			 * @var array
			 */
			public $writes = array();
			/**
			 * Return only the explicitly supplied schema response.
			 *
			 * @param mixed  $prepared Prepared query.
			 * @param string $output Result format.
			 */
			public function get_results( $prepared, $output = ARRAY_A ) {
				return $this->rows;
			}
			/**
			 * Record attempted writes instead of touching global test data.
			 *
			 * @param mixed $sql Transaction query.
			 */
			public function query( $sql ) {
				$this->writes[] = $sql;
				return true;
			}
		};
		foreach ( array( $db->options, $db->posts, $db->postmeta, $db->prefix . 'wc_product_meta_lookup', $db->term_relationships, $db->term_taxonomy, $db->terms ) as $table ) {
			$db->rows[] = array(
				'table_name' => $table,
				'engine'     => 'InnoDB',
			);
		}
		return $db;
	}
}
