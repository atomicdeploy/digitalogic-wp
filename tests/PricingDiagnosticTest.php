<?php
/**
 * Pricing diagnostic contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify the provider-neutral diagnostic boundary. */
final class PricingDiagnosticTest extends TestCase {

	/** The factory emits every required field while removing sensitive details. */
	public function test_factory_emits_one_complete_bounded_provider_neutral_contract(): void {
		$details = array(
			'state_revision' => 'sha256:' . str_repeat( 'a', 64 ),
			'client_secret'  => 'must-not-survive',
			'counts'         => array(
				'rows'     => 757,
				'distinct' => 757,
			),
		);

		$diagnostic = Digitalogic_Pricing_Diagnostic::make(
			'optional_digest_mismatch',
			'warning',
			false,
			'<b>The optional digest differs, but semantic identity is intact.</b>',
			true,
			'Refresh using the canonical state revision.',
			$details
		);

		$this->assertSame(
			array( 'code', 'severity', 'blocking', 'reason', 'retryable', 'recovery_action', 'details' ),
			array_keys( $diagnostic )
		);
		$this->assertSame( 'optional_digest_mismatch', $diagnostic['code'] );
		$this->assertSame( 'warning', $diagnostic['severity'] );
		$this->assertFalse( $diagnostic['blocking'] );
		$this->assertSame( 'The optional digest differs, but semantic identity is intact.', $diagnostic['reason'] );
		$this->assertTrue( $diagnostic['retryable'] );
		$this->assertSame( 'Refresh using the canonical state revision.', $diagnostic['recovery_action'] );
		$this->assertSame( 757, $diagnostic['details']['counts']['rows'] );
		$this->assertArrayNotHasKey( 'client_secret', $diagnostic['details'] );
	}

	/** Nested REST/WebSocket wrappers cannot erase actionable diagnostic fields. */
	public function test_normalizer_preserves_nested_transport_diagnostic_and_safe_details(): void {
		$payload = array(
			'code'    => 'transport_wrapper',
			'message' => 'Wrapper message',
			'details' => array(
				'diagnostic' => array(
					'code'            => 'pricing_state_revision_conflict',
					'severity'        => 'error',
					'blocking'        => true,
					'reason'          => 'A newer canonical state is already active.',
					'retryable'       => false,
					'recovery_action' => 'Read the current state before proposing another mutation.',
					'details'         => array(
						'current_state_revision' => 'sha256:' . str_repeat( 'b', 64 ),
						'authorization'          => 'Bearer must-not-survive',
					),
				),
			),
		);

		$diagnostic = Digitalogic_Pricing_Diagnostic::normalize( $payload );

		$this->assertSame( 'pricing_state_revision_conflict', $diagnostic['code'] );
		$this->assertSame( 'A newer canonical state is already active.', $diagnostic['reason'] );
		$this->assertSame(
			'Read the current state before proposing another mutation.',
			$diagnostic['recovery_action']
		);
		$this->assertSame(
			'sha256:' . str_repeat( 'b', 64 ),
			$diagnostic['details']['current_state_revision']
		);
		$this->assertArrayNotHasKey( 'authorization', $diagnostic['details'] );
	}

	/** Blocking results always use error severity and survive WP_Error transport. */
	public function test_blocking_diagnostics_are_errors_and_wp_error_retains_all_fields(): void {
		$error = Digitalogic_Pricing_Diagnostic::error(
			'unsafe_revision_mismatch',
			'The proposed state could overwrite a newer revision.',
			409,
			false,
			'Read the current revision and rebuild the proposal.',
			array( 'current_revision' => 'sha256:' . str_repeat( 'c', 64 ) ),
			true,
			'info'
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$data = $error->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 'error', $data['severity'] );
		$this->assertTrue( $data['blocking'] );
		$this->assertFalse( $data['retryable'] );
		$this->assertSame( 'Read the current revision and rebuild the proposal.', $data['recovery_action'] );
		$this->assertSame( 'sha256:' . str_repeat( 'c', 64 ), $data['details']['current_revision'] );

		$this->assertSame( $data, array_merge( $data, Digitalogic_Pricing_Diagnostic::normalize( $error ) ) );
	}

	/** The seam contains no provider, consumer, or version dialect. */
	public function test_factory_has_no_provider_or_consumer_dialect_dependency(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-digitalogic-pricing-diagnostic.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsString( $source );
		$this->assertDoesNotMatchRegularExpression( '/Patris|WooCommerce|Excel|schema_version|\/v\d/i', $source );
	}
}
