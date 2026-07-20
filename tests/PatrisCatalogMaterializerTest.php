<?php

use PHPUnit\Framework\TestCase;

final class PatrisCatalogMaterializerTest extends TestCase {

	private static $fixture_json;
	private static $fixture;

	public static function setUpBeforeClass(): void {
		self::$fixture_json = file_get_contents( __DIR__ . '/fixtures/patris-product-sync-v1.1-golden.json' );
		self::$fixture      = json_decode( self::$fixture_json, true, 512, JSON_THROW_ON_ERROR );
	}

	protected function setUp(): void {
		$GLOBALS['digitalogic_test_options']           = array();
		$GLOBALS['digitalogic_test_option_cache']      = array();
		$GLOBALS['digitalogic_test_posts']             = array();
		$GLOBALS['digitalogic_test_next_post_id']      = 1;
		$GLOBALS['digitalogic_test_post_meta_cache']   = array();
		$GLOBALS['digitalogic_test_terms']             = array();
		$GLOBALS['digitalogic_test_term_meta']         = array();
		$GLOBALS['digitalogic_test_next_term_id']      = 1;
		$GLOBALS['digitalogic_test_wc_products']       = array();
		$GLOBALS['digitalogic_test_wc_product_saves']  = array();
		$GLOBALS['digitalogic_test_wc_save_failures']  = array();
		$GLOBALS['digitalogic_test_wc_save_fail_once'] = array();
		$GLOBALS['digitalogic_test_actions']           = array();
		$GLOBALS['digitalogic_test_action_callbacks']  = array();
		$GLOBALS['digitalogic_test_filters']           = array();
		$GLOBALS['digitalogic_test_cache_deletes']     = array();
		$GLOBALS['digitalogic_test_capabilities']      = array( 'manage_options' => true );
		$GLOBALS['wpdb']                               = new Digitalogic_Test_WPDB();
		WC_Product_Variable::$synced_ids               = array();
		$this->resetSingleton( Digitalogic_Import_Freight_Service::class );
	}

	public function test_dry_run_plans_only_positive_stock_and_writes_nothing(): void {
		$this->receiveFixture();

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $this->manifest() );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'dry_run', $result['mode'] );
		$this->assertSame( 1, $result['selected_positive_stock'] );
		$this->assertSame( 1, $result['planned_create'] );
		$this->assertSame( 2, $result['categories']['planned_create'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_posts'] );
		$this->assertSame( array(), $GLOBALS['digitalogic_test_terms'] );
	}

	public function test_apply_creates_an_idempotent_draft_with_exact_feed_category_and_air_express(): void {
		$this->receiveFixture();
		$service = Digitalogic_Patris_Catalog_Materializer::instance();

		$first  = $service->run( $this->manifest(), array( 'apply' => true ) );
		$second = $service->run( $this->manifest(), array( 'apply' => true ) );

		$this->assertSame( 1, $first['created'] );
		$this->assertSame( 0, $first['published'] );
		$this->assertSame( 1, $first['publish_blocked'] );
		$this->assertSame( 0, $second['created'] );
		$this->assertSame( 1, $second['reconciled'] );
		$this->assertCount( 1, $GLOBALS['digitalogic_test_posts'] );
		$product_id = (int) array_key_first( $GLOBALS['digitalogic_test_posts'] );
		$product    = wc_get_product( $product_id );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '101001001', $product->get_sku() );
		$this->assertSame( '101001001', $product->get_meta( '_digitalogic_patris_product_code', true ) );
		$this->assertSame( 'Synthetic priced product', $product->get_meta( '_digitalogic_patris_name', true ) );
		$this->assertSame( 'air_express', get_post_meta( $product_id, Digitalogic_Import_Freight_Service::PRODUCT_METHOD_META, true ) );
		$this->assertSame( '2009410', $product->get_regular_price() );
		$this->assertSame( 5, $product->get_stock_quantity() );
		$this->assertGreaterThan( 0, (float) $product->get_weight() );
		$this->assertCount( 1, $product->get_category_ids() );
		$this->assertSame( (string) $product->get_category_ids()[0], $product->get_meta( 'rank_math_primary_product_cat', true ) );
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
		$state['sources'][ $source_key ]['products']['101001001']['import_freight_method_id'] = 'air_express';
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
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

	public function test_preserves_a_published_reviewed_target_when_publication_gates_are_incomplete(): void {
		$this->receiveFixture();
		$this->addProduct( 10803, 'simple' );
		$manifest = $this->manifest();
		$manifest['products']['101001001']['target_product_id'] = '10803';

		$result = Digitalogic_Patris_Catalog_Materializer::instance()->run( $manifest, array( 'apply' => true ) );

		$this->assertSame( 1, $result['adopted'] );
		$this->assertSame( 1, $result['preserved_published'] );
		$this->assertSame( 0, $result['published'] );
		$this->assertSame( 1, $result['publish_blocked'] );
		$this->assertSame( 'publish', wc_get_product( 10803 )->get_status() );
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
		$this->assertSame( 'raw-sensor', $child->get_meta( 'attribute_pa_model', true ) );
		$this->assertSame( '', wc_get_product( 100 )->get_sku() );
		$this->assertSame( '', wc_get_product( 100 )->get_meta( '_digitalogic_patris_product_code', true ) );

		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] );
		$state['sources'][ $source_key ]['products']['101001001']['import_freight_method_id'] = 'air_express';
		update_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, $state, false );
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

		$state      = get_option( Digitalogic_Product_Sync_Receiver::STATE_OPTION, array() );
		$source_key = array_key_first( $state['sources'] );
		$state['sources'][ $source_key ]['products']['101001001']['import_freight_method_id'] = 'air_express';
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
			'schema'         => Digitalogic_Patris_Catalog_Materializer::MANIFEST_SCHEMA,
			'schema_version' => Digitalogic_Patris_Catalog_Materializer::MANIFEST_SCHEMA_VERSION,
			'source'         => array(
				'id'      => 'synthetic-fixture',
				'dataset' => 'synthetic-kala.db',
			),
			'products'       => array(
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
			'categories'     => array(
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
