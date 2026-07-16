<?php

use PHPUnit\Framework\TestCase;

final class UnitConverterTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['digitalogic_test_options'] = array();
        $GLOBALS['digitalogic_test_option_cache'] = array();
    }

    public function test_grams_are_preserved_for_a_gram_store(): void {
        $GLOBALS['digitalogic_test_options']['woocommerce_weight_unit'] = 'g';

        $this->assertSame(240.0, Digitalogic_Unit_Converter::grams_to_store_weight(240));
    }

    public function test_grams_are_converted_to_kilograms_for_a_kilogram_store(): void {
        $GLOBALS['digitalogic_test_options']['woocommerce_weight_unit'] = 'kg';

        $this->assertSame(0.24, Digitalogic_Unit_Converter::grams_to_store_weight(240));
    }

    public function test_invalid_non_finite_and_non_positive_weights_are_rejected(): void {
        foreach (array(null, '', 'not-a-number', 0, -1, INF, NAN) as $weight) {
            $this->assertNull(Digitalogic_Unit_Converter::grams_to_store_weight($weight));
        }
    }

    public function test_patris_feed_uses_the_canonical_store_unit_converter(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-patris-feed.php');

        $this->assertStringContainsString('Digitalogic_Unit_Converter::grams_to_store_weight', $source);
        $this->assertStringNotContainsString("weight_grams'] / 1000", $source);
    }
}
