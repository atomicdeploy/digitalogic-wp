<?php
/**
 * Define WordPress translation ownership before Composer loads Laravel helpers.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.WP.I18n -- Test-only WordPress function stub.
if ( ! function_exists( '__' ) ) {
	/** Return untranslated test text, matching the shared bootstrap stub. */
	function __( $message, $domain = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $message;
	}
}
