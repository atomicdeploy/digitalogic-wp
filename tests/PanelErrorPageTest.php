<?php
/**
 * Panel error-page tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies branded, safe, localized standalone error documents.
 */
final class PanelErrorPageTest extends TestCase {

	/**
	 * Reset request, locale, and user fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_filters']         = array();
		$GLOBALS['digitalogic_test_locale']          = 'en_US';
		$GLOBALS['digitalogic_test_current_user_id'] = 0;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 0,
			'user_login'   => '',
			'display_name' => '',
		);
		$GLOBALS['digitalogic_test_status_headers']  = array();
		$GLOBALS['digitalogic_test_nocache_headers'] = 0;
	}

	/**
	 * A forbidden response is branded, escaped, and returns HTTP 403.
	 */
	public function test_forbidden_page_is_branded_safe_and_sets_the_status(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 12;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 12,
			'user_login'   => 'operator',
			'display_name' => 'Store Operator',
		);

		ob_start();
		Digitalogic_Panel_Error_Page::render( 403, 'panel-access-denied', '<script>alert(1)</script>' );
		$html = (string) ob_get_clean();

		$this->assertSame( array( 403 ), $GLOBALS['digitalogic_test_status_headers'] );
		$this->assertSame( 1, $GLOBALS['digitalogic_test_nocache_headers'] );
		$this->assertStringContainsString( 'class="digitalogic-error-body"', $html );
		$this->assertStringContainsString( 'Digitalogic', $html );
		$this->assertStringContainsString( 'DG-PANEL-ACCESS-DENIED', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( 'wp-die-message', $html );
	}

	/**
	 * Persian output is complete and uses the RTL document direction.
	 */
	public function test_persian_page_uses_rtl_and_complete_localized_copy(): void {
		$GLOBALS['digitalogic_test_locale']          = 'fa_IR';
		$GLOBALS['digitalogic_test_current_user_id'] = 9;
		$GLOBALS['digitalogic_test_current_user']    = (object) array(
			'ID'           => 9,
			'user_login'   => 'mahdi',
			'display_name' => 'مدیر فروشگاه',
		);

		ob_start();
		Digitalogic_Panel_Error_Page::render( 403, 'panel-access-denied' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<html lang="fa-IR" dir="rtl">', $html );
		$this->assertStringContainsString( 'این حساب به پنل دسترسی ندارد', $html );
		$this->assertStringContainsString( 'ورود با حساب دیگر', $html );
		$this->assertStringContainsString( 'مدیر فروشگاه', $html );
	}

	/**
	 * The reusable renderer exposes non-authorization plugin failures too.
	 */
	public function test_renderer_supports_other_plugin_error_statuses(): void {
		$view = Digitalogic_Panel_Error_Page::view_model( 503, 'laravel-unavailable' );

		$this->assertSame( 503, $view['status'] );
		$this->assertSame( 'DG-LARAVEL-UNAVAILABLE', $view['code'] );
		$this->assertSame( 'ltr', $view['direction'] );
		$this->assertStringContainsString( 'not ready', $view['title'] );
	}
}
