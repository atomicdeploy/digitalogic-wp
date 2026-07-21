<?php

use PHPUnit\Framework\TestCase;

final class PatrisCatalogMaterializerTest extends TestCase {

	private static $fixture_json;
	private static $fixture;

	public static function setUpBeforeClass(): void {
		self::$fixture_json = file_get_contents( __DIR__ . '/fixtures/patris-catalog-materializer-golden.json' );
		self::$fixture      = json_decode( self::$fixture_json, true, 512, JSON_THROW_ON_ERROR );
	}

	/** Reset every shared service and storage surface used by materialization. */
	protected function setUp(): void {
		parent::setUp();
		foreach (
			array(
				'digitalogic_test_options',
				'digitalogic_test_option_cache',
				'digitalogic_test_posts',
				'digitalogic_test_post_meta_cache',
				'digitalogic_test_terms',
				'digitalogic_test_term_meta',
				'digitalogic_test_wc_products',
				'digitalogic_test_wc_product_saves',
				'digitalogic_test_wc_save_failures',
				'digitalogic_test_wc_save_fail_once',
				'digitalogic_test_wc_lookup_rows',
				'digitalogic_test_actions',
				'digitalogic_test_action_callbacks',
				'digitalogic_test_filters',
				'digitalogic_test_routes',
				'digitalogic_test_update_failures',
				'digitalogic_test_meta_update_failures',
				'digitalogic_test_meta_delete_failures',
				'digitalogic_test_transaction_failures',
				'digitalogic_test_cache_deletes',
				'digitalogic_test_remote_posts',
				'digitalogic_test_remote_post_results',
				'digitalogic_test_product_updates',
				'digitalogic_test_wp_query_args',
				'digitalogic_test_wp_query_results',
				'digitalogic_test_primed_post_ids',
				'digitalogic_test_transients',
				'digitalogic_test_transient_deletes',
			) as $global_name
		) {
			$GLOBALS[ $global_name ] = array();
		}

		$defaults = array(
			'digitalogic_test_next_post_id'            => 1,
			'digitalogic_test_next_term_id'            => 1,
			'digitalogic_test_wc_data_store'           => null,
			'digitalogic_test_wc_lookup_full_rebuilds' => 0,
			'digitalogic_test_capabilities'            => array( 'manage_options' => true ),
			'digitalogic_test_rest_prefix'             => 'wp-json',
			'digitalogic_test_rest_url_calls'          => 0,
			'digitalogic_test_current_user_can_calls'  => 0,
			'digitalogic_test_wc_currency'             => 'IRT',
			'digitalogic_test_locale'                  => 'en_US',
		);
		foreach ( $defaults as $global_name => $value ) {
			$GLOBALS[ $global_name ] = $value;
		}

		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();

		WC_Product_Variable::$synced_ids = array();
		$this->resetSingleton( Digitalogic_Patris_Catalog_Materializer::class );
		$this->resetSingleton( Digitalogic_Product_Sync_Receiver::class );
		$this->resetSingleton( Digitalogic_Shipping_Method_Service::class );
		$this->resetSingleton( Digitalogic_Patris_Feed::class );
		$this->resetSingleton( Digitalogic_Product_Identifier_Resolver::class );
		$this->resetSingleton( Digitalogic_WooCommerce_Currency_Status::class );
	}

	public function test_dry_run_plans_only_positive_stock_and_writes_nothing(): void {
		$this->receiveFixture();

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $this->manifest() );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic.patris-catalog-materialization-result', $result['schema'] );
		$this->assertSame( 'dry_run', $result['mode'] );
		$this->assertSame( 1, $result['selected_positive_stock'] );
		$this->assertSame( 1, $result['planned_create'] );
		$this->assertSame( 2, $result['categories']['planned_create'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_terms'] );
	}

	public function test_fixture_uses_the_living_contract_and_currency_qualified_freight(): void {
		$this->assertSame( 'patris.product-sync', self::$fixture['schema'] );

		$this->receiveFixture();
		$state    = Digitalogic_Product_Sync_Receiver::instance()->get_source_state( 'synthetic-fixture', 'synthetic-kala.db' );
		$priced   = $state['products']['101001001'];
		$explicit = $state['products']['101001002'];

		$this->assertSame( '120', $priced['shipping_price_per_kg'] );
		$this->assertSame( 'CNY', $priced['shipping_price_per_kg_currency'] );
		$this->assertSame( 2009410, $priced['final_price'] );
		$this->assertArrayHasKey( 'shipping_price_per_kg', $explicit );
		$this->assertArrayHasKey( 'shipping_price_per_kg_currency', $explicit );
		$this->assertNull( $explicit['shipping_price_per_kg'] );
		$this->assertNull( $explicit['shipping_price_per_kg_currency'] );
		$this->assertArrayNotHasKey( 'final_price', $explicit );
	}

	public function test_manifest_has_one_closed_living_shape(): void {
		$service   = Digitalogic_Patris_Catalog_Materializer::instance();
		$manifest  = $this->manifest();
		$validated = $service->validate_manifest( $manifest );

		$this->assertNotInstanceOf( WP_Error::class, $validated );
		$this->assertSame( array( 'schema', 'source', 'products', 'categories' ), array_keys( $validated ) );

		$manifest['unexpected'] = true;
		$rejected               = $service->validate_manifest( $manifest );
		$this->assertInstanceOf( WP_Error::class, $rejected );
		$this->assertSame( 'digitalogic_patris_materializer_manifest_shape', $rejected->get_error_code() );
	}

	public function test_apply_creates_an_idempotent_draft_with_exact_feed_category_and_air_express(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();

		$first  = $service->run( $this->manifest(), array( 'apply' => true ) );
		$second = $service->run( $this->manifest(), array( 'apply' => true ) );

		$this->assertSame( 1, $first['created'] );
		$this->assertSame( 0, $first['published'] );
		$this->assertSame( 1, $first['publish_blocked'] );
		$this->assertSame( 0, $first['publish_ready'] );
		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['reconciled'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		$product    = wc_get_product( $product_id );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '101001001', $product->get_sku() );
		$this->assertSame( '101001001', $product->get_meta( '_digitalogic_patris_product_code', true ) );
		$this->assertSame( 'Synthetic priced product', $product->get_meta( '_digitalogic_patris_name', true ) );
		$this->assertSame( 'air_express', get_post_meta( $product_id, Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META, true ) );
		$this->assertSame( '2009410', $product->get_regular_price() );
		$this->assertSame( 5, $product->get_stock_quantity() );
		$this->assertGreaterThan( 0, (float) $product->get_weight() );
		$this->assertCount( 1, $product->get_category_ids() );
		$this->assertSame( (string) $product->get_category_ids()[0], $product->get_meta( 'rank_math_primary_product_cat', true ) );
	}

	/** Verify stale freight state cannot bypass publication readiness. */
	public function test_publish_ready_requires_positive_currency_qualified_freight_and_accepts_irr(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();
		$service->run( $this->manifest(), array( 'apply' => true ) );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		$this->attachReviewedImage( $product_id );
		$cases = array(
			'pair missing'         => array( array(), array( 'shipping_price_per_kg', 'shipping_price_per_kg_currency' ) ),
			'amount missing'       => array( array( 'shipping_price_per_kg_currency' => 'CNY' ), array( 'shipping_price_per_kg' ) ),
			'currency missing'     => array( array( 'shipping_price_per_kg' => '120' ), array( 'shipping_price_per_kg_currency' ) ),
			'amount not positive'  => array(
				array(
					'shipping_price_per_kg'          => '0',
					'shipping_price_per_kg_currency' => 'IRR',
				),
				array( 'shipping_price_per_kg' ),
			),
			'currency not exact'   => array(
				array(
					'shipping_price_per_kg'          => '120',
					'shipping_price_per_kg_currency' => 'cny',
				),
				array( 'shipping_price_per_kg_currency' ),
			),
			'currency unsupported' => array(
				array(
					'shipping_price_per_kg'          => '120',
					'shipping_price_per_kg_currency' => 'USD',
				),
				array( 'shipping_price_per_kg_currency' ),
			),
		);

		foreach ( $cases as $label => $case ) {
			list( $freight, $expected_gates ) = $case;
			$state                            = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
			$source_key                       = array_key_first( $state['sources'] );
			$record                           = &$state['sources'][ $source_key ]['products']['101001001'];
			$record['shipping_method_id']     = 'air_express';
			unset( $record['shipping_price_per_kg'], $record['shipping_price_per_kg_currency'] );
			foreach ( $freight as $field => $value ) {
				$record[ $field ] = $value;
			}
			unset( $record );
			update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );

			$result = $service->run(
				$this->manifest(),
				array(
					'apply'         => true,
					'publish_ready' => true,
				)
			);

			$this->assertSame( 1, $result['publish_blocked'], $label );
			$this->assertSame( 0, $result['published'], $label );
			$this->assertSame( 'publish_blocked', $result['details'][0]['reason'], $label );
			foreach ( $expected_gates as $gate ) {
				$this->assertContains( $gate, $result['details'][0]['gates'], $label );
			}
		}

		$state                                    = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key                               = array_key_first( $state['sources'] );
		$record                                   = &$state['sources'][ $source_key ]['products']['101001001'];
		$record['shipping_price_per_kg']          = '34800000';
		$record['shipping_price_per_kg_currency'] = 'IRR';
		unset( $record );
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );

		$result = $service->run(
			$this->manifest(),
			array(
				'apply'         => true,
				'publish_ready' => true,
			)
		);

		$this->assertSame( 0, $result['publish_blocked'] );
		$this->assertSame( 1, $result['published'] );
	}

	/** Every commercial input and source warning fails closed before publication. */
	public function test_publish_ready_requires_complete_pricing_weight_assignment_and_warning_free_source(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();
		$service->run( $this->manifest(), array( 'apply' => true ) );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		$this->attachReviewedImage( $product_id );

		$baseline                               = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key                             = array_key_first( $baseline['sources'] );
		$baseline_record                        = &$baseline['sources'][ $source_key ]['products']['101001001'];
		$baseline_record['shipping_method_id'] = 'air_express';
		unset( $baseline_record );
		$cases = array(
			'foreign price'      => array(
				static function ( &$record ) {
					$record['foreign_price'] = null;
				},
				array( 'foreign_price' ),
			),
			'source weight'      => array(
				static function ( &$record ) {
					$record['weight_grams'] = null;
				},
				array( 'weight_grams', 'woocommerce_weight' ),
			),
			'final price'        => array(
				static function ( &$record ) {
					unset( $record['final_price'] );
				},
				array( 'final_price', 'woocommerce_price' ),
			),
			'source shipping'    => array(
				static function ( &$record ) {
					$record['shipping_method_id'] = '';
				},
				array( 'patris_air_express' ),
			),
			'markup'             => array(
				static function ( &$record ) {
					$record['markup_percent'] = null;
				},
				array( 'markup_percent' ),
			),
			'exchange rate'      => array(
				static function ( &$record ) {
					$record['irt_per_cny'] = null;
				},
				array( 'irt_per_cny' ),
			),
			'catalog revision'   => array(
				static function ( &$record ) {
					$record['pricing_catalog_revision'] = '';
				},
				array( 'pricing_assignment' ),
			),
			'catalog status'     => array(
				static function ( &$record ) {
					$record['pricing_catalog_status'] = '';
				},
				array( 'pricing_assignment' ),
			),
			'assignment warning' => array(
				static function ( &$record ) {
					$record['warnings'] = array( 'product_pricing_assignment_not_found' );
				},
				array( 'patris_warnings' ),
			),
			'image warning'      => array(
				static function ( &$record ) {
					$record['warnings'] = array( 'missing_image' );
				},
				array( 'patris_warnings' ),
			),
		);

		foreach ( $cases as $label => $case ) {
			$state  = $baseline;
			$record = &$state['sources'][ $source_key ]['products']['101001001'];
			$case[0]( $record );
			unset( $record );
			update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );

			$result = $service->run(
				$this->manifest(),
				array(
					'apply'         => true,
					'publish_ready' => true,
				)
			);

			$this->assertSame( 1, $result['publish_blocked'], $label );
			$this->assertSame( 0, $result['publish_ready'], $label );
			$this->assertSame( 0, $result['published'], $label );
			$this->assertSame( 'draft', wc_get_product( $product_id )->get_status(), $label );
			foreach ( $case[1] as $gate ) {
				$this->assertContains( $gate, $result['details'][0]['gates'], $label );
			}
		}
	}

	/** A real WooCommerce featured image is required before the reviewed draft can publish. */
	public function test_publish_ready_requires_a_reviewed_woocommerce_image(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();
		$service->run( $this->manifest(), array( 'apply' => true ) );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] );
		$state['sources'][ $source_key ]['products']['101001001']['shipping_method_id'] = 'air_express';
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );

		$blocked = $service->run(
			$this->manifest(),
			array(
				'apply'         => true,
				'publish_ready' => true,
			)
		);

		$this->assertSame( 1, $blocked['publish_blocked'] );
		$this->assertSame( 0, $blocked['published'] );
		$this->assertContains( 'woocommerce_image', $blocked['details'][0]['gates'] );
		$this->assertSame( 'draft', wc_get_product( $product_id )->get_status() );

		$this->attachReviewedImage( $product_id );
		$published = $service->run(
			$this->manifest(),
			array(
				'apply'         => true,
				'publish_ready' => true,
			)
		);

		$this->assertSame( 0, $published['publish_blocked'] );
		$this->assertSame( 1, $published['published'] );
		$this->assertSame( 'publish', wc_get_product( $product_id )->get_status() );
	}

	/** Verify every apply removes the retired marker without a migration flag. */
	public function test_apply_removes_the_obsolete_materializer_marker_idempotently(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();
		$service->run( $this->manifest(), array( 'apply' => true ) );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		update_post_meta( $product_id, '_digitalogic_patris_materializer_version', 'obsolete' );

		$second = $service->run( $this->manifest(), array( 'apply' => true ) );
		$third  = $service->run( $this->manifest(), array( 'apply' => true ) );

		$this->assertSame( 1, $second['reconciled'] );
		$this->assertSame( 1, $third['reconciled'] );
		$this->assertSame( '', get_post_meta( $product_id, '_digitalogic_patris_materializer_version', true ) );
	}

	public function test_refuses_a_variable_container_and_converts_only_an_explicit_empty_exception(): void {
		$this->receiveFixture();
		$this->addProduct( 10912, 'variable' );
		$GLOBALS['digitalogic_test_posts'][10912]['post_status'] = 'draft';
		$model_attribute = new WC_Product_Attribute();
		$model_attribute->set_name( 'pa_model' );
		$model_attribute->set_options( array( 10 ) );
		$model_attribute->set_variation( true );
		$app_attribute = new WC_Product_Attribute();
		$app_attribute->set_name( 'pa_applications' );
		$app_attribute->set_options( array( 20 ) );
		$app_attribute->set_variation( true );
		$GLOBALS['digitalogic_test_posts'][10912]['attributes']         = array(
			'pa_model'        => $model_attribute,
			'pa_applications' => $app_attribute,
		);
		$GLOBALS['digitalogic_test_posts'][10912]['default_attributes'] = array( 'pa_model' => 'hc-sr04' );
		$manifest = $this->manifest();
		$manifest['products']['101001001']['target_product_id'] = '10912';

		$refused = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest );
		$this->assertSame( 1, $refused['skipped'] );
		$this->assertSame( 'digitalogic_patris_materializer_variable_parent_refused', $refused['details'][0]['reason'] );

		$manifest['products']['101001001']['convert_empty_variable_to_simple'] = true;
		$converted = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );

		$this->assertSame( 1, $converted['converted_empty_variables'] );
		$this->assertSame( 'simple', wc_get_product( 10912 )->get_type() );
		$this->assertSame( '101001001', wc_get_product( 10912 )->get_sku() );
		$this->assertSame( 'draft', wc_get_product( 10912 )->get_status() );
		$this->assertSame( array(), wc_get_product( 10912 )->get_default_attributes() );
		foreach ( wc_get_product( 10912 )->get_attributes() as $attribute ) {
			$this->assertFalse( $attribute->get_variation() );
			$this->assertTrue( $attribute->get_visible() );
		}

		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] );
		$state['sources'][ $source_key ]['products']['101001001']['shipping_method_id'] = 'air_express';
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		$this->attachReviewedImage( 10912 );
		$published = Digitalogic_Patris_Catalog_Materializer::instance()->run(
			$manifest,
			array(
				'apply'         => true,
				'publish_ready' => true,
			)
		);

		$this->assertSame( 0, $published['converted_empty_variables'] );
		$this->assertSame( 1, $published['reconciled'] );
		$this->assertSame( 1, $published['published'] );
		$this->assertSame( 'publish', wc_get_product( 10912 )->get_status() );
	}

	public function test_rejects_noncanonical_or_negative_limits_without_widening_the_batch(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();

		foreach ( array( -1, '-1', '01', '+1', '1.5', '1e2', ' 1' ) as $limit ) {
			$result = $service->run( $this->manifest(), array( 'limit' => $limit ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'digitalogic_patris_materializer_limit_invalid', $result->get_error_code() );
		}
		$unlimited = $service->run( $this->manifest(), array( 'limit' => '0' ) );
		$this->assertSame( 1, $unlimited['selected_positive_stock'] );
	}

	public function test_requires_a_persian_focus_keyword_in_product_parent_and_category_enrichment(): void {
		$service = Digitalogic_Patris_Catalog_Materializer::instance();

		$product_manifest = $this->manifest();
		$product_manifest['products']['101001001']['focus_keyword_fa'] = '';
		$product_error = $service->validate_manifest( $product_manifest );
		$this->assertInstanceOf( WP_Error::class, $product_error );
		$this->assertSame( 'products.101001001.focus_keyword_fa', $product_error->get_error_data()['path'] );

		$category_manifest = $this->manifest();
		$category_manifest['categories']['101']['focus_keyword_fa'] = '';
		$category_error = $service->validate_manifest( $category_manifest );
		$this->assertInstanceOf( WP_Error::class, $category_error );
		$this->assertSame( 'categories.101.focus_keyword_fa', $category_error->get_error_data()['path'] );

		$parent_manifest = $this->manifest();
		$parent_manifest['products']['101001001']['target_parent_id']                      = '100';
		$parent_manifest['products']['101001001']['attribute_taxonomy']                    = 'pa_model';
		$parent_manifest['products']['101001001']['attribute_term_id']                     = '373';
		$parent_manifest['products']['101001001']['variation_group']                       = 'focus-check';
		$parent_manifest['products']['101001001']['parent_enrichment']                     = $this->parentEnrichment();
		$parent_manifest['products']['101001001']['parent_enrichment']['focus_keyword_fa'] = '';
		$parent_error = $service->validate_manifest( $parent_manifest );
		$this->assertInstanceOf( WP_Error::class, $parent_error );
		$this->assertSame( 'products.101001001.parent_enrichment.focus_keyword_fa', $parent_error->get_error_data()['path'] );
	}

	public function test_demotes_a_published_managed_target_when_publication_gates_are_incomplete(): void {
		$this->receiveFixture();
		$this->addProduct( 10803, 'simple' );
		$manifest = $this->manifest();
		$manifest['products']['101001001']['target_product_id'] = '10803';

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );

		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 0, $result['preserved_published'] );
		$this->assertSame( 0, $result['published'] );
		$this->assertSame( 1, $result['publish_blocked'] );
		$this->assertSame( 0, $result['publish_ready'] );
		$this->assertSame( 'draft', wc_get_product( 10803 )->get_status() );
		$this->assertSame( 'hidden', wc_get_product( 10803 )->get_catalog_visibility() );
	}

	public function test_partial_create_with_first_save_ownership_is_retryable(): void {
		$this->receiveFixture();
		$GLOBALS['digitalogic_test_wc_save_fail_once'][1] = 2;
		$service = Digitalogic_Patris_Catalog_Materializer::instance();

		$failed = $service->run( $this->manifest(), array( 'apply' => true ) );
		$this->assertSame( 1, $failed['failed'] );
		$this->assertSame( '101001001', get_post_meta( 1, Digitalogic_Patris_Catalog_Materializer::OWNER_CODE_META, true ) );
		$this->assertSame( '101001001', get_post_meta( 1, Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true ) );

		$retried = $service->run( $this->manifest(), array( 'apply' => true ) );
		$this->assertSame( 0, $retried['created'] );
		$this->assertSame( 1, $retried['reconciled'] );
		$this->assertSame( 0, $retried['failed'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
	}

	public function test_apply_aborts_and_releases_its_lock_if_the_source_changes_while_starting(): void {
		$this->receiveFixture();
		$release_count                    = $GLOBALS['wpdb']->release_count;
		$GLOBALS['wpdb']->before_get_lock = static function () {
			$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
			$source_key = array_key_first( $state['sources'] );
			$state['sources'][ $source_key ]['source']['revision'] = 'sha256:' . str_repeat( 'f', 64 );
			update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		};

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $this->manifest(), array( 'apply' => true ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'digitalogic_patris_materializer_source_changed_during_apply', $result->get_error_code() );
		$this->assertSame( $release_count + 1, $GLOBALS['wpdb']->release_count );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_posts'] );
	}

	public function test_creates_one_reviewed_variation_and_publishes_its_code_less_parent_only_after_gates_pass(): void {
		$this->receiveFixture();
		$this->addProduct( 100, 'variable' );
		$this->addTerm( 373, 'سنسور خام', 0, 'pa_model', 'raw-sensor' );
		$manifest                  = $this->manifest();
		$row                       = &$manifest['products']['101001001'];
		$row['target_parent_id']   = '100';
		$row['attribute_taxonomy'] = 'pa_model';
		$row['attribute_term_id']  = '373';
		$row['parent_enrichment']  = $this->parentEnrichment();
		$row['variation_group']    = 'synthetic-sensors';

		$draft = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );
		$this->assertSame( 1, $draft['created_variations'] );
		$children = wc_get_product( 100 )->get_children();
		$this->assertCount( 1, $children );
		$child = wc_get_product( $children[0] );
		$this->assertSame( 'draft', $child->get_status() );
		$this->assertSame( 'raw-sensor', $child->get_variation_attributes()['attribute_pa_model'] );
		$this->assertSame( '', wc_get_product( 100 )->get_sku() );
		$this->assertSame( '', wc_get_product( 100 )->get_meta( '_digitalogic_patris_product_code', true ) );

		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] );
		$state['sources'][ $source_key ]['products']['101001001']['shipping_method_id'] = 'air_express';
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		$this->attachReviewedImage( $children[0] );
		$published = Digitalogic_Patris_Catalog_Materializer::instance()->run(
			$manifest,
			array(
				'apply'         => true,
				'publish_ready' => true,
			)
		);

		$this->assertSame( 1, $published['published'], wp_json_encode( $published, JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( 'publish', wc_get_product( $children[0] )->get_status() );
		$this->assertSame( 'publish', wc_get_product( 100 )->get_status() );
		$this->assertSame( 'خانواده حسگر آزمایشی', wc_get_product( 100 )->get_name() );
		$this->assertSame( 'Synthetic sensor family', wc_get_product( 100 )->get_meta( '_digitalogic_patris_family_name', true ) );
		$this->assertSame( '', wc_get_product( 100 )->get_meta( '_digitalogic_patris_product_code', true ) );
		$this->assertContains( 100, WC_Product_Variable::$synced_ids );
	}

	public function test_refuses_a_reviewed_variation_option_already_owned_by_an_existing_child(): void {
		$this->receiveFixture();
		$this->addProduct( 100, 'variable' );
		$this->addTerm( 373, 'Reviewed sensor', 0, 'pa_model', 'reviewed-sensor' );
		$GLOBALS['digitalogic_test_posts'][200] = array(
			'post_type'    => 'product_variation',
			'post_status'  => 'publish',
			'post_parent'  => 100,
			'product_type' => 'variation',
			'post_title'   => 'Existing reviewed option',
			'meta'         => array( 'attribute_pa_model' => 'reviewed-sensor' ),
		);
		$manifest                               = $this->manifest();
		$row                                    = &$manifest['products']['101001001'];
		$row['target_parent_id']                = '100';
		$row['attribute_taxonomy']              = 'pa_model';
		$row['attribute_term_id']               = '373';
		$row['parent_enrichment']               = $this->parentEnrichment();
		$row['variation_group']                 = 'duplicate-option-check';

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest );

		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'digitalogic_patris_materializer_variation_attribute_conflict', $result['details'][0]['reason'] );
	}

	public function test_parent_publication_failure_restores_a_preexisting_published_child_status(): void {
		$this->receiveFixture();
		$this->addProduct( 100, 'variable' );
		$this->addTerm( 373, 'Reviewed sensor', 0, 'pa_model', 'reviewed-sensor' );
		$manifest                  = $this->manifest();
		$row                       = &$manifest['products']['101001001'];
		$row['target_parent_id']   = '100';
		$row['attribute_taxonomy'] = 'pa_model';
		$row['attribute_term_id']  = '373';
		$row['parent_enrichment']  = $this->parentEnrichment();
		$row['variation_group']    = 'rollback-sensors';

		Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );
		$child_id = wc_get_product( 100 )->get_children()[0];
		$child    = wc_get_product( $child_id );
		$child->set_status( 'publish' );
		$child->save();
		$this->attachReviewedImage( $child_id );

		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] );
		$state['sources'][ $source_key ]['products']['101001001']['shipping_method_id'] = 'air_express';
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
		$GLOBALS['digitalogic_test_wc_save_failures'][] = 100;

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run(
			$manifest,
			array(
				'apply'         => true,
				'publish_ready' => true,
			)
		);

		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 0, $result['published'] );
		$this->assertSame( 1, $result['preserved_published'] );
		$this->assertSame( 'publish', wc_get_product( $child_id )->get_status() );
	}

	public function test_reviewed_category_target_is_adopted_without_renaming_or_reparenting(): void {
		$this->receiveFixture();
		$this->addTerm( 50, 'قطعات منتخب', 0 );
		$this->addTerm( 51, 'ماژول‌های منتخب', 50 );
		$manifest                                        = $this->manifest();
		$manifest['categories']['101']['target_term_id'] = '50';
		$manifest['categories']['101']['rename']         = false;
		$manifest['categories']['101001']['target_term_id'] = '51';
		$manifest['categories']['101001']['rename']         = false;

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );

		$this->assertSame( 2, $result['categories']['preserved_manual'] );
		$this->assertSame( 'قطعات منتخب', get_term( 50, 'product_cat' )->name );
		$this->assertSame( 50, get_term( 51, 'product_cat' )->parent );
		$this->assertSame( '101', get_term_meta( 50, Digitalogic_Patris_Catalog_Materializer::CATEGORY_CODE_META, true ) );
		$this->assertSame( '101001', get_term_meta( 51, Digitalogic_Patris_Catalog_Materializer::CATEGORY_CODE_META, true ) );
	}

	public function test_product_can_use_an_exact_reviewed_category_term_override(): void {
		$this->receiveFixture();
		$this->addTerm( 70, 'دسته‌بندی دقیق', 0 );
		$manifest = $this->manifest();
		$manifest['products']['101001001']['category_override'] = array(
			'category_code'  => null,
			'target_term_id' => '70',
		);

		$result  = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );
		$product = wc_get_product( (int) array_key_first( $GLOBALS['digitalogic_test_posts'] ) );

		$this->assertSame( 0, $result['categories']['needed'] );
		$this->assertSame( array( 70 ), $product->get_category_ids() );
		$this->assertSame( '70', $product->get_meta( 'rank_math_primary_product_cat', true ) );
	}

	public function test_referenced_synthetic_category_is_created_under_a_reviewed_manual_parent(): void {
		$this->receiveFixture();
		$this->addTerm( 80, 'تجهیزات پزشکی', 0 );
		$manifest = $this->manifest();
		$manifest['products']['101001001']['category_override'] = array(
			'category_code'  => 'digitalogic:medical-sensors',
			'target_term_id' => null,
		);
		$manifest['categories']['digitalogic:medical-sensors']  = array(
			'patris_name'           => '',
			'target_term_id'        => null,
			'rename'                => false,
			'parent_category_code'  => null,
			'target_parent_term_id' => '80',
			'name_fa'               => 'حسگرهای پزشکی',
			'seo_title_fa'          => 'خرید حسگرهای پزشکی',
			'seo_description_fa'    => 'حسگرها و ماژول‌های پزشکی برای پروژه‌های الکترونیکی.',
			'focus_keyword_fa'      => 'حسگر پزشکی',
		);

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );
		$this->assertSame( 1, $result['categories']['created'] );
		$created = array_values(
			array_filter(
				$GLOBALS['digitalogic_test_terms'],
				static fn( $term ) => 'product_cat' === $term['taxonomy'] && 80 === (int) $term['parent']
			)
		);
		$this->assertCount( 1, $created );
		$term_id = (int) $created[0]['term_id'];
		$this->assertSame( 'digitalogic:medical-sensors', get_term_meta( $term_id, Digitalogic_Patris_Catalog_Materializer::CATEGORY_KEY_META, true ) );
		$this->assertSame( '', get_term_meta( $term_id, Digitalogic_Patris_Catalog_Materializer::CATEGORY_CODE_META, true ) );
	}

	private function receiveFixture(): void {
		$result = Digitalogic_Product_Sync_Receiver::instance()->receive_json( self::$fixture_json );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	private function manifest(): array {
		return array(
			'schema'     => Digitalogic_Patris_Catalog_Materializer::MANIFEST_SCHEMA,
			'source'     => array(
				'id'      => 'synthetic-fixture',
				'dataset' => 'synthetic-kala.db',
			),
			'products'   => array(
				'101001001' => array(
					'patris_name'                      => 'Synthetic priced product',
					'target_product_id'                => null,
					'target_parent_id'                 => null,
					'convert_empty_variable_to_simple' => false,
					'attribute_taxonomy'               => '',
					'attribute_term_id'                => null,
					'category_override'                => null,
					'parent_enrichment'                => null,
					'variation_group'                  => '',
					'name_fa'                          => 'محصول آزمایشی قیمت‌گذاری‌شده',
					'short_description_fa'             => 'یک قطعه آزمایشی برای بررسی همگام‌سازی دقیق فروشگاه.',
					'seo_title_fa'                     => 'خرید محصول آزمایشی قیمت‌گذاری‌شده',
					'seo_description_fa'               => 'مشخصات و قیمت محصول آزمایشی با کد دقیق پاتریس.',
					'focus_keyword_fa'                 => 'محصول آزمایشی',
					'part_number'                      => 'SYNTH-001',
					'model'                            => 'SYNTH-001',
				),
			),
			'categories' => array(
				'101'    => $this->categoryRow( 'Synthetic components', 'قطعات آزمایشی' ),
				'101001' => $this->categoryRow( 'Synthetic modules', 'ماژول‌های آزمایشی' ),
			),
		);
	}

	private function categoryRow( string $patris_name, string $name_fa ): array {
		return array(
			'patris_name'           => $patris_name,
			'target_term_id'        => null,
			'rename'                => false,
			'parent_category_code'  => null,
			'target_parent_term_id' => null,
			'name_fa'               => $name_fa,
			'seo_title_fa'          => $name_fa,
			'seo_description_fa'    => 'دسته‌بندی ' . $name_fa . ' برای محصولات فروشگاه.',
			'focus_keyword_fa'      => $name_fa,
		);
	}

	private function parentEnrichment(): array {
		return array(
			'patris_family_name'   => 'Synthetic sensor family',
			'name_fa'              => 'خانواده حسگر آزمایشی',
			'short_description_fa' => 'خانواده‌ای از حسگرهای آزمایشی با گزینه‌های دقیق.',
			'seo_title_fa'         => 'خرید خانواده حسگر آزمایشی',
			'seo_description_fa'   => 'مشخصات و قیمت گزینه‌های خانواده حسگر آزمایشی.',
			'focus_keyword_fa'     => 'حسگر آزمایشی',
		);
	}

	private function addProduct( int $id, string $type ): void {
		$GLOBALS['digitalogic_test_posts'][ $id ] = array(
			'post_type'    => 'product',
			'post_status'  => 'publish',
			'product_type' => $type,
			'post_title'   => 'Existing target',
			'meta'         => array(),
		);
		$GLOBALS['digitalogic_test_next_post_id'] = max( $GLOBALS['digitalogic_test_next_post_id'], $id + 1 );
	}

	private function attachReviewedImage( int $product_id ): void {
		$product = wc_get_product( $product_id );
		$product->update_meta_data( '_thumbnail_id', 41 );
		$product->save();
	}

	private function addTerm( int $id, string $name, int $parent = 0, string $taxonomy = 'product_cat', string $slug = '' ): void {
		$GLOBALS['digitalogic_test_terms'][ $id ] = array(
			'term_id'  => $id,
			'name'     => $name,
			'slug'     => '' === $slug ? 'term-' . $id : $slug,
			'parent'   => $parent,
			'taxonomy' => $taxonomy,
		);
		$GLOBALS['digitalogic_test_next_term_id'] = max( $GLOBALS['digitalogic_test_next_term_id'], $id + 1 );
	}

	private function resetSingleton( string $class_name ): void {
		$property = ( new ReflectionClass( $class_name ) )->getProperty( 'instance' );
		$property->setValue( null, null );
	}
}
