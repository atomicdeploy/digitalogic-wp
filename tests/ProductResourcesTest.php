<?php // phpcs:ignoreFile -- Test stubs intentionally share one fixture file with the test case.
/**
 * Product document and mixed-direction specification regression tests.
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

if ( ! defined( 'DIGITALOGIC_VERSION' ) ) {
	define( 'DIGITALOGIC_VERSION', 'test' );
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'is_product' ) ) {
	function is_product() {
		return ! empty( $GLOBALS['digitalogic_test_is_product'] );
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return isset( $GLOBALS['digitalogic_test_queried_object_id'] ) ? (int) $GLOBALS['digitalogic_test_queried_object_id'] : 0;
	}
}

final class ProductResourcesTest extends TestCase {

	/**
	 * @var Digitalogic_Product_Resources
	 */
	private $resources;

	/**
	 * @var string
	 */
	private $pdf_path;

	protected function setUp(): void {
		$this->pdf_path = tempnam( sys_get_temp_dir(), 'dgl-pdf-' );
		file_put_contents( $this->pdf_path, "%PDF-1.4\nDigitalogic product document fixture\n%%EOF\n" );

		$GLOBALS['digitalogic_test_posts']                = array();
		$GLOBALS['digitalogic_test_post_meta_cache']      = array();
		$GLOBALS['digitalogic_test_meta_update_failures'] = array();
		$GLOBALS['digitalogic_test_attached_files']       = array();
		$GLOBALS['digitalogic_test_is_product']           = false;
		$GLOBALS['digitalogic_test_queried_object_id']    = 0;
		$GLOBALS['digitalogic_test_enqueued_styles']      = array();
		$this->resources = ( new ReflectionClass( Digitalogic_Product_Resources::class ) )->newInstanceWithoutConstructor();
	}

	protected function tearDown(): void {
		if ( is_string( $this->pdf_path ) && is_file( $this->pdf_path ) ) {
			unlink( $this->pdf_path );
		}
	}

	public function test_resolves_first_strong_character_direction(): void {
		$this->assertSame( 'rtl', Digitalogic_Product_Resources::direction_for_text( 'پایه‌دار روی PCB (Through-hole)' ) );
		$this->assertSame( 'ltr', Digitalogic_Product_Resources::direction_for_text( 'G8FE-1AP-L DC12' ) );
		$this->assertSame( 'ltr', Digitalogic_Product_Resources::direction_for_text( '12V DC' ) );
	}

	public function test_normalizes_product_experience_bdi_values(): void {
		$html = '<td><bdi dir="auto">پایه&zwnj;دار روی PCB (Through-hole)</bdi></td>'
			. "<td><bdi class=\"model\" DIR = 'AUTO'>G8FE-1AP-L DC12</bdi></td>"
			. '<td><bdi dir="ltr">already explicit</bdi></td>';

		$normalized = $this->resources->normalize_spec_directions( $html, 'dgl_product_specs' );

		$this->assertStringContainsString( '<bdi dir="rtl">پایه&zwnj;دار روی PCB (Through-hole)</bdi>', $normalized );
		$this->assertStringContainsString( '<bdi class="model" dir="ltr">G8FE-1AP-L DC12</bdi>', $normalized );
		$this->assertStringNotContainsString( 'dir="auto"', $normalized );
		$this->assertStringContainsString( '<bdi dir="ltr">already explicit</bdi>', $normalized );
		$this->assertSame( $html, $this->resources->normalize_spec_directions( $html, 'unrelated_shortcode' ) );
	}

	public function test_rejects_incomplete_or_non_official_document_pairs(): void {
		$this->seed_product_and_pdf();
		$document               = $this->valid_document();
		$document['source_url'] = 'http://example.test/g8fe.pdf';

		$result = $this->resources->replace_documents( 11867, array( $document ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_invalid_document', $result->get_error_code() );

		$document                  = $this->valid_document();
		$document['attachment_id'] = 999;
		$result                    = $this->resources->replace_documents( 11867, array( $document ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$GLOBALS['digitalogic_test_posts'][13090]['post_mime_type'] = 'text/plain';
		$result = $this->resources->replace_documents( 11867, array( $this->valid_document() ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$GLOBALS['digitalogic_test_posts'][13090]['post_mime_type'] = 'application/pdf';
		$document = $this->valid_document();
		$GLOBALS['digitalogic_test_posts'][13090]['post_parent'] = 55;
		$result = $this->resources->replace_documents( 11867, array( $document ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$GLOBALS['digitalogic_test_posts'][13090]['post_parent'] = 11867;
		$document               = $this->valid_document();
		$document['source_url'] = 'https://username:password@example.test/g8fe.pdf';
		$result                 = $this->resources->replace_documents( 11867, array( $document ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_rejects_file_provenance_or_timestamp_mismatches(): void {
		$this->seed_product_and_pdf();
		$document           = $this->valid_document();
		$document['sha256'] = str_repeat( '0', 64 );

		$result = $this->resources->replace_documents( 11867, array( $document ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$document          = $this->valid_document();
		$document['bytes'] = $document['bytes'] + 1;
		$result            = $this->resources->replace_documents( 11867, array( $document ) );
		$this->assertInstanceOf( WP_Error::class, $result );

		$document                = $this->valid_document();
		$document['verified_at'] = 'yesterday';
		$result                  = $this->resources->replace_documents( 11867, array( $document ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_writes_and_reads_back_closed_document_metadata(): void {
		$this->seed_product_and_pdf();
		$document = $this->valid_document();

		$result = $this->resources->replace_documents( 11867, array( $document ) );

		$this->assertSame( array( $document ), $result );
		$this->assertSame(
			array( $document ),
			get_post_meta( 11867, Digitalogic_Product_Resources::META_KEY, true )
		);
		$this->assertSame( array( $document ), $this->resources->get_documents( 11867 ) );
	}

	public function test_renders_hosted_download_and_official_source_actions(): void {
		$this->seed_product_and_pdf();
		$this->resources->replace_documents( 11867, array( $this->valid_document() ) );

		$html = $this->resources->render_documents( 11867 );

		$this->assertStringContainsString( 'data-digitalogic-product-documents', $html );
		$this->assertStringContainsString( 'دانلود از دیجیتالوجیک', $html );
		$this->assertStringContainsString( 'https://digitalogic.test/media/13090', $html );
		$this->assertMatchesRegularExpression( '/\sdownload(?:\s|>)/', $html );
		$this->assertStringContainsString( 'مشاهده منبع رسمی', $html );
		$this->assertStringContainsString( 'https://omronfs.omron.com/en_US/ecb/products/pdf/en-g8fe.pdf', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer nofollow external"', $html );
		$this->assertStringContainsString( 'G8FE-1AP-L DC12', $html );
		$this->assertStringContainsString( 'dgl-product-document__metadata', $html );
		$this->assertStringContainsString( '<bdi dir="ltr">OMRON</bdi>', $html );
	}

	public function test_appends_once_only_to_the_matching_product_specs_shortcode(): void {
		$this->seed_product_and_pdf();
		$this->resources->replace_documents( 11867, array( $this->valid_document() ) );
		$GLOBALS['digitalogic_test_is_product']        = true;
		$GLOBALS['digitalogic_test_queried_object_id'] = 11867;

		$specs  = '<section class="dgl-dynamic-specs">مشخصات</section>';
		$output = $this->resources->append_documents_to_specs( $specs, 'dgl_product_specs' );
		$this->assertStringContainsString( 'data-digitalogic-product-documents', $output );
		$this->assertSame( 1, substr_count( $output, 'data-digitalogic-product-documents' ) );
		$this->assertSame( $output, $this->resources->append_documents_to_specs( $output, 'dgl_product_specs' ) );
		$this->assertSame( $specs, $this->resources->append_documents_to_specs( $specs, 'unrelated_shortcode' ) );

		$GLOBALS['digitalogic_test_is_product'] = false;
		$this->assertSame( $specs, $this->resources->append_documents_to_specs( $specs, 'dgl_product_specs' ) );
	}

	public function test_registers_shortcode_filters_with_all_wordpress_arguments(): void {
		$old_filters                         = $GLOBALS['digitalogic_test_filters'];
		$GLOBALS['digitalogic_test_filters'] = array();

		try {
			$reflection  = new ReflectionClass( Digitalogic_Product_Resources::class );
			$instance    = $reflection->newInstanceWithoutConstructor();
			$constructor = $reflection->getConstructor();
			$constructor->invoke( $instance );

			$this->assertCount( 2, $GLOBALS['digitalogic_test_filters']['do_shortcode_tag'] );
			$this->assertSame(
				array( 4, 4 ),
				array_column( $GLOBALS['digitalogic_test_filters']['do_shortcode_tag'], 'accepted_args' )
			);
		} finally {
			$GLOBALS['digitalogic_test_filters'] = $old_filters;
		}
	}

	public function test_stylesheet_overrides_legacy_ltr_and_has_responsive_actions(): void {
		$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/product-resources.css' );

		$this->assertIsString( $css );
		$this->assertStringContainsString( 'bdi[dir="rtl"]', $css );
		$this->assertStringContainsString( 'bdi[dir="ltr"]', $css );
		$this->assertStringContainsString( '.dgl-product-document__button--hosted', $css );
		$this->assertStringContainsString( '@container dgl-documents (max-width: 620px)', $css );
		$this->assertStringContainsString( '@media (max-width: 520px)', $css );
	}

	private function seed_product_and_pdf(): void {
		$GLOBALS['digitalogic_test_posts'][11867] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'post_title'  => 'رله قدرت خودرویی OMRON G8FE-1AP-L-12VDC',
			'meta'        => array(),
		);
		$GLOBALS['digitalogic_test_posts'][13090] = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
			'post_parent'    => 11867,
			'meta'           => array(),
		);
		$GLOBALS['digitalogic_test_attached_files'][13090] = $this->pdf_path;
	}

	private function valid_document(): array {
		return array(
			'title'            => 'دیتاشیت رسمی OMRON G8FE',
			'attachment_id'    => 13090,
			'source_url'       => 'https://omronfs.omron.com/en_US/ecb/products/pdf/en-g8fe.pdf',
			'source_label'     => 'OMRON',
			'sha256'           => hash_file( 'sha256', $this->pdf_path ),
			'bytes'            => filesize( $this->pdf_path ),
			'product_identity' => 'G8FE-1AP-L DC12',
			'verified_at'      => '2026-07-25T04:00:00+03:30',
		);
	}
}
