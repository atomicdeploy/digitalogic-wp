<?php
/**
 * Typed provider-neutral catalog identity.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Typed provider-neutral identity used by reconciliation rules. */
final readonly class Digitalogic_Canonical_Catalog_Identity {

	/**
	 * Create one canonical identity value.
	 *
	 * @param string $provider Provider namespace.
	 * @param string $provider_id Provider-scoped immutable identity.
	 * @param string $product_code Canonical Product Code, when supplied.
	 * @param string $sku Provider SKU, when supplied.
	 * @param string $name Display name, when supplied.
	 * @param string $type Product or variation type.
	 * @param int    $parent_id Provider parent identity for variations.
	 */
	public function __construct(
		public string $provider,
		public string $provider_id,
		public string $product_code,
		public string $sku,
		public string $name,
		public string $type,
		public int $parent_id
	) {}

	/** Whether this identity represents a child variation. */
	public function is_variation(): bool {
		return 'variation' === $this->type || $this->parent_id > 0;
	}
}
