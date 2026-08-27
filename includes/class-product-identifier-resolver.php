<?php
/**
 * Shared exact product identifier resolver.
 *
 * Identifiers remain strings at the service boundary. Resolution is exact,
 * variation-aware, and deliberately never falls back to fuzzy/name matching.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Digitalogic_Product_Identifier_Resolver {

    public const PATRIS_CODE_META = '_digitalogic_patris_product_code';
    public const SKU_META = '_sku';

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Resolve the highest-precedence supplied identifier.
     *
     * Precedence is explicit WooCommerce ID, exact SKU, exact Patris Code,
     * then the collision-safe generic exact code adapter.
     *
     * @param array $identifiers String identifiers.
     * @return array|WP_Error
     */
    public function resolve($identifiers) {
        if (!is_array($identifiers)) {
            return $this->invalid('Product identifiers must be supplied as an object.');
        }

        if (array_key_exists('woocommerce_id', $identifiers) || array_key_exists('product_id', $identifiers)) {
            $value = array_key_exists('woocommerce_id', $identifiers)
                ? $identifiers['woocommerce_id']
                : $identifiers['product_id'];
            $identifier = $this->normalize_identifier($value, 'WooCommerce product ID', true);
            if (is_wp_error($identifier)) {
                return $identifier;
            }
            if (!$this->is_canonical_positive_integer($identifier)) {
                return $this->invalid('WooCommerce product ID must be a canonical positive integer string.');
            }

            return $this->resolve_woocommerce_id($identifier);
        }

        if (array_key_exists('sku', $identifiers)) {
            $sku = $this->normalize_identifier($identifiers['sku'], 'SKU');
            return is_wp_error($sku) ? $sku : $this->resolve_meta(self::SKU_META, 'sku', $sku);
        }

        if (array_key_exists('patris_code', $identifiers)) {
            $code = $this->normalize_identifier($identifiers['patris_code'], 'Patris Code');
            return is_wp_error($code) ? $code : $this->resolve_meta(self::PATRIS_CODE_META, 'patris_code', $code);
        }

        if (array_key_exists('code', $identifiers)) {
            $code = $this->normalize_identifier($identifiers['code'], 'Code/SKU');
            return is_wp_error($code) ? $code : $this->resolve_code($code);
        }

        return $this->invalid('A WooCommerce ID, SKU, Patris Code, or generic Code/SKU is required.');
    }

    /**
     * Resolve a generic Patris code without crossing identifier namespaces.
     *
     * Patris Code is canonical. SKU is only a compatibility fallback when no
     * exact Patris Code exists. If the same text names a Patris Code target and
     * a distinct SKU target, the identifier is ambiguous and no write is safe.
     */
    public function resolve_code($code) {
        $code = $this->normalize_identifier($code, 'Code/SKU');
        if (is_wp_error($code)) {
            return $code;
        }

        global $wpdb;
        $postmeta = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $current_sku = "COALESCE((SELECT pm_sku_match.meta_value FROM {$postmeta} pm_sku_match
            WHERE pm_sku_match.post_id = p.ID AND pm_sku_match.meta_key = '" . self::SKU_META . "'
            ORDER BY pm_sku_match.meta_id DESC LIMIT 1), '')";
        $current_patris = "COALESCE((SELECT pm_patris_match.meta_value FROM {$postmeta} pm_patris_match
            WHERE pm_patris_match.post_id = p.ID AND pm_patris_match.meta_key = '" . self::PATRIS_CODE_META . "'
            ORDER BY pm_patris_match.meta_id DESC LIMIT 1), '')";
		$rows           = $this->query_rows(
			'code',
			"(BINARY {$current_sku} = BINARY %s OR BINARY {$current_patris} = BINARY %s)",
			array( $code, $code )
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$sku_matches    = array();
		$patris_matches = array();
		foreach ( (array) $rows as $row ) {
			if ( (string) $row['sku'] === $code ) {
				$sku_matches[] = $row;
			}
			if ( (string) $row['patris_code'] === $code ) {
				$patris_matches[] = $row;
			}
		}

		if ( ! empty( $patris_matches ) ) {
			if ( count( $patris_matches ) > 1 ) {
				return $this->ambiguous( $patris_matches, 'patris_code', 'duplicate_patris_code' );
			}

			$patris_match         = reset( $patris_matches );
			$distinct_sku_matches = array_values(
				array_filter(
					$sku_matches,
					static function ( $row ) use ( $patris_match ) {
						return (string) $row['ID'] !== (string) $patris_match['ID'];
					}
				)
			);
			if ( ! empty( $distinct_sku_matches ) ) {
				return $this->ambiguous(
					array_merge( array( $patris_match ), $distinct_sku_matches ),
					'patris_code',
					'cross_namespace_collision'
				);
			}

			return $this->format_match( $patris_match, 'patris_code', $code );
		}
		if ( ! empty( $sku_matches ) ) {
			return $this->one_or_ambiguous( $sku_matches, 'sku_fallback', $code );
		}

		return $this->not_found( 'No product has that exact Code or SKU.' );
	}

	/**
	 * Resolve a bounded set of exact Patris Codes with one database query.
	 *
	 * Currency reconciliation used to execute the scalar correlated-subquery
	 * resolver once for every catalog row. On the production catalog that made
	 * identity lookup alone take roughly one hundred seconds. Fetching the
	 * current Patris identity projection once preserves the same exact and
	 * ambiguity-safe semantics without one query per product.
	 *
	 * @param array $codes Patris Codes as strings.
	 * @return array<string,array|WP_Error> Result keyed by the submitted code.
	 */
	public function resolve_patris_codes( $codes ) {
		if ( ! is_array( $codes ) ) {
			return array();
		}

		$normalized = array();
		$results    = array();
		foreach ( $codes as $code ) {
			$value = $this->normalize_identifier( $code, 'Patris Code' );
			if ( is_wp_error( $value ) ) {
				$results[ (string) $code ] = $value;
				continue;
			}
			$normalized[ $value ] = true;
		}
		if ( empty( $normalized ) ) {
			return $results;
		}

		$rows = $this->query_patris_rows_bulk();
		if ( is_wp_error( $rows ) ) {
			foreach ( array_keys( $normalized ) as $code ) {
				$results[ $code ] = $rows;
			}
			return $results;
		}

		$matches = array();
		foreach ( $rows as $row ) {
			$patris_code = isset( $row['patris_code'] ) ? (string) $row['patris_code'] : '';
			if ( '' === $patris_code || ! isset( $normalized[ $patris_code ] ) ) {
				continue;
			}
			$matches[ $patris_code ][] = $row;
		}

		foreach ( array_keys( $normalized ) as $code ) {
			$code_rows        = $matches[ $code ] ?? array();
			$results[ $code ] = empty( $code_rows )
				? $this->not_found( 'No product has that exact identifier.' )
				: $this->one_or_ambiguous( $code_rows, 'patris_code', $code );
		}

		return $results;
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag -- Legacy private resolver helpers predate the strict documentation ruleset.
	/** Resolve one exact WooCommerce object ID. */
	private function resolve_woocommerce_id( $woocommerce_id ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		$rows = $this->query_rows( 'woocommerce_id', 'p.ID = %d', array( (int) $woocommerce_id ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( empty( $rows ) ) {
			return $this->not_found( 'No product or variation has that exact WooCommerce ID.' );
		}

		return $this->format_match( reset( $rows ), 'woocommerce_id', $woocommerce_id );
	}

	/** Resolve one exact latest metadata value. */
	private function resolve_meta( $meta_key, $resolved_by, $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		global $wpdb;
		$postmeta      = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		$current_value = "COALESCE((SELECT pm_match.meta_value FROM {$postmeta} pm_match
            WHERE pm_match.post_id = p.ID AND pm_match.meta_key = %s
            ORDER BY pm_match.meta_id DESC LIMIT 1), '')";
        $rows = $this->query_rows(
            $resolved_by,
            "BINARY {$current_value} = BINARY %s",
            array($meta_key, $value)
        );
        if (is_wp_error($rows)) {
            return $rows;
        }

        // Defend exactness again in PHP in case a database collation or test
        // adapter returns a broader row set than the BINARY predicate.
        $column = self::SKU_META === $meta_key ? 'sku' : 'patris_code';
        $rows = array_values(array_filter((array) $rows, static function($row) use ($column, $value) {
            return isset($row[$column]) && (string) $row[$column] === $value;
        }));

        if (empty($rows)) {
            return $this->not_found('No product has that exact identifier.');
        }

        return $this->one_or_ambiguous($rows, $resolved_by, $value);
    }

    /**
     * Fetch one deterministic current SKU and Patris Code per candidate in a
     * single query. Latest meta_id wins when corrupted duplicate rows exist.
     */
    private function query_rows($marker, $predicate, $args) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
            return $this->query_failed();
        }
        $posts = isset($wpdb->posts) ? $wpdb->posts : $wpdb->prefix . 'posts';
        $postmeta = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $query = "/* digitalogic_identifier:{$marker} */
            SELECT p.ID, p.post_type,
                COALESCE((
                    SELECT pm_sku.meta_value FROM {$postmeta} pm_sku
                    WHERE pm_sku.post_id = p.ID AND pm_sku.meta_key = '" . self::SKU_META . "'
                    ORDER BY pm_sku.meta_id DESC LIMIT 1
                ), '') AS sku,
                COALESCE((
                    SELECT pm_patris.meta_value FROM {$postmeta} pm_patris
                    WHERE pm_patris.post_id = p.ID AND pm_patris.meta_key = '" . self::PATRIS_CODE_META . "'
                    ORDER BY pm_patris.meta_id DESC LIMIT 1
                ), '') AS patris_code
            FROM {$posts} p
            WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status NOT IN ('trash', 'auto-draft')
                AND {$predicate}
            ORDER BY p.ID ASC";

		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The predicate is internal-only and every external value remains placeholder-bound in $args.
			$prepared = $wpdb->prepare( $query, ...$args );
			if ( false === $prepared || null === $prepared || '' === $prepared ) {
				return $this->query_failed();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Exact identity resolution requires authoritative prepared readback.
			$rows = $wpdb->get_results( $prepared, ARRAY_A );
		} catch ( Throwable ) {
			return $this->query_failed();
		}

		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return $this->query_failed();
		}

		return $rows;
	}

	/**
	 * Fetch the latest exact Patris Code row for every Woo product in one pass.
	 *
	 * Duplicate metadata is deliberately retained until PHP selects the latest
	 * meta_id per product. Duplicate current values across products therefore
	 * remain an explicit ambiguous identity, matching the scalar resolver.
	 *
	 * @return array|WP_Error
	 */
	private function query_patris_rows_bulk() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $this->query_failed();
		}
		$posts    = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts';
		$postmeta = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		$query    = "/* digitalogic_identifier:patris_codes_bulk */
            SELECT p.ID, p.post_type, pm.meta_id, '' AS sku, pm.meta_value AS patris_code
            FROM {$postmeta} pm
            INNER JOIN {$posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type IN ('product', 'product_variation')
              AND p.post_status NOT IN ('trash', 'auto-draft')
            ORDER BY p.ID ASC, pm.meta_id ASC";
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are supplied by $wpdb and the metadata key remains placeholder-bound.
			$prepared = $wpdb->prepare( $query, self::PATRIS_CODE_META );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return $this->query_failed();
		}
		if ( ! is_string( $prepared ) && ! is_array( $prepared ) ) {
			return $this->query_failed();
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bulk exact identity resolution requires authoritative prepared readback.
			$rows = $wpdb->get_results( $prepared, ARRAY_A );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return $this->query_failed();
		}
		if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
			return $this->query_failed();
		}

		$current = array();
		foreach ( $rows as $row ) {
			$product_id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
			if ( $product_id <= 0 ) {
				continue;
			}
			$current[ $product_id ] = $row;
		}

		return array_values( $current );
	}

	/** Return the one exact row or an ambiguity error. */
	private function one_or_ambiguous( $rows, $resolved_by, $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		if ( count( $rows ) > 1 ) {
			return $this->ambiguous( $rows, $resolved_by, 'duplicate_identifier' );
		}

		return $this->format_match( reset( $rows ), $resolved_by, $value );
	}

	/** Build one exact-identity ambiguity error. */
	private function ambiguous( $rows, $resolved_by, $reason ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		$ids = array_values(
			array_unique(
				array_map(
					static function ( $row ) {
						return (string) $row['ID'];
					},
					$rows
				)
			)
		);
		sort( $ids, SORT_STRING );

		return new WP_Error(
			'digitalogic_product_identifier_ambiguous',
			__( 'More than one product has that exact identifier.', 'digitalogic' ),
			array(
				'status'          => 409,
				'resolved_by'     => $resolved_by,
				'reason'          => (string) $reason,
				'woocommerce_ids' => $ids,
			)
		);
	}

	/** Format one exact resolved identity. */
	private function format_match( $row, $resolved_by, $identifier ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		return array(
			'woocommerce_id' => (string) $row['ID'],
			'post_type'      => (string) $row['post_type'],
			'sku'            => isset( $row['sku'] ) ? (string) $row['sku'] : '',
			'patris_code'    => isset( $row['patris_code'] ) ? (string) $row['patris_code'] : '',
			'identifier'     => (string) $identifier,
			'resolved_by'    => (string) $resolved_by,
		);
	}

	/** Normalize one external identifier without broad matching. */
	private function normalize_identifier( $value, $label, $allow_integer = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		if ( ! is_string( $value ) && ! ( $allow_integer && is_int( $value ) ) ) {
			return $this->invalid( $label . ' must be a string.' );
		}

		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return $this->invalid( $label . ' is empty or invalid.' );
		}

		return $value;
	}

	/** Check one canonical positive integer string. */
	private function is_canonical_positive_integer( $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		if ( ! preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return false;
		}

		$maximum = (string) PHP_INT_MAX;
		return strlen( $value ) < strlen( $maximum )
			|| ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) <= 0 );
	}

	/** Build an invalid-identifier response. */
	private function invalid( $message ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		return new WP_Error(
			'digitalogic_invalid_product_identifier',
			(string) $message,
			array( 'status' => 400 )
		);
	}

	/** Build an exact-identifier not-found response. */
	private function not_found( $message ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		return new WP_Error(
			'digitalogic_product_identifier_not_found',
			(string) $message,
			array( 'status' => 404 )
		);
	}

	/** Build an authoritative-query failure response. */
	private function query_failed() { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingReturn -- Legacy private helper.
		return new WP_Error(
			'digitalogic_product_identifier_query_failed',
			__( 'The product identifier lookup could not be completed.', 'digitalogic' ),
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
}
