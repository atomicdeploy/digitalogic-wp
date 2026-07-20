<?php
/**
 * Webhooks Class
 *
 * Handles outbound webhook notifications for operational, auth, product,
 * order, and pricing events.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Webhooks {

    // phpcs:disable -- Preserve the established webhook formatting while the legacy file remains baseline-managed.
    private const PRODUCT_SYNC_EVENT = 'patris.product_sync.applied';
    private const PRODUCT_SYNC_SCHEMA = 'digitalogic.product-sync';
    private const MAX_PRODUCT_SYNC_COUNT = 10000;
    private const PRODUCT_SYNC_STATUSES = array(
        'accepted',
        'already_current',
        'partially_applied',
        'recovered',
        'retry_pending',
    );
    // phpcs:enable
    
    private static $instance = null;

    /**
     * Best-effort WooCommerce product field changes captured before save.
     *
     * @var array<int,array<int,array<string,mixed>>>
     */
    private $pending_product_changes = array();
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        self::$instance->register_import_freight_delivery_channel();
        return self::$instance;
    }
    
    private function __construct() {
        // Authentication and user events
        add_action('wp_login', array($this, 'login_succeeded'), 10, 2);
        add_action('wp_login_failed', array($this, 'login_failed'), 10, 1);
        add_action('user_register', array($this, 'user_registered'), 10, 1);
        add_action('profile_update', array($this, 'user_updated'), 10, 3);

        // Hook into product updates
        add_action('woocommerce_before_product_object_save', array($this, 'capture_product_changes'), 10, 1);
        add_action('woocommerce_update_product', array($this, 'product_updated'), 10, 1);
        add_action('woocommerce_new_product', array($this, 'product_created'), 10, 1);
        add_action('before_delete_post', array($this, 'product_deleted'), 10, 2);
        add_action('woocommerce_product_set_stock', array($this, 'product_stock_changed'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'product_stock_changed'), 10, 1);

        // Hook into order updates
        add_action('woocommerce_new_order', array($this, 'order_created'), 10, 2);
        add_action('woocommerce_update_order', array($this, 'order_updated'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed'), 10, 4);
        
        // Hook into currency updates
        add_action('update_option_dollar_price', array($this, 'currency_updated'), 10, 3);
        add_action('update_option_yuan_price', array($this, 'currency_updated'), 10, 3);
        add_action('digitalogic_woocommerce_currency_changed', array($this, 'woocommerce_currency_changed'), 10, 3);

        // Committed transformed Patris outcomes are optional observer events.
        add_action('digitalogic_product_sync_v1_applied', array($this, 'product_sync_applied'), 10, 2);

        // Add settings page
        add_action('admin_init', array($this, 'register_settings'));
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    private function register_import_freight_delivery_channel() {
        // Freight delivery uses an explicit result contract so failures from
        // one transport cannot abort panel/Redis or other webhook attempts.
        Digitalogic_Import_Freight_Service::instance()->register_delivery_channel(
            'webhook',
            array($this, 'deliver_import_freight_event')
        );
    }
    
    /**
     * Register webhook settings
     */
    public function register_settings() {
        register_setting('digitalogic_webhooks', 'digitalogic_webhook_urls', array(
            'sanitize_callback' => array($this, 'sanitize_webhook_urls'),
        ));
        register_setting('digitalogic_webhooks', 'digitalogic_webhook_secret', array(
            'sanitize_callback' => 'sanitize_text_field',
        ));
    }

    /**
     * Register operational webhook endpoints.
     */
    public function register_routes() {
        register_rest_route('digitalogic/v1', '/webhooks/test', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_test_webhook'),
            'permission_callback' => array($this, 'check_test_permission'),
        ));
    }

    /**
     * Sanitize webhook URL option from textarea or JSON/array input.
     */
    public function sanitize_webhook_urls($value) {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value)));
        }

        if (!is_array($value)) {
            return array();
        }

        $urls = array();
        foreach ($value as $url) {
            $url = esc_url_raw(trim((string) $url));
            if (!empty($url) && wp_http_validate_url($url)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Check permission for sending a test event.
     */
    public function check_test_permission(WP_REST_Request $request) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        $secret = get_option('digitalogic_webhook_secret', '');
        $provided = $request->get_header('x-digitalogic-secret');

        return !empty($secret) && !empty($provided) && hash_equals($secret, $provided);
    }

    /**
     * POST /webhooks/test
     */
    public function rest_test_webhook(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            $data = array();
        }

        $this->trigger_webhook('webhook.test', array(
            'message' => isset($data['message']) ? sanitize_text_field($data['message']) : 'Digitalogic webhook test',
            'requested_by' => get_current_user_id(),
        ), true);

        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Webhook test event queued',
        ), 200);
    }

    /**
     * Successful WordPress login webhook.
     */
    public function login_succeeded($user_login, $user) {
        $this->trigger_webhook('auth.login.succeeded', array(
            'user' => $this->format_user($user),
            'login' => $user_login,
            'request' => $this->request_context(),
        ));
    }

    /**
     * Failed WordPress login webhook and fail2ban syslog signal.
     */
    public function login_failed($username) {
        $ip = $this->client_ip();
        $safe_username = sanitize_user($username, true);

        if (!empty($ip)) {
            openlog('wordpress', LOG_PID, LOG_AUTH);
            syslog(LOG_NOTICE, sprintf('Authentication failure for %s from %s', $safe_username ?: 'unknown', $ip));
            closelog();
        }

        $this->trigger_webhook('auth.login.failed', array(
            'login' => $safe_username,
            'request' => $this->request_context(),
        ));
    }

    /**
     * New user webhook.
     */
    public function user_registered($user_id) {
        $this->trigger_webhook('user.created', array(
            'user' => $this->format_user(get_user_by('id', $user_id)),
            'request' => $this->request_context(),
        ));
    }

    /**
     * User profile update webhook.
     */
    public function user_updated($user_id, $old_user_data, $userdata) {
        $this->trigger_webhook('user.updated', array(
            'user' => $this->format_user(get_user_by('id', $user_id)),
            'old_roles' => is_object($old_user_data) ? $old_user_data->roles : array(),
            'request' => $this->request_context(),
        ));
    }
    
    // phpcs:disable -- Preserve the production hotfix formatting while the legacy file remains baseline-managed.
    /**
     * Capture changed WooCommerce product fields before save.
     *
     * @param WC_Product $product Product being saved.
     */
    public function capture_product_changes($product) {
        if (!is_object($product) || !method_exists($product, 'get_id') || !method_exists($product, 'get_changes')) {
            return;
        }

        $product_id = (int) $product->get_id();
        if ($product_id <= 0) {
            return;
        }

        $changes = $product->get_changes();
        if (empty($changes) || !is_array($changes)) {
            return;
        }

        $data = method_exists($product, 'get_data') ? $product->get_data() : array();
        $captured = array();
        foreach ($changes as $field => $new_value) {
            if (in_array((string) $field, array('date_modified', 'date_created'), true)) {
                continue;
            }
            $captured[] = array(
                'field' => (string) $field,
                'old' => $this->webhook_scalar_value(is_array($data) && array_key_exists($field, $data) ? $data[$field] : null),
                'new' => $this->webhook_scalar_value($new_value),
            );
        }

        if (!empty($captured)) {
            $this->pending_product_changes[$product_id] = $captured;
        }
    }
    // phpcs:enable

    /**
     * Product updated webhook
     */
    public function product_updated($product_id) {
        $manager = Digitalogic_Product_Manager::instance();
        $product = $manager->get_product($product_id);
        
        if ($product) {
            if (isset($this->pending_product_changes[(int) $product_id])) {
                $product['changed_fields'] = $this->pending_product_changes[(int) $product_id];
                unset($this->pending_product_changes[(int) $product_id]);
            }
            $this->trigger_webhook('product.updated', $product);
        }
    }
    
    /**
     * Product created webhook
     */
    public function product_created($product_id) {
        $manager = Digitalogic_Product_Manager::instance();
        $product = $manager->get_product($product_id);
        
        if ($product) {
            $this->trigger_webhook('product.created', $product);
        }
    }

    /**
     * Product deleted webhook.
     */
    public function product_deleted($post_id, $post) {
        if (!is_object($post) || !in_array($post->post_type, array('product', 'product_variation'), true)) {
            return;
        }

        $this->trigger_webhook('product.deleted', array(
            'id' => (int) $post_id,
            'type' => $post->post_type,
            'title' => get_the_title($post_id),
            'sku' => get_post_meta($post_id, '_sku', true),
            'request' => $this->request_context(),
        ));
    }

    /**
     * Product stock webhook.
     */
    public function product_stock_changed($product) {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $this->trigger_webhook('product.stock.changed', array(
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'request' => $this->request_context(),
        ));
    }

    /**
     * Order created webhook.
     */
    public function order_created($order_id, $order = null) {
        $this->trigger_webhook('order.created', $this->format_order($order ?: wc_get_order($order_id)));
    }

    /**
     * Order updated webhook.
     */
    public function order_updated($order_id) {
        $this->trigger_webhook('order.updated', $this->format_order(wc_get_order($order_id)));
    }

    /**
     * Order status changed webhook.
     */
    public function order_status_changed($order_id, $old_status, $new_status, $order) {
        $data = $this->format_order($order ?: wc_get_order($order_id));
        $data['old_status'] = $old_status;
        $data['new_status'] = $new_status;

        $this->trigger_webhook('order.status.changed', $data);
    }
    
    /**
     * Currency updated webhook
     */
    public function currency_updated($old_value, $value, $option) {
        $data = Digitalogic_Command_Dispatcher::instance()->get_currency();
        $data['changed_option'] = $option;
        
        $this->trigger_webhook('currency.updated', $data);
    }

    /**
     * Publish committed WooCommerce base-currency status through the existing event.
     *
     * @param string $old_currency Previous currency code.
     * @param string $new_currency New currency code.
     * @param array  $status Shared new-currency status.
     * @return void
     */
    public function woocommerce_currency_changed($old_currency, $new_currency, $status) {
        if (!is_array($status)) {
            $status = Digitalogic_WooCommerce_Currency_Status::instance()->describe($new_currency);
        }
        $data = Digitalogic_Command_Dispatcher::instance()->get_currency();
        $data['woocommerce_base'] = $status;
        $data['previous_woocommerce_base'] = Digitalogic_WooCommerce_Currency_Status::instance()->describe($old_currency);
        $data['changed_option'] = Digitalogic_WooCommerce_Currency_Status::OPTION_NAME;

        $this->trigger_webhook('currency.updated', $data);
    }

    // phpcs:disable -- Preserve the established webhook formatting while the legacy file remains baseline-managed.
    /**
     * Publish a committed product-sync outcome through the shared observer.
     *
     * The receiver owns delivery truth. This listener deliberately ignores
     * observer transport results so n8n remains optional and cannot change a
     * committed receiver response.
     */
    public function product_sync_applied($result, $envelope) {
        try {
            $summary = $this->product_sync_summary($result, $envelope);
            if (null === $summary) {
                return true;
            }

            $this->trigger_webhook(self::PRODUCT_SYNC_EVENT, $summary);
        } catch (Throwable) {
            error_log('[Digitalogic webhooks] Product-sync observer delivery was unavailable.');
        }

        return true;
    }

    /**
     * Project the receiver result onto the bounded public observer contract.
     */
    private function product_sync_summary($result, $envelope) {
        if (!is_array($result) || !is_array($envelope)) {
            return null;
        }
        if (self::PRODUCT_SYNC_SCHEMA !== ($envelope['schema'] ?? null)) {
            return null;
        }
        $schema_version = $envelope['schema_version'] ?? null;
        if (!is_string($schema_version) || !preg_match('/^1(?:\.[0-9]+){1,2}$/', $schema_version)) {
            return null;
        }
        $event_id = $envelope['event_id'] ?? null;
        if (!is_string($event_id) || !preg_match('/^sha256:[a-f0-9]{64}$/', $event_id)) {
            return null;
        }
        $event_type = $envelope['event_type'] ?? null;
        if (!in_array($event_type, array('snapshot', 'update'), true)) {
            return null;
        }
        $status = $result['status'] ?? null;
        if (!in_array($status, self::PRODUCT_SYNC_STATUSES, true)) {
            return null;
        }
        $source = is_array($envelope['source'] ?? null) ? $envelope['source'] : array();
        $source_id = $this->safe_product_sync_source($source['id'] ?? null);
        $dataset = $this->safe_product_sync_source($source['dataset'] ?? null);
        if (null === $source_id || null === $dataset) {
            return null;
        }

        $pending = $this->bounded_product_sync_count($result['pending_products'] ?? null);
        $deferred = $this->bounded_product_sync_count($result['deferred_products'] ?? null);
        $retryable = true === ($result['retryable'] ?? false);
        $partial = in_array($status, array('partially_applied', 'retry_pending'), true);
        if (($partial && (!$retryable || 0 === $pending)) || (!$partial && ($retryable || 0 !== $pending))) {
            return null;
        }

        $woocommerce = is_array($result['woocommerce'] ?? null) ? $result['woocommerce'] : array();
        $woo_summary = array();
        foreach (array('attempted', 'updated', 'already_applied', 'missing', 'ambiguous', 'failed', 'errors_truncated') as $field) {
            $woo_summary[$field] = $this->bounded_product_sync_count($woocommerce[$field] ?? null);
        }

        return array(
            'schema' => self::PRODUCT_SYNC_SCHEMA,
            'schema_version' => $schema_version,
            'event_id' => $event_id,
            'event_type' => $event_type,
            'source' => array(
                'id' => $source_id,
                'dataset' => $dataset,
            ),
            'status' => $status,
            'retryable' => $retryable,
            'pending_products' => $pending,
            'deferred_products' => $deferred,
            'woocommerce' => $woo_summary,
        );
    }

    private function safe_product_sync_source($value) {
        if (
            !is_string($value)
            || 1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $value)
        ) {
            return null;
        }

        return $value;
    }

    private function bounded_product_sync_count($value) {
        if (!is_int($value) || $value < 0) {
            return 0;
        }

        return min($value, self::MAX_PRODUCT_SYNC_COUNT);
    }
    // phpcs:enable

    public function import_freight_method_created($method) {
        return $this->trigger_import_freight_method_webhook('import_freight.method.created', $method);
    }

    public function import_freight_method_updated($method) {
        return $this->trigger_import_freight_method_webhook('import_freight.method.updated', $method);
    }

    public function import_freight_method_deleted($method) {
        return $this->trigger_import_freight_method_webhook('import_freight.method.deleted', $method);
    }

    public function import_freight_assignment_updated($product_id, $method_id) {
        return $this->trigger_webhook('import_freight.assignment.updated', array(
            'product_id' => absint($product_id),
            'import_freight_method_id' => sanitize_key((string) $method_id),
        ));
    }

    private function trigger_import_freight_method_webhook($event, $method) {
        $method = is_array($method) ? $method : array();
        return $this->trigger_webhook($event, array(
            'id' => isset($method['id']) ? sanitize_key($method['id']) : '',
            'name' => isset($method['name']) ? sanitize_text_field($method['name']) : '',
            'enabled' => !empty($method['enabled']),
            'price_per_kg_cny' => isset($method['price_per_kg_cny']) ? (float) $method['price_per_kg_cny'] : null,
        ));
    }

    /**
     * Result-aware synchronous freight delivery used by the canonical service.
     */
    public function deliver_import_freight_event($hook, $args) {
        $args = is_array($args) ? $args : array();
        if ('digitalogic_import_freight_default_markup_updated' === $hook) {
            $markup = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
            return $this->trigger_webhook('import_freight.default_markup.updated', array(
                'configured' => !empty($markup['configured']),
                'profit_percent' => isset($markup['profit_percent']) ? (string) $markup['profit_percent'] : null,
                'source' => isset($markup['source']) ? sanitize_key($markup['source']) : '',
                'revision' => isset($markup['revision']) ? sanitize_text_field($markup['revision']) : '',
                'previous_revision' => isset($markup['previous_revision']) ? sanitize_text_field($markup['previous_revision']) : '',
                'updated_at' => isset($markup['updated_at']) ? sanitize_text_field($markup['updated_at']) : '',
                'updated_by' => isset($markup['updated_by']) ? absint($markup['updated_by']) : 0,
            ), true);
        }
        if ('digitalogic_product_import_freight_method_updated' === $hook) {
            return $this->trigger_webhook('import_freight.assignment.updated', array(
                'product_id' => absint(isset($args[0]) ? $args[0] : 0),
                'import_freight_method_id' => sanitize_key((string) (isset($args[1]) ? $args[1] : '')),
            ), true);
        }

        $events = array(
            'digitalogic_import_freight_method_created' => 'import_freight.method.created',
            'digitalogic_import_freight_method_updated' => 'import_freight.method.updated',
            'digitalogic_import_freight_method_deleted' => 'import_freight.method.deleted',
        );
        if (!isset($events[$hook])) {
            return new WP_Error(
                'digitalogic_webhook_delivery_event_unknown',
                __('The webhook transport does not recognize this import freight event.', 'digitalogic')
            );
        }

        $method = isset($args[0]) && is_array($args[0]) ? $args[0] : array();
        return $this->trigger_webhook($events[$hook], array(
            'id' => isset($method['id']) ? sanitize_key($method['id']) : '',
            'name' => isset($method['name']) ? sanitize_text_field($method['name']) : '',
            'enabled' => !empty($method['enabled']),
            'price_per_kg_cny' => isset($method['price_per_kg_cny']) ? (float) $method['price_per_kg_cny'] : null,
        ), true);
    }
    
    /**
     * Trigger webhook
     */
    private function trigger_webhook($event, $data, $blocking = false) {
        $webhook_urls = get_option('digitalogic_webhook_urls', array());
        
        if (empty($webhook_urls)) {
            return true;
        }
        
        // Ensure it's an array
        if (!is_array($webhook_urls)) {
            $webhook_urls = array_filter(array_map('trim', explode("\n", $webhook_urls)));
        }
        
        $secret = get_option('digitalogic_webhook_secret', '');
        
        $payload = array(
            'event' => $event,
            'event_id' => wp_generate_uuid4(),
            'timestamp' => time(),
            'site' => array(
                'name' => get_bloginfo('name'),
                'url' => home_url(),
            ),
            'data' => $data,
        );
        
        $payload_json = json_encode($payload);
        if (!is_string($payload_json)) {
            return new WP_Error(
                'digitalogic_webhook_payload_encoding_failed',
                __('The webhook payload could not be encoded.', 'digitalogic')
            );
        }
        $signature = hash_hmac('sha256', $payload_json, $secret);

        $failures = array();
        foreach (array_values($webhook_urls) as $index => $url) {
            if (empty($url)) {
                continue;
            }

            try {
                $response = wp_remote_post($url, array(
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'X-Digitalogic-Signature' => $signature,
                        'X-Digitalogic-Event' => $event
                    ),
                    'body' => $payload_json,
                    'timeout' => 10,
                    'blocking' => (bool) $blocking
                ));
            } catch (Throwable $exception) {
                $response = new WP_Error('digitalogic_webhook_transport_exception', $exception->getMessage());
            }
            
            $response_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if (is_wp_error($response) || ($blocking && ($response_code < 200 || $response_code >= 300))) {
                $error_message = is_wp_error($response)
                    ? $response->get_error_message()
                    : sprintf('HTTP %d', $response_code);
                $failures[] = array('index' => $index, 'reason' => $error_message);
                try {
                    Digitalogic_Logger::instance()->log(
                        'webhook_failed',
                        'webhook',
                        null,
                        null,
                        wp_json_encode(array('destination_index' => $index, 'error' => $error_message)),
                        'Webhook delivery failed'
                    );
                } catch (Throwable $exception) {
                    error_log('[Digitalogic webhooks] Failure logging was unavailable.');
                }
            }
        }

        if (!empty($failures)) {
            return new WP_Error(
                'digitalogic_webhook_delivery_failed',
                __('One or more webhook destinations rejected the import freight event.', 'digitalogic'),
                array('failures' => $failures)
            );
        }

        return true;
    }
    
    /**
     * Verify webhook signature
     * 
     * @param string $payload
     * @param string $signature
     * @return bool
     */
    public static function verify_signature($payload, $signature) {
        $secret = get_option('digitalogic_webhook_secret', '');
        $expected_signature = hash_hmac('sha256', $payload, $secret);
        
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Manually trigger a webhook (for testing)
     * 
     * @param string $event
     * @param array $data
     */
    public function manual_trigger($event, $data) {
        $this->trigger_webhook($event, $data);
    }

    /**
     * Format a WordPress user without secrets.
     */
    private function format_user($user) {
        if (!$user instanceof WP_User) {
            return null;
        }

        return array(
            'id' => (int) $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => $user->roles,
        );
    }

    /**
     * Format a WooCommerce order without sensitive payment data.
     */
    private function format_order($order) {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return array();
        }

        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $items[] = array(
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'sku' => $product ? $product->get_sku() : '',
                'total' => $item->get_total(),
            );
        }

        return array(
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_method' => $order->get_payment_method(),
            'billing_email' => $order->get_billing_email(),
            'billing_phone' => $order->get_billing_phone(),
            'created_at' => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'updated_at' => $order->get_date_modified() ? $order->get_date_modified()->date('c') : null,
            'items' => $items,
            'request' => $this->request_context(),
        );
    }

    // phpcs:disable -- Preserve the production hotfix formatting while the legacy file remains baseline-managed.
    /**
     * Normalize webhook change values into compact nonsecret scalars.
     *
     * @param mixed $value Raw value.
     * @return mixed
     */
    private function webhook_scalar_value($value) {
        if ($value instanceof WC_DateTime || $value instanceof DateTimeInterface) {
            return $value->date('c');
        }
        if (is_scalar($value) || null === $value) {
            return $value;
        }
        if (is_array($value)) {
            return wp_json_encode($value);
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return gettype($value);
    }
    // phpcs:enable

    /**
     * Current request metadata.
     */
    private function request_context() {
        return array(
            'ip' => $this->client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'uri' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '',
            'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
        );
    }

    /**
     * Best-effort client IP discovery.
     */
    private function client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        );

        foreach ($headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash($_SERVER[$header]));
            $parts = array_map('trim', explode(',', $value));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        return '';
    }
}
