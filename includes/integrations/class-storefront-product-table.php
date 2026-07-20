<?php
/**
 * Public, inventory-backed product table with WooCommerce quick add support.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Storefront_Product_Table {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'digitalogic_product_table', array( $this, 'render' ) );
		add_shortcode( 'digitalogic_image_credits', array( $this, 'render_image_credits' ) );
		add_filter( 'posts_orderby', array( $this, 'recommended_orderby' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Load catalog styles in the document head for pages that use our shortcodes.
	 */
	public function maybe_enqueue_assets() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( has_shortcode( $post->post_content, 'digitalogic_product_table' ) || has_shortcode( $post->post_content, 'digitalogic_image_credits' ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Render the public product catalog.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$this->enqueue_assets();

		$filters  = $this->request_filters();
		$query    = new WP_Query( $this->query_args( $filters ) );
		$products = array_filter(
			array_map(
				static fn( $post ) => wc_get_product( $post->ID ),
				$query->posts
			)
		);
		$terms    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
				'orderby'    => 'name',
			)
		);
		$terms    = is_wp_error( $terms ) ? array() : $terms;

		ob_start();
		?>
		<section class="dgl-catalog" dir="rtl" aria-labelledby="dgl-catalog-title">
			<header class="dgl-catalog-hero">
				<div>
					<span class="dgl-catalog-eyebrow">لیست حرفه‌ای قطعات</span>
					<h1 id="dgl-catalog-title">سریع بگرد، مقایسه کن، بنداز توی سبد</h1>
					<p>فقط کالاهای قابل‌نمایش و موجود اینجاست؛ کد، قیمت و وضعیت هر قطعه رو یک‌جا می‌بینی.</p>
				</div>
				<div class="dgl-catalog-count"><strong><?php echo esc_html( number_format_i18n( $query->found_posts ) ); ?></strong><span>کالای موجود</span></div>
			</header>

			<form class="dgl-catalog-toolbar" method="get" action="<?php echo esc_url( get_permalink() ); ?>">
				<label class="dgl-catalog-search">
					<span class="screen-reader-text">جست‌وجوی محصول</span>
					<svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
					<input type="search" name="dgl_search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="اسم قطعه، SKU یا کد پاتریس...">
				</label>
				<label>
					<span class="screen-reader-text">دسته‌بندی</span>
					<select name="dgl_category">
						<option value="0">همه دسته‌ها</option>
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $filters['category'], $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span class="screen-reader-text">مرتب‌سازی</span>
					<select name="dgl_sort">
						<option value="recommended" <?php selected( $filters['sort'], 'recommended' ); ?>>پیشنهادی‌ها</option>
						<option value="popular" <?php selected( $filters['sort'], 'popular' ); ?>>محبوب‌ترها</option>
						<option value="newest" <?php selected( $filters['sort'], 'newest' ); ?>>جدیدترها</option>
						<option value="name" <?php selected( $filters['sort'], 'name' ); ?>>نام محصول</option>
						<option value="price-low" <?php selected( $filters['sort'], 'price-low' ); ?>>ارزان‌ترها</option>
						<option value="price-high" <?php selected( $filters['sort'], 'price-high' ); ?>>گران‌ترها</option>
					</select>
				</label>
				<button type="submit">اعمال فیلتر</button>
				<?php if ( $filters['search'] || $filters['category'] || 'recommended' !== $filters['sort'] ) : ?>
					<a class="dgl-catalog-reset" href="<?php echo esc_url( get_permalink() ); ?>">پاکش کن</a>
				<?php endif; ?>
			</form>

			<?php if ( empty( $products ) ) : ?>
				<div class="dgl-catalog-empty">
					<strong>چیزی با این فیلتر پیدا نشد</strong>
					<p>یه عبارت کوتاه‌تر امتحان کن یا دسته‌بندی رو روی «همه دسته‌ها» بذار.</p>
					<a href="<?php echo esc_url( get_permalink() ); ?>">دیدن همه محصولات</a>
				</div>
			<?php else : ?>
				<div class="dgl-catalog-table-wrap">
					<table class="dgl-catalog-table">
						<thead>
							<tr>
								<th scope="col">محصول</th>
								<th scope="col">کد / SKU</th>
								<th scope="col">دسته</th>
								<th scope="col">موجودی</th>
								<th scope="col">قیمت</th>
								<th scope="col">سفارش سریع</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $products as $product ) : ?>
								<?php echo $this->product_row( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php echo $this->pagination( $query, $filters ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>

			<div class="dgl-catalog-toast" role="status" aria-live="polite" hidden></div>
			<p class="dgl-catalog-credit-note">عکس‌های موقتِ منبع‌باز با ذکر منبع استفاده شدن؛ <a href="<?php echo esc_url( home_url( '/image-credits/' ) ); ?>">اعتبار عکس‌ها</a></p>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render attribution for temporary, openly licensed catalog photos.
	 *
	 * @return string
	 */
	public function render_image_credits() {
		$this->enqueue_assets();
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_digitalogic_image_source_url',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		ob_start();
		?>
		<section class="dgl-image-credits" dir="rtl">
			<header><span>شفاف و مرتب</span><h1>اعتبار عکس‌های محصولات</h1><p>این عکس‌ها موقتی‌ان تا عکس‌های اختصاصی فروشگاه جایگزین بشن. منبع و مجوز هرکدوم اینجاست.</p></header>
			<div class="dgl-image-credit-list">
				<?php foreach ( $query->posts as $attachment ) :
					$product_id = absint( get_post_meta( $attachment->ID, '_digitalogic_image_product_id', true ) );
					$source     = get_post_meta( $attachment->ID, '_digitalogic_image_source_url', true );
					$license    = get_post_meta( $attachment->ID, '_digitalogic_image_license', true );
					$license_url= get_post_meta( $attachment->ID, '_digitalogic_image_license_url', true );
					$credit     = get_post_meta( $attachment->ID, '_digitalogic_image_credit', true );
					$changes    = get_post_meta( $attachment->ID, '_digitalogic_image_changes', true );
					$product     = $product_id ? wc_get_product( $product_id ) : false;
					?>
					<article>
						<?php echo wp_get_attachment_image( $attachment->ID, 'thumbnail', false, array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div>
							<h2><?php echo $product ? '<a href="' . esc_url( $product->get_permalink() ) . '">' . esc_html( $product->get_name() ) . '</a>' : esc_html( get_the_title( $attachment ) ); ?></h2>
							<p>عکس: <?php echo esc_html( $credit ?: 'Wikimedia Commons contributor' ); ?> · <a href="<?php echo esc_url( $source ); ?>" target="_blank" rel="noopener">منبع</a> · <a href="<?php echo esc_url( $license_url ); ?>" target="_blank" rel="license noopener"><?php echo esc_html( $license ); ?></a></p>
							<?php if ( $changes ) : ?><small><?php echo esc_html( $changes ); ?></small><?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Enqueue catalog assets and native WooCommerce add-to-cart behavior.
	 */
	private function enqueue_assets() {
		wp_enqueue_style(
			'digitalogic-storefront-catalog',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/storefront-catalog.css',
			array(),
			DIGITALOGIC_VERSION
		);

		$dependencies = array( 'jquery' );
		if ( wp_script_is( 'wc-add-to-cart', 'registered' ) ) {
			$dependencies[] = 'wc-add-to-cart';
			wp_enqueue_script( 'wc-add-to-cart' );
		}
		if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_enqueue_script( 'wc-cart-fragments' );
		}

		wp_enqueue_script(
			'digitalogic-storefront-catalog',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/storefront-catalog.js',
			$dependencies,
			DIGITALOGIC_VERSION,
			true
		);
		wp_localize_script(
			'digitalogic-storefront-catalog',
			'digitalogicCatalog',
			array(
				'added' => 'اضافه شد؛ توی سبد منتظرته 😎',
				'error' => 'اضافه نشد؛ یه بار دیگه امتحانش کن.',
			)
		);
	}

	/**
	 * Read and normalize public filters.
	 *
	 * @return array
	 */
	private function request_filters() {
		$allowed_sorts = array( 'recommended', 'popular', 'newest', 'name', 'price-low', 'price-high' );
		$sort          = isset( $_GET['dgl_sort'] ) && is_scalar( $_GET['dgl_sort'] ) ? sanitize_key( wp_unslash( $_GET['dgl_sort'] ) ) : 'recommended'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search        = isset( $_GET['dgl_search'] ) && is_scalar( $_GET['dgl_search'] ) ? sanitize_text_field( wp_unslash( $_GET['dgl_search'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$category      = isset( $_GET['dgl_category'] ) && is_scalar( $_GET['dgl_category'] ) ? absint( wp_unslash( $_GET['dgl_category'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page          = isset( $_GET['dgl_page'] ) && is_scalar( $_GET['dgl_page'] ) ? max( 1, absint( wp_unslash( $_GET['dgl_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'search'   => $search,
			'category' => $category,
			'sort'     => in_array( $sort, $allowed_sorts, true ) ? $sort : 'recommended',
			'page'     => $page,
		);
	}

	/**
	 * Build a public-only WooCommerce product query.
	 *
	 * @param array $filters Normalized filters.
	 * @return array
	 */
	private function query_args( $filters ) {
		$visibility_ids = function_exists( 'wc_get_product_visibility_term_ids' ) ? wc_get_product_visibility_term_ids() : array();
		$tax_query      = array( 'relation' => 'AND' );
		$hidden_ids     = array_filter( array( $visibility_ids['exclude-from-catalog'] ?? 0 ) );

		if ( $hidden_ids ) {
			$tax_query[] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'term_taxonomy_id',
				'terms'    => $hidden_ids,
				'operator' => 'NOT IN',
			);
		}
		if ( $filters['category'] ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => array( $filters['category'] ),
				'include_children' => true,
			);
		}

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => 30,
			'paged'                  => $filters['page'],
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_stock_status',
					'value' => 'instock',
				),
			),
			'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		);

		if ( '' !== $filters['search'] ) {
			$args['post__in'] = $this->search_product_ids( $filters['search'] ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__in
		}

		switch ( $filters['sort'] ) {
			case 'recommended':
				$args['digitalogic_recommended_order'] = true;
				$args['orderby']                       = 'date';
				$args['order']                         = 'DESC';
				break;
			case 'newest':
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
			case 'name':
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			case 'price-low':
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				break;
			case 'price-high':
				$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				break;
			default:
				$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = array( 'meta_value_num' => 'DESC', 'date' => 'DESC' );
				break;
		}

		return $args;
	}

	/**
	 * Put photographed products first in the default showcase order, then
	 * preserve useful commercial ordering by sales and recency.
	 *
	 * @param string   $orderby Existing ORDER BY clause.
	 * @param WP_Query $query   Current query.
	 * @return string
	 */
	public function recommended_orderby( $orderby, $query ) {
		if ( ! $query->get( 'digitalogic_recommended_order' ) ) {
			return $orderby;
		}

		global $wpdb;

		return "EXISTS (
			SELECT 1 FROM {$wpdb->postmeta} AS dgl_thumbnail_meta
			WHERE dgl_thumbnail_meta.post_id = {$wpdb->posts}.ID
				AND dgl_thumbnail_meta.meta_key = '_thumbnail_id'
				AND dgl_thumbnail_meta.meta_value <> ''
		) DESC,
		COALESCE((
			SELECT CAST(dgl_sales_meta.meta_value AS UNSIGNED)
			FROM {$wpdb->postmeta} AS dgl_sales_meta
			WHERE dgl_sales_meta.post_id = {$wpdb->posts}.ID
				AND dgl_sales_meta.meta_key = 'total_sales'
			LIMIT 1
		), 0) DESC,
		{$wpdb->posts}.post_date DESC";
	}

	/**
	 * Search names, SKU values and Patris codes.
	 *
	 * @param string $search Search text.
	 * @return array
	 */
	private function search_product_ids( $search ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT DISTINCT products.ID
			FROM {$wpdb->posts} AS products
			LEFT JOIN {$wpdb->postmeta} AS identifiers
				ON identifiers.post_id = products.ID
				AND identifiers.meta_key IN ('_sku', '_digitalogic_patris_product_code')
			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				AND (products.post_title LIKE %s OR identifiers.meta_value LIKE %s)
			LIMIT 3000",
			$like,
			$like
		);
		$ids  = array_map( 'absint', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $ids ?: array( 0 );
	}

	/**
	 * Render a catalog table row.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function product_row( $product ) {
		$name        = $product->get_name();
		$url         = $product->get_permalink();
		$sku         = trim( (string) $product->get_sku() );
		$patris_code = trim( (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
		$code        = $patris_code ?: $sku;
		$categories  = wc_get_product_category_list( $product->get_id(), '، ' );
		$quantity    = $product->get_stock_quantity();
		$stock_text  = $product->managing_stock() && null !== $quantity ? number_format_i18n( max( 0, $quantity ) ) . ' عدد' : 'موجود';
		$can_add     = $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() && '' !== $product->get_price();

		ob_start();
		?>
		<tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<td class="dgl-catalog-product-cell">
				<a class="dgl-catalog-thumb" href="<?php echo esc_url( $url ); ?>" aria-label="<?php echo esc_attr( $name ); ?>">
					<?php if ( $product->get_image_id() ) : ?>
						<?php echo wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php else : ?>
						<span class="dgl-catalog-no-image" aria-hidden="true"><i></i><b>DL</b></span>
					<?php endif; ?>
				</a>
				<div>
					<a class="dgl-catalog-product-name" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
					<span class="dgl-catalog-mobile-meta"><?php echo $code ? 'کد ' . esc_html( $code ) . ' · ' : ''; ?><?php echo wp_kses_post( $categories ); ?></span>
				</div>
			</td>
			<td class="dgl-catalog-code" dir="ltr"><?php echo $code ? esc_html( $code ) : '—'; ?></td>
			<td class="dgl-catalog-category"><?php echo $categories ? wp_kses_post( $categories ) : '—'; ?></td>
			<td><span class="dgl-catalog-stock"><i></i><?php echo esc_html( $stock_text ); ?></span></td>
			<td class="dgl-catalog-price"><?php echo $product->get_price_html() ? wp_kses_post( $product->get_price_html() ) : '<span>استعلام قیمت</span>'; ?></td>
			<td class="dgl-catalog-action">
				<?php if ( $can_add ) : ?>
					<div class="dgl-quick-add">
						<label><span class="screen-reader-text">تعداد <?php echo esc_html( $name ); ?></span><input type="number" class="dgl-quick-qty" min="1" step="1" value="1"<?php echo $product->is_sold_individually() ? ' max="1" readonly' : ''; ?>></label>
						<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" data-quantity="1" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>" data-product_sku="<?php echo esc_attr( $sku ); ?>" class="dgl-quick-button button product_type_simple add_to_cart_button ajax_add_to_cart" rel="nofollow"><span>افزودن سریع</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6 6h15l-2 8H8L6 3H3m5 15a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm10 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/></svg></a>
					</div>
				<?php elseif ( $product->is_type( 'variable' ) ) : ?>
					<a class="dgl-catalog-options" href="<?php echo esc_url( $url ); ?>">انتخاب گزینه‌ها</a>
				<?php else : ?>
					<a class="dgl-catalog-options" href="<?php echo esc_url( add_query_arg( 'product_id', $product->get_id(), home_url( '/import-of-electronic-products/' ) ) ); ?>">درخواست قیمت</a>
				<?php endif; ?>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render pagination while preserving active filters.
	 *
	 * @param WP_Query $query Query.
	 * @param array    $filters Filters.
	 * @return string
	 */
	private function pagination( $query, $filters ) {
		if ( $query->max_num_pages < 2 ) {
			return '';
		}

		$base = add_query_arg(
			array(
				'dgl_search'   => $filters['search'] ?: false,
				'dgl_category' => $filters['category'] ?: false,
				'dgl_sort'     => 'recommended' !== $filters['sort'] ? $filters['sort'] : false,
				'dgl_page'     => 999999999,
			),
			get_permalink()
		);
		$base = str_replace( '999999999', '%#%', $base );
		$links = paginate_links(
			array(
				'base'      => $base,
				'current'   => $filters['page'],
				'total'     => $query->max_num_pages,
				'mid_size'  => 2,
				'end_size'  => 1,
				'prev_text' => 'قبلی',
				'next_text' => 'بعدی',
				'type'      => 'list',
			)
		);

		return $links ? '<nav class="dgl-catalog-pagination" aria-label="صفحه‌های محصولات">' . $links . '</nav>' : '';
	}
}
