<?php
/**
 * Shared visual contract for the public Digitalogic storefront.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Enqueue the cross-surface storefront design system after feature styles. */
final class Digitalogic_Storefront_Design_System {
	private const STYLE_HANDLE = 'digitalogic-storefront-design-system';

	/** Register the late frontend stylesheet. */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 120 );
		add_action( 'wp_footer', array( self::class, 'render_recaptcha_disclosure' ), 120 );
	}

	/** Load a single cache-busted style contract on public storefront requests. */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		$path = DIGITALOGIC_PLUGIN_DIR . 'assets/css/storefront-design-system.css';
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			DIGITALOGIC_PLUGIN_URL . 'assets/css/storefront-design-system.css',
			array(),
			(string) filemtime( $path )
		);
	}

	/**
	 * Keep required reCAPTCHA branding in-flow when its fixed badge is hidden.
	 *
	 * The fixed badge obscures mobile commerce controls. Google's documented
	 * alternative is a visible disclosure in the user flow.
	 */
	public static function render_recaptcha_disclosure(): void {
		?>
		<p class="dgl-recaptcha-disclosure" lang="en" dir="ltr">
			This site is protected by reCAPTCHA and the Google
			<a href="https://policies.google.com/privacy" rel="noopener noreferrer">Privacy Policy</a> and
			<a href="https://policies.google.com/terms" rel="noopener noreferrer">Terms of Service</a> apply.
		</p>
		<?php
	}
}
