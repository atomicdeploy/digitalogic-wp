<?php
/**
 * Offline transaction and retention regressions for the explicit option cutover.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Exercises the real script against a transactional database double. */
final class PricingOptionCutoverTest extends TestCase {

	/** Load the script without WP-CLI positional arguments. */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/scripts/cutover-pricing-options.php';
	}

	/** Default execution plans changes without touching any option or cache. */
	public function test_default_dry_run_preserves_all_options(): void {
		$db     = new PricingOptionCutoverDatabase();
		$before = $db->rows;
		$caches = $GLOBALS['digitalogic_test_cache_deletes'] ?? array();
		$result = Digitalogic_Pricing_Option_Cutover::run( $db );
		$this->assertSame( 'dry_run', $result['mode'] );
		$this->assertSame( $before, $db->rows );
		$this->assertSame( 0, $db->writes );
		$this->assertSame( $caches, $GLOBALS['digitalogic_test_cache_deletes'] ?? array() );
		$this->assertSame( array_reverse( $db->acquired ), $db->released );
		$this->assertFalse( $db->suppressed );
	}

	/** Both values, autoload flags and unrelated options survive; reruns are safe. */
	public function test_apply_retains_exact_bytes_and_reruns_without_writes(): void {
		$db     = new PricingOptionCutoverDatabase();
		$before = $db->rows;
		$result = Digitalogic_Pricing_Option_Cutover::run( $db, true );
		$this->assertTrue( $result['readback_verified'] );
		foreach ( Digitalogic_Pricing_Option_Cutover::OPTION_PAIRS as $old => $new ) {
			$this->assertArrayNotHasKey( $old, $db->rows );
			$this->assertSame( $before[ $old ]['option_value'], $db->rows[ $new ]['option_value'] );
			$this->assertSame( $before[ $old ]['autoload'], $db->rows[ $new ]['autoload'] );
		}
		$this->assertSame( $before['unrelated_rate'], $db->rows['unrelated_rate'] );
		$this->assertSame( 2, $db->reads_at_first_delete );
		$this->assertSame( array_map( static fn( $name ) => substr( $name . '_' . md5( $db->prefix ), 0, 64 ), array( 'digitalogic_excel_pricing_sync_v1', 'digitalogic_pricing_v1', 'digitalogic_product_sync' ) ), $db->acquired );
		$this->assertStringNotContainsString( 'private-rate-value', wp_json_encode( $result ) );
		$writes = $db->writes;
		$again  = Digitalogic_Pricing_Option_Cutover::run( $db, true );
		$this->assertSame( array( 'already_cutover', 'already_cutover' ), array_values( $again['options'] ) );
		$this->assertSame( $writes, $db->writes );
	}

	/** Conflicts in either pair, including differing autoload, prevent all writes. */
	public function test_conflicts_fail_before_any_mutation(): void {
		foreach ( Digitalogic_Pricing_Option_Cutover::OPTION_PAIRS as $old => $new ) {
			foreach ( array( 'option_value', 'autoload' ) as $field ) {
				$db               = new PricingOptionCutoverDatabase();
				$db->rows[ $new ] = array_replace(
					$db->rows[ $old ],
					array(
						'option_name' => $new,
						$field        => 'conflicting',
					)
				);
				$before           = $db->rows;
				$this->assert_failure( $db, 'option_conflict:' . $old );
				$this->assertSame( $before, $db->rows );
				$this->assertSame( 0, $db->writes );
			}
		}
	}

	/** Identical destination rows may be retained while removing only old keys. */
	public function test_verified_duplicates_are_removed_and_absent_pairs_stay_absent(): void {
		$db = new PricingOptionCutoverDatabase();
		foreach ( Digitalogic_Pricing_Option_Cutover::OPTION_PAIRS as $old => $new ) {
			$db->rows[ $new ] = array_replace( $db->rows[ $old ], array( 'option_name' => $new ) );
		}
		$result = Digitalogic_Pricing_Option_Cutover::run( $db, true );
		$this->assertSame( array( 'remove_verified_duplicate', 'remove_verified_duplicate' ), array_values( $result['options'] ) );
		$this->assertSame( 2, $db->writes );
		$db->rows = array();
		$result   = Digitalogic_Pricing_Option_Cutover::run( $db, true );
		$this->assertSame( array( 'absent', 'absent' ), array_values( $result['options'] ) );
		$this->assertSame( array(), $db->rows );
	}

	/** Failed copy, mismatched readback, deletion and commit all restore both old values. */
	public function test_transaction_failures_restore_the_entire_original_state(): void {
		$cases = array(
			'second_insert'      => 'option_copy_failed',
			'readback'           => 'copied_payload_readback_failed',
			'second_delete'      => 'old_option_delete_failed',
			'commit'             => 'commit_failed_verify_state_before_retry',
			'lock_lost'          => 'pricing_writer_lock_lost',
			'connection_changed' => 'database_connection_changed',
		);
		foreach ( $cases as $failure => $message ) {
			$db          = new PricingOptionCutoverDatabase();
			$db->failure = $failure;
			$before      = $db->rows;
			$this->assert_failure( $db, $message );
			$this->assertSame( $before, $db->rows, $failure );
			$this->assertSame( array_reverse( $db->acquired ), $db->released );
			$this->assertFalse( $db->suppressed );
		}
	}

	/** A busy writer or nontransactional table is never modified. */
	public function test_unsafe_database_or_busy_writer_is_rejected(): void {
		foreach ( array(
			'busy'   => 'pricing_writer_busy',
			'engine' => 'transactional_options_table_required',
		) as $failure => $message ) {
			$db          = new PricingOptionCutoverDatabase();
			$db->failure = $failure;
			$before      = $db->rows;
			$this->assert_failure( $db, $message );
			$this->assertSame( $before, $db->rows );
			$this->assertSame( 0, $db->writes );
			$this->assertSame( array_reverse( $db->acquired ), $db->released );
		}
	}

	/** Assert a bounded diagnostic without leaking an option payload. */
	private function assert_failure( PricingOptionCutoverDatabase $db, string $message ): void {
		try {
			Digitalogic_Pricing_Option_Cutover::run( $db, true );
			$this->fail( 'Expected the cutover to fail closed.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $message, $error->getMessage() );
			$this->assertStringNotContainsString( 'private-rate-value', $error->getMessage() );
		}
	}
}

/** Minimal database protocol with transaction snapshots and injectable failures. */
final class PricingOptionCutoverDatabase {
	public $options               = 'test_7_options';
	public $prefix                = 'test_7_';
	public $rows                  = array();
	public $last_error            = '';
	public $failure               = '';
	public $suppressed            = false;
	public $acquired              = array();
	public $released              = array();
	public $writes                = 0;
	public $reads_at_first_delete = 0;
	private $snapshot;
	private $reads   = 0;
	private $inserts = 0;
	private $deletes = 0;

	/** Seed opaque values including independent dates and an unrelated option. */
	public function __construct() {
		$values = array(
			'digitalogic_excel_pricing_sync_settings' => array(
				'schema'             => 'existing-schema',
				'dollar_rate'        => 'private-rate-value',
				'yuan_rate'          => 'untouched-yuan',
				'usd_effective_date' => '2026-08-01',
				'cny_effective_date' => '2026-08-03',
				'rate_provenance'    => array( 'source' => 'owner' ),
				'revision'           => 'existing-revision',
			),
			'digitalogic_excel_pricing_sync_audit'    => array(
				array(
					'revision' => 'existing-revision',
					'reason'   => 'preserved audit',
				),
			),
			'unrelated_rate'                          => 'untouched',
		);
		foreach ( $values as $name => $value ) {
			$this->rows[ $name ] = array(
				'option_name'  => $name,
				'option_value' => maybe_serialize( $value ),
				'autoload'     => str_ends_with( $name, 'settings' ) ? 'auto-on' : 'off',
			);
		}
	}

	/** Preserve and restore database error suppression. */
	public function suppress_errors( $value ) {
		$previous         = $this->suppressed;
		$this->suppressed = $value;
		return $previous;
	}

	/** Keep SQL values separate so tests can exercise exact raw bytes. */
	public function prepare( $query, ...$values ) {
		return array( $query, $values );
	}

	/** Implement the connection, engine and advisory-lock protocol. */
	public function get_var( $statement ) {
		list( $query, $values ) = is_array( $statement ) ? $statement : array( $statement, array() );
		if ( str_contains( $query, 'CONNECTION_ID' ) ) {
			return 'connection_changed' === $this->failure && $this->writes > 0 ? '42' : '41';
		}
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			if ( 'busy' === $this->failure && 1 === count( $this->acquired ) ) {
				return '0';
			}
			$this->acquired[] = $values[0];
			return '1';
		}
		if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
			$this->released[] = $values[0];
			return '1';
		}
		if ( str_contains( $query, 'IS_USED_LOCK' ) ) {
			return 'lock_lost' === $this->failure && $this->writes > 0 ? null : '41';
		}
		return 'engine' === $this->failure ? 'MyISAM' : 'InnoDB';
	}

	/** Read selected rows, optionally simulating corrupted destination readback. */
	public function get_results( $statement, $format ) {
		if ( ARRAY_A !== $format ) {
			throw new RuntimeException( 'Unexpected database result format.' );
		}
		++$this->reads;
		$rows = array_intersect_key( $this->rows, array_flip( $statement[1] ) );
		if ( 'readback' === $this->failure && 2 === $this->reads ) {
			$rows['digitalogic_pricing_audit']['option_value'] = 'corrupted';
		}
		return array_values( $rows );
	}

	/** Execute transaction and mutation statements with real rollback semantics. */
	public function query( $statement ) {
		list( $query, $values ) = is_array( $statement ) ? $statement : array( $statement, array() );
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = $this->rows;
		} elseif ( 'ROLLBACK' === $query ) {
			$this->rows = $this->snapshot;
		} elseif ( 'COMMIT' === $query ) {
			return 'commit' === $this->failure ? false : 0;
		} elseif ( str_starts_with( $query, 'INSERT' ) ) {
			++$this->writes;
			++$this->inserts;
			if ( 'second_insert' === $this->failure && 2 === $this->inserts ) {
				return false;
			}
			$this->rows[ $values[0] ] = array(
				'option_name'  => $values[0],
				'option_value' => $values[1],
				'autoload'     => $values[2],
			);
			return 1;
		} elseif ( str_starts_with( $query, 'DELETE' ) ) {
			++$this->writes;
			++$this->deletes;
			$this->reads_at_first_delete = $this->reads_at_first_delete ?: $this->reads;
			if ( 'second_delete' === $this->failure && 2 === $this->deletes ) {
				return false;
			}
			if ( ! isset( $this->rows[ $values[0] ] ) || $this->rows[ $values[0] ]['option_value'] !== $values[1] || $this->rows[ $values[0] ]['autoload'] !== $values[2] ) {
				return 0;
			}
			unset( $this->rows[ $values[0] ] );
			return 1;
		}
		return 0;
	}
}
