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

    public function import_payload($payload, $source = 'push') {
        if (!is_array($payload)) {
            return new WP_Error('digitalogic_patris_invalid_payload', __('Source payload must be an object.', 'digitalogic'));
        }

        $products  = $this->extract_list($payload, 'products');
        $customers = $this->extract_list($payload, 'customers');

        if (empty($products) && empty($customers)) {
            return new WP_Error('digitalogic_patris_empty_payload', __('Source payload did not contain products or customers.', 'digitalogic'));
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
            'synced_at'              => current_time('mysql'),
        );

        foreach ($products as $row) {
            $product_data = $this->normalize_product($row);
            if (empty($product_data['product_code'])) {
                $results['failed']++;
                $results['errors'][] = __('Skipped product without product_code.', 'digitalogic');
                continue;
            }

            $results['total']++;
            // Keep the complete normalized upstream snapshot for reporting and
            // reconciliation even when no unique WooCommerce target exists.
            // Resolution failures below must never turn into product writes.
            $normalized_products[$product_data['product_code']] = $product_data;

            $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(array(
                'patris_code' => $product_data['product_code'],
            ));
            if (is_wp_error($resolved)) {
                if ('digitalogic_product_identifier_not_found' === $resolved->get_error_code()) {
                    $results['missing_in_woocommerce']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = 'digitalogic_product_identifier_ambiguous' === $resolved->get_error_code()
                        ? __('Skipped product because its exact Product Code is ambiguous.', 'digitalogic')
                        : __('Skipped product because its Product Code could not be resolved.', 'digitalogic');
                }
                continue;
            }

            $product_id = (int) $resolved['woocommerce_id'];

            $product = wc_get_product($product_id);
            if (!$product) {
                $results['failed']++;
                $results['errors'][] = sprintf(__('WooCommerce product for %s could not be loaded.', 'digitalogic'), $product_data['product_code']);
                continue;
            }

            $this->apply_product_feed($product, $product_data);
            $results['updated']++;
        }

        $normalized_customers          = $this->normalize_customers($customers);
        $results['customers_imported'] = count($normalized_customers);

        if (!empty($products)) {
            update_option(self::PRODUCTS_OPTION, $normalized_products, false);
        }
        if (!empty($customers)) {
            update_option(self::CUSTOMERS_OPTION, $normalized_customers, false);
        }
        update_option(self::LAST_SYNC_OPTION, $results, false);

        Digitalogic_Logger::instance()->log(
            'patris_feed_sync',
            'patris_feed',
            null,
            null,
            wp_json_encode($results),
            'Source feed synchronized'
        );

        do_action('digitalogic_patris_feed_synced', $results);

        return $results;
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
	 * @return mixed Guard callback result.
	 */
	public function apply_product_feed( WC_Product $product, $data ) {
		return Digitalogic_Patris_Price_Write_Guard::instance()->with_authorized_write(
			function () use ( $product, $data ) {
				$this->apply_product_feed_authorized( $product, $data );
			}
		);
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

				$plans    = array();
				$warnings = array();
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
					'updated_ids' => array_map( 'intval', array_keys( $plans ) ),
					'batches'     => $batch_count,
					'meta_rows'   => $meta_row_count,
					'warnings'    => $warnings,
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
		$data = is_array( $data ) ? $data : array();
		$product->update_meta_data( '_digitalogic_patris_product_code', (string) ( $data['product_code'] ?? '' ) );

		$meta_fields    = array(
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

		if ( array_key_exists( 'total_stock', $data ) ) {
			if ( null === $data['total_stock'] ) {
				$product->set_manage_stock( false );
				$product->set_stock_quantity( null );
				$product->delete_meta_data( '_stock' );
			} else {
				$stock_quantity = $data['total_stock'] > 0
					? max( 1, (int) floor( (float) $data['total_stock'] ) )
					: 0;
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $stock_quantity );
				$product->set_stock_status( $stock_quantity > 0 ? 'instock' : 'outofstock' );
			}
		}

		$price_policy = Digitalogic_Patris_Price_Policy::instance();
		$price_policy->apply( $product, $data );
		$product->save();
		$price_policy->invalidate( $product );
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
