<?php
/**
 * Versioned, transformed-only Patris product-sync receiver.
 *
 * This service deliberately does not share the legacy /patris/push storage
 * semantics. It accepts the typed digitalogic.product-sync v1 contract,
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
        $value = $decoder->parse_value(0);
        $decoder->skip_whitespace();
        if ($decoder->position !== $decoder->length) {
            throw new RuntimeException('Unexpected data after the JSON document.');
        }

        return $value;
    }

    private function __construct($json) {
        $this->json = $json;
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
        $token = $matches[0];
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
    public const STATE_OPTION = 'digitalogic_product_sync_v1_state';
    public const CONTRACT_NAME = 'digitalogic.product-sync';
    public const FORMULA_ID = 'landed_price_v1';

    private const STATE_VERSION = 3;
    private const LOCK_NAME = 'digitalogic_product_sync_v1';
    private const LOCK_TIMEOUT_SECONDS = 15;
    private const MAX_BODY_BYTES = 8388608;
    private const MAX_STATE_BYTES = 16777216;
    private const MAX_PRODUCTS = 10000;
    private const MAX_SOURCES = 16;
    private const MAX_RECENT_EVENTS = 128;
    private const MAX_RESULT_ERRORS = 100;
    private const MAX_DEFERRED_PRODUCTS = self::MAX_PRODUCTS;
    private const MAX_CODE_LENGTH = 191;
    private const MAX_FORMULA_INTEGER_DIGITS = 15;
    private const MAX_FORMULA_SCALE = 12;
    private const MAX_MARKUP_PERCENT = '1000';

    private const ENVELOPE_FIELDS = array(
        'schema',
        'schema_version',
        'event',
        'event_type',
        'event_id',
        'local_currency',
        'formula_id',
        'formula_revision',
        'formula_version',
        'source',
        'generated_at',
        'products',
        'deleted_codes',
        'quarantined_codes',
        'warnings',
    );

    private const PRODUCT_FIELDS = array(
        'product_code',
        'name',
        'serial',
        'unit',
        'sale_price_source',
        'purchase_price_source',
        'warehouse_stock',
        'total_stock',
        'minimum_stock',
        'foreign_currency',
        'foreign_price',
        'weight_grams',
        'location',
        'import_freight_method_id',
        'freight_cny_per_kg',
        'markup_percent',
        'irt_per_cny',
        'pricing_catalog_revision',
        'pricing_catalog_status',
        'currency_effective_date',
        'final_price',
        'formula_version',
        'source_updated_at',
        'warnings',
        'record_hash',
    );

    private const PRODUCT_STRING_FIELDS = array(
        'name',
        'serial',
        'unit',
        'location',
        'import_freight_method_id',
        'pricing_catalog_revision',
        'pricing_catalog_status',
        'currency_effective_date',
        'source_updated_at',
    );

    private const PRODUCT_NULLABLE_NUMBER_FIELDS = array(
        'sale_price_source',
        'purchase_price_source',
        'total_stock',
        'minimum_stock',
        'foreign_price',
        'weight_grams',
        'freight_cny_per_kg',
        'markup_percent',
        'irt_per_cny',
    );

    private const PRODUCT_DECIMAL_FIELDS = array(
        'foreign_price',
        'weight_grams',
        'freight_cny_per_kg',
        'markup_percent',
        'irt_per_cny',
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

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {}

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
     * Validate, order, persist, and apply a typed v1 envelope.
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
            return $this->receive_locked($envelope);
        } catch (Throwable $exception) {
            return $this->error(
                'digitalogic_product_sync_unexpected_failure',
                'The product-sync event could not be applied.',
                500,
                array('exception' => get_class($exception))
            );
        } finally {
            $this->release_lock();
        }
    }

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    /**
     * Return the stored state for diagnostics and tests.
     *
     * @return array
     */
    public function get_state() {
        $migration_required = false;

        return $this->load_state($migration_required);
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
            'state_version' => self::STATE_VERSION,
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
            $migration_required = false;
            $state = $this->load_state($migration_required);
            $selected = array();
            if (null !== $source_id) {
                $source_key = $this->source_key((string) $source_id, (string) $dataset);
                if (!isset($state['sources'][$source_key]) || !is_array($state['sources'][$source_key])) {
                    return $this->error(
                        'digitalogic_product_sync_source_not_found',
                        'The requested product-sync source was not found.',
                        404
                    );
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

            if ($migration_required || !hash_equals($before, $this->state_digest($state))) {
                $stored = $this->persist_and_read_back($state);
                if (is_wp_error($stored)) {
                    return $stored;
                }
            }

            return array(
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
            return $this->error(
                'digitalogic_product_sync_reconcile_failed',
                'Deferred product reconciliation failed.',
                500,
                array('exception' => get_class($exception))
            );
        } finally {
            $this->release_lock();
        }
    }

    private function load_state(&$migration_required) {
        $state = get_option(self::STATE_OPTION, array());
        $migration_required = false;
        if (!is_array($state)) {
            return array('version' => self::STATE_VERSION, 'sources' => array());
        }
        $version = (int) ($state['version'] ?? 0);
        if (2 === $version) {
            $state = $this->migrate_v2_state($state);
            $migration_required = true;
        } elseif (self::STATE_VERSION !== $version) {
            return array('version' => self::STATE_VERSION, 'sources' => array());
        }
        $state['sources'] = isset($state['sources']) && is_array($state['sources']) ? $state['sources'] : array();
        foreach ($state['sources'] as &$source_state) {
            if (!is_array($source_state)) {
                $source_state = array();
            }
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

    // phpcs:disable -- Preserve the established receiver formatting while the legacy file remains baseline-managed.
    private function receive_locked($envelope) {
        $migration_required = false;
        $state = $this->load_state($migration_required);
        if ($migration_required) {
            $migrated = $this->persist_and_read_back($state);
            if (is_wp_error($migrated)) {
                return $migrated;
            }
        }
        $source_key = $this->source_key($envelope['source']['id'], $envelope['source']['dataset']);
        $existing = isset($state['sources'][$source_key]) && is_array($state['sources'][$source_key])
            ? $state['sources'][$source_key]
            : null;

        if (null === $existing && count($state['sources']) >= self::MAX_SOURCES) {
            return $this->error('digitalogic_product_sync_source_limit', 'The configured source limit has been reached.', 409);
        }

        if (is_array($existing) && isset($existing['recent_events'][$envelope['event_id']])) {
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
            'local_currency' => $envelope['local_currency'],
            'formula_id' => $envelope['formula_id'],
            'formula_revision' => $envelope['formula_revision'],
            'products' => $transition['products'],
            'quarantined_codes' => $envelope['quarantined_codes'],
            'recent_events' => $recent_events,
            'applied_products' => $delivery['applied_products'],
            'pending_products' => $delivery['pending_products'],
            'deferred_products' => $delivery['deferred_products'],
            'received_at' => current_time('mysql'),
        );
        $state['sources'][$source_key] = $source_state;

        $stored = $this->persist_and_read_back($state);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $before_delivery = $this->state_digest($source_state);
        $woo = $this->drain_delivery_products($source_state, true, true);
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
            'deleted_codes' => $transition['deleted_count'],
            'preserved_quarantined' => $transition['preserved_quarantined'],
            'woocommerce' => $woo,
            'persistence_verified' => true,
        ), $this->delivery_result_state($source_state));

        return $this->emit_result($result, $envelope);
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
            'woocommerce' => $woo,
            'persistence_verified' => true,
        ), $this->delivery_result_state($source_state));

        return $this->emit_result($result, $envelope);
    }
    // phpcs:enable

    private function emit_result($result, $envelope) {
        try {
            do_action('digitalogic_product_sync_v1_applied', $result, array(
                'schema' => $envelope['schema'],
                'schema_version' => $envelope['schema_version'],
                'event_id' => $envelope['event_id'],
                'event_type' => $envelope['event_type'],
                'source' => $envelope['source'],
                'generated_at' => $envelope['generated_at'],
            ));
        } catch (Throwable $exception) {
            $result['delivery_warnings'][] = array(
                'code' => 'digitalogic_product_sync_listener_failed',
                'exception' => get_class($exception),
            );
        }

        try {
            Digitalogic_Logger::instance()->log(
                'product_sync_v1_applied',
                'patris_feed',
                null,
                null,
                wp_json_encode($result),
                'Versioned Patris product-sync event applied'
            );
        } catch (Throwable $exception) {
            $result['delivery_warnings'][] = array(
                'code' => 'digitalogic_product_sync_log_failed',
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

        $required = array_slice(self::ENVELOPE_FIELDS, 0, 12);
        $missing = array_values(array_diff($required, array_keys($payload)));
        if (!empty($missing)) {
            return $this->error('digitalogic_product_sync_missing_field', 'The envelope is missing required fields.', 422, array('fields' => $missing));
        }

        foreach (array('schema', 'schema_version', 'event', 'event_type', 'event_id', 'local_currency', 'formula_id', 'formula_revision', 'formula_version', 'generated_at') as $field) {
            if (!is_string($payload[$field])) {
                return $this->field_error($field, 'must be a string');
            }
        }
        if (self::CONTRACT_NAME !== $payload['schema'] || self::CONTRACT_NAME !== $payload['event']) {
            return $this->error('digitalogic_product_sync_schema_unsupported', 'Only digitalogic.product-sync envelopes are accepted.', 422);
        }
        if (!$this->is_supported_major_version($payload['schema_version'])) {
            return $this->error('digitalogic_product_sync_version_unsupported', 'Only product-sync schema major version 1 is supported.', 422);
        }
        if (!in_array($payload['event_type'], array('snapshot', 'update'), true)) {
            return $this->field_error('event_type', 'must be snapshot or update');
        }
        if ('IRT' !== $payload['local_currency']) {
            return $this->error('digitalogic_product_sync_currency_unsupported', 'The receiver currently supports IRT output only.', 422);
        }
        $currency_status = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();
        if (!$currency_status['compatible']) {
            return $this->error(
                'digitalogic_product_sync_store_currency_mismatch',
                'WooCommerce must use IRT before transformed IRT prices can be applied.',
                409,
                array(
                    'woocommerce_base_currency' => $currency_status['code'],
                    'required_currency' => Digitalogic_WooCommerce_Currency_Status::REQUIRED_CURRENCY,
                    'warning' => Digitalogic_WooCommerce_Currency_Status::INCOMPATIBLE_WARNING,
                )
            );
        }
        if (self::FORMULA_ID !== $payload['formula_id'] || self::FORMULA_ID !== $payload['formula_version']) {
            return $this->error('digitalogic_product_sync_formula_unsupported', 'The landed_price_v1 formula is required.', 422);
        }
        if (!$this->is_supported_major_version($payload['formula_revision'])) {
            return $this->error('digitalogic_product_sync_formula_revision_unsupported', 'Only formula major revision 1 is supported.', 422);
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

        $products = array();
        $seen_codes = array();
        foreach ($payload['products'] as $index => $product) {
            $validated = $this->validate_product($product, $index);
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
            $products[] = $validated;
        }

        $deleted_codes = $this->validate_tombstones($payload['deleted_codes'] ?? array(), $payload['event_type']);
        if (is_wp_error($deleted_codes)) {
            return $deleted_codes;
        }
        $quarantined_codes = $this->validate_string_set($payload['quarantined_codes'] ?? array(), 'quarantined_codes');
        if (is_wp_error($quarantined_codes)) {
            return $quarantined_codes;
        }
        $warnings = $this->validate_string_set($payload['warnings'] ?? array(), 'warnings', false);
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
            'schema' => $payload['schema'],
            'schema_version' => $payload['schema_version'],
            'event' => $payload['event'],
            'event_type' => $payload['event_type'],
            'event_id' => $payload['event_id'],
            'local_currency' => $payload['local_currency'],
            'formula_id' => $payload['formula_id'],
            'formula_revision' => $payload['formula_revision'],
            'formula_version' => $payload['formula_version'],
            'source' => $source,
            'generated_at' => $payload['generated_at'],
            'generated_at_order' => $generated_at_order,
            'products' => $products,
            'deleted_codes' => $deleted_codes,
            'quarantined_codes' => $quarantined_codes,
            'warnings' => $warnings,
        );

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

    private function validate_product($product, $index) {
        $path = 'products[' . (int) $index . ']';
        if (!is_array($product) || array_is_list($product)) {
            return $this->field_error($path, 'must be an object');
        }
        $missing = array_values(array_diff(self::PRODUCT_FIELDS, array_keys($product)));
        $unknown = array_values(array_diff(array_keys($product), self::PRODUCT_FIELDS));
        if (!empty($missing) || !empty($unknown)) {
            return $this->error(
                'digitalogic_product_sync_product_shape_invalid',
                'A product does not match the typed v1 shape.',
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
        foreach (self::PRODUCT_STRING_FIELDS as $field) {
            if (!is_string($product[$field])) {
                return $this->field_error($path . '.' . $field, 'must be a string');
            }
        }
        if (!is_string($product['foreign_currency']) || 'CNY' !== $product['foreign_currency']) {
            return $this->field_error($path . '.foreign_currency', 'must be CNY');
        }
        if (!is_string($product['formula_version']) || self::FORMULA_ID !== $product['formula_version']) {
            return $this->field_error($path . '.formula_version', 'must be landed_price_v1');
        }
        foreach (self::PRODUCT_NULLABLE_NUMBER_FIELDS as $field) {
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
        foreach (array('foreign_price', 'weight_grams', 'freight_cny_per_kg', 'irt_per_cny') as $field) {
            if (null !== $product[$field] && $this->number_compare_zero($product[$field]) <= 0) {
                return $this->field_error($path . '.' . $field, 'must be greater than zero when provided');
            }
        }
        if (null !== $product['markup_percent'] && $this->number_compare_zero($product['markup_percent']) < 0) {
            return $this->field_error($path . '.markup_percent', 'must not be negative');
        }
        if (null !== $product['final_price'] && !$this->is_nonnegative_integer($product['final_price'])) {
            return $this->field_error($path . '.final_price', 'must be a non-negative integer or null');
        }
        if (!is_array($product['warehouse_stock']) || (!empty($product['warehouse_stock']) && array_is_list($product['warehouse_stock']))) {
            return $this->field_error($path . '.warehouse_stock', 'must be an object');
        }
        foreach ($product['warehouse_stock'] as $warehouse => $stock) {
            if ('' === trim((string) $warehouse) || !$this->is_json_number($stock)) {
                return $this->field_error($path . '.warehouse_stock', 'must map non-empty string keys to JSON numbers');
            }
        }
        $warnings = $this->validate_string_set($product['warnings'], $path . '.warnings', false);
        if (is_wp_error($warnings)) {
            return $warnings;
        }
        if (!is_string($product['record_hash']) || !$this->is_hash($product['record_hash'])) {
            return $this->field_error($path . '.record_hash', 'must be a sha256 identity');
        }

        $hash_product = $product;
        $hash_product['warnings'] = $warnings;
        $expected_hash = $this->record_hash($hash_product);
        if (!hash_equals($expected_hash, $product['record_hash'])) {
            return $this->error(
                'digitalogic_product_sync_record_hash_mismatch',
                'A record_hash does not match its typed product.',
                422,
                array('path' => $path . '.record_hash', 'expected' => $expected_hash)
            );
        }

        $formula_check = $this->validate_final_price_formula($product, $path);
        if (is_wp_error($formula_check)) {
            return $formula_check;
        }

        $stored = array();
        foreach (self::PRODUCT_FIELDS as $field) {
            if ('warehouse_stock' === $field) {
                $stocks = $product[$field];
                ksort($stocks, SORT_STRING);
                $stored[$field] = array_map(array($this, 'number_to_storage'), $stocks);
            } elseif (in_array($field, self::PRODUCT_DECIMAL_FIELDS, true)) {
                $stored[$field] = null === $product[$field] ? null : $this->decimal_to_storage($product[$field]);
            } elseif (in_array($field, self::PRODUCT_NULLABLE_NUMBER_FIELDS, true) || 'final_price' === $field) {
                $stored[$field] = null === $product[$field] ? null : $this->number_to_storage($product[$field]);
            } elseif ('warnings' === $field) {
                $stored[$field] = $warnings;
            } else {
                $stored[$field] = $product[$field];
            }
        }

        return $stored;
    }

    private function validate_tombstones($values, $event_type) {
        if (!is_array($values) || !array_is_list($values)) {
            return $this->field_error('deleted_codes', 'must be an array');
        }
        if ('snapshot' === $event_type && !empty($values)) {
            return $this->field_error('deleted_codes', 'is only valid on update events');
        }
        $result = array();
        $seen = array();
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
            $result[] = array('product_code' => $value['product_code'], 'deleted' => true);
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
        $seen = array();
        foreach ($values as $index => $value) {
            if (!is_string($value) || '' === trim($value) || ($code_rules && trim($value) !== $value)) {
                return $this->field_error($field . '[' . $index . ']', 'must be a non-empty string');
            }
            $limit = $code_rules ? self::MAX_CODE_LENGTH : 255;
            if (strlen($value) > $limit || isset($seen[$value])) {
                return $this->field_error($field . '[' . $index . ']', 'must be unique and within the length limit');
            }
            $seen[$value] = true;
            $result[] = $value;
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
        $quarantined = array();
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
        $expected_revision = $this->source_revision($revision_products, $envelope['quarantined_codes']);
        if (!hash_equals($expected_revision, $envelope['source']['revision'])) {
            return $this->error(
                'digitalogic_product_sync_source_revision_mismatch',
                'The source revision does not match the resulting source snapshot.',
                422,
                array('expected' => $expected_revision)
            );
        }

        return array(
            'products' => $next,
            'changed_products' => array_values($incoming),
            'deleted_count' => 'snapshot' === $envelope['event_type']
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
                unset($pending[$code]);
                unset($deferred[$code]);
                continue;
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
    private function drain_delivery_products(&$source_state, $include_pending, $include_deferred) {
        $result = array(
            'attempted' => 0,
            'updated' => 0,
            'already_applied' => 0,
            'missing' => 0,
            'ambiguous' => 0,
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
        ksort($work, SORT_STRING);

        foreach ($work as $code_key => $delivery_entry) {
            $product_code = $this->valid_delivery_product_code($products, $code_key, $delivery_entry);
            if (null === $product_code) {
                unset($pending[$code_key]);
                unset($deferred[$code_key]);
                continue;
            }
            $delivery_entry['product_code'] = $product_code;
            $product_data = $products[$code_key];
            $record_hash = (string) $delivery_entry['record_hash'];

            $result['attempted']++;
            $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(array(
                'code' => $product_code,
            ));
            if (is_wp_error($resolved)) {
                $error_code = $resolved->get_error_code();
                $deferred_reason = $this->terminal_resolution_reason($error_code);
                if ('missing' === $deferred_reason) {
                    $result['missing']++;
                } elseif ('ambiguous' === $deferred_reason) {
                    $result['ambiguous']++;
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

            $woocommerce_id = (int) $resolved['woocommerce_id'];
            $applied_entry = is_array($applied[$code_key] ?? null) ? $applied[$code_key] : array();
            if (
                isset($applied_entry['record_hash'], $applied_entry['woocommerce_id'])
                && hash_equals((string) $applied_entry['record_hash'], $record_hash)
                && (string) $applied_entry['woocommerce_id'] === (string) $woocommerce_id
            ) {
                unset($pending[$code_key]);
                unset($deferred[$code_key]);
                $result['already_applied']++;
                continue;
            }

            $persisted_hash = (string) get_post_meta($woocommerce_id, '_digitalogic_patris_record_hash', true);
            if ('' !== $persisted_hash && hash_equals($record_hash, $persisted_hash)) {
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
                Digitalogic_Patris_Feed::instance()->apply_product_feed($product, $product_data);
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
        if ('digitalogic_product_identifier_not_found' === $error_code) {
            return 'missing';
        }
        if ('digitalogic_product_identifier_ambiguous' === $error_code) {
            return 'ambiguous';
        }

        return null;
    }

    private function v2_terminal_resolution_reason($error_code) {
        // v2 collapsed SQL lookup failures into not_found, so only a proven
        // multi-row ambiguity can be migrated without first querying again.
        return 'digitalogic_product_identifier_ambiguous' === $error_code
            ? 'ambiguous'
            : null;
    }

    /**
     * Project v2 delivery state into v3 without discarding retry metadata.
     *
     * The migration is persisted by receive() or reconcile() while holding the
     * same advisory lock used for normal event delivery.
     */
    private function migrate_v2_state($state) {
        $state['version'] = self::STATE_VERSION;
        $sources = is_array($state['sources'] ?? null) ? $state['sources'] : array();
        foreach ($sources as &$source_state) {
            if (!is_array($source_state)) {
                $source_state = array();
            }
            $pending = is_array($source_state['pending_products'] ?? null)
                ? $source_state['pending_products']
                : array();
            $deferred = array();
            foreach ($pending as $code => $entry) {
                $reason = is_array($entry)
                    ? $this->v2_terminal_resolution_reason((string) ($entry['last_error'] ?? ''))
                    : null;
                if (null === $reason) {
                    continue;
                }
                $entry['reason'] = $reason;
                $deferred[$code] = $entry;
                unset($pending[$code]);
            }
            ksort($pending, SORT_STRING);
            ksort($deferred, SORT_STRING);
            $source_state['pending_products'] = $pending;
            $source_state['deferred_products'] = array_slice(
                $deferred,
                0,
                self::MAX_DEFERRED_PRODUCTS,
                true
            );
        }
        unset($source_state);
        $state['sources'] = $sources;

        return $state;
    }

    private function deferred_summary($deferred) {
        $deferred = is_array($deferred) ? $deferred : array();
        ksort($deferred, SORT_STRING);
        $summary = array(
            'missing' => 0,
            'ambiguous' => 0,
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
            'applied_products' => count(is_array($source_state['applied_products'] ?? null) ? $source_state['applied_products'] : array()),
            'pending_products' => count(is_array($source_state['pending_products'] ?? null) ? $source_state['pending_products'] : array()),
            'deferred_products' => count(is_array($source_state['deferred_products'] ?? null) ? $source_state['deferred_products'] : array()),
        );
    }
    // phpcs:enable

    private function persist_and_read_back($state) {
        global $wpdb;
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
        if (false === $wpdb->query('START TRANSACTION')) {
            return $this->error('digitalogic_product_sync_transaction_unavailable', 'The receiver could not start a storage transaction.', 503);
        }

        $serialized = maybe_serialize($state);
        if (!is_string($serialized) || strlen($serialized) > self::MAX_STATE_BYTES) {
            $wpdb->query('ROLLBACK');

            return $this->error('digitalogic_product_sync_state_too_large', 'The combined receiver state is too large.', 413);
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
            self::STATE_OPTION
        ), ARRAY_A);
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
                    'option_name' => self::STATE_OPTION,
                    'option_value' => $serialized,
                    'autoload' => 'no',
                ),
                array('%s', '%s', '%s')
            );
        }
        if (false === $written || 0 === $written) {
            $wpdb->query('ROLLBACK');
            $this->invalidate_state_cache();

            return $this->error('digitalogic_product_sync_storage_failed', 'The receiver state could not be stored.', 500);
        }

        $read_row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::STATE_OPTION
        ), ARRAY_A);
        $read_back = is_array($read_row) && array_key_exists('option_value', $read_row)
            ? maybe_unserialize($read_row['option_value'])
            : null;
        if (!is_array($read_back) || !hash_equals($this->state_digest($state), $this->state_digest($read_back))) {
            $wpdb->query('ROLLBACK');
            $this->invalidate_state_cache();

            return $this->error('digitalogic_product_sync_readback_failed', 'Stored receiver state did not pass readback verification.', 500);
        }
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            $this->invalidate_state_cache();

            return $this->error('digitalogic_product_sync_commit_failed', 'The receiver state transaction could not be committed.', 500);
        }

        $this->invalidate_state_cache();

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
        ), $this->delivery_result_state($existing));
    }
    // phpcs:enable

    /**
     * Independently evaluate landed_price_v1 using bounded decimal strings.
     * No binary floating-point operation participates in the calculation and
     * the only rounding is one half-up step to the final IRT integer.
     */
    private function validate_final_price_formula($product, $path) {
        $required = array('foreign_price', 'weight_grams', 'freight_cny_per_kg', 'markup_percent', 'irt_per_cny');
        $missing = array();
        $decimals = array();
        foreach ($required as $field) {
            if (null === $product[$field]) {
                $missing[] = $field;
                continue;
            }
            $parts = $this->formula_decimal_parts($product[$field]);
            if (isset($parts['error'])) {
                return $this->field_error($path . '.' . $field, $parts['error']);
            }
            $decimals[$field] = $parts;
        }
        if ('' === $product['import_freight_method_id']) {
            $missing[] = 'import_freight_method_id';
        }

        if (!empty($missing)) {
            if (null === $product['final_price']) {
                return true;
            }

            return $this->error(
                'digitalogic_product_sync_final_price_mismatch',
                'final_price must be null when landed_price_v1 inputs are incomplete.',
                422,
                array('path' => $path . '.final_price', 'expected' => null, 'missing' => $missing)
            );
        }

        if ($this->decimal_compare($decimals['markup_percent'], $this->formula_decimal_parts(self::MAX_MARKUP_PERCENT)) > 0) {
            return $this->field_error($path . '.markup_percent', 'must not exceed ' . self::MAX_MARKUP_PERCENT);
        }

        $freight = $this->decimal_multiply($decimals['weight_grams'], $decimals['freight_cny_per_kg']);
        $freight['scale'] += 3; // grams to kilograms, exactly.
        $landed_cny = $this->decimal_add($decimals['foreign_price'], $freight);
        $markup_multiplier = $this->decimal_add(
            $this->formula_decimal_parts('100'),
            $decimals['markup_percent']
        );
        $marked_up = $this->decimal_multiply($landed_cny, $markup_multiplier);
        $marked_up['scale'] += 2; // percent to multiplier, exactly.
        $irt = $this->decimal_multiply($marked_up, $decimals['irt_per_cny']);
        $rounded = $this->decimal_round_half_up_integer($irt);
        if ($this->big_integer_compare($rounded, (string) PHP_INT_MAX) > 0) {
            return $this->field_error($path . '.final_price', 'landed_price_v1 exceeds the supported IRT integer range');
        }

        $actual = null === $product['final_price'] ? null : $this->number_to_storage($product['final_price']);
        $expected = (int) $rounded;
        if (!is_int($actual) || $actual !== $expected) {
            return $this->error(
                'digitalogic_product_sync_final_price_mismatch',
                'final_price does not match independently evaluated landed_price_v1.',
                422,
                array('path' => $path . '.final_price', 'expected' => $expected, 'actual' => $actual)
            );
        }

        return true;
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

        $integer = $matches[1];
        $fraction = isset($matches[2]) ? $matches[2] : '';
        $integer_digits = strlen(ltrim($integer, '0'));
        if (0 === $integer_digits) {
            $integer_digits = 1;
        }
        if ($integer_digits > self::MAX_FORMULA_INTEGER_DIGITS) {
            return array('error' => 'has too many integer digits for landed_price_v1');
        }
        if (strlen($fraction) > self::MAX_FORMULA_SCALE) {
            return array('error' => 'has too many fractional digits for landed_price_v1');
        }

        $scale = strlen($fraction);
        $digits = ltrim($integer . $fraction, '0');
        $digits = '' === $digits ? '0' : $digits;
        while ($scale > 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            $scale--;
        }
        if ('' === $digits) {
            $digits = '0';
            $scale = 0;
        }

        return array('digits' => $digits, 'scale' => $scale);
    }

    private function decimal_add($left, $right) {
        $scale = max((int) $left['scale'], (int) $right['scale']);
        $left_digits = $left['digits'] . str_repeat('0', $scale - (int) $left['scale']);
        $right_digits = $right['digits'] . str_repeat('0', $scale - (int) $right['scale']);

        return array('digits' => $this->big_integer_add($left_digits, $right_digits), 'scale' => $scale);
    }

    private function decimal_multiply($left, $right) {
        return array(
            'digits' => $this->big_integer_multiply($left['digits'], $right['digits']),
            'scale' => (int) $left['scale'] + (int) $right['scale'],
        );
    }

    private function decimal_compare($left, $right) {
        $scale = max((int) $left['scale'], (int) $right['scale']);
        $left_digits = $left['digits'] . str_repeat('0', $scale - (int) $left['scale']);
        $right_digits = $right['digits'] . str_repeat('0', $scale - (int) $right['scale']);

        return $this->big_integer_compare($left_digits, $right_digits);
    }

    private function decimal_round_half_up_integer($decimal) {
        $digits = $this->normalize_big_integer($decimal['digits']);
        $scale = (int) $decimal['scale'];
        if ($scale <= 0) {
            return $digits . str_repeat('0', -$scale);
        }

        $padded = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $cut = strlen($padded) - $scale;
        $integer = $this->normalize_big_integer(substr($padded, 0, $cut));
        if ((int) $padded[$cut] >= 5) {
            $integer = $this->big_integer_add($integer, '1');
        }

        return $integer;
    }

    private function big_integer_add($left, $right) {
        $left = strrev($this->normalize_big_integer($left));
        $right = strrev($this->normalize_big_integer($right));
        $length = max(strlen($left), strlen($right));
        $carry = 0;
        $result = '';
        for ($index = 0; $index < $length; $index++) {
            $sum = ($index < strlen($left) ? (int) $left[$index] : 0)
                + ($index < strlen($right) ? (int) $right[$index] : 0)
                + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return $this->normalize_big_integer(strrev($result));
    }

    private function big_integer_multiply($left, $right) {
        $left = $this->normalize_big_integer($left);
        $right = $this->normalize_big_integer($right);
        if ('0' === $left || '0' === $right) {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($left_index = strlen($left) - 1; $left_index >= 0; $left_index--) {
            for ($right_index = strlen($right) - 1; $right_index >= 0; $right_index--) {
                $position = $left_index + $right_index + 1;
                $sum = $result[$position] + ((int) $left[$left_index] * (int) $right[$right_index]);
                $result[$position] = $sum % 10;
                $result[$position - 1] += intdiv($sum, 10);
            }
        }

        return $this->normalize_big_integer(implode('', $result));
    }

    private function big_integer_compare($left, $right) {
        $left = $this->normalize_big_integer($left);
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

    private function record_hash($product) {
        $ordered = array();
        foreach (self::PRODUCT_FIELDS as $field) {
            if ('record_hash' !== $field) {
                $ordered[$field] = $product[$field];
            }
        }

        return $this->hash_identity($this->encode_go_json($ordered, array('warehouse_stock')));
    }

    private function source_revision($products, $quarantined_codes) {
        $material = array();
        foreach ($products as $product) {
            $material[] = $product['product_code'] . '=' . $product['record_hash'];
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
        $identity = array(
            'schema' => $envelope['schema'],
            'schema_version' => $envelope['schema_version'],
            'event_type' => $envelope['event_type'],
            'local_currency' => $envelope['local_currency'],
            'formula_id' => $envelope['formula_id'],
            'formula_revision' => $envelope['formula_revision'],
            'source' => array(
                'id' => $envelope['source']['id'],
                'dataset' => $envelope['source']['dataset'],
                'revision' => $envelope['source']['revision'],
            ),
            'generated_at' => $envelope['generated_at'],
            'products' => $product_hashes,
        );
        if (!empty($envelope['deleted_codes'])) {
            $identity['deleted_codes'] = $envelope['deleted_codes'];
        }
        if (!empty($envelope['quarantined_codes'])) {
            $identity['quarantined_codes'] = $envelope['quarantined_codes'];
        }

        return $this->hash_identity($this->encode_go_json($identity));
    }

    /**
     * Encode the validated subset the same way Go encoding/json does.
     *
     * Associative arrays preserve insertion order unless explicitly marked as
     * maps. The only map in Product v1 is warehouse_stock, whose keys Go sorts.
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
        $items = array();
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

    private function is_supported_major_version($version) {
        return is_string($version) && 1 === preg_match('/^1(?:\.[0-9]+){1,2}$/', $version);
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
        $text = $value instanceof Digitalogic_Product_Sync_JSON_Number ? $value->value : (string) $value;
        $text = ltrim($text, '+');
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        $parts = preg_split('/[eE]/', $text, 2);
        $digits = str_replace('.', '', $parts[0]);
        $nonzero = '' !== trim($digits, '0');

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

        return (float) $value->value;
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
        $zone = 'Z' === $matches[3] ? '+00:00' : $matches[3];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $matches[1] . $zone);
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

    private function acquire_lock() {
        if ($this->lock_depth > 0) {
            $this->lock_depth++;
            return true;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return $this->error('digitalogic_product_sync_lock_unavailable', 'The database lock service is unavailable.', 503);
        }
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
        $lock_name = substr(self::LOCK_NAME . '_' . md5($prefix), 0, 64);
        $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT_SECONDS));
        if ('1' !== (string) $locked) {
            return $this->error('digitalogic_product_sync_busy', 'Another product-sync event is being applied. Please retry.', 503, array('retryable' => true));
        }
        $this->lock_depth = 1;

        return true;
    }

    private function release_lock() {
        if ($this->lock_depth <= 0) {
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
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
        $lock_name = substr(self::LOCK_NAME . '_' . md5($prefix), 0, 64);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
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
