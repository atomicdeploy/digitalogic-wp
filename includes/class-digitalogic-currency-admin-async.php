<?php
/**
 * Non-blocking currency submission and status for the authenticated ACF page.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps the ACF request short while the website-first repricer runs in background. */
final class Digitalogic_Currency_Admin_Async {

	private const JOB_OPTION              = 'digitalogic_currency_admin_async_job_v1';
	private const APPLY_HOOK              = 'digitalogic_currency_admin_async_apply_v1';
	private const NONCE_ACTION            = 'digitalogic_currency_admin_async';
	private const MAX_APPLY_ATTEMPTS      = 6;
	private const RETRY_BASE_MICROSECONDS = 200000;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Register the authenticated admin and background-job hooks. */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_digitalogic_currency_async_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_digitalogic_currency_async_status', array( $this, 'ajax_status' ) );
		add_action( self::APPLY_HOOK, array( $this, 'run_job' ), 10, 1 );
	}

	/**
	 * Return the shared instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Load only on the exact authenticated currency settings page. */
	public function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read only selects page-specific assets.
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		if ( 'currency-settings' !== $page ) {
			return;
		}
		$asset_path    = DIGITALOGIC_PLUGIN_DIR . 'assets/js/currency-admin-async.js';
		$asset_version = is_file( $asset_path ) ? (string) filemtime( $asset_path ) : DIGITALOGIC_VERSION;
		wp_enqueue_script(
			'digitalogic-currency-admin-async',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/currency-admin-async.js',
			array(),
			$asset_version,
			true
		);
		wp_localize_script(
			'digitalogic-currency-admin-async',
			'DigitalogicCurrencyAsync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Queue one strict CNY change without mutating the confirmed option.
	 *
	 * @param int|string $yuan_price Proposed CNY rate.
	 * @param bool       $dispatch   Whether to wake the fallback cron worker.
	 * @return array|WP_Error
	 */
	public function enqueue( $yuan_price, $dispatch = true ) {
		$value = is_string( $yuan_price ) ? trim( $yuan_price ) : (string) $yuan_price;
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,9}\z/D', $value ) ) {
			return new WP_Error( 'digitalogic_currency_async_value_invalid', 'نرخ یوآن معتبر نیست.' );
		}
		$existing        = $this->status();
		$existing_status = (string) ( $existing['status'] ?? '' );
		$existing_age    = time() - (int) ( $existing['updated_at'] ?? 0 );
		if ( in_array( $existing_status, array( 'queued', 'running', 'awaiting_excel' ), true ) && $existing_age <= 180 ) {
			if ( (int) ( $existing['desired_value'] ?? 0 ) === (int) $value ) {
				return $dispatch ? $this->dispatch_queued_job( $existing ) : $existing;
			}
			return new WP_Error(
				'digitalogic_currency_async_busy',
				'یک تغییر نرخ هنوز در حال تکمیل است؛ پس از اعلام نتیجه دوباره تلاش کنید.'
			);
		}
		$current = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_settings();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$job = array(
			'job_id'            => bin2hex( random_bytes( 16 ) ),
			'status'            => (int) $current['yuan_price'] === (int) $value ? 'confirmed' : 'queued',
			'desired_value'     => (int) $value,
			'confirmed_value'   => (int) $current['yuan_price'],
			'created_at'        => time(),
			'updated_at'        => time(),
			'transaction_id'    => '',
			'message_fa'        => (int) $current['yuan_price'] === (int) $value
				? 'نرخ تأییدشدهٔ سایت از قبل همین مقدار است.'
				: 'تغییر ثبت شد و بازتولید قیمت‌های سایت در پس‌زمینه آغاز می‌شود.',
			'dispatch_attempts' => 0,
			'apply_attempts'    => 0,
			'last_dispatch_at'  => 0,
		);
		if ( ! update_option( self::JOB_OPTION, $job, false ) && get_option( self::JOB_OPTION, null ) !== $job ) {
			return new WP_Error( 'digitalogic_currency_async_store_failed', 'ثبت درخواست نرخ ممکن نشد.' );
		}
		if ( 'queued' === $job['status'] && $dispatch ) {
			$scheduled = wp_schedule_single_event( time(), self::APPLY_HOOK, array( $job['job_id'] ), true );
			if ( is_wp_error( $scheduled ) || false === $scheduled || 0 === $scheduled ) {
				$job['status']     = 'failed';
				$job['message_fa'] = 'اجرای پس‌زمینهٔ نرخ زمان‌بندی نشد؛ مقدار سایت تغییر نکرد.';
				$job['updated_at'] = time();
				update_option( self::JOB_OPTION, $job, false );
				return new WP_Error( 'digitalogic_currency_async_schedule_failed', $job['message_fa'] );
			}
			$job = $this->dispatch_queued_job( $job );
		}
		return $job;
	}

	/**
	 * Dispatch the exact due cron event without waiting for the site's disabled automatic cron.
	 *
	 * @param array $job Current durable job record.
	 * @return array
	 */
	private function dispatch_queued_job( array $job ) {
		if ( 'queued' !== (string) ( $job['status'] ?? '' ) ) {
			return $job;
		}
		$attempts      = (int) ( $job['dispatch_attempts'] ?? 0 );
		$last_dispatch = (int) ( $job['last_dispatch_at'] ?? 0 );
		if ( $attempts >= 3 || time() - $last_dispatch < 2 ) {
			return $job;
		}
		$job_id = (string) ( $job['job_id'] ?? '' );
		if ( '' === $job_id ) {
			return $job;
		}
		if ( false === wp_next_scheduled( self::APPLY_HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time(), self::APPLY_HOOK, array( $job_id ), true );
		}
		$job['dispatch_attempts'] = $attempts + 1;
		$job['last_dispatch_at']  = time();
		$job['updated_at']        = time();
		update_option( self::JOB_OPTION, $job, false );
		$origin_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$cron_url    = add_query_arg(
			array( 'doing_wp_cron' => sprintf( '%.22F', microtime( true ) ) ),
			'https://127.0.0.1/wp-cron.php'
		);
		wp_remote_post(
			$cron_url,
			array(
				// Ten milliseconds routinely expired before the loopback socket was
				// opened, leaving the due job to the site's much slower cron traffic.
				// The request remains non-blocking; this bound only gives WordPress a
				// realistic window to hand the wake-up to the local web server.
				'timeout'   => 1.0,
				'blocking'  => false,
				'sslverify' => false,
				'headers'   => array( 'Host' => $origin_host ),
			)
		);
		return $job;
	}

	/**
	 * Run the website-first repricer outside the browser request.
	 *
	 * @param string $job_id Exact queued job identifier.
	 * @return void
	 */
	public function run_job( $job_id ) {
		$job = get_option( self::JOB_OPTION, array() );
		if ( ! is_array( $job ) || ! hash_equals( (string) ( $job['job_id'] ?? '' ), (string) $job_id ) || 'queued' !== (string) ( $job['status'] ?? '' ) ) {
			return;
		}
		$job['status']     = 'running';
		$job['message_fa'] = 'در حال ثبت نرخ و بازتولید قیمت‌های سایت…';
		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );

		$result = null;
		for ( $attempt = 1; $attempt <= self::MAX_APPLY_ATTEMPTS; $attempt++ ) {
			$job['apply_attempts'] = $attempt;
			$job['message_fa']     = sprintf(
				'در حال ثبت نرخ و بازتولید قیمت‌های سایت؛ تلاش %1$d از %2$d…',
				$attempt,
				self::MAX_APPLY_ATTEMPTS
			);
			$job['updated_at']     = time();
			update_option( self::JOB_OPTION, $job, false );
			try {
				$result = Digitalogic_Pricing_Coordinator::instance()->update_currency(
					array( 'yuan_price' => (string) $job['desired_value'] ),
					'admin_async'
				);
			} catch ( Throwable $exception ) {
				$result = new WP_Error(
					'digitalogic_currency_async_unexpected_failure',
					'اجرای پس‌زمینه نرخ به‌طور غیرمنتظره متوقف شد.',
					array( 'exception' => get_class( $exception ) )
				);
			}
			if ( ! $this->is_retryable_apply_error( $result ) ) {
				break;
			}
			if ( $attempt >= self::MAX_APPLY_ATTEMPTS ) {
				break;
			}
			$job['message_fa'] = sprintf(
				'داده‌های Patris در حال به‌روزرسانی است؛ بازخوانی وضعیت تازه و تلاش دوباره %1$d از %2$d…',
				$attempt + 1,
				self::MAX_APPLY_ATTEMPTS
			);
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
			usleep( min( 1600000, self::RETRY_BASE_MICROSECONDS * ( 2 ** ( $attempt - 1 ) ) ) );
		}
		if ( is_wp_error( $result ) ) {
			$job['status']     = 'failed';
			$job['message_fa'] = 'ثبت نرخ کامل نشد: ' . $result->get_error_message();
		} else {
			$confirmation           = is_array( $result['confirmation'] ?? null ) ? $result['confirmation'] : array();
			$job['transaction_id']  = (string) ( $confirmation['transaction_id'] ?? '' );
			$job['confirmed_value'] = (int) $job['desired_value'];
			$job['status']          = 'awaiting_ack' === (string) ( $confirmation['status'] ?? '' ) ? 'awaiting_excel' : 'confirmed';
			$job['message_fa']      = 'awaiting_excel' === $job['status']
				? 'قیمت‌های سایت بازتولید شد؛ در انتظار اعمال و تأیید Excel است.'
				: 'نرخ و قیمت‌های سایت تأیید شد.';

			// update_currency() has now released the pricing transaction lock.
			// Drain the tiny revision event here, while this background request is
			// still alive, so sites with delayed cron wake the open workbook before
			// the bounded confirmation deadline.
			Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
		}
		$job['updated_at'] = time();
		update_option( self::JOB_OPTION, $job, false );
	}

	/**
	 * Decide whether a failed apply is safe to rebase and retry.
	 *
	 * Identity ambiguity and actual price/readback contradictions remain
	 * blocking. Only lock contention or durable pending work with zero
	 * ambiguous identities can be retried against a freshly loaded state.
	 *
	 * @param mixed $result Coordinator result.
	 * @return bool
	 */
	private function is_retryable_apply_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		if ( 'digitalogic_product_sync_busy' === $result->get_error_code() ) {
			return true;
		}
		if ( 'digitalogic_pricing_delivery_incomplete' !== $result->get_error_code() ) {
			return false;
		}
		$data = $result->get_error_data();
		return is_array( $data )
			&& (int) ( $data['pending_products'] ?? 0 ) > 0
			&& 0 === (int) ( $data['deferred_ambiguous'] ?? 0 );
	}

	/** Return status reconciled with the durable confirmation ledger. */
	public function status() {
		$job = get_option( self::JOB_OPTION, array() );
		if ( ! is_array( $job ) || empty( $job['job_id'] ) ) {
			return array(
				'status'     => 'idle',
				'message_fa' => 'آماده',
			);
		}
		$transaction_id  = (string) ( $job['transaction_id'] ?? '' );
		$original_status = (string) ( $job['status'] ?? '' );
		if ( 'queued' === $original_status ) {
			$job = $this->dispatch_queued_job( $job );
		}
		if ( '' !== $transaction_id ) {
			$ledger = get_option( Digitalogic_Excel_Pricing_Sync::CONFIRMATIONS_OPTION, array() );
			$record = $ledger['transactions'][ $transaction_id ] ?? null;
			if ( is_array( $record ) ) {
				if ( 'acknowledged' === (string) ( $record['status'] ?? '' ) ) {
					$job['status']     = 'confirmed';
					$job['message_fa'] = 'سایت و Excel نرخ جدید را تأیید کردند.';
				} elseif ( 'rolled_back' === (string) ( $record['status'] ?? '' ) ) {
					$job['status']          = 'failed';
					$job['confirmed_value'] = (int) ( $record['restored_settings']['yuan_price'] ?? $job['confirmed_value'] );
					$job['message_fa']      = 'تأیید Excel در مهلت مقرر نرسید و سایت نرخ قبلی را بازگرداند.';
				}
			}
		}
		if (
			in_array( (string) ( $job['status'] ?? '' ), array( 'queued', 'running', 'awaiting_excel' ), true )
			&& time() - (int) ( $job['updated_at'] ?? 0 ) > 180
		) {
			$job['status']     = 'failed';
			$job['message_fa'] = 'مهلت تکمیل تغییر نرخ پایان یافت؛ مقدار تأییدشدهٔ قبلی حفظ شد.';
		}
		if ( (string) ( $job['status'] ?? '' ) !== $original_status ) {
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
		}
		return $job;
	}

	/** Handle an authenticated rate proposal from the settings page. */
	public function ajax_submit() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message_fa' => 'دسترسی کافی نیست.' ), 403 );
		}
		$job = $this->enqueue( sanitize_text_field( wp_unslash( $_POST['yuan_price'] ?? '' ) ), true );
		if ( is_wp_error( $job ) ) {
			wp_send_json_error( array( 'message_fa' => $job->get_error_message() ), 409 );
		}
		// Return immediately. The exact due cron event is already woken through a
		// non-blocking loopback request by enqueue(); running the full repricer in
		// this AJAX request made the ACF button look hung while WordPress held the
		// browser connection open.
		wp_send_json_success( $job, 200 );
	}

	/** Return the bounded website/Excel confirmation state. */
	public function ajax_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message_fa' => 'دسترسی کافی نیست.' ), 403 );
		}
		wp_send_json_success( $this->status() );
	}
}
