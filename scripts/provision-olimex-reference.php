<?php
/**
 * Publish the reviewed manufacturer reference through WP-CLI eval-file.
 *
 * @package Digitalogic
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'publish_posts' ) ) {
	WP_CLI::error( 'Use an existing administrator with publishing access.' );
}

global $wpdb;
$data       = require __DIR__ . '/olimex-esp32-gateway-rev-i.php';
$prefix     = $wpdb->prefix . 'digitalogic_hw_';
$slug       = $data['slug'];
$guide_type = Digitalogic_Knowledge_Hub::POST_TYPE;

Digitalogic_Hardware_Reference::install();
$lock = 'digitalogic-hw-' . md5( DB_NAME . $wpdb->prefix );
if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize editorial provisioning.
	WP_CLI::error( 'Another hardware provisioning operation is active.' );
}

try {
	$guide = get_page_by_path( $slug, OBJECT, $guide_type );
	if ( $guide && get_post_meta( $guide->ID, '_digitalogic_hardware_manifest', true ) !== $slug ) {
		throw new RuntimeException( 'An unrelated guide already uses this slug.' );
	}
	if ( ! $guide ) {
		$guide_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $guide_type,
					'post_status'  => 'draft',
					'post_name'    => $slug,
					'post_title'   => $data['title'],
					'post_excerpt' => $data['excerpt'],
					'post_content' => $data['content'],
					'meta_input'   => array( '_digitalogic_hardware_manifest' => $slug ),
				)
			),
			true
		);
		if ( ! $guide_id || is_wp_error( $guide_id ) ) {
			throw new RuntimeException( 'The guide draft could not be created.' );
		}
		$guide = get_post( $guide_id );
	}

	$topic = term_exists( 'esp', Digitalogic_Knowledge_Hub::TOPIC_TAXONOMY );
	if ( ! $topic || is_wp_error( wp_set_object_terms( $guide->ID, (int) $topic['term_id'], Digitalogic_Knowledge_Hub::TOPIC_TAXONOMY, true ) ) ) {
		throw new RuntimeException( 'The existing ESP topic could not be assigned.' );
	}
	$insert = static function ( string $suffix, array $record ) use ( $wpdb, $prefix ): int {
		if ( ! $wpdb->insert( $prefix . $suffix, $record ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit transactional import.
			throw new RuntimeException( 'Could not write a reviewed hardware record.' );
		}
		return (int) $wpdb->insert_id;
	};
	$boards = $prefix . 'boards';
	$entry  = $wpdb->get_row( $wpdb->prepare( "SELECT id,guide_id FROM $boards WHERE slug=%s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table.
	if ( $entry && (int) $entry['guide_id'] !== (int) $guide->ID ) {
		throw new RuntimeException( 'The board is already bound to a different guide.' );
	}
	if ( ! $entry ) {
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Make the reference visible only as a complete set.
			throw new RuntimeException( 'Unable to start a hardware reference transaction.' );
		}
		try {
			$board_id   = $insert(
				'boards',
				array(
					'slug'         => $slug,
					'manufacturer' => $data['manufacturer'],
					'model'        => $data['model'],
					'revision'     => $data['revision'],
					'guide_id'     => $guide->ID,
				)
			);
			$source_ids = array();
			foreach ( $data['sources'] as $key => list( $kind, $source_title, $url ) ) {
				$source_ids[ $key ] = $insert(
					'sources',
					array(
						'board_id'   => $board_id,
						'source_key' => $key,
						'kind'       => $kind,
						'title'      => $source_title,
						'url'        => $url,
					)
				);
			}
			foreach ( $data['pins'] as $position => list( $label, $gpio, $pin_role, $note, $source ) ) {
				$insert(
					'pins',
					array(
						'board_id'  => $board_id,
						'connector' => 'CON1',
						'position'  => $position,
						'label'     => $label,
						'gpio'      => $gpio,
						'role'      => $pin_role,
						'note'      => $note,
						'source_id' => $source_ids[ $source ],
					)
				);
			}
			foreach ( $data['parameters'] as list( $parameter, $qualifier, $magnitude, $unit, $conditions, $source ) ) {
				$insert(
					'parameters',
					array(
						'board_id'   => $board_id,
						'parameter'  => $parameter,
						'qualifier'  => $qualifier,
						'magnitude'  => $magnitude,
						'unit'       => $unit,
						'conditions' => $conditions,
						'source_id'  => $source_ids[ $source ],
					)
				);
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit transaction.
				throw new RuntimeException( 'Could not commit the reviewed hardware data.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit transaction.
			throw $error;
		}
	}

	if ( 'draft' === $guide->post_status ) {
		$updated = wp_update_post(
			array(
				'ID'          => $guide->ID,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $updated ) || ! $updated ) {
			throw new RuntimeException( 'The guide could not be published.' );
		}
	}
	$reference = Digitalogic_Hardware_Reference::read( $slug );
	if ( count( $reference['pins'] ?? array() ) !== 20 || count( $reference['parameters'] ?? array() ) !== 4 ) {
		throw new RuntimeException( 'Published reference did not match the reviewed record counts.' );
	}
	WP_CLI::success( $reference['guide'] );
} finally {
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the provisioning lock.
}
