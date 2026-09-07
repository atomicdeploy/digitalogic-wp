<?php
declare(strict_types=1);

namespace Digitalogic\Adapters;

/** Normalize transport metadata before application request identity is computed. */
final class PricingRequestAdapter {
	private const FIELDS = array(
		'source',
		'operation',
		'page',
		'limit',
		'locale',
		'projection',
		'client_id',
		'channel',
		'request_id',
		'idempotency_key',
		'expected_state_revision',
		'settings',
		'product_changes',
		'preview_digest',
		'confirmation',
		'confirm',
	);

	public static function normalize( mixed $payload ): array {
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new \InvalidArgumentException( 'Pricing request must be a JSON object.' );
		}
		// Labels and unknown extensions are not additional contract dialects.
		// Discard them before hashing/idempotency; only semantic fields participate.
		return array_intersect_key( $payload, array_fill_keys( self::FIELDS, true ) );
	}
}
