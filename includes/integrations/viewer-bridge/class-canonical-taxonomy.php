<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use Normalizer;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Live port of the reviewed canonical taxonomy policy. It rebuilds topology
 * from current stores on every state request; it never reads the development
 * canonical-taxonomy.json artifact.
 */
final class Canonical_Taxonomy {

	/**
	 * @param array<string,mixed>            $patris
	 * @param array<int,array<string,mixed>> $errors
	 * @param array<int,array<string,mixed>> $warnings
	 * @return array<string,mixed>
	 */
	public static function build( array $patris, array &$errors, array &$warnings ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$errors[] = self::issue(
				'woocommerce_categories_unavailable',
				'woocommerce',
				'WooCommerce categories could not be read.',
				true
			);
			$terms    = array();
		}

		$term_by_id = array();
		foreach ( (array) $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$term_by_id[ (int) $term->term_id ] = array(
				'object'             => $term,
				'termId'             => (int) $term->term_id,
				'name'               => (string) $term->name,
				'slug'               => (string) $term->slug,
				'parentTermId'       => (int) $term->parent,
				'patrisCode'         => Store::patris_code(
					(string) get_term_meta(
						$term->term_id,
						'_digitalogic_patris_category_code',
						true
					)
				),
				'patrisManaged'      => (string) get_term_meta(
					$term->term_id,
					'_digitalogic_patris_category_managed',
					true
				),
				'patrisAdopted'      => (string) get_term_meta(
					$term->term_id,
					'_digitalogic_patris_category_adopted',
					true
				),
				'catalogKey'         => (string) get_term_meta(
					$term->term_id,
					'_digitalogic_catalog_category_key',
					true
				),
				'thumbnailId'        => absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) ),
				'viewerTrashedAt'    => (string) get_term_meta(
					$term->term_id,
					'_digitalogic_viewer_trashed_at',
					true
				),
				'viewerReassignedTo' => absint(
					get_term_meta(
						$term->term_id,
						'_digitalogic_viewer_reassigned_to',
						true
					)
				),
			);
		}

		$menu               = wp_get_nav_menu_object( 'digitalogic-product-categories' );
		$menu_items_result  = $menu
			? wp_get_nav_menu_items( (int) $menu->term_id )
			: array();
		$menu_items         = is_array( $menu_items_result ) ? $menu_items_result : array();
		$menu_item_by_id    = array();
		$menu_items_by_term = array();
		$menu_term_ids      = array();
		$menu_order_by_term = array();
		$menu_position      = 1;
		foreach ( $menu_items as $item ) {
			$menu_item_by_id[ (int) $item->ID ] = $item;
			if ( $item->type === 'taxonomy' && $item->object === 'product_cat' ) {
				$term_id                          = (int) $item->object_id;
				$menu_term_ids[ $term_id ]        = true;
				$menu_items_by_term[ $term_id ][] = $item;
				if ( ! isset( $menu_order_by_term[ $term_id ] ) ) {
					$menu_order_by_term[ $term_id ] = $menu_position;
				}
				++$menu_position;
			}
		}

		$entities = array();
		foreach ( array_keys( $term_by_id ) as $term_id ) {
			$entities[] = 'wp:' . $term_id;
		}
		foreach ( array_keys( $patris['categories'] ) as $code ) {
			$entities[] = 'patris:' . $code;
		}
		$union       = new Canonical_Union_Find( $entities );
		$relations   = array();
		$conflicts   = array();
		$code_owners = array();

		foreach ( $term_by_id as $term_id => $term ) {
			$code = (string) $term['patrisCode'];
			if ( $code !== '' ) {
				$code_owners[ $code ][] = $term_id;
			}
		}
		foreach ( Store::mappings() as $code => $mapping ) {
			$term_id = absint( $mapping['termId'] ?? 0 );
			if ( ! isset( $term_by_id[ $term_id ] ) || ! isset( $patris['categories'][ $code ] ) ) {
				$warnings[] = self::issue(
					'reviewed_mapping_target_unavailable',
					'canonical-map',
					$code,
					true
				);
				continue;
			}
			if ( ! isset( $code_owners[ $code ] ) ) {
				$code_owners[ $code ] = array( $term_id );
				$relations[]          = array(
					'left'       => 'wp:' . $term_id,
					'right'      => 'patris:' . $code,
					'matchState' => 'manual',
					'reason'     => 'digitalogic_viewer_category_map',
				);
			}
		}

		foreach ( $code_owners as $code => $owners ) {
			$owners = array_values( array_unique( array_map( 'intval', $owners ) ) );
			if ( count( $owners ) !== 1 || ! isset( $patris['categories'][ $code ] ) ) {
				$conflicts[] = array(
					'id'       => 'conflict:patris-code:' . $code,
					'kind'     => count( $owners ) > 1
						? 'duplicate_authoritative_meta'
						: 'orphan_authoritative_meta',
					'state'    => 'open',
					'blocking' => true,
					'entities' => array_map(
						static fn( int $term_id ): string => 'cat:wp:' . $term_id,
						$owners
					),
					'evidence' => array( 'patris:category:' . $code ),
				);
				continue;
			}
			$term_id = $owners[0];
			$union->union( 'wp:' . $term_id, 'patris:' . $code );
			$already_custom = false;
			foreach ( $relations as $relation ) {
				if (
					$relation['left'] === 'wp:' . $term_id
					&& $relation['right'] === 'patris:' . $code
				) {
					$already_custom = true;
					break;
				}
			}
			if ( ! $already_custom ) {
				$term        = $term_by_id[ $term_id ];
				$relations[] = array(
					'left'       => 'wp:' . $term_id,
					'right'      => 'patris:' . $code,
					'matchState' => $term['patrisManaged'] === '1' ? 'exact' : 'manual',
					'reason'     => '_digitalogic_patris_category_code',
				);
			}
		}

		$patris_sorted = array_values( $patris['categories'] );
		usort(
			$patris_sorted,
			static function ( array $left, array $right ): int {
				return ( (int) ( $left['depth'] ?? 0 ) ) <=> ( (int) ( $right['depth'] ?? 0 ) )
					?: strnatcasecmp(
						(string) ( $left['category_code'] ?? '' ),
						(string) ( $right['category_code'] ?? '' )
					);
			}
		);

		// Deterministic suggestions only: unique normalized name plus an exact
		// mapped parent, or a unique normalized root-path suffix.
		foreach ( $patris_sorted as $category ) {
			$code = Store::patris_code( (string) ( $category['category_code'] ?? '' ) );
			if ( $code === '' ) {
				continue;
			}
			if ( self::component_has_prefix( $union, $entities, 'patris:' . $code, 'wp:' ) ) {
				continue;
			}
			$key = self::normalized_name_key( (string) ( $category['name'] ?? '' ) );
			if ( $key === '' ) {
				continue;
			}
			$candidates = array();
			foreach ( $term_by_id as $term_id => $term ) {
				if ( self::normalized_name_key( $term['name'] ) !== $key ) {
					continue;
				}
				if ( self::component_has_prefix( $union, $entities, 'wp:' . $term_id, 'patris:' ) ) {
					continue;
				}
				$parent_code = Store::patris_code( (string) ( $category['parent_code'] ?? '' ) );
				if ( $parent_code !== '' ) {
					if ( ! isset( $patris['categories'][ $parent_code ] ) ) {
						continue;
					}
					if ( ! self::component_has_prefix(
						$union,
						$entities,
						'patris:' . $parent_code,
						'wp:'
					) ) {
						continue;
					}
					$term_parent = (int) $term['parentTermId'];
					if (
						$term_parent < 1
						|| $union->find( 'wp:' . $term_parent )
							!== $union->find( 'patris:' . $parent_code )
					) {
						continue;
					}
				} else {
					$source_path = array_map(
						array( self::class, 'normalized_name_key' ),
						self::patris_path( $code, $patris['categories'], 'name' )
					);
					$target_path = array_map(
						array( self::class, 'normalized_name_key' ),
						self::term_path( $term_id, $term_by_id, 'name' )
					);
					if ( ! self::is_path_suffix( $source_path, $target_path ) ) {
						continue;
					}
				}
				$candidates[] = $term_id;
			}
			if ( count( $candidates ) === 1 ) {
				$term_id = $candidates[0];
				$union->union( 'patris:' . $code, 'wp:' . $term_id );
				$relations[] = array(
					'left'       => 'patris:' . $code,
					'right'      => 'wp:' . $term_id,
					'matchState' => 'suggested',
					'reason'     => 'unique_normalized_name_and_parent_path',
				);
			} elseif ( count( $candidates ) > 1 ) {
				$conflicts[] = array(
					'id'       => 'conflict:ambiguous-normalized-match:' . $code,
					'kind'     => 'ambiguous_normalized_match',
					'state'    => 'open',
					'blocking' => false,
					'entities' => array_merge(
						array( 'cat:patris:' . $code ),
						array_map(
							static fn( int $term_id ): string => 'cat:wp:' . $term_id,
							$candidates
						)
					),
					'evidence' => array( 'normalizedNameKey:' . $key ),
				);
			}
		}

		// Resolve a duplicate WordPress alias only when a mapped non-menu term
		// has exactly one same-normalized-name menu term with no Patris source.
		$duplicate_relations = array();
		foreach ( $term_by_id as $term_id => $term ) {
			if ( isset( $menu_term_ids[ $term_id ] ) ) {
				continue;
			}
			if ( ! self::component_has_prefix( $union, $entities, 'wp:' . $term_id, 'patris:' ) ) {
				continue;
			}
			$key        = self::normalized_name_key( $term['name'] );
			$candidates = array();
			foreach ( $term_by_id as $candidate_id => $candidate ) {
				if (
					! isset( $menu_term_ids[ $candidate_id ] )
					|| $union->find( 'wp:' . $candidate_id ) === $union->find( 'wp:' . $term_id )
					|| self::normalized_name_key( $candidate['name'] ) !== $key
					|| self::component_has_prefix(
						$union,
						$entities,
						'wp:' . $candidate_id,
						'patris:'
					)
				) {
					continue;
				}
				$candidates[] = $candidate_id;
			}
			if ( count( $candidates ) !== 1 ) {
				continue;
			}
			$candidate_id = $candidates[0];
			$union->union( 'wp:' . $term_id, 'wp:' . $candidate_id );
			$relation              = array(
				'left'       => 'wp:' . $term_id,
				'right'      => 'wp:' . $candidate_id,
				'matchState' => 'suggested',
				'reason'     => 'unique_normalized_wordpress_duplicate_menu_authority',
			);
			$relations[]           = $relation;
			$duplicate_relations[] = $relation;
		}

		$components = array();
		foreach ( $entities as $entity ) {
			$components[ $union->find( $entity ) ][] = $entity;
		}
		foreach ( $components as &$component ) {
			sort( $component, SORT_NATURAL );
		}
		unset( $component );

		$canonical_by_root = array();
		foreach ( $components as $root => $component ) {
			$parts     = self::component_parts( $component, $term_by_id, $patris['categories'] );
			$menu_term = null;
			foreach ( $parts['wp'] as $term ) {
				if ( isset( $menu_term_ids[ $term['termId'] ] ) ) {
					$menu_term = $term;
					break;
				}
			}
			if ( $menu_term ) {
				$canonical_by_root[ $root ] = 'cat:wp:' . $menu_term['termId'];
				continue;
			}
			$catalog_keys = array();
			foreach ( $parts['wp'] as $term ) {
				if ( str_starts_with( (string) $term['catalogKey'], 'digitalogic:' ) ) {
					$catalog_keys[] = (string) $term['catalogKey'];
				}
			}
			sort( $catalog_keys, SORT_STRING );
			if ( $catalog_keys ) {
				$canonical_by_root[ $root ] = 'cat:' . $catalog_keys[0];
			} elseif ( $parts['patris'] ) {
				$canonical_by_root[ $root ] =
					'cat:patris:' . (string) $parts['patris'][0]['category_code'];
			} else {
				$canonical_by_root[ $root ] = 'cat:wp:' . (int) $parts['wp'][0]['termId'];
			}
		}
		$canonical = static function ( string $entity ) use ( $union, $canonical_by_root ): string {
			return (string) ( $canonical_by_root[ $union->find( $entity ) ] ?? '' );
		};

		$nodes               = array();
		$canonical_by_term   = array();
		$canonical_by_patris = array();
		foreach ( $components as $root => $component ) {
			$parts     = self::component_parts( $component, $term_by_id, $patris['categories'] );
			$preferred = self::preferred_term( $parts['wp'], $menu_term_ids );
			$id        = (string) $canonical_by_root[ $root ];
			$parent_id = Live_State::ROOT_CATEGORY_ID;
			if ( $preferred ) {
				$source_parent = (int) $preferred['parentTermId'];
				if ( $source_parent > 0 && isset( $term_by_id[ $source_parent ] ) ) {
					$parent_id = $canonical( 'wp:' . $source_parent );
				}
			} elseif ( ! empty( $parts['patris'][0]['parent_code'] ) ) {
				$parent_entity = 'patris:' . (string) $parts['patris'][0]['parent_code'];
				$parent_id     = $canonical( $parent_entity );
			}
			if ( $parent_id === '' || $parent_id === $id ) {
				$parent_id = Live_State::ROOT_CATEGORY_ID;
			}

			$name        = (string) ( $preferred['name'] ?? $parts['patris'][0]['name'] ?? $id );
			$source_refs = array();
			foreach ( $parts['wp'] as $term ) {
				$term_id                       = (int) $term['termId'];
				$canonical_by_term[ $term_id ] = $id;
				$source_refs[]                 = array(
					'source'     => 'woocommerce.product_cat',
					'externalId' => (string) $term_id,
					'path'       => '/product-category/'
						. implode(
							'/',
							array_map(
								'rawurlencode',
								self::term_path( $term_id, $term_by_id, 'slug' )
							)
						)
						. '/',
				);
				foreach ( $menu_items_by_term[ $term_id ] ?? array() as $item ) {
					$source_refs[] = array(
						'source'     => 'wordpress.nav_menu:' . (int) ( $menu->term_id ?? 0 ),
						'externalId' => (string) $item->ID,
						'path'       => '/nav-menu/'
							. (int) ( $menu->term_id ?? 0 )
							. '/'
							. implode( '/', self::menu_item_path( (int) $item->ID, $menu_item_by_id ) ),
					);
				}
			}
			foreach ( $parts['patris'] as $category ) {
				$code                         = (string) $category['category_code'];
				$canonical_by_patris[ $code ] = $id;
				$source_refs[]                = array(
					'source'     => 'patris.product-sync',
					'externalId' => $code,
					'path'       => '/categories/'
						. implode(
							'/',
							array_map(
								'rawurlencode',
								self::patris_path( $code, $patris['categories'], 'category_code' )
							)
						),
				);
			}
			usort(
				$source_refs,
				static fn( array $left, array $right ): int =>
					strcmp( $left['source'], $right['source'] )
					?: strcmp( $left['externalId'], $right['externalId'] )
			);

			$match_state = self::component_match_state(
				$component,
				$parts,
				$relations,
				$union
			);
			$menu_orders = array();
			$term_ids    = array();
			foreach ( $parts['wp'] as $part_term ) {
				$part_term_id = (int) $part_term['termId'];
				$term_ids[]   = $part_term_id;
				if ( isset( $menu_order_by_term[ $part_term_id ] ) ) {
					$menu_orders[] = (int) $menu_order_by_term[ $part_term_id ];
				}
			}
			$presentation_order = $menu_orders
				? min( $menu_orders )
				: (
					$term_ids
						? 10000 + min( $term_ids )
						: 20000 + count( $nodes )
				);
			$nodes[ $id ]       = array(
				'id'                     => $id,
				'name'                   => sanitize_text_field( $name ),
				'parentId'               => $parent_id,
				'directProductCount'     => 0,
				'descendantProductCount' => 0,
				'descendantCount'        => 0,
				'sourceRefs'             => $source_refs,
				'iconKey'                => self::semantic_icon( $name ),
				'matchState'             => $match_state,
				'lifecycleStatus'        => $preferred && $preferred['viewerTrashedAt'] !== ''
					? 'trash'
					: 'active',
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability is registered by Store::add_capabilities during activation.
				'allowedActions'         => current_user_can( 'digitalogic_viewer_manage' )
					&& ! in_array( $match_state, array( 'suggested', 'unresolved' ), true )
					? (
						$preferred && $preferred['viewerTrashedAt'] !== ''
							? array( 'entity.restore' )
							: array(
								'category.rename',
								'category.move',
								'category.map_source',
								'category.unmap_source',
								'entity.trash',
							)
					)
					: array(),
				'_reassignedTermId'      => $preferred
					? (int) $preferred['viewerReassignedTo']
					: 0,
				'revision'               => self::component_revision( $id, $preferred, $parts['patris'] ),
				'_order'                 => $presentation_order,
			);
		}

		foreach ( $patris['categories'] as $code => $unused ) {
			if ( ! isset( $canonical_by_patris[ $code ] ) ) {
				$canonical_by_patris[ $code ] = $canonical( 'patris:' . $code );
			}
		}
		foreach ( $term_by_id as $term_id => $unused ) {
			if ( ! isset( $canonical_by_term[ $term_id ] ) ) {
				$canonical_by_term[ $term_id ] = $canonical( 'wp:' . $term_id );
			}
		}

		$redirects = array();
		foreach ( $nodes as $node_id => &$node ) {
			$target_term_id = absint( $node['_reassignedTermId'] ?? 0 );
			unset( $node['_reassignedTermId'] );
			if (
				( $node['lifecycleStatus'] ?? 'active' ) !== 'trash'
				|| $target_term_id < 1
			) {
				continue;
			}
			$target_id = (string) ( $canonical_by_term[ $target_term_id ] ?? '' );
			if ( $target_id === '' || $target_id === $node_id || ! isset( $nodes[ $target_id ] ) ) {
				continue;
			}
			$node['lifecycleRedirectId'] = $target_id;
			$redirects[ $node_id ]       = $target_id;
		}
		unset( $node );

		// A trashed source-managed branch redirects its live children to the
		// reviewed reassignment target without rewriting Patris source data.
		foreach ( $nodes as $node_id => &$node ) {
			$parent_id = (string) ( $node['parentId'] ?? '' );
			$visited   = array();
			while ( isset( $redirects[ $parent_id ] ) && ! isset( $visited[ $parent_id ] ) ) {
				$visited[ $parent_id ] = true;
				$parent_id             = $redirects[ $parent_id ];
			}
			if ( $parent_id !== '' && $parent_id !== $node_id ) {
				$node['parentId'] = $parent_id;
			}
		}
		unset( $node );

		$aliases = array();
		foreach ( $components as $root => $component ) {
			$canonical_id = (string) $canonical_by_root[ $root ];
			$parts        = self::component_parts( $component, $term_by_id, $patris['categories'] );
			$match_state  = self::component_match_state(
				$component,
				$parts,
				$relations,
				$union
			);
			foreach ( $parts['wp'] as $term ) {
				$alias_id = 'cat:wp:' . $term['termId'];
				if ( $alias_id !== $canonical_id ) {
					$aliases[] = array(
						'aliasId'     => $alias_id,
						'canonicalId' => $canonical_id,
						'aliasName'   => $term['name'],
						'matchState'  => $match_state,
					);
				}
				if ( str_starts_with( (string) $term['catalogKey'], 'digitalogic:' ) ) {
					$catalog_alias = 'cat:' . $term['catalogKey'];
					if ( $catalog_alias !== $canonical_id ) {
						$aliases[] = array(
							'aliasId'     => $catalog_alias,
							'canonicalId' => $canonical_id,
							'aliasName'   => $term['name'],
							'matchState'  => 'manual',
						);
					}
				}
			}
			foreach ( $parts['patris'] as $category ) {
				$alias_id = 'cat:patris:' . $category['category_code'];
				if ( $alias_id !== $canonical_id ) {
					$aliases[] = array(
						'aliasId'     => $alias_id,
						'canonicalId' => $canonical_id,
						'aliasName'   => (string) $category['name'],
						'matchState'  => $match_state,
					);
				}
			}
		}
		usort(
			$aliases,
			static fn( array $left, array $right ): int =>
				strcmp( $left['aliasId'], $right['aliasId'] )
				?: strcmp( $left['canonicalId'], $right['canonicalId'] )
		);

		foreach ( $menu_items as $item ) {
			if ( $item->type !== 'taxonomy' || $item->object !== 'product_cat' ) {
				continue;
			}
			$term = $term_by_id[ (int) $item->object_id ] ?? null;
			if ( ! $term ) {
				continue;
			}
			$parent_item      = $menu_item_by_id[ (int) $item->menu_item_parent ] ?? null;
			$menu_parent_term = $parent_item ? (int) $parent_item->object_id : 0;
			if ( $menu_parent_term !== (int) $term['parentTermId'] ) {
				$conflicts[] = array(
					'id'       => 'conflict:menu-parent:' . (int) $item->ID,
					'kind'     => 'menu_taxonomy_parent_mismatch',
					'state'    => 'open',
					'blocking' => false,
					'entities' => array( 'menu-item:' . (int) $item->ID ),
					'evidence' => array(
						'menuParentTerm:' . $menu_parent_term,
						'taxonomyParentTerm:' . (int) $term['parentTermId'],
					),
				);
			}
		}

		foreach ( $patris['categories'] as $code => $category ) {
			$node_id = $canonical_by_patris[ $code ] ?? '';
			if ( ( $nodes[ $node_id ]['matchState'] ?? '' ) === 'unresolved' ) {
				$conflicts[] = array(
					'id'       => 'conflict:unresolved-patris:' . $code,
					'kind'     => 'unresolved_source_category',
					'state'    => 'open',
					'blocking' => false,
					'entities' => array( $node_id ),
					'evidence' => array( 'patris:category:' . $code ),
				);
			}
		}
		foreach ( $duplicate_relations as $relation ) {
			$conflicts[] = array(
				'id'       => 'conflict:resolved-duplicate:'
					. str_replace( ':', '-', $relation['left'] . '-' . $relation['right'] ),
				'kind'     => 'normalized_duplicate',
				'state'    => 'resolved_in_view',
				'blocking' => false,
				'entities' => array(
					$canonical( $relation['left'] ),
					str_replace( 'wp:', 'cat:wp:', $relation['left'] ),
					str_replace( 'wp:', 'cat:wp:', $relation['right'] ),
				),
				'evidence' => array( $relation['reason'] ),
			);
		}

		$nodes[ Live_State::ROOT_CATEGORY_ID ] = array(
			'id'                     => Live_State::ROOT_CATEGORY_ID,
			'name'                   => 'محصولات دیجیتالاجیک',
			'parentId'               => null,
			'directProductCount'     => 0,
			'descendantProductCount' => 0,
			'descendantCount'        => 0,
			'sourceRefs'             => array(
				array(
					'source'     => 'wordpress.nav_menu',
					'externalId' => (string) ( $menu->term_id ?? '' ),
					'path'       => '/nav-menu/' . (string) ( $menu->term_id ?? '' ),
				),
				array(
					'source'     => 'patris.product-sync',
					'externalId' => implode( ',', $patris['sourceRefs'] ),
					'path'       => '/categories',
				),
			),
			'iconKey'                => 'catalog',
			'matchState'             => 'exact',
			'revision'               => Revision::hash(
				array(
					'root'   => 'digitalogic-products',
					'terms'  => count( $term_by_id ),
					'patris' => count( $patris['categories'] ),
				)
			),
			'_order'                 => 0,
		);

		$child_order_by_parent = array();
		foreach ( $nodes as $id => $node ) {
			if ( $id === Live_State::ROOT_CATEGORY_ID ) {
				continue;
			}
			$parent_id                             = (string) ( $node['parentId'] ?? Live_State::ROOT_CATEGORY_ID );
			$child_order_by_parent[ $parent_id ][] = $id;
		}
		foreach ( $child_order_by_parent as &$child_ids ) {
			usort(
				$child_ids,
				static function ( string $left, string $right ) use ( $nodes ): int {
					return ( (int) ( $nodes[ $left ]['_order'] ?? PHP_INT_MAX ) )
						<=> ( (int) ( $nodes[ $right ]['_order'] ?? PHP_INT_MAX ) )
						?: strcmp( $left, $right );
				}
			);
		}
		unset( $child_ids );

		return array(
			'nodes'               => $nodes,
			'termsById'           => $term_by_id,
			'canonicalByTerm'     => $canonical_by_term,
			'canonicalByPatris'   => $canonical_by_patris,
			'aliases'             => $aliases,
			'conflicts'           => $conflicts,
			'presentation'        => array(
				'childOrderByParent' => $child_order_by_parent,
				'menuSlug'           => 'digitalogic-product-categories',
				'menuRole'           => 'ordered-curated-subset',
			),
			'redirects'           => $redirects,
			'wooCount'            => count( $term_by_id ),
			'menuFound'           => (bool) $menu,
			'menuItemCount'       => count( $menu_items_by_term ),
			'mappedPatrisCount'   => count(
				array_filter(
					$canonical_by_patris,
					static fn( string $id ): bool => ! str_starts_with( $id, 'cat:patris:' )
						|| isset( $nodes[ $id ] )
							&& ( $nodes[ $id ]['matchState'] ?? '' ) !== 'unresolved'
				)
			),
			'unmappedPatrisCount' => count(
				array_filter(
					$canonical_by_patris,
					static fn( string $id ): bool => isset( $nodes[ $id ] )
						&& ( $nodes[ $id ]['matchState'] ?? '' ) === 'unresolved'
				)
			),
			'policy'              => array(
				'displayHierarchyAuthority' => 'woocommerce.product_cat',
				'megamenuRole'              => 'ordered-curated-subset',
				'fuzzyMatching'             => false,
				'suggestedMappingsWritable' => false,
				'countDefinition'           => 'distinct-inclusive-subtree-product-union',
			),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $terms
	 * @param array<int,bool>                $menu_term_ids
	 */
	private static function preferred_term( array $terms, array $menu_term_ids ): ?array {
		foreach ( $terms as $term ) {
			if ( isset( $menu_term_ids[ (int) $term['termId'] ] ) ) {
				return $term;
			}
		}
		foreach ( $terms as $term ) {
			if ( str_starts_with( (string) $term['catalogKey'], 'digitalogic:' ) ) {
				return $term;
			}
		}
		return $terms[0] ?? null;
	}

	private static function component_parts(
		array $component,
		array $term_by_id,
		array $patris_categories
	): array {
		$wp     = array();
		$patris = array();
		foreach ( $component as $entity ) {
			if ( str_starts_with( $entity, 'wp:' ) ) {
				$term = $term_by_id[ (int) substr( $entity, 3 ) ] ?? null;
				if ( $term ) {
					$wp[] = $term;
				}
			} elseif ( str_starts_with( $entity, 'patris:' ) ) {
				$category = $patris_categories[ substr( $entity, 7 ) ] ?? null;
				if ( $category ) {
					$patris[] = $category;
				}
			}
		}
		usort( $wp, static fn( array $a, array $b ): int => $a['termId'] <=> $b['termId'] );
		usort(
			$patris,
			static fn( array $a, array $b ): int => strnatcasecmp(
				(string) $a['category_code'],
				(string) $b['category_code']
			)
		);
		return array(
			'wp'     => $wp,
			'patris' => $patris,
		);
	}

	private static function component_match_state(
		array $component,
		array $parts,
		array $relations,
		Canonical_Union_Find $union
	): string {
		$root = $union->find( $component[0] );
		foreach ( array( 'suggested', 'manual' ) as $state ) {
			foreach ( $relations as $relation ) {
				if (
					$relation['matchState'] === $state
					&& $union->find( $relation['left'] ) === $root
					&& $union->find( $relation['right'] ) === $root
				) {
					return $state;
				}
			}
		}
		foreach ( $parts['wp'] as $term ) {
			if ( str_starts_with( (string) $term['catalogKey'], 'digitalogic:' ) ) {
				return 'manual';
			}
		}
		return $parts['patris'] && ! $parts['wp'] ? 'unresolved' : 'exact';
	}

	private static function component_has_prefix(
		Canonical_Union_Find $union,
		array $entities,
		string $entity,
		string $prefix
	): bool {
		$root = $union->find( $entity );
		foreach ( $entities as $candidate ) {
			if ( str_starts_with( $candidate, $prefix ) && $union->find( $candidate ) === $root ) {
				return true;
			}
		}
		return false;
	}

	private static function component_revision(
		string $id,
		?array $preferred,
		array $patris
	): string {
		$patris_hashes = array_map(
			static fn( array $record ): string => (string) ( $record['record_hash'] ?? '' ),
			$patris
		);
		sort( $patris_hashes, SORT_STRING );
		return Revision::hash(
			array(
				'id'           => $id,
				'term'         => $preferred
					? array(
						'id'           => $preferred['termId'],
						'name'         => $preferred['name'],
						'slug'         => $preferred['slug'],
						'parent'       => $preferred['parentTermId'],
						'trashedAt'    => $preferred['viewerTrashedAt'],
						'reassignedTo' => $preferred['viewerReassignedTo'],
					)
					: null,
				'patrisHashes' => $patris_hashes,
			)
		);
	}

	private static function normalized_name_key( string $value ): string {
		if ( class_exists( Normalizer::class ) ) {
			$value = Normalizer::normalize( $value, Normalizer::FORM_KC ) ?: $value;
		}
		$value      = str_replace(
			array( 'ي', 'ى', 'ك', 'ۀ', 'ة', 'ـ', "\u{200C}", "\u{200D}" ),
			array( 'ی', 'ی', 'ک', 'ه', 'ه', '', ' ', ' ' ),
			$value
		);
		$value      = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value ) ?? $value;
		$value      = preg_replace( '/[._\/\\\\+&\-–—,:؛،()\[\]{}\'"`]+/u', ' ', $value ) ?? $value;
		$value      = preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? trim( $value );
		$tokens     = preg_split( '/\s+/u', mb_strtolower( $value, 'UTF-8' ) ) ?: array();
		$normalized = array();
		foreach ( $tokens as $token ) {
			if ( $token === '' || $token === 'ها' || $token === 'های' ) {
				continue;
			}
			if ( mb_strlen( $token, 'UTF-8' ) > 4 && str_ends_with( $token, 'های' ) ) {
				$token = mb_substr( $token, 0, -3, 'UTF-8' );
			} elseif ( mb_strlen( $token, 'UTF-8' ) > 3 && str_ends_with( $token, 'ها' ) ) {
				$token = mb_substr( $token, 0, -2, 'UTF-8' );
			}
			$normalized[] = $token;
		}
		return implode( ' ', $normalized );
	}

	private static function is_path_suffix( array $source, array $target ): bool {
		if ( ! $source || count( $source ) > count( $target ) ) {
			return false;
		}
		$offset = count( $target ) - count( $source );
		foreach ( $source as $index => $segment ) {
			if ( $segment !== $target[ $offset + $index ] ) {
				return false;
			}
		}
		return true;
	}

	private static function term_path( int $term_id, array $terms, string $field ): array {
		$path = array();
		$seen = array();
		while ( $term_id > 0 && isset( $terms[ $term_id ] ) && ! isset( $seen[ $term_id ] ) ) {
			$seen[ $term_id ] = true;
			array_unshift( $path, (string) $terms[ $term_id ][ $field ] );
			$term_id = (int) $terms[ $term_id ]['parentTermId'];
		}
		return $path;
	}

	private static function patris_path( string $code, array $categories, string $field ): array {
		$path = array();
		$seen = array();
		while ( $code !== '' && isset( $categories[ $code ] ) && ! isset( $seen[ $code ] ) ) {
			$seen[ $code ] = true;
			array_unshift( $path, (string) ( $categories[ $code ][ $field ] ?? '' ) );
			$code = Store::patris_code( (string) ( $categories[ $code ]['parent_code'] ?? '' ) );
		}
		return $path;
	}

	private static function menu_item_path( int $item_id, array $items ): array {
		$path = array();
		$seen = array();
		while ( $item_id > 0 && isset( $items[ $item_id ] ) && ! isset( $seen[ $item_id ] ) ) {
			$seen[ $item_id ] = true;
			array_unshift( $path, (string) $item_id );
			$item_id = (int) $items[ $item_id ]->menu_item_parent;
		}
		return $path;
	}

	private static function semantic_icon( string $name ): string {
		$key   = self::normalized_name_key( $name );
		$rules = array(
			'car-chip'      => '/ecu|خودرو/iu',
			'chip'          => '/نیمه هادی|میکروکنترلر|میکروپروسسور|آی سی|eeprom|opamp|pc x86/iu',
			'transistor'    => '/ترانزیستور|mosfet|igbt|bjt|\bfet\b/iu',
			'diode'         => '/دیود|شاتکی|fast/iu',
			'resistor'      => '/مقاومت|پتانسیومتر|ترمیستور|ولوم/iu',
			'capacitor'     => '/خازن/iu',
			'inductor'      => '/سلف/iu',
			'thermometer'   => '/دما|رطوبت|ترموالکتریک/iu',
			'sensor'        => '/سنسور|حسگر/iu',
			'display'       => '/نمایشگر|lcd|oled/iu',
			'light'         => '/نور|لیزر|led/iu',
			'connector'     => '/کابل|کانکتور|اتصال|سوکت/iu',
			'robot'         => '/موتور|ربات|مکانیک|چرخ/iu',
			'circuit-board' => '/ماژول|برد|آردوینو|raspberry|اینترنت اشیا|iot/iu',
		);
		foreach ( $rules as $icon => $pattern ) {
			if ( preg_match( $pattern, $key ) ) {
				return $icon;
			}
		}
		return 'category';
	}

	private static function issue(
		string $code,
		string $source,
		string $reference,
		bool $retryable
	): array {
		return array(
			'code'      => sanitize_key( $code ),
			'source'    => sanitize_key( $source ),
			'message'   => sanitize_text_field( $reference ),
			'retryable' => $retryable,
		);
	}
}

/**
 * Deterministic lexical union-find used only while building one live response.
 */
final class Canonical_Union_Find {

	/** @var array<string,string> */
	private array $parent = array();

	public function __construct( array $values ) {
		foreach ( $values as $value ) {
			$this->parent[ (string) $value ] = (string) $value;
		}
	}

	public function find( string $value ): string {
		if ( ! isset( $this->parent[ $value ] ) ) {
			$this->parent[ $value ] = $value;
		}
		if ( $this->parent[ $value ] === $value ) {
			return $value;
		}
		$this->parent[ $value ] = $this->find( $this->parent[ $value ] );
		return $this->parent[ $value ];
	}

	public function union( string $left, string $right ): string {
		$a = $this->find( $left );
		$b = $this->find( $right );
		if ( $a === $b ) {
			return $a;
		}
		$winner                 = strcmp( $a, $b ) <= 0 ? $a : $b;
		$loser                  = $winner === $a ? $b : $a;
		$this->parent[ $loser ] = $winner;
		return $winner;
	}
}
