<?php

namespace DigitalogicAdmin;

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

final class Users_Command {

	/**
	 * Report normalization counts without changing users.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic-admin users audit
	 *
	 * @when after_wp_load
	 */
	public function audit( array $args, array $assoc_args ): void {
		$this->print_counts( User_Normalizer::run( false ) );
	}

	/**
	 * Normalize eligible users. This is a dry run unless --apply is supplied.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Persist the reported safe changes.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic-admin users normalize
	 *     wp digitalogic-admin users normalize --apply
	 *
	 * @when after_wp_load
	 */
	public function normalize( array $args, array $assoc_args ): void {
		$apply = \WP_CLI\Utils\get_flag_value( $assoc_args, 'apply', false );
		$this->print_counts( User_Normalizer::run( (bool) $apply ) );
	}

	/** @param array<string,int> $counts */
	private function print_counts( array $counts ): void {
		\WP_CLI::line( (string) wp_json_encode( $counts, JSON_UNESCAPED_SLASHES ) );
	}
}
