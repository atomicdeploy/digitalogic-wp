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
        return (float) get_option('dollar_price', 0);
    }
    
    /**
     * Set dollar price in local currency
     * 
     * @param float $price
     * @return bool
     */
    public function set_dollar_price($price) {
        $result = update_option('dollar_price', (float) $price);
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
        return (float) get_option('yuan_price', 0);
    }
    
    /**
     * Set yuan/CNY price in local currency
     * 
     * @param float $price
     * @return bool
     */
    public function set_yuan_price($price) {
        $result = update_option('yuan_price', (float) $price);
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
        return get_option('update_date', date('ymd'));
    }
    
    /**
     * Get formatted update date for display
     * Supports Persian dates via parsidate plugin if available
     * 
     * @param string $format Date format (default: 'Y/m/d')
     * @return string Formatted date
     */
    public function get_update_date_formatted($format = 'Y/m/d') {
        $update_date_raw = $this->get_update_date();
        
        // Convert YYMMDD to a full date string
        // Assuming 20XX century for YY
        if (strlen($update_date_raw) === 6) {
            $year = '20' . substr($update_date_raw, 0, 2);
            $month = substr($update_date_raw, 2, 2);
            $day = substr($update_date_raw, 4, 2);
            $date_string = $year . '-' . $month . '-' . $day;
        } else {
            // Fallback to today's date if format is wrong
            $date_string = date('Y-m-d');
        }
        
        // Check if Persian (Jalali) date conversion is available
        if (function_exists('parsidate') && get_locale() === 'fa_IR') {
            return parsidate($format, strtotime($date_string));
        } else {
            return date_i18n($format, strtotime($date_string));
        }
    }
    
    /**
     * Helper function to format any date with Persian support
     * Can be used throughout the plugin for consistent date formatting
     * 
     * @param int|string $timestamp Unix timestamp or date string
     * @param string $format Date format (default: 'Y/m/d')
     * @return string Formatted date
     */
    public static function format_date($timestamp, $format = 'Y/m/d') {
        // Convert string to timestamp if needed
        if (!is_numeric($timestamp)) {
            $timestamp = strtotime($timestamp);
        }
        
        // Check if Persian (Jalali) date conversion is available
        if (function_exists('parsidate') && get_locale() === 'fa_IR') {
            return parsidate($format, $timestamp);
        } else {
            return date_i18n($format, $timestamp);
        }
    }
    
    /**
     * Update the last modified date to today
     * 
     * @return bool
     */
    private function update_date() {
        return update_option('update_date', date('ymd'));
    }
}

/**
 * Note: This plugin stores options without prefix (dollar_price, yuan_price, update_date)
 * to ensure compatibility with ACF and other plugins that may use these shared fields.
 * 
 * Both get_option('dollar_price') and get_field('dollar_price', 'option') access
 * the same underlying WordPress option in wp_options table.
 * 
 * This allows:
 * - ACF to read/write these fields when installed
 * - Other plugins to access the same shared fields
 * - Standard WordPress option storage without prefixes
 * - True field sharing across plugins
 */
