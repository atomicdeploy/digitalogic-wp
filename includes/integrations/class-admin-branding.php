<?php
/**
 * Digitalogic admin and login branding layer.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Digitalogic_Plugin_Admin_Branding {
    public const OPTION_ENABLED = 'digitalogic_custom_ui_enabled';
    private const STYLE_HANDLE = 'digitalogic-admin-branding';
    private const STYLE_FILE = 'assets/css/branding/admin-branding.css';
    private const SCRIPT_HANDLE = 'digitalogic-admin-branding';
    private const SCRIPT_FILE = 'assets/js/branding/admin-branding.js';
    private const THEME_STORAGE_KEY = 'digitalogic-admin-theme';

    public static function init(): void {
        static $booted = false;

        if ($booted || !self::is_enabled()) {
            return;
        }

        $booted = true;

        add_action('admin_head', [self::class, 'output_theme_bootstrap']);
        add_action('login_head', [self::class, 'output_theme_bootstrap']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action('login_enqueue_scripts', [self::class, 'enqueue_login_assets']);
        add_action('admin_bar_menu', [self::class, 'admin_bar_menu'], 1);
        add_action('wp_dashboard_setup', [self::class, 'register_dashboard_widgets']);

        add_filter('admin_body_class', [self::class, 'admin_body_class']);
        add_filter('login_body_class', [self::class, 'login_body_class']);
        add_filter('login_headerurl', [self::class, 'login_header_url']);
        add_filter('login_headertext', [self::class, 'login_header_text']);
        add_filter('admin_footer_text', [self::class, 'admin_footer_text']);
        add_filter('update_footer', [self::class, 'update_footer'], 999);
    }

    public static function is_enabled(): bool {
        return get_option(self::OPTION_ENABLED, 'yes') === 'yes';
    }

    public static function output_theme_bootstrap(): void {
        $storage_key = wp_json_encode(self::THEME_STORAGE_KEY);

        echo <<<HTML
<script>
(function() {
    var key = {$storage_key};
    var root = document.documentElement;
    var stored = null;

    try {
        stored = window.localStorage ? window.localStorage.getItem(key) : null;
    } catch (error) {
        stored = null;
    }

    var theme = stored;

    if (theme !== 'light' && theme !== 'dark') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    root.setAttribute('data-dg-theme', theme);
})();
</script>
HTML;
    }

    public static function enqueue_admin_assets(): void {
        self::enqueue_assets(false);
    }

    public static function enqueue_login_assets(): void {
        self::enqueue_assets(true);
    }

    public static function admin_body_class(string $classes): string {
        return trim($classes . ' digitalogic-admin-shell');
    }

    public static function login_body_class(array $classes): array {
        $classes[] = 'digitalogic-login-shell';
        return $classes;
    }

    public static function login_header_url(): string {
        return home_url('/');
    }

    public static function login_header_text(): string {
        return get_bloginfo('name');
    }

    public static function admin_bar_menu(WP_Admin_Bar $admin_bar): void {
        if (!is_admin()) {
            return;
        }

        $admin_bar->remove_node('wp-logo');

        $admin_bar->add_node([
            'id'    => 'digitalogic-brand',
            'title' => '<span class="ab-item dg-brand-node"><span class="dg-brand-node__mark"></span><span class="dg-brand-node__text">Digitalogic</span></span>',
            'href'  => home_url('/'),
            'meta'  => [
                'class' => 'digitalogic-brand-node',
                'title' => get_bloginfo('name'),
            ],
        ]);

        $admin_bar->add_node([
            'id'     => 'digitalogic-theme-toggle',
            'parent' => 'digitalogic-brand',
            'title'  => '<span class="ab-item" data-dg-theme-toggle-label>Switch theme</span>',
            'href'   => '#',
            'meta'   => [
                'class' => 'digitalogic-theme-toggle',
            ],
        ]);

        $admin_bar->add_node([
            'id'     => 'digitalogic-login-security',
            'parent' => 'digitalogic-brand',
            'title'  => 'Login Security',
            'href'   => admin_url('admin.php?page=WFLS#top#settings'),
        ]);
    }

    public static function register_dashboard_widgets(): void {
        wp_add_dashboard_widget(
            'digitalogic-admin-overview',
            'Digitalogic Control Center',
            [self::class, 'render_dashboard_widget']
        );
    }

    public static function render_dashboard_widget(): void {
        $wordfence = self::wordfence_status();
        $proxy = self::proxy_status();

        $security_state = $wordfence['captcha_enabled'] ? 'Protected with reCAPTCHA v3' : 'CAPTCHA is disabled';
        $security_tone = $wordfence['captcha_enabled'] ? 'success' : 'warning';
        $proxy_state = $proxy['enabled']
            ? sprintf('Proxy %s:%s with bypass: %s', $proxy['host'], $proxy['port'], $proxy['bypass'])
            : 'No WordPress HTTP proxy constants are enabled.';

        echo '<div class="dg-dashboard-grid">';
        echo '<section class="dg-dashboard-card">';
        echo '<div class="dg-dashboard-card__eyebrow">Brand</div>';
        echo '<h3>Digitalogic admin workspace</h3>';
        echo '<p>Theme-aware controls, cleaner notices, and faster access to the parts of WordPress you use most.</p>';
        echo '<div class="dg-dashboard-actions">';
        echo '<a class="button button-primary" href="' . esc_url(admin_url()) . '">Open dashboard</a>';
        echo '<a class="button" href="' . esc_url(home_url('/')) . '" target="_blank" rel="noopener">View site</a>';
        echo '</div>';
        echo '</section>';

        echo '<section class="dg-dashboard-card">';
        echo '<div class="dg-dashboard-card__eyebrow">Security</div>';
        echo '<div class="dg-dashboard-pill dg-dashboard-pill--' . esc_attr($security_tone) . '">' . esc_html($security_state) . '</div>';
        echo '<p>Provider: ' . esc_html($wordfence['provider']) . '</p>';
        echo '<p>Mode: ' . esc_html($wordfence['captcha_enabled'] ? 'Login protection enabled' : 'Manual sign-in only') . '</p>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=WFLS#top#settings')) . '">Open Wordfence settings</a>';
        echo '</section>';

        echo '<section class="dg-dashboard-card">';
        echo '<div class="dg-dashboard-card__eyebrow">Proxy</div>';
        echo '<div class="dg-dashboard-pill dg-dashboard-pill--' . esc_attr($proxy['enabled'] ? 'info' : 'muted') . '">' . esc_html($proxy['enabled'] ? 'Selective proxy enabled' : 'Direct outbound mode') . '</div>';
        echo '<p>' . esc_html($proxy_state) . '</p>';
        echo '<p>WordPress outbound proxy rules are read from <code>wp-config.php</code>.</p>';
        echo '</section>';
        echo '</div>';
    }

    public static function admin_footer_text(string $text): string {
        return 'Digitalogic admin experience powered by WordPress, Wordfence, and custom branding controls.';
    }

    public static function update_footer(string $text): string {
        return 'Digitalogic UI Layer';
    }

    private static function enqueue_assets(bool $is_login, bool $is_frontend_auth = false): void {
        $style_path = DIGITALOGIC_PLUGIN_DIR . self::STYLE_FILE;
        $script_path = DIGITALOGIC_PLUGIN_DIR . self::SCRIPT_FILE;

        if (!file_exists($style_path) || !file_exists($script_path)) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            DIGITALOGIC_PLUGIN_URL . self::STYLE_FILE,
            [],
            (string) filemtime($style_path)
        );

        wp_add_inline_style(self::STYLE_HANDLE, self::dynamic_css());

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            DIGITALOGIC_PLUGIN_URL . self::SCRIPT_FILE,
            [],
            (string) filemtime($script_path),
            true
        );

        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.DigitalogicBranding = ' . wp_json_encode(self::client_config($is_login, $is_frontend_auth)) . ';',
            'before'
        );
    }

    private static function client_config(bool $is_login, bool $is_frontend_auth = false): array {
        return [
            'storageKey' => self::THEME_STORAGE_KEY,
            'isLogin' => $is_login,
            'isFrontendAuth' => $is_frontend_auth,
            'defaultCountryCode' => '+98',
            'labels' => [
                'dark' => 'تیره',
                'light' => 'روشن',
                'toggleToDark' => 'تغییر به حالت تیره',
                'toggleToLight' => 'تغییر به حالت روشن',
                'runtimeError' => 'یک خطای غیرمنتظره در صفحه رخ داد. بهتر است صفحه را تازه سازی کنید و دوباره ادامه دهید.',
                'requestError' => 'یک درخواست پس زمینه با خطا روبه رو شد. دوباره تلاش کنید یا صفحه را تازه سازی کنید.',
                'requiredUsername' => 'نام کاربری، ایمیل یا شماره موبایل را وارد کنید.',
                'requiredPassword' => 'رمز عبور را وارد کنید.',
                'recaptchaUnavailable' => 'محافظت ورود هنوز کامل بارگذاری نشده است. چند لحظه دیگر دوباره تلاش کنید.',
            ],
        ];
    }

    private static function dynamic_css(): string {
        $stylesheet_uri = get_stylesheet_directory_uri();
        $font_regular = $stylesheet_uri . '/fonts/woff2/' . rawurlencode('IRANSansWeb(FaNum).woff2');
        $font_medium = $stylesheet_uri . '/fonts/woff2/' . rawurlencode('IRANSansWeb(FaNum)_Medium.woff2');
        $font_bold = $stylesheet_uri . '/fonts/woff2/' . rawurlencode('IRANSansWeb(FaNum)_Bold.woff2');
        $logos = self::logo_urls();

        return <<<CSS
@font-face {
    font-family: "DigitalogicFanum";
    src: url("{$font_regular}") format("woff2");
    font-style: normal;
    font-weight: 400;
    font-display: swap;
}
@font-face {
    font-family: "DigitalogicFanum";
    src: url("{$font_medium}") format("woff2");
    font-style: normal;
    font-weight: 500;
    font-display: swap;
}
@font-face {
    font-family: "DigitalogicFanum";
    src: url("{$font_bold}") format("woff2");
    font-style: normal;
    font-weight: 700;
    font-display: swap;
}
:root {
    --dg-font: "DigitalogicFanum", "IRANSans", system-ui, sans-serif;
    --dg-logo-light: url("{$logos['light']}");
    --dg-logo-dark: url("{$logos['dark']}");
}
CSS;
    }

    private static function logo_urls(): array {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $logo_id = (int) get_theme_mod('custom_logo');
        $light = '';
        $dark = '';

        if ($logo_id > 0) {
            $resolved = wp_get_attachment_image_url($logo_id, 'full');
            if (is_string($resolved) && $resolved !== '') {
                $light = $resolved;
                $dark = $resolved;
            }

            $path = get_attached_file($logo_id);
            if (is_string($path) && $path !== '' && file_exists($path) && str_ends_with(strtolower($path), '.svg')) {
                $contents = file_get_contents($path);
                if (is_string($contents) && $contents !== '') {
                    $mutated = str_replace(
                        ['#2f414b', '#2F414B', '#2f414b;', '#2F414B;'],
                        ['#f7fbff', '#f7fbff', '#f7fbff;', '#f7fbff;'],
                        $contents
                    );

                    $dark = 'data:image/svg+xml;base64,' . base64_encode($mutated);
                }
            }
        }

        if ($light === '') {
            $light = content_url('uploads/2025/09/logo-copy-03.svg');
        }

        if ($dark === '') {
            $dark = $light;
        }

        $cached = [
            'light' => $light,
            'dark' => $dark,
        ];

        return $cached;
    }

    private static function wordfence_status(): array {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $settings_table = $wpdb->prefix . 'wfls_settings';
        $defaults = [
            'captcha_enabled' => false,
            'has_keys' => false,
            'provider' => 'Google reCAPTCHA v3',
        ];

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $settings_table));
        if ($table_exists !== $settings_table) {
            $cached = $defaults;
            return $cached;
        }

        $rows = $wpdb->get_results(
            "SELECT name, value FROM {$settings_table} WHERE name IN ('enable-auth-captcha', 'recaptcha-site-key', 'recaptcha-secret')",
            ARRAY_A
        );

        if (!is_array($rows)) {
            $cached = $defaults;
            return $cached;
        }

        $map = [];
        foreach ($rows as $row) {
            if (!isset($row['name'], $row['value'])) {
                continue;
            }

            $map[$row['name']] = (string) $row['value'];
        }

        $cached = [
            'captcha_enabled' => !empty($map['enable-auth-captcha']) && $map['enable-auth-captcha'] !== '0',
            'has_keys' => !empty($map['recaptcha-site-key']) && !empty($map['recaptcha-secret']),
            'provider' => 'Google reCAPTCHA v3',
        ];

        return $cached;
    }

    private static function proxy_status(): array {
        $enabled = defined('WP_PROXY_HOST') && defined('WP_PROXY_PORT');

        return [
            'enabled' => $enabled,
            'host' => $enabled ? (string) WP_PROXY_HOST : '',
            'port' => $enabled ? (string) WP_PROXY_PORT : '',
            'bypass' => defined('WP_PROXY_BYPASS_HOSTS') ? (string) WP_PROXY_BYPASS_HOSTS : 'n/a',
        ];
    }
}
