<?php
/**
 * Explicit account consolidation and durable exact-operation recovery.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Digitalogic\Laravel\Services\MobileAccountMerge as Merge;
use Digitalogic\Laravel\Services\MobileMergeOperation as Operation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.

/** No account is changed by discovery or a completed phone-verification result. */
final class MobileMergeController {
	/**
	 * Read a review bound to both current proofs and canonical provider state.
	 *
	 * @param Request $request Native request.
	 * @return Response Review or prerequisite status.
	 */
	public function plan( Request $request ): Response {
		$session = MobileIdentityController::enabled() ? Identity::authenticate( $request->headers->get( 'Authorization', '' ) ) : null;
		if ( ! $session ) {
			return $this->json( array( 'code' => 'unauthorized' ), 401 );
		}
		if ( ! Merge::enabled() ) {
			return $this->json( array( 'code' => 'merge_unavailable' ), 503 );
		}
		try {
			return $this->json( Merge::plan( $session ) );
		} catch ( \Throwable $error ) {
			return $this->json( array( 'code' => 'merge_inventory_unavailable' ), 503 );
		}
	}

	/**
	 * Recover the exact immutable request before attempting a new transition.
	 *
	 * @param Request $request Native request.
	 * @param string  $id Operation UUID for lookup.
	 * @return Response Durable result.
	 */
	public function handle( Request $request, string $id = '' ): Response {
		if ( ! MobileIdentityController::enabled() || ! Merge::enabled() ) {
			return $this->json( array( 'code' => 'merge_unavailable' ), 503 );
		}
		$input = 'POST' === $request->method() ? $request->json()->all() : null;
		$id    = Operation::uuid( $input['operation_id'] ?? $id );
		if ( ! $id ) {
			return $this->json( array( 'code' => 'invalid_operation_id' ), 400 );
		}
		$header = $request->headers->get( 'Authorization', '' );
		try {
			$result = Operation::recover( $header, $id, $input );
			if ( null === $result ) {
				$session = Identity::authenticate( $header );
				if ( ! $session ) {
					return $this->json( array( 'code' => 'unauthorized' ), 401 );
				}
				if ( null === $input ) {
					return $this->json(
						array(
							'operation_id'     => $id,
							'operation_status' => 'unknown',
						),
						404
					);
				}
				$result = Operation::submit( $session, $input );
			}
			if ( is_wp_error( $result ) ) {
				return $this->json( array( 'code' => $result->get_error_code() ), (int) ( $result->get_error_data()['status'] ?? 503 ) );
			}
			$status = 'processing' === ( $result['operation_status'] ?? '' ) ? 202 : ( 'rejected' === ( $result['operation_status'] ?? '' ) && null !== $input ? 409 : 200 );
			return $this->json( $result, $status );
		} catch ( \Throwable $error ) {
			return $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'processing',
					'code'             => 'merge_temporarily_unavailable',
				),
				503
			);
		}
	}

	/**
	 * Keep plans, profile and recovered credentials out of all shared caches.
	 *
	 * @param array $data Authorized response only.
	 * @param int   $status HTTP status.
	 * @return Response Private JSON.
	 */
	private function json( array $data, int $status = 200 ): Response {
		return new Response(
			wp_json_encode( $data ),
			$status,
			array(
				'Content-Type'  => 'application/json; charset=UTF-8',
				'Cache-Control' => 'private, no-store',
			)
		);
	}
}
