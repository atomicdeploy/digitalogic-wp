<?php
/**
 * Capability-gated WP-CLI transport for the sanitized SEO monitor status.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Expose only the reviewed, sanitized SEO monitor status contract.
 */
final class Digitalogic_SEO_Monitor_Status_CLI {

	private const BRIDGE_COMMAND = array(
		'/usr/bin/sudo',
		'-n',
		'--',
		'/usr/local/libexec/digitalogic-seo-monitor-status',
	);

	private const BRIDGE_ENVIRONMENT = array(
		'PATH'   => '/usr/sbin:/usr/bin:/sbin:/bin',
		'LANG'   => 'C',
		'LC_ALL' => 'C',
	);

	private const BRIDGE_TIMEOUT_SECONDS = 5.0;
	private const MAX_BRIDGE_BYTES       = 32768;
	private const MAX_SAFE_INTEGER       = 9007199254740991;
	private const STATUS_SCHEMA          = 'digitalogic.seo-monitor.status/v1';

	private const SOURCE_KEYS = array(
		'latest_completed',
		'owner_approvals',
		'pending_decisions',
		'google_docs_scan_state',
		'google_docs_migration_ledger',
	);

	private const READ_STATES = array(
		'available',
		'missing',
		'invalid',
		'unsupported_schema',
		'unsafe',
		'too_large',
		'changed_during_read',
	);

	private const SNAPSHOT_STATES = array(
		'complete',
		'partial',
		'unavailable',
	);

	private const RUN_STATES = array(
		'completed',
		'attention',
		'blocked',
		'failed',
		'unknown',
	);

	/**
	 * Test-only runner seam. Production command registration never supplies it.
	 *
	 * @var callable|null
	 */
	private $runner;

	/**
	 * Construct the command.
	 *
	 * @param callable|null $runner Test-only bridge runner.
	 * @throws InvalidArgumentException When the test runner is not callable.
	 */
	public function __construct( $runner = null ) {
		if ( null !== $runner && ! is_callable( $runner ) ) {
			throw new InvalidArgumentException( 'The SEO monitor status runner must be callable.' );
		}

		$this->runner = $runner;
	}

	/**
	 * Return one sanitized SEO monitor status snapshot.
	 *
	 * This command intentionally accepts no command-specific arguments. The
	 * WP-CLI global --user argument supplies the WordPress identity used for the
	 * capability check.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic seo-monitor status --user=<administrator>
	 *
	 * @when after_wp_load
	 *
	 * @param array $args Positional command arguments.
	 * @param array $assoc_args Named command arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			WP_CLI::error( 'Run this command with --user=<administrator>.' );
			return;
		}

		if ( ! empty( $args ) || ! empty( $assoc_args ) ) {
			WP_CLI::error( 'This command does not accept command-specific arguments.' );
			return;
		}

		$result = null === $this->runner
			? self::run_bridge()
			: call_user_func(
				$this->runner,
				self::BRIDGE_COMMAND,
				self::BRIDGE_ENVIRONMENT,
				self::BRIDGE_TIMEOUT_SECONDS,
				self::MAX_BRIDGE_BYTES
			);

		$projection = self::project_bridge_result( $result );
		if ( false === $projection ) {
			WP_CLI::error( 'SEO monitor status is unavailable.' );
			return;
		}

		$output = wp_json_encode( $projection, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $output ) ) {
			WP_CLI::error( 'SEO monitor status is unavailable.' );
			return;
		}

		WP_CLI::line( $output );
	}

	/**
	 * Execute the fixed privileged sanitizer without a shell.
	 *
	 * @return array|false Bounded process result, or false on launch failure.
	 */
	private static function run_bridge() {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes       = array();
		$process     = @proc_open( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The fixed bridge emits one stable error instead of leaking a local process warning.
			self::BRIDGE_COMMAND,
			$descriptors,
			$pipes,
			null,
			self::BRIDGE_ENVIRONMENT,
			array( 'bypass_shell' => true )
		);

		if ( ! is_resource( $process ) || 3 !== count( $pipes ) ) {
			return false;
		}

		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes a proc_open pipe, not a WordPress filesystem path.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout      = '';
		$stderr      = '';
		$overflow    = false;
		$timed_out   = false;
		$exit_code   = null;
		$started_at  = microtime( true );
		$last_status = null;

		while ( true ) {
			self::append_bounded_stream( $pipes[1], $stdout, $overflow );
			self::append_bounded_stream( $pipes[2], $stderr, $overflow );

			$last_status = proc_get_status( $process );
			if ( ! is_array( $last_status ) || empty( $last_status['running'] ) ) {
				if ( is_array( $last_status ) && isset( $last_status['exitcode'] ) && 0 <= $last_status['exitcode'] ) {
					$exit_code = (int) $last_status['exitcode'];
				}
				break;
			}

			if ( $overflow ) {
				break;
			}

			if ( microtime( true ) - $started_at >= self::BRIDGE_TIMEOUT_SECONDS ) {
				$timed_out = true;
				break;
			}

			usleep( 10000 );
		}

		if ( $overflow || $timed_out ) {
			self::terminate_process( $process );
		}

		self::append_bounded_stream( $pipes[1], $stdout, $overflow );
		self::append_bounded_stream( $pipes[2], $stderr, $overflow );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes a proc_open pipe, not a WordPress filesystem path.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This closes a proc_open pipe, not a WordPress filesystem path.

		$closed_exit_code = proc_close( $process );
		if ( null === $exit_code && is_int( $closed_exit_code ) && 0 <= $closed_exit_code ) {
			$exit_code = $closed_exit_code;
		}

		return array(
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'timed_out' => $timed_out,
			'overflow'  => $overflow,
		);
	}

	/**
	 * Append one nonblocking pipe read without retaining more than the cap.
	 *
	 * @param resource $stream Pipe stream.
	 * @param string   $buffer Captured buffer.
	 * @param bool     $overflow Whether either stream exceeded the cap.
	 * @return void
	 */
	private static function append_bounded_stream( $stream, &$buffer, &$overflow ) {
		if ( $overflow ) {
			return;
		}

		$chunk = stream_get_contents( $stream );
		if ( false === $chunk || '' === $chunk ) {
			return;
		}

		$remaining = self::MAX_BRIDGE_BYTES - strlen( $buffer );
		if ( strlen( $chunk ) > $remaining ) {
			if ( 0 < $remaining ) {
				$buffer .= substr( $chunk, 0, $remaining );
			}
			$overflow = true;
			return;
		}

		$buffer .= $chunk;
	}

	/**
	 * Stop a timed-out or overproducing sanitizer process.
	 *
	 * @param resource $process Process handle.
	 * @return void
	 */
	private static function terminate_process( $process ) {
		proc_terminate( $process );
		$deadline = microtime( true ) + 0.2;
		do {
			$status = proc_get_status( $process );
			if ( ! is_array( $status ) || empty( $status['running'] ) ) {
				return;
			}
			usleep( 10000 );
		} while ( microtime( true ) < $deadline );

		proc_terminate( $process, 9 );
	}

	/**
	 * Validate a process result and reconstruct the fixed public projection.
	 *
	 * Raw stdout and stderr are never returned.
	 *
	 * @param mixed $result Bridge process result.
	 * @return array|false
	 */
	private static function project_bridge_result( $result ) {
		if (
			! self::has_exact_keys(
				$result,
				array( 'exit_code', 'stdout', 'stderr', 'timed_out', 'overflow' )
			)
			|| 0 !== $result['exit_code']
			|| ! is_string( $result['stdout'] )
			|| ! is_string( $result['stderr'] )
			|| ! is_bool( $result['timed_out'] )
			|| ! is_bool( $result['overflow'] )
			|| $result['timed_out']
			|| $result['overflow']
			|| '' !== $result['stderr']
			|| self::MAX_BRIDGE_BYTES < strlen( $result['stdout'] )
		) {
			return false;
		}

		if ( 1 !== preg_match( '/\A[^\r\n]+\r?\n?\z/D', $result['stdout'] ) ) {
			return false;
		}

		$document = rtrim( $result['stdout'], "\r\n" );
		$payload  = json_decode( $document, true, 64, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return false;
		}

		return self::project_payload( $payload );
	}

	/**
	 * Validate and reconstruct the complete v1 payload.
	 *
	 * @param mixed $payload Decoded bridge payload.
	 * @return array|false
	 */
	private static function project_payload( $payload ) {
		if (
			! self::has_exact_keys(
				$payload,
				array( 'schema', 'generated_at', 'snapshot_state', 'sources' )
			)
			|| self::STATUS_SCHEMA !== $payload['schema']
			|| ! self::is_utc_timestamp( $payload['generated_at'] )
			|| ! in_array( $payload['snapshot_state'], self::SNAPSHOT_STATES, true )
			|| ! self::has_exact_keys( $payload['sources'], self::SOURCE_KEYS )
		) {
			return false;
		}

		$sources         = array();
		$available_count = 0;
		foreach ( self::SOURCE_KEYS as $source_key ) {
			$source = self::project_source( $source_key, $payload['sources'][ $source_key ] );
			if ( false === $source ) {
				return false;
			}
			if ( 'available' === $source['read_state'] ) {
				++$available_count;
			}
			$sources[ $source_key ] = $source;
		}

		$derived_snapshot_state = 0 === $available_count
			? 'unavailable'
			: ( count( self::SOURCE_KEYS ) === $available_count ? 'complete' : 'partial' );
		if ( $derived_snapshot_state !== $payload['snapshot_state'] ) {
			return false;
		}

		return array(
			'schema'         => self::STATUS_SCHEMA,
			'generated_at'   => $payload['generated_at'],
			'snapshot_state' => $derived_snapshot_state,
			'sources'        => $sources,
		);
	}

	/**
	 * Validate and reconstruct one fixed source envelope.
	 *
	 * @param string $source_key Source identifier.
	 * @param mixed  $source Source envelope.
	 * @return array|false
	 */
	private static function project_source( $source_key, $source ) {
		if (
			! self::has_exact_keys(
				$source,
				array( 'read_state', 'updated_at', 'summary', 'fingerprint' )
			)
			|| ! in_array( $source['read_state'], self::READ_STATES, true )
			|| ( null !== $source['updated_at'] && ! self::is_utc_timestamp( $source['updated_at'] ) )
		) {
			return false;
		}

		if ( 'available' !== $source['read_state'] ) {
			if (
				null !== $source['updated_at']
				|| null !== $source['summary']
				|| null !== $source['fingerprint']
			) {
				return false;
			}

			return array(
				'read_state'  => $source['read_state'],
				'updated_at'  => $source['updated_at'],
				'summary'     => null,
				'fingerprint' => null,
			);
		}

		if (
			! self::is_utc_timestamp( $source['updated_at'] )
			|| ! is_string( $source['fingerprint'] )
			|| 1 !== preg_match( '/\Ahmac-sha256:[0-9a-f]{64}\z/D', $source['fingerprint'] )
		) {
			return false;
		}

		$summary = self::project_summary( $source_key, $source['summary'] );
		if ( false === $summary ) {
			return false;
		}

		return array(
			'read_state'  => 'available',
			'updated_at'  => $source['updated_at'],
			'summary'     => $summary,
			'fingerprint' => $source['fingerprint'],
		);
	}

	/**
	 * Validate and reconstruct a source-specific summary.
	 *
	 * @param string $source_key Source identifier.
	 * @param mixed  $summary Source summary.
	 * @return array|false
	 */
	private static function project_summary( $source_key, $summary ) {
		switch ( $source_key ) {
			case 'latest_completed':
				return self::project_latest_completed_summary( $summary );
			case 'owner_approvals':
				return self::project_integer_summary(
					$summary,
					array( 'total', 'pending', 'approved', 'rejected', 'expired' )
				);
			case 'pending_decisions':
				return self::project_integer_summary(
					$summary,
					array( 'total', 'critical', 'significant', 'tracked', 'other' )
				);
			case 'google_docs_scan_state':
				return self::project_google_docs_scan_summary( $summary );
			case 'google_docs_migration_ledger':
				return self::project_integer_summary(
					$summary,
					array(
						'total',
						'discovered',
						'not_product_content',
						'needs_mapping',
						'staged_conflict',
						'applied_verified',
						'failed_retryable',
						'blocked',
						'other',
					)
				);
		}

		return false;
	}

	/**
	 * Validate the latest completed run summary.
	 *
	 * @param mixed $summary Source summary.
	 * @return array|false
	 */
	private static function project_latest_completed_summary( $summary ) {
		$integer_keys = array(
			'critical_findings',
			'significant_findings',
			'tracked_findings',
			'owner_decisions',
		);
		$expected     = array_merge( array( 'run_state' ), $integer_keys );
		if (
			! self::has_exact_keys( $summary, $expected )
			|| ! in_array( $summary['run_state'], self::RUN_STATES, true )
		) {
			return false;
		}

		$projection = array( 'run_state' => $summary['run_state'] );
		foreach ( $integer_keys as $key ) {
			if ( ! self::is_unsigned_safe_integer( $summary[ $key ] ) ) {
				return false;
			}
			$projection[ $key ] = $summary[ $key ];
		}

		return $projection;
	}

	/**
	 * Validate an exact all-integer summary.
	 *
	 * @param mixed $summary Source summary.
	 * @param array $keys Exact summary keys.
	 * @return array|false
	 */
	private static function project_integer_summary( $summary, $keys ) {
		if ( ! self::has_exact_keys( $summary, $keys ) ) {
			return false;
		}

		$projection = array();
		foreach ( $keys as $key ) {
			if ( ! self::is_unsigned_safe_integer( $summary[ $key ] ) ) {
				return false;
			}
			$projection[ $key ] = $summary[ $key ];
		}

		if ( in_array( 'total', $keys, true ) ) {
			$partition_total = 0;
			foreach ( $keys as $key ) {
				if ( 'total' !== $key ) {
					$partition_total += $summary[ $key ];
				}
			}
			if ( $summary['total'] !== $partition_total ) {
				return false;
			}
		}

		return $projection;
	}

	/**
	 * Validate the Google Docs inventory summary.
	 *
	 * @param mixed $summary Source summary.
	 * @return array|false
	 */
	private static function project_google_docs_scan_summary( $summary ) {
		$boolean_keys = array(
			'inventory_complete',
			'access_blocked',
			'cursor_pending',
		);
		$integer_keys = array(
			'documents_seen',
			'documents_changed',
			'tabs_seen',
			'errors',
		);
		$expected     = array_merge( $boolean_keys, $integer_keys );
		if ( ! self::has_exact_keys( $summary, $expected ) ) {
			return false;
		}

		$projection = array();
		foreach ( $boolean_keys as $key ) {
			if ( ! is_bool( $summary[ $key ] ) ) {
				return false;
			}
			$projection[ $key ] = $summary[ $key ];
		}
		foreach ( $integer_keys as $key ) {
			if ( ! self::is_unsigned_safe_integer( $summary[ $key ] ) ) {
				return false;
			}
			$projection[ $key ] = $summary[ $key ];
		}

		if (
			$summary['documents_changed'] > $summary['documents_seen']
			|| $summary['errors'] > $summary['documents_seen']
		) {
			return false;
		}

		return $projection;
	}

	/**
	 * Check an exact JSON-object key set.
	 *
	 * @param mixed $value Candidate object.
	 * @param array $expected_keys Expected keys.
	 * @return bool
	 */
	private static function has_exact_keys( $value, $expected_keys ) {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return false;
		}

		$actual_keys = array_keys( $value );
		sort( $actual_keys );
		sort( $expected_keys );

		return $actual_keys === $expected_keys;
	}

	/**
	 * Check a strict UTC ISO timestamp with millisecond precision.
	 *
	 * @param mixed $value Candidate timestamp.
	 * @return bool
	 */
	private static function is_utc_timestamp( $value ) {
		if (
			! is_string( $value )
			|| 1 !== preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/D', $value )
		) {
			return false;
		}

		$parsed = DateTimeImmutable::createFromFormat(
			'!Y-m-d\TH:i:s.v\Z',
			$value,
			new DateTimeZone( 'UTC' )
		);

		return false !== $parsed && $value === $parsed->format( 'Y-m-d\TH:i:s.v\Z' );
	}

	/**
	 * Check an unsigned JavaScript-safe integer.
	 *
	 * @param mixed $value Candidate count.
	 * @return bool
	 */
	private static function is_unsigned_safe_integer( $value ) {
		return is_int( $value ) && 0 <= $value && self::MAX_SAFE_INTEGER >= $value;
	}
}

WP_CLI::add_command(
	'digitalogic seo-monitor status',
	array( 'Digitalogic_SEO_Monitor_Status_CLI', 'status' )
);
