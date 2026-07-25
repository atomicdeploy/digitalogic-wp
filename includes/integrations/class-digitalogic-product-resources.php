<?php
/**
 * Storefront product documents and mixed-direction specification values.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders verified product documents and corrects mixed-direction specs.
 */
final class Digitalogic_Product_Resources {

	public const META_KEY = '_digitalogic_product_documents';

	/**
	 * Product document cards emitted during the current request.
	 *
	 * @var int[]
	 */
	private $rendered_products = array();

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared integration instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register storefront hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
		add_filter( 'do_shortcode_tag', array( $this, 'normalize_spec_directions' ), 20, 4 );
		add_filter( 'do_shortcode_tag', array( $this, 'append_documents_to_specs' ), 30, 4 );
	}

	/**
	 * Register the reviewed document manifest as private product metadata.
	 */
	public function register_meta(): void {
		register_post_meta(
			'product',
			self::META_KEY,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_documents_meta' ),
				'auth_callback'     => static function (): bool {
					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Sanitize product-document metadata at the WordPress boundary.
	 *
	 * Invalid documents are omitted so an incomplete or non-HTTPS record never
	 * becomes customer-facing. Operational callers should use replace_documents()
	 * when they need an explicit WP_Error for a rejected record.
	 *
	 * @param mixed $documents Raw product-document records.
	 * @return array
	 */
	public function sanitize_documents_meta( $documents ): array {
		$normalized = $this->normalize_documents( $documents, false, 0 );

		return is_wp_error( $normalized ) ? array() : $normalized;
	}

	/**
	 * Replace a product's reviewed document list and verify the live readback.
	 *
	 * @param int   $product_id Product post ID.
	 * @param mixed $documents  Reviewed document records.
	 * @return array|WP_Error
	 */
	public function replace_documents( int $product_id, $documents ) {
		if ( 'product' !== get_post_type( $product_id ) ) {
			return new WP_Error( 'digitalogic_invalid_document_product', 'Product documents require an existing product post.' );
		}

		$normalized = $this->normalize_documents( $documents, true, $product_id );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$current = get_post_meta( $product_id, self::META_KEY, true );
		if ( $current !== $normalized ) {
			$updated = update_post_meta( $product_id, self::META_KEY, $normalized );
			if ( false === $updated && get_post_meta( $product_id, self::META_KEY, true ) !== $normalized ) {
				return new WP_Error( 'digitalogic_document_write_failed', 'Product document metadata could not be written.' );
			}
		}

		wp_cache_delete( $product_id, 'post_meta' );
		$readback = $this->normalize_documents( get_post_meta( $product_id, self::META_KEY, true ), true, $product_id );
		if ( is_wp_error( $readback ) || $readback !== $normalized ) {
			return new WP_Error( 'digitalogic_document_readback_failed', 'Product document metadata failed live readback verification.' );
		}

		return $readback;
	}

	/**
	 * Load only complete, locally hosted, official-source document pairs.
	 *
	 * @param int $product_id Product post ID.
	 * @return array
	 */
	public function get_documents( int $product_id ): array {
		$documents = $this->normalize_documents( get_post_meta( $product_id, self::META_KEY, true ), false, $product_id );

		return is_wp_error( $documents ) ? array() : $documents;
	}

	/**
	 * Normalize and validate document records.
	 *
	 * @param mixed $documents Raw records.
	 * @param bool  $strict    Return a WP_Error instead of skipping invalid rows.
	 * @param int   $product_id Product that must own each attachment, or zero at the generic sanitize boundary.
	 * @return array|WP_Error
	 */
	private function normalize_documents( $documents, bool $strict, int $product_id ) {
		if ( ! is_array( $documents ) || ! array_is_list( $documents ) ) {
			return $strict
				? new WP_Error( 'digitalogic_invalid_documents', 'Product documents must be a JSON-style list.' )
				: array();
		}

		$normalized   = array();
		$seen_hashes  = array();
		$seen_sources = array();
		$allowed_keys = array(
			'attachment_id',
			'bytes',
			'product_identity',
			'sha256',
			'source_label',
			'source_url',
			'title',
			'verified_at',
		);

		foreach ( $documents as $index => $document ) {
			$error = null;
			if ( ! is_array( $document ) || array_is_list( $document ) ) {
				$error = 'Each product document must be an object.';
			} elseif ( array_diff( array_keys( $document ), $allowed_keys ) ) {
				$error = 'Product document contains unsupported fields.';
			}

			$attachment_id    = is_array( $document ) ? absint( $document['attachment_id'] ?? 0 ) : 0;
			$title            = is_array( $document ) ? sanitize_text_field( (string) ( $document['title'] ?? '' ) ) : '';
			$source_url       = is_array( $document ) ? esc_url_raw( (string) ( $document['source_url'] ?? '' ) ) : '';
			$source_label     = is_array( $document ) ? sanitize_text_field( (string) ( $document['source_label'] ?? '' ) ) : '';
			$sha256           = is_array( $document ) ? strtolower( trim( (string) ( $document['sha256'] ?? '' ) ) ) : '';
			$bytes            = is_array( $document ) ? absint( $document['bytes'] ?? 0 ) : 0;
			$product_identity = is_array( $document ) ? sanitize_text_field( (string) ( $document['product_identity'] ?? '' ) ) : '';
			$verified_at      = is_array( $document ) ? sanitize_text_field( (string) ( $document['verified_at'] ?? '' ) ) : '';
			$source_parts     = $source_url ? wp_parse_url( $source_url ) : false;
			$hosted_url       = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : false;
			$attachment       = $attachment_id > 0 ? get_post( $attachment_id ) : null;
			$attachment_path  = $strict && $attachment_id > 0 ? get_attached_file( $attachment_id, true ) : false;
			$verified_time    = \DateTimeImmutable::createFromFormat( DATE_ATOM, $verified_at );

			if ( null === $error && ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) ) {
				$error = 'Product document requires an existing media attachment.';
			} elseif ( null === $error && 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
				$error = 'Product document attachment must be a PDF.';
			} elseif (
				null === $error
				&& 0 < $product_id
				&& (
					! $attachment
					|| absint( $attachment->post_parent ?? 0 ) !== $product_id
				)
			) {
				$error = 'Product document attachment must belong to the selected product.';
			} elseif ( null === $error && ! $hosted_url ) {
				$error = 'Product document attachment URL does not resolve.';
			} elseif ( null === $error && '' === $title ) {
				$error = 'Product document title is required.';
			} elseif (
				null === $error
				&& (
					! is_array( $source_parts )
					|| 'https' !== strtolower( (string) ( $source_parts['scheme'] ?? '' ) )
					|| '' === (string) ( $source_parts['host'] ?? '' )
					|| isset( $source_parts['user'] )
					|| isset( $source_parts['pass'] )
				)
			) {
				$error = 'Product document source must be an official HTTPS URL.';
			} elseif ( null === $error && '' === $source_label ) {
				$error = 'Product document source label is required.';
			} elseif ( null === $error && ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
				$error = 'Product document SHA-256 is required.';
			} elseif ( null === $error && $bytes <= 0 ) {
				$error = 'Product document byte size is required.';
			} elseif ( null === $error && '' === $product_identity ) {
				$error = 'Product document identity evidence is required.';
			} elseif (
				null === $error
				&& (
					false === $verified_time
					|| $verified_time->format( DATE_ATOM ) !== $verified_at
				)
			) {
				$error = 'Product document verification time must be an ISO-8601 timestamp.';
			} elseif (
				null === $error
				&& $strict
				&& (
					! is_string( $attachment_path )
					|| ! is_readable( $attachment_path )
				)
			) {
				$error = 'Product document attachment file is not readable.';
			} elseif (
				null === $error
				&& $strict
				&& (
					filesize( $attachment_path ) !== $bytes
					|| ! hash_equals( $sha256, (string) hash_file( 'sha256', $attachment_path ) )
				)
			) {
				$error = 'Product document hash or byte size does not match the attached file.';
			} elseif ( null === $error && isset( $seen_hashes[ $sha256 ] ) ) {
				$error = 'Product document SHA-256 must be unique.';
			} elseif ( null === $error && isset( $seen_sources[ $source_url ] ) ) {
				$error = 'Product document official source URL must be unique.';
			}

			if ( null !== $error ) {
				if ( $strict ) {
					return new WP_Error(
						'digitalogic_invalid_document',
						sprintf( 'Document %d: %s', (int) $index + 1, $error )
					);
				}
				continue;
			}

			$seen_hashes[ $sha256 ]      = true;
			$seen_sources[ $source_url ] = true;
			$normalized[]                = array(
				'title'            => $title,
				'attachment_id'    => $attachment_id,
				'source_url'       => $source_url,
				'source_label'     => $source_label,
				'sha256'           => $sha256,
				'bytes'            => $bytes,
				'product_identity' => $product_identity,
				'verified_at'      => $verified_at,
			);
		}

		if ( $strict && empty( $normalized ) ) {
			return new WP_Error( 'digitalogic_empty_documents', 'At least one complete product document is required.' );
		}

		return $normalized;
	}

	/**
	 * Append a reusable document card once at the product specifications surface.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @param array  $attr   Shortcode attributes.
	 * @param array  $shortcode_match Shortcode regex match.
	 * @return string
	 */
	public function append_documents_to_specs( string $output, string $tag, array $attr = array(), array $shortcode_match = array() ): string {
		unset( $attr, $shortcode_match );
		if (
			'dgl_product_specs' !== $tag
			|| is_admin()
			|| ! function_exists( 'is_product' )
			|| ! is_product()
			|| false !== strpos( $output, 'data-digitalogic-product-documents' )
		) {
			return $output;
		}

		$product_id = function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0;
		if (
			! $product_id
			|| 'product' !== get_post_type( $product_id )
			|| in_array( $product_id, $this->rendered_products, true )
		) {
			return $output;
		}

		$documents = $this->render_documents( $product_id );
		if ( '' === $documents ) {
			return $output;
		}

		$this->rendered_products[] = $product_id;

		return $output . $documents;
	}

	/**
	 * Render hosted and official-source actions for each reviewed document.
	 *
	 * @param int $product_id Product post ID.
	 * @return string
	 */
	public function render_documents( int $product_id ): string {
		$documents = $this->get_documents( $product_id );
		if ( empty( $documents ) ) {
			return '';
		}

		$heading_id = 'dgl-product-documents-' . $product_id;
		ob_start();
		?>
		<section class="dgl-product-documents" data-digitalogic-product-documents dir="rtl" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>">
			<header class="dgl-product-documents__header">
				<span class="dgl-product-documents__header-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
						<path d="M7 3h7l4 4v14H7z"/>
						<path d="M14 3v5h5M10 13h5M10 17h5"/>
					</svg>
				</span>
				<div>
					<h2 id="<?php echo esc_attr( $heading_id ); ?>">دانلود دیتاشیت و مستندات</h2>
					<p>نسخه میزبانی‌شده در دیجیتالاجیک و پیوند مستقیم منبع رسمی سازنده.</p>
				</div>
			</header>
			<div class="dgl-product-documents__list">
				<?php foreach ( $documents as $document ) : ?>
					<?php $hosted_url = wp_get_attachment_url( $document['attachment_id'] ); ?>
					<article class="dgl-product-document">
						<div class="dgl-product-document__summary">
							<span class="dgl-product-document__pdf" aria-hidden="true">PDF</span>
							<div>
								<h3><?php echo esc_html( $document['title'] ); ?></h3>
								<small class="dgl-product-document__metadata">
									<bdi dir="<?php echo esc_attr( self::direction_for_text( $document['source_label'] ) ); ?>"><?php echo esc_html( $document['source_label'] ); ?></bdi>
									<span aria-hidden="true">•</span>
									<?php $file_size = $this->format_file_size( $document['bytes'] ); ?>
									<bdi dir="<?php echo esc_attr( self::direction_for_text( $file_size ) ); ?>"><?php echo esc_html( $file_size ); ?></bdi>
									<span aria-hidden="true">•</span>
									<bdi dir="<?php echo esc_attr( self::direction_for_text( $document['product_identity'] ) ); ?>"><?php echo esc_html( $document['product_identity'] ); ?></bdi>
								</small>
							</div>
						</div>
						<div class="dgl-product-document__actions">
							<a
								class="dgl-product-document__button dgl-product-document__button--hosted"
								href="<?php echo esc_url( $hosted_url ); ?>"
								download
								aria-label="<?php echo esc_attr( 'دانلود نسخه میزبانی‌شده ' . $document['title'] ); ?>"
							>
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
									<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/>
								</svg>
								دانلود از دیجیتالاجیک
							</a>
							<a
								class="dgl-product-document__button dgl-product-document__button--official"
								href="<?php echo esc_url( $document['source_url'] ); ?>"
								target="_blank"
								rel="noopener noreferrer nofollow external"
								aria-label="<?php echo esc_attr( 'مشاهده منبع رسمی ' . $document['title'] ); ?>"
							>
								<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
									<path d="M14 5h5v5M10 14 19 5M19 14v5H5V5h5"/>
								</svg>
								مشاهده منبع رسمی
							</a>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Load the document UI and directionality correction on product pages.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$relative_path = 'assets/css/product-resources.css';
		$asset_path    = DIGITALOGIC_PLUGIN_DIR . $relative_path;
		$version       = is_readable( $asset_path ) ? (string) filemtime( $asset_path ) : DIGITALOGIC_VERSION;

		wp_enqueue_style(
			'digitalogic-product-resources',
			DIGITALOGIC_PLUGIN_URL . $relative_path,
			array(),
			$version
		);
	}

	/**
	 * Convert Product Experience's dir=auto values to deterministic directions.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @param array  $attr   Shortcode attributes.
	 * @param array  $shortcode_match Shortcode regex match.
	 * @return string
	 */
	public function normalize_spec_directions( string $output, string $tag, array $attr = array(), array $shortcode_match = array() ): string {
		unset( $attr, $shortcode_match );
		$supported_tags = array( 'dgl_product_specs', 'dgl_product_highlights' );
		if ( ! in_array( $tag, $supported_tags, true ) || false === stripos( $output, 'dir' ) ) {
			return $output;
		}

		$normalized = preg_replace_callback(
			'/<bdi\b([^>]*?)\bdir\s*=\s*(["\'])auto\2([^>]*)>(.*?)<\/bdi>/uis',
			static function ( array $matches ): string {
				$text      = html_entity_decode( wp_strip_all_tags( $matches[4] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$direction = self::direction_for_text( $text );

				return '<bdi' . $matches[1] . 'dir="' . esc_attr( $direction ) . '"' . $matches[3] . '>' . $matches[4] . '</bdi>';
			},
			$output
		);

		return is_string( $normalized ) ? $normalized : $output;
	}

	/**
	 * Resolve direction from the first strong Persian/Arabic or Latin letter.
	 *
	 * @param string $value Visible text.
	 * @return string
	 */
	public static function direction_for_text( string $value ): string {
		if ( preg_match( '/[\x{0600}-\x{06FF}A-Za-z]/u', $value, $match ) ) {
			return preg_match( '/[\x{0600}-\x{06FF}]/u', $match[0] ) ? 'rtl' : 'ltr';
		}

		return function_exists( 'is_rtl' ) && is_rtl() ? 'rtl' : 'ltr';
	}

	/**
	 * Format bytes for the Persian storefront.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	private function format_file_size( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return number_format_i18n( $bytes / 1048576, 1 ) . ' مگابایت';
		}

		return number_format_i18n( (int) ceil( $bytes / 1024 ) ) . ' کیلوبایت';
	}
}
