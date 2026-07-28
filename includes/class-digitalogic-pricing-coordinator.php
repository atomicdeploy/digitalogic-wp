<?php
/**
 * Atomic coordinator for Digitalogic global and per-product pricing inputs.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes every supported price input through the exact Patris repricer.
 */
final class Digitalogic_Pricing_Coordinator {

	/**
	 * Shared service.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Re-entrant legacy option interception depth.
	 *
	 * @var int
	 */
	private $legacy_option_write_depth = 0;

	/**
	 * Register fail-closed guards for legacy and third-party rate writes.
	 */
	private function __construct() {
		foreach (
			array(
				'dollar_price',
				'options_dollar_price',
				'yuan_price',
				'options_yuan_price',
				'update_date',
				'options_update_date',
			) as $option_name
		) {
			add_filter(
				'pre_update_option_' . $option_name,
				array( $this, 'intercept_legacy_currency_option_write' ),
				10,
				3
			);
		}
		add_filter(
			'pre_update_option_' . Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION,
			array( $this, 'intercept_legacy_rounding_option_write' ),
			10,
			3
		);
	}

	/**
	 * Return the shared coordinator.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Apply one partial currency change and reprice before committing.
	 *
	 * @param array  $values Currency rates and optional legacy/independent dates.
	 * @param string $source Bounded internal source label.
	 * @return array|WP_Error
	 */
	public function update_currency( $values, $source = 'wp' ) {
		if ( ! is_array( $values ) || array_is_list( $values ) ) {
			return $this->error(
				'digitalogic_pricing_currency_payload_invalid',
				'مقادیر نرخ ارز معتبر نیست.',
				400
			);
		}
		$allowed = array(
			'dollar_price',
			'yuan_price',
			'effective_date',
			'usd_effective_date',
			'cny_effective_date',
		);
		$unknown = array_diff( array_keys( $values ), $allowed );
		if (
			$unknown
			|| (
				! array_intersect( array_keys( $values ), $allowed )
			)
		) {
			return $this->error(
				'digitalogic_pricing_currency_fields_invalid',
				'حداقل یکی از نرخ‌های دلار، یوآن یا تاریخ مؤثر باید ارسال شود.',
				400,
				array( 'fields' => array_values( $unknown ) )
			);
		}

		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$current = $settings;
		foreach ( array( 'dollar_price', 'yuan_price' ) as $field ) {
			if ( array_key_exists( $field, $values ) ) {
				$settings[ $field ] = $values[ $field ];
			}
		}

		if ( array_key_exists( 'effective_date', $values ) ) {
			$targets_usd = array_key_exists( 'dollar_price', $values );
			$targets_cny = array_key_exists( 'yuan_price', $values );
			if ( ! $targets_usd && ! $targets_cny ) {
				$targets_cny = true;
			}
			if ( $targets_usd ) {
				$settings['usd_effective_date'] = $values['effective_date'];
			}
			if ( $targets_cny ) {
				$settings['cny_effective_date'] = $values['effective_date'];
				$settings['effective_date']     = $values['effective_date'];
			}
		}
		if ( array_key_exists( 'usd_effective_date', $values ) ) {
			$settings['usd_effective_date'] = $values['usd_effective_date'];
		}
		if ( array_key_exists( 'cny_effective_date', $values ) ) {
			$settings['cny_effective_date'] = $values['cny_effective_date'];
			if ( ! array_key_exists( 'effective_date', $values ) ) {
				$settings['effective_date'] = $values['cny_effective_date'];
			}
		}

		$has_any_date = array_key_exists( 'effective_date', $values )
			|| array_key_exists( 'usd_effective_date', $values )
			|| array_key_exists( 'cny_effective_date', $values );
		if (
			! $has_any_date
			&& (
				array_key_exists( 'dollar_price', $values )
				|| array_key_exists( 'yuan_price', $values )
			)
		) {
			$today = gmdate( 'Y-m-d' );
			if (
				array_key_exists( 'dollar_price', $values )
				&& $this->rate_value_changed( $current['dollar_price'], $values['dollar_price'] )
			) {
				$settings['usd_effective_date'] = $today;
			}
			if (
				array_key_exists( 'yuan_price', $values )
				&& $this->rate_value_changed( $current['yuan_price'], $values['yuan_price'] )
			) {
				$settings['cny_effective_date'] = $today;
				$settings['effective_date']     = $today;
			}
		}

		return Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings(
			$settings,
			$this->source_label( $source )
		);
	}

	/**
	 * Apply the canonical air-express rate and reprice before committing.
	 *
	 * @param mixed  $price_per_kg Exact non-negative freight rate.
	 * @param mixed  $currency     CNY or IRR.
	 * @param string $source       Bounded internal source label.
	 * @return array|WP_Error
	 */
	public function update_air_express_shipping( $price_per_kg, $currency, $source = 'wp' ) {
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings['air_express_price_per_kg'] = $price_per_kg;
		$settings['air_express_currency']     = $currency;

		return Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings(
			$settings,
			$this->source_label( $source )
		);
	}

	/**
	 * Apply the one shared profit margin and reprice before committing.
	 *
	 * Clearing the default is deliberately rejected while canonical prices
	 * depend on it; an absent percentage would otherwise remove final prices.
	 *
	 * @param mixed  $value  Exact percentage.
	 * @param string $source Bounded internal source label.
	 * @return array|WP_Error
	 */
	public function update_profit_margin( $value, $source = 'wp' ) {
		if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
			return $this->error(
				'digitalogic_pricing_profit_margin_required',
				'حاشیه سود مشترک اکوسیستم را نمی‌توان خالی کرد.',
				409
			);
		}

		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings( $value );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings['profit_margin_percent'] = $value;

		return Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings(
			$settings,
			$this->source_label( $source )
		);
	}

	/**
	 * Apply the one shared final-price rounding policy and reprice atomically.
	 *
	 * @param mixed  $digits Trailing IRT digit count from zero through nine.
	 * @param string $source Bounded internal source label.
	 * @return array|WP_Error
	 */
	public function update_price_rounding( $digits, $source = 'wp' ) {
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$settings['price_rounding_digits'] = $digits;
		$settings['price_rounding_mode']   = Digitalogic_Shipping_Method_Service::ROUNDING_MODE;

		return Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings(
			$settings,
			$this->source_label( $source )
		);
	}

	/**
	 * Backward-compatible name retained for deployed integrations.
	 *
	 * @deprecated Use update_profit_margin().
	 *
	 * @param mixed  $value  Exact percentage.
	 * @param string $source Bounded internal actor label.
	 * @return array|WP_Error
	 */
	public function update_default_profit( $value, $source = 'wp' ) {
		return $this->update_profit_margin( $value, $source );
	}

	/**
	 * Reconcile current canonical settings without changing them.
	 *
	 * @param string $source Bounded internal source label.
	 * @return array|WP_Error
	 */
	public function reconcile_current( $source = 'wp_reconcile' ) {
		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		return Digitalogic_Excel_Pricing_Sync::instance()->apply_internal_settings(
			$settings,
			$this->source_label( $source )
		);
	}

	/**
	 * Reprice all stored products inside an already-open DB transaction.
	 *
	 * @param array       $settings                  Complete canonical settings.
	 * @param string|null $previous_catalog_revision Catalog revision before the atomic write.
	 * @return array|WP_Error
	 */
	public function reprice_open_transaction( $settings, $previous_catalog_revision = null ) {
		return Digitalogic_Product_Sync_Receiver::instance()->reprice_pricing_state(
			$this->receiver_settings( $settings ),
			array(),
			array(),
			$previous_catalog_revision
		);
	}

	/**
	 * Reject the retired per-product profit path.
	 *
	 * @param string      $product_code  Exact Product Code.
	 * @param string|null $profit_percent Percentage override, or null for default.
	 * @return array|WP_Error
	 */
	public function reprice_product_open_transaction( $product_code, $profit_percent ) {
		unset( $profit_percent );

		return $this->error(
			'digitalogic_pricing_product_profit_forbidden',
			'حاشیه سود یک مقدار مشترک است و برای یک کالای جداگانه قابل تغییر نیست.',
			409,
			array( 'product_code' => (string) $product_code )
		);
	}

	/**
	 * Project additive canonical settings onto the receiver's strict v1 shape.
	 *
	 * @param array $settings Canonical pricing settings.
	 * @return array
	 */
	private function receiver_settings( $settings ) {
		return array(
			'dollar_price'          => $settings['dollar_price'],
			'yuan_price'            => $settings['yuan_price'],
			'effective_date'        => $settings['cny_effective_date'] ?? $settings['effective_date'],
			'profit_margin_percent' => $settings['profit_margin_percent'],
			'price_rounding_digits' => $settings['price_rounding_digits'],
			'price_rounding_mode'   => $settings['price_rounding_mode'],
		);
	}

	/**
	 * Compare one submitted rate after the same integer normalization as sync.
	 *
	 * Invalid input is treated as changed; canonical validation will reject it
	 * before anything is stored.
	 *
	 * @param mixed $current  Current canonical rate.
	 * @param mixed $proposed Submitted rate.
	 * @return bool
	 */
	private function rate_value_changed( $current, $proposed ) {
		if ( is_int( $proposed ) ) {
			$text = (string) $proposed;
		} elseif ( is_float( $proposed ) && is_finite( $proposed ) && floor( $proposed ) === $proposed ) {
			$text = sprintf( '%.0F', $proposed );
		} elseif ( is_string( $proposed ) ) {
			$text = trim( $proposed );
		} else {
			return true;
		}
		if ( 1 !== preg_match( '/\A[0-9]{1,10}\z/D', $text ) ) {
			return true;
		}

		return (int) ltrim( $text, '0' ) !== (int) $current;
	}

	/**
	 * Keep the product-sync lock held until a caller commits or rolls back.
	 *
	 * @param callable $callback Transaction owner.
	 * @return mixed|WP_Error
	 */
	public function with_repricing_lock( $callback ) {
		return Digitalogic_Product_Sync_Receiver::instance()->with_coordinated_pricing_lock( $callback );
	}

	/**
	 * Clear caches after the surrounding transaction reaches a terminal state.
	 *
	 * @return void
	 */
	public function flush_repricing_caches() {
		Digitalogic_Product_Sync_Receiver::instance()->flush_coordinated_pricing_caches();
	}

	/**
	 * Publish a successfully committed reconciliation.
	 *
	 * @param array $result Reconciliation result.
	 * @return void
	 */
	public function publish_repricing_result( $result ) {
		Digitalogic_Product_Sync_Receiver::instance()->publish_coordinated_pricing_result( $result );
	}

	/**
	 * Whether the transformed-only Patris receiver owns a product's price.
	 *
	 * @param WC_Product|int $product Product or ID.
	 * @return bool
	 */
	public function is_managed_product( $product ) {
		return Digitalogic_Patris_Price_Write_Guard::instance()->is_managed_product( $product );
	}

	/**
	 * Whether a transformed Patris catalog currently owns any pricing rows.
	 *
	 * Before the first source snapshot, installation and migration code may
	 * populate defaults normally. Once a catalog exists, every public rate or
	 * profit-margin mutation must pass through this coordinator.
	 *
	 * @return bool
	 */
	public function has_managed_pricing_state() {
		$state   = Digitalogic_Product_Sync_Receiver::instance()->get_state();
		$sources = is_array( $state['sources'] ?? null ) ? $state['sources'] : array();
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$products = is_array( $source['products'] ?? null ) ? $source['products'] : array();
			if ( $products ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Route raw update_option()/ACF currency aliases through atomic repricing.
	 *
	 * The coordinator stores both aliases by verified SQL inside its own
	 * transaction. Returning the canonical persisted value lets the outer
	 * WordPress update finish as a harmless idempotent write. On failure the
	 * old value is returned, so the uncoordinated mutation is rejected.
	 *
	 * @param mixed  $value     Proposed option value.
	 * @param mixed  $old_value Previous option value.
	 * @param string $option    Exact option name.
	 * @return mixed
	 */
	public function intercept_legacy_currency_option_write( $value, $old_value, $option ) {
		if (
			$this->legacy_option_write_depth > 0
			|| ! $this->has_managed_pricing_state()
			|| (string) $value === (string) $old_value
		) {
			return $value;
		}

		$option = (string) $option;
		if ( in_array( $option, array( 'dollar_price', 'options_dollar_price' ), true ) ) {
			$change = array( 'dollar_price' => $value );
			$field  = 'dollar_price';
		} elseif ( in_array( $option, array( 'yuan_price', 'options_yuan_price' ), true ) ) {
			$change = array( 'yuan_price' => $value );
			$field  = 'yuan_price';
		} elseif ( in_array( $option, array( 'update_date', 'options_update_date' ), true ) ) {
			$date = Digitalogic_Currency_Date_Formatter::instance()->parse( $value );
			if ( null === $date ) {
				$this->publish_legacy_write_failure( $option, 'digitalogic_pricing_effective_date_invalid' );

				return $old_value;
			}
			$change = array( 'effective_date' => $date->format( 'Y-m-d' ) );
			$field  = 'effective_date';
		} else {
			return $value;
		}

		++$this->legacy_option_write_depth;
		try {
			$result = $this->update_currency( $change, 'legacy_option' );
		} finally {
			--$this->legacy_option_write_depth;
		}
		if ( is_wp_error( $result ) ) {
			$this->publish_legacy_write_failure( $option, $result->get_error_code() );

			return $old_value;
		}

		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $settings ) ) {
			$this->publish_legacy_write_failure( $option, $settings->get_error_code() );

			return $old_value;
		}
		if ( 'effective_date' === $field ) {
			return substr( $settings['effective_date'], 2, 2 )
				. substr( $settings['effective_date'], 5, 2 )
				. substr( $settings['effective_date'], 8, 2 );
		}

		return $settings[ $field ];
	}

	/**
	 * Route raw rounding-option writes through the same atomic repricer.
	 *
	 * @param mixed  $value     Proposed option value.
	 * @param mixed  $old_value Previous option value.
	 * @param string $option    Exact option name.
	 * @return mixed
	 */
	public function intercept_legacy_rounding_option_write( $value, $old_value, $option ) {
		if (
			$this->legacy_option_write_depth > 0
			|| ! $this->has_managed_pricing_state()
			|| (string) $value === (string) $old_value
		) {
			return $value;
		}

		++$this->legacy_option_write_depth;
		try {
			$result = $this->update_price_rounding( $value, 'legacy_option' );
		} finally {
			--$this->legacy_option_write_depth;
		}
		if ( is_wp_error( $result ) ) {
			$this->publish_legacy_write_failure( $option, $result->get_error_code() );

			return $old_value;
		}

		$settings = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $settings ) ) {
			$this->publish_legacy_write_failure( $option, $settings->get_error_code() );

			return $old_value;
		}

		return $settings['price_rounding_digits'];
	}

	/**
	 * Emit a bounded diagnostic when a legacy mutation is rejected.
	 *
	 * @param string $option     Option name.
	 * @param string $error_code Safe error code.
	 * @return void
	 */
	private function publish_legacy_write_failure( $option, $error_code ) {
		try {
			do_action(
				'digitalogic_pricing_legacy_write_rejected',
				array(
					'option'     => sanitize_key( (string) $option ),
					'error_code' => sanitize_key( (string) $error_code ),
				)
			);
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Normalize a short internal audit-source label.
	 *
	 * @param mixed $source Source label.
	 * @return string
	 */
	private function source_label( $source ) {
		$source = sanitize_key( (string) $source );

		return '' === $source ? 'wp' : substr( $source, 0, 64 );
	}

	/**
	 * Build one bounded error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Persian operator message.
	 * @param int    $status  HTTP status.
	 * @param array  $details Safe details.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status, $details = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge( array( 'status' => (int) $status ), $details )
		);
	}
}
