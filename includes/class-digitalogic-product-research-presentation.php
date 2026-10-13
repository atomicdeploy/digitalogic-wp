<?php
/**
 * Structured private research values and administrator presentation.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

/** Present normalized sources without changing observations or public prices. */
final class Digitalogic_Product_Research_Presentation {

	/** Lossless decimal normalization; unknown values stay null.
	 *
	 * @param mixed $value Value.
	 */
	public static function amount( $value ) {
		if ( null === $value || ! is_scalar( $value ) || ! preg_match( '/^\d{1,16}(?:\.\d{1,8})?$/', (string) $value ) ) {
			return null;
		}
		$parts = explode( '.', (string) $value );
		$whole = ltrim( $parts[0], '0' );
		$tail  = rtrim( $parts[1] ?? '', '0' );
		return ( '' === $whole ? '0' : $whole ) . ( '' === $tail ? '' : '.' . $tail );
	}

	/** Persian digits for human-readable labels only.
	 *
	 * @param mixed $value Value.
	 */
	public static function digits( $value ) {
		return strtr(
			(string) $value,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}

	/** Thousands separators without float rounding or truncating real fractions.
	 *
	 * @param mixed $value Value.
	 */
	public static function format_amount( $value ) {
		$value = self::amount( $value );
		if ( null === $value ) {
			return 'نامعلوم';
		}
		$parts = explode( '.', $value );
		return self::digits( preg_replace( '/\B(?=(\d{3})+(?!\d))/', '٬', $parts[0] ) . ( isset( $parts[1] ) ? '٫' . $parts[1] : '' ) );
	}

	/** Parse SQL DATETIME as UTC, exposing ISO and integer epoch values.
	 *
	 * @param mixed $value Value.
	 */
	public static function time_value( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, new DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}
		return array(
			'iso8601' => $date->format( 'Y-m-d\TH:i:s\Z' ),
			'unix'    => $date->getTimestamp(),
			'display' => self::digits( $date->format( 'Y/m/d H:i:s' ) ) . ' UTC',
		);
	}

	/** Semantic time markup; never displays an unknown date as epoch zero.
	 *
	 * @param mixed $value Value.
	 */
	public static function time_html( $value ) {
		$time = self::time_value( $value );
		return $time ? '<time datetime="' . esc_attr( $time['iso8601'] ) . '" data-timestamp="' . esc_attr( (string) $time['unix'] ) . '" dir="ltr">' . esc_html( $time['display'] ) . '</time>' : '<span>زمان نامعلوم</span>';
	}

	/** Structured amounts remain decimals; currency is a separate field.
	 *
	 * @param string|null $amount Amount.
	 * @param string|null $currency Currency.
	 */
	public static function money_html( $amount, $currency ) {
		$amount = self::amount( $amount );
		if ( null === $amount ) {
			return '<span class="dgl-research-unknown">قیمت نامعلوم</span>';
		}
		$label = array(
			'IRT' => 'تومان',
			'IRR' => 'ریال',
		)[ $currency ] ?? $currency;
		return '<span class="dgl-research-money" data-currency="' . esc_attr( (string) $currency ) . '"><data value="' . esc_attr( $amount ) . '">' . esc_html( self::format_amount( $amount ) ) . '</data> <span>' . esc_html( (string) $label ) . '</span></span>';
	}

	/** Fixed-scale digit strings allow exact comparison above PHP integer precision.
	 *
	 * @param string|null $amount Amount.
	 */
	private static function scaled( $amount ) {
		$parts  = explode( '.', $amount );
		$scaled = ltrim( $parts[0] . str_pad( $parts[1] ?? '', 8, '0' ), '0' );
		return '' === $scaled ? '0' : $scaled;
	}

	/** Exact nonnegative difference, preserving the eight decimal database places.
	 *
	 * @param string $larger Larger.
	 * @param string $smaller Smaller.
	 */
	private static function difference( $larger, $smaller ) {
		$larger  = self::scaled( $larger );
		$smaller = str_pad( self::scaled( $smaller ), strlen( $larger ), '0', STR_PAD_LEFT );
		$result  = '';
		$borrow  = 0;
		for ( $i = strlen( $larger ) - 1; $i >= 0; --$i ) {
			$digit  = (int) $larger[ $i ] - (int) $smaller[ $i ] - $borrow;
			$borrow = $digit < 0 ? 1 : 0;
			$result = (string) ( $digit < 0 ? $digit + 10 : $digit ) . $result;
		}
		$result = str_pad( $result, 9, '0', STR_PAD_LEFT );
		return self::amount( substr( $result, 0, -8 ) . '.' . substr( $result, -8 ) );
	}

	/** Pure numeric comparison. Candidate colors never certify physical identity.
	 *
	 * @param array $offer Offer.
	 * @param array $own Own.
	 * @param array $context Context.
	 */
	public static function compare( $offer, $own, $context ) {
		$result = array(
			'state'      => 'neutral',
			'reason'     => 'price_unknown',
			'difference' => null,
			'percent'    => null,
			'currency'   => $own['currency'],
			'reference'  => $own,
		);
		$price  = self::amount( $offer['amount'] );
		$base   = self::amount( $own['amount'] );
		if ( ! $context['identity_current'] || in_array( $context['match'], array( 'different_variant', 'reference_only' ), true ) ) {
			$result['reason'] = 'model_unconfirmed';
		} elseif ( ! $own['product_id'] ) {
			$result['reason'] = 'variation_unspecified';
		} elseif ( 1 !== $offer['units'] ) {
			$result['reason'] = 'quantity_unconfirmed';
		} elseif ( null !== $price && null !== $base ) {
			if ( $offer['currency'] !== $own['currency'] ) {
				$result['reason'] = 'currency_mismatch';
			} else {
				$a                    = self::scaled( $price );
				$b                    = self::scaled( $base );
				$direction            = strlen( $a ) <=> strlen( $b );
				$direction            = 0 === $direction ? strcmp( $a, $b ) : $direction;
				$result['state']      = 0 === $direction ? 'equal' : ( $direction < 0 ? 'lower' : 'higher' );
				$result['reason']     = 'candidate' === $context['match'] ? 'numeric_candidate' : 'same_currency_unit';
				$result['difference'] = $direction < 0 ? self::difference( $base, $price ) : self::difference( $price, $base );
				// The exact difference determines color; the percentage is a rounded display approximation.
				$result['percent'] = '0' === $base ? null : round( (float) $result['difference'] / (float) $base * 100, 2 );
			}
		}
		return $result;
	}

	/** Current single-unit WooCommerce base price, never a formatted aggregate minimum.
	 *
	 * @param int $id Id.
	 * @param int $variation_id Variation id.
	 */
	public static function current_price( $id, $variation_id = 0 ) {
		$parent = wc_get_product( $id );
		$target = $variation_id ? wc_get_product( $variation_id ) : $parent;
		$valid  = $target && $parent && ( $variation_id ? $target->get_parent_id() === (int) $id : 'variable' !== $parent->get_type() );
		return array(
			'product_id' => $valid ? $target->get_id() : null,
			'sku'        => $valid ? (string) get_post_meta( $target->get_id(), '_sku', true ) : null,
			'amount'     => $valid ? self::amount( $target->get_price() ) : null,
			'currency'   => get_woocommerce_currency(),
			'units'      => 1,
			'basis'      => 'current_woocommerce_base_price',
		);
	}

	/** Add machine-readable price/time/comparison values without rewriting raw history.
	 *
	 * @param int   $id Id.
	 * @param array $rows Rows.
	 */
	public static function decorate( $id, $rows ) {
		foreach ( $rows as &$row ) {
			$time                    = self::time_value( $row['observed_at'] );
			$row['offer']            = array(
				'amount'        => self::amount( $row['price'] ),
				'currency'      => $row['currency'],
				'units'         => $row['details']['offer_units'] ?? null,
				'observed_at'   => $time ? $time['iso8601'] : null,
				'observed_unix' => $time ? $time['unix'] : null,
			);
			$row['price_comparison'] = self::compare(
				$row['offer'],
				self::current_price( $id, $row['details']['variation_id'] ?? 0 ),
				array(
					'identity_current' => $row['identity_current'],
					'match'            => $row['details']['match'],
				)
			);
		}
		unset( $row );
		return $rows;
	}

	/** A host profile appears once; independent offers keep their listing identities.
	 *
	 * @param array $rows Rows.
	 */
	public static function groups( $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$groups[ $row['source_id'] ][] = $row;
		}
		return $groups;
	}

	/** Accessible color badges include exact absolute and approximate relative difference.
	 *
	 * @param array $comparison Comparison.
	 */
	private static function comparison_html( $comparison ) {
		$labels  = array(
			'lower'   => 'قیمت فروشنده کمتر از ما',
			'higher'  => 'قیمت فروشنده بیشتر از ما',
			'equal'   => 'قیمت برابر',
			'neutral' => 'بدون مقایسهٔ عددی',
		);
		$reasons = array(
			'price_unknown'         => 'قیمت نامعلوم است.',
			'currency_mismatch'     => 'ارز متفاوت است؛ تبدیل انجام نشده.',
			'model_unconfirmed'     => 'مدل متفاوت، مرجع یا هویت تغییرکرده است.',
			'variation_unspecified' => 'گونهٔ دقیق دیجیتالاجیک انتخاب نشده.',
			'quantity_unconfirmed'  => 'تعداد بسته نامعلوم یا متفاوت است.',
			'numeric_candidate'     => 'مقایسه فقط عددی است؛ تطبیق مدل هنوز قطعی نیست.',
			'same_currency_unit'    => 'مقایسهٔ تک‌عدد و هم‌ارز.',
		);
		$html    = '<div class="dgl-research-comparison dgl-research-comparison--' . esc_attr( $comparison['state'] ) . '" data-comparison="' . esc_attr( $comparison['state'] ) . '"><strong>' . esc_html( $labels[ $comparison['state'] ] ) . '</strong>';
		if ( null !== $comparison['difference'] ) {
			$html .= '<p>اختلاف: ' . self::money_html( $comparison['difference'], $comparison['currency'] ) . ( null === $comparison['percent'] ? '' : '؛ حدود ' . esc_html( self::digits( (string) $comparison['percent'] ) ) . '٪' ) . '</p>';
		}
		return $html . '<p>' . esc_html( $reasons[ $comparison['reason'] ] ) . '</p></div>';
	}

	/** Render only inside authorized administration views.
	 *
	 * @param int   $id Id.
	 * @param array $data Data.
	 */
	public static function render( $id, $data ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		$own = self::current_price( $id );
		echo '<div class="dgl-research" dir="rtl"><h3>جدول پژوهش فروشندگان و سازندگان</h3><p class="dgl-research-current">قیمت پایهٔ فعلی دیجیتالاجیک برای یک عدد: ' . self::money_html( $own['amount'], $own['currency'] ) . '</p><p>سبز: فروشنده ارزان‌تر؛ قرمز: فروشنده گران‌تر؛ بدون رنگ: برابر یا مقایسه‌ناپذیر. قیمت پایه بدون تخفیف پلکانی و حمل مقایسه می‌شود؛ مالیات و اصالت جدا بررسی شوند. زمان‌ها میلادی و UTC هستند.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helpers escape every value before returning semantic markup.
		if ( $data['coverage'] ) {
			echo '<p>' . esc_html( $data['coverage']['report']['summary_fa'] ?? '' ) . '</p><details><summary>موارد نیازمند بررسی</summary><p>' . esc_html( $data['coverage']['report']['issues_fa'] ?? '' ) . '</p></details>';
		}
		echo '<div class="dgl-research-scroll" tabindex="0" role="region" aria-label="جدول خصوصی پژوهش محصول"><table class="widefat dgl-research-table"><thead><tr><th scope="col">فروشگاه</th><th scope="col">پیشنهاد و تطبیق</th><th scope="col">قیمت و زمان مشاهده</th><th scope="col">مقایسهٔ قیمت</th><th scope="col">محتوا و پیوست‌ها</th></tr></thead>';
		foreach ( self::groups( $data['listings'] ) as $source_id => $rows ) {
			echo '<tbody data-source-id="' . esc_attr( (string) $source_id ) . '">';
			foreach ( $rows as $index => $row ) {
				$d = $row['details'];
				$p = $row['profile'];
				echo '<tr data-listing-id="' . esc_attr( (string) $row['id'] ) . '">';
				if ( 0 === $index ) {
					echo '<td class="dgl-research-source" rowspan="' . esc_attr( (string) count( $rows ) ) . '"><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $p['storefront_url'] ) . '"><strong>' . esc_html( $p['title'] ) . '</strong></a><p><bdi>' . esc_html( $row['domain'] ) . '</bdi></p><p>' . esc_html( self::digits( count( $rows ) ) ) . ' پیشنهاد مستقل</p><details><summary>معرفی و نشانی</summary><p>' . esc_html( $p['about_fa'] ?? 'معرفی نامعلوم' ) . '</p><p>' . esc_html( $p['address_fa'] ?? 'نشانی نامعلوم' ) . '</p></details></td>';
				}
				echo '<td><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $row['url'] ) . '">' . esc_html( $d['title'] ) . '</a><p>' . esc_html( Digitalogic_Product_Research::label( $d['kind'] ) . '؛ ' . Digitalogic_Product_Research::label( $d['match'] ) ) . '</p><details><summary>شواهد تطبیق</summary><p>' . esc_html( $d['identity_evidence_fa'] ) . '</p></details>' . ( $row['identity_current'] ? '' : '<strong>هویت محصول تغییر کرده؛ بررسی دوباره لازم است.</strong>' ) . '</td><td>' . self::money_html( $row['offer']['amount'], $row['offer']['currency'] ) . '<p>' . esc_html( Digitalogic_Product_Research::label( $row['availability'] ) ) . '</p>' . self::time_html( $row['observed_at'] ) . '<details><summary>شرایط قیمت و موجودی</summary><p>' . esc_html( $d['price_note_fa'] ) . '</p><p>' . esc_html( $d['availability_note_fa'] ) . '</p></details></td><td>' . self::comparison_html( $row['price_comparison'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helpers escape every value before returning semantic markup.
				if ( $row['price_comparison']['reference']['product_id'] && $row['price_comparison']['reference']['product_id'] !== (int) $id ) {
					echo '<p>گونهٔ دیجیتالاجیک: <bdi>' . esc_html( (string) $row['price_comparison']['reference']['sku'] ) . '</bdi>؛ ' . self::money_html( $row['price_comparison']['reference']['amount'], $row['price_comparison']['reference']['currency'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helpers escape every value before returning semantic markup.
				}
				echo '<details><summary>یادداشت تاریخی مقایسه</summary><p>' . esc_html( $d['comparison_fa'] ) . '</p></details></td><td><details><summary>شرح، مشخصات و فایل‌ها</summary>';
				foreach ( array_unique( array_filter( array( $d['summary_fa'], $d['specifications_fa'], $d['description_fa'] ) ) ) as $text ) {
					echo '<p>' . esc_html( $text ) . '</p>';
				}
				foreach ( $d['attachments'] as $attachment ) {
					echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $attachment['url'] ) . '">' . esc_html( $attachment['title_fa'] ) . '</a></p>';
				}
				echo '</details></td></tr>';
			}
			echo '</tbody>';
		}
		echo '</table></div></div>';
	}
}
