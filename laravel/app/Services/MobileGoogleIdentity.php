<?php
/**
 * Native GoogleIdentity adapter and verification contracts.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Composer PSR-4 class names and Laravel method contracts are intentional.

use Digitalogic\Vendor\Firebase\JWT\JWK;
use Digitalogic\Vendor\Firebase\JWT\JWT;

/** Google signatures are verified locally against cached HTTPS Google JWKs. */
final class MobileGoogleIdentity {
	/**
	 * Read only the configured public Google web audience.
	 *
	 * @return string Native adapter result.
	 */
	public static function audience(): string {
		$config = get_option( 'digits_option_social_settings', array() );
		$id     = $config['google']['clientID'] ?? '';
		return is_string( $id ) && preg_match( '/\A[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com\z/D', $id ) ? $id : '';
	}

	/**
	 * Read only the configured public Android OAuth client identifier.
	 *
	 * @return string Native adapter result.
	 */
	public static function presenter(): string {
		$id = get_option( 'digitalogic_mobile_google_android_client', '' );
		return is_string( $id ) && preg_match( '/\A[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com\z/D', $id ) ? $id : '';
	}

	/**
	 * Require the verified configuration and runtime dependencies before enabling the adapter.
	 *
	 * @return bool Native adapter result.
	 */
	public static function configured(): bool {
		$audience  = self::audience();
		$presenter = self::presenter();
		// Targeted deployments may add this locked dependency before a full artifact
		// replaces Composer metadata. Register only our package, never another plugin's copy.
		self::loadLibrary();
		$audience_project  = explode( '-', $audience )[0];
		$presenter_project = explode( '-', $presenter )[0];
		return '' !== $audience && '' !== $presenter && $audience_project === $presenter_project && class_exists( JWT::class ) && class_exists( JWK::class );
	}

	/**
	 * Load only the pinned namespace-scoped JWT dependency.
	 *
	 * @return void Native adapter result.
	 */
	public static function loadLibrary(): void {
		$vendor = dirname( __DIR__, 3 ) . '/vendor/';
		if ( is_file( $vendor . 'firebase/php-jwt/digitalogic-scoped/JWT.php' ) ) {
			$loader = require $vendor . 'autoload.php';
			$loader->addPsr4( 'Digitalogic\\Vendor\\Firebase\\JWT\\', $vendor . 'firebase/php-jwt/digitalogic-scoped', true );
		}
	}

	/**
	 * This method is separately exercised with locally signed test keys.
	 *
	 * @param string $token Opaque or signed identity proof; never logged.
	 * @param array  $jwks Jwks.
	 * @param string $audience Audience.
	 * @param string $presenter Presenter.
	 * @return array Native adapter result.
	 * @throws \UnexpectedValueException When the authoritative operation cannot be completed safely.
	 */
	public static function verify( string $token, array $jwks, string $audience, string $presenter ): array {
		self::loadLibrary();
		if ( strlen( $token ) > 6000 || substr_count( $token, '.' ) !== 2 ) {
			throw new \UnexpectedValueException( 'Invalid identity token.' );
		}
		$keys   = array_filter( $jwks['keys'] ?? array(), static fn( $key ) => is_array( $key ) && ( $key['kty'] ?? '' ) === 'RSA' && ( $key['alg'] ?? '' ) === 'RS256' && ( $key['use'] ?? '' ) === 'sig' );
		$claims = (array) JWT::decode( $token, JWK::parseKeySet( array( 'keys' => array_values( $keys ) ), 'RS256' ) );
		if ( ! in_array( $claims['iss'] ?? '', array( 'accounts.google.com', 'https://accounts.google.com' ), true ) || ( $claims['aud'] ?? '' ) !== $audience || ( isset( $claims['azp'] ) && ! in_array( $claims['azp'], array( $presenter, $audience ), true ) ) || ! is_int( $claims['exp'] ?? null ) || $claims['exp'] <= time() || ! is_int( $claims['iat'] ?? null ) || $claims['iat'] > time() + 30 || $claims['iat'] < time() - 3600 || ( $claims['email_verified'] ?? false ) !== true || ! is_string( $claims['email'] ?? null ) || ! filter_var( $claims['email'], FILTER_VALIDATE_EMAIL ) || ! is_string( $claims['sub'] ?? null ) || ! preg_match( '/\A[0-9]{1,255}\z/D', $claims['sub'] ) ) {
			throw new \UnexpectedValueException( 'Invalid identity claims.' );
		}
		return $claims;
	}

	/**
	 * Read or refresh bounded Google public signing keys over HTTPS.
	 *
	 * @return array Native adapter result.
	 * @throws \RuntimeException When the authoritative operation cannot be completed safely.
	 */
	public static function keys(): array {
		$keys = get_transient( 'digitalogic_mobile_google_jwks' );
		if ( is_array( $keys ) && ! empty( $keys['keys'] ) ) {
			return $keys; }
		$response = wp_safe_remote_get(
			'https://www.googleapis.com/oauth2/v3/certs',
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 32768,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			throw new \RuntimeException( 'Google identity keys unavailable.' ); }
		$keys = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $keys ) || empty( $keys['keys'] ) ) {
			throw new \RuntimeException( 'Google identity keys invalid.' ); }
		$cache = (string) wp_remote_retrieve_header( $response, 'cache-control' );
		$ttl   = preg_match( '/max-age=([0-9]+)/', $cache, $matches ) ? max( 60, min( 21600, (int) $matches[1] ) ) : 300;
		set_transient( 'digitalogic_mobile_google_jwks', $keys, $ttl );
		return $keys;
	}

	/**
	 * Only a pre-existing explicit Digits Google subject binding grants identity.
	 *
	 * @param string $subject Subject.
	 * @return int Native adapter result.
	 */
	public static function owner( string $subject ): int {
		$users = get_users(
			array(
				'number'     => 2,
				'fields'     => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact existing Digits binding, limited to two matches to reject ambiguity.
				'meta_query' => array(
					array(
						'key'   => 'auth_google_id',
						'value' => $subject,
					),
				),
			)
		);
		return count( $users ) === 1 ? (int) $users[0] : 0;
	}
}
