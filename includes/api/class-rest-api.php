<?php
/**
 * REST API Class
 * 
 * Provides REST API endpoints for external integrations
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_REST_API {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_filter(
            'woocommerce_rest_is_request_to_rest_api',
            array($this, 'allow_woocommerce_rest_authentication')
        );
    }

    /**
     * Let WooCommerce authenticate API keys for this plugin's REST namespace.
     *
     * WooCommerce only recognizes its own wc/* and wc-* namespaces by default.
     * Preserve requests already recognized by WooCommerce and opt in only the
     * existing Digitalogic API and exact Patris integration routes
     * (including subdirectory installs and rest_route query parameters).
     *
     * @param bool $is_request Whether WooCommerce already recognizes the request.
     * @return bool
     */
    public function allow_woocommerce_rest_authentication($is_request) {
        if ($is_request) {
            return true;
        }

        if (empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $request_uri = (string) $_SERVER['REQUEST_URI'];
        $request_path = parse_url($request_uri, PHP_URL_PATH);
        if (!is_string($request_path)) {
            return false;
        }

        $query_match = $this->query_targets_digitalogic_namespace($request_uri);
        if (null !== $query_match) {
            return $query_match && $this->request_path_targets_front_controller($request_path);
        }

        return $this->request_path_targets_digitalogic_namespace($request_path);
    }

    /**
     * Check a rest_route query parameter against the Digitalogic namespace.
     *
     * @param string $request_uri Current request URI.
     * @return bool|null True or false when rest_route is present; null otherwise.
     */
    private function query_targets_digitalogic_namespace($request_uri) {
        $request_query = parse_url($request_uri, PHP_URL_QUERY);
        if (!is_string($request_query) || '' === $request_query) {
            return null;
        }

        parse_str($request_query, $query_params);
        if (!array_key_exists('rest_route', $query_params)) {
            return null;
        }

        if (!is_string($query_params['rest_route'])) {
            return false;
        }

        $route = '/' . trim($query_params['rest_route'], '/');

        return '/digitalogic/v1' === $route
            || str_starts_with($route, '/digitalogic/v1/')
            || '/digitalogic/reports' === $route
            || '/digitalogic/patris/product-sync' === $route
            || '/digitalogic/integration' === $route
            || str_starts_with($route, '/digitalogic/integration/');
    }

    /**
     * Match a pretty-permalink REST request without generating a REST URL.
     *
     * @param string $request_path Current request path.
     * @return bool
     */
    private function request_path_targets_digitalogic_namespace($request_path) {
        $rest_prefix = rest_get_url_prefix();
        if (!is_string($rest_prefix) || '' === trim($rest_prefix, '/')) {
            return false;
        }

        $request_path = $this->normalize_request_path($request_path);
        $base = $this->get_wordpress_base_path() . '/' . trim($rest_prefix, '/');
        $report_path = $this->normalize_request_path($base . '/digitalogic/reports');
        if ($request_path === $report_path) {
            return true;
        }
        $routes = array(
            '/digitalogic/v1',
            '/digitalogic/patris/product-sync',
            '/digitalogic/integration',
        );
        foreach ($routes as $route) {
            $api_path = $this->normalize_request_path($base . $route);
            if ($request_path === $api_path || str_starts_with($request_path, $api_path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ensure query-style REST requests target this WordPress front controller.
     *
     * @param string $request_path Current request path.
     * @return bool
     */
    private function request_path_targets_front_controller($request_path) {
        $request_path = $this->normalize_request_path($request_path);
        $base_path = $this->get_wordpress_base_path();
        $base_path = '' === $base_path ? '/' : $base_path;

        if ($request_path === $base_path) {
            return true;
        }

        $script_path = $this->get_wordpress_script_path();

        return null !== $script_path && $request_path === $script_path;
    }

    /**
     * Resolve the URL path of the active WordPress front controller.
     *
     * @return string|null
     */
    private function get_wordpress_script_path() {
        if (empty($_SERVER['SCRIPT_NAME']) || !is_string($_SERVER['SCRIPT_NAME'])) {
            return null;
        }

        $script_path = parse_url($_SERVER['SCRIPT_NAME'], PHP_URL_PATH);
        if (!is_string($script_path) || '' === $script_path) {
            return null;
        }

        return $this->normalize_request_path($script_path);
    }

    /**
     * Resolve the install subdirectory from the front-controller path.
     *
     * @return string Empty for a root installation.
     */
    private function get_wordpress_base_path() {
        $script_path = $this->get_wordpress_script_path();
        if (null === $script_path) {
            return '';
        }

        $base_path = str_replace('\\', '/', dirname($script_path));

        return '/' === $base_path || '.' === $base_path
            ? ''
            : $this->normalize_request_path($base_path);
    }

    /**
     * Normalize a URL path for exact, segment-boundary comparisons.
     *
     * @param string $path URL path.
     * @return string
     */
    private function normalize_request_path($path) {
        $path = '/' . trim($path, '/');

        return '/' === $path ? '/' : rtrim($path, '/');
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Products endpoints
        register_rest_route('digitalogic/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_products'),
            'permission_callback' => array($this, 'check_read_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_product'),
            'permission_callback' => array($this, 'check_read_permission')
        ));

		register_rest_route(
			'digitalogic/v1',
			'/products/sku/(?P<sku>[^/]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_by_sku' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);
        
        register_rest_route('digitalogic/v1', '/products/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_product'),
            'permission_callback' => array($this, 'check_write_permission')
        ));

		register_rest_route(
			'digitalogic/v1',
			'/products/sku/(?P<sku>[^/]+)',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_product_by_sku' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			'digitalogic/v1',
			'/products/(?P<id>\d+)/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_metadata' ),
				'permission_callback' => array( $this, 'check_diagnostic_permission' ),
			)
		);

		register_rest_route(
			'digitalogic/v1',
			'/products/sku/(?P<sku>[^/]+)/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_metadata_by_sku' ),
				'permission_callback' => array( $this, 'check_diagnostic_permission' ),
			)
		);
        
        register_rest_route('digitalogic/v1', '/products/batch', array(
            'methods' => 'POST',
            'callback' => array($this, 'batch_update_products'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Currency endpoints
        register_rest_route('digitalogic/v1', '/currency', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_currency'),
            'permission_callback' => array($this, 'check_read_permission')
        ));
        
        register_rest_route('digitalogic/v1', '/currency', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_currency'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Pricing endpoints
        register_rest_route('digitalogic/v1', '/pricing/recalculate', array(
            'methods' => 'POST',
            'callback' => array($this, 'recalculate_prices'),
            'permission_callback' => array($this, 'check_write_permission')
        ));
        
        // Export endpoint
        register_rest_route('digitalogic/v1', '/export', array(
            'methods' => 'GET',
            'callback' => array($this, 'export_products'),
            'permission_callback' => array($this, 'check_diagnostic_permission')
        ));

		register_rest_route(
			'digitalogic/v1',
			'/google-sheets/catalog',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_google_sheets_catalog' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		foreach ( array( 'preview', 'apply' ) as $writeback_mode ) {
			register_rest_route(
				'digitalogic/v1',
				'/google-sheets/writeback/' . $writeback_mode,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'apply' === $writeback_mode ? 'apply_google_sheets_writeback' : 'preview_google_sheets_writeback' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				)
			);
		}

        register_rest_route('digitalogic', '/reports', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_reports'),
            'permission_callback' => array($this, 'check_diagnostic_permission')
        ));

        // Living transformed-only Patris format. No raw-feed
        // aliases are registered for this integration surface.
        register_rest_route('digitalogic', '/patris/product-sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'receive_patris_product_sync'),
            'permission_callback' => array($this, 'check_patris_product_sync_permission')
        ));

		// Supplier shipping methods (not WooCommerce customer delivery methods).
		register_rest_route('digitalogic', '/integration/catalog', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_integration_catalog'),
            'permission_callback' => array($this, 'check_pricing_input_permission'),
        ));

        register_rest_route('digitalogic/v1', '/pricing/default-markup', array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'get_default_percentage_markup'),
                'permission_callback' => array($this, 'check_read_permission'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array($this, 'update_default_percentage_markup'),
                'permission_callback' => array($this, 'check_write_permission'),
            ),
        ));

		$this->register_shipping_method_routes();

		register_rest_route(
			'digitalogic',
			'/integration/pricing-assignments/batch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'get_pricing_assignments_batch' ),
				'permission_callback' => array( $this, 'check_pricing_input_permission' ),
			)
		);

		register_rest_route(
			'digitalogic',
			'/integration/products/by-code/(?P<code>[^/]+)/pricing',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product_pricing' ),
				'permission_callback' => array( $this, 'check_pricing_input_permission' ),
			)
		);

	}

	/**
	 * Register the canonical shipping-method management surface.
	 */
	private function register_shipping_method_routes() {
		$collections = array(
			'/shipping-methods' => array('list_shipping_methods', 'create_shipping_method'),
		);
		foreach ($collections as $route => $callbacks) {
			register_rest_route('digitalogic/v1', $route, array(
				array(
					'methods' => 'GET',
					'callback' => array($this, $callbacks[0]),
					'permission_callback' => array($this, 'check_read_permission'),
				),
				array(
					'methods' => 'POST',
					'callback' => array($this, $callbacks[1]),
					'permission_callback' => array($this, 'check_write_permission'),
				),
			));
		}

		$items = array(
			'/shipping-methods/(?P<id>[a-z][a-z0-9_]{1,63})' => array(
				'get_shipping_method',
				'update_shipping_method',
				'delete_shipping_method',
			),
		);
		foreach ($items as $route => $callbacks) {
			register_rest_route('digitalogic/v1', $route, array(
				array(
					'methods' => 'GET',
					'callback' => array($this, $callbacks[0]),
					'permission_callback' => array($this, 'check_read_permission'),
				),
				array(
					'methods' => 'PUT',
					'callback' => array($this, $callbacks[1]),
					'permission_callback' => array($this, 'check_write_permission'),
				),
				array(
					'methods' => 'DELETE',
					'callback' => array($this, $callbacks[2]),
					'permission_callback' => array($this, 'check_write_permission'),
				),
			));
		}

		$product_routes = array(
			'/products/by-code/(?P<code>[^/]+)/shipping-method' => array(
				'get_product_shipping_method',
				'assign_product_shipping_method',
			),
		);
		foreach ($product_routes as $route => $callbacks) {
			register_rest_route('digitalogic/v1', $route, array(
				array(
					'methods' => 'GET',
					'callback' => array($this, $callbacks[0]),
					'permission_callback' => array($this, 'check_read_permission'),
				),
				array(
					'methods' => 'PUT',
					'callback' => array($this, $callbacks[1]),
					'permission_callback' => array($this, 'check_write_permission'),
				),
			));
		}

		register_rest_route('digitalogic/v1', '/products/shipping-methods/batch', array(
			'methods' => 'POST',
			'callback' => array($this, 'batch_assign_product_shipping_methods'),
			'permission_callback' => array($this, 'check_write_permission'),
		));
    }
    
    /**
     * Backward-compatible permission check for existing integrations.
     *
     * This alias deliberately uses the write scope, matching the broad access
     * historically associated with this method without restoring read-level
     * authorization for management routes.
     *
     * @deprecated Use check_read_permission(), check_write_permission(), or
     *             check_diagnostic_permission() for an explicit route scope.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_permission($request = null) {
        return $this->check_write_permission($request);
    }

    /**
     * Check access to read-only catalog and currency routes.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_read_permission($request = null) {
        return $this->check_scoped_permission('read', $request);
    }

    /**
     * Check access to routes that mutate products, settings, or sync state.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_write_permission($request = null) {
        return $this->check_scoped_permission('write', $request);
    }

    /**
     * Check access to reports and exports that expose operational data.
     *
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    public function check_diagnostic_permission($request = null) {
        return $this->check_scoped_permission('diagnostic', $request);
    }

	/**
	 * Authorize the two exact Patris pricing-input contracts.
	 *
	 * Human administrators and shop managers retain their normal capability
	 * access. The separate machine identity is verified by its route-aware,
	 * header-only credential service and cannot fall through to other scopes.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return true|WP_Error
	 */
	public function check_pricing_input_permission( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			return true;
		}

		return Digitalogic_Pricing_Input_Credential::instance()->authorize( $request );
	}

    /**
     * Resolve a REST permission scope.
     *
     * The legacy filter remains available for explicit integrations. Its
     * default is deliberately false; callbacks must return the boolean true.
     * The second argument lets integrations grant only the required scope.
     *
     * @param string               $scope   One of read, write, or diagnostic.
     * @param WP_REST_Request|null $request Current REST request.
     * @return bool
     */
    private function check_scoped_permission($scope, $request = null) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        /**
         * Filter access to a Digitalogic REST API scope.
         *
         * @param bool                 $allowed Denied by default.
         * @param string               $scope   Permission scope.
         * @param WP_REST_Request|null $request Current REST request.
         */
        $allowed = apply_filters('digitalogic_rest_api_permission', false, $scope, $request);

        return true === $allowed;
    }

    /**
     * Authenticate the contract-aware receiver via its dedicated header-only
     * secret or the normal write scope (including WooCommerce credentials).
     *
     * @param WP_REST_Request $request Current request.
     * @return bool
     */
    public function check_patris_product_sync_permission(WP_REST_Request $request) {
        if (Digitalogic_Patris_Feed::instance()->verify_product_sync_request($request)) {
            return true;
        }

        return $this->check_write_permission($request);
    }

    /**
     * GET /products
     */
    public function get_products(WP_REST_Request $request) {
        $params = $request->get_params();
        $result = Digitalogic_Product_Manager::instance()->query_products($params);

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result['products'],
            'total' => $result['total'],
            'recordsTotal' => $result['recordsTotal'],
            'recordsFiltered' => $result['recordsFiltered'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'pages' => $result['pages'],
        ), 200);
    }

	/**
	 * GET /google-sheets/catalog
	 *
	 * Return one bounded Products or Categories page through the shared catalog
	 * projection. External clients authenticate with a read-only WooCommerce
	 * key, while signed-in administrators keep their WordPress session.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_google_sheets_catalog( WP_REST_Request $request ) {
		$result = Digitalogic_Google_Sheets_Catalog::instance()->get_page( $request->get_params() );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
				$status
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * POST /google-sheets/writeback/preview
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function preview_google_sheets_writeback( WP_REST_Request $request ) {
		return $this->google_sheets_writeback_response(
			Digitalogic_Google_Sheets_Writeback::instance()->preview( $request->get_json_params() )
		);
	}

	/**
	 * POST /google-sheets/writeback/apply
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function apply_google_sheets_writeback( WP_REST_Request $request ) {
		return $this->google_sheets_writeback_response(
			Digitalogic_Google_Sheets_Writeback::instance()->apply( $request->get_json_params() )
		);
	}

	/**
	 * Wrap one transport-neutral Sheets writeback result.
	 *
	 * @param array|WP_Error $result Service result.
	 * @return WP_REST_Response
	 */
	private function google_sheets_writeback_response( $result ) {
		if ( is_wp_error( $result ) ) {
			$data    = $result->get_error_data();
			$status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			$details = is_array( $data ) ? $data : array();
			unset( $details['status'] );

			$response = array(
				'success' => false,
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			);
			if ( $details ) {
				$response['details'] = $details;
			}

			return new WP_REST_Response( $response, $status );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			),
			200
		);
	}
    
	/**
	 * GET /products/{id}
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_product( WP_REST_Request $request ) {
		$product = Digitalogic_Product_Manager::instance()->get_product_by_identifiers(
			array(
				'woocommerce_id' => (string) $request['id'],
			)
		);

		if ( is_wp_error( $product ) ) {
			return $this->product_error_response( $product, 404 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $product,
			),
			200
		);
	}

	/**
	 * GET /products/sku/{sku}
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_product_by_sku( WP_REST_Request $request ) {
		$product = Digitalogic_Product_Manager::instance()->get_product_by_identifiers(
			array(
				'sku' => (string) $request['sku'],
			)
		);

		if ( is_wp_error( $product ) ) {
			return $this->product_error_response( $product, 404 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $product,
			),
			200
		);
	}

	/**
	 * GET /products/{id}/metadata
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_product_metadata( WP_REST_Request $request ) {
		$metadata = Digitalogic_Product_Manager::instance()->get_product_metadata(
			array(
				'woocommerce_id' => (string) $request['id'],
			)
		);

		if ( is_wp_error( $metadata ) ) {
			return $this->product_error_response( $metadata, 404 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $metadata,
			),
			200
		);
	}

	/**
	 * GET /products/sku/{sku}/metadata
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_product_metadata_by_sku( WP_REST_Request $request ) {
		$metadata = Digitalogic_Product_Manager::instance()->get_product_metadata(
			array(
				'sku' => (string) $request['sku'],
			)
		);

		if ( is_wp_error( $metadata ) ) {
			return $this->product_error_response( $metadata, 404 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => $metadata,
			),
			200
		);
	}

	/**
	 * PUT /products/{id}
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_product( WP_REST_Request $request ) {
		$result = Digitalogic_Product_Manager::instance()->update_product_by_identifiers(
			array( 'woocommerce_id' => (string) $request['id'] ),
			$request->get_json_params()
		);

		if ( is_wp_error( $result ) ) {
			return $this->product_error_response( $result, 400 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Product updated successfully',
			),
			200
		);
	}

	/**
	 * PUT /products/sku/{sku}
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_product_by_sku( WP_REST_Request $request ) {
		$result = Digitalogic_Product_Manager::instance()->update_product_by_identifiers(
			array( 'sku' => (string) $request['sku'] ),
			$request->get_json_params()
		);

		if ( is_wp_error( $result ) ) {
			return $this->product_error_response( $result, 400 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Product updated successfully',
			),
			200
		);
	}

	/**
	 * Convert a product service error to a REST response.
	 *
	 * @param WP_Error $error Product service error.
	 * @param int      $default_status Default HTTP response status.
	 * @return WP_REST_Response
	 */
	private function product_error_response( $error, $default_status ) {
		$details = $error->get_error_data();
		$status  = is_array( $details ) && isset( $details['status'] )
			? (int) $details['status']
			: (int) $default_status;

		return new WP_REST_Response(
			array(
				'success' => false,
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'details' => is_array( $details ) ? $details : array(),
			),
			$status
		);
	}
    
    /**
     * POST /products/batch
     */
    public function batch_update_products(WP_REST_Request $request) {
        $updates = $request->get_json_params();
        
        if (empty($updates)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No updates provided'
            ), 400);
        }
        
        $manager = Digitalogic_Product_Manager::instance();
        $results = $manager->bulk_update($updates);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $results
        ), 200);
    }
    
    /**
     * GET /currency
     */
    public function get_currency(WP_REST_Request $request) {
        return new WP_REST_Response(array(
            'success' => true,
            'data' => Digitalogic_Command_Dispatcher::instance()->get_currency()
        ), 200);
    }
    
    /**
     * POST /currency
     */
    public function update_currency(WP_REST_Request $request) {
        $data = $request->get_json_params();
        
        $options = Digitalogic_Options::instance();
        
        if (isset($data['dollar_price'])) {
            $options->set_dollar_price($data['dollar_price']);
        }
        
        if (isset($data['yuan_price'])) {
            $options->set_yuan_price($data['yuan_price']);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Currency rates updated'
        ), 200);
    }
    
    /**
     * POST /pricing/recalculate
     */
    public function recalculate_prices(WP_REST_Request $request) {
        $pricing = Digitalogic_Pricing::instance();
        $results = $pricing->bulk_recalculate_prices();
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $results
        ), 200);
    }
    
    /**
     * GET /export
     */
    public function export_products(WP_REST_Request $request) {
        $format = $request->get_param('format') ?: 'json';
        $product_ids = $request->get_param('product_ids') ?: array();
        
        $import_export = Digitalogic_Import_Export::instance();
        
        if ($format === 'csv') {
            $filepath = $import_export->export_csv($product_ids);
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel($product_ids);
        } else {
            $filepath = $import_export->export_json($product_ids);
        }
        
        if (is_wp_error($filepath)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $filepath->get_error_message()
            ), 500);
        }
        
        $upload_dir = wp_upload_dir();
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $filepath);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'url' => $file_url,
                'format' => $format
            )
        ), 200);
    }

    public function get_reports(WP_REST_Request $request) {
        return new WP_REST_Response(array(
            'success' => true,
            'data' => Digitalogic_Report_Engine::instance()->get_report($request->get_params())
        ), 200);
    }

    /**
     * POST /patris/product-sync
     *
     * Consume the deterministic patris.product-sync envelope. Patris
     * identity headers are optional, but when present they must agree with the
     * body so proxies cannot accidentally pair stale metadata with new JSON.
     */
    public function receive_patris_product_sync(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        $header_contract = $request->get_header('x-patris-contract');
        $header_event_id = $request->get_header('x-patris-event-id');
        $header_checks = array(
            'x-patris-contract' => array($header_contract, is_array($payload) ? ($payload['schema'] ?? null) : null),
            'x-patris-event-id' => array($header_event_id, is_array($payload) ? ($payload['event_id'] ?? null) : null),
        );
        foreach ($header_checks as $header => $values) {
            $provided = $values[0];
            $expected = $values[1];
            if (null === $provided || '' === $provided) {
                continue;
            }

            if (!is_string($provided) || !is_string($expected) || !hash_equals($provided, $expected)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'code' => 'digitalogic_product_sync_header_mismatch',
                    'message' => 'Patris identity headers must match the product-sync body.',
                    'details' => array('header' => $header),
                ), 400);
            }
        }

        $body = method_exists($request, 'get_body') ? $request->get_body() : '';
        if (!is_string($body) || '' === trim($body)) {
            $body = wp_json_encode(is_array($payload) ? $payload : array());
        }
        $result = Digitalogic_Product_Sync_Receiver::instance()->receive_json($body);

        return $this->product_sync_response($result);
    }

    public function get_integration_catalog(WP_REST_Request $request) {
		return $this->shipping_method_response(
            Digitalogic_Command_Dispatcher::instance()->get_integration_catalog($request->get_params())
        );
    }

    public function get_default_percentage_markup(WP_REST_Request $request) {
		return $this->shipping_method_response(
            Digitalogic_Command_Dispatcher::instance()->get_default_percentage_markup($request->get_params())
        );
    }

    public function update_default_percentage_markup(WP_REST_Request $request) {
		return $this->shipping_method_response(
            Digitalogic_Command_Dispatcher::instance()->update_default_percentage_markup($this->request_payload($request))
        );
    }

	public function list_shipping_methods(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->list_shipping_methods($request->get_params())
		);
	}

	public function create_shipping_method(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->create_shipping_method($this->request_payload($request)),
			201
		);
	}

	public function get_shipping_method(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->get_shipping_method(array('id' => $request['id']))
		);
	}

	public function update_shipping_method(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->update_shipping_method(array(
				'id' => $request['id'],
				'method' => $this->request_payload($request),
			))
		);
	}

	public function delete_shipping_method(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->delete_shipping_method(array('id' => $request['id']))
		);
	}

	public function get_product_shipping_method(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->get_product_shipping_method(array('code' => $request['code']))
		);
	}

	public function assign_product_shipping_method(WP_REST_Request $request) {
		$payload = $this->request_payload($request);
		$payload['code'] = $request['code'];

		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->assign_product_shipping_method($payload)
		);
	}

	public function batch_assign_product_shipping_methods(WP_REST_Request $request) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->batch_assign_product_shipping_methods($this->request_payload($request))
		);
	}

	/**
	 * Read a bounded batch of product pricing assignments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_pricing_assignments_batch( WP_REST_Request $request ) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->get_pricing_assignments_batch( $this->request_payload( $request ) )
		);
	}

	public function get_product_pricing( WP_REST_Request $request ) {
		return $this->shipping_method_response(
			Digitalogic_Command_Dispatcher::instance()->get_product_pricing(
				array( 'code' => $request['code'] )
			)
		);
	}

    private function request_payload(WP_REST_Request $request) {
        $payload = $request->get_json_params();

        return is_array($payload) ? $payload : array();
    }

	private function shipping_method_response($result, $success_status = 200) {
        if (is_wp_error($result)) {
            $details = $result->get_error_data();
            $status = is_array($details) && isset($details['status']) ? (int) $details['status'] : 400;

            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'details' => $details,
            ), $status);
        }

        return new WP_REST_Response(array('success' => true, 'data' => $result), $success_status);
    }

    private function product_sync_response($result) {
        if (is_wp_error($result)) {
            $details = $result->get_error_data();
            $status = is_array($details) && isset($details['status']) ? (int) $details['status'] : 400;

            return new WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'details' => $details,
            ), $status);
        }

        return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
    }
}
