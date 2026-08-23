<?php
/**
 * Product-type cache-prefix repair command tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,Generic.Files.OneObjectStructurePerFile.MultipleFound -- PHPUnit test doubles share their contract test file.

/** Verify the bounded report-derived cache-only command contract. */
final class ProductTypeCacheCliTest extends TestCase {
	/** Reset command and capability fixtures. */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_capabilities']             = array( 'manage_options' => true );
		$GLOBALS['digitalogic_test_object_term_cache_cleans'] = array();

		WP_CLI::$errors   = array();
		WP_CLI::$logs     = array();
		WP_CLI::$warnings = array();
	}

	/** The exact production command is registered. */
	public function test_exact_command_is_registered(): void {
		$this->assertArrayHasKey( 'digitalogic product-type-cache repair', WP_CLI::$commands );
	}

	/** Dry-run derives candidates and records evidence without invalidation. */
	public function test_dry_run_is_read_only_and_reports_factory_taxonomy_and_variations(): void {
		$command = new Digitalogic_Test_Product_Type_Cache_CLI();

		$command->repair( array(), array() );

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertSame( array(), $command->invalidated_ids );
		$output = $this->last_output();
		$this->assertSame( 'dry-run', $output['mode'] );
		$this->assertSame( 1, $output['candidate_count'] );
		$this->assertSame( 0, $output['repaired_count'] );
		$this->assertSame( 'Digitalogic_Test_Stale_Simple_Product', $output['items'][0]['before']['factory_class'] );
		$this->assertSame( 'simple', $output['items'][0]['before']['factory_type'] );
		$this->assertSame( 'variable', $output['items'][0]['before']['durable_type'] );
		$this->assertSame( 2, $output['items'][0]['variation_count'] );
		$this->assertSame( array( 151, 152 ), $output['items'][0]['variation_ids'] );
		$this->assertSame(
			array(
				'products'   => 0,
				'taxonomies' => 0,
				'prices'     => 0,
			),
			$output['catalog_writes']
		);
	}

	/** Apply invalidates exact prefixes, verifies readback, and is idempotent. */
	public function test_apply_repairs_only_derived_prefixes_and_repeat_is_noop(): void {
		$command                = new Digitalogic_Test_Product_Type_Cache_CLI();
		$command->candidate_ids = array( 150, 175 );
		$args                   = array(
			'source-id'      => 'patris-office',
			'dataset'        => 'kala.db',
			'apply'          => true,
			'max-candidates' => '2',
		);

		$command->repair( array(), $args );

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertSame( array( 150, 175 ), $command->invalidated_ids );
		$output = $this->last_output();
		$this->assertSame( 'applied', $output['mode'] );
		$this->assertSame( 2, $output['repaired_count'] );
		$this->assertSame( 0, $output['remaining_drift_count'] );
		$this->assertSame( array( 'product_150', 'product_175' ), $output['cache_groups_invalidated'] );
		$this->assertSame( 'Digitalogic_Test_Repaired_Variable_Product', $output['items'][0]['after']['factory_class'] );
		$this->assertSame( 'variable', $output['items'][0]['after']['factory_type'] );

		WP_CLI::$logs = array();
		$command->repair( array(), $args );

		$this->assertSame( array( 150, 175 ), $command->invalidated_ids );
		$output = $this->last_output();
		$this->assertSame( 0, $output['candidate_count'] );
		$this->assertSame( 0, $output['repaired_count'] );
		$this->assertSame( 0, $output['remaining_drift_count'] );
	}

	/** Apply requires an exact source scope and an explicit safety ceiling. */
	public function test_apply_requires_scope_and_candidate_ceiling_before_report_or_cache_work(): void {
		$command = new Digitalogic_Test_Product_Type_Cache_CLI();

		$command->repair( array(), array( 'apply' => true ) );
		$this->assertSame( array( 'An exact --source-id and --dataset are required with --apply.' ), WP_CLI::$errors );
		$this->assertSame( array(), $command->invalidated_ids );

		WP_CLI::$errors = array();
		$command->repair(
			array(),
			array(
				'source-id' => 'patris-office',
				'dataset'   => 'kala.db',
				'apply'     => true,
			)
		);
		$this->assertSame( array( '--max-candidates is required with --apply.' ), WP_CLI::$errors );
		$this->assertSame( array(), $command->invalidated_ids );
	}

	/** Too many current candidates fails as one unit before any cache write. */
	public function test_candidate_ceiling_fails_closed_before_first_invalidation(): void {
		$command                = new Digitalogic_Test_Product_Type_Cache_CLI();
		$command->candidate_ids = array( 150, 175 );

		$command->repair(
			array(),
			array(
				'source-id'      => 'patris-office',
				'dataset'        => 'kala.db',
				'apply'          => true,
				'max-candidates' => '1',
			)
		);

		$this->assertSame( array( 'The current candidate count exceeds --max-candidates; no cache was changed.' ), WP_CLI::$errors );
		$this->assertSame( array(), $command->invalidated_ids );
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/** Any unrelated integrity warning prevents a partial cache repair. */
	public function test_unrelated_integrity_warning_fails_closed(): void {
		$command                    = new Digitalogic_Test_Product_Type_Cache_CLI();
		$command->unrelated_warning = true;

		$command->repair( array(), array() );

		$this->assertSame( array( 'The report contains an unrelated integrity warning; no cache was changed.' ), WP_CLI::$errors );
		$this->assertSame( array(), $command->invalidated_ids );
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/** Factory/taxonomy/variation preconditions are all checked before writes. */
	public function test_missing_variations_fail_before_cache_invalidation(): void {
		$command                = new Digitalogic_Test_Product_Type_Cache_CLI();
		$command->variation_ids = new WP_Error( 'missing_variations', 'No durable variations.' );

		$command->repair( array(), array() );

		$this->assertSame( array( 'No durable variations.' ), WP_CLI::$errors );
		$this->assertSame( array(), $command->invalidated_ids );
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/** The production seam clears every exact Woo type cache without catalog writes. */
	public function test_production_invalidator_changes_no_catalog_fixture(): void {
		$GLOBALS['digitalogic_test_wc_cache_group_invalidations'] = array();
		$GLOBALS['digitalogic_test_posts'][902]                   = array(
			'post_type'             => 'product',
			'post_status'           => 'publish',
			'product_type'          => 'simple',
			'taxonomy_product_type' => 'variable',
			'meta'                  => array( '_regular_price' => '123' ),
		);
		$before  = $GLOBALS['digitalogic_test_posts'][902];
		$command = new Digitalogic_Test_Production_Product_Type_Cache_CLI();

		$result = $command->invalidate_for_test( 902 );

		$this->assertTrue( $result );
		$this->assertSame( array( 902 ), $command->instance_cache_removals );
		$this->assertSame( array( array( 902, 'product' ) ), $GLOBALS['digitalogic_test_object_term_cache_cleans'] );
		$this->assertSame( array( 'product_902' ), $GLOBALS['digitalogic_test_wc_cache_group_invalidations'] );
		$this->assertSame( $before, $GLOBALS['digitalogic_test_posts'][902] );
	}

	/** Decode the last JSON line. */
	private function last_output() {
		$this->assertNotEmpty( WP_CLI::$logs );

		return json_decode( (string) end( WP_CLI::$logs ), true, 512, JSON_THROW_ON_ERROR );
	}
}

/** Expose the production invalidation path while recording its container seam. */
final class Digitalogic_Test_Production_Product_Type_Cache_CLI extends Digitalogic_Product_Type_Cache_CLI {
	/**
	 * Product-object cache removals.
	 *
	 * @var int[]
	 */
	public $instance_cache_removals = array();

	/**
	 * Invoke the protected production cache invalidator.
	 *
	 * @param int $product_id Product ID.
	 * @return true|WP_Error
	 */
	public function invalidate_for_test( $product_id ) {
		return $this->invalidate_product_cache_prefix( $product_id );
	}

	/**
	 * Record the optional WooCommerce product-object cache removal.
	 *
	 * @param int $product_id Product ID.
	 * @return true
	 */
	protected function remove_product_instance_cache( $product_id ) {
		$this->instance_cache_removals[] = (int) $product_id;

		return true;
	}
}

/** Factory object representing a stale simple-class read. */
final class Digitalogic_Test_Stale_Simple_Product extends WC_Product {
	/**
	 * Create a stale factory object.
	 *
	 * @param int $product_id Product ID.
	 */
	public function __construct( $product_id ) {
		$this->id = (int) $product_id;
	}

	/**
	 * Return the stale factory type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'simple';
	}
}

/** Factory object representing a repaired variable-class read. */
final class Digitalogic_Test_Repaired_Variable_Product extends WC_Product {
	/**
	 * Create a repaired factory object.
	 *
	 * @param int $product_id Product ID.
	 */
	public function __construct( $product_id ) {
		$this->id = (int) $product_id;
	}

	/**
	 * Return the repaired factory type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'variable';
	}
}

/** Deterministic command seam for cache-only behavior tests. */
final class Digitalogic_Test_Product_Type_Cache_CLI extends Digitalogic_Product_Type_Cache_CLI {
	/**
	 * Report-derived candidate IDs.
	 *
	 * @var int[]
	 */
	public $candidate_ids = array( 150 );

	/**
	 * Cache prefixes invalidated by the command.
	 *
	 * @var int[]
	 */
	public $invalidated_ids = array();

	/**
	 * Whether to inject an unrelated integrity warning.
	 *
	 * @var bool
	 */
	public $unrelated_warning = false;

	/**
	 * Current post type fixture.
	 *
	 * @var string
	 */
	public $post_type = 'product';

	/**
	 * Durable taxonomy fixture.
	 *
	 * @var string|WP_Error
	 */
	public $durable_type = 'variable';

	/**
	 * Durable variation fixture.
	 *
	 * @var int[]|WP_Error
	 */
	public $variation_ids = array( 151, 152 );

	/**
	 * Return a fresh report whose drift disappears after invalidation.
	 *
	 * @param array $parsed Parsed command arguments.
	 * @return array
	 */
	protected function build_report( $parsed ) {
		$warnings = array();
		foreach ( $this->candidate_ids as $product_id ) {
			if ( ! in_array( $product_id, $this->invalidated_ids, true ) ) {
				$warnings[] = array(
					'code'           => 'product_type_cache_drift',
					'severity'       => 'critical',
					'woocommerce_id' => $product_id,
					'durable_type'   => 'variable',
					'object_type'    => 'simple',
				);
			}
		}
		if ( $this->unrelated_warning ) {
			$warnings[] = array(
				'code'     => 'projection_integrity_product_type_readback_failed',
				'severity' => 'critical',
			);
		}

		return array(
			'status'            => 'current',
			'snapshot_revision' => 'sha256:test-report',
			'source'            => array(
				'id'       => '' !== $parsed['source_id'] ? $parsed['source_id'] : 'patris-office',
				'dataset'  => '' !== $parsed['dataset'] ? $parsed['dataset'] : 'kala.db',
				'revision' => 'sha256:test-source',
			),
			'limits'            => array(
				'source_truncated'      => false,
				'woocommerce_truncated' => false,
			),
			'integrity'         => array(
				'status'   => empty( $warnings ) ? 'current' : 'warning',
				'warnings' => $warnings,
			),
		);
	}

	/**
	 * Return the simulated factory object.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product
	 */
	protected function read_product( $product_id ) {
		return in_array( $product_id, $this->invalidated_ids, true )
			? new Digitalogic_Test_Repaired_Variable_Product( $product_id )
			: new Digitalogic_Test_Stale_Simple_Product( $product_id );
	}

	/**
	 * Return the simulated post type.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	protected function read_post_type( $product_id ) {
		unset( $product_id );

		return $this->post_type;
	}

	/**
	 * Return the simulated durable taxonomy type.
	 *
	 * @param int $product_id Product ID.
	 * @return string|WP_Error
	 */
	protected function read_durable_product_type( $product_id ) {
		unset( $product_id );

		return $this->durable_type;
	}

	/**
	 * Return the simulated durable variation IDs.
	 *
	 * @param int $product_id Product ID.
	 * @return int[]|WP_Error
	 */
	protected function read_variation_ids( $product_id ) {
		unset( $product_id );

		return $this->variation_ids;
	}

	/**
	 * Record an exact simulated cache-prefix invalidation.
	 *
	 * @param int $product_id Product ID.
	 * @return true
	 */
	protected function invalidate_product_cache_prefix( $product_id ) {
		$this->invalidated_ids[] = (int) $product_id;

		return true;
	}
}
