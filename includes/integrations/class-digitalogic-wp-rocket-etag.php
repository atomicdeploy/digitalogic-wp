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

	private const STOCK_ETAG_DIRECTIVE            = 'Header unset ETag';
	private const PRICING_REVISION_ETAG_DIRECTIVE = 'Header unset ETag "expr=%{THE_REQUEST} !~ m#\\\\s/+wp-json/digitalogic/pricing/sync/revision(?:[/?\\\\s])#"';

	/** Register the WP Rocket generator filter. */
	public static function init(): void {
		add_filter( 'rocket_htaccess_etag', array( self::class, 'scope_etag_removal' ), 100, 1 );
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
			self::PRICING_REVISION_ETAG_DIRECTIVE . PHP_EOL,
			$rules
		);
	}
}
