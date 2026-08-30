<?php
/**
 * Living, transformed-only Patris product-sync receiver.
 *
 * It accepts the canonical patris.product-sync contract,
 * verifies its deterministic identities, and maintains an ordered snapshot
 * per Patris source.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Digitalogic_Product_Identifier_Resolver')) {
    require_once __DIR__ . '/class-product-identifier-resolver.php';
}
if (!class_exists('Digitalogic_Patris_Feed')) {
    require_once __DIR__ . '/class-patris-feed.php';
}

/**
 * JSON number token retained exactly as it appeared on the wire.
 *
 * Go's canonical contract hashes decimal JSON lexemes before transport. PHP's
 * normal JSON decoder converts them to IEEE-754 values, which can silently
 * change high-precision inputs. Keeping the token lets the receiver reproduce
 * Go encoding/json byte-for-byte while still converting validated values for
 * WordPress storage and WooCommerce writes.
 */
final class Digitalogic_Product_Sync_JSON_Number {
    /** @var string */
    public $value;

    public function __construct($value) {
        $this->value = (string) $value;
    }
}

/**
 * Strict JSON decoder with duplicate-key rejection and exact number tokens.
 */
final class Digitalogic_Product_Sync_JSON_Decoder {
    private const MAX_DEPTH = 32;

    /** @var string */
    private $json;

    /** @var int */
    private $length;

    /** @var int */
    private $position = 0;

    public static function decode($json) {
        $decoder = new self((string) $json);
		$value   = $decoder->parse_value( 0 );
        $decoder->skip_whitespace();
        if ($decoder->position !== $decoder->length) {
            throw new RuntimeException('Unexpected data after the JSON document.');
        }

        return $value;
    }

    private function __construct($json) {
		$this->json   = $json;
        $this->length = strlen($json);
    }

    private function parse_value($depth) {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('The JSON document is nested too deeply.');
        }

        $this->skip_whitespace();
        if ($this->position >= $this->length) {
            throw new RuntimeException('Unexpected end of JSON input.');
        }

        $character = $this->json[$this->position];
        if ('{' === $character) {
            return $this->parse_object($depth + 1);
        }
        if ('[' === $character) {
            return $this->parse_array($depth + 1);
        }
        if ('"' === $character) {
            return $this->parse_string();
        }
        if ('t' === $character && $this->consume_literal('true')) {
            return true;
        }
        if ('f' === $character && $this->consume_literal('false')) {
            return false;
        }
        if ('n' === $character && $this->consume_literal('null')) {
            return null;
        }

        return $this->parse_number();
    }

    private function parse_object($depth) {
        $result = array();
        $this->position++;
        $this->skip_whitespace();
        if ($this->consume_character('}')) {
            return $result;
        }

        while (true) {
            $this->skip_whitespace();
            if ($this->position >= $this->length || '"' !== $this->json[$this->position]) {
                throw new RuntimeException('JSON object keys must be strings.');
            }
            $key = $this->parse_string();
            if (array_key_exists($key, $result)) {
                throw new RuntimeException('Duplicate JSON object key: ' . $key);
            }
            $this->skip_whitespace();
            if (!$this->consume_character(':')) {
                throw new RuntimeException('Expected a colon after a JSON object key.');
            }
            $result[$key] = $this->parse_value($depth);
            $this->skip_whitespace();
            if ($this->consume_character('}')) {
                break;
            }
            if (!$this->consume_character(',')) {
                throw new RuntimeException('Expected a comma between JSON object members.');
            }
        }

        return $result;
    }

    private function parse_array($depth) {
        $result = array();
        $this->position++;
        $this->skip_whitespace();
        if ($this->consume_character(']')) {
            return $result;
        }

        while (true) {
            $result[] = $this->parse_value($depth);
            $this->skip_whitespace();
            if ($this->consume_character(']')) {
                break;
            }
            if (!$this->consume_character(',')) {
                throw new RuntimeException('Expected a comma between JSON array values.');
            }
        }

        return $result;
    }

    private function parse_string() {
        $start = $this->position;
        $this->position++;
        $escaped = false;
        while ($this->position < $this->length) {
            $character = $this->json[$this->position++];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ('\\' === $character) {
                $escaped = true;
                continue;
            }
            if ('"' === $character) {
                $token = substr($this->json, $start, $this->position - $start);
                try {
                    return json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Invalid JSON string.', 0, $exception);
                }
            }
            if (ord($character) < 0x20) {
                throw new RuntimeException('Unescaped control character in JSON string.');
            }
        }

        throw new RuntimeException('Unterminated JSON string.');
    }

    private function parse_number() {
        if (!preg_match(
            '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/',
            $this->json,
            $matches,
            0,
            $this->position
        )) {
            throw new RuntimeException('Invalid JSON value.');
        }
		$token           = $matches[0];
        $this->position += strlen($token);

        return new Digitalogic_Product_Sync_JSON_Number($token);
    }

    private function consume_literal($literal) {
        if (substr($this->json, $this->position, strlen($literal)) !== $literal) {
            return false;
        }
        $this->position += strlen($literal);

        return true;
    }

    private function consume_character($character) {
        if ($this->position >= $this->length || $this->json[$this->position] !== $character) {
            return false;
        }
        $this->position++;

        return true;
    }

    private function skip_whitespace() {
        while ($this->position < $this->length) {
            $character = $this->json[$this->position];
            if (' ' !== $character && "\n" !== $character && "\r" !== $character && "\t" !== $character) {
                break;
            }
            $this->position++;
        }
    }
}

class Digitalogic_Product_Sync_Receiver {
	public const STATE_OPTION  = 'digitalogic_product_sync_state';
    public const CONTRACT_NAME = 'patris.product-sync';
	public const FORMULA_ID    = 'landed_price';

	private const LOCK_NAME                  = 'digitalogic_product_sync';
	private const LOCK_TIMEOUT_SECONDS       = 15;
	private const MAX_BODY_BYTES             = 8388608;
	private const MAX_STATE_BYTES            = 16777216;
	private const MAX_PRODUCTS               = 10000;
	private const MAX_CATEGORIES             = 10000;
	private const MAX_SOURCES                = 16;
	private const MAX_RECENT_EVENTS          = 128;
	private const MAX_RESULT_ERRORS          = 100;
	private const MAX_DEFERRED_PRODUCTS      = self::MAX_PRODUCTS;
	private const MAX_DELIVERY_PRODUCTS_PER_REQUEST = 25;
	private const MAX_CODE_LENGTH            = 191;
    private const MAX_FORMULA_INTEGER_DIGITS = 15;
	private const MAX_FORMULA_SCALE          = 12;
	private const MAX_MARKUP_PERCENT         = '1000';

    private const ENVELOPE_FIELDS = array(
        'schema',
        'event_type',
        'event_id',
        'local_currency',
        'formula_id',
        'source',
        'generated_at',
        'products',
        'categories',
        'excluded_codes',
        'deleted_codes',
        'quarantined_codes',
        'warnings',
    );

    private const PRODUCT_FIELDS = array(
        'product_code',
        'category_code',
        'name',
        'serial',
        'unit',
        'sale_price_source',
        'partner_price_source',
        'purchase_price_source',
        'warehouse_stock',
        'total_stock',
        'minimum_stock',
        'foreign_currency',
        'foreign_price',
        'price_source_amount',
        'price_source_currency',
        'price_source_kind',
        'weight_grams',
        'location',
        'shipping_method_id',
        'shipping_price_per_kg',
        'shipping_price_per_kg_currency',
        'markup_percent',
        'irt_per_cny',
        'price_rounding_digits',
        'price_rounding_mode',
        'pricing_catalog_revision',
        'pricing_catalog_status',
        'currency_effective_date',
        'final_price',
        'source_updated_at',
        'warnings',
        'record_hash',
    );

    private const REQUIRED_PRODUCT_FIELDS = array(
        'product_code',
        'warnings',
        'record_hash',
    );

    private const CATEGORY_FIELDS = array(
        'category_code',
        'name',
        'parent_code',
        'depth',
        'warnings',
        'record_hash',
    );

    private const REQUIRED_CATEGORY_FIELDS = array(
        'category_code',
        'name',
        'parent_code',
        'depth',
        'warnings',
        'record_hash',
    );

    private const PRODUCT_STRING_FIELDS = array(
        'name',
        'serial',
        'unit',
        'location',
        'shipping_method_id',
        'shipping_price_per_kg_currency',
        'price_source_currency',
        'price_source_kind',
        'price_rounding_mode',
        'pricing_catalog_revision',
        'pricing_catalog_status',
        'currency_effective_date',
        'source_updated_at',
    );

    private const PRODUCT_NULLABLE_NUMBER_FIELDS = array(
        'sale_price_source',
        'partner_price_source',
        'purchase_price_source',
        'total_stock',
        'minimum_stock',
        'foreign_price',
        'price_source_amount',
        'weight_grams',
        'shipping_price_per_kg',
        'markup_percent',
        'irt_per_cny',
    );

    private const PRODUCT_DECIMAL_FIELDS = array(
        'foreign_price',
        'partner_price_source',
        'price_source_amount',
        'weight_grams',
        'shipping_price_per_kg',
        'markup_percent',
        'irt_per_cny',
    );

    private const PRODUCT_PRICING_FIELDS = array(
        'shipping_method_id',
        'shipping_price_per_kg',
        'shipping_price_per_kg_currency',
        'price_source_amount',
        'price_source_currency',
        'price_source_kind',
        'markup_percent',
        'irt_per_cny',
        'price_rounding_digits',
        'price_rounding_mode',
        'pricing_catalog_revision',
        'pricing_catalog_status',
        'currency_effective_date',
        'final_price',
    );

    private const FORBIDDEN_RAW_KEYS = array(
        'raw',
        'sharh',
        'sharh1',
        'sharh2',
        'forosh',
        'kharyd',
        'allanbar',
        'priceinfo',
        'shortdesc',
        'feekol',
        'sefaresh',
        'weight',
    );

    private static $instance = null;

    private $lock_depth = 0;

	/** @var int MySQL connection ID that acquired the advisory lock. */
	private $lock_connection_id = 0;

    // phpcs:disable -- New coordinator state follows this legacy receiver's established formatting.
    /**
     * Nesting depth while a caller-owned pricing transaction is active.
     *
     * The normal receiver owns its short state transaction. The pricing
     * coordinator instead needs the receiver state, WooCommerce price writes,
     * and global settings to share one caller-owned transaction.
     *
     * @var int
     */
    private $coordinated_transaction_depth = 0;

    /**
     * Product IDs whose caches must be cleared after the outer commit/rollback.
     *
     * @var array<int,bool>
     */
    private $coordinated_product_ids = array();

    /** @var bool Whether pricing-only SQL batches need bounded cache flushing. */
    private $coordinated_batch_write = false;

	/** Verified product snapshots waiting until every source lock is released. */
	private $materializer_committed_snapshots = array();
    // phpcs:enable

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

	/**
	 * Return the site-scoped source-identity lock shared by sync and bounded
	 * canonical identity edits.
	 *
	 * @param string $table_prefix WordPress database table prefix.
	 * @return string
	 */
	public static function source_identity_lock_name( $table_prefix ) {
		return substr( self::LOCK_NAME . '_' . md5( (string) $table_prefix ), 0, 64 );
	}

	/**
	 * Acquire the shared source/identity writer lock with a bounded wait.
	 *
	 * Canonical identity writers outside this receiver must use this pair so a
	 * source delivery, materialization, legacy feed, and explicit owner edit
	 * cannot interleave their identity checks and writes. Nested calls on this
	 * receiver instance reuse the already-owned database lock.
	 *
	 * @param int $timeout_seconds Maximum database wait in whole seconds.
	 * @return true|WP_Error
	 */
	public function acquire_source_identity_lock( $timeout_seconds = 0 ) {
		return $this->acquire_lock( $timeout_seconds );
	}

	/** Release one nesting level of the shared source/identity writer lock. */
	public function release_source_identity_lock() {
		$this->release_lock();
		$this->dispatch_materializer_product_committed();
	}

	/** Return whether this request currently owns the shared source lock. */
	public function source_identity_lock_is_owned() {
		if ( $this->lock_depth <= 0 || $this->lock_connection_id <= 0 ) {
			return false;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			$this->forget_lost_lock();
			return false;
		}
		$prefix        = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$lock_name     = self::source_identity_lock_name( $prefix );
		$connection_id = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection identity is live session state.
		$owner_query   = $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name );
		$owner_id      = false !== $owner_query ? $wpdb->get_var( $owner_query ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory lock ownership is live session state.
		if (
			$this->lock_connection_id !== (int) $connection_id
			|| $this->lock_connection_id !== (int) $owner_id
		) {
			$this->forget_lost_lock();
			return false;
		}

		return true;
	}

	/**
	 * Emit verified product commits only after the outer source lock is gone.
	 *
	 * Alert adapters are deliberately downstream of this action. A listener
	 * failure can never roll back or reclassify a correctly committed product.
	 */
	public function dispatch_materializer_product_committed() {
		if ( $this->source_identity_lock_is_owned() || empty( $this->materializer_committed_snapshots ) ) {
			return;
		}

		$snapshots                              = $this->materializer_committed_snapshots;
		$this->materializer_committed_snapshots = array();
		try {
			foreach ( $snapshots as $snapshot ) {
				try {
					do_action( 'digitalogic_patris_materializer_product_committed', $snapshot );
				} catch ( Throwable $exception ) {
					$this->log_materializer_listener_failure( $exception, 'patris_materializer_listener_failed' );
				}
			}
		} finally {
			try {
				do_action( 'digitalogic_patris_materializer_product_commits_complete' );
			} catch ( Throwable $exception ) {
				$this->log_materializer_listener_failure( $exception, 'patris_materializer_flush_listener_failed' );
			}
		}
	}

	/**
	 * Best-effort diagnostics for post-commit listeners; never affect delivery.
	 *
	 * @param Throwable $exception Listener failure.
	 * @param string    $event Bounded diagnostic event name.
	 * @return void
	 */
	private function log_materializer_listener_failure( $exception, $event ) {
		if ( ! $exception instanceof Throwable || ! class_exists( 'Digitalogic_Logger' ) ) {
			return;
		}
		try {
			Digitalogic_Logger::instance()->log(
				(string) $event,
				'product_sync',
				null,
				null,
				get_class( $exception ),
				'A product-commit listener failed after the verified product was saved.'
			);
		} catch ( Throwable $logging_exception ) {
			unset( $logging_exception );
		}
	}

	/**
	 * Keep one latest verified snapshot per exact source revision and Code.
	 *
	 * @param array $snapshot Verified public materialization snapshot.
	 * @return void
	 */
	private function queue_materializer_product_committed( $snapshot ) {
		if ( ! is_array( $snapshot ) ) {
			return;
		}
		$key = (string) ( $snapshot['source_id'] ?? '' ) . "\n"
			. (string) ( $snapshot['dataset'] ?? '' ) . "\n"
			. (string) ( $snapshot['source_revision'] ?? '' ) . "\n"
			. (string) ( $snapshot['product_code'] ?? '' );
		$this->materializer_committed_snapshots[ hash( 'sha256', $key ) ] = $snapshot;
	}

    private function __construct() {}

    /**
     * Validate one exact JSON document without locking, persisting, or writing WooCommerce.
     *
     * The returned envelope is the same typed, sparse canonical projection used
     * by receive_json(). Callers must not treat this method as authorization to
     * apply the envelope.
     *
     * @param string $json Request body.
     * @return array|WP_Error
     */
    public function validate_json($json) {
        if (!is_string($json) || '' === trim($json)) {
            return $this->error('digitalogic_product_sync_invalid_json', 'A JSON request body is required.', 400);
        }
        if (strlen($json) > self::MAX_BODY_BYTES) {
            return $this->error('digitalogic_product_sync_payload_too_large', 'The product-sync payload is too large.', 413);
        }

        try {
            $payload = Digitalogic_Product_Sync_JSON_Decoder::decode($json);
        } catch (RuntimeException $exception) {
            return $this->error(
                'digitalogic_product_sync_invalid_json',
                'The product-sync request is not valid JSON.',
                400,
                array('reason' => $exception->getMessage())
            );
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->error('digitalogic_product_sync_invalid_payload', 'The product-sync payload must be an object.', 400);
        }

        $forbidden = $this->find_forbidden_raw_key($payload);
        if (null !== $forbidden) {
            return $this->error(
                'digitalogic_product_sync_raw_key_forbidden',
                'Raw Patris fields are forbidden on the transformed product-sync endpoint.',
                422,
                array('path' => $forbidden)
            );
        }

        return $this->validate_envelope($payload);
    }

    /**
     * Receive an exact JSON document while preserving numeric wire tokens.
     *
     * @param string $json Request body.
     * @return array|WP_Error
     */
    public function receive_json($json) {
        if (!is_string($json) || '' === trim($json)) {
            return $this->error('digitalogic_product_sync_invalid_json', 'A JSON request body is required.', 400);
        }
        if (strlen($json) > self::MAX_BODY_BYTES) {
            return $this->error('digitalogic_product_sync_payload_too_large', 'The product-sync payload is too large.', 413);
        }

        try {
            $payload = Digitalogic_Product_Sync_JSON_Decoder::decode($json);
        } catch (RuntimeException $exception) {
            return $this->error(
                'digitalogic_product_sync_invalid_json',
                'The product-sync request is not valid JSON.',
                400,
                array('reason' => $exception->getMessage())
            );
        }

        return $this->receive($payload);
    }

    /**
     * Validate, order, persist, and apply the living typed envelope.
     *
     * @param array $payload Decoded envelope.
     * @return array|WP_Error
     */
    public function receive($payload) {
        if (!is_array($payload) || array_is_list($payload)) {
            return $this->error('digitalogic_product_sync_invalid_payload', 'The product-sync payload must be an object.', 400);
        }

        $forbidden = $this->find_forbidden_raw_key($payload);
        if (null !== $forbidden) {
            return $this->error(
                'digitalogic_product_sync_raw_key_forbidden',
                'Raw Patris fields are forbidden on the transformed product-sync endpoint.',
                422,
                array('path' => $forbidden)
            );
        }

        $envelope = $this->validate_envelope($payload);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        $locked = $this->acquire_lock();
        if (is_wp_error($locked)) {
            return $locked;
        }

        try {
			$result = $this->receive_locked( $envelope );
        } catch (Throwable $exception) {
			$result = $this->error(
                'digitalogic_product_sync_unexpected_failure',
                'The product-sync event could not be applied.',
                500,
                array('exception' => get_class($exception))
            );
        } finally {
            $this->release_lock();
			$this->dispatch_materializer_product_committed();
        }

		return $result;
    }

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    /**
     * Return the stored state for diagnostics and tests.
     *
     * @return array
     */
    public function get_state() {
        return $this->load_state();
    }

    /**
     * Return a bounded, nonsecret operational summary.
     *
     * @return array
     */
    public function get_status() {
        $state = $this->get_state();
        $sources = array();
        $totals = array(
            'stored_products' => 0,
            'stored_categories' => 0,
            'excluded_codes' => 0,
            'applied_products' => 0,
            'pending_products' => 0,
            'deferred_products' => 0,
        );
        ksort($state['sources'], SORT_STRING);

        foreach ($state['sources'] as $source_state) {
            if (!is_array($source_state)) {
                continue;
            }
            $summary = $this->source_status($source_state);
            $sources[] = $summary;
            foreach ($totals as $field => $_unused) {
                $totals[$field] += $summary[$field];
            }
        }

        return array(
            'source_count' => count($sources),
            'totals' => $totals,
            'sources' => $sources,
        );
    }

    /**
     * Retry only durable delivery work, without changing source ordering.
     *
     * Applied records are never replayed. Deferred reconciliation and any
     * transient pending writes are attempted under the receiver lock.
     *
     * @param string|null $source_id Optional exact source id.
     * @param string|null $dataset Optional exact source dataset.
     * @return array|WP_Error
     */
    public function reconcile($source_id = null, $dataset = null) {
        if ((null === $source_id) !== (null === $dataset)) {
            return $this->error(
                'digitalogic_product_sync_reconcile_scope_invalid',
                'Source id and dataset must be provided together.',
                400
            );
        }

        $locked = $this->acquire_lock();
        if (is_wp_error($locked)) {
            return $locked;
        }

        try {
            $state = $this->load_state();
            $selected = array();
            if (null !== $source_id) {
                $source_key = $this->source_key((string) $source_id, (string) $dataset);
                if (!isset($state['sources'][$source_key]) || !is_array($state['sources'][$source_key])) {
                    $result = $this->error(
                        'digitalogic_product_sync_source_not_found',
                        'The requested product-sync source was not found.',
                        404
                    );
					return $result;
                }
                $selected[] = $source_key;
            } else {
                $selected = array_keys($state['sources']);
                sort($selected, SORT_STRING);
            }

            $before = $this->state_digest($state);
            $sources = array();
            $pending_total = 0;
            $deferred_total = 0;
            foreach ($selected as $source_key) {
                $woo = $this->drain_delivery_products($state['sources'][$source_key], true, true);
                $source_result = $this->source_status($state['sources'][$source_key]);
                $source_result['woocommerce'] = $woo;
                $sources[] = $source_result;
                $pending_total += $source_result['pending_products'];
                $deferred_total += $source_result['deferred_products'];
            }

            if (!hash_equals($before, $this->state_digest($state))) {
                $stored = $this->persist_and_read_back($state);
                if (is_wp_error($stored)) {
					$result = $stored;
					return $result;
                }
            }

            $result = array(
                'status' => $pending_total > 0 ? 'partially_applied' : 'reconciled',
                'retryable' => $pending_total > 0,
                'pending_products' => $pending_total,
                'deferred_products' => $deferred_total,
                'source_count' => count($sources),
                'sources' => $sources,
                'source_order_unchanged' => true,
                'persistence_verified' => true,
            );
        } catch (Throwable $exception) {
            $result = $this->error(
                'digitalogic_product_sync_reconcile_failed',
                'Deferred product reconciliation failed.',
                500,
                array('exception' => get_class($exception))
            );
        } finally {
            $this->release_lock();
			$this->dispatch_materializer_product_committed();
        }

		return $result;
    }

    private function load_state() {
        $state = get_option(self::STATE_OPTION, array());
        if (!is_array($state)) {
            return array('sources' => array());
        }
        $state['sources'] = isset($state['sources']) && is_array($state['sources']) ? $state['sources'] : array();
        foreach ($state['sources'] as &$source_state) {
            if (!is_array($source_state)) {
                $source_state = array();
            }
            $source_state['categories'] = is_array($source_state['categories'] ?? null)
                ? $source_state['categories']
                : array();
            $source_state['excluded_codes'] = is_array($source_state['excluded_codes'] ?? null)
                ? $source_state['excluded_codes']
                : array();
            $source_state['deferred_products'] = is_array($source_state['deferred_products'] ?? null)
                ? array_slice($source_state['deferred_products'], 0, self::MAX_DEFERRED_PRODUCTS, true)
                : array();
        }
        unset($source_state);

        return $state;
    }

    public function get_source_state($source_id, $dataset) {
        $state = $this->get_state();
        $key = $this->source_key((string) $source_id, (string) $dataset);

        return isset($state['sources'][$key]) && is_array($state['sources'][$key])
            ? $state['sources'][$key]
            : array();
    }
    // phpcs:enable

    // phpcs:disable -- Coordinator methods follow this legacy receiver's established formatting.
    /**
     * Reprice the stored Patris snapshot inside a caller-owned DB transaction.
     *
     * This is the only local repricing path. It reuses the receiver's exact
     * decimal landed-price evaluator, canonical record/source identities,
     * WooCommerce writer, durable delivery sets, and post-save readback.
     *
     * @param array $settings         Complete canonical global settings.
     * @param array $profit_overrides Optional Product Code => percentage/null.
     * @param array $scope_codes      Optional exact Product Codes to reprice.
     * @param string|null $previous_catalog_revision Catalog revision before the caller's atomic write.
     * @return array|WP_Error
     */
    public function reprice_pricing_state($settings, $profit_overrides = array(), $scope_codes = array(), $previous_catalog_revision = null) {
        if (
            null !== $previous_catalog_revision
            && (
                !is_string($previous_catalog_revision)
                || 1 !== preg_match('/\Asha256:[a-f0-9]{64}\z/D', $previous_catalog_revision)
            )
        ) {
            return $this->error(
                'digitalogic_pricing_previous_catalog_revision_invalid',
                'Previous shipping-catalog revision is invalid.',
                400
            );
        }
        $normalized = $this->normalize_coordinated_pricing_inputs(
            $settings,
            $profit_overrides,
            $scope_codes
        );
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        $locked = $this->acquire_lock();
        if (is_wp_error($locked)) {
            return $locked;
        }

        ++$this->coordinated_transaction_depth;
        try {
            return $this->reprice_pricing_state_locked(
                $normalized['settings'],
                $normalized['profit_overrides'],
                $normalized['scope_codes'],
                $previous_catalog_revision
            );
        } catch (Throwable $exception) {
            return $this->error(
                'digitalogic_pricing_reconciliation_failed',
                'هماهنگ‌سازی اتمیک قیمت‌های Patris انجام نشد.',
                500,
                array('exception' => get_class($exception))
            );
        } finally {
            --$this->coordinated_transaction_depth;
            $this->release_lock();
        }
    }

    /**
     * Hold the receiver advisory lock across a caller-owned transaction.
     *
     * The callback must not return until its database COMMIT or ROLLBACK has
     * completed. Nested receiver calls reuse the same in-process lock depth.
     *
     * @param callable $callback Transaction owner.
     * @return mixed|WP_Error
     */
    public function with_coordinated_pricing_lock($callback) {
        if (!is_callable($callback)) {
            return $this->error(
                'digitalogic_pricing_lock_callback_invalid',
                'Pricing lock callback is invalid.',
                500
            );
        }
        $locked = $this->acquire_lock();
        if (is_wp_error($locked)) {
            return $locked;
        }

        try {
            return call_user_func($callback);
        } finally {
            $this->release_lock();
        }
    }

    /** Return a durable, bounded cache plan before the transaction owner commits. */
    public function coordinated_pricing_cache_plan() {
        return array(
            'product_ids' => array_values(array_map('intval', array_keys($this->coordinated_product_ids))),
            'batch_write' => (bool) $this->coordinated_batch_write,
        );
    }

    /**
     * Clear receiver and WooCommerce caches after the caller commits/rolls back.
     *
     * An explicit plan survives a process loss after COMMIT. It is merged with
     * any request-local plan so recovery cannot omit a product whose SQL write
     * already landed.
     *
     * @param array $persisted_plan Optional transaction marker cache plan.
     * @return void
     */
    public function flush_coordinated_pricing_caches($persisted_plan = array()) {
        $this->invalidate_state_cache();
        $persisted_ids = is_array($persisted_plan['product_ids'] ?? null)
            ? $persisted_plan['product_ids']
            : array();
        $product_ids = array_merge(array_keys($this->coordinated_product_ids), $persisted_ids);
        $product_ids = array_values(
            array_unique(
                array_filter(
                    array_map('absint', array_slice($product_ids, 0, self::MAX_PRODUCTS)),
                    static function ($product_id) {
                        return $product_id > 0;
                    }
                )
            )
        );
        $this->coordinated_product_ids = array();
        $batch_write = $this->coordinated_batch_write || !empty($persisted_plan['batch_write']);
        $this->coordinated_batch_write = false;
        if ($batch_write) {
            if (!empty($product_ids) && function_exists('wp_cache_delete_multiple')) {
                $post_results = wp_cache_delete_multiple($product_ids, 'posts');
                $meta_results = wp_cache_delete_multiple($product_ids, 'post_meta');
                foreach ($product_ids as $product_id) {
                    $post_deleted = is_array($post_results)
                        && !empty($post_results[$product_id]);
                    $meta_deleted = is_array($meta_results)
                        && !empty($meta_results[$product_id]);
                    if ((!$post_deleted || !$meta_deleted) && function_exists('clean_post_cache')) {
                        // A persistent cache may accept a multi-delete while
                        // failing individual keys. Fall back only for those
                        // products so the committed canonical metadata cannot
                        // remain stale while Woo's public price is current.
                        clean_post_cache($product_id);
                    }
                }
            } else {
                foreach ($product_ids as $product_id) {
                    if (function_exists('clean_post_cache')) {
                        clean_post_cache($product_id);
                    }
                }
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients();
            }
            if (
                class_exists('WC_Cache_Helper')
                && is_callable(array('WC_Cache_Helper', 'invalidate_cache_group'))
            ) {
                // WC_Product stores raw object metadata in the plural
                // "products" cache group. The singular product_* prefixes
                // cover per-object projections, but cannot invalidate the
                // shared raw-meta cache populated by wc_get_products().
                WC_Cache_Helper::invalidate_cache_group('products');
            }
            return;
        }
        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            if (function_exists('clean_post_cache')) {
                clean_post_cache($product_id);
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }
            if (
                class_exists('WC_Cache_Helper')
                && is_callable(array('WC_Cache_Helper', 'invalidate_cache_group'))
            ) {
                WC_Cache_Helper::invalidate_cache_group('product_' . $product_id);
            }
            if (function_exists('clean_object_term_cache')) {
                clean_object_term_cache($product_id, 'product');
            }
        }
    }

    /**
     * Publish a committed nonsecret reconciliation summary.
     *
     * @param array $result Repricing result.
     * @return void
     */
    public function publish_coordinated_pricing_result($result) {
        if (!is_array($result)) {
            return;
        }
        try {
            do_action('digitalogic_pricing_reconciled', $result);
        } catch (Throwable $exception) {
            unset($exception);
        }
        try {
            Digitalogic_Logger::instance()->log(
                'pricing_reconciled',
                'patris_feed',
                null,
                null,
                array(
                    'source_count' => (int) ($result['source_count'] ?? 0),
                    'updated_products' => (int) ($result['updated_products'] ?? 0),
                    'deferred_missing' => (int) ($result['deferred_missing'] ?? 0),
                ),
                'Patris-managed WooCommerce prices reconciled atomically.'
            );
        } catch (Throwable $exception) {
            unset($exception);
        }
    }

    /**
     * Validate coordinator inputs without binary floating-point conversion.
     *
     * @param mixed $settings         Complete settings.
     * @param mixed $profit_overrides Product overrides.
     * @param mixed $scope_codes      Product scope.
     * @return array|WP_Error
     */
    private function normalize_coordinated_pricing_inputs($settings, $profit_overrides, $scope_codes) {
        $required = array(
            'dollar_price',
            'yuan_price',
            'effective_date',
            'profit_margin_percent',
            'price_rounding_digits',
            'price_rounding_mode',
        );
        if (
            !is_array($settings)
            || array_is_list($settings)
            || !empty(array_diff($required, array_keys($settings)))
            || !empty(array_diff(array_keys($settings), $required))
        ) {
            return $this->error(
                'digitalogic_pricing_settings_invalid',
                'نرخ‌های ارز، تاریخ مؤثر، حاشیه سود و سیاست گردکردن کامل لازم است.',
                400
            );
        }

        $dollar = $this->formula_decimal_parts($settings['dollar_price']);
        $yuan = $this->formula_decimal_parts($settings['yuan_price']);
        $profit = $this->formula_decimal_parts($settings['profit_margin_percent']);
        if (
            isset($dollar['error'])
            || isset($yuan['error'])
            || isset($profit['error'])
            || $this->decimal_compare($dollar, $this->formula_decimal_parts('0')) <= 0
            || $this->decimal_compare($yuan, $this->formula_decimal_parts('0')) <= 0
            || $this->decimal_compare($profit, $this->formula_decimal_parts(self::MAX_MARKUP_PERCENT)) > 0
        ) {
            return $this->error(
                'digitalogic_pricing_settings_decimal_invalid',
                'نرخ‌های ارز و حاشیه سود باید اعشار ده‌دهی معتبر و در محدوده مجاز باشند.',
                400
            );
        }
        if (
            !is_string($settings['effective_date'])
            || 1 !== preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $settings['effective_date'])
        ) {
            return $this->error(
                'digitalogic_pricing_effective_date_invalid',
                'تاریخ مؤثر قیمت باید به‌شکل YYYY-MM-DD باشد.',
                400
            );
        }
        if (
            !$this->is_nonnegative_integer($settings['price_rounding_digits'])
            || (int) $this->number_to_storage($settings['price_rounding_digits']) > 9
            || Digitalogic_Shipping_Method_Service::ROUNDING_MODE !== $settings['price_rounding_mode']
        ) {
            return $this->error(
                'digitalogic_pricing_rounding_invalid',
                'تعداد ارقام گردکردن باید عدد صحیح صفر تا ۹ و روش آن nearest_half_up باشد.',
                400
            );
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $settings['effective_date']);
        $date_errors = DateTimeImmutable::getLastErrors();
        if (
            false === $date
            || (is_array($date_errors) && ($date_errors['warning_count'] > 0 || $date_errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $settings['effective_date']
        ) {
            return $this->error(
                'digitalogic_pricing_effective_date_invalid',
                'تاریخ مؤثر قیمت معتبر نیست.',
                400
            );
        }

        if (!is_array($profit_overrides) || !empty($profit_overrides)) {
            return $this->error(
                'digitalogic_pricing_product_profit_forbidden',
                'حاشیه سود یک مقدار مشترک اکوسیستم است؛ حاشیه سود اختصاصی کالا پشتیبانی نمی‌شود.',
                409
            );
        }
        $normalized_overrides = array();

        if (!is_array($scope_codes) || !array_is_list($scope_codes)) {
            return $this->field_error('scope_codes', 'must be an array');
        }
        $normalized_scope = array();
        foreach ($scope_codes as $product_code) {
            if (
                !is_string($product_code)
                || '' === trim($product_code)
                || trim($product_code) !== $product_code
                || strlen($product_code) > self::MAX_CODE_LENGTH
            ) {
                return $this->field_error('scope_codes', 'contains an invalid Product Code');
            }
            $normalized_scope[$product_code] = true;
        }
        ksort($normalized_scope, SORT_STRING);

        return array(
            'settings' => array(
                'dollar_price' => $this->decimal_parts_to_string($dollar),
                'yuan_price' => $this->decimal_parts_to_string($yuan),
                'effective_date' => $settings['effective_date'],
                'profit_margin_percent' => $this->decimal_parts_to_string($profit),
                'price_rounding_digits' => (int) $this->number_to_storage($settings['price_rounding_digits']),
                'price_rounding_mode' => Digitalogic_Shipping_Method_Service::ROUNDING_MODE,
            ),
            'profit_overrides' => $normalized_overrides,
            'scope_codes' => $normalized_scope,
        );
    }

    /**
     * Reprice current source state while both receiver lock and DB transaction are held.
     *
     * @param array $settings         Canonical settings.
     * @param array $profit_overrides Canonical Product Code overrides.
     * @param array $scope_codes      Product Code set, or empty for all.
     * @param string|null $previous_catalog_revision Catalog revision before the atomic write.
     * @return array|WP_Error
     */
    private function reprice_pricing_state_locked($settings, $profit_overrides, $scope_codes, $previous_catalog_revision) {
        $state = $this->load_state();
        if (empty($state['sources'])) {
            return $this->error(
                'digitalogic_pricing_source_state_required',
                'پیش از تغییر تنظیمات قیمت، یک snapshot معتبر Patris لازم است.',
                409
            );
        }

        $previous_source_identities = $this->source_identity_state($state);
        $before_state = $this->state_digest($state);
        $pricing_revision = $this->hash_identity(
            $this->encode_go_json(
                array(
                    'schema' => 'digitalogic.pricing-coordinator',
                    'dollar_price' => $settings['dollar_price'],
                    'yuan_price' => $settings['yuan_price'],
                    'effective_date' => $settings['effective_date'],
                    'profit_margin_percent' => $settings['profit_margin_percent'],
                    'price_rounding_digits' => $settings['price_rounding_digits'],
                    'price_rounding_mode' => $settings['price_rounding_mode'],
                )
            )
        );
        $catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
        if (is_wp_error($catalog)) {
            return $catalog;
        }
        $catalog_revision = $this->coordinated_pricing_catalog_revision($settings, $catalog);
        if (is_wp_error($catalog_revision)) {
            return $catalog_revision;
        }
        $bulk_codes = array();
        foreach ($state['sources'] as $candidate_source) {
            if (
                !is_array($candidate_source)
                || 'IRT' !== (string) ($candidate_source['local_currency'] ?? '')
                || self::FORMULA_ID !== (string) ($candidate_source['formula_id'] ?? '')
            ) {
                continue;
            }
            $candidate_products = is_array($candidate_source['products'] ?? null)
                ? $candidate_source['products']
                : array();
            foreach ($candidate_products as $candidate_key => $candidate_product) {
                $candidate_code = $this->delivery_product_code($candidate_products, $candidate_key);
                if (
                    null !== $candidate_code
                    && (empty($scope_codes) || isset($scope_codes[$candidate_code]))
                ) {
                    $bulk_codes[$candidate_code] = $candidate_code;
                }
            }
        }
        $resolution_cache = Digitalogic_Product_Identifier_Resolver::instance()->resolve_patris_codes(
            array_values($bulk_codes)
        );
        $resolved_product_ids = array();
        foreach ($resolution_cache as $resolved_product) {
            if (!is_wp_error($resolved_product) && !empty($resolved_product['woocommerce_id'])) {
                $resolved_product_ids[(int) $resolved_product['woocommerce_id']] = true;
            }
        }
        if (!empty($resolved_product_ids)) {
            $resolved_product_ids = array_keys($resolved_product_ids);
            if (function_exists('_prime_post_caches')) {
                // wc_get_product() needs the post, pricing metadata, and product
                // type terms. Priming only postmeta left a production-sized rate
                // change doing one taxonomy/object load per product, turning one
                // bounded SQL batch into minutes of PHP/DB round trips.
                _prime_post_caches($resolved_product_ids, true, true);
            } elseif (function_exists('update_meta_cache')) {
                update_meta_cache('post', $resolved_product_ids);
            }
        }
        $found_scope = array();
        $product_code_sources = array();
        $pricing_sources = array();
        $initially_verified_codes = array();
        $changed_total = 0;

        foreach ($state['sources'] as $source_key => &$source_state) {
            if (!is_array($source_state)) {
                continue;
            }
            $products = is_array($source_state['products'] ?? null) ? $source_state['products'] : array();
            if (empty($products)) {
                if (empty($scope_codes)) {
                    $pricing_sources[$source_key] = array(
                        'source' => is_array($source_state['source'] ?? null)
                            ? $source_state['source']
                            : array(),
                        'target_codes' => array(),
                        'event_id' => (string) ($source_state['last_event_id'] ?? ''),
                    );
                }
                continue;
            }
            if (
                'IRT' !== (string) ($source_state['local_currency'] ?? '')
                || self::FORMULA_ID !== (string) ($source_state['formula_id'] ?? '')
            ) {
                continue;
            }

            $changed_products = array();
            $target_codes = array();
            foreach ($products as $code_key => $product) {
                $product_code = $this->delivery_product_code($products, $code_key);
                if (null === $product_code || (!empty($scope_codes) && !isset($scope_codes[$product_code]))) {
                    continue;
                }
                if (
                    isset($product_code_sources[$product_code])
                    && $product_code_sources[$product_code] !== (string) $source_key
                ) {
                    return $this->error(
                        'digitalogic_pricing_product_source_ambiguous',
                        'یک کد کالا در بیش از یک منبع فعال قیمت‌گذاری وجود دارد؛ هیچ تغییری ثبت نشد.',
                        409,
                        array('product_code' => $product_code)
                    );
                }
                $product_code_sources[$product_code] = (string) $source_key;
                $found_scope[$product_code] = true;
                $target_codes[$product_code] = true;
                $markup = $this->coordinated_markup_percent(
                    $product_code,
                    $product,
                    $settings['profit_margin_percent'],
                    $profit_overrides,
                    $resolution_cache
                );
                if (is_wp_error($markup)) {
                    return $markup;
                }
                $repriced = $this->coordinated_product_record(
                    $product,
                    $settings,
                    $catalog,
                    $catalog_revision,
                    $markup,
                    $previous_catalog_revision
                );
                if (is_wp_error($repriced)) {
                    return $repriced;
                }
                $products[$code_key] = $repriced;
                if (
                    !isset($product['record_hash'])
                    || !hash_equals((string) $product['record_hash'], (string) $repriced['record_hash'])
                ) {
                    $changed_products[] = $repriced;
                    ++$changed_total;
                }
            }
            if (empty($target_codes)) {
                continue;
            }

            $source = is_array($source_state['source'] ?? null) ? $source_state['source'] : array();
            $categories = is_array($source_state['categories'] ?? null) ? $source_state['categories'] : array();
            $excluded_codes = is_array($source_state['excluded_codes'] ?? null) ? $source_state['excluded_codes'] : array();
            $quarantined_codes = is_array($source_state['quarantined_codes'] ?? null) ? $source_state['quarantined_codes'] : array();
            $source['revision'] = $this->source_revision(
                $products,
                $categories,
                $excluded_codes,
                $quarantined_codes
            );
            $generated_at = (string) ($source_state['generated_at'] ?? '');
            $event_id = $this->hash_identity(
                $this->encode_go_json(
                    array(
                        'schema' => 'digitalogic.pricing-reconcile',
                        'source' => $source,
                        'generated_at' => $generated_at,
                        'pricing_revision' => $pricing_revision,
                    )
                )
            );
            $delivery = $this->build_delivery_state(
                $products,
                $changed_products,
                array('event_id' => $event_id),
                $source_state
            );

            foreach (array_keys($target_codes) as $product_code_key) {
                // PHP converts numeric-string array keys to integers. Product
                // Codes remain text identifiers at every integration boundary.
                $product_code = (string) $product_code_key;
                $product = $products[$product_code_key];
                $resolved = $this->coordinated_resolution($product_code, $resolution_cache);
                if (is_wp_error($resolved)) {
                    $reason = $this->terminal_resolution_reason($resolved->get_error_code());
                    if ('missing' === $reason) {
                        if (isset($delivery['pending_products'][$product_code])) {
                            $delivery['pending_products'][$product_code]['pricing_only'] = true;
                        }
                        if (isset($delivery['deferred_products'][$product_code])) {
                            $delivery['deferred_products'][$product_code]['pricing_only'] = true;
                        }
                        continue;
                    }
                    return $this->error(
                        'digitalogic_pricing_product_identity_ambiguous',
                        'هویت یکی از کالاهای Patris در ووکامرس یکتا نیست؛ هیچ تغییری ثبت نشد.',
                        409,
                        array('product_code' => $product_code, 'code' => $resolved->get_error_code())
                    );
                }
                $woocommerce_id = (int) $resolved['woocommerce_id'];
                if ($this->coordinated_price_readback_matches($woocommerce_id, $product)) {
                    $initially_verified_codes[$source_key][(string) $product_code] = true;
                    continue;
                }
                $this->coordinated_product_ids[$woocommerce_id] = true;
                unset($delivery['applied_products'][$product_code], $delivery['deferred_products'][$product_code]);
                $delivery['pending_products'][$product_code] = array(
                    'product_code' => $product_code,
                    'record_hash' => $product['record_hash'],
                    'queued_event_id' => $event_id,
                    'attempts' => 0,
                    'force_apply' => true,
                    'pricing_only' => true,
                );
            }

            $source_state['source'] = $source;
            $source_state['products'] = $products;
            $source_state['applied_products'] = $delivery['applied_products'];
            $source_state['pending_products'] = $delivery['pending_products'];
            $source_state['deferred_products'] = $delivery['deferred_products'];
            $pricing_sources[$source_key] = array(
                'source' => $source,
                'target_codes' => array_keys($target_codes),
                'event_id' => $event_id,
            );
        }
        unset($source_state);

        if (empty($pricing_sources)) {
            return $this->error(
                'digitalogic_pricing_active_source_required',
                'هیچ منبع فعال landed_price برای هماهنگ‌سازی قیمت پیدا نشد.',
                409
            );
        }
        if (!empty($scope_codes)) {
            $missing_scope = array_values(array_diff(array_keys($scope_codes), array_keys($found_scope)));
            if (!empty($missing_scope)) {
                return $this->error(
                    'digitalogic_pricing_product_not_in_source',
                    'کد کالا در snapshot فعلی Patris وجود ندارد.',
                    409,
                    array('product_codes' => $missing_scope)
                );
            }
        }

        $updated_total = 0;
        $already_total = 0;
        $missing_total = 0;
        $source_results = array();
        $pricing_warnings = array();
        foreach ($pricing_sources as $source_key => $context) {
            $woo = $this->drain_delivery_products(
                $state['sources'][$source_key],
                true,
                true,
                $resolution_cache
            );
            $deferred = $this->deferred_summary($state['sources'][$source_key]['deferred_products'] ?? array());
            $pending_count = count($state['sources'][$source_key]['pending_products'] ?? array());
            if ('digitalogic_pricing_delivery_readback_failed' === ($woo['fatal_error_code'] ?? '')) {
                return $this->error(
                    'digitalogic_pricing_delivery_readback_failed',
                    'قیمت نهایی کالا پس از ذخیره با مقدار محاسبه‌شده یکسان نیست.',
                    502
                );
            }
            if ($pending_count > 0 || (int) $deferred['ambiguous'] > 0) {
                return $this->error(
                    'digitalogic_pricing_delivery_incomplete',
                    'خواندن مجدد قیمت‌های ووکامرس کامل نشد؛ تراکنش قیمت بازگردانده می‌شود.',
                    502,
                    array(
                        'source' => $context['source'],
                        'pending_products' => $pending_count,
                        'deferred_missing' => (int) $deferred['missing'],
                        'deferred_ambiguous' => (int) $deferred['ambiguous'],
                        'woocommerce' => $woo,
                    )
                );
            }
            $verified_codes = array_fill_keys(
                array_map('strval', (array) ($woo['verified_product_codes'] ?? array())),
                true
            );
            foreach (array_keys($initially_verified_codes[$source_key] ?? array()) as $verified_code) {
                $verified_codes[(string) $verified_code] = true;
            }
            foreach ((array) ($woo['pricing_warnings'] ?? array()) as $batch_warning) {
                if (is_array($batch_warning)) {
                    $pricing_warnings[] = $batch_warning;
                }
            }
            foreach ($context['target_codes'] as $product_code) {
                if (isset($verified_codes[(string) $product_code])) {
                    continue;
                }
                $resolved = $this->coordinated_resolution($product_code, $resolution_cache);
                if (is_wp_error($resolved)) {
                    if ('missing' === $this->terminal_resolution_reason($resolved->get_error_code())) {
                        continue;
                    }
                    return $this->error(
                        'digitalogic_pricing_delivery_readback_failed',
                        'هویت کالا هنگام خواندن مجدد تغییر کرد؛ تراکنش بازگردانده می‌شود.',
                        502,
                        array('product_code' => $product_code)
                    );
                }
                $product = $state['sources'][$source_key]['products'][$product_code];
                if (!$this->coordinated_price_readback_matches((int) $resolved['woocommerce_id'], $product)) {
                    return $this->error(
                        'digitalogic_pricing_delivery_readback_failed',
                        'قیمت نهایی کالا پس از ذخیره با مقدار محاسبه‌شده یکسان نیست.',
                        502,
                        array(
                            'product_code' => $product_code,
                            'woocommerce_id' => (int) $resolved['woocommerce_id'],
                        )
                    );
                }
                if (!array_key_exists('final_price', $product)) {
                    $woo_product = wc_get_product((int) $resolved['woocommerce_id']);
                    if (
                        $woo_product
                        && 'canonical_missing_preserved' === (string) $woo_product->get_meta(
                            Digitalogic_Patris_Price_Policy::STATUS_META,
                            true
                        )
                    ) {
                        $pricing_warnings[] = array(
                            'code' => 'canonical_missing_preserved',
                            'message' => Digitalogic_Patris_Price_Policy::MISSING_WEIGHT_WARNING,
                            'product_code' => $product_code,
                            'woocommerce_id' => (int) $resolved['woocommerce_id'],
                        );
                    }
                }
            }
            $updated_total += (int) $woo['updated'];
            $already_total += (int) $woo['already_applied'];
            $missing_total += (int) $deferred['missing'];
            $source_results[] = array(
                'source' => $context['source'],
                'event_id' => $context['event_id'],
                'target_products' => count($context['target_codes']),
                'woocommerce' => $woo,
                'deferred_reconciliation' => $deferred,
            );
        }

        if (!hash_equals($before_state, $this->state_digest($state))) {
            $stored = $this->persist_and_read_back($state);
            if (is_wp_error($stored)) {
                return $stored;
            }
        }

        return array(
            'schema' => 'digitalogic.pricing-reconcile-result',
            'status' => 'reconciled',
            'pricing_revision' => $pricing_revision,
            'source_count' => count($source_results),
            'changed_products' => $changed_total,
            'updated_products' => $updated_total,
            'already_current_products' => $already_total,
            'deferred_missing' => $missing_total,
            'deferred_ambiguous' => 0,
            'pending_products' => 0,
            'warning_count' => count($pricing_warnings),
            'warnings' => $pricing_warnings,
            'sources' => $source_results,
            'source_state_before' => $previous_source_identities,
            'source_state_after' => $this->source_identity_state($state),
        );
    }

    /**
     * Resolve effective percentage markup for one stored product.
     *
     * @return string|null|WP_Error
     */
    private function coordinated_markup_percent(
        $product_code,
        $product,
        $profit_margin,
        $profit_overrides,
        &$resolution_cache
    ) {
        unset($product_code, $product, $profit_overrides, $resolution_cache);

        return $profit_margin;
    }

    /**
     * Build one canonical repriced stored product.
     *
     * @return array|WP_Error
     */
    private function coordinated_product_record($product, $settings, $catalog, $catalog_revision, $markup_percent, $previous_catalog_revision = null) {
        if (!is_array($product)) {
            return $this->field_error('products', 'contains invalid stored data');
        }
        $path = 'products.' . ($product['product_code'] ?? '');
        $price_source_kind = (string) ($product['price_source_kind'] ?? '');
        if ('' === $price_source_kind) {
            $calculated = $this->evaluate_final_price_formula($product, $path);
            if (is_wp_error($calculated)) {
                return $calculated;
            }
            unset($product['final_price']);
            $product['record_hash'] = $this->record_hash_from_storage($product);

            return $product;
        }
        if (!in_array($price_source_kind, array('foreign_price', 'partner_price', 'sale_price_direct'), true)) {
            return $this->field_error($path . '.price_source_kind', 'contains an unsupported selected price source');
        }
        if ('sale_price_direct' === $price_source_kind) {
            $calculated = $this->evaluate_final_price_formula($product, $path);
            if (is_wp_error($calculated)) {
                return $calculated;
            }
            if (empty($calculated['available'])) {
                unset($product['final_price']);
            } else {
                $product['final_price'] = $calculated['value'];
            }
            $validated = $this->validate_final_price_formula($product, $path, true);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $product['record_hash'] = $this->record_hash_from_storage($product);

            return $product;
        }
        if (!isset($product['pricing_catalog_revision'])) {
            return $this->error(
                'digitalogic_pricing_catalog_provenance_required',
                'هویت کاتالوگ قیمت قبلی کالا کامل نیست؛ هیچ تغییری ثبت نشد.',
                409,
                array('product_code' => (string) ($product['product_code'] ?? ''))
            );
        }
        if (
            null === $previous_catalog_revision
            || 1 !== preg_match('/\Asha256:[a-f0-9]{64}\z/D', (string) $product['pricing_catalog_revision'])
        ) {
            return $this->error(
                'digitalogic_pricing_catalog_provenance_invalid',
                'هویت کاتالوگ قیمت کالا معتبر نیست؛ هیچ تغییری ثبت نشد.',
                409,
                array('product_code' => (string) ($product['product_code'] ?? ''))
            );
        }
        // Patris is a live source: a product may legitimately have been replaced
        // by a newer coherent source revision after this website transaction read
        // its settings. Rebase that product onto the current locked shipping
        // catalog instead of requiring a quiet catalog window. Identity remains
        // fail-closed below: the exact Product Code must resolve uniquely and its
        // stored shipping method must still exist and satisfy the supported
        // pricing contract before any WooCommerce write is allowed.
        $shipping = $this->coordinated_shipping_method($product, $catalog);
        if (is_wp_error($shipping)) {
            return $shipping;
        }
        $product['markup_percent'] = $markup_percent;
        $product['price_rounding_digits'] = $settings['price_rounding_digits'];
        $product['price_rounding_mode'] = $settings['price_rounding_mode'];
        $product['pricing_catalog_revision'] = $catalog_revision;
        $product['shipping_price_per_kg'] = $shipping['price_per_kg'];
        $product['shipping_price_per_kg_currency'] = $shipping['currency'];
        if ('foreign_price' === $price_source_kind) {
            $product['irt_per_cny'] = $settings['yuan_price'];
            $product['currency_effective_date'] = $settings['effective_date'];
        } else {
            unset($product['irt_per_cny'], $product['currency_effective_date']);
        }

        $calculated = $this->evaluate_final_price_formula($product, $path);
        if (is_wp_error($calculated)) {
            return $calculated;
        }
        if (empty($calculated['available'])) {
            unset($product['final_price']);
        } else {
            $product['final_price'] = $calculated['value'];
        }
        $validated = $this->validate_final_price_formula($product, $path, true);
        if (is_wp_error($validated)) {
            return $validated;
        }
        $product['record_hash'] = $this->record_hash_from_storage($product);

        return $product;
    }

    /**
     * Resolve the product's exact fixed-rate shipping dependency.
     *
     * The landed-price formula has no tier/minimum/volumetric implementation.
     * Any such method therefore fails closed instead of silently applying the
     * flat-rate branch to a variable shipping contract.
     *
     * @param array $product Stored product record.
     * @param array $catalog Current integration catalog.
     * @return array|WP_Error
     */
    private function coordinated_shipping_method($product, $catalog) {
        $product_code = (string) ($product['product_code'] ?? '');
        $method_id = isset($product['shipping_method_id']) && is_string($product['shipping_method_id'])
            ? $product['shipping_method_id']
            : '';
        if ('' === $method_id) {
            return $this->error(
                'digitalogic_pricing_shipping_method_required',
                'روش حمل قطعی کالا برای بازتولید قیمت لازم است؛ هیچ تغییری ثبت نشد.',
                409,
                array('product_code' => $product_code)
            );
        }

        $selected = null;
        foreach ((array) ($catalog['shipping_methods'] ?? array()) as $method) {
            if (is_array($method) && $method_id === (string) ($method['id'] ?? '')) {
                $selected = $method;
                break;
            }
        }
        if (!is_array($selected) || empty($selected['enabled'])) {
            return $this->error(
                'digitalogic_pricing_shipping_method_unavailable',
                'روش حمل کالا در کاتالوگ فعال حمل موجود نیست؛ هیچ تغییری ثبت نشد.',
                409,
                array(
                    'product_code' => $product_code,
                    'shipping_method_id' => $method_id,
                )
            );
        }
        if (
            !empty($selected['tiered_rates'])
            || (array_key_exists('minimum_charge', $selected) && null !== $selected['minimum_charge'])
            || (
                array_key_exists('volumetric_divisor_cm3_per_kg', $selected)
                && null !== $selected['volumetric_divisor_cm3_per_kg']
            )
        ) {
            return $this->error(
                'digitalogic_pricing_variable_shipping_unsupported',
                'فرمول فعلی قیمت از حمل پلکانی، حداقل کرایه یا وزن حجمی پشتیبانی نمی‌کند؛ هیچ تغییری ثبت نشد.',
                409,
                array(
                    'product_code' => $product_code,
                    'shipping_method_id' => $method_id,
                )
            );
        }

        $price = $this->formula_decimal_parts($selected['price_per_kg'] ?? null);
        $domestic = Digitalogic_Shipping_Method_Service::DOMESTIC_METHOD_ID === $method_id;
        if (
            isset($price['error'])
            || (
                $domestic
                && 0 !== $this->decimal_compare($price, $this->formula_decimal_parts('0'))
            )
            || (
                !$domestic
                && $this->decimal_compare($price, $this->formula_decimal_parts('0')) <= 0
            )
        ) {
            return $this->error(
                'digitalogic_pricing_shipping_rate_invalid',
                'نرخ قطعی روش حمل کالا معتبر نیست؛ هیچ تغییری ثبت نشد.',
                409,
                array(
                    'product_code' => $product_code,
                    'shipping_method_id' => $method_id,
                )
            );
        }
        $currency = $selected['currency'] ?? null;
        if (
            !is_string($currency)
            || !in_array($currency, array('CNY', 'IRR'), true)
            || ($domestic && 'IRR' !== $currency)
        ) {
            return $this->error(
                'digitalogic_pricing_shipping_currency_invalid',
                'واحد پول روش حمل کالا معتبر نیست؛ هیچ تغییری ثبت نشد.',
                409,
                array(
                    'product_code' => $product_code,
                    'shipping_method_id' => $method_id,
                )
            );
        }

        return array(
            'price_per_kg' => $this->decimal_parts_to_string($price),
            'currency' => $currency,
        );
    }

    /**
     * Rebuild the shipping catalog identity with the transaction's desired rate.
     *
     * @param array $settings Canonical desired settings.
     * @param array $catalog  Current integration catalog.
     * @return string|WP_Error
     */
    private function coordinated_pricing_catalog_revision($settings, $catalog) {
        $currency = is_array($catalog['currency'] ?? null) ? $catalog['currency'] : array();
        $warnings = is_array($currency['warnings'] ?? null) ? $currency['warnings'] : array();
        $currency['warnings'] = array_values(
            array_diff($warnings, array('cny_to_local_missing_or_invalid'))
        );
        $currency['cny_to_local'] = (int) $settings['yuan_price'];
        if ('IRT' === (string) ($currency['local'] ?? '')) {
            $currency['cny_to_irt'] = (int) $settings['yuan_price'];
        } else {
            unset($currency['cny_to_irt']);
        }
        $currency['effective_date'] = $settings['effective_date'];

        return $this->hash_identity(
            wp_json_encode(
                array(
                    'schema' => (string) ($catalog['schema'] ?? Digitalogic_Shipping_Method_Service::CATALOG_SCHEMA),
                    'currency' => $currency,
                    'pricing' => is_array($catalog['pricing'] ?? null) ? $catalog['pricing'] : array(),
                    'selected_warehouses' => is_array($catalog['selected_warehouses'] ?? null)
                        ? $catalog['selected_warehouses']
                        : array(),
                    'shipping_methods' => is_array($catalog['shipping_methods'] ?? null)
                        ? $catalog['shipping_methods']
                        : array(),
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * Resolve one Product Code once per coordinated transaction.
     *
     * @return array|WP_Error
     */
    private function coordinated_resolution($product_code, &$resolution_cache) {
        if (!array_key_exists($product_code, $resolution_cache)) {
            $resolution_cache[$product_code] = Digitalogic_Product_Identifier_Resolver::instance()->resolve(
                array('patris_code' => $product_code)
            );
        }

        return $resolution_cache[$product_code];
    }

    /**
     * Verify canonical metadata and Woo selling/customer prices exactly.
     *
     * @param int   $woocommerce_id Product ID.
     * @param array $product         Canonical stored product.
     * @return bool
     */
    private function coordinated_price_readback_matches($woocommerce_id, $product) {
        $record_hash = (string) get_post_meta($woocommerce_id, '_digitalogic_patris_record_hash', true);
        if (
            !isset($product['record_hash'])
            || '' === $record_hash
            || !hash_equals((string) $product['record_hash'], $record_hash)
        ) {
            return false;
        }
        $pricing_meta = array(
            'price_source_amount' => '_digitalogic_patris_price_source_amount',
            'price_source_currency' => '_digitalogic_patris_price_source_currency',
            'price_source_kind' => '_digitalogic_patris_price_source_kind',
            'shipping_method_id' => '_digitalogic_patris_shipping_method_id',
            'shipping_price_per_kg' => '_digitalogic_patris_shipping_price_per_kg',
            'shipping_price_per_kg_currency' => '_digitalogic_patris_shipping_price_per_kg_currency',
            'markup_percent' => '_digitalogic_patris_markup_percent',
            'irt_per_cny' => '_digitalogic_patris_irt_per_cny',
            'price_rounding_digits' => '_digitalogic_patris_price_rounding_digits',
            'price_rounding_mode' => '_digitalogic_patris_price_rounding_mode',
            'pricing_catalog_revision' => '_digitalogic_patris_pricing_catalog_revision',
            'pricing_catalog_status' => '_digitalogic_patris_pricing_catalog_status',
            'currency_effective_date' => '_digitalogic_patris_currency_effective_date',
        );
        foreach ($pricing_meta as $field => $meta_key) {
            if (!array_key_exists($field, $product) || null === $product[$field]) {
                if (metadata_exists('post', $woocommerce_id, $meta_key)) {
                    return false;
                }
                continue;
            }
            if ((string) get_post_meta($woocommerce_id, $meta_key, true) !== (string) $product[$field]) {
                return false;
            }
        }
        $assigned_shipping_method = (string) get_post_meta(
            $woocommerce_id,
            Digitalogic_Shipping_Method_Service::PRODUCT_METHOD_META,
            true
        );
        if (
            array_key_exists('shipping_method_id', $product)
            && null !== $product['shipping_method_id']
            && '' !== $product['shipping_method_id']
            && $assigned_shipping_method !== (string) $product['shipping_method_id']
        ) {
            return false;
        }
        if (!array_key_exists('final_price', $product)) {
            if (metadata_exists('post', $woocommerce_id, '_digitalogic_patris_final_price')) {
                return false;
            }
            $woo_product = wc_get_product($woocommerce_id);
            if (!$woo_product) {
                return false;
            }
            if ($woo_product->is_type('variable')) {
                return false;
            }

            $regular = trim((string) $woo_product->get_regular_price());
            $visible = trim((string) $woo_product->get_price());
            $sale = trim((string) $woo_product->get_sale_price());
            $status = (string) $woo_product->get_meta(Digitalogic_Patris_Price_Policy::STATUS_META, true);
            if ('canonical_missing_preserved' === $status) {
                return '' !== $regular
                    && is_numeric($regular)
                    && (float) $regular > 0
                    && $regular === $visible
                    && '' === $sale
                    && Digitalogic_Patris_Price_Policy::MISSING_WEIGHT_WARNING
                        === (string) $woo_product->get_meta(Digitalogic_Patris_Price_Policy::WARNING_META, true);
            }

            return '' === $regular && '' === $visible && '' === $sale;
        }

        $final_price = (string) $product['final_price'];
        if ((string) get_post_meta($woocommerce_id, '_digitalogic_patris_final_price', true) !== $final_price) {
            return false;
        }
        $woo_product = wc_get_product($woocommerce_id);
        if (!$woo_product) {
            return false;
        }
        if ($woo_product->is_type('variable')) {
            return false;
        }

        return (string) $woo_product->get_regular_price() === $final_price
            && (string) $woo_product->get_price() === $final_price
            && '' === trim((string) $woo_product->get_sale_price());
    }

    /**
     * Turn exact decimal parts back into their normalized plain string.
     *
     * @param array $parts Decimal parts.
     * @return string
     */
    private function decimal_parts_to_string($parts) {
        $digits = $this->normalize_big_integer($parts['digits']);
        $scale = (int) $parts['scale'];
        if ($scale <= 0) {
            return $digits . str_repeat('0', -$scale);
        }
        $padded = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $cut = strlen($padded) - $scale;

        return substr($padded, 0, $cut) . '.' . substr($padded, $cut);
    }

    /**
     * Rebuild a canonical wire-equivalent record hash from stored decimals.
     *
     * @param array $product Stored product.
     * @return string
     */
    private function record_hash_from_storage($product) {
        $wire = $product;
        unset($wire['record_hash']);
        foreach (self::PRODUCT_NULLABLE_NUMBER_FIELDS as $field) {
            if (array_key_exists($field, $wire) && null !== $wire[$field] && is_string($wire[$field])) {
                $wire[$field] = new Digitalogic_Product_Sync_JSON_Number($wire[$field]);
            }
        }
        if (isset($wire['final_price']) && is_string($wire['final_price'])) {
            $wire['final_price'] = new Digitalogic_Product_Sync_JSON_Number($wire['final_price']);
        }
        if (isset($wire['warehouse_stock']) && is_array($wire['warehouse_stock'])) {
            foreach ($wire['warehouse_stock'] as $warehouse => $stock) {
                if (is_string($stock)) {
                    $wire['warehouse_stock'][$warehouse] = new Digitalogic_Product_Sync_JSON_Number($stock);
                }
            }
        }

        return $this->record_hash($wire);
    }

    // phpcs:enable
    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    private function receive_locked($envelope) {
        $margin_validation = $this->validate_shared_profit_margin($envelope);
        if (is_wp_error($margin_validation)) {
            return $margin_validation;
        }

        $state = $this->load_state();
        $source_key = $this->source_key($envelope['source']['id'], $envelope['source']['dataset']);
        $existing = isset($state['sources'][$source_key]) && is_array($state['sources'][$source_key])
            ? $state['sources'][$source_key]
            : null;

        if (null === $existing && count($state['sources']) >= self::MAX_SOURCES) {
            return $this->error('digitalogic_product_sync_source_limit', 'The configured source limit has been reached.', 409);
        }

        if (is_array($existing) && isset($existing['recent_events'][$envelope['event_id']])) {
            $existing_products = is_array($existing['products'] ?? null)
                ? $existing['products']
                : array();
            $delivery = $this->build_delivery_state(
                $existing_products,
                array_values($existing_products),
                $envelope,
                $existing
            );
            $existing['applied_products'] = $delivery['applied_products'];
            $existing['pending_products'] = $delivery['pending_products'];
            $existing['deferred_products'] = $delivery['deferred_products'];
            if (empty($existing['pending_products'])) {
                return $this->replay_result($envelope, $existing);
            }

            return $this->retry_pending_locked($state, $source_key, $envelope, $existing);
        }
        if ('update' === $envelope['event_type'] && !is_array($existing)) {
            return $this->error(
                'digitalogic_product_sync_baseline_required',
                'An update event cannot be applied before its source snapshot.',
                409
            );
        }
        if (is_array($existing)) {
            $comparison = $this->compare_timestamp_order($envelope['generated_at_order'], $existing['generated_at_order'] ?? array());
            if ($comparison < 0) {
                return $this->error('digitalogic_product_sync_stale_event', 'The event is older than the stored source state.', 409);
            }
            if (0 === $comparison && $envelope['event_id'] !== ($existing['last_event_id'] ?? '')) {
                return $this->error(
                    'digitalogic_product_sync_order_conflict',
                    'A different event already occupies this source timestamp.',
                    409
                );
            }
        }

        $transition = $this->build_transition($envelope, $existing);
        if (is_wp_error($transition)) {
            return $transition;
        }
        $same_revision = is_array($existing)
            && $envelope['source']['revision'] === ($existing['source']['revision'] ?? '');

        $recent_events = is_array($existing['recent_events'] ?? null) ? $existing['recent_events'] : array();
        $recent_events[$envelope['event_id']] = array(
            'generated_at' => $envelope['generated_at'],
            'source_revision' => $envelope['source']['revision'],
            'event_type' => $envelope['event_type'],
        );
        while (count($recent_events) > self::MAX_RECENT_EVENTS) {
            array_shift($recent_events);
        }

        $delivery = $this->build_delivery_state($transition['products'], $transition['changed_products'], $envelope, $existing);
        $source_state = array(
            'source' => $envelope['source'],
            'generated_at' => $envelope['generated_at'],
            'generated_at_order' => $envelope['generated_at_order'],
            'last_event_id' => $envelope['event_id'],
            'last_event_type' => $envelope['event_type'],
            'products' => $transition['products'],
            'categories' => $transition['categories'],
            'excluded_codes' => $transition['excluded_codes'],
            'quarantined_codes' => $envelope['quarantined_codes'],
            'recent_events' => $recent_events,
            'applied_products' => $delivery['applied_products'],
            'pending_products' => $delivery['pending_products'],
            'deferred_products' => $delivery['deferred_products'],
            'received_at' => current_time('mysql'),
        );
        foreach (array('local_currency', 'formula_id') as $field) {
            if (array_key_exists($field, $envelope)) {
                $source_state[$field] = $envelope[$field];
            }
        }
        $state['sources'][$source_key] = $source_state;

        $stored = $this->persist_and_read_back($state);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $before_delivery = $this->state_digest($source_state);
        // A normal source delivery retries transient pending work only. Terminal
        // missing/ambiguous reconciliation stays durable until its source record
        // changes or an administrator explicitly runs product-sync reconcile.
        // Rechecking every unchanged deferred record here can keep the HTTP
        // acknowledgement open long after receiver state has committed.
        $woo = $this->drain_delivery_products($source_state, true, false);
        $state['sources'][$source_key] = $source_state;
        if (!hash_equals($before_delivery, $this->state_digest($source_state))) {
            $delivery_stored = $this->persist_and_read_back($state);
            if (is_wp_error($delivery_stored)) {
                return $delivery_stored;
            }
        }

        $fully_applied = empty($source_state['pending_products']);
        $result = array_merge(array(
            'status' => $fully_applied ? ($same_revision ? 'already_current' : 'accepted') : 'partially_applied',
            'replayed' => false,
            'event_id' => $envelope['event_id'],
            'event_type' => $envelope['event_type'],
            'source' => $envelope['source'],
            'received_products' => count($envelope['products']),
            'stored_products' => count($transition['products']),
            'received_categories' => count($envelope['categories']),
            'stored_categories' => count($transition['categories']),
            'excluded_codes' => count($transition['excluded_codes']),
            'deleted_codes' => $transition['deleted_count'],
            'preserved_quarantined' => $transition['preserved_quarantined'],
            'woocommerce' => $woo,
            'persistence_verified' => true,
        ), $this->delivery_result_state($source_state));

        return $this->emit_result($result, $envelope);
    }

    /**
     * Reject pricing rows generated with a stale or product-specific margin.
     *
     * The catalog-wide margin is owned by the pricing coordinator. A Patris
     * payload may transport that value for deterministic formula verification,
     * but it cannot introduce another value. An unconfigured installation may
     * still accept its first source snapshot; once configured, mismatch fails
     * before receiver state or WooCommerce is changed.
     *
     * @param array $envelope Validated product-sync envelope.
     * @return true|WP_Error
     */
    private function validate_shared_profit_margin($envelope) {
        if (empty($envelope['local_currency']) || empty($envelope['formula_id'])) {
            return true;
        }

        $margin = Digitalogic_Shipping_Method_Service::instance()->get_default_percentage_markup();
        if (is_wp_error($margin)) {
            return $margin;
        }
        if (empty($margin['configured']) || null === ($margin['profit_percent'] ?? null)) {
            return true;
        }

        $expected = $this->formula_decimal_parts($margin['profit_percent']);
        if (isset($expected['error'])) {
            return $this->error(
                'digitalogic_product_sync_profit_margin_state_invalid',
                'The configured shared profit margin is invalid.',
                409
            );
        }

        $mismatches = array();
        foreach ($envelope['products'] as $product) {
            if (!array_key_exists('markup_percent', $product) || null === $product['markup_percent']) {
                continue;
            }
            $submitted = $this->formula_decimal_parts($product['markup_percent']);
            if (
                isset($submitted['error'])
                || 0 !== $this->decimal_compare($submitted, $expected)
            ) {
                $mismatches[] = (string) $product['product_code'];
                if (count($mismatches) >= 50) {
                    break;
                }
            }
        }
        if (empty($mismatches)) {
            return true;
        }

        return $this->error(
            'digitalogic_product_sync_profit_margin_mismatch',
            'Product pricing rows must use the current shared profit margin.',
            409,
            array(
                'profit_margin_percent' => $this->decimal_parts_to_string($expected),
                'product_codes' => $mismatches,
            )
        );
    }

    private function retry_pending_locked($state, $source_key, $envelope, $existing) {
        $source_state = $existing;
        $before_delivery = $this->state_digest($source_state);
        $woo = $this->drain_delivery_products($source_state, true, false);
        $state['sources'][$source_key] = $source_state;
        if (!hash_equals($before_delivery, $this->state_digest($source_state))) {
            $stored = $this->persist_and_read_back($state);
            if (is_wp_error($stored)) {
                return $stored;
            }
        }

        $fully_applied = empty($source_state['pending_products']);
        $result = array_merge(array(
            'status' => $fully_applied ? 'recovered' : 'retry_pending',
            'replayed' => true,
            'event_id' => $envelope['event_id'],
            'event_type' => $envelope['event_type'],
            'source' => $envelope['source'],
            'stored_products' => count($source_state['products'] ?? array()),
            'stored_categories' => count($source_state['categories'] ?? array()),
            'excluded_codes' => count($source_state['excluded_codes'] ?? array()),
            'woocommerce' => $woo,
            'persistence_verified' => true,
        ), $this->delivery_result_state($source_state));

        return $this->emit_result($result, $envelope);
    }
    // phpcs:enable

    private function emit_result($result, $envelope) {
        try {
            do_action('digitalogic_product_sync_applied', $result, array(
					'schema'       => $envelope['schema'],
					'event_id'     => $envelope['event_id'],
					'event_type'   => $envelope['event_type'],
					'source'       => $envelope['source'],
					'generated_at' => $envelope['generated_at'],
            ));
        } catch (Throwable $exception) {
            $result['delivery_warnings'][] = array(
				'code'      => 'digitalogic_product_sync_listener_failed',
                'exception' => get_class($exception),
            );
        }

        try {
            Digitalogic_Logger::instance()->log(
                'product_sync_applied',
                'patris_feed',
                null,
                null,
                wp_json_encode($result),
                'Patris product-sync event applied'
            );
        } catch (Throwable $exception) {
            $result['delivery_warnings'][] = array(
				'code'      => 'digitalogic_product_sync_log_failed',
                'exception' => get_class($exception),
            );
        }

        return $result;
    }

    private function validate_envelope($payload) {
        $unknown = array_values(array_diff(array_keys($payload), self::ENVELOPE_FIELDS));
        if (!empty($unknown)) {
            return $this->error('digitalogic_product_sync_unknown_field', 'The envelope contains unsupported fields.', 422, array('fields' => $unknown));
        }

        $required = array(
            'schema',
            'event_type',
            'event_id',
            'source',
            'generated_at',
            'products',
            'categories',
            'excluded_codes',
            'quarantined_codes',
            'warnings',
        );
		$missing  = array_values( array_diff( $required, array_keys( $payload ) ) );
        if (!empty($missing)) {
            return $this->error('digitalogic_product_sync_missing_field', 'The envelope is missing required fields.', 422, array('fields' => $missing));
        }

        foreach (array('schema', 'event_type', 'event_id', 'generated_at') as $field) {
            if (!is_string($payload[$field])) {
                return $this->field_error($field, 'must be a string');
            }
        }
        if (self::CONTRACT_NAME !== $payload['schema']) {
            return $this->error('digitalogic_product_sync_schema_unsupported', 'Only patris.product-sync envelopes are accepted.', 422);
        }
        if (!in_array($payload['event_type'], array('snapshot', 'update'), true)) {
            return $this->field_error('event_type', 'must be snapshot or update');
        }
        $has_currency = array_key_exists('local_currency', $payload);
		$has_formula  = array_key_exists( 'formula_id', $payload );
        if ($has_currency !== $has_formula) {
            return $this->field_error('formula_id', 'must be present exactly when local_currency is present');
        }
        $pricing_active = $has_currency;
        if ($pricing_active) {
            if (!is_string($payload['local_currency']) || 'IRT' !== $payload['local_currency']) {
                return $this->error('digitalogic_product_sync_currency_unsupported', 'Integrated prices must use IRT.', 422);
            }
            if (!is_string($payload['formula_id']) || self::FORMULA_ID !== $payload['formula_id']) {
                return $this->error('digitalogic_product_sync_formula_unsupported', 'The landed_price formula is required.', 422);
            }
            $currency_status = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();
            if (!$currency_status['compatible']) {
                return $this->error(
                    'digitalogic_product_sync_store_currency_mismatch',
                    'WooCommerce must use IRT before transformed IRT prices can be applied.',
                    409,
                    array(
                        'woocommerce_base_currency' => $currency_status['code'],
						'required_currency'         => Digitalogic_WooCommerce_Currency_Status::REQUIRED_CURRENCY,
						'warning'                   => Digitalogic_WooCommerce_Currency_Status::INCOMPATIBLE_WARNING,
                    )
                );
            }
        }
        if (!$this->is_hash($payload['event_id'])) {
            return $this->field_error('event_id', 'must be a sha256 identity');
        }

        $source = $this->validate_source($payload['source']);
        if (is_wp_error($source)) {
            return $source;
        }
        $generated_at_order = $this->timestamp_order($payload['generated_at']);
        if (is_wp_error($generated_at_order)) {
            return $generated_at_order;
        }
        if (!is_array($payload['products']) || !array_is_list($payload['products'])) {
            return $this->field_error('products', 'must be an array');
        }
        if (count($payload['products']) > self::MAX_PRODUCTS) {
            return $this->error('digitalogic_product_sync_product_limit', 'The event contains too many products.', 413);
        }

		$products   = array();
        $seen_codes = array();
        foreach ($payload['products'] as $index => $product) {
            $validated = $this->validate_product($product, $index, $pricing_active);
            if (is_wp_error($validated)) {
                return $validated;
            }
            $code = $validated['product_code'];
            if (isset($seen_codes[$code])) {
                return $this->error('digitalogic_product_sync_duplicate_code', 'Product codes must be unique inside an event.', 422, array('product_code' => $code));
            }
            // PHP coerces canonical numeric-string array keys to integers. Keep
            // the validated string as the value whenever the map is projected.
            $seen_codes[$code] = $code;
			$products[]          = $validated;
        }

        $categories = $this->validate_categories($payload['categories']);
        if (is_wp_error($categories)) {
            return $categories;
        }
        $excluded_codes = $this->validate_string_set($payload['excluded_codes'], 'excluded_codes');
        if (is_wp_error($excluded_codes)) {
            return $excluded_codes;
        }
        $catalog_check = $this->validate_catalog_projection($products, $categories, $excluded_codes);
        if (is_wp_error($catalog_check)) {
            return $catalog_check;
        }

        $deleted_codes = $this->validate_tombstones($payload['deleted_codes'] ?? array(), $payload['event_type']);
        if (is_wp_error($deleted_codes)) {
            return $deleted_codes;
        }
        $quarantined_codes = $this->validate_string_set($payload['quarantined_codes'], 'quarantined_codes');
        if (is_wp_error($quarantined_codes)) {
            return $quarantined_codes;
        }
        $warnings = $this->validate_string_set($payload['warnings'], 'warnings', false);
        if (is_wp_error($warnings)) {
            return $warnings;
        }

        $deleted_lookup = array();
        foreach ($deleted_codes as $tombstone) {
            $deleted_lookup[$tombstone['product_code']] = $tombstone['product_code'];
        }
        $quarantined_lookup = array();
        foreach ($quarantined_codes as $quarantined_code) {
            $quarantined_lookup[$quarantined_code] = $quarantined_code;
        }
        foreach ($seen_codes as $code) {
            if (isset($deleted_lookup[$code]) || isset($quarantined_lookup[$code])) {
                return $this->error('digitalogic_product_sync_code_overlap', 'A code cannot be both a product and a tombstone or quarantine entry.', 422, array('product_code' => $code));
            }
        }
        foreach ($deleted_lookup as $code) {
            if (isset($quarantined_lookup[$code])) {
                return $this->error('digitalogic_product_sync_code_overlap', 'Quarantined codes cannot be tombstoned.', 422, array('product_code' => $code));
            }
        }

        $envelope = array(
			'schema'             => $payload['schema'],
			'event_type'         => $payload['event_type'],
			'event_id'           => $payload['event_id'],
			'source'             => $source,
			'generated_at'       => $payload['generated_at'],
            'generated_at_order' => $generated_at_order,
			'products'           => $products,
			'categories'         => $categories,
			'excluded_codes'     => $excluded_codes,
			'deleted_codes'      => $deleted_codes,
			'quarantined_codes'  => $quarantined_codes,
			'warnings'           => $warnings,
        );
        if ($pricing_active) {
            $envelope['local_currency'] = $payload['local_currency'];
			$envelope['formula_id']     = $payload['formula_id'];
        }

        $expected_event_id = $this->event_id($envelope);
        if (!hash_equals($expected_event_id, $envelope['event_id'])) {
            return $this->error(
                'digitalogic_product_sync_event_hash_mismatch',
                'The event_id does not match the canonical event contents.',
                422,
                array('expected' => $expected_event_id)
            );
        }

        return $envelope;
    }

    private function validate_source($source) {
        if (!is_array($source) || array_is_list($source)) {
            return $this->field_error('source', 'must be an object');
        }
        if (
            !empty(array_diff(array('id', 'dataset', 'revision'), array_keys($source)))
            || !empty(array_diff(array_keys($source), array('id', 'dataset', 'revision')))
        ) {
            return $this->field_error('source', 'must contain exactly id, dataset, and revision');
        }
        foreach (array('id', 'dataset', 'revision') as $field) {
            if (!is_string($source[$field])) {
                return $this->field_error('source.' . $field, 'must be a string');
            }
        }
        if (
            '' === trim($source['id'])
            || '' === trim($source['dataset'])
            || trim($source['id']) !== $source['id']
            || trim($source['dataset']) !== $source['dataset']
        ) {
            return $this->field_error('source', 'id and dataset must not be empty');
        }
        if (strlen($source['id']) > 191 || strlen($source['dataset']) > 191) {
            return $this->field_error('source', 'id and dataset are too long');
        }
        if (!$this->is_hash($source['revision'])) {
            return $this->field_error('source.revision', 'must be a sha256 identity');
        }

        return $source;
    }

    private function validate_product($product, $index, $pricing_active) {
        $path = 'products[' . (int) $index . ']';
        if (!is_array($product) || array_is_list($product)) {
            return $this->field_error($path, 'must be an object');
        }
        $missing = array_values(array_diff(self::REQUIRED_PRODUCT_FIELDS, array_keys($product)));
        $unknown = array_values(array_diff(array_keys($product), self::PRODUCT_FIELDS));
        if (!empty($missing) || !empty($unknown)) {
            return $this->error(
                'digitalogic_product_sync_product_shape_invalid',
                'A product does not match the living product-sync shape.',
                422,
                array('path' => $path, 'missing' => $missing, 'unknown' => $unknown)
            );
        }
        if (
            !is_string($product['product_code'])
            || '' === trim($product['product_code'])
            || trim($product['product_code']) !== $product['product_code']
        ) {
            return $this->field_error($path . '.product_code', 'must be a non-empty string');
        }
        if (strlen($product['product_code']) > self::MAX_CODE_LENGTH) {
            return $this->field_error($path . '.product_code', 'is too long');
        }
        if (array_key_exists('category_code', $product) && null !== $product['category_code']) {
            if (
                !is_string($product['category_code'])
                || trim($product['category_code']) !== $product['category_code']
                || strlen($product['category_code']) > self::MAX_CODE_LENGTH
            ) {
                return $this->field_error($path . '.category_code', 'must be null or a trimmed string within the code limit');
            }
        }
        foreach (self::PRODUCT_STRING_FIELDS as $field) {
            if (array_key_exists($field, $product) && null !== $product[$field] && !is_string($product[$field])) {
                return $this->field_error($path . '.' . $field, 'must be a string or explicit null');
            }
        }
        if (
            array_key_exists('foreign_currency', $product)
            && null !== $product['foreign_currency']
            && (!is_string($product['foreign_currency']) || 'CNY' !== $product['foreign_currency'])
        ) {
            return $this->field_error($path . '.foreign_currency', 'must be CNY or explicit null');
        }
        $price_source_fields         = array('price_source_amount', 'price_source_currency', 'price_source_kind');
        $present_price_source_fields = array_values(array_intersect($price_source_fields, array_keys($product)));
        if (!empty($present_price_source_fields) && count($present_price_source_fields) !== count($price_source_fields)) {
            return $this->error(
                'digitalogic_product_sync_price_source_incomplete',
                'price_source_amount, price_source_currency, and price_source_kind must be provided together or all omitted.',
                422,
                array('path' => $path, 'present' => $present_price_source_fields)
            );
        }
        if (count($present_price_source_fields) === count($price_source_fields)) {
            foreach ($price_source_fields as $field) {
                if (null === $product[$field]) {
                    return $this->field_error($path . '.' . $field, 'must be omitted instead of null when no usable price source exists');
                }
            }
            if (!in_array($product['price_source_currency'], array('CNY', 'IRR'), true)) {
                return $this->field_error($path . '.price_source_currency', 'must be CNY or IRR');
            }
            if (!in_array($product['price_source_kind'], array('foreign_price', 'partner_price', 'sale_price_direct'), true)) {
                return $this->field_error($path . '.price_source_kind', 'must be foreign_price, partner_price, or sale_price_direct');
            }
        }
        $direct_sale_selected = $pricing_active && 'sale_price_direct' === ($product['price_source_kind'] ?? null);
        $has_shipping_price = array_key_exists('shipping_price_per_kg', $product);
        $has_shipping_currency = array_key_exists('shipping_price_per_kg_currency', $product);
        if ($has_shipping_price !== $has_shipping_currency) {
            return $this->error(
                'digitalogic_product_sync_shipping_currency_required',
                'shipping_price_per_kg and shipping_price_per_kg_currency must be provided together.',
                422,
                array('path' => $path)
            );
        }
        if (
            $has_shipping_currency
            && null !== $product['shipping_price_per_kg_currency']
            && !in_array($product['shipping_price_per_kg_currency'], array('CNY', 'IRR'), true)
        ) {
            return $this->field_error($path . '.shipping_price_per_kg_currency', 'must be CNY, IRR, or explicit null');
        }
        if (!$pricing_active) {
            $unexpected_pricing = array_values(array_intersect(self::PRODUCT_PRICING_FIELDS, array_keys($product)));
            if (!empty($unexpected_pricing)) {
                return $this->error(
                    'digitalogic_product_sync_pricing_context_missing',
                    'Pricing fields require envelope local_currency and formula_id.',
                    422,
                    array('path' => $path, 'fields' => $unexpected_pricing)
                );
            }
        }
        foreach (self::PRODUCT_NULLABLE_NUMBER_FIELDS as $field) {
            if (!array_key_exists($field, $product)) {
                continue;
            }
            if (null !== $product[$field] && !$this->is_json_number($product[$field])) {
                return $this->field_error($path . '.' . $field, 'must be a JSON number or null');
            }
            if (
                null !== $product[$field]
                && in_array($field, self::PRODUCT_DECIMAL_FIELDS, true)
                && !$this->is_plain_decimal($product[$field])
            ) {
                return $this->field_error($path . '.' . $field, 'must be a base-10 decimal without exponent notation');
            }
        }
        if ($direct_sale_selected) {
            $forbidden_direct_inputs = array_values(
                array_intersect(
                    array('markup_percent', 'price_rounding_digits', 'price_rounding_mode', 'irt_per_cny'),
                    array_keys($product)
                )
            );
            if (!empty($forbidden_direct_inputs)) {
                return $this->error(
                    'digitalogic_product_sync_direct_sale_inputs_forbidden',
                    'sale_price_direct must omit markup, rounding, and foreign-exchange inputs.',
                    422,
                    array('path' => $path, 'fields' => $forbidden_direct_inputs)
                );
            }
        }
        if (
            array_key_exists('weight_grams', $product)
            && null !== $product['weight_grams']
            && $this->number_compare_zero($product['weight_grams']) < 0
        ) {
            return $this->field_error($path . '.weight_grams', 'must not be negative');
        }
        if (
            array_key_exists('irt_per_cny', $product)
            && null !== $product['irt_per_cny']
            && $this->number_compare_zero($product['irt_per_cny']) <= 0
        ) {
            return $this->field_error($path . '.irt_per_cny', 'must be greater than zero when provided');
        }
        if (
            array_key_exists('shipping_price_per_kg', $product)
            && null !== $product['shipping_price_per_kg']
            && $this->number_compare_zero($product['shipping_price_per_kg']) < 0
        ) {
            return $this->field_error($path . '.shipping_price_per_kg', 'must not be negative');
        }
        if (
            array_key_exists('foreign_price', $product)
            && null !== $product['foreign_price']
            && $this->number_compare_zero($product['foreign_price']) < 0
        ) {
            return $this->field_error($path . '.foreign_price', 'must not be negative');
        }
        if (
            array_key_exists('price_source_amount', $product)
            && null !== $product['price_source_amount']
            && $this->number_compare_zero($product['price_source_amount']) <= 0
        ) {
            return $this->field_error($path . '.price_source_amount', 'must be greater than zero when selected');
        }
        if (array_key_exists('markup_percent', $product) && null !== $product['markup_percent'] && $this->number_compare_zero($product['markup_percent']) < 0) {
            return $this->field_error($path . '.markup_percent', 'must not be negative');
        }
        if ($pricing_active) {
            if (!$direct_sale_selected && !array_key_exists('price_rounding_digits', $product)) {
                return $this->field_error($path . '.price_rounding_digits', 'is required when pricing is active');
            }
            if (!$direct_sale_selected && null === $product['price_rounding_digits']) {
                if (array_key_exists('price_rounding_mode', $product)) {
                    return $this->field_error($path . '.price_rounding_mode', 'must be omitted when price_rounding_digits is explicitly null');
                }
            } elseif (!$direct_sale_selected && (
                !$this->is_nonnegative_integer($product['price_rounding_digits'])
                || (int) $this->number_to_storage($product['price_rounding_digits']) > 9
            )) {
                return $this->field_error($path . '.price_rounding_digits', 'must be an integer from 0 through 9');
            } elseif (
                !$direct_sale_selected
                && (!array_key_exists('price_rounding_mode', $product) || 'nearest_half_up' !== $product['price_rounding_mode'])
            ) {
                return $this->field_error($path . '.price_rounding_mode', 'must be nearest_half_up when pricing is active');
            }
        }
        if (array_key_exists('final_price', $product) && !$this->is_nonnegative_integer($product['final_price'])) {
            return $this->field_error($path . '.final_price', 'must be a non-negative integer; omit it when unavailable');
        }
        if (array_key_exists('warehouse_stock', $product) && null !== $product['warehouse_stock']) {
            if (!is_array($product['warehouse_stock']) || (!empty($product['warehouse_stock']) && array_is_list($product['warehouse_stock']))) {
                return $this->field_error($path . '.warehouse_stock', 'must be an object or explicit null');
            }
            foreach ($product['warehouse_stock'] as $warehouse => $stock) {
                if ('' === trim((string) $warehouse) || (null !== $stock && !$this->is_json_number($stock))) {
                    return $this->field_error($path . '.warehouse_stock', 'must map non-empty string keys to JSON numbers or explicit null');
                }
            }
        }
        if (array_key_exists('warnings', $product)) {
            $warnings = $this->validate_string_set($product['warnings'], $path . '.warnings', false);
            if (is_wp_error($warnings)) {
                return $warnings;
            }
            $product['warnings'] = $warnings;
        }
        if (!is_string($product['record_hash']) || !$this->is_hash($product['record_hash'])) {
            return $this->field_error($path . '.record_hash', 'must be a sha256 identity');
        }

        $expected_hash = $this->record_hash($product);
        if (!hash_equals($expected_hash, $product['record_hash'])) {
            return $this->error(
                'digitalogic_product_sync_record_hash_mismatch',
                'A record_hash does not match its typed product.',
                422,
                array('path' => $path . '.record_hash', 'expected' => $expected_hash)
            );
        }

        $formula_check = $this->validate_final_price_formula($product, $path, $pricing_active);
        if (is_wp_error($formula_check)) {
            return $formula_check;
        }

        $stored = array();
        foreach (self::PRODUCT_FIELDS as $field) {
            if (!array_key_exists($field, $product)) {
                continue;
            }
            if ('warehouse_stock' === $field) {
                $stocks = $product[$field];
                if (null === $stocks) {
                    $stored[$field] = null;
                } else {
                    ksort($stocks, SORT_STRING);
                    $stored[$field] = array();
                    foreach ($stocks as $warehouse => $stock) {
                        $stored[$field][$warehouse] = null === $stock ? null : $this->number_to_storage($stock);
                    }
                }
            } elseif (in_array($field, self::PRODUCT_DECIMAL_FIELDS, true)) {
                $stored[$field] = null === $product[$field] ? null : $this->decimal_to_storage($product[$field]);
            } elseif ('price_rounding_digits' === $field) {
                $stored[$field] = null === $product[$field] ? null : (int) $this->number_to_storage($product[$field]);
            } elseif (in_array($field, self::PRODUCT_NULLABLE_NUMBER_FIELDS, true) || 'final_price' === $field) {
                $stored[$field] = null === $product[$field] ? null : $this->number_to_storage($product[$field]);
            } else {
                $stored[$field] = $product[$field];
            }
        }

        return $stored;
    }

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    private function validate_categories($values) {
        if (!is_array($values) || !array_is_list($values)) {
            return $this->field_error('categories', 'must be an array');
        }
        if (count($values) > self::MAX_CATEGORIES) {
            return $this->error('digitalogic_product_sync_category_limit', 'The event contains too many categories.', 413);
        }

        $categories = array();
        $seen = array();
        foreach ($values as $index => $category) {
            $path = 'categories[' . (int) $index . ']';
            if (!is_array($category) || array_is_list($category)) {
                return $this->field_error($path, 'must be an object');
            }
            $missing = array_values(array_diff(self::REQUIRED_CATEGORY_FIELDS, array_keys($category)));
            $unknown = array_values(array_diff(array_keys($category), self::CATEGORY_FIELDS));
            if (!empty($missing) || !empty($unknown)) {
                return $this->error(
                    'digitalogic_product_sync_category_shape_invalid',
                    'A category does not match the living category shape.',
                    422,
                    array('path' => $path, 'missing' => $missing, 'unknown' => $unknown)
                );
            }
            if (
                !is_string($category['category_code'])
                || '' === trim($category['category_code'])
                || trim($category['category_code']) !== $category['category_code']
                || strlen($category['category_code']) > self::MAX_CODE_LENGTH
            ) {
                return $this->field_error($path . '.category_code', 'must be a non-empty trimmed string within the code limit');
            }
            if (isset($seen[$category['category_code']])) {
                return $this->error(
                    'digitalogic_product_sync_duplicate_category_code',
                    'Category codes must be unique inside an event.',
                    422,
                    array('category_code' => $category['category_code'])
                );
            }
            if (null !== $category['name'] && !is_string($category['name'])) {
                return $this->field_error($path . '.name', 'must be a string or explicit null');
            }
            if (
                !is_string($category['parent_code'])
                || trim($category['parent_code']) !== $category['parent_code']
                || strlen($category['parent_code']) > self::MAX_CODE_LENGTH
                || $category['parent_code'] === $category['category_code']
            ) {
                return $this->field_error($path . '.parent_code', 'must be a derived string and cannot reference the category itself');
            }
            if (!$this->is_nonnegative_integer($category['depth']) || $this->number_compare_zero($category['depth']) <= 0) {
                return $this->field_error($path . '.depth', 'must be a positive integer');
            }
            if (array_key_exists('warnings', $category)) {
                $warnings = $this->validate_string_set($category['warnings'], $path . '.warnings', false);
                if (is_wp_error($warnings)) {
                    return $warnings;
                }
                $category['warnings'] = $warnings;
            }
            if (!is_string($category['record_hash']) || !$this->is_hash($category['record_hash'])) {
                return $this->field_error($path . '.record_hash', 'must be a sha256 identity');
            }

            $expected_hash = $this->category_record_hash($category);
            if (!hash_equals($expected_hash, $category['record_hash'])) {
                return $this->error(
                    'digitalogic_product_sync_category_hash_mismatch',
                    'A category record_hash does not match its typed category.',
                    422,
                    array('path' => $path . '.record_hash', 'expected' => $expected_hash)
                );
            }

            $stored = array();
            foreach (self::CATEGORY_FIELDS as $field) {
                if (!array_key_exists($field, $category)) {
                    continue;
                }
                $stored[$field] = 'depth' === $field
                    ? $this->number_to_storage($category[$field])
                    : $category[$field];
            }
            $seen[$stored['category_code']] = $stored['category_code'];
            $categories[] = $stored;
        }

        usort($categories, static function($left, $right) {
            return strcmp($left['category_code'], $right['category_code']);
        });
        $lookup = array();
        foreach ($categories as $category) {
            $lookup[$category['category_code']] = $category;
        }
        foreach ($categories as $category) {
            $parent_code = $category['parent_code'] ?? null;
            if (null === $parent_code || '' === $parent_code) {
                if (1 !== $category['depth']) {
                    return $this->field_error(
                        'categories.' . $category['category_code'] . '.depth',
                        'must be 1 for a root category'
                    );
                }
                continue;
            }
            if (!isset($lookup[$parent_code])) {
                return $this->field_error(
                    'categories.' . $category['category_code'] . '.parent_code',
                    'must reference a category in the same complete projection'
                );
            }
            if ($category['depth'] !== $lookup[$parent_code]['depth'] + 1) {
                return $this->field_error(
                    'categories.' . $category['category_code'] . '.depth',
                    'must be exactly one greater than its parent depth'
                );
            }
        }

        return $categories;
    }

    private function validate_catalog_projection($products, $categories, $excluded_codes) {
        $category_lookup = array();
        foreach ($categories as $category) {
            $category_lookup[$category['category_code']] = $category['category_code'];
        }
        $excluded_lookup = array();
        foreach ($excluded_codes as $code) {
            $excluded_lookup[$code] = $code;
            if (isset($category_lookup[$code])) {
                return $this->error(
                    'digitalogic_product_sync_catalog_overlap',
                    'A Code cannot be both a category and an excluded record.',
                    422,
                    array('code' => $code)
                );
            }
        }
        foreach ($products as $product) {
            $code = $product['product_code'];
            if (isset($category_lookup[$code]) || isset($excluded_lookup[$code])) {
                return $this->error(
                    'digitalogic_product_sync_catalog_overlap',
                    'A Code cannot be both a sellable product and a structural catalog record.',
                    422,
                    array('code' => $code)
                );
            }
            $category_code = (string) ($product['category_code'] ?? '');
            if ('' !== $category_code && !isset($category_lookup[$category_code])) {
                return $this->error(
                    'digitalogic_product_sync_category_reference_invalid',
                    'A product category_code must reference the complete category projection.',
                    422,
                    array('product_code' => $code, 'category_code' => $category_code)
                );
            }
        }

        return true;
    }
    // phpcs:enable

    private function validate_tombstones($values, $event_type) {
        if (!is_array($values) || !array_is_list($values)) {
            return $this->field_error('deleted_codes', 'must be an array');
        }
        if ('snapshot' === $event_type && !empty($values)) {
            return $this->field_error('deleted_codes', 'is only valid on update events');
        }
        $result = array();
		$seen   = array();
        foreach ($values as $index => $value) {
            if (
                !is_array($value)
                || !empty(array_diff(array('product_code', 'deleted'), array_keys($value)))
                || !empty(array_diff(array_keys($value), array('product_code', 'deleted')))
            ) {
                return $this->field_error('deleted_codes[' . $index . ']', 'must contain exactly product_code and deleted');
            }
            if (
                !is_string($value['product_code'])
                || '' === trim($value['product_code'])
                || trim($value['product_code']) !== $value['product_code']
                || true !== $value['deleted']
            ) {
                return $this->field_error('deleted_codes[' . $index . ']', 'must contain a non-empty string code and deleted=true');
            }
            if (strlen($value['product_code']) > self::MAX_CODE_LENGTH || isset($seen[$value['product_code']])) {
                return $this->field_error('deleted_codes[' . $index . '].product_code', 'must be unique and within the code limit');
            }
            $seen[$value['product_code']] = true;
			$result[]                       = array(
				'product_code' => $value['product_code'],
				'deleted'      => true,
			);
        }
        usort($result, static function($left, $right) {
            return strcmp($left['product_code'], $right['product_code']);
        });

        return $result;
    }

    private function validate_string_set($values, $field, $code_rules = true) {
        if (!is_array($values) || !array_is_list($values)) {
            return $this->field_error($field, 'must be an array of unique strings');
        }
        $result = array();
		$seen   = array();
        foreach ($values as $index => $value) {
            if (!is_string($value) || '' === trim($value) || ($code_rules && trim($value) !== $value)) {
                return $this->field_error($field . '[' . $index . ']', 'must be a non-empty string');
            }
            $limit = $code_rules ? self::MAX_CODE_LENGTH : 255;
            if (strlen($value) > $limit || isset($seen[$value])) {
                return $this->field_error($field . '[' . $index . ']', 'must be unique and within the length limit');
            }
            $seen[$value] = true;
			$result[]       = $value;
        }
        sort($result, SORT_STRING);

        return $result;
    }

    private function build_transition($envelope, $existing) {
        $previous = is_array($existing['products'] ?? null) ? $existing['products'] : array();
        $incoming = array();
        foreach ($envelope['products'] as $product) {
            $incoming[$product['product_code']] = $product;
        }
        $categories = array();
        foreach ($envelope['categories'] as $category) {
            $categories[$category['category_code']] = $category;
        }
        ksort($categories, SORT_STRING);
        $excluded_codes = $envelope['excluded_codes'];
		$quarantined    = array();
        foreach ($envelope['quarantined_codes'] as $quarantined_code) {
            $quarantined[$quarantined_code] = $quarantined_code;
        }
        $preserved = 0;

        if ('snapshot' === $envelope['event_type']) {
            $next = $incoming;
            foreach ($quarantined as $code) {
                if (isset($previous[$code])) {
                    $next[$code] = $previous[$code];
                    $preserved++;
                }
            }
            $revision_products = $incoming;
        } else {
            $next = $previous;
            foreach ($incoming as $product) {
                $next[$product['product_code']] = $product;
            }
            $deleted = 0;
            foreach ($envelope['deleted_codes'] as $tombstone) {
                $code = $tombstone['product_code'];
                if (isset($next[$code])) {
                    unset($next[$code]);
                    $deleted++;
                }
            }
            foreach ($quarantined as $code) {
                if (isset($previous[$code])) {
                    $next[$code] = $previous[$code];
                    $preserved++;
                }
            }
            $revision_products = $next;
            foreach ($quarantined as $code) {
                unset($revision_products[$code]);
            }
        }

        ksort($next, SORT_STRING);
        ksort($revision_products, SORT_STRING);
        $catalog_check = $this->validate_catalog_projection(
            array_values($next),
            array_values($categories),
            $excluded_codes
        );
        if (is_wp_error($catalog_check)) {
            return $catalog_check;
        }
        $expected_revision = $this->source_revision(
            $revision_products,
            $categories,
            $excluded_codes,
            $envelope['quarantined_codes']
        );
        if (!hash_equals($expected_revision, $envelope['source']['revision'])) {
            return $this->error(
                'digitalogic_product_sync_source_revision_mismatch',
                'The source revision does not match the resulting source snapshot.',
                422,
                array('expected' => $expected_revision)
            );
        }

        return array(
			'products'              => $next,
			'categories'            => $categories,
			'excluded_codes'        => $excluded_codes,
			'changed_products'      => array_values( $incoming ),
			'deleted_count'         => 'snapshot' === $envelope['event_type']
                ? count(array_diff(array_keys($previous), array_merge(array_keys($next), $envelope['quarantined_codes'])))
                : ($deleted ?? 0),
            'preserved_quarantined' => $preserved,
        );
    }

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    private function build_delivery_state($products, $changed_products, $envelope, $existing) {
        $applied = is_array($existing['applied_products'] ?? null) ? $existing['applied_products'] : array();
        $pending = is_array($existing['pending_products'] ?? null) ? $existing['pending_products'] : array();
        $deferred = is_array($existing['deferred_products'] ?? null) ? $existing['deferred_products'] : array();

        foreach ($applied as $code_key => $entry) {
            $product_code = $this->delivery_product_code($products, $code_key, $entry);
            if (null === $product_code || !is_array($entry) || !isset($entry['record_hash'], $entry['woocommerce_id'])) {
                unset($applied[$code_key]);
                continue;
            }
            $entry['product_code'] = $product_code;
            $applied[$code_key] = $entry;
        }
        $pending = $this->prune_delivery_set($products, $pending);
        $deferred = $this->prune_delivery_set($products, $deferred);

        foreach ($changed_products as $product) {
            $code = $product['product_code'];
            $record_hash = $product['record_hash'];
            $applied_entry = is_array($applied[$code] ?? null) ? $applied[$code] : array();
            if (isset($applied_entry['record_hash']) && hash_equals((string) $applied_entry['record_hash'], $record_hash)) {
                $woocommerce_id = isset($applied_entry['woocommerce_id'])
                    ? (int) $applied_entry['woocommerce_id']
                    : 0;
                if (
                    $woocommerce_id > 0
                    && $this->delivery_price_projection_matches($woocommerce_id, $product)
                ) {
                    unset($pending[$code]);
                    unset($deferred[$code]);
                    continue;
                }
                unset($applied[$code]);
            }

            $pending_entry = is_array($pending[$code] ?? null) ? $pending[$code] : array();
            $deferred_entry = is_array($deferred[$code] ?? null) ? $deferred[$code] : array();
            $existing_entry = !empty($pending_entry) ? $pending_entry : $deferred_entry;
            if (!isset($existing_entry['record_hash']) || !hash_equals((string) $existing_entry['record_hash'], $record_hash)) {
                $pending_entry = array(
                    'product_code' => $code,
                    'record_hash' => $record_hash,
                    'queued_event_id' => $envelope['event_id'],
                    'attempts' => 0,
                );
                $pending[$code] = $pending_entry;
                unset($deferred[$code]);
            }
        }

        ksort($applied, SORT_STRING);
        ksort($pending, SORT_STRING);
        ksort($deferred, SORT_STRING);
        return array(
            'applied_products' => $applied,
            'pending_products' => $pending,
            'deferred_products' => array_slice($deferred, 0, self::MAX_DEFERRED_PRODUCTS, true),
        );
    }

    /**
     * Drain selected durable delivery sets and classify outcomes once.
     *
     * @param array $source_state Source state, updated in place.
     * @param bool  $include_pending Retry transient work.
     * @param bool  $include_deferred Retry terminal reconciliation work.
     * @return array
     */
    private function drain_delivery_products(
        &$source_state,
        $include_pending,
        $include_deferred,
        $resolution_cache = null
    ) {
        $suspend_cache_invalidation = $this->coordinated_transaction_depth > 0
            && function_exists('wp_suspend_cache_invalidation');
        $previous_cache_invalidation = false;
        if ($suspend_cache_invalidation) {
            $previous_cache_invalidation = wp_suspend_cache_invalidation(true);
        }

        try {
            return Digitalogic_Webhooks::instance()->without_product_change_webhooks(
                function () use (&$source_state, $include_pending, $include_deferred, $resolution_cache) {
                    return $this->drain_delivery_products_without_product_change_webhooks(
                        $source_state,
                        $include_pending,
                        $include_deferred,
                        $resolution_cache
                    );
                }
            );
        } finally {
            if ($suspend_cache_invalidation) {
                wp_suspend_cache_invalidation($previous_cache_invalidation);
            }
        }
    }

    /**
     * Drain the durable delivery sets inside the receiver's webhook guard.
     *
     * @param array $source_state Source state, updated in place.
     * @param bool  $include_pending Retry transient work.
     * @param bool  $include_deferred Retry terminal reconciliation work.
     * @return array
     */
    private function drain_delivery_products_without_product_change_webhooks(
        &$source_state,
        $include_pending,
        $include_deferred,
        $resolution_cache = null
    ) {
        $result = array(
            'attempted' => 0,
			'created' => 0,
            'updated' => 0,
            'already_applied' => 0,
            'missing' => 0,
            'ambiguous' => 0,
			'identity_hazard' => 0,
            'failed' => 0,
            'errors' => array(),
            'errors_truncated' => 0,
        );
        $products = is_array($source_state['products'] ?? null) ? $source_state['products'] : array();
        $pending = is_array($source_state['pending_products'] ?? null) ? $source_state['pending_products'] : array();
        $deferred = is_array($source_state['deferred_products'] ?? null) ? $source_state['deferred_products'] : array();
        $applied = is_array($source_state['applied_products'] ?? null) ? $source_state['applied_products'] : array();
        $work = array();
        if ($include_deferred) {
            $work = $deferred;
        }
        if ($include_pending) {
            $work = array_replace($work, $pending);
        }
        if ($include_deferred && $include_pending) {
            // Reconciliation must not retry the same low-code terminal misses
            // forever while higher-code pending or newly resolvable records
            // starve behind the per-request delivery bound. Least-attempted
            // work wins, with exact Product Code as the stable tie-breaker.
            uksort($work, static function($left, $right) use ($work) {
                $left_attempts = max(0, (int) ($work[$left]['attempts'] ?? 0));
                $right_attempts = max(0, (int) ($work[$right]['attempts'] ?? 0));
                return $left_attempts <=> $right_attempts ?: strcmp((string) $left, (string) $right);
            });
        } else {
            ksort($work, SORT_STRING);
        }

        if ($this->coordinated_transaction_depth > 0 && !empty($work)) {
            $pricing_only = true;
            foreach ($work as $delivery_entry) {
                if (empty($delivery_entry['pricing_only'])) {
                    $pricing_only = false;
                    break;
                }
            }
            if ($pricing_only) {
                return $this->drain_coordinated_pricing_batch(
                    $source_state,
                    $work,
                    is_array($resolution_cache) ? $resolution_cache : array()
                );
            }
        }

        foreach ($work as $code_key => $delivery_entry) {
            if ($result['attempted'] >= self::MAX_DELIVERY_PRODUCTS_PER_REQUEST) {
                break;
            }
            $product_code = $this->valid_delivery_product_code($products, $code_key, $delivery_entry);
            if (null === $product_code) {
                unset($pending[$code_key]);
                unset($deferred[$code_key]);
                continue;
            }
            $delivery_entry['product_code'] = $product_code;
            $product_data = $products[$code_key];
            $record_hash = (string) $delivery_entry['record_hash'];
            $force_apply = !empty($delivery_entry['force_apply']);
			$materialization_enabled = (bool) apply_filters(
				'digitalogic_patris_auto_materialize_source_product',
				true,
				$product_data,
				$source_state['source'] ?? array()
			);

            $result['attempted']++;
            $resolved = is_array($resolution_cache) && array_key_exists($product_code, $resolution_cache)
                ? $resolution_cache[$product_code]
                : Digitalogic_Product_Identifier_Resolver::instance()->resolve(array(
                    'patris_code' => $product_code,
                ));
			$created = false;
			if (
				is_wp_error( $resolved )
				&& 'digitalogic_product_identifier_not_found' === $resolved->get_error_code()
				&& $materialization_enabled
			) {
				if ( class_exists( 'Digitalogic_Patris_Catalog_Materializer' ) ) {
					$materialized = Digitalogic_Patris_Catalog_Materializer::instance()->materialize_source_record(
						$product_data,
						is_array( $source_state['source'] ?? null ) ? $source_state['source'] : array(),
						is_array( $source_state['quarantined_codes'] ?? null ) ? $source_state['quarantined_codes'] : array()
					);
					if ( is_wp_error( $materialized ) ) {
						$resolved = $materialized;
					} else {
						$resolved = array(
							'woocommerce_id' => (string) (int) $materialized['woocommerce_id'],
							'resolved_by'     => 'patris_code',
							'value'           => $product_code,
						);
						$created = ! empty( $materialized['created'] );
					}
				} else {
					$resolved = $this->error(
						'digitalogic_patris_materializer_unavailable',
						'The automatic source product materializer is unavailable.',
						503,
						array( 'retryable' => true )
					);
				}
			}
			if ( ! is_wp_error( $resolved ) && class_exists( 'Digitalogic_Patris_Catalog_Materializer' ) ) {
				$target_valid = Digitalogic_Patris_Catalog_Materializer::instance()->validate_source_product_target(
					(int) $resolved['woocommerce_id'],
					is_array( $source_state['source'] ?? null ) ? $source_state['source'] : array()
				);
				if ( is_wp_error( $target_valid ) ) {
					$resolved = $target_valid;
				}
			}
            if (is_wp_error($resolved)) {
                $error_code = $resolved->get_error_code();
                $deferred_reason = $this->terminal_resolution_reason($error_code);
                if ('digitalogic_product_identifier_not_found' === $error_code) {
                    $result['missing']++;
                } elseif ('ambiguous' === $deferred_reason) {
                    $result['ambiguous']++;
				} elseif ( 'identity_hazard' === $deferred_reason ) {
					$result['identity_hazard']++;
                } else {
                    $result['failed']++;
                }
                $this->mark_delivery_failure($delivery_entry, $error_code);
                if (null !== $deferred_reason) {
                    $delivery_entry['reason'] = $deferred_reason;
                    $deferred[$code_key] = $delivery_entry;
                    unset($pending[$code_key]);
                } else {
                    unset($delivery_entry['reason']);
                    $pending[$code_key] = $delivery_entry;
                    unset($deferred[$code_key]);
                }
                $this->append_woo_error($result, array(
                    'product_code' => $product_code,
                    'code' => $error_code,
                    'retryable' => null === $deferred_reason,
                ));
                continue;
            }
			if ( $created ) {
				++$result['created'];
			}

            $woocommerce_id = (int) $resolved['woocommerce_id'];
            $applied_entry = is_array($applied[$code_key] ?? null) ? $applied[$code_key] : array();
            if (
                !$force_apply
                &&
                isset($applied_entry['record_hash'], $applied_entry['woocommerce_id'])
                && hash_equals((string) $applied_entry['record_hash'], $record_hash)
                && (string) $applied_entry['woocommerce_id'] === (string) $woocommerce_id
                && $this->delivery_price_projection_matches($woocommerce_id, $product_data)
				&& ( ! $materialization_enabled || $this->delivery_materialization_projection_matches( $woocommerce_id, $source_state['source'] ?? array() ) )
            ) {
                unset($pending[$code_key]);
                unset($deferred[$code_key]);
                $result['already_applied']++;
                continue;
            }

            $persisted_hash = (string) get_post_meta($woocommerce_id, '_digitalogic_patris_record_hash', true);
            if (
                !$force_apply
                && '' !== $persisted_hash
                && hash_equals($record_hash, $persisted_hash)
                && $this->delivery_price_projection_matches($woocommerce_id, $product_data)
				&& ( ! $materialization_enabled || $this->delivery_materialization_projection_matches( $woocommerce_id, $source_state['source'] ?? array() ) )
            ) {
                $applied[$code_key] = array(
                    'product_code' => $product_code,
                    'record_hash' => $record_hash,
                    'woocommerce_id' => (string) $woocommerce_id,
                );
                unset($pending[$code_key]);
                unset($deferred[$code_key]);
                $result['already_applied']++;
                continue;
            }

            $product = wc_get_product($woocommerce_id);
            if (!$product) {
                $result['failed']++;
                $this->mark_delivery_failure($delivery_entry, 'digitalogic_product_sync_woocommerce_product_unavailable');
                unset($delivery_entry['reason']);
                $pending[$code_key] = $delivery_entry;
                unset($deferred[$code_key]);
                $this->append_woo_error($result, array(
                    'product_code' => $product_code,
                    'code' => 'digitalogic_product_sync_woocommerce_product_unavailable',
                    'retryable' => true,
                ));
                continue;
            }

            try {
				if (
					$materialization_enabled
					&& '1' === (string) $product->get_meta( Digitalogic_Patris_Catalog_Materializer::AUTO_MATERIALIZED_META, true )
				) {
					$shipping_method = array_key_exists( 'shipping_method_id', $product_data )
						&& null !== $product_data['shipping_method_id']
						&& '' !== (string) $product_data['shipping_method_id']
						? (string) $product_data['shipping_method_id']
						: null;
					$assignment = Digitalogic_Shipping_Method_Service::instance()->assign_product_by_code(
						$product_code,
						$shipping_method
					);
					if ( is_wp_error( $assignment ) ) {
						throw new RuntimeException( $assignment->get_error_code() );
					}
				}
                if (!empty($delivery_entry['pricing_only'])) {
                    Digitalogic_Patris_Feed::instance()->apply_product_pricing($product, $product_data);
                } else {
					$feed_write = Digitalogic_Patris_Feed::instance()->apply_product_feed( $product, $product_data );
					if ( is_wp_error( $feed_write ) ) {
						throw new RuntimeException( $feed_write->get_error_code() );
					}
                }
				$committed = null;
				if ( $materialization_enabled ) {
					$committed = Digitalogic_Patris_Catalog_Materializer::instance()->commit_source_product(
						$woocommerce_id,
						$product_data,
						is_array( $source_state['source'] ?? null ) ? $source_state['source'] : array()
					);
					if ( is_wp_error( $committed ) ) {
						throw new RuntimeException( $committed->get_error_code() );
					}
				}
                $persisted_hash = (string) get_post_meta($woocommerce_id, '_digitalogic_patris_record_hash', true);
                if ('' === $persisted_hash || !hash_equals($record_hash, $persisted_hash)) {
                    throw new RuntimeException('WooCommerce record hash readback failed.');
                }
                $applied[$code_key] = array(
                    'product_code' => $product_code,
                    'record_hash' => $record_hash,
                    'woocommerce_id' => (string) $woocommerce_id,
                );
                unset($pending[$code_key]);
                unset($deferred[$code_key]);
                $result['updated']++;
				if ( is_array( $committed ) ) {
					$this->queue_materializer_product_committed( $committed );
				}
            } catch (Throwable $exception) {
                $result['failed']++;
                $this->mark_delivery_failure($delivery_entry, 'digitalogic_product_sync_woocommerce_write_failed');
                unset($delivery_entry['reason']);
                $pending[$code_key] = $delivery_entry;
                unset($deferred[$code_key]);
                $this->append_woo_error($result, array(
                    'product_code' => $product_code,
                    'code' => 'digitalogic_product_sync_woocommerce_write_failed',
                    'retryable' => true,
                ));
            }
        }

        ksort($pending, SORT_STRING);
        ksort($deferred, SORT_STRING);
        ksort($applied, SORT_STRING);
        $source_state['pending_products'] = $pending;
        $source_state['deferred_products'] = array_slice($deferred, 0, self::MAX_DEFERRED_PRODUCTS, true);
        $source_state['applied_products'] = $applied;
        $result['pending'] = count($pending);
        $result['deferred'] = count($source_state['deferred_products']);

        return $result;
    }

    /**
     * Drain a pricing-only coordinated transaction with one bounded SQL writer.
     *
     * @param array $source_state      Source state, updated in place.
     * @param array $work              Durable pricing delivery entries.
     * @param array $resolution_cache  Bulk exact identity results.
     * @return array
     */
    private function drain_coordinated_pricing_batch(&$source_state, $work, $resolution_cache) {
        $result = array(
            'attempted' => 0,
            'updated' => 0,
            'already_applied' => 0,
            'missing' => 0,
            'ambiguous' => 0,
            'failed' => 0,
            'errors' => array(),
            'errors_truncated' => 0,
            'batch_count' => 0,
            'batch_meta_rows' => 0,
            'verified_product_codes' => array(),
        );
        $products = is_array($source_state['products'] ?? null) ? $source_state['products'] : array();
        $pending = is_array($source_state['pending_products'] ?? null) ? $source_state['pending_products'] : array();
        $deferred = is_array($source_state['deferred_products'] ?? null) ? $source_state['deferred_products'] : array();
        $applied = is_array($source_state['applied_products'] ?? null) ? $source_state['applied_products'] : array();
        $batch_items = array();
        $batch_entries = array();

        foreach ($work as $code_key => $delivery_entry) {
            $product_code = $this->valid_delivery_product_code($products, $code_key, $delivery_entry);
            if (null === $product_code) {
                unset($pending[$code_key], $deferred[$code_key]);
                continue;
            }
            $delivery_entry['product_code'] = $product_code;
            $product_data = $products[$code_key];
            $record_hash = (string) $delivery_entry['record_hash'];
            ++$result['attempted'];
            $resolved = array_key_exists($product_code, $resolution_cache)
                ? $resolution_cache[$product_code]
                : Digitalogic_Product_Identifier_Resolver::instance()->resolve(
                    array('patris_code' => $product_code)
                );
            if (is_wp_error($resolved)) {
                $error_code = $resolved->get_error_code();
                $deferred_reason = $this->terminal_resolution_reason($error_code);
                if ('missing' === $deferred_reason) {
                    ++$result['missing'];
                } elseif ('ambiguous' === $deferred_reason) {
                    ++$result['ambiguous'];
                } else {
                    ++$result['failed'];
                }
                $this->mark_delivery_failure($delivery_entry, $error_code);
                if (null !== $deferred_reason) {
                    $delivery_entry['reason'] = $deferred_reason;
                    $deferred[$code_key] = $delivery_entry;
                    unset($pending[$code_key]);
                } else {
                    unset($delivery_entry['reason']);
                    $pending[$code_key] = $delivery_entry;
                    unset($deferred[$code_key]);
                }
                $this->append_woo_error(
                    $result,
                    array(
                        'product_code' => $product_code,
                        'code' => $error_code,
                        'retryable' => null === $deferred_reason,
                    )
                );
                continue;
            }

            $woocommerce_id = (int) $resolved['woocommerce_id'];
            $product = wc_get_product($woocommerce_id);
            if (!$product) {
                ++$result['failed'];
                $this->mark_delivery_failure(
                    $delivery_entry,
                    'digitalogic_product_sync_woocommerce_product_unavailable'
                );
                unset($delivery_entry['reason']);
                $pending[$code_key] = $delivery_entry;
                unset($deferred[$code_key]);
                $this->append_woo_error(
                    $result,
                    array(
                        'product_code' => $product_code,
                        'code' => 'digitalogic_product_sync_woocommerce_product_unavailable',
                        'retryable' => true,
                    )
                );
                continue;
            }
            $batch_items[] = array(
                'product' => $product,
                'data' => $product_data,
                'product_code' => $product_code,
            );
            $batch_entries[] = array(
                'code_key' => $code_key,
                'product_code' => $product_code,
                'record_hash' => $record_hash,
                'woocommerce_id' => $woocommerce_id,
                'delivery_entry' => $delivery_entry,
            );
        }

        if (!empty($batch_items)) {
            $written = Digitalogic_Patris_Feed::instance()->apply_product_pricing_batch($batch_items);
            if (is_wp_error($written)) {
                if (
                    in_array(
                        $written->get_error_code(),
                        array(
                            'digitalogic_pricing_batch_product_unsupported',
                            'digitalogic_pricing_batch_shipping_assignment_mismatch',
                        ),
                        true
                    )
                ) {
                    $result['fatal_error_code'] = 'digitalogic_pricing_delivery_readback_failed';
                }
                foreach ($batch_entries as $entry) {
                    ++$result['failed'];
                    $delivery_entry = $entry['delivery_entry'];
                    $this->mark_delivery_failure($delivery_entry, $written->get_error_code());
                    unset($delivery_entry['reason']);
                    $pending[$entry['code_key']] = $delivery_entry;
                    unset($deferred[$entry['code_key']]);
                    $this->append_woo_error(
                        $result,
                        array(
                            'product_code' => $entry['product_code'],
                            'code' => $written->get_error_code(),
                            'retryable' => true,
                        )
                    );
                }
            } else {
                $this->coordinated_batch_write = true;
                $result['batch_count'] = (int) ($written['batches'] ?? 0);
                $result['batch_meta_rows'] = (int) ($written['meta_rows'] ?? 0);
                $result['pricing_warnings'] = array_values((array) ($written['warnings'] ?? array()));
                foreach ($batch_entries as $entry) {
                    $applied[$entry['code_key']] = array(
                        'product_code' => $entry['product_code'],
                        'record_hash' => $entry['record_hash'],
                        'woocommerce_id' => (string) $entry['woocommerce_id'],
                    );
                    unset($pending[$entry['code_key']], $deferred[$entry['code_key']]);
                    ++$result['updated'];
                    $result['verified_product_codes'][] = $entry['product_code'];
                }
            }
        }

        ksort($pending, SORT_STRING);
        ksort($deferred, SORT_STRING);
        ksort($applied, SORT_STRING);
        $source_state['pending_products'] = $pending;
        $source_state['deferred_products'] = array_slice($deferred, 0, self::MAX_DEFERRED_PRODUCTS, true);
        $source_state['applied_products'] = $applied;
        $result['pending'] = count($pending);
        $result['deferred'] = count($source_state['deferred_products']);

        return $result;
    }

    private function valid_delivery_product_code($products, $code_key, $entry) {
        $product_code = $this->delivery_product_code($products, $code_key, $entry);
        if (
            null === $product_code
            || !is_array($entry)
            || !isset($entry['record_hash'])
            || !hash_equals((string) $products[$code_key]['record_hash'], (string) $entry['record_hash'])
        ) {
            return null;
        }

        return $product_code;
    }

    /**
     * Restore a validated canonical Code from durable product data.
     *
     * Numeric-string PHP array keys are integers after insertion and after
     * WordPress option serialization. The product value remains string-typed,
     * so it is the authoritative resolver and response boundary value.
     *
     * @return string|null
     */
    private function delivery_product_code($products, $code_key, $entry = null) {
        if (!array_key_exists($code_key, $products) || !is_array($products[$code_key])) {
            return null;
        }
        $product_code = $products[$code_key]['product_code'] ?? null;
        if (!is_string($product_code) || (string) $code_key !== $product_code) {
            return null;
        }
        if (
            is_array($entry)
            && array_key_exists('product_code', $entry)
            && (!is_string($entry['product_code']) || $entry['product_code'] !== $product_code)
        ) {
            return null;
        }

        return $product_code;
    }

    /**
     * Verify the canonical/source price projection before trusting a stored hash.
     *
     * A matching receiver/applied record hash only proves what should have been
     * written. It cannot prove that Woo metadata and customer prices still match
     * after a failed or legacy write. Sparse unpriced rows remain compatible:
     * they may preserve one valid Woo fallback, but never gain a fabricated
     * canonical Patris price.
     *
     * @param int   $woocommerce_id WooCommerce product ID.
     * @param array $product         Canonical stored product.
     * @return bool
     */
    private function delivery_price_projection_matches($woocommerce_id, $product) {
        if (
            $woocommerce_id <= 0
            || !is_array($product)
            || (string) get_post_meta($woocommerce_id, '_digitalogic_patris_product_code', true)
                !== (string) ($product['product_code'] ?? '')
        ) {
            return false;
        }

        $source_meta = array(
            'price_source_amount' => '_digitalogic_patris_price_source_amount',
            'price_source_currency' => '_digitalogic_patris_price_source_currency',
            'price_source_kind' => '_digitalogic_patris_price_source_kind',
        );
        foreach ($source_meta as $field => $meta_key) {
            if (!array_key_exists($field, $product) || null === $product[$field]) {
                if (metadata_exists('post', $woocommerce_id, $meta_key)) {
                    return false;
                }
                continue;
            }
            if (
                !metadata_exists('post', $woocommerce_id, $meta_key)
                || (string) get_post_meta($woocommerce_id, $meta_key, true) !== (string) $product[$field]
            ) {
                return false;
            }
        }

        $has_final_price = array_key_exists('final_price', $product) && null !== $product['final_price'];
        if (!$has_final_price) {
            if (metadata_exists('post', $woocommerce_id, '_digitalogic_patris_final_price')) {
                return false;
            }
        } elseif (
            !metadata_exists('post', $woocommerce_id, '_digitalogic_patris_final_price')
            || (string) get_post_meta($woocommerce_id, '_digitalogic_patris_final_price', true)
                !== (string) $product['final_price']
        ) {
            return false;
        }

        $woo_product = wc_get_product($woocommerce_id);
        if (!$woo_product) {
            return false;
        }
        if ($woo_product->is_type('variable')) {
            return true;
        }

        $regular = trim((string) $woo_product->get_regular_price());
        $visible = trim((string) $woo_product->get_price());
        $sale = trim((string) $woo_product->get_sale_price());
        if ($has_final_price) {
            $final_price = (string) $product['final_price'];
            return $regular === $final_price && $visible === $final_price && '' === $sale;
        }

        $status = (string) $woo_product->get_meta(Digitalogic_Patris_Price_Policy::STATUS_META, true);
        if ('canonical_missing_preserved' === $status) {
            return '' !== $regular
                && is_numeric($regular)
                && (float) $regular > 0
                && $regular === $visible
                && '' === $sale
                && Digitalogic_Patris_Price_Policy::MISSING_WEIGHT_WARNING
                    === (string) $woo_product->get_meta(Digitalogic_Patris_Price_Policy::WARNING_META, true);
        }

        return 'canonical_missing_unpriced' === $status
            && '' === $regular
            && '' === $visible
            && '' === $sale;
    }

	/** Verify the public materialization marker before trusting an applied hash. */
	private function delivery_materialization_projection_matches( $woocommerce_id, $source ) {
		$product = $woocommerce_id > 0 ? wc_get_product( $woocommerce_id ) : false;
		if (
			! $product instanceof WC_Product
			|| 'publish' !== (string) $product->get_status()
			|| ( ! $product->is_type( 'variation' ) && 'visible' !== (string) $product->get_catalog_visibility() )
			|| ! metadata_exists( 'post', $woocommerce_id, Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META )
		) {
			return false;
		}
		$missing = json_decode(
			(string) get_post_meta( $woocommerce_id, Digitalogic_Patris_Catalog_Materializer::MISSING_FIELDS_META, true ),
			true
		);
		if ( ! is_array( $missing ) || ! array_is_list( $missing ) ) {
			return false;
		}

		return is_array( $source )
			&& hash_equals(
				(string) ( $source['revision'] ?? '' ),
				(string) get_post_meta( $woocommerce_id, Digitalogic_Patris_Catalog_Materializer::SOURCE_REVISION_META, true )
			);
	}

    private function prune_delivery_set($products, $delivery_set) {
        foreach ($delivery_set as $code_key => $entry) {
            $product_code = $this->valid_delivery_product_code($products, $code_key, $entry);
            if (null === $product_code) {
                unset($delivery_set[$code_key]);
                continue;
            }
            $entry['product_code'] = $product_code;
            $delivery_set[$code_key] = $entry;
        }

        return $delivery_set;
    }

    private function mark_delivery_failure(&$delivery_entry, $code) {
        $attempts = max(0, (int) ($delivery_entry['attempts'] ?? 0));
        $delivery_entry['attempts'] = $attempts < PHP_INT_MAX ? $attempts + 1 : PHP_INT_MAX;
        $delivery_entry['last_error'] = (string) $code;
        $delivery_entry['last_attempt_at'] = current_time('mysql');
    }

    private function append_woo_error(&$result, $error) {
        if (count($result['errors']) < self::MAX_RESULT_ERRORS) {
            $result['errors'][] = $error;
        } else {
            $result['errors_truncated']++;
        }
    }

    private function terminal_resolution_reason($error_code) {
        if ('digitalogic_product_identifier_ambiguous' === $error_code) {
            return 'ambiguous';
        }
		if ( 'digitalogic_patris_materializer_identity_hazard' === $error_code ) {
			return 'identity_hazard';
		}

        return null;
    }

    private function deferred_summary($deferred) {
        $deferred = is_array($deferred) ? $deferred : array();
        ksort($deferred, SORT_STRING);
        $summary = array(
            'missing' => 0,
            'ambiguous' => 0,
			'identity_hazard' => 0,
            'details' => array(),
            'details_truncated' => 0,
        );
        foreach ($deferred as $code => $entry) {
            $product_code = is_array($entry) && is_string($entry['product_code'] ?? null)
                ? $entry['product_code']
                : (string) $code;
            $reason = is_array($entry) ? (string) ($entry['reason'] ?? '') : '';
            if ('ambiguous' === $reason) {
                $summary['ambiguous']++;
			} elseif ( 'identity_hazard' === $reason ) {
				$summary['identity_hazard']++;
            } else {
                $reason = 'missing';
                $summary['missing']++;
            }
            if (count($summary['details']) < self::MAX_RESULT_ERRORS) {
                $summary['details'][] = array(
                    'product_code' => $product_code,
                    'reason' => $reason,
                    'code' => is_array($entry) ? (string) ($entry['last_error'] ?? '') : '',
                );
            } else {
                $summary['details_truncated']++;
            }
        }

        return $summary;
    }

    private function delivery_result_state($source_state) {
        $pending = is_array($source_state['pending_products'] ?? null)
            ? $source_state['pending_products']
            : array();
        $deferred = is_array($source_state['deferred_products'] ?? null)
            ? $source_state['deferred_products']
            : array();
        $fully_applied = empty($pending);

        return array(
            'fully_applied' => $fully_applied,
            'retryable' => !$fully_applied,
            'pending_products' => count($pending),
            'deferred_products' => count($deferred),
            'deferred_reconciliation' => $this->deferred_summary($deferred),
        );
    }

	/** Return only exact source identities safe to persist in a pricing marker. */
	private function source_identity_state( $state ) {
		$identities = array( 'sources' => array() );
		foreach ( (array) ( is_array( $state ) ? ( $state['sources'] ?? array() ) : array() ) as $key => $entry ) {
			$source = is_array( $entry ) && is_array( $entry['source'] ?? null ) ? $entry['source'] : array();
			if (
				'' === (string) ( $source['id'] ?? '' )
				|| '' === (string) ( $source['dataset'] ?? '' )
				|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $source['revision'] ?? '' ) )
			) {
				continue;
			}
			$identities['sources'][ (string) $key ] = array(
				'source' => array(
					'id'       => (string) $source['id'],
					'dataset'  => (string) $source['dataset'],
					'revision' => (string) $source['revision'],
				),
			);
		}

		return $identities;
	}

    private function source_status($source_state) {
        $source = is_array($source_state['source'] ?? null) ? $source_state['source'] : array();

        return array(
            'source' => array(
                'id' => (string) ($source['id'] ?? ''),
                'dataset' => (string) ($source['dataset'] ?? ''),
                'revision' => (string) ($source['revision'] ?? ''),
            ),
            'generated_at' => (string) ($source_state['generated_at'] ?? ''),
            'last_event_id' => (string) ($source_state['last_event_id'] ?? ''),
            'stored_products' => count(is_array($source_state['products'] ?? null) ? $source_state['products'] : array()),
            'stored_categories' => count(is_array($source_state['categories'] ?? null) ? $source_state['categories'] : array()),
            'excluded_codes' => count(is_array($source_state['excluded_codes'] ?? null) ? $source_state['excluded_codes'] : array()),
            'applied_products' => count(is_array($source_state['applied_products'] ?? null) ? $source_state['applied_products'] : array()),
            'pending_products' => count(is_array($source_state['pending_products'] ?? null) ? $source_state['pending_products'] : array()),
            'deferred_products' => count(is_array($source_state['deferred_products'] ?? null) ? $source_state['deferred_products'] : array()),
        );
    }
    // phpcs:enable

    private function persist_and_read_back($state) {
        global $wpdb;
		$owns_transaction = $this->coordinated_transaction_depth <= 0;
        if (
            !is_object($wpdb)
            || !isset($wpdb->options)
            || !method_exists($wpdb, 'query')
            || !method_exists($wpdb, 'get_row')
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'insert')
            || !method_exists($wpdb, 'update')
        ) {
            return $this->error('digitalogic_product_sync_storage_unavailable', 'The receiver storage service is unavailable.', 503);
        }
		if ( $owns_transaction && false === $wpdb->query( 'START TRANSACTION' ) ) {
            return $this->error('digitalogic_product_sync_transaction_unavailable', 'The receiver could not start a storage transaction.', 503);
        }

        $serialized = maybe_serialize($state);
        if (!is_string($serialized) || strlen($serialized) > self::MAX_STATE_BYTES) {
			if ( $owns_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}

            return $this->error('digitalogic_product_sync_state_too_large', 'The combined receiver state is too large.', 413);
        }
        $expected_digest = hash('sha256', $serialized);
        $row             = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
            self::STATE_OPTION
        ), ARRAY_A);
		$previous_state  = is_array( $row ) && is_string( $row['option_value'] ?? null )
			? maybe_unserialize( $row['option_value'] )
			: array( 'sources' => array() );
		$previous_state  = is_array( $previous_state ) ? $previous_state : array( 'sources' => array() );
        if (is_array($row)) {
            $written = $wpdb->update(
                $wpdb->options,
                array('option_value' => $serialized),
                array('option_name' => self::STATE_OPTION),
                array('%s'),
                array('%s')
            );
        } else {
            $written = $wpdb->insert(
                $wpdb->options,
                array(
					'option_name'  => self::STATE_OPTION,
                    'option_value' => $serialized,
                    'autoload'     => 'no',
                ),
                array('%s', '%s', '%s')
            );
        }
        if (false === $written || 0 === $written) {
			if ( $owns_transaction ) {
				$wpdb->query( 'ROLLBACK' );
			}
            $this->invalidate_state_cache();

            return $this->error('digitalogic_product_sync_storage_failed', 'The receiver state could not be stored.', 500);
        }

        $read_row        = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::STATE_OPTION
        ), ARRAY_A);
        $read_serialized = is_array($read_row) && is_string($read_row['option_value'] ?? null)
            ? $read_row['option_value']
            : null;
		$read_back       = is_string( $read_serialized ) ? maybe_unserialize( $read_serialized ) : null;
        if (
            !is_array($read_back)
            || !is_string($read_serialized)
            || !hash_equals($expected_digest, hash('sha256', $read_serialized))
        ) {
            if ($owns_transaction) {
				$wpdb->query( 'ROLLBACK' );
            }
            $this->invalidate_state_cache();

            return $this->error('digitalogic_product_sync_readback_failed', 'Stored receiver state did not pass readback verification.', 500);
        }
        if ($owns_transaction && false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            $this->invalidate_state_cache();

            return $this->error('digitalogic_product_sync_commit_failed', 'The receiver state transaction could not be committed.', 500);
        }

        $this->invalidate_state_cache();
		if ( $owns_transaction ) {
			do_action( 'digitalogic_product_sync_state_committed', $previous_state, $read_back );
		}

        return $read_back;
    }

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    private function replay_result($envelope, $existing) {
        return array_merge(array(
            'status' => 'replayed',
            'replayed' => true,
            'event_id' => $envelope['event_id'],
            'event_type' => $envelope['event_type'],
            'source' => $envelope['source'],
            'stored_products' => count($existing['products'] ?? array()),
            'stored_categories' => count($existing['categories'] ?? array()),
            'excluded_codes' => count($existing['excluded_codes'] ?? array()),
        ), $this->delivery_result_state($existing));
    }
    // phpcs:enable

    /**
     * Independently evaluate the selected living price formula.
     *
     * A complete CNY route has priority and uses the landed-price formula with
     * currency-qualified freight. A CNY fact without usable weight and a
     * selected freight method is not a selectable route, so the domestic IRR
     * partner-price fallback may be used. That fallback consumes no freight,
     * weight, or FX input; its canonical domestic method carries an explicit
     * zero IRR rate. Foreign and partner paths apply markup once and then round
     * once. The opt-in sale_price_direct last fallback only converts the raw IRR
     * fact to contract IRT; it consumes no freight, markup, or rounding policy.
     */
    private function validate_final_price_formula($product, $path, $pricing_active) {
        if (!$pricing_active) {
            return true;
        }
        $direct_sale_selected = 'sale_price_direct' === ($product['price_source_kind'] ?? null);
        if (!$direct_sale_selected && null === $product['price_rounding_digits']) {
            if (!array_key_exists('final_price', $product)) {
                return true;
            }

            return $this->error(
                'digitalogic_product_sync_final_price_mismatch',
                'final_price must be omitted when price_rounding_digits is explicitly null.',
                422,
                array('path' => $path . '.final_price', 'expected' => 'omitted')
            );
        }

        $source_fields  = array('price_source_amount', 'price_source_currency', 'price_source_kind');
        $has_source     = count(array_intersect($source_fields, array_keys($product))) === count($source_fields);
        $complete_markup = false;
        if (
            array_key_exists('markup_percent', $product)
            && null !== $product['markup_percent']
            && $this->number_compare_zero($product['markup_percent']) >= 0
        ) {
            $markup_parts = $this->formula_decimal_parts($product['markup_percent']);
            $complete_markup = !isset($markup_parts['error'])
                && $this->decimal_compare($markup_parts, $this->formula_decimal_parts(self::MAX_MARKUP_PERCENT)) <= 0;
        }
        $complete_rounding = array_key_exists('price_rounding_digits', $product)
            && null !== $product['price_rounding_digits']
            && $this->is_nonnegative_integer($product['price_rounding_digits'])
            && (int) $this->number_to_storage($product['price_rounding_digits']) <= 9
            && array_key_exists('price_rounding_mode', $product)
            && 'nearest_half_up' === $product['price_rounding_mode'];
        $usable_cny_fact = array_key_exists('foreign_price', $product)
            && null !== $product['foreign_price']
            && $this->number_compare_zero($product['foreign_price']) > 0
            && array_key_exists('foreign_currency', $product)
            && 'CNY' === $product['foreign_currency'];
        $complete_cny_route = $usable_cny_fact
            && array_key_exists('weight_grams', $product)
            && null !== $product['weight_grams']
            && $this->number_compare_zero($product['weight_grams']) > 0
            && array_key_exists('shipping_method_id', $product)
            && null !== $product['shipping_method_id']
            && '' !== $product['shipping_method_id']
            && Digitalogic_Shipping_Method_Service::DOMESTIC_METHOD_ID !== $product['shipping_method_id']
            && array_key_exists('shipping_price_per_kg', $product)
            && null !== $product['shipping_price_per_kg']
            && $this->number_compare_zero($product['shipping_price_per_kg']) > 0
            && array_key_exists('shipping_price_per_kg_currency', $product)
            && in_array($product['shipping_price_per_kg_currency'], array('CNY', 'IRR'), true)
            && array_key_exists('irt_per_cny', $product)
            && null !== $product['irt_per_cny']
            && $this->number_compare_zero($product['irt_per_cny']) > 0
            && $complete_markup
            && $complete_rounding;
        $usable_partner = array_key_exists('partner_price_source', $product)
            && null !== $product['partner_price_source']
            && $this->number_compare_zero($product['partner_price_source']) > 0;
        $complete_partner_route = $usable_partner
            && $complete_markup
            && $complete_rounding;

        if (!$has_source) {
            if ($complete_cny_route || $complete_partner_route) {
                return $this->error(
                    'digitalogic_product_sync_price_source_missing',
                    'A usable source price requires explicit selected-price provenance.',
                    422,
                    array('path' => $path . '.price_source_amount')
                );
            }
            if (!array_key_exists('final_price', $product)) {
                return true;
            }

            return $this->error(
                'digitalogic_product_sync_final_price_mismatch',
                'final_price must be omitted when no usable price source is selected.',
                422,
                array('path' => $path . '.final_price', 'expected' => 'omitted')
            );
        }

        $source_amount = $this->formula_decimal_parts($product['price_source_amount']);
        if (isset($source_amount['error'])) {
            return $this->field_error($path . '.price_source_amount', $source_amount['error']);
        }
        if ('foreign_price' === $product['price_source_kind']) {
            $raw_source_field = 'foreign_price';
        } elseif ('partner_price' === $product['price_source_kind']) {
            $raw_source_field = 'partner_price_source';
        } else {
            $raw_source_field = 'sale_price_source';
        }
        if (!array_key_exists($raw_source_field, $product) || null === $product[$raw_source_field]) {
            return $this->field_error($path . '.' . $raw_source_field, 'must contain the selected source amount');
        }
        $raw_source = $this->formula_decimal_parts($product[$raw_source_field]);
        if (isset($raw_source['error'])) {
            return $this->field_error($path . '.' . $raw_source_field, $raw_source['error']);
        }
        if (0 !== $this->decimal_compare($source_amount, $raw_source)) {
            return $this->error(
                'digitalogic_product_sync_price_source_mismatch',
                'Selected price-source provenance does not match the raw source fact.',
                422,
                array('path' => $path . '.price_source_amount', 'source_field' => $raw_source_field)
            );
        }

        if ('foreign_price' === $product['price_source_kind']) {
            if (
                array_key_exists('weight_grams', $product)
                && null !== $product['weight_grams']
                && $this->number_compare_zero($product['weight_grams']) <= 0
            ) {
                return $this->field_error($path . '.weight_grams', 'must be greater than zero for foreign freight pricing');
            }
            if ('CNY' !== $product['price_source_currency'] || 'CNY' !== ($product['foreign_currency'] ?? null)) {
                return $this->field_error($path . '.price_source_currency', 'must be CNY for foreign_price');
            }
        } elseif ('partner_price' === $product['price_source_kind']) {
            if ('IRR' !== $product['price_source_currency']) {
                return $this->field_error($path . '.price_source_currency', 'must be IRR for partner_price');
            }
            if ($complete_cny_route) {
                return $this->error(
                    'digitalogic_product_sync_price_source_priority',
                    'A complete CNY freight route must be selected before the domestic partner-price fallback.',
                    422,
                    array('path' => $path . '.price_source_kind')
                );
            }
        } else {
            if ('IRR' !== $product['price_source_currency']) {
                return $this->field_error($path . '.price_source_currency', 'must be IRR for sale_price_direct');
            }
            if ($complete_cny_route || $complete_partner_route) {
                return $this->error(
                    'digitalogic_product_sync_price_source_priority',
                    'sale_price_direct is the last fallback after complete foreign and partner routes.',
                    422,
                    array('path' => $path . '.price_source_kind')
                );
            }
        }

		$evaluated = $this->evaluate_final_price_formula( $product, $path );
		if ( is_wp_error( $evaluated ) ) {
			return $evaluated;
		}
		if ( empty( $evaluated['available'] ) ) {
			if ( ! array_key_exists( 'final_price', $product ) ) {
				return true;
			}

			return $this->error(
                'digitalogic_product_sync_final_price_mismatch',
				'final_price must be omitted when selected-price inputs are incomplete.',
				422,
				array(
					'path'     => $path . '.final_price',
					'expected' => 'omitted',
                    'missing' => $evaluated['missing'],
				)
			);
		}
		if ( ! array_key_exists( 'final_price', $product ) ) {
			return $this->field_error( $path . '.final_price', 'is required when all selected-price inputs are available' );
		}

		$actual = $this->number_to_storage( $product['final_price'] );
		if ( ! is_int( $actual ) || $actual !== $evaluated['value'] ) {
			$message = 'sale_price_direct' === $product['price_source_kind']
				? 'final_price does not match the direct source sale amount converted from IRR to IRT.'
                : 'final_price does not match independently evaluated landed_price.';

			return $this->error(
                'digitalogic_product_sync_final_price_mismatch',
				$message,
				422,
				array(
					'path'     => $path . '.final_price',
					'expected' => $evaluated['value'],
					'actual'   => $actual,
				)
			);
		}

		return true;
	}

	/**
	 * Evaluate one selected route without choosing a source on the caller's behalf.
	 *
	 * Foreign CNY pricing adds exact item and weight-based freight costs, converts
	 * them to IRT, applies markup once, then rounds once. Partner IRR pricing uses
	 * the canonical zero-rate domestic route before markup and rounding. Direct
	 * sale pricing only performs an exact IRR-to-IRT division and is deliberately
	 * independent from freight, FX, markup, and rounding settings.
	 *
	 * @param array  $product Canonical product record.
	 * @param string $path    Error path.
	 * @return array|WP_Error
	 */
    private function evaluate_final_price_formula($product, $path) {
		$kind = isset( $product['price_source_kind'] ) && is_string( $product['price_source_kind'] )
			? $product['price_source_kind']
			: '';
		if ( '' === $kind ) {
			return array(
				'available' => false,
				'missing'   => array( 'price_source_kind' ),
			);
        }
		if ( ! in_array( $kind, array( 'foreign_price', 'partner_price', 'sale_price_direct' ), true ) ) {
			return $this->field_error( $path . '.price_source_kind', 'contains an unsupported selected price source' );
		}

        $source_fields = array('price_source_amount', 'price_source_currency', 'price_source_kind');
        $missing = array();
		foreach ( $source_fields as $field ) {
			if ( ! array_key_exists( $field, $product ) || null === $product[ $field ] || '' === $product[ $field ] ) {
                $missing[] = $field;
            }
		}
		if ( ! empty( $missing ) ) {
            return array(
				'available' => false,
				'missing'   => $missing,
			);
		}

		$source_amount = $this->formula_decimal_parts( $product['price_source_amount'] );
		if ( isset( $source_amount['error'] ) ) {
			return $this->field_error( $path . '.price_source_amount', $source_amount['error'] );
		}
		if ( $this->decimal_compare( $source_amount, $this->formula_decimal_parts( '0' ) ) <= 0 ) {
			return $this->field_error( $path . '.price_source_amount', 'must be greater than zero when selected' );
		}

		if ( 'foreign_price' === $kind ) {
			if ( 'CNY' !== $product['price_source_currency'] ) {
				return $this->field_error( $path . '.price_source_currency', 'must be CNY for foreign_price' );
			}
			$required = array(
				'weight_grams',
				'shipping_method_id',
				'shipping_price_per_kg',
				'shipping_price_per_kg_currency',
				'markup_percent',
				'irt_per_cny',
				'price_rounding_digits',
				'price_rounding_mode',
			);
		} elseif ( 'partner_price' === $kind ) {
			if ( 'IRR' !== $product['price_source_currency'] ) {
				return $this->field_error( $path . '.price_source_currency', 'must be IRR for partner_price' );
			}
			$required = array(
				'shipping_method_id',
				'shipping_price_per_kg',
				'shipping_price_per_kg_currency',
				'markup_percent',
				'price_rounding_digits',
				'price_rounding_mode',
			);
		} else {
			if ( 'IRR' !== $product['price_source_currency'] ) {
                return $this->field_error($path . '.price_source_currency', 'must be IRR for sale_price_direct');
            }
			$required = array(
				'shipping_method_id',
				'shipping_price_per_kg',
				'shipping_price_per_kg_currency',
			);
        }
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $product ) || null === $product[ $field ] || '' === $product[ $field ] ) {
				$missing[] = $field;
            }
		}

		if ( 'foreign_price' === $kind ) {
            if (
				array_key_exists( 'shipping_method_id', $product )
				&& (
					'' === $product['shipping_method_id']
					|| Digitalogic_Shipping_Method_Service::DOMESTIC_METHOD_ID === $product['shipping_method_id']
				)
            ) {
				$missing[] = 'shipping_method_id';
            }
            if (
				array_key_exists( 'shipping_price_per_kg_currency', $product )
				&& ! in_array( $product['shipping_price_per_kg_currency'], array( 'CNY', 'IRR' ), true )
            ) {
				return $this->field_error(
					$path . '.shipping_price_per_kg_currency',
					'must be CNY or IRR for foreign freight pricing'
				);
            }
        } else {
            if (
				array_key_exists( 'shipping_method_id', $product )
				&& Digitalogic_Shipping_Method_Service::DOMESTIC_METHOD_ID !== $product['shipping_method_id']
            ) {
                $missing[] = 'shipping_method_id';
            }
            if (
                array_key_exists('shipping_price_per_kg_currency', $product)
				&& 'IRR' !== $product['shipping_price_per_kg_currency']
            ) {
                $missing[] = 'shipping_price_per_kg_currency';
            }
        }
		$missing = array_values( array_unique( $missing ) );
        if (!empty($missing)) {
			return array(
				'available' => false,
				'missing'   => $missing,
            );
        }

		$shipping_rate = $this->formula_decimal_parts( $product['shipping_price_per_kg'] );
		if ( isset( $shipping_rate['error'] ) ) {
			return $this->field_error( $path . '.shipping_price_per_kg', $shipping_rate['error'] );
        }
		if ( 'foreign_price' === $kind ) {
			if ( $this->decimal_compare( $shipping_rate, $this->formula_decimal_parts( '0' ) ) <= 0 ) {
				return array(
					'available' => false,
					'missing'   => array( 'shipping_price_per_kg' ),
				);
			}
			$weight      = $this->formula_decimal_parts( $product['weight_grams'] );
			$markup      = $this->formula_decimal_parts( $product['markup_percent'] );
			$irt_per_cny = $this->formula_decimal_parts( $product['irt_per_cny'] );
			foreach (
				array(
					'weight_grams'   => $weight,
					'markup_percent' => $markup,
					'irt_per_cny'    => $irt_per_cny,
				) as $field => $decimal
			) {
                if (isset($decimal['error'])) {
					return $this->field_error( $path . '.' . $field, $decimal['error'] );
				}
			}
			if ( $this->decimal_compare( $weight, $this->formula_decimal_parts( '0' ) ) <= 0 ) {
				return array(
					'available' => false,
					'missing'   => array( 'weight_grams' ),
				);
			}
			if ( $this->decimal_compare( $irt_per_cny, $this->formula_decimal_parts( '0' ) ) <= 0 ) {
				return $this->field_error( $path . '.irt_per_cny', 'must be greater than zero' );
			}

			$goods_irt               = $this->decimal_multiply( $source_amount, $irt_per_cny );
			$shipping_cost           = $this->decimal_multiply( $weight, $shipping_rate );
            $shipping_cost['scale'] += 3; // grams to kilograms, exactly.
            if ('CNY' === $product['shipping_price_per_kg_currency']) {
				$shipping_irt = $this->decimal_multiply( $shipping_cost, $irt_per_cny );
            } else {
				$shipping_irt           = $shipping_cost;
                $shipping_irt['scale'] += 1; // IRR to IRT, exactly.
            }
			$base_irt = $this->decimal_add( $goods_irt, $shipping_irt );
		} elseif ( 'partner_price' === $kind ) {
			if ( 0 !== $this->decimal_compare( $shipping_rate, $this->formula_decimal_parts( '0' ) ) ) {
				return array(
					'available' => false,
					'missing'   => array( 'shipping_price_per_kg' ),
				);
			}
			$markup = $this->formula_decimal_parts( $product['markup_percent'] );
			if ( isset( $markup['error'] ) ) {
				return $this->field_error( $path . '.markup_percent', $markup['error'] );
			}
            $base_irt = $source_amount;
            $base_irt['scale'] += 1; // IRR to IRT, exactly.
        } else {
			if ( 0 !== $this->decimal_compare( $shipping_rate, $this->formula_decimal_parts( '0' ) ) ) {
				return array(
					'available' => false,
					'missing'   => array( 'shipping_price_per_kg' ),
				);
			}
            $direct_irt = $source_amount;
            $direct_irt['scale'] += 1; // IRR to IRT, exactly.
            while ($direct_irt['scale'] > 0 && str_ends_with($direct_irt['digits'], '0')) {
                $direct_irt['digits'] = substr($direct_irt['digits'], 0, -1);
                --$direct_irt['scale'];
            }
            if ($direct_irt['scale'] > 0) {
				return array(
					'available' => false,
                    'missing' => array('integer_irt_amount'),
                );
            }
            if ($this->big_integer_compare($direct_irt['digits'], (string) PHP_INT_MAX) > 0) {
                return $this->field_error($path . '.final_price', 'sale_price_direct exceeds the supported IRT integer range');
            }

			return array(
				'available' => true,
				'missing'   => array(),
				'value'     => (int) $direct_irt['digits'],
			);
		}

        if ($this->decimal_compare($markup, $this->formula_decimal_parts(self::MAX_MARKUP_PERCENT)) > 0) {
			return $this->field_error( $path . '.markup_percent', 'must not exceed ' . self::MAX_MARKUP_PERCENT );
		}
		if (
			! $this->is_nonnegative_integer( $product['price_rounding_digits'] )
			|| (int) $this->number_to_storage( $product['price_rounding_digits'] ) > 9
			|| 'nearest_half_up' !== $product['price_rounding_mode']
		) {
			return array(
				'available' => false,
				'missing'   => array( 'price_rounding_digits', 'price_rounding_mode' ),
			);
        }

		$markup_multiplier   = $this->decimal_add(
            $this->formula_decimal_parts('100'),
			$markup
        );
		$marked_up           = $this->decimal_multiply( $base_irt, $markup_multiplier );
        $marked_up['scale'] += 2; // percent to multiplier, exactly.
        $rounding_digits = (int) $this->number_to_storage($product['price_rounding_digits']);
        $rounded = $this->decimal_round_half_up_to_digits($marked_up, $rounding_digits);
        if ($this->big_integer_compare($rounded, (string) PHP_INT_MAX) > 0) {
            return $this->field_error($path . '.final_price', 'landed_price exceeds the supported IRT integer range');
        }

		return array(
			'available' => true,
			'missing'   => array(),
			'value'     => (int) $rounded,
		);
    }

    private function formula_decimal_parts($value) {
        if ($value instanceof Digitalogic_Product_Sync_JSON_Number) {
            $text = $value->value;
        } elseif (is_string($value) || is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = json_encode($value, JSON_THROW_ON_ERROR);
        } else {
            return array('error' => 'must be an exact base-10 decimal');
        }
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $text, $matches)) {
            return array('error' => 'must be a non-negative base-10 decimal without exponent notation');
        }

		$integer        = $matches[1];
		$fraction       = isset( $matches[2] ) ? $matches[2] : '';
        $integer_digits = strlen(ltrim($integer, '0'));
        if (0 === $integer_digits) {
            $integer_digits = 1;
        }
        if ($integer_digits > self::MAX_FORMULA_INTEGER_DIGITS) {
            return array('error' => 'has too many integer digits for landed_price');
        }
        if (strlen($fraction) > self::MAX_FORMULA_SCALE) {
            return array('error' => 'has too many fractional digits for landed_price');
        }

		$scale  = strlen( $fraction );
        $digits = ltrim($integer . $fraction, '0');
        $digits = '' === $digits ? '0' : $digits;
        while ($scale > 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            $scale--;
        }
        if ('' === $digits) {
            $digits = '0';
            $scale  = 0;
        }

        return array('digits' => $digits, 'scale' => $scale);
    }

    private function decimal_add($left, $right) {
        $scale        = max((int) $left['scale'], (int) $right['scale']);
		$left_digits  = $left['digits'] . str_repeat( '0', $scale - (int) $left['scale'] );
        $right_digits = $right['digits'] . str_repeat('0', $scale - (int) $right['scale']);

        return array('digits' => $this->big_integer_add($left_digits, $right_digits), 'scale' => $scale);
    }

    private function decimal_multiply($left, $right) {
        return array(
            'digits' => $this->big_integer_multiply($left['digits'], $right['digits']),
			'scale'  => (int) $left['scale'] + (int) $right['scale'],
        );
    }

    private function decimal_compare($left, $right) {
        $scale        = max((int) $left['scale'], (int) $right['scale']);
		$left_digits  = $left['digits'] . str_repeat( '0', $scale - (int) $left['scale'] );
        $right_digits = $right['digits'] . str_repeat('0', $scale - (int) $right['scale']);

        return $this->big_integer_compare($left_digits, $right_digits);
    }

    private function decimal_round_half_up_integer($decimal) {
        $digits = $this->normalize_big_integer($decimal['digits']);
		$scale  = (int) $decimal['scale'];
        if ($scale <= 0) {
            return $digits . str_repeat('0', -$scale);
        }

		$padded  = str_pad( $digits, $scale + 1, '0', STR_PAD_LEFT );
        $cut     = strlen($padded) - $scale;
        $integer = $this->normalize_big_integer(substr($padded, 0, $cut));
        if ((int) $padded[$cut] >= 5) {
            $integer = $this->big_integer_add($integer, '1');
        }

        return $integer;
    }

    private function decimal_round_half_up_to_digits($decimal, $digits) {
        $scaled           = $decimal;
        $scaled['scale'] += (int) $digits;
        $rounded          = $this->decimal_round_half_up_integer($scaled);

        return $this->normalize_big_integer($rounded . str_repeat('0', (int) $digits));
    }

    private function big_integer_add($left, $right) {
		$left   = strrev( $this->normalize_big_integer( $left ) );
		$right  = strrev( $this->normalize_big_integer( $right ) );
        $length = max(strlen($left), strlen($right));
		$carry  = 0;
        $result = '';
        for ($index = 0; $index < $length; $index++) {
			$sum     = ( $index < strlen( $left ) ? (int) $left[ $index ] : 0 )
                + ($index < strlen($right) ? (int) $right[$index] : 0)
                + $carry;
            $result .= (string) ($sum % 10);
			$carry   = intdiv( $sum, 10 );
        }
        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return $this->normalize_big_integer(strrev($result));
    }

    private function big_integer_multiply($left, $right) {
		$left  = $this->normalize_big_integer( $left );
        $right = $this->normalize_big_integer($right);
        if ('0' === $left || '0' === $right) {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($left_index = strlen($left) - 1; $left_index >= 0; $left_index--) {
            for ($right_index = strlen($right) - 1; $right_index >= 0; $right_index--) {
				$position                 = $left_index + $right_index + 1;
				$sum                      = $result[ $position ] + ( (int) $left[ $left_index ] * (int) $right[ $right_index ] );
				$result[ $position ]      = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return $this->normalize_big_integer(implode('', $result));
    }

    private function big_integer_compare($left, $right) {
        $left  = $this->normalize_big_integer($left);
        $right = $this->normalize_big_integer($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private function normalize_big_integer($value) {
        $value = ltrim((string) $value, '0');
        return '' === $value ? '0' : $value;
    }

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    private function record_hash($product) {
        unset($product['record_hash']);
        ksort($product, SORT_STRING);

        return $this->hash_identity($this->encode_go_json($product, array('warehouse_stock')));
    }

    private function category_record_hash($category) {
        unset($category['record_hash']);
        ksort($category, SORT_STRING);

        return $this->hash_identity($this->encode_go_json($category));
    }

    private function source_revision($products, $categories, $excluded_codes, $quarantined_codes) {
        $material = array();
        foreach ($products as $product) {
            $material[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        foreach ($categories as $category) {
            $material[] = 'category:' . $category['category_code'] . '=' . $category['record_hash'];
        }
        foreach ($excluded_codes as $code) {
            $material[] = 'excluded=' . $code;
        }
        sort($material, SORT_STRING);
        foreach ($quarantined_codes as $code) {
            $material[] = 'quarantined=' . $code;
        }

        return $this->hash_identity(implode("\n", $material));
    }

    private function event_id($envelope) {
        $product_hashes = array();
        foreach ($envelope['products'] as $product) {
            $product_hashes[] = $product['product_code'] . '=' . $product['record_hash'];
        }
        sort($product_hashes, SORT_STRING);
        $category_hashes = array();
        foreach ($envelope['categories'] as $category) {
            $category_hashes[] = $category['category_code'] . '=' . $category['record_hash'];
        }
        sort($category_hashes, SORT_STRING);
        $identity = array(
            'schema' => $envelope['schema'],
            'event_type' => $envelope['event_type'],
        );
        if (array_key_exists('local_currency', $envelope)) {
            $identity['local_currency'] = $envelope['local_currency'];
            $identity['formula_id'] = $envelope['formula_id'];
        }
        $identity['source'] = array(
                'id' => $envelope['source']['id'],
                'dataset' => $envelope['source']['dataset'],
                'revision' => $envelope['source']['revision'],
        );
        $identity['generated_at'] = $envelope['generated_at'];
        $identity['products'] = $product_hashes;
        $identity['categories'] = $category_hashes;
        $identity['excluded_codes'] = $envelope['excluded_codes'];
        if (!empty($envelope['deleted_codes'])) {
            $identity['deleted_codes'] = $envelope['deleted_codes'];
        }
        $identity['quarantined_codes'] = $envelope['quarantined_codes'];

        return $this->hash_identity($this->encode_go_json($identity));
    }
    // phpcs:enable

    /**
     * Encode the validated subset the same way Go encoding/json does.
     *
     * Associative arrays preserve insertion order unless explicitly marked as
     * maps. The warehouse_stock map is encoded with lexicographically sorted keys.
     */
    private function encode_go_json($value, $map_fields = array(), $field = '') {
        if ($value instanceof Digitalogic_Product_Sync_JSON_Number) {
            return $value->value;
        }
        if (null === $value) {
            return 'null';
        }
        if (true === $value) {
            return 'true';
        }
        if (false === $value) {
            return 'false';
        }
        if (is_int($value) || is_float($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return str_replace(
                array('<', '>', '&', "\u{2028}", "\u{2029}"),
                array('\\u003c', '\\u003e', '\\u0026', '\\u2028', '\\u2029'),
                $encoded
            );
        }
        if (!is_array($value)) {
            throw new RuntimeException('Unsupported canonical JSON value.');
        }
        if (in_array($field, $map_fields, true)) {
            $object = $value;
            ksort($object, SORT_STRING);
            $items = array();
            foreach ($object as $key => $item) {
                $items[] = $this->encode_go_json((string) $key) . ':' . $this->encode_go_json($item, $map_fields, (string) $key);
            }

            return '{' . implode(',', $items) . '}';
        }
        if (array_is_list($value)) {
            $items = array();
            foreach ($value as $item) {
                $items[] = $this->encode_go_json($item, $map_fields, $field);
            }

            return '[' . implode(',', $items) . ']';
        }

        $object = $value;
		$items  = array();
        foreach ($object as $key => $item) {
            $items[] = $this->encode_go_json((string) $key) . ':' . $this->encode_go_json($item, $map_fields, (string) $key);
        }

        return '{' . implode(',', $items) . '}';
    }

    private function hash_identity($material) {
        return 'sha256:' . hash('sha256', $material);
    }

    private function state_digest($state) {
        return hash('sha256', maybe_serialize($state));
    }

    private function find_forbidden_raw_key($value, $path = '$') {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $key => $child) {
            $child_path = is_int($key) ? $path . '[' . $key . ']' : $path . '.' . $key;
            if (is_string($key)) {
                $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));
                if (in_array($normalized, self::FORBIDDEN_RAW_KEYS, true) || str_starts_with($normalized, 'anbar')) {
                    return $child_path;
                }
            }
            $found = $this->find_forbidden_raw_key($child, $child_path);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private function is_hash($value) {
        return is_string($value) && 1 === preg_match('/^sha256:[a-f0-9]{64}$/', $value);
    }

    private function is_json_number($value) {
        return $value instanceof Digitalogic_Product_Sync_JSON_Number
            || is_int($value)
            || (is_float($value) && is_finite($value));
    }

    private function is_nonnegative_integer($value) {
        if ($value instanceof Digitalogic_Product_Sync_JSON_Number) {
            return 1 === preg_match('/^(?:0|[1-9][0-9]*)$/', $value->value)
                && false !== filter_var($value->value, FILTER_VALIDATE_INT);
        }

        return is_int($value) && $value >= 0;
    }

    private function number_compare_zero($value) {
		$text     = $value instanceof Digitalogic_Product_Sync_JSON_Number ? $value->value : (string) $value;
		$text     = ltrim( $text, '+' );
        $negative = str_starts_with($text, '-');
		$text     = ltrim( $text, '-' );
		$parts    = preg_split( '/[eE]/', $text, 2 );
		$digits   = str_replace( '.', '', $parts[0] );
		$nonzero  = '' !== trim( $digits, '0' );

        return !$nonzero ? 0 : ($negative ? -1 : 1);
    }

    private function number_to_storage($value) {
        if (!$value instanceof Digitalogic_Product_Sync_JSON_Number) {
            return $value;
        }
        if (1 === preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value->value)) {
            $integer = filter_var($value->value, FILTER_VALIDATE_INT);
            if (false !== $integer) {
                return $integer;
            }
        }

		return $value->value;
    }

    private function decimal_to_storage($value) {
        if ($value instanceof Digitalogic_Product_Sync_JSON_Number) {
            return $value->value;
        }

        return is_int($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function is_plain_decimal($value) {
        if ($value instanceof Digitalogic_Product_Sync_JSON_Number) {
            return 1 === preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value->value);
        }

        return is_int($value) || (is_float($value) && is_finite($value));
    }

    private function timestamp_order($timestamp) {
        if (!is_string($timestamp) || !preg_match(
            '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+\-]\d{2}:\d{2})$/',
            $timestamp,
            $matches
        )) {
            return $this->field_error('generated_at', 'must be RFC3339 with up to nanosecond precision');
        }
		$zone   = 'Z' === $matches[3] ? '+00:00' : $matches[3];
		$date   = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $matches[1] . $zone );
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return $this->field_error('generated_at', 'must be a valid RFC3339 timestamp');
        }
        $fraction = isset($matches[2]) ? str_pad($matches[2], 9, '0') : '000000000';

        return array((int) $date->format('U'), (int) $fraction);
    }

    private function compare_timestamp_order($left, $right) {
        if (!is_array($right) || count($right) !== 2) {
            return 1;
        }
        if ((int) $left[0] !== (int) $right[0]) {
            return (int) $left[0] <=> (int) $right[0];
        }

        return (int) $left[1] <=> (int) $right[1];
    }

    private function source_key($source_id, $dataset) {
        return hash('sha256', $source_id . "\n" . $dataset);
    }

    private function invalidate_state_cache() {
        wp_cache_delete(self::STATE_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete('alloptions', 'options');
    }

	/**
	 * Acquire the re-entrant source identity lock.
	 *
	 * @param int|null $timeout_seconds Maximum bounded wait, or the receiver default.
	 * @return true|WP_Error
	 */
	private function acquire_lock( $timeout_seconds = null ) {
        if ($this->lock_depth > 0) {
			if ( ! $this->source_identity_lock_is_owned() ) {
				return $this->error( 'digitalogic_product_sync_lock_lost', 'The source identity lock was lost after a database reconnect. Retry safely.', 503, array( 'retryable' => true ) );
			}
            $this->lock_depth++;
            return true;
        }

		$timeout_seconds = null === $timeout_seconds
			? self::LOCK_TIMEOUT_SECONDS
			: max( 0, min( self::LOCK_TIMEOUT_SECONDS, (int) $timeout_seconds ) );

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return $this->error('digitalogic_product_sync_lock_unavailable', 'The database lock service is unavailable.', 503);
        }
		$prefix    = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$lock_name = self::source_identity_lock_name( $prefix );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named advisory lock is connection state.
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $timeout_seconds ) );
        if ('1' !== (string) $locked) {
            return $this->error('digitalogic_product_sync_busy', 'Another product-sync event is being applied. Please retry.', 503, array('retryable' => true));
        }
		$connection_id = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection identity is live session state.
		$owner_query   = $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name );
		$owner_id      = false !== $owner_query ? $wpdb->get_var( $owner_query ) : false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Advisory lock ownership is live session state.
		if ( (int) $connection_id <= 0 || (int) $connection_id !== (int) $owner_id ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Best-effort cleanup of an unverified lock.
			$this->forget_lost_lock();
			return $this->error( 'digitalogic_product_sync_lock_unavailable', 'The source identity lock ownership could not be verified.', 503, array( 'retryable' => true ) );
		}
		$this->lock_connection_id = (int) $connection_id;
		$this->lock_depth         = 1;

        return true;
    }

    private function release_lock() {
        if ($this->lock_depth <= 0) {
            return;
        }
		if ( ! $this->source_identity_lock_is_owned() ) {
			return;
		}
        $this->lock_depth--;
        if ($this->lock_depth > 0) {
            return;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return;
        }
		$prefix    = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$lock_name = self::source_identity_lock_name( $prefix );
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
		$this->lock_connection_id = 0;
    }

	/** Forget request-local depth immediately after connection-scoped ownership is lost. */
	private function forget_lost_lock() {
		$this->lock_depth         = 0;
		$this->lock_connection_id = 0;
	}

    private function field_error($field, $reason) {
        return $this->error(
            'digitalogic_product_sync_field_invalid',
            'A product-sync field is invalid.',
            422,
            array('field' => $field, 'reason' => $reason)
        );
    }

    private function error($code, $message, $status, $details = array()) {
        return new WP_Error($code, __($message, 'digitalogic'), array_merge(array('status' => (int) $status), $details));
    }
}
