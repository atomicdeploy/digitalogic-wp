<?php
/**
 * Published board reference.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

// Keep technical labels in the same Latin font inside Persian notes.
$technical_note = static function ( string $note ): string {
	$parts = preg_split( '/(?<![A-Za-z0-9_])(\+?[0-9]+V[A-Z0-9_]*|[A-Z][A-Z0-9_]+(?:-[A-Z0-9]+)*)(?![A-Za-z0-9_])/', $note, -1, PREG_SPLIT_DELIM_CAPTURE );
	$html  = '';
	foreach ( $parts as $index => $part ) {
		$part  = esc_html( $part );
		$html .= $index % 2 ? '<code class="dgl-hw__token" dir="ltr" lang="en">' . $part . '</code>' : $part;
	}
	return $html;
};

$roles = array(
	'power-in'  => 'ورودی تغذیه',
	'power-out' => 'خروجی تغذیه',
	'ground'    => 'زمین',
	'control'   => 'کنترل',
	'shared'    => 'مشترک',
	'gpio'      => 'ورودی و خروجی',
	'input'     => 'فقط ورودی',
	'open'      => 'قطع در حالت پیش‌فرض',
);
?>
<section class="dgl-hw" dir="rtl" lang="fa">
	<h2>Pinout و کاربرد پایه‌ها</h2>
	<div class="dgl-hw__scroll" role="region" aria-label="جدول پایه‌ها" tabindex="0">
		<table class="dgl-hw__table">
			<caption><bdi dir="ltr"><?php echo esc_html( $board['model'] . ' Rev ' . $board['revision'] ); ?></bdi></caption>
			<thead><tr><th scope="col">پایه</th><th scope="col">سیگنال</th><th scope="col">نوع و کاربرد</th></tr></thead>
			<tbody>
			<?php foreach ( $board['pins'] as $pin ) : ?>
				<?php $is_3v3 = str_starts_with( $pin['role'], 'power' ) && preg_match( '/^\+?(?:3V3|3\.3V)(?:$|_)/i', $pin['label'] ); ?>
				<tr>
					<th scope="row"><bdi class="dgl-hw__position" dir="ltr" lang="en"><?php echo esc_html( $pin['connector'] . '–' . $pin['position'] ); ?></bdi></th>
					<td><code class="dgl-hw__pin" data-role="<?php echo esc_attr( $pin['role'] ); ?>"
					<?php
					if ( $is_3v3 ) :
						?>
						data-rail="3v3"<?php endif; ?> dir="ltr" lang="en"><?php echo esc_html( $pin['label'] ); ?></code></td>
					<td><strong><?php echo esc_html( $roles[ $pin['role'] ] ?? $pin['role'] ); ?></strong> <?php echo wp_kses_post( $technical_note( $pin['note'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<h2>ولتاژ و جریان</h2>
	<dl class="dgl-hw__power">
	<?php foreach ( $board['parameters'] as $item ) : ?>
		<dt><data class="dgl-hw__value" data-unit="<?php echo esc_attr( $item['unit'] ); ?>"
		<?php
		if ( 'V' === $item['unit'] && 3.3 === (float) $item['magnitude'] ) :
			?>
			data-rail="3v3"<?php endif; ?> dir="ltr" lang="en" value="<?php echo esc_attr( $item['magnitude'] ); ?>"><?php echo esc_html( rtrim( rtrim( $item['magnitude'], '0' ), '.' ) . ' ' . $item['unit'] ); ?></data></dt>
		<dd><?php echo wp_kses_post( $technical_note( $item['conditions'] ) ); ?></dd>
	<?php endforeach; ?>
	</dl>
	<h2>منابع فنی</h2>
	<ul>
	<?php foreach ( $board['sources'] as $source ) : ?>
		<li><a href="<?php echo esc_url( $source['url'] ); ?>"><?php echo esc_html( $source['title'] ); ?></a></li>
	<?php endforeach; ?>
	</ul>
</section>
