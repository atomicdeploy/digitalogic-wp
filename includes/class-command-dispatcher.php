<?php
/**
 * Shared command dispatcher for AJAX, REST, and WebSocket transports.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Ajax_Die_Exception extends Exception {
    private $title;
    private $args;

    public function __construct($message = '', $title = '', $args = array()) {
        parent::__construct((string) $message);
        $this->title = $title;
        $this->args = is_array($args) ? $args : array();
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
        if (is_null(self::$instance)) {
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
        $command = self::normalize_command_name($command);

        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error('digitalogic_unauthorized', __('Unauthorized', 'digitalogic'), array('status' => 403));
        }

        if (!is_array($payload)) {
            return new WP_Error('digitalogic_invalid_payload', __('Payload must be an object.', 'digitalogic'), array('status' => 400));
        }

        $commands = apply_filters('digitalogic_command_handlers', $this->default_handlers(), $transport);

        if (!isset($commands[$command]) || !is_callable($commands[$command])) {
            if (in_array($transport, array('websocket', 'laravel'), true)) {
                return $this->execute_wp_ajax_action($command, $payload, $transport);
            }

            return new WP_Error('digitalogic_unknown_command', __('Unknown command.', 'digitalogic'), array('status' => 404));
        }

        return call_user_func($commands[$command], $payload, $transport);
    }

    private function default_handlers() {
        return array(
            'digitalogic_get_products' => array($this, 'get_products'),
            'digitalogic_get_product' => array($this, 'get_product'),
            'digitalogic_update_product' => array($this, 'update_product'),
            'digitalogic_bulk_update' => array($this, 'bulk_update'),
            'digitalogic_update_currency' => array($this, 'update_currency'),
            'digitalogic_get_currency' => array($this, 'get_currency'),
            'digitalogic_export' => array($this, 'export'),
            'digitalogic_get_logs' => array($this, 'get_logs'),
        );
    }

    public function get_products($payload) {
        $page = isset($payload['page']) ? max(1, intval($payload['page'])) : 1;
        $limit = isset($payload['limit']) ? max(1, min(1000, intval($payload['limit']))) : 50;
        $search = isset($payload['search']) ? sanitize_text_field(wp_unslash($payload['search'])) : '';
        $sku = isset($payload['sku']) ? sanitize_text_field(wp_unslash($payload['sku'])) : '';

        $manager = Digitalogic_Product_Manager::instance();
        $args = array(
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
            'sku' => $sku,
        );
        $total = $manager->get_product_count();
        $filtered = ($search || $sku) ? $manager->get_product_count($args) : $total;

        return array(
            'products' => $manager->get_products($args),
            'total' => $total,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
        );
    }

    public function get_product($payload) {
        $product_id = isset($payload['product_id']) ? intval($payload['product_id']) : 0;
        if (!$product_id && isset($payload['id'])) {
            $product_id = intval($payload['id']);
        }

        if ($product_id <= 0) {
            return new WP_Error('digitalogic_invalid_product', __('Product ID is required.', 'digitalogic'), array('status' => 400));
        }

        $product = Digitalogic_Product_Manager::instance()->get_product($product_id);
        if (!$product) {
            return new WP_Error('digitalogic_product_not_found', __('Product not found.', 'digitalogic'), array('status' => 404));
        }

        return array('product' => $product);
    }

    public function update_product($payload) {
        $product_id = isset($payload['product_id']) ? intval($payload['product_id']) : 0;
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

        if ($product_id <= 0 || empty($data)) {
            return new WP_Error('digitalogic_invalid_product_update', __('Product ID and data are required.', 'digitalogic'), array('status' => 400));
        }

        $result = Digitalogic_Product_Manager::instance()->update_product($product_id, $this->sanitize_product_data($data));
        if (is_wp_error($result)) {
            return $result;
        }

        return __('Product updated', 'digitalogic');
    }

    public function bulk_update($payload) {
        $updates = isset($payload['updates']) && is_array($payload['updates']) ? $payload['updates'] : array();
        if (empty($updates)) {
            return new WP_Error('digitalogic_empty_bulk_update', __('No updates provided.', 'digitalogic'), array('status' => 400));
        }

        $sanitized = array();
        foreach ($updates as $product_id => $data) {
            $product_id = intval($product_id);
            if ($product_id > 0 && is_array($data)) {
                $sanitized[$product_id] = $this->sanitize_product_data($data);
            }
        }

        return Digitalogic_Product_Manager::instance()->bulk_update($sanitized);
    }

    public function update_currency($payload) {
        $options = Digitalogic_Options::instance();

        if (isset($payload['dollar_price'])) {
            $options->set_dollar_price(floatval($payload['dollar_price']));
        }

        if (isset($payload['yuan_price'])) {
            $options->set_yuan_price(floatval($payload['yuan_price']));
        }

        return __('Currency updated', 'digitalogic');
    }

    public function get_currency() {
        $options = Digitalogic_Options::instance();

        return array(
            'dollar_price' => $options->get_dollar_price(),
            'yuan_price' => $options->get_yuan_price(),
            'update_date' => $options->get_update_date(),
        );
    }

    public function export($payload) {
        $format = isset($payload['format']) ? sanitize_key($payload['format']) : 'csv';
        $product_ids = isset($payload['product_ids']) && is_array($payload['product_ids'])
            ? array_map('intval', $payload['product_ids'])
            : array();

        $import_export = Digitalogic_Import_Export::instance();
        if ($format === 'json') {
            $filepath = $import_export->export_json($product_ids);
        } elseif ($format === 'excel') {
            $filepath = $import_export->export_excel($product_ids);
        } else {
            $format = 'csv';
            $filepath = $import_export->export_csv($product_ids);
        }

        if (is_wp_error($filepath)) {
            return $filepath;
        }

        $upload_dir = wp_upload_dir();

        return array(
            'url' => str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $filepath),
            'filepath' => $filepath,
            'format' => $format,
        );
    }

    public function get_logs($payload) {
        $page = isset($payload['page']) ? max(1, intval($payload['page'])) : 1;
        $limit = isset($payload['limit']) ? max(1, min(200, intval($payload['limit']))) : 50;
        $offset = ($page - 1) * $limit;

        return array(
            'logs' => Digitalogic_Logger::instance()->get_logs(array(
                'limit' => $limit,
                'offset' => $offset,
            )),
        );
    }

    /**
     * Execute a normal wp_ajax_{action} callback through a non-HTTP transport.
     *
     * This supports other plugins that already expose admin-ajax commands. It
     * cannot protect handlers that call raw exit/die instead of wp_die(), but it
     * handles the common wp_send_json()/wp_die() path used by WordPress AJAX.
     */
    public function execute_wp_ajax_action($command, $payload, $transport = 'websocket') {
        $command = self::normalize_command_name($command);
        if (!$command || !has_action('wp_ajax_' . $command)) {
            return new WP_Error('digitalogic_unknown_command', __('Unknown command.', 'digitalogic'), array('status' => 404));
        }

        $allowed = apply_filters('digitalogic_websocket_ajax_action_allowed', true, $command, $payload, $transport);
        if (!$allowed) {
            return new WP_Error('digitalogic_ajax_action_blocked', __('This AJAX action is not available over WebSocket.', 'digitalogic'), array('status' => 403));
        }

        if (!defined('DOING_AJAX')) {
            define('DOING_AJAX', true);
        }

        $old_post = $_POST;
        $old_get = $_GET;
        $old_request = $_REQUEST;
        $old_files = $_FILES;
        $_FILES = array();

        $request = $this->normalize_ajax_payload($payload);
        $request['action'] = $command;
        $_POST = $request;
        $_REQUEST = array_merge($_GET, $_POST);

        $die_handler = function($message, $title = '', $args = array()) {
            throw new Digitalogic_Ajax_Die_Exception($message, $title, $args);
        };

        $ajax_die_filter = function() use ($die_handler) {
            return $die_handler;
        };
        $die_filter = function() use ($die_handler) {
            return $die_handler;
        };

        add_filter('wp_die_ajax_handler', $ajax_die_filter);
        add_filter('wp_die_handler', $die_filter);

        ob_start();
        try {
            do_action('wp_ajax_' . $command);
        } catch (Digitalogic_Ajax_Die_Exception $e) {
            $output = ob_get_clean();
            remove_filter('wp_die_ajax_handler', $ajax_die_filter);
            remove_filter('wp_die_handler', $die_filter);
            $this->restore_request_globals($old_post, $old_get, $old_request, $old_files);

            if ($output !== '') {
                return $this->parse_ajax_output($output);
            }

            $args = $e->get_args();
            $status = isset($args['response']) && $args['response'] ? (int) $args['response'] : 500;

            return new WP_Error('digitalogic_ajax_die', $e->getMessage(), array('status' => $status));
        } catch (Throwable $e) {
            $output = ob_get_clean();
            remove_filter('wp_die_ajax_handler', $ajax_die_filter);
            remove_filter('wp_die_handler', $die_filter);
            $this->restore_request_globals($old_post, $old_get, $old_request, $old_files);

            return new WP_Error('digitalogic_ajax_exception', $e->getMessage(), array(
                'status' => 500,
                'output' => $output,
            ));
        }

        $output = ob_get_clean();
        remove_filter('wp_die_ajax_handler', $ajax_die_filter);
        remove_filter('wp_die_handler', $die_filter);
        $this->restore_request_globals($old_post, $old_get, $old_request, $old_files);

        return $this->parse_ajax_output($output);
    }

    private function normalize_ajax_payload($payload) {
        $normalized = array();

        foreach ($payload as $key => $value) {
            $key = sanitize_key($key);
            if ($key === '') {
                continue;
            }

            $normalized[$key] = $this->recursive_unslash($value);
        }

        return $normalized;
    }

    private function recursive_unslash($value) {
        if (is_array($value)) {
            return array_map(array($this, 'recursive_unslash'), $value);
        }

        return is_string($value) ? wp_unslash($value) : $value;
    }

    private function parse_ajax_output($output) {
        $output = trim((string) $output);
        if ($output === '') {
            return null;
        }

        $decoded = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $output;
    }

    private function restore_request_globals($post, $get, $request, $files) {
        $_POST = $post;
        $_GET = $get;
        $_REQUEST = $request;
        $_FILES = $files;
    }

    public static function normalize_command_name($command) {
        $command = sanitize_text_field(wp_unslash((string) $command));

        return preg_replace('/[^a-zA-Z0-9_\.\/-]/', '', $command);
    }

    private function sanitize_product_data($data) {
        $allowed = array(
            'name',
            'sku',
            'regular_price',
            'sale_price',
            'stock_quantity',
            'stock_status',
            'manage_stock',
            'weight',
            'length',
            'width',
            'height',
        );

        $sanitized = array();
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = is_string($data[$key]) ? wp_unslash($data[$key]) : $data[$key];
            if (in_array($key, array('name', 'sku', 'stock_status'), true)) {
                $sanitized[$key] = sanitize_text_field($value);
            } elseif ($key === 'manage_stock') {
                $sanitized[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } else {
                $numeric_value = is_string($value) ? str_replace(array(',', '٬', '،', ' '), '', $value) : $value;
                $sanitized[$key] = is_numeric($numeric_value) ? $numeric_value : sanitize_text_field((string) $value);
            }
        }

        return $sanitized;
    }
}
