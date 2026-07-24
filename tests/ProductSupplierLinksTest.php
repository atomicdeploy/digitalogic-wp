<?php
/**
 * Private product supplier-link contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify storage, privacy, admin, and CLI boundaries. */
final class ProductSupplierLinksTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Digitalogic_Product_Supplier_Links
	 */
	private $service;

	/** Reset deterministic product fixtures. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_posts']                = array(
			101 => array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'ماژول تست',
				'meta'        => array( '_sku' => 'MODULE-101' ),
			),
			102 => array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => 101,
				'meta'        => array( '_sku' => 'MODULE-101-BLUE' ),
			),
		);
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_capabilities']         = array(
			'manage_options' => true,
			'edit_post'      => true,
		);
		$GLOBALS['digitalogic_test_registered_post_meta'] = array();
		$GLOBALS['digitalogic_test_meta_boxes']           = array();
		$GLOBALS['digitalogic_test_enqueued_scripts']     = array();
		$GLOBALS['digitalogic_test_enqueued_styles']      = array();
		$GLOBALS['digitalogic_test_valid_nonces']         = array();
		$GLOBALS['digitalogic_test_autosaves']            = array();
		$GLOBALS['digitalogic_test_revisions']            = array();
		$GLOBALS['digitalogic_test_current_user_id']      = 7;
		$GLOBALS['digitalogic_test_current_screen']       = (object) array( 'post_type' => 'product' );
		$GLOBALS['wpdb']                                  = new Digitalogic_Test_WPDB();
		WP_CLI::$errors                                   = array();
		WP_CLI::$logs                                     = array();
		$_POST         = array();
		$this->service = Digitalogic_Product_Supplier_Links::instance();
	}

	/** Verify protected registration and the strict administrator capability pair. */
	public function test_registered_meta_is_private_and_admin_authorized(): void {
		$this->service->register_meta();

		$args = $GLOBALS['digitalogic_test_registered_post_meta']['product'][ Digitalogic_Product_Supplier_Links::META_KEY ];
		$this->assertFalse( $args['show_in_rest'] );
		$this->assertTrue( $args['single'] );
		$this->assertSame( 'array', $args['type'] );
		$this->assertTrue( $this->service->authorize_meta( false, Digitalogic_Product_Supplier_Links::META_KEY, 101 ) );

		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = false;
		$this->assertFalse( $this->service->authorize_meta( true, Digitalogic_Product_Supplier_Links::META_KEY, 101 ) );
	}

	/** Verify Persian data, provider inference, URL normalization, and de-duplication. */
	public function test_replace_links_normalizes_and_persists_reviewed_rows(): void {
		$result = $this->service->replace_links(
			101,
			array(
				array(
					'url'          => 'HTTPS://Item.Taobao.com/item.htm?id=123#tracking',
					'site_name'    => 'فروشنده چین',
					'source_title' => 'ماژول بلوتوث اصلی',
					'source'       => 'purchase_history',
					'status'       => 'matched',
					'note'         => "خرید قبلی\nتطبیق‌شده",
					'last_checked' => '2026-07-24',
				),
				array(
					'url'          => 'https://item.taobao.com/item.htm?id=123',
					'source_title' => 'ردیف تکراری',
				),
				array(
					'url'       => 'https://example.ir/product/module',
					'site_name' => 'فروشگاه ایرانی نمونه',
					'source'    => 'iranian_market',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'taobao', $result[0]['marketplace'] );
		$this->assertSame( 'https://item.taobao.com/item.htm?id=123', $result[0]['url'] );
		$this->assertSame( 'فروشنده چین', $result[0]['site_name'] );
		$this->assertSame( 'ماژول بلوتوث اصلی', $result[0]['source_title'] );
		$this->assertSame( 'purchase_history', $result[0]['source'] );
		$this->assertSame( 'matched', $result[0]['status'] );
		$this->assertStringStartsWith( 'sl_', $result[0]['id'] );
		$this->assertSame( 'iranian_market', $result[1]['marketplace'] );
		$this->assertSame( $result, get_post_meta( 101, Digitalogic_Product_Supplier_Links::META_KEY, true ) );
	}

	/** Verify imported IDs cannot forge or collide with an existing private row. */
	public function test_only_existing_ids_are_preserved_and_duplicate_ids_are_rejected(): void {
		$forged_id = 'sl_0123456789abcdef0123';
		$initial   = $this->service->replace_links(
			101,
			array(
				array(
					'id'  => $forged_id,
					'url' => 'https://item.taobao.com/item.htm?id=400',
				),
			)
		);

		$this->assertNotSame( $forged_id, $initial[0]['id'] );
		$existing_id = $initial[0]['id'];

		$collision = $this->service->replace_links(
			101,
			array(
				array(
					'id'  => $existing_id,
					'url' => 'https://item.taobao.com/item.htm?id=401',
				),
				array(
					'id'  => $existing_id,
					'url' => 'https://item.taobao.com/item.htm?id=401',
				),
			)
		);

		$this->assertSame( 'digitalogic_supplier_link_id_duplicate', $collision->get_error_code() );
		$this->assertSame( $initial, $this->service->get_links( 101 ) );
	}

	/** Verify unsafe URLs and variation targets cannot alter storage. */
	public function test_invalid_url_and_variation_target_are_rejected(): void {
		$invalid_url    = $this->service->replace_links(
			101,
			array( array( 'url' => 'javascript:alert(1)' ) )
		);
		$variation      = $this->service->replace_links(
			102,
			array( array( 'url' => 'https://example.com/item' ) )
		);
		$non_scalar_url = $this->service->replace_links(
			101,
			array( array( 'url' => array( 'https://example.com/item' ) ) )
		);

		$this->assertSame( 'digitalogic_supplier_link_url_invalid', $invalid_url->get_error_code() );
		$this->assertSame( 'digitalogic_supplier_link_url_invalid', $non_scalar_url->get_error_code() );
		$this->assertSame( 'digitalogic_supplier_links_parent_product_required', $variation->get_error_code() );
		$this->assertSame( '', get_post_meta( 101, Digitalogic_Product_Supplier_Links::META_KEY, true ) );
		$this->assertSame( '', get_post_meta( 102, Digitalogic_Product_Supplier_Links::META_KEY, true ) );
	}

	/** Verify WooCommerce REST and webhook payloads never contain private metadata. */
	public function test_rest_and_webhook_payloads_are_scrubbed_recursively(): void {
		$private       = array(
			'id'    => 8,
			'key'   => Digitalogic_Product_Supplier_Links::META_KEY,
			'value' => array( array( 'url' => 'https://secret.example/item' ) ),
		);
		$public        = array(
			'id'    => 9,
			'key'   => '_public_fixture',
			'value' => 'visible',
		);
		$mixed_private = array(
			'id'    => 10,
			'key'   => strtoupper( Digitalogic_Product_Supplier_Links::META_KEY ),
			'value' => array( array( 'url' => 'https://mixed-secret.example/item' ) ),
		);
		$response      = new WP_REST_Response(
			array(
				'id'        => 101,
				'meta_data' => array( $private, $mixed_private, $public ),
				'child'     => array( 'meta_data' => array( $mixed_private ) ),
			)
		);

		$this->service->scrub_rest_response( $response );
		$data = $response->get_data();
		$this->assertSame( array( $public ), $data['meta_data'] );
		$this->assertSame( array(), $data['child']['meta_data'] );

		$webhook = $this->service->scrub_webhook_payload( array( 'meta_data' => array( $private, $mixed_private, $public ) ) );
		$this->assertSame( array( $public ), $webhook['meta_data'] );
		$this->assertStringNotContainsString( 'secret.example', wp_json_encode( $webhook ) );
		$this->assertStringNotContainsString( 'mixed-secret.example', wp_json_encode( $webhook ) );
	}

	/** Verify a REST write attempt restores the existing private value. */
	public function test_rest_write_attempt_cannot_replace_private_metadata(): void {
		$stored    = $this->service->replace_links(
			101,
			array( array( 'url' => 'https://item.taobao.com/item.htm?id=321' ) )
		);
		$product   = new WC_Product( 101 );
		$mixed_key = strtoupper( Digitalogic_Product_Supplier_Links::META_KEY );
		$product->update_meta_data(
			$mixed_key,
			array( array( 'url' => 'https://attacker.example/item' ) )
		);
		$request = new WP_REST_Request(
			array(),
			array(
				'meta_data' => array(
					array(
						'key'   => $mixed_key,
						'value' => array( array( 'url' => 'https://attacker.example/item' ) ),
					),
				),
			)
		);

		$result = $this->service->prevent_rest_write( $product, $request );

		$this->assertSame( $stored, $result->get_meta( Digitalogic_Product_Supplier_Links::META_KEY ) );
		$this->assertSame( '', $result->get_meta( $mixed_key ) );
		$this->assertStringNotContainsString( 'attacker.example', wp_json_encode( $result->get_meta( Digitalogic_Product_Supplier_Links::META_KEY ) ) );
	}

	/** Verify the WordPress postbox ID cannot shadow the JavaScript repeater root. */
	public function test_admin_metabox_and_repeater_root_ids_do_not_collide(): void {
		$admin = Digitalogic_Product_Supplier_Links_Admin::instance();
		$admin->add_meta_box( get_post( 101 ) );

		$this->assertArrayHasKey( 'digitalogic-private-supplier-links-box', $GLOBALS['digitalogic_test_meta_boxes'] );
		$this->assertArrayNotHasKey( 'digitalogic-private-supplier-links', $GLOBALS['digitalogic_test_meta_boxes'] );

		ob_start();
		$admin->render_meta_box( get_post( 101 ) );
		$output = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $output, 'id="digitalogic-private-supplier-links"' ) );
		$this->assertStringContainsString( 'data-next-index="0"', $output );
	}

	/** Verify nonce-guarded admin save and a redacted list-table column. */
	public function test_admin_save_and_list_column_never_print_raw_urls(): void {
		$admin = Digitalogic_Product_Supplier_Links_Admin::instance();
		$GLOBALS['digitalogic_test_valid_nonces']['digitalogic_save_supplier_links'] = 'valid-nonce';
		$_POST = array(
			'digitalogic_supplier_links_nonce' => 'valid-nonce',
			'digitalogic_supplier_links'       => array(
				array(
					'url'          => 'https://www.aliexpress.com/item/100500123.html',
					'source_title' => 'برد توسعه',
				),
			),
		);

		$admin->save_product( 101, get_post( 101 ), true );
		$this->assertCount( 1, $this->service->get_links( 101 ) );

		ob_start();
		$admin->render_product_column( 'digitalogic_supplier_links', 101 );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'علی‌اکسپرس', $output );
		$this->assertStringNotContainsString( 'aliexpress.com', $output );
	}

	/** Verify denied or autosave requests leave existing metadata untouched. */
	public function test_admin_save_guards_block_unauthorized_and_autosave_writes(): void {
		$existing = $this->service->replace_links(
			101,
			array( array( 'url' => 'https://www.alibaba.com/product-detail/example.html' ) )
		);
		$admin    = Digitalogic_Product_Supplier_Links_Admin::instance();
		$GLOBALS['digitalogic_test_valid_nonces']['digitalogic_save_supplier_links'] = 'valid-nonce';
		$_POST = array(
			'digitalogic_supplier_links_nonce' => 'valid-nonce',
			'digitalogic_supplier_links'       => array(
				array( 'url' => 'https://example.ir/replacement' ),
			),
		);

		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = false;
		$admin->save_product( 101, get_post( 101 ), true );
		$this->assertSame( $existing, $this->service->get_links( 101 ) );

		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$GLOBALS['digitalogic_test_autosaves'][]                    = 101;
		$admin->save_product( 101, get_post( 101 ), true );
		$this->assertSame( $existing, $this->service->get_links( 101 ) );
	}

	/** Verify CLI JSON shape checks and standalone command registration. */
	public function test_cli_json_decoder_and_command_registration(): void {
		$single      = Digitalogic_Product_Supplier_Links_CLI::decode_json_input(
			'{"url":"https://example.ir/کالا","source_title":"ماژول ایرانی"}',
			false
		);
		$list        = Digitalogic_Product_Supplier_Links_CLI::decode_json_input(
			'[{"url":"https://item.taobao.com/item.htm?id=1"}]',
			true
		);
		$wrong_shape = Digitalogic_Product_Supplier_Links_CLI::decode_json_input( '{}', true );

		$this->assertSame( 'ماژول ایرانی', $single['source_title'] );
		$this->assertCount( 1, $list );
		$this->assertSame( 'digitalogic_supplier_links_json_shape_invalid', $wrong_shape->get_error_code() );
		foreach ( array( 'list', 'add', 'replace', 'remove' ) as $command ) {
			$this->assertArrayHasKey( 'digitalogic supplier-links ' . $command, WP_CLI::$commands );
		}
	}

	/** Verify CLI reads expose operational status but never seller details. */
	public function test_cli_list_output_is_redacted(): void {
		$this->service->replace_links(
			101,
			array(
				array(
					'url'          => 'https://item.taobao.com/item.htm?id=2468',
					'source_title' => 'Private purchased module',
					'seller'       => 'Private seller',
					'seller_sku'   => 'PRIVATE-SKU',
					'note'         => 'Private procurement note',
					'source'       => 'purchase_history',
					'status'       => 'purchased',
				),
			)
		);

		$cli = new Digitalogic_Product_Supplier_Links_CLI();
		$cli->list_links( array(), array( 'id' => '101' ) );

		$this->assertNotEmpty( WP_CLI::$logs );
		$output = (string) end( WP_CLI::$logs );
		$data   = json_decode( $output, true );

		$this->assertSame( '101', $data['product_id'] );
		$this->assertSame( 1, $data['count'] );
		$this->assertSame( 'taobao', $data['links'][0]['marketplace'] );
		$this->assertSame( 'purchase_history', $data['links'][0]['source'] );
		$this->assertSame( 'purchased', $data['links'][0]['status'] );
		$this->assertTrue( $data['links'][0]['has_url'] );
		$this->assertTrue( $data['links'][0]['has_note'] );
		$this->assertArrayNotHasKey( 'url', $data['links'][0] );
		$this->assertArrayNotHasKey( 'note', $data['links'][0] );
		$this->assertArrayNotHasKey( 'seller', $data['links'][0] );
		$this->assertArrayNotHasKey( 'seller_sku', $data['links'][0] );
		$this->assertArrayNotHasKey( 'site_name', $data['links'][0] );
		$this->assertArrayNotHasKey( 'source_title', $data['links'][0] );
		$this->assertStringNotContainsString( 'taobao.com', $output );
		$this->assertStringNotContainsString( 'Private seller', $output );
		$this->assertStringNotContainsString( 'Private procurement note', $output );
	}

	/** Verify CLI mutations require admin and never echo a seller URL. */
	public function test_cli_remove_is_admin_gated_and_mutation_output_is_redacted(): void {
		$links = $this->service->replace_links(
			101,
			array( array( 'url' => 'https://item.taobao.com/item.htm?id=987' ) )
		);
		$cli   = new Digitalogic_Product_Supplier_Links_CLI();

		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = false;
		$cli->remove_link(
			array(),
			array(
				'id'      => '101',
				'link-id' => $links[0]['id'],
			)
		);
		$this->assertNotEmpty( WP_CLI::$errors );
		$this->assertCount( 1, $this->service->get_links( 101 ) );

		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		WP_CLI::$errors = array();
		$cli->remove_link(
			array(),
			array(
				'id'      => '101',
				'link-id' => $links[0]['id'],
			)
		);

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 0, $this->service->get_links( 101 ) );
		$this->assertStringNotContainsString( 'taobao.com', implode( "\n", WP_CLI::$logs ) );
	}
}
