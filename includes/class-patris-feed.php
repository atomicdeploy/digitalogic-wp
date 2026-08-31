<?php
/**
 * Normalized Patris feed ingestion.
 *
 * The external Patris service is responsible for Paradox reads, Patris text
 * conversion, and final price calculation. WordPress consumes a normalized API
 * payload and applies/report it against WooCommerce products.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Digitalogic_Unit_Converter')) {
    require_once __DIR__ . '/class-unit-converter.php';
}
if (!class_exists('Digitalogic_Product_Identifier_Resolver')) {
    require_once __DIR__ . '/class-product-identifier-resolver.php';
}

class Digitalogic_Patris_Feed {

    private const SETTINGS_OPTION           = 'digitalogic_patris_feed_settings';
    private const PRODUCTS_OPTION           = 'digitalogic_patris_feed_products';
    private const CUSTOMERS_OPTION          = 'digitalogic_patris_feed_customers';
    private const LAST_SYNC_OPTION          = 'digitalogic_patris_feed_last_sync';
    private const TOKEN_OPTION              = 'digitalogic_patris_feed_push_token';
    public const PRODUCT_SYNC_SECRET_OPTION = 'digitalogic_product_sync_secret';
    public const PRODUCT_SYNC_SCOPES_OPTION = 'digitalogic_product_sync_source_scopes';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('digitalogic_patris_feed_sync', array($this, 'pull_sync'));
    }

    public function get_settings() {
        $settings = get_option(self::SETTINGS_OPTION, array());
        $settings = is_array($settings) ? $settings : array();

        $settings = wp_parse_args($settings, array(
            'api_url'                  => '',
            'api_token'                => '',
            'selected_warehouses'      => array(),
            'legacy_url_replacements'  => array(),
            'image_quality_thresholds' => array(
                'very_low'    => 180,
                'low'         => 250,
                'review'      => 350,
                'soft_review' => 450,
            ),
            'stale_after_hours'        => 48,
            'sync_interval'            => '',
        ));

        unset($settings['shipping_methods']);
        return $settings;
    }

    public function update_settings($settings) {
        $current = $this->get_settings();
        $next    = is_array($settings) ? $settings : array();

        // Supplier shipping methods are managed by Digitalogic_Shipping_Method_Service.
        // Never revive the former unvalidated, free-form shipping_methods blob.
        unset($current['shipping_methods'], $next['shipping_methods']);

        if (isset($next['selected_warehouses']) && is_string($next['selected_warehouses'])) {
            $next['selected_warehouses'] = array_filter(array_map('trim', explode(',', $next['selected_warehouses'])));
        }

        if (isset($next['legacy_url_replacements']) && is_string($next['legacy_url_replacements'])) {
            $decoded                         = json_decode($next['legacy_url_replacements'], true);
            $next['legacy_url_replacements'] = is_array($decoded) ? $decoded : array();
        }

        $settings = array_merge($current, $this->sanitize_settings($next));
        update_option(self::SETTINGS_OPTION, $settings, false);

        if (isset($next['sync_interval'])) {
            $this->schedule_sync($settings['sync_interval']);
        }

        return $settings;
    }

    private function sanitize_settings($settings) {
        $clean = array();

        if (isset($settings['api_url'])) {
            $clean['api_url'] = esc_url_raw($settings['api_url']);
        }
        if (isset($settings['api_token'])) {
            $clean['api_token'] = sanitize_text_field(wp_unslash($settings['api_token']));
        }
        if (isset($settings['selected_warehouses'])) {
            $clean['selected_warehouses'] = array_values(array_filter(array_map('sanitize_text_field', (array) $settings['selected_warehouses'])));
        }
        if (isset($settings['legacy_url_replacements'])) {
            $clean['legacy_url_replacements'] = $this->sanitize_assoc_array($settings['legacy_url_replacements']);
        }
        if (isset($settings['image_quality_thresholds']) && is_array($settings['image_quality_thresholds'])) {
            $clean['image_quality_thresholds'] = array_map('absint', $settings['image_quality_thresholds']);
        }
        if (isset($settings['stale_after_hours'])) {
            $clean['stale_after_hours'] = max(1, absint($settings['stale_after_hours']));
        }
        if (isset($settings['sync_interval'])) {
            $clean['sync_interval'] = sanitize_key($settings['sync_interval']);
        }

        return $clean;
    }

    private function sanitize_assoc_array($items) {
        $clean = array();
        foreach ((array) $items as $key => $value) {
            $key = sanitize_text_field((string) $key);
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize_assoc_array($value);
            } else {
                $clean[$key] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }

    public function get_push_token() {
        $token = (string) get_option(self::TOKEN_OPTION, '');
        if ($token === '') {
            $token = wp_generate_password(48, false, false);
            add_option(self::TOKEN_OPTION, $token, '', 'no');
        }

        return $token;
    }

    public function schedule_sync($interval) {
        $timestamp = wp_next_scheduled('digitalogic_patris_feed_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'digitalogic_patris_feed_sync');
        }

        if (in_array($interval, array('hourly', 'twicedaily', 'daily'), true)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $interval, 'digitalogic_patris_feed_sync');
        }
    }

    public function pull_sync() {
        $settings = $this->get_settings();
        if (empty($settings['api_url'])) {
            return new WP_Error('digitalogic_patris_missing_url', __('Source API URL is not configured.', 'digitalogic'));
        }

        $headers = array('Accept' => 'application/json');
        if (!empty($settings['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['api_token'];
        }

        $response = wp_remote_get($settings['api_url'], array(
            'timeout' => 45,
            'headers' => $headers,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('digitalogic_patris_http_error', sprintf(__('Source API returned HTTP %d.', 'digitalogic'), $code));
        }

        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            return new WP_Error('digitalogic_patris_invalid_json', __('Source API did not return valid JSON.', 'digitalogic'));
        }

        return $this->import_payload($payload, 'pull');
    }

	public function import_payload( $payload, $source = 'push' ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'digitalogic_patris_invalid_payload', __( 'Source payload must be an object.', 'digitalogic' ) );
		}
		if ( array_key_exists( 'products', $payload ) && ! is_array( $payload['products'] ) ) {
			return new WP_Error( 'digitalogic_patris_invalid_products', __( 'The source products snapshot must be a list.', 'digitalogic' ) );
		}

		$products_supplied = array_key_exists( 'products', $payload ) || ( ! empty( $payload ) && array_is_list( $payload ) );
		$products          = $this->extract_list( $payload, 'products' );
		$customers         = $this->extract_list( $payload, 'customers' );

		if ( ! $products_supplied && empty( $customers ) ) {
			return new WP_Error( 'digitalogic_patris_empty_payload', __( 'Source payload did not contain products or customers.', 'digitalogic' ) );
		}

		$normalized_products = array();
		$results             = array(
			'source'                 => $source,
			'total'                  => 0,
			'updated'                => 0,
			'missing_in_woocommerce' => 0,
			'customers_imported'     => 0,
			'failed'                 => 0,
			'errors'                 => array(),
			'synced_at'              => current_time( 'mysql' ),
		);

		if ( $products_supplied ) {
			$receiver = Digitalogic_Product_Sync_Receiver::instance();
			$locked   = $receiver->acquire_source_identity_lock( 0 );
			if ( is_wp_error( $locked ) ) {
				return $locked;
			}

			try {
				foreach ( $products as $row ) {
					$product_data = $this->normalize_product( $row );
					if ( empty( $product_data['product_code'] ) ) {
						++$results['failed'];
						$results['errors'][] = __( 'Skipped product without product_code.', 'digitalogic' );
						continue;
					}

					++$results['total'];
					$normalized_products[ $product_data['product_code'] ] = $product_data;
				}

				// Publish and verify the accepted ownership snapshot before any row
				// write. The same source lock remains held for the complete pass.
				$stored = $this->replace_products_snapshot( $normalized_products );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}

				foreach ( $normalized_products as $product_data ) {
					$resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
						array( 'patris_code' => $product_data['product_code'] )
					);
					if ( is_wp_error( $resolved ) ) {
						if ( 'digitalogic_product_identifier_not_found' === $resolved->get_error_code() ) {
							++$results['missing_in_woocommerce'];
						} else {
							++$results['failed'];
							$results['errors'][] = 'digitalogic_product_identifier_ambiguous' === $resolved->get_error_code()
								? __( 'Skipped product because its exact Product Code is ambiguous.', 'digitalogic' )
								: __( 'Skipped product because its Product Code could not be resolved.', 'digitalogic' );
						}
						continue;
					}

					$product_id = (int) $resolved['woocommerce_id'];
					$product    = wc_get_product( $product_id );
					if ( ! $product ) {
						++$results['failed'];
						$results['errors'][] = sprintf(
							__( 'WooCommerce product for %s could not be loaded.', 'digitalogic' ),
							$product_data['product_code']
						);
						continue;
					}

					$applied = $this->apply_product_feed( $product, $product_data );
					if ( is_wp_error( $applied ) ) {
						++$results['failed'];
						$results['errors'][] = $applied->get_error_code();
						continue;
					}
					++$results['updated'];
				}
			} finally {
				$receiver->release_source_identity_lock();
			}
		}

		$normalized_customers          = $this->normalize_customers( $customers );
		$results['customers_imported'] = count( $normalized_customers );
		if ( ! empty( $customers ) ) {
			update_option( self::CUSTOMERS_OPTION, $normalized_customers, false );
		}
		update_option( self::LAST_SYNC_OPTION, $results, false );

		Digitalogic_Logger::instance()->log(
			'patris_feed_sync',
			'patris_feed',
			null,
			null,
			wp_json_encode( $results ),
			'Source feed synchronized'
		);

		do_action( 'digitalogic_patris_feed_synced', $results );

		return $results;
	}

	/**
	 * Replace and verify the active normalized product-source snapshot.
	 *
	 * This runs before row writes while the shared source-identity lock is held.
	 *
	 * @param array $products Complete accepted normalized product map.
	 * @return true|WP_Error
	 */
	private function replace_products_snapshot( $products ) {
		$before = $this->read_exact_products_snapshot();
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		try {
			update_option( self::PRODUCTS_OPTION, $products, false );
			wp_cache_delete( self::PRODUCTS_OPTION, 'options' );
			$after = $this->read_exact_products_snapshot();
			if ( ! is_wp_error( $after ) && $after['exists'] && $after['value'] === $products ) {
				return true;
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
		}

		$rollback_verified = $this->restore_products_snapshot( $before );
		return new WP_Error(
			$rollback_verified ? 'digitalogic_patris_products_snapshot_write_failed' : 'digitalogic_patris_products_snapshot_outcome_unknown',
			$rollback_verified
				? __( 'The source product snapshot could not be verified and was restored.', 'digitalogic' )
				: __( 'The source product snapshot outcome requires exact reconciliation.', 'digitalogic' ),
			array(
				'status'            => $rollback_verified ? 503 : 409,
				'retryable'         => $rollback_verified,
				'rollback_verified' => $rollback_verified,
			)
		);
	}

	/**
	 * Read the active product-source snapshot directly from the database.
	 *
	 * @return array|WP_Error Exact exists/value pair or a typed failure.
	 */
	private function read_exact_products_snapshot() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return new WP_Error( 'digitalogic_patris_products_snapshot_unavailable', __( 'The source product snapshot is unavailable.', 'digitalogic' ) );
		}
		$options = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- wpdb-owned table name cannot be a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_patris_products_snapshot */
			SELECT option_value
			FROM {$options}
			WHERE option_name = %s
			LIMIT 1",
			self::PRODUCTS_OPTION
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( false === $query ) {
			return new WP_Error( 'digitalogic_patris_products_snapshot_unavailable', __( 'The source product snapshot is unavailable.', 'digitalogic' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Source ownership readback must bypass object caches.
		$row = $wpdb->get_row( $query, ARRAY_A );
		if ( null === $row ) {
			if ( isset( $wpdb->last_error ) && '' !== trim( (string) $wpdb->last_error ) ) {
				return new WP_Error( 'digitalogic_patris_products_snapshot_unavailable', __( 'The source product snapshot is unavailable.', 'digitalogic' ) );
			}

			return array(
				'exists' => false,
				'value'  => array(),
			);
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'option_value', $row ) ) {
			return new WP_Error( 'digitalogic_patris_products_snapshot_unavailable', __( 'The source product snapshot is unavailable.', 'digitalogic' ) );
		}
		$value = maybe_unserialize( $row['option_value'] );
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'digitalogic_patris_products_snapshot_malformed', __( 'The source product snapshot is malformed.', 'digitalogic' ) );
		}

		return array(
			'exists' => true,
			'value'  => $value,
		);
	}

	/**
	 * Restore and verify the exact prior product-source snapshot.
	 *
	 * @param array $before Exact prior exists/value pair.
	 * @return bool
	 */
	private function restore_products_snapshot( $before ) {
		try {
			if ( $before['exists'] ) {
				update_option( self::PRODUCTS_OPTION, $before['value'], false );
			} else {
				delete_option( self::PRODUCTS_OPTION );
			}
			wp_cache_delete( self::PRODUCTS_OPTION, 'options' );
			$restored = $this->read_exact_products_snapshot();
		} catch ( Throwable $exception ) {
			unset( $exception );
			return false;
		}

		return ! is_wp_error( $restored )
			&& $restored['exists'] === $before['exists']
			&& $restored['value'] === $before['value'];
	}

    private function extract_list($payload, $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values($payload[$key]);
        }

        if ($key === 'products' && array_is_list($payload)) {
            return $payload;
        }

        return array();
    }

    public function get_products() {
        $products = get_option(self::PRODUCTS_OPTION, array());
        return is_array($products) ? $products : array();
    }

    public function get_customers() {
        $customers = get_option(self::CUSTOMERS_OPTION, array());
        return is_array($customers) ? $customers : array();
    }

    public function get_last_sync() {
        $sync = get_option(self::LAST_SYNC_OPTION, array());
        return is_array($sync) ? $sync : array();
    }

    public function verify_push_request(WP_REST_Request $request) {
        $expected = $this->get_push_token();
        $provided = $request->get_header('x-digitalogic-token');

        if (!$provided) {
            $provided = $request->get_param('token');
        }

        return is_string($provided) && hash_equals($expected, $provided);
    }

    /** Return the dedicated product-sync receiver secret. */
    public function get_product_sync_secret() {
        $secret = (string) get_option(self::PRODUCT_SYNC_SECRET_OPTION, '');
        if ('' !== $secret) {
            return $secret;
        }

        $generated = wp_generate_password(64, false, false);
        if (add_option(self::PRODUCT_SYNC_SECRET_OPTION, $generated, '', 'no')) {
            return $generated;
        }

        return (string) get_option(self::PRODUCT_SYNC_SECRET_OPTION, '');
    }

    /**
     * Return normalized exact source scopes for the product-sync secret.
     *
     * An empty list is deliberately unscoped for initial setup;
     * once configured, every request must match one exact {id,dataset} pair.
     */
    public function get_product_sync_source_scopes() {
        $configured = get_option(self::PRODUCT_SYNC_SCOPES_OPTION, array());
        $scopes     = array();
        foreach ((array) $configured as $scope) {
            if (!is_array($scope)) {
                continue;
            }
            $id      = isset($scope['id']) && is_string($scope['id']) ? trim($scope['id']) : '';
            $dataset = isset($scope['dataset']) && is_string($scope['dataset']) ? trim($scope['dataset']) : '';
            if ('' === $id || '' === $dataset || strlen($id) > 191 || strlen($dataset) > 191) {
                continue;
            }
            $scopes[$id . "\n" . $dataset] = array('id' => $id, 'dataset' => $dataset);
        }

        ksort($scopes, SORT_STRING);
        return array_values($scopes);
    }

    /**
     * Authenticate the living receiver with its dedicated header-only
     * secret and, when configured, an exact source ID/dataset scope.
     *
     * @param WP_REST_Request $request Current request.
     * @return bool
     */
    public function verify_product_sync_request(WP_REST_Request $request) {
		$payload = $request->get_json_params();
		$source  = is_array( $payload ) && isset( $payload['source'] ) && is_array( $payload['source'] )
			? $payload['source']
			: array();

		return $this->verify_product_sync_request_for_source( $request, $source );
	}

	/**
	 * Authenticate the receiver secret against an explicit source scope.
	 *
	 * Token-addressed GET/HEAD routes cannot safely depend on a JSON request
	 * body. Keeping this override explicit preserves the existing verifier while
	 * allowing those routes to bind authorization to stored snapshot identity.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param array           $source            Exact source identity.
	 * @param bool            $allow_generation Whether initial setup may create a secret.
	 * @return bool
	 */
	public function verify_product_sync_request_for_source( WP_REST_Request $request, $source, $allow_generation = true ) {
		return $this->verify_product_sync_credential_for_source(
			$request->get_header( 'x-patris-product-sync-secret' ),
			$source,
			$allow_generation
		);
	}

	/**
	 * Authenticate one raw header credential against an exact source scope.
	 *
	 * The credential remains server-side and is never returned to callers. This
	 * helper lets the outbound WebSocket subscriber reuse the same narrow scope
	 * without manufacturing a REST body or widening command privileges.
	 *
	 * @param mixed $provided         Header credential supplied by the caller.
	 * @param array $source           Exact source identity.
	 * @param bool  $allow_generation Whether initial setup may create a secret.
	 * @return bool
	 */
	public function verify_product_sync_credential_for_source( $provided, $source, $allow_generation = true ) {
		$expected = $allow_generation
			? $this->get_product_sync_secret()
			: (string) get_option( self::PRODUCT_SYNC_SECRET_OPTION, '' );

		if ( ! is_string( $provided ) || '' === $provided || '' === $expected || ! hash_equals( $expected, $provided ) ) {
            return false;
        }

        $configured_scopes = get_option(self::PRODUCT_SYNC_SCOPES_OPTION, array());
        $scopes            = $this->get_product_sync_source_scopes();
        if (empty($configured_scopes)) {
            return true;
        }
        if (empty($scopes)) {
            return false;
        }
		$source    = is_array( $source ) ? $source : array();
        $source_id = isset($source['id']) && is_string($source['id']) ? $source['id'] : '';
        $dataset   = isset($source['dataset']) && is_string($source['dataset']) ? $source['dataset'] : '';
        foreach ($scopes as $scope) {
            if (hash_equals($scope['id'], $source_id) && hash_equals($scope['dataset'], $dataset)) {
                return true;
            }
        }

		return false;
	}

	/**
	 * Return a nonsecret in-memory fingerprint for one currently authorized scope.
	 *
	 * Long-running WebSocket workers use this value to revoke existing service
	 * sockets after the secret or exact configured scope changes. The raw secret
	 * never leaves this service and the fingerprint is never serialized.
	 *
	 * @param array $source Exact source identity.
	 * @return string Empty when the credential or scope is unavailable.
	 */
	public function product_sync_credential_fingerprint_for_source( $source ) {
		$expected = (string) get_option( self::PRODUCT_SYNC_SECRET_OPTION, '' );
		$scopes   = $this->get_product_sync_source_scopes();
		$source   = is_array( $source ) ? $source : array();
		$id       = isset( $source['id'] ) && is_string( $source['id'] ) ? $source['id'] : '';
		$dataset  = isset( $source['dataset'] ) && is_string( $source['dataset'] ) ? $source['dataset'] : '';
		if ( '' === $expected || '' === $id || '' === $dataset || empty( $scopes ) ) {
			return '';
		}
		foreach ( $scopes as $scope ) {
			if ( hash_equals( $scope['id'], $id ) && hash_equals( $scope['dataset'], $dataset ) ) {
				return hash( 'sha256', self::PRODUCT_SYNC_SECRET_OPTION . "\0" . $expected . "\0" . $id . "\0" . $dataset );
			}
		}

		return '';
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Legacy private normalizers predate the strict documentation ruleset.
	/** Normalize one provider product row. */
	private function normalize_product( $row ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private normalizer.
		$row             = is_array( $row ) ? $row : array();
		$warehouse_stock = isset( $row['warehouse_stock'] ) && is_array( $row['warehouse_stock'] ) ? $row['warehouse_stock'] : array();

		$product = array(
			'product_code'          => $this->clean_string( $row['product_code'] ?? $row['code'] ?? '' ),
			'name'                  => $this->clean_string( $row['name'] ?? '' ),
			'serial'                => $this->clean_string( $row['serial'] ?? '' ),
			'unit'                  => $this->clean_string( $row['unit'] ?? '' ),
			'unit_id'               => $this->clean_string( $row['unit_id'] ?? '' ),
			'sale_price_source'     => $this->clean_number( $row['sale_price_source'] ?? null ),
			'partner_price_source'  => $this->clean_number( $row['partner_price_source'] ?? null ),
			'purchase_price_source' => $this->clean_number( $row['purchase_price_source'] ?? null ),
			'warehouse_stock'       => array_map( array( $this, 'clean_number' ), $warehouse_stock ),
			'total_stock'           => $this->clean_number( $row['total_stock'] ?? $row['stock'] ?? null ),
			'minimum_stock'         => $this->clean_number( $row['minimum_stock'] ?? null ),
			'foreign_currency'      => strtoupper( $this->clean_string( $row['foreign_currency'] ?? '' ) ),
			'foreign_price'         => $this->clean_number( $row['foreign_price'] ?? null ),
			'weight_grams'          => $this->clean_number( $row['weight_grams'] ?? null ),
			'location'              => $this->clean_string( $row['location'] ?? '' ),
			'final_price'           => $this->clean_number( $row['final_price'] ?? null ),
			'description'           => wp_kses_post( (string) ( $row['description'] ?? '' ) ),
			'source_updated_at'     => $this->clean_string( $row['source_updated_at'] ?? $row['updated_at'] ?? '' ),
			'flags'                 => isset( $row['flags'] ) && is_array( $row['flags'] ) ? array_values( array_map( 'sanitize_key', $row['flags'] ) ) : array(),
			'raw'                   => $row,
		);

		foreach ( array( 'price_source_amount', 'price_rounding_digits' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				if ( null === $row[ $field ] ) {
					$product[ $field ] = null;
					continue;
				}
				$value = $this->clean_number( $row[ $field ] );
				if ( null !== $value ) {
					$product[ $field ] = $value;
				}
			}
		}
		foreach ( array( 'price_source_currency', 'price_source_kind', 'price_rounding_mode' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$product[ $field ] = null === $row[ $field ]
					? null
					: $this->clean_string( $row[ $field ] );
			}
		}
		if ( isset( $product['price_source_currency'] ) ) {
			$product['price_source_currency'] = strtoupper( $product['price_source_currency'] );
		}

		return $product;
	}

	/** Normalize provider customer rows by exact customer code. */
	private function normalize_customers( $customers ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private normalizer.
		$normalized = array();

		foreach ( (array) $customers as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$code = $this->clean_string( $row['customer_code'] ?? $row['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}

			$normalized[ $code ] = array(
				'customer_code' => $code,
				'name'          => $this->clean_string( $row['name'] ?? '' ),
				'tel'           => $this->clean_string( $row['tel'] ?? '' ),
				'phone'         => $this->clean_string( $row['phone'] ?? '' ),
				'mobile'        => $this->clean_string( $row['mobile'] ?? '' ),
				'email'         => sanitize_email( $row['email'] ?? '' ),
				'address'       => $this->clean_string( $row['address'] ?? '' ),
				'national_code' => $this->clean_string( $row['national_code'] ?? '' ),
				'postal_code'   => $this->clean_string( $row['postal_code'] ?? '' ),
				'updated_at'    => $this->clean_string( $row['updated_at'] ?? '' ),
				'raw'           => $row,
			);
		}

		return $normalized;
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag

	/**
	 * Apply a normalized Patris product through the shared WooCommerce writer.
	 *
	 * Both row imports and the transformed-only receiver use this
	 * method so stock, weight, pricing, and Patris metadata cannot drift into
	 * parallel implementations. Canonical callers do not pass a raw payload.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param array      $data    Validated normalized product.
	 * @return mixed|WP_Error Guard callback result or a bounded identity error.
	 */
	public function apply_product_feed( WC_Product $product, $data ) {
		$expected_product_id   = (int) $product->get_id();
		$expected_product_code = (string) $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true );
		$receiver              = Digitalogic_Product_Sync_Receiver::instance();
		$locked                = $receiver->acquire_source_identity_lock( 0 );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		try {
			$data = is_array( $data ) ? $data : array();

			return Digitalogic_Product_Write_Lock::instance()->with_product_lock(
				$expected_product_id,
				function () use ( $expected_product_id, $expected_product_code, $data ) {
					return $this->apply_product_feed_locked( $expected_product_id, $expected_product_code, $data );
				},
				0
			);
		} finally {
			$receiver->release_source_identity_lock();
		}
	}

	/**
	 * Apply only canonical pricing projection fields.
	 *
	 * Currency/profit reconciliation must not replay snapshot stock, weight,
	 * or other operational fields that may have changed after import.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param array      $data    Canonical repriced product.
	 * @return mixed Guard callback result.
	 */
	public function apply_product_pricing( WC_Product $product, $data ) {
		return Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write(
			function () use ( $product, $data ) {
				$this->stage_product_pricing( $product, $data );
				$product->save();
				Digitalogic_Patris_Price_Policy::instance()->invalidate( $product );
			}
		);
	}

	/**
	 * Capture the bounded feed surface for an enclosing source transaction.
	 *
	 * The materializer uses this only while it already owns the shared source
	 * and exact product locks. It deliberately does not acquire another lock or
	 * expose any source payload.
	 *
	 * @return array|WP_Error
	 */
	public function capture_locked_product_feed_backup( WC_Product $product ) {
		$product_id = (int) $product->get_id();
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return $this->source_write_outcome_unknown( $product_id );
		}

		return $this->capture_product_feed_backup( $product );
	}

	/** Restore the bounded feed surface inside an enclosing source transaction. */
	public function restore_locked_product_feed_backup( WC_Product $product, $backup ) {
		$product_id = (int) $product->get_id();
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return false;
		}

		return $this->restore_product_feed_backup( $product, $backup );
	}

	/**
	 * Capture the exact feed projection while an enclosing source transaction owns the locks.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $data Normalized source row.
	 * @return array|WP_Error
	 */
	public function capture_locked_product_feed_expected( $product_id, $data ) {
		$product_id = absint( $product_id );
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return $this->source_write_outcome_unknown( $product_id );
		}
		$product = $this->fresh_product_for_source_readback( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'digitalogic_patris_product_projection_readback_failed',
				__( 'The source product projection could not be verified.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		return $this->capture_product_feed_expected( $product, is_array( $data ) ? $data : array() );
	}

	/**
	 * Recheck a previously captured feed projection after every later row save.
	 *
	 * @param int   $product_id Product or variation ID.
	 * @param array $expected Expected exact projection.
	 * @return true|WP_Error
	 */
	public function verify_locked_product_feed_expected( $product_id, $expected ) {
		$product_id = absint( $product_id );
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return $this->source_write_outcome_unknown( $product_id );
		}

		return $this->verify_product_feed_expected( $product_id, $expected );
	}

	/**
	 * Prove that the complete current feed projection already matches one row.
	 *
	 * Explicit legacy ownership repair may avoid a redundant WooCommerce save
	 * only after the same canonical feed staging code has produced an in-memory
	 * expectation and a fresh, cache-bypassed database read matches it exactly.
	 * The caller must own both the shared source lock and the product lock, so a
	 * concurrent stock, price, or identity write cannot race this proof.
	 *
	 * @param int   $product_id Exact WooCommerce product or variation ID.
	 * @param array $data       Exact normalized source row.
	 * @return true|WP_Error
	 */
	public function verify_locked_product_feed_projection( $product_id, $data ) {
		$product_id = absint( $product_id );
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return $this->source_write_outcome_unknown( $product_id );
		}

		$product = $this->fresh_product_for_source_readback( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'digitalogic_patris_product_projection_readback_failed',
				__( 'The source product projection could not be verified.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$this->stage_product_feed( $product, is_array( $data ) ? $data : array() );
		$expected = $this->capture_product_feed_expected( $product, $data );

		return $this->verify_product_feed_expected( $product_id, $expected );
	}

	/** Apply one source row while both the source and exact product locks are owned. */
	private function apply_product_feed_locked( $expected_product_id, $expected_product_code, $data ) {
		$product_code = is_string( $data['product_code'] ?? null ) ? $data['product_code'] : '';
		if ( '' === $product_code ) {
			return new WP_Error(
				'digitalogic_patris_product_code_invalid',
				__( 'The source product requires an exact string Product Code.', 'digitalogic' ),
				array( 'status' => 422 )
			);
		}

		// The caller may have resolved and loaded this object before either lock.
		// Start the critical section from a fresh WooCommerce object and exact DB
		// identity so no stale stock, price, enrichment, or Code enters backup.
		$product = $this->fresh_product_for_source_readback( $expected_product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error(
				'digitalogic_patris_product_binding_changed',
				__( 'The exact Product Code binding changed before the source write.', 'digitalogic' ),
				array( 'status' => 409, 'retryable' => true )
			);
		}
		$current = Digitalogic_Product_Code_Editor::instance()->canonical_source_backup( $expected_product_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$current_code = ! empty( $current['meta_exists'] ) ? (string) $current['product_code'] : '';
		if ( ! hash_equals( $expected_product_code, $current_code ) ) {
			return new WP_Error(
				'digitalogic_patris_product_binding_changed',
				__( 'The exact Product Code binding changed before the source write.', 'digitalogic' ),
				array( 'status' => 409, 'retryable' => true )
			);
		}

		$resolver = Digitalogic_Product_Identifier_Resolver::instance();
		$resolved = $resolver->resolve( array( 'woocommerce_id' => (string) $expected_product_id ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if (
			(int) ( $resolved['woocommerce_id'] ?? 0 ) !== $expected_product_id
			|| ! hash_equals( $current_code, (string) ( $resolved['patris_code'] ?? '' ) )
		) {
			return new WP_Error(
				'digitalogic_patris_product_binding_changed',
				__( 'The exact Product Code binding changed before the source write.', 'digitalogic' ),
				array( 'status' => 409, 'retryable' => true )
			);
		}

		$desired_binding = $resolver->resolve( array( 'patris_code' => $product_code ) );
		if ( ! is_wp_error( $desired_binding ) && (int) ( $desired_binding['woocommerce_id'] ?? 0 ) !== $expected_product_id ) {
			return new WP_Error(
				'digitalogic_patris_product_code_conflict',
				__( 'The exact Product Code belongs to another WooCommerce product or variation.', 'digitalogic' ),
				array( 'status' => 409 )
			);
		}
		if ( is_wp_error( $desired_binding ) && 'digitalogic_product_identifier_not_found' !== $desired_binding->get_error_code() ) {
			return $desired_binding;
		}
		$preflight = Digitalogic_Product_Code_Editor::instance()->preflight_canonical_source_write( $expected_product_id, $product_code );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		$backup = $this->capture_product_feed_backup( $product );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		try {
			$applied = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'legacy_feed',
				array(
					'product_id' => $expected_product_id,
					'operation'  => 'set',
					'value'      => $product_code,
				),
				function () use ( $product, $data ) {
					return Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write(
						function () use ( $product, $data ) {
							$this->apply_product_feed_authorized( $product, $data );

							return $this->capture_product_feed_expected( $product, $data );
						}
					);
				}
			);
		} catch ( Throwable $exception ) {
			$applied = new WP_Error(
				'digitalogic_patris_product_write_failed',
				__( 'The source product write failed and must be rolled back.', 'digitalogic' ),
				array( 'status' => 503, 'retryable' => true )
			);
		}
		if ( is_wp_error( $applied ) ) {
			if ( ! $this->source_write_locks_are_owned( $expected_product_id ) ) {
				return $this->source_write_outcome_unknown( $expected_product_id, $applied );
			}
			return $this->rollback_product_feed_failure( $product, $backup, $applied );
		}
		if ( ! $this->source_write_locks_are_owned( $expected_product_id ) ) {
			return $this->source_write_outcome_unknown( $expected_product_id );
		}

		$verified = Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_write( $expected_product_id, $product_code );
		if ( is_wp_error( $verified ) ) {
			return $this->source_write_locks_are_owned( $expected_product_id )
				? $this->rollback_product_feed_failure( $product, $backup, $verified )
				: $this->source_write_outcome_unknown( $expected_product_id, $verified );
		}
		$projection_verified = $this->verify_product_feed_expected( $expected_product_id, $applied );
		if ( is_wp_error( $projection_verified ) ) {
			return $this->source_write_locks_are_owned( $expected_product_id )
				? $this->rollback_product_feed_failure( $product, $backup, $projection_verified )
				: $this->source_write_outcome_unknown( $expected_product_id, $projection_verified );
		}

		return $this->source_write_locks_are_owned( $expected_product_id )
			? true
			: $this->source_write_outcome_unknown( $expected_product_id );
	}

	/** Capture the exact bounded projection staged on the Woo object after save. */
	private function capture_product_feed_expected( $product, $data ) {
		$meta        = array();
		$direct_keys = array();
		foreach ( $this->feed_meta_fields() as $field => $definition ) {
			$direct_keys[ $definition[0] ] = array_key_exists( $field, $data ) && null !== $data[ $field ];
		}
		$direct_keys['_digitalogic_patris_null_fields']    = true;
		$direct_keys['_digitalogic_patris_missing_fields'] = true;
		foreach ( $this->feed_meta_keys() as $key ) {
			$value        = $product->get_meta( $key, true );
			$exists       = array_key_exists( $key, $direct_keys ) ? $direct_keys[ $key ] : '' !== $value;
			$meta[ $key ] = $exists ? array( $this->normalize_meta_readback_value( $value ) ) : array();
		}

		return array(
			'meta'  => $meta,
			'props' => array(
				'weight'         => (string) $product->get_weight(),
				'manage_stock'   => $product->get_manage_stock(),
				'stock_quantity' => $product->get_stock_quantity(),
				'stock_status'   => (string) $product->get_stock_status(),
				'regular_price'  => (string) $product->get_regular_price(),
				'sale_price'     => (string) $product->get_sale_price(),
				'price'          => (string) $product->get_price(),
			),
		);
	}

	/**
	 * Match WordPress/MySQL scalar metadata storage semantics for strict readback.
	 *
	 * WooCommerce keeps freshly assigned scalar metadata in its original PHP
	 * type on the in-memory object, while wp_postmeta stores every non-serialized
	 * scalar as text. Exact verification must therefore compare the value that a
	 * fresh database read will return, not the transient object type.
	 *
	 * @param mixed $value In-memory metadata value after save.
	 * @return mixed Database readback value.
	 */
	private function normalize_meta_readback_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return (string) $value;
	}

	/**
	 * Normalize only a verification copy without changing rollback backups.
	 *
	 * @param array<string,array<int,mixed>> $projection Metadata projection.
	 * @return array<string,array<int,mixed>> Normalized metadata projection.
	 */
	private function normalize_meta_readback_projection( $projection ) {
		$normalized = array();
		foreach ( (array) $projection as $key => $values ) {
			$normalized[ $key ] = array_map( array( $this, 'normalize_meta_readback_value' ), (array) $values );
		}

		return $normalized;
	}

	/** Verify every feed field from DB/fresh Woo state before terminal success. */
	private function verify_product_feed_expected( $product_id, $expected ) {
		if ( ! is_array( $expected ) || ! is_array( $expected['meta'] ?? null ) || ! is_array( $expected['props'] ?? null ) ) {
			return new WP_Error(
				'digitalogic_patris_product_projection_readback_failed',
				__( 'The source product projection could not be verified.', 'digitalogic' ),
				array( 'status' => 503, 'retryable' => true )
			);
		}
		$meta = $this->read_exact_meta_rows( $product_id, array_keys( $expected['meta'] ) );

		$fresh = $this->fresh_product_for_source_readback( $product_id );

		$expects_unavailable = '' === trim( (string) ( $expected['props']['regular_price'] ?? '' ) )
			&& '' === trim( (string) ( $expected['props']['sale_price'] ?? '' ) )
			&& '' === trim( (string) ( $expected['props']['price'] ?? '' ) )
			&& 'outofstock' === (string) ( $expected['props']['stock_status'] ?? '' );

		$unavailable_matches = ! $expects_unavailable
			|| $this->unavailable_price_projection_matches( $product_id, $fresh );
		if (
			is_wp_error( $meta )
			|| $this->normalize_meta_readback_projection( $meta ) !== $this->normalize_meta_readback_projection( $expected['meta'] )
			|| ! $fresh instanceof WC_Product
			|| ! $this->source_props_match( $fresh, $expected['props'] )
			|| ! $unavailable_matches
		) {
			return new WP_Error(
				'digitalogic_patris_product_projection_readback_failed',
				__( 'The source product projection did not pass exact database readback.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		return true;
	}

	/** Capture every field the legacy source writer can change. */
	private function capture_product_feed_backup( $product ) {
		$product_id = $product instanceof WC_Product ? (int) $product->get_id() : 0;
		$canonical  = Digitalogic_Product_Code_Editor::instance()->canonical_source_backup( $product_id );
		if ( $product_id <= 0 || is_wp_error( $canonical ) ) {
			return is_wp_error( $canonical ) ? $canonical : new WP_Error( 'digitalogic_patris_product_backup_unavailable', __( 'The source product backup is unavailable.', 'digitalogic' ) );
		}
		$meta = $this->read_exact_meta_rows( $product_id, $this->product_feed_backup_meta_keys() );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		$stock_lookup = $this->read_exact_stock_lookup_projection( $product_id );
		$price_lookup = $this->read_exact_price_lookup_projection( $product_id );
		if ( is_wp_error( $stock_lookup ) || is_wp_error( $price_lookup ) ) {
			return is_wp_error( $stock_lookup ) ? $stock_lookup : $price_lookup;
		}

		return array(
			'product_id'   => $product_id,
			'canonical'    => $canonical,
			'meta'         => $meta,
			'stock_lookup' => $stock_lookup,
			'price_lookup' => $price_lookup,
			'props'        => array(
				'weight'         => (string) $product->get_weight(),
				'manage_stock'   => $product->get_manage_stock(),
				'stock_quantity' => $product->get_stock_quantity(),
				'stock_status'   => (string) $product->get_stock_status(),
				'regular_price'  => (string) $product->get_regular_price(),
				'sale_price'     => (string) $product->get_sale_price(),
				'price'          => (string) $product->get_price(),
			),
		);
	}

	/** Restore and verify the targeted source-writer backup. */
	private function restore_product_feed_backup( $product, $backup ) {
		$product_id = (int) ( $backup['product_id'] ?? 0 );
		if ( ! $product instanceof WC_Product || $product_id <= 0 || $product_id !== (int) $product->get_id() ) {
			return false;
		}
		$canonical = $backup['canonical'];
		try {
			$restored = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
				'legacy_feed',
				array(
					'product_id' => $product_id,
					'operation'  => $canonical['meta_exists'] ? 'set' : 'delete',
					'value'      => (string) $canonical['product_code'],
				),
					function () use ( $product, $backup, $canonical, $product_id ) {
						return Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write(
							function () use ( $product, $backup, $canonical, $product_id ) {
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
							$product->set_weight( $backup['props']['weight'] );
							$product->set_manage_stock( $backup['props']['manage_stock'] );
							$product->set_stock_quantity( $backup['props']['stock_quantity'] );
							$product->set_stock_status( $backup['props']['stock_status'] );
							$product->set_regular_price( $backup['props']['regular_price'] );
							$product->set_sale_price( $backup['props']['sale_price'] );
								$product->set_price( $backup['props']['price'] );
								$saved = $product->save();
								if (
									! $saved
									|| ! $this->restore_exact_meta_rows( $product_id, $backup['meta'] )
									|| ! $this->restore_exact_stock_lookup_projection( $product_id, $backup['stock_lookup'] ?? null )
									|| ! $this->restore_exact_price_lookup_projection( $product_id, $backup['price_lookup'] ?? null )
								) {
									return false;
								}

								return $saved;
							}
						);
				}
			);
			if ( is_wp_error( $restored ) ) {
				return false;
			}

			$canonical_restored = Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_restore( $product_id, $canonical );
			if ( is_wp_error( $canonical_restored ) ) {
				$deleted = Digitalogic_Product_Code_Write_Guard::instance()->with_authorized_write(
					'legacy_feed',
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
						'legacy_feed',
						array( 'product_id' => $product_id, 'operation' => 'set', 'value' => $canonical['product_code'] ),
						static function () use ( $product_id, $canonical ) {
							return update_post_meta( $product_id, Digitalogic_Product_Code_Editor::META_KEY, $canonical['product_code'] );
						}
					);
					if ( is_wp_error( $written ) || false === $written ) {
						return false;
					}
				}
			}
			Digitalogic_Patris_Price_Policy::instance()->invalidate( $product );
		} catch ( Throwable $exception ) {
			return false;
		}

		if ( is_wp_error( Digitalogic_Product_Code_Editor::instance()->verify_canonical_source_restore( $product_id, $canonical ) ) ) {
			return false;
		}
		$meta_readback = $this->read_exact_meta_rows( $product_id, array_keys( $backup['meta'] ) );
		if ( is_wp_error( $meta_readback ) || $meta_readback !== $backup['meta'] ) {
			return false;
		}
		$lookup_readback = $this->read_exact_stock_lookup_projection( $product_id );
		if ( is_wp_error( $lookup_readback ) || ( $backup['stock_lookup'] ?? null ) !== $lookup_readback ) {
			return false;
		}
		$price_lookup_readback = $this->read_exact_price_lookup_projection( $product_id );
		if ( is_wp_error( $price_lookup_readback ) || ( $backup['price_lookup'] ?? null ) !== $price_lookup_readback ) {
			return false;
		}
		$fresh = $this->fresh_product_for_source_readback( $product_id );
		if ( ! $fresh instanceof WC_Product || ! $this->source_props_match( $fresh, $backup['props'] ) ) {
			return false;
		}

		return true;
	}

	/** Restore the exact ordered value/count state for every touched non-canonical key. */
	private function restore_exact_meta_rows( $product_id, $states ) {
		foreach ( (array) $states as $key => $rows ) {
			if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
				return false;
			}
			delete_post_meta( (int) $product_id, (string) $key );
			foreach ( (array) $rows as $value ) {
				if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
					return false;
				}
				if ( false === add_post_meta( (int) $product_id, (string) $key, $value, false ) ) {
					return false;
				}
			}
		}
		wp_cache_delete( (int) $product_id, 'post_meta' );

		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return false;
		}
		$readback = $this->read_exact_meta_rows( $product_id, array_keys( (array) $states ) );

		return ! is_wp_error( $readback ) && $readback === $states;
	}

	/**
	 * Read ordered non-canonical metadata rows directly from MySQL.
	 *
	 * Metadata IDs are intentionally not restored: only the exact ordered value
	 * sequence and row count for each bounded key are part of the source-writer
	 * backup. Values are compared after WordPress unserialization so supported
	 * arrays retain their canonical metadata semantics.
	 */
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
			return new WP_Error(
				'digitalogic_patris_product_backup_unavailable',
				__( 'The source product backup is unavailable.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
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
			return new WP_Error( 'digitalogic_patris_product_backup_unavailable', __( 'The source product backup is unavailable.', 'digitalogic' ), array( 'status' => 503, 'retryable' => true ) );
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact rollback backup must bypass metadata/object caches.
		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'digitalogic_patris_product_backup_unavailable', __( 'The source product backup is unavailable.', 'digitalogic' ), array( 'status' => 503, 'retryable' => true ) );
		}

		$result = array_fill_keys( $keys, array() );
		foreach ( $rows as $row ) {
			$key = (string) ( $row['meta_key'] ?? '' );
			if ( ! array_key_exists( $key, $result ) ) {
				return new WP_Error( 'digitalogic_patris_product_backup_unavailable', __( 'The source product backup is unavailable.', 'digitalogic' ), array( 'status' => 503, 'retryable' => true ) );
			}
			$result[ $key ][] = maybe_unserialize( $row['meta_value'] ?? '' );
		}

		return $result;
	}

	/**
	 * Read the exact WooCommerce stock lookup projection directly from MySQL.
	 *
	 * @param int $product_id Exact WooCommerce product ID.
	 * @return array<string,string|null>|WP_Error
	 */
	private function read_exact_stock_lookup_projection( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->prefix )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_row' )
		) {
			return new WP_Error(
				'digitalogic_patris_product_backup_unavailable',
				__( 'The source product backup is unavailable.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is the exact site-scoped WooCommerce lookup table; product ID uses a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_patris_stock_lookup_readback */ SELECT stock_quantity, stock_status FROM {$lookup_table} WHERE product_id = %d",
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact rollback and terminal readback must bypass object caches.
		$row = false === $query ? null : $wpdb->get_row( $query, ARRAY_A );
		if (
			! is_array( $row )
			|| ! array_key_exists( 'stock_quantity', $row )
			|| ! array_key_exists( 'stock_status', $row )
			|| '' !== (string) $wpdb->last_error
		) {
			return new WP_Error(
				'digitalogic_patris_product_backup_unavailable',
				__( 'The source product backup is unavailable.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		return array(
			'stock_quantity' => null === $row['stock_quantity'] ? null : (string) $row['stock_quantity'],
			'stock_status'   => (string) $row['stock_status'],
		);
	}

	/**
	 * Read Woo's exact customer-price lookup projection without object caches.
	 *
	 * WooCommerce may use numeric zero as an internal unavailable-price sentinel
	 * when every raw customer price is blank. The sentinel is validated here but
	 * is never promoted into a product price or exposed as a customer price.
	 *
	 * @param int $product_id Exact WooCommerce product ID.
	 * @return array<string,string|null>|WP_Error
	 */
	private function read_exact_price_lookup_projection( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->prefix )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_row' )
		) {
			return new WP_Error(
				'digitalogic_patris_product_backup_unavailable',
				__( 'The source product backup is unavailable.', 'digitalogic' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is the exact site-scoped WooCommerce lookup table; product ID uses a placeholder.
		$query = $wpdb->prepare(
			"/* digitalogic_patris_price_lookup_readback */ SELECT min_price, max_price, onsale FROM {$lookup_table} WHERE product_id = %d",
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact terminal readback must bypass WooCommerce caches.
		$row = false === $query ? null : $wpdb->get_row( $query, ARRAY_A );
		if (
			! is_array( $row )
			|| ! array_key_exists( 'min_price', $row )
			|| ! array_key_exists( 'max_price', $row )
			|| ! array_key_exists( 'onsale', $row )
			|| '' !== (string) $wpdb->last_error
		) {
			return new WP_Error(
				'digitalogic_patris_product_backup_unavailable',
				__( 'The source product backup is unavailable.', 'digitalogic' ),
				array( 'status' => 503, 'retryable' => true )
			);
		}

		return array(
			'min_price' => null === $row['min_price'] ? null : (string) $row['min_price'],
			'max_price' => null === $row['max_price'] ? null : (string) $row['max_price'],
			'onsale'    => (string) $row['onsale'],
		);
	}

	/**
	 * Restore and verify the exact WooCommerce customer-price lookup prestate.
	 *
	 * @param int                            $product_id Exact WooCommerce product ID.
	 * @param array<string,string|null>|null $expected Exact lookup projection.
	 * @return bool
	 */
	private function restore_exact_price_lookup_projection( $product_id, $expected ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! $this->source_write_locks_are_owned( $product_id )
			|| ! is_array( $expected )
			|| ! array_key_exists( 'min_price', $expected )
			|| ! array_key_exists( 'max_price', $expected )
			|| ! array_key_exists( 'onsale', $expected )
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->prefix )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			return false;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$assignments  = array();
		$arguments    = array();
		foreach ( array( 'min_price', 'max_price' ) as $column ) {
			if ( null === $expected[ $column ] ) {
				$assignments[] = $column . ' = NULL';
			} else {
				$assignments[] = $column . ' = %s';
				$arguments[]   = (string) $expected[ $column ];
			}
		}
		$assignments[] = 'onsale = %s';
		$arguments[]   = (string) $expected['onsale'];
		$arguments[]   = $product_id;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table and fixed-column assignment list are bounded locally; values use placeholders.
		$query = $wpdb->prepare(
			"UPDATE /* digitalogic_patris_price_lookup_restore */ {$lookup_table} SET " . implode( ', ', $assignments ) . ' WHERE product_id = %d',
			...$arguments
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact rollback projection is prepared above and verified below.
		$updated = false === $query ? false : $wpdb->query( $query );
		if ( false === $updated || (int) $updated > 1 || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return false;
		}
		$readback = $this->read_exact_price_lookup_projection( $product_id );

		return ! is_wp_error( $readback ) && $readback === $expected;
	}

	/**
	 * Restore and verify the exact WooCommerce stock lookup prestate.
	 *
	 * @param int                            $product_id Exact WooCommerce product ID.
	 * @param array<string,string|null>|null $expected Exact lookup projection.
	 * @return bool
	 */
	private function restore_exact_stock_lookup_projection( $product_id, $expected ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! $this->source_write_locks_are_owned( $product_id )
			|| ! is_array( $expected )
			|| ! array_key_exists( 'stock_quantity', $expected )
			|| ! array_key_exists( 'stock_status', $expected )
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->prefix )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			return false;
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( null === $expected['stock_quantity'] ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is the exact site-scoped WooCommerce lookup table; values use placeholders.
			$query = $wpdb->prepare(
				"UPDATE /* digitalogic_patris_stock_lookup_restore */ {$lookup_table} SET stock_quantity = NULL, stock_status = %s WHERE product_id = %d",
				(string) $expected['stock_status'],
				$product_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is the exact site-scoped WooCommerce lookup table; values use placeholders.
			$query = $wpdb->prepare(
				"UPDATE /* digitalogic_patris_stock_lookup_restore */ {$lookup_table} SET stock_quantity = %s, stock_status = %s WHERE product_id = %d",
				(string) $expected['stock_quantity'],
				(string) $expected['stock_status'],
				$product_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact rollback projection is prepared above and verified below.
		$updated = false === $query ? false : $wpdb->query( $query );
		if ( false === $updated || (int) $updated > 1 || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return false;
		}
		$readback = $this->read_exact_stock_lookup_projection( $product_id );

		return ! is_wp_error( $readback ) && $readback === $expected;
	}

	/** Read one product after narrowly invalidating only its object/meta caches. */
	private function fresh_product_for_source_readback( $product_id ) {
		$product_id = absint( $product_id );
		wp_cache_delete( $product_id, 'post_meta' );
		clean_post_cache( $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// A direct constructor forces the Woo data store to read this exact ID
		// without deleting unrelated product transients. Custom product classes
		// follow the same WooCommerce constructor contract; fail closed otherwise.
		$class_name = get_class( $product );
		try {
			$fresh = new $class_name( $product_id );
		} catch ( Throwable $exception ) {
			return false;
		}

		return $fresh instanceof WC_Product && $product_id === (int) $fresh->get_id() ? $fresh : false;
	}

	/** Compare every WooCommerce property changed by the legacy feed. */
	private function source_props_match( $product, $expected ) {
		return (string) $product->get_weight() === (string) $expected['weight']
			&& $product->get_manage_stock() === $expected['manage_stock']
			&& $product->get_stock_quantity() === $expected['stock_quantity']
			&& (string) $product->get_stock_status() === (string) $expected['stock_status']
			&& (string) $product->get_regular_price() === (string) $expected['regular_price']
			&& (string) $product->get_sale_price() === (string) $expected['sale_price']
			&& (string) $product->get_price() === (string) $expected['price'];
	}

	/** Return a typed failure whose exact rollback result is explicit. */
	private function rollback_product_feed_failure( $product, $backup, $cause ) {
		$product_id = (int) ( $backup['product_id'] ?? 0 );
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return $this->source_write_outcome_unknown( $product_id, $cause );
		}
		$verified = $this->restore_product_feed_backup( $product, $backup );
		if ( ! $verified ) {
			return new WP_Error(
				'digitalogic_patris_product_rollback_unknown',
				__( 'The source product write and rollback require exact reconciliation.', 'digitalogic' ),
				array( 'status' => 409, 'retryable' => false, 'cause' => $cause->get_error_code() )
			);
		}
		$data                      = is_array( $cause->get_error_data() ) ? $cause->get_error_data() : array();
		$data['effect_attempted']  = true;
		$data['rollback_verified'] = true;

		return new WP_Error( $cause->get_error_code(), $cause->get_error_message(), $data );
	}

	/** Prove both advisory locks before readback, rollback, or terminal success. */
	private function source_write_locks_are_owned( $product_id ) {
		return Digitalogic_Product_Sync_Receiver::instance()->source_identity_lock_is_owned()
			&& Digitalogic_Product_Write_Lock::instance()->is_owned( (int) $product_id );
	}

	/** Return a fail-closed result when a source effect cannot be attributed safely. */
	private function source_write_outcome_unknown( $product_id, $cause = null ) {
		return new WP_Error(
			'digitalogic_patris_product_write_outcome_unknown',
			__( 'The source product write lost its lock and requires exact reconciliation.', 'digitalogic' ),
			array(
				'status'     => 409,
				'retryable'  => false,
				'product_id' => (int) $product_id,
				'cause'      => $cause instanceof WP_Error ? $cause->get_error_code() : 'lock_lost',
			)
		);
	}

	/** Metadata keys changed by apply_product_feed_authorized and price policy. */
	private function feed_meta_keys() {
		return array(
			'_digitalogic_patris_category_code', '_digitalogic_patris_name', '_digitalogic_patris_serial',
			'_digitalogic_patris_unit', '_digitalogic_patris_unit_id', '_digitalogic_patris_sale_price_source',
			'_digitalogic_patris_partner_price_source', '_digitalogic_patris_purchase_price_source',
			'_digitalogic_patris_warehouse_stock', '_digitalogic_patris_total_stock', '_digitalogic_patris_minimum_stock',
			'_digitalogic_patris_foreign_currency', '_digitalogic_patris_foreign_price', '_digitalogic_patris_price_source_amount',
			'_digitalogic_patris_price_source_currency', '_digitalogic_patris_price_source_kind', '_digitalogic_patris_weight_grams',
			'_digitalogic_patris_location', '_digitalogic_patris_shipping_method_id', '_digitalogic_patris_shipping_price_per_kg',
			'_digitalogic_patris_shipping_price_per_kg_currency', '_digitalogic_patris_markup_percent', '_digitalogic_patris_irt_per_cny',
			'_digitalogic_patris_price_rounding_digits', '_digitalogic_patris_price_rounding_mode', '_digitalogic_patris_pricing_catalog_revision',
			'_digitalogic_patris_pricing_catalog_status', '_digitalogic_patris_currency_effective_date', '_digitalogic_patris_final_price',
			'_digitalogic_patris_updated_at', '_digitalogic_patris_warnings', '_digitalogic_patris_record_hash',
			'_digitalogic_patris_flags', '_digitalogic_patris_last_feed', '_digitalogic_patris_null_fields',
			'_digitalogic_patris_missing_fields', Digitalogic_Patris_Price_Policy::STATUS_META,
			Digitalogic_Patris_Price_Policy::POLICY_META, Digitalogic_Patris_Price_Policy::WARNING_META,
		);
	}

	/** Every non-canonical metadata key changed directly or through Woo props. */
	private function product_feed_backup_meta_keys() {
		return array_values(
			array_unique(
				array_merge(
					$this->feed_meta_keys(),
					array( '_weight', '_manage_stock', '_stock', '_stock_status', '_regular_price', '_sale_price', '_price' )
				)
			)
		);
	}

	/**
	 * Apply a pricing-only projection in bounded SQL batches.
	 *
	 * The caller owns the surrounding database transaction. Product objects
	 * are used only to execute the canonical Woo price policy in memory; no
	 * per-product save or hook fan-out occurs. Managed postmeta and the Woo
	 * lookup projection are replaced in chunks and verified with bulk reads.
	 *
	 * @param array $items Rows with product and canonical data members.
	 * @return array|WP_Error
	 */
	public function apply_product_pricing_batch( $items ) {
		if ( ! is_array( $items ) || empty( $items ) ) {
			return array(
				'updated_ids' => array(),
				'parent_ids'  => array(),
				'batches'     => 0,
				'meta_rows'   => 0,
			);
		}

		return Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write(
			function () use ( $items ) {
				global $wpdb;
				if (
					! is_object( $wpdb )
					|| ! isset( $wpdb->postmeta )
					|| ! method_exists( $wpdb, 'prepare' )
					|| ! method_exists( $wpdb, 'query' )
					|| ! method_exists( $wpdb, 'get_results' )
				) {
					return new WP_Error(
						'digitalogic_pricing_batch_storage_unavailable',
						'Bulk WooCommerce pricing storage is unavailable.',
						array( 'status' => 503 )
					);
				}

				$plans                   = array();
				$identity_plans          = array();
				$warnings                = array();
				$commit_snapshots        = array();
				$additional_managed_keys = array();
				$variation_parents       = array();
				foreach ( $items as $item ) {
					$product = $item['product'] ?? null;
					$data    = is_array( $item['data'] ?? null ) ? $item['data'] : array();
					if (
						! $product instanceof WC_Product
						|| ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) )
					) {
						return new WP_Error(
							'digitalogic_pricing_batch_product_unsupported',
							'A pricing batch contained an unavailable or variable WooCommerce product.',
							array( 'status' => 409 )
						);
					}
					$product_id = (int) $product->get_id();
					if ( $product_id <= 0 || isset( $plans[ $product_id ] ) ) {
						return new WP_Error(
							'digitalogic_pricing_batch_identity_invalid',
							'A pricing batch contained a duplicate or invalid WooCommerce product ID.',
							array( 'status' => 409 )
						);
					}
					if ( $product->is_type( 'variation' ) ) {
						$parent_id = (int) $product->get_parent_id();
						if ( $parent_id <= 0 ) {
							return new WP_Error(
								'digitalogic_pricing_batch_variation_parent_invalid',
								'A pricing batch contained a variation without a canonical parent.',
								array( 'status' => 409 )
							);
						}
						$variation_parents[ $product_id ] = $parent_id;
					}

					$assigned_shipping = (string) $product->get_meta(
						Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META,
						true
					);
					if (
						array_key_exists( 'shipping_method_id', $data )
						&& null !== $data['shipping_method_id']
						&& '' !== (string) $data['shipping_method_id']
						&& $assigned_shipping !== (string) $data['shipping_method_id']
					) {
						return new WP_Error(
							'digitalogic_pricing_batch_shipping_assignment_mismatch',
							'The site-owned shipping assignment changed during pricing reconciliation.',
							array( 'status' => 409 )
						);
					}

					$this->stage_product_pricing( $product, $data );
					$meta = array();
					foreach ( $this->pricing_meta_fields() as $field => $meta_key ) {
						if ( array_key_exists( $field, $data ) && null !== $data[ $field ] ) {
							$meta[ $meta_key ] = (string) $data[ $field ];
						}
					}
					foreach (
						array(
							Digitalogic_Patris_Price_Policy::POLICY_META,
							Digitalogic_Patris_Price_Policy::STATUS_META,
							Digitalogic_Patris_Price_Policy::WARNING_META,
						) as $meta_key
					) {
						$value = (string) $product->get_meta( $meta_key, true );
						if ( '' !== $value ) {
							$meta[ $meta_key ] = $value;
						}
					}
					$regular                = (string) $product->get_regular_price();
					$sale                   = (string) $product->get_sale_price();
					$visible                = (string) $product->get_price();
					$meta['_regular_price'] = $regular;
					$meta['_sale_price']    = $sale;
					$meta['_price']         = $visible;
					$materialization_source = is_array( $item['materialization_source'] ?? null )
						? $item['materialization_source']
						: array();
					if ( ! empty( $materialization_source ) ) {
						$source_id       = (string) ( $materialization_source['id'] ?? '' );
						$dataset         = (string) ( $materialization_source['dataset'] ?? '' );
						$source_revision = (string) ( $materialization_source['revision'] ?? '' );
						if (
							'' === $source_id
							|| '' === $dataset
							|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $source_revision )
							|| ! class_exists( 'Digitalogic_Patris_Catalog_Materializer' )
						) {
							return new WP_Error(
								'digitalogic_pricing_batch_materialization_source_invalid',
								'A pricing batch contained an invalid materialization source.',
								array( 'status' => 409 )
							);
						}
						$missing = Digitalogic_Patris_Catalog_Materializer::instance()->canonical_missing_fields(
							$product,
							$data
						);
						$meta[ Digitalogic_Patris_Catalog_Materializer::SOURCE_REVISION_META ] = $source_revision;
						$meta[ Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META ]  = wp_json_encode(
							$missing,
							JSON_UNESCAPED_SLASHES
						);

						$additional_managed_keys[ Digitalogic_Patris_Catalog_Materializer::SOURCE_REVISION_META ] = true;
						$additional_managed_keys[ Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META ]  = true;
						$commit_snapshots[] = array(
							'product_id'      => $product_id,
							'product_code'    => (string) ( $data['product_code'] ?? '' ),
							'name'            => (string) $product->get_name(),
							'source_id'       => $source_id,
							'dataset'         => $dataset,
							'source_revision' => $source_revision,
							'missing_fields'  => $missing,
							'visible'         => true,
							'purchasable'     => ! in_array( 'price', $missing, true ) && 'outofstock' !== (string) $product->get_stock_status(),
							'price_status'    => (string) $product->get_meta( Digitalogic_Patris_Price_Policy::STATUS_META, true ),
						);
					}
					$identity_plans[ $product_id ] = array(
						'product_code'       => (string) ( $data['product_code'] ?? '' ),
						'product_type'       => $product->is_type( 'variation' ) ? 'variation' : 'simple',
						'parent_id'          => (int) $product->get_parent_id(),
						'source_id'          => (string) ( $materialization_source['id'] ?? '' ),
						'dataset'            => (string) ( $materialization_source['dataset'] ?? '' ),
						'shipping_method_id' => $assigned_shipping,
					);
					if (
						'canonical_missing_preserved'
						=== (string) $product->get_meta( Digitalogic_Patris_Price_Policy::STATUS_META, true )
					) {
						$warnings[] = array(
							'code'           => 'canonical_missing_preserved',
							'message'        => Digitalogic_Patris_Price_Policy::MISSING_WEIGHT_WARNING,
							'product_code'   => (string) ( $item['product_code'] ?? '' ),
							'woocommerce_id' => $product_id,
						);
					}
					ksort( $meta, SORT_STRING );
					$plans[ $product_id ] = array(
						'meta'         => $meta,
						'lookup_price' => '' === trim( $visible ) ? null : $visible,
					);
				}
				$parent_plans = $this->pricing_batch_parent_lookup_plans(
					$plans,
					$variation_parents
				);
				if ( is_wp_error( $parent_plans ) ) {
					return $parent_plans;
				}
				$identity_matches = $this->pricing_batch_leaf_identity_matches( $identity_plans );
				if ( is_wp_error( $identity_matches ) ) {
					return $identity_matches;
				}

				$managed_keys = array_values(
					array_unique(
						array_merge(
							array_values( $this->pricing_meta_fields() ),
							array_keys( $additional_managed_keys ),
							array(
								Digitalogic_Patris_Price_Policy::POLICY_META,
								Digitalogic_Patris_Price_Policy::STATUS_META,
								Digitalogic_Patris_Price_Policy::WARNING_META,
								'_regular_price',
								'_sale_price',
								'_price',
							)
						)
					)
				);
				sort( $managed_keys, SORT_STRING );

				$lookup_table   = $wpdb->prefix . 'wc_product_meta_lookup';
				$batch_count    = 0;
				$meta_row_count = 0;
				$parent_ids       = array_map( 'absint', array_keys( $parent_plans ) );
				$parent_meta_keys = array( '_price', '_regular_price', '_sale_price' );
				foreach ( array_chunk( $plans, 200, true ) as $chunk ) {
					++$batch_count;
					$ids         = array_keys( $chunk );
					$parent_args = array();
					$parent_sql  = '';
					if ( 1 === $batch_count && ! empty( $parent_ids ) ) {
						$parent_sql  = ' OR (post_id IN (' . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $parent_meta_keys ), '%s' ) ) . '))';
						$parent_args = array_merge( $parent_ids, $parent_meta_keys );
					}
					$delete_sql = '/* digitalogic_pricing_batch_meta_delete ids:' . count( $ids ) . ' keys:' . count( $managed_keys ) . ' parent_ids:' . ( 1 === $batch_count ? count( $parent_ids ) : 0 ) . ' parent_keys:' . count( $parent_meta_keys ) . " */ DELETE FROM {$wpdb->postmeta} WHERE (post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $managed_keys ), '%s' ) ) . '))' . $parent_sql;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholder counts are prepared immediately before the transactional batch query.
					if ( false === $wpdb->query( $wpdb->prepare( $delete_sql, ...array_merge( $ids, $managed_keys, $parent_args ) ) ) ) {
						return $this->pricing_batch_error( 'delete' );
					}

					$meta_args   = array();
					$meta_values = array();
					foreach ( $chunk as $product_id => $plan ) {
						foreach ( $plan['meta'] as $meta_key => $meta_value ) {
							$meta_values[] = '(%d,%s,%s)';
							array_push( $meta_args, $product_id, $meta_key, $meta_value );
							++$meta_row_count;
						}
					}
					if ( 1 === $batch_count ) {
						foreach ( $parent_plans as $parent_id => $parent_plan ) {
							foreach ( $parent_plan['meta']['_price'] as $parent_price ) {
								$meta_values[] = '(%d,%s,%s)';
								array_push( $meta_args, $parent_id, '_price', $parent_price );
								++$meta_row_count;
							}
						}
					}
					if ( ! empty( $meta_values ) ) {
						$insert_sql = '/* digitalogic_pricing_batch_meta_insert parent_ids:' . ( 1 === $batch_count ? count( $parent_ids ) : 0 ) . " */ INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $meta_values );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Every dynamic value has a generated placeholder and is prepared in this transaction.
						if ( false === $wpdb->query( $wpdb->prepare( $insert_sql, ...$meta_args ) ) ) {
							return $this->pricing_batch_error( 'insert' );
						}
					}

					$lookup_values = array();
					$lookup_args   = array();
					foreach ( $chunk as $product_id => $plan ) {
						if ( null === $plan['lookup_price'] ) {
							$lookup_values[] = '(%d,NULL,NULL,0)';
							$lookup_args[]   = $product_id;
						} else {
							$lookup_values[] = '(%d,%s,%s,0)';
							array_push( $lookup_args, $product_id, $plan['lookup_price'], $plan['lookup_price'] );
						}
					}
					$lookup_sql = "/* digitalogic_pricing_batch_lookup_upsert */ INSERT INTO {$lookup_table} (product_id, min_price, max_price, onsale) VALUES " . implode( ',', $lookup_values ) . ' ON DUPLICATE KEY UPDATE min_price=VALUES(min_price), max_price=VALUES(max_price), onsale=VALUES(onsale)';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Every lookup value has a generated placeholder and is prepared in this transaction.
					if ( false === $wpdb->query( $wpdb->prepare( $lookup_sql, ...$lookup_args ) ) ) {
						return $this->pricing_batch_error( 'lookup' );
					}

				}

				if ( ! empty( $parent_plans ) ) {
					$parent_written = $this->write_pricing_batch_parent_lookups( $parent_plans, false );
					if ( is_wp_error( $parent_written ) ) {
						return $parent_written;
					}
				}

				$ids              = array_keys( $plans );
				$parent_read_sql  = '';
				$parent_read_args = array();
				if ( ! empty( $parent_ids ) ) {
					$parent_read_sql  = ' OR (post_id IN (' . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $parent_meta_keys ), '%s' ) ) . '))';
					$parent_read_args = array_merge( $parent_ids, $parent_meta_keys );
				}
				$read_sql = '/* digitalogic_pricing_batch_meta_readback ids:' . count( $ids ) . ' keys:' . count( $managed_keys ) . ' parent_ids:' . count( $parent_ids ) . ' parent_keys:' . count( $parent_meta_keys ) . " */ SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE (post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $managed_keys ), '%s' ) ) . '))' . $parent_read_sql . ' ORDER BY post_id, meta_key, meta_id';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One exact transactional readback covers every bulk-written metadata row.
				$read_rows = $wpdb->get_results( $wpdb->prepare( $read_sql, ...array_merge( $ids, $managed_keys, $parent_read_args ) ), ARRAY_A );
				if ( ! $this->pricing_batch_meta_readback_matches( $plans, $read_rows ) ) {
					return $this->pricing_batch_error( 'readback' );
				}
				if ( ! $this->pricing_batch_parent_meta_readback_matches( $parent_plans, $read_rows ) ) {
					return $this->pricing_batch_error( 'parent_meta_readback' );
				}

				$lookup_plans    = $plans + $parent_plans;
				$lookup_ids      = array_keys( $lookup_plans );
				$lookup_read_sql = "/* digitalogic_pricing_batch_lookup_readback */ SELECT product_id, min_price, max_price, onsale FROM {$lookup_table} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $lookup_ids ), '%d' ) ) . ') ORDER BY product_id';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One exact transactional readback covers leaves and variable-parent aggregates.
				$lookup_rows = $wpdb->get_results( $wpdb->prepare( $lookup_read_sql, ...$lookup_ids ), ARRAY_A );
				if ( ! $this->pricing_batch_lookup_readback_matches( $lookup_plans, $lookup_rows ) ) {
					return $this->pricing_batch_error( 'lookup_readback' );
				}

				return array(
					'updated_ids'      => array_map( 'intval', array_keys( $plans ) ),
					'parent_ids'       => array_map( 'intval', array_keys( $parent_plans ) ),
					'batches'          => $batch_count,
					'meta_rows'        => $meta_row_count,
					'warnings'         => $warnings,
					'commit_snapshots' => $commit_snapshots,
				);
			}
		);
	}

	/**
	 * Recompute exact variable-parent aggregates after bounded fallback saves.
	 *
	 * The caller owns the surrounding transaction and source/identity fences.
	 *
	 * @param int[] $parent_ids Variable parent IDs.
	 * @return array|WP_Error
	 */
	public function refresh_variable_parent_price_lookups( $parent_ids ) {
		return Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write(
			function () use ( $parent_ids ) {
				$plans = $this->pricing_batch_parent_lookup_plans( array(), array(), $parent_ids );
				if ( is_wp_error( $plans ) ) {
					return $plans;
				}
				$meta_written = $this->write_pricing_batch_parent_meta( $plans );
				if ( is_wp_error( $meta_written ) ) {
					return $meta_written;
				}
				$written = $this->write_pricing_batch_parent_lookups( $plans );
				if ( is_wp_error( $written ) ) {
					return $written;
				}

				return array( 'parent_ids' => array_map( 'intval', array_keys( $plans ) ) );
			}
		);
	}

	/**
	 * Find priced leaves whose durable Woo lookup projection is stale.
	 *
	 * The caller already verified the canonical leaf metadata and owns the
	 * surrounding pricing transaction. This single cache-bypassing read prevents
	 * a same-rate reconcile from accepting stale catalog sort/filter prices.
	 *
	 * @param array<int,string> $expected_prices Canonical positive price by leaf ID.
	 * @return int[]|WP_Error
	 */
	public function drifted_priced_leaf_lookup_projections( $expected_prices ) {
		$plans = array();
		foreach ( (array) $expected_prices as $product_id => $expected_price ) {
			$product_id = absint( $product_id );
			if ( $product_id <= 0 || null === $this->pricing_batch_decimal( $expected_price ) ) {
				return $this->pricing_batch_error( 'leaf_lookup_preflight' );
			}
			$plans[ $product_id ] = array( 'lookup_price' => (string) $expected_price );
		}
		if ( empty( $plans ) ) {
			return array();
		}

		global $wpdb;
		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$product_ids  = array_keys( $plans );
		$sql          = '/* digitalogic_pricing_batch_leaf_lookup_preflight ids:' . count( $product_ids ) . " */ SELECT product_id, min_price, max_price, onsale FROM {$lookup_table} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ') ORDER BY product_id FOR UPDATE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One prepared locking read verifies every initially-current priced leaf lookup inside the open pricing transaction.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$product_ids ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $this->pricing_batch_error( 'leaf_lookup_preflight' );
		}

		$drifted = array();
		foreach ( $plans as $product_id => $plan ) {
			if ( ! $this->pricing_batch_lookup_readback_matches( array( $product_id => $plan ), $rows ) ) {
				$drifted[] = (int) $product_id;
			}
		}

		return $drifted;
	}

	/**
	 * Find variable parents whose raw and lookup price aggregates are stale.
	 *
	 * Visible child prices, parent topology, and ownership are locked by the
	 * shared parent planner. Exact raw price-row cardinality and lookup min/max
	 * are then compared without Woo object caches before a no-op may commit.
	 *
	 * @param int[] $parent_ids Variable parent IDs.
	 * @return int[]|WP_Error
	 */
	public function drifted_variable_parent_price_projections( $parent_ids ) {
		$plans = $this->pricing_batch_parent_lookup_plans( array(), array(), $parent_ids );
		if ( is_wp_error( $plans ) || empty( $plans ) ) {
			return $plans;
		}

		global $wpdb;
		$parent_ids = array_map( 'absint', array_keys( $plans ) );
		$meta_keys  = array( '_price', '_regular_price', '_sale_price' );
		$meta_sql   = '/* digitalogic_pricing_batch_parent_meta_readback ids:' . count( $parent_ids ) . ' keys:' . count( $meta_keys ) . " */ SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ) . ') ORDER BY post_id,meta_key,meta_id FOR UPDATE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One prepared locking current read verifies every parent raw price row.
		$meta_rows = $wpdb->get_results( $wpdb->prepare( $meta_sql, ...array_merge( $parent_ids, $meta_keys ) ), ARRAY_A );
		if ( ! is_array( $meta_rows ) ) {
			return $this->pricing_batch_error( 'parent_projection_preflight' );
		}

		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		$lookup_sql   = "/* digitalogic_pricing_batch_parent_lookup_readback */ SELECT product_id, min_price, max_price, onsale FROM {$lookup_table} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') ORDER BY product_id FOR UPDATE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One prepared locking current read verifies every parent lookup aggregate.
		$lookup_rows = $wpdb->get_results( $wpdb->prepare( $lookup_sql, ...$parent_ids ), ARRAY_A );
		if ( ! is_array( $lookup_rows ) ) {
			return $this->pricing_batch_error( 'parent_projection_preflight' );
		}

		$drifted = array();
		foreach ( $plans as $parent_id => $plan ) {
			$parent_plan = array( $parent_id => $plan );
			if (
				! $this->pricing_batch_parent_meta_readback_matches( $parent_plan, $meta_rows )
				|| ! $this->pricing_batch_lookup_readback_matches( $parent_plan, $lookup_rows )
			) {
				$drifted[] = (int) $parent_id;
			}
		}

		return $drifted;
	}

	/**
	 * Lock and verify final fallback leaf identity immediately before commit.
	 *
	 * @param array<int,array> $identity_plans Exact expected identity plans.
	 * @return true|WP_Error
	 */
	public function verify_pricing_leaf_identities( $identity_plans ) {
		return $this->pricing_batch_leaf_identity_matches( (array) $identity_plans );
	}

	/**
	 * Replace and verify Woo's variable-parent price metadata in an open transaction.
	 *
	 * @param array $parent_plans Exact parent aggregate plans.
	 * @return true|WP_Error
	 */
	private function write_pricing_batch_parent_meta( $parent_plans ) {
		if ( empty( $parent_plans ) ) {
			return true;
		}
		global $wpdb;
		$parent_ids = array_map( 'absint', array_keys( $parent_plans ) );
		$meta_keys  = array( '_price', '_regular_price', '_sale_price' );
		$delete_sql = '/* digitalogic_pricing_batch_parent_meta_delete ids:' . count( $parent_ids ) . ' keys:' . count( $meta_keys ) . " */ DELETE FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact parent IDs and keys are prepared before the transactional delete.
		if ( false === $wpdb->query( $wpdb->prepare( $delete_sql, ...array_merge( $parent_ids, $meta_keys ) ) ) ) {
			return $this->pricing_batch_error( 'parent_meta_delete' );
		}
		$values = array();
		$args   = array();
		foreach ( $parent_plans as $parent_id => $parent_plan ) {
			foreach ( $parent_plan['meta']['_price'] as $parent_price ) {
				$values[] = '(%d,%s,%s)';
				array_push( $args, $parent_id, '_price', $parent_price );
			}
		}
		if ( ! empty( $values ) ) {
			$insert_sql = "/* digitalogic_pricing_batch_parent_meta_insert */ INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $values );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact parent price rows are prepared before the transactional insert.
			if ( false === $wpdb->query( $wpdb->prepare( $insert_sql, ...$args ) ) ) {
				return $this->pricing_batch_error( 'parent_meta_insert' );
			}
		}
		$read_sql = '/* digitalogic_pricing_batch_parent_meta_readback ids:' . count( $parent_ids ) . ' keys:' . count( $meta_keys ) . " */ SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) ) . ') ORDER BY post_id,meta_key,meta_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One prepared current read verifies all parent price rows.
		$rows = $wpdb->get_results( $wpdb->prepare( $read_sql, ...array_merge( $parent_ids, $meta_keys ) ), ARRAY_A );
		if ( ! $this->pricing_batch_parent_meta_readback_matches( $parent_plans, $rows ) ) {
			return $this->pricing_batch_error( 'parent_meta_readback' );
		}

		return true;
	}

	/**
	 * Stage the exact pricing policy without persisting the Woo object.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param array      $data    Canonical pricing projection.
	 * @return void
	 */
	private function stage_product_pricing( WC_Product $product, $data ) {
		$data = is_array( $data ) ? $data : array();
		foreach ( $this->pricing_meta_fields() as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $data ) || null === $data[ $field ] ) {
				$product->delete_meta_data( $meta_key );
			} else {
				$product->update_meta_data( $meta_key, $data[ $field ] );
			}
		}
		Digitalogic_Patris_Price_Policy::instance()->apply( $product, $data );
	}

	/** Return the complete pricing-only canonical metadata mapping.
	 *
	 * @return array
	 */
	private function pricing_meta_fields() {
		return array(
			'price_source_amount'            => '_digitalogic_patris_price_source_amount',
			'price_source_currency'          => '_digitalogic_patris_price_source_currency',
			'price_source_kind'              => '_digitalogic_patris_price_source_kind',
			'shipping_method_id'             => '_digitalogic_patris_shipping_method_id',
			'shipping_price_per_kg'          => '_digitalogic_patris_shipping_price_per_kg',
			'shipping_price_per_kg_currency' => '_digitalogic_patris_shipping_price_per_kg_currency',
			'markup_percent'                 => '_digitalogic_patris_markup_percent',
			'irt_per_cny'                    => '_digitalogic_patris_irt_per_cny',
			'price_rounding_digits'          => '_digitalogic_patris_price_rounding_digits',
			'price_rounding_mode'            => '_digitalogic_patris_price_rounding_mode',
			'pricing_catalog_revision'       => '_digitalogic_patris_pricing_catalog_revision',
			'pricing_catalog_status'         => '_digitalogic_patris_pricing_catalog_status',
			'currency_effective_date'        => '_digitalogic_patris_currency_effective_date',
			'final_price'                    => '_digitalogic_patris_final_price',
			'record_hash'                    => '_digitalogic_patris_record_hash',
		);
	}

	/**
	 * Build exact variable-parent lookup aggregates before any leaf row changes.
	 *
	 * Current sibling prices are read once, then every targeted variation is
	 * overlaid from the in-memory canonical plan. Duplicate untargeted price rows
	 * fail closed because their aggregate would otherwise be ambiguous.
	 *
	 * @param array $plans Leaf pricing plans.
	 * @param array $variation_parents Target variation ID to parent ID map.
	 * @param int[] $additional_parent_ids Additional parents requiring a final aggregate refresh.
	 * @return array|WP_Error
	 */
	private function pricing_batch_parent_lookup_plans( $plans, $variation_parents, $additional_parent_ids = array() ) {
		$variation_parents = array_map( 'absint', (array) $variation_parents );
		$parent_ids        = array_values(
			array_unique(
				array_filter(
					array_merge(
						array_values( $variation_parents ),
						array_map( 'absint', (array) $additional_parent_ids )
					)
				)
			)
		);
		if ( empty( $parent_ids ) ) {
			return array();
		}

		global $wpdb;
		$lookup_table          = $wpdb->prefix . 'wc_product_meta_lookup';
		$relationships         = $wpdb->term_relationships;
		$term_taxonomy         = $wpdb->term_taxonomy;
		$terms                 = $wpdb->terms;
		$forbidden_parent_meta = array_map(
			'strtolower',
			array(
				Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META,
				Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META,
				Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META,
				Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META,
			)
		);
		$sql                   = '/* digitalogic_pricing_batch_parent_inputs parents:' . count( $parent_ids ) . " */ SELECT p.ID product_id, p.post_parent parent_id, pm.meta_value price, EXISTS (SELECT 1 FROM {$relationships} visibility_tr INNER JOIN {$term_taxonomy} visibility_tt ON visibility_tt.term_taxonomy_id=visibility_tr.term_taxonomy_id AND visibility_tt.taxonomy='product_visibility' INNER JOIN {$terms} visibility_term ON visibility_term.term_id=visibility_tt.term_id AND visibility_term.slug='outofstock' WHERE visibility_tr.object_id=p.ID) outofstock_visibility FROM {$wpdb->posts} p INNER JOIN {$wpdb->posts} parent ON parent.ID=p.post_parent AND parent.post_type='product' AND parent.post_status='publish' INNER JOIN {$lookup_table} parent_lookup ON parent_lookup.product_id=parent.ID LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_price' WHERE p.post_parent IN (" . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ") AND p.post_type='product_variation' AND p.post_status='publish' AND EXISTS (SELECT 1 FROM {$relationships} tr INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_type' INNER JOIN {$terms} term ON term.term_id=tt.term_id AND term.slug='variable' WHERE tr.object_id=parent.ID) AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} owned WHERE owned.post_id=parent.ID AND LOWER(owned.meta_key) IN (" . implode( ',', array_fill( 0, count( $forbidden_parent_meta ), '%s' ) ) . ')) ORDER BY p.post_parent,p.ID,pm.meta_id FOR UPDATE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One locking current read rechecks every parent topology/owner predicate and builds its exact aggregate inside the surrounding transaction.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic SQL contains only generated placeholder counts and is prepared with the exact bounded arguments below.
		$prepared_sql = $wpdb->prepare( $sql, ...array_merge( $parent_ids, $forbidden_parent_meta ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded FOR UPDATE read must bypass object caches and run inside the surrounding transaction.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- PHPCS cannot infer that $prepared_sql is the direct result of $wpdb->prepare() on the immediately preceding line.
			$prepared_sql,
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return $this->pricing_batch_error( 'parent_inputs' );
		}

		$children = array();
		foreach ( $rows as $row ) {
			$parent_id  = absint( $row['parent_id'] ?? 0 );
			$product_id = absint( $row['product_id'] ?? 0 );
			if ( ! in_array( $parent_id, $parent_ids, true ) || $product_id <= 0 ) {
				return $this->pricing_batch_error( 'parent_inputs' );
			}
			if ( ! isset( $children[ $parent_id ][ $product_id ] ) ) {
				$children[ $parent_id ][ $product_id ] = array(
					'prices'     => array(),
					'outofstock' => ! empty( $row['outofstock_visibility'] ),
				);
			}
			if ( array_key_exists( 'price', $row ) && null !== $row['price'] ) {
				$children[ $parent_id ][ $product_id ]['prices'][] = (string) $row['price'];
			}
		}
		foreach ( $variation_parents as $product_id => $expected_parent_id ) {
			$product_id         = absint( $product_id );
			$expected_parent_id = absint( $expected_parent_id );
			if (
				$product_id <= 0
				|| $expected_parent_id <= 0
				|| ! isset( $plans[ $product_id ] )
				|| ! isset( $children[ $expected_parent_id ][ $product_id ] )
			) {
				return $this->pricing_batch_error( 'parent_inputs' );
			}
		}

		$hide_out_of_stock = 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' );
		$parent_plans      = array();
		foreach ( $parent_ids as $parent_id ) {
			if ( empty( $children[ $parent_id ] ) ) {
				return $this->pricing_batch_error( 'parent_inputs' );
			}
			$visible_prices = array();
			foreach ( $children[ $parent_id ] as $product_id => $child ) {
				if ( $hide_out_of_stock && ! empty( $child['outofstock'] ) ) {
					continue;
				}
				if ( isset( $plans[ $product_id ] ) ) {
					$price = $plans[ $product_id ]['lookup_price'];
				} else {
					if ( count( $child['prices'] ) > 1 ) {
						return $this->pricing_batch_error( 'parent_inputs' );
					}
					$price = empty( $child['prices'] ) ? null : reset( $child['prices'] );
				}
				$raw_price = $price;
				$price     = $this->pricing_batch_decimal( $price );
				if ( null !== $raw_price && '' !== trim( (string) $raw_price ) && null === $price ) {
					return $this->pricing_batch_error( 'parent_inputs' );
				}
				if ( null !== $price ) {
					if ( $this->pricing_batch_decimal_compare( $price, '0' ) < 0 ) {
						return $this->pricing_batch_error( 'parent_inputs' );
					}
					$visible_prices[] = $price;
				}
			}
			$visible_prices = array_values( array_unique( $visible_prices ) );
			usort(
				$visible_prices,
				function ( $left, $right ) {
					return $this->pricing_batch_decimal_compare( $left, $right );
				}
			);
			$minimum                    = empty( $visible_prices ) ? '0' : reset( $visible_prices );
			$maximum                    = empty( $visible_prices ) ? '0' : end( $visible_prices );
			$parent_plans[ $parent_id ] = array(
				'min_price' => $minimum,
				'max_price' => $maximum,
				'onsale'    => 0,
				'meta'      => array(
					'_price'         => $visible_prices,
					'_regular_price' => array(),
					'_sale_price'    => array(),
				),
			);
		}

		return $parent_plans;
	}

	/**
	 * Lock and verify every target leaf identity immediately before bulk writes.
	 *
	 * @param array $identity_plans Expected leaf identity plans keyed by post ID.
	 * @return true|WP_Error
	 */
	private function pricing_batch_leaf_identity_matches( $identity_plans ) {
		if ( empty( $identity_plans ) ) {
			return true;
		}
		global $wpdb;
		$product_ids = array_map( 'absint', array_keys( $identity_plans ) );
		sort( $product_ids, SORT_NUMERIC );
		$meta_keys       = array(
			Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META,
			'_sku',
			Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META,
			Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META,
			Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META,
			Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META,
			Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META,
		);
		$normalized_keys = array_map( 'strtolower', $meta_keys );
		$product_codes   = array_values(
			array_unique(
				array_map(
					static fn( $plan ) => (string) ( $plan['product_code'] ?? '' ),
					$identity_plans
				)
			)
		);
		if ( in_array( '', $product_codes, true ) ) {
			return $this->pricing_batch_error( 'leaf_identity' );
		}
		$collision_keys = array_map(
			'strtolower',
			array(
				Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META,
				'_sku',
			)
		);
		$lookup_table   = $wpdb->prefix . 'wc_product_meta_lookup';
		$type_sql       = "SELECT term.slug FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_type' INNER JOIN {$wpdb->terms} term ON term.term_id=tt.term_id WHERE tr.object_id=p.ID ORDER BY term.slug LIMIT 1";
		$sql            = '/* digitalogic_pricing_batch_leaf_identity ids:' . count( $product_ids ) . ' keys:' . count( $normalized_keys ) . " */ SELECT p.ID product_id, p.post_type, p.post_status, p.post_parent parent_id, CASE WHEN p.post_type='product_variation' THEN 'variation' ELSE COALESCE(({$type_sql}),'simple') END product_type, leaf_lookup.product_id lookup_id, pm.meta_key, pm.meta_value FROM {$wpdb->posts} p LEFT JOIN {$lookup_table} leaf_lookup ON leaf_lookup.product_id=p.ID LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND LOWER(pm.meta_key) IN (" . implode( ',', array_fill( 0, count( $normalized_keys ), '%s' ) ) . ') WHERE p.ID IN (' . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ') ORDER BY p.ID,pm.meta_key,pm.meta_id FOR UPDATE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One locking current read fences exact leaf identity/topology/owner rows immediately before the transactional batch writes.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic SQL contains only generated placeholder counts and is prepared with the exact bounded arguments below.
		$prepared_sql = $wpdb->prepare( $sql, ...array_merge( $normalized_keys, $product_ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded FOR UPDATE read must bypass object caches and run inside the surrounding transaction.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- PHPCS cannot infer that $prepared_sql is the direct result of $wpdb->prepare() on the immediately preceding line.
			$prepared_sql,
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return $this->pricing_batch_error( 'leaf_identity' );
		}
		$collision_sql = '/* digitalogic_pricing_batch_leaf_collision ids:' . count( $product_ids ) . ' keys:' . count( $collision_keys ) . ' codes:' . count( $product_codes ) . " */ SELECT collision.post_id product_id FROM {$wpdb->postmeta} collision INNER JOIN {$wpdb->posts} collision_post ON collision_post.ID=collision.post_id AND collision_post.post_type IN ('product','product_variation') AND collision_post.post_status NOT IN ('trash','auto-draft') WHERE collision.post_id NOT IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ') AND LOWER(collision.meta_key) IN (' . implode( ',', array_fill( 0, count( $collision_keys ), '%s' ) ) . ') AND BINARY collision.meta_value IN (' . implode( ',', array_fill( 0, count( $product_codes ), '%s' ) ) . ') ORDER BY collision.post_id,collision.meta_id FOR UPDATE';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic SQL contains only generated placeholder counts and is prepared with the exact bounded arguments below.
		$prepared_collision_sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This dynamic query contains only generated placeholders and is prepared with all bounded values here.
			$collision_sql,
			...array_merge( $product_ids, $collision_keys, $product_codes )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This second bounded current read locks only cross-leaf SKU/Patris collisions and avoids MariaDB's unstable OR/EXISTS locking plan.
		$collision_rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- PHPCS cannot infer that this is the direct prepared collision query above.
			$prepared_collision_sql,
			ARRAY_A
		);
		if ( ! is_array( $collision_rows ) || ! empty( $collision_rows ) ) {
			return $this->pricing_batch_error( 'leaf_identity' );
		}
		$actual = array();
		foreach ( $rows as $row ) {
			$product_id = absint( $row['product_id'] ?? 0 );
			if ( ! isset( $identity_plans[ $product_id ] ) ) {
				return $this->pricing_batch_error( 'leaf_identity' );
			}
			if ( ! isset( $actual[ $product_id ] ) ) {
				$actual[ $product_id ] = array(
					'post_type'    => (string) ( $row['post_type'] ?? '' ),
					'post_status'  => (string) ( $row['post_status'] ?? '' ),
					'parent_id'    => absint( $row['parent_id'] ?? 0 ),
					'product_type' => (string) ( $row['product_type'] ?? '' ),
					'lookup_id'    => absint( $row['lookup_id'] ?? 0 ),
					'meta'         => array(),
				);
			}
			$meta_key = (string) ( $row['meta_key'] ?? '' );
			if ( '' !== $meta_key ) {
				$actual[ $product_id ]['meta'][ strtolower( $meta_key ) ][] = array(
					'key'   => $meta_key,
					'value' => (string) ( $row['meta_value'] ?? '' ),
				);
			}
		}
		foreach ( $identity_plans as $product_id => $expected ) {
			$product_id         = absint( $product_id );
			$row                = $actual[ $product_id ] ?? null;
			$product_code       = (string) ( $expected['product_code'] ?? '' );
			$expected_post_type = 'variation' === (string) ( $expected['product_type'] ?? '' )
				? 'product_variation'
				: 'product';
			if (
				! is_array( $row )
				|| '' === $product_code
				|| $expected_post_type !== $row['post_type']
				|| 'publish' !== $row['post_status']
				|| (string) ( $expected['product_type'] ?? '' ) !== $row['product_type']
				|| (int) ( $expected['parent_id'] ?? 0 ) !== $row['parent_id']
				|| (int) ( $row['lookup_id'] ?? 0 ) !== $product_id
			) {
				return $this->pricing_batch_error( 'leaf_identity' );
			}
			$expected_meta_rows = is_array( $expected['meta_rows'] ?? null )
				? $expected['meta_rows']
				: array(
					Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META => array( $product_code ),
					'_sku' => array( $product_code ),
					Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META => array(
						(string) ( $expected['shipping_method_id'] ?? '' ),
					),
					Digitalogic_Patris_Catalog_Materializer::OWNER_SOURCE_META => array(
						(string) ( $expected['source_id'] ?? '' ),
					),
					Digitalogic_Patris_Catalog_Materializer::OWNER_DATASET_META => array(
						(string) ( $expected['dataset'] ?? '' ),
					),
					Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META => array( $product_code ),
					Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META => array(),
				);
			foreach ( $expected_meta_rows as $meta_key => $expected_values ) {
				$actual_rows   = $row['meta'][ strtolower( (string) $meta_key ) ] ?? array();
				$actual_values = array();
				foreach ( $actual_rows as $actual_row ) {
					if ( (string) ( $actual_row['key'] ?? '' ) !== (string) $meta_key ) {
						return $this->pricing_batch_error( 'leaf_identity' );
					}
					$actual_values[] = (string) ( $actual_row['value'] ?? '' );
				}
				if ( array_map( 'strval', array_values( (array) $expected_values ) ) !== $actual_values ) {
					return $this->pricing_batch_error( 'leaf_identity' );
				}
			}
		}

		return true;
	}

	/**
	 * Persist and verify exact variable-parent lookup aggregates.
	 *
	 * @param array $parent_plans Parent aggregate plans.
	 * @param bool  $verify       Whether to read back parent rows immediately.
	 * @return true|WP_Error
	 */
	private function write_pricing_batch_parent_lookups( $parent_plans, $verify = true ) {
		if ( empty( $parent_plans ) ) {
			return true;
		}
		global $wpdb;
		$lookup_table  = $wpdb->prefix . 'wc_product_meta_lookup';
		$parent_values = array();
		$parent_args   = array();
		foreach ( $parent_plans as $parent_id => $parent_plan ) {
			$parent_values[] = '(%d,%s,%s,%d)';
			array_push(
				$parent_args,
				$parent_id,
				$parent_plan['min_price'],
				$parent_plan['max_price'],
				$parent_plan['onsale']
			);
		}
		$parent_sql = "/* digitalogic_pricing_batch_parent_lookup_upsert */ INSERT INTO {$lookup_table} (product_id, min_price, max_price, onsale) VALUES " . implode( ',', $parent_values ) . ' ON DUPLICATE KEY UPDATE min_price=VALUES(min_price), max_price=VALUES(max_price), onsale=VALUES(onsale)';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact parent aggregate values are prepared immediately before the transactional write.
		if ( false === $wpdb->query( $wpdb->prepare( $parent_sql, ...$parent_args ) ) ) {
			return $this->pricing_batch_error( 'parent_lookup' );
		}
		if ( ! $verify ) {
			return true;
		}
		$parent_ids      = array_keys( $parent_plans );
		$lookup_read_sql = "/* digitalogic_pricing_batch_parent_lookup_readback */ SELECT product_id, min_price, max_price, onsale FROM {$lookup_table} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) ) . ') ORDER BY product_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One exact transactional readback covers all refreshed parent aggregates.
		$lookup_rows = $wpdb->get_results( $wpdb->prepare( $lookup_read_sql, ...$parent_ids ), ARRAY_A );
		if ( ! $this->pricing_batch_lookup_readback_matches( $parent_plans, $lookup_rows ) ) {
			return $this->pricing_batch_error( 'parent_lookup_readback' );
		}

		return true;
	}

	/**
	 * Verify the exact managed metadata projection from one bulk database read.
	 *
	 * @param array $plans Expected per-product metadata.
	 * @param array $rows  Database readback rows.
	 * @return bool
	 */
	private function pricing_batch_meta_readback_matches( $plans, $rows ) {
		if ( ! is_array( $rows ) ) {
			return false;
		}
		$actual = array();
		foreach ( $rows as $row ) {
			$product_id = (int) ( $row['post_id'] ?? 0 );
			$meta_key   = (string) ( $row['meta_key'] ?? '' );
			if ( 0 < $product_id && '' !== $meta_key ) {
				$actual[ $product_id ][ $meta_key ][] = (string) ( $row['meta_value'] ?? '' );
			}
		}
		foreach ( $plans as $product_id => $plan ) {
			$expected = $plan['meta'];
			$stored   = $actual[ (int) $product_id ] ?? array();
			if ( array_keys( $expected ) !== array_keys( $stored ) ) {
				return false;
			}
			foreach ( $expected as $meta_key => $meta_value ) {
				if ( array( (string) $meta_value ) !== ( $stored[ $meta_key ] ?? array() ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Verify Woo's durable variable-parent price metadata projection.
	 *
	 * Woo stores the sorted distinct visible-child prices as repeated parent
	 * `_price` rows and leaves parent regular/sale rows absent.
	 *
	 * @param array $parent_plans Expected parent aggregate plans.
	 * @param array $rows         Combined leaf and parent metadata readback.
	 * @return bool
	 */
	private function pricing_batch_parent_meta_readback_matches( $parent_plans, $rows ) {
		if ( ! is_array( $rows ) ) {
			return false;
		}
		$actual = array();
		foreach ( $rows as $row ) {
			$product_id = absint( $row['post_id'] ?? 0 );
			$meta_key   = (string) ( $row['meta_key'] ?? '' );
			if ( isset( $parent_plans[ $product_id ]['meta'][ $meta_key ] ) ) {
				$actual[ $product_id ][ $meta_key ][] = (string) ( $row['meta_value'] ?? '' );
			}
		}
		foreach ( $parent_plans as $parent_id => $parent_plan ) {
			foreach ( $parent_plan['meta'] as $meta_key => $expected_values ) {
				if ( array_values( $expected_values ) !== ( $actual[ (int) $parent_id ][ $meta_key ] ?? array() ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Verify Woo's customer-price lookup projection after the batch upsert.
	 *
	 * @param array $plans Expected per-product lookup values.
	 * @param array $rows  Database readback rows.
	 * @return bool
	 */
	private function pricing_batch_lookup_readback_matches( $plans, $rows ) {
		if ( ! is_array( $rows ) ) {
			return false;
		}
		$actual = array();
		foreach ( $rows as $row ) {
			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ( 0 < $product_id ) {
				$actual[ $product_id ] = $row;
			}
		}
		foreach ( $plans as $product_id => $plan ) {
			if ( ! isset( $actual[ (int) $product_id ] ) ) {
				return false;
			}
			$row = $actual[ (int) $product_id ];
			if ( array_key_exists( 'min_price', $plan ) ) {
				$expected_min    = $this->pricing_batch_decimal( $plan['min_price'] );
				$expected_max    = $this->pricing_batch_decimal( $plan['max_price'] );
				$expected_onsale = (int) $plan['onsale'];
			} else {
				$expected_min    = $this->pricing_batch_decimal( $plan['lookup_price'] );
				$expected_max    = $expected_min;
				$expected_onsale = 0;
			}
			if (
				$expected_min !== $this->pricing_batch_decimal( $row['min_price'] ?? null )
				|| $expected_max !== $this->pricing_batch_decimal( $row['max_price'] ?? null )
				|| (int) ( $row['onsale'] ?? -1 ) !== $expected_onsale
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Normalize a lookup decimal without binary floating-point conversion.
	 *
	 * @param mixed $value Raw lookup decimal.
	 * @return string|null
	 */
	private function pricing_batch_decimal( $value ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/D', $value ) ) {
			return null;
		}
		if ( str_contains( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		return '' === $value ? '0' : $value;
	}

	/**
	 * Compare two non-negative canonical price decimals without float conversion.
	 *
	 * @param mixed $left Left canonical decimal.
	 * @param mixed $right Right canonical decimal.
	 * @return int
	 */
	private function pricing_batch_decimal_compare( $left, $right ) {
		$left          = (string) $this->pricing_batch_decimal( $left );
		$right         = (string) $this->pricing_batch_decimal( $right );
		$left_parts    = array_pad( explode( '.', $left, 2 ), 2, '' );
		$right_parts   = array_pad( explode( '.', $right, 2 ), 2, '' );
		$left_integer  = ltrim( $left_parts[0], '0' );
		$right_integer = ltrim( $right_parts[0], '0' );
		$left_integer  = '' === $left_integer ? '0' : $left_integer;
		$right_integer = '' === $right_integer ? '0' : $right_integer;
		if ( strlen( $left_integer ) !== strlen( $right_integer ) ) {
			return strlen( $left_integer ) <=> strlen( $right_integer );
		}
		$integer_compare = strcmp( $left_integer, $right_integer );
		if ( 0 !== $integer_compare ) {
			return $integer_compare < 0 ? -1 : 1;
		}
		$scale            = max( strlen( $left_parts[1] ), strlen( $right_parts[1] ) );
		$left_fraction    = str_pad( $left_parts[1], $scale, '0' );
		$right_fraction   = str_pad( $right_parts[1], $scale, '0' );
		$fraction_compare = strcmp( $left_fraction, $right_fraction );

		return 0 === $fraction_compare ? 0 : ( $fraction_compare < 0 ? -1 : 1 );
	}

	/**
	 * Build a fail-closed batch storage error without database details.
	 *
	 * @param string $phase Failed storage phase.
	 * @return WP_Error
	 */
	private function pricing_batch_error( $phase ) {
		return new WP_Error(
			'digitalogic_pricing_batch_' . sanitize_key( (string) $phase ) . '_failed',
			'Bulk WooCommerce pricing storage failed and was rolled back.',
			array( 'status' => 500 )
		);
	}

	/**
	 * Apply a normalized Patris product while the canonical price guard is open.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param array      $data    Validated normalized product.
	 * @return void
	 */
	private function apply_product_feed_authorized( WC_Product $product, $data ) {
		$this->stage_product_feed( $product, $data );
		$product->save();
		$this->persist_unpriced_stock_status( $product );
		Digitalogic_Patris_Price_Policy::instance()->invalidate( $product );
	}

	/**
	 * Persist unavailable status after WooCommerce and save hooks synchronize stock.
	 *
	 * A canonical quantity remains exact even when price inputs are unavailable.
	 * WooCommerce integrations may promote a positive quantity to `instock`, and
	 * its native data store may represent a blank zero-stock price with lookup
	 * min/max zero. The final projection accepts that internal sentinel only while
	 * raw prices stay blank and the product stays unavailable. No second product
	 * save or hook fan-out is used.
	 *
	 * @param WC_Product $product Product staged by the canonical writer.
	 * @return void
	 * @throws RuntimeException When the exact unavailable state cannot be persisted and verified.
	 */
	private function persist_unpriced_stock_status( WC_Product $product ) {
		$status = (string) $product->get_meta( Digitalogic_Patris_Price_Policy::STATUS_META, true );
		if (
			! in_array( $status, array( 'canonical_missing_unpriced', 'canonical_nonpositive_unpriced' ), true )
		) {
			return;
		}

		$product_id = (int) $product->get_id();
		$fresh      = $this->fresh_product_for_source_readback( $product_id );
		if ( ! $fresh instanceof WC_Product || ! $this->source_write_locks_are_owned( $product_id ) ) {
			throw new RuntimeException( 'The unavailable product stock projection could not be read safely.' );
		}
		if ( ! $this->unavailable_stock_projection_matches( $product_id, $fresh ) ) {
			if ( ! $this->write_unavailable_stock_projection( $product_id ) ) {
				throw new RuntimeException( 'The unavailable product stock projection could not be persisted.' );
			}
			$this->invalidate_unavailable_stock_projection_caches( $product_id );
			$fresh = $this->fresh_product_for_source_readback( $product_id );
		}
		if ( ! $this->unavailable_stock_projection_matches( $product_id, $fresh ) ) {
			throw new RuntimeException( 'The unavailable product stock projection could not be verified.' );
		}

		$product->set_stock_status( 'outofstock' );
	}

	/**
	 * Write one exact status-only WooCommerce projection without save hooks.
	 *
	 * @param int $product_id Exact WooCommerce product ID.
	 * @return bool
	 */
	private function write_unavailable_stock_projection( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! $this->source_write_locks_are_owned( $product_id )
			|| ! is_object( $wpdb )
			|| ! isset( $wpdb->postmeta, $wpdb->prefix )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'query' )
		) {
			return false;
		}
		$current        = $this->read_exact_meta_rows( $product_id, array( '_stock_status' ) );
		$current_status = is_array( $current ) && 1 === count( $current['_stock_status'] ?? array() )
			? (string) reset( $current['_stock_status'] )
			: '';
		if ( is_wp_error( $current ) || ! in_array( $current_status, array( 'instock', 'onbackorder', 'outofstock' ), true ) ) {
			return false;
		}

		$postmeta     = $wpdb->postmeta;
		$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( 'outofstock' !== $current_status ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is the exact wpdb postmeta table; all values use generated placeholders.
			$meta_sql = $wpdb->prepare(
				"UPDATE /* digitalogic_patris_unpriced_stock_meta_update */ {$postmeta} SET meta_value = %s WHERE post_id = %d AND BINARY meta_key = %s",
				'outofstock',
				$product_id,
				'_stock_status'
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact single-row status projection is prepared above and verified below.
			$meta_updated = false === $meta_sql ? false : $wpdb->query( $meta_sql );
			if ( false === $meta_updated || (int) $meta_updated > 1 || '' !== (string) $wpdb->last_error ) {
				return false;
			}
			$meta_readback = $this->read_exact_meta_rows( $product_id, array( '_stock_status' ) );
			if ( is_wp_error( $meta_readback ) || array( '_stock_status' => array( 'outofstock' ) ) !== $meta_readback ) {
				return false;
			}
		}
		if ( ! $this->source_write_locks_are_owned( $product_id ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is the exact site-scoped Woo lookup table; values use placeholders.
		$lookup_sql = $wpdb->prepare(
			"UPDATE /* digitalogic_patris_unpriced_stock_lookup_update */ {$lookup_table} SET stock_status = %s WHERE product_id = %d",
			'outofstock',
			$product_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact single-row lookup projection is prepared above and verified below.
		$lookup_updated = false === $lookup_sql ? false : $wpdb->query( $lookup_sql );

		if (
			false === $lookup_updated
			|| (int) $lookup_updated > 1
			|| '' !== (string) $wpdb->last_error
			|| ! $this->source_write_locks_are_owned( $product_id )
		) {
			return false;
		}
		$lookup_readback = $this->read_exact_stock_lookup_projection( $product_id );

		return ! is_wp_error( $lookup_readback )
			&& 'outofstock' === $lookup_readback['stock_status'];
	}

	/**
	 * Clear every product cache WooCommerce clears after an authoritative update.
	 *
	 * @param int $product_id Exact WooCommerce product ID.
	 * @return void
	 * @throws RuntimeException When enabled instance caching cannot be invalidated.
	 */
	private function invalidate_unavailable_stock_projection_caches( $product_id ) {
		$product_id = absint( $product_id );
		wp_cache_delete( $product_id, 'post_meta' );
		clean_post_cache( $product_id );
		wc_delete_product_transients( $product_id );
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
		}

		$features_class      = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
		$product_cache_class = '\\Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
		if (
			class_exists( $features_class )
			&& class_exists( $product_cache_class )
			&& call_user_func( array( $features_class, 'feature_is_enabled' ), 'product_instance_caching' )
		) {
			$cache = wc_get_container()->get( $product_cache_class );
			if ( ! is_object( $cache ) || ! method_exists( $cache, 'remove' ) ) {
				throw new RuntimeException( 'The unavailable product object cache could not be invalidated.' );
			}
			$cache->remove( $product_id );
		}
	}

	/**
	 * Verify raw meta, lookup, and a cache-bypassed WooCommerce object agree.
	 *
	 * @param int              $product_id Exact WooCommerce product ID.
	 * @param WC_Product|false $fresh      Cache-bypassed WooCommerce product.
	 * @return bool
	 */
	private function unavailable_stock_projection_matches( $product_id, $fresh ) {
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! $fresh instanceof WC_Product
			|| ! $this->source_write_locks_are_owned( $product_id )
		) {
			return false;
		}
		$meta = $this->read_exact_meta_rows( $product_id, array( '_stock_status' ) );
		if ( is_wp_error( $meta ) || array( '_stock_status' => array( 'outofstock' ) ) !== $meta ) {
			return false;
		}

		$lookup = $this->read_exact_stock_lookup_projection( $product_id );

		$fresh_quantity = $fresh->get_stock_quantity();

		$lookup_quantity = is_wp_error( $lookup ) ? null : $lookup['stock_quantity'];

		$quantity_matches = ( null === $lookup_quantity && null === $fresh_quantity )
			|| (
				null !== $lookup_quantity
				&& null !== $fresh_quantity
				&& is_numeric( $lookup_quantity )
				&& is_numeric( $fresh_quantity )
				&& (float) $lookup_quantity === (float) $fresh_quantity
			);

		return ! is_wp_error( $lookup )
			&& 'outofstock' === $lookup['stock_status']
			&& $quantity_matches
			&& 'outofstock' === (string) $fresh->get_stock_status()
			&& '' === trim( (string) $fresh->get_regular_price() )
			&& '' === trim( (string) $fresh->get_sale_price() )
			&& '' === trim( (string) $fresh->get_price() )
			&& $this->unavailable_price_projection_matches( $product_id, $fresh );
	}

	/**
	 * Verify a blank customer price cannot be fabricated from Woo's zero sentinel.
	 *
	 * Both lookup bounds must be either NULL or numeric zero. A mixed pair or any
	 * non-zero value fails closed, as does any raw/Woo price, sale flag, or
	 * purchasable stock status. This keeps the lookup sentinel storage-only.
	 *
	 * @param int              $product_id Exact WooCommerce product ID.
	 * @param WC_Product|false $fresh      Cache-bypassed WooCommerce product.
	 * @return bool
	 */
	private function unavailable_price_projection_matches( $product_id, $fresh ) {
		$product_id = absint( $product_id );
		if (
			$product_id <= 0
			|| ! $fresh instanceof WC_Product
			|| ! $this->source_write_locks_are_owned( $product_id )
			|| 'outofstock' !== (string) $fresh->get_stock_status()
			|| '' !== trim( (string) $fresh->get_regular_price() )
			|| '' !== trim( (string) $fresh->get_sale_price() )
			|| '' !== trim( (string) $fresh->get_price() )
		) {
			return false;
		}

		$meta = $this->read_exact_meta_rows(
			$product_id,
			array( '_regular_price', '_sale_price', '_price', '_stock_status' )
		);
		if ( is_wp_error( $meta ) || array( 'outofstock' ) !== ( $meta['_stock_status'] ?? array() ) ) {
			return false;
		}
		foreach ( array( '_regular_price', '_sale_price', '_price' ) as $price_key ) {
			$rows = array_values( (array) ( $meta[ $price_key ] ?? array() ) );
			if ( count( $rows ) > 1 || ( 1 === count( $rows ) && '' !== trim( (string) reset( $rows ) ) ) ) {
				return false;
			}
		}

		$lookup = $this->read_exact_price_lookup_projection( $product_id );
		if ( is_wp_error( $lookup ) || '0' !== $lookup['onsale'] ) {
			return false;
		}
		$min_price = $this->pricing_batch_decimal( $lookup['min_price'] );
		$max_price = $this->pricing_batch_decimal( $lookup['max_price'] );

		return ( null === $min_price && null === $max_price )
			|| ( '0' === $min_price && '0' === $max_price );
	}

	/**
	 * Stage the complete canonical feed projection without persisting it.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param array      $data    Validated normalized product.
	 * @return void
	 */
	private function stage_product_feed( WC_Product $product, $data ) {
		$data = is_array( $data ) ? $data : array();
		$product->update_meta_data( '_digitalogic_patris_product_code', (string) ( $data['product_code'] ?? '' ) );

		$meta_fields    = $this->feed_meta_fields();
		$null_fields    = array();
		$missing_fields = array();
		foreach ( $meta_fields as $field => $definition ) {
			$this->sync_product_meta(
				$product,
				$data,
				$field,
				$definition[0],
				$definition[1],
				$null_fields,
				$missing_fields
			);
		}
		sort( $null_fields, SORT_STRING );
		sort( $missing_fields, SORT_STRING );
		$product->update_meta_data( '_digitalogic_patris_null_fields', wp_json_encode( $null_fields ) );
		$product->update_meta_data( '_digitalogic_patris_missing_fields', wp_json_encode( $missing_fields ) );

		if ( array_key_exists( 'weight_grams', $data ) && null !== $data['weight_grams'] ) {
			$store_weight = Digitalogic_Unit_Converter::grams_to_store_weight( $data['weight_grams'] );
			$product->set_weight( is_null( $store_weight ) ? '' : (string) $store_weight );
		} else {
			$product->set_weight( '' );
		}

		if ( ! array_key_exists( 'total_stock', $data ) || null === $data['total_stock'] ) {
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
				$product->delete_meta_data( '_stock' );
				$product->set_stock_status( 'outofstock' );
		} else {
			$stock_quantity = $data['total_stock'] > 0
				? max( 1, (int) floor( (float) $data['total_stock'] ) )
				: 0;
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock_quantity );
			$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
		}

		Digitalogic_Patris_Price_Policy::instance()->apply( $product, $data );
	}

	/** Field-to-meta mapping shared by the writer and its exact verifier. */
	private function feed_meta_fields() {
		return array(
			'category_code'                  => array( '_digitalogic_patris_category_code', false ),
			'name'                           => array( '_digitalogic_patris_name', false ),
			'serial'                         => array( '_digitalogic_patris_serial', false ),
			'unit'                           => array( '_digitalogic_patris_unit', false ),
			'unit_id'                        => array( '_digitalogic_patris_unit_id', false ),
			'sale_price_source'              => array( '_digitalogic_patris_sale_price_source', false ),
			'partner_price_source'           => array( '_digitalogic_patris_partner_price_source', false ),
			'purchase_price_source'          => array( '_digitalogic_patris_purchase_price_source', false ),
			'warehouse_stock'                => array( '_digitalogic_patris_warehouse_stock', true ),
			'total_stock'                    => array( '_digitalogic_patris_total_stock', false ),
			'minimum_stock'                  => array( '_digitalogic_patris_minimum_stock', false ),
			'foreign_currency'               => array( '_digitalogic_patris_foreign_currency', false ),
			'foreign_price'                  => array( '_digitalogic_patris_foreign_price', false ),
			'price_source_amount'            => array( '_digitalogic_patris_price_source_amount', false ),
			'price_source_currency'          => array( '_digitalogic_patris_price_source_currency', false ),
			'price_source_kind'              => array( '_digitalogic_patris_price_source_kind', false ),
			'weight_grams'                   => array( '_digitalogic_patris_weight_grams', false ),
			'location'                       => array( '_digitalogic_patris_location', false ),
			'shipping_method_id'             => array( '_digitalogic_patris_shipping_method_id', false ),
			'shipping_price_per_kg'          => array( '_digitalogic_patris_shipping_price_per_kg', false ),
			'shipping_price_per_kg_currency' => array( '_digitalogic_patris_shipping_price_per_kg_currency', false ),
			'markup_percent'                 => array( '_digitalogic_patris_markup_percent', false ),
			'irt_per_cny'                    => array( '_digitalogic_patris_irt_per_cny', false ),
			'price_rounding_digits'          => array( '_digitalogic_patris_price_rounding_digits', false ),
			'price_rounding_mode'            => array( '_digitalogic_patris_price_rounding_mode', false ),
			'pricing_catalog_revision'       => array( '_digitalogic_patris_pricing_catalog_revision', false ),
			'pricing_catalog_status'         => array( '_digitalogic_patris_pricing_catalog_status', false ),
			'currency_effective_date'        => array( '_digitalogic_patris_currency_effective_date', false ),
			'final_price'                    => array( '_digitalogic_patris_final_price', false ),
			'source_updated_at'              => array( '_digitalogic_patris_updated_at', false ),
			'warnings'                       => array( '_digitalogic_patris_warnings', true ),
			'record_hash'                    => array( '_digitalogic_patris_record_hash', false ),
			'flags'                          => array( '_digitalogic_patris_flags', true ),
			'raw'                            => array( '_digitalogic_patris_last_feed', true ),
		);
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Legacy private normalization helpers predate the strict documentation ruleset.
	/** Synchronize one normalized provider field to product metadata. */
	private function sync_product_meta( $product, $data, $field, $meta_key, $encode_json, &$null_fields, &$missing_fields ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		if ( ! array_key_exists( $field, $data ) ) {
			$product->delete_meta_data( $meta_key );
			$missing_fields[] = $field;
			return;
		}
		if ( null === $data[ $field ] ) {
			$product->delete_meta_data( $meta_key );
			$null_fields[] = $field;
			return;
		}

		$value = $encode_json
			? wp_json_encode( $data[ $field ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			: $data[ $field ];
		$product->update_meta_data( $meta_key, $value );
	}

	/** Sanitize one provider string. */
	private function clean_string( $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		return sanitize_text_field( wp_unslash( (string) $value ) );
	}

	/** Normalize one provider number. */
	private function clean_number( $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( is_string( $value ) ) {
			$value = str_replace( array( ',', '٬', '،', ' ' ), '', wp_unslash( $value ) );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
}
