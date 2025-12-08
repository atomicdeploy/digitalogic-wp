<?php
/**
 * Webhooks Class
 * 
 * Handles webhook notifications for product and currency updates
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Webhooks {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into product updates
        add_action('woocommerce_update_product', array($this, 'product_updated'), 10, 1);
        add_action('woocommerce_new_product', array($this, 'product_created'), 10, 1);
        
        // Hook into currency updates
        add_action('update_option_digitalogic_dollar_price', array($this, 'currency_updated'), 10, 3);
        add_action('update_option_digitalogic_yuan_price', array($this, 'currency_updated'), 10, 3);
        
        // Add settings page
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register webhook settings
     */
    public function register_settings() {
        register_setting('digitalogic_webhooks', 'digitalogic_webhook_urls');
        register_setting('digitalogic_webhooks', 'digitalogic_webhook_secret');
    }
    
    /**
     * Product updated webhook
     */
    public function product_updated($product_id) {
        $manager = Digitalogic_Product_Manager::instance();
        $product = $manager->get_product($product_id);
        
        if ($product) {
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
     * Currency updated webhook
     */
    public function currency_updated($old_value, $value, $option) {
        $options = Digitalogic_Options::instance();
        
        $data = array(
            'dollar_price' => $options->get_dollar_price(),
            'yuan_price' => $options->get_yuan_price(),
            'update_date' => $options->get_update_date(),
            'changed_option' => $option
        );
        
        $this->trigger_webhook('currency.updated', $data);
    }
    
    /**
     * Trigger webhook
     */
    private function trigger_webhook($event, $data) {
        $webhook_urls = get_option('digitalogic_webhook_urls', array());
        
        if (empty($webhook_urls)) {
            return;
        }
        
        // Ensure it's an array
        if (!is_array($webhook_urls)) {
            $webhook_urls = array_filter(array_map('trim', explode("\n", $webhook_urls)));
        }
        
        $secret = get_option('digitalogic_webhook_secret', '');
        
        $payload = array(
            'event' => $event,
            'timestamp' => time(),
            'data' => $data
        );
        
        $payload_json = json_encode($payload);
        $signature = hash_hmac('sha256', $payload_json, $secret);
        
        foreach ($webhook_urls as $url) {
            if (empty($url)) {
                continue;
            }
            
            wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Digitalogic-Signature' => $signature,
                    'X-Digitalogic-Event' => $event
                ),
                'body' => $payload_json,
                'timeout' => 10,
                'blocking' => false // Non-blocking to avoid slowing down operations
            ));
        }
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
}
