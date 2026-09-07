<?php
/**
 * Transaction-owner guard tests for the existing bulk feed path.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** A rejected checkpoint must stop before preparing or writing product data. */
final class PatrisFeedBatchGuardTest extends TestCase {

	/** The exact timeout object remains available to the outer transaction owner. */
	public function test_batch_guard_preserves_original_error_before_any_write(): void {
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		$error           = new WP_Error( 'digitalogic_currency_cli_deadline_exceeded', 'Deadline expired.', array( 'blocking' => true ) );
		$phases          = array();
		$result          = Digitalogic_Patris_Feed::instance()->apply_product_pricing_batch(
			array( array( 'product' => null ) ),
			static function ( $phase ) use ( $error, &$phases ) {
				$phases[] = $phase;
				return $error;
			}
		);
		$this->assertSame( $error, $result );
		$this->assertSame( array( 'before_batch' ), $phases );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	/** False or invalid guards cannot silently permit a pricing step. */
	public function test_batch_guard_fails_closed_for_non_true_results(): void {
		foreach ( array( static fn() => false, 'not_a_callable_guard' ) as $guard ) {
			$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
			$result          = Digitalogic_Patris_Feed::instance()->apply_product_pricing_batch( array( array( 'product' => null ) ), $guard );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'digitalogic_pricing_actuation_guard_rejected', $result->get_error_code() );
			$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		}
	}
}
