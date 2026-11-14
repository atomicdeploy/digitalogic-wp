<?php
/**
 * Linguistic search equivalence never changes canonical product identity.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\CatalogSearchPolicy as Policy;
use Digitalogic\Laravel\Http\Controllers\MobileCatalogController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/laravel/app/Services/CatalogSearchPolicy.php';
require_once dirname( __DIR__ ) . '/laravel/app/Http/Controllers/MobileCatalogController.php';

/** Query-only normalization, alias bounds and explicit sorting regressions. */
final class CatalogSearchPolicyTest extends TestCase {

	/** Model punctuation survives numeral and presentation normalization. */
	public function test_normalization_preserves_model_and_sku_punctuation(): void {
		self::assertSame( '113001017', Policy::normalize( "\u{200F}۱۱۳۰۰۱۰۱۷\u{200E}" ) );
		self::assertSame( 'esp32-c3 / 3.3v', Policy::normalize( ' ESP32-C3 / ۳.۳V ' ) );
		self::assertSame( 'آردوینو uno', Policy::normalize( "آردوینو\u{200C}UNO" ) );
		self::assertSame( 'یک', Policy::normalize( 'يك' ) );
	}

	/** Existing theme syntax is the sole configurable alias registry. */
	public function test_canonical_theme_setting_expands_symmetrically(): void {
		$groups = Policy::groups( "arduino = آردوینو,اردوینو\nesp32 = esp 32\nignored line" );
		self::assertCount( 2, $groups );
		$english = Policy::terms( 'Arduino UNO', $groups );
		self::assertSame( $english, Policy::terms( 'آردوینو UNO', $groups ) );
		self::assertSame( $english, Policy::terms( 'اردوینو UNO', $groups ) );
		self::assertContains( 'arduino uno', $english );
		self::assertContains( 'آردوینو uno', $english );
		self::assertSame( Policy::terms( 'esp32-c3', $groups ), Policy::terms( 'esp 32-c3', $groups ) );
	}

	/** Linguistic aliases do not replace substrings in unrelated model identifiers. */
	public function test_aliases_are_boundary_scoped_and_work_is_bounded(): void {
		self::assertSame( array( 'myarduino123' ), Policy::terms( 'myArduino123', array( array( 'arduino', 'آردوینو' ) ) ) );
		self::assertSame( array( 'esp32s3' ), Policy::terms( 'ESP32S3', array( array( 'esp32', 'esp 32' ) ) ) );
		self::assertSame( array(), Policy::terms( '' ) );
		self::assertLessThanOrEqual( 12, count( Policy::terms( 'a', array_fill( 0, 40, array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ) ) ) ) );
		self::assertSame( array( 'a' ), Policy::terms( 'a', array( 'invalid', array( 'a', array( 'invalid' ) ) ) ) );
	}

	/** Prior app builds can keep sending date; new normal search selects relevance. */
	public function test_default_relevance_never_overrides_an_explicit_sort(): void {
		self::assertSame( 'date', MobileCatalogController::normalizeQuery( array() )['orderby'] );
		self::assertSame( 'relevance', MobileCatalogController::normalizeQuery( array( 'search' => 'Arduino' ) )['orderby'] );
		foreach ( array( 'date', 'price', 'popularity', 'title', 'relevance' ) as $sort ) {
			$actual = MobileCatalogController::normalizeQuery(
				array(
					'search'  => 'Arduino',
					'orderby' => $sort,
					'order'   => 'ASC',
				)
			);
			self::assertSame( $sort, $actual['orderby'] );
			self::assertSame( 'ASC', $actual['order'] );
		}
		self::assertSame(
			'date',
			MobileCatalogController::normalizeQuery(
				array(
					'search'  => 'Arduino',
					'orderby' => 'DROP TABLE',
				)
			)['orderby']
		);
	}

	/** Large overlapping configuration cannot discard the customer's literal query. */
	public function test_alias_bound_always_retains_the_original_query(): void {
		$terms = Policy::terms( 'zzzz', array( array( 'zzzz', 'a', 'b', 'c', 'd', 'e' ), array( 'zzzz', 'f', 'g', 'h', 'i', 'j' ), array( 'zzzz', 'k', 'l', 'm', 'n', 'o' ) ) );
		self::assertCount( 12, $terms );
		self::assertContains( 'zzzz', $terms );
	}
}
