<?php
/**
 * Compatibility guard for WP Rocket's Cloudflare callback cleanup.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

final class Digitalogic_WP_Rocket_Cloudflare_Fix {
	private static $restore = array();
	private static $hooks   = array( 'deleted_post', 'transition_post_status' );
	private static $prefix  = 'digitalogic_wpr_cf_intkey_';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'stringify_keys' ), 9 );
		add_action( 'init', array( self::class, 'restore_keys' ), 11 );
	}

	public static function stringify_keys(): void {
		global $wp_filter;
		foreach ( self::$hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $key => $config ) {
					if ( ! is_int( $key ) ) {
						continue;
					}
					$temp_key = self::$prefix . $key;
					$wp_filter[ $hook ]->callbacks[ $priority ][ $temp_key ] = $config;
					unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $key ] );
					self::$restore[ $hook ][ $priority ][ $temp_key ] = $key;
				}
			}
		}
	}

	public static function restore_keys(): void {
		global $wp_filter;
		foreach ( self::$restore as $hook => $priorities ) {
			if ( empty( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
				continue;
			}
			foreach ( $priorities as $priority => $map ) {
				foreach ( $map as $temp_key => $original_key ) {
					if ( isset( $wp_filter[ $hook ]->callbacks[ $priority ][ $temp_key ] ) ) {
						$wp_filter[ $hook ]->callbacks[ $priority ][ $original_key ] = $wp_filter[ $hook ]->callbacks[ $priority ][ $temp_key ];
						unset( $wp_filter[ $hook ]->callbacks[ $priority ][ $temp_key ] );
					}
				}
			}
		}
		self::$restore = array();
	}
}
