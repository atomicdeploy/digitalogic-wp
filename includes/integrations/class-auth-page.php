<?php
/**
 * Auth route consolidation and Digits-backed registration guard.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Digitalogic_Plugin_Auth_Routes {
    private const AUTH_PAGE_ID = 10512;
    private const DEFAULT_COUNTRY_CODE = '98';

    public static function init(): void {
        static $booted = false;

        if ($booted || !Digitalogic_Plugin_Admin_Branding::is_enabled()) {
            return;
        }

        $booted = true;

        add_action('template_redirect', [self::class, 'redirect_legacy_login_page'], 1);
        add_action('login_form_register', [self::class, 'render_digits_register'], 1);
        add_filter('register_url', [self::class, 'register_url'], 9999);
        add_filter('lostpassword_url', [self::class, 'lostpassword_url'], 9999, 2);
        add_filter('login_url', [self::class, 'login_url'], 9999, 3);
        add_filter('authenticate', [self::class, 'authenticate_phone_user'], 30, 3);
    }

    public static function redirect_legacy_login_page(): void {
        if (!self::is_legacy_login_page()) {
            return;
        }

        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';
        wp_safe_redirect(self::login_url(wp_login_url($redirect_to), $redirect_to, false), 301);
        exit;
    }

    public static function render_digits_register(): void {
        $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';

        login_header(__('Register'), '', null);

        echo '<div class="dg-digits-register-shell">';

        if (function_exists('df_digits_form_signup')) {
            echo df_digits_form_signup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo '<div id="login_error" class="notice notice-error"><p>';
            esc_html_e('Digits registration is not available. Please check the Digits plugin configuration.', 'digitalogic');
            echo '</p></div>';
        }

        echo '</div>';
        echo '<p id="nav">';
        echo '<a href="' . esc_url(self::login_url(wp_login_url($redirect_to), $redirect_to, false)) . '">';
        esc_html_e('Log in', 'digitalogic');
        echo '</a>';
        echo '</p>';

        login_footer('user_login');
        exit;
    }

    public static function register_url(string $url): string {
        return add_query_arg('action', 'register', site_url('wp-login.php', 'login'));
    }

    public static function lostpassword_url(string $url, string $redirect = ''): string {
        $args = ['action' => 'lostpassword'];

        if ($redirect !== '') {
            $args['redirect_to'] = $redirect;
        }

        return add_query_arg($args, wp_login_url());
    }

    public static function login_url(string $login_url, string $redirect = '', bool $force_reauth = false): string {
        $args = [];

        if ($redirect !== '') {
            $args['redirect_to'] = $redirect;
        }

        if ($force_reauth) {
            $args['reauth'] = '1';
        }

        return add_query_arg($args, site_url('wp-login.php', 'login'));
    }

    public static function authenticate_phone_user($user, $username, $password) {
        if ($user instanceof WP_User) {
            return $user;
        }

        $username = is_string($username) ? trim($username) : '';
        $password = is_string($password) ? $password : '';

        if ($username === '' || $password === '') {
            return $user;
        }

        $phone = self::normalize_phone($username, self::DEFAULT_COUNTRY_CODE);
        if (!preg_match('/^9\d{9}$/', $phone)) {
            return $user;
        }

        $phone_user = self::find_user_by_phone($phone, self::DEFAULT_COUNTRY_CODE);
        if (!$phone_user instanceof WP_User) {
            return $user;
        }

        if (!wp_check_password($password, $phone_user->user_pass, $phone_user->ID)) {
            return new WP_Error(
                'incorrect_password',
                __('<strong>Error:</strong> The password you entered for the phone number is incorrect.')
            );
        }

        return $phone_user;
    }

    private static function is_legacy_login_page(): bool {
        if (is_admin() || wp_doing_ajax()) {
            return false;
        }

        if (is_singular('digits-forms-page')) {
            return true;
        }

        $queried_id = (int) get_queried_object_id();
        return $queried_id === self::AUTH_PAGE_ID || is_page('login');
    }

    private static function find_user_by_phone(string $phone, string $country_code): ?WP_User {
        $normalized = self::normalize_phone($phone, $country_code);
        if ($normalized === '') {
            return null;
        }

        $global = '+' . $country_code . $normalized;
        $local = '0' . $normalized;

        $users = get_users([
            'number' => 1,
            'fields' => 'all',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => 'digits_phone_no',
                    'value' => $normalized,
                ],
                [
                    'key' => 'digits_phone',
                    'value' => $global,
                ],
                [
                    'key' => 'billing_phone',
                    'value' => $local,
                ],
            ],
        ]);

        if (empty($users) || !$users[0] instanceof WP_User) {
            return null;
        }

        return $users[0];
    }

    private static function normalize_phone(string $phone, string $country_code): string {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, $country_code) && strlen($digits) > 10) {
            $digits = substr($digits, strlen($country_code));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }
}
