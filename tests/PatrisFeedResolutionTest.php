<?php

use PHPUnit\Framework\TestCase;

final class PatrisFeedResolutionTest extends TestCase {

    /** @var Digitalogic_Patris_Feed */
    private $feed;

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array('woocommerce_weight_unit' => 'kg');
        $GLOBALS['digitalogic_test_option_cache'] = array();
        $GLOBALS['digitalogic_test_posts'] = array();
        $GLOBALS['digitalogic_test_post_meta_cache'] = array();
        $GLOBALS['digitalogic_test_actions'] = array();
        $GLOBALS['digitalogic_test_action_callbacks'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

        $this->resetSingleton(Digitalogic_Product_Identifier_Resolver::class);
        $this->resetSingleton(Digitalogic_Patris_Feed::class);
        $this->feed = Digitalogic_Patris_Feed::instance();
    }

    public function test_patris_meta_only_match_updates_product_and_not_found_remains_in_normalized_snapshot(): void {
        $GLOBALS['digitalogic_test_posts'][701] = array(
            'post_type' => 'product',
            'meta' => array(
                '_digitalogic_patris_product_code' => 'PATRIS-ONLY',
                '_existing_sentinel' => 'keep-me',
            ),
        );
        $matched_row = array(
            'product_code' => 'PATRIS-ONLY',
            'name' => 'Patris-only product',
            'weight_grams' => 240,
            'total_stock' => 5,
            'final_price' => 2009410,
            'source_marker' => 'preserve-in-raw-snapshot',
        );
        $missing_row = array(
            'product_code' => 'NOT-IN-WOO',
            'name' => 'Upstream-only product',
            'source_marker' => 'still-reportable',
        );

        $result = $this->feed->import_payload(array('products' => array($matched_row, $missing_row)), 'test');

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['missing_in_woocommerce']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(array(701), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertSame('keep-me', $GLOBALS['digitalogic_test_posts'][701]['meta']['_existing_sentinel']);
        $this->assertSame('PATRIS-ONLY', $GLOBALS['digitalogic_test_posts'][701]['meta']['_digitalogic_patris_product_code']);
        $this->assertSame('0.24', $GLOBALS['digitalogic_test_wc_products'][701]->weight);

        $snapshot = get_option('digitalogic_patris_feed_products');
        $this->assertSame('preserve-in-raw-snapshot', $snapshot['PATRIS-ONLY']['raw']['source_marker']);
        $this->assertSame('still-reportable', $snapshot['NOT-IN-WOO']['raw']['source_marker']);
        $this->assertSame('Upstream-only product', $snapshot['NOT-IN-WOO']['name']);
    }

    public function test_cross_namespace_collision_is_ambiguous_and_neither_product_is_written(): void {
        $GLOBALS['digitalogic_test_posts'] = array(
            702 => array(
                'post_type' => 'product',
                'meta' => array('_sku' => 'COLLISION', '_existing_sentinel' => 'sku-target'),
            ),
            703 => array(
                'post_type' => 'product',
                'meta' => array('_digitalogic_patris_product_code' => 'COLLISION', '_existing_sentinel' => 'patris-nontarget'),
            ),
        );

        $result = $this->feed->import_payload(array('products' => array(array(
            'product_code' => 'COLLISION',
            'name' => 'Must remain ambiguous',
            'total_stock' => 2,
            'final_price' => 12345,
        ))), 'test');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(array('Skipped product because its exact Code/SKU is ambiguous.'), $result['errors']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertSame('sku-target', $GLOBALS['digitalogic_test_posts'][702]['meta']['_existing_sentinel']);
        $this->assertArrayNotHasKey('_digitalogic_patris_name', $GLOBALS['digitalogic_test_posts'][702]['meta']);
        $this->assertSame('patris-nontarget', $GLOBALS['digitalogic_test_posts'][703]['meta']['_existing_sentinel']);
        $this->assertArrayNotHasKey('_digitalogic_patris_name', $GLOBALS['digitalogic_test_posts'][703]['meta']);
        $this->assertSame('Must remain ambiguous', get_option('digitalogic_patris_feed_products')['COLLISION']['name']);
    }

    public function test_ambiguous_and_invalid_identifiers_fail_safely_without_product_writes_but_remain_reportable(): void {
        $GLOBALS['digitalogic_test_posts'] = array(
            704 => array('post_type' => 'product', 'meta' => array('_sku' => 'AMBIGUOUS', '_existing_sentinel' => 'first')),
            705 => array('post_type' => 'product_variation', 'meta' => array('_sku' => 'AMBIGUOUS', '_existing_sentinel' => 'second')),
        );
        $invalid_code = str_repeat('X', 192);
        $ambiguous_row = array(
            'product_code' => 'AMBIGUOUS',
            'name' => 'Must not write',
            'source_marker' => 'ambiguous-snapshot',
        );
        $invalid_row = array(
            'product_code' => $invalid_code,
            'name' => 'Invalid identifier',
            'source_marker' => 'invalid-snapshot',
        );

        $result = $this->feed->import_payload(array('products' => array($ambiguous_row, $invalid_row)), 'test');

        $this->assertSame(2, $result['total']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['missing_in_woocommerce']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(array(
            'Skipped product because its exact Code/SKU is ambiguous.',
            'Skipped product because its Code/SKU could not be resolved.',
        ), $result['errors']);
        $this->assertSame(array(), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertSame('first', $GLOBALS['digitalogic_test_posts'][704]['meta']['_existing_sentinel']);
        $this->assertSame('second', $GLOBALS['digitalogic_test_posts'][705]['meta']['_existing_sentinel']);
        $this->assertArrayNotHasKey('_digitalogic_patris_name', $GLOBALS['digitalogic_test_posts'][704]['meta']);
        $this->assertArrayNotHasKey('_digitalogic_patris_name', $GLOBALS['digitalogic_test_posts'][705]['meta']);

        $snapshot = get_option('digitalogic_patris_feed_products');
        $this->assertSame('ambiguous-snapshot', $snapshot['AMBIGUOUS']['raw']['source_marker']);
        $this->assertSame('invalid-snapshot', $snapshot[$invalid_code]['raw']['source_marker']);
    }

    private function resetSingleton($class) {
        $property = new ReflectionProperty($class, 'instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
