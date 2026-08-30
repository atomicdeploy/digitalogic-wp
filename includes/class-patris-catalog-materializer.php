<?php
/**
 * Patris catalog materialization from the validated product-sync state.
 *
 * The receiver uses the minimal identity-safe path for every source leaf. The
 * administrator-operated WP-CLI path adds reviewed taxonomy and Persian
 * enrichment without becoming a prerequisite for public source identity.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Patris_Catalog_Materializer {

	public const MANIFEST_SCHEMA = 'digitalogic.patris-catalog-enrichment';

	public const CATEGORY_CODE_META     = Digitalogic_Product_Category_Slugs::CATEGORY_CODE_META;
	public const CATEGORY_KEY_META      = '_digitalogic_catalog_category_key';
	public const CATEGORY_TERM_META     = '_digitalogic_patris_category_term_id';
	public const CATEGORY_MANAGED_META  = Digitalogic_Product_Category_Slugs::CATEGORY_MANAGED_META;
	public const CATEGORY_ADOPTED_META  = '_digitalogic_patris_category_adopted';
	public const OWNER_SOURCE_META      = '_digitalogic_patris_owner_source_id';
	public const OWNER_DATASET_META     = '_digitalogic_patris_owner_dataset';
	public const OWNER_CODE_META        = '_digitalogic_patris_owner_product_code';
	public const AUTO_MATERIALIZED_META = '_digitalogic_patris_auto_materialized';
	public const SOURCE_REVISION_META   = '_digitalogic_patris_source_revision';
	public const MISSING_FIELDS_META    = '_digitalogic_patris_materialization_missing_fields';

	private const SHIPPING_METHOD      = 'air_express';
	private const DOMESTIC_METHOD      = Digitalogic_Shipping_Method_Service::DOMESTIC_METHOD_ID;
	private const LOCK_NAME            = 'digitalogic_patris_catalog_materializer';
	private const LOCK_TIMEOUT_SECONDS = 10;
	private const MAX_MANIFEST_BYTES   = 8388608;
	private const MAX_DETAILS          = 100;

	private static $instance = null;

	/**
	 * Return the singleton service.
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
	 * Create the minimum safe WooCommerce leaf for one exact source record.
	 *
	 * This path deliberately does not invent enrichment, taxonomy, images, SEO,
	 * price, stock, weight, or freight. The receiver applies the canonical feed
	 * and calls commit_source_product() before the product becomes public.
	 *
	 * @param array $record Exact normalized receiver record.
	 * @param array $source Exact source identity.
	 * @param array $quarantined_codes Current source quarantine projection.
	 * @return array|WP_Error
	 */
	public function materialize_source_record( $record, $source, $quarantined_codes = array() ) {
		$identity = $this->source_record_identity( $record, $source );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		if ( ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() ) {
			return $this->error( 'digitalogic_patris_materializer_source_lock_required', 'Automatic materialization requires the shared source identity lock.' );
		}

		$code = $identity['product_code'];
		if ( $this->source_code_is_quarantined( $code, $quarantined_codes ) ) {
			return $this->identity_hazard( 'quarantined_source_code', $code );
		}

		$exact = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'patris_code' => $code ) );
		if ( ! is_wp_error( $exact ) ) {
			$valid = $this->validate_source_product_target( (int) $exact['woocommerce_id'], $source );
			return is_wp_error( $valid )
				? $valid
				: array(
					'woocommerce_id' => (int) $exact['woocommerce_id'],
					'created'        => false,
				);
		}
		if ( 'digitalogic_product_identifier_not_found' !== $exact->get_error_code() ) {
			return 'digitalogic_product_identifier_ambiguous' === $exact->get_error_code()
				? $this->identity_hazard( 'duplicate_patris_code', $code )
				: $exact;
		}

		$generic = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'code' => $code ) );
		if ( ! is_wp_error( $generic ) ) {
			return $this->identity_hazard( 'existing_sku_without_patris_code', $code );
		}
		if ( 'digitalogic_product_identifier_ambiguous' === $generic->get_error_code() ) {
			return $this->identity_hazard( 'split_or_duplicate_code_identity', $code );
		}
		if ( 'digitalogic_product_identifier_not_found' !== $generic->get_error_code() ) {
			return $generic;
		}

		$preflight = Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( 0, $code );
		if ( is_wp_error( $preflight ) ) {
			return 'digitalogic_product_code_source_not_unique' === $preflight->get_error_code()
				? $this->identity_hazard( $preflight->get_error_code(), $code )
				: $preflight;
		}

		$name    = trim( wp_strip_all_tags( (string) ( $record['name'] ?? '' ) ) );
		$product = $this->create_source_draft_shell( '' !== $name ? $name : $code );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$product_id = (int) $product->get_id();

		return $this->with_product_locks(
			array( $product_id ),
			function () use ( $product_id, $identity, $record, $source ) {
				if ( ! $this->source_write_locks_are_owned( array( $product_id ) ) ) {
					return $this->source_write_outcome_unknown( $product_id );
				}
				$code     = $identity['product_code'];
				$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'code' => $code ) );
				if ( ! is_wp_error( $resolved ) ) {
					$cause = $this->identity_hazard( 'identity_changed_during_creation', $code );
					return $this->rollback_failed_draft_locked( wc_get_product( $product_id ), $cause );
				}
				if ( 'digitalogic_product_identifier_ambiguous' === $resolved->get_error_code() ) {
					$cause = $this->identity_hazard( 'split_or_duplicate_code_identity', $code );
					return $this->rollback_failed_draft_locked( wc_get_product( $product_id ), $cause );
				}
				if ( 'digitalogic_product_identifier_not_found' !== $resolved->get_error_code() ) {
					return $this->rollback_failed_draft_locked( wc_get_product( $product_id ), $resolved );
				}
				$preflight = Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( $product_id, $code );
				if ( is_wp_error( $preflight ) ) {
					$cause = 'digitalogic_product_code_source_not_unique' === $preflight->get_error_code()
						? $this->identity_hazard( $preflight->get_error_code(), $code )
						: $preflight;
					return $this->rollback_failed_draft_locked(
						wc_get_product( $product_id ),
						$cause
					);
				}

				$this->flush_product_caches( $product_id );
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product || ! $product->is_type( 'simple' ) || $product->get_parent_id() > 0 ) {
					return $this->rollback_failed_draft_locked(
						$product,
						$this->identity_hazard( 'unsafe_created_product_type', $code )
					);
				}

				try {
					$product->set_sku( $code );
					$product->set_status( 'draft' );
					$product->set_catalog_visibility( 'hidden' );
					$product->set_regular_price( '' );
					$product->set_sale_price( '' );
					$product->set_price( '' );
					$product->set_manage_stock( true );
					$product->set_stock_quantity( 0 );
					$product->set_stock_status( 'outofstock' );
					$this->stage_managed_identity( $product, $code, $identity['source_id'], $identity['dataset'] );
					$product->update_meta_data( self::AUTO_MATERIALIZED_META, '1' );
					$product->update_meta_data( self::SOURCE_REVISION_META, $identity['source_revision'] );
					$saved = $this->save_managed_identity( $product );
				} catch ( Throwable $exception ) {
					$saved = $this->error( 'digitalogic_patris_materializer_create_failed', 'The safe source product identity could not be created.' );
				}
				if ( is_wp_error( $saved ) || ! $saved ) {
					$cause = is_wp_error( $saved ) ? $saved : $this->error( 'digitalogic_patris_materializer_create_failed', 'The safe source product identity could not be created.' );
					return $this->rollback_failed_draft_locked( $product, $cause );
				}

				$verified = Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_write( $product_id, $code );
				$valid    = $this->validate_source_product_target( $product_id, $source );
				if ( is_wp_error( $verified ) || is_wp_error( $valid ) ) {
					return $this->rollback_failed_draft_locked( $product, is_wp_error( $verified ) ? $verified : $valid );
				}

				return array(
					'woocommerce_id' => $product_id,
					'created'        => true,
				);
			}
		);
	}

	/**
	 * Refuse identity-corrupt, container, or conflicting-parent source targets.
	 *
	 * @param int   $product_id WooCommerce product or variation ID.
	 * @param array $source Exact source identity.
	 * @return true|WP_Error
	 */
	public function validate_source_product_target( $product_id, $source ) {
		$product_id = absint( $product_id );
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
		$source_id  = is_array( $source ) ? (string) ( $source['id'] ?? '' ) : '';
		$dataset    = is_array( $source ) ? (string) ( $source['dataset'] ?? '' ) : '';
		if ( ! $product instanceof WC_Product || '' === $source_id || '' === $dataset ) {
			return $this->identity_hazard( 'source_target_unavailable', '' );
		}
		if ( $product->is_type( 'variable' ) || ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) ) {
			return $this->identity_hazard( 'variable_or_unsupported_leaf_owner', (string) $product->get_sku() );
		}

		$readback = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $product_id );
		if ( is_wp_error( $readback ) ) {
			return $readback;
		}
		if (
			empty( $readback['product_exists'] )
			|| 'trash' === (string) ( $readback['post_status'] ?? '' )
			|| ! empty( $readback['duplicate_rows'] )
			|| ! empty( $readback['invalid_key_rows'] )
			|| empty( $readback['meta_exists'] )
		) {
			return $this->identity_hazard( 'invalid_or_duplicate_product_code_provenance', '' );
		}
		$code = (string) $readback['product_code'];
		if ( '' === $code || ( '' !== (string) $product->get_sku() && (string) $product->get_sku() !== $code ) ) {
			return $this->identity_hazard( 'split_code_and_sku', $code );
		}

		$owner_counts = is_array( $readback['owner_row_counts'] ?? null ) ? $readback['owner_row_counts'] : array();
		$owner        = is_array( $readback['owner'] ?? null ) ? $readback['owner'] : array();
		$owner_total  = array_sum( array_map( 'intval', $owner_counts ) );
		if ( 0 !== $owner_total ) {
			$owner_exact = 3 === $owner_total
				&& 1 === (int) ( $owner_counts['source_id'] ?? 0 )
				&& 1 === (int) ( $owner_counts['dataset'] ?? 0 )
				&& 1 === (int) ( $owner_counts['product_code'] ?? 0 )
				&& hash_equals( $source_id, (string) ( $owner['source_id'] ?? '' ) )
				&& hash_equals( $dataset, (string) ( $owner['dataset'] ?? '' ) )
				&& hash_equals( $code, (string) ( $owner['product_code'] ?? '' ) );
			if ( ! $owner_exact ) {
				return $this->identity_hazard( 'conflicting_or_incomplete_source_ownership', $code );
			}
		}

		$generic = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'code' => $code ) );
		if ( is_wp_error( $generic ) || (int) ( $generic['woocommerce_id'] ?? 0 ) !== $product_id ) {
			return is_wp_error( $generic ) && 'digitalogic_product_identifier_ambiguous' !== $generic->get_error_code()
				? $generic
				: $this->identity_hazard( 'split_or_duplicate_code_identity', $code );
		}

		if ( $product->is_type( 'simple' ) && $product->get_parent_id() > 0 ) {
			return $this->identity_hazard( 'simple_product_has_parent', $code );
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product || ! $parent->is_type( 'variable' ) ) {
				return $this->identity_hazard( 'variation_parent_invalid', $code );
			}
			$parent_readback = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $parent->get_id() );
			if ( is_wp_error( $parent_readback ) ) {
				return $parent_readback;
			}
			$parent_owners = is_array( $parent_readback ) && is_array( $parent_readback['owner_row_counts'] ?? null )
				? array_sum( array_map( 'intval', $parent_readback['owner_row_counts'] ) )
				: -1;
			if (
				! empty( $parent_readback['meta_exists'] )
				|| ! empty( $parent_readback['duplicate_rows'] )
				|| ! empty( $parent_readback['invalid_key_rows'] )
				|| 0 !== $parent_owners
			) {
				return $this->identity_hazard( 'conflicting_variable_parent_ownership', $code );
			}
		}

		return true;
	}

	/**
	 * Publish a verified source leaf and return its canonical warning snapshot.
	 *
	 * @param int   $product_id WooCommerce product or variation ID.
	 * @param array $record Exact normalized receiver record.
	 * @param array $source Exact source identity.
	 * @return array|WP_Error
	 */
	public function commit_source_product( $product_id, $record, $source ) {
		$product_id = absint( $product_id );
		$identity   = $this->source_record_identity( $record, $source );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		return $this->with_product_locks(
			array( $product_id ),
			function () use ( $product_id, $record, $source, $identity ) {
				if ( ! $this->source_write_locks_are_owned( array( $product_id ) ) ) {
					return $this->source_write_outcome_unknown( $product_id );
				}
				$valid = $this->validate_source_product_target( $product_id, $source );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$this->flush_product_caches( $product_id );
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product ) {
					return $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The source product is unavailable after its canonical feed write.' );
				}

				$missing = $this->canonical_missing_fields( $product, $record );
				try {
					if ( '' === (string) $product->get_sku() ) {
						$product->set_sku( $identity['product_code'] );
					}
					if ( '1' === (string) $product->get_meta( self::AUTO_MATERIALIZED_META, true ) ) {
						$name = trim( wp_strip_all_tags( (string) ( $record['name'] ?? '' ) ) );
						$product->set_name( '' !== $name ? $name : $identity['product_code'] );
					}
					$product->set_status( 'publish' );
					if ( ! $product->is_type( 'variation' ) ) {
						$product->set_catalog_visibility( 'visible' );
					}
					$product->update_meta_data( self::SOURCE_REVISION_META, $identity['source_revision'] );
					$product->update_meta_data( self::MISSING_FIELDS_META, wp_json_encode( $missing, JSON_UNESCAPED_SLASHES ) );
					if ( in_array( 'price', $missing, true ) ) {
						$product->set_regular_price( '' );
						$product->set_sale_price( '' );
						$product->set_price( '' );
						// WooCommerce derives stock status from managed quantity during
						// validation. A positive source quantity would therefore turn an
						// unpriced product back to "instock" while it is being published.
						// Preserve the exact source quantity in Patris metadata, but keep
						// operational Woo stock at zero until a canonical price arrives.
						if ( $product->get_manage_stock() && 0 !== (int) $product->get_stock_quantity() ) {
							$product->set_stock_quantity( 0 );
						}
						$product->set_stock_status( 'outofstock' );
					}
					if ( ! $product->save() ) {
						throw new RuntimeException( 'WooCommerce rejected the public source projection.' );
					}
				} catch ( Throwable $exception ) {
					return $this->error( 'digitalogic_patris_materializer_publication_failed', 'The verified source product could not be made public.' );
				}

				$this->flush_product_caches( $product_id );
				$fresh = wc_get_product( $product_id );
				if (
					! $fresh instanceof WC_Product
					|| 'publish' !== (string) $fresh->get_status()
					|| ( ! $fresh->is_type( 'variation' ) && 'visible' !== (string) $fresh->get_catalog_visibility() )
					|| ( in_array( 'price', $missing, true ) && ( '' !== trim( (string) $fresh->get_regular_price() ) || '' !== trim( (string) $fresh->get_price() ) || 'outofstock' !== (string) $fresh->get_stock_status() ) )
				) {
					return $this->error( 'digitalogic_patris_materializer_publication_readback_failed', 'The public source product failed exact readback.' );
				}

				return array(
					'product_id'      => $product_id,
					'product_code'    => $identity['product_code'],
					'name'            => (string) $fresh->get_name(),
					'source_id'       => $identity['source_id'],
					'dataset'         => $identity['dataset'],
					'source_revision' => $identity['source_revision'],
					'missing_fields'  => $missing,
					'visible'         => true,
					'purchasable'     => ! in_array( 'price', $missing, true ) && 'outofstock' !== (string) $fresh->get_stock_status(),
					'price_status'    => (string) $fresh->get_meta( '_digitalogic_patris_price_status', true ),
				);
			}
		);
	}

	/**
	 * Read and validate an administrator-reviewed enrichment manifest.
	 *
	 * @param string $path Absolute or working-directory-relative JSON path.
	 * @return array|WP_Error
	 */
	public function load_manifest_file( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $this->error(
				'digitalogic_patris_materializer_manifest_unreadable',
				'The enrichment manifest is not a readable file.'
			);
		}

		$size = filesize( $path );
		if ( false === $size || $size <= 0 || $size > self::MAX_MANIFEST_BYTES ) {
			return $this->error(
				'digitalogic_patris_materializer_manifest_size',
				'The enrichment manifest is empty or exceeds the 8 MiB limit.'
			);
		}

		$json = file_get_contents( $path );
		if ( false === $json ) {
			return $this->error(
				'digitalogic_patris_materializer_manifest_unreadable',
				'The enrichment manifest could not be read.'
			);
		}

		try {
			$manifest = Digitalogic_Product_Sync_JSON_Decoder::decode( $json );
		} catch ( Throwable $exception ) {
			return $this->error(
				'digitalogic_patris_materializer_manifest_json_invalid',
				'The enrichment manifest is not strict valid JSON.'
			);
		}

		return $this->validate_manifest( $manifest );
	}

	/**
	 * Strictly validate and normalize one enrichment manifest.
	 *
	 * Product rows deliberately contain explicit target ownership. A null
	 * target_product_id means create one new simple draft. A string ID means
	 * adopt exactly that reviewed simple product or variation. Variations must
	 * also provide their exact target_parent_id.
	 *
	 * @param mixed $manifest Decoded manifest.
	 * @return array|WP_Error
	 */
	public function validate_manifest( $manifest ) {
		if ( ! is_array( $manifest ) || array_is_list( $manifest ) ) {
			return $this->manifest_error( 'root', 'must be an object' );
		}

		$required = array( 'schema', 'source', 'products', 'categories' );
		$allowed  = array_merge( $required, array( 'source_revision' ) );
		$shape    = $this->validate_object_shape( $manifest, $required, $allowed, 'root' );
		if ( is_wp_error( $shape ) ) {
			return $shape;
		}
		if ( self::MANIFEST_SCHEMA !== $manifest['schema'] ) {
			return $this->manifest_error( 'schema', 'must identify the living enrichment manifest' );
		}
		if ( ! is_array( $manifest['source'] ) || array_is_list( $manifest['source'] ) ) {
			return $this->manifest_error( 'source', 'must be an object' );
		}
		$source_shape = $this->validate_object_shape(
			$manifest['source'],
			array( 'id', 'dataset' ),
			array( 'id', 'dataset' ),
			'source'
		);
		if ( is_wp_error( $source_shape ) ) {
			return $source_shape;
		}
		foreach ( array( 'id', 'dataset' ) as $field ) {
			if ( ! is_string( $manifest['source'][ $field ] ) || trim( $manifest['source'][ $field ] ) !== $manifest['source'][ $field ] || '' === $manifest['source'][ $field ] ) {
				return $this->manifest_error( 'source.' . $field, 'must be a non-empty trimmed string' );
			}
		}
		if ( isset( $manifest['source_revision'] ) && ( ! is_string( $manifest['source_revision'] ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/', $manifest['source_revision'] ) ) ) {
			return $this->manifest_error( 'source_revision', 'must be a lowercase sha256 identity when supplied' );
		}

		$source_revision = isset( $manifest['source_revision'] ) ? (string) $manifest['source_revision'] : '';
		$products        = $this->validate_manifest_products( $manifest['products'], $source_revision );
		if ( is_wp_error( $products ) ) {
			return $products;
		}
		$categories = $this->validate_manifest_categories( $manifest['categories'] );
		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		$normalized               = array(
			'schema' => self::MANIFEST_SCHEMA,
		);
		$normalized['source']     = $manifest['source'];
		$normalized['products']   = $products;
		$normalized['categories'] = $categories;
		if ( isset( $manifest['source_revision'] ) ) {
			$normalized['source_revision'] = $manifest['source_revision'];
		}

		return $normalized;
	}

	/**
	 * Build a dry-run plan or apply it when explicitly requested.
	 *
	 * @param array $manifest Validated or raw decoded manifest.
	 * @param array $options  apply, publish_ready, limit, and codes.
	 * @return array|WP_Error
	 */
	public function run( $manifest, $options = array() ) {
		$manifest = $this->validate_manifest( $manifest );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$options       = wp_parse_args(
			is_array( $options ) ? $options : array(),
			array(
				'apply'         => false,
				'publish_ready' => false,
				'limit'         => 0,
				'codes'         => array(),
			)
		);
		$apply         = true === $options['apply'];
		$publish_ready = true === $options['publish_ready'];
		$limit         = $this->normalize_limit( $options['limit'] );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$codes = $this->normalize_code_filter( $options['codes'] );
		if ( is_wp_error( $codes ) ) {
			return $codes;
		}

		$source_id    = $manifest['source']['id'];
		$dataset      = $manifest['source']['dataset'];
		$source_state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( $source_id, $dataset );
		if ( empty( $source_state ) ) {
			return $this->error(
				'digitalogic_patris_materializer_source_not_found',
				'The exact product-sync source in the manifest was not found.'
			);
		}
		if (
			empty( $source_state['source']['revision'] )
			|| ! is_array( $source_state['categories'] ?? null )
			|| ! is_array( $source_state['excluded_codes'] ?? null )
		) {
			return $this->error(
				'digitalogic_patris_materializer_catalog_projection_required',
				'A current living product-sync baseline with catalog projections is required before materialization.'
			);
		}
		if (
			isset( $manifest['source_revision'] )
			&& ! hash_equals( (string) ( $source_state['source']['revision'] ?? '' ), $manifest['source_revision'] )
		) {
			return $this->error(
				'digitalogic_patris_materializer_source_revision_changed',
				'The Patris source changed after this manifest was reviewed.'
			);
		}

		$products = is_array( $source_state['products'] ?? null ) ? $source_state['products'] : array();
		$selected = array();
		foreach ( $manifest['products'] as $code => $enrichment ) {
			$code = (string) $code;
			if ( ! isset( $products[ $code ] ) || ! is_array( $products[ $code ] ) ) {
				continue;
			}
			if ( ! empty( $codes ) && ! isset( $codes[ $code ] ) ) {
				continue;
			}
			$selected[ $code ] = $enrichment;
		}
		ksort( $selected, SORT_STRING );
		if ( $limit > 0 ) {
			$selected = array_slice( $selected, 0, $limit, true );
		}

		$selected_positive_stock = 0;
		foreach ( array_keys( $selected ) as $selected_code ) {
			if ( $this->number( $products[ $selected_code ]['total_stock'] ?? null ) > 0 ) {
				++$selected_positive_stock;
			}
		}
		$result = $this->new_result( $source_state, $manifest, $apply, $publish_ready, count( $selected ), $selected_positive_stock );
		if ( empty( $selected ) ) {
			return $result;
		}

		$locked                 = false;
		$source_identity_locked = false;
		if ( $apply ) {
			$source_identity_locked = Digitalogic_Product_Sync_Receiver::instance()->acquire_source_identity_lock( 0 );
			if ( is_wp_error( $source_identity_locked ) ) {
				return $source_identity_locked;
			}
			$locked = $this->acquire_lock();
			if ( is_wp_error( $locked ) ) {
				Digitalogic_Product_Sync_Receiver::instance()->release_source_identity_lock();
				return $locked;
			}
		}

		try {
			if ( $apply ) {
				$current_state = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( $source_id, $dataset );
				if (
					empty( $current_state )
					|| ! hash_equals(
						(string) ( $source_state['source']['revision'] ?? '' ),
						(string) ( $current_state['source']['revision'] ?? '' )
					)
				) {
					return $this->error(
						'digitalogic_patris_materializer_source_changed_during_apply',
						'The Patris source changed while the reviewed apply was starting.'
					);
				}
				$source_state = $current_state;
				$products     = is_array( $current_state['products'] ?? null ) ? $current_state['products'] : array();
			}

			if ( $apply ) {
				foreach ( $selected as $code => $enrichment ) {
					$target_id = null !== $enrichment['target_product_id'] ? (int) $enrichment['target_product_id'] : 0;
					if ( 0 === $target_id ) {
						$existing = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'code' => (string) $code ) );
						if ( ! is_wp_error( $existing ) ) {
							$target_id = (int) ( $existing['woocommerce_id'] ?? 0 );
						}
					}
					$preflight = Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( $target_id, (string) $code );
					if ( is_wp_error( $preflight ) ) {
						$this->append_detail( $result, (string) $code, $preflight->get_error_code() );
						++$result['skipped'];
						unset( $selected[ $code ] );
					}
				}
				if ( empty( $selected ) ) {
					return $result;
				}
			}

			$category_result      = $this->reconcile_categories( $source_state, $manifest, $selected, $apply );
			$result['categories'] = $category_result['summary'];

			foreach ( $selected as $code => $enrichment ) {
				$code   = (string) $code;
				$record = $products[ $code ];
				if ( (string) $record['name'] !== $enrichment['patris_name'] ) {
					$this->append_detail( $result, $code, 'patris_name_changed' );
					++$result['skipped'];
					continue;
				}

				$category_selection = $this->resolve_product_category( $record, $enrichment, $category_result );
				$category_code      = $category_selection['category_code'];
				$category_term      = $category_selection['term_id'];
				$category_available = $category_selection['available'];
				if ( ! $category_available || ( $apply && $category_term <= 0 ) ) {
					$this->append_detail( $result, $code, 'category_unavailable' );
					++$result['skipped'];
					continue;
				}

				$target = $this->resolve_manifest_target( $code, $enrichment, $source_id, $dataset );
				if ( is_wp_error( $target ) ) {
					$this->append_detail( $result, $code, $target->get_error_code() );
					++$result['skipped'];
					continue;
				}

				if ( ! $apply ) {
					++$result[ $target['action'] ];
					continue;
				}

				$row = $this->apply_materializer_row_transaction(
					$target,
					$code,
					$source_id,
					$dataset,
					$record,
					$enrichment,
					$category_term,
					$category_code,
					$publish_ready
				);
				if ( is_wp_error( $row ) ) {
					$this->append_detail( $result, $code, $row->get_error_code() );
					$error_data = $row->get_error_data();
					if ( is_array( $error_data ) && ( ! empty( $error_data['effect_attempted'] ) || ! empty( $error_data['apply_attempted'] ) ) ) {
						++$result['failed'];
					} else {
						++$result['skipped'];
					}
					if ( is_array( $error_data ) && ! empty( $error_data['preserved_published'] ) ) {
						++$result['preserved_published'];
					}
					continue;
				}
				$this->merge_materializer_row_result( $result, $code, $row );
			}

			if ( $apply ) {
				if ( function_exists( 'clean_term_cache' ) && ! empty( $category_result['term_ids'] ) ) {
					clean_term_cache( array_values( array_filter( $category_result['term_ids'] ) ), 'product_cat' );
				}
				$receiver = Digitalogic_Product_Sync_Receiver::instance()->reconcile( $source_id, $dataset );
				if ( is_wp_error( $receiver ) ) {
					$result['receiver_reconciliation'] = array(
						'status' => 'error',
						'code'   => $receiver->get_error_code(),
					);
				} else {
					$result['receiver_reconciliation'] = array(
						'status'            => (string) ( $receiver['status'] ?? '' ),
						'pending_products'  => (int) ( $receiver['pending_products'] ?? 0 ),
						'deferred_products' => (int) ( $receiver['deferred_products'] ?? 0 ),
					);
				}
				$this->invalidate_sitemap_cache();
			}
		} finally {
			if ( $locked ) {
				$this->release_lock();
			}
			if ( $source_identity_locked ) {
				Digitalogic_Product_Sync_Receiver::instance()->release_source_identity_lock();
			}
		}

		return $result;
	}

	/**
	 * Validate product rows and explicit target ownership.
	 *
	 * @param mixed  $rows            Manifest products object.
	 * @param string $source_revision Reviewed source revision, when pinned.
	 * @return array|WP_Error
	 */
	private function validate_manifest_products( $rows, $source_revision ) {
		if ( ! is_array( $rows ) || array_is_list( $rows ) ) {
			return $this->manifest_error( 'products', 'must be an object keyed by exact Patris Code' );
		}

		$fields             = array(
			'patris_name',
			'target_product_id',
			'target_parent_id',
			'convert_empty_variable_to_simple',
			'attribute_taxonomy',
			'attribute_term_id',
			'category_override',
			'parent_enrichment',
			'variation_group',
			'name_fa',
			'short_description_fa',
			'seo_title_fa',
			'seo_description_fa',
			'focus_keyword_fa',
			'part_number',
			'model',
		);
		$normalized         = array();
		$target_ids         = array();
		$parent_enrichments = array();
		$parent_groups      = array();
		foreach ( $rows as $code => $row ) {
			$code = (string) $code;
			$path = 'products.' . $code;
			if ( ! $this->valid_code( $code ) ) {
				return $this->manifest_error( $path, 'has an invalid Patris Code key' );
			}
			if ( ! is_array( $row ) || array_is_list( $row ) ) {
				return $this->manifest_error( $path, 'must be an object' );
			}
			$shape = $this->validate_object_shape( $row, $fields, $fields, $path );
			if ( is_wp_error( $shape ) ) {
				return $shape;
			}
			foreach ( array( 'patris_name', 'attribute_taxonomy', 'variation_group', 'name_fa', 'short_description_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa', 'part_number', 'model' ) as $field ) {
				if ( ! is_string( $row[ $field ] ) ) {
					return $this->manifest_error( $path . '.' . $field, 'must be a string' );
				}
			}
			if ( '' === trim( $row['patris_name'] ) || trim( $row['patris_name'] ) !== $row['patris_name'] ) {
				return $this->manifest_error( $path . '.patris_name', 'must be the exact non-empty Patris name' );
			}
			foreach ( array( 'name_fa', 'short_description_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' ) as $field ) {
				if ( '' === trim( wp_strip_all_tags( $row[ $field ] ) ) || ! $this->contains_persian( wp_strip_all_tags( $row[ $field ] ) ) ) {
					return $this->manifest_error( $path . '.' . $field, 'must contain reviewed Persian text' );
				}
			}
			$target_id = $this->canonical_id_or_null( $row['target_product_id'] );
			if ( is_wp_error( $target_id ) ) {
				return $this->manifest_error( $path . '.target_product_id', 'must be a canonical positive integer string or null' );
			}
			$parent_id = $this->canonical_id_or_null( $row['target_parent_id'] );
			if ( is_wp_error( $parent_id ) ) {
				return $this->manifest_error( $path . '.target_parent_id', 'must be a canonical positive integer string or null' );
			}
			$attribute_term_id = $this->canonical_id_or_null( $row['attribute_term_id'] );
			if ( is_wp_error( $attribute_term_id ) ) {
				return $this->manifest_error( $path . '.attribute_term_id', 'must be a canonical positive integer string or null' );
			}
			if ( ! is_bool( $row['convert_empty_variable_to_simple'] ) ) {
				return $this->manifest_error( $path . '.convert_empty_variable_to_simple', 'must be a boolean' );
			}
			$attribute_taxonomy = trim( $row['attribute_taxonomy'] );
			if ( $attribute_taxonomy !== $row['attribute_taxonomy'] ) {
				return $this->manifest_error( $path . '.attribute_taxonomy', 'must be trimmed' );
			}
			$is_new_variation = null === $target_id && null !== $parent_id;
			if ( $is_new_variation ) {
				if ( $row['convert_empty_variable_to_simple'] ) {
					return $this->manifest_error( $path, 'cannot request both variation creation and variable conversion' );
				}
				if ( ! preg_match( '/^pa_[a-z0-9_-]+$/', $attribute_taxonomy ) || null === $attribute_term_id ) {
					return $this->manifest_error( $path, 'new variations require an exact pa_* taxonomy and attribute_term_id' );
				}
			} elseif ( '' !== $attribute_taxonomy || null !== $attribute_term_id ) {
				return $this->manifest_error( $path, 'variation attribute fields are only valid when creating a reviewed child' );
			}
			if ( $row['convert_empty_variable_to_simple'] && ( null === $target_id || null !== $parent_id ) ) {
				return $this->manifest_error( $path, 'empty-variable conversion requires one exact target_product_id and no parent' );
			}
			$category_override = $this->validate_category_override(
				$row['category_override'],
				$path . '.category_override',
				$row['name_fa'],
				$source_revision
			);
			if ( is_wp_error( $category_override ) ) {
				return $category_override;
			}
			$parent_enrichment = $this->validate_parent_enrichment( $row['parent_enrichment'], $path . '.parent_enrichment' );
			if ( is_wp_error( $parent_enrichment ) ) {
				return $parent_enrichment;
			}
			if ( ( null !== $parent_id ) !== ( null !== $parent_enrichment ) ) {
				return $this->manifest_error( $path . '.parent_enrichment', 'must be supplied exactly for a variation child' );
			}
			if ( null !== $parent_id ) {
				if ( '' === trim( $row['variation_group'] ) || trim( $row['variation_group'] ) !== $row['variation_group'] ) {
					return $this->manifest_error( $path . '.variation_group', 'must be a non-empty trimmed group identifier for a variation child' );
				}
				$fingerprint = wp_json_encode( $parent_enrichment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( isset( $parent_enrichments[ $parent_id ] ) && $parent_enrichments[ $parent_id ] !== $fingerprint ) {
					return $this->manifest_error( $path . '.parent_enrichment', 'must match every other row for the same variable parent' );
				}
				$parent_enrichments[ $parent_id ] = $fingerprint;
				if ( isset( $parent_groups[ $parent_id ] ) && $parent_groups[ $parent_id ] !== $row['variation_group'] ) {
					return $this->manifest_error( $path . '.variation_group', 'must match every other row for the same variable parent' );
				}
				$parent_groups[ $parent_id ] = $row['variation_group'];
			} elseif ( '' !== $row['variation_group'] ) {
				return $this->manifest_error( $path . '.variation_group', 'must be empty for a simple product or conversion' );
			}
			if ( null !== $target_id ) {
				if ( isset( $target_ids[ $target_id ] ) ) {
					return $this->manifest_error( $path . '.target_product_id', 'is already claimed by another manifest row' );
				}
				$target_ids[ $target_id ] = $code;
			}

			$row['target_product_id'] = $target_id;
			$row['target_parent_id']  = $parent_id;
			$row['attribute_term_id'] = $attribute_term_id;
			$row['category_override'] = $category_override;
			$row['parent_enrichment'] = $parent_enrichment;
			$normalized[ $code ]      = $row;
		}
		ksort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Validate category translation/SEO rows.
	 *
	 * @param mixed $rows Manifest categories object.
	 * @return array|WP_Error
	 */
	private function validate_manifest_categories( $rows ) {
		if ( ! is_array( $rows ) || ( ! empty( $rows ) && array_is_list( $rows ) ) ) {
			return $this->manifest_error( 'categories', 'must be an object keyed by exact Patris category Code' );
		}

		$fields          = array( 'patris_name', 'target_term_id', 'rename', 'parent_category_code', 'target_parent_term_id', 'name_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' );
		$result          = array();
		$target_term_ids = array();
		foreach ( $rows as $code => $row ) {
			$code = (string) $code;
			$path = 'categories.' . $code;
			if ( ! $this->valid_code( $code ) || ! is_array( $row ) || array_is_list( $row ) ) {
				return $this->manifest_error( $path, 'must be an object under a valid category Code' );
			}
			$shape = $this->validate_object_shape( $row, $fields, $fields, $path );
			if ( is_wp_error( $shape ) ) {
				return $shape;
			}
			foreach ( array( 'patris_name', 'name_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' ) as $field ) {
				if ( ! is_string( $row[ $field ] ) ) {
					return $this->manifest_error( $path . '.' . $field, 'must be a string' );
				}
			}
			$is_synthetic = str_starts_with( $code, 'digitalogic:' );
			if ( ( $is_synthetic && '' !== $row['patris_name'] ) || ( ! $is_synthetic && '' === trim( $row['patris_name'] ) ) ) {
				return $this->manifest_error( $path, 'must contain the correct source-name mode and a reviewed Persian name' );
			}
			foreach ( array( 'name_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' ) as $field ) {
				if ( '' === trim( wp_strip_all_tags( $row[ $field ] ) ) || ! $this->contains_persian( wp_strip_all_tags( $row[ $field ] ) ) ) {
					return $this->manifest_error( $path . '.' . $field, 'must contain reviewed Persian text' );
				}
			}
			$target_term_id = $this->canonical_id_or_null( $row['target_term_id'] );
			if ( is_wp_error( $target_term_id ) ) {
				return $this->manifest_error( $path . '.target_term_id', 'must be a canonical positive integer string or null' );
			}
			if ( ! is_bool( $row['rename'] ) ) {
				return $this->manifest_error( $path . '.rename', 'must be a boolean' );
			}
			$parent_category_code = $row['parent_category_code'];
			if ( null !== $parent_category_code && ( ! is_string( $parent_category_code ) || ! $this->valid_code( $parent_category_code ) || $parent_category_code === $code ) ) {
				return $this->manifest_error( $path . '.parent_category_code', 'must be a distinct exact category key or null' );
			}
			$target_parent_term_id = $this->canonical_id_or_null( $row['target_parent_term_id'] );
			if ( is_wp_error( $target_parent_term_id ) ) {
				return $this->manifest_error( $path . '.target_parent_term_id', 'must be a canonical positive integer string or null' );
			}
			if ( null !== $parent_category_code && null !== $target_parent_term_id ) {
				return $this->manifest_error( $path, 'cannot select both parent_category_code and target_parent_term_id' );
			}
			if ( ! $is_synthetic && ( null !== $parent_category_code || null !== $target_parent_term_id ) ) {
				return $this->manifest_error( $path, 'source categories must retain the validated Patris parent relationship' );
			}
			if ( null !== $target_term_id ) {
				if ( isset( $target_term_ids[ $target_term_id ] ) ) {
					return $this->manifest_error( $path . '.target_term_id', 'is already claimed by another category row' );
				}
				$target_term_ids[ $target_term_id ] = $code;
			}
			$row['target_term_id']        = $target_term_id;
			$row['target_parent_term_id'] = $target_parent_term_id;
			$result[ $code ]              = $row;
		}
		ksort( $result, SORT_STRING );

		return $result;
	}

	/**
	 * Validate an optional reviewed product-specific category override.
	 *
	 * @param mixed  $override        Raw override.
	 * @param string $path            Manifest path.
	 * @param string $product_name_fa Exact reviewed product title.
	 * @param string $source_revision Reviewed manifest source revision.
	 * @return array|null|WP_Error
	 */
	private function validate_category_override( $override, $path, $product_name_fa, $source_revision ) {
		if ( null === $override ) {
			return null;
		}
		if ( ! is_array( $override ) || array_is_list( $override ) ) {
			return $this->manifest_error( $path, 'must be null or an object' );
		}
		$shape = $this->validate_object_shape(
			$override,
			array( 'category_code', 'target_term_id', 'approved_name_fa', 'approved_source_revision', 'evidence_urls' ),
			array( 'category_code', 'target_term_id', 'approved_name_fa', 'approved_source_revision', 'evidence_urls' ),
			$path
		);
		if ( is_wp_error( $shape ) ) {
			return $shape;
		}
		$category_code = $override['category_code'];
		if ( null !== $category_code && ( ! is_string( $category_code ) || ! $this->valid_code( $category_code ) ) ) {
			return $this->manifest_error( $path . '.category_code', 'must be an exact category key or null' );
		}
		$target_term_id = $this->canonical_id_or_null( $override['target_term_id'] );
		if ( is_wp_error( $target_term_id ) ) {
			return $this->manifest_error( $path . '.target_term_id', 'must be a canonical positive integer string or null' );
		}
		if ( ( null === $category_code ) === ( null === $target_term_id ) ) {
			return $this->manifest_error( $path, 'must select exactly one category_code or target_term_id' );
		}
		if ( '' === $source_revision ) {
			return $this->manifest_error( $path . '.approved_source_revision', 'requires a pinned root source_revision' );
		}
		$approved_source_revision = $override['approved_source_revision'];
		if ( ! is_string( $approved_source_revision ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/', $approved_source_revision ) ) {
			return $this->manifest_error( $path . '.approved_source_revision', 'must be a lowercase sha256 identity' );
		}
		if ( ! hash_equals( $source_revision, $approved_source_revision ) ) {
			return $this->manifest_error( $path . '.approved_source_revision', 'must match the pinned root source_revision' );
		}
		$approved_name_fa = $override['approved_name_fa'];
		if (
			! is_string( $approved_name_fa )
			|| '' === trim( wp_strip_all_tags( $approved_name_fa ) )
			|| trim( $approved_name_fa ) !== $approved_name_fa
			|| ! $this->contains_persian( wp_strip_all_tags( $approved_name_fa ) )
		) {
			return $this->manifest_error( $path . '.approved_name_fa', 'must be an exact reviewed Persian title' );
		}
		if ( ! hash_equals( $approved_name_fa, $product_name_fa ) ) {
			return $this->manifest_error( $path . '.approved_name_fa', 'must exactly match the product name_fa' );
		}
		if ( ! is_array( $override['evidence_urls'] ) || ! array_is_list( $override['evidence_urls'] ) || empty( $override['evidence_urls'] ) ) {
			return $this->manifest_error( $path . '.evidence_urls', 'must be a non-empty list of HTTPS references' );
		}
		$evidence_urls = array();
		foreach ( $override['evidence_urls'] as $index => $url ) {
			if (
				! is_string( $url )
				|| trim( $url ) !== $url
				|| false === filter_var( $url, FILTER_VALIDATE_URL )
				|| 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) )
				|| '' === (string) wp_parse_url( $url, PHP_URL_HOST )
			) {
				return $this->manifest_error( $path . '.evidence_urls.' . $index, 'must be a trimmed HTTPS URL' );
			}
			$evidence_urls[] = $url;
		}
		if ( count( array_unique( $evidence_urls ) ) !== count( $evidence_urls ) ) {
			return $this->manifest_error( $path . '.evidence_urls', 'must not contain duplicate references' );
		}

		return array(
			'category_code'            => $category_code,
			'target_term_id'           => $target_term_id,
			'approved_name_fa'         => $approved_name_fa,
			'approved_source_revision' => $approved_source_revision,
			'evidence_urls'            => $evidence_urls,
		);
	}

	/**
	 * Validate code-less variable-parent enrichment for reviewed children.
	 *
	 * @return array|null|WP_Error
	 */
	private function validate_parent_enrichment( $enrichment, $path ) {
		if ( null === $enrichment ) {
			return null;
		}
		if ( ! is_array( $enrichment ) || array_is_list( $enrichment ) ) {
			return $this->manifest_error( $path, 'must be null or an object' );
		}
		$fields = array( 'patris_family_name', 'name_fa', 'short_description_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' );
		$shape  = $this->validate_object_shape( $enrichment, $fields, $fields, $path );
		if ( is_wp_error( $shape ) ) {
			return $shape;
		}
		foreach ( $fields as $field ) {
			if ( ! is_string( $enrichment[ $field ] ) ) {
				return $this->manifest_error( $path . '.' . $field, 'must be a string' );
			}
		}
		if ( '' === trim( $enrichment['patris_family_name'] ) ) {
			return $this->manifest_error( $path . '.patris_family_name', 'must be a reviewed non-empty family label' );
		}
		foreach ( array( 'name_fa', 'short_description_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' ) as $field ) {
			if ( '' === trim( wp_strip_all_tags( $enrichment[ $field ] ) ) || ! $this->contains_persian( wp_strip_all_tags( $enrichment[ $field ] ) ) ) {
				return $this->manifest_error( $path . '.' . $field, 'must contain reviewed Persian text' );
			}
		}

		return $enrichment;
	}

	/**
	 * Reconcile the selected category branches in parent-first order.
	 *
	 * @param array $source_state Receiver source state.
	 * @param array $manifest Enrichment manifest.
	 * @param array $product_codes Selected product codes.
	 * @param bool  $apply Whether writes are authorized.
	 * @return array
	 */
	private function reconcile_categories( $source_state, $manifest, $selected, $apply ) {
		$source_categories = is_array( $source_state['categories'] ?? null ) ? $source_state['categories'] : array();
		$products          = is_array( $source_state['products'] ?? null ) ? $source_state['products'] : array();
		$needed            = array();
		$missing           = array();
		foreach ( $selected as $product_code => $enrichment ) {
			$override = $enrichment['category_override'];
			if ( is_array( $override ) && null !== $override['target_term_id'] ) {
				continue;
			}
			$code = is_array( $override )
				? (string) $override['category_code']
				: (string) ( $products[ $product_code ]['category_code'] ?? '' );
			$this->collect_needed_category( $code, $source_categories, $manifest['categories'], $needed, $missing );
		}

		$term_ids  = array();
		$available = array();
		$summary   = array(
			'needed'           => count( $needed ),
			'planned_create'   => 0,
			'created'          => 0,
			'adopted'          => 0,
			'updated'          => 0,
			'already_mapped'   => 0,
			'preserved_manual' => 0,
			'failed'           => count( $missing ),
		);
		ksort( $needed, SORT_STRING );
		$remaining = $needed;
		do {
			$progress = false;
			foreach ( $remaining as $code => $definition ) {
				$parent_code = (string) $definition['parent_code'];
				if ( '' !== $parent_code && empty( $available[ $parent_code ] ) ) {
					continue;
				}
				$parent_id = '' === $parent_code ? (int) $definition['target_parent_term_id'] : (int) ( $term_ids[ $parent_code ] ?? 0 );
				if ( $definition['target_parent_term_id'] > 0 ) {
					$parent_term = get_term( (int) $definition['target_parent_term_id'], 'product_cat' );
					if ( is_wp_error( $parent_term ) || ! is_object( $parent_term ) ) {
						++$summary['failed'];
						unset( $remaining[ $code ] );
						$progress = true;
						continue;
					}
				}

				if ( 'synthetic' === $definition['kind'] ) {
					$mapped = $this->reconcile_synthetic_category_term( $code, $definition['enrichment'], $parent_id, $manifest['source'], $apply );
				} else {
					$category   = $definition['record'];
					$enrichment = $definition['enrichment'];
					if ( is_array( $enrichment ) && (string) $category['name'] !== $enrichment['patris_name'] ) {
						$mapped = $this->error( 'digitalogic_patris_materializer_category_name_changed', 'A Patris category name changed after review.' );
					} else {
						$name   = is_array( $enrichment ) ? $enrichment['name_fa'] : (string) $category['name'];
						$mapped = $this->contains_persian( $name )
							? $this->reconcile_category_term( $category, $name, $parent_id, $enrichment, $manifest['source'], $apply )
							: $this->error( 'digitalogic_patris_materializer_category_persian_required', 'A reviewed Persian category name is required.' );
					}
				}

				if ( is_wp_error( $mapped ) ) {
					++$summary['failed'];
				} else {
					++$summary[ $mapped['action'] ];
					$available[ $code ] = true;
					$term_ids[ $code ]  = (int) $mapped['term_id'];
				}
				unset( $remaining[ $code ] );
				$progress = true;
			}
		} while ( $progress && ! empty( $remaining ) );
		$summary['failed'] += count( $remaining );

		return array(
			'term_ids'  => $term_ids,
			'available' => $available,
			'summary'   => $summary,
		);
	}

	/**
	 * Collect one reviewed category and its declared ancestors.
	 */
	private function collect_needed_category( $code, $source_categories, $manifest_categories, &$needed, &$missing ) {
		$trail = array();
		while ( '' !== $code && ! isset( $needed[ $code ] ) ) {
			if ( isset( $trail[ $code ] ) ) {
				$missing[ $code ] = 'cycle';
				return;
			}
			$trail[ $code ] = true;
			if ( isset( $source_categories[ $code ] ) ) {
				$record          = $source_categories[ $code ];
				$needed[ $code ] = array(
					'kind'                  => 'patris',
					'parent_code'           => (string) $record['parent_code'],
					'target_parent_term_id' => 0,
					'record'                => $record,
					'enrichment'            => $manifest_categories[ $code ] ?? null,
				);
				$code            = (string) $record['parent_code'];
				continue;
			}
			if ( isset( $manifest_categories[ $code ] ) && str_starts_with( $code, 'digitalogic:' ) ) {
				$enrichment      = $manifest_categories[ $code ];
				$needed[ $code ] = array(
					'kind'                  => 'synthetic',
					'parent_code'           => (string) ( $enrichment['parent_category_code'] ?? '' ),
					'target_parent_term_id' => (int) ( $enrichment['target_parent_term_id'] ?? 0 ),
					'enrichment'            => $enrichment,
				);
				$code            = (string) ( $enrichment['parent_category_code'] ?? '' );
				continue;
			}
			$missing[ $code ] = 'not_found';
			return;
		}
	}

	/**
	 * Resolve a product's source, synthetic, or direct reviewed category.
	 *
	 * @return array
	 */
	private function resolve_product_category( $record, $enrichment, $category_result ) {
		$override = $enrichment['category_override'];
		if ( is_array( $override ) && null !== $override['target_term_id'] ) {
			$term_id = (int) $override['target_term_id'];
			$term    = get_term( $term_id, 'product_cat' );

			return array(
				'category_code' => '',
				'term_id'       => $term_id,
				'available'     => ! is_wp_error( $term ) && is_object( $term ),
			);
		}
		$code = is_array( $override )
			? (string) $override['category_code']
			: (string) ( $record['category_code'] ?? '' );

		return array(
			'category_code' => $code,
			'term_id'       => (int) ( $category_result['term_ids'][ $code ] ?? 0 ),
			'available'     => '' !== $code && ! empty( $category_result['available'][ $code ] ),
		);
	}

	/**
	 * Reconcile a reviewed Digitalogic category that has no Patris source row.
	 *
	 * @return array|WP_Error
	 */
	private function reconcile_synthetic_category_term( $key, $enrichment, $parent_id, $source, $apply ) {
		$matches = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => self::CATEGORY_KEY_META,
				'meta_value' => $key,
				'number'     => 2,
			)
		);
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}
		if ( count( $matches ) > 1 ) {
			return $this->error( 'digitalogic_patris_materializer_synthetic_category_ambiguous', 'Multiple categories claim the same reviewed Digitalogic key.' );
		}

		$term_id = 0;
		$action  = 'already_mapped';
		if ( null !== $enrichment['target_term_id'] ) {
			$term_id = (int) $enrichment['target_term_id'];
			$term    = get_term( $term_id, 'product_cat' );
			if ( is_wp_error( $term ) || ! is_object( $term ) ) {
				return $this->error( 'digitalogic_patris_materializer_category_target_unavailable', 'The reviewed product category target is unavailable.' );
			}
			if ( 1 === count( $matches ) ) {
				$match    = reset( $matches );
				$match_id = is_object( $match ) ? (int) $match->term_id : (int) $match;
				if ( $match_id !== $term_id ) {
					return $this->error( 'digitalogic_patris_materializer_category_target_mismatch', 'The reviewed category differs from the existing category-key owner.' );
				}
			}
			$claimed = (string) get_term_meta( $term_id, self::CATEGORY_KEY_META, true );
			if ( '' !== $claimed && $claimed !== $key ) {
				return $this->error( 'digitalogic_patris_materializer_category_conflict', 'The reviewed category is already claimed by another category key.' );
			}
			$action = '' === $claimed ? 'adopted' : 'already_mapped';
		} elseif ( 1 === count( $matches ) ) {
			$match   = reset( $matches );
			$term_id = is_object( $match ) ? (int) $match->term_id : (int) $match;
		} else {
			$existing = term_exists( $enrichment['name_fa'], 'product_cat', $parent_id );
			if ( is_array( $existing ) ) {
				$term_id = (int) ( $existing['term_id'] ?? 0 );
				$action  = 'adopted';
			} elseif ( ! $apply ) {
				return array(
					'term_id' => 0,
					'action'  => 'planned_create',
				);
			} else {
				$inserted = wp_insert_term(
					$enrichment['name_fa'],
					'product_cat',
					array(
						'parent' => $parent_id,
						'slug'   => 'digitalogic-' . sanitize_title( substr( $key, strlen( 'digitalogic:' ) ) ),
					)
				);
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
				$term_id = (int) ( $inserted['term_id'] ?? 0 );
				$action  = 'created';
			}
		}
		if ( ! $apply ) {
			return array(
				'term_id' => $term_id,
				'action'  => $action,
			);
		}

		$term = get_term( $term_id, 'product_cat' );
		if ( is_wp_error( $term ) || ! is_object( $term ) ) {
			return $this->error( 'digitalogic_patris_materializer_category_unavailable', 'The reviewed category is unavailable.' );
		}
		if ( $enrichment['rename'] && ( (string) $term->name !== $enrichment['name_fa'] || (int) $term->parent !== $parent_id ) ) {
			$updated = wp_update_term(
				$term_id,
				'product_cat',
				array(
					'name'   => $enrichment['name_fa'],
					'parent' => $parent_id,
				)
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$action = 'updated';
		} elseif ( ! $enrichment['rename'] && ( (string) $term->name !== $enrichment['name_fa'] || (int) $term->parent !== $parent_id ) ) {
			$action = 'preserved_manual';
		}

		update_term_meta( $term_id, self::CATEGORY_KEY_META, $key );
		update_term_meta( $term_id, self::CATEGORY_MANAGED_META, '1' );
		update_term_meta( $term_id, '_digitalogic_category_origin', 'manual_enrichment' );
		update_term_meta( $term_id, '_digitalogic_category_source_id', (string) $source['id'] );
		update_term_meta( $term_id, '_digitalogic_category_dataset', (string) $source['dataset'] );
		$this->apply_seo_meta( $term_id, $enrichment, true );

		return array(
			'term_id' => $term_id,
			'action'  => $action,
		);
	}

	/**
	 * Reconcile one category without deleting or overwriting manual terms.
	 *
	 * @return array|WP_Error
	 */
	private function reconcile_category_term( $category, $name, $parent_id, $enrichment, $source, $apply ) {
		$matches = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => self::CATEGORY_CODE_META,
				'meta_value' => (string) $category['category_code'],
				'number'     => 2,
			)
		);
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}
		if ( count( $matches ) > 1 ) {
			return $this->error( 'digitalogic_patris_materializer_category_ambiguous', 'Multiple categories claim the same Patris Code.' );
		}

		$term_id            = 0;
		$action             = 'already_mapped';
		$managed            = false;
		$reviewed_target_id = is_array( $enrichment ) ? $enrichment['target_term_id'] : null;
		$rename_reviewed    = is_array( $enrichment ) && true === $enrichment['rename'];
		if ( null !== $reviewed_target_id ) {
			$reviewed_term = get_term( (int) $reviewed_target_id, 'product_cat' );
			if ( is_wp_error( $reviewed_term ) || ! is_object( $reviewed_term ) ) {
				return $this->error( 'digitalogic_patris_materializer_category_target_unavailable', 'The reviewed product category target is unavailable.' );
			}
			if ( 1 === count( $matches ) ) {
				$match    = reset( $matches );
				$match_id = is_object( $match ) ? (int) $match->term_id : (int) $match;
				if ( $match_id !== (int) $reviewed_target_id ) {
					return $this->error( 'digitalogic_patris_materializer_category_target_mismatch', 'The reviewed category differs from the existing Patris Code owner.' );
				}
			}
			$claimed_code = (string) get_term_meta( (int) $reviewed_target_id, self::CATEGORY_CODE_META, true );
			if ( '' !== $claimed_code && $claimed_code !== (string) $category['category_code'] ) {
				return $this->error( 'digitalogic_patris_materializer_category_conflict', 'The reviewed category is already claimed by another Patris Code.' );
			}
			$term_id = (int) $reviewed_target_id;
			$managed = '1' === (string) get_term_meta( $term_id, self::CATEGORY_MANAGED_META, true );
			$action  = '' === $claimed_code ? 'adopted' : 'already_mapped';
		} elseif ( 1 === count( $matches ) ) {
			$term    = reset( $matches );
			$term_id = is_object( $term ) ? (int) $term->term_id : (int) $term;
			$managed = '1' === (string) get_term_meta( $term_id, self::CATEGORY_MANAGED_META, true );
		} else {
			$existing = term_exists( $name, 'product_cat', $parent_id );
			if ( is_array( $existing ) ) {
				$term_id = (int) ( $existing['term_id'] ?? 0 );
			} elseif ( is_int( $existing ) || ctype_digit( (string) $existing ) ) {
				$term_id = (int) $existing;
			}
			if ( $term_id > 0 ) {
				$claimed_code = (string) get_term_meta( $term_id, self::CATEGORY_CODE_META, true );
				if ( '' !== $claimed_code && $claimed_code !== (string) $category['category_code'] ) {
					return $this->error( 'digitalogic_patris_materializer_category_conflict', 'A manual category is already claimed by another Patris Code.' );
				}
				$action = 'adopted';
			} elseif ( ! $apply ) {
				return array(
					'term_id' => 0,
					'action'  => 'planned_create',
				);
			} else {
				$neutral_slug = Digitalogic_Product_Category_Slugs::neutral_slug( (string) $category['category_code'] );
				if ( '' === $neutral_slug ) {
					return $this->error( 'digitalogic_patris_materializer_category_slug_invalid', 'The product category Code cannot produce a stable public slug.' );
				}
				$slug_owner = get_term_by( 'slug', $neutral_slug, 'product_cat' );
				if ( is_object( $slug_owner ) ) {
					return $this->error( 'digitalogic_patris_materializer_category_slug_conflict', 'The stable product category slug is already owned by another category.' );
				}
				$inserted = wp_insert_term(
					$name,
					'product_cat',
					array(
						'parent' => $parent_id,
						'slug'   => $neutral_slug,
					)
				);
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
				$term_id = (int) ( $inserted['term_id'] ?? 0 );
				$action  = 'created';
				$managed = true;
			}
		}

		if ( ! $apply ) {
			return array(
				'term_id' => $term_id,
				'action'  => $action,
			);
		}
		$term = get_term( $term_id, 'product_cat' );
		if ( $term_id <= 0 || is_wp_error( $term ) || ! is_object( $term ) ) {
			return $this->error( 'digitalogic_patris_materializer_category_unavailable', 'The mapped category is unavailable.' );
		}

		$neutral_slug = Digitalogic_Product_Category_Slugs::neutral_slug( (string) $category['category_code'] );
		if ( '' === $neutral_slug ) {
			return $this->error( 'digitalogic_patris_materializer_category_slug_invalid', 'The product category Code cannot produce a stable public slug.' );
		}
		$migrate_slug = $managed && str_starts_with( (string) ( $term->slug ?? '' ), Digitalogic_Product_Category_Slugs::LEGACY_PREFIX );
		$needs_update = (string) $term->name !== $name || (int) $term->parent !== $parent_id || $migrate_slug;
		if ( $migrate_slug ) {
			$slug_owner = get_term_by( 'slug', $neutral_slug, 'product_cat' );
			if ( is_object( $slug_owner ) && (int) $slug_owner->term_id !== $term_id ) {
				return $this->error( 'digitalogic_patris_materializer_category_slug_conflict', 'The stable product category slug is already owned by another category.' );
			}

			if ( ! Digitalogic_Product_Category_Slugs::instance()->remember_legacy_slug( $term_id, (string) $term->slug ) ) {
				return $this->error( 'digitalogic_patris_materializer_category_legacy_slug_failed', 'The legacy product category redirect could not be recorded.' );
			}
		}

		if ( ( $managed || $rename_reviewed ) && $needs_update ) {
			$update_args = array(
				'name'   => $name,
				'parent' => $parent_id,
			);
			if ( $migrate_slug ) {
				$update_args['slug'] = $neutral_slug;
			}
			$updated = wp_update_term(
				$term_id,
				'product_cat',
				$update_args
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$action = 'updated';
		} elseif ( ! $managed && $needs_update ) {
			$action = 'preserved_manual';
		}

		update_term_meta( $term_id, self::CATEGORY_CODE_META, (string) $category['category_code'] );
		update_term_meta( $term_id, '_digitalogic_patris_category_record_hash', (string) $category['record_hash'] );
		update_term_meta( $term_id, '_digitalogic_patris_category_source_id', (string) $source['id'] );
		update_term_meta( $term_id, '_digitalogic_patris_category_dataset', (string) $source['dataset'] );
		if ( 'created' === $action || $managed ) {
			update_term_meta( $term_id, self::CATEGORY_MANAGED_META, '1' );
		} else {
			update_term_meta( $term_id, self::CATEGORY_ADOPTED_META, '1' );
		}
		if ( is_array( $enrichment ) ) {
			$this->apply_seo_meta( $term_id, $enrichment, true );
		}
		if ( (string) get_term_meta( $term_id, self::CATEGORY_CODE_META, true ) !== (string) $category['category_code'] ) {
			return $this->error( 'digitalogic_patris_materializer_category_meta_failed', 'The category Code failed readback verification.' );
		}
		if ( ( 'created' === $action || $migrate_slug ) && (string) get_term( $term_id, 'product_cat' )->slug !== $neutral_slug ) {
			return $this->error( 'digitalogic_patris_materializer_category_slug_failed', 'The stable product category slug failed readback verification.' );
		}

		return array(
			'term_id' => $term_id,
			'action'  => $action,
		);
	}

	/**
	 * Resolve or validate one explicitly reviewed leaf target.
	 *
	 * @return array|WP_Error
	 */
	private function resolve_manifest_target( $code, $enrichment, $source_id, $dataset ) {
		$resolved  = Digitalogic_Product_Identifier_Resolver::instance()->resolve( array( 'code' => $code ) );
		$target_id = $enrichment['target_product_id'];
		if ( ! is_wp_error( $resolved ) ) {
			$resolved_id = (string) $resolved['woocommerce_id'];
			if ( null !== $target_id && $resolved_id !== $target_id ) {
				return $this->error( 'digitalogic_patris_materializer_target_mismatch', 'The reviewed target differs from the exact Code/SKU owner.' );
			}
			$product = wc_get_product( (int) $resolved_id );
			if ( ! $product ) {
				return $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The resolved WooCommerce target is unavailable.' );
			}
			$owned = $this->target_owned_by( $product, $source_id, $dataset, $code );
			if ( null === $target_id && ! $owned ) {
				return $this->error( 'digitalogic_patris_materializer_explicit_target_required', 'An existing unowned product requires an explicit reviewed target_product_id.' );
			}
			$valid = $this->validate_target_product( $product, $enrichment, $source_id, $dataset, $code );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			return array(
				'action'    => $owned ? 'planned_reconcile' : 'planned_adopt',
				'new_claim' => ! $owned,
				'product'   => $product,
			);
		}

		if ( 'digitalogic_product_identifier_not_found' !== $resolved->get_error_code() ) {
			return $resolved;
		}
		if ( null !== $target_id ) {
			$product = wc_get_product( (int) $target_id );
			if ( ! $product ) {
				return $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The reviewed WooCommerce target is unavailable.' );
			}
			$valid = $this->validate_target_product( $product, $enrichment, $source_id, $dataset, $code );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			return array(
				'action'    => $this->target_owned_by( $product, $source_id, $dataset, $code ) ? 'planned_reconcile' : 'planned_adopt',
				'new_claim' => ! $this->target_owned_by( $product, $source_id, $dataset, $code ),
				'product'   => $product,
			);
		}
		if ( null !== $enrichment['target_parent_id'] ) {
			$parent = $this->validate_new_variation_parent( $enrichment );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}

			return array(
				'action'    => 'planned_create_variation',
				'new_claim' => true,
				'product'   => null,
			);
		}

		return array(
			'action'    => 'planned_create',
			'new_claim' => true,
			'product'   => null,
		);
	}

	/**
	 * Refuse containers and enforce explicit variation parent ownership.
	 *
	 * @return true|WP_Error
	 */
	private function validate_target_product( $product, $enrichment, $source_id, $dataset, $code ) {
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		$provenance = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $product_id );
		if ( is_wp_error( $provenance ) || empty( $provenance['product_exists'] ) ) {
			return is_wp_error( $provenance ) ? $provenance : $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The reviewed WooCommerce target is unavailable.' );
		}
		if ( 'trash' === (string) ( $provenance['post_status'] ?? '' ) ) {
			return $this->error( 'digitalogic_patris_materializer_target_trashed', 'A product in Trash keeps its Product Code ownership until permanent deletion.' );
		}
		if ( ! empty( $provenance['duplicate_rows'] ) || ! empty( $provenance['invalid_key_rows'] ) ) {
			return $this->error( 'digitalogic_patris_materializer_target_provenance_conflict', 'The reviewed target has malformed or conflicting Product Code provenance.' );
		}
		$owner_counts = is_array( $provenance['owner_row_counts'] ?? null ) ? $provenance['owner_row_counts'] : array();
		$owner_values = is_array( $provenance['owner'] ?? null ) ? $provenance['owner'] : array();
		$owner_total  = array_sum( array_map( 'intval', $owner_counts ) );
		$owner_exact  = 3 === $owner_total
			&& 1 === (int) ( $owner_counts['source_id'] ?? 0 )
			&& 1 === (int) ( $owner_counts['dataset'] ?? 0 )
			&& 1 === (int) ( $owner_counts['product_code'] ?? 0 )
			&& '' !== (string) ( $owner_values['source_id'] ?? '' )
			&& '' !== (string) ( $owner_values['dataset'] ?? '' )
			&& '' !== (string) ( $owner_values['product_code'] ?? '' );
		if ( $owner_total > 0 && ! $owner_exact ) {
			return $this->error( 'digitalogic_patris_materializer_target_provenance_conflict', 'The reviewed target has incomplete or duplicate source ownership provenance.' );
		}
		if (
			$owner_exact
			&& (
				! hash_equals( $source_id, (string) $owner_values['source_id'] )
				|| ! hash_equals( $dataset, (string) $owner_values['dataset'] )
				|| ! hash_equals( $code, (string) $owner_values['product_code'] )
			)
		) {
			return $this->error( 'digitalogic_patris_materializer_target_owned', 'The reviewed target is already owned by another source leaf.' );
		}

		$converting_variable = false;
		if ( $product->is_type( 'variable' ) ) {
			if ( ! $enrichment['convert_empty_variable_to_simple'] ) {
				return $this->error( 'digitalogic_patris_materializer_variable_parent_refused', 'A variable container cannot own a Patris leaf Code.' );
			}
			if ( ! empty( $product->get_children() ) ) {
				return $this->error( 'digitalogic_patris_materializer_nonempty_variable_refused', 'Only an explicitly reviewed variable product with zero children can be converted.' );
			}

			$converting_variable = true;
		}
		if ( ! $converting_variable && ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
			return $this->error( 'digitalogic_patris_materializer_product_type_unsupported', 'Only simple products and existing variations can own Patris leaf Codes.' );
		}

		$parent_id = $enrichment['target_parent_id'];
		if ( $product->is_type( 'variation' ) ) {
			if ( null === $parent_id || (string) $product->get_parent_id() !== $parent_id ) {
				return $this->error( 'digitalogic_patris_materializer_variation_parent_mismatch', 'The variation does not belong to the explicitly reviewed parent.' );
			}
			$parent = wc_get_product( (int) $parent_id );
			if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
				return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The reviewed variation parent is not a variable product.' );
			}
			$parent_provenance = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( (int) $parent_id );
			$parent_owner_rows = is_array( $parent_provenance ) && is_array( $parent_provenance['owner_row_counts'] ?? null )
				? array_sum( array_map( 'intval', $parent_provenance['owner_row_counts'] ) )
				: -1;
			if (
				is_wp_error( $parent_provenance )
				|| ! empty( $parent_provenance['duplicate_rows'] )
				|| ! empty( $parent_provenance['invalid_key_rows'] )
				|| ! empty( $parent_provenance['meta_exists'] )
				|| 0 !== $parent_owner_rows
			) {
				return $this->error( 'digitalogic_patris_materializer_parent_identity_conflict', 'The variable container already owns a leaf identity.' );
			}
			if ( '' !== $enrichment['attribute_taxonomy'] ) {
				$term = get_term( (int) $enrichment['attribute_term_id'], $enrichment['attribute_taxonomy'] );
				$variation_attributes = $product->get_variation_attributes();
				$attribute_key        = 'attribute_' . $enrichment['attribute_taxonomy'];
				if (
					is_wp_error( $term )
					|| ! is_object( $term )
					|| (string) ( $variation_attributes[ $attribute_key ] ?? '' ) !== (string) $term->slug
				) {
					return $this->error( 'digitalogic_patris_materializer_variation_attribute_mismatch', 'The managed variation no longer owns its reviewed attribute option.' );
				}
			}
		} elseif ( null !== $parent_id || $product->get_parent_id() > 0 ) {
			return $this->error( 'digitalogic_patris_materializer_simple_parent_invalid', 'A simple target cannot declare a parent.' );
		}

		$patris_code = ! empty( $provenance['meta_exists'] ) ? (string) $provenance['product_code'] : '';
		if ( '' !== $patris_code && $patris_code !== $code ) {
			return $this->error( 'digitalogic_patris_materializer_patris_code_conflict', 'The reviewed target has a different Patris Code.' );
		}
		$sku = (string) $product->get_sku();
		if ( '' !== $sku && $sku !== $code ) {
			return $this->error( 'digitalogic_patris_materializer_sku_conflict', 'The reviewed target has a different non-empty SKU.' );
		}

		return true;
	}

	/** Apply one selected source row as a single rollback-backed product transaction. */
	private function apply_materializer_row_transaction( $target, $code, $source_id, $dataset, $record, $enrichment, $category_term, $category_code, $publish_ready ) {
		$action           = (string) ( $target['action'] ?? '' );
		$is_new_simple    = 'planned_create' === $action;
		$is_new_variation = 'planned_create_variation' === $action;
		$parent_id        = null !== ( $enrichment['target_parent_id'] ?? null ) ? (int) $enrichment['target_parent_id'] : 0;

		if ( $is_new_variation ) {
			return $this->with_product_locks(
				array( $parent_id ),
				function () use ( $target, $code, $source_id, $dataset, $record, $enrichment, $category_term, $category_code, $publish_ready, $parent_id ) {
					if ( ! $this->source_write_locks_are_owned( array( $parent_id ) ) ) {
						return $this->source_write_outcome_unknown( $parent_id );
					}
					$this->flush_product_caches( $parent_id );
					$parent = $this->validate_new_variation_parent( $enrichment );
					if ( is_wp_error( $parent ) ) {
						return $parent;
					}
					$product = $this->create_variation_draft_shell( $enrichment );
					if ( is_wp_error( $product ) ) {
						return $product;
					}
					$product_id = (int) $product->get_id();
					$applied    = $this->with_product_locks(
						array( $parent_id, $product_id ),
						function () use ( $target, $product_id, $code, $source_id, $dataset, $record, $enrichment, $category_term, $category_code, $publish_ready ) {
							return $this->apply_materializer_row_locked(
								$target,
								$product_id,
								$code,
								$source_id,
								$dataset,
								$record,
								$enrichment,
								$category_term,
								$category_code,
								$publish_ready,
								true
							);
						}
					);

					return is_wp_error( $applied ) && 'product_write_lock_busy' === $applied->get_error_code()
						? $this->source_write_outcome_unknown( $product_id, $applied )
						: $applied;
				}
			);
		}

		if ( $is_new_simple ) {
			$product = $this->create_simple_draft_shell( $enrichment );
			if ( is_wp_error( $product ) ) {
				return $product;
			}
			$product_id = (int) $product->get_id();
			$applied    = $this->with_product_locks(
				array( $product_id ),
				function () use ( $target, $product_id, $code, $source_id, $dataset, $record, $enrichment, $category_term, $category_code, $publish_ready ) {
					return $this->apply_materializer_row_locked(
						$target,
						$product_id,
						$code,
						$source_id,
						$dataset,
						$record,
						$enrichment,
						$category_term,
						$category_code,
						$publish_ready,
						true
					);
				}
			);

			return is_wp_error( $applied ) && 'product_write_lock_busy' === $applied->get_error_code()
				? $this->source_write_outcome_unknown( $product_id, $applied )
				: $applied;
		}

		$product    = $target['product'] ?? null;
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		if ( $product_id <= 0 ) {
			return $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The reviewed WooCommerce target is unavailable.' );
		}
		$lock_ids = array( $product_id );
		if ( $parent_id > 0 ) {
			$lock_ids[] = $parent_id;
		}

		return $this->with_product_locks(
			$lock_ids,
			function () use ( $target, $product_id, $code, $source_id, $dataset, $record, $enrichment, $category_term, $category_code, $publish_ready ) {
				return $this->apply_materializer_row_locked(
					$target,
					$product_id,
					$code,
					$source_id,
					$dataset,
					$record,
					$enrichment,
					$category_term,
					$category_code,
					$publish_ready,
					false
				);
			}
		);
	}

	/** Create an unowned hidden simple shell before acquiring its newly assigned ID lock. */
	private function create_simple_draft_shell( $enrichment ) {
		try {
			$product = new WC_Product_Simple();
			$product->set_name( sanitize_text_field( $enrichment['name_fa'] ) );
			$product->set_status( 'draft' );
			if ( method_exists( $product, 'set_catalog_visibility' ) ) {
				$product->set_catalog_visibility( 'hidden' );
			}
			$product_id = $product->save();
			if ( (int) $product_id <= 0 || (int) $product->get_id() !== (int) $product_id ) {
				throw new RuntimeException( 'WooCommerce returned an invalid product ID.' );
			}
			$this->flush_product_caches( (int) $product_id );
			$fresh = wc_get_product( (int) $product_id );

			return $fresh instanceof WC_Product
				? $fresh
				: $this->source_write_outcome_unknown( (int) $product_id );
		} catch ( Throwable $exception ) {
			return $this->error(
				'digitalogic_patris_materializer_create_failed',
				'The unowned draft shell could not be created.',
				array( 'effect_attempted' => isset( $product ) && $product instanceof WC_Product && (int) $product->get_id() > 0 )
			);
		}
	}

	/**
	 * Create an unowned, conservative source shell before assigning identity.
	 *
	 * @param string $name Exact source product name.
	 * @return WC_Product|WP_Error
	 * @throws RuntimeException When WooCommerce rejects the temporary shell.
	 */
	private function create_source_draft_shell( $name ) {
		try {
			$product = new WC_Product_Simple();
			$product->set_name( sanitize_text_field( (string) $name ) );
			$product->set_status( 'draft' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
			$product->set_price( '' );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 0 );
			$product->set_stock_status( 'outofstock' );
			$product_id = $product->save();
			if ( (int) $product_id <= 0 || (int) $product->get_id() !== (int) $product_id ) {
				throw new RuntimeException( 'WooCommerce returned an invalid product ID.' );
			}
			$this->flush_product_caches( (int) $product_id );
			$fresh = wc_get_product( (int) $product_id );

			return $fresh instanceof WC_Product
				? $fresh
				: $this->source_write_outcome_unknown( (int) $product_id );
		} catch ( Throwable $exception ) {
			return $this->error(
				'digitalogic_patris_materializer_create_failed',
				'The unowned safe source shell could not be created.',
				array( 'effect_attempted' => isset( $product ) && $product instanceof WC_Product && (int) $product->get_id() > 0 )
			);
		}
	}

	/** Create an unowned variation shell while its reviewed parent remains locked. */
	private function create_variation_draft_shell( $enrichment ) {
		$parent_id = (int) $enrichment['target_parent_id'];
		$term      = get_term( (int) $enrichment['attribute_term_id'], $enrichment['attribute_taxonomy'] );
		if ( ! is_object( $term ) || empty( $term->slug ) || ! $this->source_write_locks_are_owned( array( $parent_id ) ) ) {
			return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The reviewed variation parent is unavailable.' );
		}
		try {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'draft' );
			$variation->set_attributes( array( $enrichment['attribute_taxonomy'] => (string) $term->slug ) );
			$product_id = $variation->save();
			if ( (int) $product_id <= 0 || (int) $variation->get_id() !== (int) $product_id ) {
				throw new RuntimeException( 'WooCommerce returned an invalid variation ID.' );
			}
			$this->flush_product_caches( (int) $product_id );
			$fresh = wc_get_product( (int) $product_id );

			return $fresh instanceof WC_Product
				? $fresh
				: $this->source_write_outcome_unknown( (int) $product_id );
		} catch ( Throwable $exception ) {
			return $this->error(
				'digitalogic_patris_materializer_variation_create_failed',
				'The unowned draft variation shell could not be created.',
				array( 'effect_attempted' => isset( $variation ) && $variation instanceof WC_Product && (int) $variation->get_id() > 0 )
			);
		}
	}

	/** Execute every row phase while the exact target and parent locks stay owned. */
	private function apply_materializer_row_locked( $target, $product_id, $code, $source_id, $dataset, $record, $enrichment, $category_term, $category_code, $publish_ready, $is_new ) {
		$parent_id = null !== ( $enrichment['target_parent_id'] ?? null ) ? (int) $enrichment['target_parent_id'] : 0;
		$lock_ids  = array( (int) $product_id );
		if ( $parent_id > 0 ) {
			$lock_ids[] = $parent_id;
		}
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->source_write_outcome_unknown( $product_id );
		}

		$this->flush_product_caches( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return $this->source_write_outcome_unknown( $product_id );
		}
		$valid = $this->validate_target_product( $product, $enrichment, $source_id, $dataset, $code );
		if ( is_wp_error( $valid ) ) {
			return $is_new ? $this->rollback_failed_draft_locked( $product, $valid ) : $valid;
		}
		$preflight = Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( $product_id, $code );
		if ( is_wp_error( $preflight ) ) {
			return $is_new ? $this->rollback_failed_draft_locked( $product, $preflight ) : $preflight;
		}

		$target_backup   = $this->capture_identity_enrichment_backup(
			$product,
			! empty( $enrichment['convert_empty_variable_to_simple'] ) && $product->is_type( 'variable' ),
			! empty( $enrichment['convert_empty_variable_to_simple'] ) && $product->is_type( 'variable' )
		);
		$feed_backup     = Digitalogic_Patris_Feed::instance()->capture_locked_product_feed_backup( $product );
		$parent          = $parent_id > 0 ? wc_get_product( $parent_id ) : null;
		$parent_backup   = $parent instanceof WC_Product
			? $this->capture_identity_enrichment_backup( $parent, $product->is_type( 'variation' ), false )
			: null;
		$shipping_before = is_array( $target_backup ) ? $this->shipping_method_from_backup( $target_backup ) : null;
		if (
			is_wp_error( $target_backup )
			|| is_wp_error( $feed_backup )
			|| ( $parent_id > 0 && ( ! $parent instanceof WC_Product || is_wp_error( $parent_backup ) ) )
			|| is_wp_error( $shipping_before )
		) {
			$cause = is_wp_error( $target_backup )
				? $target_backup
				: ( is_wp_error( $feed_backup )
					? $feed_backup
					: ( is_wp_error( $parent_backup ) ? $parent_backup : $shipping_before ) );

			if ( ! $is_new ) {
				$data                    = is_array( $cause->get_error_data() ) ? $cause->get_error_data() : array();
				$data['apply_attempted'] = true;
				$cause                   = new WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
			}

			return $is_new ? $this->rollback_failed_draft_locked( $product, $cause ) : $cause;
		}

		$original_status             = (string) $target_backup['status'];
		$shipping_after              = $this->selected_shipping_method( $record );
		$converted                   = false;
		$variation_identity_expected = null;
		try {
			if ( ! empty( $enrichment['convert_empty_variable_to_simple'] ) && $product->is_type( 'variable' ) ) {
				$product = $this->convert_empty_variable_to_simple( $product );
				if ( is_wp_error( $product ) ) {
					return $this->rollback_materializer_row_failure(
						$product_id,
						$target_backup,
						$feed_backup,
						$parent_backup,
						$product,
						$lock_ids,
						$is_new,
						$code,
						$shipping_before,
						$shipping_after
					);
				}
				$converted = true;
			}

			$identity_write = $this->apply_identity_and_enrichment(
				$product,
				$code,
				$source_id,
				$dataset,
				$enrichment,
				$category_term,
				$category_code
			);
			if ( is_wp_error( $identity_write ) ) {
				return $this->rollback_materializer_row_failure(
					$product_id,
					$target_backup,
					$feed_backup,
					$parent_backup,
					$identity_write,
					$lock_ids,
					$is_new,
					$code,
					$shipping_before,
					$shipping_after
				);
			}
			if (
				! is_array( $identity_write )
				|| ! $identity_write['product'] instanceof WC_Product
				|| ! is_array( $identity_write['expected'] ?? null )
				|| (int) ( $identity_write['category_product_id'] ?? 0 ) <= 0
			) {
				return $this->rollback_materializer_row_failure(
					$product_id,
					$target_backup,
					$feed_backup,
					$parent_backup,
					$this->error( 'digitalogic_patris_materializer_projection_readback_failed', 'The reviewed product projection could not be verified.' ),
					$lock_ids,
					$is_new,
					$code,
					$shipping_before,
					$shipping_after
				);
			}
			$product           = $identity_write['product'];
			$identity_expected = $identity_write['expected'];
			$category_target   = (int) $identity_write['category_product_id'];

			if ( $is_new && $product->is_type( 'variation' ) ) {
				$parent_attribute = $this->add_parent_variation_attribute(
					$parent,
					$enrichment['attribute_taxonomy'],
					(int) $enrichment['attribute_term_id']
				);
				if ( is_wp_error( $parent_attribute ) ) {
					return $this->rollback_materializer_row_failure(
						$product_id, $target_backup, $feed_backup, $parent_backup, $parent_attribute,
						$lock_ids, $is_new, $code, $shipping_before, $shipping_after
					);
				}
			}
			if ( $product->is_type( 'variation' ) ) {
				$variation_identity_expected = $this->capture_variation_identity_expected( $product_id, $enrichment );
				if ( is_wp_error( $variation_identity_expected ) ) {
					return $this->rollback_materializer_row_failure(
						$product_id,
						$target_backup,
						$feed_backup,
						$parent_backup,
						$variation_identity_expected,
						$lock_ids,
						$is_new,
						$code,
						$shipping_before,
						$shipping_after
					);
				}
			}

			$feed_write = Digitalogic_Patris_Feed::instance()->apply_product_feed( $product, $record );
			if ( is_wp_error( $feed_write ) ) {
				return $this->rollback_materializer_row_failure(
					$product_id, $target_backup, $feed_backup, $parent_backup, $feed_write,
					$lock_ids, $is_new, $code, $shipping_before, $shipping_after
				);
			}
			$feed_expected = Digitalogic_Patris_Feed::instance()->capture_locked_product_feed_expected( $product_id, $record );
			if ( is_wp_error( $feed_expected ) ) {
				return $this->rollback_materializer_row_failure(
					$product_id,
					$target_backup,
					$feed_backup,
					$parent_backup,
					$feed_expected,
					$lock_ids,
					$is_new,
					$code,
					$shipping_before,
					$shipping_after
				);
			}

			$assignment = Digitalogic_Shipping_Method_Service::instance()->assign_product_by_code( $code, $shipping_after );
			if ( is_wp_error( $assignment ) || $this->exact_shipping_method( $product_id ) !== $shipping_after ) {
				$cause = is_wp_error( $assignment )
					? $assignment
					: $this->error( 'digitalogic_patris_materializer_shipping_readback_failed', 'The reviewed shipping assignment failed exact readback.' );

				return $this->rollback_materializer_row_failure(
					$product_id, $target_backup, $feed_backup, $parent_backup, $cause,
					$lock_ids, $is_new, $code, $shipping_before, $shipping_after
				);
			}

			$this->flush_product_caches( $product_id );
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return $this->rollback_materializer_row_failure(
					$product_id, $target_backup, $feed_backup, $parent_backup,
					$this->error( 'digitalogic_patris_materializer_feed_readback_failed', 'The exact WooCommerce product could not be reloaded.' ),
					$lock_ids, $is_new, $code, $shipping_before, $shipping_after
				);
			}

			$gates            = $this->publish_gates( $product, $record, $enrichment, $category_term );
			$parent_published = false;
			if ( empty( $gates ) && $publish_ready ) {
				$product->set_status( 'publish' );
				if ( method_exists( $product, 'set_catalog_visibility' ) && ! $product->is_type( 'variation' ) ) {
					$product->set_catalog_visibility( 'visible' );
				}
				$product->update_meta_data( '_digitalogic_patris_publish_ready_at', current_time( 'mysql' ) );
				if ( ! $product->save() ) {
					throw new RuntimeException( 'WooCommerce rejected the reviewed publication state.' );
				}
				if ( $product->is_type( 'variation' ) ) {
					$parent_publish = $this->publish_variation_parent( $product, $enrichment['parent_enrichment'], $category_term );
					if ( is_wp_error( $parent_publish ) ) {
						return $this->rollback_materializer_row_failure(
							$product_id, $target_backup, $feed_backup, $parent_backup, $parent_publish,
							$lock_ids, $is_new, $code, $shipping_before, $shipping_after
						);
					}
					$parent_published = true;
				}
			} elseif ( ! empty( $gates ) ) {
				if ( 'publish' === $original_status ) {
					$product->set_status( 'publish' );
					if ( method_exists( $product, 'set_catalog_visibility' ) && ! $product->is_type( 'variation' ) ) {
						$product->set_catalog_visibility( 'visible' );
					}
				} else {
					$product->set_status( 'draft' );
					if ( method_exists( $product, 'set_catalog_visibility' ) && ! $product->is_type( 'variation' ) ) {
						$product->set_catalog_visibility( 'hidden' );
					}
				}
				$product->delete_meta_data( '_digitalogic_patris_publish_ready_at' );
				if ( ! $product->save() ) {
					throw new RuntimeException( 'WooCommerce rejected the reviewed incomplete state.' );
				}
			}
		} catch ( Throwable $exception ) {
			return $this->rollback_materializer_row_failure(
				$product_id, $target_backup, $feed_backup, $parent_backup,
				$this->error( 'digitalogic_patris_materializer_row_write_failed', 'The reviewed catalog row could not be committed.' ),
				$lock_ids, $is_new, $code, $shipping_before, $shipping_after
			);
		}

		$verified = $this->verify_materializer_row_final(
			$product_id,
			$code,
			$record,
			$enrichment,
			$category_term,
			$category_target,
			$identity_expected,
			$feed_expected,
			$shipping_after,
			$gates,
			$publish_ready,
			$parent_published,
			$variation_identity_expected,
			$original_status,
			$lock_ids
		);
		if ( is_wp_error( $verified ) ) {
			return $this->rollback_materializer_row_failure(
				$product_id, $target_backup, $feed_backup, $parent_backup, $verified,
				$lock_ids, $is_new, $code, $shipping_before, $shipping_after
			);
		}

		$final_status = (string) $verified->get_status();
		$action       = (string) ( $target['action'] ?? '' );
		$is_variation = $verified->is_type( 'variation' );
		$air          = self::SHIPPING_METHOD === $shipping_after;
		$domestic     = self::DOMESTIC_METHOD === $shipping_after;

		return array(
			'created'                   => $is_new ? 1 : 0,
			'created_variations'        => $is_new && $is_variation ? 1 : 0,
			'converted_empty_variables' => $converted ? 1 : 0,
			'adopted'                   => ! $is_new && ! empty( $target['new_claim'] ) ? 1 : 0,
			'reconciled'                => ! $is_new && empty( $target['new_claim'] ) ? 1 : 0,
			'air_express_assigned'      => $air ? 1 : 0,
			'domestic_assigned'         => $domestic ? 1 : 0,
			'publish_ready'             => empty( $gates ) ? 1 : 0,
			'publish_blocked'           => ! empty( $gates ) && 'publish' !== $final_status ? 1 : 0,
			'published_incomplete'      => ! empty( $gates ) && 'publish' === $final_status ? 1 : 0,
			'published'                 => $publish_ready && 'publish' === $final_status && 'publish' !== $original_status ? 1 : 0,
			'preserved_published'       => 'publish' === $original_status && 'publish' === $final_status ? 1 : 0,
			'gates'                     => $gates,
			'action'                    => $action,
		);
	}

	/** Choose the one supported shipping path from exact source pricing fields. */
	private function selected_shipping_method( $record ) {
		$currency = (string) ( $record['price_source_currency'] ?? '' );
		$kind     = (string) ( $record['price_source_kind'] ?? '' );
		if ( 'CNY' === $currency && 'foreign_price' === $kind ) {
			return self::SHIPPING_METHOD;
		}
		if ( 'IRR' === $currency && in_array( $kind, array( 'partner_price', 'sale_price_direct' ), true ) ) {
			return self::DOMESTIC_METHOD;
		}

		return '';
	}

	/** Read the single exact shipping assignment row without object/meta caches. */
	private function exact_shipping_method( $product_id ) {
		$rows = $this->read_exact_meta_rows( $product_id, array( Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$values = (array) ( $rows[ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] ?? array() );
		if ( count( $values ) > 1 ) {
			return $this->error( 'digitalogic_patris_materializer_shipping_state_ambiguous', 'The product has duplicate shipping assignment rows.' );
		}

		return 1 === count( $values ) ? (string) reset( $values ) : '';
	}

	/** Extract the exact pre-row shipping assignment from the durable row backup. */
	private function shipping_method_from_backup( $backup ) {
		$values = (array) ( $backup['meta'][ Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META ] ?? array() );
		if ( count( $values ) > 1 ) {
			return $this->error( 'digitalogic_patris_materializer_shipping_state_ambiguous', 'The product has duplicate shipping assignment rows.' );
		}

		return 1 === count( $values ) ? (string) reset( $values ) : '';
	}

	/** Roll back every row phase or return a terminal exact-reconciliation gate. */
	private function rollback_materializer_row_failure( $product_id, $target_backup, $feed_backup, $parent_backup, $cause, $lock_ids, $is_new, $code, $shipping_before, $shipping_after ) {
		$cause = $cause instanceof WP_Error
			? $cause
			: $this->error( 'digitalogic_patris_materializer_row_write_failed', 'The reviewed catalog row could not be committed.' );
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->source_write_outcome_unknown( $product_id, $cause );
		}

		$current_shipping = $this->exact_shipping_method( $product_id );
		if ( is_wp_error( $current_shipping ) ) {
			return $this->source_write_outcome_unknown( $product_id, $current_shipping );
		}
		if ( $current_shipping !== $shipping_before ) {
			if ( $current_shipping !== $shipping_after ) {
				return $this->source_write_outcome_unknown( $product_id, $cause );
			}
			$shipping_restored = Digitalogic_Shipping_Method_Service::instance()->compare_and_assign_product_by_code(
				$code,
				$shipping_after,
				$shipping_before
			);
			if ( is_wp_error( $shipping_restored ) || $this->exact_shipping_method( $product_id ) !== $shipping_before ) {
				return $this->source_write_outcome_unknown(
					$product_id,
					is_wp_error( $shipping_restored ) ? $shipping_restored : $cause
				);
			}
		}

		$this->flush_product_caches( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return $this->source_write_outcome_unknown( $product_id, $cause );
		}
		if ( $is_new ) {
			if ( is_array( $parent_backup ) ) {
				$parent = wc_get_product( (int) ( $parent_backup['product_id'] ?? 0 ) );
				if ( ! $parent instanceof WC_Product || ! $this->restore_identity_enrichment_backup( $parent, $parent_backup ) ) {
					return $this->source_write_outcome_unknown( $product_id, $cause );
				}
			}

			$draft_rollback = $this->rollback_failed_draft_locked( $product, $cause );
			if (
				! $draft_rollback instanceof WP_Error
				|| ( is_array( $parent_backup ) && ! $this->identity_enrichment_backup_matches( $parent_backup ) )
				|| ! $this->source_write_locks_are_owned( $lock_ids )
			) {
				return $this->source_write_outcome_unknown( $product_id, $cause );
			}

			return $draft_rollback;
		}

		if (
			! Digitalogic_Patris_Feed::instance()->restore_locked_product_feed_backup( $product, $feed_backup )
			|| ! $this->source_write_locks_are_owned( $lock_ids )
		) {
			return $this->source_write_outcome_unknown(
				$product_id,
				$this->error( 'digitalogic_patris_materializer_feed_rollback_failed', 'The source feed rollback requires exact reconciliation.' )
			);
		}
		$this->flush_product_caches( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product || ! $this->restore_identity_enrichment_backup( $product, $target_backup ) ) {
			return $this->row_outcome_unknown_after_target_restore(
				$product_id,
				$this->error( 'digitalogic_patris_materializer_identity_rollback_failed', 'The identity rollback requires exact reconciliation.' ),
				$target_backup
			);
		}
		if ( is_array( $parent_backup ) ) {
			$parent = wc_get_product( (int) ( $parent_backup['product_id'] ?? 0 ) );
			if ( ! $parent instanceof WC_Product || ! $this->restore_identity_enrichment_backup( $parent, $parent_backup ) ) {
				return $this->row_outcome_unknown_after_target_restore(
					$product_id,
					$this->error( 'digitalogic_patris_materializer_parent_rollback_failed', 'The parent rollback requires exact reconciliation.' ),
					$target_backup
				);
			}
		}
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->row_outcome_unknown_after_target_restore( $product_id, $cause, $target_backup );
		}
		if (
			! $this->verify_materializer_row_rollback_final(
				$product_id,
				$target_backup,
				$feed_backup,
				$parent_backup,
				$shipping_before,
				$lock_ids
			)
		) {
			return $this->row_outcome_unknown_after_target_restore(
				$product_id,
				$this->error( 'digitalogic_patris_materializer_composite_rollback_failed', 'The complete catalog row rollback requires exact reconciliation.' ),
				$target_backup
			);
		}

		$data                        = is_array( $cause->get_error_data() ) ? $cause->get_error_data() : array();
		$data['effect_attempted']    = true;
		$data['rollback_verified']   = true;
		$data['preserved_published'] = 'publish' === (string) ( $target_backup['status'] ?? '' );

		return new WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
	}

	/**
	 * Re-read every restored row surface after the final rollback save.
	 *
	 * @param int             $product_id Product or variation ID.
	 * @param array           $target_backup Exact target backup.
	 * @param array           $feed_backup Exact feed backup.
	 * @param array|null      $parent_backup Exact parent backup when applicable.
	 * @param string          $shipping_before Exact pre-row shipping method.
	 * @param array<int, int> $lock_ids Product IDs whose locks must remain owned.
	 * @return bool
	 */
	private function verify_materializer_row_rollback_final( $product_id, $target_backup, $feed_backup, $parent_backup, $shipping_before, $lock_ids ) {
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return false;
		}
		$shipping = $this->exact_shipping_method( $product_id );
		$feed     = Digitalogic_Patris_Feed::instance()->verify_locked_product_feed_expected( $product_id, $feed_backup );
		if (
			is_wp_error( $shipping )
			|| $shipping !== $shipping_before
			|| is_wp_error( $feed )
			|| ! $this->identity_enrichment_backup_matches( $target_backup )
			|| ( is_array( $parent_backup ) && ! $this->identity_enrichment_backup_matches( $parent_backup ) )
		) {
			return false;
		}

		return $this->source_write_locks_are_owned( $lock_ids );
	}

	/** Preserve exact target-status attribution even when a parent rollback is uncertain. */
	private function row_outcome_unknown_after_target_restore( $product_id, $cause, $target_backup ) {
		$error                       = $this->source_write_outcome_unknown( $product_id, $cause );
		$readback                    = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $product_id );
		$data                        = is_array( $error->get_error_data() ) ? $error->get_error_data() : array();
		$data['preserved_published'] = 'publish' === (string) ( $target_backup['status'] ?? '' )
			&& is_array( $readback )
			&& 'publish' === (string) ( $readback['post_status'] ?? '' );

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Verify every row projection after the final child or parent save.
	 *
	 * @param int             $product_id Product or variation ID.
	 * @param string          $code Exact Product Code.
	 * @param array           $record Normalized source row.
	 * @param array           $enrichment Reviewed enrichment row.
	 * @param int             $category_term Reviewed category term ID.
	 * @param int             $category_product_id Product receiving the category.
	 * @param array           $identity_expected Expected identity/enrichment projection.
	 * @param array           $feed_expected Expected feed projection.
	 * @param string          $shipping_method Expected shipping method.
	 * @param array           $gates Expected publication gates.
	 * @param bool            $publish_ready Whether publication was requested.
	 * @param bool            $parent_published Whether a variation parent was published.
	 * @param array|null      $variation_identity_expected Exact child/parent variant identity.
	 * @param string          $original_status Exact status captured before the row write.
	 * @param array<int, int> $lock_ids Product IDs whose locks must remain owned.
	 * @return WC_Product|WP_Error
	 */
	private function verify_materializer_row_final( $product_id, $code, $record, $enrichment, $category_term, $category_product_id, $identity_expected, $feed_expected, $shipping_method, $gates, $publish_ready, $parent_published, $variation_identity_expected, $original_status, $lock_ids ) {
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->source_write_outcome_unknown( $product_id );
		}
		$shipping = $this->exact_shipping_method( $product_id );
		$this->flush_product_caches( $product_id );
		$product = wc_get_product( $product_id );
		if ( is_wp_error( $shipping ) || $shipping !== $shipping_method || ! $product instanceof WC_Product ) {
			return $this->error( 'digitalogic_patris_materializer_row_readback_failed', 'The reviewed catalog row failed exact final readback.' );
		}
		$identity = Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_write( $product_id, $code );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$enrichment_readback = $this->verify_identity_enrichment_expected( $product_id, $identity_expected );
		if ( is_wp_error( $enrichment_readback ) ) {
			return $enrichment_readback;
		}
		$category_readback = $this->verify_product_category( $category_product_id, $category_term );
		if ( is_wp_error( $category_readback ) ) {
			return $category_readback;
		}
		$feed_readback = Digitalogic_Patris_Feed::instance()->verify_locked_product_feed_expected( $product_id, $feed_expected );
		if ( is_wp_error( $feed_readback ) ) {
			return $feed_readback;
		}

		$marker_rows = $this->read_exact_meta_rows( $product_id, array( '_digitalogic_patris_publish_ready_at' ) );
		if ( is_wp_error( $marker_rows ) ) {
			return $marker_rows;
		}
		$marker = (array) ( $marker_rows['_digitalogic_patris_publish_ready_at'] ?? array() );
		if ( empty( $gates ) && $publish_ready ) {
			if (
				'publish' !== (string) $product->get_status()
				|| ( ! $product->is_type( 'variation' ) && method_exists( $product, 'get_catalog_visibility' ) && 'visible' !== (string) $product->get_catalog_visibility() )
				|| 1 !== count( $marker )
				|| '' === (string) reset( $marker )
			) {
				return $this->error( 'digitalogic_patris_materializer_publication_readback_failed', 'The reviewed publication state failed exact readback.' );
			}
		} elseif ( ! empty( $gates ) && 'publish' === $original_status ) {
			if (
				'publish' !== (string) $product->get_status()
				|| ( ! $product->is_type( 'variation' ) && method_exists( $product, 'get_catalog_visibility' ) && 'visible' !== (string) $product->get_catalog_visibility() )
				|| ! empty( $marker )
			) {
				return $this->error( 'digitalogic_patris_materializer_publication_readback_failed', 'The existing public product was not preserved.' );
			}
		} elseif ( ! empty( $gates ) ) {
			if (
				'draft' !== (string) $product->get_status()
				|| ( ! $product->is_type( 'variation' ) && method_exists( $product, 'get_catalog_visibility' ) && 'hidden' !== (string) $product->get_catalog_visibility() )
				|| ! empty( $marker )
			) {
				return $this->error( 'digitalogic_patris_materializer_draft_readback_failed', 'The reviewed incomplete draft state failed exact readback.' );
			}
		}

		if ( $parent_published ) {
			$parent = $this->verify_published_variation_parent( $product, $enrichment['parent_enrichment'], $category_term );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}
		$variation_identity = $this->verify_variation_identity_expected( $product_id, $variation_identity_expected );
		if ( is_wp_error( $variation_identity ) ) {
			return $variation_identity;
		}
		if ( $this->publish_gates( $product, $record, $enrichment, $category_term ) !== $gates ) {
			return $this->error( 'digitalogic_patris_materializer_row_readback_failed', 'The reviewed catalog row failed exact final readback.' );
		}

		return $this->source_write_locks_are_owned( $lock_ids )
			? $product
			: $this->source_write_outcome_unknown( $product_id );
	}

	/**
	 * Capture the exact reviewed child and full parent variation identity.
	 *
	 * @param int   $product_id Variation ID.
	 * @param array $enrichment Reviewed enrichment row.
	 * @return array|WP_Error
	 */
	private function capture_variation_identity_expected( $product_id, $enrichment ) {
		$taxonomy              = (string) ( $enrichment['attribute_taxonomy'] ?? '' );
		$term_id               = (int) ( $enrichment['attribute_term_id'] ?? 0 );
		$parent_id             = (int) ( $enrichment['target_parent_id'] ?? 0 );
		$reviewed_new_identity = '' !== $taxonomy || $term_id > 0;
		$term                  = $reviewed_new_identity && $taxonomy && $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
		$this->flush_product_caches( $product_id );
		$this->flush_product_caches( $parent_id );
		$variation = wc_get_product( $product_id );
		$parent    = wc_get_product( $parent_id );
		$children  = $variation instanceof WC_Product ? $variation->get_variation_attributes() : array();
		$parents   = $parent instanceof WC_Product ? $parent->get_attributes() : array();
		if (
			! $variation instanceof WC_Product
			|| ! $variation->is_type( 'variation' )
			|| (int) $variation->get_parent_id() !== $parent_id
			|| ! $parent instanceof WC_Product
			|| ! $parent->is_type( 'variable' )
		) {
			return $this->error( 'digitalogic_patris_materializer_variation_identity_readback_failed', 'The reviewed variation identity failed exact readback.' );
		}
		if ( $reviewed_new_identity ) {
			$key       = 'attribute_' . $taxonomy;
			$attribute = $parents[ $taxonomy ] ?? null;
			$options   = $attribute instanceof WC_Product_Attribute ? array_map( 'intval', $attribute->get_options() ) : array();
			if (
				'' === $taxonomy
				|| $term_id <= 0
				|| ! is_object( $term )
				|| '' === (string) ( $term->slug ?? '' )
				|| (string) ( $children[ $key ] ?? '' ) !== (string) $term->slug
				|| ! $attribute instanceof WC_Product_Attribute
				|| ! in_array( $term_id, $options, true )
				|| ! $attribute->get_variation()
			) {
				return $this->error( 'digitalogic_patris_materializer_variation_identity_readback_failed', 'The reviewed variation identity failed exact readback.' );
			}
		}

		return array(
			'parent_id'         => $parent_id,
			'taxonomy'          => $taxonomy,
			'term_id'           => $term_id,
			'term_slug'         => $reviewed_new_identity ? (string) $term->slug : '',
			'child_attributes'  => $children,
			'parent_attributes' => $this->clone_product_attributes( $parents ),
		);
	}

	/**
	 * Re-read exact reviewed variation identity after every later save.
	 *
	 * @param int        $product_id Variation ID.
	 * @param array|null $expected Captured exact identity or null for simple rows.
	 * @return true|WP_Error
	 */
	private function verify_variation_identity_expected( $product_id, $expected ) {
		if ( null === $expected ) {
			return true;
		}
		if (
			! is_array( $expected )
			|| ! is_array( $expected['child_attributes'] ?? null )
			|| ! is_array( $expected['parent_attributes'] ?? null )
			|| (int) ( $expected['parent_id'] ?? 0 ) <= 0
		) {
			return $this->error( 'digitalogic_patris_materializer_variation_identity_readback_failed', 'The reviewed variation identity could not be verified.' );
		}

		$parent_id = (int) $expected['parent_id'];
		$this->flush_product_caches( $product_id );
		$this->flush_product_caches( $parent_id );
		$variation = wc_get_product( $product_id );
		$parent    = wc_get_product( $parent_id );
		if (
			! $variation instanceof WC_Product
			|| ! $variation->is_type( 'variation' )
			|| (int) $variation->get_parent_id() !== $parent_id
			|| ! $parent instanceof WC_Product
			|| ! $parent->is_type( 'variable' )
			|| $variation->get_variation_attributes() !== $expected['child_attributes']
			|| ! $this->product_attributes_equal( $expected['parent_attributes'], $parent->get_attributes() )
		) {
			return $this->error( 'digitalogic_patris_materializer_variation_identity_readback_failed', 'The reviewed variation identity failed exact final readback.' );
		}

		return true;
	}

	/** Verify every deterministic parent field changed by variation publication. */
	private function verify_published_variation_parent( $variation, $enrichment, $category_term ) {
		$parent_id = $variation instanceof WC_Product ? (int) $variation->get_parent_id() : 0;
		$this->flush_product_caches( $parent_id );
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof WC_Product || ! $parent->is_type( 'variable' ) ) {
			return $this->error( 'digitalogic_patris_materializer_parent_publish_readback_failed', 'The reviewed variable parent failed exact publication readback.' );
		}
		$keys     = array(
			'_digitalogic_patris_family_name', '_digitalogic_variation_group', 'rank_math_title',
			'rank_math_description', 'rank_math_focus_keyword', 'rank_math_primary_product_cat',
		);
		$meta     = $this->read_exact_meta_rows( $parent_id, $keys );
		$post     = get_post( $parent_id );
		$expected = array(
			'_digitalogic_patris_family_name' => array( sanitize_text_field( $enrichment['patris_family_name'] ) ),
			'_digitalogic_variation_group'    => array( sanitize_text_field( $variation->get_meta( '_digitalogic_variation_group', true ) ) ),
			'rank_math_title'                 => array( sanitize_text_field( $enrichment['seo_title_fa'] ) ),
			'rank_math_description'           => array( sanitize_text_field( $enrichment['seo_description_fa'] ) ),
			'rank_math_focus_keyword'         => array( sanitize_text_field( $enrichment['focus_keyword_fa'] ) ),
			'rank_math_primary_product_cat'   => array( (string) $category_term ),
		);
		if (
			is_wp_error( $meta )
			|| $meta !== $expected
			|| ! $post
			|| (string) ( $post->post_title ?? '' ) !== sanitize_text_field( $enrichment['name_fa'] )
			|| (string) ( $post->post_excerpt ?? '' ) !== wp_kses_post( $enrichment['short_description_fa'] )
			|| 'publish' !== (string) $parent->get_status()
			|| ( method_exists( $parent, 'get_catalog_visibility' ) && 'visible' !== (string) $parent->get_catalog_visibility() )
			|| is_wp_error( $this->verify_product_category( $parent_id, $category_term ) )
		) {
			return $this->error( 'digitalogic_patris_materializer_parent_publish_readback_failed', 'The reviewed variable parent failed exact publication readback.' );
		}

		return true;
	}

	/** Merge one verified row's bounded counters only after terminal readback. */
	private function merge_materializer_row_result( &$result, $code, $row ) {
		foreach (
			array(
				'created',
				'created_variations',
				'converted_empty_variables',
				'adopted',
				'reconciled',
				'air_express_assigned',
				'domestic_assigned',
				'publish_ready',
				'publish_blocked',
				'published',
				'published_incomplete',
				'preserved_published',
			) as $counter
		) {
			$result[ $counter ] += (int) ( $row[ $counter ] ?? 0 );
		}
		if ( ! empty( $row['publish_blocked'] ) ) {
			$this->append_detail( $result, $code, 'publish_blocked', array( 'gates' => (array) ( $row['gates'] ?? array() ) ) );
		}
	}

	/**
	 * Validate a reviewed new child against an existing variable parent.
	 *
	 * @return array|WP_Error
	 */
	private function validate_new_variation_parent( $enrichment ) {
		$parent_id = (int) $enrichment['target_parent_id'];
		$parent    = wc_get_product( $parent_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The reviewed variation parent is not a variable product.' );
		}
		$provenance = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $parent_id );
		$owner_rows = is_array( $provenance ) && is_array( $provenance['owner_row_counts'] ?? null )
			? array_sum( array_map( 'intval', $provenance['owner_row_counts'] ) )
			: -1;
		if (
			is_wp_error( $provenance )
			|| ! empty( $provenance['duplicate_rows'] )
			|| ! empty( $provenance['invalid_key_rows'] )
			|| ! empty( $provenance['meta_exists'] )
			|| 0 !== $owner_rows
			|| 'trash' === (string) ( $provenance['post_status'] ?? '' )
			|| '' !== (string) $parent->get_sku()
		) {
			return $this->error( 'digitalogic_patris_materializer_parent_identity_conflict', 'The variable container must remain Code-less and SKU-less.' );
		}

		$taxonomy = $enrichment['attribute_taxonomy'];
		$term     = get_term( (int) $enrichment['attribute_term_id'], $taxonomy );
		if ( is_wp_error( $term ) || ! is_object( $term ) || empty( $term->slug ) ) {
			return $this->error( 'digitalogic_patris_materializer_variation_attribute_invalid', 'The reviewed variation attribute term is unavailable.' );
		}
		foreach ( (array) $parent->get_children() as $child_id ) {
			$child            = wc_get_product( (int) $child_id );
			$child_attributes = $child && $child->is_type( 'variation' ) ? $child->get_variation_attributes() : array();
			if ( (string) ( $child_attributes[ 'attribute_' . $taxonomy ] ?? '' ) === (string) $term->slug ) {
				return $this->error( 'digitalogic_patris_materializer_variation_attribute_conflict', 'That reviewed attribute option already belongs to another child.' );
			}
		}

		return $parent;
	}

	/**
	 * Add one taxonomy option while preserving every existing parent attribute.
	 *
	 * @return true|WP_Error
	 */
	private function add_parent_variation_attribute( $parent, $taxonomy, $term_id ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- WooCommerce parent product.
		$parent_id = $parent instanceof WC_Product ? (int) $parent->get_id() : 0;
		if ( $parent_id <= 0 ) {
			return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The reviewed variation parent is unavailable.' );
		}

		return $this->with_product_locks(
			array( $parent_id ),
			function () use ( $parent_id, $taxonomy, $term_id ) {
				if ( ! $this->source_write_locks_are_owned( array( $parent_id ) ) ) {
					return $this->source_write_outcome_unknown( $parent_id );
				}
				$this->flush_product_caches( $parent_id );
				$fresh = wc_get_product( $parent_id );
				if ( ! $fresh instanceof WC_Product || ! $fresh->is_type( 'variable' ) ) {
					return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The reviewed variation parent is unavailable.' );
				}

				return $this->add_parent_variation_attribute_locked( $fresh, $taxonomy, $term_id, $parent_id );
			}
		);
	}

	/** Mutate and verify one parent attribute while its exact product lock is held. */
	private function add_parent_variation_attribute_locked( $parent, $taxonomy, $term_id, $parent_id ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound -- WooCommerce parent product.
		$backup = $this->clone_product_attributes( $parent->get_attributes() );
		try {
			$attributes = $this->clone_product_attributes( $backup );
			$attribute  = $attributes[ $taxonomy ] ?? null;
			if ( $attribute instanceof WC_Product_Attribute ) {
				$options = array_map( 'intval', $attribute->get_options() );
				if ( ! in_array( (int) $term_id, $options, true ) ) {
					$options[] = (int) $term_id;
				}
				$attribute->set_options( array_values( array_unique( $options ) ) );
				$attribute->set_variation( true );
				$attributes[ $taxonomy ] = $attribute;
			} else {
				$attribute_id = (int) wc_attribute_taxonomy_id_by_name( $taxonomy );
				if ( $attribute_id <= 0 ) {
					return $this->error( 'digitalogic_patris_materializer_variation_taxonomy_missing', 'The reviewed global variation taxonomy does not exist.' );
				}
				$attribute = new WC_Product_Attribute();
				$attribute->set_id( $attribute_id );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( array( (int) $term_id ) );
				$attribute->set_position( count( $attributes ) );
				$attribute->set_visible( true );
				$attribute->set_variation( true );
				$attributes[ $taxonomy ] = $attribute;
			}
			$parent->set_attributes( $attributes );
			if ( ! $parent->save() ) {
				throw new RuntimeException( 'WooCommerce rejected the parent attribute save.' );
			}
			$this->flush_product_caches( (int) $parent->get_id() );
			$fresh     = wc_get_product( (int) $parent->get_id() );
			$readback  = $fresh instanceof WC_Product ? $fresh->get_attributes() : array();
			$attribute = $readback[ $taxonomy ] ?? null;
			$options   = $attribute instanceof WC_Product_Attribute ? array_map( 'intval', $attribute->get_options() ) : array();
			if ( ! in_array( (int) $term_id, $options, true ) ) {
				throw new RuntimeException( 'The parent attribute readback did not contain the reviewed option.' );
			}
			if ( ! $this->source_write_locks_are_owned( array( $parent_id ) ) ) {
				return $this->source_write_outcome_unknown( $parent_id );
			}
		} catch ( Throwable $exception ) {
			$cause = $this->error( 'digitalogic_patris_materializer_parent_attribute_failed', 'The reviewed parent attribute option could not be saved.' );
			if ( ! $this->source_write_locks_are_owned( array( $parent_id ) ) ) {
				return $this->source_write_outcome_unknown( $parent_id, $cause );
			}
			try {
				$parent->set_attributes( $this->clone_product_attributes( $backup ) );
				$restored = $parent->save();
				if ( $restored ) {
					$this->flush_product_caches( (int) $parent->get_id() );
				}
				$fresh = $restored ? wc_get_product( (int) $parent->get_id() ) : false;
			} catch ( Throwable $rollback_exception ) {
				$fresh = false;
			}
			if ( ! $fresh instanceof WC_Product || ! $this->product_attributes_equal( $backup, $fresh->get_attributes() ) ) {
				return $this->error( 'digitalogic_patris_materializer_parent_attribute_rollback_unknown', 'The parent attribute rollback requires exact reconciliation.' );
			}
			if ( ! $this->source_write_locks_are_owned( array( $parent_id ) ) ) {
				return $this->source_write_outcome_unknown( $parent_id, $cause );
			}
			$data                      = is_array( $cause->get_error_data() ) ? $cause->get_error_data() : array();
			$data['effect_attempted']  = true;
			$data['rollback_verified'] = true;

			return new WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
		}

		return true;
	}

	/** Deep-clone the bounded WooCommerce attribute objects before mutation. */
	private function clone_product_attributes( $attributes ) {
		$cloned = array();
		foreach ( (array) $attributes as $key => $attribute ) {
			$cloned[ $key ] = is_object( $attribute ) ? clone $attribute : $attribute;
		}

		return $cloned;
	}

	/** Compare the semantic parent attribute state used by variation creation. */
	private function product_attributes_equal( $left, $right ) {
		$normalize = static function ( $attributes ) {
			$normalized = array();
			foreach ( (array) $attributes as $key => $attribute ) {
				if ( $attribute instanceof WC_Product_Attribute ) {
					$options = array_values( array_map( 'intval', $attribute->get_options() ) );
					sort( $options, SORT_NUMERIC );
					$normalized[ (string) $key ] = array(
						'id'        => (int) $attribute->get_id(),
						'name'      => (string) $attribute->get_name(),
						'options'   => $options,
						'visible'   => (bool) $attribute->get_visible(),
						'variation' => (bool) $attribute->get_variation(),
					);
				} else {
					$normalized[ (string) $key ] = $attribute;
				}
			}
			ksort( $normalized, SORT_STRING );

			return $normalized;
		};

		return $normalize( $left ) === $normalize( $right );
	}

	/**
	 * Convert only an explicitly reviewed childless variable shell.
	 *
	 * @return WC_Product|WP_Error
	 */
	private function convert_empty_variable_to_simple( $product ) {
		if ( ! $product->is_type( 'variable' ) || ! empty( $product->get_children() ) ) {
			return $this->error( 'digitalogic_patris_materializer_nonempty_variable_refused', 'The variable target is no longer an empty container.' );
		}
		try {
			$attributes = $product->get_attributes();
			foreach ( $attributes as $attribute ) {
				if ( $attribute instanceof WC_Product_Attribute ) {
					$attribute->set_variation( false );
					$attribute->set_visible( true );
				}
			}
			$product->set_attributes( $attributes );
			if ( method_exists( $product, 'set_default_attributes' ) ) {
				$product->set_default_attributes( array() );
			}
			$product->save();
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_variable_conversion_failed', 'The empty variable target attributes could not be normalized.' );
		}

		$product_id = $product->get_id();
		$changed    = wp_set_object_terms( $product_id, 'simple', 'product_type', false );
		if ( is_wp_error( $changed ) ) {
			return $changed;
		}
		$this->flush_product_caches( $product_id );

		try {
			return new WC_Product_Simple( $product_id );
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_variable_conversion_failed', 'The empty variable target could not be reopened as a simple product.' );
		}
	}

	/** Delete and verify one failed new draft while its product lock remains owned. */
	private function rollback_failed_draft_locked( $product, $cause ) {
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		if ( $product_id <= 0 || ! $cause instanceof WP_Error || ! $this->source_write_locks_are_owned( array( $product_id ) ) ) {
			return $this->source_write_outcome_unknown( $product_id, $cause );
		}
		try {
			$deleted = wp_delete_post( $product_id, true );
		} catch ( Throwable $exception ) {
			$deleted = false;
		}
		if ( $deleted && ! get_post( $product_id ) && Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() ) {
			$data                      = is_array( $cause->get_error_data() ) ? $cause->get_error_data() : array();
			$data['effect_attempted']  = true;
			$data['rollback_verified'] = true;
			return new WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
		}

		return new WP_Error(
			'digitalogic_patris_materializer_draft_rollback_unknown',
			'The failed draft identity could not be removed and requires exact reconciliation.',
			array(
				'status'     => 409,
				'retryable'  => false,
				'product_id' => $product_id,
				'cause'      => $cause->get_error_code(),
			)
		);
	}

	/**
	 * Apply exact identity, reviewed enrichment, and additive taxonomy.
	 *
	 * @return WC_Product|WP_Error
	 */
	private function apply_identity_and_enrichment( $product, $code, $source_id, $dataset, $enrichment, $category_term, $reviewed_category_code ) {
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		if ( $product_id <= 0 ) {
			return $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The reviewed WooCommerce target is unavailable.' );
		}
		$lock_ids = array( $product_id );
		if ( $product->is_type( 'variation' ) && null !== $enrichment['target_parent_id'] ) {
			$lock_ids[] = (int) $enrichment['target_parent_id'];
		}

		return $this->with_product_locks(
			$lock_ids,
			function () use ( $product_id, $code, $source_id, $dataset, $enrichment, $category_term, $reviewed_category_code, $lock_ids ) {
				if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
					return $this->source_write_outcome_unknown( $product_id );
				}
				$this->flush_product_caches( $product_id );
				$fresh = wc_get_product( $product_id );
				if ( ! $fresh instanceof WC_Product ) {
					return $this->error( 'digitalogic_patris_materializer_target_unavailable', 'The reviewed WooCommerce target is unavailable.' );
				}
				$valid = $this->validate_target_product( $fresh, $enrichment, $source_id, $dataset, $code );
				if ( is_wp_error( $valid ) ) {
					return $valid;
				}
				$preflight = Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( $product_id, $code );
				if ( is_wp_error( $preflight ) ) {
					return $preflight;
				}

				return $this->apply_identity_and_enrichment_locked(
					$fresh,
					$code,
					$source_id,
					$dataset,
					$enrichment,
					$category_term,
					$reviewed_category_code,
					$lock_ids
				);
			}
		);
	}

	/** Apply and verify one identity after fresh readback while all target locks remain held. */
	private function apply_identity_and_enrichment_locked( $product, $code, $source_id, $dataset, $enrichment, $category_term, $reviewed_category_code, $lock_ids ) {
		$backup = $this->capture_identity_enrichment_backup( $product );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		try {
			if ( '' === (string) $product->get_sku() ) {
				$product->set_sku( $code );
			}
			if ( ! $product->is_type( 'variation' ) ) {
				$product->set_name( sanitize_text_field( $enrichment['name_fa'] ) );
				if ( method_exists( $product, 'set_short_description' ) ) {
					$product->set_short_description( wp_kses_post( $enrichment['short_description_fa'] ) );
				}
			} else {
				$product->update_meta_data( '_digitalogic_persian_name', sanitize_text_field( $enrichment['name_fa'] ) );
				$product->update_meta_data( '_digitalogic_short_description_fa', wp_kses_post( $enrichment['short_description_fa'] ) );
			}

			$this->stage_managed_identity( $product, $code, $source_id, $dataset );
			$product->update_meta_data( self::CATEGORY_TERM_META, (string) $category_term );
			$product->update_meta_data( '_digitalogic_reviewed_category_key', (string) $reviewed_category_code );
			$product->update_meta_data( '_digitalogic_part_number', sanitize_text_field( $enrichment['part_number'] ) );
			$product->update_meta_data( '_digitalogic_model', sanitize_text_field( $enrichment['model'] ) );
			$product->update_meta_data( '_digitalogic_variation_group', sanitize_text_field( $enrichment['variation_group'] ) );
			$this->apply_product_seo_meta( $product, $enrichment );
			$product->update_meta_data( 'rank_math_primary_product_cat', (string) $category_term );
			$category_product_id = $this->assign_product_category( $product, $category_term );
			$saved               = $this->save_managed_identity( $product );
			if ( is_wp_error( $saved ) ) {
				return $this->rollback_identity_enrichment_failure( $product, $backup, $saved, $lock_ids );
			}
			$expected = $this->capture_identity_enrichment_expected( $product );
		} catch ( Throwable $exception ) {
			return $this->rollback_identity_enrichment_failure(
				$product,
				$backup,
				$this->error( 'digitalogic_patris_materializer_product_write_failed', 'The reviewed product enrichment could not be saved.' ),
				$lock_ids
			);
		}
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->source_write_outcome_unknown( $product->get_id() );
		}

		$category_readback = $this->verify_product_category( $category_product_id, $category_term );
		if ( is_wp_error( $category_readback ) ) {
			return $this->rollback_identity_enrichment_failure( $product, $backup, $category_readback, $lock_ids );
		}
		$identity_readback = Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_write( $product->get_id(), $code );
		if ( is_wp_error( $identity_readback ) ) {
			return $this->rollback_identity_enrichment_failure( $product, $backup, $identity_readback, $lock_ids );
		}

		if (
			(string) get_post_meta( $product->get_id(), Digitalogic_Product_Identifier_Resolver::SKU_META, true ) !== $code
		) {
			return $this->rollback_identity_enrichment_failure(
				$product,
				$backup,
				$this->error( 'digitalogic_patris_materializer_identity_readback_failed', 'The Product Code/SKU identity failed readback verification.' ),
				$lock_ids
			);
		}
		$projection_readback = $this->verify_identity_enrichment_expected( $product->get_id(), $expected );
		if ( is_wp_error( $projection_readback ) ) {
			return $this->rollback_identity_enrichment_failure( $product, $backup, $projection_readback, $lock_ids );
		}
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->source_write_outcome_unknown( $product->get_id() );
		}
		$this->flush_product_caches( $product->get_id() );
		$fresh = wc_get_product( $product->get_id() );

		return $fresh instanceof WC_Product
			? array(
				'product'             => $fresh,
				'expected'            => $expected,
				'category_product_id' => (int) $category_product_id,
			)
			: $this->source_write_outcome_unknown( $product->get_id() );
	}

	/** Capture every staged identity/enrichment value before object caches are flushed. */
	private function capture_identity_enrichment_expected( $product ) {
		$expected = array();
		$touched  = array(
			'_sku', self::OWNER_SOURCE_META, self::OWNER_DATASET_META, self::OWNER_CODE_META,
			self::CATEGORY_TERM_META, '_digitalogic_reviewed_category_key', '_digitalogic_part_number',
			'_digitalogic_model', '_digitalogic_variation_group', 'rank_math_title',
			'rank_math_description', 'rank_math_focus_keyword', 'rank_math_primary_product_cat',
		);
		if ( $product->is_type( 'variation' ) ) {
			$touched[] = '_digitalogic_persian_name';
			$touched[] = '_digitalogic_short_description_fa';
		}
		foreach ( $touched as $key ) {
			$expected[ $key ] = array( $product->get_meta( $key, true ) );
		}
		$expected['_digitalogic_patris_materializer_version'] = array();
		$post = get_post( $product->get_id() );

		return array(
			'meta'              => $expected,
			'name'              => (string) ( is_object( $post ) ? ( $post->post_title ?? '' ) : '' ),
			'short_description' => (string) ( is_object( $post ) ? ( $post->post_excerpt ?? '' ) : '' ),
		);
	}

	/** Verify the complete bounded identity/enrichment surface from MySQL. */
	private function verify_identity_enrichment_expected( $product_id, $expected ) {
		if ( ! is_array( $expected ) || ! is_array( $expected['meta'] ?? null ) ) {
			return $this->error( 'digitalogic_patris_materializer_projection_readback_failed', 'The reviewed product projection could not be verified.' );
		}
		$meta = $this->read_exact_meta_rows( $product_id, array_keys( $expected['meta'] ) );
		clean_post_cache( $product_id );
		$post = get_post( $product_id );
		if (
			is_wp_error( $meta )
			|| $meta !== $expected['meta']
			|| ! $post
			|| (string) ( $post->post_title ?? '' ) !== (string) $expected['name']
			|| (string) ( $post->post_excerpt ?? '' ) !== (string) $expected['short_description']
		) {
			return $this->error( 'digitalogic_patris_materializer_projection_readback_failed', 'The reviewed product projection did not pass exact database readback.' );
		}

		return true;
	}

	/** Capture every product field changed by the identity/enrichment phase. */
	private function capture_identity_enrichment_backup( $product, $capture_attributes = false, $capture_product_type = false ) {
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		$canonical  = Digitalogic_Product_Code_Editor::instance()->canonical_source_backup( $product_id );
		if ( $product_id <= 0 || is_wp_error( $canonical ) ) {
			return is_wp_error( $canonical ) ? $canonical : $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product backup is unavailable.' );
		}
		$meta_keys = $this->identity_enrichment_meta_keys();
		if ( $product->is_type( 'variation' ) ) {
			foreach ( array_keys( $product->get_variation_attributes() ) as $attribute_key ) {
				if ( is_string( $attribute_key ) && str_starts_with( $attribute_key, 'attribute_' ) ) {
					$meta_keys[] = $attribute_key;
				}
			}
		}
		$meta = $this->read_exact_meta_rows( $product_id, array_values( array_unique( $meta_keys ) ) );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		clean_post_cache( $product_id );
		$post            = get_post( $product_id );
		$category_target = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$product_type    = $capture_product_type ? $this->product_type_term( $product_id ) : null;
		if ( ! $post || ! $category_target instanceof WC_Product || is_wp_error( $product_type ) ) {
			return $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product backup is unavailable.' );
		}

		return array(
			'product_id'         => $product_id,
			'canonical'          => $canonical,
			'meta'               => $meta,
			'name'               => (string) ( $post->post_title ?? '' ),
			'short_description'  => (string) ( $post->post_excerpt ?? '' ),
			'status'             => (string) $product->get_status(),
			'catalog_visibility' => method_exists( $product, 'get_catalog_visibility' ) ? (string) $product->get_catalog_visibility() : '',
			'attributes'         => $capture_attributes ? $this->clone_product_attributes( $product->get_attributes() ) : null,
			'default_attributes' => $capture_product_type && method_exists( $product, 'get_default_attributes' ) ? (array) $product->get_default_attributes() : null,
			'product_type'       => $product_type,
			'category_target_id' => (int) $category_target->get_id(),
			'category_ids'       => array_values( array_map( 'intval', (array) $category_target->get_category_ids() ) ),
		);
	}

	/** Restore and prove the exact identity/enrichment backup. */
	private function restore_identity_enrichment_backup( $product, $backup ) {
		$product_id = (int) ( $backup['product_id'] ?? 0 );
		if ( ! $product instanceof WC_Product || $product_id <= 0 || $product_id !== (int) $product->get_id() ) {
			return false;
		}
		$canonical = $backup['canonical'] ?? array();
		try {
			$product->set_name( $backup['name'] );
			if ( method_exists( $product, 'set_short_description' ) ) {
				$product->set_short_description( $backup['short_description'] );
			}
			$product->set_status( (string) $backup['status'] );
			if ( method_exists( $product, 'set_catalog_visibility' ) ) {
				$product->set_catalog_visibility( (string) $backup['catalog_visibility'] );
			}
			if ( null !== $backup['attributes'] ) {
				$product->set_attributes( $this->clone_product_attributes( $backup['attributes'] ) );
			}
			if ( null !== $backup['default_attributes'] && method_exists( $product, 'set_default_attributes' ) ) {
				$product->set_default_attributes( (array) $backup['default_attributes'] );
			}
			foreach ( $backup['meta'] as $key => $rows ) {
				if ( ! empty( $rows ) ) {
					$product->update_meta_data( $key, reset( $rows ) );
				} else {
					$product->delete_meta_data( $key );
				}
			}
			if ( $canonical['meta_exists'] ) {
				$product->update_meta_data( Digitalogic_Product_Code_Editor::META_KEY, $canonical['product_code'] );
			} else {
				$product->delete_meta_data( Digitalogic_Product_Code_Editor::META_KEY );
			}
			$category_target = (int) $backup['category_target_id'] === $product_id
				? $product
				: wc_get_product( (int) $backup['category_target_id'] );
			if ( ! $category_target instanceof WC_Product ) {
				return false;
			}
			$category_target->set_category_ids( $backup['category_ids'] );
			if ( $category_target->get_id() !== $product_id && ! $category_target->save() ) {
				return false;
			}
			$saved = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'materializer',
				array(
					'product_id' => $product_id,
					'operation'  => $canonical['meta_exists'] ? 'set' : 'delete',
					'value'      => (string) ( $canonical['product_code'] ?? '' ),
				),
				static function () use ( $product ) {
					return $product->save();
				}
			);
			if ( is_wp_error( $saved ) || ! $saved ) {
				return false;
			}
			if ( null !== $backup['product_type'] ) {
				$type_terms    = '' === (string) $backup['product_type'] ? array() : array( (string) $backup['product_type'] );
				$type_restored = wp_set_object_terms( $product_id, $type_terms, 'product_type', false );
				if ( is_wp_error( $type_restored ) ) {
					return false;
				}
			}
			if ( ! $this->restore_exact_meta_rows( $product_id, $backup['meta'] ) ) {
				return false;
			}
		} catch ( Throwable $exception ) {
			return false;
		}

		$canonical_restored = Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_restore( $product_id, $canonical );
		if ( is_wp_error( $canonical_restored ) ) {
			$deleted = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'materializer',
				array( 'product_id' => $product_id, 'operation' => 'delete' ),
				static function () use ( $product_id ) {
					return delete_post_meta( $product_id, Digitalogic_Product_Code_Editor::META_KEY );
				}
			);
			if ( is_wp_error( $deleted ) ) {
				return false;
			}
			if ( $canonical['meta_exists'] ) {
				$written = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
					'materializer',
					array( 'product_id' => $product_id, 'operation' => 'set', 'value' => $canonical['product_code'] ),
					static function () use ( $product_id, $canonical ) {
						return update_post_meta( $product_id, Digitalogic_Product_Code_Editor::META_KEY, $canonical['product_code'] );
					}
				);
				if ( is_wp_error( $written ) || false === $written ) {
					return false;
				}
			}
			if ( is_wp_error( Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_restore( $product_id, $canonical ) ) ) {
				return false;
			}
		}

		return $this->identity_enrichment_backup_matches( $backup );
	}

	/**
	 * Verify an identity/enrichment backup without issuing another write.
	 *
	 * @param array $backup Exact identity/enrichment backup.
	 * @return bool
	 */
	private function identity_enrichment_backup_matches( $backup ) {
		$product_id = (int) ( $backup['product_id'] ?? 0 );
		$canonical  = $backup['canonical'] ?? array();
		if (
			$product_id <= 0
			|| ! is_array( $backup['meta'] ?? null )
			|| is_wp_error( Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_restore( $product_id, $canonical ) )
		) {
			return false;
		}
		$this->flush_product_caches( $product_id );
		if ( (int) $backup['category_target_id'] !== $product_id ) {
			$this->flush_product_caches( (int) $backup['category_target_id'] );
		}
		$meta_readback = $this->read_exact_meta_rows( $product_id, array_keys( $backup['meta'] ) );
		if ( is_wp_error( $meta_readback ) || $meta_readback !== $backup['meta'] ) {
			return false;
		}
		clean_post_cache( $product_id );
		$post = get_post( $product_id );
		if ( ! $post || (string) ( $post->post_title ?? '' ) !== $backup['name'] || (string) ( $post->post_excerpt ?? '' ) !== $backup['short_description'] ) {
			return false;
		}
		$fresh        = wc_get_product( $product_id );
		$product_type = null !== $backup['product_type'] ? $this->product_type_term( $product_id ) : null;
		if (
			! $fresh instanceof WC_Product
			|| is_wp_error( $product_type )
			|| (string) $fresh->get_status() !== (string) $backup['status']
			|| ( method_exists( $fresh, 'get_catalog_visibility' ) && (string) $fresh->get_catalog_visibility() !== (string) $backup['catalog_visibility'] )
			|| ( null !== $backup['attributes'] && ! $this->product_attributes_equal( $backup['attributes'], $fresh->get_attributes() ) )
			|| ( null !== $backup['default_attributes'] && method_exists( $fresh, 'get_default_attributes' ) && (array) $fresh->get_default_attributes() !== (array) $backup['default_attributes'] )
			|| ( null !== $backup['product_type'] && $product_type !== (string) $backup['product_type'] )
		) {
			return false;
		}
		$category_target = wc_get_product( (int) $backup['category_target_id'] );
		$category_ids    = $category_target instanceof WC_Product ? array_values( array_map( 'intval', (array) $category_target->get_category_ids() ) ) : array();
		sort( $category_ids, SORT_NUMERIC );
		$expected_category_ids = $backup['category_ids'];
		sort( $expected_category_ids, SORT_NUMERIC );

		return $category_ids === $expected_category_ids;
	}

	/** Convert any failed identity effect into a verified rollback or terminal reconciliation block. */
	private function rollback_identity_enrichment_failure( $product, $backup, $cause, $lock_ids ) {
		if ( ! $this->source_write_locks_are_owned( $lock_ids ) ) {
			return $this->source_write_outcome_unknown( (int) ( $backup['product_id'] ?? 0 ), $cause );
		}
		if ( $cause instanceof WP_Error && $this->restore_identity_enrichment_backup( $product, $backup ) ) {
			$data                      = is_array( $cause->get_error_data() ) ? $cause->get_error_data() : array();
			$data['effect_attempted']  = true;
			$data['rollback_verified'] = true;

			return new WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
		}

		return new WP_Error(
			'digitalogic_patris_materializer_rollback_unknown',
			'The reviewed product write and rollback require exact reconciliation.',
			array(
				'status'     => 409,
				'retryable'  => false,
				'product_id' => (int) ( $backup['product_id'] ?? 0 ),
				'cause'      => $cause instanceof WP_Error ? $cause->get_error_code() : 'unknown',
			)
		);
	}

	/** Restore exact ordered values and row counts for every non-canonical key. */
	private function restore_exact_meta_rows( $product_id, $states ) {
		foreach ( (array) $states as $key => $rows ) {
			if ( ! $this->source_write_locks_are_owned( array( $product_id ) ) ) {
				return false;
			}
			delete_post_meta( (int) $product_id, (string) $key );
			foreach ( (array) $rows as $value ) {
				if ( ! $this->source_write_locks_are_owned( array( $product_id ) ) ) {
					return false;
				}
				if ( false === add_post_meta( (int) $product_id, (string) $key, $value, false ) ) {
					return false;
				}
			}
		}
		wp_cache_delete( (int) $product_id, 'post_meta' );

		if ( ! $this->source_write_locks_are_owned( array( $product_id ) ) ) {
			return false;
		}
		$readback = $this->read_exact_meta_rows( $product_id, array_keys( (array) $states ) );

		return ! is_wp_error( $readback ) && $readback === $states;
	}

	/** Read one deterministic, cache-bypassed semantic metadata snapshot. */
	private function read_exact_meta_rows( $product_id, $keys ) {
		global $wpdb;
		$product_id = absint( $product_id );
		$keys       = array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ), 'strlen' ) ) );
		if (
			$product_id <= 0
			|| empty( $keys )
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_results' )
		) {
			return $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product backup is unavailable.' );
		}
		$postmeta     = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and placeholder list are generated from wpdb and a bounded in-memory key list.
		$query = $wpdb->prepare(
			"/* digitalogic_exact_product_meta_rows */
			SELECT meta_id, meta_key, meta_value
			FROM {$postmeta}
			WHERE post_id = %d
				AND BINARY meta_key IN ({$placeholders})
			ORDER BY meta_key ASC, meta_id ASC",
			...array_merge( array( $product_id ), $keys )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product backup is unavailable.' );
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact rollback backup must bypass metadata/object caches.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product backup is unavailable.' );
		}

		$result = array_fill_keys( $keys, array() );
		foreach ( $rows as $row ) {
			$key = (string) ( $row['meta_key'] ?? '' );
			if ( ! array_key_exists( $key, $result ) ) {
				return $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product backup is unavailable.' );
			}
			$result[ $key ][] = maybe_unserialize( $row['meta_value'] ?? '' );
		}

		return $result;
	}

	/** Acquire one or more product locks in deterministic order without waiting. */
	private function with_product_locks( $product_ids, $callback ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) );
		sort( $product_ids, SORT_NUMERIC );
		$wrapped = $callback;
		foreach ( array_reverse( $product_ids ) as $product_id ) {
			$next    = $wrapped;
			$wrapped = static function () use ( $product_id, $next ) {
				return Digitalogic_Product_Write_Lock::instance()->with_product_lock( $product_id, $next, 0 );
			};
		}

		return call_user_func( $wrapped );
	}

	/** Prove source and every affected product lock before rollback or success. */
	private function source_write_locks_are_owned( $product_ids ) {
		if ( ! Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned() ) {
			return false;
		}
		foreach ( array_unique( array_filter( array_map( 'absint', (array) $product_ids ) ) ) as $product_id ) {
			if ( ! Digitalogic_Product_Write_Lock::instance()->is_owned( $product_id ) ) {
				return false;
			}
		}

		return true;
	}

	/** Return a non-retryable reconciliation gate after any lock-loss ambiguity. */
	private function source_write_outcome_unknown( $product_id, $cause = null ) {
		return new WP_Error(
			'digitalogic_patris_materializer_write_outcome_unknown',
			'The reviewed product write lost its lock and requires exact reconciliation.',
			array(
				'status'           => 409,
				'retryable'        => false,
				'effect_attempted' => true,
				'product_id'       => (int) $product_id,
				'cause'            => $cause instanceof WP_Error ? $cause->get_error_code() : 'lock_lost',
			)
		);
	}

	/** Exact metadata surface changed by apply_identity_and_enrichment. */
	private function identity_enrichment_meta_keys() {
		return array(
			'_sku',
			'_digitalogic_patris_materializer_version',
			self::OWNER_SOURCE_META,
			self::OWNER_DATASET_META,
			self::OWNER_CODE_META,
			self::CATEGORY_TERM_META,
			'_digitalogic_reviewed_category_key',
			'_digitalogic_part_number',
			'_digitalogic_model',
			'_digitalogic_variation_group',
			'_digitalogic_persian_name',
			'_digitalogic_short_description_fa',
			'_digitalogic_patris_family_name',
			'_digitalogic_patris_publish_ready_at',
			self::AUTO_MATERIALIZED_META,
			self::SOURCE_REVISION_META,
			self::MISSING_FIELDS_META,
			Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META,
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
			'rank_math_primary_product_cat',
		);
	}

	/** Return the exact single WooCommerce product-type term slug. */
	private function product_type_term( $product_id ) {
		$terms = wp_get_object_terms( (int) $product_id, 'product_type' );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$slugs = array();
		foreach ( (array) $terms as $term ) {
			$slug = is_object( $term ) ? (string) ( $term->slug ?? '' ) : '';
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}
		$slugs = array_values( array_unique( $slugs ) );

		if ( count( $slugs ) > 1 ) {
			return $this->error( 'digitalogic_patris_materializer_backup_unavailable', 'The reviewed product type backup is ambiguous.' );
		}

		return 1 === count( $slugs ) ? $slugs[0] : '';
	}

	/**
	 * Stage the minimum exact ownership needed to recover a partial first save.
	 *
	 * @param WC_Product $product Product or variation leaf.
	 * @param string     $code Exact Patris Code.
	 * @param string     $source_id Exact receiver source ID.
	 * @param string     $dataset Exact receiver dataset.
	 */
	private function stage_managed_identity( $product, $code, $source_id, $dataset ) {
		$product->delete_meta_data( '_digitalogic_patris_materializer_version' );
		$product->delete_meta_data( self::AUTO_MATERIALIZED_META );
		$product->update_meta_data( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, $code );
		$product->update_meta_data( self::OWNER_SOURCE_META, $source_id );
		$product->update_meta_data( self::OWNER_DATASET_META, $dataset );
		$product->update_meta_data( self::OWNER_CODE_META, $code );
	}

	/** Persist one staged canonical identity under the shared writer boundary. */
	private function save_managed_identity( $product ) {
		return Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
			'materializer',
			array(
				'product'   => $product,
				'operation' => 'set',
				'value'     => (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ),
			),
			static function () use ( $product ) {
				return $product->save();
			}
		);
	}

	/**
	 * Preserve manual categories and add the managed leaf to the public parent.
	 *
	 * @return int Product ID that owns the product_cat terms.
	 */
	private function assign_product_category( $product, $term_id ) {
		$target = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$target = $parent;
			}
		}
		$ids   = array_map( 'intval', (array) $target->get_category_ids() );
		$ids[] = (int) $term_id;
		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids, SORT_NUMERIC );
		$target->set_category_ids( $ids );
		if ( $target->get_id() !== $product->get_id() ) {
			$target->save();
		}

		return (int) $target->get_id();
	}

	/**
	 * Flush product taxonomy caches and verify the exact assigned term.
	 *
	 * @param int $product_id Product or variation-parent ID.
	 * @param int $term_id    Reviewed product_cat term ID.
	 * @return true|WP_Error
	 */
	private function verify_product_category( $product_id, $term_id ) {
		$this->flush_product_caches( $product_id );
		$term_ids = wp_get_object_terms(
			(int) $product_id,
			'product_cat',
			array( 'fields' => 'ids' )
		);
		if ( is_wp_error( $term_ids ) ) {
			return $this->error(
				'digitalogic_patris_materializer_category_readback_failed',
				'The reviewed product category could not be read back after assignment.'
			);
		}
		$term_ids = array_map( 'intval', (array) $term_ids );
		if ( ! in_array( (int) $term_id, $term_ids, true ) ) {
			return $this->error(
				'digitalogic_patris_materializer_category_readback_failed',
				'The reviewed product category failed readback verification.'
			);
		}

		return true;
	}

	/**
	 * Publish and enrich a Code-less variable parent after one child is ready.
	 *
	 * @return true|WP_Error
	 */
	private function publish_variation_parent( $variation, $enrichment, $category_term ) {
		if ( ! is_array( $enrichment ) ) {
			return $this->error( 'digitalogic_patris_materializer_parent_enrichment_missing', 'Variation publication requires reviewed parent enrichment.' );
		}
		$parent = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The variation parent is unavailable.' );
		}
		$parent_id  = (int) $parent->get_id();
		$provenance = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $parent_id );
		$owner_rows = is_array( $provenance ) && is_array( $provenance['owner_row_counts'] ?? null )
			? array_sum( array_map( 'intval', $provenance['owner_row_counts'] ) )
			: -1;
		if (
			! $this->source_write_locks_are_owned( array( (int) $variation->get_id(), $parent_id ) )
			|| is_wp_error( $provenance )
			|| ! empty( $provenance['duplicate_rows'] )
			|| ! empty( $provenance['invalid_key_rows'] )
			|| ! empty( $provenance['meta_exists'] )
			|| 0 !== $owner_rows
			|| 'trash' === (string) ( $provenance['post_status'] ?? '' )
			|| '' !== (string) $parent->get_sku()
		) {
			return $this->error( 'digitalogic_patris_materializer_parent_identity_conflict', 'The variable parent must remain Code-less and SKU-less.' );
		}

		try {
			$parent->set_name( sanitize_text_field( $enrichment['name_fa'] ) );
			if ( method_exists( $parent, 'set_short_description' ) ) {
				$parent->set_short_description( wp_kses_post( $enrichment['short_description_fa'] ) );
			}
			$parent->set_status( 'publish' );
			if ( method_exists( $parent, 'set_catalog_visibility' ) ) {
				$parent->set_catalog_visibility( 'visible' );
			}
			$parent->update_meta_data( '_digitalogic_patris_family_name', sanitize_text_field( $enrichment['patris_family_name'] ) );
			$parent->update_meta_data( '_digitalogic_variation_group', sanitize_text_field( $variation->get_meta( '_digitalogic_variation_group', true ) ) );
			$parent->update_meta_data( 'rank_math_title', sanitize_text_field( $enrichment['seo_title_fa'] ) );
			$parent->update_meta_data( 'rank_math_description', sanitize_text_field( $enrichment['seo_description_fa'] ) );
			$parent->update_meta_data( 'rank_math_focus_keyword', sanitize_text_field( $enrichment['focus_keyword_fa'] ) );
			$parent->update_meta_data( 'rank_math_primary_product_cat', (string) $category_term );
			$parent->save();
			if ( is_callable( array( 'WC_Product_Variable', 'sync' ) ) ) {
				WC_Product_Variable::sync( $parent->get_id() );
			}
			$this->flush_product_caches( $parent->get_id() );
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_parent_publish_failed', 'The variable parent could not be published safely.' );
		}

		return true;
	}

	private function flush_product_caches( $product_id ) {
		if (
			class_exists( 'WC_Cache_Helper' )
			&& is_callable( array( 'WC_Cache_Helper', 'invalidate_cache_group' ) )
		) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . (int) $product_id );
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( (int) $product_id );
		}
		clean_object_term_cache( (int) $product_id, 'product' );
		clean_post_cache( (int) $product_id );
	}

	/**
	 * Invalidate Rank Math sitemap storage after an applied catalog batch.
	 *
	 * Current Rank Math versions expose a cache API rather than a flush action.
	 * Keep the former action as a guarded fallback for older or custom
	 * integrations that registered it.
	 */
	private function invalidate_sitemap_cache() {
		if (
			class_exists( '\RankMath\Sitemap\Cache' )
			&& is_callable( array( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) )
		) {
			\RankMath\Sitemap\Cache::invalidate_storage();
			return;
		}

		if ( has_action( 'rank_math/sitemap/flush_cache' ) ) {
			do_action( 'rank_math/sitemap/flush_cache' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Preserve the former integration hook only as a compatibility fallback.
		}
	}

	/**
	 * Return every publication gate that is not satisfied.
	 *
	 * @return array
	 */
	private function publish_gates( $product, $record, $enrichment, $category_term ) {
		$gates = array();
		if ( $this->number( $record['total_stock'] ?? null ) <= 0 ) {
			$gates[] = 'positive_stock';
		}
		$price_source_kind     = (string) ( $record['price_source_kind'] ?? '' );
		$price_source_currency = (string) ( $record['price_source_currency'] ?? '' );
		$price_source_amount   = $this->number( $record['price_source_amount'] ?? null );
		$is_cny_source         = 'foreign_price' === $price_source_kind && 'CNY' === $price_source_currency;
		$is_partner_source     = 'partner_price' === $price_source_kind && 'IRR' === $price_source_currency;
		$is_direct_sale_source = 'sale_price_direct' === $price_source_kind && 'IRR' === $price_source_currency;
		$final_price           = $this->number( $record['final_price'] ?? null );
		$complete_partner_path = $is_partner_source && $price_source_amount > 0 && $final_price > 0;
		$complete_direct_path  = $is_direct_sale_source && $price_source_amount > 0 && $final_price > 0;
		if ( ( ! $is_cny_source && ! $is_partner_source && ! $is_direct_sale_source ) || $price_source_amount <= 0 ) {
			$gates[] = 'price_source';
		}
		if ( $final_price <= 0 ) {
			$gates[] = 'final_price';
		}
		if (
			! $is_direct_sale_source
			&& (
				! array_key_exists( 'markup_percent', $record )
				|| null === $record['markup_percent']
				|| ! is_numeric( $record['markup_percent'] )
				|| $this->number( $record['markup_percent'] ) < 0
			)
		) {
			$gates[] = 'markup_percent';
		}
		if (
			! $is_direct_sale_source
			&& (
				! array_key_exists( 'price_rounding_digits', $record )
				|| ! is_int( $record['price_rounding_digits'] )
				|| $record['price_rounding_digits'] < 0
				|| $record['price_rounding_digits'] > 9
				|| 'nearest_half_up' !== ( $record['price_rounding_mode'] ?? null )
			)
		) {
			$gates[] = 'price_rounding';
		}
		if ( $is_cny_source ) {
			if ( $this->number( $record['foreign_price'] ?? null ) <= 0 ) {
				$gates[] = 'foreign_price';
			}
			if (
				! array_key_exists( 'weight_grams', $record )
				|| null === $record['weight_grams']
				|| ! is_numeric( $record['weight_grams'] )
				|| $this->number( $record['weight_grams'] ) <= 0
			) {
				$gates[] = 'weight_grams';
			}
			if ( self::SHIPPING_METHOD !== (string) ( $record['shipping_method_id'] ?? '' ) ) {
				$gates[] = 'patris_air_express';
			}
			if ( $this->number( $record['shipping_price_per_kg'] ?? null ) <= 0 ) {
				$gates[] = 'shipping_price_per_kg';
			}
			if (
				! isset( $record['shipping_price_per_kg_currency'] )
				|| ! is_string( $record['shipping_price_per_kg_currency'] )
				|| ! in_array( $record['shipping_price_per_kg_currency'], array( 'CNY', 'IRR' ), true )
			) {
				$gates[] = 'shipping_price_per_kg_currency';
			}
			if ( $this->number( $record['irt_per_cny'] ?? null ) <= 0 ) {
				$gates[] = 'irt_per_cny';
			}
		} elseif ( $is_partner_source && $this->number( $record['partner_price_source'] ?? null ) <= 0 ) {
			$gates[] = 'partner_price';
		} elseif ( $is_direct_sale_source && $this->number( $record['sale_price_source'] ?? null ) <= 0 ) {
			$gates[] = 'sale_price_direct';
		}
		if ( $is_partner_source || $is_direct_sale_source ) {
			if ( self::DOMESTIC_METHOD !== (string) ( $record['shipping_method_id'] ?? '' ) ) {
				$gates[] = 'patris_domestic';
			}
			if (
				! array_key_exists( 'shipping_price_per_kg', $record )
				|| null === $record['shipping_price_per_kg']
				|| ! is_numeric( $record['shipping_price_per_kg'] )
				|| 0.0 !== (float) $record['shipping_price_per_kg']
			) {
				$gates[] = 'domestic_shipping_price_per_kg';
			}
			if ( 'IRR' !== ( $record['shipping_price_per_kg_currency'] ?? null ) ) {
				$gates[] = 'domestic_shipping_price_per_kg_currency';
			}
		}
		if (
			'' === trim( (string) ( $record['pricing_catalog_revision'] ?? '' ) )
			|| '' === trim( (string) ( $record['pricing_catalog_status'] ?? '' ) )
		) {
			$gates[] = 'pricing_assignment';
		}
		$ignored_warnings  = $complete_partner_path
			? array(
				'partner_price_fallback_used',
				'freight_not_applied_for_partner_price',
				'foreign_price_missing',
				'foreign_price_non_positive',
				'weight_missing',
				'weight_unparsed',
				'weight_ambiguous',
				'weight_source_conflict',
			)
			: array();
		if ( $complete_direct_path ) {
			$ignored_warnings = array_merge(
				$ignored_warnings,
				array(
					'sale_price_direct_fallback_used',
					'freight_not_applied_for_sale_price_direct',
					'foreign_price_missing',
					'foreign_price_non_positive',
					'weight_missing',
					'weight_unparsed',
					'weight_ambiguous',
					'weight_source_conflict',
				)
			);
		}
		$blocking_warnings = array_values(
			array_diff(
				is_array( $record['warnings'] ?? null ) ? $record['warnings'] : array(),
				$ignored_warnings
			)
		);
		if ( $blocking_warnings ) {
			$gates[] = 'patris_warnings';
		}
		if ( $category_term <= 0 ) {
			$gates[] = 'category';
		}
		if ( $is_cny_source ) {
			if ( (string) get_post_meta( $product->get_id(), Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) !== self::SHIPPING_METHOD ) {
				$gates[] = 'woocommerce_air_express';
			}
			if ( '' === (string) $product->get_weight() || $this->number( $product->get_weight() ) <= 0 ) {
				$gates[] = 'woocommerce_weight';
			}
		} elseif (
			( $is_partner_source || $is_direct_sale_source )
			&& (string) get_post_meta( $product->get_id(), Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) !== self::DOMESTIC_METHOD
		) {
			$gates[] = 'woocommerce_domestic';
		}
		if ( $this->number( $product->get_stock_quantity() ) <= 0 ) {
			$gates[] = 'woocommerce_stock';
		}
		if ( $this->number( $product->get_regular_price() ) <= 0 || $this->number( $product->get_price() ) <= 0 ) {
			$gates[] = 'woocommerce_price';
		}
		$image_id = (int) $product->get_image_id();
		if ( $image_id <= 0 || false === wp_get_attachment_url( $image_id ) ) {
			$gates[] = 'woocommerce_image';
		}
		foreach ( array( 'name_fa', 'short_description_fa', 'seo_title_fa', 'seo_description_fa', 'focus_keyword_fa' ) as $field ) {
			if ( '' === trim( wp_strip_all_tags( $enrichment[ $field ] ) ) ) {
				$gates[] = $field;
			}
		}
		if ( (string) $product->get_sku() !== (string) $record['product_code'] ) {
			$gates[] = 'sku';
		}
		if ( (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) !== (string) $record['product_code'] ) {
			$gates[] = 'patris_code';
		}

		return array_values( array_unique( $gates ) );
	}

	/**
	 * Store Rank Math-compatible product metadata without requiring Rank Math.
	 */
	private function apply_product_seo_meta( $product, $enrichment ) {
		$product->update_meta_data( 'rank_math_title', sanitize_text_field( $enrichment['seo_title_fa'] ) );
		$product->update_meta_data( 'rank_math_description', sanitize_text_field( $enrichment['seo_description_fa'] ) );
		$product->update_meta_data( 'rank_math_focus_keyword', sanitize_text_field( $enrichment['focus_keyword_fa'] ) );
	}

	/**
	 * Store term SEO metadata through the taxonomy API.
	 */
	private function apply_seo_meta( $term_id, $enrichment, $term = false ) {
		$values = array(
			'rank_math_title'         => $enrichment['seo_title_fa'],
			'rank_math_description'   => $enrichment['seo_description_fa'],
			'rank_math_focus_keyword' => $enrichment['focus_keyword_fa'],
		);
		foreach ( $values as $key => $value ) {
			if ( $term ) {
				update_term_meta( $term_id, $key, sanitize_text_field( $value ) );
			} else {
				update_post_meta( $term_id, $key, sanitize_text_field( $value ) );
			}
		}
	}

	/**
	 * Normalize the source/record identity used by automatic materialization.
	 *
	 * @param array $record Exact normalized product record.
	 * @param array $source Exact source identity.
	 * @return array|WP_Error
	 */
	private function source_record_identity( $record, $source ) {
		$code            = is_array( $record ) ? (string) ( $record['product_code'] ?? '' ) : '';
		$source_id       = is_array( $source ) ? (string) ( $source['id'] ?? '' ) : '';
		$dataset         = is_array( $source ) ? (string) ( $source['dataset'] ?? '' ) : '';
		$source_revision = is_array( $source ) ? (string) ( $source['revision'] ?? '' ) : '';
		if (
			'' === $code
			|| trim( $code ) !== $code
			|| '' === $source_id
			|| trim( $source_id ) !== $source_id
			|| '' === $dataset
			|| trim( $dataset ) !== $dataset
			|| 1 !== preg_match( '/^sha256:[a-f0-9]{64}$/', $source_revision )
		) {
			return $this->error( 'digitalogic_patris_materializer_source_identity_invalid', 'Automatic materialization requires one exact source record identity.' );
		}

		return array(
			'product_code'    => $code,
			'source_id'       => $source_id,
			'dataset'         => $dataset,
			'source_revision' => $source_revision,
		);
	}

	/**
	 * Return whether an exact Code is present in either quarantine shape.
	 *
	 * @param string $code Exact Product Code.
	 * @param array  $quarantined_codes Current quarantine projection.
	 * @return bool
	 */
	private function source_code_is_quarantined( $code, $quarantined_codes ) {
		if ( ! is_array( $quarantined_codes ) ) {
			return false;
		}
		if ( array_key_exists( $code, $quarantined_codes ) ) {
			return true;
		}
		foreach ( $quarantined_codes as $value ) {
			if ( is_string( $value ) && hash_equals( $code, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Classify only the canonical warning vocabulary shared with alert routing.
	 *
	 * @param WC_Product $product Verified product.
	 * @param array      $record Exact normalized product record.
	 * @return array
	 */
	private function canonical_missing_fields( $product, $record ) {
		$missing = array();
		$status  = (string) $product->get_meta( '_digitalogic_patris_price_status', true );
		if (
			in_array( $status, array( 'canonical_missing_unpriced', 'canonical_nonpositive_unpriced' ), true )
			|| '' === trim( (string) $product->get_regular_price() )
			|| '' === trim( (string) $product->get_price() )
		) {
			$missing[] = 'price';
		}
		if (
			! array_key_exists( 'total_stock', $record )
			|| null === $record['total_stock']
			|| ! is_numeric( $record['total_stock'] )
			|| (float) $record['total_stock'] <= 0
		) {
			$missing[] = 'stock';
		}
		if (
			! array_key_exists( 'weight_grams', $record )
			|| null === $record['weight_grams']
			|| ! is_numeric( $record['weight_grams'] )
			|| (float) $record['weight_grams'] <= 0
		) {
			$missing[] = 'weight';
		}

		$shipping_method   = (string) ( $record['shipping_method_id'] ?? '' );
		$shipping_currency = (string) ( $record['shipping_price_per_kg_currency'] ?? '' );
		$shipping_amount   = $record['shipping_price_per_kg'] ?? null;
		$domestic_freight  = self::DOMESTIC_METHOD === $shipping_method
			&& 'IRR' === $shipping_currency
			&& is_numeric( $shipping_amount )
			&& 0.0 === (float) $shipping_amount;
		$air_freight       = self::SHIPPING_METHOD === $shipping_method
			&& in_array( $shipping_currency, array( 'CNY', 'IRR' ), true )
			&& is_numeric( $shipping_amount )
			&& (float) $shipping_amount > 0;
		if ( ! $domestic_freight && ! $air_freight ) {
			$missing[] = 'freight';
		}
		if (
			! array_key_exists( 'markup_percent', $record )
			|| null === $record['markup_percent']
			|| ! is_numeric( $record['markup_percent'] )
			|| (float) $record['markup_percent'] < 0
		) {
			$missing[] = 'markup';
		}
		$image_id = (int) $product->get_image_id();
		if ( $image_id <= 0 || false === wp_get_attachment_url( $image_id ) ) {
			$missing[] = 'image';
		}
		foreach ( array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' ) as $seo_key ) {
			if ( '' === trim( wp_strip_all_tags( (string) $product->get_meta( $seo_key, true ) ) ) ) {
				$missing[] = 'seo';
				break;
			}
		}

		$missing = array_values( array_unique( $missing ) );
		sort( $missing, SORT_STRING );

		return $missing;
	}

	/**
	 * Return the one terminal class reserved for concrete identity hazards.
	 *
	 * @param string $reason Bounded internal hazard reason.
	 * @param string $code Exact Product Code when available.
	 * @return WP_Error
	 */
	private function identity_hazard( $reason, $code ) {
		return new WP_Error(
			'digitalogic_patris_materializer_identity_hazard',
			'The source product cannot be materialized until its identity conflict is reconciled.',
			array(
				'status'       => 409,
				'retryable'    => false,
				'reason'       => (string) $reason,
				'product_code' => (string) $code,
			)
		);
	}

	private function target_owned_by( $product, $source_id, $dataset, $code ) {
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		$readback   = Digitalogic_Product_Code_Editor::instance()->canonical_source_provenance_readback( $product_id );
		if ( is_wp_error( $readback ) || ! is_array( $readback['owner'] ?? null ) || ! is_array( $readback['owner_row_counts'] ?? null ) ) {
			return false;
		}

		return 1 === (int) ( $readback['owner_row_counts']['source_id'] ?? 0 )
			&& 1 === (int) ( $readback['owner_row_counts']['dataset'] ?? 0 )
			&& 1 === (int) ( $readback['owner_row_counts']['product_code'] ?? 0 )
			&& hash_equals( $source_id, (string) ( $readback['owner']['source_id'] ?? '' ) )
			&& hash_equals( $dataset, (string) ( $readback['owner']['dataset'] ?? '' ) )
			&& hash_equals( $code, (string) ( $readback['owner']['product_code'] ?? '' ) );
	}

	/**
	 * Create the bounded reviewed-materializer result projection.
	 *
	 * @param array $source_state Current exact source state.
	 * @param array $manifest Reviewed enrichment manifest.
	 * @param bool  $apply Whether writes are authorized.
	 * @param bool  $publish_ready Whether reviewed readiness was requested.
	 * @param int   $selected Selected source product count.
	 * @param int   $selected_positive_stock Positive-stock subset count.
	 * @return array
	 */
	private function new_result( $source_state, $manifest, $apply, $publish_ready, $selected, $selected_positive_stock ) {
		return array(
			'schema'                    => 'digitalogic.patris-catalog-materialization-result',
			'mode'                      => $apply ? 'apply' : 'dry_run',
			'publish_requested'         => $publish_ready,
			'source'                    => $manifest['source'],
			'source_revision'           => (string) ( $source_state['source']['revision'] ?? '' ),
			'selected_products'         => $selected,
			'selected_positive_stock'   => $selected_positive_stock,
			'planned_create'            => 0,
			'planned_create_variation'  => 0,
			'planned_adopt'             => 0,
			'planned_reconcile'         => 0,
			'created'                   => 0,
			'created_variations'        => 0,
			'converted_empty_variables' => 0,
			'adopted'                   => 0,
			'reconciled'                => 0,
			'air_express_assigned'      => 0,
			'domestic_assigned'         => 0,
			'publish_ready'             => 0,
			'publish_blocked'           => 0,
			'published'                 => 0,
			'published_incomplete'      => 0,
			'preserved_published'       => 0,
			'skipped'                   => 0,
			'failed'                    => 0,
			'categories'                => array(),
			'details'                   => array(),
			'details_truncated'         => 0,
		);
	}

	private function append_detail( &$result, $code, $reason, $extra = array() ) {
		if ( count( $result['details'] ) >= self::MAX_DETAILS ) {
			++$result['details_truncated'];
			return;
		}
		$result['details'][] = array_merge(
			array(
				'product_code' => (string) $code,
				'reason'       => (string) $reason,
			),
			is_array( $extra ) ? $extra : array()
		);
	}

	/**
	 * Normalize an explicit nonnegative batch limit without coercion.
	 *
	 * Zero is the documented unlimited value. Strings must use canonical base-10
	 * notation so negative, signed, decimal, exponent, and leading-zero values
	 * can never accidentally widen an apply run.
	 *
	 * @param mixed $value Raw limit option.
	 * @return int|WP_Error
	 */
	private function normalize_limit( $value ) {
		if ( is_int( $value ) ) {
			return $value >= 0
				? $value
				: $this->error( 'digitalogic_patris_materializer_limit_invalid', 'The batch limit must be zero or a canonical positive integer.' );
		}
		if ( ! is_string( $value ) || ! preg_match( '/^(0|[1-9][0-9]*)$/', $value ) ) {
			return $this->error( 'digitalogic_patris_materializer_limit_invalid', 'The batch limit must be zero or a canonical positive integer.' );
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return $this->error( 'digitalogic_patris_materializer_limit_invalid', 'The batch limit exceeds this platform.' );
		}

		return (int) $value;
	}

	private function normalize_code_filter( $codes ) {
		if ( ! is_array( $codes ) ) {
			return $this->error( 'digitalogic_patris_materializer_codes_invalid', 'The Code filter must be an array.' );
		}
		$result = array();
		foreach ( $codes as $code ) {
			if ( ! is_string( $code ) || ! $this->valid_code( $code ) ) {
				return $this->error( 'digitalogic_patris_materializer_codes_invalid', 'Every filtered Code must be an exact non-empty string.' );
			}
			$result[ $code ] = true;
		}

		return $result;
	}

	private function validate_object_shape( $value, $required, $allowed, $path ) {
		$missing = array_values( array_diff( $required, array_keys( $value ) ) );
		$unknown = array_values( array_diff( array_keys( $value ), $allowed ) );
		if ( ! empty( $missing ) || ! empty( $unknown ) ) {
			return new WP_Error(
				'digitalogic_patris_materializer_manifest_shape',
				'The enrichment manifest object has missing or unknown fields.',
				array(
					'path'    => $path,
					'missing' => $missing,
					'unknown' => $unknown,
				)
			);
		}

		return true;
	}

	private function canonical_id_or_null( $value ) {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return $this->error( 'digitalogic_patris_materializer_id_invalid', 'A target ID is invalid.' );
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return $this->error( 'digitalogic_patris_materializer_id_invalid', 'A target ID exceeds this platform.' );
		}

		return $value;
	}

	private function valid_code( $code ) {
		return '' !== $code && trim( $code ) === $code && strlen( $code ) <= 191 && ! preg_match( '/[\x00-\x1F\x7F]/', $code );
	}

	private function contains_persian( $value ) {
		return 1 === preg_match( '/[\x{0600}-\x{06FF}]/u', (string) $value );
	}

	private function number( $value ) {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	private function manifest_error( $path, $message ) {
		return new WP_Error(
			'digitalogic_patris_materializer_manifest_invalid',
			'The enrichment manifest is invalid.',
			array(
				'path'   => (string) $path,
				'reason' => (string) $message,
			)
		);
	}

	private function error( $code, $message, $data = array() ) {
		return new WP_Error( $code, $message, is_array( $data ) ? $data : array() );
	}

	private function acquire_lock() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $this->error( 'digitalogic_patris_materializer_lock_unavailable', 'The catalog materializer lock service is unavailable.' );
		}
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS ) );
		if ( 1 !== (int) $locked ) {
			return $this->error( 'digitalogic_patris_materializer_busy', 'Another catalog materialization is already running.' );
		}

		return true;
	}

	private function release_lock() {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		}
	}
}
