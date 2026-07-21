<?php
/**
 * Opt-in Patris catalog materialization from the validated product-sync state.
 *
 * The receiver remains the authority for typed Patris records. This service
 * only creates or explicitly adopts administrator-reviewed WooCommerce leaves,
 * reconciles their managed taxonomy, and applies human-reviewed Persian
 * enrichment. Nothing runs from HTTP or cron; WP-CLI is the only entry point.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Patris_Catalog_Materializer {

	public const MANIFEST_SCHEMA = 'digitalogic.patris-catalog-enrichment';

	public const CATEGORY_CODE_META    = '_digitalogic_patris_category_code';
	public const CATEGORY_KEY_META     = '_digitalogic_catalog_category_key';
	public const CATEGORY_TERM_META    = '_digitalogic_patris_category_term_id';
	public const CATEGORY_MANAGED_META = '_digitalogic_patris_category_managed';
	public const CATEGORY_ADOPTED_META = '_digitalogic_patris_category_adopted';
	public const OWNER_SOURCE_META     = '_digitalogic_patris_owner_source_id';
	public const OWNER_DATASET_META    = '_digitalogic_patris_owner_dataset';
	public const OWNER_CODE_META       = '_digitalogic_patris_owner_product_code';

	private const SHIPPING_METHOD      = 'air_express';
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

		$products = $this->validate_manifest_products( $manifest['products'] );
		if ( is_wp_error( $products ) ) {
			return $products;
		}
		$categories = $this->validate_manifest_categories( $manifest['categories'] );
		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		$normalized = array(
			'schema'     => self::MANIFEST_SCHEMA,
			'source'     => $manifest['source'],
			'products'   => $products,
			'categories' => $categories,
		);
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
			if ( $this->number( $products[ $code ]['total_stock'] ?? null ) <= 0 ) {
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

		$result = $this->new_result( $source_state, $manifest, $apply, $publish_ready, count( $selected ) );
		if ( empty( $selected ) ) {
			return $result;
		}

		$locked = false;
		if ( $apply ) {
			$locked = $this->acquire_lock();
			if ( is_wp_error( $locked ) ) {
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

				$product          = $target['product'];
				$original_status  = $product instanceof WC_Product ? (string) $product->get_status() : 'draft';
				$was_published    = 'publish' === $original_status;
				$is_new_simple    = 'planned_create' === $target['action'];
				$is_new_variation = 'planned_create_variation' === $target['action'];
				if ( $is_new_simple || $is_new_variation ) {
					$product = $is_new_variation
						? $this->create_variation_draft( $code, $enrichment, $source_id, $dataset )
						: $this->create_simple_draft( $code, $enrichment, $source_id, $dataset );
					if ( is_wp_error( $product ) ) {
						$this->append_detail( $result, $code, $product->get_error_code() );
						++$result['skipped'];
						continue;
					}
					++$result['created'];
					if ( $is_new_variation ) {
						++$result['created_variations'];
					}
				} elseif ( $enrichment['convert_empty_variable_to_simple'] && $product->is_type( 'variable' ) ) {
					$product = $this->convert_empty_variable_to_simple( $product );
					if ( is_wp_error( $product ) ) {
						$this->append_detail( $result, $code, $product->get_error_code() );
						++$result['failed'];
						continue;
					}
					++$result['converted_empty_variables'];
					if ( ! empty( $target['new_claim'] ) ) {
						++$result['adopted'];
					} else {
						++$result['reconciled'];
					}
				} elseif ( ! empty( $target['new_claim'] ) ) {
					++$result['adopted'];
				} else {
					++$result['reconciled'];
				}
				if ( $was_published ) {
					++$result['preserved_published'];
				}
				$updated = $this->apply_identity_and_enrichment(
					$product,
					$code,
					$source_id,
					$dataset,
					$enrichment,
					$category_term,
					$category_code
				);
				if ( is_wp_error( $updated ) ) {
					$this->append_detail( $result, $code, $updated->get_error_code() );
					++$result['failed'];
					continue;
				}

				try {
					Digitalogic_Patris_Feed::instance()->apply_product_feed( $product, $record );
				} catch ( Throwable $exception ) {
					$this->append_detail( $result, $code, 'woocommerce_feed_write_failed' );
					++$result['failed'];
					continue;
				}

				$assignment = Digitalogic_Shipping_Method_Service::instance()->assign_product_by_code(
					$code,
					self::SHIPPING_METHOD
				);
				if ( is_wp_error( $assignment ) ) {
					$this->append_detail( $result, $code, $assignment->get_error_code() );
					++$result['failed'];
					continue;
				}
				$product->update_meta_data( Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, self::SHIPPING_METHOD );
				++$result['air_express_assigned'];

				$gates = $this->publish_gates( $product, $record, $enrichment, $category_term );
				if ( empty( $gates ) ) {
					++$result['publish_ready'];
					if ( $publish_ready ) {
						$product->set_status( 'publish' );
						if ( method_exists( $product, 'set_catalog_visibility' ) && ! $product->is_type( 'variation' ) ) {
							$product->set_catalog_visibility( 'visible' );
						}
						$product->update_meta_data( '_digitalogic_patris_publish_ready_at', current_time( 'mysql' ) );
						try {
							$product->save();
						} catch ( Throwable $exception ) {
							$this->append_detail( $result, $code, 'publish_write_failed' );
							++$result['failed'];
							continue;
						}
						if ( $product->is_type( 'variation' ) ) {
							$parent_published = $this->publish_variation_parent( $product, $enrichment['parent_enrichment'], $category_term );
							if ( is_wp_error( $parent_published ) ) {
								try {
									$product->set_status( $original_status );
									$product->save();
								} catch ( Throwable $exception ) {
									$this->append_detail( $result, $code, 'publication_rollback_failed' );
								}
								$this->append_detail( $result, $code, $parent_published->get_error_code() );
								++$result['failed'];
								continue;
							}
						}
						$this->flush_product_caches( $product->get_id() );
						if ( ! $was_published ) {
							++$result['published'];
						}
					}
				} else {
					++$result['publish_blocked'];
					$this->append_detail( $result, $code, 'publish_blocked', array( 'gates' => $gates ) );
					if ( 'draft' !== (string) $product->get_status() ) {
						$product->set_status( 'draft' );
						if ( method_exists( $product, 'set_catalog_visibility' ) && ! $product->is_type( 'variation' ) ) {
							$product->set_catalog_visibility( 'hidden' );
						}
						$product->delete_meta_data( '_digitalogic_patris_publish_ready_at' );
						try {
							$product->save();
						} catch ( Throwable $exception ) {
							$this->append_detail( $result, $code, 'draft_write_failed' );
							++$result['failed'];
							continue;
						}
						if ( $was_published ) {
							--$result['preserved_published'];
						}
						$this->flush_product_caches( $product->get_id() );
					}
				}
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
				do_action( 'rank_math/sitemap/flush_cache' );
			}
		} finally {
			if ( $locked ) {
				$this->release_lock();
			}
		}

		return $result;
	}

	/**
	 * Validate product rows and explicit target ownership.
	 *
	 * @param mixed $rows Manifest products object.
	 * @return array|WP_Error
	 */
	private function validate_manifest_products( $rows ) {
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
			$category_override = $this->validate_category_override( $row['category_override'], $path . '.category_override' );
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
	 * @param mixed  $override Raw override.
	 * @param string $path Manifest path.
	 * @return array|null|WP_Error
	 */
	private function validate_category_override( $override, $path ) {
		if ( null === $override ) {
			return null;
		}
		if ( ! is_array( $override ) || array_is_list( $override ) ) {
			return $this->manifest_error( $path, 'must be null or an object' );
		}
		$shape = $this->validate_object_shape(
			$override,
			array( 'category_code', 'target_term_id' ),
			array( 'category_code', 'target_term_id' ),
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

		return array(
			'category_code'  => $category_code,
			'target_term_id' => $target_term_id,
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
				$inserted = wp_insert_term(
					$name,
					'product_cat',
					array(
						'parent' => $parent_id,
						'slug'   => 'patris-' . sanitize_title( (string) $category['category_code'] ),
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

		if ( ( $managed || $rename_reviewed ) && ( (string) $term->name !== $name || (int) $term->parent !== $parent_id ) ) {
			$updated = wp_update_term(
				$term_id,
				'product_cat',
				array(
					'name'   => $name,
					'parent' => $parent_id,
				)
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			$action = 'updated';
		} elseif ( ! $managed && ( (string) $term->name !== $name || (int) $term->parent !== $parent_id ) ) {
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
			if ( '' !== (string) $parent->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) || '' !== (string) $parent->get_meta( self::OWNER_CODE_META, true ) ) {
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

		$owner_code    = (string) $product->get_meta( self::OWNER_CODE_META, true );
		$owner_source  = (string) $product->get_meta( self::OWNER_SOURCE_META, true );
		$owner_dataset = (string) $product->get_meta( self::OWNER_DATASET_META, true );
		if ( '' !== $owner_code && ( $owner_code !== $code || $owner_source !== $source_id || $owner_dataset !== $dataset ) ) {
			return $this->error( 'digitalogic_patris_materializer_target_owned', 'The reviewed target is already owned by another Patris leaf.' );
		}
		$patris_code = (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true );
		if ( '' !== $patris_code && $patris_code !== $code ) {
			return $this->error( 'digitalogic_patris_materializer_patris_code_conflict', 'The reviewed target has a different Patris Code.' );
		}
		$sku = (string) $product->get_sku();
		if ( '' !== $sku && $sku !== $code ) {
			return $this->error( 'digitalogic_patris_materializer_sku_conflict', 'The reviewed target has a different non-empty SKU.' );
		}

		return true;
	}

	/**
	 * Validate a reviewed new child against an existing variable parent.
	 *
	 * @return WC_Product|WP_Error
	 */
	private function validate_new_variation_parent( $enrichment ) {
		$parent = wc_get_product( (int) $enrichment['target_parent_id'] );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return $this->error( 'digitalogic_patris_materializer_variation_parent_invalid', 'The reviewed variation parent is not a variable product.' );
		}
		if ( '' !== (string) $parent->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) || '' !== (string) $parent->get_meta( self::OWNER_CODE_META, true ) || '' !== (string) $parent->get_sku() ) {
			return $this->error( 'digitalogic_patris_materializer_parent_identity_conflict', 'The variable container must remain Code-less and SKU-less.' );
		}

		$taxonomy = $enrichment['attribute_taxonomy'];
		$term     = get_term( (int) $enrichment['attribute_term_id'], $taxonomy );
		if ( is_wp_error( $term ) || ! is_object( $term ) || empty( $term->slug ) ) {
			return $this->error( 'digitalogic_patris_materializer_variation_attribute_invalid', 'The reviewed variation attribute term is unavailable.' );
		}
		foreach ( (array) $parent->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );
			$child_attributes = $child && $child->is_type( 'variation' ) ? $child->get_variation_attributes() : array();
			if ( (string) ( $child_attributes[ 'attribute_' . $taxonomy ] ?? '' ) === (string) $term->slug ) {
				return $this->error( 'digitalogic_patris_materializer_variation_attribute_conflict', 'That reviewed attribute option already belongs to another child.' );
			}
		}

		return $parent;
	}

	/**
	 * Create one reviewed child and add only its exact option to the parent.
	 *
	 * @return WC_Product|WP_Error
	 */
	private function create_variation_draft( $code, $enrichment, $source_id, $dataset ) {
		$parent = $this->validate_new_variation_parent( $enrichment );
		if ( is_wp_error( $parent ) ) {
			return $parent;
		}
		$taxonomy = $enrichment['attribute_taxonomy'];
		$term_id  = (int) $enrichment['attribute_term_id'];
		$term     = get_term( $term_id, $taxonomy );
		$updated  = $this->add_parent_variation_attribute( $parent, $taxonomy, $term_id );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		try {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_status( 'draft' );
			$variation->set_sku( $code );
			$variation->set_attributes( array( $taxonomy => (string) $term->slug ) );
			$this->stage_managed_identity( $variation, $code, $source_id, $dataset );
			$product_id = $variation->save();
			if ( (int) $product_id <= 0 ) {
				throw new RuntimeException( 'WooCommerce returned an invalid variation ID.' );
			}

			return $variation;
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_variation_create_failed', 'The reviewed draft variation could not be created.' );
		}
	}

	/**
	 * Add one taxonomy option while preserving every existing parent attribute.
	 *
	 * @return true|WP_Error
	 */
	private function add_parent_variation_attribute( $parent, $taxonomy, $term_id ) {
		try {
			$attributes = $parent->get_attributes();
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
			$parent->save();
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_parent_attribute_failed', 'The reviewed parent attribute option could not be saved.' );
		}

		return true;
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
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );

		try {
			return new WC_Product_Simple( $product_id );
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_variable_conversion_failed', 'The empty variable target could not be reopened as a simple product.' );
		}
	}

	/**
	 * Create one code-owning simple draft. No variation family is inferred.
	 *
	 * @return WC_Product|WP_Error
	 */
	private function create_simple_draft( $code, $enrichment, $source_id, $dataset ) {
		try {
			$product = new WC_Product_Simple();
			$product->set_name( sanitize_text_field( $enrichment['name_fa'] ) );
			$product->set_sku( $code );
			$product->set_status( 'draft' );
			if ( method_exists( $product, 'set_catalog_visibility' ) ) {
				$product->set_catalog_visibility( 'hidden' );
			}
			$this->stage_managed_identity( $product, $code, $source_id, $dataset );
			$product_id = $product->save();
			if ( (int) $product_id <= 0 ) {
				throw new RuntimeException( 'WooCommerce returned an invalid product ID.' );
			}

			return $product;
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_create_failed', 'The draft product could not be created.' );
		}
	}

	/**
	 * Apply exact identity, reviewed enrichment, and additive taxonomy.
	 *
	 * @return true|WP_Error
	 */
	private function apply_identity_and_enrichment( $product, $code, $source_id, $dataset, $enrichment, $category_term, $reviewed_category_code ) {
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
			$this->assign_product_category( $product, $category_term );
			$product->save();
		} catch ( Throwable $exception ) {
			return $this->error( 'digitalogic_patris_materializer_product_write_failed', 'The reviewed product enrichment could not be saved.' );
		}

		if (
			(string) get_post_meta( $product->get_id(), Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) !== $code
			|| (string) get_post_meta( $product->get_id(), Digitalogic_Product_Identifier_Resolver::SKU_META, true ) !== $code
		) {
			return $this->error( 'digitalogic_patris_materializer_identity_readback_failed', 'The Patris Code/SKU identity failed readback verification.' );
		}

		return true;
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
		$product->update_meta_data( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, $code );
		$product->update_meta_data( self::OWNER_SOURCE_META, $source_id );
		$product->update_meta_data( self::OWNER_DATASET_META, $dataset );
		$product->update_meta_data( self::OWNER_CODE_META, $code );
	}

	/**
	 * Preserve manual categories and add the managed leaf to the public parent.
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
		if ( '' !== (string) $parent->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) || '' !== (string) $parent->get_meta( self::OWNER_CODE_META, true ) || '' !== (string) $parent->get_sku() ) {
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
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( (int) $product_id );
		}
		clean_post_cache( (int) $product_id );
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
		if ( $this->number( $record['foreign_price'] ?? null ) <= 0 ) {
			$gates[] = 'foreign_price';
		}
		if ( $this->number( $record['weight_grams'] ?? null ) <= 0 ) {
			$gates[] = 'weight_grams';
		}
		if ( $this->number( $record['final_price'] ?? null ) <= 0 ) {
			$gates[] = 'final_price';
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
		if (
			! array_key_exists( 'markup_percent', $record )
			|| null === $record['markup_percent']
			|| ! is_numeric( $record['markup_percent'] )
			|| $this->number( $record['markup_percent'] ) < 0
		) {
			$gates[] = 'markup_percent';
		}
		if ( $this->number( $record['irt_per_cny'] ?? null ) <= 0 ) {
			$gates[] = 'irt_per_cny';
		}
		if (
			'' === trim( (string) ( $record['pricing_catalog_revision'] ?? '' ) )
			|| '' === trim( (string) ( $record['pricing_catalog_status'] ?? '' ) )
		) {
			$gates[] = 'pricing_assignment';
		}
		if ( ! empty( $record['warnings'] ) ) {
			$gates[] = 'patris_warnings';
		}
		if ( $category_term <= 0 ) {
			$gates[] = 'category';
		}
		if ( (string) get_post_meta( $product->get_id(), Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) !== self::SHIPPING_METHOD ) {
			$gates[] = 'woocommerce_air_express';
		}
		if ( $this->number( $product->get_weight() ) <= 0 ) {
			$gates[] = 'woocommerce_weight';
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

	private function target_owned_by( $product, $source_id, $dataset, $code ) {
		return (string) $product->get_meta( self::OWNER_SOURCE_META, true ) === $source_id
			&& (string) $product->get_meta( self::OWNER_DATASET_META, true ) === $dataset
			&& (string) $product->get_meta( self::OWNER_CODE_META, true ) === $code;
	}

	private function new_result( $source_state, $manifest, $apply, $publish_ready, $selected ) {
		return array(
			'schema'                    => 'digitalogic.patris-catalog-materialization-result',
			'mode'                      => $apply ? 'apply' : 'dry_run',
			'publish_requested'         => $publish_ready,
			'source'                    => $manifest['source'],
			'source_revision'           => (string) ( $source_state['source']['revision'] ?? '' ),
			'selected_positive_stock'   => $selected,
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
			'publish_ready'             => 0,
			'publish_blocked'           => 0,
			'published'                 => 0,
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

	private function error( $code, $message ) {
		return new WP_Error( $code, __( $message, 'digitalogic' ) );
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
