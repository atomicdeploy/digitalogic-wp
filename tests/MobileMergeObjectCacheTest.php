<?php
/**
 * Transaction-local cache compatibility and isolation fixtures.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Services\MobileMergeObjectCache;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.Files.FileName -- PHPUnit-discoverable test class filename.

require_once dirname( __DIR__ ) . '/laravel/app/Services/MobileMergeObjectCache.php';

/** No fixture uses Redis, a provider, or a real WordPress identity. */
final class MobileMergeObjectCacheTest extends TestCase {
	/** Stored false and null must be distinguished from missing private data. */
	public function test_false_null_existence_and_add_replace_delete_semantics(): void {
		$cache = new MobileMergeObjectCache( 1 );
		self::assertTrue( $cache->set( 'false', false, 'user_meta' ) );
		self::assertTrue( $cache->set( 'null', null, 'user_meta' ) );
		self::assertFalse( $cache->get( 'false', 'user_meta', false, $found ) );
		self::assertTrue( $found );
		self::assertNull( $cache->get( 'null', 'user_meta', false, $found ) );
		self::assertTrue( $found );
		self::assertFalse( $cache->get( 'missing', 'user_meta', true, $found ) );
		self::assertFalse( $found );
		self::assertFalse( $cache->add( 'false', 'replacement', 'user_meta' ) );
		self::assertTrue( $cache->replace( 'false', 'replacement', 'user_meta' ) );
		self::assertFalse( $cache->replace( 'missing', 'value', 'user_meta' ) );
		self::assertTrue( $cache->delete( 'null', 'user_meta' ) );
		self::assertFalse( $cache->delete( 'null', 'user_meta' ) );
		self::assertSame( 2, $cache->cache_hits );
		self::assertSame( 1, $cache->cache_misses );
	}

	/** Core-style object clones prevent caller mutation from changing local cache state. */
	public function test_objects_are_cloned_on_write_and_each_read(): void {
		$cache = new MobileMergeObjectCache( 1 );
		$user  = (object) array( 'name' => 'original fixture' );
		$cache->set( 7, $user, 'users' );
		$user->name = 'changed caller';
		$first      = $cache->get( 7, 'users' );
		self::assertSame( 'original fixture', $first->name );
		$first->name = 'changed reader';
		self::assertSame( 'original fixture', $cache->get( 7, 'users' )->name );
		self::assertNotSame( $first, $cache->get( 7, 'users' ) );
	}

	/** Bulk operations preserve per-key outcomes rather than inventing all-success. */
	public function test_bulk_methods_match_individual_value_and_failure_semantics(): void {
		$cache = new MobileMergeObjectCache( 1 );
		self::assertSame(
			array(
				'a' => true,
				'b' => true,
			),
			$cache->set_multiple(
				array(
					'a' => 4,
					'b' => false,
				),
				'fixture'
			)
		);
		self::assertSame(
			array(
				'a' => false,
				'c' => true,
			),
			$cache->add_multiple(
				array(
					'a' => 9,
					'c' => null,
				),
				'fixture'
			)
		);
		self::assertSame(
			array(
				'a' => 4,
				'b' => false,
				'c' => null,
				'd' => false,
			),
			$cache->get_multiple( array( 'a', 'b', 'c', 'd' ), 'fixture', true )
		);
		self::assertSame(
			array(
				'a' => true,
				'd' => false,
			),
			$cache->delete_multiple( array( 'a', 'd' ), 'fixture' )
		);
	}

	/** Blog-local groups never collide; explicitly global identity groups stay shared. */
	public function test_group_and_blog_namespaces_preserve_global_configuration(): void {
		$cache = new MobileMergeObjectCache( 1, array( 'users' => true ), array( 'volatile' ) );
		$cache->add_global_groups( array( 'user_meta' ) );
		$cache->set( 7, 'global user', 'users' );
		$cache->set( 7, 'global metadata', 'user_meta' );
		$cache->set( 7, 'blog one', 'options' );
		$cache->set( '2:7', 'separate key', 'options' );
		$cache->switch_to_blog( 2 );
		self::assertSame( 'global user', $cache->get( 7, 'users' ) );
		self::assertSame( 'global metadata', $cache->get( 7, 'user_meta' ) );
		self::assertFalse( $cache->get( 7, 'options' ) );
		$cache->set( 7, 'blog two', 'options' );
		$cache->switch_to_blog( 1 );
		self::assertSame( 'blog one', $cache->get( 7, 'options' ) );
		self::assertSame( 'separate key', $cache->get( '2:7', 'options' ) );
		self::assertTrue( $cache->non_persistent_groups['volatile'] );
		self::assertTrue( $cache->global_groups['users'] );
	}

	/** Numerical counters follow WordPress existence and nonnegative-value behavior. */
	public function test_counters_do_not_mint_missing_keys_and_floor_at_zero(): void {
		$cache = new MobileMergeObjectCache( 1 );
		self::assertFalse( $cache->incr( 'absent' ) );
		self::assertFalse( $cache->decr( 'absent' ) );
		$cache->set( 'count', '4' );
		self::assertSame( 7, $cache->incr( 'count', 3 ) );
		self::assertSame( 0, $cache->decr( 'count', 99 ) );
		$cache->set( 'text', 'not a counter' );
		self::assertSame( 2, $cache->incr( 'text', 2 ) );
		self::assertSame( 0, $cache->incr( 'text', -3 ) );
	}

	/** Invalid cache keys cannot alias a valid private entry or leave found stale. */
	public function test_invalid_keys_and_default_group_normalization(): void {
		$cache = new MobileMergeObjectCache( 1 );
		$cache->set( 0, 'zero', '' );
		self::assertSame( 'zero', $cache->get( '0', 'default' ) );
		foreach ( array( '', '  ', null, false, array(), new stdClass() ) as $key ) {
			self::assertFalse( $cache->set( $key, 'invalid' ) );
			self::assertFalse( $cache->add( $key, 'invalid' ) );
			self::assertFalse( $cache->replace( $key, 'invalid' ) );
			self::assertFalse( $cache->delete( $key ) );
			$found = true;
			self::assertFalse( $cache->get( $key, 'default', false, $found ) );
			self::assertFalse( $found );
		}
		self::assertSame( 'zero', $cache->get( 0 ) );
	}

	/** Reset retains globals; scoped and full flushes never affect another instance. */
	public function test_reset_flush_close_and_new_instances_keep_transaction_data_isolated(): void {
		$cache = new MobileMergeObjectCache( 1, array( 'users' ) );
		$other = new MobileMergeObjectCache( 1, array( 'users' ) );
		$other->set( 7, 'independent', 'users' );
		$cache->set( 7, 'transaction value', 'users' );
		$cache->set( 7, 'local', 'options' );
		self::assertTrue( $cache->reset() );
		self::assertSame( 'transaction value', $cache->get( 7, 'users' ) );
		self::assertFalse( $cache->get( 7, 'options' ) );
		$cache->flush_group( 'users' );
		self::assertFalse( $cache->get( 7, 'users' ) );
		foreach ( array( 'flush', 'flush_runtime', 'close' ) as $method ) {
			$cache->set( 7, 'temporary', 'users' );
			self::assertTrue( $cache->$method() );
			self::assertFalse( $cache->get( 7, 'users' ) );
			self::assertSame( 'independent', $other->get( 7, 'users' ) );
		}
	}

	/** Even forced reads and flush operations cannot touch the installed upstream cache. */
	public function test_no_api_delegates_to_upstream_or_advertises_persistence(): void {
		$original                   = $GLOBALS['wp_object_cache'] ?? null;
		$sentinel                   = new class() {
			/**
			 * Any attempted upstream access is an immediate regression.
			 *
			 * @param string $name Attempted method.
			 * @param array  $arguments Attempted arguments.
			 * @throws LogicException Whenever an upstream method is called.
			 */
			public function __call( $name, $arguments ) {
				throw new LogicException( 'Upstream cache access is forbidden.' );
			}
		};
		$GLOBALS['wp_object_cache'] = $sentinel;
		try {
			$cache = new MobileMergeObjectCache( 1 );
			$cache->set( 'fixture', 'local only', 'users' );
			self::assertSame( 'local only', $cache->get( 'fixture', 'users', true ) );
			self::assertFalse( $cache->get( 'upstream-only', 'users', true ) );
			self::assertTrue( $cache->flush_group( 'users' ) );
			self::assertTrue( $cache->flush_runtime() );
			self::assertTrue( $cache->close() );
			foreach ( array( 'get_multiple', 'set_multiple', 'add_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ) as $feature ) {
				self::assertTrue( $cache->supports( $feature ) );
			}
			self::assertFalse( $cache->supports( 'redis' ) );
			self::assertFalse( $cache->supports( 'persistent' ) );
			self::assertSame( $sentinel, $GLOBALS['wp_object_cache'] );
		} finally {
			$GLOBALS['wp_object_cache'] = $original;
		}
	}
}
