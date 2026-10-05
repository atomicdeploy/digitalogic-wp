<?php
/**
 * Product authenticity and SMD-specific customer specifications.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Own the reviewed product fields from editor input through public schema. */
final class Digitalogic_Product_Specifications {

	public const AUTHENTICITY_META = '_digitalogic_authenticity';
	public const SMD_MARKING_META  = '_digitalogic_smd_marking';
	public const SMD_PACKAGE_META  = '_digitalogic_smd_package';

	/**
	 * Shared integration instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Return the shared integration instance. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register WooCommerce admin, storefront, and Product schema hooks. */
	private function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_admin_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_fields' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single_product_specs' ), 7 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_storefront_assets' ), 90 );
		add_filter( 'woocommerce_structured_data_product', array( $this, 'add_product_schema_properties' ), 20, 2 );
		add_filter( 'rank_math/snippet/rich_snippet_product_entity', array( $this, 'add_product_schema_properties' ), 20, 2 );
	}

	/** Render the product-level fields in the WooCommerce General tab. */
	public function render_admin_fields() {
		echo '<div class="options_group digitalogic-product-origin-fields">';
		woocommerce_wp_select(
			array(
				'id'          => self::AUTHENTICITY_META,
				'label'       => 'وضعیت اصالت قطعه',
				'description' => 'Original یا Copy بودن قطعه را مشخص کنید. مقدار خالی در سایت نمایش داده نمی‌شود.',
				'desc_tip'    => true,
				'options'     => array(
					''         => 'انتخاب نشده',
					'original' => 'Original (اصل)',
					'copy'     => 'Copy (کپی)',
				),
			)
		);
		echo '</div>';

		echo '<div class="options_group digitalogic-smd-fields" data-digitalogic-smd-fields hidden>';
		woocommerce_wp_text_input(
			array(
				'id'          => self::SMD_MARKING_META,
				'label'       => 'Marking روی قطعه',
				'description' => 'نوشتهٔ چاپ‌شده روی بدنهٔ قطعه؛ این مقدار الزاماً Part Number یا MPN نیست.',
				'desc_tip'    => true,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::SMD_PACKAGE_META,
				'label'       => 'Package قطعه',
				'description' => 'پکیج دقیق قطعه مانند SOT-23، SOIC-8، QFN-32 یا 0603.',
				'desc_tip'    => true,
			)
		);
		echo '</div>';
	}

	/**
	 * Persist reviewed product fields through the WooCommerce product object.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_product_fields( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$authenticity = isset( $_POST[ self::AUTHENTICITY_META ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product editor nonce before this hook.
			? sanitize_key( wp_unslash( $_POST[ self::AUTHENTICITY_META ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		if ( ! in_array( $authenticity, array( 'original', 'copy' ), true ) ) {
			$authenticity = '';
		}
		$this->set_or_delete_meta( $product, self::AUTHENTICITY_META, $authenticity );

		if ( ! $this->is_submitted_smd_product( $product ) ) {
			$product->delete_meta_data( self::SMD_MARKING_META );
			$product->delete_meta_data( self::SMD_PACKAGE_META );
			return;
		}

		$marking = isset( $_POST[ self::SMD_MARKING_META ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See the WooCommerce nonce note above.
			? sanitize_text_field( wp_unslash( $_POST[ self::SMD_MARKING_META ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$package = isset( $_POST[ self::SMD_PACKAGE_META ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See the WooCommerce nonce note above.
			? sanitize_text_field( wp_unslash( $_POST[ self::SMD_PACKAGE_META ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';
		$this->set_or_delete_meta( $product, self::SMD_MARKING_META, $marking );
		$this->set_or_delete_meta( $product, self::SMD_PACKAGE_META, $package );
	}

	/**
	 * Load category-aware visibility behavior only in the product editor.
	 *
	 * @param string $hook_suffix Current WordPress admin hook suffix.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== (string) $screen->post_type ) {
			return;
		}

		$path = DIGITALOGIC_PLUGIN_DIR . 'assets/js/product-specifications-admin.js';
		wp_enqueue_script(
			'digitalogic-product-specifications-admin',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/product-specifications-admin.js',
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : DIGITALOGIC_VERSION,
			true
		);
		wp_localize_script(
			'digitalogic-product-specifications-admin',
			'digitalogicProductSpecificationsAdmin',
			array( 'smdCategoryIds' => $this->get_smd_category_ids() )
		);
	}

	/** Enqueue the compact storefront presentation only on product pages. */
	public function enqueue_storefront_assets() {
		if ( is_admin() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$path = DIGITALOGIC_PLUGIN_DIR . 'assets/css/product-specifications.css';
		wp_enqueue_style(
			'digitalogic-product-specifications',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/product-specifications.css',
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : DIGITALOGIC_VERSION
		);
	}

	/** Display only populated, applicable specifications to customers. */
	public function render_single_product_specs() {
		$product = isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ? $GLOBALS['product'] : null;
		$specs   = $this->get_public_specifications( $product );
		if ( empty( $specs ) ) {
			return;
		}

		echo '<dl class="digitalogic-product-specifications" aria-label="مشخصات تکمیلی کالا">';
		foreach ( $specs as $spec ) {
			echo '<div class="digitalogic-product-specification">';
			echo '<dt>' . esc_html( $spec['label'] ) . '</dt>';
			echo '<dd dir="ltr">' . esc_html( $spec['value'] ) . '</dd>';
			echo '</div>';
		}
		echo '</dl>';
	}

	/**
	 * Add populated fields as schema.org PropertyValue entries.
	 *
	 * Marking deliberately remains separate from mpn because a package marking
	 * is not guaranteed to be the manufacturer's part number.
	 *
	 * @param array      $entity Existing Product entity.
	 * @param WC_Product $product Product supplied by WooCommerce or Rank Math.
	 * @return array
	 */
	public function add_product_schema_properties( $entity, $product = null ) {
		if ( ! is_array( $entity ) ) {
			return $entity;
		}
		if ( ! $product instanceof WC_Product && isset( $GLOBALS['product'] ) && $GLOBALS['product'] instanceof WC_Product ) {
			$product = $GLOBALS['product'];
		}

		foreach ( $this->get_public_specifications( $product ) as $spec ) {
			$entity = $this->append_schema_property( $entity, $spec['schema_name'], $spec['value'] );
		}

		return $entity;
	}

	/**
	 * Return customer-visible specifications in a stable display order.
	 *
	 * @param WC_Product|null $product Product candidate.
	 * @return array
	 */
	private function get_public_specifications( $product ) {
		$product = $this->get_metadata_product( $product );
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$specs        = array();
		$authenticity = sanitize_key( (string) $product->get_meta( self::AUTHENTICITY_META, true ) );
		$labels       = $this->authenticity_labels();
		if ( isset( $labels[ $authenticity ] ) ) {
			$specs[] = array(
				'label'       => 'وضعیت اصالت',
				'schema_name' => 'Authenticity / وضعیت اصالت',
				'value'       => $labels[ $authenticity ],
			);
		}

		if ( ! $this->is_smd_product( $product ) ) {
			return $specs;
		}

		$marking = sanitize_text_field( (string) $product->get_meta( self::SMD_MARKING_META, true ) );
		if ( '' !== $marking ) {
			$specs[] = array(
				'label'       => 'Marking',
				'schema_name' => 'SMD Marking / مارکینگ روی قطعه',
				'value'       => $marking,
			);
		}

		$package = sanitize_text_field( (string) $product->get_meta( self::SMD_PACKAGE_META, true ) );
		if ( '' !== $package ) {
			$specs[] = array(
				'label'       => 'Package',
				'schema_name' => 'SMD Package / پکیج قطعه',
				'value'       => $package,
			);
		}

		return $specs;
	}

	/**
	 * Product variations inherit these catalog-level fields from their parent.
	 *
	 * @param WC_Product|null $product Product candidate.
	 * @return WC_Product|null
	 */
	private function get_metadata_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( $product->is_type( 'variation' ) && $product->get_parent_id() > 0 ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof WC_Product ) {
				return $parent;
			}
		}

		return $product;
	}

	/**
	 * Keep schema entries list-shaped and avoid duplicate names.
	 *
	 * @param array  $entity Existing Product schema entity.
	 * @param string $name Property name.
	 * @param string $value Property value.
	 * @return array
	 */
	private function append_schema_property( $entity, $name, $value ) {
		$properties = $entity['additionalProperty'] ?? array();
		if ( isset( $properties['name'] ) || isset( $properties['@type'] ) ) {
			$properties = array( $properties );
		}
		if ( ! is_array( $properties ) ) {
			$properties = array();
		}
		foreach ( $properties as $property ) {
			if ( is_array( $property ) && isset( $property['name'] ) && (string) $property['name'] === (string) $name ) {
				$entity['additionalProperty'] = $properties;
				return $entity;
			}
		}

		$properties[]                 = array(
			'@type' => 'PropertyValue',
			'name'  => (string) $name,
			'value' => (string) $value,
		);
		$entity['additionalProperty'] = $properties;

		return $entity;
	}

	/**
	 * Update non-empty metadata and remove blank values.
	 *
	 * @param WC_Product $product Product being edited.
	 * @param string     $key Meta key.
	 * @param string     $value Sanitized meta value.
	 */
	private function set_or_delete_meta( $product, $key, $value ) {
		if ( '' === trim( (string) $value ) ) {
			$product->delete_meta_data( $key );
			return;
		}
		$product->update_meta_data( $key, $value );
	}

	/**
	 * Resolve whether the submitted final category selection includes SMD.
	 *
	 * @param WC_Product $product Product being edited.
	 * @return bool
	 */
	private function is_submitted_smd_product( $product ) {
		$category_ids = $product->get_category_ids();
		if ( isset( $_POST['tax_input']['product_cat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product editor nonce.
			$category_ids = array_map( 'absint', (array) wp_unslash( $_POST['tax_input']['product_cat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		return $this->category_ids_include_smd( $category_ids );
	}

	/**
	 * Check direct categories and their ancestors for the canonical SMD term.
	 *
	 * @param WC_Product $product Product candidate.
	 * @return bool
	 */
	private function is_smd_product( $product ) {
		return $product instanceof WC_Product && $this->category_ids_include_smd( $product->get_category_ids() );
	}

	/**
	 * Return true when any category is SMD or descends from SMD.
	 *
	 * @param array $category_ids Product category IDs.
	 * @return bool
	 */
	private function category_ids_include_smd( $category_ids ) {
		foreach ( array_map( 'absint', (array) $category_ids ) as $category_id ) {
			$seen = array();
			while ( $category_id > 0 && ! isset( $seen[ $category_id ] ) ) {
				$seen[ $category_id ] = true;
				$term                 = get_term( $category_id, 'product_cat' );
				if ( is_wp_error( $term ) || ! $term ) {
					break;
				}
				if ( $this->is_smd_term( $term ) ) {
					return true;
				}
				$category_id = absint( $term->parent ?? 0 );
			}
		}

		return false;
	}

	/** Resolve all SMD descendants so the admin UI updates immediately. */
	private function get_smd_category_ids() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( $this->category_ids_include_smd( array( $term->term_id ?? 0 ) ) ) {
				$ids[] = absint( $term->term_id );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Match only the deliberate SMD category identity.
	 *
	 * @param WP_Term|object $term Product category term.
	 * @return bool
	 */
	private function is_smd_term( $term ) {
		$slug = strtolower( trim( (string) ( $term->slug ?? '' ) ) );
		$name = strtoupper( trim( (string) ( $term->name ?? '' ) ) );

		return 'smd' === $slug || 'SMD' === $name;
	}

	/** Customer-facing values for the controlled authenticity dropdown. */
	private function authenticity_labels() {
		return array(
			'original' => 'Original',
			'copy'     => 'Copy',
		);
	}
}
