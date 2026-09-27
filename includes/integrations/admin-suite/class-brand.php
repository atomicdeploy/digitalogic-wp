<?php

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Brand {

	public const KEY  = 'digitalogic';
	public const NAME = 'Digitalogic';

	/**
	 * Keep this plugin's presentation dormant while Digitalogic's established
	 * interface is enabled. No plugin slug, class name, or foreign site signal
	 * participates in this decision.
	 */
	public static function ui_enabled(): bool {
		return ! self::legacy_ui_enabled();
	}

	public static function legacy_ui_enabled(): bool {
		return strtolower( trim( (string) get_option( 'digitalogic_custom_ui_enabled', 'no' ) ) ) === 'yes';
	}

	/** @return array{name:string, primary:string, accent:string, surface:string, font:string} */
	public static function design(): array {
		return array(
			'name'    => self::NAME,
			'primary' => '#1169d8',
			'accent'  => '#0fa7eb',
			'surface' => '#f2f8fc',
			'font'    => 'YekanBakh, IRANSans, IRANYekan, Tahoma, system-ui, sans-serif',
		);
	}

	public static function logo_url(): string {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$logo = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $logo ) && $logo !== '' ) {
				return $logo;
			}
		}

		$icon = get_site_icon_url( 512 );
		if ( ! $icon ) {
			$icon = get_site_icon_url( 192 );
		}

		return is_string( $icon ) ? $icon : '';
	}
}
