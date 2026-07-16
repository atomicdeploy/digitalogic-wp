<?php

use PHPUnit\Framework\TestCase;

final class ProductIdentifierResolverTest extends TestCase {

    /** @var Digitalogic_Product_Identifier_Resolver */
    private $resolver;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_posts'] = array(
            601 => array(
                'post_type' => 'product',
                'meta' => array('_sku' => 'SKU-601', '_digitalogic_patris_product_code' => 'PATRIS-601'),
            ),
            602 => array(
                'post_type' => 'product_variation',
                'post_status' => 'draft',
                'meta' => array('_sku' => 'SKU-602', '_digitalogic_patris_product_code' => 'PATRIS-602'),
            ),
            603 => array('post_type' => 'product', 'meta' => array('_sku' => 'DUP-SKU')),
            604 => array('post_type' => 'product_variation', 'meta' => array('_sku' => 'DUP-SKU')),
            605 => array('post_type' => 'product', 'meta' => array('_digitalogic_patris_product_code' => 'DUP-PATRIS')),
            606 => array('post_type' => 'product_variation', 'meta' => array('_digitalogic_patris_product_code' => 'DUP-PATRIS')),
            607 => array('post_type' => 'product', 'meta' => array('_sku' => 'CROSS')),
            608 => array('post_type' => 'product', 'meta' => array('_digitalogic_patris_product_code' => 'CROSS')),
            609 => array('post_type' => 'product', 'meta' => array('_sku' => '00123')),
            610 => array(
                'post_type' => 'product',
                'meta' => array(),
                'meta_rows' => array('_sku' => array('STALE-SKU', 'CURRENT-SKU')),
            ),
            611 => array('post_type' => 'product', 'post_status' => 'trash', 'meta' => array('_sku' => 'TRASH-SKU')),
            612 => array('post_type' => 'product_variation', 'post_status' => 'auto-draft', 'meta' => array('_sku' => 'AUTO-SKU')),
            700 => array('post_type' => 'post', 'meta' => array('_sku' => 'NOT-A-PRODUCT')),
        );
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

        $instance = new ReflectionProperty(Digitalogic_Product_Identifier_Resolver::class, 'instance');
        $instance->setValue(null, null);
        $this->resolver = Digitalogic_Product_Identifier_Resolver::instance();
    }

    public function test_supplied_identifier_precedence_prefers_explicit_woo_id_then_sku_then_patris_code(): void {
        $input = array(
            'woocommerce_id' => '602',
            'sku' => 'SKU-601',
            'patris_code' => 'PATRIS-601',
        );
        $before = $input;

        $resolved = $this->resolver->resolve($input);

        $this->assertSame('602', $resolved['woocommerce_id']);
        $this->assertSame('product_variation', $resolved['post_type']);
        $this->assertSame('woocommerce_id', $resolved['resolved_by']);
        $this->assertSame('602', $resolved['identifier']);
        $this->assertSame($before, $input);
        $this->assertSame(1, $GLOBALS['wpdb']->identifier_query_count);

        $GLOBALS['wpdb']->identifier_query_count = 0;
        $resolved = $this->resolver->resolve(array('sku' => 'SKU-601', 'patris_code' => 'PATRIS-602'));
        $this->assertSame('601', $resolved['woocommerce_id']);
        $this->assertSame('sku', $resolved['resolved_by']);
        $this->assertSame(1, $GLOBALS['wpdb']->identifier_query_count);
    }

    public function test_generic_code_rejects_cross_namespace_collision_and_uses_exact_sku_fallback(): void {
        $cross = $this->resolver->resolve(array('code' => 'CROSS'));
        $numeric_looking = $this->resolver->resolve(array('code' => '00123'));
        $wrong_case = $this->resolver->resolve(array('code' => 'sku-601'));

        $this->assertSame('digitalogic_product_identifier_ambiguous', $cross->get_error_code());
        $this->assertSame('cross_namespace_collision', $cross->get_error_data()['reason']);
        $this->assertSame(array('607', '608'), $cross->get_error_data()['woocommerce_ids']);
        $this->assertSame('609', $numeric_looking['woocommerce_id']);
        $this->assertSame('sku_fallback', $numeric_looking['resolved_by']);
        $this->assertSame('00123', $numeric_looking['identifier']);
        $this->assertSame('digitalogic_product_identifier_not_found', $wrong_case->get_error_code());
        $this->assertSame(3, $GLOBALS['wpdb']->identifier_query_count);
    }

    public function test_non_woo_identifiers_require_strings_and_latest_duplicate_meta_row_is_deterministic(): void {
        foreach (array('sku', 'patris_code', 'code') as $field) {
            $invalid = $this->resolver->resolve(array($field => 601));
            $this->assertSame('digitalogic_invalid_product_identifier', $invalid->get_error_code());
        }

        $stale = $this->resolver->resolve(array('sku' => 'STALE-SKU'));
        $current = $this->resolver->resolve(array('sku' => 'CURRENT-SKU'));
        $trash = $this->resolver->resolve(array('sku' => 'TRASH-SKU'));
        $auto_draft = $this->resolver->resolve(array('sku' => 'AUTO-SKU'));

        $this->assertSame('digitalogic_product_identifier_not_found', $stale->get_error_code());
        $this->assertSame('610', $current['woocommerce_id']);
        $this->assertSame('digitalogic_product_identifier_not_found', $trash->get_error_code());
        $this->assertSame('digitalogic_product_identifier_not_found', $auto_draft->get_error_code());
    }

    public function test_same_source_duplicates_are_ambiguous_for_products_and_variations(): void {
        $sku = $this->resolver->resolve(array('sku' => 'DUP-SKU'));
        $patris = $this->resolver->resolve(array('patris_code' => 'DUP-PATRIS'));

        $this->assertSame('digitalogic_product_identifier_ambiguous', $sku->get_error_code());
        $this->assertSame(array('603', '604'), $sku->get_error_data()['woocommerce_ids']);
        $this->assertSame('digitalogic_product_identifier_ambiguous', $patris->get_error_code());
        $this->assertSame(array('605', '606'), $patris->get_error_data()['woocommerce_ids']);
    }

    public function test_invalid_or_non_product_explicit_ids_fail_without_fallback(): void {
        $integer_id = $this->resolver->resolve(array('woocommerce_id' => 602));
        $this->assertSame('602', $integer_id['woocommerce_id']);
        $this->assertSame('product_variation', $integer_id['post_type']);

        foreach (array('0', '0601', '601x', '', array('601')) as $invalid) {
            $result = $this->resolver->resolve(array(
                'woocommerce_id' => $invalid,
                'sku' => 'SKU-601',
            ));
            $this->assertSame('digitalogic_invalid_product_identifier', $result->get_error_code());
        }

        $not_product = $this->resolver->resolve(array('woocommerce_id' => '700', 'sku' => 'SKU-601'));
        $this->assertSame('digitalogic_product_identifier_not_found', $not_product->get_error_code());
    }
}
