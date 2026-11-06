<?php
/**
 * Native CatalogContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-mobile-api.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCatalogController.php';

/** Regression coverage for the native adapter. */
final class MobileCatalogContractTest extends TestCase {
	/**
	 * Verify public entry cannot dispatch internal routes or unbounded ids.
	 *
	 * @return void Native adapter result.
	 */
	public function test_public_entry_cannot_dispatch_internal_routes_or_unbounded_ids(): void {
		self::assertSame( '/_digitalogic/mobile/products/123', Digitalogic_Mobile_API::route( '/app-api/products/123' ) );
		self::assertSame( '/_digitalogic/mobile/bootstrap', Digitalogic_Mobile_API::route( '/app-api/bootstrap/' ) );
		foreach ( array( '/app-api/../../bridge/status', '/app-api/bridge/status', '/app-api/products/0', '/app-api/products/-1', '/app-api/products/12345678901', '/app-api/products/1/delete', '/panel/' ) as $path ) {
			self::assertNull( Digitalogic_Mobile_API::route( $path ) );
		}
	}

	/**
	 * Verify query bounds allowlists and structured input do not reach sql.
	 *
	 * @return void Native adapter result.
	 */
	public function test_query_bounds_allowlists_and_structured_input_do_not_reach_sql(): void {
		$query = MobileCatalogController::normalizeQuery(
			array(
				'page'         => -5,
				'per_page'     => 500000,
				'orderby'      => 'id; DROP TABLE',
				'order'        => 'ASC;DELETE',
				'stock_status' => 'private',
				'search'       => array( 'bad' ),
				'category'     => -5,
			)
		);
		self::assertSame( 1, $query['page'] );
		self::assertSame( 48, $query['per_page'] );
		self::assertSame( 'date', $query['orderby'] );
		self::assertSame( 'DESC', $query['order'] );
		self::assertSame( '', $query['stock_status'] );
		self::assertSame( '', $query['search'] );
		self::assertSame( 0, $query['category'] );
		self::assertSame( 120, mb_strlen( MobileCatalogController::normalizeQuery( array( 'search' => str_repeat( 'الف', 100 ) ) )['search'] ) );
	}

	/**
	 * Verify absent price stays absent and currency minor units are explicit.
	 *
	 * @return void Native adapter result.
	 */
	public function test_absent_price_stays_absent_and_currency_minor_units_are_explicit(): void {
		self::assertNull( MobileCatalogController::minorUnits( '', 0 ) );
		self::assertNull( MobileCatalogController::minorUnits( null, 2 ) );
		self::assertNull( MobileCatalogController::minorUnits( 'unknown', 2 ) );
		self::assertSame( '125000', MobileCatalogController::minorUnits( '125000', 0 ) );
		self::assertSame( '12345', MobileCatalogController::minorUnits( '123.45', 2 ) );
		self::assertSame( '0', MobileCatalogController::minorUnits( '0', 0 ) );
	}

	/**
	 * Verify name removes only its own exact trailing product code.
	 *
	 * @return void Native adapter result.
	 */
	public function test_name_removes_only_its_own_exact_trailing_product_code(): void {
		self::assertSame( 'Arduino Mini', MobileCatalogController::displayName( 'Arduino Mini [113001017]', '113001017' ) );
		self::assertSame( 'Arduino Mini [other]', MobileCatalogController::displayName( 'Arduino Mini [other]', '113001017' ) );
		self::assertSame( 'Board [113001017] adapter', MobileCatalogController::displayName( 'Board [113001017] adapter', '113001017' ) );
	}
}
