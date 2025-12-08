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
     * Reads from ACF storage (options_dollar_price) for consistency
     * 
     * @return float
     */
    public function get_dollar_price() {
        // Try ACF storage first (options_ prefix)
        $value = get_option('options_dollar_price', false);
        if ($value !== false) {
            return (float) $value;
        }
        // Fallback to direct option
        return (float) get_option('dollar_price', 0);
    }
    
    /**
     * Set dollar price in local currency
     * Updates both ACF storage and direct option for full compatibility
     * 
     * @param float $price
     * @return bool
     */
    public function set_dollar_price($price) {
        $price = (float) $price;
        
        // Update ACF storage (options_ prefix)
        update_option('options_dollar_price', $price);
        
        // Also update direct option for backward compatibility
        $result = update_option('dollar_price', $price);
        
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
     * Reads from ACF storage (options_yuan_price) for consistency
     * 
     * @return float
     */
    public function get_yuan_price() {
        // Try ACF storage first (options_ prefix)
        $value = get_option('options_yuan_price', false);
        if ($value !== false) {
            return (float) $value;
        }
        // Fallback to direct option
        return (float) get_option('yuan_price', 0);
    }
    
    /**
     * Set yuan/CNY price in local currency
     * Updates both ACF storage and direct option for full compatibility
     * 
     * @param float $price
     * @return bool
     */
    public function set_yuan_price($price) {
        $price = (float) $price;
        
        // Update ACF storage (options_ prefix)
        update_option('options_yuan_price', $price);
        
        // Also update direct option for backward compatibility
        $result = update_option('yuan_price', $price);
        
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
     * Reads from ACF storage (options_update_date) for consistency
     * 
     * @return string YYMMDD format
     */
    public function get_update_date() {
        // Try ACF storage first (options_ prefix)
        $value = get_option('options_update_date', false);
        if ($value !== false) {
            return $value;
        }
        // Fallback to direct option
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
     * Updates both ACF storage and direct option for full compatibility
     * 
     * @return bool
     */
    private function update_date() {
        $date = date('ymd');
        
        // Update ACF storage (options_ prefix)
        update_option('options_update_date', $date);
        
        // Also update direct option for backward compatibility
        return update_option('update_date', $date);
    }
}

/**
 * Add WordPress option filters to ensure get_option() and get_field() are always synchronized
 * ACF stores options with 'options_' prefix, so we need to redirect get_option() calls
 * 
 * This ensures that when ANY code calls get_option('dollar_price'), it gets the value
 * from ACF storage (options_dollar_price), maintaining bidirectional synchronization.
 */

// Redirect get_option('dollar_price') to use our plugin methods
add_filter('pre_option_dollar_price', function($pre_option, $option, $default) {
    // Use our plugin method which handles ACF storage properly
    $options = Digitalogic_Options::instance();
    return $options->get_dollar_price();
}, 10, 3);

// Redirect get_option('yuan_price') to use our plugin methods
add_filter('pre_option_yuan_price', function($pre_option, $option, $default) {
    // Use our plugin method which handles ACF storage properly
    $options = Digitalogic_Options::instance();
    return $options->get_yuan_price();
}, 10, 3);

// Redirect get_option('update_date') to use our plugin methods
add_filter('pre_option_update_date', function($pre_option, $option, $default) {
    // Use our plugin method which handles ACF storage properly
    $options = Digitalogic_Options::instance();
    return $options->get_update_date();
}, 10, 3);

/**
 * Hook into update_option to synchronize when options are updated directly
 * When someone calls update_option('dollar_price', $value), redirect to our methods
 * to ensure proper synchronization with ACF storage and logging.
 */
add_action('update_option_dollar_price', function($old_value, $value, $option) {
    // Prevent infinite loop by checking if we're already in our method
    static $updating = false;
    if ($updating) {
        return;
    }
    $updating = true;
    
    // Use our plugin method which handles ACF sync and logging
    $options = Digitalogic_Options::instance();
    $options->set_dollar_price($value);
    
    $updating = false;
}, 10, 3);

add_action('update_option_yuan_price', function($old_value, $value, $option) {
    // Prevent infinite loop by checking if we're already in our method
    static $updating = false;
    if ($updating) {
        return;
    }
    $updating = true;
    
    // Use our plugin method which handles ACF sync and logging
    $options = Digitalogic_Options::instance();
    $options->set_yuan_price($value);
    
    $updating = false;
}, 10, 3);

add_action('update_option_update_date', function($old_value, $value, $option) {
    // Synchronize to ACF storage (options_ prefix)
    // Don't use plugin method here to avoid recursion
    static $updating = false;
    if ($updating) {
        return;
    }
    $updating = true;
    
    update_option('options_update_date', $value);
    
    $updating = false;
}, 10, 3);

/**
 * Hook into add_option to synchronize when options are added directly
 * This ensures even the first-time creation of these options goes through our methods.
 */
add_action('add_option_dollar_price', function($option, $value) {
    // Prevent infinite loop
    static $adding = false;
    if ($adding) {
        return;
    }
    $adding = true;
    
    // Synchronize to ACF storage (options_ prefix)
    update_option('options_dollar_price', $value);
    
    $adding = false;
}, 10, 2);

add_action('add_option_yuan_price', function($option, $value) {
    // Prevent infinite loop
    static $adding = false;
    if ($adding) {
        return;
    }
    $adding = true;
    
    // Synchronize to ACF storage (options_ prefix)
    update_option('options_yuan_price', $value);
    
    $adding = false;
}, 10, 2);

add_action('add_option_update_date', function($option, $value) {
    // Prevent infinite loop
    static $adding = false;
    if ($adding) {
        return;
    }
    $adding = true;
    
    // Synchronize to ACF storage (options_ prefix)
    update_option('options_update_date', $value);
    
    $adding = false;
}, 10, 2);

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
