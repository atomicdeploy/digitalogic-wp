<?php
/** Storefront UX and localization contract tests. */

use PHPUnit\Framework\TestCase;

final class StorefrontUXTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_locale'] = 'fa_IR';

		$_GET = array();
	}

	public function test_known_visible_strings_are_persian_only_in_persian_locale(): void {
		$this->assertSame( 'فیلتر بر اساس قیمت', Digitalogic_Storefront_UX::translate_storefront_text( 'Filter by price', 'Filter by price', 'woodmart' ) );
		$this->assertSame( 'از', Digitalogic_Storefront_UX::translate_storefront_text( 'From', 'From', 'tier-pricing-table' ) );
		$this->assertSame( 'تماس با ما', Digitalogic_Storefront_UX::translate_storefront_text( 'Contact us', 'Contact us', 'chaty' ) );
		$GLOBALS['digitalogic_test_locale'] = 'en_US';
		$this->assertSame( 'From', Digitalogic_Storefront_UX::translate_storefront_text( 'From', 'From', 'tier-pricing-table' ) );
	}

	public function test_order_status_labels_change_without_changing_slugs(): void {
		$input  = array(
			'wc-processing'        => 'Processing',
			'wc-pre-ordered'       => 'Pre-ordered',
			'wc-spamorder'         => 'Spam',
			'wc-partial-payment'   => 'Partially Paid',
			'wc-scheduled-payment' => 'Scheduled',
			'wc-pending-deposit'   => 'Pending Deposit Payment',
		);
		$output = Digitalogic_Storefront_UX::translate_order_statuses( $input );

		$this->assertSame( array_keys( $input ), array_keys( $output ) );
		$this->assertSame( 'در حال پردازش', $output['wc-processing'] );
		$this->assertSame( 'پیش‌سفارش‌شده', $output['wc-pre-ordered'] );
		$this->assertSame( 'سفارش مشکوک', $output['wc-spamorder'] );
		$this->assertSame( 'بخشی پرداخت‌شده', $output['wc-partial-payment'] );
		$this->assertSame( 'زمان‌بندی‌شده', $output['wc-scheduled-payment'] );
		$this->assertSame( 'در انتظار پرداخت بیعانه', $output['wc-pending-deposit'] );
	}

	public function test_stock_filter_uses_exact_stock_status_meta(): void {
		$_GET['dgl_availability'] = 'instock';

		$query = Digitalogic_Storefront_UX::filter_stock_query( array() );

		$this->assertSame( '_stock_status', $query[0]['key'] );
		$this->assertSame( 'instock', $query[0]['value'] );
		$this->assertSame( '=', $query[0]['compare'] );
	}

	public function test_sources_include_loading_feedback_account_fix_and_unique_banner_assets(): void {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/storefront-ux.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$js  = file_get_contents( dirname( __DIR__ ) . '/assets/js/storefront-ux.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertStringContainsString( '.woocommerce-MyAccount-navigation{float:none!important;width:100%!important', $css );
		$this->assertStringContainsString( 'aria-busy', $js );
		$this->assertStringContainsString( 'MutationObserver', $js );
		foreach ( array( 'semiconductors', 'sensors', 'displays', 'modules', 'electromechanical', 'passive' ) as $name ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/assets/images/category-banners/' . $name . '.webp' );
		}
	}
}
