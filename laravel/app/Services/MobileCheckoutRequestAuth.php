<?php
/**
 * Authenticate native checkout before WordPress plugins classify the request.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 and native adapter method contracts.

/** Does not create browser cookies or change any provider's enforcement hooks. */
final class MobileCheckoutRequestAuth {

	/**
	 * Conflicting principals cannot proceed through the native controller.
	 *
	 * @var bool
	 */
	private static bool $conflict = false;

	/**
	 * Limit early authentication to exact native checkout requests without action queries.
	 *
	 * @param array $server Request metadata; never persisted.
	 * @param bool  $secure Whether WordPress recognizes the HTTPS connection.
	 */
	public static function supports( array $server, bool $secure ): bool {
		$path   = $server['REQUEST_URI'] ?? '';
		$method = $server['REQUEST_METHOD'] ?? '';
		if ( ! $secure || ! is_string( $path ) || ! is_string( $method ) ) {
			return false;
		}
		if ( in_array( $path, array( '/app-api/checkout', '/app-api/checkout/' ), true ) && in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return true;
		}
		$type = $server['CONTENT_TYPE'] ?? '';
		return 'POST' === $method && in_array( $path, array( '/app-api/checkout', '/app-api/checkout/', '/app-api/checkout/quote', '/app-api/checkout/quote/' ), true ) && is_string( $type ) && 1 === preg_match( '/\Aapplication\/json(?:\s*;[^\r\n]*)?\z/iD', $type );
	}

	/**
	 * Resolve existing browser identity and verified native identity without privilege mixing.
	 *
	 * @param mixed      $existing Existing WordPress principal.
	 * @param array|null $session Verified native session, or null after failed authentication.
	 * @return array Exact principal and explicit conflict flag.
	 */
	public static function principal( $existing, ?array $session ): array {
		$native   = (int) ( $session['user_id'] ?? 0 );
		$conflict = $native > 0 && (int) $existing > 0 && (int) $existing !== $native;
		return array(
			'user_id'  => $conflict ? 0 : max( 0, $native ),
			'conflict' => $conflict,
		);
	}

	/**
	 * WordPress's supported current-user filter runs before normal plugin init policies.
	 *
	 * @param mixed $user_id Existing WordPress authentication result.
	 * @return mixed Unchanged outside the closed native checkout boundary.
	 */
	public static function determineUser( $user_id ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Closed path/method/type allowlist validates raw request metadata without rewriting action URLs.
		if ( ! self::supports( $_SERVER, is_ssl() ) ) {
			return $user_id;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- The strict Bearer grammar and hashed session lookup validate this credential; transforming it would change its identity.
		$header  = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		$session = null;
		if ( is_string( $header ) && '2' === get_option( 'digitalogic_mobile_identity_schema' ) && (bool) get_option( 'digitalogic_mobile_native_auth', false ) ) {
			// No cookie or last-seen writes during early principal resolution.
			$session = MobileIdentityStore::authenticate( $header, false );
		}
		$result         = self::principal( $user_id, $session );
		self::$conflict = self::$conflict || $result['conflict'];
		return self::$conflict ? 0 : $result['user_id'];
	}

	/** Prevent a later controller from accepting a different native/cookie principal pair. */
	public static function conflicted(): bool {
		return self::$conflict;
	}
}
