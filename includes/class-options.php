<?php
/**
 * Options Management Class
 * 
 * Handles currency prices and other plugin options
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Options {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Constructor
    }
    
    /**
     * Get dollar price in local currency
     * 
     * @return float
     */
    public function get_dollar_price() {
        return (float) get_option('digitalogic_dollar_price', 0);
    }
    
    /**
     * Set dollar price in local currency
     * 
     * @param float $price
     * @return bool
     */
    public function set_dollar_price($price) {
        $result = update_option('digitalogic_dollar_price', (float) $price);
        $this->update_date();
        
        // Log the change
        Digitalogic_Logger::instance()->log(
            'update_currency',
            'option',
            null,
            null,
            $price,
            'Updated dollar price to ' . $price
        );
        
        return $result;
    }
    
    /**
     * Get yuan/CNY price in local currency
     * 
     * @return float
     */
    public function get_yuan_price() {
        return (float) get_option('digitalogic_yuan_price', 0);
    }
    
    /**
     * Set yuan/CNY price in local currency
     * 
     * @param float $price
     * @return bool
     */
    public function set_yuan_price($price) {
        $result = update_option('digitalogic_yuan_price', (float) $price);
        $this->update_date();
        
        // Log the change
        Digitalogic_Logger::instance()->log(
            'update_currency',
            'option',
            null,
            null,
            $price,
            'Updated yuan price to ' . $price
        );
        
        return $result;
    }
    
    /**
     * Get last update date
     * 
     * @return string YYMMDD format
     */
    public function get_update_date() {
        return get_option('digitalogic_update_date', date('ymd'));
    }
    
    /**
     * Update the last modified date to today
     * 
     * @return bool
     */
    private function update_date() {
        return update_option('digitalogic_update_date', date('ymd'));
    }
    
    /**
     * ACF-compatible get_field function
     * 
     * @param string $field_name
     * @param string $context
     * @return mixed
     */
    public function get_field($field_name, $context = 'option') {
        if ($context === 'option') {
            switch ($field_name) {
                case 'dollar_price':
                    return $this->get_dollar_price();
                case 'yuan_price':
                    return $this->get_yuan_price();
                case 'update_date':
                    return $this->get_update_date();
                default:
                    return get_option('digitalogic_' . $field_name);
            }
        }
        return null;
    }
    
    /**
     * ACF-compatible update_field function
     * 
     * @param string $field_name
     * @param mixed $value
     * @param string $context
     * @return bool
     */
    public function update_field($field_name, $value, $context = 'option') {
        if ($context === 'option') {
            switch ($field_name) {
                case 'dollar_price':
                    return $this->set_dollar_price($value);
                case 'yuan_price':
                    return $this->set_yuan_price($value);
                default:
                    return update_option('digitalogic_' . $field_name, $value);
            }
        }
        return false;
    }
}

/**
 * Helper functions for ACF-style field access
 */
if (!function_exists('digitalogic_get_field')) {
    function digitalogic_get_field($field_name, $context = 'option') {
        return Digitalogic_Options::instance()->get_field($field_name, $context);
    }
}

if (!function_exists('digitalogic_update_field')) {
    function digitalogic_update_field($field_name, $value, $context = 'option') {
        return Digitalogic_Options::instance()->update_field($field_name, $value, $context);
    }
}
