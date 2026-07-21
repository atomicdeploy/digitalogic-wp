<?php
/**
 * Canonical supplier shipping-method catalog and product assignment service.
 *
 * A supplier shipping method describes how Digitalogic obtains inventory. It
 * is intentionally independent from WooCommerce customer delivery methods.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Digitalogic_Product_Identifier_Resolver')) {
    require_once __DIR__ . '/class-product-identifier-resolver.php';
}

final class Digitalogic_Shipping_Method_Service {

	public const METHODS_OPTION = 'digitalogic_shipping_methods';
	public const DEFAULT_MARKUP_OPTION = 'digitalogic_pricing_default_percentage_markup';
	public const PRODUCT_METHOD_META = '_digitalogic_shipping_method_id';
    public const CATALOG_SCHEMA = 'digitalogic.integration-catalog';
    public const FORMULA_ID = 'landed_price';
    public const DEFAULT_MARKUP_SCHEMA = 'digitalogic.default-percentage-markup';

	public const PRICING_ASSIGNMENT_BATCH_SCHEMA = 'digitalogic.pricing-assignment-batch';
	public const MAX_PRICING_ASSIGNMENT_BATCH_SIZE = 500;

    private const MAX_BATCH_SIZE = 500;
	private const CATALOG_LOCK_NAME = 'digitalogic_shipping_method_catalog';
    private const CATALOG_LOCK_TIMEOUT_SECONDS = 10;
    private const DEFAULT_MARKUP_MAX_PERCENT = '1000';
    private const DEFAULT_MARKUP_MAX_SCALE = 12;
	private const METHOD_DECIMAL_MAX_SCALE = 12;
	private const METHOD_DECIMAL_MAX_INTEGER_DIGITS = 18;
	private const CURRENCY_MIGRATION_OPTION = 'digitalogic_shipping_currency_migration_complete';
	private const RETIRED_RATE_STORAGE_KEY = 'shipping_price_per_kg_cny';
	private const RETIRED_MINIMUM_STORAGE_KEY = 'minimum_charge_cny';

    private static $instance = null;
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
		$this->migrate_stored_method_currencies_once();
    }

	/**
	 * Convert the installed method option to the currency-explicit internal
	 * shape once. This is storage maintenance only; request payloads never
	 * accept retired field names.
	 */
	private function migrate_stored_method_currencies_once() {
		$marker = $this->read_option_db(self::CURRENCY_MIGRATION_OPTION);
		if ($marker['exists'] && 'complete' === (string) $marker['value']) {
			return;
		}

		$this->with_catalog_lock(function() {
			return $this->run_transaction(function() {
				$marker = $this->read_option_db(self::CURRENCY_MIGRATION_OPTION, true);
				if ($marker['exists'] && 'complete' === (string) $marker['value']) {
					return array('changed' => false);
				}

				$stored = $this->read_option_db(self::METHODS_OPTION, true);
				if ($stored['exists'] && is_array($stored['value'])) {
					$migrated = $this->migrate_stored_methods($stored['value']);
					if (is_wp_error($migrated)) {
						return $migrated;
					}
					if ($migrated !== $stored['value']) {
						$write = $this->store_option_verified(
							self::METHODS_OPTION,
							$migrated,
							'digitalogic_shipping_currency_migration_failed'
						);
						if (is_wp_error($write)) {
							return $write;
						}
					}
				}

				return $this->store_option_verified(
					self::CURRENCY_MIGRATION_OPTION,
					'complete',
					'digitalogic_shipping_currency_migration_failed'
				);
			});
		});
	}

	/**
	 * Normalize only installed storage. Public management inputs remain strict.
	 *
	 * @param array $methods Stored method map.
	 * @return array|WP_Error
	 */
	private function migrate_stored_methods($methods) {
		foreach ($methods as &$method) {
			if (!is_array($method)) {
				continue;
			}
			if (array_key_exists(self::RETIRED_RATE_STORAGE_KEY, $method)) {
				if (!array_key_exists('price_per_kg', $method)) {
					$method['price_per_kg'] = $method[self::RETIRED_RATE_STORAGE_KEY];
				}
				unset($method[self::RETIRED_RATE_STORAGE_KEY]);
			}
			if (array_key_exists(self::RETIRED_MINIMUM_STORAGE_KEY, $method)) {
				if (!array_key_exists('minimum_charge', $method)) {
					$method['minimum_charge'] = $method[self::RETIRED_MINIMUM_STORAGE_KEY];
				}
				unset($method[self::RETIRED_MINIMUM_STORAGE_KEY]);
			}

			$currency = isset($method['currency']) && is_scalar($method['currency'])
				? strtoupper(trim((string) $method['currency']))
				: '';
			$method['currency'] = in_array($currency, array('CNY', 'IRR'), true) ? $currency : 'CNY';

			foreach (array('price_per_kg', 'minimum_charge', 'volumetric_divisor_cm3_per_kg') as $field) {
				if (!array_key_exists($field, $method) || null === $method[$field]) {
					continue;
				}
				$decimal = $this->canonical_stored_method_decimal($method[$field]);
				if (is_wp_error($decimal)) {
					return $decimal;
				}
				$method[$field] = $decimal;
			}

			if (!isset($method['tiered_rates']) || !is_array($method['tiered_rates'])) {
				continue;
			}
			foreach ($method['tiered_rates'] as &$tier) {
				if (!is_array($tier)) {
					continue;
				}
				if (array_key_exists(self::RETIRED_RATE_STORAGE_KEY, $tier)) {
					if (!array_key_exists('price_per_kg', $tier)) {
						$tier['price_per_kg'] = $tier[self::RETIRED_RATE_STORAGE_KEY];
					}
					unset($tier[self::RETIRED_RATE_STORAGE_KEY]);
				}
				foreach (array('min_weight_kg', 'max_weight_kg', 'price_per_kg') as $field) {
					if (!array_key_exists($field, $tier) || null === $tier[$field]) {
						continue;
					}
					$decimal = $this->canonical_stored_method_decimal($tier[$field]);
					if (is_wp_error($decimal)) {
						return $decimal;
					}
					$tier[$field] = $decimal;
				}
			}
			unset($tier);
		}
		unset($method);

		return $methods;
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
     * Return methods with assignment counts.
     *
     * @param bool $include_disabled Whether disabled methods should be returned.
     * @return array
     */
    public function list_methods($include_disabled = true) {
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
				return $this->store_default_markup_state(!$clear, $state);
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
                    'digitalogic_shipping_default_markup_readback_failed',
                    __('The default percentage markup did not pass exact database readback.', 'digitalogic'),
                    array('status' => 500)
                );
            }

            $result['changed'] = true;
            $result['previous_revision'] = $previous['revision'];
            $delivery_warnings = $this->emit_domain_action(
                'digitalogic_shipping_default_markup_updated',
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
        $id = $this->validate_method_id($id);
        if (is_wp_error($id)) {
            return $id;
        }

        $methods = $this->load_methods();
        if (!isset($methods[$id])) {
            return new WP_Error(
                'digitalogic_shipping_method_not_found',
				__('Shipping method not found.', 'digitalogic'),
                array('status' => 404)
            );
        }

        $method = $methods[$id];
        $method['assigned_products'] = $this->count_assignments($id);

        return $method;
    }

	/**
	 * Present an internal method record using the canonical transport schema.
	 *
	 * @param array $method Stored method record.
	 * @return array
	 */
	public function present_method($method) {
		$presented = array();
		foreach ((array) $method as $key => $value) {
			if (null === $value) {
				continue;
			}
			if (is_array($value)) {
				$value = array_map(function($item) {
					return is_array($item) ? array_filter($item, static fn($part) => null !== $part) : $item;
				}, $value);
			}
			$presented[$key] = $value;
		}

		return $presented;
	}

    /**
     * Create a method with a caller-supplied immutable ID.
     *
     * @param array $data Method data.
     * @return array|WP_Error
     */
    public function create_method($data) {
        $data = is_array($data) ? $data : array();
        $id = $this->validate_method_id(isset($data['id']) ? $data['id'] : '');
        if (is_wp_error($id)) {
            return $id;
        }

        $data['id'] = $id;
        $method = $this->sanitize_method($data);
        if (is_wp_error($method)) {
            return $method;
        }

        return $this->with_catalog_lock(function() use ($id, $method) {
            $methods = $this->load_methods();
            if (isset($methods[$id])) {
                return new WP_Error(
                    'digitalogic_shipping_method_exists',
					__('Shipping method ID already exists.', 'digitalogic'),
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

            $delivery_warnings = $this->emit_domain_action('digitalogic_shipping_method_created', $method);
            $result = $method;
            $result['assigned_products'] = 0;
            if (!empty($delivery_warnings)) {
                $result['delivery_warnings'] = $delivery_warnings;
            }

            return $result;
        });
    }

    /**
     * Update mutable method fields while preserving the ID.
     *
     * @param string $id Method ID.
     * @param array  $changes Changed fields.
     * @return array|WP_Error
     */
    public function update_method($id, $changes) {
        $id = $this->validate_method_id($id);
        if (is_wp_error($id)) {
            return $id;
        }

        $changes = is_array($changes) ? $changes : array();
        if (isset($changes['id']) && (string) $changes['id'] !== $id) {
            return new WP_Error(
                'digitalogic_shipping_method_id_immutable',
				__('Shipping method IDs are immutable.', 'digitalogic'),
                array('status' => 400)
            );
        }

        return $this->with_catalog_lock(function() use ($id, $changes) {
            $methods = $this->load_methods();
            if (!isset($methods[$id])) {
                return new WP_Error(
                    'digitalogic_shipping_method_not_found',
					__('Shipping method not found.', 'digitalogic'),
                    array('status' => 404)
                );
            }

            $candidate = array_merge($methods[$id], $changes, array('id' => $id));
            $method = $this->sanitize_method($candidate, $methods[$id]);
            if (is_wp_error($method)) {
                return $method;
            }

            $changed = $method !== $methods[$id];
            $delivery_warnings = array();
            if ($changed) {
                $methods[$id] = $method;
                $stored = $this->run_transaction(function() use ($methods) {
                    return $this->store_methods($methods);
                });
                if (is_wp_error($stored)) {
                    return $stored;
                }

                $delivery_warnings = $this->emit_domain_action('digitalogic_shipping_method_updated', $method);
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
        $id = $this->validate_method_id($id);
        if (is_wp_error($id)) {
            return $id;
        }

        return $this->with_catalog_lock(function() use ($id) {
            $methods = $this->load_methods();
            if (!isset($methods[$id])) {
                return new WP_Error(
                    'digitalogic_shipping_method_not_found',
					__('Shipping method not found.', 'digitalogic'),
                    array('status' => 404)
                );
            }

            $assigned = $this->count_assignments($id);
            if ($assigned > 0) {
                return new WP_Error(
                    'digitalogic_shipping_method_assigned',
					__('Assigned shipping methods cannot be deleted. Disable the method or reassign its products first.', 'digitalogic'),
                    array('status' => 409, 'assigned_products' => $assigned)
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

            $delivery_warnings = $this->emit_domain_action('digitalogic_shipping_method_deleted', $deleted);
            $result = array('deleted' => true, 'id' => $id);
            if (!empty($delivery_warnings)) {
                $result['delivery_warnings'] = $delivery_warnings;
            }
            return $result;
        });
    }

    /**
     * Resolve an exact Patris Code and return its current assignment.
     *
     * @param string $code Exact Patris Code.
     * @return array|WP_Error
     */
    public function get_product_assignment_by_code($code) {
        $resolved = $this->resolve_shipping_product($code);
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
	 * @param array $codes Exact Patris Codes.
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
			$resolved = $this->resolve_shipping_product( $code );
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
			'requested_count'           => count( $normalized_codes ),
			'resolved_count'            => $resolved_count,
			'error_count'               => count( $normalized_codes ) - $resolved_count,
			'maximum_codes'             => self::MAX_PRICING_ASSIGNMENT_BATCH_SIZE,
			'default_percentage_markup' => $this->present_default_percentage_markup($default_markup),
			'results'                   => $results,
		);
	}

    /**
     * Assign or clear a method through deterministic Patris Code resolution.
     *
     * @param string      $code Patris Code or exact SKU.
     * @param string|null $method_id Method ID; empty clears the assignment.
     * @return array|WP_Error
     */
    public function assign_product_by_code($code, $method_id) {
        return $this->with_catalog_lock(function() use ($code, $method_id) {
            $resolved = $this->resolve_shipping_product($code);
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
                    'digitalogic_product_shipping_method_updated',
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
        if (!is_array($assignments) || empty($assignments)) {
            return new WP_Error(
                'digitalogic_shipping_empty_batch',
                __('At least one product assignment is required.', 'digitalogic'),
                array('status' => 400)
            );
        }

        if (count($assignments) > self::MAX_BATCH_SIZE) {
            return new WP_Error(
                'digitalogic_shipping_batch_too_large',
				__('Shipping-method assignment batches are limited to 500 rows.', 'digitalogic'),
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
				if (array_key_exists('shipping_method_id', $assignment)) {
					$method_id = $assignment['shipping_method_id'];
                } else {
                    $errors[$index] = array(
						'code' => 'digitalogic_shipping_method_required',
						'message' => __('The shipping_method_id field is required; use null or an empty value to clear it explicitly.', 'digitalogic'),
                    );
                    continue;
                }

                if ($code === '') {
					$errors[$index] = array('code' => 'digitalogic_invalid_product_code', 'message' => __('Patris Code is required.', 'digitalogic'));
                    continue;
                }

                if (isset($seen_codes[$code])) {
					$errors[$index] = array('code' => 'digitalogic_duplicate_product_code', 'message' => __('Duplicate Patris Code in assignment batch.', 'digitalogic'));
                    continue;
                }
                $seen_codes[$code] = true;

                $resolved = $this->resolve_shipping_product($code);
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
                    'digitalogic_shipping_batch_invalid',
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
                        'digitalogic_product_shipping_method_updated',
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
		$catalog_methods = array();
		foreach ($methods as $method) {
			$catalog_method = array(
				'id' => (string) $method['id'],
				'name' => (string) $method['name'],
				'enabled' => !empty($method['enabled']),
				'currency' => (string) $method['currency'],
			);
			foreach (array('price_per_kg', 'minimum_charge', 'volumetric_divisor_cm3_per_kg') as $field) {
				if (array_key_exists($field, $method) && null !== $method[$field]) {
					$catalog_method[$field] = (string) $method[$field];
				}
			}
			if (!empty($method['tiered_rates']) && is_array($method['tiered_rates'])) {
				$catalog_method['tiered_rates'] = array_values(array_map(function($tier) {
					$presented = array();
					foreach (array('min_weight_kg', 'max_weight_kg', 'price_per_kg') as $field) {
						if (is_array($tier) && array_key_exists($field, $tier) && null !== $tier[$field]) {
							$presented[$field] = (string) $tier[$field];
						}
					}
					return $presented;
				}, $method['tiered_rates']));
			}
			$catalog_methods[] = $catalog_method;
		}

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
        $currency = array(
            'local' => $local_currency,
            'warnings' => $currency_warnings,
        );
        if (!is_null($yuan_rate)) {
            $currency['cny_to_local'] = $yuan_rate;
            if ('IRT' === $local_currency) {
                $currency['cny_to_irt'] = $yuan_rate;
            }
        }
        $effective_date = $this->normalize_effective_date($source_effective_date);
        if (!is_null($effective_date)) {
            $currency['effective_date'] = $effective_date;
        }

        $identity = array(
            'schema' => self::CATALOG_SCHEMA,
            'currency' => $currency,
            'pricing' => array(
                'formula_id' => self::FORMULA_ID,
            ),
            'selected_warehouses' => $warehouses,
			'shipping_methods' => $catalog_methods,
        );

        $revision = 'sha256:' . hash('sha256', wp_json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return array(
            'schema' => self::CATALOG_SCHEMA,
            'revision' => $revision,
            'currency' => $currency,
            'pricing' => $identity['pricing'],
            'selected_warehouses' => $warehouses,
            'shipping_methods' => $catalog_methods,
        );
    }

    private function default_methods() {
        $definitions = array(
            'air_express' => array(
				'name' => 'Air (Express)',
                'fallback_rate' => 85,
            ),
            'air_freight' => array(
				'name' => 'Air',
                'fallback_rate' => 80,
            ),
            'sea_freight' => array(
				'name' => 'Sea/Ocean',
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
                'price_per_kg' => $definition['fallback_rate'],
                'minimum_charge' => null,
                'billable_weight_rule' => 'actual',
                'volumetric_divisor_cm3_per_kg' => null,
                'transit_days_min' => null,
                'transit_days_max' => null,
                'metadata' => array('source' => 'built_in_default'),
                'tiered_rates' => array(),
            ));
        }

        return $methods;
    }

    private function sanitize_method($data, $existing = null) {
        $allowed = array(
            'id',
            'name',
            'enabled',
            'currency',
            'price_per_kg',
            'minimum_charge',
            'billable_weight_rule',
            'volumetric_divisor_cm3_per_kg',
            'transit_days_min',
            'transit_days_max',
            'metadata',
            'tiered_rates',
        );
        $unknown = array_values(array_diff(array_keys((array) $data), $allowed));
        if (!empty($unknown)) {
            return new WP_Error(
                'digitalogic_shipping_method_unknown_field',
                __('The shipping method contains unsupported fields.', 'digitalogic'),
                array('status' => 422, 'fields' => $unknown)
            );
        }
        $id = $this->validate_method_id(isset($data['id']) ? $data['id'] : '');
        if (is_wp_error($id)) {
            return $id;
        }

        $name = sanitize_text_field(isset($data['name']) ? wp_unslash($data['name']) : '');
        if ($name === '') {
			return new WP_Error('digitalogic_shipping_name_required', __('Shipping method name is required.', 'digitalogic'), array('status' => 400));
        }

        if (!array_key_exists('currency', $data) || !is_string($data['currency'])) {
            return new WP_Error('digitalogic_shipping_currency_required', __('Shipping price currency is required.', 'digitalogic'), array('status' => 400));
        }
        $currency = $data['currency'];
        if (!in_array($currency, array('CNY', 'IRR'), true)) {
			return new WP_Error('digitalogic_shipping_currency_unsupported', __('Shipping price currency must be CNY or IRR.', 'digitalogic'), array('status' => 400));
        }

        $price = $this->canonical_method_decimal(
            array_key_exists('price_per_kg', $data) ? $data['price_per_kg'] : null,
            false,
            'digitalogic_shipping_rate_invalid',
            __('A non-negative shipping price per kilogram is required.', 'digitalogic')
        );
        if (is_wp_error($price)) {
            return $price;
        }

        $minimum = $this->canonical_method_decimal(
            array_key_exists('minimum_charge', $data) ? $data['minimum_charge'] : null,
            true,
            'digitalogic_shipping_minimum_invalid',
            __('Minimum charge must be a non-negative decimal.', 'digitalogic')
        );
        if (is_wp_error($minimum)) {
            return $minimum;
        }

        $billable_rule = sanitize_key(isset($data['billable_weight_rule']) ? $data['billable_weight_rule'] : 'actual');
        if (!in_array($billable_rule, array('actual', 'volumetric', 'greater_of'), true)) {
            return new WP_Error('digitalogic_shipping_billable_weight_invalid', __('Unknown billable-weight rule.', 'digitalogic'), array('status' => 400));
        }

        $divisor = $this->canonical_method_decimal(
            array_key_exists('volumetric_divisor_cm3_per_kg', $data) ? $data['volumetric_divisor_cm3_per_kg'] : null,
            true,
            'digitalogic_shipping_divisor_invalid',
            __('Volumetric divisor must be a positive decimal.', 'digitalogic')
        );
        if (is_wp_error($divisor)) {
            return $divisor;
        }
        if (!is_null($divisor) && '0' === $divisor) {
            return new WP_Error('digitalogic_shipping_divisor_invalid', __('Volumetric divisor must be greater than zero.', 'digitalogic'), array('status' => 400));
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
            return new WP_Error('digitalogic_shipping_transit_invalid', __('Maximum transit days cannot be lower than minimum transit days.', 'digitalogic'), array('status' => 400));
        }

        $tiered_rates = $this->sanitize_tiered_rates(isset($data['tiered_rates']) ? $data['tiered_rates'] : array());
        if (is_wp_error($tiered_rates)) {
            return $tiered_rates;
        }

        return array(
            'id' => $id,
            'name' => $name,
            'enabled' => !isset($data['enabled']) || $this->boolean_value($data['enabled']),
            'currency' => $currency,
            'price_per_kg' => $price,
            'minimum_charge' => $minimum,
            'billable_weight_rule' => $billable_rule,
            'volumetric_divisor_cm3_per_kg' => $divisor,
            'transit_days_min' => $transit_min,
            'transit_days_max' => $transit_max,
            'metadata' => $this->sanitize_metadata(isset($data['metadata']) ? $data['metadata'] : array()),
            'tiered_rates' => $tiered_rates,
        );
    }

    private function sanitize_tiered_rates($tiers) {
        $clean = array();
        foreach ((array) $tiers as $index => $tier) {
            if (!is_array($tier)) {
                return new WP_Error(
                    'digitalogic_shipping_tier_invalid',
                    __('Every tiered rate must be an object.', 'digitalogic'),
                    array('status' => 400, 'tier_index' => $index)
                );
            }
            $unknown = array_values(array_diff(
                array_keys($tier),
                array('min_weight_kg', 'max_weight_kg', 'price_per_kg')
            ));
            if (!empty($unknown)) {
                return new WP_Error(
                    'digitalogic_shipping_tier_unknown_field',
                    __('A tiered rate contains unsupported fields.', 'digitalogic'),
                    array('status' => 422, 'tier_index' => $index, 'fields' => $unknown)
                );
            }

            $min = $this->canonical_method_decimal(
                array_key_exists('min_weight_kg', $tier) ? $tier['min_weight_kg'] : null,
                false,
                'digitalogic_shipping_tier_invalid',
                __('Tiered rates require valid non-negative bounds and rates.', 'digitalogic')
            );
            $max = $this->canonical_method_decimal(
                array_key_exists('max_weight_kg', $tier) ? $tier['max_weight_kg'] : null,
                true,
                'digitalogic_shipping_tier_invalid',
                __('Tiered rates require valid non-negative bounds and rates.', 'digitalogic')
            );
            $rate = $this->canonical_method_decimal(
                array_key_exists('price_per_kg', $tier) ? $tier['price_per_kg'] : null,
                false,
                'digitalogic_shipping_tier_invalid',
                __('Tiered rates require valid non-negative bounds and rates.', 'digitalogic')
            );
            if (
                is_wp_error($min)
                || is_wp_error($max)
                || is_wp_error($rate)
                || (!is_null($max) && $this->compare_method_decimals($max, $min) < 0)
            ) {
                return new WP_Error(
                    'digitalogic_shipping_tier_invalid',
                    __('Tiered rates require valid non-negative bounds and rates.', 'digitalogic'),
                    array('status' => 400, 'tier_index' => $index)
                );
            }

            $clean[] = array(
                'min_weight_kg' => $min,
                'max_weight_kg' => $max,
                'price_per_kg' => $rate,
            );
        }

        usort($clean, function($left, $right) {
            return $this->compare_method_decimals($left['min_weight_kg'], $right['min_weight_kg']);
        });

        $previous_max = null;
        foreach ($clean as $index => $tier) {
            if (
                $index > 0
                && (is_null($previous_max) || $this->compare_method_decimals($tier['min_weight_kg'], $previous_max) <= 0)
            ) {
                return new WP_Error(
                    'digitalogic_shipping_tiers_overlap',
					__('Tiered shipping-price ranges cannot overlap.', 'digitalogic'),
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
                'digitalogic_shipping_method_id_invalid',
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
			return new WP_Error('digitalogic_shipping_method_not_found', __('Shipping method not found.', 'digitalogic'), array('status' => 404));
        }
        if (empty($methods[$method_id]['enabled'])) {
            $current = $product_id
				? $this->read_product_method_meta($product_id)
                : array('exists' => false, 'value' => null);
            $current_method_id = $current['exists'] ? (string) $current['value'] : '';
            if ($current_method_id === $method_id) {
                return $methods[$method_id];
            }

			return new WP_Error('digitalogic_shipping_method_disabled', __('Disabled shipping methods cannot be assigned to new products.', 'digitalogic'), array('status' => 409));
        }

        return $methods[$method_id];
    }

    private function resolve_shipping_product($code) {
        $resolved = Digitalogic_Product_Identifier_Resolver::instance()->resolve(array('patris_code' => $code));
        if (is_wp_error($resolved)) {
            $error_code = $resolved->get_error_code();
            $data = is_array($resolved->get_error_data()) ? $resolved->get_error_data() : array();
            if ('digitalogic_invalid_product_identifier' === $error_code) {
                return new WP_Error('digitalogic_invalid_product_code', __('Patris Code is required.', 'digitalogic'), array('status' => 400));
            }
            if ('digitalogic_product_identifier_not_found' === $error_code) {
                return new WP_Error('digitalogic_product_code_not_found', __('No product has that exact Patris Code.', 'digitalogic'), array('status' => 404));
            }
            if ('digitalogic_product_identifier_ambiguous' === $error_code) {
                return new WP_Error(
                    'digitalogic_product_code_ambiguous',
                    __('More than one product has that exact Patris Code; no assignment was changed.', 'digitalogic'),
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
        $write = $this->store_post_meta_verified(
            $product_id,
            self::PRODUCT_METHOD_META,
            $desired_method_id
        );
        if (is_wp_error($write)) {
            return $write;
        }

        return array('changed' => !empty($write['changed']), 'product_id' => (int) $product_id);
    }

    private function build_assignment_response($resolved, $default_markup = null) {
		$method_row = $this->read_product_method_meta($resolved['product_id']);
        $method_id = $method_row['exists'] ? (string) $method_row['value'] : '';
        $methods = $this->load_methods();
        if (!is_array($default_markup)) {
            $default_markup = $this->load_default_percentage_markup();
        }
        $markup = $this->build_markup_contract($resolved['product_id'], $default_markup);
		$stored_method = $method_id !== '' && isset($methods[$method_id]) ? $methods[$method_id] : null;
		$shipping_method = is_array($stored_method) ? $this->present_method($stored_method) : null;

		$response = array_merge($resolved, array(
			'shipping_method_id' => $method_id,
			'shipping_method' => $shipping_method,
            'markup' => $markup,
            'profit_percent' => $markup['profit_percent'],
            'profit_percent_source' => $markup['source'],
            'pricing_warnings' => $markup['warning'] ? array($markup['warning']) : array(),
        ));
		if (is_array($stored_method)) {
			$response['shipping_price_per_kg'] = $stored_method['price_per_kg'];
			$response['shipping_price_per_kg_currency'] = $stored_method['currency'];
		}

		return $response;
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
		$method_row = $this->read_product_method_meta( $resolved['product_id'] );
		$markup     = $this->build_exact_markup_contract( $resolved['product_id'], $default_markup );

		$projection = array(
			'profit_percent_source' => null === $markup['source'] ? 'unavailable' : (string) $markup['source'],
			'pricing_warnings'      => $markup['warning'] ? array( $markup['warning'] ) : array(),
		);
		if ($method_row['exists'] && '' !== (string) $method_row['value']) {
			$projection['shipping_method_id'] = (string) $method_row['value'];
		}
		if (null !== $markup['profit_percent']) {
			$projection['profit_percent'] = $markup['profit_percent'];
		}

		return $projection;
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

        return count(array_unique(array_map('absint', (array) $ids)));
    }

    private function load_methods() {
        $stored = $this->read_option_db(self::METHODS_OPTION);
        $methods = $stored['exists'] ? $stored['value'] : $this->default_methods();
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

    // phpcs:disable -- Keep the established service formatting in this file.
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
        $defaults = $this->default_methods();

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
			'digitalogic_shipping_catalog_write_failed'
        );
	}

	private function store_default_markup_state($desired_exists, $state) {
		return $this->store_option_state_verified(
			self::DEFAULT_MARKUP_OPTION,
			$desired_exists,
			$state,
			'digitalogic_shipping_default_markup_write_failed'
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
     * such as integer markers and floating-point rates.
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

	private function read_product_method_meta($post_id, $for_update = false) {
		return $this->read_post_meta_db($post_id, self::PRODUCT_METHOD_META, $for_update);
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
                'digitalogic_shipping_unexpected_write_failure',
				__('The shipping-method change could not be completed.', 'digitalogic'),
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
                'digitalogic_shipping_lock_unavailable',
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
                'digitalogic_shipping_catalog_busy',
				__('Another shipping-method update is already running. Please retry.', 'digitalogic'),
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
                'digitalogic_shipping_transaction_exception',
				__('The shipping-method transaction was rolled back.', 'digitalogic'),
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
                'digitalogic_shipping_nested_transaction',
				__('Nested shipping-method transactions are not supported.', 'digitalogic'),
                array('status' => 500)
            );
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'query') || false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error(
                'digitalogic_shipping_transaction_unavailable',
				__('The database could not start a shipping-method transaction.', 'digitalogic'),
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
                'digitalogic_shipping_commit_failed',
				__('The shipping-method transaction could not be committed.', 'digitalogic'),
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
                'digitalogic_shipping_rollback_failed',
				__('The database could not roll back the failed shipping-method change.', 'digitalogic'),
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
    }

    private function safe_do_action($hook, ...$args) {
        try {
            do_action($hook, ...$args);
            return true;
        } catch (Throwable $exception) {
            error_log(sprintf(
				'[Digitalogic shipping method] Listener for %s failed after commit: %s',
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
					'[Digitalogic shipping method] Delivery channel %s failed after commit: %s',
                    $channel,
                    $exception->getMessage()
                ));
                $warnings[] = 'event_delivery_failed:' . $channel . ':exception';
            }
        }

        // Publish the public domain action independently from result-aware channels.
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
                'digitalogic_shipping_transaction_required',
				__('Shipping-method storage writes require an active transaction.', 'digitalogic'),
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
				__('The shipping-method option could not be saved.', 'digitalogic'),
                array('status' => 500, 'option' => $name)
            );
        }
        $stored = $this->read_option_db($name, true);
        if (!$this->option_row_matches($stored, $desired_exists, $value)) {
            return new WP_Error(
                $error_code,
				__('The shipping-method option could not be saved.', 'digitalogic'),
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
                'digitalogic_shipping_transaction_required',
				__('Shipping-method storage writes require an active transaction.', 'digitalogic'),
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
                'digitalogic_shipping_meta_write_failed',
				__('The product shipping-method assignment could not be saved.', 'digitalogic'),
                array('status' => 500, 'product_id' => (int) $post_id, 'meta_key' => $key)
            );
        }
        $stored = $this->read_post_meta_db($post_id, $key, true);
        if (($desired_exists && (!$stored['exists'] || (string) $stored['value'] !== $value)) || (!$desired_exists && $stored['exists'])) {
            return new WP_Error(
                'digitalogic_shipping_meta_write_failed',
				__('The product shipping-method assignment could not be saved.', 'digitalogic'),
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
            $warning = 'fixed_markup_not_supported_by_landed_price';
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
            || !isset($state['schema'], $state['type'], $state['source'], $state['revision'])
            || self::DEFAULT_MARKUP_SCHEMA !== $state['schema']
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

    private function present_default_percentage_markup($contract) {
        $presented = array(
            'schema' => self::DEFAULT_MARKUP_SCHEMA,
            'configured' => !empty($contract['configured']),
            'type' => 'percentage',
            'source' => (string) ($contract['source'] ?? 'unset'),
            'revision' => (string) ($contract['revision'] ?? ''),
        );
        if (!empty($contract['configured']) && array_key_exists('profit_percent', $contract)) {
            $presented['profit_percent'] = $contract['profit_percent'];
        }

        return $presented;
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
        $identity = array(
            'schema' => self::DEFAULT_MARKUP_SCHEMA,
            'configured' => (bool) $configured,
            'type' => 'percentage',
            'source' => $source,
        );
        if ($configured) {
            $identity['profit_percent'] = $value;
        }

        return $identity;
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
                'digitalogic_shipping_default_markup_invalid',
                __('Default markup must be a finite base-10 percentage.', 'digitalogic'),
                array('status' => 400)
            );
        }
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return new WP_Error(
                    'digitalogic_shipping_default_markup_invalid',
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
                'digitalogic_shipping_default_markup_invalid',
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
                'digitalogic_shipping_default_markup_scale_invalid',
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
                'digitalogic_shipping_default_markup_out_of_range',
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
            $choices[(string) $method['id']] = $label;
        }
        $field['choices'] = $choices;

        return $field;
    }

    public function validate_acf_method_value($valid, $value, $field, $input) {
        if (true !== $valid) {
            return $valid;
        }

        $methods = $this->load_methods();
        $method_id = sanitize_key((string) $value);
        if ($method_id === '' || !isset($methods[$method_id])) {
			return __('Select a valid shipping method.', 'digitalogic');
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
			? $this->read_product_method_meta($post_id)
            : array('exists' => false, 'value' => null);
        $current = $current_row['exists'] ? (string) $current_row['value'] : '';

        return $current === $method_id
            ? true
			: __('Disabled shipping methods cannot be assigned to new products.', 'digitalogic');
    }

    private function normalize_code($code) {
        return is_string($code) || is_int($code) ? trim((string) $code) : '';
    }

	/**
	 * Canonicalize one non-negative method decimal without binary-float output.
	 *
	 * Callers should send decimal strings when fractional precision matters.
	 * Integers are lossless; finite floats are accepted only when PHP can expose
	 * them directly as ordinary base-10 text within the same bounded shape.
	 */
	private function canonical_method_decimal($value, $nullable, $error_code, $message) {
		if (null === $value || '' === $value) {
			return $nullable
				? null
				: new WP_Error($error_code, $message, array('status' => 400));
		}
		if (is_bool($value) || is_array($value) || is_object($value)) {
			return new WP_Error($error_code, $message, array('status' => 400));
		}

		if (is_int($value)) {
			$text = (string) $value;
		} elseif (is_float($value)) {
			if (!is_finite($value)) {
				return new WP_Error($error_code, $message, array('status' => 400));
			}
			$text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
			$text = is_string($text) ? $text : '';
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
				$error_code,
				$message,
				array(
					'status' => 400,
					'maximum_fraction_digits' => self::METHOD_DECIMAL_MAX_SCALE,
				)
			);
		}

		$negative = str_starts_with($text, '-');
		$text = ltrim($text, '+-');
		$parts = explode('.', $text, 2);
		$integer = ltrim($parts[0], '0');
		$integer = '' === $integer ? '0' : $integer;
		$fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
		$is_zero = '0' === $integer && '' === $fraction;
		if (
			($negative && !$is_zero)
			|| strlen($integer) > self::METHOD_DECIMAL_MAX_INTEGER_DIGITS
			|| strlen($fraction) > self::METHOD_DECIMAL_MAX_SCALE
		) {
			return new WP_Error(
				$error_code,
				$message,
				array(
					'status' => 400,
					'maximum_integer_digits' => self::METHOD_DECIMAL_MAX_INTEGER_DIGITS,
					'maximum_fraction_digits' => self::METHOD_DECIMAL_MAX_SCALE,
				)
			);
		}

		return '' === $fraction ? $integer : $integer . '.' . $fraction;
	}

	/**
	 * Bring already-installed numeric values into the canonical storage shape.
	 */
	private function canonical_stored_method_decimal($value) {
		if (is_float($value) && is_finite($value)) {
			$text = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
			if (!is_string($text) || false !== stripos($text, 'e')) {
				$text = sprintf('%.' . self::METHOD_DECIMAL_MAX_SCALE . 'F', $value);
			}
			$value = $text;
		}

		return $this->canonical_method_decimal(
			$value,
			false,
			'digitalogic_shipping_currency_migration_failed',
			__('An installed shipping-method decimal could not be migrated safely.', 'digitalogic')
		);
	}

	/**
	 * Compare two canonical non-negative decimal strings without float casts.
	 */
	private function compare_method_decimals($left, $right) {
		$left_parts = explode('.', (string) $left, 2);
		$right_parts = explode('.', (string) $right, 2);
		if (strlen($left_parts[0]) !== strlen($right_parts[0])) {
			return strlen($left_parts[0]) <=> strlen($right_parts[0]);
		}
		$integer_comparison = strcmp($left_parts[0], $right_parts[0]);
		if (0 !== $integer_comparison) {
			return $integer_comparison < 0 ? -1 : 1;
		}

		$scale = max(strlen($left_parts[1] ?? ''), strlen($right_parts[1] ?? ''));
		$left_fraction = str_pad($left_parts[1] ?? '', $scale, '0');
		$right_fraction = str_pad($right_parts[1] ?? '', $scale, '0');
		$fraction_comparison = strcmp($left_fraction, $right_fraction);

		return 0 === $fraction_comparison ? 0 : ($fraction_comparison < 0 ? -1 : 1);
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
            'digitalogic_shipping_transit_invalid',
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
