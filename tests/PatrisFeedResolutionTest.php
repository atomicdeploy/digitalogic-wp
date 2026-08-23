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
		$GLOBALS['digitalogic_test_filters'] = array();
        $GLOBALS['digitalogic_test_wc_products'] = array();
        $GLOBALS['digitalogic_test_wc_product_saves'] = array();
        $GLOBALS['digitalogic_test_wc_after_save'] = null;
        $GLOBALS['digitalogic_test_update_failures'] = array();
        $GLOBALS['digitalogic_test_option_delete_failures'] = array();
        $GLOBALS['digitalogic_test_capabilities'] = array(
            'manage_woocommerce' => true,
            'edit_post' => true,
        );
        $GLOBALS['digitalogic_test_current_user_id'] = 17;
        $GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

        $this->resetSingleton(Digitalogic_Product_Identifier_Resolver::class);
		$this->resetSingleton( Digitalogic_Product_Sync_Receiver::class );
		$this->resetSingleton( Digitalogic_Product_Code_Editor::class );
		$this->resetSingleton( Digitalogic_Product_Code_Write_Guard::class );
		Digitalogic_Product_Code_Write_Guard::instance();
        $this->resetSingleton(Digitalogic_Patris_Feed::class);
        $this->feed = Digitalogic_Patris_Feed::instance();
    }

	/** Omitting products preserves ownership while an explicit empty list clears it. */
	public function test_product_snapshot_absence_and_explicit_empty_list_have_distinct_semantics(): void {
		$existing = array(
			'LEGACY-KEEP' => array(
				'product_code' => 'LEGACY-KEEP',
				'name'         => 'Existing source row',
			),
		);
		$GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = $existing;

		$customers_only = $this->feed->import_payload(
			array(
				'customers' => array(
					array( 'customer_code' => 'SAFE-AGGREGATE-TEST' ),
				),
			),
			'test'
		);

		$this->assertIsArray( $customers_only );
		$this->assertSame( $existing, get_option( 'digitalogic_patris_feed_products' ) );

		$explicit_empty = $this->feed->import_payload( array( 'products' => array() ), 'test' );

		$this->assertIsArray( $explicit_empty );
		$this->assertSame( array(), get_option( 'digitalogic_patris_feed_products' ) );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Snapshot persistence must verify before any canonical Product Code row write. */
	public function test_snapshot_persistence_failure_stops_before_product_writes(): void {
		$GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products'] = array(
			'LEGACY-OLD' => array( 'product_code' => 'LEGACY-OLD' ),
		);
		$GLOBALS['digitalogic_test_posts'][712] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => 'LEGACY-NEW' ),
		);
		$GLOBALS['digitalogic_test_update_failures'][] = 'digitalogic_patris_feed_products';

		$result = $this->feed->import_payload(
			array(
				'products' => array(
					array( 'product_code' => 'LEGACY-NEW', 'name' => 'Must not write' ),
				),
			),
			'test'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_products_snapshot_write_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
		$this->assertSame(
			array( 'LEGACY-OLD' => array( 'product_code' => 'LEGACY-OLD' ) ),
			$GLOBALS['digitalogic_test_options']['digitalogic_patris_feed_products']
		);
	}

	/** The accepted legacy ownership snapshot exists before the first row-save callback. */
	public function test_nested_owner_edit_during_row_save_is_blocked_by_published_source_ownership(): void {
		$GLOBALS['digitalogic_test_posts'][713] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => 'LEGACY-713' ),
		);
		$nested_result = null;
		$GLOBALS['digitalogic_test_wc_after_save'] = static function () use ( &$nested_result ) {
			$editor        = Digitalogic_Product_Code_Editor::instance();
			$nested_result = $editor->edit(
				array(
					'product_id'    => 713,
					'expected_code' => 'LEGACY-713',
					'product_code'  => 'OWNER-713',
					'if_match'      => $editor->revision_for( 713, 'LEGACY-713' ),
					'request_id'    => 'product-code:713:nested-feed-attempt',
				)
			);
		};

		$result = $this->feed->import_payload(
			array(
				'products' => array(
					array( 'product_code' => 'LEGACY-713', 'name' => 'Owned source row' ),
				),
			),
			'test'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['updated'] );
		$this->assertInstanceOf( WP_Error::class, $nested_result );
		$this->assertSame( 'digitalogic_product_code_source_managed', $nested_result->get_error_code() );
		$this->assertSame( 'LEGACY-713', get_post_meta( 713, '_digitalogic_patris_product_code', true ) );
		$this->assertArrayHasKey( 'LEGACY-713', get_option( 'digitalogic_patris_feed_products' ) );
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
        $this->assertSame('0.24', wc_get_product(701)->get_weight());

        $snapshot = get_option('digitalogic_patris_feed_products');
        $this->assertSame('preserve-in-raw-snapshot', $snapshot['PATRIS-ONLY']['raw']['source_marker']);
        $this->assertSame('still-reportable', $snapshot['NOT-IN-WOO']['raw']['source_marker']);
        $this->assertSame('Upstream-only product', $snapshot['NOT-IN-WOO']['name']);
    }

    public function test_sku_collision_does_not_override_the_exact_patris_code_target(): void {
        $GLOBALS['digitalogic_test_posts'] = array(
            702 => array(
                'post_type' => 'product',
                'meta' => array('_sku' => 'COLLISION', '_existing_sentinel' => 'sku-target'),
            ),
            703 => array(
                'post_type' => 'product',
                'meta' => array('_digitalogic_patris_product_code' => 'COLLISION', '_existing_sentinel' => 'patris-target'),
            ),
        );

        $result = $this->feed->import_payload(array('products' => array(array(
            'product_code' => 'COLLISION',
            'name' => 'Exact Patris target',
            'total_stock' => 2,
            'final_price' => 12345,
        ))), 'test');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array(703), $GLOBALS['digitalogic_test_wc_product_saves']);
        $this->assertSame('sku-target', $GLOBALS['digitalogic_test_posts'][702]['meta']['_existing_sentinel']);
        $this->assertArrayNotHasKey('_digitalogic_patris_name', $GLOBALS['digitalogic_test_posts'][702]['meta']);
        $this->assertSame('patris-target', $GLOBALS['digitalogic_test_posts'][703]['meta']['_existing_sentinel']);
        $this->assertSame('Exact Patris target', $GLOBALS['digitalogic_test_posts'][703]['meta']['_digitalogic_patris_name']);
        $this->assertSame('Exact Patris target', get_option('digitalogic_patris_feed_products')['COLLISION']['name']);
    }

	/** A stale pre-lock resolution cannot overwrite an intervening owner identity edit. */
	public function test_feed_revalidates_exact_binding_after_acquiring_source_lock(): void {
		$GLOBALS['digitalogic_test_posts'][709] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => 'SOURCE-709' ),
		);

		$GLOBALS['wpdb']->before_get_lock = static function () {
			$GLOBALS['digitalogic_test_posts'][709]['meta']['_digitalogic_patris_product_code'] = 'OWNER-EDITED-709';
			unset( $GLOBALS['digitalogic_test_wc_products'][709] );
		};

		$product = wc_get_product( 709 );
		$result  = $this->feed->apply_product_feed(
			$product,
			array(
				'product_code' => 'SOURCE-709',
				'name'         => 'Stale source row',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_product_binding_changed', $result->get_error_code() );
		$this->assertSame( 'OWNER-EDITED-709', $GLOBALS['digitalogic_test_posts'][709]['meta']['_digitalogic_patris_product_code'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** A trashed product remains the exact canonical owner until permanent deletion. */
	public function test_feed_preflight_rejects_a_code_owned_by_a_trashed_product_without_writing(): void {
		$GLOBALS['digitalogic_test_posts'][714] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array(
				'_digitalogic_patris_product_code' => 'SOURCE-714',
				'_existing_sentinel'               => 'unchanged',
			),
		);
		$GLOBALS['digitalogic_test_posts'][715] = array(
			'post_type'   => 'product_variation',
			'post_status' => 'trash',
			'meta'        => array( '_digitalogic_patris_product_code' => 'TRASH-OWNER-715' ),
		);

		$result = $this->feed->apply_product_feed(
			wc_get_product( 714 ),
			array(
				'product_code' => 'TRASH-OWNER-715',
				'name'         => 'Must never be applied',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_source_not_unique', $result->get_error_code() );
		$this->assertSame( array( 715 ), $result->get_error_data()['conflicting_product_ids'] );
		$this->assertSame( 'SOURCE-714', get_post_meta( 714, Digitalogic_Product_Code_Editor::META_KEY, true ) );
		$this->assertSame( 'unchanged', get_post_meta( 714, '_existing_sentinel', true ) );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** A metadata short-circuit cannot be counted as a successful source write. */
	public function test_feed_fails_when_canonical_write_does_not_pass_database_readback(): void {
		$GLOBALS['digitalogic_test_posts'][710] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array(
				'_digitalogic_patris_product_code' => 'SOURCE-710',
				'_digitalogic_patris_name'         => 'Existing source name',
				'_weight'                           => '1.25',
				'_manage_stock'                     => 'yes',
				'_stock'                            => 9,
				'_stock_status'                     => 'instock',
				'_regular_price'                    => '875000',
				'_sale_price'                       => '825000',
				'_price'                            => '825000',
			),
		);
		add_filter(
			'update_post_metadata',
			static function ( $check, $post_id, $key ) {
				return 710 === (int) $post_id && '_digitalogic_patris_product_code' === $key ? false : $check;
			},
			0,
			3
		);

		$result = $this->feed->apply_product_feed(
			wc_get_product( 710 ),
			array(
				'product_code' => 'SOURCE-710-NEW',
				'name'         => 'Must fail exact readback',
				'weight_grams' => 3000,
				'total_stock'  => 2,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_source_readback_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['effect_attempted'] );
		$this->assertTrue( $result->get_error_data()['rollback_verified'] );
		$this->assertSame( 'SOURCE-710', get_post_meta( 710, '_digitalogic_patris_product_code', true ) );
		$this->assertSame( 'Existing source name', get_post_meta( 710, '_digitalogic_patris_name', true ) );
		$this->assertSame( '1.25', get_post_meta( 710, '_weight', true ) );
		$this->assertSame( 'yes', get_post_meta( 710, '_manage_stock', true ) );
		$this->assertSame( 9, get_post_meta( 710, '_stock', true ) );
		$this->assertSame( 'instock', get_post_meta( 710, '_stock_status', true ) );
		$this->assertSame( '875000', get_post_meta( 710, '_regular_price', true ) );
		$this->assertSame( '825000', get_post_meta( 710, '_sale_price', true ) );
		$this->assertSame( '825000', get_post_meta( 710, '_price', true ) );
	}

	/** Duplicate canonical rows injected at save are rejected before lock release. */
	public function test_feed_fails_when_post_save_canonical_readback_is_duplicated(): void {
		$GLOBALS['digitalogic_test_posts'][711] = array(
			'post_type'   => 'product',
			'post_status' => 'publish',
			'meta'        => array( '_digitalogic_patris_product_code' => 'SOURCE-711' ),
		);
		$GLOBALS['digitalogic_test_wc_after_save'] = static function () {
			$GLOBALS['digitalogic_test_posts'][711]['meta_rows']['_digitalogic_patris_product_code'] = array( 'SOURCE-711', 'SOURCE-711' );
		};

		$result = $this->feed->apply_product_feed(
			wc_get_product( 711 ),
			array( 'product_code' => 'SOURCE-711', 'name' => 'Duplicate readback' )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_product_code_source_readback_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['rollback_verified'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_posts'][711]['meta_rows']['_digitalogic_patris_product_code'] ?? array() );
		$this->assertSame( 'SOURCE-711', get_post_meta( 711, '_digitalogic_patris_product_code', true ) );
	}

    public function test_ambiguous_and_invalid_identifiers_fail_safely_without_product_writes_but_remain_reportable(): void {
        $GLOBALS['digitalogic_test_posts'] = array(
            704 => array('post_type' => 'product', 'meta' => array('_digitalogic_patris_product_code' => 'AMBIGUOUS', '_existing_sentinel' => 'first')),
            705 => array('post_type' => 'product_variation', 'meta' => array('_digitalogic_patris_product_code' => 'AMBIGUOUS', '_existing_sentinel' => 'second')),
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
            'Skipped product because its exact Product Code is ambiguous.',
            'Skipped product because its Product Code could not be resolved.',
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

    public function test_selected_price_fields_preserve_missing_and_explicit_null_as_distinct_states(): void {
        $GLOBALS['digitalogic_test_posts'] = array(
            706 => array(
                'post_type' => 'product',
                'meta'      => array('_digitalogic_patris_product_code' => 'SPARSE-MISSING'),
            ),
            707 => array(
                'post_type' => 'product',
                'meta'      => array('_digitalogic_patris_product_code' => 'SPARSE-NULL'),
            ),
            708 => array(
                'post_type' => 'product',
                'meta'      => array('_digitalogic_patris_product_code' => 'SPARSE-INVALID'),
            ),
        );
        $selected_fields                   = array(
            'price_source_amount',
            'price_source_currency',
            'price_source_kind',
            'price_rounding_digits',
            'price_rounding_mode',
        );
        $explicit_null                     = array('product_code' => 'SPARSE-NULL');
        foreach ($selected_fields as $field) {
            $explicit_null[$field] = null;
        }

        $result = $this->feed->import_payload(
            array(
                'products' => array(
                    array('product_code' => 'SPARSE-MISSING'),
                    $explicit_null,
                    array(
                        'product_code'          => 'SPARSE-INVALID',
                        'price_source_amount'   => 'not-a-number',
                        'price_rounding_digits' => '',
                    ),
                ),
            ),
            'test'
        );

        $this->assertSame(3, $result['updated']);
        $snapshot       = get_option('digitalogic_patris_feed_products');
        $missing_fields = json_decode(
            $GLOBALS['digitalogic_test_posts'][706]['meta']['_digitalogic_patris_missing_fields'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $null_fields    = json_decode(
            $GLOBALS['digitalogic_test_posts'][707]['meta']['_digitalogic_patris_null_fields'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($selected_fields as $field) {
            $this->assertArrayNotHasKey($field, $snapshot['SPARSE-MISSING']);
            $this->assertContains($field, $missing_fields);
            $this->assertArrayHasKey($field, $snapshot['SPARSE-NULL']);
            $this->assertNull($snapshot['SPARSE-NULL'][$field]);
            $this->assertContains($field, $null_fields);
            $this->assertArrayNotHasKey($field, $snapshot['SPARSE-INVALID']);
        }
    }

    private function resetSingleton($class) {
        $property = new ReflectionProperty($class, 'instance');
        $property->setValue(null, null);
    }
}
