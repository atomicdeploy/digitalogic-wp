<?php
/**
 * Logger Class
 * 
 * Handles activity logging and journaling
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Logger {
    
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
     * Log an activity
     * 
     * @param string $action Action name
     * @param string $object_type Type of object (product, option, etc.)
     * @param int $object_id Object ID
     * @param mixed $old_value Old value
     * @param mixed $new_value New value
     * @param string $description Optional description
     * @return bool|int
     */
    public function log($action, $object_type, $object_id = null, $old_value = null, $new_value = null, $description = '') {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $ip_address = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : '';
        
        // Serialize values if they're arrays or objects
        if (is_array($old_value) || is_object($old_value)) {
            $old_value = json_encode($old_value);
        }
        if (is_array($new_value) || is_object($new_value)) {
            $new_value = json_encode($new_value);
        }
        
        $table_name = $wpdb->prefix . 'digitalogic_logs';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'action' => $action,
                'object_type' => $object_type,
                'object_id' => $object_id,
                'old_value' => $old_value,
                'new_value' => $new_value,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get activity logs
     * 
     * @param array $args Query arguments
     * @return array
     */
    public function get_logs($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'user_id' => null,
            'action' => null,
            'object_type' => null,
            'object_id' => null,
            'limit' => 100,
            'offset' => 0,
            'order_by' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $table_name = $wpdb->prefix . 'digitalogic_logs';
        $where = array('1=1');
        $where_values = array();
        
        if (!is_null($args['user_id'])) {
            $where[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }
        
        if (!is_null($args['action'])) {
            $where[] = 'action = %s';
            $where_values[] = $args['action'];
        }
        
        if (!is_null($args['object_type'])) {
            $where[] = 'object_type = %s';
            $where_values[] = $args['object_type'];
        }
        
        if (!is_null($args['object_id'])) {
            $where[] = 'object_id = %d';
            $where_values[] = $args['object_id'];
        }
        
        $where_clause = implode(' AND ', $where);
        
        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY {$args['order_by']} {$args['order']} LIMIT %d OFFSET %d";
        $where_values[] = $args['limit'];
        $where_values[] = $args['offset'];
        
        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $where_values);
        }
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Get client IP address
     * 
     * @return string
     */
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Clear old logs
     * 
     * @param int $days Days to keep
     * @return bool|int
     */
    public function clear_old_logs($days = 90) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'digitalogic_logs';
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE created_at < %s", $date)
        );
    }
}
