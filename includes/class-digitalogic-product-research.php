<?php
/**
 * Private, normalized product research and coverage ledger.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Private research service. */
final class Digitalogic_Product_Research {

	public const SCHEMA = '1';

	/** Table.
	 *
	 * @param string $name Name.
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_research_' . $name;
	}

	/** Register private administrator views. */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Explicit migration: never performs DDL during public page requests.
	 *
	 * @throws RuntimeException When a table cannot be created.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset      = $wpdb->get_charset_collate();
		$sources      = self::table( 'sources' );
		$listings     = self::table( 'listings' );
		$observations = self::table( 'observations' );
		$queue        = self::table( 'queue' );
		dbDelta(
			"CREATE TABLE $sources (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			domain varchar(253) NOT NULL,
			profile longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY domain (domain)
		) ENGINE=InnoDB $charset;"
		);
		dbDelta(
			"CREATE TABLE $listings (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint unsigned NOT NULL,
			source_id bigint unsigned NOT NULL,
			url_hash char(64) NOT NULL,
			url text NOT NULL,
			details longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_url (product_id,url_hash),
			KEY source_id (source_id)
		) ENGINE=InnoDB $charset;"
		);
		dbDelta(
			"CREATE TABLE $observations (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			listing_id bigint unsigned NOT NULL,
			observation_hash char(64) NOT NULL,
			price decimal(24,8) NULL,
			currency varchar(8) NULL,
			availability varchar(24) NOT NULL,
			observed_at datetime NOT NULL,
			evidence longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY observation_hash (observation_hash),
			KEY listing_time (listing_id,observed_at)
		) ENGINE=InnoDB $charset;"
		);
		dbDelta(
			"CREATE TABLE $queue (
			product_id bigint unsigned NOT NULL,
			fingerprint char(64) NOT NULL,
			state varchar(24) NOT NULL,
			lease char(36) NOT NULL DEFAULT '',
			lease_until datetime NULL,
			searched_at datetime NULL,
			report longtext NOT NULL,
			PRIMARY KEY  (product_id),
			KEY state (state)
		) ENGINE=InnoDB $charset;"
		);
		foreach ( array( $sources, $listings, $observations, $queue ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
				throw new RuntimeException( 'Research table migration failed.' );
			}
		}
		update_option( 'digitalogic_research_schema', self::SCHEMA, false );
	}

	/**
	 * Verify schema availability.
	 *
	 * @throws RuntimeException When the migration is missing.
	 */
	public static function ready() {
		if ( self::SCHEMA !== get_option( 'digitalogic_research_schema' ) ) {
			throw new RuntimeException( 'Run digitalogic research install first.' );
		}
	}

	/**
	 * Require administrator capability and access to the selected product.
	 *
	 * @param int $id Product ID or zero for global operations.
	 * @throws RuntimeException When access is forbidden.
	 */
	public static function authorize( $id = 0 ) {
		if ( ! current_user_can( 'manage_options' ) || ( $id && ! current_user_can( 'edit_post', $id ) ) ) {
			throw new RuntimeException( 'Administrator access required.' );
		}
	}

	/**
	 * Fingerprint an exact parent identity without changing its SKU.
	 *
	 * @param int $id Parent ID.
	 * @throws RuntimeException When the selected post is not a product.
	 */
	public static function identity( $id ) {
		if ( 'product' !== get_post_type( $id ) ) {
			throw new RuntimeException( 'Exact parent product required.' );
		}
		$identity = array( get_the_title( $id ), get_post_meta( $id, '_sku', true ) );
		$product  = wc_get_product( $id );
		if ( $product && $product->is_type( 'variable' ) ) {
			$identity[] = self::variants( $product );
		}
		return hash( 'sha256', wp_json_encode( $identity ) );
	}

	/**
	 * Bind research identity to current exact child codes and attributes.
	 *
	 * @param WC_Product $product Parent product.
	 * @return array Exact child identities.
	 */
	private static function variants( $product ) {
		$children = $product->get_children();
		sort( $children, SORT_NUMERIC );
		$result = array();
		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child ) {
				$result[] = array(
					'id'         => $child->get_id(),
					'sku'        => (string) get_post_meta( $child_id, '_sku', true ),
					'attributes' => $child->get_attributes( 'edit' ),
				);
			}
		}
		return $result;
	}

	/** Serialize claims and writes across CLI workers.
	 *
	 * @param callable $callback Callback.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 * @throws Throwable Re-throws a failed operation after rollback.
	 */
	public static function locked( $callback ) {
		global $wpdb;
		self::ready();
		$key = 'dgl-research-' . substr( hash( 'sha256', self::table( 'queue' ) ), 0, 32 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $key ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
			throw new RuntimeException( 'Another research writer is active.' );
		}
		try {
			self::sql( 'START TRANSACTION' );
			$result = $callback();
			self::sql( 'COMMIT' );
			return $result;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
			throw $error;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		}
	}

	/** Sql.
	 *
	 * @param string $query Query.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 */
	private static function sql( $query ) {
		global $wpdb;
		if ( false === $wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
			throw new RuntimeException( 'Research database operation failed.' );
		}
	}

	/** Claim unseen products first; expired claims and blocked searches can retry.
	 *
	 * @param int $limit Limit.
	 */
	public static function batch( $limit ) {
		global $wpdb;
		$limit = max( 1, min( 10, (int) $limit ) );
		return self::locked(
			static function () use ( $wpdb, $limit ) {
				$table = self::table( 'queue' );
				$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
				foreach ( $ids as $id ) {
					$fingerprint = self::identity( $id );
					$row         = $wpdb->get_row( $wpdb->prepare( "SELECT fingerprint FROM $table WHERE product_id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
					if ( ! $row || $row['fingerprint'] !== $fingerprint ) {
						if ( false === $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
							$table,
							array(
								'product_id'  => $id,
								'fingerprint' => $fingerprint,
								'state'       => 'unseen',
								'report'      => '{}',
							)
						) ) {
							throw new RuntimeException( 'Queue synchronization failed.' );
						}
					}
				}
				$now      = gmdate( 'Y-m-d H:i:s' );
				$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT q.* FROM $table q JOIN {$wpdb->posts} p ON p.ID=q.product_id WHERE p.post_status='publish' AND p.post_type='product' AND (q.state='unseen' OR (q.state IN ('leased','blocked') AND q.lease_until < %s)) ORDER BY (q.state='unseen') DESC,q.product_id LIMIT %d", $now, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
				$lease    = wp_generate_uuid4();
				$products = array();
				foreach ( $rows as $row ) {
					$id = (int) $row['product_id'];
					self::sql( $wpdb->prepare( "UPDATE $table SET state='leased',lease=%s,lease_until=%s WHERE product_id=%d", $lease, gmdate( 'Y-m-d H:i:s', time() + 3300 ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are fixed and values are prepared.
					$p          = wc_get_product( $id );
					$products[] = array(
						'id'          => $id,
						'title'       => $p->get_name(),
						'sku'         => $p->get_sku(),
						'fingerprint' => $row['fingerprint'],
						'url'         => get_permalink( $id ),
						'type'        => $p->get_type(),
						'variations'  => $p->is_type( 'variable' ) ? self::variants( $p ) : array(),
						'description' => mb_substr( wp_strip_all_tags( $p->get_description() ), 0, 6000 ),
					);
				}
				return array(
					'lease'    => $lease,
					'products' => $products,
				);
			}
		);
	}

	/** Url.
	 *
	 * @param string $value Value.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 */
	public static function url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 4096 ) {
			throw new RuntimeException( 'Invalid source URL.' );
		}
		$parts = wp_parse_url( trim( $value ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			throw new RuntimeException( 'Public HTTP(S) source URL required.' );
		}
		$host = strtolower( rtrim( $parts['host'], '.' ) );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii_host = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host       = $ascii_host ? $ascii_host : $host;
		}
		if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) || strlen( $host ) > 253 || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			throw new RuntimeException( 'Public domain required.' );
		}
		$query = array();
		parse_str( $parts['query'] ?? '', $query );
		foreach ( array_keys( $query ) as $key ) {
			if ( preg_match( '/^(utm_|gclid$|fbclid$|srsltid$)/i', $key ) ) {
				unset( $query[ $key ] );
			}
		}
		ksort( $query );
		return strtolower( $parts['scheme'] ) . '://' . $host . ( $parts['path'] ?? '/' ) . ( $query ? '?' . http_build_query( $query ) : '' );
	}

	/** Domain.
	 *
	 * @param string $url Url.
	 */
	public static function domain( $url ) {
		return preg_replace( '/^www\./', '', strtolower( wp_parse_url( self::url( $url ), PHP_URL_HOST ) ) );
	}

	/** Text.
	 *
	 * @param string $value Value.
	 * @param int    $limit Limit.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 */
	private static function text( $value, $limit = 4000 ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new RuntimeException( 'Text field must be scalar.' );
		}
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, $limit );
	}

	/** Timestamp.
	 *
	 * @param string $value Value.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 */
	private static function timestamp( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value || $date->getTimestamp() > time() + 300 ) {
			throw new RuntimeException( 'Valid non-future UTC observation time required.' );
		}
		return $value;
	}

	/** One product per transaction; no-results and blocked are explicit outcomes.
	 *
	 * @param array $input Input.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 */
	public static function record( $input ) {
		global $wpdb;
		$id = absint( $input['product_id'] ?? 0 );
		self::authorize( $id );
		return self::locked(
			static function () use ( $wpdb, $id, $input ) {
				$q   = self::table( 'queue' );
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $q WHERE product_id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
				if ( ! $row || 'leased' !== $row['state'] || ! hash_equals( $row['lease'], (string) ( $input['lease'] ?? '' ) ) || gmdate( 'Y-m-d H:i:s' ) > $row['lease_until'] || self::identity( $id ) !== $row['fingerprint'] || ( $input['fingerprint'] ?? '' ) !== $row['fingerprint'] ) {
					throw new RuntimeException( 'Claim expired or product identity changed; obtain a fresh batch.' );
				}
				$state = $input['outcome'] ?? 'searched';
				if ( ! in_array( $state, array( 'searched', 'no_results', 'blocked' ), true ) ) {
					throw new RuntimeException( 'Invalid research outcome.' );
				}
				$searches = $input['searches'] ?? array();
				if ( ! is_array( $searches ) || ! count( $searches ) || count( $searches ) > 20 ) {
					throw new RuntimeException( 'Search provenance is required.' );
				}
				foreach ( $searches as &$search ) {
					if ( ! in_array( $search['engine'] ?? '', array( 'google', 'bing' ), true ) ) {
						throw new RuntimeException( 'Google or Bing provenance required.' );
					}
					$search = array(
						'engine'      => $search['engine'],
						'query'       => self::text( $search['query'] ?? '', 500 ),
						'url'         => self::url( $search['url'] ?? '' ),
						'observed_at' => self::timestamp( $search['observed_at'] ?? '' ),
					);
					$host   = self::domain( $search['url'] );
					if ( ! preg_match( '/^(?:[a-z]+\.)?(google\.[a-z.]+|bing\.com)$/', $host ) || '' === $search['query'] ) {
						throw new RuntimeException( 'Search engine URL and query required.' );
					}
				}
				unset( $search );
				$results = $input['results'] ?? array();
				if ( ! is_array( $results ) || count( $results ) > 50 || ( 'no_results' === $state && $results ) ) {
					throw new RuntimeException( 'Invalid result collection.' );
				}
				$listing_ids = array();
				foreach ( $results as $result ) {
					$listing_ids[] = self::save_result( $id, $result );
				}
				$previous_report = json_decode( $row['report'], true );
				$report          = array_merge(
					is_array( $previous_report ) ? $previous_report : array(),
					array(
						'searches'    => $searches,
						'summary_fa'  => self::text( $input['summary_fa'] ?? '' ),
						'issues_fa'   => self::text( $input['issues_fa'] ?? '' ),
						'listing_ids' => array_values( array_unique( $listing_ids ) ),
					)
				);
				self::sql( $wpdb->prepare( "UPDATE $q SET state=%s,searched_at=%s,lease_until=%s,report=%s WHERE product_id=%d", $state, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + 86400 ), wp_json_encode( $report, JSON_UNESCAPED_UNICODE ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are fixed and values are prepared.
				return array(
					'product_id' => $id,
					'outcome'    => $state,
					'results'    => count( $listing_ids ),
				);
			}
		);
	}

	/** Save result.
	 *
	 * @param int   $id Id.
	 * @param array $r R.
	 * @throws RuntimeException On invalid data, access, or failed persistence.
	 */
	private static function save_result( $id, $r ) {
		global $wpdb;
		$variation_id = absint( $r['variation_id'] ?? 0 );
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $id !== (int) $variation->get_parent_id() ) {
				throw new RuntimeException( 'External variant must belong to the exact selected parent.' );
			}
		}
		$url      = self::url( $r['url'] ?? '' );
		$url_hash = hash( 'sha256', $url . ( $variation_id ? "\0" . $variation_id : '' ) );
		$domain   = self::domain( $url );
		$s        = self::table( 'sources' );
		$l        = self::table( 'listings' );
		$o        = self::table( 'observations' );
		$old      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $s WHERE domain=%s", $domain ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		$profile  = $old ? json_decode( $old['profile'], true ) : array();
		foreach ( array( 'title', 'about_fa', 'address_fa', 'country', 'storefront_url', 'about_url', 'icon_url' ) as $field ) {
			if ( isset( $r['host'][ $field ] ) && '' !== $r['host'][ $field ] ) {
				$profile[ $field ] = str_ends_with( $field, '_url' ) ? self::url( $r['host'][ $field ] ) : self::text( $r['host'][ $field ], 2000 );
			}
		}
		$profile['title']            = $profile['title'] ?? $domain;
		$profile['last_verified_at'] = self::timestamp( $r['observed_at'] ?? '' );
		$profile['storefront_url']   = $profile['storefront_url'] ?? 'https://' . $domain . '/';
		$now                         = gmdate( 'Y-m-d H:i:s' );
		self::sql( $wpdb->prepare( "INSERT INTO $s (domain,profile,updated_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE profile=VALUES(profile),updated_at=VALUES(updated_at)", $domain, wp_json_encode( $profile, JSON_UNESCAPED_UNICODE ), $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are fixed and values are prepared.
		$source_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $s WHERE domain=%s", $domain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		$kind      = $r['kind'] ?? 'seller';
		$match     = $r['match'] ?? 'candidate';
		if ( ! in_array( $kind, array( 'seller', 'manufacturer', 'reference', 'aggregator' ), true ) || ! in_array( $match, array( 'candidate', 'exact_model', 'different_variant', 'reference_only' ), true ) ) {
			throw new RuntimeException( 'Invalid relationship or identity confidence.' );
		}
		$details = array(
			'kind'                => $kind,
			'match'               => $match,
			'product_fingerprint' => self::identity( $id ),
			'variation_id'        => $variation_id,
		);
		foreach ( array( 'title', 'seller', 'seller_sku', 'identity_evidence_fa', 'summary_fa', 'specifications_fa', 'description_fa', 'comparison_fa', 'availability_note_fa', 'price_note_fa' ) as $field ) {
			$details[ $field ] = self::text( $r[ $field ] ?? '' );
		}
		if ( 'exact_model' === $match && '' === $details['identity_evidence_fa'] ) {
			throw new RuntimeException( 'Exact-model claims need explicit identity evidence.' );
		}
		$details['attachments'] = array();
		foreach ( array_slice( $r['attachments'] ?? array(), 0, 20 ) as $a ) {
			$details['attachments'][] = array(
				'title_fa' => self::text( $a['title_fa'] ?? '', 240 ),
				'url'      => self::url( $a['url'] ?? '' ),
			);
		}
		$availability = $r['availability'] ?? 'unknown';
		if ( ! in_array( $availability, array( 'unknown', 'in_stock', 'out_of_stock', 'preorder', 'discontinued' ), true ) ) {
			throw new RuntimeException( 'Invalid availability.' );
		}
		list( $price, $currency ) = self::price( $r['price'] ?? null, $r['currency'] ?? null );
		$at                       = self::timestamp( $r['observed_at'] ?? '' );
		self::sql( $wpdb->prepare( "INSERT INTO $l (product_id,source_id,url_hash,url,details,updated_at) VALUES (%d,%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id),details=VALUES(details),updated_at=VALUES(updated_at)", $id, $source_id, $url_hash, $url, wp_json_encode( $details, JSON_UNESCAPED_UNICODE ), $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers are fixed and values are prepared.
		$listing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $l WHERE product_id=%d AND url_hash=%s", $id, $url_hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		$evidence   = wp_json_encode(
			array(
				'url'     => $url,
				'note_fa' => self::text( $r['evidence_fa'] ?? '' ),
			),
			JSON_UNESCAPED_UNICODE
		);
		$hash       = hash( 'sha256', wp_json_encode( array( $listing_id, $at, $price, $currency, $availability, $evidence ) ) );
		$exists     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $o WHERE observation_hash=%s", $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		if ( ! $exists && false === $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
			$o,
			array(
				'listing_id'       => $listing_id,
				'observation_hash' => $hash,
				'price'            => $price,
				'currency'         => $currency,
				'availability'     => $availability,
				'observed_at'      => $at,
				'evidence'         => $evidence,
			)
		) ) {
			throw new RuntimeException( 'Observation storage failed.' );
		}
		return (int) $listing_id;
	}

	/** Validate an observed offer without converting currencies.
	 *
	 * @param mixed $price Decimal price or null.
	 * @param mixed $currency Explicit currency code.
	 * @return array
	 * @throws RuntimeException On malformed or unsupported values.
	 */
	private static function price( $price, $currency ) {
		if ( null === $price ) {
			return array( null, null );
		}
		if ( ! is_scalar( $price ) || ! preg_match( '/^\d{1,16}(\.\d{1,8})?$/', (string) $price ) || ! in_array( $currency, array( 'IRR', 'IRT', 'USD', 'EUR', 'GBP', 'CNY', 'AED', 'CAD', 'AUD', 'JPY', 'INR', 'TRY', 'PLN' ), true ) ) {
			throw new RuntimeException( 'Nonnegative decimal price and explicit currency required; IRT means toman.' );
		}
		return array( $price, $currency );
	}

	/** Product.
	 *
	 * @param int $id Id.
	 */
	public static function product( $id ) {
		global $wpdb;
		self::ready();
		self::authorize( $id );
		$l    = self::table( 'listings' );
		$s    = self::table( 'sources' );
		$o    = self::table( 'observations' );
		$q    = self::table( 'queue' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.*,s.domain,s.profile,o.price,o.currency,o.availability,o.observed_at FROM $l l JOIN $s s ON s.id=l.source_id LEFT JOIN $o o ON o.id=(SELECT id FROM $o WHERE listing_id=l.id ORDER BY observed_at DESC,id DESC LIMIT 1) WHERE l.product_id=%d ORDER BY l.id", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		foreach ( $rows as &$row ) {
			$row['details']          = json_decode( $row['details'], true );
			$row['profile']          = json_decode( $row['profile'], true );
			$row['identity_current'] = ( $row['details']['product_fingerprint'] ?? '' ) === self::identity( $id );
		}
		unset( $row );
		$coverage = $wpdb->get_row( $wpdb->prepare( "SELECT state,searched_at,report FROM $q WHERE product_id=%d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		if ( $coverage ) {
			$coverage['report'] = json_decode( $coverage['report'], true );
		}
		return array(
			'coverage' => $coverage,
			'listings' => $rows,
		);
	}

	/** Return coverage and database counts for administrators. */
	public static function status() {
		global $wpdb;
		self::ready();
		self::authorize();
		$q      = self::table( 'queue' );
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		$counts = $wpdb->get_results( "SELECT q.state,COUNT(*) AS count FROM $q q JOIN {$wpdb->posts} p ON p.ID=q.product_id WHERE p.post_status='publish' AND p.post_type='product' GROUP BY q.state", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		$states = array_fill_keys( array( 'unseen', 'leased', 'searched', 'no_results', 'blocked' ), 0 );
		foreach ( $counts as $row ) {
			$states[ $row['state'] ] = (int) $row['count'];
		}
		$states['unseen'] += max( 0, $total - array_sum( $states ) );
		return array(
			'published_products' => $total,
			'states'             => $states,
			'sources'            => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'sources' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
			'listings'           => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'listings' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
			'observations'       => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'observations' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		);
	}

	/**
	 * Import a bounded legacy page without marking products as researched.
	 *
	 * @param int $after Last parent ID processed.
	 * @return array Cursor and import count.
	 */
	public static function import_legacy( $after = 0 ) {
		self::authorize();
		return self::locked(
			static function () use ( $after ) {
				global $wpdb;
				$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type='product' AND m.meta_key=%s AND p.ID>%d ORDER BY p.ID LIMIT 10", Digitalogic_Product_Supplier_Links::META_KEY, $after ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded explicit migration.
				$count = 0;
				foreach ( $ids as $id ) {
					$links = Digitalogic_Product_Supplier_Links::instance()->get_links( $id );
					foreach ( is_array( $links ) ? $links : array() as $link ) {
						$url     = self::url( $link['url'] );
						$domain  = self::domain( $url );
						$s       = self::table( 'sources' );
						$l       = self::table( 'listings' );
						$profile = array(
							'title'          => $link['site_name'] ?? $domain,
							'storefront_url' => 'https://' . $domain . '/',
							'provenance'     => 'legacy_unverified',
						);
						$now     = gmdate( 'Y-m-d H:i:s' );
						self::sql( $wpdb->prepare( "INSERT IGNORE INTO $s (domain,profile,updated_at) VALUES (%s,%s,%s)", $domain, wp_json_encode( $profile ), $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table.
						$source_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $s WHERE domain=%s", $domain ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Fixed table; prepared values.
						$details   = array_fill_keys( array( 'title', 'seller', 'seller_sku', 'identity_evidence_fa', 'summary_fa', 'specifications_fa', 'description_fa', 'comparison_fa', 'availability_note_fa', 'price_note_fa' ), '' );
						$details   = array_merge(
							$details,
							array(
								'title'               => $link['source_title'] ?? '',
								'kind'                => 'seller',
								'match'               => 'candidate',
								'product_fingerprint' => self::identity( $id ),
								'summary_fa'          => 'لینک خصوصی قبلی؛ جست‌وجوی جدید و قیمت‌خوانی انجام نشده است.',
								'attachments'         => array(),
								'legacy'              => $link,
							)
						);
						self::sql( $wpdb->prepare( "INSERT IGNORE INTO $l (product_id,source_id,url_hash,url,details,updated_at) VALUES (%d,%d,%s,%s,%s,%s)", $id, $source_id, hash( 'sha256', $url ), $url, wp_json_encode( $details, JSON_UNESCAPED_UNICODE ), $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table.
						++$count;
					}
				}
				return array(
					'last_id'         => $ids ? (int) end( $ids ) : (int) $after,
					'products'        => count( $ids ),
					'links_processed' => $count,
				);
			}
		);
	}

	/** Bounded safe fetch: returns extraction candidates, never changes commercial data.
	 *
	 * @param string $url Url.
	 */
	public static function inspect( $url ) {
		self::authorize();
		$url      = self::url( $url );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 2,
				'limit_response_size' => 524288,
				'cookies'             => array(),
				'user-agent'          => 'DigitalogicProductResearch/1.0 (+https://digitalogic.ir/)',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'state'       => 'unavailable',
				'observed_at' => gmdate( 'Y-m-d H:i:s' ),
			);
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || ! str_contains( strtolower( wp_remote_retrieve_header( $response, 'content-type' ) ), 'text/html' ) ) {
			return array(
				'state'       => 'unavailable',
				'http_status' => $code,
				'observed_at' => gmdate( 'Y-m-d H:i:s' ),
			);
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . wp_remote_retrieve_body( $response ), LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath  = new DOMXPath( $dom );
		$jsonld = array();
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) as $node ) {
			$data = json_decode( $node->textContent, true, 32 ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API property.
			if ( is_array( $data ) && count( $jsonld ) < 10 ) {
				$jsonld[] = $data;
			}
		}
		return array(
			'state'           => 'extracted_candidates',
			'url'             => $url,
			'observed_at'     => gmdate( 'Y-m-d H:i:s' ),
			'title'           => self::text( $xpath->evaluate( 'string(//title)' ), 240 ),
			'description'     => self::text( $xpath->evaluate( 'string(//meta[@name="description"]/@content)' ), 2000 ),
			'jsonld'          => $jsonld,
			'review_required' => true,
		);
	}

	/** Label.
	 *
	 * @param string $value Value.
	 */
	private static function label( $value ) {
		$labels = array(
			'seller'            => 'فروشنده',
			'manufacturer'      => 'سازندهٔ رسمی مدل مرجع',
			'reference'         => 'مرجع فنی',
			'aggregator'        => 'مقایسه‌گر قیمت',
			'candidate'         => 'نیازمند تطبیق',
			'exact_model'       => 'مدل تطبیق‌شده',
			'different_variant' => 'نسخهٔ متفاوت',
			'reference_only'    => 'فقط مرجع؛ اصالت موجودی تأیید نشده',
			'unknown'           => 'نامشخص',
			'in_stock'          => 'موجود',
			'out_of_stock'      => 'ناموجود',
			'preorder'          => 'پیش‌سفارش',
			'discontinued'      => 'توقف تولید',
			'searched'          => 'جست‌وجوشده',
			'no_results'        => 'جست‌وجوشده؛ بدون نتیجه',
			'blocked'           => 'نیازمند پیگیری',
			'unseen'            => 'بررسی‌نشده',
			'leased'            => 'در حال بررسی',
		);
		return $labels[ $value ] ?? $value;
	}

	/** Show administrators a notice for research updates they have not viewed. */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || self::SCHEMA !== get_option( 'digitalogic_research_schema' ) ) {
			return;
		}
		global $wpdb;
		$q      = self::table( 'queue' );
		$latest = $wpdb->get_var( "SELECT MAX(searched_at) FROM $q" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Fixed private table; fresh notification state.
		$seen   = get_user_meta( get_current_user_id(), '_digitalogic_research_seen_at', true );
		if ( $latest && $latest > $seen ) {
			echo '<div class="notice notice-info" dir="rtl"><p>پژوهش محصولات به‌روزرسانی شده است. <a href="' . esc_url( admin_url( 'admin.php?page=digitalogic-product-research' ) ) . '">مشاهده مقایسه‌ها و موارد نیازمند پیگیری</a></p></div>';
		}
	}

	/** Register the WooCommerce research dashboard. */
	public static function menu() {
		add_submenu_page( 'woocommerce', 'پژوهش خصوصی محصولات', 'پژوهش محصولات', 'manage_options', 'digitalogic-product-research', array( __CLASS__, 'dashboard' ) );
	}

	/** Render private coverage and actionable findings. */
	public static function dashboard() {
		self::authorize();
		echo '<div class="wrap" dir="rtl"><h1>پژوهش خصوصی محصولات</h1>';
		if ( self::SCHEMA !== get_option( 'digitalogic_research_schema' ) ) {
			echo '<p>ساختار پژوهش هنوز راه‌اندازی نشده است.</p></div>';
			return;
		}
		$status = self::status();
		global $wpdb;
		$q      = self::table( 'queue' );
		$latest = $wpdb->get_var( "SELECT MAX(searched_at) FROM $q" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Fixed private notification state.
		if ( $latest ) {
			update_user_meta( get_current_user_id(), '_digitalogic_research_seen_at', $latest );
		}
		echo '<p>محصولات منتشرشده: ' . esc_html( $status['published_products'] ) . ' | بررسی‌نشده: ' . esc_html( $status['states']['unseen'] ) . ' | جست‌وجوشده: ' . esc_html( $status['states']['searched'] + $status['states']['no_results'] ) . ' | نیازمند پیگیری: ' . esc_html( $status['states']['blocked'] ) . '</p>';
		echo '<p>قیمت‌ها مشاهدات زمان‌دار هستند؛ تومان و ریال جدا ذخیره می‌شوند. منابع مشابه، اصالت موجودی دیجیتالاجیک را اثبات نمی‌کنند.</p>';
		global $wpdb;
		$q    = self::table( 'queue' );
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.product_id,q.state,q.searched_at,q.report FROM $q q JOIN {$wpdb->posts} p ON p.ID=q.product_id WHERE p.post_type='product' AND p.post_status='publish' AND q.searched_at IS NOT NULL ORDER BY q.searched_at DESC,q.product_id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- Private uncached ledger; fixed table identifiers and prepared values.
		echo '<table class="widefat striped"><thead><tr><th>محصول</th><th>وضعیت و زمان UTC</th><th>جمع‌بندی مقایسه</th><th>اطلاع به مدیران</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$r = json_decode( $row['report'], true );
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( $row['product_id'] ) ) . '">' . esc_html( get_the_title( $row['product_id'] ) ) . '</a></td><td>' . esc_html( self::label( $row['state'] ) . ' / ' . $row['searched_at'] ) . '</td><td>' . esc_html( $r['summary_fa'] ?? '' ) . '</td><td>' . esc_html( $r['issues_fa'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table><p><a href="' . esc_url( add_query_arg( 'paged', $page + 1 ) ) . '">صفحه بعد</a></p></div>';
	}

	/** Render inside the existing administrator-only supplier metabox.
	 *
	 * @param int $id Id.
	 */
	public static function render( $id ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) || self::SCHEMA !== get_option( 'digitalogic_research_schema' ) ) {
			return;
		}
		$data = self::product( $id );
		echo '<div dir="rtl" style="overflow:auto"><h3>جدول پژوهش فروشندگان و سازندگان</h3><p>زمان‌ها UTC؛ اطلاعات فقط برای مدیران سایت است.</p>';
		if ( $data['coverage'] ) {
			echo '<p>' . esc_html( $data['coverage']['report']['summary_fa'] ?? '' ) . '</p><p><strong>' . esc_html( $data['coverage']['report']['issues_fa'] ?? '' ) . '</strong></p>';
		}
		echo '<table class="widefat striped"><thead><tr><th>منبع و فروشگاه</th><th>مدل و نوع منبع</th><th>قیمت و موجودی</th><th>مشخصات و محتوا</th><th>مقایسه و پیوست‌ها</th></tr></thead><tbody>';
		foreach ( $data['listings'] as $row ) {
			$d = $row['details'];
			$p = $row['profile'];
			echo '<tr><td><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $row['url'] ) . '">' . esc_html( $p['title'] ) . '</a><br>' . esc_html( $row['domain'] ) . '<br>' . esc_html( $p['about_fa'] ?? '' ) . '<br>' . esc_html( $p['address_fa'] ?? '' ) . '<br><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $p['storefront_url'] ) . '">صفحه فروشگاه</a></td><td>' . esc_html( $d['title'] . ' / ' . self::label( $d['kind'] ) . ' / ' . self::label( $d['match'] ) ) . '<br>' . esc_html( $d['identity_evidence_fa'] ) . ( $row['identity_current'] ? '' : '<br><strong>هویت محصول تغییر کرده؛ بررسی دوباره لازم است.</strong>' ) . '</td><td>' . esc_html( null === $row['price'] ? 'قیمت نامشخص' : $row['price'] . ' ' . ( 'IRT' === $row['currency'] ? 'تومان' : ( 'IRR' === $row['currency'] ? 'ریال' : $row['currency'] ) ) ) . '<br>' . esc_html( self::label( $row['availability'] ) . ' / ' . $d['availability_note_fa'] ) . '<br>' . esc_html( $d['price_note_fa'] . ' / ' . $row['observed_at'] ) . '</td><td>' . esc_html( $d['summary_fa'] . ' ' . $d['specifications_fa'] . ' ' . $d['description_fa'] ) . '</td><td>' . esc_html( $d['comparison_fa'] );
			foreach ( $d['attachments'] as $attachment ) {
				echo '<br><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $attachment['url'] ) . '">' . esc_html( $attachment['title_fa'] ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
