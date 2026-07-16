<?php
/**
 * Canonical inbound freight catalog and product assignment service.
 *
 * Import freight describes how Digitalogic obtains inventory from suppliers.
 * It is intentionally independent from WooCommerce customer delivery methods.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Digitalogic_Product_Identifier_Resolver')) {
    require_once __DIR__ . '/class-product-identifier-resolver.php';
}

final class Digitalogic_Import_Freight_Service {

    public const METHODS_OPTION = 'digitalogic_import_freight_methods_v1';
    public const DEFAULT_MARKUP_OPTION = 'digitalogic_import_freight_default_markup_v1';
    public const MIGRATION_OPTION = 'digitalogic_import_freight_migration_v1';
    public const PRODUCT_METHOD_META = '_digitalogic_import_freight_method_id';
    public const LEGACY_PRODUCT_METHOD_META = 'shipping_method';
    public const LEGACY_ACF_REFERENCE_META = '_shipping_method';
    public const LEGACY_ACF_FIELD_KEY = 'field_694534693f9ba';
    public const CATALOG_SCHEMA = 'digitalogic.integration-catalog';
    public const CATALOG_SCHEMA_VERSION = '1.1.0';
    public const FORMULA_ID = 'landed_price_v1';
    public const FORMULA_REVISION = '1.0.0';
    public const DEFAULT_MARKUP_SCHEMA = 'digitalogic.default-percentage-markup';
    public const DEFAULT_MARKUP_SCHEMA_VERSION = '1.0.0';

	public const PRICING_ASSIGNMENT_BATCH_SCHEMA         = 'digitalogic.pricing-assignment-batch';
	public const PRICING_ASSIGNMENT_BATCH_SCHEMA_VERSION = '1.0.0';
	public const MAX_PRICING_ASSIGNMENT_BATCH_SIZE       = 500;

    private const MIGRATION_VERSION = 1;
    private const MAX_BATCH_SIZE = 500;
    private const CATALOG_LOCK_NAME = 'digitalogic_import_freight_catalog_v1';
    private const CATALOG_LOCK_TIMEOUT_SECONDS = 10;
    private const DEFAULT_MARKUP_MAX_PERCENT = '1000';
    private const DEFAULT_MARKUP_MAX_SCALE = 12;

    private static $instance = null;
    private $migration_checked = false;
    private $migrating = false;
    private $syncing_legacy_meta = false;
    private $syncing_legacy_rate = false;
    private $catalog_lock_depth = 0;
    private $transaction_active = false;
    private $transaction_option_names = array();
    private $transaction_post_ids = array();
    private $transaction_hook_events = array();
    private $delivery_channels = array();

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'maybe_migrate'), 5);
        add_action('added_post_meta', array($this, 'sync_legacy_assignment'), 10, 4);
        add_action('updated_post_meta', array($this, 'sync_legacy_assignment'), 10, 4);
        add_action('deleted_post_meta', array($this, 'delete_legacy_assignment'), 10, 4);
        add_action('added_option', array($this, 'sync_added_legacy_rate'), 10, 2);
        add_action('updated_option', array($this, 'sync_updated_legacy_rate'), 10, 3);
        add_filter('acf/load_field/key=' . self::LEGACY_ACF_FIELD_KEY, array($this, 'filter_acf_method_field'));
        add_filter('acf/validate_value/key=' . self::LEGACY_ACF_FIELD_KEY, array($this, 'validate_acf_method_value'), 10, 4);
    }

    /**
     * Register an independently invoked post-commit delivery channel.
     *
     * Channel callbacks receive the stable domain action name and its argument
     * array. They must return true on confirmed delivery or a WP_Error on
     * failure. A null/void result is deliberately treated as unconfirmed.
     */
    public function register_delivery_channel($name, $callback) {
        $name = sanitize_key((string) $name);
        if ($name === '' || !is_callable($callback) || isset($this->delivery_channels[$name])) {
            return false;
        }

        $this->delivery_channels[$name] = $callback;
        return true;
    }

    public function unregister_delivery_channel($name) {
        $name = sanitize_key((string) $name);
        if (!isset($this->delivery_channels[$name])) {
            return false;
        }

        unset($this->delivery_channels[$name]);
        return true;
    }

    /**
     * Seed the canonical catalog and migrate legacy ACF product values once.
     *
     * @return array Migration result.
     */
    public function maybe_migrate() {
        if ($this->migration_checked || $this->migrating) {
            return array('migrated' => false, 'assignments_migrated' => 0);
        }

        $version_row = $this->read_option_db(self::MIGRATION_OPTION);
        $methods_row = $this->read_option_db(self::METHODS_OPTION);
        $version = $version_row['exists'] ? (int) $version_row['value'] : 0;
        $stored = $methods_row['exists'] ? $methods_row['value'] : false;

        $required_methods_present = is_array($stored)
            && isset($stored['air_express'], $stored['air_freight'], $stored['sea_freight']);
        if ($version >= self::MIGRATION_VERSION && $required_methods_present) {
            $this->migration_checked = true;
            return array('migrated' => false, 'assignments_migrated' => 0);
        }

        return $this->migrate_legacy_data();
    }

    /**
     * Explicit migration entry point used by activation, upgrades, and tests.
     *
     * @return array Migration result.
     */
    public function migrate_legacy_data() {
        if ($this->migrating) {
            return array('migrated' => false, 'assignments_migrated' => 0);
        }

        $this->migrating = true;
        try {
            $result = $this->with_catalog_lock(function() {
                return $this->run_transaction(function() {
                    $methods = $this->load_methods();
                    $catalog_seeded = false;

                    $seed_methods = $this->legacy_seed_methods();
                    foreach ($seed_methods as $method) {
                        if (is_wp_error($method)) {
                            return $method;
                        }
                    }

                    foreach ($seed_methods as $id => $method) {
                        if (!isset($methods[$id])) {
                            $methods[$id] = $method;
                            $catalog_seeded = true;
                        }
                    }

                    if ($catalog_seeded) {
                        $stored = $this->store_methods($methods);
                        if (is_wp_error($stored)) {
                            return $stored;
                        }
                    }

                    $assignments_migrated = $this->migrate_legacy_product_assignments($methods);
                    if (is_wp_error($assignments_migrated)) {
                        return $assignments_migrated;
                    }

                    $version_stored = $this->store_option_verified(
                        self::MIGRATION_OPTION,
                        self::MIGRATION_VERSION,
                        'digitalogic_import_freight_migration_write_failed'
                    );
                    if (is_wp_error($version_stored)) {
                        return $version_stored;
                    }

                    return array(
                        'migrated' => $catalog_seeded || $assignments_migrated > 0,
                        'catalog_seeded' => $catalog_seeded,
                        'assignments_migrated' => $assignments_migrated,
                        'migration_version' => self::MIGRATION_VERSION,
                    );
                });
            });

            if (!is_wp_error($result)) {
                $this->migration_checked = true;
            }

            return $result;
        } finally {
            $this->migrating = false;
        }
    }

    /**
     * Return methods with assignment counts.
     *
     * @param bool $include_disabled Whether disabled methods should be returned.
     * @return array
     */
    public function list_methods($include_disabled = true) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }

        return $this->present_methods($this->load_methods(), $include_disabled);
    }

    /**
     * Return the canonical nullable catalog-wide percentage markup.
     *
     * The value is read directly from MySQL so Redis or another object cache
     * cannot return stale pricing inputs to Patris.
     *
     * @return array|WP_Error
     */
    public function get_default_percentage_markup() {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }

        return $this->load_default_percentage_markup();
    }

    /**
     * Set or explicitly clear the catalog-wide default percentage markup.
     *
     * Only null removes the option. Blank strings are invalid so an omitted
     * form value cannot accidentally perform a destructive clear. An
     * equivalent canonical value is an idempotent no-op and does not publish
     * another domain event.
     * This mutation never recalculates or writes a WooCommerce product price.
     *
     * @param mixed $value Decimal percentage or null to clear.
     * @return array|WP_Error
     */
    public function update_default_percentage_markup($value) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }

        $clear = is_null($value);
        $canonical = null;
        if (!$clear) {
            $canonical = $this->canonical_default_percentage($value);
            if (is_wp_error($canonical)) {
                return $canonical;
            }
        }

        return $this->with_catalog_lock(function() use ($clear, $canonical) {
            $previous = $this->load_default_percentage_markup();
            $same = $clear
                ? empty($previous['storage_present'])
                : !empty($previous['configured']) && $previous['profit_percent'] === $canonical;
            if ($same) {
                $previous['changed'] = false;
                return $previous;
            }

            $state = $clear ? null : $this->new_default_percentage_markup_state($canonical);
            $stored = $this->run_transaction(function() use ($clear, $state) {
                return $this->store_option_state_verified(
                    self::DEFAULT_MARKUP_OPTION,
                    !$clear,
                    $state,
                    'digitalogic_import_freight_default_markup_write_failed'
                );
            });
            if (is_wp_error($stored)) {
                return $stored;
            }

            $result = $this->load_default_percentage_markup();
            if (
                ($clear && !empty($result['storage_present']))
                || (!$clear && (empty($result['configured']) || $result['profit_percent'] !== $canonical))
            ) {
                return new WP_Error(
                    'digitalogic_import_freight_default_markup_readback_failed',
                    __('The default percentage markup did not pass exact database readback.', 'digitalogic'),
                    array('status' => 500)
                );
            }

            $result['changed'] = true;
            $result['previous_revision'] = $previous['revision'];
            $delivery_warnings = $this->emit_domain_action(
                'digitalogic_import_freight_default_markup_updated',
                $result
            );
            if (!empty($delivery_warnings)) {
                $result['delivery_warnings'] = $delivery_warnings;
            }

            return $result;
        });
    }

    /**
     * Fetch one method.
     *
     * @param string $id Immutable method ID.
     * @return array|WP_Error
     */
    public function get_method($id) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }
        $id = $this->validate_method_id($id);
        if (is_wp_error($id)) {
            return $id;
        }

        $methods = $this->load_methods();
        if (!isset($methods[$id])) {
            return new WP_Error(
                'digitalogic_import_freight_method_not_found',
                __('Import freight method not found.', 'digitalogic'),
                array('status' => 404)
            );
        }

        $method = $methods[$id];
        $method['assigned_products'] = $this->count_assignments($id);

        return $method;
    }

    /**
     * Create a method with a caller-supplied immutable ID.
     *
     * @param array $data Method data.
     * @return array|WP_Error
     */
    public function create_method($data) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }
        $data = is_array($data) ? $data : array();
        if (array_key_exists('legacy_key', $data) && trim((string) $data['legacy_key']) !== '') {
            return new WP_Error(
                'digitalogic_import_freight_legacy_key_reserved',
                __('Legacy freight mappings are reserved for migrated methods.', 'digitalogic'),
                array('status' => 400)
            );
        }
        $id = $this->validate_method_id(isset($data['id']) ? $data['id'] : '');
        if (is_wp_error($id)) {
            return $id;
        }

        if (in_array($id, array('express', 'aerial', 'marine'), true)) {
            return new WP_Error(
                'digitalogic_import_freight_method_id_reserved',
                __('That method ID is reserved by the legacy ACF field mapping.', 'digitalogic'),
                array('status' => 409)
            );
        }

        $data['id'] = $id;
        $reserved_legacy_keys = array(
            'air_express' => 'express',
            'air_freight' => 'aerial',
            'sea_freight' => 'marine',
        );
        if (isset($reserved_legacy_keys[$id])) {
            $data['legacy_key'] = $reserved_legacy_keys[$id];
        }
        $method = $this->sanitize_method($data);
        if (is_wp_error($method)) {
            return $method;
        }

        return $this->with_catalog_lock(function() use ($id, $method) {
            $methods = $this->load_methods();
            if (isset($methods[$id])) {
                return new WP_Error(
                    'digitalogic_import_freight_method_exists',
                    __('Import freight method ID already exists.', 'digitalogic'),
                    array('status' => 409)
                );
            }

            $methods[$id] = $method;
            $stored = $this->run_transaction(function() use ($methods) {
                return $this->store_methods($methods);
            });
            if (is_wp_error($stored)) {
                return $stored;
            }

            $delivery_warnings = $this->emit_domain_action('digitalogic_import_freight_method_created', $method);
            $result = $method;
            $result['assigned_products'] = 0;
            if (!empty($delivery_warnings)) {
                $result['delivery_warnings'] = $delivery_warnings;
            }

            return $result;
        });
    }

    /**
     * Update mutable method fields while preserving the ID and legacy mapping.
     *
     * @param string $id Method ID.
     * @param array  $changes Changed fields.
     * @return array|WP_Error
     */
    public function update_method($id, $changes) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }
        $id = $this->validate_method_id($id);
        if (is_wp_error($id)) {
            return $id;
        }

        $changes = is_array($changes) ? $changes : array();
        if (isset($changes['id']) && (string) $changes['id'] !== $id) {
            return new WP_Error(
                'digitalogic_import_freight_method_id_immutable',
                __('Import freight method IDs are immutable.', 'digitalogic'),
                array('status' => 400)
            );
        }

        return $this->with_catalog_lock(function() use ($id, $changes) {
            $methods = $this->load_methods();
            if (!isset($methods[$id])) {
                return new WP_Error(
                    'digitalogic_import_freight_method_not_found',
                    __('Import freight method not found.', 'digitalogic'),
                    array('status' => 404)
                );
            }

            if (isset($changes['legacy_key']) && $changes['legacy_key'] !== $methods[$id]['legacy_key']) {
                return new WP_Error(
                    'digitalogic_import_freight_legacy_key_immutable',
                    __('Legacy freight mappings are immutable.', 'digitalogic'),
                    array('status' => 400)
                );
            }

            $candidate = array_merge($methods[$id], $changes, array('id' => $id));
            $candidate['legacy_key'] = $methods[$id]['legacy_key'];
            $method = $this->sanitize_method($candidate, $methods[$id]);
            if (is_wp_error($method)) {
                return $method;
            }

            $changed = $method !== $methods[$id];
            $delivery_warnings = array();
            if ($changed) {
                $methods[$id] = $method;
                $stored = $this->run_transaction(function() use ($methods, $method) {
                    $result = $this->store_methods($methods);
                    if (is_wp_error($result)) {
                        return $result;
                    }

                    return $this->sync_legacy_rate_option($method);
                });
                if (is_wp_error($stored)) {
                    return $stored;
                }

                $delivery_warnings = $this->emit_domain_action('digitalogic_import_freight_method_updated', $method);
            }

            $method['assigned_products'] = $this->count_assignments($id);
            $method['changed'] = $changed;
            if (!empty($delivery_warnings)) {
                $method['delivery_warnings'] = $delivery_warnings;
            }

            return $method;
        });
    }

    /**
     * Delete an unassigned method. Assigned methods can be disabled instead.
     *
     * @param string $id Method ID.
     * @return array|WP_Error
     */
    public function delete_method($id) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }
        $id = $this->validate_method_id($id);
        if (is_wp_error($id)) {
            return $id;
        }

        return $this->with_catalog_lock(function() use ($id) {
            $methods = $this->load_methods();
            if (!isset($methods[$id])) {
                return new WP_Error(
                    'digitalogic_import_freight_method_not_found',
                    __('Import freight method not found.', 'digitalogic'),
                    array('status' => 404)
                );
            }

            $assigned = $this->count_assignments($id);
            if ($assigned > 0) {
                return new WP_Error(
                    'digitalogic_import_freight_method_assigned',
                    __('Assigned import freight methods cannot be deleted. Disable the method or reassign its products first.', 'digitalogic'),
                    array('status' => 409, 'assigned_products' => $assigned)
                );
            }

            if (!empty($methods[$id]['legacy_key'])) {
                return new WP_Error(
                    'digitalogic_import_freight_legacy_method_delete_forbidden',
                    __('Migrated legacy import freight methods must be disabled instead of deleted.', 'digitalogic'),
                    array('status' => 409)
                );
            }

            $deleted = $methods[$id];
            unset($methods[$id]);
            $stored = $this->run_transaction(function() use ($methods) {
                return $this->store_methods($methods);
            });
            if (is_wp_error($stored)) {
                return $stored;
            }

            $delivery_warnings = $this->emit_domain_action('digitalogic_import_freight_method_deleted', $deleted);
            $result = array('deleted' => true, 'id' => $id);
            if (!empty($delivery_warnings)) {
                $result['delivery_warnings'] = $delivery_warnings;
            }
            return $result;
        });
    }

    /**
     * Resolve an exact Patris Code/SKU and return its current assignment.
     *
     * @param string $code Patris Code or exact SKU.
     * @return array|WP_Error
     */
    public function get_product_assignment_by_code($code) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }
        $resolved = $this->resolve_freight_product($code);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        return $this->build_assignment_response($resolved);
    }

	/**
	 * Resolve a bounded list of exact product Codes without mutating state.
	 *
	 * Results retain normalized request order. Product lookup failures are
	 * represented per Code so one missing or ambiguous product does not hide
	 * valid assignments in the same read. Shared default-markup state is loaded
	 * once and reused for every successful row.
	 *
	 * @param array $codes Exact Patris Codes or SKU compatibility fallbacks.
	 * @return array|WP_Error
	 */
	public function get_product_assignments_by_codes( $codes ) {
		if ( ! is_array( $codes ) || array_values( $codes ) !== $codes ) {
			return new WP_Error(
				'digitalogic_pricing_assignment_batch_shape_invalid',
				__( 'Product Codes must be provided as an ordered JSON list.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}
		if ( empty( $codes ) ) {
			return new WP_Error(
				'digitalogic_pricing_assignment_batch_empty',
				__( 'At least one product Code is required.', 'digitalogic' ),
				array( 'status' => 400 )
			);
		}
		if ( count( $codes ) > self::MAX_PRICING_ASSIGNMENT_BATCH_SIZE ) {
			return new WP_Error(
				'digitalogic_pricing_assignment_batch_too_large',
				sprintf(
					/* translators: %d: maximum number of Codes per request. */
					__( 'Pricing assignment read batches are limited to %d Codes.', 'digitalogic' ),
					self::MAX_PRICING_ASSIGNMENT_BATCH_SIZE
				),
				array(
					'status'        => 413,
					'maximum_codes' => self::MAX_PRICING_ASSIGNMENT_BATCH_SIZE,
				)
			);
		}

		$normalized_codes = array();
		$seen             = array();
		foreach ( array_values( $codes ) as $index => $code ) {
			$normalized = $this->normalize_code( $code );
			if ( '' === $normalized ) {
				return new WP_Error(
					'digitalogic_pricing_assignment_batch_code_invalid',
					__( 'Every pricing assignment batch item must be a non-empty string or integer Code.', 'digitalogic' ),
					array(
						'status' => 400,
						'index'  => $index,
					)
				);
			}
			if ( isset( $seen[ $normalized ] ) ) {
				return new WP_Error(
					'digitalogic_pricing_assignment_batch_code_duplicate',
					__( 'Duplicate product Codes are not allowed in a pricing assignment read batch.', 'digitalogic' ),
					array(
						'status'      => 400,
						'code_value'  => $normalized,
						'first_index' => $seen[ $normalized ],
						'index'       => $index,
					)
				);
			}
			$seen[ $normalized ] = $index;
			$normalized_codes[]  = $normalized;
		}

		$default_markup = $this->load_default_percentage_markup();
		$results        = array();
		$resolved_count = 0;
		foreach ( $normalized_codes as $code ) {
			$resolved = $this->resolve_freight_product( $code );
			if ( is_wp_error( $resolved ) ) {
				$data      = $resolved->get_error_data();
				$status    = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
				$results[] = array(
					'code'   => $code,
					'status' => 'error',
					'error'  => array(
						'code'        => (string) $resolved->get_error_code(),
						'http_status' => $status,
						'retryable'   => $status >= 500,
					),
				);
				continue;
			}

			++$resolved_count;
			$results[] = array(
				'code'       => $code,
				'status'     => 'ok',
				'assignment' => $this->build_pricing_assignment_projection( $code, $resolved, $default_markup ),
			);
		}

		return array(
			'schema'                    => self::PRICING_ASSIGNMENT_BATCH_SCHEMA,
			'schema_version'            => self::PRICING_ASSIGNMENT_BATCH_SCHEMA_VERSION,
			'requested_count'           => count( $normalized_codes ),
			'resolved_count'            => $resolved_count,
			'error_count'               => count( $normalized_codes ) - $resolved_count,
			'maximum_codes'             => self::MAX_PRICING_ASSIGNMENT_BATCH_SIZE,
			'default_percentage_markup' => $default_markup,
			'results'                   => $results,
		);
	}

    /**
     * Assign or clear a method through deterministic Code/SKU resolution.
     *
     * @param string      $code Patris Code or exact SKU.
     * @param string|null $method_id Method ID; empty clears the assignment.
     * @return array|WP_Error
     */
    public function assign_product_by_code($code, $method_id) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }

        return $this->with_catalog_lock(function() use ($code, $method_id) {
            $resolved = $this->resolve_freight_product($code);
            if (is_wp_error($resolved)) {
                return $resolved;
            }

            $method = $this->validate_assignment_method($method_id, $resolved['product_id']);
            if (is_wp_error($method)) {
                return $method;
            }

            $write = $this->run_transaction(function() use ($resolved, $method) {
                return $this->apply_assignment($resolved['product_id'], $method);
            });
            if (is_wp_error($write)) {
                return $write;
            }

            $delivery_warnings = array();
            if (!empty($write['changed'])) {
                $delivery_warnings = $this->emit_domain_action(
                    'digitalogic_product_import_freight_method_updated',
                    $resolved['product_id'],
                    is_null($method) ? '' : $method['id']
                );
            }

            $response = $this->build_assignment_response($resolved);
            $response['changed'] = !empty($write['changed']);
            if (!empty($delivery_warnings)) {
                $response['delivery_warnings'] = $delivery_warnings;
            }

            return $response;
        });
    }

    /**
     * Apply a preflighted batch. No changes occur when any row is invalid.
     *
     * @param array $assignments List of code/method assignments.
     * @return array|WP_Error
     */
    public function batch_assign_products($assignments) {
        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return $migration;
        }
        if (!is_array($assignments) || empty($assignments)) {
            return new WP_Error(
                'digitalogic_import_freight_empty_batch',
                __('At least one product assignment is required.', 'digitalogic'),
                array('status' => 400)
            );
        }

        if (count($assignments) > self::MAX_BATCH_SIZE) {
            return new WP_Error(
                'digitalogic_import_freight_batch_too_large',
                __('Import freight assignment batches are limited to 500 rows.', 'digitalogic'),
                array('status' => 413)
            );
        }

        return $this->with_catalog_lock(function() use ($assignments) {
            $prepared = array();
            $errors = array();
            $seen_codes = array();
            $seen_product_ids = array();

            foreach (array_values($assignments) as $index => $assignment) {
                $assignment = is_array($assignment) ? $assignment : array();
                $code = $this->normalize_code(isset($assignment['code']) ? $assignment['code'] : '');
                if (array_key_exists('import_freight_method_id', $assignment)) {
                    $method_id = $assignment['import_freight_method_id'];
                } elseif (array_key_exists('method_id', $assignment)) {
                    $method_id = $assignment['method_id'];
                } else {
                    $errors[$index] = array(
                        'code' => 'digitalogic_import_freight_method_required',
                        'message' => __('The import freight method field is required; use null or an empty value to clear it explicitly.', 'digitalogic'),
                    );
                    continue;
                }

                if ($code === '') {
                    $errors[$index] = array('code' => 'digitalogic_invalid_product_code', 'message' => __('Product Code/SKU is required.', 'digitalogic'));
                    continue;
                }

                if (isset($seen_codes[$code])) {
                    $errors[$index] = array('code' => 'digitalogic_duplicate_product_code', 'message' => __('Duplicate Code/SKU in assignment batch.', 'digitalogic'));
                    continue;
                }
                $seen_codes[$code] = true;

                $resolved = $this->resolve_freight_product($code);
                if (is_wp_error($resolved)) {
                    $errors[$index] = array('code' => $resolved->get_error_code(), 'message' => $resolved->get_error_message());
                    continue;
                }

                $product_id = (int) $resolved['product_id'];
                if (isset($seen_product_ids[$product_id])) {
                    $errors[$index] = array(
                        'code' => 'digitalogic_duplicate_product_target',
                        'message' => __('Two batch rows resolve to the same product.', 'digitalogic'),
                        'product_id' => $product_id,
                        'first_index' => $seen_product_ids[$product_id],
                    );
                    continue;
                }
                $seen_product_ids[$product_id] = $index;

                $method = $this->validate_assignment_method($method_id, $product_id);
                if (is_wp_error($method)) {
                    $errors[$index] = array('code' => $method->get_error_code(), 'message' => $method->get_error_message());
                    continue;
                }

                $prepared[] = array('resolved' => $resolved, 'method' => $method);
            }

            if (!empty($errors)) {
                return new WP_Error(
                    'digitalogic_import_freight_batch_invalid',
                    __('No assignments were changed because one or more batch rows were invalid.', 'digitalogic'),
                    array('status' => 400, 'errors' => $errors)
                );
            }

            $writes = $this->run_transaction(function() use ($prepared) {
                $results = array();
                foreach ($prepared as $row) {
                    $write = $this->apply_assignment($row['resolved']['product_id'], $row['method']);
                    if (is_wp_error($write)) {
                        return $write;
                    }
                    $results[] = $write;
                }

                return $results;
            });
            if (is_wp_error($writes)) {
                return $writes;
            }

            $results = array();
            $updated = 0;
            $delivery_warnings = array();
            // The catalog lock keeps this exact direct-DB read stable for the
            // response loop and avoids one option query per batch row.
            $default_markup = $this->load_default_percentage_markup();
            foreach ($prepared as $index => $row) {
                if (!empty($writes[$index]['changed'])) {
                    $updated++;
                    $delivery_warnings = array_merge($delivery_warnings, $this->emit_domain_action(
                        'digitalogic_product_import_freight_method_updated',
                        $row['resolved']['product_id'],
                        is_null($row['method']) ? '' : $row['method']['id']
                    ));
                }

                $response = $this->build_assignment_response($row['resolved'], $default_markup);
                $response['changed'] = !empty($writes[$index]['changed']);
                $results[] = $response;
            }

            $result = array(
                'updated' => $updated,
                'unchanged' => count($results) - $updated,
                'assignments' => $results,
            );
            if (!empty($delivery_warnings)) {
                $result['delivery_warnings'] = array_values(array_unique($delivery_warnings));
            }
            return $result;
        });
    }

    /**
     * Read-only contract consumed by Patris Export or another integration.
     *
     * @return array
     */
    public function get_integration_catalog() {
        $settings = get_option('digitalogic_patris_feed_settings', array());
        $settings = is_array($settings) ? $settings : array();
        $warehouses = isset($settings['selected_warehouses']) && is_array($settings['selected_warehouses'])
            ? array_values(array_unique(array_filter(array_map('sanitize_text_field', $settings['selected_warehouses']))))
            : array();
        sort($warehouses, SORT_STRING);

        $methods = $this->load_catalog_methods_for_read();
        if (is_wp_error($methods)) {
            return $methods;
        }
        $default_markup = $this->load_default_percentage_markup();

        $currency_status = Digitalogic_WooCommerce_Currency_Status::instance()->get_status();
        $local_currency = $currency_status['code'];
        $yuan_rate = $this->yuan_rate();
        $source_effective_date = $this->currency_effective_date();
        $currency_warnings = is_null($yuan_rate)
            ? array('cny_to_local_missing_or_invalid')
            : array();
        $currency_warnings = array_values(array_unique(array_merge(
            $currency_warnings,
            $currency_status['warnings']
        )));
        $material = array(
            'schema' => self::CATALOG_SCHEMA,
            'schema_version' => self::CATALOG_SCHEMA_VERSION,
            'currency' => array(
                'foreign' => 'CNY',
                'local' => $local_currency,
                'cny_to_local' => $yuan_rate,
                'cny_to_irt' => 'IRT' === $local_currency ? $yuan_rate : null,
                'effective_date' => $this->normalize_effective_date($source_effective_date),
                'source_effective_date' => $source_effective_date,
                'source_effective_date_format' => 'ymd',
                'woocommerce_base' => array(
                    'source' => $currency_status['source'],
                    'option' => $currency_status['option'],
                    'code' => $currency_status['code'],
                    'unit' => $currency_status['unit'],
                    'irr_per_unit' => $currency_status['irr_per_unit'],
                    'price_decimals' => $currency_status['price_decimals'],
                ),
                'pricing_output' => array(
                    'code' => $currency_status['pricing_output_currency'],
                    'unit' => $currency_status['pricing_output_unit'],
                    'irr_per_unit' => $currency_status['pricing_output_irr_per_unit'],
                    'price_decimals' => 0,
                ),
                'compatibility' => array(
                    'status' => $currency_status['status'],
                    'compatible' => $currency_status['compatible'],
                    'required_woocommerce_base' => Digitalogic_WooCommerce_Currency_Status::REQUIRED_CURRENCY,
                    'read_only' => $currency_status['read_only'],
                ),
                'warnings' => $currency_warnings,
            ),
            'pricing' => array(
                'formula_id' => self::FORMULA_ID,
                'formula_revision' => self::FORMULA_REVISION,
                'expression' => '((weight_g * freight_cny_per_kg / 1000) + foreign_price_cny) * (1 + profit_percent / 100) * cny_to_irt',
                'inputs' => array('weight_g', 'freight_cny_per_kg', 'foreign_price_cny', 'profit_percent', 'cny_to_irt'),
                'product_markup_contract' => array(
                    'type_field' => 'markup.type',
                    'value_field' => 'markup.value',
                    'profit_percent_field' => 'markup.profit_percent',
                    'supported_type' => 'percentage',
                    'unsupported_types_return_null_with_warning' => true,
                    'resolution_order' => array('product_percentage', 'global_default'),
                    'global_default_field' => 'pricing.default_percentage_markup.profit_percent',
                    'fixed_or_unsupported_never_uses_global_default' => true,
                ),
                'default_percentage_markup' => $default_markup,
                'rounding' => array(
                    'mode' => 'half_up',
                    'local_currency_decimals' => $currency_status['price_decimals'],
                ),
            ),
            'selected_warehouses' => $warehouses,
            'import_freight_methods' => $methods,
        );

        $revision_material = $material;
        foreach ($revision_material['import_freight_methods'] as &$method) {
            unset($method['assigned_products']);
        }
        unset($method);
        $material['revision'] = 'sha256:' . hash('sha256', wp_json_encode($revision_material, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $material;
    }

    /**
     * Keep the canonical assignment in sync when legacy ACF saves a product.
     */
    public function sync_legacy_assignment($meta_id, $object_id, $meta_key, $meta_value) {
        if ($this->syncing_legacy_meta || self::LEGACY_PRODUCT_METHOD_META !== $meta_key || !$this->is_product($object_id)) {
            return;
        }

        $migration = $this->maybe_migrate();
        if (is_wp_error($migration)) {
            return;
        }

        $attempted_value = (string) $meta_value;
        $result = $this->with_catalog_lock(function() use ($object_id, $attempted_value) {
            $methods = $this->load_methods();
            $write = $this->run_transaction(function() use ($object_id, $attempted_value, $methods) {
                $legacy = $this->read_post_meta_db($object_id, self::LEGACY_PRODUCT_METHOD_META, true);
                if (!$legacy['exists'] || (string) $legacy['value'] !== $attempted_value) {
                    return array('stale' => true, 'changed' => false);
                }

                $canonical = $this->read_post_meta_db($object_id, self::PRODUCT_METHOD_META, true);
                $current_id = $canonical['exists'] ? (string) $canonical['value'] : '';
                $method_id = $this->legacy_value_to_method_id($attempted_value, $methods);
                if (
                    $method_id === ''
                    || !isset($methods[$method_id])
                    || (empty($methods[$method_id]['enabled']) && $current_id !== $method_id)
                ) {
                    $restored = $this->restore_legacy_assignment_in_transaction($object_id, $current_id, $methods);
                    return is_wp_error($restored)
                        ? $restored
                        : array('rejected' => true, 'changed' => false);
                }

                $assignment = $this->apply_assignment($object_id, $methods[$method_id]);
                return is_wp_error($assignment)
                    ? $assignment
                    : array('write' => $assignment, 'method_id' => $method_id);
            });

            if (is_wp_error($write)) {
                $this->restore_legacy_assignment_cas($object_id, $attempted_value, $methods);
            }

            return $write;
        });

        if (is_wp_error($result) || empty($result['write'])) {
            return;
        }

        if (!empty($result['write']['changed'])) {
            $this->emit_domain_action('digitalogic_product_import_freight_method_updated', $object_id, $result['method_id']);
        }
    }

    /**
     * Clear the canonical value when ACF clears the legacy value.
     */
    public function delete_legacy_assignment($meta_ids, $object_id, $meta_key, $meta_value) {
        if ($this->syncing_legacy_meta || self::LEGACY_PRODUCT_METHOD_META !== $meta_key || !$this->is_product($object_id)) {
            return;
        }

        $result = $this->with_catalog_lock(function() use ($object_id, $meta_value) {
            return $this->run_transaction(function() use ($object_id, $meta_value) {
                $legacy = $this->read_post_meta_db($object_id, self::LEGACY_PRODUCT_METHOD_META, true);
                if ($legacy['exists']) {
                    return array('changed' => false, 'stale' => true);
                }

                $canonical_row = $this->read_post_meta_db($object_id, self::PRODUCT_METHOD_META, true);
                $canonical = $canonical_row['exists'] ? (string) $canonical_row['value'] : '';
                $deleted_method_id = $this->legacy_value_to_method_id($meta_value);
                if ($canonical === '' || $canonical !== $deleted_method_id) {
                    return array('changed' => false);
                }

                return $this->apply_assignment($object_id, null);
            });
        });
        if (!is_wp_error($result) && !empty($result['changed'])) {
            $this->emit_domain_action('digitalogic_product_import_freight_method_updated', $object_id, '');
        }
    }

    /**
     * Mirror a newly-created legacy ACF rate into an existing canonical method.
     */
    public function sync_added_legacy_rate($option, $value) {
        if (!$this->syncing_legacy_rate) {
            $this->sync_legacy_rate_change($option, $value, false, null);
        }
    }

    /**
     * Mirror a legacy ACF rate update into the canonical catalog.
     */
    public function sync_updated_legacy_rate($option, $old_value, $value) {
        if (!$this->syncing_legacy_rate) {
            $this->sync_legacy_rate_change($option, $value, true, $old_value);
        }
    }

    private function legacy_seed_methods() {
        $definitions = array(
            'air_express' => array(
                'name' => 'Air Freight (Express)',
                'legacy_key' => 'express',
                'fallback_rate' => 85,
            ),
            'air_freight' => array(
                'name' => 'Air Freight',
                'legacy_key' => 'aerial',
                'fallback_rate' => 80,
            ),
            'sea_freight' => array(
                'name' => 'Sea/Ocean Freight',
                'legacy_key' => 'marine',
                'fallback_rate' => 50,
            ),
        );

        $methods = array();
        foreach ($definitions as $id => $definition) {
            $methods[$id] = $this->sanitize_method(array(
                'id' => $id,
                'name' => $definition['name'],
                'enabled' => true,
                'currency' => 'CNY',
                'price_per_kg_cny' => $this->legacy_rate($definition['legacy_key'], $definition['fallback_rate']),
                'minimum_charge_cny' => null,
                'billable_weight_rule' => 'actual',
                'volumetric_divisor_cm3_per_kg' => null,
                'transit_days_min' => null,
                'transit_days_max' => null,
                'metadata' => array('source' => 'legacy_acf_options'),
                'tiered_rates' => array(),
                'legacy_key' => $definition['legacy_key'],
            ));
        }

        return $methods;
    }

    private function legacy_rate($legacy_key, $fallback) {
        $stored = $this->read_option_db('options_' . $legacy_key, $this->transaction_active);
        $value = $this->number_or_null($stored['exists'] ? $stored['value'] : null);

        return is_null($value) ? (float) $fallback : $value;
    }

    private function migrate_legacy_product_assignments($methods) {
        $ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                array('key' => self::LEGACY_PRODUCT_METHOD_META, 'compare' => 'EXISTS'),
            ),
        ));
        $migrated = 0;

        foreach ((array) $ids as $product_id) {
            $canonical_row = $this->read_post_meta_db($product_id, self::PRODUCT_METHOD_META, true);
            $canonical = $canonical_row['exists'] ? (string) $canonical_row['value'] : '';
            if ($canonical !== '' && isset($methods[$canonical])) {
                $method_id = $canonical;
            } else {
                $legacy_row = $this->read_post_meta_db($product_id, self::LEGACY_PRODUCT_METHOD_META, true);
                $legacy = $legacy_row['exists'] ? $legacy_row['value'] : '';
                $method_id = $this->legacy_value_to_method_id($legacy, $methods);
                if ($method_id === '') {
                    continue;
                }
            }

            $write = $this->apply_assignment($product_id, $methods[$method_id]);
            if (is_wp_error($write)) {
                return $write;
            }
            if (!empty($write['changed'])) {
                $migrated++;
            }
        }

        return $migrated;
    }

    private function sanitize_method($data, $existing = null) {
        $id = $this->validate_method_id(isset($data['id']) ? $data['id'] : '');
        if (is_wp_error($id)) {
            return $id;
        }

        $name = sanitize_text_field(isset($data['name']) ? wp_unslash($data['name']) : '');
        if ($name === '') {
            return new WP_Error('digitalogic_import_freight_name_required', __('Import freight method name is required.', 'digitalogic'), array('status' => 400));
        }

        $currency = strtoupper(sanitize_key(isset($data['currency']) ? $data['currency'] : 'CNY'));
        if ('CNY' !== $currency) {
            return new WP_Error('digitalogic_import_freight_currency_unsupported', __('landed_price_v1 currently requires CNY freight rates.', 'digitalogic'), array('status' => 400));
        }

        $price = $this->number_or_null(isset($data['price_per_kg_cny']) ? $data['price_per_kg_cny'] : null);
        if (is_null($price) || $price < 0) {
            return new WP_Error('digitalogic_import_freight_rate_invalid', __('A non-negative CNY price per kilogram is required.', 'digitalogic'), array('status' => 400));
        }

        $minimum = $this->number_or_null(isset($data['minimum_charge_cny']) ? $data['minimum_charge_cny'] : null);
        if (!is_null($minimum) && $minimum < 0) {
            return new WP_Error('digitalogic_import_freight_minimum_invalid', __('Minimum charge cannot be negative.', 'digitalogic'), array('status' => 400));
        }

        $billable_rule = sanitize_key(isset($data['billable_weight_rule']) ? $data['billable_weight_rule'] : 'actual');
        if (!in_array($billable_rule, array('actual', 'volumetric', 'greater_of'), true)) {
            return new WP_Error('digitalogic_import_freight_billable_weight_invalid', __('Unknown billable-weight rule.', 'digitalogic'), array('status' => 400));
        }

        $divisor = $this->number_or_null(isset($data['volumetric_divisor_cm3_per_kg']) ? $data['volumetric_divisor_cm3_per_kg'] : null);
        if (!is_null($divisor) && $divisor <= 0) {
            return new WP_Error('digitalogic_import_freight_divisor_invalid', __('Volumetric divisor must be greater than zero.', 'digitalogic'), array('status' => 400));
        }

        $transit_min = $this->nullable_absint(isset($data['transit_days_min']) ? $data['transit_days_min'] : null);
        $transit_max = $this->nullable_absint(isset($data['transit_days_max']) ? $data['transit_days_max'] : null);
        if (is_wp_error($transit_min)) {
            return $transit_min;
        }
        if (is_wp_error($transit_max)) {
            return $transit_max;
        }
        if (!is_null($transit_min) && !is_null($transit_max) && $transit_max < $transit_min) {
            return new WP_Error('digitalogic_import_freight_transit_invalid', __('Maximum transit days cannot be lower than minimum transit days.', 'digitalogic'), array('status' => 400));
        }

        $legacy_key = '';
        if (is_array($existing) && isset($existing['legacy_key'])) {
            $legacy_key = $existing['legacy_key'];
        } elseif (isset($data['legacy_key'])) {
            $legacy_key = sanitize_key($data['legacy_key']);
        }

        $tiered_rates = $this->sanitize_tiered_rates(isset($data['tiered_rates']) ? $data['tiered_rates'] : array());
        if (is_wp_error($tiered_rates)) {
            return $tiered_rates;
        }

        return array(
            'id' => $id,
            'name' => $name,
            'enabled' => !isset($data['enabled']) || $this->boolean_value($data['enabled']),
            'currency' => 'CNY',
            'price_per_kg_cny' => $price,
            'minimum_charge_cny' => $minimum,
            'billable_weight_rule' => $billable_rule,
            'volumetric_divisor_cm3_per_kg' => $divisor,
            'transit_days_min' => $transit_min,
            'transit_days_max' => $transit_max,
            'metadata' => $this->sanitize_metadata(isset($data['metadata']) ? $data['metadata'] : array()),
            'tiered_rates' => $tiered_rates,
            'legacy_key' => $legacy_key,
        );
    }

    private function sanitize_tiered_rates($tiers) {
        $clean = array();
        foreach ((array) $tiers as $index => $tier) {
            if (!is_array($tier)) {
                return new WP_Error(
                    'digitalogic_import_freight_tier_invalid',
                    __('Every tiered rate must be an object.', 'digitalogic'),
                    array('status' => 400, 'tier_index' => $index)
                );
            }

            $min = $this->number_or_null(isset($tier['min_weight_kg']) ? $tier['min_weight_kg'] : null);
            $max = $this->number_or_null(isset($tier['max_weight_kg']) ? $tier['max_weight_kg'] : null);
            $rate = $this->number_or_null(isset($tier['price_per_kg_cny']) ? $tier['price_per_kg_cny'] : null);
            if (is_null($min) || $min < 0 || is_null($rate) || $rate < 0 || (!is_null($max) && $max < $min)) {
                return new WP_Error(
                    'digitalogic_import_freight_tier_invalid',
                    __('Tiered rates require valid non-negative bounds and rates.', 'digitalogic'),
                    array('status' => 400, 'tier_index' => $index)
                );
            }

            $clean[] = array(
                'min_weight_kg' => $min,
                'max_weight_kg' => $max,
                'price_per_kg_cny' => $rate,
            );
        }

        usort($clean, function($left, $right) {
            return $left['min_weight_kg'] <=> $right['min_weight_kg'];
        });

        $previous_max = null;
        foreach ($clean as $index => $tier) {
            if ($index > 0 && (is_null($previous_max) || $tier['min_weight_kg'] <= $previous_max)) {
                return new WP_Error(
                    'digitalogic_import_freight_tiers_overlap',
                    __('Tiered freight-rate ranges cannot overlap.', 'digitalogic'),
                    array('status' => 400, 'tier_index' => $index)
                );
            }
            $previous_max = $tier['max_weight_kg'];
        }

        return $clean;
    }

    private function sanitize_metadata($value, $depth = 0) {
        if ($depth >= 4 || !is_array($value)) {
            return array();
        }

        $clean = array();
        foreach ($value as $key => $item) {
            $key = sanitize_key($key);
            if ($key === '') {
                continue;
            }

            if (is_array($item)) {
                $clean[$key] = $this->sanitize_metadata($item, $depth + 1);
            } elseif (is_bool($item) || is_numeric($item)) {
                $clean[$key] = $item;
            } else {
                $clean[$key] = sanitize_text_field(wp_unslash((string) $item));
            }
        }

        ksort($clean, SORT_STRING);
        return $clean;
    }

    private function validate_method_id($id) {
        $id = (string) $id;
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $id)) {
            return new WP_Error(
                'digitalogic_import_freight_method_id_invalid',
                __('Method ID must be 2-64 lowercase letters, numbers, or underscores and start with a letter.', 'digitalogic'),
                array('status' => 400)
            );
        }

        return $id;
    }

    private function validate_assignment_method($method_id, $product_id = 0) {
        if (is_null($method_id) || '' === trim((string) $method_id)) {
            return null;
        }

        $method_id = $this->validate_method_id($method_id);
        if (is_wp_error($method_id)) {
            return $method_id;
        }

        $methods = $this->load_methods();
        if (!isset($methods[$method_id])) {
            return new WP_Error('digitalogic_import_freight_method_not_found', __('Import freight method not found.', 'digitalogic'), array('status' => 404));
        }
        if (empty($methods[$method_id]['enabled'])) {
            $current = $product_id
                ? $this->read_post_meta_db($product_id, self::PRODUCT_METHOD_META)
                : array('exists' => false, 'value' => null);
            $current_method_id = $current['exists'] ? (string) $current['value'] : '';
            if ($current_method_id === $method_id) {
                return $methods[$method_id];
            }

            return new WP_Error('digitalogic_import_freight_method_disabled', __('Disabled import freight methods cannot be assigned to new products.', 'digitalogic'), array('status' => 409));
        }

        return $methods[$method_id];
    }

    private function resolve_freight_product($code) {
        $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(array('code' => $code));
        if (is_wp_error($resolved)) {
            $error_code = $resolved->get_error_code();
            $data = is_array($resolved->get_error_data()) ? $resolved->get_error_data() : array();
            if ('digitalogic_invalid_product_identifier' === $error_code) {
                return new WP_Error('digitalogic_invalid_product_code', __('Product Code/SKU is required.', 'digitalogic'), array('status' => 400));
            }
            if ('digitalogic_product_identifier_not_found' === $error_code) {
                return new WP_Error('digitalogic_product_code_not_found', __('No product has that exact Patris Code or SKU.', 'digitalogic'), array('status' => 404));
            }
            if ('digitalogic_product_identifier_ambiguous' === $error_code) {
                return new WP_Error(
                    'digitalogic_product_code_ambiguous',
                    __('More than one product has that exact Patris Code or SKU; no assignment was changed.', 'digitalogic'),
                    array(
                        'status' => 409,
                        'product_ids' => isset($data['woocommerce_ids']) ? $data['woocommerce_ids'] : array(),
                    )
                );
            }

            return $resolved;
        }

        return array(
            'product_id' => (int) $resolved['woocommerce_id'],
            'woocommerce_id' => $resolved['woocommerce_id'],
            'post_type' => $resolved['post_type'],
            'code' => $resolved['identifier'],
            'sku' => $resolved['sku'],
            'patris_code' => $resolved['patris_code'],
            'resolved_by' => $resolved['resolved_by'],
        );
    }

    private function apply_assignment($product_id, $method) {
        $desired_method_id = is_null($method) ? '' : (string) $method['id'];
        $desired_legacy_value = is_null($method) ? '' : $this->field_value_for_method($method);
        $changed = false;

        $this->syncing_legacy_meta = true;
        try {
            $canonical_write = $this->store_post_meta_verified(
                $product_id,
                self::PRODUCT_METHOD_META,
                $desired_method_id
            );
            if (is_wp_error($canonical_write)) {
                return $canonical_write;
            }
            $changed = $changed || !empty($canonical_write['changed']);

            $legacy_write = $this->store_post_meta_verified(
                $product_id,
                self::LEGACY_PRODUCT_METHOD_META,
                $desired_legacy_value
            );
            if (is_wp_error($legacy_write)) {
                return $legacy_write;
            }
            $changed = $changed || !empty($legacy_write['changed']);

            if (!is_null($method)) {
                $reference = $this->read_post_meta_db($product_id, self::LEGACY_ACF_REFERENCE_META, true);
                $reference_write = $this->store_post_meta_verified(
                    $product_id,
                    self::LEGACY_ACF_REFERENCE_META,
                    $reference['exists'] && (string) $reference['value'] !== ''
                        ? (string) $reference['value']
                        : self::LEGACY_ACF_FIELD_KEY
                );
                if (is_wp_error($reference_write)) {
                    return $reference_write;
                }
            }
        } finally {
            $this->syncing_legacy_meta = false;
        }

        return array('changed' => $changed, 'product_id' => (int) $product_id);
    }

    private function build_assignment_response($resolved, $default_markup = null) {
        $method_row = $this->read_post_meta_db($resolved['product_id'], self::PRODUCT_METHOD_META);
        $legacy_row = $this->read_post_meta_db($resolved['product_id'], self::LEGACY_PRODUCT_METHOD_META);
        $method_id = $method_row['exists'] ? (string) $method_row['value'] : '';
        $methods = $this->load_methods();
        if (!is_array($default_markup)) {
            $default_markup = $this->load_default_percentage_markup();
        }
        $markup = $this->build_markup_contract($resolved['product_id'], $default_markup);

        return array_merge($resolved, array(
            'import_freight_method_id' => $method_id,
            'import_freight_method' => $method_id !== '' && isset($methods[$method_id]) ? $methods[$method_id] : null,
            'legacy_shipping_method' => $legacy_row['exists'] ? (string) $legacy_row['value'] : '',
            'markup' => $markup,
            'profit_percent' => $markup['profit_percent'],
            'profit_percent_source' => $markup['source'],
            'pricing_warnings' => $markup['warning'] ? array($markup['warning']) : array(),
        ));
    }

	/**
	 * Build the stable machine projection used by the batch read contract.
	 *
	 * @param string $requested_code Normalized Code from the request.
	 * @param array  $resolved Resolved internal product identity.
	 * @param array  $default_markup Preloaded default-markup contract.
	 * @return array
	 */
	private function build_pricing_assignment_projection( $requested_code, $resolved, $default_markup ) {
		$method_row = $this->read_post_meta_db( $resolved['product_id'], self::PRODUCT_METHOD_META );
		$markup     = $this->build_exact_markup_contract( $resolved['product_id'], $default_markup );

		return array(
			'code'                     => $requested_code,
			'import_freight_method_id' => $method_row['exists'] ? (string) $method_row['value'] : '',
			'profit_percent'           => $markup['profit_percent'],
			'profit_percent_source'    => $markup['source'],
			'pricing_warnings'         => $markup['warning'] ? array( $markup['warning'] ) : array(),
		);
	}

	/**
	 * Preserve an exact product percentage in the machine batch projection.
	 *
	 * @param int   $product_id Product post ID.
	 * @param array $default_markup Preloaded default-markup contract.
	 * @return array
	 */
	private function build_exact_markup_contract( $product_id, $default_markup ) {
		$markup = $this->build_markup_contract( $product_id, $default_markup );
		if ( 'percentage' !== $markup['type'] || 'product_override' !== $markup['source'] ) {
			return $markup;
		}

		$canonical = $this->canonical_default_percentage( get_post_meta( $product_id, '_digitalogic_markup', true ) );
		if ( is_wp_error( $canonical ) ) {
			$markup['value']          = null;
			$markup['profit_percent'] = null;
			$markup['source']         = null;
			$markup['warning']        = 'percentage_markup_value_invalid';
			return $markup;
		}

		$markup['value']          = $canonical;
		$markup['profit_percent'] = $canonical;
		return $markup;
	}

    private function count_assignments($method_id, $methods = null) {
        $ids = get_posts(array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => self::PRODUCT_METHOD_META,
            'meta_value' => $method_id,
        ));

        $methods = is_array($methods) ? $methods : $this->load_methods();
        if (isset($methods[$method_id])) {
            $legacy_value = $this->field_value_for_method($methods[$method_id]);
            $legacy_ids = get_posts(array(
                'post_type' => array('product', 'product_variation'),
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_key' => self::LEGACY_PRODUCT_METHOD_META,
                'meta_value' => $legacy_value,
            ));
            $ids = array_merge((array) $ids, (array) $legacy_ids);
        }

        return count(array_unique(array_map('absint', (array) $ids)));
    }

    private function legacy_value_to_method_id($legacy_value, $methods = null) {
        $legacy_value = sanitize_key((string) $legacy_value);
        if ($legacy_value === '') {
            return '';
        }

        $aliases = array(
            'express' => 'air_express',
            'aerial' => 'air_freight',
            'marine' => 'sea_freight',
        );
        $methods = is_array($methods) ? $methods : $this->load_methods();
        if (isset($aliases[$legacy_value])) {
            return isset($methods[$aliases[$legacy_value]]) ? $aliases[$legacy_value] : '';
        }

        return isset($methods[$legacy_value]) ? $legacy_value : '';
    }

    private function sync_legacy_rate_option($method) {
        if (!empty($method['legacy_key'])) {
            $this->syncing_legacy_rate = true;
            try {
                return $this->store_option_verified(
                    'options_' . $method['legacy_key'],
                    $method['price_per_kg_cny'],
                    'digitalogic_import_freight_legacy_rate_write_failed'
                );
            } finally {
                $this->syncing_legacy_rate = false;
            }
        }

        return array('changed' => false);
    }

    private function sync_legacy_rate_change($option, $value, $old_exists, $old_value = null) {
        $mapping = array(
            'options_express' => 'air_express',
            'options_aerial' => 'air_freight',
            'options_marine' => 'sea_freight',
        );
        if (!isset($mapping[$option])) {
            return;
        }

        $method_id = $mapping[$option];
        $result = $this->with_catalog_lock(function() use ($option, $value, $method_id, $old_exists, $old_value) {
            $methods = $this->load_methods();
            $stored = $this->run_transaction(function() use ($option, $value, $method_id, $old_exists, $old_value, $methods) {
                $current = $this->read_option_db($option, true);
                if (!$current['exists'] || (string) $current['value'] !== (string) $value) {
                    return array('changed' => false, 'stale' => true, 'method' => null);
                }

                $rate = $this->number_or_null($current['value']);
                if (is_null($rate) || $rate < 0) {
                    $restored = $this->store_option_state_verified(
                        $option,
                        $old_exists,
                        $old_value,
                        'digitalogic_import_freight_legacy_rate_restore_failed'
                    );
                    return is_wp_error($restored)
                        ? $restored
                        : array('changed' => false, 'rejected' => true, 'method' => null);
                }

                if (!isset($methods[$method_id]) || $methods[$method_id]['price_per_kg_cny'] === $rate) {
                    return array('changed' => false, 'method' => isset($methods[$method_id]) ? $methods[$method_id] : null);
                }

                $methods[$method_id]['price_per_kg_cny'] = $rate;
                $catalog_stored = $this->store_methods($methods);
                return is_wp_error($catalog_stored)
                    ? $catalog_stored
                    : array('changed' => true, 'method' => $methods[$method_id]);
            });

            if (is_wp_error($stored)) {
                $this->restore_legacy_rate_option_cas($option, $value, $old_exists, $old_value);
            }

            return $stored;
        });

        if (is_wp_error($result)) {
            return;
        }

        if (!empty($result['changed']) && is_array($result['method'])) {
            $this->emit_domain_action('digitalogic_import_freight_method_updated', $result['method']);
        }
    }

    private function load_methods() {
        $stored = $this->read_option_db(self::METHODS_OPTION);
        $methods = $stored['exists'] ? $stored['value'] : array();
        if (!is_array($methods)) {
            return array();
        }

        $valid = array();
        foreach ($methods as $id => $method) {
            if (!is_array($method) || !isset($method['id']) || $method['id'] !== $id) {
                continue;
            }
            $valid[$id] = $method;
        }
        ksort($valid, SORT_STRING);

        return $valid;
    }

    // phpcs:disable -- New read projection is isolated inside a legacy non-WPCS class.
    /**
     * Build the complete integration catalog projection without migrating.
     *
     * Activation and init own persistence. A cold read overlays the same
     * canonical defaults in memory so the machine contract remains typed and
     * useful without turning a GET request into a write path.
     *
     * @return array|WP_Error
     */
    private function load_catalog_methods_for_read() {
        $methods = $this->load_methods();
        $defaults = $this->legacy_seed_methods();

        foreach ($defaults as $id => $method) {
            if (is_wp_error($method)) {
                return $method;
            }
            if (!isset($methods[$id])) {
                $methods[$id] = $method;
            }
        }
        ksort($methods, SORT_STRING);

        return $this->present_methods($methods, true);
    }

    /**
     * Add assignment counts to a method map without repeating projection code.
     *
     * @param array $methods Canonical method map.
     * @param bool  $include_disabled Whether disabled methods are returned.
     * @return array
     */
    private function present_methods($methods, $include_disabled) {
        $result = array();
        foreach ($methods as $method) {
            if (!$include_disabled && empty($method['enabled'])) {
                continue;
            }

            $method['assigned_products'] = $this->count_assignments($method['id'], $methods);
            $result[] = $method;
        }

        return $result;
    }
    // phpcs:enable

    private function store_methods($methods) {
        ksort($methods, SORT_STRING);
        return $this->store_option_verified(
            self::METHODS_OPTION,
            $methods,
            'digitalogic_import_freight_catalog_write_failed'
        );
    }

    /**
     * Read an option from MySQL on the current connection, bypassing the
     * shared WordPress object cache. FOR UPDATE is used only inside a write
     * transaction so concurrent database writers cannot change the row while
     * it is being reconciled.
     */
    private function read_option_db($name, $for_update = false) {
        global $wpdb;

        $table = isset($wpdb->options) ? $wpdb->options : $wpdb->prefix . 'options';
        $query = "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1";
        if ($for_update) {
            $query .= ' FOR UPDATE';
        }
        $row = $wpdb->get_row($wpdb->prepare($query, $name), ARRAY_A);

        return is_array($row)
            ? array(
                'exists' => true,
                'value' => maybe_unserialize($row['option_value']),
                'raw' => (string) $row['option_value'],
            )
            : array('exists' => false, 'value' => null, 'raw' => null);
    }

    /**
     * Compare an option row using the exact serialized representation MySQL
     * stores. WordPress/MySQL returns scalar option values as strings, so a
     * strict comparison of the unserialized PHP values rejects valid writes
     * such as integer migration markers and floating-point legacy rates.
     */
    private function option_row_matches($row, $desired_exists, $value = null) {
        if ((bool) $row['exists'] !== (bool) $desired_exists) {
            return false;
        }
        if (!$desired_exists) {
            return true;
        }

        return (string) $row['raw'] === (string) maybe_serialize($value);
    }

    /**
     * Read one post-meta value directly from MySQL, bypassing Redis/meta cache.
     */
    private function read_post_meta_db($post_id, $key, $for_update = false) {
        global $wpdb;

        $table = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $query = "SELECT meta_id, meta_value FROM {$table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1";
        if ($for_update) {
            $query .= ' FOR UPDATE';
        }
        $row = $wpdb->get_row($wpdb->prepare($query, (int) $post_id, $key), ARRAY_A);

        return is_array($row)
            ? array(
                'exists' => true,
                'value' => maybe_unserialize($row['meta_value']),
                'meta_id' => (int) $row['meta_id'],
            )
            : array('exists' => false, 'value' => null, 'meta_id' => 0);
    }

    /**
     * Low-level option write used by the transaction.
     * It deliberately does not mutate WordPress caches or fire hooks.
     */
    private function write_option_db($name, $exists, $value = null) {
        global $wpdb;

        $table = isset($wpdb->options) ? $wpdb->options : $wpdb->prefix . 'options';
        $current = $this->read_option_db($name, $this->transaction_active);
        if (!$exists) {
            if (!$current['exists']) {
                return true;
            }
            return false !== $wpdb->delete($table, array('option_name' => $name), array('%s'));
        }

        $serialized = maybe_serialize($value);
        if ($current['exists']) {
            if ($this->option_row_matches($current, true, $value)) {
                return true;
            }
            return false !== $wpdb->update(
                $table,
                array('option_value' => $serialized),
                array('option_name' => $name),
                array('%s'),
                array('%s')
            );
        }

        return false !== $wpdb->insert(
            $table,
            array('option_name' => $name, 'option_value' => $serialized, 'autoload' => 'no'),
            array('%s', '%s', '%s')
        );
    }

    /**
     * Low-level post-meta write used by the transaction.
     */
    private function write_post_meta_db($post_id, $key, $exists, $value = null) {
        global $wpdb;

        $table = isset($wpdb->postmeta) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
        $current = $this->read_post_meta_db($post_id, $key, $this->transaction_active);
        if (!$exists) {
            if (!$current['exists']) {
                return true;
            }
            return false !== $wpdb->delete(
                $table,
                array('post_id' => (int) $post_id, 'meta_key' => $key),
                array('%d', '%s')
            );
        }

        $serialized = maybe_serialize($value);
        if ($current['exists']) {
            if ($current['value'] === $value) {
                return true;
            }
            return false !== $wpdb->update(
                $table,
                array('meta_value' => $serialized),
                array('meta_id' => $current['meta_id']),
                array('%s'),
                array('%d')
            );
        }

        return false !== $wpdb->insert(
            $table,
            array('post_id' => (int) $post_id, 'meta_key' => $key, 'meta_value' => $serialized),
            array('%d', '%s', '%s')
        );
    }

    private function restore_legacy_rate_option_cas($option, $attempted_value, $old_exists, $old_value) {
        return $this->run_transaction(function() use ($option, $attempted_value, $old_exists, $old_value) {
            $current = $this->read_option_db($option, true);
            if (!$current['exists'] || (string) $current['value'] !== (string) $attempted_value) {
                return array('changed' => false, 'stale' => true);
            }

            return $this->store_option_state_verified(
                $option,
                $old_exists,
                $old_value,
                'digitalogic_import_freight_legacy_rate_restore_failed'
            );
        });
    }

    /**
     * Run a catalog/assignment mutation under a site-scoped advisory lock.
     *
     * @param callable $callback Mutation callback.
     * @return mixed|WP_Error
     */
    private function with_catalog_lock($callback) {
        $acquired = $this->acquire_catalog_lock();
        if (is_wp_error($acquired)) {
            return $acquired;
        }

        try {
            return call_user_func($callback);
        } catch (Throwable $exception) {
            if ($this->transaction_active) {
                $rollback = $this->rollback_transaction();
                if (is_wp_error($rollback)) {
                    return $rollback;
                }
            }

            return new WP_Error(
                'digitalogic_import_freight_unexpected_write_failure',
                __('The import freight change could not be completed.', 'digitalogic'),
                array('status' => 500, 'exception' => get_class($exception))
            );
        } finally {
            $this->release_catalog_lock();
        }
    }

    private function acquire_catalog_lock() {
        if ($this->catalog_lock_depth > 0) {
            $this->catalog_lock_depth++;
            return true;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return new WP_Error(
                'digitalogic_import_freight_lock_unavailable',
                __('The database lock service is unavailable.', 'digitalogic'),
                array('status' => 503)
            );
        }

        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
        $lock_name = substr(self::CATALOG_LOCK_NAME . '_' . md5($prefix), 0, 64);
        $locked = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lock_name,
            self::CATALOG_LOCK_TIMEOUT_SECONDS
        ));
        if ('1' !== (string) $locked) {
            return new WP_Error(
                'digitalogic_import_freight_catalog_busy',
                __('Another import freight update is already running. Please retry.', 'digitalogic'),
                array('status' => 503, 'retryable' => true)
            );
        }

        $this->catalog_lock_depth = 1;
        return true;
    }

    private function release_catalog_lock() {
        if ($this->catalog_lock_depth <= 0) {
            return;
        }
        $this->catalog_lock_depth--;
        if ($this->catalog_lock_depth > 0) {
            return;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
            return;
        }

        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
        $lock_name = substr(self::CATALOG_LOCK_NAME . '_' . md5($prefix), 0, 64);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    private function run_transaction($callback) {
        $started = $this->begin_transaction();
        if (is_wp_error($started)) {
            return $started;
        }

        try {
            $result = call_user_func($callback);
        } catch (Throwable $exception) {
            $rollback = $this->rollback_transaction();
            if (is_wp_error($rollback)) {
                return $rollback;
            }
            return new WP_Error(
                'digitalogic_import_freight_transaction_exception',
                __('The import freight transaction was rolled back.', 'digitalogic'),
                array('status' => 500, 'exception' => get_class($exception))
            );
        }

        if (is_wp_error($result)) {
            $rollback = $this->rollback_transaction();
            return is_wp_error($rollback) ? $rollback : $result;
        }

        $committed = $this->commit_transaction();
        if (is_wp_error($committed)) {
            $rollback = $this->rollback_transaction();
            return is_wp_error($rollback) ? $rollback : $committed;
        }

        return $result;
    }

    private function begin_transaction() {
        if ($this->transaction_active) {
            return new WP_Error(
                'digitalogic_import_freight_nested_transaction',
                __('Nested import freight transactions are not supported.', 'digitalogic'),
                array('status' => 500)
            );
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'query') || false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error(
                'digitalogic_import_freight_transaction_unavailable',
                __('The database could not start an import freight transaction.', 'digitalogic'),
                array('status' => 503)
            );
        }

        $this->transaction_active = true;
        $this->transaction_option_names = array();
        $this->transaction_post_ids = array();
        $this->transaction_hook_events = array();
        return true;
    }

    private function commit_transaction() {
        global $wpdb;
        if (!$this->transaction_active || false === $wpdb->query('COMMIT')) {
            return new WP_Error(
                'digitalogic_import_freight_commit_failed',
                __('The import freight transaction could not be committed.', 'digitalogic'),
                array('status' => 500)
            );
        }

        $option_names = $this->transaction_option_names;
        $post_ids = $this->transaction_post_ids;
        $hook_events = $this->transaction_hook_events;
        $this->transaction_active = false;
        $this->transaction_option_names = array();
        $this->transaction_post_ids = array();
        $this->transaction_hook_events = array();
        $this->invalidate_transaction_caches($option_names, $post_ids);
        $this->dispatch_committed_storage_hooks($hook_events);
        return true;
    }

    private function rollback_transaction() {
        global $wpdb;
        $option_names = $this->transaction_option_names;
        $post_ids = $this->transaction_post_ids;
        $rollback_failed = $this->transaction_active
            && (!is_object($wpdb) || !method_exists($wpdb, 'query') || false === $wpdb->query('ROLLBACK'));

        $this->transaction_active = false;
        $this->transaction_option_names = array();
        $this->transaction_post_ids = array();
        $this->transaction_hook_events = array();
        $this->invalidate_transaction_caches($option_names, $post_ids);

        if ($rollback_failed) {
            return new WP_Error(
                'digitalogic_import_freight_rollback_failed',
                __('The database could not roll back the failed import freight change.', 'digitalogic'),
                array('status' => 500)
            );
        }

        return true;
    }

    private function invalidate_transaction_caches($option_names, $post_ids) {
        if (!function_exists('wp_cache_delete')) {
            return;
        }

        foreach (array_keys((array) $option_names) as $option_name) {
            wp_cache_delete($option_name, 'options');
        }
        if (!empty($option_names)) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        foreach (array_keys((array) $post_ids) as $post_id) {
            wp_cache_delete((int) $post_id, 'post_meta');
        }
    }

    private function dispatch_committed_storage_hooks($events) {
        $previous_legacy_rate_guard = $this->syncing_legacy_rate;
        $previous_legacy_meta_guard = $this->syncing_legacy_meta;
        $this->syncing_legacy_rate = true;
        $this->syncing_legacy_meta = true;
        try {
            foreach ((array) $events as $event) {
                if (!isset($event['hook'], $event['args'])) {
                    continue;
                }
                if ('updated_option' === $event['hook']) {
                    $this->safe_do_action('update_option', $event['args'][0], $event['args'][1], $event['args'][2]);
                    $this->safe_do_action('update_option_' . $event['args'][0], $event['args'][1], $event['args'][2], $event['args'][0]);
                } elseif ('added_option' === $event['hook']) {
                    $this->safe_do_action('add_option', $event['args'][0], $event['args'][1]);
                    $this->safe_do_action('add_option_' . $event['args'][0], $event['args'][0], $event['args'][1]);
                } elseif ('deleted_option' === $event['hook']) {
                    $this->safe_do_action('delete_option', $event['args'][0]);
                    $this->safe_do_action('delete_option_' . $event['args'][0], $event['args'][0]);
                }
                $this->safe_do_action($event['hook'], ...$event['args']);
            }
        } finally {
            $this->syncing_legacy_rate = $previous_legacy_rate_guard;
            $this->syncing_legacy_meta = $previous_legacy_meta_guard;
        }
    }

    private function safe_do_action($hook, ...$args) {
        try {
            do_action($hook, ...$args);
            return true;
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[Digitalogic import freight] Listener for %s failed after commit: %s',
                $hook,
                $exception->getMessage()
            ));
            return false;
        }
    }

    private function emit_domain_action($hook, ...$args) {
        $warnings = array();

        // Built-in and third-party result-aware transports run independently,
        // so one exception or false/void result cannot prevent later channels.
        foreach ($this->delivery_channels as $channel => $callback) {
            try {
                $result = call_user_func($callback, $hook, $args);
                if (true === $result) {
                    continue;
                }

                if (is_wp_error($result)) {
                    $warnings[] = 'event_delivery_failed:' . $channel . ':' . sanitize_key($result->get_error_code());
                } else {
                    $warnings[] = 'event_delivery_unconfirmed:' . $channel;
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    '[Digitalogic import freight] Delivery channel %s failed after commit: %s',
                    $channel,
                    $exception->getMessage()
                ));
                $warnings[] = 'event_delivery_failed:' . $channel . ':exception';
            }
        }

        // Preserve the public domain actions for existing integrations. The
        // result-aware transports above no longer depend on this action fanout.
        if (!$this->safe_do_action($hook, ...$args)) {
            $warnings[] = 'event_delivery_failed:' . $hook;
        }

        return array_values(array_unique($warnings));
    }

    private function store_option_verified($name, $value, $error_code) {
        return $this->store_option_state_verified($name, true, $value, $error_code);
    }

    private function store_option_state_verified($name, $desired_exists, $value, $error_code) {
        if (!$this->transaction_active) {
            return new WP_Error(
                'digitalogic_import_freight_transaction_required',
                __('Import freight storage writes require an active transaction.', 'digitalogic'),
                array('status' => 500)
            );
        }

        $old = $this->read_option_db($name, true);
        $old_value = $old['value'];
        $old_exists = $old['exists'];
        if ($this->option_row_matches($old, $desired_exists, $value)) {
            return array('changed' => false);
        }

        $this->transaction_option_names[$name] = true;
        if (!$this->write_option_db($name, $desired_exists, $value)) {
            return new WP_Error(
                $error_code,
                __('The import freight option could not be saved.', 'digitalogic'),
                array('status' => 500, 'option' => $name)
            );
        }
        $stored = $this->read_option_db($name, true);
        if (!$this->option_row_matches($stored, $desired_exists, $value)) {
            return new WP_Error(
                $error_code,
                __('The import freight option could not be saved.', 'digitalogic'),
                array('status' => 500, 'option' => $name)
            );
        }

        if (!$desired_exists) {
            $this->transaction_hook_events[] = array('hook' => 'deleted_option', 'args' => array($name));
        } elseif ($old_exists) {
            $this->transaction_hook_events[] = array('hook' => 'updated_option', 'args' => array($name, $old_value, $value));
        } else {
            $this->transaction_hook_events[] = array('hook' => 'added_option', 'args' => array($name, $value));
        }

        return array('changed' => true);
    }

    private function store_post_meta_verified($post_id, $key, $value) {
        if (!$this->transaction_active) {
            return new WP_Error(
                'digitalogic_import_freight_transaction_required',
                __('Import freight storage writes require an active transaction.', 'digitalogic'),
                array('status' => 500)
            );
        }

        $old = $this->read_post_meta_db($post_id, $key, true);
        $old_exists = $old['exists'];
        $old_value = $old['value'];
        $value = (string) $value;
        if ($this->transaction_active) {
            $this->transaction_post_ids[(int) $post_id] = true;
        }

        $desired_exists = $value !== '';
        if ($old_exists === $desired_exists && (!$desired_exists || (string) $old_value === $value)) {
            return array('changed' => false);
        }

        if (!$this->write_post_meta_db($post_id, $key, $desired_exists, $value)) {
            return new WP_Error(
                'digitalogic_import_freight_meta_write_failed',
                __('The product import freight assignment could not be saved.', 'digitalogic'),
                array('status' => 500, 'product_id' => (int) $post_id, 'meta_key' => $key)
            );
        }
        $stored = $this->read_post_meta_db($post_id, $key, true);
        if (($desired_exists && (!$stored['exists'] || (string) $stored['value'] !== $value)) || (!$desired_exists && $stored['exists'])) {
            return new WP_Error(
                'digitalogic_import_freight_meta_write_failed',
                __('The product import freight assignment could not be saved.', 'digitalogic'),
                array('status' => 500, 'product_id' => (int) $post_id, 'meta_key' => $key)
            );
        }

        if (!$desired_exists) {
            $this->transaction_hook_events[] = array(
                'hook' => 'deleted_post_meta',
                'args' => array(array($old['meta_id']), (int) $post_id, $key, $old_value),
            );
        } elseif ($old_exists) {
            $this->transaction_hook_events[] = array(
                'hook' => 'updated_post_meta',
                'args' => array($old['meta_id'], (int) $post_id, $key, $value),
            );
        } else {
            $this->transaction_hook_events[] = array(
                'hook' => 'added_post_meta',
                'args' => array($stored['meta_id'], (int) $post_id, $key, $value),
            );
        }

        return array('changed' => true);
    }

    private function field_value_for_method($method) {
        return !empty($method['legacy_key']) ? (string) $method['legacy_key'] : (string) $method['id'];
    }

    private function restore_legacy_assignment_in_transaction($product_id, $canonical_id, $methods) {
        $desired = $canonical_id !== '' && isset($methods[$canonical_id])
            ? $this->field_value_for_method($methods[$canonical_id])
            : '';

        return $this->store_post_meta_verified(
            $product_id,
            self::LEGACY_PRODUCT_METHOD_META,
            $desired
        );
    }

    private function restore_legacy_assignment_cas($product_id, $attempted_value, $methods) {
        return $this->run_transaction(function() use ($product_id, $attempted_value, $methods) {
            $legacy = $this->read_post_meta_db($product_id, self::LEGACY_PRODUCT_METHOD_META, true);
            if (!$legacy['exists'] || (string) $legacy['value'] !== (string) $attempted_value) {
                return array('changed' => false, 'stale' => true);
            }

            $canonical = $this->read_post_meta_db($product_id, self::PRODUCT_METHOD_META, true);
            $canonical_id = $canonical['exists'] ? (string) $canonical['value'] : '';
            return $this->restore_legacy_assignment_in_transaction(
                $product_id,
                $canonical_id,
                $methods
            );
        });
    }

    private function build_markup_contract($product_id, $default_markup) {
        $type_exists = metadata_exists('post', $product_id, '_digitalogic_markup_type');
        $value_exists = metadata_exists('post', $product_id, '_digitalogic_markup');
        $raw_type = get_post_meta($product_id, '_digitalogic_markup_type', true);
        $raw_value = get_post_meta($product_id, '_digitalogic_markup', true);
        $type_input = is_scalar($raw_type) ? strtolower(trim(wp_unslash((string) $raw_type))) : '';
        $type = sanitize_key($type_input);
        $type_malformed = $type_exists && (
            !is_scalar($raw_type)
            || ('' !== $type_input && ('' === $type || $type !== $type_input))
        );
        $type_empty = !$type_exists || '' === $type_input;
        $value_empty = !$value_exists
            || is_null($raw_value)
            || (is_string($raw_value) && '' === trim($raw_value));
        $semantically_unconfigured = (
            (!$type_exists && !$value_exists)
            || ($type_exists && $value_exists && $type_empty && $value_empty)
        );
        $value_present = !$value_empty;
        $value = $this->number_or_null($raw_value);
        $profit_percent = null;
        $source = null;
        $default_revision = null;
        $warning = null;

        if ($type_malformed) {
            $warning = 'markup_type_malformed';
        } elseif ($semantically_unconfigured) {
            if (!empty($default_markup['configured'])) {
                $profit_percent = $default_markup['profit_percent'];
                $source = 'global_default';
                $default_revision = $default_markup['revision'];
            } else {
                $source = 'unset';
                $warning = 'markup_missing';
            }
        } elseif ($type_exists && !$value_exists && $type_empty) {
            $warning = 'markup_metadata_value_absent';
        } elseif (!$type_exists && $value_exists && $value_empty) {
            $warning = 'markup_metadata_type_absent';
        } elseif ($type === 'percentage' && !is_null($value) && $value >= 0 && $value <= (float) self::DEFAULT_MARKUP_MAX_PERCENT) {
            $profit_percent = $value;
            $source = 'product_override';
        } elseif ($type === 'fixed') {
            $warning = 'fixed_markup_not_supported_by_landed_price_v1';
        } elseif ($type === 'percentage') {
            $warning = !$value_present ? 'percentage_markup_value_missing' : 'percentage_markup_value_invalid';
        } elseif ($type === '') {
            $warning = 'markup_type_missing';
        } else {
            $warning = 'markup_type_unsupported';
        }

        return array(
            'type' => $type === '' ? null : $type,
            'value' => $value,
            'profit_percent' => $profit_percent,
            'source' => $source,
            'default_revision' => $default_revision,
            'warning' => $warning,
        );
    }

    private function load_default_percentage_markup() {
        $row = $this->read_option_db(self::DEFAULT_MARKUP_OPTION);
        if (!$row['exists']) {
            return $this->default_percentage_markup_contract(false, null, 'unset', null, 0, false, array('default_markup_unset'));
        }

        $state = $row['value'];
        if (!is_array($state)) {
            return $this->default_percentage_markup_contract(false, null, 'invalid_storage', null, 0, true, array('default_markup_storage_invalid'));
        }
        $canonical = $this->canonical_default_percentage(isset($state['profit_percent']) ? $state['profit_percent'] : null);
        if (
            is_wp_error($canonical)
            || !isset($state['schema'], $state['schema_version'], $state['type'], $state['source'], $state['revision'])
            || self::DEFAULT_MARKUP_SCHEMA !== $state['schema']
            || self::DEFAULT_MARKUP_SCHEMA_VERSION !== $state['schema_version']
            || 'percentage' !== $state['type']
            || 'global_default' !== $state['source']
            || !isset($state['configured'])
            || true !== $state['configured']
            || !array_key_exists('profit_percent', $state)
            || !is_string($state['profit_percent'])
            || $state['profit_percent'] !== $canonical
        ) {
            return $this->default_percentage_markup_contract(false, null, 'invalid_storage', null, 0, true, array('default_markup_storage_invalid'));
        }

        $identity = $this->default_percentage_markup_identity(true, $canonical, 'global_default');
        $revision = $this->default_percentage_markup_revision($identity);
        if (!hash_equals($revision, (string) $state['revision'])) {
            return $this->default_percentage_markup_contract(false, null, 'invalid_storage', null, 0, true, array('default_markup_storage_invalid'));
        }

        return array_merge($identity, array(
            'revision' => $revision,
            'updated_at' => isset($state['updated_at']) ? (string) $state['updated_at'] : '',
            'updated_by' => isset($state['updated_by']) ? absint($state['updated_by']) : 0,
            'storage_present' => true,
            'bounds' => $this->default_percentage_markup_bounds(),
            'warnings' => array(),
        ));
    }

    private function new_default_percentage_markup_state($canonical) {
        $identity = $this->default_percentage_markup_identity(true, $canonical, 'global_default');

        return array_merge($identity, array(
            'revision' => $this->default_percentage_markup_revision($identity),
            'updated_at' => current_time('mysql', true),
            'updated_by' => function_exists('get_current_user_id') ? absint(get_current_user_id()) : 0,
        ));
    }

    private function default_percentage_markup_contract($configured, $value, $source, $updated_at, $updated_by, $storage_present, $warnings) {
        $identity = $this->default_percentage_markup_identity($configured, $value, $source);

        return array_merge($identity, array(
            'revision' => $this->default_percentage_markup_revision($identity),
            'updated_at' => $updated_at,
            'updated_by' => absint($updated_by),
            'storage_present' => (bool) $storage_present,
            'bounds' => $this->default_percentage_markup_bounds(),
            'warnings' => array_values($warnings),
        ));
    }

    private function default_percentage_markup_identity($configured, $value, $source) {
        return array(
            'schema' => self::DEFAULT_MARKUP_SCHEMA,
            'schema_version' => self::DEFAULT_MARKUP_SCHEMA_VERSION,
            'configured' => (bool) $configured,
            'type' => 'percentage',
            'profit_percent' => $value,
            'source' => $source,
        );
    }

    private function default_percentage_markup_revision($identity) {
        return 'sha256:' . hash(
            'sha256',
            wp_json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function default_percentage_markup_bounds() {
        return array(
            'minimum' => '0',
            'maximum' => self::DEFAULT_MARKUP_MAX_PERCENT,
            'maximum_fraction_digits' => self::DEFAULT_MARKUP_MAX_SCALE,
        );
    }

    private function canonical_default_percentage($value) {
        if (is_bool($value) || is_array($value) || is_object($value) || is_null($value)) {
            return new WP_Error(
                'digitalogic_import_freight_default_markup_invalid',
                __('Default markup must be a finite base-10 percentage.', 'digitalogic'),
                array('status' => 400)
            );
        }
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return new WP_Error(
                    'digitalogic_import_freight_default_markup_invalid',
                    __('Default markup must be a finite base-10 percentage.', 'digitalogic'),
                    array('status' => 400)
                );
            }
            $text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            if (!is_string($text)) {
                $text = '';
            }
        } elseif (is_string($value)) {
            $text = trim(wp_unslash($value));
            $text = strtr($text, array(
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
                '٫' => '.',
            ));
        } else {
            $text = '';
        }

        if (strlen($text) > 64 || !preg_match('/^[+\-]?[0-9]+(?:\.[0-9]+)?$/', $text)) {
            return new WP_Error(
                'digitalogic_import_freight_default_markup_invalid',
                __('Default markup must be a finite base-10 percentage without exponent or grouping notation.', 'digitalogic'),
                array('status' => 400)
            );
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        $parts = explode('.', $text, 2);
        $integer = ltrim($parts[0], '0');
        $integer = '' === $integer ? '0' : $integer;
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
        if (strlen($fraction) > self::DEFAULT_MARKUP_MAX_SCALE) {
            return new WP_Error(
                'digitalogic_import_freight_default_markup_scale_invalid',
                sprintf(__('Default markup supports at most %d fractional digits.', 'digitalogic'), self::DEFAULT_MARKUP_MAX_SCALE),
                array('status' => 400, 'maximum_fraction_digits' => self::DEFAULT_MARKUP_MAX_SCALE)
            );
        }

        $is_zero = '0' === $integer && '' === $fraction;
        if (
            ($negative && !$is_zero)
            || strlen($integer) > strlen(self::DEFAULT_MARKUP_MAX_PERCENT)
            || (strlen($integer) === strlen(self::DEFAULT_MARKUP_MAX_PERCENT) && strcmp($integer, self::DEFAULT_MARKUP_MAX_PERCENT) > 0)
            || ($integer === self::DEFAULT_MARKUP_MAX_PERCENT && '' !== $fraction)
        ) {
            return new WP_Error(
                'digitalogic_import_freight_default_markup_out_of_range',
                sprintf(__('Default markup must be between 0 and %s percent.', 'digitalogic'), self::DEFAULT_MARKUP_MAX_PERCENT),
                array('status' => 400, 'minimum' => '0', 'maximum' => self::DEFAULT_MARKUP_MAX_PERCENT)
            );
        }

        return '' === $fraction ? $integer : $integer . '.' . $fraction;
    }

    public function filter_acf_method_field($field) {
        $methods = $this->list_methods(true);
        if (is_wp_error($methods) || !is_array($field)) {
            return $field;
        }

        $choices = array();
        foreach ($methods as $method) {
            $label = (string) $method['name'];
            if (empty($method['enabled'])) {
                $label .= ' ' . __('(disabled)', 'digitalogic');
            }
            $choices[$this->field_value_for_method($method)] = $label;
        }
        $field['choices'] = $choices;

        return $field;
    }

    public function validate_acf_method_value($valid, $value, $field, $input) {
        if (true !== $valid) {
            return $valid;
        }

        $methods = $this->load_methods();
        $method_id = $this->legacy_value_to_method_id($value, $methods);
        if ($method_id === '' || !isset($methods[$method_id])) {
            return __('Select a valid import freight method.', 'digitalogic');
        }
        if (!empty($methods[$method_id]['enabled'])) {
            return true;
        }

        $post_id = 0;
        if (function_exists('acf_get_form_data')) {
            $post_id = absint(acf_get_form_data('post_id'));
        }
        if (!$post_id && isset($_POST['post_ID'])) {
            $post_id = absint(wp_unslash($_POST['post_ID']));
        }
        $current_row = $post_id
            ? $this->read_post_meta_db($post_id, self::PRODUCT_METHOD_META)
            : array('exists' => false, 'value' => null);
        $current = $current_row['exists'] ? (string) $current_row['value'] : '';

        return $current === $method_id
            ? true
            : __('Disabled import freight methods cannot be assigned to new products.', 'digitalogic');
    }

    private function is_product($post_id) {
        return in_array(get_post_type($post_id), array('product', 'product_variation'), true);
    }

    private function normalize_code($code) {
        return is_string($code) || is_int($code) ? trim((string) $code) : '';
    }

    private function number_or_null($value) {
        if (is_null($value) || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace(array(',', '٬', '،', ' '), '', wp_unslash($value));
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private function nullable_absint($value) {
        if (is_null($value) || $value === '') {
            return null;
        }

        if ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^\d+$/', $value))) {
            return (int) $value;
        }

        return new WP_Error(
            'digitalogic_import_freight_transit_invalid',
            __('Transit days must be non-negative whole numbers.', 'digitalogic'),
            array('status' => 400)
        );
    }

    private function boolean_value($value) {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }

    private function yuan_rate() {
        if (class_exists('Digitalogic_Options')) {
            $value = Digitalogic_Options::instance()->get_yuan_price();
        } else {
            $value = get_option('options_yuan_price', false);
            $value = $value === false ? get_option('yuan_price', null) : $value;
        }

        $value = $this->number_or_null($value);
        return !is_null($value) && $value > 0 ? $value : null;
    }

    private function currency_effective_date() {
        if (class_exists('Digitalogic_Options')) {
            return (string) Digitalogic_Options::instance()->get_update_date();
        }

        return (string) get_option('options_update_date', get_option('update_date', ''));
    }

    private function normalize_effective_date($value) {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $matches)) {
            return null;
        }

        $year = 2000 + (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
