<?php
declare(strict_types=1);

namespace Digitalogic\Pricing;

require_once __DIR__ . '/ExactDecimalArithmetic.php';

/** One framework-independent implementation of the supported selling-price policies. */
final class Calculator {
	use ExactDecimalArithmetic;

	private const MAX_MARKUP_PERCENT = '1000';

	public function evaluate( array $product, string $path = 'product' ): array {
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

		$source_fields = array( 'price_source_amount', 'price_source_currency', 'price_source_kind' );
		$missing       = array();
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
				return $this->field_error( $path . '.price_source_currency', 'must be IRR for sale_price_direct' );
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
					|| 'domestic' === $product['shipping_method_id']
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
				&& 'domestic' !== $product['shipping_method_id']
			) {
				$missing[] = 'shipping_method_id';
			}
			if (
				array_key_exists( 'shipping_price_per_kg_currency', $product )
				&& 'IRR' !== $product['shipping_price_per_kg_currency']
			) {
				$missing[] = 'shipping_price_per_kg_currency';
			}
		}
		$missing = array_values( array_unique( $missing ) );
		if ( ! empty( $missing ) ) {
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
				if ( isset( $decimal['error'] ) ) {
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
			if ( 'CNY' === $product['shipping_price_per_kg_currency'] ) {
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
			$base_irt           = $source_amount;
			$base_irt['scale'] += 1; // IRR to IRT, exactly.
		} else {
			if ( 0 !== $this->decimal_compare( $shipping_rate, $this->formula_decimal_parts( '0' ) ) ) {
				return array(
					'available' => false,
					'missing'   => array( 'shipping_price_per_kg' ),
				);
			}
			$direct_irt           = $source_amount;
			$direct_irt['scale'] += 1; // IRR to IRT, exactly.
			while ( $direct_irt['scale'] > 0 && str_ends_with( $direct_irt['digits'], '0' ) ) {
				$direct_irt['digits'] = substr( $direct_irt['digits'], 0, -1 );
				--$direct_irt['scale'];
			}
			if ( $direct_irt['scale'] > 0 ) {
				return array(
					'available' => false,
					'missing'   => array( 'integer_irt_amount' ),
				);
			}
			if ( $this->big_integer_compare( $direct_irt['digits'], (string) PHP_INT_MAX ) > 0 ) {
				return $this->field_error( $path . '.final_price', 'sale_price_direct exceeds the supported IRT integer range' );
			}

			return array(
				'available' => true,
				'missing'   => array(),
				'value'     => (int) $direct_irt['digits'],
			);
		}

		if ( $this->decimal_compare( $markup, $this->formula_decimal_parts( self::MAX_MARKUP_PERCENT ) ) > 0 ) {
			return $this->field_error( $path . '.markup_percent', 'must not exceed ' . self::MAX_MARKUP_PERCENT );
		}
		if (
			( ! is_int( $product['price_rounding_digits'] ) && ! is_string( $product['price_rounding_digits'] ) )
			|| ! preg_match( '/\A[0-9]\z/D', (string) $product['price_rounding_digits'] )
			|| (int) $product['price_rounding_digits'] > 9
			|| 'nearest_half_up' !== $product['price_rounding_mode']
		) {
			return array(
				'available' => false,
				'missing'   => array( 'price_rounding_digits', 'price_rounding_mode' ),
			);
		}

		$markup_multiplier   = $this->decimal_add(
			$this->formula_decimal_parts( '100' ),
			$markup
		);
		$marked_up           = $this->decimal_multiply( $base_irt, $markup_multiplier );
		$marked_up['scale'] += 2; // percent to multiplier, exactly.
		$rounding_digits     = (int) $product['price_rounding_digits'];
		$rounded             = $this->decimal_round_half_up_to_digits( $marked_up, $rounding_digits );
		if ( $this->big_integer_compare( $rounded, (string) PHP_INT_MAX ) > 0 ) {
			return $this->field_error( $path . '.final_price', 'landed_price exceeds the supported IRT integer range' );
		}

		return array(
			'available' => true,
			'missing'   => array(),
			'value'     => (int) $rounded,
		);
	}


	private function field_error( string $field, string $reason ): never {
		throw new \InvalidArgumentException( $field . ': ' . $reason );
	}
}
