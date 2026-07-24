<?php
/**
 * Administrator-only product supplier-link editor.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render and save private supplier links on classic WooCommerce product screens.
 */
final class Digitalogic_Product_Supplier_Links_Admin {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared handler.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register classic product-editor hooks.
	 */
	private function __construct() {
		add_action( 'add_meta_boxes_product', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_product' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'show_saved_error' ) );
		add_filter( 'manage_edit-product_columns', array( $this, 'add_product_column' ), 30 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_product_column' ), 10, 2 );
	}

	/**
	 * Register the private Persian metabox.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public function add_meta_box( $post ) {
		if ( ! is_object( $post ) || empty( $post->ID ) || ! $this->can_manage_product( $post->ID ) ) {
			return;
		}

		add_meta_box(
			'digitalogic-private-supplier-links-box',
			'منابع خرید خصوصی',
			array( $this, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render a private, RTL link repeater.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		if ( ! $this->can_manage_product( $post->ID ) ) {
			return;
		}

		$links = Digitalogic_Product_Supplier_Links::instance()->get_links( $post->ID );
		if ( is_wp_error( $links ) ) {
			$links = array();
		}

		wp_nonce_field( 'digitalogic_save_supplier_links', 'digitalogic_supplier_links_nonce' );
		?>
		<div
			id="digitalogic-private-supplier-links"
			class="digitalogic-supplier-links"
			dir="rtl"
			data-next-index="<?php echo esc_attr( (string) count( $links ) ); ?>"
		>
			<p class="description">
				این اطلاعات فقط برای مدیران سایت نگهداری می‌شود و در فروشگاه، داده‌های ساختاریافته یا API عمومی نمایش داده نمی‌شود.
			</p>
			<div class="digitalogic-supplier-links__rows">
				<?php
				foreach ( $links as $index => $link ) {
					$this->render_link_row( (string) $index, $link );
				}
				?>
			</div>
			<p class="digitalogic-supplier-links__empty<?php echo empty( $links ) ? '' : ' is-hidden'; ?>">
				هنوز منبع خریدی برای این محصول ثبت نشده است.
			</p>
			<p>
				<button type="button" class="button digitalogic-supplier-links__add">افزودن منبع خرید</button>
			</p>
		</div>
		<script type="text/html" id="tmpl-digitalogic-supplier-link-row">
			<?php $this->render_link_row( '__INDEX__', array() ); ?>
		</script>
		<?php
	}

	/**
	 * Persist only nonce-verified administrator input.
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post Product post.
	 * @param bool    $update Whether an existing product is being updated.
	 * @return void
	 */
	public function save_product( $post_id, $post, $update ) {
		unset( $update );
		if (
			! is_object( $post )
			|| 'product' !== $post->post_type
			|| wp_is_post_autosave( $post_id )
			|| wp_is_post_revision( $post_id )
			|| ! $this->can_manage_product( $post_id )
		) {
			return;
		}

		$nonce = isset( $_POST['digitalogic_supplier_links_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['digitalogic_supplier_links_nonce'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'digitalogic_save_supplier_links' ) ) {
			return;
		}

		$raw_links = isset( $_POST['digitalogic_supplier_links'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? map_deep( wp_unslash( $_POST['digitalogic_supplier_links'] ), 'sanitize_text_field' ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();
		$result    = Digitalogic_Product_Supplier_Links::instance()->replace_links( $post_id, $raw_links );
		if ( is_wp_error( $result ) ) {
			set_transient(
				'digitalogic_supplier_links_error_' . get_current_user_id(),
				$result->get_error_message(),
				MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Enqueue the repeater only on classic product editors.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! is_object( $screen ) || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'digitalogic-product-supplier-links',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/product-supplier-links-admin.css',
			array(),
			DIGITALOGIC_VERSION
		);
		wp_enqueue_script(
			'digitalogic-product-supplier-links',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/product-supplier-links-admin.js',
			array(),
			DIGITALOGIC_VERSION,
			true
		);
	}

	/**
	 * Display a redacted validation error after a product save redirect.
	 *
	 * @return void
	 */
	public function show_saved_error() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key     = 'digitalogic_supplier_links_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}

		delete_transient( $key );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Add a non-sensitive count/provider column for administrators.
	 *
	 * @param array $columns Existing product columns.
	 * @return array
	 */
	public function add_product_column( $columns ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $columns;
		}

		$result   = array();
		$inserted = false;
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$result['digitalogic_supplier_links'] = 'منابع خرید';
				$inserted                             = true;
			}
			$result[ $key ] = $label;
		}
		if ( ! $inserted ) {
			$result['digitalogic_supplier_links'] = 'منابع خرید';
		}

		return $result;
	}

	/**
	 * Render only a count and provider labels, never seller URLs.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public function render_product_column( $column, $post_id ) {
		if ( 'digitalogic_supplier_links' !== $column || ! $this->can_manage_product( $post_id ) ) {
			return;
		}

		$summary = Digitalogic_Product_Supplier_Links::instance()->get_summary( $post_id );
		if ( is_wp_error( $summary ) || 0 === $summary['count'] ) {
			echo '<span aria-label="بدون منبع خرید">—</span>';
			return;
		}

		$providers = array_map( array( $this, 'provider_label' ), $summary['providers'] );
		echo '<strong>' . esc_html( number_format_i18n( $summary['count'] ) . ' منبع' ) . '</strong>';
		echo '<br><span class="description">' . esc_html( implode( '، ', $providers ) ) . '</span>';
	}

	/**
	 * Render one server-escaped repeater card.
	 *
	 * @param string $index Row index or template marker.
	 * @param array  $link Stored link.
	 * @return void
	 */
	private function render_link_row( $index, $link ) {
		$link   = wp_parse_args(
			$link,
			array(
				'id'           => '',
				'marketplace'  => 'other',
				'site_name'    => '',
				'url'          => '',
				'source_title' => '',
				'seller'       => '',
				'seller_sku'   => '',
				'source'       => 'manual',
				'status'       => 'candidate',
				'note'         => '',
				'created_at'   => '',
				'last_checked' => '',
			)
		);
		$prefix = 'digitalogic_supplier_links[' . $index . ']';
		?>
		<fieldset class="digitalogic-supplier-link">
			<legend>منبع خرید خصوصی</legend>
			<input type="hidden" name="<?php echo esc_attr( $prefix . '[id]' ); ?>" value="<?php echo esc_attr( $link['id'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $prefix . '[created_at]' ); ?>" value="<?php echo esc_attr( $link['created_at'] ); ?>">
			<div class="digitalogic-supplier-link__grid">
				<label>
					<span>بازار یا پلتفرم</span>
					<select name="<?php echo esc_attr( $prefix . '[marketplace]' ); ?>">
						<?php foreach ( $this->marketplace_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php echo $value === $link['marketplace'] ? ' selected' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>نام سایت یا فروشنده</span>
					<input type="text" maxlength="120" name="<?php echo esc_attr( $prefix . '[site_name]' ); ?>" value="<?php echo esc_attr( $link['site_name'] ); ?>">
				</label>
				<label class="digitalogic-supplier-link__wide">
					<span>لینک کالا</span>
					<input type="url" dir="ltr" maxlength="4096" required name="<?php echo esc_attr( $prefix . '[url]' ); ?>" value="<?php echo esc_attr( $link['url'] ); ?>">
					<?php if ( '' !== $link['url'] ) : ?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer">باز کردن لینک</a>
					<?php endif; ?>
				</label>
				<label class="digitalogic-supplier-link__wide">
					<span>عنوان کالا در منبع</span>
					<input type="text" maxlength="240" name="<?php echo esc_attr( $prefix . '[source_title]' ); ?>" value="<?php echo esc_attr( $link['source_title'] ); ?>">
				</label>
				<label>
					<span>نام فروشنده</span>
					<input type="text" maxlength="160" name="<?php echo esc_attr( $prefix . '[seller]' ); ?>" value="<?php echo esc_attr( $link['seller'] ); ?>">
				</label>
				<label>
					<span>کد کالا نزد فروشنده</span>
					<input type="text" dir="ltr" maxlength="120" name="<?php echo esc_attr( $prefix . '[seller_sku]' ); ?>" value="<?php echo esc_attr( $link['seller_sku'] ); ?>">
				</label>
				<label>
					<span>منبع تطبیق</span>
					<select name="<?php echo esc_attr( $prefix . '[source]' ); ?>">
						<?php foreach ( $this->source_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php echo $value === $link['source'] ? ' selected' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>وضعیت</span>
					<select name="<?php echo esc_attr( $prefix . '[status]' ); ?>">
						<?php foreach ( $this->status_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"<?php echo $value === $link['status'] ? ' selected' : ''; ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>آخرین بررسی</span>
					<input type="date" dir="ltr" name="<?php echo esc_attr( $prefix . '[last_checked]' ); ?>" value="<?php echo esc_attr( $link['last_checked'] ); ?>">
				</label>
				<label class="digitalogic-supplier-link__wide">
					<span>یادداشت داخلی</span>
					<textarea rows="3" maxlength="1000" name="<?php echo esc_attr( $prefix . '[note]' ); ?>"><?php echo esc_textarea( $link['note'] ); ?></textarea>
				</label>
			</div>
			<p>
				<button type="button" class="button-link-delete digitalogic-supplier-link__remove">حذف این منبع</button>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Whether the current administrator can manage a product.
	 *
	 * @param int $post_id Product ID.
	 * @return bool
	 */
	private function can_manage_product( $post_id ) {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Marketplace labels.
	 *
	 * @return array
	 */
	private function marketplace_options() {
		return array(
			'taobao'         => 'تائوبائو',
			'1688'           => '۱۶۸۸',
			'tmall'          => 'تی‌مال',
			'alibaba'        => 'علی‌بابا',
			'aliexpress'     => 'علی‌اکسپرس',
			'iranian_market' => 'فروشگاه ایرانی',
			'other'          => 'سایر',
		);
	}

	/**
	 * Match-source labels.
	 *
	 * @return array
	 */
	private function source_options() {
		return array(
			'purchase_history' => 'سابقه خرید',
			'iranian_market'   => 'بررسی بازار ایران',
			'manual'           => 'ثبت دستی',
			'other'            => 'سایر',
		);
	}

	/**
	 * Review-state labels.
	 *
	 * @return array
	 */
	private function status_options() {
		return array(
			'candidate' => 'نیازمند بررسی',
			'matched'   => 'تطبیق داده‌شده',
			'purchased' => 'خریداری‌شده',
			'preferred' => 'منبع ترجیحی',
			'inactive'  => 'غیرفعال',
		);
	}

	/**
	 * Convert a stored provider token into a Persian list-table label.
	 *
	 * @param string $provider Provider token.
	 * @return string
	 */
	private function provider_label( $provider ) {
		if ( str_starts_with( $provider, 'iranian_market:' ) ) {
			return 'ایران: ' . sanitize_text_field( substr( $provider, strlen( 'iranian_market:' ) ) );
		}

		$options = $this->marketplace_options();

		return $options[ $provider ] ?? 'سایر';
	}
}
