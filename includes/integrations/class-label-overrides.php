<?php
/**
 * Small display-time wording overrides for storefront product labels.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Digitalogic_Label_Overrides {
    private const BRAND_LABEL = 'پارت نامبر:';
    private const SKU_LABEL = 'کد کالا:';
    private const PRODUCT_CODE_LABEL = 'کد کالا';
    private const PRODUCT_SERIAL_LABEL = 'سریال کالا';

    public static function init(): void {
        add_filter('gettext', [self::class, 'gettext'], 20, 3);
        add_filter('ngettext', [self::class, 'ngettext'], 20, 5);
        add_filter('the_content', [self::class, 'replace_labels_in_html'], 99);
        add_filter('widget_text', [self::class, 'replace_labels_in_html'], 99);
        add_filter('elementor/widget/render_content', [self::class, 'replace_labels_in_html'], 99);
        add_filter('acf/load_field', [self::class, 'replace_acf_field_label'], 20);
        add_filter('woocommerce_attribute_label', [self::class, 'replace_woocommerce_attribute_label'], 20, 3);
        add_filter('woocommerce_get_item_data', [self::class, 'replace_cart_item_data_labels'], 20, 2);
        add_filter('woocommerce_order_item_display_meta_key', [self::class, 'replace_order_item_meta_key'], 20, 3);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_styles'], 30);
    }

    public static function gettext(string $translation, string $text, string $domain): string {
        if (!self::is_allowed_domain($domain)) {
            return $translation;
        }

        $identity_label = self::replace_product_identity_label($translation);
        if ($identity_label !== $translation) {
            return $identity_label;
        }

        $identity_label = self::replace_product_identity_label($text);
        if ($identity_label !== $text) {
            return $identity_label;
        }

        if (self::is_brand_label($translation, $text)) {
            return self::BRAND_LABEL;
        }

        if (self::is_sku_label($translation, $text)) {
            return self::SKU_LABEL;
        }

        return $translation;
    }

    public static function ngettext(string $translation, string $single, string $plural, int $number, string $domain): string {
        if (!self::is_allowed_domain($domain)) {
            return $translation;
        }

        foreach ([$translation, $single, $plural] as $candidate) {
            $identity_label = self::replace_product_identity_label($candidate);
            if ($identity_label !== $candidate) {
                return $identity_label;
            }
        }

        if (self::is_brand_label($translation, $single) || self::is_brand_label($translation, $plural)) {
            return self::BRAND_LABEL;
        }

        if (self::is_sku_label($translation, $single) || self::is_sku_label($translation, $plural)) {
            return self::SKU_LABEL;
        }

        return $translation;
    }

    public static function replace_labels_in_html(string $html): string {
        if ($html === '') {
            return $html;
        }

        $html = self::replace_product_identity_label($html);

        return str_replace(
            [
                'نام تجاری :',
                'نام تجاری:',
                'پارت نامبر :',
                'شناسه محصول :',
                'شناسه محصول:',
                'کد کالا :',
            ],
            [
                self::BRAND_LABEL,
                self::BRAND_LABEL,
                self::BRAND_LABEL,
                self::SKU_LABEL,
                self::SKU_LABEL,
                self::SKU_LABEL,
            ],
            $html
        );
    }

    /**
     * Replace source-system wording only where it is used as a product label.
     *
     * Internal meta keys and protocol fields deliberately remain unchanged.
     */
    public static function replace_product_identity_label(string $label): string {
        return str_replace(
            [
                'کد پاتریس',
                'سریال پاتریس',
            ],
            [
                self::PRODUCT_CODE_LABEL,
                self::PRODUCT_SERIAL_LABEL,
            ],
            $label
        );
    }

    /**
     * Normalize an existing ACF field label without creating or renaming data.
     *
     * @param mixed $field ACF field definition.
     * @return mixed
     */
    public static function replace_acf_field_label($field) {
        if (!is_array($field) || !isset($field['label']) || !is_string($field['label'])) {
            return $field;
        }

        $field['label'] = self::replace_product_identity_label($field['label']);

        return $field;
    }

    /**
     * Normalize custom product-attribute labels in Additional Information.
     */
    public static function replace_woocommerce_attribute_label(string $label, string $name = '', $product = null): string {
        unset($name, $product);

        return self::replace_product_identity_label($label);
    }

    /**
     * Normalize existing cart/checkout item-data labels without touching values.
     *
     * @param mixed $item_data Display item-data rows.
     * @param mixed $cart_item Cart item.
     * @return mixed
     */
    public static function replace_cart_item_data_labels($item_data, $cart_item = null) {
        unset($cart_item);
        if (!is_array($item_data)) {
            return $item_data;
        }

        foreach ($item_data as &$row) {
            if (is_array($row) && isset($row['key']) && is_string($row['key'])) {
                $row['key'] = self::replace_product_identity_label($row['key']);
            }
        }
        unset($row);

        return $item_data;
    }

    /**
     * Normalize existing order and invoice item metadata labels.
     */
    public static function replace_order_item_meta_key(string $display_key, $meta = null, $item = null): string {
        unset($meta, $item);

        return self::replace_product_identity_label($display_key);
    }

    public static function enqueue_frontend_styles(): void {
        if (is_admin() || !function_exists('is_product')) {
            return;
        }

        $font_uri = trailingslashit(get_stylesheet_directory_uri()) . 'fonts/woff2/' . rawurlencode('IRANSansWeb(FaNum).woff2');
        $css = '@font-face{font-family:"DigitalogicStoreFanum";src:url("' . esc_url_raw($font_uri) . '") format("woff2");font-weight:400;font-style:normal;font-display:swap;}'
            . '.woocommerce-Price-amount,.woocommerce-Price-amount.amount,.price,.product p.price,.product span.price,.wd-product .price,.wd-product .amount{font-family:"DigitalogicStoreFanum","IRANSans",Tahoma,Arial,sans-serif!important;font-variant-numeric:normal;}';

        wp_register_style('digitalogic-store-label-overrides', false, [], defined('DIGITALOGIC_VERSION') ? DIGITALOGIC_VERSION : null);
        wp_enqueue_style('digitalogic-store-label-overrides');
        wp_add_inline_style('digitalogic-store-label-overrides', $css);
    }

    private static function is_allowed_domain(string $domain): bool {
        return in_array($domain, ['woocommerce', 'woodmart', 'digitalogic', 'acf', 'elementor', 'elementor-pro'], true);
    }

    private static function is_brand_label(string $translation, string $source): bool {
        return in_array(self::normalize_label($source), ['brand', 'brands'], true)
            || in_array(self::normalize_label($translation), ['نامتجاری', 'برند', 'برندها'], true);
    }

    private static function is_sku_label(string $translation, string $source): bool {
        return in_array(self::normalize_label($source), ['sku'], true)
            || in_array(self::normalize_label($translation), ['شناسهمحصول', 'کدانحصاریمحصول'], true);
    }

    private static function normalize_label(string $value): string {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
        $value = str_replace(["\xc2\xa0", ':', '：', ' ', "\t", "\r", "\n"], '', $value);

        return strtolower(trim($value));
    }
}
