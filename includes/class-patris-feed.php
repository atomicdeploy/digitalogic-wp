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
		$meta  = $this->read_exact_meta_rows( $product_id, array_keys( $expected['meta'] ) );
		$fresh = $this->fresh_product_for_source_readback( $product_id );
		if (
			is_wp_error( $meta )
			|| $this->normalize_meta_readback_projection( $meta ) !== $this->normalize_meta_readback_projection( $expected['meta'] )
			|| ! $fresh instanceof WC_Product
			|| ! $this->source_props_match( $fresh, $expected['props'] )
		) {
			return new WP_Error(
				'digitalogic_patris_product_projection_readback_failed',
				__( 'The source product projection did not pass exact database readback.', 'digitalogic' ),
				array( 'status' => 503, 'retryable' => true )
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
		if ( is_wp_error( $stock_lookup ) ) {
			return $stock_lookup;
		}

		return array(
			'product_id'   => $product_id,
			'canonical'    => $canonical,
			'meta'         => $meta,
			'stock_lookup' => $stock_lookup,
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
				$warnings                = array();
				$commit_snapshots        = array();
				$additional_managed_keys = array();
				foreach ( $items as $item ) {
					$product = $item['product'] ?? null;
					$data    = is_array( $item['data'] ?? null ) ? $item['data'] : array();
					if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
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
				foreach ( array_chunk( $plans, 200, true ) as $chunk ) {
					++$batch_count;
					$ids        = array_keys( $chunk );
					$delete_sql = '/* digitalogic_pricing_batch_meta_delete ids:' . count( $ids ) . " */ DELETE FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $managed_keys ), '%s' ) ) . ')';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Dynamic placeholder counts are prepared immediately before the transactional batch query.
					if ( false === $wpdb->query( $wpdb->prepare( $delete_sql, ...array_merge( $ids, $managed_keys ) ) ) ) {
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
					if ( ! empty( $meta_values ) ) {
						$insert_sql = "/* digitalogic_pricing_batch_meta_insert */ INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $meta_values );
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

					$read_sql = '/* digitalogic_pricing_batch_meta_readback ids:' . count( $ids ) . " */ SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key IN (' . implode( ',', array_fill( 0, count( $managed_keys ), '%s' ) ) . ') ORDER BY post_id, meta_key, meta_id';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact transactional readback requires the generated placeholder list and bypasses stale caches.
					$read_rows = $wpdb->get_results( $wpdb->prepare( $read_sql, ...array_merge( $ids, $managed_keys ) ), ARRAY_A );
					if ( ! $this->pricing_batch_meta_readback_matches( $chunk, $read_rows ) ) {
						return $this->pricing_batch_error( 'readback' );
					}
					$lookup_read_sql = "/* digitalogic_pricing_batch_lookup_readback */ SELECT product_id, min_price, max_price, onsale FROM {$lookup_table} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY product_id';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact transactional lookup readback uses generated placeholders and must bypass caches.
					$lookup_rows = $wpdb->get_results( $wpdb->prepare( $lookup_read_sql, ...$ids ), ARRAY_A );
					if ( ! $this->pricing_batch_lookup_readback_matches( $chunk, $lookup_rows ) ) {
						return $this->pricing_batch_error( 'lookup_readback' );
					}
				}

				return array(
					'updated_ids'      => array_map( 'intval', array_keys( $plans ) ),
					'batches'          => $batch_count,
					'meta_rows'        => $meta_row_count,
					'warnings'         => $warnings,
					'commit_snapshots' => $commit_snapshots,
				);
			}
		);
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
			if ( $product_id > 0 && '' !== $meta_key ) {
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
			if ( $product_id > 0 ) {
				$actual[ $product_id ] = $row;
			}
		}
		foreach ( $plans as $product_id => $plan ) {
			if ( ! isset( $actual[ (int) $product_id ] ) ) {
				return false;
			}
			$row      = $actual[ (int) $product_id ];
			$expected = $this->pricing_batch_decimal( $plan['lookup_price'] );
			if (
				$expected !== $this->pricing_batch_decimal( $row['min_price'] ?? null )
				|| $expected !== $this->pricing_batch_decimal( $row['max_price'] ?? null )
				|| 0 !== (int) ( $row['onsale'] ?? -1 )
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
		if ( str_contains( $value, '.' ) ) {
			$value = rtrim( rtrim( $value, '0' ), '.' );
		}
		return '' === $value ? '0' : $value;
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
		$this->persist_unpriced_positive_stock_status( $product );
		Digitalogic_Patris_Price_Policy::instance()->invalidate( $product );
	}

	/**
	 * Persist unavailable status after WooCommerce and save hooks synchronize stock.
	 *
	 * A positive canonical quantity remains exact even when price inputs are
	 * unavailable. WooCommerce integrations may promote that quantity to
	 * `instock` during the full product save, so the final status-only projection
	 * is written directly to Woo's authoritative meta and lookup row while both
	 * source locks remain held. No second product save or hook fan-out is used.
	 *
	 * @param WC_Product $product Product staged by the canonical writer.
	 * @return void
	 * @throws RuntimeException When the exact unavailable state cannot be persisted and verified.
	 */
	private function persist_unpriced_positive_stock_status( WC_Product $product ) {
		$status = (string) $product->get_meta( Digitalogic_Patris_Price_Policy::STATUS_META, true );
		if (
			! in_array( $status, array( 'canonical_missing_unpriced', 'canonical_nonpositive_unpriced' ), true )
			|| (float) $product->get_stock_quantity() <= 0
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

		return ! is_wp_error( $lookup )
			&& 'outofstock' === $lookup['stock_status']
			&& null !== $lookup['stock_quantity']
			&& (float) $lookup['stock_quantity'] === (float) $fresh->get_stock_quantity()
			&& 'outofstock' === (string) $fresh->get_stock_status()
			&& (float) $fresh->get_stock_quantity() > 0
			&& '' === trim( (string) $fresh->get_regular_price() )
			&& '' === trim( (string) $fresh->get_sale_price() )
			&& '' === trim( (string) $fresh->get_price() );
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
