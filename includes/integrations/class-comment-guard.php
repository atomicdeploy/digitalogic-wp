<?php
/**
 * Migrated comment reputation guard.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Digitalogic_Comment_Guard {

    private const SCORE_THRESHOLD = 25;
    private const SFS_CONFIDENCE_THRESHOLD = 70;
    private const LOG_FILE = '/var/log/wordpress-comment-abuseipdb.log';
    private const FAIL2BAN_CONFIG = '/etc/fail2ban/jail.local';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        if ($this->is_enabled()) {
            add_filter('preprocess_comment', array($this, 'check_reputation'), 1);
        }
    }

    public function check_reputation($commentdata) {
        if (current_user_can('moderate_comments')) {
            return $commentdata;
        }

        $ip = $this->client_ip();
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->log('ALLOW', 'missing-ip', '', $commentdata);
            return $commentdata;
        }

        $email = isset($commentdata['comment_author_email']) ? sanitize_email($commentdata['comment_author_email']) : '';
        $abuse = $this->abuseipdb_check($ip);
        $sfs = $this->stopforumspam_check($ip, $email);

        $reasons = array();
        if (!empty($abuse['block'])) {
            $reasons[] = 'abuseipdb-score-' . (int) $abuse['score'];
        }
        if (!empty($sfs['block'])) {
            $reasons[] = 'stopforumspam-confidence-' . (int) $sfs['confidence'];
        }

        if ($reasons) {
            $this->log('BLOCK', implode(',', $reasons), $ip, $commentdata, $abuse, $sfs);
            wp_die(
                esc_html__('Your comment could not be accepted from this network.', 'digitalogic'),
                esc_html__('Comment blocked', 'digitalogic'),
                array('response' => 403)
            );
        }

        $this->log('ALLOW', 'clean', $ip, $commentdata, $abuse, $sfs);
        return $commentdata;
    }

    private function is_enabled() {
        return (bool) apply_filters(
            'digitalogic_comment_guard_enabled',
            defined('DIGITALOGIC_COMMENT_GUARD_ENABLED') && DIGITALOGIC_COMMENT_GUARD_ENABLED
        );
    }

    private function client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        $cf_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';

        if ($cf_ip && filter_var($cf_ip, FILTER_VALIDATE_IP) && $this->is_cloudflare_ip($remote)) {
            return $cf_ip;
        }

        return $remote;
    }

    private function abuseipdb_check($ip) {
        $cache_key = 'dlogic_abuseipdb_' . md5($ip);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $key = $this->abuseipdb_key();
        if (!$key) {
            return array('source' => 'abuseipdb', 'error' => 'missing-key', 'block' => false, 'score' => 0);
        }

        $response = wp_remote_get(add_query_arg(array(
            'ipAddress' => $ip,
            'maxAgeInDays' => 90,
        ), 'https://api.abuseipdb.com/api/v2/check'), array(
            'timeout' => 4,
            'headers' => array(
                'Accept' => 'application/json',
                'Key' => $key,
            ),
        ));

        if (is_wp_error($response)) {
            return array('source' => 'abuseipdb', 'error' => $response->get_error_code(), 'block' => false, 'score' => 0);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $score = isset($body['data']['abuseConfidenceScore']) ? (int) $body['data']['abuseConfidenceScore'] : 0;
        $result = array(
            'source' => 'abuseipdb',
            'block' => $score >= self::SCORE_THRESHOLD,
            'score' => $score,
            'reports' => isset($body['data']['totalReports']) ? (int) $body['data']['totalReports'] : 0,
        );

        set_transient($cache_key, $result, $result['block'] ? WEEK_IN_SECONDS : DAY_IN_SECONDS);
        return $result;
    }

    private function stopforumspam_check($ip, $email) {
        $cache_key = 'dlogic_sfs_' . md5($ip . '|' . $email);
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $args = array('ip' => $ip, 'f' => 'json');
        if ($email) {
            $args['email'] = $email;
        }

        $response = wp_remote_get(add_query_arg($args, 'https://api.stopforumspam.org/api'), array('timeout' => 4));
        if (is_wp_error($response)) {
            return array('source' => 'stopforumspam', 'error' => $response->get_error_code(), 'block' => false, 'confidence' => 0);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $ip_confidence = isset($body['ip']['confidence']) ? (float) $body['ip']['confidence'] : 0;
        $email_confidence = isset($body['email']['confidence']) ? (float) $body['email']['confidence'] : 0;
        $confidence = max($ip_confidence, $email_confidence);

        $result = array(
            'source' => 'stopforumspam',
            'block' => $confidence >= self::SFS_CONFIDENCE_THRESHOLD,
            'confidence' => $confidence,
            'ip_appears' => !empty($body['ip']['appears']),
            'email_appears' => !empty($body['email']['appears']),
        );

        set_transient($cache_key, $result, $result['block'] ? WEEK_IN_SECONDS : DAY_IN_SECONDS);
        return $result;
    }

    private function abuseipdb_key() {
        if (defined('ABUSEIPDB_API_KEY') && ABUSEIPDB_API_KEY) {
            return ABUSEIPDB_API_KEY;
        }

        if (!is_readable(self::FAIL2BAN_CONFIG)) {
            return '';
        }

        $config = file_get_contents(self::FAIL2BAN_CONFIG);
        if (!$config || !preg_match('/abuseipdb_apikey\s*=\s*([a-f0-9]{64})/i', $config, $matches)) {
            return '';
        }

        return $matches[1];
    }

    private function log($decision, $reason, $ip, $commentdata, $abuse = array(), $sfs = array()) {
        $post_id = isset($commentdata['comment_post_ID']) ? (int) $commentdata['comment_post_ID'] : 0;
        $abuse_score = isset($abuse['score']) ? (int) $abuse['score'] : 0;
        $sfs_confidence = isset($sfs['confidence']) ? (float) $sfs['confidence'] : 0;
        $line = sprintf(
            "%s decision=%s ip=%s reason=%s post=%d abuseipdb_score=%d stopforumspam_confidence=%.1f\n",
            gmdate('Y-m-d H:i:s'),
            $decision,
            $ip ? $ip : '-',
            preg_replace('/[^a-zA-Z0-9,._-]/', '-', $reason),
            $post_id,
            $abuse_score,
            $sfs_confidence
        );

        error_log($line, 3, self::LOG_FILE);
    }

    private function is_cloudflare_ip($ip) {
        if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ranges = array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
            '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
            '2a06:98c0::/29', '2c0f:f248::/32',
        );

        foreach ($ranges as $range) {
            if ($this->ip_in_cidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ip_in_cidr($ip, $cidr) {
        list($network, $prefix) = explode('/', $cidr, 2);
        $ip_bin = inet_pton($ip);
        $network_bin = inet_pton($network);

        if ($ip_bin === false || $network_bin === false || strlen($ip_bin) !== strlen($network_bin)) {
            return false;
        }

        $prefix = (int) $prefix;
        $bytes = intdiv($prefix, 8);
        $bits = $prefix % 8;

        if ($bytes && substr($ip_bin, 0, $bytes) !== substr($network_bin, 0, $bytes)) {
            return false;
        }

        if (!$bits) {
            return true;
        }

        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($ip_bin[$bytes]) & $mask) === (ord($network_bin[$bytes]) & $mask);
    }
}
