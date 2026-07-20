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
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Load homepage presentation assets in the document head.
	 */
	public function maybe_enqueue_assets() {
		$post = get_queried_object();
		if ( is_front_page() || ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'digitalogic_homepage' ) ) ) {
			$this->enqueue_assets();
		}
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

		$this->enqueue_assets();

		$hero_products = $this->get_varied_products( 6 );
		$hero_ids      = array_map( static fn( $product ) => $product->get_id(), $hero_products );
		$more_products = $this->get_more_products( 8, $hero_ids );
		$categories    = $this->get_category_spotlights();

		ob_start();
		?>
		<div class="dgl-home" dir="rtl">
			<?php echo $this->hero_carousel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( ! empty( $hero_products ) ) : ?>
				<section class="dgl-section dgl-products dgl-stock-now" aria-labelledby="dgl-stock-title">
					<div class="dgl-section-heading">
						<div>
							<span class="dgl-section-kicker"><i></i> همین الان موجوده</span>
							<h2 id="dgl-stock-title">چندتا ماژول که حیفه نبینیشون</h2>
						</div>
						<div class="dgl-heading-actions">
							<a href="<?php echo esc_url( $this->catalog_url() ); ?>">لیست کامل محصولات</a>
							<div class="dgl-carousel-buttons" aria-label="کنترل محصولات">
								<button type="button" data-dgl-rail-prev aria-label="محصولات قبلی">→</button>
								<button type="button" data-dgl-rail-next aria-label="محصولات بعدی">←</button>
							</div>
						</div>
					</div>
					<div class="dgl-product-rail" data-dgl-rail>
						<?php foreach ( $hero_products as $product ) : ?>
							<?php echo $this->product_card( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $categories ) ) : ?>
				<section class="dgl-section" aria-labelledby="dgl-category-title">
					<div class="dgl-section-heading">
						<div>
							<span class="dgl-eyebrow">از کجا شروع کنیم؟</span>
							<h2 id="dgl-category-title">یه راست برو سراغ چیزی که لازم داری</h2>
						</div>
						<a href="<?php echo esc_url( $this->catalog_url() ); ?>">دیدن همه محصولات</a>
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
					<?php echo $this->service_card( '01', 'قطعه و ماژول آماده', 'آردوینو، ESP، سنسور، نمایشگر و کلی ماژول کاربردی برای پروژه‌های کوچیک و بزرگ.', $this->catalog_url(), 'محصولات رو ببین' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
						<div class="dgl-heading-actions">
							<a href="<?php echo esc_url( $this->catalog_url() ); ?>">کل فروشگاه</a>
							<div class="dgl-carousel-buttons" aria-label="کنترل محصولات">
								<button type="button" data-dgl-rail-prev aria-label="محصولات قبلی">→</button>
								<button type="button" data-dgl-rail-next aria-label="محصولات بعدی">←</button>
							</div>
						</div>
					</div>
					<div class="dgl-product-rail" data-dgl-rail>
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
	 * Render the main, high-contrast service carousel.
	 *
	 * @return string
	 */
	private function hero_carousel() {
		$slides = array(
			array(
				'image'           => DIGITALOGIC_PLUGIN_URL . 'assets/images/storefront-modules-hero.webp',
				'eyebrow'         => 'قطعات، ماژول‌ها و بردهای توسعه',
				'title'           => 'ماژول‌های باحال رو کردیم؛ ببین چی موجوده',
				'copy'            => 'از ESP و آردوینو تا Raspberry Pi، سنسور و نمایشگر؛ موجودی واقعی رو ورق بزن و سریع سفارش بده.',
				'primary_label'   => 'لیست حرفه‌ای محصولات',
				'primary_url'     => $this->catalog_url(),
				'secondary_label' => 'ESP و ارتباطات',
				'secondary_url'   => $this->category_url( 'wi-fi-and-bluetooth-modules' ),
			),
			array(
				'image'           => DIGITALOGIC_PLUGIN_URL . 'assets/images/storefront-sourcing-service.webp',
				'eyebrow'         => 'سفارش کالا و قطعه از چین',
				'title'           => 'پارت‌نامبر بده؛ پیداش می‌کنیم و می‌رسونیم دستت',
				'copy'            => 'برای قطعه کمیاب، خرید تیراژی یا BOM کامل، یه درخواست بفرست تا قیمت و زمان تأمین رو شفاف برات دربیاریم.',
				'primary_label'   => 'ثبت سفارش خارجی',
				'primary_url'     => home_url( '/import-of-electronic-products/' ),
				'secondary_label' => 'فرم چه اطلاعاتی می‌خواد؟',
				'secondary_url'   => home_url( '/import-of-electronic-products/#dgl-request-title' ),
			),
			array(
				'image'           => DIGITALOGIC_PLUGIN_URL . 'assets/images/storefront-pcb-service.webp',
				'eyebrow'         => 'تولید PCB دو لایه و چهار لایه',
				'title'           => 'Gerber رو بده؛ برد تمیز و حرفه‌ای تحویل بگیر',
				'copy'            => 'از نمونه‌سازی تا تیراژ؛ مشخصات برد رو ثبت کن تا قیمت، زمان ساخت و گزینه‌های تولید رو یک‌جا بگیری.',
				'primary_label'   => 'استعلام ساخت PCB',
				'primary_url'     => home_url( '/سفارش-چاپ-برد-pcb/' ),
				'secondary_label' => 'دو لایه یا چهار لایه؟',
				'secondary_url'   => home_url( '/سفارش-چاپ-برد-pcb/#dgl-request-title' ),
			),
		);

		ob_start();
		?>
		<section class="dgl-story-carousel" data-dgl-story-carousel aria-roledescription="carousel" aria-label="محصولات و خدمات دیجیتالاجیک">
			<div class="dgl-story-slides" aria-live="off">
				<?php foreach ( $slides as $index => $slide ) : ?>
					<article class="dgl-story-slide<?php echo 0 === $index ? ' is-active' : ''; ?>" data-dgl-story-slide aria-hidden="<?php echo 0 === $index ? 'false' : 'true'; ?>"<?php echo 0 === $index ? '' : ' hidden'; ?>>
						<span class="dgl-story-media" style="background-image:url('<?php echo esc_url( $slide['image'] ); ?>')" aria-hidden="true"></span>
						<div class="dgl-story-copy">
							<span class="dgl-eyebrow"><?php echo esc_html( $slide['eyebrow'] ); ?></span>
							<?php if ( 0 === $index ) : ?>
								<h1><?php echo esc_html( $slide['title'] ); ?></h1>
							<?php else : ?>
								<h2><?php echo esc_html( $slide['title'] ); ?></h2>
							<?php endif; ?>
							<p><?php echo esc_html( $slide['copy'] ); ?></p>
							<div class="dgl-hero-actions">
								<a class="dgl-button dgl-button--primary" href="<?php echo esc_url( $slide['primary_url'] ); ?>"><?php echo esc_html( $slide['primary_label'] ); ?></a>
								<a class="dgl-button dgl-button--ghost" href="<?php echo esc_url( $slide['secondary_url'] ); ?>"><?php echo esc_html( $slide['secondary_label'] ); ?></a>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
			<div class="dgl-story-controls">
				<button type="button" class="dgl-story-autoplay" data-dgl-story-autoplay aria-pressed="false" aria-label="توقف پخش خودکار اسلایدها">
					<span class="dgl-story-autoplay-icon" data-dgl-story-autoplay-icon aria-hidden="true">Ⅱ</span>
					<span data-dgl-story-autoplay-label>توقف پخش</span>
				</button>
				<span class="dgl-screen-reader-text" data-dgl-story-autoplay-status aria-live="polite" aria-atomic="true"></span>
				<div class="dgl-carousel-buttons">
					<button type="button" data-dgl-story-prev aria-label="اسلاید قبلی">→</button>
					<button type="button" data-dgl-story-next aria-label="اسلاید بعدی">←</button>
				</div>
				<div class="dgl-story-dots" role="tablist" aria-label="انتخاب اسلاید">
					<?php foreach ( $slides as $index => $slide ) : ?>
						<button type="button" role="tab" data-dgl-story-dot="<?php echo esc_attr( $index ); ?>" aria-label="اسلاید <?php echo esc_attr( $index + 1 ); ?>" aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>"></button>
					<?php endforeach; ?>
				</div>
				<span class="dgl-story-counter" dir="ltr"><b data-dgl-story-current>01</b> / 03</span>
			</div>
		</section>
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
					'limit'        => 24,
					'orderby'      => 'modified',
					'order'        => 'DESC',
				)
			);
			if ( empty( $products ) ) {
				continue;
			}
			$product                    = $this->prefer_product_with_image( $products );
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
					'limit'        => 24,
					'orderby'      => 'modified',
					'order'        => 'DESC',
				)
			);
			if ( empty( $products ) ) {
				continue;
			}
			$product                     = $this->prefer_product_with_image( $products );
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
	 * Prefer a real product photo without making image availability a hard gate.
	 *
	 * @param array $products Candidate products.
	 * @return WC_Product|false
	 */
	private function prefer_product_with_image( $products ) {
		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product && $product->get_image_id() ) {
				return $product;
			}
		}

		return reset( $products );
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
			$url = add_query_arg( 'dgl_category', $term->term_id, $this->catalog_url() );
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
		$url  = $term && ! is_wp_error( $term ) ? add_query_arg( 'dgl_category', $term->term_id, $this->catalog_url() ) : '';

		return ! is_wp_error( $url ) && '' !== $url ? $url : wc_get_page_permalink( 'shop' );
	}

	/**
	 * Register homepage assets. WordPress de-duplicates repeated calls.
	 */
	private function enqueue_assets() {
		wp_enqueue_style(
			'digitalogic-storefront',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/storefront.css',
			array(),
			DIGITALOGIC_VERSION
		);
		wp_enqueue_script(
			'digitalogic-storefront-carousel',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/storefront-carousel.js',
			array(),
			DIGITALOGIC_VERSION,
			true
		);
	}

	/**
	 * Resolve the professional catalog page, with the Woo shop as fallback.
	 *
	 * @return string
	 */
	private function catalog_url() {
		$page = get_page_by_path( 'catalog' );

		return $page && 'publish' === $page->post_status ? get_permalink( $page ) : wc_get_page_permalink( 'shop' );
	}
}
