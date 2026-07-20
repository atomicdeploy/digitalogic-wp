<?php
/**
 * A focused, inventory-backed storefront homepage for Digitalogic.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Homepage_Showcase {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'digitalogic_homepage', array( $this, 'render' ) );
	}

	/**
	 * Render the streamlined public homepage.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return '';
		}

		wp_enqueue_style(
			'digitalogic-storefront',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/storefront.css',
			array(),
			DIGITALOGIC_VERSION
		);

		$hero_products = $this->get_varied_products( 6 );
		$hero_ids      = array_map( static fn( $product ) => $product->get_id(), $hero_products );
		$more_products = $this->get_more_products( 8, $hero_ids );
		$categories    = $this->get_category_spotlights();

		ob_start();
		?>
		<div class="dgl-home" dir="rtl">
			<section class="dgl-hero" aria-labelledby="dgl-hero-title">
				<div class="dgl-hero-copy">
					<span class="dgl-eyebrow">فروشگاه قطعات و ماژول‌های الکترونیک</span>
					<h1 id="dgl-hero-title">یه عالمه قطعه و ماژول باحال، آماده‌ی پروژه‌ی بعدی تو</h1>
					<p>از آردوینو، ESP و رزبری‌پای تا سنسور، نمایشگر و ماژول‌های کاربردی؛ موجودی واقعی فروشگاه رو ببین و راحت انتخاب کن.</p>
					<div class="dgl-hero-actions">
						<a class="dgl-button dgl-button--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">بزن بریم فروشگاه</a>
						<a class="dgl-button dgl-button--ghost" href="<?php echo esc_url( $this->category_url( 'modules-development-board' ) ); ?>">ماژول‌ها و بردها</a>
					</div>
					<div class="dgl-hero-tags" aria-label="محصولات محبوب">
						<a href="<?php echo esc_url( $this->category_url( 'wi-fi-and-bluetooth-modules' ) ); ?>">ESP و ارتباطات</a>
						<a href="<?php echo esc_url( $this->category_url( 'raspberry-pi-boards' ) ); ?>">Raspberry Pi</a>
						<a href="<?php echo esc_url( $this->category_url( 'arduino-boards' ) ); ?>">Arduino</a>
						<a href="<?php echo esc_url( $this->category_url( 'sensors-transducers' ) ); ?>">سنسورها</a>
					</div>
				</div>
				<div class="dgl-prime-showcase" aria-label="محصولات موجود فروشگاه">
					<div class="dgl-section-kicker"><span></span> همین الان موجوده</div>
					<div class="dgl-prime-grid">
						<?php foreach ( $hero_products as $product ) : ?>
							<?php echo $this->product_card( $product, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<?php if ( ! empty( $categories ) ) : ?>
				<section class="dgl-section" aria-labelledby="dgl-category-title">
					<div class="dgl-section-heading">
						<div>
							<span class="dgl-eyebrow">از کجا شروع کنیم؟</span>
							<h2 id="dgl-category-title">یه راست برو سراغ چیزی که لازم داری</h2>
						</div>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">دیدن همه محصولات</a>
					</div>
					<div class="dgl-category-grid">
						<?php foreach ( $categories as $category ) : ?>
							<a class="dgl-category-card" href="<?php echo esc_url( $category['url'] ); ?>">
								<span class="dgl-category-mark" aria-hidden="true"><?php echo esc_html( $category['mark'] ); ?></span>
								<strong><?php echo esc_html( $category['title'] ); ?></strong>
								<small><?php echo esc_html( $category['subtitle'] ); ?></small>
								<span class="dgl-card-link">ببین چه خبره ←</span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<section class="dgl-section dgl-services" aria-labelledby="dgl-services-title">
				<div class="dgl-section-heading">
					<div>
						<span class="dgl-eyebrow">فقط فروش قطعه نیست</span>
						<h2 id="dgl-services-title">از خرید تا ساخت، کنارتیم</h2>
					</div>
				</div>
				<div class="dgl-service-grid">
					<?php echo $this->service_card( '01', 'قطعه و ماژول آماده', 'آردوینو، ESP، سنسور، نمایشگر و کلی ماژول کاربردی برای پروژه‌های کوچیک و بزرگ.', wc_get_page_permalink( 'shop' ), 'محصولات رو ببین' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->service_card( '02', 'سفارش مستقیم از چین', 'قطعه‌ای پیدا نمی‌شه یا تعداد بالا می‌خوای؟ لینک یا پارت‌نامبر رو بده؛ تأمینش می‌کنیم.', home_url( '/import-of-electronic-products/' ), 'ثبت درخواست خرید' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->service_card( '2L', 'ساخت PCB دو لایه', 'فایل Gerber رو بفرست؛ برای نمونه‌سازی و تولید، قیمت و زمان تحویل شفاف می‌گیری.', home_url( '/سفارش-چاپ-برد-pcb/' ), 'سفارش PCB دو لایه' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo $this->service_card( '4L', 'ساخت PCB چهار لایه', 'برای بردهای حرفه‌ای‌تر و مسیرکشی فشرده، سفارش چهار لایه‌ات رو با خیال راحت بسپار.', home_url( '/سفارش-چاپ-برد-pcb/' ), 'سفارش PCB چهار لایه' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</section>

			<?php if ( ! empty( $more_products ) ) : ?>
				<section class="dgl-section dgl-products" aria-labelledby="dgl-products-title">
					<div class="dgl-section-heading">
						<div>
							<span class="dgl-eyebrow">بیشتر بگرد</span>
							<h2 id="dgl-products-title">چندتا انتخاب جذاب دیگه</h2>
						</div>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">کل فروشگاه</a>
					</div>
					<div class="dgl-product-grid">
						<?php foreach ( $more_products as $product ) : ?>
							<?php echo $this->product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<section class="dgl-final-cta" aria-label="درخواست مشاوره و تأمین قطعه">
				<div>
					<span class="dgl-eyebrow">پارت‌نامبر داری؟</span>
					<h2>پیداش نکردی؟ ما برات پیدا می‌کنیم.</h2>
					<p>اسم قطعه، پارت‌نامبر یا لینک محصول رو بفرست؛ سریع بررسی می‌کنیم و راه درست تأمینش رو می‌گیم.</p>
				</div>
				<a class="dgl-button dgl-button--light" href="<?php echo esc_url( home_url( '/our-contacts/' ) ); ?>">با ما در ارتباط باش</a>
			</section>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Pick one live product from several useful catalog areas.
	 *
	 * @param int $limit Maximum products.
	 * @return array
	 */
	private function get_varied_products( $limit ) {
		$slugs = array(
			'wi-fi-and-bluetooth-modules',
			'raspberry-pi-boards',
			'arduino-boards',
			'sensors-transducers',
			'displays-modules',
			'modules-development-board',
		);
		$found = array();

		foreach ( $slugs as $slug ) {
			$products = wc_get_products(
				array(
					'status'       => 'publish',
					'stock_status' => 'instock',
					'visibility'   => 'visible',
					'category'     => array( $slug ),
					'limit'        => 1,
					'orderby'      => 'modified',
					'order'        => 'DESC',
				)
			);
			if ( empty( $products ) ) {
				continue;
			}
			$product                    = reset( $products );
			$found[ $product->get_id() ] = $product;
			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		return array_values( $found );
	}

	/**
	 * Get a second, non-duplicated live product set.
	 *
	 * @param int   $limit Maximum products.
	 * @param array $exclude_ids Product IDs already used in the hero.
	 * @return array
	 */
	private function get_more_products( $limit, $exclude_ids ) {
		$slugs = array(
			'patris-113007',
			'patris-113003',
			'patris-113008',
			'patris-113010',
			'temperature-and-humidity-sensors',
			'lcd-and-oled-displays',
			'regulators',
			'patris-106001',
		);
		$found = array();

		foreach ( $slugs as $slug ) {
			$products = wc_get_products(
				array(
					'status'       => 'publish',
					'stock_status' => 'instock',
					'visibility'   => 'visible',
					'category'     => array( $slug ),
					'exclude'      => array_merge( array_map( 'absint', $exclude_ids ), array_keys( $found ) ),
					'limit'        => 1,
					'orderby'      => 'modified',
					'order'        => 'DESC',
				)
			);
			if ( empty( $products ) ) {
				continue;
			}
			$product                     = reset( $products );
			$found[ $product->get_id() ] = $product;
			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		if ( count( $found ) < $limit ) {
			$fallback = wc_get_products(
				array(
					'status'       => 'publish',
					'stock_status' => 'instock',
					'visibility'   => 'visible',
					'exclude'      => array_merge( array_map( 'absint', $exclude_ids ), array_keys( $found ) ),
					'limit'        => $limit - count( $found ),
					'orderby'      => 'modified',
					'order'        => 'DESC',
				)
			);
			foreach ( $fallback as $product ) {
				$found[ $product->get_id() ] = $product;
			}
		}

		return array_values( $found );
	}

	/**
	 * Build curated category cards, omitting unavailable terms.
	 *
	 * @return array
	 */
	private function get_category_spotlights() {
		$definitions = array(
			array( 'modules-development-board', 'ماژول‌ها و بردها', 'برای ساختن، تست‌کردن و راه‌اندازی سریع', 'IoT' ),
			array( 'wi-fi-and-bluetooth-modules', 'ESP و ارتباطات', 'وای‌فای، بلوتوث و اینترنت اشیا', 'ESP' ),
			array( 'raspberry-pi-boards', 'رزبری‌پای و مینی‌کامپیوتر', 'از آموزش تا اتوماسیون و پردازش', 'Pi' ),
			array( 'sensors-transducers', 'سنسورها', 'دما، فشار، حرکت، گاز، نور و بیشتر', 'SEN' ),
			array( 'displays-modules', 'نمایشگرها', 'LCD، OLED، TFT و ماژول‌های نمایش', 'LCD' ),
			array( 'arduino-boards', 'آردوینو', 'بردهای محبوب برای شروع سریع پروژه', 'ARD' ),
		);
		$cards       = array();
		$visibility  = Digitalogic_Storefront_Catalog::instance();

		foreach ( $definitions as $definition ) {
			$term = get_term_by( 'slug', $definition[0], 'product_cat' );
			if ( ! $term || is_wp_error( $term ) || ! $visibility->is_category_visible( $term->term_id ) ) {
				continue;
			}
			$url = get_term_link( $term, 'product_cat' );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$cards[] = array(
				'url'      => $url,
				'title'    => $definition[1],
				'subtitle' => $definition[2],
				'mark'     => $definition[3],
			);
		}

		return $cards;
	}

	/**
	 * Render one product card.
	 *
	 * @param WC_Product $product Product object.
	 * @param bool       $compact Compact hero variant.
	 * @return string
	 */
	private function product_card( $product, $compact = false ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$name        = $product->get_name();
		$url         = $product->get_permalink();
		$image       = $product->get_image( 'woocommerce_thumbnail', array( 'loading' => 'lazy', 'alt' => $name ) );
		$price       = $product->get_price_html();
		$patris_code = trim( (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		$classes     = 'dgl-product-card' . ( $compact ? ' dgl-product-card--compact' : '' );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $classes ); ?>">
			<a class="dgl-product-image" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $name ); ?>">
				<?php echo wp_kses_post( $image ); ?>
				<span class="dgl-stock-badge">موجوده</span>
			</a>
			<div class="dgl-product-body">
				<?php if ( '' !== $patris_code ) : ?>
					<small>کد <?php echo esc_html( $patris_code ); ?></small>
				<?php endif; ?>
				<h3><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a></h3>
				<div class="dgl-product-footer">
					<span class="dgl-price"><?php echo '' !== $price ? wp_kses_post( $price ) : 'استعلام قیمت'; ?></span>
					<a class="dgl-product-arrow" href="<?php echo esc_url( $url ); ?>" aria-label="دیدن <?php echo esc_attr( $name ); ?>">←</a>
				</div>
			</div>
		</article>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render one service card.
	 *
	 * @return string
	 */
	private function service_card( $mark, $title, $description, $url, $label ) {
		return sprintf(
			'<a class="dgl-service-card" href="%1$s"><span>%2$s</span><h3>%3$s</h3><p>%4$s</p><strong>%5$s ←</strong></a>',
			esc_url( $url ),
			esc_html( $mark ),
			esc_html( $title ),
			esc_html( $description ),
			esc_html( $label )
		);
	}

	/**
	 * Resolve a category URL with a safe shop fallback.
	 *
	 * @param string $slug Category slug.
	 * @return string
	 */
	private function category_url( $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		$url  = $term && ! is_wp_error( $term ) ? get_term_link( $term, 'product_cat' ) : '';

		return ! is_wp_error( $url ) && '' !== $url ? $url : wc_get_page_permalink( 'shop' );
	}
}
