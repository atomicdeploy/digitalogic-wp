<div class="wrap digitalogic-currency">
    <h1><?php esc_html_e('Currency Settings', 'digitalogic'); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('digitalogic_currency_update'); ?>

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
