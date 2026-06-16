<?php
/**
 * Laravel Panel launcher.
 */

if (!defined('ABSPATH')) {
    exit;
}

$labels = is_rtl() ? array(
    'title' => 'پنل',
    'heading' => 'باز کردن پنل عملیاتی دیجیتالاجیک',
    'description' => 'این پنل با ورود فعلی وردپرس شما کار می کند و در یک زبانه جدا باز می شود. مسیر موقت /panell/ است تا با vhost های رزروشده تداخل نداشته باشد.',
    'open' => 'باز کردن پنل',
    'direct' => 'باز کردن مستقیم آدرس پنل',
    'panel_url' => 'آدرس پنل',
    'authentication' => 'احراز هویت',
    'auth_text' => 'نشست وردپرس، فرمان های وب سوکت، و جایگزین AJAX از طریق پل پنل دیجیتالاجیک.',
) : array(
    'title' => __('Panel', 'digitalogic'),
    'heading' => __('Open the Digitalogic operations panel', 'digitalogic'),
    'description' => __('The panel uses your current WordPress login and opens in a separate tab. The temporary in-site route is /panell/ so it does not interfere with reserved platform vhosts.', 'digitalogic'),
    'open' => __('Open Panel', 'digitalogic'),
    'direct' => __('Open panel URL directly', 'digitalogic'),
    'panel_url' => __('Panel URL', 'digitalogic'),
    'authentication' => __('Authentication', 'digitalogic'),
    'auth_text' => __('WordPress session, WebSocket commands, and AJAX fallback through the Digitalogic panel bridge.', 'digitalogic'),
);
?>
<div class="wrap digitalogic-panel-page">
    <h1><?php echo esc_html($labels['title']); ?></h1>

    <div class="digitalogic-panel-card">
        <h2><?php echo esc_html($labels['heading']); ?></h2>
        <p>
            <?php echo esc_html($labels['description']); ?>
        </p>

        <p class="digitalogic-panel-actions">
            <a class="button button-primary button-hero" href="<?php echo esc_url($launch_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($labels['open']); ?>
            </a>
            <a class="button" href="<?php echo esc_url($panel_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($labels['direct']); ?>
            </a>
        </p>

        <dl class="digitalogic-panel-meta">
            <dt><?php echo esc_html($labels['panel_url']); ?></dt>
            <dd><code><?php echo esc_html($panel_url); ?></code></dd>
            <dt><?php echo esc_html($labels['authentication']); ?></dt>
            <dd><?php echo esc_html($labels['auth_text']); ?></dd>
        </dl>
    </div>
</div>
