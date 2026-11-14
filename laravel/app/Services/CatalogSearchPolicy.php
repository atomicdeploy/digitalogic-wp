<?php
/**
 * Bounded query normalization for canonical catalog discovery, never identity writes.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reusable PSR-4 query policy.

/** Equivalent query words expand candidates before SQL pagination. */
final class CatalogSearchPolicy {

	/**
	 * Normalize presentation differences while retaining meaningful model punctuation.
	 *
	 * @param string $value Customer search input.
	 * @return string Bounded comparison text, not a product identity.
	 */
	public static function normalize( string $value ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_KC );
			$value      = is_string( $normalized ) ? $normalized : $value;
		}
		$value = strtr( $value, array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
		$value = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $value );
		$value = preg_replace( '/[\x{061C}\x{200E}\x{200F}\x{2066}-\x{2069}]/u', '', $value );
		$value = preg_replace( '/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', ' ', $value );
		return mb_substr( mb_strtolower( trim( sanitize_text_field( $value ) ), 'UTF-8' ), 0, 120 );
	}

	/**
	 * Expand configured linguistic equivalents symmetrically, with fixed work bounds.
	 *
	 * @param string     $search Normalized or raw search input.
	 * @param array|null $groups Explicit policy for fixtures; production uses WordPress options.
	 * @return array Unique sorted query alternatives.
	 */
	public static function terms( string $search, ?array $groups = null ): array {
		$search = self::normalize( $search );
		if ( '' === $search ) {
			return array();
		}
		$groups ??= self::groups( function_exists( 'woodmart_get_opt' ) ? (string) woodmart_get_opt( 'search_synonyms' ) : '' );
		$terms    = array( $search );
		foreach ( array_slice( $groups, 0, 20 ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$aliases = array();
			foreach ( array_slice( $group, 0, 6 ) as $alias ) {
				if ( is_string( $alias ) ) {
					$alias = self::normalize( $alias );
					if ( '' !== $alias && mb_strlen( $alias ) <= 40 ) {
						$aliases[] = $alias;
					}
				}
			}
			foreach ( $aliases as $alias ) {
				$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $alias, '/' ) . '(?![\p{L}\p{N}])/u';
				if ( ! preg_match( $pattern, $search ) ) {
					continue;
				}
				foreach ( $aliases as $replacement ) {
					$terms[] = preg_replace_callback( $pattern, static fn() => $replacement, $search );
				}
			}
			$terms = array_values( array_unique( $terms ) );
			if ( count( $terms ) >= 12 ) {
				break;
			}
		}
		$alternatives = array_values( array_diff( $terms, array( $search ) ) );
		sort( $alternatives, SORT_STRING );
		$terms = array_merge( array( $search ), array_slice( $alternatives, 0, 11 ) );
		sort( $terms, SORT_STRING );
		return $terms;
	}

	/**
	 * Read the existing Woodmart admin setting rather than creating another registry.
	 *
	 * @param string $configured Canonical newline-separated term = alias,alias setting.
	 * @return array Bounded equivalent word groups.
	 */
	public static function groups( string $configured ): array {
		$groups = array();
		foreach ( array_slice( preg_split( '/\R/u', $configured ), 0, 20 ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$groups[] = array_merge( array( trim( $parts[0] ) ), array_map( 'trim', explode( ',', $parts[1] ) ) );
			}
		}
		return $groups;
	}
}
