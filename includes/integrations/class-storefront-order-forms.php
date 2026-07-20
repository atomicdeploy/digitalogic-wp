<?php
/**
 * Public order-request forms for foreign sourcing and PCB production.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digitalogic_Storefront_Order_Forms {

	private const POST_TYPE = 'digitalogic_request';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_request_type' ) );
		add_shortcode( 'digitalogic_foreign_order_form', array( $this, 'render_foreign_form' ) );
		add_shortcode( 'digitalogic_pcb_order_form', array( $this, 'render_pcb_form' ) );
		add_action( 'admin_post_digitalogic_submit_request', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_nopriv_digitalogic_submit_request', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_digitalogic_download_request_file', array( $this, 'download_request_file' ) );
		add_action( 'before_delete_post', array( $this, 'delete_private_file' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_request_status' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Register private, admin-manageable customer requests.
	 */
	public function register_request_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => 'درخواست‌های فروشگاه',
					'singular_name' => 'درخواست فروشگاه',
					'menu_name'     => 'درخواست‌های مشتری',
					'all_items'     => 'همه درخواست‌ها',
					'edit_item'     => 'بررسی درخواست',
					'view_item'     => 'دیدن درخواست',
					'search_items'  => 'جست‌وجوی درخواست‌ها',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-clipboard',
				'supports'     => array( 'title', 'author' ),
				'map_meta_cap' => true,
				'capabilities' => array(
					'edit_post'              => 'manage_woocommerce',
					'read_post'              => 'manage_woocommerce',
					'delete_post'            => 'manage_woocommerce',
					'edit_posts'             => 'manage_woocommerce',
					'edit_others_posts'      => 'manage_woocommerce',
					'publish_posts'          => 'manage_woocommerce',
					'read_private_posts'     => 'manage_woocommerce',
					'delete_posts'           => 'manage_woocommerce',
					'delete_private_posts'   => 'manage_woocommerce',
					'delete_published_posts' => 'manage_woocommerce',
					'delete_others_posts'    => 'manage_woocommerce',
					'edit_private_posts'     => 'manage_woocommerce',
					'edit_published_posts'   => 'manage_woocommerce',
					'create_posts'           => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * Render foreign sourcing form.
	 *
	 * @return string
	 */
	public function render_foreign_form() {
		$this->enqueue_assets();
		$prefill = $this->prefill_contact();
		$product = $this->prefill_product();

		ob_start();
		?>
		<section class="dgl-request dgl-request--foreign" dir="rtl" aria-labelledby="dgl-request-title">
			<?php echo $this->request_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<header class="dgl-request-hero">
				<div>
					<span>تأمین مستقیم قطعه</span>
					<h1 id="dgl-request-title">قطعه‌ات رو از چین سفارش بده؛ بقیه‌ش با ما</h1>
					<p>لینک، پارت‌نامبر یا فایل BOM رو بده. قیمت، زمان تأمین و هزینه ارسال رو شفاف برات درمیاریم.</p>
				</div>
				<ul><li>استعلام بدون هزینه</li><li>خرید تکی یا تیراژ</li><li>پیگیری با کد درخواست</li></ul>
			</header>

			<form class="dgl-request-form" data-dgl-request-form="foreign" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
				<?php echo $this->hidden_fields( 'foreign' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->contact_fields( $prefill ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<fieldset class="dgl-form-section">
					<legend><b>۲</b><span>چی لازم داری؟</span><small>تا ۱۰ قلم رو دستی وارد کن؛ برای لیست بلندتر فایل بفرست.</small></legend>
					<div class="dgl-order-items" data-dgl-items>
						<?php echo $this->foreign_item_row( 0, $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<button class="dgl-add-row" type="button" data-dgl-add-row>+ یه قطعه دیگه</button>
					<template data-dgl-item-template><?php echo $this->foreign_item_row( '__INDEX__', array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></template>
				</fieldset>

				<fieldset class="dgl-form-section">
					<legend><b>۳</b><span>فایل و جزئیات سفارش</span><small>BOM، اکسل یا PDF داری؟ همین‌جا بفرست.</small></legend>
					<div class="dgl-form-grid">
						<label class="dgl-file-field dgl-span-2">
							<span>فایل BOM یا لیست خرید <small>اختیاری، حداکثر ۲۰ مگابایت</small></span>
							<input type="file" name="dgl_request_file" accept=".xlsx,.xls,.csv,.pdf,.zip">
							<strong data-dgl-file-name>فایل رو بکش اینجا یا انتخابش کن</strong>
						</label>
						<label><span>روش ارسال ترجیحی</span><select name="shipping_speed"><option value="best">بهترین ترکیب قیمت و زمان</option><option value="economy">اقتصادی؛ عجله ندارم</option><option value="express">سریع؛ زمان مهم‌تره</option></select></label>
						<label><span>مهلت تقریبی تحویل</span><input type="text" name="target_date" placeholder="مثلاً تا یک ماه آینده"></label>
						<label><span>بودجه یا ارز مرجع</span><input type="text" name="budget" placeholder="مثلاً ۵۰۰ دلار یا بدون محدودیت"></label>
						<label><span>فاکتور رسمی</span><select name="invoice"><option value="no">لازم ندارم</option><option value="yes">لازم دارم</option><option value="unsure">بعداً هماهنگ کنیم</option></select></label>
						<label class="dgl-span-2"><span>توضیحات تکمیلی</span><textarea name="notes" rows="4" placeholder="برند موردنظر، جایگزین قابل‌قبول، شرایط بسته‌بندی یا هر نکته‌ای که مهمه..."></textarea></label>
					</div>
				</fieldset>

				<?php echo $this->form_footer( 'ثبت درخواست تأمین قطعه' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</form>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render PCB production form.
	 *
	 * @return string
	 */
	public function render_pcb_form() {
		$this->enqueue_assets();
		$prefill = $this->prefill_contact();

		ob_start();
		?>
		<section class="dgl-request dgl-request--pcb" dir="rtl" aria-labelledby="dgl-request-title">
			<?php echo $this->request_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<header class="dgl-request-hero">
				<div>
					<span>ساخت PCB دو لایه و چهار لایه</span>
					<h1 id="dgl-request-title">Gerber رو بفرست؛ برد تمیز تحویل بگیر</h1>
					<p>مشخصات برد رو دقیق وارد کن تا قیمت و زمان ساخت بدون رفت‌وبرگشت اضافه برات آماده بشه.</p>
				</div>
				<ul><li>نمونه‌سازی و تیراژ</li><li>دو لایه و چهار لایه</li><li>بررسی فایل قبل از تولید</li></ul>
			</header>

			<form class="dgl-request-form" data-dgl-request-form="pcb" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
				<?php echo $this->hidden_fields( 'pcb' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $this->contact_fields( $prefill ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<fieldset class="dgl-form-section">
					<legend><b>۲</b><span>مشخصات برد</span><small>همون چیزهایی که روی قیمت و کیفیت اثر می‌ذاره.</small></legend>
					<div class="dgl-form-grid dgl-form-grid--3">
						<label><span>اسم پروژه یا برد <em>*</em></span><input type="text" name="project_name" required maxlength="120" placeholder="مثلاً کنترلر گلخانه"></label>
						<label><span>تعداد <em>*</em></span><input type="number" name="board_quantity" required min="5" step="5" value="5"></label>
						<label><span>تعداد لایه <em>*</em></span><select name="layers" required><option value="2">دو لایه (2L)</option><option value="4">چهار لایه (4L)</option></select></label>
						<label><span>طول برد (mm) <em>*</em></span><input type="number" name="board_length" required min="1" max="600" step="0.01" inputmode="decimal"></label>
						<label><span>عرض برد (mm) <em>*</em></span><input type="number" name="board_width" required min="1" max="600" step="0.01" inputmode="decimal"></label>
						<label><span>ضخامت برد</span><select name="thickness"><option value="1.6">1.6 mm</option><option value="1.2">1.2 mm</option><option value="1.0">1.0 mm</option><option value="0.8">0.8 mm</option><option value="2.0">2.0 mm</option></select></label>
						<label><span>ضخامت مس</span><select name="copper"><option value="1oz">1 oz</option><option value="2oz">2 oz</option></select></label>
						<label><span>رنگ سولدرمسک</span><select name="soldermask"><option value="green">سبز</option><option value="black">مشکی</option><option value="blue">آبی</option><option value="red">قرمز</option><option value="white">سفید</option><option value="yellow">زرد</option></select></label>
						<label><span>رنگ سیلک</span><select name="silkscreen"><option value="white">سفید</option><option value="black">مشکی</option></select></label>
						<label><span>فینیش سطح</span><select name="surface_finish"><option value="hasl">HASL</option><option value="lead-free-hasl">Lead-free HASL</option><option value="enig">ENIG</option></select></label>
						<label><span>پنلایز</span><select name="panelization"><option value="no">نه، برد تکی</option><option value="yes">بله، پنل لازم دارم</option><option value="review">بررسی و پیشنهاد شما</option></select></label>
						<label><span>استنسیل</span><select name="stencil"><option value="no">لازم ندارم</option><option value="yes">لازم دارم</option><option value="unsure">مطمئن نیستم</option></select></label>
					</div>
				</fieldset>

				<fieldset class="dgl-form-section">
					<legend><b>۳</b><span>فایل ساخت و توضیحات</span><small>Gerber رو ZIP کن و بفرست؛ قبل تولید بررسیش می‌کنیم.</small></legend>
					<div class="dgl-form-grid">
						<label class="dgl-file-field dgl-span-2">
							<span>فایل Gerber <em>*</em> <small>ZIP، RAR یا 7Z — حداکثر ۲۰ مگابایت</small></span>
							<input type="file" name="dgl_request_file" accept=".zip,.rar,.7z" required>
							<strong data-dgl-file-name>فایل فشرده Gerber رو انتخاب کن</strong>
						</label>
						<label><span>زمان تحویل ترجیحی</span><select name="delivery_speed"><option value="standard">استاندارد</option><option value="fast">سریع‌تر، حتی با هزینه بیشتر</option><option value="economy">اقتصادی، زمان مهم نیست</option></select></label>
						<label><span>مونتاژ قطعات</span><select name="assembly"><option value="no">فقط PCB خام</option><option value="quote">مونتاژ هم قیمت بدهید</option></select></label>
						<label class="dgl-span-2"><span>توضیحات فنی</span><textarea name="notes" rows="4" placeholder="امپدانس کنترل‌شده، castellated hole، تست الکتریکی، نوع پنل یا هر نکته مهم دیگه..."></textarea></label>
					</div>
				</fieldset>

				<?php echo $this->form_footer( 'ثبت سفارش PCB' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</form>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Enqueue shared form assets.
	 */
	private function enqueue_assets() {
		wp_enqueue_style(
			'digitalogic-storefront-forms',
			DIGITALOGIC_PLUGIN_URL . 'assets/css/storefront-forms.css',
			array(),
			DIGITALOGIC_VERSION
		);
		wp_enqueue_script(
			'digitalogic-storefront-forms',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/storefront-forms.js',
			array(),
			DIGITALOGIC_VERSION,
			true
		);
	}

	/**
	 * Render hidden action, type, nonce and honeypot fields.
	 *
	 * @param string $type Request type.
	 * @return string
	 */
	private function hidden_fields( $type ) {
		ob_start();
		?>
		<input type="hidden" name="action" value="digitalogic_submit_request">
		<input type="hidden" name="request_type" value="<?php echo esc_attr( $type ); ?>">
		<?php wp_nonce_field( 'digitalogic_submit_' . $type, 'digitalogic_request_nonce' ); ?>
		<label class="dgl-hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render common contact section.
	 *
	 * @param array $prefill Prefill values.
	 * @return string
	 */
	private function contact_fields( $prefill ) {
		ob_start();
		?>
		<fieldset class="dgl-form-section">
			<legend><b>۱</b><span>راه ارتباطی</span><small>برای قیمت و زمان تحویل باهات هماهنگ می‌شیم.</small></legend>
			<div class="dgl-form-grid">
				<label><span>نام و نام خانوادگی <em>*</em></span><input type="text" name="contact_name" value="<?php echo esc_attr( $prefill['name'] ); ?>" required maxlength="100" autocomplete="name"></label>
				<label><span>شماره موبایل <em>*</em></span><input type="tel" name="mobile" value="<?php echo esc_attr( $prefill['mobile'] ); ?>" required maxlength="20" inputmode="tel" autocomplete="tel" placeholder="09xxxxxxxxx"></label>
				<label><span>ایمیل</span><input type="email" name="email" value="<?php echo esc_attr( $prefill['email'] ); ?>" maxlength="150" autocomplete="email" placeholder="name@example.com"></label>
				<label><span>شرکت / مجموعه</span><input type="text" name="company" maxlength="120" autocomplete="organization" placeholder="اختیاری"></label>
			</div>
		</fieldset>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render one repeatable sourcing line.
	 *
	 * @param int|string $index Row index or template token.
	 * @param array      $values Prefill values.
	 * @return string
	 */
	private function foreign_item_row( $index, $values ) {
		$values = wp_parse_args(
			$values,
			array(
				'name'        => '',
				'part_number' => '',
				'url'         => '',
				'quantity'    => 1,
				'notes'       => '',
			)
		);

		ob_start();
		?>
		<div class="dgl-order-item" data-dgl-item>
			<span class="dgl-item-number" data-dgl-item-number><?php echo is_numeric( $index ) ? esc_html( (int) $index + 1 ) : '#'; ?></span>
			<label><span>نام قطعه</span><input type="text" name="items[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $values['name'] ); ?>" maxlength="180" placeholder="مثلاً ESP32-C3 Super Mini"></label>
			<label><span>پارت‌نامبر</span><input type="text" name="items[<?php echo esc_attr( $index ); ?>][part_number]" value="<?php echo esc_attr( $values['part_number'] ); ?>" maxlength="120" dir="ltr" placeholder="STM32F103C8T6"></label>
			<label class="dgl-item-url"><span>لینک محصول</span><input type="url" name="items[<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $values['url'] ); ?>" maxlength="500" dir="ltr" placeholder="https://..."></label>
			<label class="dgl-item-qty"><span>تعداد</span><input type="number" name="items[<?php echo esc_attr( $index ); ?>][quantity]" value="<?php echo esc_attr( $values['quantity'] ); ?>" min="1" step="1"></label>
			<label class="dgl-item-notes"><span>توضیح کوتاه</span><input type="text" name="items[<?php echo esc_attr( $index ); ?>][notes]" value="<?php echo esc_attr( $values['notes'] ); ?>" maxlength="250" placeholder="برند یا شرایط جایگزین"></label>
			<button type="button" class="dgl-remove-row" data-dgl-remove-row aria-label="حذف این قطعه">×</button>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render consent and submit footer.
	 *
	 * @param string $button_label Button label.
	 * @return string
	 */
	private function form_footer( $button_label ) {
		ob_start();
		?>
		<div class="dgl-form-submit">
			<label class="dgl-consent"><input type="checkbox" name="consent" value="yes" required><span>اطلاعات این فرم برای بررسی و پیگیری همین درخواست استفاده بشه. <em>*</em></span></label>
			<button type="submit"><span><?php echo esc_html( $button_label ); ?></span><small>بعدش یه کد پیگیری می‌گیری</small></button>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Prefill logged-in contact details.
	 *
	 * @return array
	 */
	private function prefill_contact() {
		$prefill = array( 'name' => '', 'mobile' => '', 'email' => '' );
		if ( ! is_user_logged_in() ) {
			return $prefill;
		}

		$user              = wp_get_current_user();
		$prefill['name']   = $user->display_name;
		$prefill['email']  = $user->user_email;
		$prefill['mobile'] = (string) get_user_meta( $user->ID, 'billing_phone', true );

		return $prefill;
	}

	/**
	 * Prefill a sourcing line from the table's request-price link.
	 *
	 * @return array
	 */
	private function prefill_product() {
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product    = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || 'publish' !== $product->get_status() ) {
			return array();
		}

		return array(
			'name'        => $product->get_name(),
			'part_number' => $product->get_sku(),
			'url'         => $product->get_permalink(),
			'quantity'    => 1,
		);
	}

	/**
	 * Handle and persist a public form submission.
	 */
	public function handle_submission() {
		$type     = isset( $_POST['request_type'] ) ? sanitize_key( wp_unslash( $_POST['request_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redirect = wp_get_referer() ?: home_url( '/' );

		if ( ! in_array( $type, array( 'foreign', 'pcb' ), true ) ) {
			$this->redirect_with_error( $redirect, 'invalid' );
		}
		$nonce = isset( $_POST['digitalogic_request_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['digitalogic_request_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'digitalogic_submit_' . $type ) ) {
			$this->redirect_with_error( $redirect, 'expired' );
		}
		if ( ! empty( $_POST['website'] ) ) {
			$this->redirect_with_success( $redirect, 'DLG-OK' );
		}

		$rate_key = 'dgl_req_' . md5( $type . '|' . $this->request_ip() );
		if ( get_transient( $rate_key ) ) {
			$this->redirect_with_error( $redirect, 'slow_down' );
		}

		$contact = $this->sanitize_contact();
		if ( is_wp_error( $contact ) ) {
			$this->redirect_with_error( $redirect, $contact->get_error_code() );
		}

		$payload = 'foreign' === $type ? $this->sanitize_foreign_payload() : $this->sanitize_pcb_payload();
		if ( is_wp_error( $payload ) ) {
			$this->redirect_with_error( $redirect, $payload->get_error_code() );
		}

		$file_check = $this->validate_file( $type );
		if ( is_wp_error( $file_check ) ) {
			$this->redirect_with_error( $redirect, $file_check->get_error_code() );
		}
		if ( 'foreign' === $type && empty( $payload['items'] ) && ! $file_check ) {
			$this->redirect_with_error( $redirect, 'no_items' );
		}
		if ( 'pcb' === $type && ! $file_check ) {
			$this->redirect_with_error( $redirect, 'file_required' );
		}
		set_transient( $rate_key, 1, 25 );

		$code       = $this->generate_code( $type );
		$type_label = 'pcb' === $type ? 'PCB' : 'سفارش خارجی';
		$request_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => sprintf( '%1$s %2$s — %3$s', $type_label, $code, $contact['name'] ),
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $request_id ) ) {
			$this->redirect_with_error( $redirect, 'save_failed' );
		}

		$file_meta = $file_check ? $this->store_upload( $type, $request_id ) : array();
		if ( is_wp_error( $file_meta ) ) {
			wp_delete_post( $request_id, true );
			$this->redirect_with_error( $redirect, 'upload_failed' );
		}

		update_post_meta( $request_id, '_dgl_request_type', $type );
		update_post_meta( $request_id, '_dgl_request_code', $code );
		update_post_meta( $request_id, '_dgl_request_status', 'new' );
		update_post_meta( $request_id, '_dgl_request_contact', $contact );
		update_post_meta( $request_id, '_dgl_request_payload', $payload );
		update_post_meta( $request_id, '_dgl_request_file', $file_meta );
		update_post_meta( $request_id, '_dgl_request_source_url', esc_url_raw( $redirect ) );

		$this->notify_admin( $request_id, $code, $type_label, $contact, $payload );
		$this->redirect_with_success( $redirect, $code );
	}

	/**
	 * Sanitize common contact data.
	 *
	 * @return array|WP_Error
	 */
	private function sanitize_contact() {
		$name    = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mobile  = isset( $_POST['mobile'] ) ? preg_replace( '/[^0-9+]/', '', wp_unslash( $_POST['mobile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$company = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$consent = isset( $_POST['consent'] ) ? sanitize_key( wp_unslash( $_POST['consent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $name || strlen( preg_replace( '/\D/', '', $mobile ) ) < 10 ) {
			return new WP_Error( 'contact', 'Contact details are incomplete.' );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'email', 'Email is invalid.' );
		}
		if ( 'yes' !== $consent ) {
			return new WP_Error( 'consent', 'Consent is required.' );
		}

		return compact( 'name', 'mobile', 'email', 'company' );
	}

	/**
	 * Sanitize sourcing line items and options.
	 *
	 * @return array
	 */
	private function sanitize_foreign_payload() {
		$raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$items     = array();

		foreach ( array_slice( $raw_items, 0, 10 ) as $raw_item ) {
			if ( ! is_array( $raw_item ) ) {
				continue;
			}
			$item = array(
				'name'        => sanitize_text_field( $raw_item['name'] ?? '' ),
				'part_number' => sanitize_text_field( $raw_item['part_number'] ?? '' ),
				'url'         => esc_url_raw( $raw_item['url'] ?? '' ),
				'quantity'    => max( 1, absint( $raw_item['quantity'] ?? 1 ) ),
				'notes'       => sanitize_text_field( $raw_item['notes'] ?? '' ),
			);
			if ( $item['name'] || $item['part_number'] || $item['url'] ) {
				$items[] = $item;
			}
		}

		return array(
			'items'          => $items,
			'shipping_speed' => $this->allowed_value( 'shipping_speed', array( 'best', 'economy', 'express' ), 'best' ),
			'target_date'    => $this->posted_text( 'target_date' ),
			'budget'         => $this->posted_text( 'budget' ),
			'invoice'        => $this->allowed_value( 'invoice', array( 'no', 'yes', 'unsure' ), 'no' ),
			'notes'          => $this->posted_textarea( 'notes' ),
		);
	}

	/**
	 * Sanitize and validate PCB specifications.
	 *
	 * @return array|WP_Error
	 */
	private function sanitize_pcb_payload() {
		$project_name = $this->posted_text( 'project_name' );
		$quantity     = isset( $_POST['board_quantity'] ) ? absint( $_POST['board_quantity'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$length       = isset( $_POST['board_length'] ) ? (float) wp_unslash( $_POST['board_length'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$width        = isset( $_POST['board_width'] ) ? (float) wp_unslash( $_POST['board_width'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $project_name || $quantity < 5 || 0 !== $quantity % 5 || $length <= 0 || $width <= 0 || $length > 600 || $width > 600 ) {
			return new WP_Error( 'pcb_specs', 'PCB specifications are incomplete.' );
		}

		return array(
			'project_name'   => $project_name,
			'quantity'       => $quantity,
			'layers'         => $this->allowed_value( 'layers', array( '2', '4' ), '2' ),
			'length_mm'      => $length,
			'width_mm'       => $width,
			'thickness_mm'   => $this->allowed_value( 'thickness', array( '0.8', '1.0', '1.2', '1.6', '2.0' ), '1.6' ),
			'copper'         => $this->allowed_value( 'copper', array( '1oz', '2oz' ), '1oz' ),
			'soldermask'     => $this->allowed_value( 'soldermask', array( 'green', 'black', 'blue', 'red', 'white', 'yellow' ), 'green' ),
			'silkscreen'     => $this->allowed_value( 'silkscreen', array( 'white', 'black' ), 'white' ),
			'surface_finish' => $this->allowed_value( 'surface_finish', array( 'hasl', 'lead-free-hasl', 'enig' ), 'hasl' ),
			'panelization'   => $this->allowed_value( 'panelization', array( 'no', 'yes', 'review' ), 'no' ),
			'stencil'        => $this->allowed_value( 'stencil', array( 'no', 'yes', 'unsure' ), 'no' ),
			'delivery_speed' => $this->allowed_value( 'delivery_speed', array( 'standard', 'fast', 'economy' ), 'standard' ),
			'assembly'       => $this->allowed_value( 'assembly', array( 'no', 'quote' ), 'no' ),
			'notes'          => $this->posted_textarea( 'notes' ),
		);
	}

	/**
	 * Validate file shape, extension and size before creating a request.
	 *
	 * @param string $type Request type.
	 * @return bool|WP_Error
	 */
	private function validate_file( $type ) {
		if ( empty( $_FILES['dgl_request_file'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['dgl_request_file']['error'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return false;
		}
		$file = $_FILES['dgl_request_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || (int) $file['size'] > 20 * MB_IN_BYTES ) {
			return new WP_Error( 'file_size', 'Upload failed or file is too large.' );
		}

		$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		$allowed   = 'pcb' === $type ? array( 'zip', 'rar', '7z' ) : array( 'xlsx', 'xls', 'csv', 'pdf', 'zip' );
		if ( ! in_array( $extension, $allowed, true ) ) {
			return new WP_Error( 'file_type', 'File extension is not allowed.' );
		}

		return true;
	}

	/**
	 * Store an uploaded request file outside the public web root when possible.
	 *
	 * @param string $type Request type.
	 * @param int    $request_id Request post ID.
	 * @return array|WP_Error
	 */
	private function store_upload( $type, $request_id ) {
		$file      = $_FILES['dgl_request_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		$directory = $this->private_upload_directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$filename = sprintf( '%1$s-%2$d-%3$s.%4$s', $type, $request_id, strtolower( wp_generate_password( 18, false, false ) ), $extension );
		$path     = trailingslashit( $directory ) . $filename;
		if ( ! is_uploaded_file( $file['tmp_name'] ) || ! move_uploaded_file( $file['tmp_name'], $path ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_move_uploaded_file
			return new WP_Error( 'upload_failed', 'Could not move uploaded file.' );
		}
		chmod( $path, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return array(
			'path'          => wp_normalize_path( $path ),
			'original_name' => sanitize_file_name( $file['name'] ),
			'extension'     => $extension,
			'size'          => absint( $file['size'] ),
		);
	}

	/**
	 * Resolve a writable private upload directory.
	 *
	 * @return string|WP_Error
	 */
	private function private_upload_directory() {
		$preferred = defined( 'DIGITALOGIC_PRIVATE_UPLOAD_DIR' )
			? DIGITALOGIC_PRIVATE_UPLOAD_DIR
			: trailingslashit( dirname( untrailingslashit( ABSPATH ) ) ) . 'digitalogic-private-requests';
		$preferred = wp_normalize_path( $preferred );

		if ( ( is_dir( $preferred ) || wp_mkdir_p( $preferred ) ) && is_writable( $preferred ) ) {
			return $preferred;
		}

		$fallback = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'uploads/digitalogic-private-requests' );
		if ( ! ( is_dir( $fallback ) || wp_mkdir_p( $fallback ) ) || ! is_writable( $fallback ) ) {
			return new WP_Error( 'upload_directory', 'Private upload directory is not writable.' );
		}
		$this->write_access_guards( $fallback );

		return $fallback;
	}

	/**
	 * Add best-effort web-server guards when an outside-root directory is unavailable.
	 *
	 * @param string $directory Directory path.
	 */
	private function write_access_guards( $directory ) {
		$guards = array(
			'.htaccess' => "Require all denied\nDeny from all\n",
			'web.config'=> "<?xml version=\"1.0\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
			'index.php' => "<?php\nhttp_response_code( 403 );\nexit;\n",
		);
		foreach ( $guards as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Download a private request file for authorized store managers.
	 */
	public function download_request_file() {
		$request_id = isset( $_GET['request_id'] ) ? absint( $_GET['request_id'] ) : 0;
		if ( ! $request_id || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this file.', 'digitalogic' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dgl_download_request_' . $request_id );
		$file = (array) get_post_meta( $request_id, '_dgl_request_file', true );
		$path = isset( $file['path'] ) ? wp_normalize_path( $file['path'] ) : '';
		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The request file no longer exists.', 'digitalogic' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $file['original_name'] ?? basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Remove a request file when an administrator permanently deletes its record.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public function delete_private_file( $post_id, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		$file = (array) get_post_meta( $post_id, '_dgl_request_file', true );
		$path = isset( $file['path'] ) ? wp_normalize_path( $file['path'] ) : '';
		if ( $path && is_file( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Email a concise admin notification; persistence does not depend on mail.
	 */
	private function notify_admin( $request_id, $code, $type_label, $contact, $payload ) {
		$subject = sprintf( '[Digitalogic] درخواست جدید %1$s — %2$s', $type_label, $code );
		$body    = implode(
			"\n",
			array(
				'درخواست جدید در سایت ثبت شد.',
				'کد: ' . $code,
				'نوع: ' . $type_label,
				'نام: ' . $contact['name'],
				'موبایل: ' . $contact['mobile'],
				'ایمیل: ' . ( $contact['email'] ?: '—' ),
				'خلاصه: ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'بررسی در مدیریت: ' . admin_url( 'post.php?post=' . $request_id . '&action=edit' ),
			)
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $contact['email'] ) {
			$headers[] = 'Reply-To: ' . $contact['name'] . ' <' . $contact['email'] . '>';
		}
		wp_mail( get_option( 'admin_email' ), $subject, $body, $headers );
	}

	/**
	 * Show success or validation feedback from a redirect.
	 *
	 * @return string
	 */
	private function request_notice() {
		$success = isset( $_GET['dgl_request'] ) && 'success' === sanitize_key( wp_unslash( $_GET['dgl_request'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code    = isset( $_GET['dgl_code'] ) ? sanitize_text_field( wp_unslash( $_GET['dgl_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$error   = isset( $_GET['dgl_error'] ) ? sanitize_key( wp_unslash( $_GET['dgl_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $success ) {
			return '<div class="dgl-request-notice is-success" role="status"><strong>درخواستت ثبت شد 🎉</strong><span>کد پیگیری: <b dir="ltr">' . esc_html( $code ) . '</b> — خیلی زود باهات هماهنگ می‌شیم.</span></div>';
		}
		if ( ! $error ) {
			return '';
		}

		$messages = array(
			'invalid'       => 'نوع درخواست مشخص نیست؛ صفحه رو تازه کن و دوباره بفرست.',
			'expired'       => 'صفحه مدت زیادی باز بوده؛ یه بار تازه‌ش کن و دوباره بفرست.',
			'slow_down'     => 'درخواست قبلی رسید؛ چند ثانیه صبر کن و دوباره امتحان کن.',
			'contact'       => 'نام و شماره موبایل رو کامل و درست وارد کن.',
			'email'         => 'فرمت ایمیل درست نیست.',
			'consent'       => 'برای ارسال فرم باید تیک اجازه پیگیری رو بزنی.',
			'no_items'      => 'حداقل یک قطعه وارد کن یا فایل BOM بفرست.',
			'pcb_specs'     => 'مشخصات اصلی PCB کامل نیست؛ ابعاد و تعداد رو دوباره چک کن.',
			'file_required' => 'فایل فشرده Gerber رو هم انتخاب کن.',
			'file_size'     => 'فایل آپلود نشد یا بیشتر از ۲۰ مگابایته.',
			'file_type'     => 'فرمت فایل مجاز نیست؛ راهنمای کنار فیلد فایل رو ببین.',
			'upload_failed' => 'آپلود فایل کامل نشد؛ دوباره امتحان کن یا فایل رو کوچک‌تر کن.',
			'save_failed'   => 'ثبت درخواست کامل نشد؛ لطفاً دوباره امتحان کن.',
		);
		$message = $messages[ $error ] ?? 'یه جای فرم درست ثبت نشده؛ موارد ستاره‌دار رو چک کن.';

		return '<div class="dgl-request-notice is-error" role="alert"><strong>یه لحظه!</strong><span>' . esc_html( $message ) . '</span></div>';
	}

	private function posted_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private function posted_textarea( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private function allowed_value( $key, $allowed, $default ) {
		$value = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function generate_code( $type ) {
		return sprintf( 'DLG-%1$s-%2$s-%3$s', strtoupper( $type ), gmdate( 'ymd' ), strtoupper( wp_generate_password( 5, false, false ) ) );
	}

	private function request_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	private function redirect_with_error( $url, $error ) {
		wp_safe_redirect( add_query_arg( array( 'dgl_error' => sanitize_key( $error ) ), remove_query_arg( array( 'dgl_request', 'dgl_code', 'dgl_error' ), $url ) ) . '#dgl-request-title' );
		exit;
	}

	private function redirect_with_success( $url, $code ) {
		wp_safe_redirect( add_query_arg( array( 'dgl_request' => 'success', 'dgl_code' => $code ), remove_query_arg( array( 'dgl_request', 'dgl_code', 'dgl_error' ), $url ) ) . '#dgl-request-title' );
		exit;
	}

	/**
	 * Register request details/status admin boxes.
	 */
	public function register_meta_boxes() {
		add_meta_box( 'dgl-request-details', 'جزئیات درخواست', array( $this, 'render_details_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'dgl-request-status', 'وضعیت پیگیری', array( $this, 'render_status_box' ), self::POST_TYPE, 'side', 'high' );
	}

	public function render_details_box( $post ) {
		$contact      = (array) get_post_meta( $post->ID, '_dgl_request_contact', true );
		$payload      = (array) get_post_meta( $post->ID, '_dgl_request_payload', true );
		$file          = (array) get_post_meta( $post->ID, '_dgl_request_file', true );
		$display_data = array( 'راه ارتباطی' => $contact, 'مشخصات سفارش' => $payload );

		echo '<div dir="rtl" style="font-family:Tahoma,sans-serif">';
		foreach ( $display_data as $heading => $data ) {
			echo '<h3>' . esc_html( $heading ) . '</h3><table class="widefat striped"><tbody>';
			foreach ( $data as $key => $value ) {
				echo '<tr><th style="width:180px">' . esc_html( $key ) . '</th><td><pre style="margin:0;white-space:pre-wrap;direction:auto">' . esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $file['path'] ) ) {
			$download_url = wp_nonce_url( admin_url( 'admin-post.php?action=digitalogic_download_request_file&request_id=' . $post->ID ), 'dgl_download_request_' . $post->ID );
			echo '<p><a class="button button-primary" href="' . esc_url( $download_url ) . '">دانلود فایل پیوست</a> <span>' . esc_html( $file['original_name'] ?? '' ) . '</span></p>';
		}
		echo '</div>';
	}

	public function render_status_box( $post ) {
		$status   = get_post_meta( $post->ID, '_dgl_request_status', true ) ?: 'new';
		$statuses = $this->request_statuses();
		wp_nonce_field( 'dgl_save_request_status', 'dgl_request_status_nonce' );
		echo '<select name="dgl_request_status" style="width:100%">';
		foreach ( $statuses as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public function save_request_status( $post_id ) {
		$nonce = isset( $_POST['dgl_request_status_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['dgl_request_status_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'dgl_save_request_status' ) || ! current_user_can( 'manage_woocommerce' ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$status = isset( $_POST['dgl_request_status'] ) ? sanitize_key( wp_unslash( $_POST['dgl_request_status'] ) ) : '';
		if ( isset( $this->request_statuses()[ $status ] ) ) {
			update_post_meta( $post_id, '_dgl_request_status', $status );
		}
	}

	public function admin_columns( $columns ) {
		return array(
			'cb'         => $columns['cb'] ?? '<input type="checkbox">',
			'title'      => 'درخواست',
			'dgl_type'   => 'نوع',
			'dgl_contact'=> 'مشتری',
			'dgl_status' => 'وضعیت',
			'date'       => 'تاریخ',
		);
	}

	public function render_admin_column( $column, $post_id ) {
		if ( 'dgl_type' === $column ) {
			echo 'pcb' === get_post_meta( $post_id, '_dgl_request_type', true ) ? 'PCB' : 'سفارش خارجی';
		} elseif ( 'dgl_contact' === $column ) {
			$contact = (array) get_post_meta( $post_id, '_dgl_request_contact', true );
			echo esc_html( ( $contact['name'] ?? '—' ) . ' — ' . ( $contact['mobile'] ?? '—' ) );
		} elseif ( 'dgl_status' === $column ) {
			$status = get_post_meta( $post_id, '_dgl_request_status', true ) ?: 'new';
			echo esc_html( $this->request_statuses()[ $status ] ?? $status );
		}
	}

	private function request_statuses() {
		return array( 'new' => 'جدید', 'reviewing' => 'در حال بررسی', 'quoted' => 'قیمت داده شده', 'accepted' => 'تأیید مشتری', 'completed' => 'تکمیل شده', 'cancelled' => 'لغو شده' );
	}
}
