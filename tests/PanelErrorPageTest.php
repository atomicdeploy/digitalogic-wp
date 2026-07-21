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

	/**
	 * Each protected browser surface gets specific copy and a safe admin route.
	 */
	public function test_protected_admin_contexts_have_specific_copy_and_recovery_actions(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 18;

		$contexts = array(
			Digitalogic_Panel_Error_Page::CONTEXT_PATRIS_REPORTS      => 'Patris reports',
			Digitalogic_Panel_Error_Page::CONTEXT_PRODUCT_DIAGNOSTICS => 'Product diagnostics',
			Digitalogic_Panel_Error_Page::CONTEXT_UI_SETTINGS         => 'Digitalogic interface settings',
		);

		foreach ( $contexts as $context => $expected_title ) {
			$view = Digitalogic_Panel_Error_Page::view_model(
				403,
				$context . '-access-denied',
				'',
				array( 'context' => $context )
			);

			$this->assertSame( $context, $view['context'] );
			$this->assertStringContainsString( $expected_title, $view['title'] );
			$this->assertSame( 'https://digitalogic.test/wp-admin/', $view['actions'][0]['url'] );
			$this->assertTrue( $view['actions'][0]['primary'] );
		}
	}

	/**
	 * Public comment blocks do not suggest authentication or reveal guard details.
	 */
	public function test_comment_guard_context_has_localized_public_recovery_action(): void {
		$GLOBALS['digitalogic_test_locale'] = 'fa_IR';

		$view = Digitalogic_Panel_Error_Page::view_model(
			403,
			'comment-network-blocked',
			'',
			array( 'context' => Digitalogic_Panel_Error_Page::CONTEXT_COMMENT_GUARD )
		);

		$this->assertSame( Digitalogic_Panel_Error_Page::CONTEXT_COMMENT_GUARD, $view['context'] );
		$this->assertSame( 'امکان پذیرش این دیدگاه نبود', $view['title'] );
		$this->assertSame( 'ارسال دیدگاه از این شبکه پذیرفته نشد.', $view['detail'] );
		$this->assertCount( 1, $view['actions'] );
		$this->assertSame( 'بازگشت به فروشگاه', $view['actions'][0]['label'] );
		$this->assertSame( 'https://digitalogic.test/', $view['actions'][0]['url'] );
	}

	/**
	 * Admin callbacks render a card without nesting a second HTML document.
	 */
	public function test_admin_mode_is_embedded_in_the_existing_document(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 18;

		ob_start();
		Digitalogic_Panel_Error_Page::render_admin(
			403,
			'patris-reports-access-denied',
			'',
			array( 'context' => Digitalogic_Panel_Error_Page::CONTEXT_PATRIS_REPORTS )
		);
		$html = (string) ob_get_clean();

		$this->assertSame( array(), $GLOBALS['digitalogic_test_status_headers'] );
		$this->assertSame( 0, $GLOBALS['digitalogic_test_nocache_headers'] );
		$this->assertStringContainsString( 'class="wrap digitalogic-error-embedded"', $html );
		$this->assertStringContainsString( 'DG-PATRIS-REPORTS-ACCESS-DENIED', $html );
		$this->assertStringNotContainsString( '<!doctype html>', $html );
		$this->assertStringNotContainsString( '<html', $html );
		$this->assertStringNotContainsString( '<body', $html );
	}

	/**
	 * Private request downloads retain specific forbidden and missing responses.
	 */
	public function test_request_download_context_preserves_security_status_copy(): void {
		$GLOBALS['digitalogic_test_current_user_id'] = 18;

		$forbidden = Digitalogic_Panel_Error_Page::view_model(
			403,
			'request-download-access-denied',
			'',
			array( 'context' => Digitalogic_Panel_Error_Page::CONTEXT_REQUEST_DOWNLOAD )
		);
		$missing   = Digitalogic_Panel_Error_Page::view_model(
			404,
			'request-file-not-found',
			'',
			array( 'context' => Digitalogic_Panel_Error_Page::CONTEXT_REQUEST_DOWNLOAD )
		);

		$this->assertSame( 403, $forbidden['status'] );
		$this->assertSame( 'This request file is protected', $forbidden['title'] );
		$this->assertSame( 404, $missing['status'] );
		$this->assertSame( 'This request file is no longer available', $missing['title'] );
		$this->assertSame( 'https://digitalogic.test/wp-admin/', $missing['actions'][0]['url'] );
	}

	/**
	 * Browser-facing plugin denials no longer invoke WordPress' raw die screen.
	 */
	public function test_browser_error_call_sites_do_not_use_raw_wp_die(): void {
		$paths = array(
			dirname( __DIR__ ) . '/includes/admin/class-admin.php',
			dirname( __DIR__ ) . '/includes/integrations/class-comment-guard.php',
			dirname( __DIR__ ) . '/includes/integrations/class-storefront-order-forms.php',
		);

		foreach ( $paths as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source audit.
			$source = file_get_contents( $path );
			$this->assertIsString( $source );
			$this->assertDoesNotMatchRegularExpression( '/\\bwp_die\\s*\\(/', $source, $path );
		}
	}
}
