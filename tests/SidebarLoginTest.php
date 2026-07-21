<?php // phpcs:ignoreFile -- Test stubs intentionally share one fixture file with the test case.
/**
 * Storefront sidebar asset regression tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'DIGITALOGIC_PLUGIN_URL' ) ) {
	define( 'DIGITALOGIC_PLUGIN_URL', 'https://digitalogic.test/wp-content/plugins/digitalogic-wp/' );
}

if ( ! defined( 'DIGITALOGIC_PLUGIN_DIR' ) ) {
	define( 'DIGITALOGIC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['digitalogic_test_is_admin'] );
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return ! empty( $GLOBALS['digitalogic_test_is_user_logged_in'] );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $dependencies = array(), $version = false ) {
		$GLOBALS['digitalogic_test_enqueued_styles'][ $handle ] = compact( 'src', 'dependencies', 'version' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $dependencies = array(), $version = false, $in_footer = false ) {
		$GLOBALS['digitalogic_test_enqueued_scripts'][ $handle ] = compact( 'src', 'dependencies', 'version', 'in_footer' );
	}
}

if ( ! function_exists( 'wp_styles' ) ) {
	function wp_styles() {
		return $GLOBALS['digitalogic_test_styles_registry'];
	}
}

if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( $handle, $status = 'enqueued' ) {
		return 'enqueued' === $status && in_array( $handle, $GLOBALS['digitalogic_test_enqueued_style_handles'], true );
	}
}

require_once dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-sidebar-login.php';

/**
 * Verifies guest-only loading and deterministic ordering of the sidebar styles.
 */
final class SidebarLoginTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_is_admin']           = false;
		$GLOBALS['digitalogic_test_is_user_logged_in']  = false;
		$GLOBALS['digitalogic_test_enqueued_styles']    = array();
		$GLOBALS['digitalogic_test_enqueued_scripts']   = array();
		$GLOBALS['digitalogic_test_action_callbacks']   = array();
		$GLOBALS['digitalogic_test_enqueued_style_handles'] = array( 'digits-style', 'digits-login-style-rtl' );
		$GLOBALS['digitalogic_test_styles_registry']    = (object) array(
			'registered' => array(
				'digits-style'           => (object) array( 'deps' => array() ),
				'digits-login-style-rtl' => (object) array( 'deps' => array() ),
			),
		);
	}

	public function test_registers_the_late_compatibility_hook_once(): void {
		Digitalogic_Sidebar_Login::init();
		Digitalogic_Sidebar_Login::init();

		$callbacks = $GLOBALS['digitalogic_test_action_callbacks']['wp_enqueue_scripts'];
		$this->assertCount( 1, $callbacks );
		$this->assertSame( 100, $callbacks[0]['priority'] );
		$this->assertSame( array( Digitalogic_Sidebar_Login::class, 'enqueue_sidebar_assets' ), $callbacks[0]['callback'] );
	}

	public function test_guest_frontend_enqueues_scoped_assets_after_homepage_digits_layers(): void {
		Digitalogic_Sidebar_Login::enqueue_sidebar_assets();

		$styles = $GLOBALS['digitalogic_test_enqueued_styles'];
		$this->assertCount( 1, $styles );
		$this->assertArrayHasKey( 'digitalogic-sidebar-login', $styles );
		$this->assertSame(
			array( 'digitalogic-call-verification', 'digits-style', 'digits-login-style-rtl' ),
			$styles['digitalogic-sidebar-login']['dependencies']
		);

		$scripts = $GLOBALS['digitalogic_test_enqueued_scripts'];
		$this->assertCount( 1, $scripts );
		$this->assertSame(
			'https://digitalogic.test/wp-content/plugins/digitalogic-wp/assets/js/sidebar-login.js',
			$scripts['digitalogic-sidebar-login']['src']
		);
		$this->assertSame(
			array( 'digitalogic-call-verification' ),
			$scripts['digitalogic-sidebar-login']['dependencies']
		);
		$this->assertTrue( $scripts['digitalogic-sidebar-login']['in_footer'] );
	}

	public function test_compatibility_style_never_forces_the_global_vendor_base_even_when_queued(): void {
		$GLOBALS['digitalogic_test_styles_registry']->registered['digits-login-style'] = (object) array( 'deps' => array() );
		array_unshift( $GLOBALS['digitalogic_test_enqueued_style_handles'], 'digits-login-style' );

		Digitalogic_Sidebar_Login::enqueue_sidebar_assets();

		$this->assertSame(
			array( 'digitalogic-call-verification', 'digits-style', 'digits-login-style-rtl' ),
			$GLOBALS['digitalogic_test_enqueued_styles']['digitalogic-sidebar-login']['dependencies']
		);
	}

	public function test_a_registered_but_unqueued_vendor_base_is_not_forced_onto_the_homepage(): void {
		$GLOBALS['digitalogic_test_styles_registry']->registered['digits-login-style'] = (object) array( 'deps' => array() );

		Digitalogic_Sidebar_Login::enqueue_sidebar_assets();

		$this->assertSame(
			array( 'digitalogic-call-verification', 'digits-style', 'digits-login-style-rtl' ),
			$GLOBALS['digitalogic_test_enqueued_styles']['digitalogic-sidebar-login']['dependencies']
		);
	}

	public function test_assets_are_not_loaded_in_admin_or_for_authenticated_visitors(): void {
		$GLOBALS['digitalogic_test_is_admin'] = true;
		Digitalogic_Sidebar_Login::enqueue_sidebar_assets();
		$this->assertSame( array(), $GLOBALS['digitalogic_test_enqueued_styles'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_enqueued_scripts'] );

		$GLOBALS['digitalogic_test_is_admin']          = false;
		$GLOBALS['digitalogic_test_is_user_logged_in'] = true;
		Digitalogic_Sidebar_Login::enqueue_sidebar_assets();
		$this->assertSame( array(), $GLOBALS['digitalogic_test_enqueued_styles'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_enqueued_scripts'] );
	}
}
