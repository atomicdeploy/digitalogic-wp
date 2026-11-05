<?php
/**
 * Published board reference.
 *
 * @package Digitalogic
 */

defined( 'ABSPATH' ) || exit;

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
	<h2>Pinout کانکتور <bdi dir="ltr">CON1</bdi></h2>
	<div class="dgl-hw__scroll" role="region" aria-label="جدول پایه‌ها" tabindex="0">
		<table class="dgl-hw__table">
			<caption><bdi dir="ltr"><?php echo esc_html( $board['model'] . ' Rev ' . $board['revision'] ); ?></bdi></caption>
			<thead><tr><th scope="col">پایه</th><th scope="col">سیگنال</th><th scope="col">نوع و کاربرد</th></tr></thead>
			<tbody>
			<?php foreach ( $board['pins'] as $pin ) : ?>
				<tr>
					<th scope="row"><bdi dir="ltr"><?php echo esc_html( $pin['connector'] . '–' . $pin['position'] ); ?></bdi></th>
					<td><code class="dgl-hw__pin" data-role="<?php echo esc_attr( $pin['role'] ); ?>" dir="ltr"><?php echo esc_html( $pin['label'] ); ?></code></td>
					<td><strong><?php echo esc_html( $roles[ $pin['role'] ] ?? $pin['role'] ); ?></strong> <?php echo esc_html( $pin['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<h2>ولتاژ و جریان</h2>
	<dl class="dgl-hw__power">
	<?php foreach ( $board['parameters'] as $item ) : ?>
		<dt><data dir="ltr" value="<?php echo esc_attr( $item['magnitude'] ); ?>"><?php echo esc_html( rtrim( rtrim( $item['magnitude'], '0' ), '.' ) . ' ' . $item['unit'] ); ?></data></dt>
		<dd><?php echo esc_html( $item['conditions'] ); ?></dd>
	<?php endforeach; ?>
	</dl>
	<h2>منابع فنی</h2>
	<ul>
	<?php foreach ( $board['sources'] as $source ) : ?>
		<li><a href="<?php echo esc_url( $source['url'] ); ?>"><?php echo esc_html( $source['title'] ); ?></a></li>
	<?php endforeach; ?>
	</ul>
</section>
