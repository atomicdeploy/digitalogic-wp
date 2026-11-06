<?php
/**
 * Reviewed consolidation into the proven canonical phone account.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native Laravel contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Exact identity and operation metadata must bypass caches and bind canonical provider snapshots.

/** Source users remain auditable aliases; their credentials never grant survivor privileges. */
final class MobileAccountMerge {
	/** Return the durable merge journal, not another account or business store. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_mobile_account_merges';
	}

	/**
	 * Check whether the reviewed deployed adapter can offer commit.
	 */
	public static function enabled(): bool {
		return '1' === get_option( 'digitalogic_mobile_merge_schema' ) && (bool) get_option( 'digitalogic_mobile_account_merge', false );
	}

	/**
	 * Install security/receipt metadata without changing any user.
	 *
	 * @throws \RuntimeException If transactional storage is unavailable.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			operation_key char(64) NOT NULL,
			request_id char(36) NOT NULL,
			source_user_id bigint unsigned NOT NULL,
			target_user_id bigint unsigned NOT NULL,
			actor_session_id char(32) NOT NULL,
			actor_token_hash char(64) NOT NULL,
			actor_auth_epoch char(64) NOT NULL,
			payload_hash char(64) NOT NULL,
			state varchar(20) NOT NULL,
			failure_code varchar(64) DEFAULT NULL,
			response_ciphertext longtext DEFAULT NULL,
			effects_ciphertext longtext DEFAULT NULL,
			effects_cursor int unsigned NOT NULL DEFAULT 0,
			effects_count int unsigned NOT NULL DEFAULT 0,
			created_at bigint unsigned NOT NULL,
			updated_at bigint unsigned NOT NULL,
			recovery_until bigint unsigned NOT NULL,
			PRIMARY KEY  (operation_key),
			KEY source_user_id (source_user_id),
			KEY target_user_id (target_user_id),
			KEY actor_token_hash (actor_token_hash)
		) ENGINE=InnoDB {$charset};"
		);
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! $status || 'InnoDB' !== $status['Engine'] ) {
			throw new \RuntimeException( 'Merge receipts require transactional storage.' );
		}
		update_option( 'digitalogic_mobile_merge_schema', '1', false );
	}

	/**
	 * Refuse atomic identity work if any known affected canonical table cannot roll back.
	 *
	 * @throws \UnexpectedValueException On a missing or nontransactional provider table.
	 *
	 * @throws \UnexpectedValueException When the operation cannot preserve its verified boundary.
	 */
	public static function assertTransactional(): void {
		global $wpdb;
		$tables = array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->prefix . 'woocommerce_sessions', $wpdb->prefix . 'wc_customer_lookup', $wpdb->prefix . 'woodmart_wishlists', $wpdb->prefix . 'woodmart_wishlist_products', $wpdb->prefix . 'digits_user_session', $wpdb->prefix . 'cerber_uss', MobileIdentityStore::table( 'sessions' ), MobileCartStore::table( 'carts' ), MobileCartStore::table( 'cart_tokens' ), MobileCartStore::table( 'cart_operations' ), self::table() );
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( ! $row || 'InnoDB' !== $row['Engine'] ) {
				throw new \UnexpectedValueException( 'nontransactional_merge_provider' );
			}
		}
	}

	/**
	 * Resolve only the exact current session's prior phone proof and Google subject.
	 *
	 * @param array $session Authenticated source session.
	 * @return array|\WP_Error Private proof context; never returned directly.
	 */
	public static function context( array $session ) {
		global $wpdb;
		$source = (int) $session['user_id'];
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE actor_user_id=%d AND actor_session_id=%s AND purpose='phone_link' AND state='used' ORDER BY GREATEST(created_at,verified_at) DESC,verified_at DESC,created_at DESC,id DESC LIMIT 1", MobileIdentityStore::table( 'otp' ), $source, $session['id'] ), ARRAY_A );
		$phone  = $row ? MobileRegistrationStore::open( (string) $row['phone_ciphertext'], 'phone' ) : null;
		if ( ! $row || ! $phone || ! hash_equals( $row['phone_hash'], MobileIdentityStore::hash( $phone ) ) ) {
			return new \WP_Error( 'phone_proof_required' );
		}
		$target  = MobileIdentityStore::ownerOfPhone( $phone );
		$subject = (string) get_user_meta( $source, 'auth_google_id', true );
		if ( $target < 1 || $target === $source || '' === $subject || MobileGoogleIdentity::owner( $subject ) !== $source || get_user_meta( $source, '_digitalogic_merged_into', true ) || get_user_meta( $target, '_digitalogic_merged_into', true ) ) {
			return new \WP_Error( 'identity_ownership_conflict' );
		}
		$target_google = (string) get_user_meta( $target, 'auth_google_id', true );
		if ( '' !== $target_google ) {
			return new \WP_Error( 'target_google_binding_occupied' );
		}
		$google_until = 'google' === $session['auth_method'] && (int) $session['verified_at'] > 0 ? (int) $session['verified_at'] + 600 : 0;
		$phone_until  = (int) ( $row['verified_at'] ?? 0 ) > 0 ? (int) $row['verified_at'] + 600 : 0;
		return array(
			'source'           => $source,
			'target'           => $target,
			'phone'            => $phone,
			'subject'          => $subject,
			'phone_proof_id'   => $row['id'],
			'phone_proof_at'   => (int) $row['verified_at'],
			'google_proof_at'  => (int) $session['verified_at'],
			'google_until'     => $google_until,
			'phone_until'      => $phone_until,
			'target_protected' => MobilePhoneResolution::protectedAccount( $target ),
		);
	}

	/**
	 * Read the canonical cart without creating a mobile cart or mutating its revision.
	 *
	 * @param int $user_id Current canonical owner.
	 * @return array Existing metadata or read-only account context.
	 */
	public static function cart( int $user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id=%d', MobileCartStore::table( 'carts' ), $user_id ), ARRAY_A );
		return $row ? $row : array(
			'id'       => '',
			'user_id'  => $user_id,
			'items'    => '[]',
			'revision' => 0,
		);
	}

	/**
	 * Read authoritative provider identities and counts without creating provider records.
	 *
	 * @param int $id Existing account.
	 * @return array Private deterministic snapshot bound into consent.
	 * @throws \RuntimeException If an authoritative provider cannot be inspected.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function inventory( int $id ): array {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$user   = get_user_by( 'id', $id );
		if ( ! $user ) {
			throw new \RuntimeException( 'Account disappeared.' );
		}
		$cart          = self::cart( $id );
		$customer      = new \WC_Customer( $id );
		$favorite_rows = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT p.product_id FROM %i p JOIN %i w ON w.ID=p.wishlist_id WHERE w.user_id=%d ORDER BY p.product_id', $prefix . 'woodmart_wishlist_products', $prefix . 'woodmart_wishlists', $id ) );
		if ( ! is_array( $favorite_rows ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Wishlist inventory unavailable.' );
		}
		$favorite_ids  = array_map( 'intval', $favorite_rows );
		$count_sql     = static function ( $query ) use ( $wpdb ): int {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every caller passes a closed, prepared provider count query.
			$value = $wpdb->get_var( $query );
			if ( null === $value || '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Provider count unavailable.' );
			}
			return (int) $value;
		};
		$count         = static function ( string $table, string $column, int $owner ) use ( $wpdb ): int {
			$value = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i=%d', $table, $column, $owner ) );
			if ( null === $value || '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Provider inventory unavailable.' );
			}
			return (int) $value;
		};
		$counts        = array(
			'orders'    => $count( $prefix . 'wc_orders', 'customer_id', $id ),
			'favorites' => count( $favorite_ids ),
			'requests'  => $count_sql( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d AND post_type='digitalogic_request'", $id ) ),
			'tickets'   => $count_sql( $wpdb->prepare( 'SELECT COUNT(*) FROM %i t JOIN %i p ON p.id=t.customer_id WHERE p.user_id=%d', $prefix . 'fs_tickets', $prefix . 'fs_persons', $id ) ),
			'drafts'    => $count( $prefix . 'digitalogic_mobile_drafts', 'user_id', $id ),
		);
		$pending       = $count_sql( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id=%d AND state IN ('validating','creating','payment_pending','uncertain')", MobileCheckoutStore::table( 'checkout_operations' ), $id ) );
		$pending      += $count_sql( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id=%d AND state NOT IN ('accepted','rejected')", MobileMessageStore::table(), $id ) );
		$unsupported   = array(
			'orders'                 => $counts['orders'],
			'legacy_orders'          => $count_sql( $wpdb->prepare( "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_customer_user' AND pm.meta_value=%s AND p.post_type IN ('shop_order','shop_order_refund')", (string) $id ) ),
			'service_requests'       => $counts['requests'],
			'fluent_people'          => $count( $prefix . 'fs_persons', 'user_id', $id ),
			'fluent_forms'           => $count( $prefix . 'fluentform_submissions', 'user_id', $id ),
			'uploads'                => $count( MobileRequestStore::table( 'uploads' ), 'user_id', $id ),
			'cloud_drafts'           => $counts['drafts'],
			'request_receipts'       => $count( MobileRequestStore::table( 'requests' ), 'user_id', $id ),
			'message_receipts'       => $count( MobileMessageStore::table(), 'user_id', $id ),
			'checkout_receipts'      => $count( MobileCheckoutStore::table( 'checkout_operations' ), 'user_id', $id ),
			'comments'               => $count( $wpdb->comments, 'user_id', $id ),
			'other_authored_content' => $count_sql( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d AND post_type<>'wd_abandoned_cart'", $id ) ),
		);
		$woo_session   = new MobileWooSession( $cart );
		$raw_cart      = (array) $woo_session->get( 'cart', array() );
		$standard_keys = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' );
		foreach ( $raw_cart as $line ) {
			if ( ! is_array( $line ) || array_diff( array_keys( $line ), $standard_keys ) ) {
				$unsupported['custom_cart_options'] = 1;
			}
		}
		$coupons                     = (array) $woo_session->get( 'applied_coupons', array() );
		$unsupported['cart_coupons'] = count( $coupons );
		$items                       = MobileWooCart::quantities( $raw_cart );
		$counts['cart_lines']        = count( $items );
		$abandoned_ids               = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author=%d AND post_type='wd_abandoned_cart' ORDER BY ID", $id ) );
		if ( ! is_array( $abandoned_ids ) || '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'Abandoned cart inventory unavailable.' ); }
		return array(
			'user_id'              => $id,
			'epoch'                => MobileIdentityStore::hash( $user->user_pass ),
			'capabilities'         => $user->caps,
			'cart'                 => $cart,
			'cart_items'           => $items,
			'cart_raw_hash'        => hash( 'sha256', wp_json_encode( $raw_cart ) ),
			'cart_coupons'         => $coupons,
			'abandoned_cart_count' => $count_sql( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d AND post_type='wd_abandoned_cart'", $id ) ),
			'abandoned_ids'        => array_map( 'intval', $abandoned_ids ),
			'billing'              => $customer->get_billing(),
			'shipping'             => $customer->get_shipping(),
			'address_locations'    => MobileAddresses::locations( $customer ),
			'favorites'            => $favorite_ids,
			'counts'               => $counts,
			'pending'              => $pending,
			'unsupported_history'  => array_filter( $unsupported ),
		);
	}

	/**
	 * Prepare a review without creating accounts, provider records, SMS or new login grants.
	 *
	 * @param array $session Current source session.
	 * @return array Safe authenticated plan presentation.
	 */
	public static function plan( array $session ): array {
		$context = self::context( $session );
		$ready   = ! is_wp_error( $context ) && $context['google_until'] > time() && $context['phone_until'] > time();
		return self::presentation( $session, $context, $ready ? self::inventory( $context['source'] ) : null, $ready ? self::inventory( $context['target'] ) : null );
	}

	/**
	 * Serialize the exact public contract from a verified private snapshot.
	 *
	 * @param array      $session Current owner-bound native session.
	 * @param mixed      $context Verified identities and proof timestamps.
	 * @param array|null $source Reviewed source snapshot.
	 * @param array|null $target Reviewed survivor snapshot.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function presentation( array $session, $context, ?array $source = null, ?array $target = null ): array {
		$result = array(
			'status'          => 'proof_required',
			'plan_id'         => null,
			'revision'        => null,
			'expires_at'      => null,
			'source_user_id'  => null,
			'target_user_id'  => null,
			'required_proofs' => array(),
			'proof_until'     => array(
				'google' => null,
				'phone'  => null,
			),
			'phone'           => null,
			'survivor'        => null,
			'records'         => null,
			'conflicts'       => array(),
			'choices'         => array(
				'cart'      => array( 'combine' ),
				'addresses' => array( 'keep_target_fill_empty' ),
			),
			'blockers'        => array(),
		);
		if ( is_wp_error( $context ) ) {
			$result['status']                = 'phone_proof_required' === $context->get_error_code() ? 'proof_required' : 'blocked';
			$google_until                    = 'google' === ( $session['auth_method'] ?? '' ) ? (int) $session['verified_at'] + 600 : 0;
			$result['proof_until']['google'] = $google_until > 0 ? gmdate( 'c', $google_until ) : null;
			$result['required_proofs']       = 'proof_required' === $result['status'] ? ( $google_until > time() ? array( 'phone' ) : array( 'google', 'phone' ) ) : array();
			$result['blockers']              = 'blocked' === $result['status'] ? array( $context->get_error_code() ) : array();
			return $result;
		}
		$result['phone']    = $context['phone'];
		$result['survivor'] = array(
			'kind'                  => 'verified_phone_account',
			'phone_masked'          => substr( $context['phone'], 0, 5 ) . '•••••' . substr( $context['phone'], -3 ),
			'has_privileged_access' => (bool) $context['target_protected'],
		);
		foreach ( array( 'google', 'phone' ) as $method ) {
			$until                            = $context[ $method . '_until' ];
			$result['proof_until'][ $method ] = $until > 0 ? gmdate( 'c', $until ) : null;
			if ( $until <= time() ) {
				$result['required_proofs'][] = $method;
			}
		}
		if ( $result['required_proofs'] ) {
			return $result;
		}
		if ( null === $source || null === $target ) {
			throw new \RuntimeException( 'Verified merge inventory is required.' );
		}
		$result['source_user_id'] = $context['source'];
		$result['target_user_id'] = $context['target'];
		$result['records']        = array(
			'source' => $source['counts'],
			'target' => $target['counts'],
		);
		if ( $source['pending'] || $target['pending'] ) {
			$result['blockers'][] = 'unresolved_operation';
		}
		if ( $source['unsupported_history'] ) {
			$result['blockers'][] = 'provider_migration_required';
		}
		$result['status'] = $result['blockers'] ? 'blocked' : 'review_required';
		if ( $result['blockers'] ) {
			return $result;
		}
		$revision             = MobileIdentityStore::hash( wp_json_encode( array( $context, $source, $target ) ) );
		$expires              = min( time() + 600, $context['google_until'], $context['phone_until'] );
		$claim                = array(
			'source'     => $context['source'],
			'target'     => $context['target'],
			'session_id' => $session['id'],
			'revision'   => $revision,
			'expires_at' => $expires,
		);
		$result['plan_id']    = MobileRegistrationStore::seal( wp_json_encode( $claim ), 'account-merge-plan' );
		$result['revision']   = $revision;
		$result['expires_at'] = gmdate( 'c', $expires );
		return $result;
	}

	/**
	 * Apply only the reviewed provider subset inside the caller's transaction.
	 *
	 * @param array  $context Exact two-proof context.
	 * @param array  $source Revalidated source inventory.
	 * @param array  $target Revalidated survivor inventory.
	 * @param string $operation_key Durable audit reference.
	 * @return void
	 * @throws \RuntimeException On unavailable products, provider drift or failed readback.
	 */
	public static function consolidate( array $context, array $source, array $target, string $operation_key ): void {
		$previous = get_current_user_id();
		// Woodmart must not delete the retained abandoned-cart record when the source cart is cleared.
		add_filter( 'woodmart_skip_register_cart', '__return_true', PHP_INT_MAX );
		wp_set_current_user( (int) $context['target'] );
		try {
			self::applyCanonical( $context, $source, $target, $operation_key );
		} finally {
			wp_set_current_user( $previous );
			remove_filter( 'woodmart_skip_register_cart', '__return_true', PHP_INT_MAX );
		}
	}

	/**
	 * Fill contact fields without mixing two geographic addresses.
	 *
	 * @param array $source Original address fields.
	 * @param array $target Survivor address fields.
	 * @return array Fields that may be copied into empty canonical values.
	 */
	public static function addressPatch( array $source, array $target ): array {
		$geography = array( 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' );
		$occupied  = false;
		foreach ( $geography as $field ) {
			$occupied = $occupied || '' !== trim( (string) ( $target[ $field ] ?? '' ) );
		}
		$patch = array();
		foreach ( $source as $field => $value ) {
			if ( '' !== trim( (string) $value ) && '' === trim( (string) ( $target[ $field ] ?? '' ) ) && ( ! $occupied || ! in_array( $field, $geography, true ) ) ) {
				$patch[ $field ] = $value;
			}
		}
		return $patch;
	}

	/**
	 * Run canonical APIs only after both principals and consent snapshots are locked.
	 *
	 * @param array  $context Exact proof context.
	 * @param array  $source Source inventory.
	 * @param array  $target Survivor inventory.
	 * @param string $operation_key Journal identity.
	 * @throws \RuntimeException When an invariant cannot be preserved.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	private static function applyCanonical( array $context, array $source, array $target, string $operation_key ): void {
		global $wpdb;
		if ( $source['unsupported_history'] || $source['pending'] || $target['pending'] ) {
			throw new \RuntimeException( 'provider_migration_required' );
		}
		$source_id   = $context['source'];
		$target_id   = $context['target'];
		$source_cart = MobileCartStore::create( $source_id );
		$target_cart = MobileCartStore::create( $target_id );
		$wanted      = array();
		foreach ( array_merge( $source['cart_items'], $target['cart_items'] ) as $line ) {
			$wanted[ $line['id'] ] = ( $wanted[ $line['id'] ] ?? 0 ) + $line['quantity'];
		}
		ksort( $wanted, SORT_NUMERIC );
		$changes = array();
		foreach ( $wanted as $id => $quantity ) {
			$changes[] = array(
				'id'       => (int) $id,
				'quantity' => $quantity,
			);
		}
		if ( $source['cart_items'] ) {
			$written = MobileWooCart::mutate( $target_cart, $changes );
			if ( $written !== $changes ) {
				throw new \RuntimeException( 'merged_cart_readback_failed' );
			}
			$remove = array_map(
				static fn( $line ) => array(
					'id'       => $line['id'],
					'quantity' => 0,
				),
				$source['cart_items']
			);
			if ( MobileWooCart::mutate( $source_cart, $remove ) ) {
				throw new \RuntimeException( 'source_cart_not_retired' );
			}
			MobileWooCart::reconcile( $target_cart );
			MobileWooCart::reconcile( $source_cart );
		}
		// Existing receipts keep their original owner and UUID. A merge never replays them.
		if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE cart_id=%s', MobileCartStore::table( 'cart_tokens' ), $source_cart['id'] ) ) ) {
			throw new \RuntimeException( 'source_cart_tokens_not_retired' );
		}
		if ( $source['favorites'] ) {
			$wishlist = new \XTS\WC_Wishlist\Wishlist( false, $target_id, false );
			if ( ! $wishlist->get_id() ) {
				throw new \RuntimeException( 'wishlist_unavailable' );
			}
			foreach ( array_diff( $source['favorites'], $target['favorites'] ) as $product_id ) {
				if ( ! $wishlist->add( $product_id, $wishlist->get_id() ) ) {
					throw new \RuntimeException( 'wishlist_merge_failed' );
				}
			}
			$expected = array_values( array_unique( array_merge( $source['favorites'], $target['favorites'] ) ) );
			$actual   = array_values( array_unique( array_map( static fn( $row ) => (int) $row['product_id'], $wishlist->get_all() ) ) );
			sort( $expected, SORT_NUMERIC );
			sort( $actual, SORT_NUMERIC );
			if ( $actual !== $expected ) {
				throw new \RuntimeException( 'wishlist_merge_readback_failed' );
			}
		}
		$customer = new \WC_Customer( $target_id );
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( self::addressPatch( $source[ $group ], $target[ $group ] ) as $field => $value ) {
				$setter = 'set_' . $group . '_' . $field;
				if ( is_callable( array( $customer, $setter ) ) ) {
					$customer->$setter( $value );
				}
			}
		}
		$locations = array();
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			// A pin follows only a wholly empty target street address, never a different existing address.
			$getter        = 'get_' . $group;
			$merged        = $customer->$getter();
			$same_location = ! empty( $source[ $group ]['address_1'] );
			foreach ( array( 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' ) as $field ) {
				$same_location = $same_location && (string) ( $merged[ $field ] ?? '' ) === (string) ( $source[ $group ][ $field ] ?? '' );
			}
			if ( $same_location && empty( $target['address_locations'][ $group ] ) && ! empty( $source['address_locations'][ $group ] ) ) {
				$locations[ $group ] = $source['address_locations'][ $group ];
			}
		}
		if ( $locations ) {
			MobileAddresses::apply_locations( $customer, $locations );
		}
		$customer->save();
		// Keep the canonical abandoned-cart record ID while assigning it to the surviving owner.
		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author=%d AND post_type='wd_abandoned_cart'", $source_id ) );
		foreach ( $posts as $post_id ) {
			if ( is_wp_error(
				wp_update_post(
					array(
						'ID'          => (int) $post_id,
						'post_author' => $target_id,
					),
					true
				)
			) ) {
				throw new \RuntimeException( 'abandoned_cart_owner_not_saved' );
			}
			update_post_meta( (int) $post_id, '_user_id', $target_id );
			update_post_meta( (int) $post_id, '_user_email', (string) get_user_by( 'id', $target_id )->user_email );
			update_post_meta( (int) $post_id, '_user_first_name', $customer->get_first_name() );
			update_post_meta( (int) $post_id, '_user_last_name', $customer->get_last_name() );
			if ( (int) get_post_field( 'post_author', $post_id ) !== $target_id || (int) get_post_meta( $post_id, '_user_id', true ) !== $target_id ) {
				throw new \RuntimeException( 'Abandoned cart owner readback failed.' ); }
		}
		if ( ! delete_user_meta( $source_id, 'auth_google_id', $context['subject'] ) || ! add_user_meta( $target_id, 'auth_google_id', $context['subject'], true ) ) {
			throw new \RuntimeException( 'google_binding_not_moved' );
		}
		update_user_meta( $target_id, '_digitalogic_google_binding_not_before', time() );
		update_user_meta( $source_id, '_digitalogic_merged_into', $target_id );
		update_user_meta( $source_id, '_digitalogic_merge_operation', $operation_key );
		\WP_Session_Tokens::get_instance( $source_id )->destroy_all();
		// Digits' installed session implementation is encoded; revoke only its exact owner's
		// canonical session-token rows. These are credentials, not order or audit history.
		if ( false === $wpdb->delete( $wpdb->prefix . 'digits_user_session', array( 'user_id' => $source_id ), array( '%d' ) ) ) {
			throw new \RuntimeException( 'Digits sessions were not retired.' ); }
		foreach ( array( 'digits_user_session', 'cerber_uss' ) as $suffix ) {
			$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id=%d', $wpdb->prefix . $suffix, $source_id ) );
			if ( null === $remaining || 0 !== (int) $remaining || '' !== $wpdb->last_error ) {
				throw new \RuntimeException( 'Provider sessions were not retired.' ); }
		}
		\WP_Application_Passwords::delete_all_application_passwords( $source_id );
		if ( \WP_Session_Tokens::get_instance( $source_id )->get_all() || \WP_Application_Passwords::get_user_application_passwords( $source_id ) ) {
			throw new \RuntimeException( 'Source browser credentials were not retired.' ); }
		// A retained account is not another human login. No new human password is generated.
		if ( false === $wpdb->update( $wpdb->users, array( 'user_pass' => '!digitalogic-retired-merge-v1' ), array( 'ID' => $source_id ) ) ) {
			throw new \RuntimeException( 'source_password_not_retired' );
		}
		clean_user_cache( $source_id );
		clean_user_cache( $target_id );
		if ( MobileGoogleIdentity::owner( $context['subject'] ) !== $target_id || MobileIdentityStore::ownerOfPhone( $context['phone'] ) !== $target_id || (int) get_user_meta( $source_id, '_digitalogic_merged_into', true ) !== $target_id || get_user_by( 'id', $target_id )->caps !== $target['capabilities'] || get_user_by( 'id', $source_id )->caps !== $source['capabilities'] || ! hash_equals( $target['epoch'], MobileIdentityStore::hash( get_user_by( 'id', $target_id )->user_pass ) ) ) {
			throw new \RuntimeException( 'merged_identity_readback_failed' );
		}
	}
}
