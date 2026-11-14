<?php
/**
 * Atomic destination changes with immutable owner-scoped receipts and Woo projections.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 native adapter contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Current owner locks, revisions and receipts bypass shared caches.

/** Does not alter contact identity, past order addresses or authentication credentials. */
final class MobileAddressMutation {

	/**
	 * Stable owner-scoped UUID key; never rewrite old operation ownership after a merge.
	 *
	 * @param int    $owner Existing WordPress owner.
	 * @param string $uuid Explicit client operation UUID.
	 */
	public static function key( int $owner, string $uuid ): string {
		return MobileIdentityStore::hash( 'saved-address:' . $owner . ':' . strtolower( $uuid ) );
	}

	/**
	 * Read a committed result without repeating any mutation or provider hook.
	 *
	 * @param int    $owner Authenticated owner.
	 * @param string $uuid Exact requested operation.
	 * @return array|\WP_Error Stored receipt or unresolved absence.
	 */
	public static function lookup( int $owner, string $uuid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s AND user_id=%d', MobileAddressBookStore::table( 'address_operations' ), self::key( $owner, $uuid ), $owner ), ARRAY_A );
		return $row ? self::receipt( $row ) : new \WP_Error( 'operation_not_found', 'نتیجه درخواست هنوز در دسترس نیست.', array( 'status' => 404 ) );
	}

	/**
	 * Only a durable terminal state allows the client to release its immutable pending operation.
	 *
	 * @param array $row Exact owned receipt row.
	 * @return array|\WP_Error Public operation response.
	 */
	private static function receipt( array $row ) {
		if ( ! in_array( $row['state'], array( 'accepted', 'rejected' ), true ) ) {
			return array(
				'operation_id'     => $row['request_id'],
				'operation_status' => 'processing',
			);
		}
		$plain  = MobileRegistrationStore::open( (string) $row['response_ciphertext'], 'saved-address-result' );
		$result = null === $plain ? null : json_decode( $plain, true );
		return is_array( $result ) && ( $result['operation_id'] ?? null ) === $row['request_id'] ? $result : new \WP_Error( 'address_receipt_unavailable', 'نتیجه ذخیره‌شده در دسترس نیست.', array( 'status' => 503 ) );
	}

	/**
	 * Validate the immutable wire intent without accepting owner, price or person fields.
	 *
	 * @param string      $action create, update, delete or defaults.
	 * @param string|null $id Existing row for update/delete.
	 * @param array       $input Client intent.
	 * @return array|\WP_Error Normalized intent.
	 */
	public static function normalize( string $action, ?string $id, array $input ) {
		$allowed = array( 'operation_id', 'base_revision' );
		if ( in_array( $action, array( 'create', 'update' ), true ) ) {
			$allowed[] = 'address';
		}
		if ( 'create' === $action ) {
			$allowed[] = 'default_for';
		} elseif ( 'defaults' === $action ) {
			$allowed = array_merge( $allowed, array( 'billing_id', 'shipping_id' ) );
		}
		if ( ! in_array( $action, array( 'create', 'update', 'delete', 'defaults' ), true ) || array_diff( array_keys( $input ), $allowed ) || ! is_int( $input['base_revision'] ?? null ) || $input['base_revision'] < 0 || ( in_array( $action, array( 'update', 'delete' ), true ) && ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) ) ) ) {
			return new \WP_Error( 'invalid_address_intent', 'درخواست نشانی معتبر نیست.' );
		}
		if ( in_array( $action, array( 'create', 'update' ), true ) ) {
			$address = MobileSavedAddress::normalize( $input['address'] ?? null );
			if ( is_wp_error( $address ) ) {
				return $address;
			}
			$input['address'] = $address;
		}
		if ( 'create' === $action ) {
			$roles = $input['default_for'] ?? array();
			if ( ! is_array( $roles ) || ! array_is_list( $roles ) || count( $roles ) > 2 ) {
				return new \WP_Error( 'invalid_address_defaults', 'کاربرد نشانی معتبر نیست.' );
			}
			foreach ( $roles as $role ) {
				if ( ! is_string( $role ) || ! in_array( $role, array( 'billing', 'shipping' ), true ) ) {
					return new \WP_Error( 'invalid_address_defaults', 'کاربرد نشانی معتبر نیست.' );
				}
			}
			if ( count( array_unique( $roles ) ) !== count( $roles ) ) {
				return new \WP_Error( 'invalid_address_defaults', 'کاربرد نشانی معتبر نیست.' );
			}
			$input['default_for'] = $roles;
		}
		if ( 'defaults' === $action ) {
			foreach ( array( 'billing_id', 'shipping_id' ) as $field ) {
				if ( ! array_key_exists( $field, $input ) || ( null !== $input[ $field ] && ( ! is_string( $input[ $field ] ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $input[ $field ] ) ) ) ) {
					return new \WP_Error( 'invalid_address_defaults', 'کاربرد نشانی معتبر نیست.' );
				}
			}
		}
		return $input;
	}

	/**
	 * Apply one explicit client operation under owner/session, transaction and durable receipt fences.
	 *
	 * @param array       $session Already authenticated native session; revalidated under the owner lock.
	 * @param string      $action Closed mutation operation.
	 * @param string|null $id Owned row identifier where applicable.
	 * @param array       $input Immutable client request.
	 * @return array|\WP_Error Committed receipt or safe unresolved/error result.
	 * @throws \RuntimeException Internally caught when storage or provider work is uncertain.
	 * @throws \UnexpectedValueException Internally caught for a definite validation rejection.
	 */
	public static function execute( array $session, string $action, ?string $id, array $input ) {
		global $wpdb;
		if ( MobileMergeEffects::active() ) {
			return new \WP_Error( 'address_busy', 'درخواست دیگری در حال بررسی است.', array( 'status' => 409 ) );
		}
		$uuid = $input['operation_id'] ?? null;
		if ( ! is_string( $uuid ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $uuid ) ) {
			return new \WP_Error( 'invalid_operation_id', 'شناسه درخواست معتبر نیست.', array( 'status' => 400 ) );
		}
		$uuid  = strtolower( $uuid );
		$owner = (int) $session['user_id'];
		$key   = self::key( $owner, $uuid );
		$hash  = hash( 'sha256', wp_json_encode( array( $action, $id, $input ) ) );
		$table = MobileAddressBookStore::table( 'address_operations' );
		$lock  = 'dgl-link-user-' . $owner;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,2)', $lock ) ) ) {
			return new \WP_Error( 'address_busy', 'درخواست دیگری در حال بررسی است.', array( 'status' => 409 ) );
		}
		$row              = null;
		$transaction      = false;
		$commit_attempted = false;
		$committed        = false;
		$cache            = null;
		$old_user         = get_current_user_id();
		try {
			if ( ! MobileIdentityStore::sessionIsCurrent( $session['id'], $owner ) ) {
				return new \WP_Error( 'unauthorized', 'دوباره وارد حساب شوید.', array( 'status' => 401 ) );
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE operation_key=%s AND user_id=%d', $table, $key, $owner ), ARRAY_A );
			if ( $row ) {
				if ( ! hash_equals( $row['payload_hash'], $hash ) ) {
					return new \WP_Error( 'operation_conflict', 'این شناسه برای درخواست دیگری استفاده شده است.', array( 'status' => 409 ) );
				}
				if ( 'validating' !== $row['state'] ) {
					return self::receipt( $row );
				}
			} else {
				if ( ! MobileIdentityStore::admit( 'saved-address:' . $owner, 60, HOUR_IN_SECONDS ) ) {
					return new \WP_Error( 'rate_limited', 'کمی صبر کنید و دوباره تلاش کنید.', array( 'status' => 429 ) );
				}
				$row = array(
					'operation_key' => $key,
					'request_id'    => $uuid,
					'user_id'       => $owner,
					'payload_hash'  => $hash,
					'state'         => 'validating',
					'created_at'    => time(),
					'updated_at'    => time(),
				);
				if ( 1 !== $wpdb->insert( $table, $row ) ) {
					throw new \RuntimeException( 'address_journal_unavailable' );
				}
			}
			$normalized = self::normalize( $action, $id, $input );
			if ( is_wp_error( $normalized ) ) {
				return self::reject( $row, $normalized->get_error_code() );
			}
			MobileAddressBookStore::assertTransactional();
			$cache = $GLOBALS['wp_object_cache'];
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Transaction-local cache prevents rollback from publishing uncommitted customer data; restored in finally.
			$GLOBALS['wp_object_cache'] = new MobileMergeObjectCache( null, (array) ( $cache->global_groups ?? array() ), (array) ( $cache->non_persistent_groups ?? array() ) );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( 'address_transaction_unavailable' );
			}
			$transaction = true;
			MobileMergeEffects::begin( $key );
			$wpdb->get_row( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $owner ) );
			$wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE id=%s AND user_id=%d FOR UPDATE', MobileIdentityStore::table( 'sessions' ), $session['id'], $owner ) );
			if ( ! MobileIdentityStore::sessionIsCurrent( $session['id'], $owner ) ) {
				throw new \UnexpectedValueException( 'session_expired' );
			}
			wp_set_current_user( $owner );
			$result  = self::apply( $owner, $action, $id, $normalized );
			$result  = array_merge(
				array(
					'operation_id'     => $uuid,
					'operation_status' => 'accepted',
				),
				$result
			);
			$receipt = MobileRegistrationStore::seal( wp_json_encode( $result ), 'saved-address-result' );
			if ( strlen( $receipt ) > 20000 || 1 !== (int) $wpdb->get_var( 'SELECT @@in_transaction' ) ) {
				throw new \RuntimeException( 'address_transaction_boundary_changed' );
			}
			if ( 1 !== $wpdb->update(
				$table,
				array(
					'state'               => 'accepted',
					'response_ciphertext' => $receipt,
					'effects_ciphertext'  => MobileMergeEffects::seal(),
					'effects_count'       => MobileMergeEffects::count(),
					'updated_at'          => time(),
				),
				array(
					'operation_key' => $key,
					'state'         => 'validating',
				)
			) ) {
				throw new \RuntimeException( 'address_receipt_not_saved' );
			}
			$commit_attempted = true;
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( 'address_commit_uncertain' );
			}
			$transaction = false;
			$committed   = true;
			return $result;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
				$transaction = false;
			}
			if ( $commit_attempted ) {
				return self::lookup( $owner, $uuid );
			}
			return $row && $error instanceof \UnexpectedValueException ? self::reject( $row, $error->getMessage() ) : new \WP_Error( 'address_not_saved', 'نتیجه ذخیره هنوز قطعی نیست؛ همان درخواست را بررسی کنید.', array( 'status' => 503 ) );
		} finally {
			if ( $transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( null !== $cache ) {
				MobileMergeEffects::stop();
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original cache after the transaction, then invalidate only this owner.
				$GLOBALS['wp_object_cache'] = $cache;
				clean_user_cache( $owner );
				wp_cache_delete( $owner, 'user_meta' );
				wp_set_current_user( $old_user );
			}
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			if ( $committed ) {
				MobileMergeEffects::schedulePending();
			}
		}
	}

	/**
	 * Persist a definitive rejection only after all canonical changes were rolled back.
	 *
	 * @param array  $row Owned validating receipt.
	 * @param string $code Safe failure code.
	 * @return array|\WP_Error Durable result.
	 */
	private static function reject( array $row, string $code ) {
		global $wpdb;
		$result = array(
			'operation_id'     => $row['request_id'],
			'operation_status' => 'rejected',
			'code'             => $code,
		);
		$saved  = $wpdb->update(
			MobileAddressBookStore::table( 'address_operations' ),
			array(
				'state'               => 'rejected',
				'response_ciphertext' => MobileRegistrationStore::seal( wp_json_encode( $result ), 'saved-address-result' ),
				'updated_at'          => time(),
			),
			array(
				'operation_key' => $row['operation_key'],
				'state'         => 'validating',
			)
		);
		return 1 === $saved ? $result : self::lookup( (int) $row['user_id'], $row['request_id'] );
	}

	/**
	 * Apply the prepared geographic intent within the caller's transaction and owner lock.
	 *
	 * @param int         $owner Canonical owner.
	 * @param string      $action Closed operation.
	 * @param string|null $id Row identifier.
	 * @param array       $input Validated shape.
	 * @throws \UnexpectedValueException For a definitive revision/validation conflict.
	 * @throws \RuntimeException If storage cannot confirm its changes.
	 */
	private static function apply( int $owner, string $action, ?string $id, array $input ): array {
		global $wpdb;
		$books     = MobileAddressBookStore::table( 'address_books' );
		$addresses = MobileAddressBookStore::table( 'addresses' );
		$book      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id=%d FOR UPDATE', $books, $owner ), ARRAY_A );
		$customer  = new \WC_Customer( $owner );
		$locations = MobileAddresses::locations( $customer );
		if ( ! $book ) {
			$book = array(
				'user_id'         => $owner,
				'revision'        => 0,
				'billing_id'      => null,
				'shipping_id'     => null,
				'legacy_migrated' => 1,
				'updated_at'      => time(),
			);
			foreach ( array( 'billing', 'shipping' ) as $group ) {
				$getter    = 'get_' . $group;
				$geography = array_intersect_key( $customer->$getter(), array_fill_keys( MobileSavedAddress::FIELDS, true ) );
				if ( array_filter( $geography, static fn( $value ): bool => '' !== $value ) || null !== $locations[ $group ] ) {
					throw new \UnexpectedValueException( 'address_migration_required' );
				}
				$book[ $group . '_hash' ] = MobileSavedAddress::geographicHash( $geography, null );
			}
			if ( 1 !== $wpdb->insert( $books, $book ) ) {
				throw new \RuntimeException( 'address_book_not_saved' );
			}
		}
		if ( empty( $book['legacy_migrated'] ) ) {
			throw new \UnexpectedValueException( 'address_migration_required' );
		}
		$row = null;
		if ( in_array( $action, array( 'update', 'delete' ), true ) ) {
			$row = MobileAddressBookStore::find( $owner, (string) $id );
			if ( ! $row || (int) $row['revision'] !== $input['base_revision'] ) {
				throw new \UnexpectedValueException( 'address_changed' );
			}
		} elseif ( (int) $book['revision'] !== $input['base_revision'] ) {
			throw new \UnexpectedValueException( 'address_book_changed' );
		}
		$defaults = array(
			'billing_id'  => $book['billing_id'],
			'shipping_id' => $book['shipping_id'],
		);
		$address  = null;
		if ( in_array( $action, array( 'create', 'update' ), true ) ) {
			$values = $row ? array_intersect_key( MobileAddressBookStore::present( $row ), array_fill_keys( array_merge( MobileSavedAddress::FIELDS, array( 'label', 'location' ) ), true ) ) : array();
			$values = MobileSavedAddress::validate( array_replace( $values, $input['address'] ) );
			if ( is_wp_error( $values ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal fixed validation code is stored in a JSON receipt, never rendered as HTML.
				throw new \UnexpectedValueException( $values->get_error_code() );
			}
			if ( 'create' === $action ) {
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id=%d AND deleted_at IS NULL', $addresses, $owner ) );
				if ( null === $count ) {
					throw new \RuntimeException( 'address_quota_unavailable' );
				}
				if ( (int) $count >= max( 1, min( 100, (int) get_option( 'digitalogic_saved_address_limit', 20 ) ) ) ) {
					throw new \UnexpectedValueException( 'address_limit' );
				}
				$id = bin2hex( random_bytes( 16 ) );
			}
			$stored = array(
				'id'           => $id,
				'user_id'      => $owner,
				'label'        => $values['label'],
				'address_json' => wp_json_encode( array_intersect_key( $values, array_fill_keys( array_merge( MobileSavedAddress::FIELDS, array( 'location' ) ), true ) ) ),
				'revision'     => $row ? (int) $row['revision'] + 1 : 1,
				'created_at'   => $row ? (int) $row['created_at'] : time(),
				'updated_at'   => time(),
			);
			$saved  = $row ? $wpdb->update(
				$addresses,
				$stored,
				array(
					'id'       => $id,
					'user_id'  => $owner,
					'revision' => $row['revision'],
				)
			) : $wpdb->insert( $addresses, $stored );
			if ( 1 !== $saved ) {
				throw new \RuntimeException( 'address_not_saved' );
			}
			$address = MobileAddressBookStore::present( $stored );
			foreach ( $input['default_for'] ?? array() as $group ) {
				$defaults[ $group . '_id' ] = $id;
			}
		} elseif ( 'delete' === $action ) {
			if ( in_array( $id, $defaults, true ) ) {
				throw new \UnexpectedValueException( 'default_address_requires_replacement' );
			}
			if ( 1 !== $wpdb->update(
				$addresses,
				array(
					'deleted_at' => time(),
					'updated_at' => time(),
					'revision'   => (int) $row['revision'] + 1,
				),
				array(
					'id'       => $id,
					'user_id'  => $owner,
					'revision' => $row['revision'],
				)
			) ) {
				throw new \RuntimeException( 'address_not_deleted' );
			}
		} else {
			$defaults = array(
				'billing_id'  => $input['billing_id'],
				'shipping_id' => $input['shipping_id'],
			);
		}
		$changes   = array(
			'revision'   => (int) $book['revision'] + 1,
			'updated_at' => time(),
		) + $defaults;
		$projected = false;
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			$selected = $defaults[ $group . '_id' ];
			if ( $selected === $book[ $group . '_id' ] && ! ( 'update' === $action && $id === $selected ) ) {
				continue;
			}
			if ( null === $selected ) {
				throw new \UnexpectedValueException( 'default_address_requires_replacement' );
			}
			$getter = 'get_' . $group;
			if ( ! hash_equals( $book[ $group . '_hash' ], MobileSavedAddress::geographicHash( $customer->$getter(), $locations[ $group ] ) ) ) {
				throw new \UnexpectedValueException( 'address_projection_changed' );
			}
			$target = MobileAddressBookStore::find( $owner, $selected );
			if ( ! $target ) {
				throw new \UnexpectedValueException( 'address_changed' );
			}
			$target = MobileSavedAddress::validate( MobileAddressBookStore::present( $target ) );
			if ( is_wp_error( $target ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal fixed validation code is stored in a JSON receipt, never rendered as HTML.
				throw new \UnexpectedValueException( $target->get_error_code() );
			}
			$target_hash = MobileSavedAddress::geographicHash( $target, $target['location'] );
			if ( hash_equals( $book[ $group . '_hash' ], $target_hash ) ) {
				continue;
			}
			foreach ( MobileSavedAddress::FIELDS as $field ) {
				$setter = 'set_' . $group . '_' . $field;
				$customer->$setter( $target[ $field ] );
			}
			MobileAddresses::apply_locations( $customer, array( $group => $target['location'] ) );
			$changes[ $group . '_hash' ] = $target_hash;
			$projected                   = true;
		}
		if ( $projected ) {
			$customer->save();
		}
		if ( 1 !== $wpdb->update(
			$books,
			$changes,
			array(
				'user_id'  => $owner,
				'revision' => $book['revision'],
			)
		) ) {
			throw new \RuntimeException( 'address_revision_not_saved' );
		}
		return array(
			'address'    => $address,
			'deleted_id' => 'delete' === $action ? $id : null,
			'revision'   => $changes['revision'],
			'defaults'   => $defaults,
		);
	}
}
