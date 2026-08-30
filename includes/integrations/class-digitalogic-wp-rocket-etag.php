<?php
/**
 * Preserve the pricing revision ETag in WP Rocket's Apache rules.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scopes WP Rocket's ETag removal without changing its static-file policy.
 */
final class Digitalogic_WP_Rocket_ETag {

	private const STOCK_ETAG_DIRECTIVE           = 'Header unset ETag';
	private const MOD_DEFLATE_OPEN               = '<IfModule mod_deflate.c>';
	private const PRICING_SYNC_REQUEST_PATTERN   = 'm#\\\\s/+wp-json/digitalogic/pricing/sync/(?:revision|snapshots|builds)(?:[/?\\\\s])#';
	private const PRICING_SYNC_ETAG_DIRECTIVE    = 'Header unset ETag "expr=%{THE_REQUEST} !~ ' . self::PRICING_SYNC_REQUEST_PATTERN . '"';
	private const PRICING_SYNC_NO_GZIP_DIRECTIVE = '<IfModule mod_setenvif.c>' . PHP_EOL
		. 'SetEnvIfNoCase Request_URI "^/wp-json/digitalogic/pricing/sync/(?:revision|snapshots|builds)(?:[/?]|$)" no-gzip dont-vary' . PHP_EOL
		. '</IfModule>';

	/** Register the WP Rocket generator filter. */
	public static function init(): void {
		add_filter( 'rocket_htaccess_etag', array( self::class, 'scope_etag_removal' ), 100, 1 );
		add_filter( 'rocket_htaccess_mod_deflate', array( self::class, 'disable_pricing_sync_compression' ), 100, 1 );
	}

	/**
	 * Preserve application ETags only for the pricing revision request.
	 *
	 * The original request line is required because per-directory WordPress
	 * rewrites can change REQUEST_URI before mod_headers evaluates its rule.
	 *
	 * @param string $rules WP Rocket's generated ETag rules.
	 * @return string
	 */
	public static function scope_etag_removal( string $rules ): string {
		$stock_line = self::STOCK_ETAG_DIRECTIVE . PHP_EOL;

		if ( 1 !== substr_count( $rules, $stock_line ) ) {
			return $rules;
		}

		return str_replace(
			$stock_line,
			self::PRICING_SYNC_ETAG_DIRECTIVE . PHP_EOL,
			$rules
		);
	}

	/**
	 * Keep authenticated pricing-sync representations byte-identical.
	 *
	 * Apache's global DEFLATE output filter can append a representation suffix
	 * to an application-owned strong ETag. The pricing client explicitly asks
	 * for identity encoding, but a proxy can mangle that request header. A
	 * route-scoped no-gzip environment flag makes the strong SHA-256 ETag exact
	 * at the origin for revision, snapshot, status, and page responses.
	 *
	 * @param string $rules WP Rocket's generated mod_deflate rules.
	 * @return string
	 */
	public static function disable_pricing_sync_compression( string $rules ): string {
		$open = self::MOD_DEFLATE_OPEN . PHP_EOL;
		if (
			1 !== substr_count( $rules, $open )
			|| str_contains( $rules, self::PRICING_SYNC_NO_GZIP_DIRECTIVE )
		) {
			return $rules;
		}

		return str_replace(
			$open,
			$open . self::PRICING_SYNC_NO_GZIP_DIRECTIVE . PHP_EOL,
			$rules
		);
	}
}
