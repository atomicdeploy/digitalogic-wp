<?php
declare(strict_types=1);

namespace Digitalogic\Integrations\Paradox;

use Digitalogic\Pricing\ExactDecimalArithmetic;

require_once dirname( __DIR__, 2 ) . '/pricing/ExactDecimalArithmetic.php';

/** Report comparisons reuse shared arithmetic on already validated decimal parts. */
final class ReportArithmetic {

	use ExactDecimalArithmetic {
		decimal_compare as public compare;
		big_integer_compare as public integerCompare;
		normalize_big_integer as public normalize;
	}

	/** Unsigned integer subtraction for display-only drift; no price evaluation. */
	public function subtract( string $larger, string $smaller ): string {
		if ( ! preg_match( '/\A[0-9]+\z/D', $larger ) || ! preg_match( '/\A[0-9]+\z/D', $smaller )
			|| $this->integerCompare( $larger, $smaller ) < 0
		) {
			throw new \InvalidArgumentException( 'Report subtraction requires ordered unsigned integers.' );
		}
		$larger  = $this->normalize( $larger );
		$smaller = str_pad( $this->normalize( $smaller ), strlen( $larger ), '0', STR_PAD_LEFT );
		$borrow  = 0;
		$result  = '';
		for ( $index = strlen( $larger ) - 1; $index >= 0; --$index ) {
			$digit  = (int) $larger[ $index ] - (int) $smaller[ $index ] - $borrow;
			$borrow = $digit < 0 ? 1 : 0;
			$result = (string) ( $digit + ( $borrow ? 10 : 0 ) ) . $result;
		}
		return $this->normalize( $result );
	}
}
