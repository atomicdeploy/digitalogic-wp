<?php
/**
 * Laravel Panel launcher.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap digitalogic-panel-page">
    <h1><?php _e('Laravel Panel', 'digitalogic'); ?></h1>

    <div class="digitalogic-panel-card">
        <h2><?php _e('Open the Digitalogic operations panel', 'digitalogic'); ?></h2>
        <p>
            <?php _e('The Laravel panel uses your current WordPress login to create a short-lived, one-time session handoff. You may be asked to log in to WordPress first if your admin session has expired.', 'digitalogic'); ?>
        </p>

        <p class="digitalogic-panel-actions">
            <a class="button button-primary button-hero" href="<?php echo esc_url($launch_url); ?>">
                <?php _e('Open Laravel Panel', 'digitalogic'); ?>
            </a>
            <a class="button" href="<?php echo esc_url($panel_url); ?>" target="_blank" rel="noopener">
                <?php _e('Open panel URL directly', 'digitalogic'); ?>
            </a>
        </p>

        <dl class="digitalogic-panel-meta">
            <dt><?php _e('Panel URL', 'digitalogic'); ?></dt>
            <dd><code><?php echo esc_html($panel_url); ?></code></dd>
            <dt><?php _e('Authentication', 'digitalogic'); ?></dt>
            <dd><?php _e('WordPress one-time handoff, consumable by Laravel through the Digitalogic panel bridge.', 'digitalogic'); ?></dd>
        </dl>
    </div>
</div>
