<?php
/**
 * Native customer message center backed exclusively by Fluent Support.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Digitalogic\Laravel\Services\MobileMessages as Provider;
use Digitalogic\Laravel\Services\MobileMessageStore as Store;
use FluentSupport\App\Models\Conversation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.

/** Native credentials authorize only the current WordPress customer's messages. */
final class MobileMessageController {
	/**
	 * Read canonical composer requirements without a positional route default.
	 *
	 * @param Request $request Native request.
	 * @return Response Owner-scoped schema.
	 */
	public function schema( Request $request ): Response {
		return $this->handle( $request, '', 'schema' );
	}

	/**
	 * Recover one exact owner operation.
	 *
	 * @param Request $request Native request.
	 * @param string  $id Operation UUID.
	 * @return Response Durable operation result.
	 */
	public function lookup( Request $request, string $id ): Response {
		return $this->handle( $request, $id, 'operations' );
	}
	/**
	 * Dispatch the narrow message-center surface with account-scoped authority.
	 *
	 * @param Request $request Native request.
	 * @param string  $id Optional ticket or operation identifier.
	 * @param string  $action Optional route action.
	 * @return Response Private native result.
	 */
	public function handle( Request $request, string $id = '', string $action = '' ): Response {
		if ( ! Provider::enabled() || ! MobileIdentityController::enabled() ) {
			return $this->json( array( 'code' => 'messages_unavailable' ), 503 );
		}
		$session = Identity::authenticate( $request->headers->get( 'Authorization', '' ) );
		if ( ! $session ) {
			return $this->json( array( 'code' => 'unauthorized' ), 401 );
		}
		$user_id  = (int) $session['user_id'];
		$previous = get_current_user_id();
		wp_set_current_user( $user_id );
		try {
			if ( 'operations' === $action ) {
				return $this->operation( $request, $user_id, $id, 'lookup', 0 );
			}
			if ( 'POST' === $request->method() ) {
				$kind = '' === $id ? 'create' : ( 'messages' === $action ? 'reply' : $action );
				return $this->operation( $request, $user_id, (string) $request->json( 'operation_id', '' ), $kind, (int) $id );
			}
			$customer = Provider::customer( $user_id );
			if ( is_wp_error( $customer ) ) {
				return $this->json( array( 'code' => $customer->get_error_code() ), 403 );
			}
			if ( 'schema' === $action ) {
				return $this->json( Provider::schema() );
			}
			if ( '' === $id ) {
				return $this->json( Provider::listing( $customer, max( 1, min( 500, (int) $request->query( 'page', 1 ) ) ), mb_substr( sanitize_text_field( (string) $request->query( 'search', '' ) ), 0, 100 ) ) );
			}
			$ticket = Provider::ticket( $customer, (int) $id );
			return is_wp_error( $ticket ) ? $this->json( array( 'code' => $ticket->get_error_code() ), 404 ) : $this->json( Provider::thread( $ticket, max( 0, (int) $request->query( 'before_id', 0 ) ) ) );
		} catch ( \Throwable $error ) {
			return $this->json( array( 'code' => 'messages_temporarily_unavailable' ), 503 );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Reserve, execute or recover exactly one immutable provider operation.
	 *
	 * @param Request $request Native request.
	 * @param int     $user_id Authenticated WordPress owner.
	 * @param string  $id Client operation UUID.
	 * @param string  $kind Operation or lookup.
	 * @param int     $ticket_id Explicit owned target.
	 * @return Response Durable result or unresolved state.
	 */
	private function operation( Request $request, int $user_id, string $id, string $kind, int $ticket_id ): Response {
		$key = Store::key( $user_id, $id );
		if ( ! $key ) {
			return $this->json( array( 'code' => 'invalid_operation_id' ), 400 );
		}
		if ( ! Store::lock( $key ) ) {
			return $this->json(
				array(
					'operation_id'     => $id,
					'operation_status' => 'processing',
				),
				202
			);
		}
		try {
			$row      = Store::read( $key );
			$customer = Provider::customer( $user_id );
			if ( 'lookup' === $kind ) {
				return $row ? $this->recover( $row, $customer ) : $this->json(
					array(
						'operation_id'     => $id,
						'operation_status' => 'unknown',
					),
					404
				);
			}
			$input = $request->json()->all();
			ksort( $input );
			$hash = hash(
				'sha256',
				wp_json_encode(
					array(
						'kind'      => $kind,
						'ticket_id' => $ticket_id,
						'input'     => $input,
					)
				)
			);
			if ( $row ) {
				if ( ! hash_equals( $row['payload_hash'], $hash ) ) {
					return $this->json(
						array(
							'operation_id'     => $id,
							'operation_status' => 'conflict',
							'code'             => 'operation_payload_conflict',
						),
						409
					);
				}
				return $this->recover( $row, $customer );
			}
			// Known UUID recovery never consumes this budget. Unknown requests are bounded
			// before allocating durable metadata; a later retry keeps the same immutable UUID.
			if ( ! Identity::admit( 'messages:' . $user_id, 20, 300 ) || ! Identity::admit( 'messages-global', 300, 300 ) ) {
				return $this->json(
					array(
						'operation_id'     => $id,
						'operation_status' => 'processing',
						'code'             => 'message_rate_limited',
						'retry_after'      => 300,
					),
					429
				);
			}
			$row = array(
				'operation_key' => $key,
				'user_id'       => $user_id,
				'request_id'    => $id,
				'payload_hash'  => $hash,
				'kind'          => $kind,
				'ticket_id'     => $ticket_id,
				'message_id'    => 0,
				'state'         => 'validating',
				'error_code'    => '',
				'created_at'    => time(),
				'updated_at'    => time(),
			);
			if ( ! Store::insert( $row ) ) {
				return $this->json(
					array(
						'operation_id'     => $id,
						'operation_status' => 'processing',
					),
					202
				);
			}
			if ( is_wp_error( $customer ) ) {
				return $this->reject( $row, $customer->get_error_code() );
			}
			if ( ! in_array( $kind, array( 'create', 'reply', 'close', 'reopen' ), true ) ) {
				return $this->reject( $row, 'unsupported_message_action' );
			}
			$data = Provider::prepare( $kind, $input );
			if ( is_wp_error( $data ) ) {
				return $this->reject( $row, $data->get_error_code() );
			}
			$ticket = 'create' === $kind ? null : Provider::ticket( $customer, $ticket_id, 'reply' === $kind ? 'response' : $kind );
			if ( is_wp_error( $ticket ) ) {
				return $this->reject( $row, $ticket->get_error_code() );
			}
			if ( ! Store::update( $key, array( 'state' => 'creating' ) ) ) {
				return $this->json(
					array(
						'operation_id'     => $id,
						'operation_status' => 'processing',
					),
					202
				);
			}
			$row['state'] = 'creating';
			try {
				if ( ! $customer ) {
					$customer = Provider::createCustomer( $user_id );
					if ( is_wp_error( $customer ) || ! $customer ) {
						return $this->reject( $row, is_wp_error( $customer ) ? $customer->get_error_code() : 'customer_identity_unavailable' );
					}
				}
				$result = Provider::perform( $kind, $customer, $ticket, $data, Store::providerId( $key ) );
				if ( is_wp_error( $result ) ) {
					return $this->reject( $row, $result->get_error_code() );
				}
				if ( Store::update( $key, array_merge( $result, array( 'state' => 'accepted' ) ) ) ) {
					$row = array_merge( $row, $result, array( 'state' => 'accepted' ) );
				}
			} catch ( \Throwable $error ) {
				// The provider may already have persisted a message; exact-marker recovery is the only safe retry.
				return $this->recover( $row, $customer );
			}
			return $this->recover( $row, $customer );
		} finally {
			Store::unlock( $key );
		}
	}

	/**
	 * Persist a terminal pre-write failure before allowing the client to revise its draft.
	 *
	 * @param array  $row Reserved operation.
	 * @param string $code Safe bounded failure code.
	 * @return Response Rejection tombstone or unresolved storage failure.
	 */
	private function reject( array $row, string $code ): Response {
		if ( ! Store::update(
			$row['operation_key'],
			array(
				'state'      => 'rejected',
				'error_code' => $code,
			)
		) ) {
			return $this->json(
				array(
					'operation_id'     => $row['request_id'],
					'operation_status' => 'processing',
				),
				202
			);
		}
		return $this->json(
			array(
				'operation_id'     => $row['request_id'],
				'operation_status' => 'rejected',
				'code'             => $code,
			),
			422
		);
	}

	/**
	 * Recover canonical results without automatically repeating messages or provider hooks.
	 *
	 * @param array       $row Locked operation metadata.
	 * @param object|null $customer Current verified provider identity.
	 * @return Response Native operation result.
	 */
	private function recover( array $row, $customer ): Response {
		$base = array(
			'operation_id'     => $row['request_id'],
			'operation_status' => $row['state'],
		);
		if ( 'validating' === $row['state'] ) {
			return $this->reject( $row, 'operation_interrupted_before_write' );
		}
		if ( 'rejected' === $row['state'] ) {
			return $this->json( array_merge( $base, array( 'code' => $row['error_code'] ) ) );
		}
		if ( ! $customer || is_wp_error( $customer ) ) {
			return $this->json( array( 'code' => 'messages_forbidden' ), 403 );
		}
		if ( 'creating' === $row['state'] ) {
			$result = Provider::recover( $row, $customer );
			if ( $result && Store::update( $row['operation_key'], array_merge( $result, array( 'state' => 'accepted' ) ) ) ) {
				$row                      = array_merge( $row, $result, array( 'state' => 'accepted' ) );
				$base['operation_status'] = 'accepted';
			} else {
				return $this->json(
					array(
						'operation_id'     => $row['request_id'],
						'operation_status' => 'processing',
					),
					202
				);
			}
		}
		$ticket = Provider::ticket( $customer, (int) $row['ticket_id'] );
		if ( is_wp_error( $ticket ) ) {
			return $this->json( array( 'code' => 'ticket_not_found' ), 404 );
		}
		$base['ticket'] = Provider::ticketData( $ticket );
		if ( (int) $row['message_id'] > 0 ) {
			$message = Conversation::where( 'ticket_id', $ticket->id )->where( 'id', $row['message_id'] )->where( 'conversation_type', 'response' )->first();
			if ( $message ) {
				$base['message'] = Provider::messageData( $message, (int) $customer->id );
			}
		}
		return $this->json( $base );
	}

	/**
	 * Return private no-store JSON; public catalog CORS never grants mutation authority.
	 *
	 * @param array $data Safe native response.
	 * @param int   $status HTTP status.
	 * @return Response JSON result.
	 */
	private function json( array $data, int $status = 200 ): Response {
		return response()->json(
			$data,
			$status,
			array(
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}
