<?php
/**
 * Shared Digitalogic error-card content.
 *
 * @package Digitalogic
 * @var array $view Digitalogic error view model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<main class="dle-shell">
	<section class="dle-card" aria-labelledby="dle-title" aria-describedby="dle-detail">
		<div class="dle-brand">
			<span class="dle-logo"><img src="<?php echo esc_url( $view['logo_url'] ); ?>" alt=""></span>
			<span><strong>Digitalogic</strong><small><?php echo esc_html( $view['site_name'] ); ?></small></span>
		</div>

		<div class="dle-status" aria-hidden="true">
			<span class="dle-status-code"><?php echo esc_html( $view['status'] ); ?></span>
			<svg viewBox="0 0 24 24" focusable="false"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 5.25a1.15 1.15 0 1 1 0 2.3 1.15 1.15 0 0 1 0-2.3Zm1.15 9.5h-2.3v-5.5h2.3v5.5Z"/></svg>
		</div>

		<p class="dle-eyebrow"><?php echo esc_html( $view['eyebrow'] ); ?></p>
		<h1 id="dle-title"><?php echo esc_html( $view['title'] ); ?></h1>
		<p class="dle-detail" id="dle-detail"><?php echo esc_html( $view['detail'] ); ?></p>

		<?php if ( '' !== $view['signed_in_label'] ) : ?>
			<p class="dle-user">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5.33 0-8 2.67-8 5v1.5h16V19c0-2.33-2.67-5-8-5Z"/></svg>
				<?php echo esc_html( $view['signed_in_label'] ); ?>
			</p>
		<?php endif; ?>

		<nav class="dle-actions" aria-label="<?php echo esc_attr( $view['eyebrow'] ); ?>">
			<?php foreach ( $view['actions'] as $error_action ) : ?>
				<a class="dle-button<?php echo ! empty( $error_action['primary'] ) ? ' is-primary' : ''; ?>" href="<?php echo esc_url( $error_action['url'] ?? '' ); ?>">
					<?php echo esc_html( $error_action['label'] ?? '' ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<footer class="dle-footer">
			<span><?php echo esc_html( $view['reference_label'] ); ?></span>
			<code dir="ltr"><?php echo esc_html( $view['code'] ); ?></code>
		</footer>
	</section>
</main>
