<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use WC_Order;
use WC_Product;
use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable optimistic-concurrency tokens. Tokens include only fields controlled
 * by the viewer action allowlist.
 */
final class Revision
{
    public static function hash(array $value): string
    {
        self::ksort_recursive($value);
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return 'rev:' . hash('sha256', is_string($encoded) ? $encoded : serialize($value));
    }

    public static function category(WP_Term $term): string
    {
        return self::hash(
            array(
                'id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent' => (int) $term->parent,
                'description' => (string) $term->description,
                'patrisCode' => (string) get_term_meta(
                    $term->term_id,
                    '_digitalogic_patris_category_code',
                    true
                ),
                'iconKey' => (string) get_term_meta(
                    $term->term_id,
                    '_digitalogic_viewer_icon_key',
                    true
                ),
                'trashedAt' => (string) get_term_meta(
                    $term->term_id,
                    '_digitalogic_viewer_trashed_at',
                    true
                ),
            )
        );
    }

    public static function product(WC_Product $product): string
    {
        $modified = $product->get_date_modified();
        return self::hash(
            array(
                'id' => $product->get_id(),
                'parentId' => $product->get_parent_id(),
                'modifiedAt' => $modified ? $modified->date('c') : '',
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'status' => $product->get_status(),
                'stockStatus' => $product->get_stock_status(),
                'stockQuantity' => $product->get_stock_quantity(),
                'price' => (string) $product->get_price(),
                'regularPrice' => (string) $product->get_regular_price(),
                'salePrice' => (string) $product->get_sale_price(),
                'totalSales' => (int) $product->get_total_sales(),
                'imageId' => (int) $product->get_image_id(),
                'categoryIds' => array_values(array_map('intval', $product->get_category_ids())),
                'patrisCode' => (string) $product->get_meta(
                    '_digitalogic_patris_product_code',
                    true
                ),
            )
        );
    }

    public static function order(WC_Order $order): string
    {
        $modified = $order->get_date_modified();
        return self::hash(
            array(
                'id' => $order->get_id(),
                'modifiedAt' => $modified ? $modified->date('c') : '',
                'status' => $order->get_status(),
                'total' => (string) $order->get_total(),
                'currency' => $order->get_currency(),
            )
        );
    }

    public static function customer(int $user_id): string
    {
        $last_order = function_exists('wc_get_customer_last_order')
            ? wc_get_customer_last_order($user_id)
            : false;
        $last_modified = $last_order instanceof WC_Order
            ? $last_order->get_date_modified()
            : null;
        return self::hash(
            array(
                'id' => $user_id,
                'segment' => (string) get_user_meta(
                    $user_id,
                    '_digitalogic_viewer_segment',
                    true
                ),
                'orderCount' => function_exists('wc_get_customer_order_count')
                    ? (int) wc_get_customer_order_count($user_id)
                    : 0,
                'totalSpent' => function_exists('wc_get_customer_total_spent')
                    ? (string) wc_get_customer_total_spent($user_id)
                    : '0',
                'lastOrderId' => $last_order instanceof WC_Order
                    ? $last_order->get_id()
                    : 0,
                'lastOrderModifiedAt' => $last_modified
                    ? $last_modified->date('c')
                    : '',
            )
        );
    }

    private static function ksort_recursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::ksort_recursive($item);
            }
        }
        unset($item);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
    }
}
