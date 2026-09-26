<?php
/**
 * Branded post-checkout actions, bank ingress details, and receipt intake.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Customer and administrator experience for card-to-card orders. */
final class Digitalogic_Order_Payment_Experience {
	private const OPTION_KEY       = 'digitalogic_bank_ingress_accounts';
	private const FRONT_STYLE      = 'digitalogic-order-payment-experience';
	private const FRONT_SCRIPT     = 'digitalogic-order-payment-experience';
	private const ADMIN_SCRIPT     = 'digitalogic-bank-ingress-admin';
	private const RECEIPT_MAX_SIZE = 5242880;
	private const META_FILE        = '_digitalogic_payment_receipt_file';
	private const META_NAME        = '_digitalogic_payment_receipt_name';
	private const META_REFERENCE   = '_digitalogic_payment_receipt_reference';
	private const META_NOTE        = '_digitalogic_payment_receipt_note';
	private const META_STATUS      = '_digitalogic_payment_receipt_status';
	private const META_SUBMITTED   = '_digitalogic_payment_receipt_submitted_at';

	/** Register hooks once. */
	public static function init(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_front_assets' ), 95 );
		add_action( 'woocommerce_thankyou', array( self::class, 'render_for_order' ), 4 );
		add_action( 'woocommerce_view_order', array( self::class, 'render_for_order' ), 4 );
		add_action( 'admin_menu', array( self::class, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_digitalogic_save_bank_ingress', array( self::class, 'save_bank_accounts' ) );
		add_action( 'admin_post_digitalogic_submit_payment_receipt', array( self::class, 'submit_receipt' ) );
		add_action( 'admin_post_nopriv_digitalogic_submit_payment_receipt', array( self::class, 'submit_receipt' ) );
		add_action( 'admin_post_digitalogic_download_payment_receipt', array( self::class, 'download_receipt' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'render_admin_receipt' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'save_admin_receipt_status' ), 20, 2 );
	}

	/** Load customer assets only where an authorized order can be shown. */
	public static function enqueue_front_assets(): void {
		$is_order_page = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$is_view_order = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' );
		if ( ! $is_order_page && ! $is_view_order ) {
			return;
		}

		$style  = DIGITALOGIC_PLUGIN_DIR . 'assets/css/order-payment-experience.css';
		$script = DIGITALOGIC_PLUGIN_DIR . 'assets/js/order-payment-experience.js';
		if ( is_readable( $style ) ) {
			wp_enqueue_style( self::FRONT_STYLE, DIGITALOGIC_PLUGIN_URL . 'assets/css/order-payment-experience.css', array(), (string) filemtime( $style ) );
		}
		if ( is_readable( $script ) ) {
			wp_enqueue_script( self::FRONT_SCRIPT, DIGITALOGIC_PLUGIN_URL . 'assets/js/order-payment-experience.js', array(), (string) filemtime( $script ), true );
			wp_localize_script(
				self::FRONT_SCRIPT,
				'DigitalogicOrderExperience',
				array(
					'copied'          => __( 'Copied', 'digitalogic' ),
					'copyUnavailable' => __( 'Automatic copy is unavailable', 'digitalogic' ),
					'invoicePending'  => __( 'The invoice PDF is not ready yet', 'digitalogic' ),
					'shareTitle'      => __( 'Digitalogic order', 'digitalogic' ),
					'shareText'       => __( 'Secure Digitalogic order and invoice link', 'digitalogic' ),
					'noFile'          => __( 'No file selected', 'digitalogic' ),
				)
			);
		}
	}

	/**
	 * Render the action hub and card-to-card receipt flow.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public static function render_for_order( $order_id ): void {
		static $rendered = array();
		$order_id        = absint( $order_id );
		if ( ! $order_id || isset( $rendered[ $order_id ] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::can_access_order( $order ) ) {
			return;
		}
		$rendered[ $order_id ] = true;

		$order_number = (string) $order->get_order_number();
		$order_url    = self::order_url( $order );
		$receipt      = self::receipt_state( $order );
		$download_url = class_exists( 'Digitalogic_Order_Documents' ) ? Digitalogic_Order_Documents::customer_document_url( $order, 'download' ) : '#';
		$print_url    = class_exists( 'Digitalogic_Order_Documents' ) ? Digitalogic_Order_Documents::customer_document_url( $order, 'print' ) : '#';
		?>
		<section class="dg-order-hub" data-order-id="<?php echo esc_attr( $order_number ); ?>" data-order-url="<?php echo esc_url( $order_url ); ?>" aria-labelledby="dg-order-hub-title">
			<div class="dg-order-hub__intro">
				<span class="dg-order-hub__eyebrow"><?php echo wp_kses_post( self::icon( 'check' ) ); ?><?php esc_html_e( 'Order received', 'digitalogic' ); ?></span>
				<h2 id="dg-order-hub-title"><?php esc_html_e( 'Your order documents and actions', 'digitalogic' ); ?></h2>
				<p><?php esc_html_e( 'Download the invoice, print it, or share the secure order link from one place.', 'digitalogic' ); ?></p>
			</div>
			<div class="dg-order-hub__actions" aria-label="<?php esc_attr_e( 'Order actions', 'digitalogic' ); ?>">
				<a class="dg-order-action dg-order-action--primary" data-dg-invoice-download href="<?php echo esc_url( $download_url ); ?>"<?php echo '#' === $download_url ? ' aria-disabled="true"' : ''; ?>>
					<?php echo wp_kses_post( self::icon( 'download' ) ); ?><span><?php esc_html_e( 'Download invoice', 'digitalogic' ); ?></span>
				</a>
				<div class="dg-order-menu">
					<button class="dg-order-action dg-order-action--secondary" type="button" data-dg-menu-toggle aria-expanded="false" aria-controls="dg-order-menu-<?php echo esc_attr( $order_id ); ?>">
						<?php echo wp_kses_post( self::icon( 'more' ) ); ?><span><?php esc_html_e( 'More actions', 'digitalogic' ); ?></span><?php echo wp_kses_post( self::icon( 'chevron' ) ); ?>
					</button>
					<div class="dg-order-menu__panel" id="dg-order-menu-<?php echo esc_attr( $order_id ); ?>" data-dg-menu-panel hidden>
						<a data-dg-invoice-print href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener"><?php echo wp_kses_post( self::icon( 'print' ) ); ?><span><?php esc_html_e( 'Print invoice', 'digitalogic' ); ?></span></a>
						<a data-dg-invoice-save href="<?php echo esc_url( $download_url ); ?>"><?php echo wp_kses_post( self::icon( 'save' ) ); ?><span><?php esc_html_e( 'Save PDF', 'digitalogic' ); ?></span></a>
						<button type="button" data-dg-share><?php echo wp_kses_post( self::icon( 'share' ) ); ?><span><?php esc_html_e( 'Share order or invoice', 'digitalogic' ); ?></span></button>
						<button type="button" data-dg-copy="<?php echo esc_attr( $order_number ); ?>"><?php echo wp_kses_post( self::icon( 'hash' ) ); ?><span><?php esc_html_e( 'Copy order number', 'digitalogic' ); ?></span></button>
						<button type="button" data-dg-copy="<?php echo esc_url( $order_url ); ?>"><?php echo wp_kses_post( self::icon( 'link' ) ); ?><span><?php esc_html_e( 'Copy secure order link', 'digitalogic' ); ?></span></button>
					</div>
				</div>
			</div>
			<div class="dg-order-toast" data-dg-toast role="status" aria-live="polite"></div>
		</section>
		<?php
		if ( 'bacs' === (string) $order->get_payment_method() ) {
			self::render_bank_and_receipt( $order, $receipt );
		}
	}

	/**
	 * Render configured ingress accounts and customer receipt form.
	 *
	 * @param WC_Order $order   Authorized order.
	 * @param array    $receipt Receipt presentation state.
	 */
	private static function render_bank_and_receipt( $order, array $receipt ): void {
		$accounts = self::get_bank_accounts();
		?>
		<section class="dg-bank-payment" aria-labelledby="dg-bank-payment-title">
			<div class="dg-bank-payment__heading">
				<div><span class="dg-bank-payment__kicker"><?php echo wp_kses_post( self::icon( 'card' ) ); ?><?php esc_html_e( 'Card-to-card payment', 'digitalogic' ); ?></span><h2 id="dg-bank-payment-title"><?php esc_html_e( 'Bank transfer details', 'digitalogic' ); ?></h2></div>
				<span class="dg-receipt-status dg-receipt-status--<?php echo esc_attr( $receipt['status'] ); ?>"><?php echo esc_html( $receipt['label'] ); ?></span>
			</div>
			<?php if ( $accounts ) : ?>
				<p class="dg-bank-payment__lead"><?php esc_html_e( 'Transfer the exact order amount to one of the cards below, then submit the receipt for review.', 'digitalogic' ); ?></p>
				<div class="dg-bank-cards">
					<?php foreach ( $accounts as $index => $account ) : ?>
						<article class="dg-bank-card" style="--dg-card-accent:<?php echo esc_attr( $account['accent'] ); ?>">
							<div class="dg-bank-card__glow" aria-hidden="true"></div>
							<header><span class="dg-bank-card__mark"><?php echo esc_html( self::bank_initials( $account['bank_name'] ) ); ?></span><span><?php echo esc_html( $account['bank_name'] ); ?></span><?php echo wp_kses_post( self::icon( 'contactless' ) ); ?></header>
							<div class="dg-bank-card__chip" aria-hidden="true"></div>
							<div class="dg-bank-card__number" dir="ltr"><?php echo esc_html( self::group_card_number( $account['card_number'] ) ); ?></div>
							<footer><div><small><?php esc_html_e( 'Account holder', 'digitalogic' ); ?></small><strong><?php echo esc_html( $account['account_holder'] ); ?></strong></div><button type="button" data-dg-copy="<?php echo esc_attr( $account['card_number'] ); ?>"><?php echo wp_kses_post( self::icon( 'copy' ) ); ?><span><?php esc_html_e( 'Copy card number', 'digitalogic' ); ?></span></button></footer>
							<?php
							if ( $account['iban'] || $account['account_number'] ) :
								?>
								<div class="dg-bank-card__details">
								<?php
								if ( $account['iban'] ) :
									?>
								<span dir="ltr">IBAN: <?php echo esc_html( $account['iban'] ); ?></span><?php endif; ?>
								<?php
								if ( $account['account_number'] ) :
									?>
	<span><?php esc_html_e( 'Account:', 'digitalogic' ); ?> <bdi><?php echo esc_html( $account['account_number'] ); ?></bdi></span><?php endif; ?></div><?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="dg-bank-payment__unavailable"><?php echo wp_kses_post( self::icon( 'info' ) ); ?><div><strong><?php esc_html_e( 'Bank details are being completed', 'digitalogic' ); ?></strong><p><?php esc_html_e( 'Please contact sales before making a transfer. No unverified card number is displayed.', 'digitalogic' ); ?></p></div></div>
			<?php endif; ?>

			<?php self::render_receipt_form( $order, $receipt, ! empty( $accounts ) ); ?>
		</section>
		<?php
	}

	/**
	 * Render upload fields and existing status.
	 *
	 * @param WC_Order $order          Authorized order.
	 * @param array    $receipt        Receipt presentation state.
	 * @param bool     $accounts_ready Whether verified bank details are published.
	 */
	private static function render_receipt_form( $order, array $receipt, bool $accounts_ready ): void {
		$order_id = (int) $order->get_id();
		?>
		<div class="dg-receipt-panel">
			<div class="dg-receipt-panel__intro"><span class="dg-receipt-panel__icon"><?php echo wp_kses_post( self::icon( 'receipt' ) ); ?></span><div><h3><?php esc_html_e( 'Submit payment receipt', 'digitalogic' ); ?></h3><p><?php esc_html_e( 'JPEG, PNG, WebP, or PDF up to 5 MB. The file is stored outside the public media library.', 'digitalogic' ); ?></p></div></div>
			<?php
			if ( $receipt['submitted_at'] ) :
				?>
				<?php /* translators: 1: receipt filename, 2: submission date and time. */ ?>
				<div class="dg-receipt-panel__existing"><?php echo wp_kses_post( self::icon( 'check' ) ); ?><span><?php printf( esc_html__( 'Receipt %1$s was submitted on %2$s.', 'digitalogic' ), esc_html( $receipt['name'] ), esc_html( $receipt['submitted_at'] ) ); ?></span></div><?php endif; ?>
			<form class="dg-receipt-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data" data-dg-receipt-form>
				<input type="hidden" name="action" value="digitalogic_submit_payment_receipt">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
				<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">
				<?php wp_nonce_field( 'digitalogic_receipt_' . $order_id, 'digitalogic_receipt_nonce' ); ?>
				<label><span><?php esc_html_e( 'Tracking or reference code', 'digitalogic' ); ?></span><input type="text" name="receipt_reference" inputmode="numeric" maxlength="64" value="<?php echo esc_attr( $receipt['reference'] ); ?>" required autocomplete="off"></label>
				<label class="dg-receipt-form__file"><span><?php esc_html_e( 'Receipt file', 'digitalogic' ); ?></span><input type="file" name="receipt_file" accept="image/jpeg,image/png,image/webp,application/pdf" <?php echo $receipt['has_file'] ? '' : 'required'; ?>><small data-dg-file-name><?php echo $receipt['has_file'] ? esc_html( $receipt['name'] ) : esc_html__( 'Choose or drop a file here', 'digitalogic' ); ?></small></label>
				<label class="dg-receipt-form__note"><span><?php esc_html_e( 'Optional note', 'digitalogic' ); ?></span><textarea name="receipt_note" rows="3" maxlength="500"><?php echo esc_textarea( $receipt['note'] ); ?></textarea></label>
				<button class="dg-receipt-form__submit" type="submit" <?php disabled( ! $accounts_ready ); ?>><?php echo wp_kses_post( self::icon( 'upload' ) ); ?><span><?php echo $receipt['has_file'] ? esc_html__( 'Replace receipt', 'digitalogic' ) : esc_html__( 'Submit receipt', 'digitalogic' ); ?></span></button>
				<?php
				if ( ! $accounts_ready ) :
					?>
					<p class="dg-receipt-form__disabled"><?php esc_html_e( 'Receipt submission becomes available after an administrator publishes verified bank details.', 'digitalogic' ); ?></p><?php endif; ?>
			</form>
		</div>
		<?php
	}

	/** Register the WooCommerce submenu. */
	public static function register_admin_page(): void {
		add_submenu_page( 'woocommerce', __( 'Card-to-card accounts', 'digitalogic' ), __( 'Card-to-card', 'digitalogic' ), 'manage_woocommerce', 'digitalogic-bank-ingress', array( self::class, 'render_admin_page' ) );
	}

	/**
	 * Load repeater assets on the dedicated settings screen.
	 *
	 * @param string $hook_suffix Current admin page suffix.
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_digitalogic-bank-ingress' !== $hook_suffix ) {
			return;
		}
		$style  = DIGITALOGIC_PLUGIN_DIR . 'assets/css/order-payment-experience.css';
		$script = DIGITALOGIC_PLUGIN_DIR . 'assets/js/bank-ingress-admin.js';
		if ( is_readable( $style ) ) {
			wp_enqueue_style( self::FRONT_STYLE, DIGITALOGIC_PLUGIN_URL . 'assets/css/order-payment-experience.css', array(), (string) filemtime( $style ) );
		}
		if ( is_readable( $script ) ) {
			wp_enqueue_script( self::ADMIN_SCRIPT, DIGITALOGIC_PLUGIN_URL . 'assets/js/bank-ingress-admin.js', array(), (string) filemtime( $script ), true );
		}
	}

	/** Render bank ingress settings without exposing secrets in source control. */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$accounts = self::get_bank_accounts( false );
		?>
		<div class="wrap dg-bank-admin" dir="rtl"><h1><?php esc_html_e( 'Card-to-card payment accounts', 'digitalogic' ); ?></h1><p><?php esc_html_e( 'Only verified ingress accounts entered here are shown to customers. Never enter PIN, CVV2, expiry date, or one-time passwords.', 'digitalogic' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post"><input type="hidden" name="action" value="digitalogic_save_bank_ingress"><?php wp_nonce_field( 'digitalogic_save_bank_ingress', 'digitalogic_bank_nonce' ); ?>
				<div data-dg-bank-rows>
				<?php
				foreach ( $accounts as $index => $account ) {
					self::render_admin_account_row( $index, $account ); }
				?>
				</div>
				<button class="button" type="button" data-dg-add-bank><?php esc_html_e( 'Add bank account', 'digitalogic' ); ?></button> <button class="button button-primary" type="submit"><?php esc_html_e( 'Save verified accounts', 'digitalogic' ); ?></button>
			</form>
			<template data-dg-bank-template>
			<?php
			self::render_admin_account_row(
				'__INDEX__',
				array(
					'enabled'        => true,
					'bank_name'      => '',
					'account_holder' => '',
					'card_number'    => '',
					'iban'           => '',
					'account_number' => '',
					'accent'         => '#0d4f86',
				)
			);
			?>
											</template>
		</div>
		<?php
	}

	/**
	 * Render one repeatable admin account row.
	 *
	 * @param int|string $index   Stable row index or template token.
	 * @param array      $account Sanitized account values.
	 */
	private static function render_admin_account_row( $index, array $account ): void {
		$prefix = 'accounts[' . $index . ']';
		?>
		<fieldset class="dg-bank-admin__row"><legend><?php esc_html_e( 'Ingress bank account', 'digitalogic' ); ?></legend><label class="dg-bank-admin__enabled"><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[enabled]" value="1" <?php checked( ! empty( $account['enabled'] ) ); ?>> <?php esc_html_e( 'Visible to customers', 'digitalogic' ); ?></label><div class="dg-bank-admin__grid">
			<label><span><?php esc_html_e( 'Bank name', 'digitalogic' ); ?></span><input name="<?php echo esc_attr( $prefix ); ?>[bank_name]" value="<?php echo esc_attr( $account['bank_name'] ); ?>" required></label>
			<label><span><?php esc_html_e( 'Account holder', 'digitalogic' ); ?></span><input name="<?php echo esc_attr( $prefix ); ?>[account_holder]" value="<?php echo esc_attr( $account['account_holder'] ); ?>" required></label>
			<label><span><?php esc_html_e( '16-digit card number', 'digitalogic' ); ?></span><input name="<?php echo esc_attr( $prefix ); ?>[card_number]" value="<?php echo esc_attr( self::group_card_number( $account['card_number'] ) ); ?>" inputmode="numeric" dir="ltr" required></label>
			<label><span>IBAN</span><input name="<?php echo esc_attr( $prefix ); ?>[iban]" value="<?php echo esc_attr( $account['iban'] ); ?>" dir="ltr" placeholder="IR000000000000000000000000"></label>
			<label><span><?php esc_html_e( 'Account number (optional)', 'digitalogic' ); ?></span><input name="<?php echo esc_attr( $prefix ); ?>[account_number]" value="<?php echo esc_attr( $account['account_number'] ); ?>" dir="ltr"></label>
			<label><span><?php esc_html_e( 'Card accent', 'digitalogic' ); ?></span><input type="color" name="<?php echo esc_attr( $prefix ); ?>[accent]" value="<?php echo esc_attr( $account['accent'] ); ?>"></label>
		</div><button class="button-link-delete" type="button" data-dg-remove-bank><?php esc_html_e( 'Remove account', 'digitalogic' ); ?></button></fieldset>
		<?php
	}

	/** Validate and save administrator-entered accounts. */
	public static function save_bank_accounts(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage payment accounts.', 'digitalogic' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'digitalogic_save_bank_ingress', 'digitalogic_bank_nonce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nested account contract is sanitized field-by-field below.
		$raw    = isset( $_POST['accounts'] ) && is_array( $_POST['accounts'] ) ? wp_unslash( $_POST['accounts'] ) : array();
		$result = self::sanitize_accounts( $raw );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}
		update_option( self::OPTION_KEY, $result, false );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=digitalogic-bank-ingress' ) ) );
		exit;
	}

	/**
	 * Sanitize the repeatable account contract.
	 *
	 * @param array $accounts Submitted account rows.
	 * @return array|WP_Error Sanitized rows or validation error.
	 */
	public static function sanitize_accounts( array $accounts ) {
		$clean = array();
		foreach ( array_slice( $accounts, 0, 8 ) as $account ) {
			if ( ! is_array( $account ) ) {
				continue;
			}
			$bank_name      = sanitize_text_field( $account['bank_name'] ?? '' );
			$holder         = sanitize_text_field( $account['account_holder'] ?? '' );
			$card           = self::ascii_digits( $account['card_number'] ?? '' );
			$iban           = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( $account['iban'] ?? '' ) ) );
			$account_number = sanitize_text_field( $account['account_number'] ?? '' );
			$accent_raw     = (string) ( $account['accent'] ?? '' );
			$accent         = preg_match( '/^#[0-9a-fA-F]{6}$/', $accent_raw ) ? strtolower( $accent_raw ) : '#0d4f86';
			if ( '' === $bank_name && '' === $holder && '' === $card ) {
				continue;
			}
			if ( '' === $bank_name || '' === $holder || ! self::is_valid_card_number( $card ) ) {
				return new WP_Error( 'digitalogic_bank_account_invalid', __( 'Each bank account requires a bank name, account holder, and valid 16-digit card number.', 'digitalogic' ) );
			}
			if ( '' !== $iban && ! self::is_valid_iban( $iban ) ) {
				return new WP_Error( 'digitalogic_bank_iban_invalid', __( 'Enter a valid Iranian IBAN beginning with IR.', 'digitalogic' ) );
			}
			$clean[] = array(
				'enabled'        => ! empty( $account['enabled'] ),
				'bank_name'      => $bank_name,
				'account_holder' => $holder,
				'card_number'    => $card,
				'iban'           => $iban,
				'account_number' => $account_number,
				'accent'         => $accent,
			);
		}
		return $clean;
	}

	/**
	 * Validate a 16-digit card number with the checksum used by Iranian bank cards.
	 *
	 * @param string $number Normalized card number.
	 */
	private static function is_valid_card_number( string $number ): bool {
		if ( ! preg_match( '/^\d{16}$/', $number ) || preg_match( '/^(\d)\1{15}$/', $number ) ) {
			return false;
		}

		$sum = 0;
		for ( $index = 0; $index < 16; $index++ ) {
			$digit = (int) $number[ $index ];
			if ( 0 === $index % 2 ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Validate the Iranian IBAN structure and ISO 13616 modulo-97 checksum.
	 *
	 * @param string $iban Normalized Iranian IBAN.
	 */
	private static function is_valid_iban( string $iban ): bool {
		if ( ! preg_match( '/^IR\d{24}$/', $iban ) ) {
			return false;
		}

		$numeric   = substr( $iban, 4 ) . '1827' . substr( $iban, 2, 2 );
		$remainder = 0;
		foreach ( str_split( $numeric ) as $digit ) {
			$remainder = ( ( $remainder * 10 ) + (int) $digit ) % 97;
		}

		return 1 === $remainder;
	}

	/**
	 * Return enabled or all configured accounts.
	 *
	 * @param bool $enabled_only Whether disabled rows should be omitted.
	 * @return array Sanitized account rows.
	 */
	public static function get_bank_accounts( bool $enabled_only = true ): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$result = self::sanitize_accounts( $stored );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		return $enabled_only ? array_values( array_filter( $result, static fn( $account ) => ! empty( $account['enabled'] ) ) ) : $result;
	}

	/** Receive a protected customer payment receipt. */
	public static function submit_receipt(): void {
		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! self::can_access_order( $order, sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) ) ) ) {
			wp_die( esc_html__( 'This order could not be verified.', 'digitalogic' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'digitalogic_receipt_' . $order_id, 'digitalogic_receipt_nonce' );
		if ( ! self::get_bank_accounts() ) {
			self::receipt_failure( $order, __( 'Verified bank details are not available yet.', 'digitalogic' ) );
		}
		$reference = sanitize_text_field( wp_unslash( $_POST['receipt_reference'] ?? '' ) );
		$note      = sanitize_textarea_field( wp_unslash( $_POST['receipt_note'] ?? '' ) );
		if ( '' === $reference ) {
			self::receipt_failure( $order, __( 'Enter the payment tracking or reference code.', 'digitalogic' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload metadata is validated against size, extension, MIME, and upload provenance before use.
		$file         = isset( $_FILES['receipt_file'] ) && is_array( $_FILES['receipt_file'] ) ? $_FILES['receipt_file'] : array();
		$has_existing = is_file( (string) $order->get_meta( self::META_FILE, true ) );
		if ( empty( $file['tmp_name'] ) && ! $has_existing ) {
			self::receipt_failure( $order, __( 'Choose a receipt file.', 'digitalogic' ) );
		}
		if ( ! empty( $file['tmp_name'] ) ) {
			self::store_receipt_file( $order, $file );
		}
		$order->update_meta_data( self::META_REFERENCE, $reference );
		$order->update_meta_data( self::META_NOTE, $note );
		$order->update_meta_data( self::META_STATUS, 'submitted' );
		$order->update_meta_data( self::META_SUBMITTED, gmdate( 'c' ) );
		$order->save();
		self::notify_receipt_submitted( $order );
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Your payment receipt was submitted and is awaiting review.', 'digitalogic' ), 'success' );
		}
		wp_safe_redirect( self::order_url( $order ) );
		exit;
	}

	/**
	 * Validate and move a receipt outside the public web root.
	 *
	 * @param WC_Order $order Authorized order.
	 * @param array    $file  PHP upload array.
	 */
	private static function store_receipt_file( $order, array $file ): void {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_OK );
		$size  = (int) ( $file['size'] ?? 0 );
		if ( UPLOAD_ERR_OK !== $error || $size < 1 || $size > self::RECEIPT_MAX_SIZE ) {
			self::receipt_failure( $order, __( 'The receipt must be a readable file no larger than 5 MB.', 'digitalogic' ) );
		}
		$name    = sanitize_file_name( wp_unslash( $file['name'] ?? '' ) );
		$allowed = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
		);
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $name, $allowed );
		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			self::receipt_failure( $order, __( 'Only JPEG, PNG, WebP, and PDF receipts are accepted.', 'digitalogic' ) );
		}
		$directory = self::receipt_directory();
		if ( ! wp_mkdir_p( $directory ) ) {
			self::receipt_failure( $order, __( 'The secure receipt storage is unavailable.', 'digitalogic' ) );
		}
		$target = trailingslashit( $directory ) . wp_generate_uuid4() . '.' . $checked['ext'];
		if ( ! is_uploaded_file( $file['tmp_name'] ) || ! move_uploaded_file( $file['tmp_name'], $target ) ) {
			self::receipt_failure( $order, __( 'The receipt could not be stored securely.', 'digitalogic' ) );
		}
		$previous = (string) $order->get_meta( self::META_FILE, true );
		if ( $previous && $previous !== $target && is_file( $previous ) && 0 === strpos( wp_normalize_path( $previous ), wp_normalize_path( trailingslashit( $directory ) ) ) ) {
			wp_delete_file( $previous );
		}
		$order->update_meta_data( self::META_FILE, $target );
		$order->update_meta_data( self::META_NAME, $name );
	}

	/**
	 * Render receipt data in the administrator order screen.
	 *
	 * @param WC_Order $order Order being edited.
	 */
	public static function render_admin_receipt( $order ): void {
		if ( ! $order || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$receipt = self::receipt_state( $order );
		if ( ! $receipt['has_file'] ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=digitalogic_download_payment_receipt&order_id=' . (int) $order->get_id() ), 'digitalogic_download_receipt_' . (int) $order->get_id() );
		wp_nonce_field( 'digitalogic_receipt_status_' . (int) $order->get_id(), 'digitalogic_receipt_status_nonce' );
		?>
		<div class="order_data_column"><h3><?php esc_html_e( 'Payment receipt', 'digitalogic' ); ?></h3><p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Download protected receipt', 'digitalogic' ); ?></a></p><p><strong><?php esc_html_e( 'Reference:', 'digitalogic' ); ?></strong> <?php echo esc_html( $receipt['reference'] ); ?></p><p><label for="digitalogic_receipt_status"><?php esc_html_e( 'Review status', 'digitalogic' ); ?></label><select id="digitalogic_receipt_status" name="digitalogic_receipt_status">
		<?php
		foreach ( self::receipt_labels() as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $receipt['status'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p></div>
		<?php
	}

	/**
	 * Save administrator review state.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order object when supplied by WooCommerce.
	 */
	public static function save_admin_receipt_status( $order_id, $order = null ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || empty( $_POST['digitalogic_receipt_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['digitalogic_receipt_status_nonce'] ) ), 'digitalogic_receipt_status_' . absint( $order_id ) ) ) {
			return;
		}
		$order  = $order ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false );
		$status = sanitize_key( wp_unslash( $_POST['digitalogic_receipt_status'] ?? '' ) );
		if ( $order && isset( self::receipt_labels()[ $status ] ) ) {
			$order->update_meta_data( self::META_STATUS, $status );
			$order->save();
		}
	}

	/** Stream a receipt only to a WooCommerce manager. */
	public static function download_receipt(): void {
		$order_id = absint( $_GET['order_id'] ?? 0 );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'digitalogic_download_receipt_' . $order_id ) ) {
			wp_die( esc_html__( 'You are not allowed to download this receipt.', 'digitalogic' ), '', array( 'response' => 403 ) );
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$path  = $order ? (string) $order->get_meta( self::META_FILE, true ) : '';
		if ( ! $path || ! is_file( $path ) || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( trailingslashit( self::receipt_directory() ) ) ) ) {
			wp_die( esc_html__( 'The receipt file is unavailable.', 'digitalogic' ), '', array( 'response' => 404 ) );
		}
		$name = sanitize_file_name( (string) $order->get_meta( self::META_NAME, true ) );
		$name = $name ? $name : 'payment-receipt';
		$type = wp_check_filetype( $name );
		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Protected binary streaming cannot use WP_Filesystem.
		exit;
	}

	/**
	 * Determine customer/order-manager access.
	 *
	 * @param WC_Order $order        Candidate order.
	 * @param string   $provided_key Explicit key from a protected form submission.
	 */
	public static function can_access_order( $order, string $provided_key = '' ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( $user_id && (int) $order->get_user_id() === $user_id ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The WooCommerce order key is the authorization token on the order-received page.
		$query_key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		$key       = $provided_key ? $provided_key : $query_key;
		return '' !== $key && hash_equals( (string) $order->get_order_key(), $key );
	}

	/**
	 * Return sanitized view-ready receipt state.
	 *
	 * @param WC_Order $order Order containing protected receipt metadata.
	 */
	private static function receipt_state( $order ): array {
		$status    = sanitize_key( (string) $order->get_meta( self::META_STATUS, true ) );
		$labels    = self::receipt_labels();
		$status    = isset( $labels[ $status ] ) ? $status : 'not-submitted';
		$submitted = (string) $order->get_meta( self::META_SUBMITTED, true );
		return array(
			'status'       => $status,
			'label'        => $labels[ $status ],
			'has_file'     => is_file( (string) $order->get_meta( self::META_FILE, true ) ),
			'name'         => sanitize_file_name( (string) $order->get_meta( self::META_NAME, true ) ),
			'reference'    => sanitize_text_field( (string) $order->get_meta( self::META_REFERENCE, true ) ),
			'note'         => sanitize_textarea_field( (string) $order->get_meta( self::META_NOTE, true ) ),
			'submitted_at' => $submitted ? wp_date( 'Y/m/d H:i', strtotime( $submitted ) ) : '',
		);
	}

	/** Receipt workflow labels. */
	private static function receipt_labels(): array {
		return array(
			'not-submitted' => __( 'Receipt not submitted', 'digitalogic' ),
			'submitted'     => __( 'Awaiting review', 'digitalogic' ),
			'approved'      => __( 'Receipt approved', 'digitalogic' ),
			'rejected'      => __( 'Receipt needs correction', 'digitalogic' ),
		);
	}

	/**
	 * Notify existing automation without customer PII or file paths.
	 *
	 * @param WC_Order $order Order whose receipt was submitted.
	 */
	private static function notify_receipt_submitted( $order ): void {
		if ( ! class_exists( 'Digitalogic_Webhooks' ) ) {
			return;
		}
		Digitalogic_Webhooks::instance()->manual_trigger(
			'order.receipt.submitted',
			array(
				'id'              => (int) $order->get_id(),
				'number'          => (string) $order->get_order_number(),
				'status'          => 'submitted',
				'category'        => 'commerce',
				'severity'        => 'info',
				'notify_channels' => array( 'telegram', 'ntfy' ),
				'audience'        => array( 'shokri' ),
			)
		);
	}

	/**
	 * Redirect back with a WooCommerce error.
	 *
	 * @param WC_Order $order   Authorized order.
	 * @param string   $message Safe customer-facing message.
	 */
	private static function receipt_failure( $order, string $message ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
		wp_safe_redirect( self::order_url( $order ) );
		exit;
	}

	/** Secure receipt directory outside the WordPress document root. */
	private static function receipt_directory(): string {
		return apply_filters( 'digitalogic_receipt_directory', trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . 'digitalogic-private/order-receipts' );
	}

	/**
	 * Customer-safe canonical order URL.
	 *
	 * @param WC_Order $order Authorized order.
	 */
	private static function order_url( $order ): string {
		if ( method_exists( $order, 'get_checkout_order_received_url' ) ) {
			return (string) $order->get_checkout_order_received_url();
		}
		return home_url( '/' );
	}

	/**
	 * Normalize Persian/Arabic numerals and strip separators.
	 *
	 * @param mixed $value Input number.
	 */
	private static function ascii_digits( $value ): string {
		$value = strtr(
			(string) $value,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
		return preg_replace( '/\D+/', '', $value );
	}

	/**
	 * Group a 16-digit card number for legibility.
	 *
	 * @param string $number Normalized or presentation card number.
	 */
	public static function group_card_number( string $number ): string {
		$digits = self::ascii_digits( $number );
		return trim( chunk_split( $digits, 4, ' ' ) );
	}

	/**
	 * Generate a restrained bank monogram when no verified logo asset exists.
	 *
	 * @param string $bank_name Bank display name.
	 */
	private static function bank_initials( string $bank_name ): string {
		$words    = preg_split( '/\s+/u', trim( $bank_name ) );
		$initials = '';
		foreach ( array_slice( array_filter( $words ), 0, 2 ) as $word ) {
			$initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1, 'UTF-8' ) : substr( $word, 0, 1 );
		}
		return $initials ? $initials : 'D';
	}

	/**
	 * Return a CSS-masked icon container with no external asset dependency.
	 *
	 * @param string $name Icon name.
	 */
	private static function icon( string $name ): string {
		$allowed = array( 'check', 'download', 'more', 'chevron', 'print', 'save', 'share', 'hash', 'link', 'card', 'contactless', 'copy', 'info', 'receipt', 'upload' );
		$name    = in_array( $name, $allowed, true ) ? $name : 'info';
		return '<span class="dg-icon dg-icon--' . esc_attr( $name ) . '" aria-hidden="true"></span>';
	}
}
