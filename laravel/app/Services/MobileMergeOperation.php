<?php
/**
 * Immutable account-merge journal and exact-operation recovery.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.WP.GlobalVariablesOverride -- The identity transaction installs a private cache and restores the original in finally.

use Digitalogic\Laravel\Http\Controllers\MobileIdentityController;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Identity transitions and recovery require transactional current-state reads.

/** An old credential can recover its own accepted result, never authenticate as an alias. */
final class MobileMergeOperation {
	/**
	 * Validate the client operation UUID.
	 *
	 * @param mixed $id Client identifier.
	 * @return string|null Canonical UUID.
	 */
	public static function uuid( $id ): ?string {
		return is_string( $id ) && preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $id ) ? strtolower( $id ) : null;
	}

	/**
	 * Hash the exact logical immutable request, independent of JSON key order.
	 *
	 * @param array $input Submitted intent.
	 * @return string Hash, never a credential.
	 */
	public static function payloadHash( array $input ): string {
		if ( is_array( $input['choices'] ?? null ) ) {
			ksort( $input['choices'] );
		}
		ksort( $input );
		return hash( 'sha256', wp_json_encode( $input ) );
	}

	/**
	 * Recover through the initiating token hash or a fresh survivor session.
	 *
	 * @param string     $header Captured native authorization.
	 * @param string     $id Exact operation UUID.
	 * @param array|null $input Immutable POST retry, otherwise read-only lookup.
	 * @return array|\WP_Error|null Result, rejection, or no existing operation.
	 */
	public static function recover( string $header, string $id, ?array $input = null ) {
		global $wpdb;
		if ( ! self::uuid( $id ) || ! preg_match( '/\ABearer ([a-f0-9]{64})\z/iD', $header, $match ) ) {
			return new \WP_Error( 'unauthorized', '', array( 'status' => 401 ) );
		}
		$current = MobileIdentityStore::authenticate( $header );
		$hash    = hash( 'sha256', $match[1] );
		$table   = MobileAccountMerge::table();
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id=%s AND (actor_token_hash=%s OR target_user_id=%d) LIMIT 2', $table, strtolower( $id ), $hash, (int) ( $current['user_id'] ?? 0 ) ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return new \WP_Error( 'merge_storage_unavailable', '', array( 'status' => 503 ) );
		}
		if ( count( $rows ) > 1 ) {
			return new \WP_Error( 'ambiguous_operation', '', array( 'status' => 409 ) );
		}
		if ( ! $rows ) {
			return null;
		}
		$row  = $rows[0];
		$lock = 'dgl-merge-op-' . substr( $row['operation_key'], 0, 40 );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) {
			return self::pending( $id );
		}
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s', $table, $row['operation_key'] ), ARRAY_A );
			if ( ! $row || '' !== $wpdb->last_error ) {
				return self::pending( $id ); }
			if ( $current ) {
				clean_user_cache( (int) $current['user_id'] );
			}
			$current = MobileIdentityStore::authenticate( $header );
			if ( null !== $input && ! hash_equals( $row['payload_hash'], self::payloadHash( $input ) ) ) {
				return new \WP_Error( 'merge_payload_conflict', '', array( 'status' => 409 ) );
			}
			$as_target    = $current && (int) $current['user_id'] === (int) $row['target_user_id'];
			$source_epoch = $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID=%d", $row['source_user_id'] ) );
			$as_source    = hash_equals( $row['actor_token_hash'], $hash ) && is_string( $source_epoch ) && hash_equals( $row['actor_auth_epoch'], MobileIdentityStore::hash( $source_epoch ) ) && ( ( $current && (int) $current['user_id'] === (int) $row['source_user_id'] ) || ( 'accepted' === $row['state'] && (int) $row['recovery_until'] > time() ) );
			if ( ! $as_target && ! $as_source ) {
				return new \WP_Error( 'merge_recovery_requires_login', '', array( 'status' => 401 ) );
			}
			if ( 'validating' === $row['state'] ) {
				// The operation lock is free and provider mutation was never entered.
				return self::reject( $row, 'merge_interrupted_before_write' );
			}
			if ( 'rejected' === $row['state'] ) {
				return self::rejectedResponse( $row );
			}
			if ( 'accepted' !== $row['state'] ) {
				return self::pending( $id );
			}
			$result = json_decode( MobileRegistrationStore::open( (string) $row['response_ciphertext'], 'account-merge-result' ) ?? '', true );
			if ( ! is_array( $result ) ) {
				return self::pending( $id );
			}
			if ( $as_target ) {
				$result['profile'] = ( new MobileIdentityController() )->profileData( (int) $row['target_user_id'] );
				unset( $result['access_token'], $result['session_id'], $result['expires_at'], $result['token_type'] );
			} elseif ( ! MobileIdentityStore::authenticate( 'Bearer ' . ( $result['access_token'] ?? '' ) ) ) {
				unset( $result['access_token'], $result['session_id'], $result['expires_at'], $result['token_type'], $result['profile'] );
				$result['requires_target_login'] = true;
			} else {
				$result['profile'] = ( new MobileIdentityController() )->profileData( (int) $row['target_user_id'] );
			}
			return $result;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Reserve an immutable intent before checking proof/revision, then commit one transition.
	 *
	 * @param array $session Current source session.
	 * @param array $input Exact user-confirmed intent.
	 * @return array|\WP_Error Durable result or unresolved state.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 * @throws \UnexpectedValueException When the operation cannot preserve its verified boundary.
	 */
	public static function submit( array $session, array $input ) {
		global $wpdb;
		$id = self::uuid( $input['operation_id'] ?? null );
		if ( ! $id ) {
			return new \WP_Error( 'invalid_operation_id', '', array( 'status' => 400 ) );
		}
		$key            = MobileIdentityStore::hash( 'account-merge:' . $session['user_id'] . ':' . $id );
		$locks          = array( 'dgl-merge-op-' . substr( $key, 0, 40 ) );
		$held           = array();
		$transaction    = false;
		$row            = null;
		$original_cache = null;
		try {
			if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $locks[0] ) ) ) {
				return self::pending( $id );
			}
			$held[] = $locks[0];
			$table  = MobileAccountMerge::table();
			$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s', $table, $key ), ARRAY_A );
			if ( $row ) {
				return hash_equals( $row['payload_hash'], self::payloadHash( $input ) ) ? ( 'rejected' === $row['state'] ? self::rejectedResponse( $row ) : self::pending( $id ) ) : new \WP_Error( 'merge_payload_conflict', '', array( 'status' => 409 ) );
			}
			if ( ! MobileIdentityStore::admit( 'merge:new:' . $session['user_id'], 10, 300 ) || ! MobileIdentityStore::admit( 'merge:new:global', 200, 300 ) ) {
				return new \WP_Error( 'merge_rate_limited', '', array( 'status' => 429 ) );
			}
			$claim = is_string( $input['plan_id'] ?? null ) && strlen( $input['plan_id'] ) <= 2048 ? json_decode( MobileRegistrationStore::open( $input['plan_id'], 'account-merge-plan' ) ?? '', true ) : null;
			$row   = array(
				'operation_key'    => $key,
				'request_id'       => $id,
				'source_user_id'   => (int) $session['user_id'],
				'target_user_id'   => is_array( $claim ) ? (int) ( $claim['target'] ?? 0 ) : 0,
				'actor_session_id' => $session['id'],
				'actor_token_hash' => $session['token_hash'],
				'actor_auth_epoch' => $session['auth_epoch'],
				'payload_hash'     => self::payloadHash( $input ),
				'state'            => 'validating',
				'created_at'       => time(),
				'updated_at'       => time(),
				'recovery_until'   => 0,
			);
			if ( 1 !== $wpdb->insert( $table, $row ) ) {
				return self::pending( $id );
			}
			$choices       = $input['choices'] ?? null;
			$valid_choices = is_array( $choices ) && count( $choices ) === 2 && ( $choices['cart'] ?? '' ) === 'combine' && ( $choices['addresses'] ?? '' ) === 'keep_target_fill_empty';
			if ( array_diff( array_keys( $input ), array( 'operation_id', 'plan_id', 'revision', 'choices', 'consent', 'device_name' ) ) || true !== ( $input['consent'] ?? false ) || ! $valid_choices || ! is_string( $input['device_name'] ?? 'Android' ) || mb_strlen( $input['device_name'] ?? 'Android' ) > 80 || ! is_array( $claim ) || (int) ( $claim['source'] ?? 0 ) !== (int) $session['user_id'] || ( $claim['session_id'] ?? '' ) !== $session['id'] || ! is_string( $input['revision'] ?? null ) || ! hash_equals( (string) ( $claim['revision'] ?? '' ), $input['revision'] ) ) {
				return self::reject( $row, 'invalid_merge_intent' );
			}
			if ( (int) ( $claim['expires_at'] ?? 0 ) <= time() ) {
				return self::reject( $row, 'merge_plan_expired' );
			}
			MobileAccountMerge::assertTransactional();
			$user_locks = array( 'dgl-link-user-' . $session['user_id'], 'dgl-link-user-' . $claim['target'] );
			sort( $user_locks, SORT_STRING );
			foreach ( $user_locks as $lock ) {
				if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) {
					return self::reject( $row, 'identity_busy' );
				}
				$held[] = $lock;
			}
			$original_cache             = $GLOBALS['wp_object_cache'];
			$GLOBALS['wp_object_cache'] = new MobileMergeObjectCache( null, (array) ( $original_cache->global_groups ?? array() ), (array) ( $original_cache->non_persistent_groups ?? array() ) );
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
				return self::reject( $row, 'merge_transaction_unavailable' );
			}
			$transaction = true;
			MobileMergeEffects::begin( $key );
			// Lock both principals, the current source session and the Google binding range.
			$wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID IN (%d,%d) ORDER BY ID FOR UPDATE", $session['user_id'], $claim['target'] ) );
			$wpdb->get_results( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key='auth_google_id' FOR UPDATE" );
			$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND user_id=%d FOR UPDATE', MobileIdentityStore::table( 'sessions' ), $session['id'], $session['user_id'] ), ARRAY_A );
			clean_user_cache( (int) $session['user_id'] );
			clean_user_cache( (int) $claim['target'] );
			$user = get_user_by( 'id', (int) $session['user_id'] );
			if ( ! $current || ! $user || (int) $current['expires_at'] <= time() || ! hash_equals( $current['token_hash'], $session['token_hash'] ) || ! hash_equals( $current['auth_epoch'], MobileIdentityStore::hash( $user->user_pass ) ) ) {
				throw new \UnexpectedValueException( 'merge_source_session_expired' );
			}
			$plan = MobileAccountMerge::plan( $current );
			if ( 'review_required' !== $plan['status'] || ! is_string( $plan['revision'] ) || ! hash_equals( $plan['revision'], $input['revision'] ) || (int) $plan['target_user_id'] !== (int) $claim['target'] ) {
				throw new \UnexpectedValueException( 'merge_plan_changed' );
			}
			$context = MobileAccountMerge::context( $current );
			$source  = MobileAccountMerge::inventory( (int) $context['source'] );
			$target  = MobileAccountMerge::inventory( (int) $context['target'] );
			// This marker is in the same transaction as every canonical change and the receipt.
			if ( 1 !== $wpdb->update( $table, array( 'state' => 'creating' ), array( 'operation_key' => $key ) ) ) {
				throw new \RuntimeException( 'merge_journal_unavailable' );
			}
			MobileAccountMerge::consolidate( $context, $source, $target, $key );
			if ( 1 !== (int) $wpdb->get_var( 'SELECT @@in_transaction' ) ) {
				throw new \RuntimeException( 'provider_transaction_boundary_changed' );
			}
			$session_data = MobileIdentityStore::issueSession( (int) $context['target'], (string) ( $input['device_name'] ?? 'Android' ), 'otp', (int) $context['phone_proof_at'] );
			$result       = self::acceptedResponse( $id, $context, ( new MobileIdentityController() )->profileData( (int) $context['target'] ), $session_data );
			if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id=%d', MobileIdentityStore::table( 'sessions' ), $context['source'] ) ) ) {
				throw new \RuntimeException( 'Source sessions were not retired.' );
			}
			// The canonical profile is read afresh on recovery. Its country/state schemas can
			// exceed the bounded proof decoder and must not bloat the credential receipt.
			$stored_result = $result;
			unset( $stored_result['profile'] );
			$saved = $wpdb->update(
				$table,
				array(
					'state'               => 'accepted',
					'updated_at'          => time(),
					'actor_auth_epoch'    => MobileIdentityStore::hash( get_user_by( 'id', (int) $context['source'] )->user_pass ),
					'recovery_until'      => time() + 900,
					'response_ciphertext' => MobileRegistrationStore::seal( wp_json_encode( $stored_result ), 'account-merge-result' ),
					'effects_ciphertext'  => MobileMergeEffects::seal(),
					'effects_count'       => MobileMergeEffects::count(),
				),
				array( 'operation_key' => $key )
			);
			if ( 1 !== $saved || false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'merge_commit_uncertain' );
			}
			$transaction = false;
			MobileMergeEffects::stop();
			$GLOBALS['wp_object_cache'] = $original_cache;
			$original_cache             = null;
			clean_user_cache( (int) $row['source_user_id'] );
			clean_user_cache( (int) $row['target_user_id'] );
			foreach ( $source['abandoned_ids'] ?? array() as $post_id ) {
				clean_post_cache( $post_id ); }
			MobileWooCart::invalidate( MobileAccountMerge::cart( (int) $row['source_user_id'] ) );
			MobileWooCart::invalidate( MobileAccountMerge::cart( (int) $row['target_user_id'] ) );
			MobileMergeEffects::schedulePending();
			return $result;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction = false;
			}
			if ( null !== $original_cache ) {
				$GLOBALS['wp_object_cache'] = $original_cache;
				$original_cache             = null; }
			if ( $row ) {
				clean_user_cache( (int) $row['source_user_id'] );
				clean_user_cache( (int) $row['target_user_id'] );
				MobileWooCart::invalidate( MobileAccountMerge::cart( (int) $row['source_user_id'] ) );
				if ( $row['target_user_id'] > 0 ) {
					MobileWooCart::invalidate( MobileAccountMerge::cart( (int) $row['target_user_id'] ) );
				}
				if ( $error instanceof \UnexpectedValueException ) {
					return self::reject( $row, $error->getMessage() );
				}
			}
			return self::pending( $id );
		} finally {
			MobileMergeEffects::stop();
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( null !== $original_cache ) {
				$GLOBALS['wp_object_cache'] = $original_cache; }
			foreach ( array_reverse( $held ) as $lock ) {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}
	}

	/**
	 * Persist a definitive before-write rejection under the same UUID.
	 *
	 * @param array  $row Reserved operation.
	 * @param string $code Rejection reason.
	 * @return array Durable rejection or unresolved state.
	 */
	private static function reject( array $row, string $code ): array {
		global $wpdb;
		if ( 1 !== $wpdb->update(
			MobileAccountMerge::table(),
			array(
				'state'        => 'rejected',
				'failure_code' => $code,
				'updated_at'   => time(),
			),
			array(
				'operation_key' => $row['operation_key'],
				'state'         => 'validating',
			)
		) ) {
			$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s', MobileAccountMerge::table(), $row['operation_key'] ), ARRAY_A );
			return $stored && 'rejected' === $stored['state'] && is_string( $stored['failure_code'] ) ? self::rejectedResponse( $stored ) : self::pending( $row['request_id'] );
		}
		$row['failure_code'] = $code;
		return self::rejectedResponse( $row );
	}

	/**
	 * Return a terminal rejection without implying any accepted account change.
	 *
	 * @param array $row Rejected journal row.
	 * @return array Safe response.
	 */
	public static function rejectedResponse( array $row ): array {
		return array(
			'operation_id'     => $row['request_id'],
			'operation_status' => 'rejected',
			'code'             => $row['failure_code'],
		);
	}

	/**
	 * Serialize only a committed canonical transition and its single issued session.
	 *
	 * @param string $id Exact request identifier.
	 * @param array  $context Verified identities and proof timestamps.
	 * @param array  $profile Canonical survivor profile.
	 * @param array  $session Current owner-bound native session.
	 */
	public static function acceptedResponse( string $id, array $context, array $profile, array $session ): array {
		return array_merge(
			array(
				'operation_id'     => $id,
				'operation_status' => 'accepted',
				'status'           => 'merged',
				'registered'       => true,
				'merge'            => array(
					'source_user_id' => (int) $context['source'],
					'target_user_id' => (int) $context['target'],
				),
				'profile'          => $profile,
			),
			$session
		);
	}

	/**
	 * Preserve uncertain intent without replaying a provider operation.
	 *
	 * @param string $id Operation UUID.
	 * @return array Unresolved status.
	 */
	private static function pending( string $id ): array {
		return array(
			'operation_id'     => strtolower( $id ),
			'operation_status' => 'processing',
		);
	}
}
