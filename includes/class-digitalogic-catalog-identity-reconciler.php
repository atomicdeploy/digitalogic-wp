<?php
/**
 * Canonical catalog identity diagnostics.
 *
 * The reconciler consumes only the provider-neutral report projection. It
 * never writes metadata and never guesses an alias match. Potential joins
 * outside the exact Product Code contract are quarantined so refresh callers
 * can fail closed with stable, actionable diagnostics.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Detect unsafe cross-provider catalog identity candidates. */
final class Digitalogic_Catalog_Identity_Reconciler {

	/**
	 * Shared stateless instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Return the shared stateless reconciler. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Annotate unsafe source-only/Woo-only identity components.
	 *
	 * Precedence is deterministic: normalized Product Code, exact SKU,
	 * normalized SKU, exact name, then normalized name. Every such edge remains
	 * quarantined; only the existing exact Product Code join is authoritative.
	 *
	 * @param array $rows Provider-neutral report rows.
	 * @return array{rows:array,counts:array,integrity_warnings:array}
	 */
	public function annotate_rows( array $rows ): array {
		$source_indexes = array();
		$woo_indexes    = array();
		$identities     = array();
		foreach ( $rows as $index => $row ) {
			$status = is_array( $row ) ? (string) ( $row['status'] ?? '' ) : '';
			if ( 'source_only' === $status && is_array( $row['source'] ?? null ) ) {
				$source_indexes[]     = $index;
				$identities[ $index ] = $this->source_identity( $row );
			} elseif ( 'woocommerce_only' === $status && is_array( $row['woocommerce'] ?? null ) ) {
				$woo_indexes[]        = $index;
				$identities[ $index ] = $this->woo_identity( $row );
			}
		}

		$edges = array();
		foreach ( $source_indexes as $source_index ) {
			$ranked = array();
			foreach ( $woo_indexes as $woo_index ) {
				$candidate = $this->candidate_reason( $identities[ $source_index ], $identities[ $woo_index ] );
				if ( null !== $candidate ) {
					$ranked[] = array(
						'source' => $source_index,
						'woo'    => $woo_index,
						'rank'   => $candidate['rank'],
						'reason' => $candidate['reason'],
					);
				}
			}
			if ( empty( $ranked ) ) {
				continue;
			}
			$best_rank = min( array_column( $ranked, 'rank' ) );
			foreach ( $ranked as $edge ) {
				if ( $best_rank === $edge['rank'] ) {
					$edges[] = $edge;
				}
			}
		}

		$components = $this->components( $edges );
		$warnings   = array();
		$counts     = array(
			'quarantined_identity_groups' => 0,
			'quarantined_source_rows'     => 0,
			'quarantined_woo_rows'        => 0,
			'one_to_one_split_candidates' => 0,
			'identity_collision_groups'   => 0,
		);

		foreach ( $components as $component ) {
			$source_rows = array_values( array_unique( $component['source'] ) );
			$woo_rows    = array_values( array_unique( $component['woo'] ) );
			$reasons     = array_values( array_unique( $component['reasons'] ) );
			sort( $source_rows, SORT_NUMERIC );
			sort( $woo_rows, SORT_NUMERIC );
			sort( $reasons, SORT_STRING );

			$source_codes   = array_values(
				array_unique(
					array_map(
						static fn( $index ) => (string) ( $rows[ $index ]['source']['product_code'] ?? '' ),
						$source_rows
					)
				)
			);
			$woo_ids        = array_values(
				array_unique(
					array_map(
						static fn( $index ) => (int) ( $rows[ $index ]['woocommerce']['id'] ?? 0 ),
						$woo_rows
					)
				)
			);
			$woo_parent_ids = array_values(
				array_unique(
					array_map(
						static fn( $index ) => $identities[ $index ]->parent_id,
						$woo_rows
					)
				)
			);
			$woo_types      = array_values(
				array_unique(
					array_map(
						static fn( $index ) => $identities[ $index ]->type,
						$woo_rows
					)
				)
			);
			sort( $source_codes, SORT_STRING );
			sort( $woo_ids, SORT_NUMERIC );
			sort( $woo_parent_ids, SORT_NUMERIC );
			sort( $woo_types, SORT_STRING );

			$quarantine_id = 'sha256:' . hash(
				'sha256',
				wp_json_encode(
					array(
						'source_product_codes'   => $source_codes,
						'woocommerce_ids'        => $woo_ids,
						'woocommerce_parent_ids' => $woo_parent_ids,
						'woocommerce_types'      => $woo_types,
						'candidate_reasons'      => $reasons,
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);
			$diagnostic    = array(
				'status'                 => 'quarantined',
				'quarantine_id'          => $quarantine_id,
				'candidate_reasons'      => $reasons,
				'source_product_codes'   => $source_codes,
				'woocommerce_ids'        => $woo_ids,
				'woocommerce_parent_ids' => $woo_parent_ids,
				'woocommerce_types'      => $woo_types,
				'safe_auto_match'        => false,
				'remediation'            => 'Assign the authoritative Product Code to exactly one WooCommerce product or variation, or confirm the records are separate products.',
			);

			foreach ( array_merge( $source_rows, $woo_rows ) as $row_index ) {
				$rows[ $row_index ]['identity_resolution'] = $diagnostic;
				$this->add_issue( $rows[ $row_index ], 'identity_quarantined' );
				$this->add_issue( $rows[ $row_index ], 'split_identity_candidate' );
				if ( count( $source_rows ) > 1 || count( $woo_rows ) > 1 ) {
					$this->add_issue( $rows[ $row_index ], 'normalized_identity_collision' );
				}
			}

			++$counts['quarantined_identity_groups'];
			$counts['quarantined_source_rows'] += count( $source_rows );
			$counts['quarantined_woo_rows']    += count( $woo_rows );
			if ( 1 === count( $source_rows ) && 1 === count( $woo_rows ) ) {
				++$counts['one_to_one_split_candidates'];
			} else {
				++$counts['identity_collision_groups'];
			}
			$warnings[] = array(
				'code'                   => 'projection_integrity_identity_quarantine',
				'severity'               => 'critical',
				'quarantine_id'          => $quarantine_id,
				'candidate_reasons'      => $reasons,
				'source_product_codes'   => $source_codes,
				'woocommerce_ids'        => $woo_ids,
				'woocommerce_parent_ids' => $woo_parent_ids,
				'woocommerce_types'      => $woo_types,
				'remediation'            => $diagnostic['remediation'],
			);
		}

		return array(
			'rows'               => $rows,
			'counts'             => $counts,
			'integrity_warnings' => $warnings,
		);
	}

	/**
	 * Normalize a Product Code/SKU without erasing meaningful punctuation.
	 *
	 * @param mixed $value External identifier.
	 */
	public static function normalize_identifier( $value ): string {
		$value = self::normalize_unicode( $value );
		$value = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $value ) ?? $value;
		$value = preg_replace( '/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', '', $value ) ?? $value;

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	/**
	 * Normalize a display name only for quarantine candidate discovery.
	 *
	 * @param mixed $value External display name.
	 */
	public static function normalize_name( $value ): string {
		$value = self::normalize_unicode( $value );
		$value = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) ?? $value;
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	/**
	 * Return the best deterministic unsafe alias edge, if any.
	 *
	 * @param Digitalogic_Canonical_Catalog_Identity $source Canonical source identity.
	 * @param Digitalogic_Canonical_Catalog_Identity $woo Canonical Woo identity.
	 */
	private function candidate_reason( Digitalogic_Canonical_Catalog_Identity $source, Digitalogic_Canonical_Catalog_Identity $woo ): ?array {
		$code  = $source->product_code;
		$name  = $source->name;
		$wcode = $woo->product_code;
		$sku   = $woo->sku;
		$wname = $woo->name;

		if ( '' !== $code && '' !== $wcode && $code !== $wcode && self::normalize_identifier( $code ) === self::normalize_identifier( $wcode ) ) {
			return array(
				'rank'   => 10,
				'reason' => 'normalized_product_code',
			);
		}
		if ( '' !== $code && '' !== $sku && $code === $sku ) {
			return array(
				'rank'   => 20,
				'reason' => 'exact_sku',
			);
		}
		if ( '' !== $code && '' !== $sku && self::normalize_identifier( $code ) === self::normalize_identifier( $sku ) ) {
			return array(
				'rank'   => 30,
				'reason' => 'normalized_sku',
			);
		}
		if ( '' !== $name && '' !== $wname && $name === $wname ) {
			return array(
				'rank'   => 40,
				'reason' => 'exact_name',
			);
		}
		if ( '' !== $name && '' !== $wname && self::normalize_name( $name ) === self::normalize_name( $wname ) ) {
			return array(
				'rank'   => 50,
				'reason' => 'normalized_name',
			);
		}

		return null;
	}

	/**
	 * Decode one provider-neutral report row into the typed identity model.
	 *
	 * @param array $row Provider-neutral source row.
	 */
	private function source_identity( array $row ): Digitalogic_Canonical_Catalog_Identity {
		$source = $row['source'];
		$code   = is_scalar( $source['product_code'] ?? null ) ? trim( (string) $source['product_code'] ) : '';

		return new Digitalogic_Canonical_Catalog_Identity(
			'patris',
			'patris:' . $code,
			$code,
			'',
			is_scalar( $source['name'] ?? null ) ? trim( (string) $source['name'] ) : '',
			'product',
			0
		);
	}

	/**
	 * Decode one provider-neutral report row into the typed identity model.
	 *
	 * @param array $row Provider-neutral WooCommerce row.
	 */
	private function woo_identity( array $row ): Digitalogic_Canonical_Catalog_Identity {
		$woo = $row['woocommerce'];
		$id  = is_numeric( $woo['id'] ?? null ) ? (int) $woo['id'] : 0;

		return new Digitalogic_Canonical_Catalog_Identity(
			'woocommerce',
			'woo:' . $id,
			is_scalar( $woo['product_code'] ?? null ) ? trim( (string) $woo['product_code'] ) : '',
			is_scalar( $woo['sku'] ?? null ) ? trim( (string) $woo['sku'] ) : '',
			is_scalar( $woo['name'] ?? null ) ? trim( (string) $woo['name'] ) : '',
			is_scalar( $woo['type'] ?? null ) ? trim( (string) $woo['type'] ) : '',
			is_numeric( $woo['parent_id'] ?? null ) ? (int) $woo['parent_id'] : 0
		);
	}

	/**
	 * Build connected source/Woo candidate components without greedy matching.
	 *
	 * @param array $edges Ranked candidate edges.
	 */
	private function components( array $edges ): array {
		$adjacency = array();
		$reasons   = array();
		foreach ( $edges as $edge ) {
			$source                          = 's:' . $edge['source'];
			$woo                             = 'w:' . $edge['woo'];
			$adjacency[ $source ][]          = $woo;
			$adjacency[ $woo ][]             = $source;
			$reasons[ $source . '|' . $woo ] = $edge['reason'];
		}

		$visited    = array();
		$components = array();
		foreach ( array_keys( $adjacency ) as $start ) {
			if ( isset( $visited[ $start ] ) ) {
				continue;
			}
			$queue     = array( $start );
			$component = array(
				'source'  => array(),
				'woo'     => array(),
				'reasons' => array(),
			);
			while ( $queue ) {
				$node = array_shift( $queue );
				if ( isset( $visited[ $node ] ) ) {
					continue;
				}
				$visited[ $node ] = true;
				$index            = (int) substr( $node, 2 );
				$component[ str_starts_with( $node, 's:' ) ? 'source' : 'woo' ][] = $index;
				foreach ( $adjacency[ $node ] ?? array() as $neighbor ) {
					$key = str_starts_with( $node, 's:' ) ? $node . '|' . $neighbor : $neighbor . '|' . $node;
					if ( isset( $reasons[ $key ] ) ) {
						$component['reasons'][] = $reasons[ $key ];
					}
					if ( ! isset( $visited[ $neighbor ] ) ) {
						$queue[] = $neighbor;
					}
				}
			}
			$components[] = $component;
		}

		return $components;
	}

	/**
	 * Add one stable issue code without duplicates.
	 *
	 * @param array  $row Report row, updated in place.
	 * @param string $issue Stable issue code.
	 */
	private function add_issue( array &$row, string $issue ): void {
		$row['issues'] = array_values( array_unique( array_merge( (array) ( $row['issues'] ?? array() ), array( $issue ) ) ) );
	}

	/**
	 * Apply Unicode, digit, and Persian/Arabic letter normalization.
	 *
	 * @param mixed $value External text.
	 */
	private static function normalize_unicode( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = Normalizer::normalize( $value, Normalizer::FORM_KC );
			if ( is_string( $normalized ) ) {
				$value = $normalized;
			}
		}

		return strtr(
			$value,
			array(
				'ك' => 'ک',
				'ي' => 'ی',
				'ى' => 'ی',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
			)
		);
	}
}
