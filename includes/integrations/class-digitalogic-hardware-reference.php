<?php
/**
 * Revision-specific hardware data for the Digitalogic knowledge hub.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Store and display hardware references separately from inventory verification. */
final class Digitalogic_Hardware_Reference {
	const SCHEMA     = '1';
	const OPTION     = 'digitalogic_hardware_reference_schema';
	const STYLESHEET = 'assets/css/hardware-pinout.css';

	/** Register public read surfaces. */
	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_shortcode( 'dgl_hardware_reference', array( self::class, 'shortcode' ) );
		add_filter( 'rocket_exclude_css', array( self::class, 'preserve_pinout_styles' ) );
		add_filter( 'rocket_rucss_safelist', array( self::class, 'preserve_pinout_styles' ) );
		add_filter( 'rocket_delay_js_exclusions', array( self::class, 'preserve_code_highlighting' ) );
	}

	/**
	 * Keep the versioned pinout stylesheet intact through page optimization.
	 *
	 * @param array $paths Stylesheets already excluded by the site.
	 * @return array
	 */
	public static function preserve_pinout_styles( array $paths ): array {
		$paths[] = wp_parse_url( DIGITALOGIC_PLUGIN_URL . self::STYLESHEET, PHP_URL_PATH );
		return $paths;
	}

	/**
	 * Apply highlighting on page load even when WP Rocket delays other scripts.
	 *
	 * @param array $paths Scripts already excluded from interaction delay.
	 * @return array
	 */
	public static function preserve_code_highlighting( array $paths ): array {
		$paths[] = '/assets/js/hardware-prism.js';
		return $paths;
	}

	/**
	 * Return a fixed, WordPress-prefixed table name.
	 *
	 * @param string $suffix Internal table suffix.
	 * @return string
	 */
	private static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_hw_' . $suffix;
	}

	/**
	 * Install the schema from an explicit WP-CLI deployment.
	 *
	 * @throws RuntimeException On a failed migration.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$tables  = array(
			'boards'      => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(120) NOT NULL,
				manufacturer varchar(100) NOT NULL,
				model varchar(100) NOT NULL,
				revision varchar(40) NOT NULL,
				guide_id bigint unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY guide (guide_id)',
			'sources'     => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				board_id bigint unsigned NOT NULL,
				source_key varchar(48) NOT NULL,
				kind varchar(24) NOT NULL,
				title varchar(200) NOT NULL,
				url varchar(1000) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY board_source (board_id,source_key)',
			'pins'        => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				board_id bigint unsigned NOT NULL,
				connector varchar(24) NOT NULL,
				position smallint unsigned NOT NULL,
				label varchar(48) NOT NULL,
				gpio smallint unsigned DEFAULT NULL,
				role varchar(24) NOT NULL,
				note varchar(400) NOT NULL,
				source_id bigint unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY physical_pin (board_id,connector,position),
				KEY gpio (gpio,board_id)',
			'parameters'  => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				board_id bigint unsigned NOT NULL,
				parameter varchar(48) NOT NULL,
				qualifier varchar(20) NOT NULL,
				magnitude decimal(16,6) NOT NULL,
				unit varchar(10) NOT NULL,
				conditions varchar(500) NOT NULL,
				source_id bigint unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY board_parameter (board_id,parameter,qualifier),
				KEY magnitude (parameter,qualifier,unit,magnitude)',
			'assignments' => 'product_id bigint unsigned NOT NULL,
				sku varchar(100) NOT NULL,
				board_id bigint unsigned NOT NULL,
				evidence_ref varchar(500) NOT NULL,
				verified_at datetime NOT NULL,
				PRIMARY KEY  (product_id),
				UNIQUE KEY sku (sku),
				KEY board (board_id)',
		);
		foreach ( $tables as $suffix => $columns ) {
			$table = self::table( $suffix );
			dbDelta( "CREATE TABLE $table (\n$columns\n) ENGINE=InnoDB $charset;" );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Confirm each explicit migration.
				throw new RuntimeException( 'Hardware reference migration failed.' );
			}
		}
		update_option( self::OPTION, self::SCHEMA, false );
	}

	/** Register the public, read-only board and product resources. */
	public static function routes(): void {
		register_rest_route(
			'digitalogic/v1',
			'/hardware/product',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_product' ),
				'args'                => array(
					'sku' => array(
						'required'  => true,
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100,
					),
				),
			)
		);
		register_rest_route(
			'digitalogic/v1',
			'/hardware/(?P<board>[a-z0-9-]{1,120})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_board' ),
			)
		);
	}

	/**
	 * Read the hardware assigned to a published product by exact SKU.
	 *
	 * @param WP_REST_Request $request HTTP request.
	 * @return array|WP_Error
	 */
	public static function rest_product( WP_REST_Request $request ) {
		global $wpdb;
		$sku = $request['sku'];
		if ( self::SCHEMA === get_option( self::OPTION ) ) {
			$assignments = self::table( 'assignments' );
			$row         = $wpdb->get_row( $wpdb->prepare( "SELECT product_id, sku FROM $assignments WHERE sku = %s", $sku ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and indexed SKU.
			if ( $row && $sku === $row['sku'] ) {
				$product_id = (int) $row['product_id'];
				$hardware   = self::for_product( $product_id, $sku );
				if ( $hardware ) {
					foreach ( $hardware['pins'] as &$pin ) {
						$pin['position'] = (int) $pin['position'];
						$pin['gpio']     = null === $pin['gpio'] ? null : (int) $pin['gpio'];
					}
					unset( $pin );
					return array(
						'product_id' => $product_id,
						'sku'        => $sku,
						'hardware'   => $hardware,
					);
				}
			}
		}
		return new WP_Error( 'digitalogic_hardware_product_missing', 'مرجع تأییدشده‌ای برای این کد کالا ثبت نشده است.', array( 'status' => 404 ) );
	}

	/**
	 * Read a published reference as structured data.
	 *
	 * @param WP_REST_Request $request HTTP request.
	 * @return array|WP_Error
	 */
	public static function rest_board( WP_REST_Request $request ) {
		$board = self::read( $request['board'] );
		return $board ? $board : new WP_Error( 'digitalogic_hardware_missing', 'مرجع سخت‌افزار پیدا نشد.', array( 'status' => 404 ) );
	}

	/**
	 * Read normalized rows for a published guide.
	 *
	 * @param string $slug Board revision key.
	 * @return array
	 */
	public static function read( string $slug ): array {
		global $wpdb;
		if ( self::SCHEMA !== get_option( self::OPTION ) ) {
			return array();
		}
		$boards = self::table( 'boards' );
		$board  = $wpdb->get_row( $wpdb->prepare( "SELECT id,slug,manufacturer,model,revision,guide_id FROM $boards WHERE slug = %s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and prepared key.
		if ( ! $board || 'publish' !== get_post_status( (int) $board['guide_id'] ) ) {
			return array();
		}
		$sources  = self::table( 'sources' );
		$pins     = self::table( 'pins' );
		$values   = self::table( 'parameters' );
		$board_id = (int) $board['id'];
		return array(
			'slug'         => $board['slug'],
			'manufacturer' => $board['manufacturer'],
			'model'        => $board['model'],
			'revision'     => $board['revision'],
			'guide'        => get_permalink( (int) $board['guide_id'] ),
			'pins'         => $wpdb->get_results( $wpdb->prepare( "SELECT p.connector,p.position,p.label,p.gpio,p.role,p.note,s.source_key AS source FROM $pins p JOIN $sources s ON s.id=p.source_id AND s.board_id=p.board_id WHERE p.board_id = %d ORDER BY p.connector,p.position", $board_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed tables and indexed revision.
			'parameters'   => $wpdb->get_results( $wpdb->prepare( "SELECT p.parameter,p.qualifier,p.magnitude,p.unit,p.conditions,s.source_key AS source FROM $values p JOIN $sources s ON s.id=p.source_id AND s.board_id=p.board_id WHERE p.board_id = %d ORDER BY p.id", $board_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed tables and indexed revision.
			'sources'      => $wpdb->get_results( $wpdb->prepare( "SELECT source_key,kind,title,url FROM $sources WHERE board_id = %d ORDER BY id", $board_id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and indexed revision.
		);
	}

	/**
	 * Find a revision explicitly assigned to the current exact product.
	 *
	 * @param int         $product_id WooCommerce product or variation ID.
	 * @param string|null $exact_sku SKU supplied by a caller that already resolved the product.
	 * @return array
	 */
	public static function for_product( int $product_id, ?string $exact_sku = null ): array {
		global $wpdb;
		if ( self::SCHEMA !== get_option( self::OPTION ) ) {
			return array();
		}
		$assignments = self::table( 'assignments' );
		$boards      = self::table( 'boards' );
		$row         = $wpdb->get_row( $wpdb->prepare( "SELECT a.sku,b.slug FROM $assignments a JOIN $boards b ON b.id=a.board_id WHERE a.product_id = %d", $product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed tables and indexed product ID.
		if ( ! $row || ( null !== $exact_sku && $exact_sku !== $row['sku'] ) || ! self::matches_sku( $product_id, $row['sku'] ) ) {
			return array();
		}
		return self::read( $row['slug'] );
	}

	/**
	 * Assign a published reference after review of stock-linked revision evidence.
	 *
	 * @param int    $product_id WooCommerce product or variation ID.
	 * @param string $sku Exact SKU and Product Code.
	 * @param string $slug Published revision reference.
	 * @param string $evidence_ref Private stock evidence identifier.
	 * @throws RuntimeException If authorization, identity or write fails.
	 */
	public static function assign( int $product_id, string $sku, string $slug, string $evidence_ref ): void {
		global $wpdb;
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $product_id )
			|| ! self::matches_sku( $product_id, $sku ) || '' === trim( $evidence_ref ) || strlen( $evidence_ref ) > 500 || ! self::read( $slug ) ) {
			throw new RuntimeException( 'Exact product, published revision and reviewed stock evidence are required.' );
		}
		$boards   = self::table( 'boards' );
		$board_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $boards WHERE slug = %s", $slug ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and indexed key.
		$data     = array(
			'product_id'   => $product_id,
			'sku'          => $sku,
			'board_id'     => (int) $board_id,
			'evidence_ref' => trim( $evidence_ref ),
			'verified_at'  => gmdate( 'Y-m-d H:i:s' ),
		);
		// Reject a collision rather than letting REPLACE delete another product's assignment.
		if ( ! $wpdb->insert( self::table( 'assignments' ), $data ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit reviewed assignment.
			throw new RuntimeException( 'Assignment already exists or could not be written.' );
		}
	}

	/**
	 * Check the raw WooCommerce SKU and the canonical Patris Product Code.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $sku Expected value.
	 * @return bool
	 */
	private static function matches_sku( int $product_id, string $sku ): bool {
		$product = wc_get_product( $product_id );
		return '' !== $sku && $product && 'publish' === $product->get_status( 'edit' )
			&& $sku === $product->get_sku( 'edit' )
			&& $sku === $product->get_meta( Digitalogic_Product_Identifier_Resolver::PATRIS_CODE_META, true, 'edit' );
	}

	/**
	 * Render a semantic, revision-scoped pin table and electrical limits.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $attributes ): string {
		$attributes = shortcode_atts( array( 'board' => '' ), $attributes );
		$board      = self::read( sanitize_key( $attributes['board'] ) );
		if ( ! $board ) {
			return '';
		}
		ob_start();
		require __DIR__ . '/hardware-reference-view.php';
		return (string) ob_get_clean();
	}
}
