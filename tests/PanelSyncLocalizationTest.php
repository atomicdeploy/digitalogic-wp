<?php
/**
 * Panel product-sync localization tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Protect bilingual product-sync copy and the technical endpoint.
 */
final class PanelSyncLocalizationTest extends TestCase {

	/**
	 * Product-sync labels and descriptions must be complete in both languages.
	 */
	public function test_sync_copy_is_complete_in_english_and_persian(): void {
		$panel = Digitalogic_Panel::instance();

		$english_method = new ReflectionMethod( Digitalogic_Panel::class, 'translations_en' );
		$persian_method = new ReflectionMethod( Digitalogic_Panel::class, 'translations_fa' );
		$english        = $english_method->invoke( $panel );
		$persian        = $persian_method->invoke( $panel );

		$this->assertSame( 'Integration service', $english['syncPage']['repository'] );
		$this->assertSame( 'Digitalogic normalized product API', $english['syncPage']['serviceValue'] );
		$this->assertSame( 'Mode', $english['syncPage']['mode'] );
		$this->assertSame(
			'Pull a scheduled or manual feed, or receive an authenticated payload in WooCommerce',
			$english['syncPage']['modeValue']
		);
		$this->assertSame( 'Suggested sync endpoint', $english['syncPage']['endpoint'] );
		$this->assertSame( 'Authenticated WooCommerce update endpoint', $english['syncPage']['endpointValue'] );

		$this->assertSame( 'سرویس یکپارچه‌سازی', $persian['syncPage']['repository'] );
		$this->assertSame( 'رابط استاندارد محصولات دیجیتالاجیک', $persian['syncPage']['serviceValue'] );
		$this->assertSame( 'روش همگام‌سازی', $persian['syncPage']['mode'] );
		$this->assertSame(
			'دریافت زمان‌بندی‌شده یا دستی داده‌ها یا دریافت امن داده در ووکامرس',
			$persian['syncPage']['modeValue']
		);
		$this->assertSame( 'نشانی پیشنهادی همگام‌سازی', $persian['syncPage']['endpoint'] );
		$this->assertSame( 'نشانی امن به‌روزرسانی ووکامرس', $persian['syncPage']['endpointValue'] );

		foreach ( array(
			'repository',
			'serviceValue',
			'mode',
			'modeValue',
			'endpoint',
			'endpointValue',
		) as $key ) {
			$this->assertNotSame( $english['syncPage'][ $key ], $persian['syncPage'][ $key ] );
		}
	}

	/**
	 * The Vue template must use the active dictionary and preserve the raw route.
	 */
	public function test_sync_view_uses_dictionary_copy_and_keeps_endpoint_ltr(): void {
		$file = new SplFileObject( dirname( __DIR__ ) . '/includes/panel/views/app.php' );
		$view = '';

		while ( ! $file->eof() ) {
			$view .= $file->fgets();
		}

		$this->assertIsString( $view );
		$this->assertStringContainsString( '{{ t.syncPage.repository }}', $view );
		$this->assertStringContainsString( '{{ t.syncPage.serviceValue }}', $view );
		$this->assertStringContainsString( '{{ t.syncPage.mode }}', $view );
		$this->assertStringContainsString( '{{ t.syncPage.modeValue }}', $view );
		$this->assertStringContainsString( '{{ t.syncPage.endpoint }}', $view );
		$this->assertStringContainsString( '{{ t.syncPage.endpointValue }}', $view );
		$this->assertStringContainsString(
			'<code class="dlp-code" dir="ltr">{{ patris.suggested_bridge }}</code>',
			$view
		);
		$this->assertStringNotContainsString( '<span>Repository</span>', $view );
		$this->assertStringNotContainsString( '<span>Mode</span>', $view );
		$this->assertStringNotContainsString( '<span>Suggested watcher</span>', $view );
	}
}
