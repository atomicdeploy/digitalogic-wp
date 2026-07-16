<?php
/**
 * Canonical unit conversions shared by Patris ingestion and pricing flows.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Digitalogic_Unit_Converter {

    /**
     * Convert a positive gram value to the WooCommerce store weight unit.
     *
     * @param mixed $weight_grams Source weight in grams.
     * @return float|null Converted positive finite weight, or null when invalid.
     */
    public static function grams_to_store_weight($weight_grams) {
        if (!is_numeric($weight_grams)) {
            return null;
        }

        $weight_grams = (float) $weight_grams;
        if (!is_finite($weight_grams) || $weight_grams <= 0) {
            return null;
        }

        $store_unit = trim((string) get_option('woocommerce_weight_unit', 'kg'));
        if ($store_unit === '') {
            $store_unit = 'kg';
        }

        $converted = wc_get_weight($weight_grams, $store_unit, 'g');
        if (!is_numeric($converted)) {
            return null;
        }

        $converted = (float) $converted;
        return is_finite($converted) && $converted > 0 ? $converted : null;
    }
}
