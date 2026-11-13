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
    private const CANONICAL_LOGIN_PATH = '/login/';

	private const ACCOUNT_HINT_COOKIE = 'digitalogic_account_hint';

    public static function init(): void {
        static $booted = false;

        if ($booted || !Digitalogic_Plugin_Admin_Branding::is_enabled()) {
            return;
        }

        $booted = true;

        add_action('template_redirect', [self::class, 'redirect_legacy_login_page'], 1);
        add_action('login_init', [self::class, 'redirect_wp_login_to_canonical'], 0);
        add_action('login_form_login', [self::class, 'render_digits_login'], 1);
        add_action('login_form_register', [self::class, 'render_digits_register'], 1);
        add_action('wp_login_failed', [self::class, 'redirect_failed_login'], 99);
        add_filter('register_url', [self::class, 'register_url'], 9999);
        add_filter('lostpassword_url', [self::class, 'lostpassword_url'], 9999, 2);
        add_filter('login_url', [self::class, 'login_url'], 9999, 3);
        add_filter('authenticate', [self::class, 'authenticate_phone_user'], 30, 3);
        add_filter('wordfence_ls_require_captcha', [self::class, 'bypass_wordfence_captcha_for_digits'], 20);
		add_action( 'set_logged_in_cookie', array( self::class, 'remember_account' ), 10, 4 );
    }

	/**
	 * Remember an identity only when the browser opts into a persistent login.
	 *
	 * @param string $cookie     WordPress login cookie (not stored here).
	 * @param int    $expire     Browser cookie expiration.
	 * @param int    $expiration WordPress authentication expiration.
	 * @param int    $user_id    Authenticated user ID.
	 */
	public static function remember_account( string $cookie, int $expire, int $expiration, int $user_id ): void {
		if ( headers_sent() || ! is_ssl() || ! apply_filters( 'send_auth_cookies', true ) ) {
			return;
		}
		$expires = $expire > 0 ? min( $expiration, time() + 30 * DAY_IN_SECONDS ) : 0;
		$value   = $user_id . '.' . $expires;
		setcookie(
			self::ACCOUNT_HINT_COOKIE,
			$expires > time() ? $value . '.' . hash_hmac( 'sha256', $value, wp_salt( 'logged_in' ) ) : '',
			array(
				'expires'  => $expires > 0 ? $expires : time() - HOUR_IN_SECONDS,
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	/** A signed, browser-local account hint; it cannot create a WordPress session. */
	public static function remembered_account(): array {
		if ( 'login' !== ( $GLOBALS['action'] ?? '' ) ) {
			return array();
		}
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( str_starts_with( $address, '172.16.' ) && filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// Only trust REMOTE_ADDR after the web server's trusted proxy handling.
			$assignments = defined( 'DIGITALOGIC_LAN_LOGIN_ACCOUNTS' ) && is_array( DIGITALOGIC_LAN_LOGIN_ACCOUNTS ) ? DIGITALOGIC_LAN_LOGIN_ACCOUNTS : array();
			$login       = $assignments[ $address ] ?? '';
			if ( is_string( $login ) && '' !== $login ) {
				$assigned = get_user_by( 'login', $login );
				if ( $assigned instanceof WP_User ) {
					return self::account_hint( $assigned, true );
				}
			}
		}
		$hint = isset( $_COOKIE[ self::ACCOUNT_HINT_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::ACCOUNT_HINT_COOKIE ] ) ) : '';
		if ( ! preg_match( '/\A([1-9][0-9]{0,11})\.([0-9]{1,12})\.([a-f0-9]{64})\z/', $hint, $matches )
			|| (int) $matches[2] <= time()
			|| ! hash_equals( hash_hmac( 'sha256', $matches[1] . '.' . $matches[2], wp_salt( 'logged_in' ) ), $matches[3] ) ) {
			return array();
		}
		$user = get_user_by( 'id', (int) $matches[1] );
		if ( ! $user instanceof WP_User ) {
			return array();
		}
		return self::account_hint( $user, false );
	}

	/**
	 * Serialize only the account chosen for this browser or assigned workstation.
	 *
	 * @param WP_User $user     Account to show.
	 * @param bool    $assigned Whether to preselect the account.
	 */
	private static function account_hint( WP_User $user, bool $assigned ): array {
		$avatar = get_avatar_url( $user->ID, array( 'size' => 80 ) );
		return array(
			'login'    => $user->user_login,
			'name'     => $user->display_name,
			'avatar'   => is_string( $avatar ) && wp_parse_url( $avatar, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ? esc_url_raw( $avatar ) : '',
			'assigned' => $assigned,
		);
	}

    public static function redirect_legacy_login_page(): void {
        if (!self::is_legacy_login_page()) {
            return;
        }

        if (self::is_canonical_login_request()) {
            return;
        }

        $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';
        wp_safe_redirect(self::login_url(wp_login_url($redirect_to), $redirect_to, false), 301);
        exit;
    }

    public static function redirect_wp_login_to_canonical(): void {
        if (!isset($_SERVER['REQUEST_URI']) || !isset($_SERVER['REQUEST_METHOD'])) {
            return;
        }

        $method = strtoupper((string) $_SERVER['REQUEST_METHOD']);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $path = (string) wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        if (basename($path) !== 'wp-login.php') {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : 'login';
        $allowed_actions = ['login', 'register', 'lostpassword', 'rp', 'resetpass'];

        if (!in_array($action, $allowed_actions, true)) {
            return;
        }

        $args = [];
        foreach (['action', 'redirect_to', 'reauth', 'wp_lang', 'login'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }

        if (($args['action'] ?? '') === 'login') {
            unset($args['action']);
        }

        wp_safe_redirect(add_query_arg($args, self::canonical_login_url()), 302);
        exit;
    }

    public static function render_digits_login(): void {
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
            return;
        }

        if (!function_exists('df_digits_form_login')) {
            return;
        }

        $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';

		nocache_headers();
		login_header( __( 'Log In' ), '', null );

        if (isset($_GET['login']) && sanitize_key(wp_unslash($_GET['login'])) === 'failed') {
            echo '<div id="login_error" class="notice notice-error"><p>';
            echo esc_html(self::failed_login_message());
            echo '</p></div>';
        }

		echo '<div class="dg-digits-login-shell dg-digits-auto-identity">';
        echo self::digits_login_form_html($redirect_to); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        echo '<p id="nav">';
        echo '<a href="' . esc_url(self::register_url(wp_registration_url())) . '">';
        echo esc_html__('ثبت نام', 'digitalogic');
        echo '</a>';
        echo '<span class="dg-nav-separator" aria-hidden="true">/</span>';
        echo '<a href="' . esc_url(self::lostpassword_url(wp_lostpassword_url(), $redirect_to)) . '">';
        echo esc_html__('بازیابی رمز عبور', 'digitalogic');
        echo '</a>';
        echo '</p>';

        self::render_login_footer();
        exit;
    }

    public static function redirect_failed_login(string $username): void {
        if (wp_doing_ajax()) {
            return;
        }

        $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';
        $args = ['login' => 'failed'];

        if ($redirect_to !== '') {
            $args['redirect_to'] = $redirect_to;
        }

        if (isset($_REQUEST['wp_lang']) && $_REQUEST['wp_lang'] !== '') {
            $args['wp_lang'] = sanitize_text_field(wp_unslash($_REQUEST['wp_lang']));
        }

        wp_safe_redirect(add_query_arg($args, self::canonical_login_url()), 302);
        exit;
    }

    private static function digits_login_form_html(string $redirect_to): string {
        if (function_exists('digits_render_new_form')) {
            $details = [
                'page_type' => 'login',
                'login_title' => __('Log In'),
                'login_details' => [
                    'dig_login_email' => 1,
                    'dig_login_mobilenumber' => 1,
                    'dig_login_username' => 1,
                    'dig_login_captcha' => '0',
                ],
            ];

            if ($redirect_to !== '') {
                $details['login_redirect'] = $redirect_to;
            }

            try {
                ob_start();
                digits_render_new_form($details);
                $html = trim((string) ob_get_clean());

                if ($html !== '') {
                    return self::add_login_http_fallback($html); // phpcs:ignore
                }
            } catch (Throwable $error) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
            }
        }

        return self::add_login_http_fallback((string) df_digits_form_login()); // phpcs:ignore
    }

// phpcs:disable -- Keep the focused Digits HTML transformation neutral to the legacy-file baseline.
    private static function digits_register_form_html(): string {
        return self::add_login_http_fallback((string) df_digits_form_signup());
    }

    private static function add_login_http_fallback(string $html): string {
        if (
            $html === ''
            || strpos($html, 'digits-form_toggle_login_register') === false
            || strpos($html, 'show_login') === false
        ) {
            return $html;
        }

        $login_url = esc_attr(esc_url(self::canonical_login_url()));
        $rewritten = preg_replace_callback(
            '/<a\b(?=[^>]*\bdigits-form_toggle_login_register\b)(?=[^>]*\bshow_login\b)[^>]*>/i',
            static function(array $matches) use ($login_url): string {
                $tag = $matches[0];
                $href_pattern = '/\shref\s*=\s*(["\'])(.*?)\1/i';

                if (!preg_match($href_pattern, $tag, $href)) {
                    return substr($tag, 0, -1) . ' href="' . $login_url . '">';
                }

                $current = trim(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($current !== '' && $current !== '#' && stripos($current, 'javascript:') !== 0) {
                    return $tag;
                }

                $replacement = preg_replace(
                    $href_pattern,
                    ' href="' . $login_url . '"',
                    $tag,
                    1
                );

                return is_string($replacement) ? $replacement : $tag;
            },
            $html
        );

        return is_string($rewritten) ? $rewritten : $html;
    }
// phpcs:enable

    public static function render_digits_register(): void {
        $redirect_to = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash($_REQUEST['redirect_to'])) : '';

        login_header(__('Register'), '', null);

        echo '<div class="dg-digits-register-shell">';

        if (function_exists('df_digits_form_signup')) {
            echo self::digits_register_form_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        } else {
            echo '<div id="login_error" class="notice notice-error"><p>';
            esc_html_e('Digits registration is not available. Please check the Digits plugin configuration.', 'digitalogic');
            echo '</p></div>';
        }

        echo '</div>';
        echo '<p id="nav">';
        echo '<a href="' . esc_url(self::login_url(wp_login_url($redirect_to), $redirect_to, false)) . '">';
        echo esc_html__('ورود', 'digitalogic');
        echo '</a>';
        echo '</p>';

        self::render_login_footer();
        exit;
    }

    /**
     * Render the custom login footer without WordPress.org translation discovery.
     *
     * The canonical login page already runs in the locale selected by WordPress
     * (including a supplied wp_lang value). The core footer language selector can
     * nevertheless call the remote translations API while rendering, holding a
     * PHP-FPM worker when that service is slow or unavailable. Keep the override
     * scoped to this footer render so the standard WordPress login screen and all
     * other admin language controls retain their normal behaviour.
     */
    private static function render_login_footer(): void {
        add_filter('login_display_language_dropdown', '__return_false', PHP_INT_MAX);

        try {
            login_footer('user_login');
        } finally {
            remove_filter('login_display_language_dropdown', '__return_false', PHP_INT_MAX);
        }
    }

    public static function register_url(string $url): string {
        return add_query_arg('action', 'register', self::canonical_login_url());
    }

    public static function lostpassword_url(string $url, string $redirect = ''): string {
        $args = ['action' => 'lostpassword'];

        if ($redirect !== '') {
            $args['redirect_to'] = $redirect;
        }

        return add_query_arg($args, self::canonical_login_url());
    }

    public static function login_url(string $login_url, string $redirect = '', bool $force_reauth = false): string {
        $args = [];

        if ($redirect !== '') {
            $args['redirect_to'] = $redirect;
        }

        if ($force_reauth) {
            $args['reauth'] = '1';
        }

        return add_query_arg($args, self::canonical_login_url());
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

    public static function bypass_wordfence_captcha_for_digits($required) {
        if (!wp_doing_ajax()) {
            return $required;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        if (str_starts_with($action, 'digits_')) {
            return false;
        }

        return $required;
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
        $digits = preg_replace('/\D+/', '', self::normalize_digits($phone)) ?? '';

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

    public static function canonical_login_url(): string {
        return home_url(self::CANONICAL_LOGIN_PATH);
    }

    private static function failed_login_message(): string {
        $locale = strtolower(determine_locale());

        if (str_starts_with($locale, 'fa')) {
            return 'نام کاربری، ایمیل، شماره موبایل یا رمز عبور نادرست است.';
        }

        return 'The username, email, phone number, or password is incorrect.';
    }

    private static function is_canonical_login_request(): bool {
        $path = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
            : '';

        return untrailingslashit($path) === untrailingslashit(self::CANONICAL_LOGIN_PATH);
    }

    private static function normalize_digits(string $value): string {
        return strtr($value, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);
    }
}
