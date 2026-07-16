<?php
/**
 * Shared exact product identifier resolver.
 *
 * Identifiers remain strings at the service boundary. Resolution is exact,
 * variation-aware, and deliberately never falls back to fuzzy/name matching.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Digitalogic_Product_Identifier_Resolver {

    public const PATRIS_CODE_META = '_digitalogic_patris_product_code';
    public const SKU_META = '_sku';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Resolve the highest-precedence supplied identifier.
     *
     * Precedence is explicit WooCommerce ID, exact SKU, exact Patris Code,
     * then the collision-safe generic exact code adapter.
     *
     * @param array $identifiers String identifiers.
     * @return array|WP_Error
     */
    public function resolve($identifiers) {
        if (!is_array($identifiers)) {
            return $this->invalid('Product identifiers must be supplied as an object.');
        }

        if (array_key_exists('woocommerce_id', $identifiers) || array_key_exists('product_id', $identifiers)) {
            $value = array_key_exists('woocommerce_id', $identifiers)
                ? $identifiers['woocommerce_id']
                : $identifiers['product_id'];
            $identifier = $this->normalize_identifier($value, 'WooCommerce product ID', true);
            if (is_wp_error($identifier)) {
                return $identifier;
            }
            if (!$this->is_canonical_positive_integer($identifier)) {
                return $this->invalid('WooCommerce product ID must be a canonical positive integer string.');
            }

            return $this->resolve_woocommerce_id($identifier);
        }

        if (array_key_exists('sku', $identifiers)) {
            $sku = $this->normalize_identifier($identifiers['sku'], 'SKU');
            return is_wp_error($sku) ? $sku : $this->resolve_meta(self::SKU_META, 'sku', $sku);
        }

        if (array_key_exists('patris_code', $identifiers)) {
            $code = $this->normalize_identifier($identifiers['patris_code'], 'Patris Code');
            return is_wp_error($code) ? $code : $this->resolve_meta(self::PATRIS_CODE_META, 'patris_code', $code);
        }

        if (array_key_exists('code', $identifiers)) {
            $code = $this->normalize_identifier($identifiers['code'], 'Code/SKU');
            return is_wp_error($code) ? $code : $this->resolve_code($code);
        }

        return $this->invalid('A WooCommerce ID, SKU, Patris Code, or generic Code/SKU is required.');
    }

    /**
     * Resolve a generic Patris code without crossing identifier namespaces.
     *
     * Patris Code is canonical. SKU is only a compatibility fallback when no
     * exact Patris Code exists. If the same text names a Patris Code target and
     * a distinct SKU target, the identifier is ambiguous and no write is safe.
     */
    public function resolve_code($code) {
        $code = $this->normalize_identifier($code, 'Code/SKU');
        if (is_wp_error($code)) {
            return $code;
        }

        global $wpdb;
        $postmeta = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $current_sku = "COALESCE((SELECT pm_sku_match.meta_value FROM {$postmeta} pm_sku_match
            WHERE pm_sku_match.post_id = p.ID AND pm_sku_match.meta_key = '" . self::SKU_META . "'
            ORDER BY pm_sku_match.meta_id DESC LIMIT 1), '')";
        $current_patris = "COALESCE((SELECT pm_patris_match.meta_value FROM {$postmeta} pm_patris_match
            WHERE pm_patris_match.post_id = p.ID AND pm_patris_match.meta_key = '" . self::PATRIS_CODE_META . "'
            ORDER BY pm_patris_match.meta_id DESC LIMIT 1), '')";
        $rows = $this->query_rows(
            'code',
            "(BINARY {$current_sku} = BINARY %s OR BINARY {$current_patris} = BINARY %s)",
            array($code, $code)
        );
        if (is_wp_error($rows)) {
            return $rows;
        }

        $sku_matches = array();
        $patris_matches = array();
        foreach ((array) $rows as $row) {
            if ((string) $row['sku'] === $code) {
                $sku_matches[] = $row;
            }
            if ((string) $row['patris_code'] === $code) {
                $patris_matches[] = $row;
            }
        }

        if (!empty($patris_matches)) {
            if (count($patris_matches) > 1) {
                return $this->ambiguous($patris_matches, 'patris_code', 'duplicate_patris_code');
            }

            $patris_match = reset($patris_matches);
            $distinct_sku_matches = array_values(array_filter($sku_matches, static function($row) use ($patris_match) {
                return (string) $row['ID'] !== (string) $patris_match['ID'];
            }));
            if (!empty($distinct_sku_matches)) {
                return $this->ambiguous(
                    array_merge(array($patris_match), $distinct_sku_matches),
                    'patris_code',
                    'cross_namespace_collision'
                );
            }

            return $this->format_match($patris_match, 'patris_code', $code);
        }
        if (!empty($sku_matches)) {
            return $this->one_or_ambiguous($sku_matches, 'sku_fallback', $code);
        }

        return $this->not_found('No product has that exact Code or SKU.');
    }

    private function resolve_woocommerce_id($woocommerce_id) {
        $rows = $this->query_rows('woocommerce_id', 'p.ID = %d', array((int) $woocommerce_id));
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (empty($rows)) {
            return $this->not_found('No product or variation has that exact WooCommerce ID.');
        }

        return $this->format_match(reset($rows), 'woocommerce_id', $woocommerce_id);
    }

    private function resolve_meta($meta_key, $resolved_by, $value) {
        global $wpdb;
        $postmeta = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $current_value = "COALESCE((SELECT pm_match.meta_value FROM {$postmeta} pm_match
            WHERE pm_match.post_id = p.ID AND pm_match.meta_key = %s
            ORDER BY pm_match.meta_id DESC LIMIT 1), '')";
        $rows = $this->query_rows(
            $resolved_by,
            "BINARY {$current_value} = BINARY %s",
            array($meta_key, $value)
        );
        if (is_wp_error($rows)) {
            return $rows;
        }

        // Defend exactness again in PHP in case a database collation or test
        // adapter returns a broader row set than the BINARY predicate.
        $column = self::SKU_META === $meta_key ? 'sku' : 'patris_code';
        $rows = array_values(array_filter((array) $rows, static function($row) use ($column, $value) {
            return isset($row[$column]) && (string) $row[$column] === $value;
        }));

        if (empty($rows)) {
            return $this->not_found('No product has that exact identifier.');
        }

        return $this->one_or_ambiguous($rows, $resolved_by, $value);
    }

    /**
     * Fetch one deterministic current SKU and Patris Code per candidate in a
     * single query. Latest meta_id wins when corrupted duplicate rows exist.
     */
    private function query_rows($marker, $predicate, $args) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return $this->query_failed();
        }
        $posts = isset($wpdb->posts) ? $wpdb->posts : $wpdb->prefix . 'posts';
        $postmeta = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $query = "/* digitalogic_identifier:{$marker} */
            SELECT p.ID, p.post_type,
                COALESCE((
                    SELECT pm_sku.meta_value FROM {$postmeta} pm_sku
                    WHERE pm_sku.post_id = p.ID AND pm_sku.meta_key = '" . self::SKU_META . "'
                    ORDER BY pm_sku.meta_id DESC LIMIT 1
                ), '') AS sku,
                COALESCE((
                    SELECT pm_patris.meta_value FROM {$postmeta} pm_patris
                    WHERE pm_patris.post_id = p.ID AND pm_patris.meta_key = '" . self::PATRIS_CODE_META . "'
                    ORDER BY pm_patris.meta_id DESC LIMIT 1
                ), '') AS patris_code
            FROM {$posts} p
            WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status NOT IN ('trash', 'auto-draft')
                AND {$predicate}
            ORDER BY p.ID ASC";

        try {
            $prepared = $wpdb->prepare($query, ...$args);
            if (false === $prepared || null === $prepared || '' === $prepared) {
                return $this->query_failed();
            }
            $rows = $wpdb->get_results($prepared, ARRAY_A);
        } catch (Throwable) {
            return $this->query_failed();
        }

        if (!is_array($rows) || '' !== trim((string) ($wpdb->last_error ?? ''))) {
            return $this->query_failed();
        }

        return $rows;
    }

    private function one_or_ambiguous($rows, $resolved_by, $value) {
        if (count($rows) > 1) {
            return $this->ambiguous($rows, $resolved_by, 'duplicate_identifier');
        }

        return $this->format_match(reset($rows), $resolved_by, $value);
    }

    private function ambiguous($rows, $resolved_by, $reason) {
        $ids = array_values(array_unique(array_map(static function($row) {
            return (string) $row['ID'];
        }, $rows)));
        sort($ids, SORT_STRING);

        return new WP_Error(
            'digitalogic_product_identifier_ambiguous',
            __('More than one product has that exact identifier.', 'digitalogic'),
            array(
                'status' => 409,
                'resolved_by' => $resolved_by,
                'reason' => (string) $reason,
                'woocommerce_ids' => $ids,
            )
        );
    }

    private function format_match($row, $resolved_by, $identifier) {
        return array(
            'woocommerce_id' => (string) $row['ID'],
            'post_type' => (string) $row['post_type'],
            'sku' => isset($row['sku']) ? (string) $row['sku'] : '',
            'patris_code' => isset($row['patris_code']) ? (string) $row['patris_code'] : '',
            'identifier' => (string) $identifier,
            'resolved_by' => (string) $resolved_by,
        );
    }

    private function normalize_identifier($value, $label, $allow_integer = false) {
        if (!is_string($value) && !($allow_integer && is_int($value))) {
            return $this->invalid($label . ' must be a string.');
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return $this->invalid($label . ' is empty or invalid.');
        }

        return $value;
    }

    private function is_canonical_positive_integer($value) {
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;
        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }

    private function invalid($message) {
        return new WP_Error(
            'digitalogic_invalid_product_identifier',
            __($message, 'digitalogic'),
            array('status' => 400)
        );
    }

    private function not_found($message) {
        return new WP_Error(
            'digitalogic_product_identifier_not_found',
            __($message, 'digitalogic'),
            array('status' => 404)
        );
    }

    private function query_failed() {
        return new WP_Error(
            'digitalogic_product_identifier_query_failed',
            __('The product identifier lookup could not be completed.', 'digitalogic'),
            array(
                'status' => 503,
                'retryable' => true,
            )
        );
    }
}
