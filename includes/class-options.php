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
    
    // Track if ACF is available
    private $acf_available = false;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Check if ACF is available
        $this->acf_available = function_exists('get_field') && function_exists('update_field');
        
        // Hook into ACF functions if available
        if ($this->acf_available) {
            $this->setup_acf_hooks();
        }
    }
    
    /**
     * Setup hooks for ACF function interception
     * This ensures complete bidirectional sync even when ACF functions are called directly
     */
    private function setup_acf_hooks() {
        // Hook into ACF's update process to sync back to direct options
        add_filter('acf/update_value', array($this, 'acf_update_value_hook'), 10, 3);
        
        // Hook into ACF's get value to ensure consistency
        add_filter('acf/load_value', array($this, 'acf_load_value_hook'), 10, 3);
    }
    
    /**
     * Hook for ACF update_field() calls
     * Ensures when ACF updates a field, our direct option is also updated
     * 
     * @param mixed $value The value to update
     * @param int|string $post_id The post ID or 'option'
     * @param array $field The field settings
     * @return mixed
     */
    public function acf_update_value_hook($value, $post_id, $field) {
        // Only intercept option pages
        if ($post_id !== 'option' && $post_id !== 'options') {
            return $value;
        }
        
        // Check if this is one of our currency fields
        $field_name = isset($field['name']) ? $field['name'] : (isset($field['key']) ? $field['key'] : '');
        
        // Prevent infinite loops
        static $updating = array();
        if (isset($updating[$field_name])) {
            return $value;
        }
        $updating[$field_name] = true;
        
        // Sync to direct options based on field name
        if ($field_name === 'dollar_price' || $field_name === 'options_dollar_price') {
            update_option('dollar_price', $value);
        } elseif ($field_name === 'yuan_price' || $field_name === 'options_yuan_price') {
            update_option('yuan_price', $value);
        } elseif ($field_name === 'update_date' || $field_name === 'options_update_date') {
            update_option('update_date', $value);
        }
        
        unset($updating[$field_name]);
        
        return $value;
    }
    
    /**
     * Hook for ACF get_field() calls
     * Ensures ACF gets the most current value from our storage
     * 
     * @param mixed $value The value
     * @param int|string $post_id The post ID or 'option'
     * @param array $field The field settings
     * @return mixed
     */
    public function acf_load_value_hook($value, $post_id, $field) {
        // Only intercept option pages
        if ($post_id !== 'option' && $post_id !== 'options') {
            return $value;
        }
        
        // Check if this is one of our currency fields
        $field_name = isset($field['name']) ? $field['name'] : (isset($field['key']) ? $field['key'] : '');
        
        // Return value from our storage if it's one of our fields
        if ($field_name === 'dollar_price' || $field_name === 'options_dollar_price') {
            $stored = $this->get_dollar_price();
            return $stored !== 0 ? $stored : $value;
        } elseif ($field_name === 'yuan_price' || $field_name === 'options_yuan_price') {
            $stored = $this->get_yuan_price();
            return $stored !== 0 ? $stored : $value;
        } elseif ($field_name === 'update_date' || $field_name === 'options_update_date') {
            return $this->get_update_date();
        }
        
        return $value;
    }
    
    /**
     * Check if ACF is available
     * 
     * @return bool
     */
    public function is_acf_available() {
        return $this->acf_available;
    }
    
    /**
     * Get dollar price in local currency
     * Works with or without ACF - tries multiple storage locations
     * 
     * @return float
     */
    public function get_dollar_price() {
        // Try ACF storage first if ACF is available (options_ prefix)
        if ($this->acf_available) {
            $value = get_option('options_dollar_price', false);
            if ($value !== false) {
                return (float) $value;
            }
        }
        
        // Fallback to direct option (works without ACF)
        return (float) get_option('dollar_price', 0);
    }
    
    /**
     * Set dollar price in local currency
     * Updates both ACF storage (if available) and direct option for full compatibility
     * Works with or without ACF
     * 
     * @param float $price
     * @return bool
     */
    public function set_dollar_price($price) {
        $price = (float) $price;
        
        // Update ACF storage if ACF is available (options_ prefix)
        if ($this->acf_available) {
            update_option('options_dollar_price', $price);
        }
        
        // Always update direct option (works with or without ACF)
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
     * Works with or without ACF - tries multiple storage locations
     * 
     * @return float
     */
    public function get_yuan_price() {
        // Try ACF storage first if ACF is available (options_ prefix)
        if ($this->acf_available) {
            $value = get_option('options_yuan_price', false);
            if ($value !== false) {
                return (float) $value;
            }
        }
        
        // Fallback to direct option (works without ACF)
        return (float) get_option('yuan_price', 0);
    }
    
    /**
     * Set yuan/CNY price in local currency
     * Updates both ACF storage (if available) and direct option for full compatibility
     * Works with or without ACF
     * 
     * @param float $price
     * @return bool
     */
    public function set_yuan_price($price) {
        $price = (float) $price;
        
        // Update ACF storage if ACF is available (options_ prefix)
        if ($this->acf_available) {
            update_option('options_yuan_price', $price);
        }
        
        // Always update direct option (works with or without ACF)
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
     * Works with or without ACF - tries multiple storage locations
     * 
     * @return string YYMMDD format
     */
    public function get_update_date() {
        // Try ACF storage first if ACF is available (options_ prefix)
        if ($this->acf_available) {
            $value = get_option('options_update_date', false);
            if ($value !== false) {
                return $value;
            }
        }
        
        // Fallback to direct option (works without ACF)
        return get_option('update_date', date('ymd'));
    }
    
    /**
     * Parse the stored YYMMDD date format to Y-m-d string
     * 
     * @param string $date_raw Date in YYMMDD format
     * @return string Date in Y-m-d format
     */
    private function parse_update_date($date_raw) {
        // Convert YYMMDD to a full date string
        // Assuming 20XX century for YY
        if (strlen($date_raw) === 6) {
            $year = '20' . substr($date_raw, 0, 2);
            $month = substr($date_raw, 2, 2);
            $day = substr($date_raw, 4, 2);
            return $year . '-' . $month . '-' . $day;
        } else {
            // Fallback to today's date if format is wrong
            return date('Y-m-d');
        }
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
        $date_string = $this->parse_update_date($update_date_raw);
        
        // Check if Persian (Jalali) date conversion is available
        if (function_exists('parsidate') && get_locale() === 'fa_IR') {
            return parsidate($format, strtotime($date_string));
        } else {
            return date_i18n($format, strtotime($date_string));
        }
    }
    
    /**
     * Get relative time for update date (e.g., "today", "2 days ago")
     * 
     * @return string Relative time string
     */
    public function get_update_date_relative() {
        $update_date_raw = $this->get_update_date();
        $date_string = $this->parse_update_date($update_date_raw);
        
        $update_timestamp = strtotime($date_string);
        $today = strtotime(date('Y-m-d'));
        
        // Calculate difference in days
        $seconds_per_day = 86400; // 24 * 60 * 60
        $diff_seconds = $today - $update_timestamp;
        $diff_days = (int) floor($diff_seconds / $seconds_per_day);
        
        // Handle future dates (negative difference)
        if ($diff_days < 0) {
            return __('today', 'digitalogic');
        }
        
        if ($diff_days === 0) {
            return __('today', 'digitalogic');
        } elseif ($diff_days === 1) {
            return __('1 day ago', 'digitalogic');
        } else {
            return sprintf(__('%d days ago', 'digitalogic'), $diff_days);
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
     * Updates both ACF storage (if available) and direct option for full compatibility
     * Works with or without ACF
     * 
     * @return bool
     */
    private function update_date() {
        $date = date('ymd');
        
        // Update ACF storage if ACF is available (options_ prefix)
        if ($this->acf_available) {
            update_option('options_update_date', $date);
        }
        
        // Always update direct option (works with or without ACF)
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
 * IMPORTANT: Plugin works both WITH and WITHOUT ACF installed
 * 
 * WITH ACF:
 * - Uses ACF's options_ prefix storage
 * - Hooks into ACF functions for complete bidirectional sync
 * - get_field() and update_field() work natively
 * - All storage locations kept in sync
 * 
 * WITHOUT ACF:
 * - Uses direct WordPress options storage
 * - Provides fallback ACF-compatible functions (below)
 * - Plugin methods work identically
 * - No ACF dependency required
 * 
 * Storage locations are synchronized automatically in both cases.
 */

/**
 * Provide fallback ACF-compatible functions if ACF is not installed
 * This allows the plugin to work standalone without ACF
 */

if (!function_exists('get_field')) {
    /**
     * Fallback get_field() function when ACF is not installed
     * Provides ACF-compatible API using WordPress options
     * 
     * @param string $selector Field name
     * @param int|string $post_id Post ID or 'option'
     * @param bool $format_value Whether to format the value
     * @return mixed
     */
    function get_field($selector, $post_id = false, $format_value = true) {
        // Only handle option pages
        if ($post_id !== 'option' && $post_id !== 'options') {
            return false;
        }
        
        // Use Digitalogic methods for our currency fields
        $options = Digitalogic_Options::instance();
        
        if ($selector === 'dollar_price') {
            return $options->get_dollar_price();
        } elseif ($selector === 'yuan_price') {
            return $options->get_yuan_price();
        } elseif ($selector === 'update_date') {
            return $options->get_update_date();
        }
        
        // For other fields, try direct option access
        return get_option($selector, false);
    }
}

if (!function_exists('update_field')) {
    /**
     * Fallback update_field() function when ACF is not installed
     * Provides ACF-compatible API using WordPress options
     * 
     * @param string $selector Field name
     * @param mixed $value Field value
     * @param int|string $post_id Post ID or 'option'
     * @return bool
     */
    function update_field($selector, $value, $post_id = false) {
        // Only handle option pages
        if ($post_id !== 'option' && $post_id !== 'options') {
            return false;
        }
        
        // Use Digitalogic methods for our currency fields
        $options = Digitalogic_Options::instance();
        
        if ($selector === 'dollar_price') {
            return $options->set_dollar_price($value);
        } elseif ($selector === 'yuan_price') {
            return $options->set_yuan_price($value);
        } elseif ($selector === 'update_date') {
            update_option('options_update_date', $value);
            return update_option('update_date', $value);
        }
        
        // For other fields, use direct option update
        update_option('options_' . $selector, $value);
        return update_option($selector, $value);
    }
}
