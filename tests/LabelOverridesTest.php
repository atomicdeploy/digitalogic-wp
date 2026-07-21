<?php
/**
 * User-facing product label tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify imported source identities use neutral storefront terminology.
 */
final class LabelOverridesTest extends TestCase {

	/** Reset captured hooks before each registration assertion. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['digitalogic_test_filters']          = array();
		$GLOBALS['digitalogic_test_action_callbacks'] = array();
	}

	/** Cover rendered HTML while preserving unrelated source-system wording. */
	public function test_replaces_product_code_and_serial_labels_in_rendered_html(): void {
		$html = '<dl><dt>کد پاتریس:</dt><dd>1001</dd><dt>سریال پاتریس :</dt><dd>S-9</dd></dl><p>گزارش پاتریس</p>';
		$result = Digitalogic_Label_Overrides::replace_labels_in_html($html);

		$this->assertStringContainsString('<dt>کد کالا:</dt>', $result);
		$this->assertStringContainsString('<dt>سریال کالا :</dt>', $result);
		$this->assertStringContainsString('<p>گزارش پاتریس</p>', $result);
		$this->assertStringNotContainsString('کد پاتریس', $result);
		$this->assertStringNotContainsString('سریال پاتریس', $result);
	}

	/** Cover actual WooCommerce and ACF label surfaces without changing values. */
	public function test_replaces_existing_acf_attribute_cart_and_order_labels_only(): void {
		$field = Digitalogic_Label_Overrides::replace_acf_field_label(
			array(
				'name'  => 'patris_serial',
				'label' => 'سریال پاتریس',
				'value' => 'SER-42',
			)
		);
		$this->assertSame('سریال کالا', $field['label']);
		$this->assertSame('patris_serial', $field['name']);
		$this->assertSame('SER-42', $field['value']);

		$this->assertSame(
			'کد کالا',
			Digitalogic_Label_Overrides::replace_woocommerce_attribute_label('کد پاتریس')
		);
		$this->assertSame(
			'سریال کالا',
			Digitalogic_Label_Overrides::replace_order_item_meta_key('سریال پاتریس')
		);

		$item_data = Digitalogic_Label_Overrides::replace_cart_item_data_labels(
			array(
				array('key' => 'کد پاتریس', 'value' => '1001'),
				array('key' => 'سریال پاتریس', 'value' => 'SER-42'),
			)
		);
		$this->assertSame('کد کالا', $item_data[0]['key']);
		$this->assertSame('1001', $item_data[0]['value']);
		$this->assertSame('سریال کالا', $item_data[1]['key']);
		$this->assertSame('SER-42', $item_data[1]['value']);
	}

	/** Ensure runtime hooks cover storefront, checkout, order/invoice, and ACF UI. */
	public function test_registers_product_identity_label_filters(): void {
		Digitalogic_Label_Overrides::init();

		foreach (
			array(
				'acf/load_field',
				'woocommerce_attribute_label',
				'woocommerce_get_item_data',
				'woocommerce_order_item_display_meta_key',
			) as $hook
		) {
			$this->assertArrayHasKey($hook, $GLOBALS['digitalogic_test_filters']);
			$this->assertNotEmpty($GLOBALS['digitalogic_test_filters'][$hook]);
		}
	}

	/** The gettext layer handles known site domains but leaves unrelated domains alone. */
	public function test_gettext_normalizes_known_site_domains_only(): void {
		$this->assertSame(
			'کد کالا:',
			Digitalogic_Label_Overrides::gettext('کد پاتریس:', 'Patris Code:', 'acf')
		);
		$this->assertSame(
			'سریال پاتریس',
			Digitalogic_Label_Overrides::gettext('سریال پاتریس', 'Patris Serial', 'unrelated')
		);
	}

	/** Active storefront assets must not fall back to source-branded Persian labels. */
	public function test_active_storefront_sources_use_generic_product_code_label(): void {
		$paths = array(
			dirname( __DIR__ ) . '/assets/js/product-identity.js',
			dirname( __DIR__ ) . '/includes/integrations/class-homepage-showcase.php',
			dirname( __DIR__ ) . '/includes/integrations/class-storefront-product-table.php',
		);

		foreach ( $paths as $path ) {
			$source = file_get_contents( $path );
			$this->assertIsString( $source, $path );
			$this->assertStringContainsString( 'کد کالا', $source, $path );
			$this->assertStringNotContainsString( 'کد پاتریس', $source, $path );
			$this->assertStringNotContainsString( 'سریال پاتریس', $source, $path );
		}
	}
}
