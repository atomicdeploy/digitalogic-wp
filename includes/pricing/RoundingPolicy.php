<?php
/**
 * Exact configurable monetary rounding.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.Files.FileName -- Shared framework-independent pricing classes use PSR names.
declare(strict_types=1);

namespace Digitalogic\Pricing;

require_once __DIR__ . '/ExactDecimalArithmetic.php';

/** Configurable final monetary rounding, selected before rounding the amount. */
final class RoundingPolicy {
	use ExactDecimalArithmetic;

	/** Return the editable initial magnitude tiers. */
	public static function defaults(): array {
		return array(
			'tiers'          => array(
				array(
					'threshold_irt' => '1000',
					'digits'        => 1,
				),
				array(
					'threshold_irt' => '10000',
					'digits'        => 2,
				),
				array(
					'threshold_irt' => '100000',
					'digits'        => 3,
				),
				array(
					'threshold_irt' => '1000000',
					'digits'        => 4,
				),
				array(
					'threshold_irt' => '10000000',
					'digits'        => 5,
				),
			),
			'extend_decades' => true,
		);
	}

	/**
	 * Validate the complete policy without silent repairs.
	 *
	 * @param array $policy Candidate configuration.
	 * @return array
	 * @throws \InvalidArgumentException When the configuration is invalid.
	 */
	public static function normalize( array $policy ): array {
		if ( array_diff( array_keys( $policy ), array( 'tiers', 'extend_decades' ) )
			|| ! isset( $policy['tiers'], $policy['extend_decades'] )
			|| ! is_array( $policy['tiers'] ) || ! array_is_list( $policy['tiers'] )
			|| count( $policy['tiers'] ) < 1 || count( $policy['tiers'] ) > 32
			|| ! is_bool( $policy['extend_decades'] ) ) {
			throw new \InvalidArgumentException( 'Rounding policy requires tiers and a boolean extend_decades.' );
		}
		$self            = new self();
		$previous        = '0';
		$previous_digits = 0;
		$tiers           = array();
		foreach ( $policy['tiers'] as $tier ) {
			if ( ! is_array( $tier ) || count( $tier ) !== 2
				|| ! isset( $tier['threshold_irt'], $tier['digits'] )
				|| ! is_string( $tier['threshold_irt'] )
				|| ! preg_match( '/\A[1-9][0-9]{0,17}\z/D', $tier['threshold_irt'] )
				|| ! is_int( $tier['digits'] ) || $tier['digits'] < $previous_digits
				|| $tier['digits'] > 18 || $tier['digits'] > strlen( $tier['threshold_irt'] ) - 1
				|| $self->big_integer_compare( $tier['threshold_irt'], $previous ) <= 0 ) {
				throw new \InvalidArgumentException( 'Rounding tiers must have ascending exact IRT thresholds and nondecreasing valid digit counts.' );
			}
			$tiers[]         = array(
				'threshold_irt' => $tier['threshold_irt'],
				'digits'        => $tier['digits'],
			);
			$previous        = $tier['threshold_irt'];
			$previous_digits = $tier['digits'];
		}
		return array(
			'tiers'          => $tiers,
			'extend_decades' => $policy['extend_decades'],
		);
	}

	/**
	 * Select digits from the exact unrounded total.
	 *
	 * @param array  $decimal Exact coefficient and decimal scale.
	 * @param string $currency IRT or IRR.
	 * @param array  $policy Configured tiers.
	 * @return int
	 * @throws \InvalidArgumentException When currency or policy is invalid.
	 */
	public static function digits_for_decimal( array $decimal, string $currency, array $policy ): int {
		if ( ! in_array( $currency, array( 'IRT', 'IRR' ), true ) ) {
			throw new \InvalidArgumentException( 'Final rounding currency must be IRT or IRR.' );
		}
		$policy        = self::normalize( $policy );
		$self          = new self();
		$irt           = $decimal;
		$irr_shift     = 'IRR' === $currency ? 1 : 0;
		$irt['scale'] += $irr_shift;
		$digits        = 0;
		foreach ( $policy['tiers'] as $tier ) {
			if ( $self->decimal_compare(
				$irt,
				array(
					'digits' => $tier['threshold_irt'],
					'scale'  => 0,
				)
			) < 0 ) {
				return $digits + $irr_shift;
			}
			$digits = $tier['digits'];
		}
		if ( $policy['extend_decades'] ) {
			$threshold = $tier['threshold_irt'] . '0';
			while ( $digits < 18 && $self->decimal_compare(
				$irt,
				array(
					'digits' => $threshold,
					'scale'  => 0,
				)
			) >= 0 ) {
				++$digits;
				$threshold .= '0';
			}
		}
		return $digits + $irr_shift;
	}

	/**
	 * Round an exact amount in its own currency without floating point.
	 *
	 * @param string $amount Exact decimal amount.
	 * @param string $currency IRT or IRR.
	 * @param array  $policy Configured tiers.
	 * @return string
	 * @throws \InvalidArgumentException When amount, currency or policy is invalid.
	 */
	public static function round( string $amount, string $currency, array $policy ): string {
		if ( ! preg_match( '/\A(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,12}))?\z/D', $amount, $match ) ) {
			throw new \InvalidArgumentException( 'Final amount must be an exact non-negative decimal.' );
		}
		$fraction    = $match[2] ?? '';
		$coefficient = ltrim( $match[1] . $fraction, '0' );
		$decimal     = array(
			'digits' => '' === $coefficient ? '0' : $coefficient,
			'scale'  => strlen( $fraction ),
		);
		$digits      = self::digits_for_decimal( $decimal, $currency, $policy );
		return ( new self() )->decimal_round_half_up_to_digits( $decimal, $digits );
	}
}
