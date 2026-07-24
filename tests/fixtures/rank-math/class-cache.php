<?php
/**
 * Rank Math sitemap cache test double.
 *
 * @package Digitalogic
 */

namespace RankMath\Sitemap;

/**
 * Record sitemap invalidations requested by the materializer.
 */
final class Cache {

	/**
	 * Record one cache invalidation.
	 *
	 * @param null|string $type Optional sitemap type.
	 */
	public static function invalidate_storage( $type = null ) {
		$GLOBALS['digitalogic_test_rank_math_invalidations'][] = $type;
	}
}
