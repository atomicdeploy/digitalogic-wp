<?php
/**
 * Sanitized SEO monitor WP-CLI transport tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify capability, process, and schema boundaries.
 */
final class WpCliSeoMonitorStatusTest extends TestCase {

	private const GENERIC_ERROR   = 'SEO monitor status is unavailable.';
	private const SECRET_SENTINEL = 'https://private.example/?token=do-not-print';

	/**
	 * Reset command output and capability fixtures.
	 */
	protected function setUp(): void {
		$GLOBALS['digitalogic_test_capabilities'] = array();
		WP_CLI::$errors                           = array();
		WP_CLI::$logs                             = array();
		WP_CLI::$warnings                         = array();
	}

	/**
	 * The exact command is registered.
	 */
	public function test_exact_command_is_registered(): void {
		$this->assertArrayHasKey( 'digitalogic seo-monitor status', WP_CLI::$commands );
	}

	/**
	 * Capability denial happens before argument handling or bridge execution.
	 */
	public function test_capability_is_required_before_argument_validation(): void {
		$runner_calls = 0;
		$command      = new Digitalogic_SEO_Monitor_Status_CLI(
			static function () use ( &$runner_calls ) {
				++$runner_calls;
				return self::successful_result( self::valid_payload() );
			}
		);

		$command->status( array( 'unexpected' ), array( 'format' => 'json' ) );

		$this->assertSame( 0, $runner_calls );
		$this->assertSame( array( 'Run this command with --user=<administrator>.' ), WP_CLI::$errors );
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/**
	 * No command-specific positional or named arguments are accepted.
	 */
	public function test_authorized_command_rejects_all_command_specific_arguments(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$runner_calls = 0;
		$command      = new Digitalogic_SEO_Monitor_Status_CLI(
			static function () use ( &$runner_calls ) {
				++$runner_calls;
				return self::successful_result( self::valid_payload() );
			}
		);

		$command->status( array( 'unexpected' ), array() );
		$command->status( array(), array( 'format' => 'json' ) );

		$this->assertSame( 0, $runner_calls );
		$this->assertSame(
			array(
				'This command does not accept command-specific arguments.',
				'This command does not accept command-specific arguments.',
			),
			WP_CLI::$errors
		);
		$this->assertSame( array(), WP_CLI::$logs );
	}

	/**
	 * The authorized command supplies only the reviewed process contract.
	 */
	public function test_authorized_command_uses_exact_bridge_contract(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$captured = array();
		$payload  = self::valid_payload();
		$command  = new Digitalogic_SEO_Monitor_Status_CLI(
			static function ( $argv, $environment, $timeout, $max_bytes ) use ( &$captured, $payload ) {
				$captured = array(
					'argv'        => $argv,
					'environment' => $environment,
					'timeout'     => $timeout,
					'max_bytes'   => $max_bytes,
				);
				return self::successful_result( $payload );
			}
		);

		$command->status( array(), array() );

		$this->assertSame(
			array(
				'/usr/bin/sudo',
				'-n',
				'--',
				'/usr/local/libexec/digitalogic-seo-monitor-status',
			),
			$captured['argv']
		);
		$this->assertSame(
			array(
				'PATH'   => '/usr/sbin:/usr/bin:/sbin:/bin',
				'LANG'   => 'C',
				'LC_ALL' => 'C',
			),
			$captured['environment']
		);
		$this->assertSame( 5.0, $captured['timeout'] );
		$this->assertSame( 32768, $captured['max_bytes'] );
		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertCount( 1, WP_CLI::$logs );
		$this->assertSame( $payload, json_decode( WP_CLI::$logs[0], true ) );
	}

	/**
	 * Nonavailable source envelopes are accepted only with null summaries and fingerprints.
	 */
	public function test_partial_snapshot_contract_is_reconstructed(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$payload                               = self::valid_payload();
		$payload['snapshot_state']             = 'partial';
		$payload['sources']['owner_approvals'] = array(
			'read_state'  => 'unsafe',
			'updated_at'  => null,
			'summary'     => null,
			'fingerprint' => null,
		);
		$command                               = new Digitalogic_SEO_Monitor_Status_CLI(
			static function () use ( $payload ) {
				return self::successful_result( $payload );
			}
		);

		$command->status( array(), array() );

		$this->assertSame( array(), WP_CLI::$errors );
		$this->assertSame( $payload, json_decode( WP_CLI::$logs[0], true ) );
	}

	/**
	 * Transport failures never echo raw process output.
	 */
	public function test_transport_failures_are_generic_and_secret_safe(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$valid_json = wp_json_encode( self::valid_payload(), JSON_UNESCAPED_SLASHES ) . "\n";
		$cases      = array(
			'nonzero'   => array(
				'exit_code' => 1,
				'stdout'    => self::SECRET_SENTINEL,
				'stderr'    => '',
				'timed_out' => false,
				'overflow'  => false,
			),
			'stderr'    => array(
				'exit_code' => 0,
				'stdout'    => $valid_json,
				'stderr'    => self::SECRET_SENTINEL,
				'timed_out' => false,
				'overflow'  => false,
			),
			'timed_out' => array(
				'exit_code' => null,
				'stdout'    => self::SECRET_SENTINEL,
				'stderr'    => '',
				'timed_out' => true,
				'overflow'  => false,
			),
			'overflow'  => array(
				'exit_code' => null,
				'stdout'    => str_repeat( 'x', 32768 ),
				'stderr'    => '',
				'timed_out' => false,
				'overflow'  => true,
			),
			'oversize'  => array(
				'exit_code' => 0,
				'stdout'    => str_repeat( 'x', 32769 ),
				'stderr'    => '',
				'timed_out' => false,
				'overflow'  => false,
			),
		);

		foreach ( $cases as $name => $result ) {
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();
			$command        = new Digitalogic_SEO_Monitor_Status_CLI(
				static function () use ( $result ) {
					return $result;
				}
			);

			$command->status( array(), array() );

			$this->assertSame( array( self::GENERIC_ERROR ), WP_CLI::$errors, $name );
			$this->assertSame( array(), WP_CLI::$logs, $name );
			$this->assertStringNotContainsString(
				self::SECRET_SENTINEL,
				implode( "\n", array_merge( WP_CLI::$errors, WP_CLI::$logs ) ),
				$name
			);
		}
	}

	/**
	 * Malformed documents and unknown fields fail closed without raw echo.
	 */
	public function test_malformed_and_unknown_key_payloads_fail_closed(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$with_unknown               = self::valid_payload();
		$with_unknown['source_url'] = self::SECRET_SENTINEL;
		$nested_unknown             = self::valid_payload();
		$nested_unknown['sources']['owner_approvals']['summary']['collaborator'] = self::SECRET_SENTINEL;
		$cases = array(
			'malformed'      => '{"schema":',
			'multiple_lines' => wp_json_encode( self::valid_payload() ) . "\n" . self::SECRET_SENTINEL,
			'unknown_top'    => wp_json_encode( $with_unknown, JSON_UNESCAPED_SLASHES ),
			'unknown_nested' => wp_json_encode( $nested_unknown, JSON_UNESCAPED_SLASHES ),
		);

		foreach ( $cases as $name => $stdout ) {
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();
			$command        = new Digitalogic_SEO_Monitor_Status_CLI(
				static function () use ( $stdout ) {
					return array(
						'exit_code' => 0,
						'stdout'    => $stdout . "\n",
						'stderr'    => '',
						'timed_out' => false,
						'overflow'  => false,
					);
				}
			);

			$command->status( array(), array() );

			$this->assertSame( array( self::GENERIC_ERROR ), WP_CLI::$errors, $name );
			$this->assertSame( array(), WP_CLI::$logs, $name );
			$this->assertStringNotContainsString(
				self::SECRET_SENTINEL,
				implode( "\n", array_merge( WP_CLI::$errors, WP_CLI::$logs ) ),
				$name
			);
		}
	}

	/**
	 * Every controlled enum, type, and exact-shape constraint is enforced.
	 */
	public function test_schema_drift_is_rejected(): void {
		$GLOBALS['digitalogic_test_capabilities']['manage_options'] = true;
		$mutations = array(
			'wrong_schema'                => static function ( &$payload ) {
				$payload['schema'] = 'digitalogic.seo-monitor.status/v2';
			},
			'invalid_timestamp'           => static function ( &$payload ) {
				$payload['generated_at'] = '2026-02-31T25:61:61.999Z';
			},
			'snapshot_mismatch'           => static function ( &$payload ) {
				$payload['snapshot_state'] = 'partial';
			},
			'missing_source'              => static function ( &$payload ) {
				unset( $payload['sources']['pending_decisions'] );
			},
			'extra_envelope_key'          => static function ( &$payload ) {
				$payload['sources']['owner_approvals']['path'] = '/private';
			},
			'invalid_read_state'          => static function ( &$payload ) {
				$payload['sources']['owner_approvals']['read_state'] = 'readable';
			},
			'bad_fingerprint'             => static function ( &$payload ) {
				$payload['sources']['owner_approvals']['fingerprint'] = 'sha256:' . str_repeat( 'a', 64 );
			},
			'negative_count'              => static function ( &$payload ) {
				$payload['sources']['owner_approvals']['summary']['pending'] = -1;
			},
			'float_count'                 => static function ( &$payload ) {
				$payload['sources']['pending_decisions']['summary']['total'] = 1.5;
			},
			'unsafe_integer'              => static function ( &$payload ) {
				$payload['sources']['pending_decisions']['summary']['total'] = 9007199254740992;
			},
			'invalid_boolean'             => static function ( &$payload ) {
				$payload['sources']['google_docs_scan_state']['summary']['inventory_complete'] = 1;
			},
			'invalid_run_state'           => static function ( &$payload ) {
				$payload['sources']['latest_completed']['summary']['run_state'] = 'running';
			},
			'available_missing_timestamp' => static function ( &$payload ) {
				$payload['sources']['owner_approvals']['updated_at'] = null;
			},
			'owner_total_mismatch'        => static function ( &$payload ) {
				$payload['sources']['owner_approvals']['summary']['total'] = 6;
			},
			'pending_total_mismatch'      => static function ( &$payload ) {
				$payload['sources']['pending_decisions']['summary']['total'] = 5;
			},
			'ledger_total_mismatch'       => static function ( &$payload ) {
				$payload['sources']['google_docs_migration_ledger']['summary']['total'] = 10;
			},
			'changed_exceeds_seen'        => static function ( &$payload ) {
				$payload['sources']['google_docs_scan_state']['summary']['documents_changed'] = 11;
			},
			'errors_exceed_seen'          => static function ( &$payload ) {
				$payload['sources']['google_docs_scan_state']['summary']['errors'] = 11;
			},
			'nonavailable_has_data'       => static function ( &$payload ) {
				$payload['snapshot_state'] = 'partial';
				$payload['sources']['owner_approvals']['read_state'] = 'missing';
				$payload['sources']['owner_approvals']['fingerprint'] = null;
			},
			'nonavailable_has_updated_at' => static function ( &$payload ) {
				$payload['snapshot_state'] = 'partial';
				$payload['sources']['owner_approvals']['read_state'] = 'missing';
				$payload['sources']['owner_approvals']['summary'] = null;
				$payload['sources']['owner_approvals']['fingerprint'] = null;
			},
		);

		foreach ( $mutations as $name => $mutate ) {
			$payload = self::valid_payload();
			$mutate( $payload );
			WP_CLI::$errors = array();
			WP_CLI::$logs   = array();
			$command        = new Digitalogic_SEO_Monitor_Status_CLI(
				static function () use ( $payload ) {
					return self::successful_result( $payload );
				}
			);

			$command->status( array(), array() );

			$this->assertSame( array( self::GENERIC_ERROR ), WP_CLI::$errors, $name );
			$this->assertSame( array(), WP_CLI::$logs, $name );
		}
	}

	/**
	 * Build one successful process result.
	 *
	 * @param array $payload Sanitized payload.
	 * @return array
	 */
	private static function successful_result( $payload ) {
		return array(
			'exit_code' => 0,
			'stdout'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n",
			'stderr'    => '',
			'timed_out' => false,
			'overflow'  => false,
		);
	}

	/**
	 * Build one complete valid v1 payload.
	 *
	 * @return array
	 */
	private static function valid_payload() {
		$fingerprint = 'hmac-sha256:' . str_repeat( 'a', 64 );
		$updated_at  = '2026-07-26T06:30:00.000Z';

		return array(
			'schema'         => 'digitalogic.seo-monitor.status/v1',
			'generated_at'   => '2026-07-26T06:31:00.123Z',
			'snapshot_state' => 'complete',
			'sources'        => array(
				'latest_completed'             => array(
					'read_state'  => 'available',
					'updated_at'  => $updated_at,
					'summary'     => array(
						'run_state'            => 'attention',
						'critical_findings'    => 1,
						'significant_findings' => 2,
						'tracked_findings'     => 3,
						'owner_decisions'      => 4,
					),
					'fingerprint' => $fingerprint,
				),
				'owner_approvals'              => array(
					'read_state'  => 'available',
					'updated_at'  => $updated_at,
					'summary'     => array(
						'total'    => 5,
						'pending'  => 2,
						'approved' => 2,
						'rejected' => 1,
						'expired'  => 0,
					),
					'fingerprint' => $fingerprint,
				),
				'pending_decisions'            => array(
					'read_state'  => 'available',
					'updated_at'  => $updated_at,
					'summary'     => array(
						'total'       => 4,
						'critical'    => 1,
						'significant' => 1,
						'tracked'     => 1,
						'other'       => 1,
					),
					'fingerprint' => $fingerprint,
				),
				'google_docs_scan_state'       => array(
					'read_state'  => 'available',
					'updated_at'  => $updated_at,
					'summary'     => array(
						'inventory_complete' => false,
						'access_blocked'     => true,
						'cursor_pending'     => false,
						'documents_seen'     => 10,
						'documents_changed'  => 3,
						'tabs_seen'          => 12,
						'errors'             => 1,
					),
					'fingerprint' => $fingerprint,
				),
				'google_docs_migration_ledger' => array(
					'read_state'  => 'available',
					'updated_at'  => $updated_at,
					'summary'     => array(
						'total'               => 9,
						'discovered'          => 1,
						'not_product_content' => 1,
						'needs_mapping'       => 1,
						'staged_conflict'     => 1,
						'applied_verified'    => 1,
						'failed_retryable'    => 1,
						'blocked'             => 1,
						'other'               => 2,
					),
					'fingerprint' => $fingerprint,
				),
			),
		);
	}
}
