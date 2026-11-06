<?php
/**
 * Deferred canonical delivery after a committed identity transition.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 native adapter contracts.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Current security receipts, schemas and transaction locks bypass shared caches.

/** Deferred canonical delivery after a committed identity transition. */
final class MobileMergeEffects {
	/**
	 * Current operation scope.
	 *
	 * @var string|null
	 */
	private static ?string $operation = null;
	/**
	 * Bounded queued events.
	 *
	 * @var array
	 */
	private static array $events = array();
	/**
	 * Unexpected outbound hook observed.
	 *
	 * @var bool
	 */
	private static bool $unexpected = false;
	/** Report whether this request owns a deferred delivery scope. */
	public static function active(): bool {
		return null !== self::$operation; }
	/**
	 * Start an isolated deferred-effect scope.
	 *
	 * @param string $key Durable operation identifier.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function begin( string $key ): void {
		if ( self::active() ) {
			throw new \RuntimeException( 'Nested identity transaction.' );
		}
		self::$operation  = $key;
		self::$events     = array();
		self::$unexpected = false;
		add_filter( 'pre_http_request', array( self::class, 'blockHttp' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_wp_mail', array( self::class, 'blockMail' ), PHP_INT_MAX, 2 );
	}
	/**
	 * Fail closed on an unreviewed outbound HTTP effect.
	 *
	 * @param mixed $result Existing hook result.
	 * @param mixed $args Transport arguments, not sent here.
	 * @param mixed $url Requested destination, not sent here.
	 */
	public static function blockHttp( $result, $args, $url ) {
		unset( $result, $args, $url );
		self::$unexpected = true;
		return new \WP_Error( 'merge_outbound_before_commit', 'Outbound transport deferred until identity commit.' );
	}
	/**
	 * Fail closed on an unreviewed outbound email effect.
	 *
	 * @param mixed $result Existing hook result.
	 * @param mixed $args Transport arguments, not sent here.
	 */
	public static function blockMail( $result, $args ) {
		unset( $result, $args );
		self::$unexpected = true;
		return false; }
	/**
	 * Queue an allowlisted canonical event for committed delivery.
	 *
	 * @param string $channel Allowlisted canonical transport.
	 * @param array  $arguments Bounded event arguments.
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function defer( string $channel, array $arguments ): bool {
		if ( ! self::active() ) {
			return false;
		}
		if ( ! in_array( $channel, array( 'panel', 'webhook', 'admin_webhook', 'viewer' ), true ) ) {
			throw new \RuntimeException( 'Unsupported merge effect.' );
		}
		$event                     = array(
			'channel'     => $channel,
			'arguments'   => $arguments,
			'occurred_at' => time(),
		);
		$identity                  = hash( 'sha256', wp_json_encode( array( $channel, $arguments ) ) );
		self::$events[ $identity ] = $event;
		if ( count( self::$events ) > 64 || strlen( wp_json_encode( self::$events ) ) > 14000 ) {
			throw new \RuntimeException( 'Merge effect bound exceeded.' );
		}
		return true;
	}
	/**
	 * Encrypt bounded effects into the owning transaction receipt.
	 *
	 * @throws \RuntimeException When the operation cannot preserve its verified boundary.
	 */
	public static function seal(): string {
		if ( self::$unexpected ) {
			throw new \RuntimeException( 'Unreviewed outbound hook prevented merge.' );
		}
		$sealed = MobileRegistrationStore::seal( wp_json_encode( array_values( self::$events ) ), 'account-merge-effects' );
		if ( strlen( $sealed ) > 20000 ) {
			throw new \RuntimeException( 'Identity delivery receipt exceeds its decoder bound.' );
		}
		return $sealed;
	}
	/**
	 * Restore transport hooks and discard request-local queued state.
	 */
	public static function stop(): void {
		remove_filter( 'pre_http_request', array( self::class, 'blockHttp' ), PHP_INT_MAX );
		remove_filter( 'pre_wp_mail', array( self::class, 'blockMail' ), PHP_INT_MAX );
		self::$operation  = null;
		self::$events     = array();
		self::$unexpected = false;
	}
	/** Accepted journal and each delivery cursor are durable. Replays retain the same event identity. */
	/**
	 * Apply the scoped native adapter operation.
	 *
	 * @param string $key Durable operation identifier.
	 * @param bool   $registration Whether this is an enrollment receipt.
	 */
	public static function drain( string $key, bool $registration = false ): void {
		global $wpdb;
		if ( self::active() || ! preg_match( '/\A[a-f0-9]{64}\z/D', $key ) ) {
			return;
		}
		$lock      = ( $registration ? 'dgl-enroll-effects-' : 'dgl-merge-effects-' ) . substr( $key, 0, 40 );
		$table     = $registration ? MobileRegistrationStore::table() : MobileAccountMerge::table();
		$key_field = $registration ? 'token_hash' : 'operation_key';
		$state     = $registration ? 'registered' : 'accepted';
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return;
		}
		try {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT effects_ciphertext,effects_cursor FROM %i WHERE %i=%s AND state=%s', $table, $key_field, $key, $state ), ARRAY_A );
			if ( ! $row || empty( $row['effects_ciphertext'] ) ) {
				return;
			}
			$events = json_decode( MobileRegistrationStore::open( $row['effects_ciphertext'], 'account-merge-effects' ) ?? '', true );
			if ( ! is_array( $events ) ) {
				return;
			}
			foreach ( $events as $index => $event ) {
				if ( $index < (int) $row['effects_cursor'] ) {
					continue;
				}
				$effect_id = 'sha256:' . hash( 'sha256', $state . ':' . $key . ':effect:' . $index );
				$args      = $event['arguments'];
				switch ( $event['channel'] ) {
					case 'panel':
						$args[1]['idempotency_key'] = $effect_id;
						$result                     = \Digitalogic_Panel::record_event_result( $args[0], $args[1] );
						break;
					case 'webhook':
						$result = \Digitalogic_Webhooks::instance()->deliver_merge_event( $args[0], $args[1], $effect_id, (int) $event['occurred_at'] );
						break;
					case 'admin_webhook':
						$result = \DigitalogicAdmin\Webhooks::deliver_merge_event( $args[0], $args[1], $args[2], $args[3], $effect_id, (int) $event['occurred_at'] );
						break;
					case 'viewer':
						$result = \Digitalogic\ViewerBridge\Events::emit( ...$args );
						break;
					default:
						return;
				}
				if ( is_wp_error( $result ) || false === $result ) {
					return;
				}
				if ( 1 !== $wpdb->update(
					$table,
					array( 'effects_cursor' => $index + 1 ),
					array(
						$key_field       => $key,
						'effects_cursor' => $index,
					)
				) ) {
					return;
				}
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
	/** A request/cron restart discovers committed receipts even if PHP died before scheduling. */
	/**
	 * Apply the scoped native adapter operation.
	 */
	public static function schedulePending(): void {
		global $wpdb;
		if ( '1' === get_option( 'digitalogic_mobile_google_continuation_schema' ) ) {
			$registrations = $wpdb->get_col( $wpdb->prepare( "SELECT token_hash FROM %i WHERE state='registered' AND effects_ciphertext IS NOT NULL AND effects_cursor<effects_count ORDER BY expires_at LIMIT 10", MobileRegistrationStore::table() ) );
			foreach ( (array) $registrations as $key ) {
				if ( ! wp_next_scheduled( 'digitalogic_mobile_registration_effects', array( $key ) ) ) {
					wp_schedule_single_event( time() + 30, 'digitalogic_mobile_registration_effects', array( $key ) ); }
			}
		}
		if ( '1' !== get_option( 'digitalogic_mobile_merge_schema' ) ) {
			return;
		}
		$keys = $wpdb->get_col( $wpdb->prepare( "SELECT operation_key FROM %i WHERE state='accepted' AND effects_ciphertext IS NOT NULL AND effects_cursor<effects_count ORDER BY updated_at LIMIT 10", MobileAccountMerge::table() ) );
		foreach ( (array) $keys as $key ) {
			if ( ! wp_next_scheduled( 'digitalogic_mobile_merge_effects', array( $key ) ) ) {
				wp_schedule_single_event( time() + 10, 'digitalogic_mobile_merge_effects', array( $key ) );
			}
		}
	}
	/**
	 * Count queued canonical effects.
	 */
	public static function count(): int {
		return count( self::$events ); }
}
