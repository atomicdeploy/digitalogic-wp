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
		add_filter( 'digitalogic_command_handlers', array( $this, 'register_commands' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_digitalogic_catalog_page', array( $this, 'ajax_catalog_page' ) );
		add_action( 'wp_ajax_nopriv_digitalogic_catalog_page', array( $this, 'ajax_catalog_page' ) );
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
		$products = $this->products_from_query( $query );
		$base_url = $this->catalog_base_url();
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

			<form class="dgl-catalog-toolbar" method="get" action="<?php echo esc_url( $base_url ); ?>">
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
					<a class="dgl-catalog-reset" href="<?php echo esc_url( $base_url ); ?>">پاکش کن</a>
				<?php endif; ?>
			</form>

			<div
				class="dgl-catalog-results"
				data-page="<?php echo esc_attr( $filters['page'] ); ?>"
				data-total-pages="<?php echo esc_attr( max( 1, (int) $query->max_num_pages ) ); ?>"
				role="region"
				aria-label="نتایج محصولات"
				aria-busy="false"
				tabindex="-1"
			>
				<?php echo $this->render_results( $query, $products, $filters, $base_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="dgl-catalog-load-status screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>

			<div class="dgl-catalog-toast" role="status" aria-live="polite" hidden></div>
			<p class="dgl-catalog-credit-note">عکس‌های موقتِ منبع‌باز با ذکر منبع استفاده شدن؛ <a href="<?php echo esc_url( home_url( '/image-credits/' ) ); ?>">اعتبار عکس‌ها</a></p>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Register the read-only catalog page command for non-HTTP transports.
	 *
	 * @param array  $commands  Registered command handlers.
	 * @param string $transport Transport name.
	 * @return array
	 */
	public function register_commands( $commands, $transport ) {
		unset( $transport );
		$commands['digitalogic_catalog_page'] = array( $this, 'catalog_page_command' );

		return $commands;
	}

	/**
	 * Serve a catalog result fragment over the normal WordPress AJAX fallback.
	 */
	public function ajax_catalog_page() {
		$result = $this->catalog_page_command( $_REQUEST, 'ajax' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 500;
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Build the same catalog fragment for AJAX and WebSocket clients.
	 *
	 * @param array  $payload   Catalog filter payload.
	 * @param string $transport Transport name.
	 * @return array|WP_Error
	 */
	public function catalog_page_command( $payload, $transport = 'websocket' ) {
		unset( $transport );

		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'digitalogic_catalog_unavailable', __( 'WooCommerce is unavailable.', 'digitalogic' ), array( 'status' => 503 ) );
		}

		$payload  = is_array( $payload ) ? $payload : array();
		$filters  = $this->request_filters( $payload );
		$query    = new WP_Query( $this->query_args( $filters ) );
		$products = $this->products_from_query( $query );
		$base_url = $this->catalog_base_url( $payload['base_url'] ?? '' );

		return array(
			'html'              => $this->render_results( $query, $products, $filters, $base_url ),
			'page'              => (int) $filters['page'],
			'max_pages'         => max( 1, (int) $query->max_num_pages ),
			'found_posts'       => (int) $query->found_posts,
			'found_posts_label' => number_format_i18n( $query->found_posts ),
		);
	}

	/**
	 * Render only the replaceable result table and pagination.
	 *
	 * @param WP_Query $query    Product query.
	 * @param array    $products Products for the current page.
	 * @param array    $filters  Normalized filters.
	 * @param string   $base_url Catalog page URL.
	 * @return string
	 */
	private function render_results( $query, $products, $filters, $base_url ) {
		ob_start();
		?>
		<?php if ( empty( $products ) ) : ?>
			<div class="dgl-catalog-empty">
				<strong>چیزی با این فیلتر پیدا نشد</strong>
				<p>یه عبارت کوتاه‌تر امتحان کن یا دسته‌بندی رو روی «همه دسته‌ها» بذار.</p>
				<a href="<?php echo esc_url( $base_url ); ?>">دیدن همه محصولات</a>
			</div>
		<?php else : ?>
			<div class="dgl-catalog-table-wrap">
				<table class="dgl-catalog-table">
					<thead>
						<tr>
							<th scope="col">محصول</th>
							<th scope="col">کد پاتریس / SKU</th>
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
			<?php echo $this->pagination( $query, $filters, $base_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
		<?php

		return ob_get_clean();
	}

	/**
	 * Convert query posts to usable WooCommerce product objects.
	 *
	 * @param WP_Query $query Product query.
	 * @return array
	 */
	private function products_from_query( $query ) {
		return array_filter(
			array_map(
				static fn( $post ) => wc_get_product( $post->ID ),
				$query->posts
			)
		);
	}

	/**
	 * Resolve a same-site base URL that remains valid in the WP-CLI WS process.
	 *
	 * @param mixed $candidate Optional client-provided catalog URL.
	 * @return string
	 */
	private function catalog_base_url( $candidate = '' ) {
		$fallback  = get_permalink();
		$fallback  = is_string( $fallback ) && '' !== $fallback ? $fallback : home_url( '/catalog/' );
		$candidate = is_scalar( $candidate ) ? esc_url_raw( wp_unslash( (string) $candidate ) ) : '';

		if ( '' === $candidate ) {
			return $fallback;
		}

		$candidate_parts = wp_parse_url( $candidate );
		$home_parts      = wp_parse_url( home_url( '/' ) );
		$scheme          = strtolower( (string) ( $candidate_parts['scheme'] ?? '' ) );
		$same_host       = isset( $candidate_parts['host'], $home_parts['host'] ) && 0 === strcasecmp( $candidate_parts['host'], $home_parts['host'] );

		if ( ! $same_host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return $fallback;
		}

		return $candidate;
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
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/css/storefront-catalog.css' ) ?: DIGITALOGIC_VERSION
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
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/js/storefront-catalog.js' ) ?: DIGITALOGIC_VERSION,
			true
		);
		wp_localize_script(
			'digitalogic-storefront-catalog',
			'digitalogicCatalog',
			array(
				'added'        => 'اضافه شد؛ توی سبد منتظرته 😎',
				'error'        => 'اضافه نشد؛ یه بار دیگه امتحانش کن.',
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'baseUrl'      => $this->catalog_base_url(),
				'pageAction'   => 'digitalogic_catalog_page',
				'pageLoading'  => 'صفحه بعدی محصولات در حال بارگذاری است.',
				'pageLoaded'   => 'صفحه محصولات بارگذاری شد.',
				'pageError'    => 'بارگذاری سریع انجام نشد؛ صفحه معمولی باز می‌شود.',
			)
		);
	}

	/**
	 * Read and normalize public filters.
	 *
	 * @param array|null $source Explicit request data, or null for the page query.
	 * @return array
	 */
	private function request_filters( $source = null ) {
		$source        = is_array( $source ) ? $source : $_GET;
		$allowed_sorts = array( 'recommended', 'popular', 'newest', 'name', 'price-low', 'price-high' );
		$sort          = isset( $source['dgl_sort'] ) && is_scalar( $source['dgl_sort'] ) ? sanitize_key( wp_unslash( $source['dgl_sort'] ) ) : 'recommended';
		$search        = isset( $source['dgl_search'] ) && is_scalar( $source['dgl_search'] ) ? sanitize_text_field( wp_unslash( $source['dgl_search'] ) ) : '';
		$category      = isset( $source['dgl_category'] ) && is_scalar( $source['dgl_category'] ) ? absint( wp_unslash( $source['dgl_category'] ) ) : 0;
		$page          = isset( $source['dgl_page'] ) && is_scalar( $source['dgl_page'] ) ? max( 1, min( 1000, absint( wp_unslash( $source['dgl_page'] ) ) ) ) : 1;

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
			LEFT JOIN {$wpdb->posts} AS variations
				ON variations.post_parent = products.ID
				AND variations.post_type = 'product_variation'
				AND variations.post_status = 'publish'
			LEFT JOIN {$wpdb->postmeta} AS variation_identifiers
				ON variation_identifiers.post_id = variations.ID
				AND variation_identifiers.meta_key IN ('_sku', '_digitalogic_patris_product_code')
			WHERE products.post_type = 'product'
				AND products.post_status = 'publish'
				AND (
					products.post_title LIKE %s
					OR identifiers.meta_value LIKE %s
					OR variations.post_title LIKE %s
					OR variation_identifiers.meta_value LIKE %s
				)
			LIMIT 3000",
			$like,
			$like,
			$like,
			$like
		);
		$ids  = array_map( 'absint', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $ids ?: array( 0 );
	}

	/**
	 * Resolve published child Codes for legacy or variable parent rows.
	 *
	 * @param int $product_id Parent product ID.
	 * @return array
	 */
	private function get_product_child_codes( $product_id ) {
		$child_ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'post_parent'    => absint( $product_id ),
				'fields'         => 'ids',
				'posts_per_page' => 50,
			)
		);
		$codes     = array();

		foreach ( $child_ids as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product || $variation->get_parent_id() !== absint( $product_id ) || 'publish' !== $variation->get_status() ) {
				continue;
			}
			$code = trim( (string) $variation->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );
			if ( '' !== $code ) {
				$codes[ $code ] = $code;
			}
		}

		return array_values( $codes );
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
		$code        = $patris_code;
		$is_variable = $product->is_type( 'variable' );
		if ( '' === $code ) {
			$code = $sku;
		}
		$child_codes = '' === $code ? $this->get_product_child_codes( $product->get_id() ) : array();
		$code_label  = 'کد پاتریس';
		if ( '' === $patris_code && '' !== $sku ) {
			$code_label = 'SKU';
		} elseif ( ! empty( $child_codes ) ) {
			$code_label = 'کدهای ثبت‌شده برای مدل‌ها';
		}
		$categories  = wc_get_product_category_list( $product->get_id(), '، ' );
		$quantity    = $product->get_stock_quantity();
		$stock_text  = $product->managing_stock() && null !== $quantity ? number_format_i18n( max( 0, $quantity ) ) . ' عدد' : 'موجود';
		$can_add     = $product->is_type( 'simple' ) && empty( $child_codes ) && $product->is_purchasable() && $product->is_in_stock() && '' !== $product->get_price();

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
					<span class="dgl-catalog-mobile-meta">
						<?php if ( $code ) : ?>
							<?php echo esc_html( $code_label ); ?> <b dir="ltr"><?php echo esc_html( $code ); ?></b> ·
						<?php elseif ( $child_codes ) : ?>
							کدهای ثبت‌شده برای مدل‌ها <b dir="ltr"><?php echo esc_html( implode( ' · ', $child_codes ) ); ?></b> ·
						<?php elseif ( $is_variable ) : ?>
							کد پاتریس بعد از انتخاب مدل ·
						<?php endif; ?>
						<?php echo wp_kses_post( $categories ); ?>
					</span>
				</div>
			</td>
			<td class="dgl-catalog-code">
				<span><?php echo esc_html( $code_label ); ?></span>
				<?php if ( $code ) : ?>
					<b dir="ltr"><?php echo esc_html( $code ); ?></b>
				<?php elseif ( $child_codes ) : ?>
					<?php foreach ( $child_codes as $child_code ) : ?>
						<b dir="ltr"><?php echo esc_html( $child_code ); ?></b>
					<?php endforeach; ?>
				<?php elseif ( $is_variable ) : ?>
					<a href="<?php echo esc_url( $url ); ?>">انتخاب مدل</a>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td class="dgl-catalog-category"><?php echo $categories ? wp_kses_post( $categories ) : '—'; ?></td>
			<td><span class="dgl-catalog-stock"><i></i><?php echo esc_html( $stock_text ); ?></span></td>
			<td class="dgl-catalog-price"><?php echo $product->get_price_html() ? wp_kses_post( $product->get_price_html() ) : '<span>استعلام قیمت</span>'; ?></td>
			<td class="dgl-catalog-action">
				<?php if ( $can_add ) : ?>
					<div class="dgl-quick-add">
						<label><span class="screen-reader-text">تعداد <?php echo esc_html( $name ); ?></span><input type="number" class="dgl-quick-qty" min="1" step="1" value="1"<?php echo $product->is_sold_individually() ? ' max="1" readonly' : ''; ?>></label>
						<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" data-quantity="1" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>" data-product_sku="<?php echo esc_attr( $sku ); ?>" class="dgl-quick-button button product_type_simple add_to_cart_button ajax_add_to_cart" rel="nofollow"><span>افزودن سریع</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6 6h15l-2 8H8L6 3H3m5 15a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm10 0a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z"/></svg></a>
					</div>
				<?php elseif ( $child_codes ) : ?>
					<a class="dgl-catalog-options" href="<?php echo esc_url( $url ); ?>">دیدن کد مدل‌ها</a>
				<?php elseif ( $is_variable ) : ?>
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
	 * @param WP_Query $query    Query.
	 * @param array    $filters  Filters.
	 * @param string   $base_url Catalog page URL.
	 * @return string
	 */
	private function pagination( $query, $filters, $base_url ) {
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
			$base_url
		);
		$base = str_replace( '999999999', '%#%', $base );
		$links = paginate_links(
			array(
				'base'      => $base,
				'current'   => $filters['page'],
				'total'     => $query->max_num_pages,
				'mid_size'  => 2,
				'end_size'  => 1,
				'aria_current' => 'page',
				'prev_text' => 'قبلی',
				'next_text' => 'بعدی',
				'type'      => 'list',
			)
		);

		return $links ? '<nav class="dgl-catalog-pagination" aria-label="صفحه‌های محصولات">' . $links . '</nav>' : '';
	}
}
