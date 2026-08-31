<?php
/**
 * Shared command dispatcher for AJAX, REST, and WebSocket transports.
 */

if (!defined( 'ABSPATH' )) {
    exit;
}

class Digitalogic_Ajax_Die_Exception extends Exception {
    private $title;
    private $args;

    public function __construct($message = '', $title = '', $args = array()) {
        parent::__construct( (string) $message );
        $this->title = $title;
        $this->args  = is_array( $args ) ? $args : array();
    }

    public function get_title() {
        return $this->title;
    }

    public function get_args() {
        return $this->args;
    }
}

class Digitalogic_Command_Dispatcher {

    private static $instance = null;

    public static function instance() {
        if (is_null( self::$instance )) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Execute a Digitalogic command by its existing AJAX action name.
     *
     * @param string $command Command/action name.
     * @param array  $payload Request payload.
     * @param string $transport Transport name for filters/logging.
     * @return mixed|WP_Error
     */
    public function execute($command, $payload = array(), $transport = 'ajax') {
        $command = self::normalize_command_name( $command );

        if (!is_array( $payload )) {
            return new WP_Error( 'digitalogic_invalid_payload', __( 'Payload must be an object.', 'digitalogic' ), array('status' => 400) );
        }

        $requires_auth = (bool) apply_filters( 'digitalogic_command_requires_auth', true, $command, $payload, $transport );
        if ($requires_auth && ! Digitalogic_Access_Control::can_access_panel() ) {
            return new WP_Error( 'digitalogic_unauthorized', __( 'Unauthorized', 'digitalogic' ), array('status' => 403) );
        }

        $commands = apply_filters( 'digitalogic_command_handlers', $this->default_handlers(), $transport );

        if (!isset( $commands[$command] ) || !is_callable( $commands[$command] )) {
            if (in_array( $transport, array('websocket', 'laravel'), true )) {
                return $this->execute_wp_ajax_action( $command, $payload, $transport );
            }

            return new WP_Error( 'digitalogic_unknown_command', __( 'Unknown command.', 'digitalogic' ), array('status' => 404) );
        }

        return call_user_func( $commands[$command], $payload, $transport );
    }

    private function default_handlers() {
        return array(
            'digitalogic_get_products'                     => array($this, 'get_products'),
            'digitalogic_get_product'                      => array($this, 'get_product'),
            'digitalogic_update_product'                   => array($this, 'update_product'),
			// phpcs:ignore WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned -- Keep this focused addition out of the legacy array's formatting debt.
			'digitalogic_update_product_code' => array( $this, 'update_product_code' ),
            'digitalogic_bulk_update'                      => array($this, 'bulk_update'),
            'digitalogic_update_currency'                  => array($this, 'update_currency'),
            'digitalogic_get_currency'                     => array($this, 'get_currency'),
            'digitalogic_currency_job_status'              => array($this, 'get_currency_job_status'),
			'digitalogic_cancel_currency_job'              => array( $this, 'cancel_currency_job' ),
            'digitalogic_export'                           => array($this, 'export'),
            'digitalogic_get_logs'                         => array($this, 'get_logs'),
            'digitalogic_get_reports'                      => array($this, 'get_reports'),
            'digitalogic_update_patris_settings'           => array($this, 'update_patris_settings'),
            'digitalogic_get_integration_catalog'          => array($this, 'get_integration_catalog'),
            'digitalogic_get_default_percentage_markup'    => array($this, 'get_default_percentage_markup'),
            'digitalogic_update_default_percentage_markup' => array($this, 'update_default_percentage_markup'),
			'digitalogic_list_shipping_methods'            => array($this, 'list_shipping_methods'),
			'digitalogic_create_shipping_method'           => array($this, 'create_shipping_method'),
			'digitalogic_get_shipping_method'              => array($this, 'get_shipping_method'),
			'digitalogic_update_shipping_method'           => array($this, 'update_shipping_method'),
			'digitalogic_delete_shipping_method'           => array($this, 'delete_shipping_method'),
			'digitalogic_get_product_shipping_method'      => array($this, 'get_product_shipping_method'),
			'digitalogic_get_product_pricing'              => array($this, 'get_product_pricing'),
			'digitalogic_get_pricing_assignments_batch'    => array($this, 'get_pricing_assignments_batch'),
			'digitalogic_assign_product_shipping_method'   => array($this, 'assign_product_shipping_method'),
			'digitalogic_batch_assign_product_shipping_methods' => array($this, 'batch_assign_product_shipping_methods'),
        );
    }

    public function get_products($payload) {
        return Digitalogic_Product_Manager::instance()->query_products( $payload );
    }

    public function get_product($payload) {
        $product_id = isset( $payload['product_id'] ) ? intval( $payload['product_id'] ) : 0;
        if (!$product_id && isset( $payload['id'] )) {
            $product_id = intval( $payload['id'] );
        }

        if ($product_id <= 0) {
            return new WP_Error( 'digitalogic_invalid_product', __( 'Product ID is required.', 'digitalogic' ), array('status' => 400) );
        }

        $product = Digitalogic_Product_Manager::instance()->get_product( $product_id );
        if (!$product) {
            return new WP_Error( 'digitalogic_product_not_found', __( 'Product not found.', 'digitalogic' ), array('status' => 404) );
        }

        return array('product' => $product);
    }

    public function update_product($payload) {
        $product_id = isset( $payload['product_id'] ) ? intval( $payload['product_id'] ) : 0;
        $data       = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

        if ($product_id <= 0 || empty( $data )) {
            return new WP_Error( 'digitalogic_invalid_product_update', __( 'Product ID and data are required.', 'digitalogic' ), array('status' => 400) );
        }

        $result = Digitalogic_Product_Manager::instance()->update_product( $product_id, $this->sanitize_product_data( $data ) );
        if (is_wp_error( $result )) {
            return $result;
        }

        return array(
            'message' => __( 'Product updated', 'digitalogic' ),
            'product' => Digitalogic_Product_Manager::instance()->get_product( $product_id ),
        );
    }

	/**
	 * Run the explicit, source-guarded canonical Product Code editor.
	 *
	 * @param mixed $payload Command payload.
	 * @return array|WP_Error
	 */
	public function update_product_code( $payload ) {
		return Digitalogic_Product_Code_Editor::instance()->edit( $payload );
	}

    public function bulk_update($payload) {
        $updates = isset( $payload['updates'] ) && is_array( $payload['updates'] ) ? $payload['updates'] : array();
        if (empty( $updates )) {
            return new WP_Error( 'digitalogic_empty_bulk_update', __( 'No updates provided.', 'digitalogic' ), array('status' => 400) );
        }

        $sanitized = array();
        foreach ($updates as $product_id => $data) {
            $product_id = intval( $product_id );
            if ($product_id > 0 && is_array( $data )) {
                $sanitized[$product_id] = $this->sanitize_product_data( $data );
            }
        }

        return Digitalogic_Product_Manager::instance()->bulk_update( $sanitized );
    }

    public function update_currency($payload, $transport = 'command') {
        $values = array();
        foreach (array('dollar_price', 'yuan_price', 'effective_date', 'usd_effective_date', 'cny_effective_date') as $field) {
            if (is_array($payload) && array_key_exists($field, $payload)) {
                $values[$field] = $payload[$field];
            }
        }

        $expected_revision = is_array($payload) && isset($payload['expected_state_revision'])
            ? sanitize_text_field((string) $payload['expected_state_revision'])
            : '';
        if (1 !== preg_match('/\Asha256:[a-f0-9]{64}\z/D', $expected_revision)) {
            return new WP_Error(
                'digitalogic_currency_expected_revision_required',
                'پیش از تغییر نرخ، وضعیت فعلی قیمت را تازه‌سازی کنید.',
                array('status' => 428, 'blocking' => true)
            );
        }
		$request_id = is_array($payload) ? sanitize_text_field((string) ($payload['request_id'] ?? '')) : '';
		if (1 !== preg_match('/\A[a-zA-Z0-9._:-]{8,128}\z/D', $request_id)) {
			return new WP_Error(
				'digitalogic_currency_request_id_required',
				'برای هر تغییر نرخ یک شناسهٔ درخواست یکتا لازم است.',
				array('status' => 428, 'blocking' => true)
			);
		}

        return Digitalogic_Currency_Admin_Async::instance()->enqueue_currency(
            $values,
            true,
            false,
            $expected_revision,
			'command_' . sanitize_key((string) $transport),
			$request_id
        );
    }

    /** Return one exact async currency job to Ajax/WebSocket/panel consumers. */
    public function get_currency_job_status($payload) {
        $payload    = is_array($payload) ? $payload : array();
        $job_id     = sanitize_text_field((string) ($payload['job_id'] ?? ''));
        $generation = absint($payload['generation'] ?? 0);

		$request_id = sanitize_text_field((string) ($payload['request_id'] ?? ''));

		return '' !== $request_id
			? Digitalogic_Currency_Admin_Async::instance()->status_by_request($request_id)
			: Digitalogic_Currency_Admin_Async::instance()->status($job_id, $generation);
	}

	/** Request cooperative cancellation without replaying the original mutation. */
	public function cancel_currency_job($payload) {
		$payload    = is_array($payload) ? $payload : array();
		$job_id     = sanitize_text_field((string) ($payload['job_id'] ?? ''));
		$generation = absint($payload['generation'] ?? 0);
		$request_id = sanitize_text_field((string) ($payload['request_id'] ?? ''));

		return Digitalogic_Currency_Admin_Async::instance()->cancel($job_id, $generation, $request_id);
	}

    public function get_currency() {
        $options = Digitalogic_Options::instance();
        $data    = array(
            'dollar_price'     => $options->get_dollar_price(),
            'yuan_price'       => $options->get_yuan_price(),
            'update_date'      => $options->get_update_date(),
            'updated_at'       => $options->get_update_date_formatted(),
            'woocommerce_base' => Digitalogic_WooCommerce_Currency_Status::instance()->get_status(),
        );
        $state   = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
        if (!is_wp_error($state)) {
            $data['dollar_price']           = $state['settings']['dollar_price'];
            $data['yuan_price']             = $state['settings']['yuan_price'];
            $data['effective_date']         = $state['settings']['effective_date'];
            $data['usd_effective_date']     = $state['settings']['usd_effective_date'];
            $data['cny_effective_date']     = $state['settings']['cny_effective_date'];
            $data['profit_margin_percent']  = $state['settings']['profit_margin_percent'];
            $data['default_profit_percent'] = $state['settings']['profit_margin_percent'];
            $data['deprecated_aliases']     = array(
                'default_profit_percent' => array(
                    'replacement' => 'profit_margin_percent',
                    'equivalent'  => true,
                ),
            );
            $data['state_revision']         = $state['state_revision'];
            $data['freshness']              = $state['freshness'];
            $data['rate_provenance']        = $state['rate_provenance'];
        }

        return $data;
    }

    public function export($payload) {
        $format      = isset( $payload['format'] ) ? sanitize_key( $payload['format'] ) : 'csv';
        $product_ids = isset( $payload['product_ids'] ) && is_array( $payload['product_ids'] )
            ? array_map( 'intval', $payload['product_ids'] )
            : array();
        $locale      = isset( $payload['locale'] ) ? sanitize_key( $payload['locale'] ) : 'en';
        $template    = ! empty( $payload['template'] );

        $import_export = Digitalogic_Import_Export::instance();
        if ($format === 'json') {
            $filepath = $import_export->export_json( $product_ids );
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel(
                $product_ids,
                array(
                    'locale'   => $locale,
                    'template' => $template,
                )
            );
        } else {
            $format   = 'csv';
            $filepath = $import_export->export_csv( $product_ids );
        }

        if (is_wp_error( $filepath )) {
            return $filepath;
        }

        $upload_dir = wp_upload_dir();

        return array(
            'url'      => str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $filepath ),
            'filepath' => $filepath,
            'format'   => $format,
            'locale'   => $locale,
            'template' => 'excel' === $format && $template,
        );
    }

    public function get_logs($payload) {
        $page   = isset( $payload['page'] ) ? max( 1, intval( $payload['page'] ) ) : 1;
        $limit  = isset( $payload['limit'] ) ? max( 1, min( 200, intval( $payload['limit'] ) ) ) : 50;
        $offset = ($page - 1) * $limit;

        return array(
            'logs' => Digitalogic_Logger::instance()->get_logs(
                array(
                'limit'  => $limit,
                'offset' => $offset,
                )
            ),
        );
    }

    public function get_reports($payload) {
        return Digitalogic_Report_Engine::instance()->get_report( is_array( $payload ) ? $payload : array() );
    }

    public function update_patris_settings($payload) {
        return array(
            'settings'   => Digitalogic_Patris_Feed::instance()->update_settings( $payload ),
            'push_token' => Digitalogic_Patris_Feed::instance()->get_push_token(),
        );
    }

    public function get_integration_catalog($payload = array()) {
		return Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
    }

    public function get_default_percentage_markup($payload = array()) {
		return Digitalogic_Shipping_Method_Service::instance()->get_default_percentage_markup();
    }

    /**
     * Return the public shared-profit-margin contract.
     *
     * @param array $payload Request payload (unused).
     * @return array|WP_Error
     */
    public function get_profit_margin($payload = array()) {
        unset($payload);
        $stored = Digitalogic_Shipping_Method_Service::instance()->get_default_percentage_markup();
        if (is_wp_error($stored)) {
            return $stored;
        }

        return array(
            'configured'            => !empty($stored['configured']),
            'profit_margin_percent' => $stored['profit_percent'],
            'revision'              => $stored['revision'],
            'updated_at'            => $stored['updated_at'],
        );
    }

    /**
     * Update the one shared ecosystem profit margin.
     *
     * `profit_percent` is accepted only as the deprecated default-markup alias.
     *
     * @param mixed $payload Request payload.
     * @return array|WP_Error
     */
    public function update_profit_margin($payload) {
        if (!is_array($payload)) {
            $payload = array();
        }
        $has_primary = array_key_exists('profit_margin_percent', $payload);
        $has_legacy  = array_key_exists('profit_percent', $payload);
        if (!$has_primary && !$has_legacy) {
            return new WP_Error(
                'digitalogic_profit_margin_required',
                __('The profit_margin_percent field is required.', 'digitalogic'),
                array('status' => 400)
            );
        }
        if ($has_primary && $has_legacy && (string) $payload['profit_margin_percent'] !== (string) $payload['profit_percent']) {
            return new WP_Error(
                'digitalogic_profit_margin_alias_conflict',
                __('profit_margin_percent and deprecated profit_percent must be exactly equivalent.', 'digitalogic'),
                array('status' => 400)
            );
        }

        $result = Digitalogic_Pricing_Coordinator::instance()->update_profit_margin(
            $has_primary ? $payload['profit_margin_percent'] : $payload['profit_percent'],
            'command_dispatcher'
        );
        if (is_wp_error($result)) {
            return $result;
        }

        $state = $this->get_profit_margin();
        if (is_wp_error($state)) {
            return $state;
        }
        $state['changed'] = !empty($result['settings_changed']);
        if ($has_legacy && !$has_primary) {
            $state['deprecated_input'] = array(
                'profit_percent' => 'profit_margin_percent',
            );
        }

        return $state;
    }

    public function update_default_percentage_markup($payload) {
        if (!is_array( $payload ) || !array_key_exists( 'profit_percent', $payload )) {
            return new WP_Error(
                'digitalogic_shipping_default_markup_required',
                __( 'The profit_percent field is required; use null to clear the default explicitly.', 'digitalogic' ),
                array('status' => 400)
            );
        }

		return Digitalogic_Pricing_Coordinator::instance()->update_profit_margin(
            $payload['profit_percent'],
            'command_dispatcher'
        );
    }

	public function list_shipping_methods($payload = array()) {
        $include_disabled = !isset( $payload['include_disabled'] )
            || filter_var( $payload['include_disabled'], FILTER_VALIDATE_BOOLEAN );

		$methods = Digitalogic_Shipping_Method_Service::instance()->list_methods( $include_disabled );
        if (is_wp_error( $methods )) {
            return $methods;
        }

        return array(
			'methods' => array_map(
				array(Digitalogic_Shipping_Method_Service::instance(), 'present_method'),
				$methods
			),
        );
    }

	public function create_shipping_method($payload) {
        $data   = isset( $payload['method'] ) && is_array( $payload['method'] ) ? $payload['method'] : $payload;
		$result = Digitalogic_Shipping_Method_Service::instance()->create_method( $data );
		if (!is_wp_error( $result )) {
			$result = Digitalogic_Shipping_Method_Service::instance()->present_method( $result );
		}
		return $result;
    }

	public function get_shipping_method($payload) {
		$result = Digitalogic_Shipping_Method_Service::instance()->get_method( isset( $payload['id'] ) ? $payload['id'] : '' );
		return is_wp_error( $result )
			? $result
			: Digitalogic_Shipping_Method_Service::instance()->present_method( $result );
    }

	public function update_shipping_method($payload) {
        $id     = isset( $payload['id'] ) ? $payload['id'] : '';
        $data   = isset( $payload['method'] ) && is_array( $payload['method'] ) ? $payload['method'] : $payload;
		$result = Digitalogic_Shipping_Method_Service::instance()->update_method( $id, $data );
		if (!is_wp_error( $result )) {
			$result = Digitalogic_Shipping_Method_Service::instance()->present_method( $result );
		}
		return $result;
    }

	public function delete_shipping_method($payload) {
		return Digitalogic_Shipping_Method_Service::instance()->delete_method( isset( $payload['id'] ) ? $payload['id'] : '' );
    }

	public function get_product_shipping_method($payload) {
		return Digitalogic_Shipping_Method_Service::instance()->get_product_assignment_by_code(
            isset( $payload['code'] ) ? $payload['code'] : ''
        );
    }

	/**
	 * Read a bounded batch of product pricing assignments.
	 *
	 * @param array $payload Command payload.
	 * @return array|WP_Error
	 */
	public function get_pricing_assignments_batch( $payload ) {
		$codes = is_array( $payload ) && array_key_exists( 'codes', $payload )
			? $payload['codes']
			: array();

		return Digitalogic_Shipping_Method_Service::instance()->get_product_assignments_by_codes( $codes );
	}

	public function get_product_pricing( $payload ) {
		$code  = is_array($payload) && array_key_exists('code', $payload)
			? $payload['code']
			: '';
		$batch = Digitalogic_Shipping_Method_Service::instance()->get_product_assignments_by_codes(array($code));
		if (is_wp_error($batch)) {
			return $batch;
		}

		$row = $batch['results'][0];
		if ('ok' === $row['status']) {
			return $row['assignment'];
		}

		return new WP_Error(
			$row['error']['code'],
			__('The product pricing assignment could not be resolved.', 'digitalogic'),
			array('status' => $row['error']['http_status'])
		);
	}

	public function assign_product_shipping_method($payload) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'digitalogic_shipping_assignment_forbidden',
				__(
					'You are not allowed to change supplier shipping assignments.',
					'digitalogic'
				),
				array( 'status' => 403 )
			);
		}
		if (array_key_exists( 'shipping_method_id', $payload )) {
			$method_id = $payload['shipping_method_id'];
        } else {
            return new WP_Error(
				'digitalogic_shipping_method_required',
				__( 'The shipping_method_id field is required; use null or an empty value to clear it explicitly.', 'digitalogic' ),
                array('status' => 400)
            );
        }

		return Digitalogic_Shipping_Method_Service::instance()->assign_product_by_code(
            isset( $payload['code'] ) ? $payload['code'] : '',
            $method_id
        );
    }

	public function batch_assign_product_shipping_methods($payload) {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'digitalogic_shipping_assignment_forbidden',
				__(
					'You are not allowed to change supplier shipping assignments.',
					'digitalogic'
				),
				array( 'status' => 403 )
			);
		}
        $assignments = isset( $payload['assignments'] ) && is_array( $payload['assignments'] )
            ? $payload['assignments']
            : array();

		return Digitalogic_Shipping_Method_Service::instance()->batch_assign_products( $assignments );
	}

    /**
     * Execute a normal wp_ajax_{action} callback through a non-HTTP transport.
     *
     * This supports other plugins that already expose admin-ajax commands. It
     * cannot protect handlers that call raw exit/die instead of wp_die(), but it
     * handles the common wp_send_json()/wp_die() path used by WordPress AJAX.
     */
    public function execute_wp_ajax_action($command, $payload, $transport = 'websocket') {
        $command = self::normalize_command_name( $command );
        if (!$command || !has_action( 'wp_ajax_' . $command )) {
            return new WP_Error( 'digitalogic_unknown_command', __( 'Unknown command.', 'digitalogic' ), array('status' => 404) );
        }

        $allowed = apply_filters( 'digitalogic_websocket_ajax_action_allowed', true, $command, $payload, $transport );
        if (!$allowed) {
            return new WP_Error( 'digitalogic_ajax_action_blocked', __( 'This AJAX action is not available over WebSocket.', 'digitalogic' ), array('status' => 403) );
        }

        if (!defined( 'DOING_AJAX' )) {
            define( 'DOING_AJAX', true );
        }

        $old_post    = $_POST;
        $old_get     = $_GET;
        $old_request = $_REQUEST;
        $old_files   = $_FILES;
        $_FILES      = array();

        $request           = $this->normalize_ajax_payload( $payload );
        $request['action'] = $command;
        $_POST             = $request;
        $_REQUEST          = array_merge( $_GET, $_POST );

        $die_handler = function($message, $title = '', $args = array()) {
            throw new Digitalogic_Ajax_Die_Exception( $message, $title, $args );
        };

        $ajax_die_filter = function() use ($die_handler) {
            return $die_handler;
        };
        $die_filter      = function() use ($die_handler) {
            return $die_handler;
        };

        add_filter( 'wp_die_ajax_handler', $ajax_die_filter );
        add_filter( 'wp_die_handler', $die_filter );

        ob_start();
        try {
            do_action( 'wp_ajax_' . $command );
        } catch (Digitalogic_Ajax_Die_Exception $e) {
            $output = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', $ajax_die_filter );
            remove_filter( 'wp_die_handler', $die_filter );
            $this->restore_request_globals( $old_post, $old_get, $old_request, $old_files );

            if ($output !== '') {
                return $this->parse_ajax_output( $output );
            }

            $args   = $e->get_args();
            $status = isset( $args['response'] ) && $args['response'] ? (int) $args['response'] : 500;

            return new WP_Error( 'digitalogic_ajax_die', $e->getMessage(), array('status' => $status) );
        } catch (Throwable $e) {
            $output = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', $ajax_die_filter );
            remove_filter( 'wp_die_handler', $die_filter );
            $this->restore_request_globals( $old_post, $old_get, $old_request, $old_files );

            return new WP_Error(
                'digitalogic_ajax_exception',
                $e->getMessage(),
                array(
                'status' => 500,
                'output' => $output,
                )
            );
        }

        $output = ob_get_clean();
        remove_filter( 'wp_die_ajax_handler', $ajax_die_filter );
        remove_filter( 'wp_die_handler', $die_filter );
        $this->restore_request_globals( $old_post, $old_get, $old_request, $old_files );

        return $this->parse_ajax_output( $output );
    }

    private function normalize_ajax_payload($payload) {
        $normalized = array();

        foreach ($payload as $key => $value) {
            $key = sanitize_key( $key );
            if ($key === '') {
                continue;
            }

            $normalized[$key] = $this->recursive_unslash( $value );
        }

        return $normalized;
    }

    private function recursive_unslash($value) {
        if (is_array( $value )) {
            return array_map( array($this, 'recursive_unslash'), $value );
        }

        return is_string( $value ) ? wp_unslash( $value ) : $value;
    }

    private function parse_ajax_output($output) {
        $output = trim( (string) $output );
        if ($output === '') {
            return null;
        }

        $decoded = json_decode( $output, true );
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $output;
    }

    private function restore_request_globals($post, $get, $request, $files) {
        $_POST    = $post;
        $_GET     = $get;
        $_REQUEST = $request;
        $_FILES   = $files;
    }

    public static function normalize_command_name($command) {
        $command = sanitize_text_field( wp_unslash( (string) $command ) );

        return preg_replace( '/[^a-zA-Z0-9_\.\/-]/', '', $command );
    }

    private function sanitize_product_data($data) {
        $allowed = array(
            'name',
            'sku',
            'status',
            'regular_price',
            'sale_price',
            'stock_quantity',
            'stock_status',
            'manage_stock',
            'weight',
            'length',
            'width',
            'height',
            'category_ids',
            'patris_foreign_currency',
            'patris_foreign_price',
            'patris_weight_grams',
            'patris_total_stock',
            'patris_minimum_stock',
            'patris_location',
            'patris_final_price',
        );

        $sanitized = array();
        foreach ($allowed as $key) {
            if (!array_key_exists( $key, $data )) {
                continue;
            }

            $value = is_string( $data[$key] ) ? wp_unslash( $data[$key] ) : $data[$key];
            if (in_array( $key, array('name', 'sku'), true )) {
                $sanitized[$key] = sanitize_text_field( $value );
            } elseif ($key === 'category_ids') {
                $sanitized[$key] = is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
            } elseif ($key === 'status') {
                $status          = sanitize_key( $value );
                $sanitized[$key] = in_array( $status, array('publish', 'draft', 'pending', 'private'), true ) ? $status : 'draft';
            } elseif ($key === 'stock_status') {
                $stock_status    = sanitize_key( $value );
                $sanitized[$key] = in_array( $stock_status, array('instock', 'outofstock', 'onbackorder'), true ) ? $stock_status : 'instock';
            } elseif ($key === 'manage_stock') {
                $sanitized[$key] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
            } else {
                $numeric_value   = is_string( $value ) ? str_replace( array(',', '٬', '،', ' '), '', $value ) : $value;
                $sanitized[$key] = is_numeric( $numeric_value ) ? $numeric_value : sanitize_text_field( (string) $value );
            }
        }

        return $sanitized;
    }
}
