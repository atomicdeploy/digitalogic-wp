<?php
/**
 * Manufacturer specifications that do not depend on a PCB revision.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Keep chip documentation independent of inventory and board assignments. */
final class Digitalogic_Chip_Reference {
	private const OPTION = 'digitalogic_chip_reference_schema';
	private const SCHEMA = '1';

	/**
	 * Resolve a fixed table name.
	 *
	 * @param string $suffix Internal table suffix.
	 */
	private static function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_hw_chip_' . $suffix;
	}

	/**
	 * Install the tables from explicit CLI provisioning.
	 *
	 * @throws RuntimeException If a table or schema marker cannot be created.
	 */
	private static function install(): void {
		if ( self::SCHEMA === get_option( self::OPTION ) ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$tables  = array(
			'parts'          => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(120) NOT NULL,
				manufacturer varchar(100) NOT NULL,
				part_number varchar(100) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				UNIQUE KEY manufacturer_part (manufacturer,part_number)',
			'sources'        => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				chip_id bigint unsigned NOT NULL,
				document_key varchar(48) NOT NULL,
				title varchar(200) NOT NULL,
				url varchar(1000) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY chip_document (chip_id,document_key)',
			'specifications' => 'id bigint unsigned NOT NULL AUTO_INCREMENT,
				chip_id bigint unsigned NOT NULL,
				parameter varchar(48) NOT NULL,
				qualifier varchar(20) NOT NULL,
				condition_key varchar(48) NOT NULL,
				magnitude decimal(16,6) NOT NULL,
				unit varchar(16) NOT NULL,
				conditions varchar(500) NOT NULL,
				source_id bigint unsigned NOT NULL,
				source_locator varchar(160) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY chip_specification (chip_id,parameter,qualifier,condition_key),
				KEY magnitude (parameter,qualifier,unit,magnitude)',
		);
		foreach ( $tables as $suffix => $columns ) {
			$table = self::table( $suffix );
			dbDelta( "CREATE TABLE $table (\n$columns\n) ENGINE=InnoDB $charset;" );
			if ( $wpdb->last_error || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Check explicit schema migration.
				throw new RuntimeException( 'Chip reference migration failed.' );
			}
		}
		update_option( self::OPTION, self::SCHEMA, false );
		if ( self::SCHEMA !== get_option( self::OPTION ) ) {
			throw new RuntimeException( 'Chip reference schema marker was not saved.' );
		}
	}

	/** Register a public resource for manufacturer-only specifications. */
	public static function routes(): void {
		register_rest_route(
			'digitalogic/v1',
			'/hardware/chips/(?P<chip>[a-z0-9-]{1,120})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'rest_chip' ),
			)
		);
	}

	/**
	 * Serve a chip reference by slug.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array|WP_Error
	 */
	public static function rest_chip( WP_REST_Request $request ) {
		$chip = self::read( (string) $request->get_param( 'chip' ) );
		return $chip ? $chip : new WP_Error( 'digitalogic_chip_not_found', 'مرجع تراشه پیدا نشد.', array( 'status' => 404 ) );
	}

	/**
	 * Read a chip and its sourced specifications in a single snapshot.
	 *
	 * @param string $slug Reference slug, independent of any SKU.
	 * @return array Chip data, or an empty array for an unknown reference.
	 */
	public static function read( string $slug ): array {
		if ( self::SCHEMA !== get_option( self::OPTION ) ) {
			return array();
		}
		global $wpdb;
		$parts   = self::table( 'parts' );
		$specs   = self::table( 'specifications' );
		$sources = self::table( 'sources' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Use fixed table names and one consistent read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.slug, c.manufacturer, c.part_number, p.parameter, p.qualifier,
					p.condition_key, p.magnitude, p.unit, p.conditions, p.source_locator,
					s.document_key, s.title, s.url
				FROM $parts c
				JOIN $specs p ON p.chip_id = c.id
				JOIN $sources s ON s.id = p.source_id AND s.chip_id = c.id
				WHERE c.slug = %s
				ORDER BY p.parameter, p.condition_key, FIELD(p.qualifier, 'min', 'typ', 'max', 'nominal')",
				$slug
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return array();
		}
		$reference = array(
			'scope'          => 'chip',
			'slug'           => $rows[0]['slug'],
			'manufacturer'   => $rows[0]['manufacturer'],
			'part_number'    => $rows[0]['part_number'],
			'specifications' => array(),
		);
		foreach ( $rows as $row ) {
			$reference['specifications'][] = array(
				'parameter'     => $row['parameter'],
				'qualifier'     => $row['qualifier'],
				'condition_key' => $row['condition_key'],
				'magnitude'     => (string) $row['magnitude'],
				'unit'          => $row['unit'],
				'conditions'    => $row['conditions'],
				'source'        => array(
					'document_key' => $row['document_key'],
					'title'        => $row['title'],
					'url'          => $row['url'],
					'locator'      => $row['source_locator'],
				),
			);
		}
		return $reference;
	}

	/**
	 * Write one bundled, reviewed manufacturer manifest.
	 *
	 * @param array $manifest Reviewed part, public datasheet, and specifications.
	 * @return array Verified stored reference.
	 * @throws RuntimeException If provisioned data cannot be written or read back.
	 * @throws Throwable If provisioning fails before the transaction commits.
	 */
	public static function provision( array $manifest ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit CLI transaction; table names are fixed.
		$lock = 'digitalogic-chip-' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			throw new RuntimeException( 'Another chip provisioning operation is active.' );
		}
		try {
			self::install();
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Could not start the chip reference transaction.' );
			}
			try {
				$parts   = self::table( 'parts' );
				$specs   = self::table( 'specifications' );
				$sources = self::table( 'sources' );
				$part    = $manifest['part'];
				$id      = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $parts WHERE slug = %s", $part['slug'] ) );
				if ( $wpdb->last_error ) {
					throw new RuntimeException( 'Could not look up the chip.' );
				}
				if ( $id ) {
					if ( false === $wpdb->update( $parts, $part, array( 'id' => (int) $id ) )
						|| false === $wpdb->delete( $specs, array( 'chip_id' => (int) $id ) )
						|| false === $wpdb->delete( $sources, array( 'chip_id' => (int) $id ) ) ) {
						throw new RuntimeException( 'Could not replace the reviewed chip reference.' );
					}
				} else {
					$id = self::insert( $parts, $part );
				}

				$source_id = self::insert( $sources, array( 'chip_id' => (int) $id ) + $manifest['source'] );
				foreach ( $manifest['specifications'] as $spec ) {
					self::insert(
						$specs,
						array(
							'chip_id'   => (int) $id,
							'source_id' => $source_id,
						) + $spec
					);
				}
				$result = self::read( $part['slug'] );
				if ( ! $result || count( $result['specifications'] ) !== count( $manifest['specifications'] ) ) {
					throw new RuntimeException( 'The written chip reference could not be read back completely.' );
				}
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'Could not commit the chip reference.' );
				}
				return $result;
			} catch ( Throwable $error ) {
				$wpdb->query( 'ROLLBACK' );
				throw $error;
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert an import record or fail the whole import.
	 *
	 * @param string $table Fixed table name.
	 * @param array  $record Record to store.
	 * @throws RuntimeException When the record could not be inserted.
	 */
	private static function insert( string $table, array $record ): int {
		global $wpdb;
		if ( 1 !== $wpdb->insert( $table, $record ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reviewed CLI manifest only.
			throw new RuntimeException( 'Could not write a chip reference record.' );
		}
		return (int) $wpdb->insert_id;
	}
}
