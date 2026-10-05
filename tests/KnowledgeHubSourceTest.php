<?php
/**
 * Knowledge hub source-contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verifies that the public knowledge feature keeps its reviewed contracts. */
final class KnowledgeHubSourceTest extends TestCase {
	/**
	 * Integration source.
	 *
	 * @var string
	 */
	private string $source;

	/** Load the source under test. */
	protected function setUp(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		$this->source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/integrations/class-digitalogic-knowledge-hub.php' );
	}

	/** Shared taxonomies and both public shortcodes remain registered. */
	public function test_registers_shared_product_vocabularies_and_public_library(): void {
		$this->assertStringContainsString( "register_post_type(\n\t\t\tself::POST_TYPE", $this->source );
		$this->assertStringContainsString( "array( 'product', self::POST_TYPE )", $this->source );
		$this->assertStringContainsString( "'dgl_software_library'", $this->source );
		$this->assertStringContainsString( "'dgl_sbc_catalog'", $this->source );
		$this->assertStringContainsString( "'dgl_product_knowledge'", $this->source );
	}

	/** Official links are durable and version numbers are not frozen. */
	public function test_official_downloads_are_https_and_no_version_is_frozen(): void {
		$this->assertStringContainsString( 'https://www.raspberrypi.com/software/', $this->source );
		$this->assertStringContainsString( 'https://github.com/rustdesk/rustdesk/releases', $this->source );
		$this->assertStringContainsString( 'https://www.arduino.cc/en/software', $this->source );
		$this->assertStringContainsString( 'https://docs.platformio.org/en/latest/integration/ide/vscode.html', $this->source );
		$this->assertDoesNotMatchRegularExpression( '/Arduino IDE [0-9]+\.[0-9]+/', $this->source );
	}

	/** ESP package indexes and the storage-erasure warning remain present. */
	public function test_esp_board_manager_urls_and_destructive_storage_warning_are_present(): void {
		$this->assertStringContainsString( 'https://espressif.github.io/arduino-esp32/package_esp32_index.json', $this->source );
		$this->assertStringContainsString( 'https://arduino.esp8266.com/stable/package_esp8266com_index.json', $this->source );
		$this->assertStringContainsString( 'نوشتن ایمیج تمام داده‌های آن را پاک می‌کند', $this->source );
	}

	/** The plugin retains responsive cards and OS badges. */
	public function test_responsive_card_design_is_owned_by_plugin(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/knowledge-hub.css' );
		$this->assertStringContainsString( '.dgl-khub__grid', $css );
		$this->assertStringContainsString( '@media (max-width:700px)', $css );
		$this->assertStringContainsString( '.dgl-khub__pill--os', $css );
	}
}
