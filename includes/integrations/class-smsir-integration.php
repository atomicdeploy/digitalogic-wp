<?php
/**
 * Migrated Digitalogic SMS.ir Integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Digitalogic_Smsir_Integration')) {
    return;
}

final class Digitalogic_Smsir_Integration
{
    private const API_BASE = 'https://api.sms.ir/v1/';
    private const TOKEN_OPTION = 'digitalogic_smsir_webhook_token';

    public static function init(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('woocommerce_new_order', array(__CLASS__, 'maybe_send_admin_new_order'), 20, 1);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'maybe_send_order_status_sms'), 20, 4);
        add_action('admin_init', array(__CLASS__, 'ensure_token'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('digitalogic-sms', array(__CLASS__, 'cli'));
        }
    }

    public static function ensure_token(): string
    {
        $token = (string) get_option(self::TOKEN_OPTION, '');
        if ($token === '') {
            $token = wp_generate_password(48, false, false);
            add_option(self::TOKEN_OPTION, $token, '', 'no');
        }
        return $token;
    }

    public static function register_routes(): void
    {
        register_rest_route('digitalogic-sms/v1', '/status', array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'authorize_request'),
            'callback' => array(__CLASS__, 'rest_status'),
        ));

        register_rest_route('digitalogic-sms/v1', '/send', array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'authorize_request'),
            'callback' => array(__CLASS__, 'rest_send'),
            'args' => array(
                'to' => array('required' => true),
                'message' => array('required' => true),
            ),
        ));
    }

    public static function authorize_request(WP_REST_Request $request): bool
    {
        $expected = defined('DIGITALOGIC_SMS_WEBHOOK_TOKEN')
            ? (string) DIGITALOGIC_SMS_WEBHOOK_TOKEN
            : self::ensure_token();

        $provided = (string) $request->get_header('x-digitalogic-sms-token');
        if ($provided === '') {
            $provided = (string) $request->get_param('token');
        }

        return $expected !== '' && hash_equals($expected, $provided);
    }

    public static function rest_status(): WP_REST_Response
    {
        $credit = self::request('credit', 'GET');
        $lines = self::request('line', 'GET');

        return rest_ensure_response(array(
            'ok' => !is_wp_error($credit) && !is_wp_error($lines),
            'credit' => is_wp_error($credit) ? $credit->get_error_message() : $credit,
            'lines' => is_wp_error($lines) ? $lines->get_error_message() : $lines,
            'configured_line' => self::line_number(),
        ));
    }

    public static function rest_send(WP_REST_Request $request)
    {
        $to = $request->get_param('to');
        $message = trim((string) $request->get_param('message'));
        $line = $request->get_param('lineNumber') ?: self::line_number();

        if ($message === '') {
            return new WP_Error('digitalogic_sms_empty_message', 'Message is required.', array('status' => 400));
        }

        $mobiles = is_array($to) ? $to : preg_split('/[\s,]+/', (string) $to, -1, PREG_SPLIT_NO_EMPTY);
        $mobiles = array_values(array_filter(array_map(array(__CLASS__, 'normalize_mobile'), $mobiles)));

        if (empty($mobiles)) {
            return new WP_Error('digitalogic_sms_empty_to', 'At least one valid recipient is required.', array('status' => 400));
        }

        $result = self::send_bulk($mobiles, $message, $line);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array('ok' => true, 'result' => $result));
    }

    public static function cli(array $args, array $assoc_args): void
    {
        $action = $args[0] ?? 'status';

        if ($action === 'token') {
            WP_CLI::line(self::ensure_token());
            return;
        }

        if ($action === 'status') {
            $credit = self::request('credit', 'GET');
            $lines = self::request('line', 'GET');
            WP_CLI::line('configured_line=' . self::line_number());
            WP_CLI::line('credit=' . (is_wp_error($credit) ? $credit->get_error_message() : wp_json_encode($credit, JSON_UNESCAPED_UNICODE)));
            WP_CLI::line('lines=' . (is_wp_error($lines) ? $lines->get_error_message() : wp_json_encode($lines, JSON_UNESCAPED_UNICODE)));
            return;
        }

        if ($action === 'send') {
            $to = (string) ($assoc_args['to'] ?? '');
            $message = (string) ($assoc_args['message'] ?? '');
            $line = isset($assoc_args['line']) ? (string) $assoc_args['line'] : null;
            $mobiles = array_values(array_filter(array_map(array(__CLASS__, 'normalize_mobile'), preg_split('/[\s,]+/', $to, -1, PREG_SPLIT_NO_EMPTY))));

            if (empty($mobiles) || trim($message) === '') {
                WP_CLI::error('Usage: wp digitalogic-sms send --to=09120000000[,09120000001] --message="Text" [--line=3000860843]');
            }

            $result = self::send_bulk($mobiles, $message, $line);
            if (is_wp_error($result)) {
                WP_CLI::error($result);
            }

            WP_CLI::success(wp_json_encode($result, JSON_UNESCAPED_UNICODE));
            return;
        }

        WP_CLI::error('Unknown action. Use: status, token, send.');
    }

    public static function maybe_send_admin_new_order($order_id): void
    {
        if (get_option('woocommerce_sms_admin_enable', 'no') !== 'yes') {
            return;
        }

        $phones = preg_split('/[\s,]+/', (string) get_option('woocommerce_sms_admin_phone_numbers', ''), -1, PREG_SPLIT_NO_EMPTY);
        $phones = array_values(array_filter(array_map(array(__CLASS__, 'normalize_mobile'), $phones)));
        if (empty($phones)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $message = self::render_order_message((string) get_option('woocommerce_sms_admin_free_message', ''), $order);
        self::send_bulk($phones, $message);
    }

    public static function maybe_send_order_status_sms($order_id, $old_status, $new_status, $order): void
    {
        if (get_option('woocommerce_sms_status_' . $new_status . '_enable', 'no') !== 'yes') {
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }

        $phone_meta = (string) get_option('woocommerce_sms_customer_phone_meta_key', 'billing_phone');
        $phone = $phone_meta === 'billing_phone' ? $order->get_billing_phone() : (string) $order->get_meta($phone_meta);
        $phone = self::normalize_mobile($phone);
        if ($phone === '') {
            return;
        }

        $template = (string) get_option('woocommerce_sms_status_' . $new_status . '_free_message', '');
        $message = self::render_order_message($template, $order);
        if ($message === '') {
            return;
        }

        self::send_bulk(array($phone), $message);
    }

    private static function settings(): array
    {
        $settings = get_option('digit_smsir2');
        return is_array($settings) ? $settings : array();
    }

    private static function api_key(): string
    {
        $settings = self::settings();
        return (string) ($settings['apiKey'] ?? '');
    }

    private static function line_number(): string
    {
        $settings = self::settings();
        return (string) ($settings['linenumber'] ?? '');
    }

    private static function send_bulk(array $mobiles, string $message, ?string $line = null)
    {
        return self::request('send/bulk', 'POST', array(
            'lineNumber' => $line ?: self::line_number(),
            'MessageText' => $message,
            'Mobiles' => $mobiles,
            'SendDateTime' => null,
        ));
    }

    private static function request(string $endpoint, string $method = 'GET', ?array $body = null)
    {
        $args = array(
            'method' => $method,
            'timeout' => 20,
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-KEY' => self::api_key(),
            ),
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        $response = wp_remote_request(self::API_BASE . ltrim($endpoint, '/'), $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($data) || (isset($data['status']) && (int) $data['status'] !== 1)) {
            return new WP_Error('digitalogic_smsir_api_error', 'SMS.ir API request failed.', array(
                'status' => $code ?: 502,
                'response' => $data,
            ));
        }

        return $data;
    }

    private static function normalize_mobile($mobile): string
    {
        $mobile = preg_replace('/[^\d+]/', '', (string) $mobile);
        if (strpos($mobile, '+98') === 0) {
            return '0' . substr($mobile, 3);
        }
        if (strpos($mobile, '98') === 0 && strlen($mobile) === 12) {
            return '0' . substr($mobile, 2);
        }
        return $mobile;
    }

    private static function render_order_message(string $template, WC_Order $order): string
    {
        $replacements = array(
            '{site_name}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            '{order_id}' => (string) $order->get_id(),
            '{customer_name}' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            '{customer_phone}' => $order->get_billing_phone(),
            '{total}' => wp_strip_all_tags($order->get_formatted_order_total()),
            '{payment_method}' => $order->get_payment_method_title(),
            '{order_link}' => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
        );

        return trim(strtr($template, $replacements));
    }
}

Digitalogic_Smsir_Integration::init();
