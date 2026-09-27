<?php
/**
 * Migrated runtime parity or focused test fixture.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Migrated runtime parity or focused test fixture; isolated from the existing coding-standard debt baseline.
/**
 * Publish one current currency event and clear storefront caches after a canonical currency change.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Currency_Storefront_Freshness {
	private const OPTIONS = array(
		'dollar_price',
		'options_dollar_price',
		'yuan_price',
		'options_yuan_price',
	);

	private static $dirty           = false;
	private static $event_cursor    = 0;
	private static $changed_options = array();

	public static function register() {
		add_action( 'updated_option', array( __CLASS__, 'updated_option' ), 1, 3 );
		add_action( 'added_option', array( __CLASS__, 'added_option' ), 1, 2 );
	}

	public static function updated_option( $option, $old_value, $value ) {
		if ( $old_value === $value ) {
			return;
		}
		self::mark_dirty( $option );
	}

	public static function added_option( $option, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		self::mark_dirty( $option );
	}

	private static function mark_dirty( $option ) {
		if ( ! in_array( $option, self::OPTIONS, true ) ) {
			return;
		}
		self::$changed_options[ $option ] = true;
		if ( self::$dirty ) {
			return;
		}
		self::$dirty = true;
		if ( class_exists( 'Digitalogic_Panel' ) ) {
			self::$event_cursor = Digitalogic_Panel::get_latest_event_id();
		}
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
	}

	public static function flush() {
		if ( ! self::$dirty ) {
			return;
		}

		$event_exists = false;
		if ( class_exists( 'Digitalogic_Panel' ) ) {
			foreach ( Digitalogic_Panel::get_events_since( self::$event_cursor ) as $event ) {
				if ( 'currency.updated' === (string) ( $event['name'] ?? '' ) ) {
					$event_exists = true;
					break;
				}
			}
			if ( ! $event_exists ) {
				Digitalogic_Panel::record_event(
					'currency.updated',
					array(
						'option'          => 'canonical_currency_store',
						'changed_options' => array_keys( self::$changed_options ),
					)
				);
			}
		}

		if ( function_exists( 'rocket_clean_files' ) ) {
			// The currency cards requested here live in the homepage header. Purging
			// one URL keeps the write response bounded; open pages update through SSE.
			rocket_clean_files( array( home_url( '/' ) ) );
		}

		do_action( 'digitalogic_currency_storefront_refreshed', array_keys( self::$changed_options ) );
	}
}
