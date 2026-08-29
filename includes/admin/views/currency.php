<div class="wrap digitalogic-currency">
    <h1><?php esc_html_e('Currency Settings', 'digitalogic'); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('digitalogic_currency_update'); ?>
        <input type="hidden" name="pricing_state_revision" value="<?php echo esc_attr($this->currency_page_value('pricing_state_revision')); ?>">

        <?php $pricing_job = $this->currency_page_value('pricing_job'); ?>
        <div
            id="digitalogic-currency-async-status"
            class="notice notice-info"
            data-status="<?php echo esc_attr((string) ($pricing_job['status'] ?? 'idle')); ?>"
            <?php echo 'idle' === ($pricing_job['status'] ?? 'idle') ? 'hidden' : ''; ?>
        >
            <p><?php echo esc_html((string) ($pricing_job['message_fa'] ?? '')); ?></p>
        </div>

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">
                <div id="postbox-container-1" class="postbox-container">
                    <?php do_meta_boxes($current_screen, 'side', null); ?>
                </div>

                <div id="postbox-container-2" class="postbox-container">
                    <?php do_meta_boxes($current_screen, 'normal', null); ?>
                </div>
            </div>
            <br class="clear">
        </div>
    </form>
</div>
