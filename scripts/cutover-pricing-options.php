<?php
/**
 * One-time, explicitly invoked pricing option-name cutover.
 *
 * @package Digitalogic
 */

/** Copies database bytes without decoding values or invoking pricing writers. */
final class Digitalogic_Pricing_Option_Cutover {
	public const OPTION_PAIRS = array(
		'digitalogic_excel_pricing_sync_settings' => 'digitalogic_pricing_settings',
		'digitalogic_excel_pricing_sync_audit'    => 'digitalogic_pricing_audit',
	);

	/**
	 * Execute under both deployed pricing locks and the receiver lock.
	 *
	 * @param object $database WordPress database connection.
	 * @param bool   $apply Whether to commit the verified cutover.
	 * @return array Nonsecret operation summary.
	 * @throws RuntimeException On unsafe state or unsuccessful database operations.
	 */
	public static function run( $database, $apply = false ) {
		$table = (string) $database->options;
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $table ) ) {
			throw new RuntimeException( 'invalid_options_table' );
		}
		$locks           = array_map(
			static fn( $name ) => substr( $name . '_' . md5( (string) $database->prefix ), 0, 64 ),
			array( 'digitalogic_excel_pricing_sync_v1', 'digitalogic_pricing_v1', 'digitalogic_product_sync' )
		);
		$held            = array();
		$transaction     = false;
		$mutated         = false;
		$previous_errors = $database->suppress_errors( true );
		$connection      = (string) $database->get_var( 'SELECT CONNECTION_ID()' );
		try {
			if ( '' === $connection || '0' === $connection ) {
				throw new RuntimeException( 'database_connection_unavailable' );
			}
			foreach ( $locks as $lock ) {
				if ( '1' !== (string) $database->get_var( $database->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 0 ) ) ) {
					throw new RuntimeException( 'pricing_writer_busy' );
				}
				$held[] = $lock;
			}
			$engine = $database->get_var( $database->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'innodb' !== strtolower( (string) $engine ) ) {
				throw new RuntimeException( 'transactional_options_table_required' );
			}
			self::query( $database, 'START TRANSACTION', 'transaction_start_failed' );
			$transaction = true;
			$before      = self::rows( $database, $table );
			$plan        = array();
			foreach ( self::OPTION_PAIRS as $old => $new ) {
				if ( ! isset( $before[ $old ] ) ) {
					$plan[ $old ] = isset( $before[ $new ] ) ? 'already_cutover' : 'absent';
					continue;
				}
				if ( isset( $before[ $new ] ) && ! self::same_payload( $before[ $old ], $before[ $new ] ) ) {
					throw new RuntimeException( 'option_conflict:' . $old );
				}
				$plan[ $old ] = isset( $before[ $new ] ) ? 'remove_verified_duplicate' : 'copy_and_remove';
			}
			if ( ! $apply ) {
				self::query( $database, 'ROLLBACK', 'dry_run_rollback_failed' );
				$transaction = false;
				return array(
					'mode'    => 'dry_run',
					'options' => $plan,
				);
			}
			self::verify_locks( $database, $held, $connection );
			foreach ( self::OPTION_PAIRS as $old => $new ) {
				if ( isset( $before[ $old ] ) && ! isset( $before[ $new ] ) ) {
					$mutated = true;
					self::query( $database, $database->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, %s)", $new, $before[ $old ]['option_value'], $before[ $old ]['autoload'] ), 'option_copy_failed' );
				}
			}
			$copied = self::rows( $database, $table );
			foreach ( self::OPTION_PAIRS as $old => $new ) {
				if ( isset( $before[ $old ] ) && ( ! isset( $copied[ $new ] ) || ! self::same_payload( $before[ $old ], $copied[ $new ] ) ) ) {
					throw new RuntimeException( 'copied_payload_readback_failed' );
				}
			}
			// No old key is removed until every destination passed exact readback.
			foreach ( self::OPTION_PAIRS as $old => $new ) {
				if ( isset( $before[ $old ] ) ) {
					$mutated = true;
					$deleted = self::query( $database, $database->prepare( "DELETE FROM `{$table}` WHERE option_name = %s AND BINARY option_value = %s AND BINARY autoload = %s", $old, $before[ $old ]['option_value'], $before[ $old ]['autoload'] ), 'old_option_delete_failed' );
					if ( 1 !== $deleted ) {
						throw new RuntimeException( 'old_option_changed' );
					}
				}
			}
			$after = self::rows( $database, $table );
			foreach ( self::OPTION_PAIRS as $old => $new ) {
				$expected = $before[ $old ] ?? $before[ $new ] ?? null;
				if ( isset( $after[ $old ] ) || ( null !== $expected && ( ! isset( $after[ $new ] ) || ! self::same_payload( $expected, $after[ $new ] ) ) ) ) {
					throw new RuntimeException( 'cutover_readback_failed' );
				}
			}
			self::verify_locks( $database, $held, $connection );
			self::query( $database, 'COMMIT', 'commit_failed_verify_state_before_retry' );
			$transaction = false;
			return array(
				'mode'              => 'applied',
				'options'           => $plan,
				'readback_verified' => true,
			);
		} finally {
			try {
				if ( $transaction && false === $database->query( 'ROLLBACK' ) ) {
					throw new RuntimeException( 'rollback_failed_verify_state_before_retry' );
				}
			} finally {
				try {
					if ( $mutated ) {
						foreach ( array_merge( array_keys( self::OPTION_PAIRS ), array_values( self::OPTION_PAIRS ), array( 'alloptions', 'notoptions' ) ) as $key ) {
							wp_cache_delete( $key, 'options' );
						}
					}
				} finally {
					foreach ( array_reverse( $held ) as $lock ) {
						$database->get_var( $database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
					}
					$database->suppress_errors( $previous_errors );
				}
			}
		}
	}

	/** Read opaque option bytes; do not deserialize or expose them in diagnostics. */
	private static function rows( $database, $table ) {
		$names = array_merge( array_keys( self::OPTION_PAIRS ), array_values( self::OPTION_PAIRS ) );
		$rows  = $database->get_results( $database->prepare( "SELECT option_name, option_value, autoload FROM `{$table}` WHERE option_name IN (%s, %s, %s, %s) ORDER BY option_name FOR UPDATE", ...$names ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $database->last_error ) {
			throw new RuntimeException( 'option_read_failed' );
		}
		$result = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['option_name'], $row['option_value'], $row['autoload'] ) || isset( $result[ $row['option_name'] ] ) ) {
				throw new RuntimeException( 'option_row_invalid' );
			}
			$result[ $row['option_name'] ] = $row;
		}
		return $result;
	}

	/** Compare exact serialized bytes and preserve the source autoload flag. */
	private static function same_payload( $left, $right ) {
		return $left['option_value'] === $right['option_value'] && $left['autoload'] === $right['autoload'];
	}

	/** Require the original connection to retain every writer fence. */
	private static function verify_locks( $database, $locks, $connection ) {
		if ( $connection !== (string) $database->get_var( 'SELECT CONNECTION_ID()' ) ) {
			throw new RuntimeException( 'database_connection_changed' );
		}
		foreach ( $locks as $lock ) {
			if ( $connection !== (string) $database->get_var( $database->prepare( 'SELECT IS_USED_LOCK(%s)', $lock ) ) ) {
				throw new RuntimeException( 'pricing_writer_lock_lost' );
			}
		}
	}

	/** Execute without returning database diagnostics containing option values. */
	private static function query( $database, $query, $error ) {
		$result = $database->query( $query );
		if ( false === $result ) {
			throw new RuntimeException( $error );
		}
		return $result;
	}
}

// WP-CLI eval-file supplies positional arguments as the local $args variable.
// Including the class without that variable permits isolated offline tests.
if ( isset( $args ) ) {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_array( $args ) || ! in_array( $args, array( array(), array( 'dry-run' ), array( 'apply' ) ), true ) ) {
		throw new RuntimeException( 'Use: wp eval-file cutover-pricing-options.php [dry-run|apply]' );
	}
	global $wpdb;
	try {
		$summary = Digitalogic_Pricing_Option_Cutover::run( $wpdb, array( 'apply' ) === $args );
		WP_CLI::log( wp_json_encode( $summary ) );
	} catch ( Throwable $error ) {
		// Unexpected database/plugin exceptions may contain payloads; never echo them.
		$reason       = $error->getMessage();
		$safe_reasons = array(
			'invalid_options_table',
			'database_connection_unavailable',
			'pricing_writer_busy',
			'transactional_options_table_required',
			'transaction_start_failed',
			'option_conflict:digitalogic_excel_pricing_sync_settings',
			'option_conflict:digitalogic_excel_pricing_sync_audit',
			'dry_run_rollback_failed',
			'option_copy_failed',
			'copied_payload_readback_failed',
			'old_option_delete_failed',
			'old_option_changed',
			'cutover_readback_failed',
			'commit_failed_verify_state_before_retry',
			'rollback_failed_verify_state_before_retry',
			'option_read_failed',
			'option_row_invalid',
			'database_connection_changed',
			'pricing_writer_lock_lost',
		);
		if ( ! in_array( $reason, $safe_reasons, true ) ) {
			$reason = 'unexpected_failure_verify_state_before_retry';
		}
		WP_CLI::error( 'Pricing option cutover stopped: ' . $reason );
	}
}
