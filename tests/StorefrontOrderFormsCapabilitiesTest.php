<?php
/**
 * Storefront order-request capability tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/integrations/class-storefront-order-forms.php';

/**
 * Prevents custom request caps from poisoning manage_woocommerce globally.
 */
final class StorefrontOrderFormsCapabilitiesTest extends TestCase {

	/**
	 * Reset post-type registration fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_registered_post_types'] = array();
		$GLOBALS['digitalogic_test_post_type_meta_caps']   = array();
	}

	/**
	 * Object caps are unique while collection operations remain manager-only.
	 */
	public function test_request_type_does_not_register_manage_woocommerce_as_a_meta_cap(): void {
		$forms = ( new ReflectionClass( Digitalogic_Storefront_Order_Forms::class ) )->newInstanceWithoutConstructor();
		$forms->register_request_type();

		$args = $GLOBALS['digitalogic_test_registered_post_types']['digitalogic_request'];
		$caps = $args['capabilities'];

		$this->assertTrue( $args['map_meta_cap'] );
		$this->assertSame( 'edit_digitalogic_request', $caps['edit_post'] );
		$this->assertSame( 'read_digitalogic_request', $caps['read_post'] );
		$this->assertSame( 'delete_digitalogic_request', $caps['delete_post'] );
		$this->assertArrayNotHasKey( 'manage_woocommerce', $GLOBALS['digitalogic_test_post_type_meta_caps'] );

		foreach ( array( 'edit_posts', 'edit_others_posts', 'read_private_posts', 'delete_posts' ) as $collection_cap ) {
			$this->assertSame( 'manage_woocommerce', $caps[ $collection_cap ] );
		}
		$this->assertSame( 'do_not_allow', $caps['create_posts'] );
	}
}
