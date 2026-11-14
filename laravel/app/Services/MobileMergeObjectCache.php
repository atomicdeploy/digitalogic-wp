<?php
/**
 * Disposable in-memory WordPress cache for an account-merge transaction.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Services;

// phpcs:disable WordPress.Files.FileName -- Composer PSR-4 service.

/**
 * Never delegates reads, writes or invalidation to a persistent cache.
 *
 * The caller owns installation/restoration in a finally block, then exact entity
 * invalidation after commit or rollback. This object must never be published back
 * into the original cache. Expiration is intentionally request-local, like core.
 */
final class MobileMergeObjectCache {
	/**
	 * Private values by group and blog namespace.
	 *
	 * @var array
	 */
	private array $values = array();
	/**
	 * Current blog namespace.
	 *
	 * @var int
	 */
	private int $blog_id;
	/**
	 * Cache hit counter; never includes keys or private values.
	 *
	 * @var int
	 */
	public int $cache_hits = 0;
	/**
	 * Cache miss counter.
	 *
	 * @var int
	 */
	public int $cache_misses = 0;
	/**
	 * Global group registry, compatible with WordPress cache configuration.
	 *
	 * @var array
	 */
	public array $global_groups = array();
	/**
	 * Non-persistent registry; every group in this adapter is non-persistent.
	 *
	 * @var array
	 */
	public array $non_persistent_groups = array();

	/**
	 * Create an empty transaction-local cache without accepting any upstream store.
	 *
	 * @param int|null $blog_id Current WordPress blog ID.
	 * @param array    $global_groups Name list or associative group registry.
	 * @param array    $non_persistent_groups Name list or associative group registry.
	 */
	public function __construct( ?int $blog_id = null, array $global_groups = array(), array $non_persistent_groups = array() ) {
		$this->blog_id = $blog_id ?? ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
		$this->add_global_groups( $global_groups );
		$this->add_non_persistent_groups( $non_persistent_groups );
	}

	/**
	 * Resolve a valid key without concatenation ambiguity between group and blog.
	 *
	 * @param mixed $key Requested key.
	 * @param mixed $group Requested group.
	 * @return array|null Exact group/key pair, or invalid input.
	 */
	private function address( $key, $group ): ?array {
		if ( ! is_int( $key ) && ( ! is_string( $key ) || '' === trim( $key ) ) ) {
			return null;
		}
		if ( ! is_string( $group ) && ! is_int( $group ) && null !== $group ) {
			return null;
		}
		$group = empty( $group ) ? 'default' : (string) $group;
		$space = isset( $this->global_groups[ $group ] ) ? 'global:' : 'blog:' . $this->blog_id . ':';
		return array( $group, $space . (string) $key );
	}

	/**
	 * Determine local existence including stored false/null values.
	 *
	 * @param array|null $address Resolved cache address.
	 * @return bool Whether the entry exists locally.
	 */
	private function contains( ?array $address ): bool {
		return null !== $address && isset( $this->values[ $address[0] ] ) && array_key_exists( $address[1], $this->values[ $address[0] ] );
	}

	/**
	 * Read only this transaction's values. Force never accesses upstream storage.
	 *
	 * @param mixed $key Cache key.
	 * @param mixed $group Cache group.
	 * @param bool  $force Compatibility parameter; no upstream read is possible.
	 * @param mixed $found Reference receives exact existence, including false/null.
	 * @return mixed Local value or false for a miss.
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$address = $this->address( $key, $group );
		$found   = $this->contains( $address );
		if ( ! $found ) {
			++$this->cache_misses;
			return false;
		}
		++$this->cache_hits;
		$value = $this->values[ $address[0] ][ $address[1] ];
		return is_object( $value ) ? clone $value : $value;
	}

	/**
	 * Save a local value; objects follow WordPress clone-on-store behavior.
	 *
	 * @param mixed $key Cache key.
	 * @param mixed $data Value.
	 * @param mixed $group Cache group.
	 * @param int   $expire Compatibility parameter; lifetime ends with this scope.
	 * @return bool Whether the key was accepted.
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress runtime-cache compatibility signature.
		$address = $this->address( $key, $group );
		if ( null === $address ) {
			return false;
		}
		$this->values[ $address[0] ][ $address[1] ] = is_object( $data ) ? clone $data : $data;
		return true;
	}

	/**
	 * Add only a missing local entry.
	 *
	 * @param mixed $key Cache key.
	 * @param mixed $data Value.
	 * @param mixed $group Cache group.
	 * @param int   $expire Compatibility lifetime.
	 * @return bool Whether a new entry was stored.
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ): bool {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		return ! $this->contains( $this->address( $key, $group ) ) && $this->set( $key, $data, $group, $expire );
	}

	/**
	 * Replace only an existing local entry.
	 *
	 * @param mixed $key Cache key.
	 * @param mixed $data Value.
	 * @param mixed $group Cache group.
	 * @param int   $expire Compatibility lifetime.
	 * @return bool Whether an existing entry was replaced.
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ): bool {
		return $this->contains( $this->address( $key, $group ) ) && $this->set( $key, $data, $group, $expire );
	}

	/**
	 * Delete a local value without issuing upstream invalidations.
	 *
	 * @param mixed $key Cache key.
	 * @param mixed $group Cache group.
	 * @param bool  $deprecated Unused WordPress compatibility argument.
	 * @return bool Whether an existing entry was removed.
	 */
	public function delete( $key, $group = 'default', $deprecated = false ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress legacy compatibility signature.
		$address = $this->address( $key, $group );
		if ( ! $this->contains( $address ) ) {
			return false;
		}
		unset( $this->values[ $address[0] ][ $address[1] ] );
		return true;
	}

	/**
	 * Retrieve keys independently, preserving their original array keys.
	 *
	 * @param array $keys Cache keys.
	 * @param mixed $group Cache group.
	 * @param bool  $force Ignored upstream-refresh flag.
	 * @return array Local values or false misses keyed by requested key.
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ): array {
		$result = array();
		foreach ( $keys as $key ) {
			if ( is_int( $key ) || is_string( $key ) ) {
				$result[ $key ] = $this->get( $key, $group, $force );
			}
		}
		return $result;
	}

	/**
	 * Set several local values.
	 *
	 * @param array $data Key/value entries.
	 * @param mixed $group Cache group.
	 * @param int   $expire Compatibility lifetime.
	 * @return array Per-key success flags.
	 */
	public function set_multiple( array $data, $group = '', $expire = 0 ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $this->set( $key, $value, $group, $expire );
		}
		return $result;
	}

	/**
	 * Add several missing local values.
	 *
	 * @param array $data Key/value entries.
	 * @param mixed $group Cache group.
	 * @param int   $expire Compatibility lifetime.
	 * @return array Per-key success flags.
	 */
	public function add_multiple( array $data, $group = '', $expire = 0 ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $this->add( $key, $value, $group, $expire );
		}
		return $result;
	}

	/**
	 * Delete several local values.
	 *
	 * @param array $keys Cache keys.
	 * @param mixed $group Cache group.
	 * @return array Per-key deletion flags.
	 */
	public function delete_multiple( array $keys, $group = '' ): array {
		$result = array();
		foreach ( $keys as $key ) {
			if ( is_int( $key ) || is_string( $key ) ) {
				$result[ $key ] = $this->delete( $key, $group );
			}
		}
		return $result;
	}

	/**
	 * Increment an existing local numeric value, flooring at zero like WordPress.
	 *
	 * @param mixed $key Cache key.
	 * @param int   $offset Change amount.
	 * @param mixed $group Cache group.
	 * @return int|float|false Updated value or missing entry.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		$address = $this->address( $key, $group );
		if ( ! $this->contains( $address ) ) {
			return false;
		}
		$before                                     = $this->values[ $address[0] ][ $address[1] ];
		$after                                      = max( 0, ( is_numeric( $before ) ? $before + 0 : 0 ) + (int) $offset );
		$this->values[ $address[0] ][ $address[1] ] = $after;
		return $after;
	}

	/**
	 * Decrement an existing local numeric value, flooring at zero.
	 *
	 * @param mixed $key Cache key.
	 * @param int   $offset Change amount.
	 * @param mixed $group Cache group.
	 * @return int|float|false Updated value or missing entry.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, - (int) $offset, $group );
	}

	/** Clear all transaction-local values, never any persistent cache. */
	public function flush(): bool {
		$this->values = array();
		return true;
	}

	/** Clear only local memory; this adapter has no other storage tier. */
	public function flush_runtime(): bool {
		return $this->flush();
	}

	/**
	 * Clear a group across local blog namespaces only.
	 *
	 * @param mixed $group Cache group.
	 * @return bool Whether the group name was accepted.
	 */
	public function flush_group( $group ): bool {
		$address = $this->address( 0, $group );
		if ( null === $address ) {
			return false;
		}
		unset( $this->values[ $address[0] ] );
		return true;
	}

	/**
	 * Register global group names; both list and associative forms are accepted.
	 *
	 * @param array|string $groups Group names or group=>true registry.
	 */
	public function add_global_groups( $groups ): void {
		$this->global_groups = array_replace( $this->global_groups, $this->groups( $groups ) );
	}

	/**
	 * Retain non-persistent configuration; all groups are local regardless.
	 *
	 * @param array|string $groups Group names or group=>true registry.
	 */
	public function add_non_persistent_groups( $groups ): void {
		$this->non_persistent_groups = array_replace( $this->non_persistent_groups, $this->groups( $groups ) );
	}

	/**
	 * Normalize cache group registries without consulting another cache.
	 *
	 * @param array|string $groups Group names or map.
	 * @return array Group=>true registry.
	 */
	private function groups( $groups ): array {
		$result = array();
		foreach ( (array) $groups as $key => $value ) {
			$name = is_string( $key ) && ( true === $value || 1 === $value ) ? $key : $value;
			if ( is_string( $name ) && '' !== $name ) {
				$result[ $name ] = true;
			}
		}
		return $result;
	}

	/**
	 * Change the local blog namespace without loading or persisting anything.
	 *
	 * @param int $blog_id New blog ID.
	 */
	public function switch_to_blog( $blog_id ): void {
		$this->blog_id = (int) $blog_id;
	}

	/** Retain global groups while dropping local blog values, like legacy WP reset. */
	public function reset(): bool {
		foreach ( array_keys( $this->values ) as $group ) {
			if ( ! isset( $this->global_groups[ $group ] ) ) {
				unset( $this->values[ $group ] );
			}
		}
		return true;
	}

	/** Release this object's local private values; never flush the restored cache. */
	public function close(): bool {
		return $this->flush();
	}

	/**
	 * Advertise only cache operations implemented locally by this adapter.
	 *
	 * @param string $feature Requested WordPress cache capability.
	 * @return bool Whether the operation is supported without upstream access.
	 */
	public function supports( $feature ): bool {
		return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true );
	}
}
