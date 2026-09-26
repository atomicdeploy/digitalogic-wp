<?php
/**
 * WP-CLI entrypoint for WooCommerce and Patris-fed order documents.
 *
 * @package Digitalogic
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/** Generate one branded PDF from a live order or a reviewed JSON payload. */
final class Digitalogic_Order_Document_CLI {

	/**
	 * Generate a PDF document.
	 *
	 * ## OPTIONS
	 *
	 * [--order=<id>]
	 * : Existing WooCommerce order ID.
	 *
	 * [--input=<path>]
	 * : Absolute path to a trusted JSON payload, such as a reviewed Patris API export.
	 *
	 * --output=<path>
	 * : Absolute output PDF path.
	 *
	 * [--format=<format>]
	 * : Output result format. Defaults to table; json is supported.
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Named arguments.
	 */
	public static function generate( array $args, array $assoc_args ): void {
		unset( $args );
		$order_id = isset( $assoc_args['order'] ) ? absint( $assoc_args['order'] ) : 0;
		$input    = isset( $assoc_args['input'] ) ? wp_normalize_path( (string) $assoc_args['input'] ) : '';
		$output   = isset( $assoc_args['output'] ) ? wp_normalize_path( (string) $assoc_args['output'] ) : '';

		if ( ( $order_id > 0 ) === ( '' !== $input ) ) {
			WP_CLI::error( 'Specify exactly one of --order or --input.' );
		}
		if ( '' === $output ) {
			WP_CLI::error( '--output is required.' );
		}

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				WP_CLI::error( 'Order not found.' );
			}
			$payload = Digitalogic_Order_Documents::payload_from_order( $order );
			$source  = 'woocommerce';
		} else {
			if ( ! is_readable( $input ) ) {
				WP_CLI::error( 'The input JSON file is not readable.' );
			}
			$payload = json_decode( (string) file_get_contents( $input ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an explicit local CLI input file.
			if ( ! is_array( $payload ) ) {
				WP_CLI::error( 'The input file does not contain a JSON object.' );
			}
			$source = 'custom';
		}

		$result = Digitalogic_Order_Documents::generate_custom_pdf( $payload, $output );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$response = array(
			'ok'         => true,
			'source'     => $source,
			'output'     => $output,
			'size_bytes' => filesize( $output ),
		);
		if ( 'json' === ( $assoc_args['format'] ?? '' ) ) {
			WP_CLI::line( wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		WP_CLI::success( sprintf( 'Generated %s (%d bytes).', $output, $response['size_bytes'] ) );
	}
}

WP_CLI::add_command( 'digitalogic document generate', array( Digitalogic_Order_Document_CLI::class, 'generate' ) );
