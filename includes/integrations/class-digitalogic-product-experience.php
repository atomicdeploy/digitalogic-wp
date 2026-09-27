<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Brand-aligned, conversion-focused WooCommerce single-product experience.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

final class Digitalogic_Product_Experience {
	const VERSION = '2.3.1';

	public static function init() {
		add_filter( 'body_class', array( __CLASS__, 'body_classes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'render_mobile_purchase_bar' ), 35 );

		add_shortcode( 'dgl_product_kicker', array( __CLASS__, 'shortcode_kicker' ) );
		add_shortcode( 'dgl_product_highlights', array( __CLASS__, 'shortcode_highlights' ) );
		add_shortcode( 'dgl_product_purchase_support', array( __CLASS__, 'shortcode_purchase_support' ) );
		add_shortcode( 'dgl_product_benefits', array( __CLASS__, 'shortcode_benefits' ) );
		add_shortcode( 'dgl_product_section_nav', array( __CLASS__, 'shortcode_section_nav' ) );
		add_shortcode( 'dgl_product_specs', array( __CLASS__, 'shortcode_specs' ) );

		add_filter( 'woocommerce_product_description_heading', array( __CLASS__, 'description_heading' ) );
		add_filter( 'woocommerce_product_additional_information_heading', array( __CLASS__, 'information_heading' ) );
	}

	public static function body_classes( $classes ) {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$classes[] = 'dgl-product-experience';
		}

		return $classes;
	}

	public static function enqueue_assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$css_path = DIGITALOGIC_PLUGIN_DIR . 'assets/css/product-experience.css';
		$js_path  = DIGITALOGIC_PLUGIN_DIR . 'assets/js/product-experience.js';
		$css_ver  = is_readable( $css_path ) ? (string) filemtime( $css_path ) : self::VERSION;
		$js_ver   = is_readable( $js_path ) ? (string) filemtime( $js_path ) : self::VERSION;

		wp_enqueue_style(
			'digitalogic-product-experience',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/product-experience.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'digitalogic-product-experience',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/product-experience.js',
			array( 'jquery' ),
			$js_ver,
			true
		);
	}

	private static function product() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		global $product;
		if ( $product instanceof WC_Product ) {
			return $product;
		}

		return wc_get_product( get_the_ID() );
	}

	private static function icon( $name ) {
		$paths = array(
			'chip'    => '<rect x="5" y="5" width="14" height="14" rx="3"/><path d="M9 9h6v6H9zM9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
			'check'   => '<path d="M20 11.1V12a8 8 0 1 1-4.7-7.3"/><path d="m20 4-9 9-3-3"/>',
			'truck'   => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
			'package' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9M8 5.2l8 4.5"/>',
			'pin'     => '<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
			'support' => '<path d="M4 13a8 8 0 0 1 16 0M4 13v4a2 2 0 0 0 2 2h2v-7H4v1ZM20 13v4a2 2 0 0 1-2 2h-2v-7h4v1ZM16 19c0 2-2 3-4 3"/>',
			'list'    => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="m4 6 1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/>',
			'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
			'star'    => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/>',
			'bolt'    => '<path d="m13 2-8 12h7l-1 8 8-12h-7z"/>',
			'copy'    => '<rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="dgl-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	public static function shortcode_kicker() {
		$product = self::product();
		if ( ! $product ) {
			return '';
		}

		$terms         = get_the_terms( $product->get_id(), 'product_cat' );
		$category_name = '';
		$category_url  = '';

		if ( is_array( $terms ) && ! empty( $terms ) ) {
			usort(
				$terms,
				function ( $a, $b ) {
					return count( get_ancestors( $b->term_id, 'product_cat' ) ) <=> count( get_ancestors( $a->term_id, 'product_cat' ) );
				}
			);
			$category_name = $terms[0]->name;
			$category_link = get_term_link( $terms[0] );
			$category_url  = is_wp_error( $category_link ) ? '' : $category_link;
		}

		$sku        = $product->get_sku();
		$is_instock = $product->is_in_stock();
		$status     = $is_instock ? 'آماده سفارش' : 'در حال حاضر ناموجود';
		$status_cls = $is_instock ? 'is-instock' : 'is-outofstock';

		ob_start();
		?>
		<div class="dgl-product-kicker" role="list" aria-label="اطلاعات سریع محصول">
			<span class="dgl-kicker-item dgl-kicker-brand" role="listitem">
				<?php echo self::icon( 'chip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span>انتخاب تخصصی دیجیتالاجیک</span>
			</span>
			<?php if ( $category_name ) : ?>
				<a class="dgl-kicker-item" role="listitem" href="<?php echo esc_url( $category_url ); ?>">
					<?php echo self::icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo esc_html( $category_name ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( $sku ) : ?>
				<span class="dgl-kicker-item" role="listitem">
					<span>کد کالا</span>
					<bdi dir="ltr"><?php echo esc_html( $sku ); ?></bdi>
				</span>
			<?php endif; ?>
			<span class="dgl-kicker-item dgl-kicker-stock <?php echo esc_attr( $status_cls ); ?>" role="listitem">
				<span class="dgl-status-dot" aria-hidden="true"></span>
				<span><?php echo esc_html( $status ); ?></span>
			</span>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function attribute_rows( $product ) {
		$rows = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute->get_visible() ) {
				continue;
			}

			$name       = $attribute->get_name();
			$label      = wc_attribute_label( $name, $product );
			$values     = array();
			$contextual = $product->is_type( 'variable' ) && method_exists( $attribute, 'get_variation' ) && $attribute->get_variation();

			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms(
					$product->get_id(),
					$name,
					array( 'fields' => 'names' )
				);
			} else {
				$values = $attribute->get_options();
			}

			$values = array_values(
				array_filter(
					array_map(
						function ( $value ) {
							return trim( wp_strip_all_tags( (string) $value ) );
						},
						(array) $values
					)
				)
			);

			if ( ! $label || ( empty( $values ) && ! $contextual ) ) {
				continue;
			}

			$rows[] = array(
				'label'      => $label,
				'value'      => $contextual ? '' : implode( '، ', $values ),
				'key'        => sanitize_key( $name ),
				'contextual' => $contextual,
			);
		}

		return $rows;
	}

	public static function shortcode_highlights() {
		$product = self::product();
		if ( ! $product ) {
			return '';
		}

		$items = array();
		$model = $product->is_type( 'variable' )
			? ''
			: trim( (string) get_post_meta( $product->get_id(), '_digitalogic_model', true ) );

		if ( $model ) {
			$items[] = array( 'chip', 'مدل', $model );
		}

		$attribute_rows = self::attribute_rows( $product );
		$priority_keys  = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $attribute_rows as $row ) {
				if ( $row['contextual'] && in_array( $row['key'], array( 'source_model', 'pa_source_model' ), true ) ) {
					$items[]                     = array( 'list', $row['label'], '', $row['key'], true );
					$priority_keys[ $row['key'] ] = true;
					break;
				}
			}
			$items[] = array( 'copy', 'کد کالا', '', 'product_code', true, 'product-code' );
		}

		foreach ( $attribute_rows as $row ) {
			if ( isset( $priority_keys[ $row['key'] ] ) ) {
				continue;
			}
			$icon    = false !== strpos( $row['key'], 'voltage' ) ? 'bolt' : ( false !== strpos( $row['key'], 'package' ) ? 'package' : 'list' );
			$items[] = array( $icon, $row['label'], $row['value'], $row['key'], $row['contextual'] );
			if ( count( $items ) >= 4 ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="dgl-product-highlights" aria-label="ویژگی‌های کلیدی محصول">
			<?php foreach ( array_slice( $items, 0, 4 ) as $item ) : ?>
				<?php $is_product_code = isset( $item[5] ) && 'product-code' === $item[5]; ?>
				<div class="dgl-highlight<?php echo $is_product_code ? ' dgl-highlight--product-code' : ''; ?>"<?php echo ! empty( $item[4] ) ? ( $is_product_code ? ' data-digitalogic-context-product-code hidden' : ' data-digitalogic-context-attribute="' . esc_attr( $item[3] ) . '" hidden' ) : ''; ?>>
					<span class="dgl-highlight__icon"><?php echo self::icon( $item[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="dgl-highlight__copy">
						<small><?php echo esc_html( $item[1] ); ?></small>
						<strong><bdi dir="<?php echo $is_product_code ? 'ltr' : 'auto'; ?>"><?php echo esc_html( $item[2] ); ?></bdi></strong>
					</span>
					<?php if ( $is_product_code ) : ?>
						<button type="button" class="dgl-highlight__copy-button" aria-label="کپی کد کالا" title="کپی کد کالا" data-digitalogic-copy-product-code>
							<?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_purchase_support() {
		ob_start();
		?>
		<div class="dgl-purchase-support">
			<div class="dgl-purchase-support__seller">
				<span class="dgl-purchase-support__icon"><?php echo self::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span>
					<strong>فروش و پردازش توسط دیجیتالاجیک</strong>
					<small>تامین تخصصی قطعات و تجهیزات الکترونیک</small>
				</span>
			</div>
			<a class="dgl-purchase-support__contact" href="tel:+982166754123">
				<?php echo self::icon( 'support' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span>مشاوره خرید <bdi dir="ltr">۰۲۱-۶۶۷۵۴۱۲۳</bdi></span>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_benefits() {
		$delivery_url = home_url( '/delivery-return-2/' );
		$items        = array(
			array( 'truck', 'ارسال سراسری', 'پست یا تیپاکس' ),
			array( 'pin', 'پیک تهران', 'با هماهنگی پیش از ارسال' ),
			array( 'package', 'بسته‌بندی محافظ', 'مناسب برد و قطعات حساس' ),
			array( 'support', 'پیگیری سفارش', 'از حساب کاربری یا پشتیبانی' ),
		);

		ob_start();
		?>
		<div class="dgl-product-benefits" aria-label="خدمات ارسال و پشتیبانی">
			<?php foreach ( $items as $item ) : ?>
				<div class="dgl-benefit">
					<span class="dgl-benefit__icon"><?php echo self::icon( $item[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span class="dgl-benefit__copy">
						<strong><?php echo esc_html( $item[1] ); ?></strong>
						<small><?php echo esc_html( $item[2] ); ?></small>
					</span>
				</div>
			<?php endforeach; ?>
			<a class="dgl-benefits-link" href="<?php echo esc_url( $delivery_url ); ?>">جزئیات ارسال و تحویل</a>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_section_nav() {
		$items = array(
			array( '#dgl-product-overview', 'info', 'معرفی محصول' ),
			array( '#dgl-product-specs', 'chip', 'مشخصات فنی' ),
			array( '#dgl-product-reviews', 'star', 'نظر خریداران' ),
		);

		ob_start();
		?>
		<nav class="dgl-section-nav" aria-label="بخش‌های صفحه محصول">
			<div class="dgl-section-nav__intro">
				<strong>اطلاعات کامل محصول</strong>
				<span>هرآنچه برای یک انتخاب مطمئن نیاز دارید</span>
			</div>
			<div class="dgl-section-nav__links">
				<?php foreach ( $items as $item ) : ?>
					<a href="<?php echo esc_attr( $item[0] ); ?>">
						<?php echo self::icon( $item[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span><?php echo esc_html( $item[2] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>
		<?php
		return ob_get_clean();
	}

	public static function shortcode_specs() {
		$product = self::product();
		if ( ! $product ) {
			return '';
		}

		$identity        = array();
		$technical       = array();
		$physical        = array();
		$sku             = trim( (string) $product->get_sku() );
		$model           = $product->is_type( 'variable' )
			? ''
			: trim( (string) get_post_meta( $product->get_id(), '_digitalogic_model', true ) );
		$technical_name  = trim( (string) get_post_meta( $product->get_id(), '_digitalogic_patris_name', true ) );
		$part_number     = trim( (string) get_post_meta( $product->get_id(), '_digitalogic_part_number', true ) );
		$normalized_name = preg_replace( '/-{2,}/', '-', $technical_name );
		if (
			'' !== $part_number
			&& is_string( $normalized_name )
			&& 0 === strcasecmp( $normalized_name, $part_number )
		) {
			$technical_name = $part_number;
		}

		if ( $sku ) {
			$identity[] = array( 'کد کالا', $sku );
		}
		if ( $model ) {
			$identity[] = array( 'مدل', $model );
		}
		if ( $technical_name && $technical_name !== $model ) {
			$identity[] = array( 'نام فنی', $technical_name );
		}
		if ( $part_number && $part_number !== $technical_name && $part_number !== $model ) {
			$identity[] = array( 'پارت نامبر', $part_number );
		}

		foreach ( self::attribute_rows( $product ) as $row ) {
			$target = false !== strpos( $row['key'], 'package' ) ? 'physical' : 'technical';
			if ( 'physical' === $target ) {
				$physical[] = array( $row['label'], $row['value'], $row['key'], $row['contextual'] );
			} else {
				$technical[] = array( $row['label'], $row['value'], $row['key'], $row['contextual'] );
			}
		}

		if ( $product->get_weight() ) {
			$physical[] = array( 'وزن', wc_format_weight( $product->get_weight() ) );
		}

		if ( $product->has_dimensions() ) {
			$dimensions = wc_format_dimensions( $product->get_dimensions( false ) );
			if ( $dimensions && 'N/A' !== $dimensions ) {
				$physical[] = array( 'ابعاد', $dimensions );
			}
		}

		$groups = array_filter(
			array(
				array( 'chip', 'هویت کالا', $identity ),
				array( 'bolt', 'مشخصات عملکردی', $technical ),
				array( 'package', 'فیزیکی و بسته‌بندی', $physical ),
			),
			function ( $group ) {
				return ! empty( $group[2] );
			}
		);

		if ( empty( $groups ) ) {
			return '<p class="dgl-specs-empty">اطلاعات فنی این محصول در حال تکمیل است.</p>';
		}

		ob_start();
		?>
		<div class="dgl-dynamic-specs">
			<?php foreach ( $groups as $group ) : ?>
				<section class="dgl-spec-group">
					<h3>
						<span><?php echo self::icon( $group[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php echo esc_html( $group[1] ); ?>
					</h3>
					<table aria-label="<?php echo esc_attr( $group[1] ); ?>">
						<tbody>
							<?php foreach ( $group[2] as $row ) : ?>
								<tr<?php echo ! empty( $row[3] ) ? ' data-digitalogic-context-attribute="' . esc_attr( $row[2] ) . '" hidden' : ''; ?>>
									<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
									<td><bdi dir="auto"><?php echo esc_html( $row[1] ); ?></bdi></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_mobile_purchase_bar() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = self::product();
		if ( ! $product || ! $product->is_purchasable() ) {
			return;
		}

		$label = $product->is_type( 'simple' ) ? 'افزودن به سبد خرید' : 'مشاهده گزینه‌های خرید';
		?>
		<div class="dgl-mobile-purchase-bar" data-product-type="<?php echo esc_attr( $product->get_type() ); ?>" aria-hidden="true">
			<div class="dgl-mobile-purchase-bar__price"><?php echo $product->is_type( 'variable' ) ? esc_html__( 'برای مشاهده قیمت، مدل را انتخاب کنید', 'digitalogic' ) : wp_kses_post( $product->get_price_html() ); ?></div>
			<button type="button" class="dgl-mobile-purchase-bar__button"><?php echo esc_html( $label ); ?></button>
		</div>
		<?php
	}

	public static function description_heading() {
		return 'معرفی و راهنمای محصول';
	}

	public static function information_heading() {
		return 'مشخصات فنی محصول';
	}
}
