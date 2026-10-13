<?php
/**
 * Administrator-only research commands; JSON mutations use stdin.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Research commands and contract verification. */
final class Digitalogic_Product_Research_CLI {
	/** Run.
	 *
	 * @param callable $callback Callback.
	 */
	private static function run( $callback ) {
		try {
			Digitalogic_Product_Research::authorize();
			WP_CLI::line( wp_json_encode( $callback(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
	}

	/** Create private research tables (non-destructive).
	 *
	 * ## EXAMPLES
	 *     wp digitalogic research install --user=1
	 */
	public function install() {
		self::run(
			static function () {
				Digitalogic_Product_Research::install();
				return Digitalogic_Product_Research::status();
			}
		);
	}

	/** Claim 1-10 published parent products. Claims expire after 55 minutes.
	 *
	 * ## OPTIONS
	 * [--limit=<number>]
	 * : Maximum products, default 10 (clamped to 1-10).
	 *
	 * @param array $args Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function batch( $args, $assoc_args ) {
		self::run(
			static function () use ( $assoc_args ) {
				return Digitalogic_Product_Research::batch( $assoc_args['limit'] ?? 10 );
			}
		);
	}

	/** Save one claimed product's evidence and outcome from stdin JSON.
	 *
	 * ## EXAMPLES
	 *     wp digitalogic research record --user=1 < evidence.json
	 */
	public function record() {
		self::run(
			static function () {
				$raw = stream_get_contents( STDIN, 1048577 );
				if ( strlen( $raw ) > 1048576 ) {
					throw new RuntimeException( 'Research input exceeds 1 MiB.' );
				}
				$input = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
				if ( ! is_array( $input ) ) {
					throw new RuntimeException( 'JSON object required.' );
				}
				return Digitalogic_Product_Research::record( $input );
			}
		);
	}

	/** Read private listings and coverage for one exact parent.
	 *
	 * ## OPTIONS
	 * --id=<product_id>
	 * : Exact parent product ID.
	 *
	 * @param array $args Args.
	 * @param array $assoc_args Assoc args.
	 */
	public function product( $args, $assoc_args ) {
		self::run(
			static function () use ( $assoc_args ) {
				return Digitalogic_Product_Research::product( absint( $assoc_args['id'] ?? 0 ) );
			}
		);
	}

	/** Read total coverage counts. No private listing information. */
	public function status() {
		self::run( array( 'Digitalogic_Product_Research', 'status' ) );
	}

	/** Extract public HTML metadata and JSON-LD as unreviewed candidates.
	 * Read one JSON object with url from stdin. Does not store prices automatically.
	 */
	public function inspect() {
		self::run(
			static function () {
				$input = json_decode( stream_get_contents( STDIN, 8192 ), true, 16, JSON_THROW_ON_ERROR );
				return Digitalogic_Product_Research::inspect( $input['url'] ?? '' );
			}
		);
	}
	/**
	 * Import ten parents with pre-existing private links; returns a cursor.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 *
	 * ## OPTIONS
	 * [--after=<id>]
	 * : Last imported parent ID, default zero.
	 */
	public function import_legacy( $args, $assoc_args ) {
		self::run(
			static function () use ( $assoc_args ) {
				return Digitalogic_Product_Research::import_legacy( absint( $assoc_args['after'] ?? 0 ) );
			}
		);
	}
}

WP_CLI::add_command( 'digitalogic research', 'Digitalogic_Product_Research_CLI' );
