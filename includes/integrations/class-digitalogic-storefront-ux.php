<?php
/**
 * Storefront translations, feedback, catalogue filters, and category artwork.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Deliver a translated, responsive, and progressively enhanced storefront. */
final class Digitalogic_Storefront_UX {

	/** Register public hooks without changing order-status identifiers. */
	public static function init(): void {
		add_filter( 'gettext', array( self::class, 'translate_storefront_text' ), 40, 3 );
		add_filter( 'wc_order_statuses', array( self::class, 'translate_order_statuses' ), 40 );
		add_filter( 'woocommerce_product_query_meta_query', array( self::class, 'filter_stock_query' ), 20, 2 );
		add_action( 'woocommerce_before_shop_loop', array( self::class, 'render_catalog_filters' ), 8 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ), 95 );
	}

	/**
	 * Translate visible theme/plugin strings that currently escape language packs.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 */
	public static function translate_storefront_text( string $translation, string $text, string $domain ): string {
		unset( $domain );
		if ( ! self::is_persian_locale() ) {
			return $translation;
		}

		$translations = array(
			'filter by price' => 'فیلتر بر اساس قیمت',
			'from'            => 'از',
			'contact us'      => 'تماس با ما',
			'pre-orders'      => 'پیش‌سفارش‌ها',
			'pre-orders.'     => 'پیش‌سفارش‌ها',
			'support'         => 'پشتیبانی',
		);
		foreach ( array( $translation, $text ) as $candidate ) {
			$key = strtolower( trim( wp_strip_all_tags( html_entity_decode( $candidate, ENT_QUOTES, 'UTF-8' ) ) ) );
			if ( isset( $translations[ $key ] ) ) {
				return $translations[ $key ];
			}
		}

		return $translation;
	}

	/**
	 * Keep status slugs intact and localize only their customer-facing labels.
	 *
	 * @param mixed $statuses Registered WooCommerce labels.
	 * @return mixed
	 */
	public static function translate_order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) || ! self::is_persian_locale() ) {
			return $statuses;
		}

		$labels = array(
			'wc-pending'                 => 'در انتظار پرداخت',
			'wc-processing'              => 'در حال پردازش',
			'wc-on-hold'                 => 'در انتظار بررسی',
			'wc-completed'               => 'تکمیل‌شده',
			'wc-cancelled'               => 'لغوشده',
			'wc-refunded'                => 'بازپرداخت‌شده',
			'wc-failed'                  => 'ناموفق',
			'wc-spam'                    => 'سفارش مشکوک',
			'wc-spamorder'               => 'سفارش مشکوک',
			'wc-pre-ordered'             => 'پیش‌سفارش‌شده',
			'wc-partially-paid'          => 'بخشی پرداخت‌شده',
			'wc-partial-payment'         => 'بخشی پرداخت‌شده',
			'wc-scheduled'               => 'زمان‌بندی‌شده',
			'wc-scheduled-payment'       => 'زمان‌بندی‌شده',
			'wc-pending-deposit-payment' => 'در انتظار پرداخت بیعانه',
			'wc-pending-deposit'         => 'در انتظار پرداخت بیعانه',
		);

		foreach ( $labels as $slug => $label ) {
			if ( array_key_exists( $slug, $statuses ) ) {
				$statuses[ $slug ] = $label;
			}
		}

		return $statuses;
	}

	/**
	 * Apply the optional in-stock catalogue filter through WooCommerce's query API.
	 *
	 * @param mixed $meta_query Existing product-query metadata constraints.
	 * @param mixed $query      WooCommerce product query.
	 * @return mixed
	 */
	public static function filter_stock_query( $meta_query, $query = null ) {
		unset( $query );
		if ( ! is_array( $meta_query ) || 'instock' !== sanitize_key( wp_unslash( $_GET['dgl_availability'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $meta_query;
		}

		$meta_query[] = array(
			'key'     => '_stock_status',
			'value'   => 'instock',
			'compare' => '=',
		);

		return $meta_query;
	}

	/** Render progressive-enhancement filters; JavaScript upgrades them to AJAX. */
	public static function render_catalog_filters(): void {
		if ( is_admin() || ( function_exists( 'is_product' ) && is_product() ) ) {
			return;
		}

		$active      = 'instock' === sanitize_key( wp_unslash( $_GET['dgl_availability'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_url = self::current_url();
		$all_url     = remove_query_arg( array( 'dgl_availability', 'product-page', 'paged' ), $current_url );
		$stock_url   = add_query_arg( 'dgl_availability', 'instock', $all_url );
		?>
		<nav class="digitalogic-catalog-filters" aria-label="فیلتر موجودی">
			<span class="digitalogic-catalog-filters__label">نمایش:</span>
			<a class="digitalogic-catalog-filter<?php echo $active ? '' : ' is-active'; ?>" href="<?php echo esc_url( $all_url ); ?>" data-digitalogic-ajax-filter>همه کالاها</a>
			<a class="digitalogic-catalog-filter<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $stock_url ); ?>" data-digitalogic-ajax-filter>فقط کالاهای موجود</a>
		</nav>
		<?php
	}

	/** Load the public experience layer and category-specific artwork. */
	public static function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$css_path = DIGITALOGIC_PLUGIN_DIR . 'assets/css/storefront-ux.css';
		$js_path  = DIGITALOGIC_PLUGIN_DIR . 'assets/js/storefront-ux.js';
		wp_enqueue_style( 'digitalogic-storefront-ux', DIGITALOGIC_PLUGIN_URL . 'assets/css/storefront-ux.css', array(), file_exists( $css_path ) ? filemtime( $css_path ) : DIGITALOGIC_VERSION );
		wp_enqueue_script( 'digitalogic-storefront-ux', DIGITALOGIC_PLUGIN_URL . 'assets/js/storefront-ux.js', array( 'jquery' ), file_exists( $js_path ) ? filemtime( $js_path ) : DIGITALOGIC_VERSION, true );
		wp_localize_script(
			'digitalogic-storefront-ux',
			'digitalogicStorefrontUX',
			array(
				'loadingLabel' => 'در حال به‌روزرسانی نتایج…',
				'errorLabel'   => 'به‌روزرسانی خودکار انجام نشد؛ صفحه بازخوانی می‌شود.',
				'banners'      => array(
					'semiconductors'    => DIGITALOGIC_PLUGIN_URL . 'assets/images/category-banners/semiconductors.webp',
					'sensors'           => DIGITALOGIC_PLUGIN_URL . 'assets/images/category-banners/sensors.webp',
					'displays'          => DIGITALOGIC_PLUGIN_URL . 'assets/images/category-banners/displays.webp',
					'modules'           => DIGITALOGIC_PLUGIN_URL . 'assets/images/category-banners/modules.webp',
					'electromechanical' => DIGITALOGIC_PLUGIN_URL . 'assets/images/category-banners/electromechanical.webp',
					'passive'           => DIGITALOGIC_PLUGIN_URL . 'assets/images/category-banners/passive.webp',
				),
			)
		);
	}

	/** Return an explicit URL without changing product-query semantics. */
	private static function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) );
		$uri    = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );

		return $scheme . $host . $uri;
	}

	/** Test the language actually being rendered, not the browser language. */
	private static function is_persian_locale(): bool {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return str_starts_with( strtolower( (string) $locale ), 'fa' );
	}
}
