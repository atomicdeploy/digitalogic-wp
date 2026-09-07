<?php
declare(strict_types=1);

namespace Digitalogic\Pricing;

/** Exact base-10 arithmetic shared by pricing and provider validation. */
trait ExactDecimalArithmetic {
	private const MAX_FORMULA_INTEGER_DIGITS = 15;
	private const MAX_FORMULA_SCALE          = 12;

	private function formula_decimal_parts( $value ) {
		if ( $value instanceof \Stringable ) {
			$text = (string) $value;
		} elseif ( is_string( $value ) || is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) && is_finite( $value ) ) {
			$text = json_encode( $value, JSON_THROW_ON_ERROR );
		} else {
			return array( 'error' => 'must be an exact base-10 decimal' );
		}
		if ( ! preg_match( '/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $text, $matches ) ) {
			return array( 'error' => 'must be a non-negative base-10 decimal without exponent notation' );
		}

		$integer        = $matches[1];
		$fraction       = isset( $matches[2] ) ? $matches[2] : '';
		$integer_digits = strlen( ltrim( $integer, '0' ) );
		if ( 0 === $integer_digits ) {
			$integer_digits = 1;
		}
		if ( $integer_digits > self::MAX_FORMULA_INTEGER_DIGITS ) {
			return array( 'error' => 'has too many integer digits for landed_price' );
		}
		if ( strlen( $fraction ) > self::MAX_FORMULA_SCALE ) {
			return array( 'error' => 'has too many fractional digits for landed_price' );
		}

		$scale  = strlen( $fraction );
		$digits = ltrim( $integer . $fraction, '0' );
		$digits = '' === $digits ? '0' : $digits;
		while ( $scale > 0 && str_ends_with( $digits, '0' ) ) {
			$digits = substr( $digits, 0, -1 );
			--$scale;
		}
		if ( '' === $digits ) {
			$digits = '0';
			$scale  = 0;
		}

		return array(
			'digits' => $digits,
			'scale'  => $scale,
		);
	}

	private function decimal_add( $left, $right ) {
		$scale        = max( (int) $left['scale'], (int) $right['scale'] );
		$left_digits  = $left['digits'] . str_repeat( '0', $scale - (int) $left['scale'] );
		$right_digits = $right['digits'] . str_repeat( '0', $scale - (int) $right['scale'] );

		return array(
			'digits' => $this->big_integer_add( $left_digits, $right_digits ),
			'scale'  => $scale,
		);
	}

	private function decimal_multiply( $left, $right ) {
		return array(
			'digits' => $this->big_integer_multiply( $left['digits'], $right['digits'] ),
			'scale'  => (int) $left['scale'] + (int) $right['scale'],
		);
	}

	private function decimal_compare( $left, $right ) {
		$scale        = max( (int) $left['scale'], (int) $right['scale'] );
		$left_digits  = $left['digits'] . str_repeat( '0', $scale - (int) $left['scale'] );
		$right_digits = $right['digits'] . str_repeat( '0', $scale - (int) $right['scale'] );

		return $this->big_integer_compare( $left_digits, $right_digits );
	}

	private function decimal_round_half_up_integer( $decimal ) {
		$digits = $this->normalize_big_integer( $decimal['digits'] );
		$scale  = (int) $decimal['scale'];
		if ( $scale <= 0 ) {
			return $digits . str_repeat( '0', -$scale );
		}

		$padded  = str_pad( $digits, $scale + 1, '0', STR_PAD_LEFT );
		$cut     = strlen( $padded ) - $scale;
		$integer = $this->normalize_big_integer( substr( $padded, 0, $cut ) );
		if ( (int) $padded[ $cut ] >= 5 ) {
			$integer = $this->big_integer_add( $integer, '1' );
		}

		return $integer;
	}

	private function decimal_round_half_up_to_digits( $decimal, $digits ) {
		$scaled           = $decimal;
		$scaled['scale'] += (int) $digits;
		$rounded          = $this->decimal_round_half_up_integer( $scaled );

		return $this->normalize_big_integer( $rounded . str_repeat( '0', (int) $digits ) );
	}

	private function big_integer_add( $left, $right ) {
		$left   = strrev( $this->normalize_big_integer( $left ) );
		$right  = strrev( $this->normalize_big_integer( $right ) );
		$length = max( strlen( $left ), strlen( $right ) );
		$carry  = 0;
		$result = '';
		for ( $index = 0; $index < $length; $index++ ) {
			$sum     = ( $index < strlen( $left ) ? (int) $left[ $index ] : 0 )
				+ ( $index < strlen( $right ) ? (int) $right[ $index ] : 0 )
				+ $carry;
			$result .= (string) ( $sum % 10 );
			$carry   = intdiv( $sum, 10 );
		}
		if ( $carry > 0 ) {
			$result .= (string) $carry;
		}

		return $this->normalize_big_integer( strrev( $result ) );
	}

	private function big_integer_multiply( $left, $right ) {
		$left  = $this->normalize_big_integer( $left );
		$right = $this->normalize_big_integer( $right );
		if ( '0' === $left || '0' === $right ) {
			return '0';
		}

		$result = array_fill( 0, strlen( $left ) + strlen( $right ), 0 );
		for ( $left_index = strlen( $left ) - 1; $left_index >= 0; $left_index-- ) {
			for ( $right_index = strlen( $right ) - 1; $right_index >= 0; $right_index-- ) {
				$position                 = $left_index + $right_index + 1;
				$sum                      = $result[ $position ] + ( (int) $left[ $left_index ] * (int) $right[ $right_index ] );
				$result[ $position ]      = $sum % 10;
				$result[ $position - 1 ] += intdiv( $sum, 10 );
			}
		}

		return $this->normalize_big_integer( implode( '', $result ) );
	}

	private function big_integer_compare( $left, $right ) {
		$left  = $this->normalize_big_integer( $left );
		$right = $this->normalize_big_integer( $right );
		if ( strlen( $left ) !== strlen( $right ) ) {
			return strlen( $left ) <=> strlen( $right );
		}

		return strcmp( $left, $right ) <=> 0;
	}

	private function normalize_big_integer( $value ) {
		$value = ltrim( (string) $value, '0' );
		return '' === $value ? '0' : $value;
	}
}
