<?php
/**
 * Bounded asynchronous currency submission for authenticated administrators.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps ACF and raw option writes short while the website repricer runs in background. */
final class Digitalogic_Currency_Admin_Async {

	private const JOB_OPTION                     = 'digitalogic_currency_admin_async_job';
	private const APPLY_HOOK                     = 'digitalogic_currency_admin_async_apply';
	private const FINALIZE_HOOK                  = 'digitalogic_currency_admin_async_finalize';
	private const WATCHDOG_HOOK                  = 'digitalogic_currency_admin_async_watchdog';
	private const ACTION_GROUP                   = 'digitalogic-currency-admin';
	private const JOB_LOCK_NAME                  = 'digitalogic_currency_admin_async_job';
	private const NONCE_ACTION                   = 'digitalogic_currency_admin_async';
	private const MAX_APPLY_ATTEMPTS             = 6;
	private const MAX_DISPATCH_ATTEMPTS          = 6;
	private const MAX_PUBLICATION_ATTEMPTS       = 6;
	private const MAX_REQUEST_ALIASES            = 32;
	private const DISPATCH_RETRY_SECONDS         = 65;
	private const PUBLICATION_RETRY_BASE_SECONDS = 2;
	private const PUBLICATION_RETRY_MAX_SECONDS  = 60;
	private const JOB_TTL_SECONDS                = 300;
	private const LEASE_SECONDS                  = 120;
	private const CLI_JOB_TTL_SECONDS            = 900;
	private const CLI_EXECUTION_MODE             = 'wp_cli_sync';
	private const ACF_OPTIONS_CONTEXT            = '_digitalogic_currency_options_context';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Request-local semantic values captured from one ACF options submission.
	 *
	 * @var array
	 */
	private $acf_pending_currency = array();

	/**
	 * Canonical revision rendered with the current ACF form.
	 *
	 * @var string|null
	 */
	private $acf_expected_revision = null;

	/** Register server-side safety routing, authenticated admin, and worker hooks. */
	private function __construct() {
		add_filter( 'acf/update_value/name=dollar_price', array( $this, 'route_acf_currency_update' ), 1, 3 );
		add_filter( 'acf/update_value/name=options_dollar_price', array( $this, 'route_acf_currency_update' ), 1, 3 );
		add_filter( 'acf/update_value/name=yuan_price', array( $this, 'route_acf_currency_update' ), 1, 3 );
		add_filter( 'acf/update_value/name=options_yuan_price', array( $this, 'route_acf_currency_update' ), 1, 3 );
		add_filter( 'acf/update_value/name=update_date', array( $this, 'route_acf_currency_update' ), 1, 3 );
		add_filter( 'acf/update_value/name=options_update_date', array( $this, 'route_acf_currency_update' ), 1, 3 );
		add_filter( 'acf/load_value/name=update_date', array( $this, 'load_acf_effective_date' ), 1, 3 );
		add_filter( 'acf/load_value/name=options_update_date', array( $this, 'load_acf_effective_date' ), 1, 3 );
		add_filter( 'acf/pre_render_field', array( $this, 'prepare_acf_effective_date_field' ), PHP_INT_MAX, 2 );
		add_filter( 'acf/prepare_field', array( $this, 'prepare_acf_effective_date_field' ), PHP_INT_MAX, 1 );
		foreach ( array( 'dollar_price', 'options_dollar_price', 'yuan_price', 'options_yuan_price', 'update_date', 'options_update_date' ) as $option_name ) {
			add_filter(
				'pre_update_option_' . $option_name,
				array( $this, 'intercept_currency_option_write' ),
				1,
				3
			);
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'acf/input/form_data', array( $this, 'render_acf_revision_field' ) );
		add_action( 'acf/save_post', array( $this, 'flush_acf_currency_update' ), 20 );
		add_action( 'shutdown', array( $this, 'flush_acf_currency_update' ), 5 );
		add_action( 'admin_notices', array( $this, 'render_admin_issue' ) );
		add_action( 'wp_ajax_digitalogic_currency_async_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_digitalogic_currency_async_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_digitalogic_currency_async_cancel', array( $this, 'ajax_cancel' ) );
		add_action( self::APPLY_HOOK, array( $this, 'run_job' ), 10, 2 );
		add_action( self::FINALIZE_HOOK, array( $this, 'finalize_job' ), 10, 4 );
		add_action( self::WATCHDOG_HOOK, array( $this, 'run_watchdog' ), 10, 3 );
		add_action( 'init', array( $this, 'recover_committed_publication' ), 20 );
		add_action( 'init', array( $this, 'recover_queued_job' ), 20 );
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

	/** Load a no-op-unless-present helper for authenticated settings administrators. */
	public function enqueue_assets() {
		if ( ! $this->can_manage_currency() ) {
			return;
		}
		$page    = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$screens = apply_filters(
			'digitalogic_currency_async_admin_pages',
			array( 'currency-settings', 'price-settings' )
		);
		$screens = array_map( 'sanitize_key', is_array( $screens ) ? $screens : array() );
		if ( ! in_array( $page, $screens, true ) ) {
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
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( self::NONCE_ACTION ),
				'fieldNames'          => array( 'dollar_price', 'yuan_price', 'update_date' ),
				'requestTimeoutMs'    => 15000,
				'terminalTimeoutMs'   => 180000,
				'pollIntervalMs'      => 1000,
				'reconnectIntervalMs' => 1500,
			)
		);
	}

	/**
	 * Route an ACF currency field by semantic name, independently of its generated key.
	 *
	 * Returning the already persisted value keeps the native ACF request cheap.
	 * The option-level filter below remains the final safety net if this named
	 * ACF filter is absent, renamed, or bypassed.
	 *
	 * @param mixed       $value   Proposed field value.
	 * @param int|string  $post_id ACF object identifier.
	 * @param array|mixed $field   ACF field definition.
	 * @return mixed
	 */
	public function route_acf_currency_update( $value, $post_id, $field ) {
		if ( 'option' !== $post_id && 'options' !== $post_id ) {
			return $value;
		}
		if ( ! $this->managed_pricing_active() ) {
			return $value;
		}

		$field_name = is_array( $field ) ? (string) ( $field['name'] ?? '' ) : '';
		$currency   = $this->currency_field( $field_name );
		if ( '' === $currency ) {
			return $value;
		}
		if ( 'effective_date' === $currency ) {
			$value = $this->normalize_acf_effective_date( $value );
		}

		// ACF calls this filter once per field. Capture every semantic rate and
		// enqueue them together from acf/save_post so field order can never drop
		// one half of a USD+CNY submission. The shutdown hook is a fail-safe for
		// custom ACF callers that omit acf/save_post.
		$this->acf_pending_currency[ $currency ] = $value;
		if ( null === $this->acf_expected_revision ) {
			$this->acf_expected_revision = $this->request_acf_expected_revision();
		}

		return $this->persisted_currency_value( $currency, $value );
	}

	/**
	 * Render ACF's legacy six-digit date as the exact canonical eight-digit value.
	 *
	 * ACF and date-localization plugins can otherwise interpret YYMMDD as Unix
	 * seconds and display an epoch-era date which would be submitted back later.
	 *
	 * @param mixed       $value   ACF-projected field value.
	 * @param int|string  $post_id ACF object identifier.
	 * @param array|mixed $field   ACF field definition.
	 * @return mixed
	 */
	public function load_acf_effective_date( $value, $post_id, $field ) {
		unset( $field );
		if (
			( 'option' !== $post_id && 'options' !== $post_id )
			|| ! $this->managed_pricing_active()
		) {
			return $value;
		}

		$date = $this->canonical_acf_effective_date();
		if ( null === $date ) {
			return $value;
		}

		return $date->format( 'Ymd' );
	}

	/**
	 * Repair a value that ACFE loaded before this plugin registered load filters.
	 *
	 * Some options-page integrations hydrate field values while plugins are still
	 * booting. The pre-render hook provides the real options context even for an
	 * already-prepared field; the outer prepare hook then runs after ACF's type,
	 * name, and key variations. Together they replace the epoch projection without
	 * relying on JavaScript or a generated field key.
	 *
	 * @param array|mixed     $field   ACF field definition.
	 * @param int|string|null $post_id ACF render context when available.
	 * @return array|mixed
	 */
	public function prepare_acf_effective_date_field( $field, $post_id = null ) {
		if ( ! is_array( $field ) ) {
			return $field;
		}
		$field_name = (string) ( $field['_name'] ?? $field['name'] ?? '' );
		if (
			! in_array( $field_name, array( 'update_date', 'options_update_date' ), true )
			|| ! $this->is_currency_admin_page()
		) {
			return $field;
		}
		if ( null !== $post_id ) {
			if ( 'option' !== $post_id && 'options' !== $post_id ) {
				return $field;
			}
			$field[ self::ACF_OPTIONS_CONTEXT ] = true;
		} elseif ( empty( $field[ self::ACF_OPTIONS_CONTEXT ] ) ) {
			return $field;
		}

		$date = $this->canonical_acf_effective_date();
		if ( null !== $date ) {
			$field['value'] = $date->format( 'Ymd' );
		}
		if ( null === $post_id ) {
			unset( $field[ self::ACF_OPTIONS_CONTEXT ] );
		}

		return $field;
	}

	/** Resolve a trusted effective date without traversing ACF option filters. */
	private function canonical_acf_effective_date() {
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( is_wp_error( $state ) ) {
			return null;
		}

		return Digitalogic_Currency_Date_Formatter::instance()->parse(
			(string) ( $state['settings']['cny_effective_date'] ?? '' )
		);
	}

	/** Whether this request is rendering one of the managed currency screens. */
	private function is_currency_admin_page() {
		$page    = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$screens = apply_filters(
			'digitalogic_currency_async_admin_pages',
			array( 'currency-settings', 'price-settings' )
		);
		$screens = array_map( 'sanitize_key', is_array( $screens ) ? $screens : array() );

		return in_array( $page, $screens, true );
	}

	/** Render a server-owned optimistic revision inside every authorized ACF form. */
	public function render_acf_revision_field() {
		if ( ! $this->can_manage_currency() || ! $this->managed_pricing_active() ) {
			return;
		}
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( is_wp_error( $state ) ) {
			return;
		}
		printf(
			'<input type="hidden" name="digitalogic_pricing_state_revision" value="%1$s"><input type="hidden" name="digitalogic_currency_request_id" value="%2$s">',
			esc_attr( (string) $state['state_revision'] ),
			esc_attr( 'acf:' . wp_generate_uuid4() )
		);
	}

	/**
	 * Enqueue one atomic semantic job after ACF has visited every field.
	 *
	 * @param mixed $post_id Optional ACF object identifier.
	 * @return void
	 */
	public function flush_acf_currency_update( $post_id = null ) {
		unset( $post_id );
		if ( ! $this->acf_pending_currency ) {
			return;
		}

		$values                      = $this->acf_pending_currency;
		$expected_revision           = $this->acf_expected_revision;
		$this->acf_pending_currency  = array();
		$this->acf_expected_revision = null;
		if (
			! is_string( $expected_revision )
			|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $expected_revision )
		) {
			$this->report_issue(
				new WP_Error(
					'digitalogic_currency_async_acf_revision_missing',
					'این فرم قیمت به وضعیت مشخصی متصل نبود؛ برای جلوگیری از بازگرداندن نرخ جدیدتر، صفحه را تازه‌سازی و دوباره ذخیره کنید.',
					array( 'blocking' => true )
				),
				'acf_revision_refresh'
			);

			return;
		}

		$result = $this->enqueue_currency(
			$values,
			true,
			false,
			$expected_revision,
			'acf_admin',
			sanitize_text_field( wp_unslash( $_POST['digitalogic_currency_request_id'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ACF verifies its form nonce before this save hook.
		);
		if ( is_wp_error( $result ) ) {
			$this->report_issue( $result, 'acf_semantic_route' );
		}
	}

	/**
	 * Fail safe every raw managed currency option write before the synchronous guard.
	 *
	 * This is deliberately option-semantic, not ACF-key-specific. A broken or
	 * missing browser script, a rotated ACF key/name, update_field(), and direct
	 * update_option() therefore cannot re-enter full repricing in that request.
	 *
	 * @param mixed  $value     Proposed option value.
	 * @param mixed  $old_value Persisted option value.
	 * @param string $option    Exact option name.
	 * @return mixed
	 */
	public function intercept_currency_option_write( $value, $old_value, $option ) {
		if (
			! $this->managed_pricing_active()
			|| (string) $value === (string) $old_value
		) {
			return $value;
		}
		$currency = $this->currency_field( $option );
		if ( '' === $currency ) {
			return $value;
		}

		$expected_revision = $this->request_acf_expected_revision();
		if ( '' === $expected_revision ) {
			$error = new WP_Error(
				'digitalogic_currency_async_acf_revision_missing',
				'این فرم قیمت به وضعیت مشخصی متصل نبود؛ صفحه را تازه‌سازی و دوباره ذخیره کنید.',
				array( 'blocking' => true )
			);
			$this->report_issue( $error, 'acf_option_fail_safe' );

			return $old_value;
		}

		$result = $this->enqueue_currency(
			array( $currency => $value ),
			true,
			false,
			$expected_revision,
			'option_fail_safe',
			sanitize_text_field( wp_unslash( $_POST['digitalogic_currency_request_id'] ?? '' ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ACF verifies its form nonce before option persistence.
		);
		if ( is_wp_error( $result ) ) {
			$this->report_issue( $result, 'option_fail_safe' );
		}

		return $old_value;
	}

	/**
	 * Queue one strict CNY change without mutating the confirmed option.
	 *
	 * @param int|string $yuan_price Proposed CNY rate.
	 * @param bool       $dispatch   Whether to wake the bounded worker.
	 * @param bool       $reconcile  Explicitly reprice at the unchanged current rate.
	 * @return array|WP_Error Public job projection or error.
	 */
	public function enqueue( $yuan_price, $dispatch = true, $reconcile = false ) {
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return $this->enqueue_currency(
			array( 'yuan_price' => $yuan_price ),
			$dispatch,
			$reconcile,
			(string) $state['state_revision'],
			'admin'
		);
	}

	/**
	 * Queue one canonical partial currency change without mutating confirmed rates.
	 *
	 * @param array  $values            One or both of dollar_price and yuan_price.
	 * @param bool   $dispatch          Whether to wake the bounded worker.
	 * @param bool   $reconcile         Explicitly reprice only when every submitted rate is already current.
	 * @param string $expected_revision Exact canonical revision seen by the submitting surface.
	 * @param string $source            Bounded internal source label.
	 * @param string $request_id        Optional explicit idempotency identity.
	 * @param string $execution_mode    Internal runner mode; defaults to bounded async delivery.
	 * @return array|WP_Error Public job projection or error.
	 */
	public function enqueue_currency( array $values, $dispatch = true, $reconcile = false, $expected_revision = '', $source = 'admin', $request_id = '', $execution_mode = 'async' ) {
		$allowed = array( 'dollar_price', 'yuan_price', 'effective_date', 'usd_effective_date', 'cny_effective_date' );
		if ( ! $values || array_diff( array_keys( $values ), $allowed ) ) {
			return new WP_Error(
				'digitalogic_currency_async_fields_invalid',
				'حداقل یکی از نرخ‌های دلار یا یوآن باید ارسال شود.',
				array( 'blocking' => true )
			);
		}
		$desired = array();
		foreach ( $values as $field => $raw_value ) {
			if ( in_array( $field, array( 'effective_date', 'usd_effective_date', 'cny_effective_date' ), true ) ) {
				$date = Digitalogic_Currency_Date_Formatter::instance()->parse( $raw_value );
				if ( null === $date ) {
					return new WP_Error(
						'digitalogic_currency_async_effective_date_invalid',
						'تاریخ مؤثر نرخ معتبر نیست.',
						array(
							'blocking' => true,
							'field'    => $field,
						)
					);
				}
				$desired[ $field ] = $date->format( 'Y-m-d' );

				continue;
			}
			$value = is_string( $raw_value ) ? trim( $raw_value ) : (string) $raw_value;
			if ( 1 !== preg_match( '/\A[1-9][0-9]{0,9}\z/D', $value ) ) {
				return new WP_Error(
					'digitalogic_currency_async_value_invalid',
					'نرخ ارز معتبر نیست.',
					array(
						'blocking' => true,
						'field'    => $field,
					)
				);
			}
			$desired[ $field ] = (int) $value;
		}
		ksort( $desired );
		if ( ! is_string( $expected_revision ) || '' === $expected_revision ) {
			return new WP_Error(
				'digitalogic_currency_async_expected_revision_required',
				'پیش از تغییر نرخ، وضعیت فعلی قیمت را تازه‌سازی کنید.',
				array(
					'status'   => 428,
					'blocking' => true,
				)
			);
		}
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $expected_revision ) ) {
			return new WP_Error(
				'digitalogic_currency_async_expected_revision_invalid',
				'شناسهٔ وضعیت قیمت معتبر نیست.',
				array(
					'status'   => 400,
					'blocking' => true,
				)
			);
		}
		$source     = sanitize_key( (string) $source );
		$source     = '' === $source ? 'admin' : substr( $source, 0, 64 );
		$execution_mode = self::CLI_EXECUTION_MODE === (string) $execution_mode
			? self::CLI_EXECUTION_MODE
			: 'async';
		$request_id = $this->normalize_request_id( $request_id );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}

		$reconcile           = true === $reconcile;
		$request_fingerprint = 'sha256:' . hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'desired_currency'        => $desired,
					'execution_mode'          => $execution_mode,
					'expected_state_revision' => $expected_revision,
					'mode'                    => $reconcile ? 'reconcile' : 'apply',
				),
				JSON_UNESCAPED_SLASHES
			)
		);
		$should_wake         = false;
		$result              = $this->with_job_lock(
			function () use ( $desired, $dispatch, $reconcile, $expected_revision, $source, $request_id, $request_fingerprint, $execution_mode, &$should_wake ) {
				$now             = time();
				$existing        = $this->raw_job();
				$existing_status = (string) ( $existing['status'] ?? '' );
				$replayed        = $this->replay_request_open_lock( $existing, $request_id, $request_fingerprint );
				if ( null !== $replayed ) {
					return $replayed;
				}
				$active = in_array( $existing_status, array( 'queued', 'running' ), true )
					&& (int) ( $existing['deadline_at'] ?? 0 ) >= $now;
				if ( ! $active && 'running' === $existing_status ) {
					$marker = (string) ( $existing['effect_state_revision'] ?? '' );
					if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $marker ) ) {
						$this->schedule_action(
							self::FINALIZE_HOOK,
							$now,
							array(
								(string) $existing['job_id'],
								(int) $existing['generation'],
								(int) ( $existing['fence'] ?? 0 ),
								$marker,
							)
						);
					} else {
						$this->schedule_action(
							self::WATCHDOG_HOOK,
							$now,
							$this->watchdog_args( $existing, (int) ( $existing['fence'] ?? 0 ) )
						);
					}
					return new WP_Error(
						'digitalogic_currency_async_worker_still_running',
						'اجرای قبلی از مهلت نمایش گذشته است اما هنوز آزاد نشده؛ درخواست جدید متوقف نشد و می‌توانید دوباره وضعیت را بررسی کنید.',
						array(
							'blocking'    => false,
							'retry_after' => 2,
						)
					);
				}

				if ( $active ) {
					$existing_reconcile = 'reconcile' === (string) ( $existing['mode'] ?? 'apply' );
					$existing_execution = (string) ( $existing['execution_mode'] ?? 'async' );
					if (
						(array) ( $existing['desired_currency'] ?? array() ) !== $desired
						|| $existing_reconcile !== $reconcile
						|| $existing_execution !== $execution_mode
					) {
						return new WP_Error(
							'digitalogic_currency_async_busy',
							'یک تغییر نرخ هنوز در حال تکمیل است؛ وضعیت همان درخواست را دنبال کنید.',
							array(
								'blocking'    => false,
								'retry_after' => 2,
							)
						);
					}
					if ( $dispatch && 'queued' === $existing_status ) {
						$should_wake = $this->wake_worker_open_lock( $existing );
					}
					if ( '' !== $request_id ) {
						$expected = $existing;
						$this->attach_request_alias( $existing, $request_id, $request_fingerprint );
						$stored = $this->store_job_open_lock( $existing, $expected );
						if ( is_wp_error( $stored ) ) {
							return $stored;
						}
					}

					return $this->public_job_for_request( $existing, $request_id );
				}
				if ( Digitalogic_Excel_Pricing_Sync::coordination_lock_is_held() ) {
					return new WP_Error(
						'digitalogic_excel_sync_busy',
						'تراکنش قیمت دیگری هنوز در حال اجرا است؛ پس از آزاد شدن همان تراکنش دوباره تلاش کنید.',
						array(
							'blocking'    => false,
							'retry_after' => 5,
						)
					);
				}

				$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
				if ( is_wp_error( $state ) ) {
					return $state;
				}
				$current = $state['settings'];
				if ( ! hash_equals( $expected_revision, (string) $state['state_revision'] ) ) {
					return new WP_Error(
						'digitalogic_currency_async_state_revision_conflict',
						'تنظیمات قیمت پس از باز شدن صفحه تغییر کرده است؛ صفحه را تازه‌سازی کنید.',
						array(
							'blocking'               => true,
							'current_state_revision' => (string) $state['state_revision'],
						)
					);
				}
				$confirmed     = array(
					'dollar_price' => (int) $current['dollar_price'],
					'yuan_price'   => (int) $current['yuan_price'],
				);
				$current_patch = array(
					'dollar_price'       => (int) $current['dollar_price'],
					'yuan_price'         => (int) $current['yuan_price'],
					'effective_date'     => (string) $current['effective_date'],
					'usd_effective_date' => (string) $current['usd_effective_date'],
					'cny_effective_date' => (string) $current['cny_effective_date'],
				);
				$mismatch      = array_filter(
					$desired,
					static function ( $value, $field ) use ( $current_patch ) {
						return (string) $current_patch[ $field ] !== (string) $value;
					},
					ARRAY_FILTER_USE_BOTH
				);
				if ( $reconcile && $mismatch ) {
					return new WP_Error(
						'digitalogic_currency_async_reconcile_rate_mismatch',
						'بازمحاسبهٔ ایمن فقط با نرخ تأییدشدهٔ فعلی مجاز است.',
						array( 'blocking' => true )
					);
				}
				if ( $existing ) {
					$this->unschedule_job( $existing );
				}

				$generation = max( 0, (int) ( $existing['generation'] ?? 0 ) ) + 1;
				$same       = ! $reconcile && ! $mismatch;
				$aliases    = $this->carry_request_aliases( $existing );
				$job        = array(
					'job_id'                  => $this->random_token( 16 ),
					'generation'              => $generation,
					'mode'                    => $reconcile ? 'reconcile' : 'apply',
					'execution_mode'          => $execution_mode,
					'source'                  => $source,
					'status'                  => $same ? 'confirmed' : 'queued',
					'desired_currency'        => $desired,
					'confirmed_currency'      => $confirmed,
					'expected_state_revision' => (string) $state['state_revision'],
					'created_at'              => $now,
					'updated_at'              => $now,
					'deadline_at'             => $now + ( self::CLI_EXECUTION_MODE === $execution_mode ? self::CLI_JOB_TTL_SECONDS : self::JOB_TTL_SECONDS ),
					'completed_at'            => $same ? $now : 0,
					'next_attempt_at'         => $same ? 0 : $now,
					'transaction_id'          => '',
					'error_code'              => '',
					'message_fa'              => $same
						? 'نرخ تأییدشدهٔ سایت از قبل همین مقدار است.'
						: ( $reconcile
							? 'بازمحاسبه با نرخ تأییدشده در پس‌زمینه آغاز می‌شود.'
							: 'تغییر ثبت شد و بازتولید قیمت‌های سایت در پس‌زمینه آغاز می‌شود.' ),
					'dispatch_attempts'       => 0,
					'apply_attempts'          => 0,
					'last_dispatch_at'        => 0,
					'owner_token'             => '',
					'fence_token'             => '',
					'fence'                   => 0,
					'lease_until'             => 0,
					'cancel_requested'        => false,
					'cancel_requested_at'     => 0,
					'primary_request_id'      => $request_id,
					'request_aliases'         => $aliases,
				);
				if ( '' !== $request_id ) {
					$this->attach_request_alias( $job, $request_id, $request_fingerprint );
				}

				$stored = $this->store_job_open_lock( $job, $existing );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				if ( $same ) {
					$this->unschedule_job( $job );

					return $this->public_job_for_request( $job, $request_id );
				}

				if (
					self::CLI_EXECUTION_MODE !== $execution_mode
					&& ! $this->schedule_action( self::APPLY_HOOK, $now, $this->apply_args( $job ) )
				) {
					return $this->fail_open_lock(
						$job,
						'digitalogic_currency_async_schedule_failed',
						'اجرای پس‌زمینهٔ نرخ زمان‌بندی نشد؛ مقدار سایت تغییر نکرد.'
					);
				}
				if ( ! $this->schedule_action( self::WATCHDOG_HOOK, $job['deadline_at'], $this->watchdog_args( $job, 0 ) ) ) {
					$this->unschedule_job( $job );

					return $this->fail_open_lock(
						$job,
						'digitalogic_currency_async_watchdog_schedule_failed',
						'پایش مهلت کار پس‌زمینه زمان‌بندی نشد؛ مقدار سایت تغییر نکرد.'
					);
				}
				if ( $dispatch && self::CLI_EXECUTION_MODE !== $execution_mode ) {
					$should_wake = $this->wake_worker_open_lock( $job );
				}

				return $this->public_job_for_request( $job, $request_id );
			}
		);
		if ( $should_wake ) {
			$this->wake_local_cron();
		}

		return $result;
	}

	/**
	 * Execute one canonical WP-CLI rate request inside the calling CLI process.
	 *
	 * A changed submitted rate already implies a full repricing transaction. The
	 * recalculate flag therefore selects reconcile mode only when every submitted
	 * rate is already canonical. The durable generation remains fenced and has no
	 * async apply action, so a web worker cannot overlap this owner.
	 *
	 * @param array  $values            Submitted currency fields.
	 * @param bool   $force_recalculate Whether an unchanged rate must be reconciled.
	 * @param string $expected_revision Exact canonical revision before enqueue.
	 * @param string $request_id        Explicit CLI request identity.
	 * @return array|WP_Error Terminal public job projection or enqueue error.
	 */
	public function execute_cli_currency( array $values, $force_recalculate, $expected_revision, $request_id ) {
		$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		$current   = (array) ( $state['settings'] ?? array() );
		$reconcile = true === $force_recalculate;
		if ( $reconcile ) {
			foreach ( $values as $field => $value ) {
				if ( ! array_key_exists( $field, $current ) || (string) $current[ $field ] !== (string) $value ) {
					$reconcile = false;
					break;
				}
			}
		}

		$job = $this->enqueue_currency(
			$values,
			false,
			$reconcile,
			$expected_revision,
			'wp_cli_currency',
			$request_id,
			self::CLI_EXECUTION_MODE
		);
		if ( is_wp_error( $job ) || 'confirmed' === (string) ( $job['status'] ?? '' ) ) {
			return $job;
		}

		$stored = $this->raw_job();
		if (
			! $this->matches_job( $stored, (string) ( $job['job_id'] ?? '' ), (int) ( $job['generation'] ?? 0 ) )
			|| self::CLI_EXECUTION_MODE !== (string) ( $stored['execution_mode'] ?? '' )
		) {
			return new WP_Error(
				'digitalogic_currency_cli_owner_conflict',
				'یک کار نرخ دیگر مالک این نسل است؛ وضعیت همان کار را بررسی کنید.',
				array( 'blocking' => false )
			);
		}

		do {
			$now = time();
			if ( (int) ( $stored['next_attempt_at'] ?? 0 ) > $now ) {
				$wait = min( 1, (int) $stored['next_attempt_at'] - $now );
				if ( 0 < $wait ) {
					sleep( $wait );
				}
			}
			$this->run_job( (string) $stored['job_id'], (int) $stored['generation'] );
			$stored = $this->raw_job();
			if ( $this->is_terminal( $stored ) ) {
				return $this->public_job_for_request( $stored, $request_id );
			}
			sleep( 1 );
		} while ( time() <= (int) ( $stored['deadline_at'] ?? 0 ) );

		$this->run_watchdog(
			(string) ( $stored['job_id'] ?? '' ),
			(int) ( $stored['generation'] ?? 0 ),
			0
		);

		return $this->public_job_for_request( $this->raw_job(), $request_id );
	}

	/**
	 * Atomically claim and run one exact generation.
	 *
	 * @param string $job_id     Exact durable job identifier.
	 * @param int    $generation Exact job generation.
	 * @return void
	 */
	public function run_job( $job_id, $generation = 0 ) {
		if ( (int) $generation < 1 ) {
			return;
		}

		$claim = $this->with_job_lock(
			function () use ( $job_id, $generation ) {
				$job = $this->raw_job();
				if ( ! $this->matches_job( $job, $job_id, $generation ) ) {
					return null;
				}
				if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $job['effect_state_revision'] ?? '' ) ) ) {
					return $job;
				}
				if ( $this->is_terminal( $job ) ) {
					return null;
				}

				$now      = time();
				$retained = $this->retain_running_job_while_pricing_lock_held( $job, $now );
				if ( is_wp_error( $retained ) ) {
					return $retained;
				}
				if ( true === $retained ) {
					return null;
				}
				if ( (int) ( $job['deadline_at'] ?? 0 ) < $now ) {
					return $this->fail_open_lock(
						$job,
						'digitalogic_currency_async_deadline_exceeded',
						'مهلت تکمیل تغییر نرخ پایان یافت؛ مقدار تأییدشدهٔ قبلی حفظ شد.'
					);
				}
				if ( 'queued' === (string) ( $job['status'] ?? '' ) && (int) ( $job['next_attempt_at'] ?? 0 ) > $now ) {
					if ( ! $this->is_cli_sync_job( $job ) ) {
						$this->schedule_action( self::APPLY_HOOK, (int) $job['next_attempt_at'], $this->apply_args( $job ) );
					}

					return null;
				}
				if (
					'running' === (string) ( $job['status'] ?? '' )
					&& (int) ( $job['lease_until'] ?? 0 ) > $now
				) {
					$this->schedule_action(
						self::WATCHDOG_HOOK,
						(int) $job['lease_until'] + 1,
						$this->watchdog_args( $job, (int) ( $job['fence'] ?? 0 ) )
					);

					return null;
				}

				$expected              = $job;
				$job['status']         = 'running';
				$job['owner_token']    = $this->random_token( 16 );
				$job['fence_token']    = $this->random_token( 16 );
				$job['fence']          = max( 0, (int) ( $job['fence'] ?? 0 ) ) + 1;
				$job['lease_until']    = $this->is_cli_sync_job( $job )
					? (int) $job['deadline_at']
					: $now + self::LEASE_SECONDS;
				$job['apply_attempts'] = max( 0, (int) ( $job['apply_attempts'] ?? 0 ) ) + 1;
				$job['updated_at']     = $now;
				$job['error_code']     = '';
				$job['message_fa']     = sprintf(
					'در حال ثبت نرخ و بازتولید قیمت‌های سایت؛ تلاش %1$d از %2$d…',
					$job['apply_attempts'],
					self::MAX_APPLY_ATTEMPTS
				);
				$stored                = $this->store_job_open_lock( $job, $expected );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				$this->schedule_action(
					self::WATCHDOG_HOOK,
					$job['lease_until'] + 1,
					$this->watchdog_args( $job, $job['fence'] )
				);

				return $job;
			}
		);

		if ( $this->is_job_transition_retryable( $claim ) ) {
			$current = $this->raw_job();
			if ( ! $this->is_cli_sync_job( $current ) ) {
				$this->schedule_action( self::APPLY_HOOK, time() + 2, array( (string) $job_id, (int) $generation ) );
			}

			return;
		}
		if ( is_array( $claim ) && ! empty( $claim['effect_state_revision'] ) ) {
			$this->finalize_job(
				(string) $claim['job_id'],
				(int) $claim['generation'],
				(int) ( $claim['fence'] ?? 0 ),
				(string) $claim['effect_state_revision']
			);

			return;
		}
		if ( ! is_array( $claim ) || empty( $claim['owner_token'] ) ) {
			return;
		}

		do_action( 'digitalogic_currency_async_worker_claimed', $this->public_job( $claim ) );
		$guard = function ( $phase, $transaction_result = null ) use ( $claim ) {
			return $this->validate_claim_for_actuation(
				$claim,
				'before_commit' === (string) $phase,
				$transaction_result
			);
		};

		try {
			$apply = 'reconcile' === (string) ( $claim['mode'] ?? 'apply' )
				? Digitalogic_Pricing_Coordinator::instance()->reconcile_current(
					'admin_async_reconcile',
					(string) ( $claim['expected_state_revision'] ?? '' ),
					$guard
				)
				: Digitalogic_Pricing_Coordinator::instance()->update_currency(
					(array) ( $claim['desired_currency'] ?? array() ),
					'admin_async',
					(string) ( $claim['expected_state_revision'] ?? '' ),
					$guard
				);
		} catch ( Throwable $exception ) {
			$apply = new WP_Error(
				'digitalogic_currency_async_unexpected_failure',
				'اجرای پس‌زمینه نرخ به‌طور غیرمنتظره متوقف شد.',
				array(
					'blocking'  => true,
					'exception' => get_class( $exception ),
				)
			);
		}

		do_action( 'digitalogic_currency_async_worker_before_complete', $this->public_job( $claim ) );
		if ( is_wp_error( $apply ) ) {
			$this->complete_failed_attempt( $claim, $apply );

			return;
		}

		$this->finalize_job(
			(string) $claim['job_id'],
			(int) $claim['generation'],
			(int) $claim['fence'],
			(string) ( $apply['state_revision'] ?? '' )
		);
	}

	/**
	 * Complete one successful fenced actuation, retrying only the tiny CAS write.
	 *
	 * @param string $job_id         Exact durable job identifier.
	 * @param int    $generation     Exact job generation.
	 * @param int    $expected_fence Exact worker fence.
	 * @param string $state_revision Committed canonical state revision.
	 * @return void
	 */
	public function finalize_job( $job_id, $generation, $expected_fence, $state_revision ) {
		if (
			(int) $generation < 1
			|| (int) $expected_fence < 1
			|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) $state_revision )
		) {
			return;
		}

		$deliver_event = false;
		$retry_at      = 0;
		$cli_sync      = false;
		$result        = $this->with_job_lock(
			function () use ( $job_id, $generation, $expected_fence, $state_revision, &$deliver_event, &$retry_at, &$cli_sync ) {
				$job = $this->raw_job();
				if ( ! $this->matches_job( $job, $job_id, $generation ) ) {
					return null;
				}
				$marker = (string) ( $job['effect_state_revision'] ?? '' );
				if ( ! hash_equals( (string) $state_revision, $marker ) ) {
					return null;
				}
				if ( 'confirmed' === (string) ( $job['status'] ?? '' ) ) {
					return true;
				}
				$cli_sync = $this->is_cli_sync_job( $job );
				if (
					(int) ( $job['fence'] ?? 0 ) !== (int) $expected_fence
				) {
					return null;
				}
				$expected    = $job;
				$publication = is_array( $job['effect_publication'] ?? null )
					? $job['effect_publication']
					: array();
				if (
					'publication_failed' === (string) ( $job['status'] ?? '' )
					|| 'failed' === (string) ( $publication['status'] ?? '' )
				) {
					return true;
				}
				$publication['schedule_failures']        = 0;
				$publication['last_schedule_failure_at'] = 0;
				$now                                     = time();
				if (
					'published' !== (string) ( $publication['status'] ?? '' )
					&& (int) ( $publication['next_attempt_at'] ?? 0 ) > $now
				) {
					$retry_at = (int) $publication['next_attempt_at'];

					return null;
				}
				$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
				if ( is_wp_error( $state ) ) {
					return $this->record_publication_failure_open_lock(
						$job,
						$expected,
						$publication,
						$state,
						$retry_at
					);
				}
				$current_revision = (string) ( $state['state_revision'] ?? '' );
				$superseded       = ! hash_equals( (string) $state_revision, $current_revision );
				if ( 'published' !== (string) ( $publication['status'] ?? '' ) ) {
					$published = Digitalogic_Excel_Pricing_Sync::instance()->publish_internal_settings_effect(
						(array) ( $publication['payload'] ?? array() ),
						$superseded,
						$current_revision
					);
					if ( is_wp_error( $published ) ) {
						return $this->record_publication_failure_open_lock(
							$job,
							$expected,
							$publication,
							$published,
							$retry_at
						);
					}
					$publication['status']          = 'published';
					$publication['last_error']      = '';
					$publication['next_attempt_at'] = 0;
					$publication['published_at']    = time();
					$job['effect_publication']      = $publication;
				}
				$confirmed = (array) ( $job['confirmed_currency'] ?? array() );
				foreach ( (array) ( $job['desired_currency'] ?? array() ) as $field => $value ) {
					if ( in_array( $field, array( 'dollar_price', 'yuan_price' ), true ) ) {
						$confirmed[ $field ] = (int) $value;
					}
				}
				ksort( $confirmed );
				$job['confirmed_currency']           = $confirmed;
				$job['committed_state_revision']     = (string) $state_revision;
				$job['status']                       = 'confirmed';
				$job['message_fa']                   = $superseded
					? 'این تغییر با موفقیت ثبت شد و سپس تغییر تازه‌تری روی سایت اعمال شد.'
					: 'نرخ و قیمت‌های سایت تأیید شد.';
				$job['superseded_by_state_revision'] = $superseded ? $current_revision : '';
				$job['completed_at']                 = time();
				$job['updated_at']                   = time();
				$job['owner_token']                  = '';
				$job['fence_token']                  = '';
				$job['lease_until']                  = 0;
				$job['next_attempt_at']              = 0;
				$job['error_code']                   = '';
				$stored                              = $this->store_job_open_lock( $job, $expected );
				if ( ! is_wp_error( $stored ) ) {
					$this->unschedule_job( $job );
					$deliver_event = true;
				}

				return $stored;
			}
		);

		if ( 0 < $retry_at && ! $cli_sync ) {
			$this->ensure_action(
				self::FINALIZE_HOOK,
				$retry_at,
				array( (string) $job_id, (int) $generation, (int) $expected_fence, (string) $state_revision )
			);
		} elseif ( $this->is_job_transition_retryable( $result ) && ! $cli_sync ) {
			$this->ensure_action(
				self::FINALIZE_HOOK,
				time() + 2,
				array( (string) $job_id, (int) $generation, (int) $expected_fence, (string) $state_revision )
			);
		}
		if ( $deliver_event ) {
			try {
				Digitalogic_Pricing_Snapshot::instance()->run_state_revision_event_delivery();
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
	}

	/**
	 * Persist one bounded post-commit publication failure without re-actuating pricing.
	 *
	 * @param array    $job         Exact committed job.
	 * @param array    $expected    Record read before mutation.
	 * @param array    $publication Durable publication marker.
	 * @param WP_Error $failure     Publication failure.
	 * @param int      $retry_at    Next retry timestamp, updated by reference.
	 * @return true|WP_Error
	 */
	private function record_publication_failure_open_lock( array $job, array $expected, array $publication, $failure, &$retry_at ) {
		$now      = time();
		$attempts = max( 0, (int) ( $publication['attempts'] ?? 0 ) ) + 1;
		$terminal = $attempts >= self::MAX_PUBLICATION_ATTEMPTS;
		$code     = is_wp_error( $failure )
			? sanitize_key( (string) $failure->get_error_code() )
			: 'digitalogic_currency_async_publication_failed';

		$publication['status']          = $terminal ? 'failed' : 'pending';
		$publication['attempts']        = $attempts;
		$publication['last_attempt_at'] = $now;
		$publication['last_error']      = $code;
		$publication['next_attempt_at'] = $terminal
			? 0
			: $now + $this->publication_retry_delay( $attempts );
		$publication['failed_at']       = $terminal ? $now : 0;
		$job['effect_publication']      = $publication;
		$job['updated_at']              = $now;
		$job['next_attempt_at']         = (int) $publication['next_attempt_at'];

		if ( $terminal ) {
			$job['status']       = 'publication_failed';
			$job['completed_at'] = $now;
			$job['owner_token']  = '';
			$job['fence_token']  = '';
			$job['lease_until']  = 0;
			$job['error_code']   = 'digitalogic_currency_async_publication_exhausted';
			$job['message_fa']   = 'نرخ و قیمت‌های سایت ثبت شد، اما انتشار وضعیت پس از تلاش‌های محدود کامل نشد؛ مدیر سیستم باید مسیر رویداد و گزارش را بررسی کند.';
		} else {
			$job['error_code'] = $code;
			$job['message_fa'] = sprintf(
				'نرخ و قیمت‌های سایت ثبت شد؛ انتشار وضعیت در تلاش %1$d از %2$d دوباره بررسی می‌شود.',
				$attempts,
				self::MAX_PUBLICATION_ATTEMPTS
			);
		}

		$stored = $this->store_job_open_lock( $job, $expected );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		if ( $terminal ) {
			$this->unschedule_job( $job );

			return true;
		}

		$retry_at = (int) $publication['next_attempt_at'];

		return $failure;
	}

	/**
	 * Return the exponential, capped delay after one failed publication attempt.
	 *
	 * @param int $attempts Completed publication attempts.
	 * @return int
	 */
	private function publication_retry_delay( $attempts ) {
		$exponent = max( 0, min( 10, (int) $attempts - 1 ) );
		$delay    = self::PUBLICATION_RETRY_BASE_SECONDS * ( 2 ** $exponent );

		return min( self::PUBLICATION_RETRY_MAX_SECONDS, (int) $delay );
	}

	/**
	 * Recover an expired lease or terminalize a job that exceeded its deadline.
	 *
	 * @param string $job_id         Exact durable job identifier.
	 * @param int    $generation     Exact job generation.
	 * @param int    $expected_fence Zero for the deadline watchdog, otherwise an exact lease fence.
	 * @return void
	 */
	public function run_watchdog( $job_id, $generation = 0, $expected_fence = 0 ) {
		if ( (int) $generation < 1 ) {
			return;
		}
		$committed   = array();
		$should_wake = false;
		$result      = $this->with_job_lock(
			function () use ( $job_id, $generation, $expected_fence, &$committed, &$should_wake ) {
				$job = $this->raw_job();
				if ( ! $this->matches_job( $job, $job_id, $generation ) ) {
					return null;
				}
				if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $job['effect_state_revision'] ?? '' ) ) ) {
					$committed = $job;

					return null;
				}
				if ( $this->is_terminal( $job ) ) {
					return null;
				}
				if ( 0 < $expected_fence && (int) ( $job['fence'] ?? 0 ) !== $expected_fence ) {
					return null;
				}

				$now = time();
				if (
					! empty( $job['cancel_requested'] )
					&& (
						'queued' === (string) ( $job['status'] ?? '' )
						|| (
							'running' === (string) ( $job['status'] ?? '' )
							&& (int) ( $job['lease_until'] ?? 0 ) <= $now
						)
					)
				) {
					$this->cancel_open_lock( $job, (string) ( $job['primary_request_id'] ?? '' ) );

					return null;
				}
				$retained = $this->retain_running_job_while_pricing_lock_held( $job, $now );
				if ( is_wp_error( $retained ) ) {
					return $retained;
				}
				if ( true === $retained ) {
					return null;
				}
				if ( (int) ( $job['deadline_at'] ?? 0 ) <= $now ) {
					$this->fail_open_lock(
						$job,
						'digitalogic_currency_async_deadline_exceeded',
						'مهلت تکمیل تغییر نرخ پایان یافت؛ مقدار تأییدشدهٔ قبلی حفظ شد.'
					);

					return null;
				}

				if ( 'running' === (string) ( $job['status'] ?? '' ) ) {
					if ( (int) ( $job['lease_until'] ?? 0 ) > $now ) {
						$this->schedule_action(
							self::WATCHDOG_HOOK,
							(int) $job['lease_until'] + 1,
							$this->watchdog_args( $job, (int) $job['fence'] )
						);

						return null;
					}
					$expected               = $job;
					$job['status']          = 'queued';
					$job['owner_token']     = '';
					$job['fence_token']     = '';
					$job['lease_until']     = 0;
					$job['next_attempt_at'] = $now;
					$job['updated_at']      = $now;
					$job['error_code']      = 'digitalogic_currency_async_lease_expired';
					$job['message_fa']      = 'اجرای قبلی پاسخ نداد؛ تلاش تازه با مالکیت جدید آغاز می‌شود.';
					$stored                 = $this->store_job_open_lock( $job, $expected );
					if ( is_wp_error( $stored ) ) {
						return $stored;
					}
				}

				if ( ! $this->is_cli_sync_job( $job ) ) {
					$timestamp = max( $now, (int) ( $job['next_attempt_at'] ?? $now ) );
					$this->schedule_action( self::APPLY_HOOK, $timestamp, $this->apply_args( $job ) );
					$should_wake = $this->wake_worker_open_lock( $job );
				}

				return null;
			}
		);
		if ( $should_wake ) {
			$this->wake_local_cron();
		}

		if ( $this->is_job_transition_retryable( $result ) ) {
			$this->schedule_action(
				self::WATCHDOG_HOOK,
				time() + 2,
				array( (string) $job_id, (int) $generation, (int) $expected_fence )
			);
		}
		if ( $committed ) {
			$this->finalize_job(
				(string) $committed['job_id'],
				(int) $committed['generation'],
				(int) ( $committed['fence'] ?? 0 ),
				(string) $committed['effect_state_revision']
			);
		}
	}

	/**
	 * Decide whether a failed apply is safe to retry against freshly loaded state.
	 *
	 * @param mixed $result Coordinator result.
	 * @return bool
	 */
	private function is_retryable_apply_error( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		if ( in_array( $result->get_error_code(), array( 'digitalogic_product_sync_busy', 'digitalogic_excel_sync_busy' ), true ) ) {
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

	/**
	 * Keep the current fence while any canonical pricing transaction remains active.
	 *
	 * This method runs only while the job mutex is held. MySQL releases the
	 * pricing advisory lock with its connection, so a retained owner represents
	 * active work rather than a stale process marker.
	 *
	 * @param array $job Durable private job.
	 * @param int   $now Current Unix timestamp.
	 * @return bool|WP_Error True when retained, false when no retention is needed.
	 */
	private function retain_running_job_while_pricing_lock_held( array $job, $now ) {
		if (
			'running' !== (string) ( $job['status'] ?? '' )
			|| (int) ( $job['lease_until'] ?? 0 ) > (int) $now
			|| ! Digitalogic_Excel_Pricing_Sync::coordination_lock_is_held()
		) {
			return false;
		}

		$expected           = $job;
		$job['lease_until'] = (int) $now + self::LEASE_SECONDS;
		$job['deadline_at'] = max( (int) ( $job['deadline_at'] ?? 0 ), $job['lease_until'] + 1 );
		$job['updated_at']  = (int) $now;
		$job['error_code']  = '';
		$job['message_fa']  = 'تراکنش قیمت فعال هنوز مالک اجرا است؛ همان fence بدون ایجاد تلاش هم‌پوشان ادامه می‌دهد.';
		$stored             = $this->store_job_open_lock( $job, $expected );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$this->schedule_action(
			self::WATCHDOG_HOOK,
			$job['lease_until'] + 1,
			$this->watchdog_args( $job, (int) $job['fence'] )
		);

		return true;
	}

	/**
	 * Whether a tiny job-row transition should be retried after a bounded delay.
	 *
	 * @param mixed $result Job-row transition result.
	 * @return bool
	 */
	private function is_job_transition_retryable( $result ) {
		return is_wp_error( $result )
			&& in_array(
				$result->get_error_code(),
				array(
					'digitalogic_currency_async_job_lock_busy',
					'digitalogic_currency_async_job_cas_conflict',
				),
				true
			);
	}

	/**
	 * Return a read-only public status projection.
	 *
	 * @param string $expected_job_id     Optional client job identifier.
	 * @param int    $expected_generation Optional client generation.
	 * @param bool   $active_only         Hide terminal and expired historical records.
	 * @return array
	 */
	public function status( $expected_job_id = '', $expected_generation = 0, $active_only = false ) {
		$this->recover_committed_publication();
		$this->recover_queued_job();
		$job = $this->raw_job();
		if (
			( '' === (string) $expected_job_id && 0 < (int) $expected_generation )
			|| ( '' !== (string) $expected_job_id && (int) $expected_generation < 1 )
		) {
			return array(
				'status'     => 'invalid_identity',
				'message_fa' => 'شناسهٔ کامل کار لازم است.',
				'progress'   => 100,
				'blocking'   => true,
				'error_code' => 'digitalogic_currency_async_identity_invalid',
			);
		}
		if ( $active_only ) {
			$status    = (string) ( $job['status'] ?? '' );
			$committed = 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $job['effect_state_revision'] ?? '' ) );
			$projected = $job ? (string) $this->public_job( $job )['status'] : 'idle';
			$expired   = ! $committed
				&& in_array( $status, array( 'queued', 'running' ), true )
				&& (int) ( $job['deadline_at'] ?? 0 ) < time();
			if ( $expired ) {
				$job['status']     = 'failed';
				$job['error_code'] = 'digitalogic_currency_async_observed_deadline_exceeded';
				$job['message_fa'] = 'مهلت کار قیمت پایان یافته و صفحه آزاد است؛ وضعیت worker را بررسی و سپس دوباره تلاش کنید.';
			} elseif ( ! in_array( $projected, array( 'queued', 'running', 'cancelling', 'publishing', 'publication_failed' ), true ) ) {
				$job = array();
			}
		}
		if ( ! $job ) {
			return array(
				'status'     => 'idle',
				'message_fa' => 'آماده',
				'progress'   => 0,
				'blocking'   => false,
				'error_code' => '',
			);
		}
		if (
			( '' !== $expected_job_id && ! hash_equals( (string) ( $job['job_id'] ?? '' ), $expected_job_id ) )
			|| ( 0 < $expected_generation && (int) ( $job['generation'] ?? 0 ) !== $expected_generation )
		) {
			return array(
				'job_id'             => $expected_job_id,
				'generation'         => $expected_generation,
				'status'             => 'superseded',
				'message_fa'         => 'این درخواست با درخواست تازه‌تری جایگزین شده است.',
				'progress'           => 100,
				'blocking'           => false,
				'error_code'         => 'digitalogic_currency_async_superseded',
				'confirmed_currency' => (array) ( $job['confirmed_currency'] ?? array() ),
			);
		}

		return $this->public_job( $job );
	}

	/**
	 * Return one durable job by the caller's explicit idempotency identity.
	 *
	 * @param string $request_id Explicit request identity.
	 * @param bool   $active_only Hide terminal historical results.
	 * @return array|WP_Error
	 */
	public function status_by_request( $request_id, $active_only = false ) {
		$request_id = $this->normalize_request_id( $request_id );
		if ( is_wp_error( $request_id ) || '' === $request_id ) {
			return is_wp_error( $request_id )
				? $request_id
				: new WP_Error(
					'digitalogic_currency_async_request_id_required',
					'شناسهٔ درخواست لازم است.',
					array(
						'status'   => 400,
						'blocking' => true,
					)
				);
		}

		$this->recover_committed_publication();
		$this->recover_queued_job();
		$job   = $this->raw_job();
		$alias = (array) ( ( $job['request_aliases'] ?? array() )[ $request_id ] ?? array() );
		if ( ! $alias ) {
			return new WP_Error(
				'digitalogic_currency_async_request_not_found',
				'درخواستی با این شناسه پیدا نشد.',
				array(
					'status'   => 404,
					'blocking' => false,
				)
			);
		}
		if ( $this->alias_targets_job( $alias, $job ) ) {
			$projection = $this->public_job_for_request( $job, $request_id );
			if ( $active_only && $this->is_terminal( $job ) ) {
				return array(
					'status'     => 'idle',
					'message_fa' => 'آماده',
					'progress'   => 0,
					'blocking'   => false,
					'error_code' => '',
				);
			}

			return $projection;
		}

		$historical = is_array( $alias['result'] ?? null ) ? $alias['result'] : array();
		if ( ! $historical ) {
			return new WP_Error(
				'digitalogic_currency_async_request_history_incomplete',
				'نتیجهٔ پایدار این درخواست در دسترس نیست.',
				array(
					'status'   => 409,
					'blocking' => true,
				)
			);
		}
		$historical['request_id'] = $request_id;
		$historical['replayed']   = true;

		return $historical;
	}

	/**
	 * Cooperatively cancel one exact uncommitted currency job.
	 *
	 * A queued job becomes terminal immediately. A running worker keeps its
	 * fence until the in-transaction guard observes the request and rolls back.
	 * A committed effect can only finish publication and is never compensated.
	 *
	 * @param string $expected_job_id     Exact job ID, or empty with request ID.
	 * @param int    $expected_generation Exact generation, or zero with request ID.
	 * @param string $request_id          Optional idempotency identity.
	 * @return array|WP_Error
	 */
	public function cancel( $expected_job_id = '', $expected_generation = 0, $request_id = '' ) {
		$request_id = $this->normalize_request_id( $request_id );
		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}
		$expected_job_id     = sanitize_text_field( (string) $expected_job_id );
		$expected_generation = absint( $expected_generation );
		if (
			'' === $request_id
			&& ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $expected_job_id ) || $expected_generation < 1 )
		) {
			return new WP_Error(
				'digitalogic_currency_async_identity_invalid',
				'شناسهٔ کامل کار یا شناسهٔ درخواست لازم است.',
				array(
					'status'   => 400,
					'blocking' => true,
				)
			);
		}

		return $this->with_job_lock(
			function () use ( $expected_job_id, $expected_generation, $request_id ) {
				$job = $this->raw_job();
				if ( '' !== $request_id ) {
					$alias = (array) ( ( $job['request_aliases'] ?? array() )[ $request_id ] ?? array() );
					if ( ! $alias ) {
						return new WP_Error(
							'digitalogic_currency_async_request_not_found',
							'درخواستی با این شناسه پیدا نشد.',
							array(
								'status'   => 404,
								'blocking' => false,
							)
						);
					}
					if ( ! $this->alias_targets_job( $alias, $job ) ) {
						$historical = is_array( $alias['result'] ?? null ) ? $alias['result'] : array();
						if ( $historical ) {
							$historical['request_id']  = $request_id;
							$historical['replayed']    = true;
							$historical['cancellable'] = false;

							return $historical;
						}
						return new WP_Error(
							'digitalogic_currency_async_request_history_incomplete',
							'نتیجهٔ پایدار این درخواست در دسترس نیست.',
							array(
								'status'   => 409,
								'blocking' => true,
							)
						);
					}
				} elseif ( ! $this->matches_job( $job, $expected_job_id, $expected_generation ) ) {
					return new WP_Error(
						'digitalogic_currency_async_superseded',
						'این کار با نسل تازه‌تری جایگزین شده است.',
						array(
							'status'   => 409,
							'blocking' => false,
						)
					);
				}

				$marker = (string) ( $job['effect_state_revision'] ?? '' );
				if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $marker ) ) {
					return new WP_Error(
						'digitalogic_currency_async_cancel_too_late',
						'اثر نرخ ثبت شده است و فقط انتشار نتیجهٔ همان اثر باید تکمیل شود.',
						array(
							'status'   => 409,
							'blocking' => false,
							'job'      => $this->public_job_for_request( $job, $request_id ),
						)
					);
				}
				if ( $this->is_terminal( $job ) ) {
					return $this->public_job_for_request( $job, $request_id, array( 'replayed' => true ) );
				}
				if ( 'queued' === (string) ( $job['status'] ?? '' ) ) {
					return $this->cancel_open_lock( $job, $request_id );
				}
				if ( 'running' !== (string) ( $job['status'] ?? '' ) ) {
					return new WP_Error(
						'digitalogic_currency_async_cancel_unavailable',
						'این کار در وضعیت قابل لغو نیست.',
						array(
							'status'   => 409,
							'blocking' => false,
						)
					);
				}

				$expected                   = $job;
				$job['cancel_requested']    = true;
				$job['cancel_requested_at'] = time();
				$job['updated_at']          = time();
				$job['message_fa']          = 'درخواست لغو ثبت شد؛ worker پیش از ثبت اثر متوقف می‌شود.';
				$stored                     = $this->store_job_open_lock( $job, $expected );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}

				return $this->public_job_for_request( $job, $request_id );
			}
		);
	}

	/**
	 * Kick a valid committed marker into its tiny publication finalizer.
	 *
	 * This never re-enters pricing actuation. It only schedules the exact
	 * generation/fence marker and wakes local cron without waiting for it.
	 *
	 * @return bool Whether recovery was needed and could be scheduled.
	 */
	public function recover_committed_publication() {
		$observed    = $this->raw_job();
		$revision    = (string) ( $observed['effect_state_revision'] ?? '' );
		$publication = is_array( $observed['effect_publication'] ?? null ) ? $observed['effect_publication'] : array();
		if (
			1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $revision )
			|| 'confirmed' === (string) ( $observed['status'] ?? '' )
			|| 'publication_failed' === (string) ( $observed['status'] ?? '' )
			|| 'failed' === (string) ( $publication['status'] ?? '' )
			|| '' === (string) ( $observed['job_id'] ?? '' )
			|| (int) ( $observed['generation'] ?? 0 ) < 1
			|| (int) ( $observed['fence'] ?? 0 ) < 1
		) {
			return false;
		}
		if ( $this->is_cli_sync_job( $observed ) && time() <= (int) ( $observed['deadline_at'] ?? 0 ) ) {
			return false;
		}

		$now       = time();
		$timestamp = max( $now, (int) ( $publication['next_attempt_at'] ?? 0 ) );
		$args      = array(
			(string) $observed['job_id'],
			(int) $observed['generation'],
			(int) $observed['fence'],
			$revision,
		);
		$scheduled = $this->action_is_scheduled( self::FINALIZE_HOOK, $args );
		if ( ! $scheduled ) {
			$last_failure = max( 0, (int) ( $publication['last_schedule_failure_at'] ?? 0 ) );
			if ( 0 < $last_failure && $now - $last_failure < self::PUBLICATION_RETRY_BASE_SECONDS ) {
				return true;
			}
			$scheduled = $this->schedule_action( self::FINALIZE_HOOK, $timestamp, $args );
			if ( ! $scheduled ) {
				$result = $this->with_job_lock(
					function () use ( $observed, $revision ) {
						$job = $this->raw_job();
						if (
							! $this->matches_job( $job, (string) $observed['job_id'], (int) $observed['generation'] )
							|| ! hash_equals( $revision, (string) ( $job['effect_state_revision'] ?? '' ) )
							|| 'confirmed' === (string) ( $job['status'] ?? '' )
							|| 'publication_failed' === (string) ( $job['status'] ?? '' )
						) {
							return false;
						}
						$publication = is_array( $job['effect_publication'] ?? null ) ? $job['effect_publication'] : array();
						$now         = time();
						$last        = max( 0, (int) ( $publication['last_schedule_failure_at'] ?? 0 ) );
						if ( 0 < $last && $now - $last < self::PUBLICATION_RETRY_BASE_SECONDS ) {
							return true;
						}
						$expected                                = $job;
						$failures                                = max( 0, (int) ( $publication['schedule_failures'] ?? 0 ) ) + 1;
						$terminal                                = $failures >= self::MAX_DISPATCH_ATTEMPTS;
						$publication['schedule_failures']        = $failures;
						$publication['last_schedule_failure_at'] = $now;
						$publication['last_error']               = $terminal
							? 'digitalogic_currency_async_publication_schedule_exhausted'
							: 'digitalogic_currency_async_publication_schedule_failed';
						$publication['status']                   = $terminal ? 'failed' : 'pending';
						$publication['failed_at']                = $terminal ? $now : 0;
						$publication['next_attempt_at']          = $terminal ? 0 : (int) ( $publication['next_attempt_at'] ?? $now );
						$job['effect_publication']               = $publication;
						$job['updated_at']                       = $now;
						$job['error_code']                       = (string) $publication['last_error'];
						$job['message_fa']                       = $terminal
							? 'نرخ و قیمت‌های سایت ثبت شد، اما زمان‌بندی worker انتشار پس از تلاش‌های محدود ممکن نشد؛ مدیر سیستم باید صف زمان‌بندی را بررسی کند.'
							: sprintf(
								'نرخ و قیمت‌های سایت ثبت شد؛ زمان‌بندی انتشار در تلاش %1$d از %2$d دوباره بررسی می‌شود.',
								$failures,
								self::MAX_DISPATCH_ATTEMPTS
							);
						if ( $terminal ) {
							$job['status']          = 'publication_failed';
							$job['completed_at']    = $now;
							$job['next_attempt_at'] = 0;
							$job['owner_token']     = '';
							$job['fence_token']     = '';
							$job['lease_until']     = 0;
						}
						$stored = $this->store_job_open_lock( $job, $expected );
						if ( $terminal && ! is_wp_error( $stored ) ) {
							$this->unschedule_job( $job );
						}

						return $stored;
					},
					0
				);

				return ! is_wp_error( $result ) && false !== $result;
			}
		}
		if ( $timestamp > $now ) {
			return true;
		}

		$should_wake = false;
		$result      = $this->with_job_lock(
			function () use ( $observed, $revision, &$should_wake ) {
				$job = $this->raw_job();
				if (
					! $this->matches_job( $job, (string) $observed['job_id'], (int) $observed['generation'] )
					|| ! hash_equals( $revision, (string) ( $job['effect_state_revision'] ?? '' ) )
					|| 'confirmed' === (string) ( $job['status'] ?? '' )
					|| 'publication_failed' === (string) ( $job['status'] ?? '' )
				) {
					return false;
				}
				$publication = is_array( $job['effect_publication'] ?? null ) ? $job['effect_publication'] : array();
				$now         = time();
				if (
					'failed' === (string) ( $publication['status'] ?? '' )
					|| (int) ( $publication['next_attempt_at'] ?? 0 ) > $now
				) {
					return false;
				}

				$attempts = max( 0, (int) ( $publication['dispatch_attempts'] ?? 0 ) );
				$last     = max( 0, (int) ( $publication['last_dispatch_at'] ?? 0 ) );
				if ( $now - $last < self::DISPATCH_RETRY_SECONDS ) {
					return true;
				}
				$expected = $job;
				if ( $attempts >= self::MAX_DISPATCH_ATTEMPTS ) {
					$publication['status']          = 'failed';
					$publication['last_error']      = 'digitalogic_currency_async_publication_dispatch_exhausted';
					$publication['next_attempt_at'] = 0;
					$publication['failed_at']       = $now;
					$job['effect_publication']      = $publication;
					$job['status']                  = 'publication_failed';
					$job['completed_at']            = $now;
					$job['updated_at']              = $now;
					$job['next_attempt_at']         = 0;
					$job['owner_token']             = '';
					$job['fence_token']             = '';
					$job['lease_until']             = 0;
					$job['error_code']              = 'digitalogic_currency_async_publication_dispatch_exhausted';
					$job['message_fa']              = 'نرخ و قیمت‌های سایت ثبت شد، اما worker انتشار پس از تلاش‌های محدود بیدار نشد؛ مدیر سیستم باید مسیر cron را بررسی کند.';
					$stored                         = $this->store_job_open_lock( $job, $expected );
					if ( ! is_wp_error( $stored ) ) {
						$this->unschedule_job( $job );
					}

					return $stored;
				}

				$publication['dispatch_attempts'] = $attempts + 1;
				$publication['last_dispatch_at']  = $now;
				$job['effect_publication']        = $publication;
				$job['updated_at']                = $now;
				$stored                           = $this->store_job_open_lock( $job, $expected );
				if ( ! is_wp_error( $stored ) ) {
					$should_wake = true;
				}

				return $stored;
			},
			0
		);
		if ( $should_wake ) {
			$this->wake_local_cron();
		}

		return ! is_wp_error( $result ) && false !== $result;
	}

	/**
	 * Repair the exact apply/deadline actions for one durable queued generation.
	 *
	 * The quick observation pass avoids taking the mutex when both actions and a
	 * recent wake already exist. Recovery never claims or actuates the job; it
	 * only restores its exact schedules and performs a bounded local cron wake.
	 *
	 * @return bool Whether the queued generation was recovered or re-woken.
	 */
	public function recover_queued_job() {
		$observed = $this->raw_job();
		if (
			'queued' !== (string) ( $observed['status'] ?? '' )
			|| '' === (string) ( $observed['job_id'] ?? '' )
			|| (int) ( $observed['generation'] ?? 0 ) < 1
			|| 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $observed['effect_state_revision'] ?? '' ) )
		) {
			return false;
		}
		if ( $this->is_cli_sync_job( $observed ) && time() <= (int) ( $observed['deadline_at'] ?? 0 ) ) {
			return false;
		}

		$now              = time();
		$deadline_at      = (int) ( $observed['deadline_at'] ?? 0 );
		$deadline_due     = $deadline_at <= $now;
		$apply_at         = max( $now, (int) ( $observed['next_attempt_at'] ?? $now ) );
		$apply_missing    = ! $deadline_due && ! $this->action_is_scheduled( self::APPLY_HOOK, $this->apply_args( $observed ) );
		$watchdog_args    = $this->watchdog_args( $observed, 0 );
		$watchdog_missing = ! $this->action_is_scheduled( self::WATCHDOG_HOOK, $watchdog_args );
		$should_wake      = ! $deadline_due
			&& $apply_at <= $now
			&& (int) ( $observed['dispatch_attempts'] ?? 0 ) < self::MAX_DISPATCH_ATTEMPTS
			&& $now - (int) ( $observed['last_dispatch_at'] ?? 0 ) >= self::DISPATCH_RETRY_SECONDS;
		if ( ! $deadline_due && ! $apply_missing && ! $watchdog_missing && ! $should_wake ) {
			return false;
		}

		$should_wake = false;
		$result      = $this->with_job_lock(
			function () use ( $observed, &$should_wake ) {
				$job = $this->raw_job();
				if (
					! $this->matches_job( $job, (string) $observed['job_id'], (int) $observed['generation'] )
					|| 'queued' !== (string) ( $job['status'] ?? '' )
					|| 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $job['effect_state_revision'] ?? '' ) )
				) {
					return false;
				}

				$now          = time();
				$deadline_at  = (int) ( $job['deadline_at'] ?? 0 );
				$deadline_due = $deadline_at <= $now;
				$apply_at     = max( $now, (int) ( $job['next_attempt_at'] ?? $now ) );
				if ( $deadline_due ) {
					$this->fail_open_lock(
						$job,
						'digitalogic_currency_async_deadline_exceeded',
						'مهلت تکمیل تغییر نرخ پایان یافت؛ مقدار تأییدشدهٔ قبلی حفظ شد.'
					);

					return true;
				}
				$apply_ready   = $deadline_due || $this->ensure_action( self::APPLY_HOOK, $apply_at, $this->apply_args( $job ) );
				$watch_created = false;
				$watch_ready   = $this->ensure_action(
					self::WATCHDOG_HOOK,
					max( $now, $deadline_at ),
					$this->watchdog_args( $job, 0 ),
					$watch_created
				);
				if ( ! $apply_ready || ! $watch_ready ) {
					return false;
				}
				if ( $apply_at <= $now ) {
					$should_wake = $this->wake_worker_open_lock( $job );
				}

				return true;
			},
			0
		);
		if ( $should_wake ) {
			$this->wake_local_cron();
		}

		return true === $result;
	}

	/** Handle an authenticated rate proposal from the settings page. */
	public function ajax_submit() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! $this->can_manage_currency() ) {
			wp_send_json_error(
				array(
					'code'       => 'digitalogic_currency_async_forbidden',
					'message_fa' => 'دسترسی کافی نیست.',
					'blocking'   => true,
				),
				403
			);
		}

		$reconcile = '1' === sanitize_text_field( wp_unslash( $_POST['reconcile_current'] ?? '' ) );
		$values    = array();
		foreach ( array( 'dollar_price', 'yuan_price' ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$values[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		$revision = sanitize_text_field( wp_unslash( $_POST['expected_state_revision'] ?? '' ) );
		$job      = $this->enqueue_currency(
			$values,
			true,
			$reconcile,
			$revision,
			'admin_ajax',
			sanitize_text_field( wp_unslash( $_POST['request_id'] ?? '' ) )
		);
		if ( is_wp_error( $job ) ) {
			$data        = $job->get_error_data();
			$data        = is_array( $data ) ? $data : array();
			$retry_after = max( 0, (int) ( $data['retry_after'] ?? 0 ) );
			if ( 0 < $retry_after && ! headers_sent() ) {
				header( 'Retry-After: ' . $retry_after );
			}
			$status_code = 'digitalogic_currency_async_value_invalid' === $job->get_error_code()
				? 400
				: ( 0 < $retry_after ? 429 : 409 );
			wp_send_json_error(
				array(
					'code'        => $job->get_error_code(),
					'message_fa'  => $job->get_error_message(),
					'blocking'    => (bool) ( $data['blocking'] ?? false ),
					'retry_after' => $retry_after,
				),
				$status_code
			);
		}

		wp_send_json_success( $job, 202 );
	}

	/** Return an observation-only status for one exact client job. */
	public function ajax_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! $this->can_manage_currency() ) {
			wp_send_json_error(
				array(
					'code'       => 'digitalogic_currency_async_forbidden',
					'message_fa' => 'دسترسی کافی نیست.',
					'blocking'   => true,
				),
				403
			);
		}

		$job_id      = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) );
		$generation  = absint( wp_unslash( $_POST['generation'] ?? 0 ) );
		$request_id  = sanitize_text_field( wp_unslash( $_POST['request_id'] ?? '' ) );
		$active_only = '1' === sanitize_text_field( wp_unslash( $_POST['active_only'] ?? '' ) );
		$result      = '' !== $request_id
			? $this->status_by_request( $request_id, $active_only )
			: $this->status( $job_id, $generation, $active_only );
		if ( is_wp_error( $result ) ) {
			$this->send_ajax_error( $result );
		}
		wp_send_json_success( $result );
	}

	/** Request cooperative cancellation for one exact authenticated admin job. */
	public function ajax_cancel() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! $this->can_manage_currency() ) {
			wp_send_json_error(
				array(
					'code'       => 'digitalogic_currency_async_forbidden',
					'message_fa' => 'دسترسی کافی نیست.',
					'blocking'   => true,
				),
				403
			);
		}

		$result = $this->cancel(
			sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ),
			absint( wp_unslash( $_POST['generation'] ?? 0 ) ),
			sanitize_text_field( wp_unslash( $_POST['request_id'] ?? '' ) )
		);
		if ( is_wp_error( $result ) ) {
			$this->send_ajax_error( $result );
		}
		wp_send_json_success( $result, 202 );
	}

	/**
	 * Emit one bounded machine-readable Ajax failure.
	 *
	 * @param WP_Error $error Exact failure.
	 * @return void
	 */
	private function send_ajax_error( WP_Error $error ) {
		$data        = $error->get_error_data();
		$data        = is_array( $data ) ? $data : array();
		$retry_after = max( 0, (int) ( $data['retry_after'] ?? 0 ) );
		$status      = isset( $data['status'] ) ? (int) $data['status'] : ( 0 < $retry_after ? 503 : 409 );
		if ( 0 < $retry_after && ! headers_sent() ) {
			header( 'Retry-After: ' . $retry_after );
		}
		wp_send_json_error(
			array(
				'code'        => $error->get_error_code(),
				'message_fa'  => $error->get_error_message(),
				'blocking'    => (bool) ( $data['blocking'] ?? false ),
				'retry_after' => $retry_after,
				'details'     => $data,
			),
			$status
		);
	}

	/**
	 * Finalize a failed/retryable actuation only while the exact claim remains current.
	 *
	 * @param array    $claim Exact private worker claim.
	 * @param WP_Error $error Coordinator error.
	 * @return void
	 */
	private function complete_failed_attempt( array $claim, WP_Error $error ) {
		$committed = array();
		$result    = $this->with_job_lock(
			function () use ( $claim, $error, &$committed ) {
				$job    = $this->raw_job();
				$marker = (string) ( $job['effect_state_revision'] ?? '' );
				if (
					$this->same_generation( $job, $claim )
					&& (int) ( $job['fence'] ?? 0 ) === (int) ( $claim['fence'] ?? 0 )
					&& 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $marker )
				) {
					$committed = $job;

					return true;
				}
				if ( ! $this->owns_claim_open_lock( $claim ) ) {
					return null;
				}
				if (
					'digitalogic_currency_async_cancel_requested' === $error->get_error_code()
					&& ! empty( $job['cancel_requested'] )
				) {
					return $this->cancel_open_lock( $job, (string) ( $job['primary_request_id'] ?? '' ) );
				}
				if (
					$this->is_retryable_apply_error( $error )
					&& (int) ( $job['apply_attempts'] ?? 0 ) < self::MAX_APPLY_ATTEMPTS
					&& time() < (int) ( $job['deadline_at'] ?? 0 )
				) {
					$expected               = $job;
					$delay                  = min( 30, 2 ** max( 0, (int) $job['apply_attempts'] - 1 ) );
					$job['status']          = 'queued';
					$job['owner_token']     = '';
					$job['fence_token']     = '';
					$job['lease_until']     = 0;
					$job['next_attempt_at'] = time() + $delay;
					$job['updated_at']      = time();
					$job['error_code']      = (string) $error->get_error_code();
					$job['message_fa']      = sprintf(
						'داده‌های قیمت در حال به‌روزرسانی است؛ تلاش دوباره %1$d از %2$d زمان‌بندی شد.',
						(int) $job['apply_attempts'] + 1,
						self::MAX_APPLY_ATTEMPTS
					);
					$stored                 = $this->store_job_open_lock( $job, $expected );
					if ( is_wp_error( $stored ) ) {
						return $stored;
					}
					if (
						! $this->is_cli_sync_job( $job )
						&& ! $this->schedule_action( self::APPLY_HOOK, $job['next_attempt_at'], $this->apply_args( $job ) )
					) {
						return $this->fail_open_lock(
							$job,
							'digitalogic_currency_async_retry_schedule_failed',
							'تلاش دوبارهٔ کار قیمت زمان‌بندی نشد؛ مقدار تأییدشدهٔ قبلی حفظ شد.'
						);
					}

					return true;
				}

				return $this->fail_claim_open_lock(
					$job,
					(string) $error->get_error_code(),
					'ثبت نرخ کامل نشد: ' . $error->get_error_message()
				);
			}
		);

		if ( $this->is_job_transition_retryable( $result ) ) {
			$this->schedule_action(
				self::WATCHDOG_HOOK,
				time() + 2,
				$this->watchdog_args( $claim, (int) ( $claim['fence'] ?? 0 ) )
			);
		}
		if ( $committed ) {
			$this->finalize_job(
				(string) $committed['job_id'],
				(int) $committed['generation'],
				(int) ( $committed['fence'] ?? 0 ),
				(string) $committed['effect_state_revision']
			);
		}
	}

	/**
	 * Validate the exact worker claim from the database inside the pricing transaction.
	 *
	 * The locking read is authoritative; WordPress option caches are not used for
	 * safety decisions. The final phase writes an effect marker in the same SQL
	 * transaction as rates and products, closing the commit/completion crash gap.
	 *
	 * @param array $claim              Exact private worker claim.
	 * @param bool  $mark_effect_commit Whether to persist the atomic commit marker.
	 * @param mixed $transaction_result Pricing transaction result before commit.
	 * @return true|WP_Error
	 */
	private function validate_claim_for_actuation( array $claim, $mark_effect_commit, $transaction_result = null ) {
		global $wpdb;

		$table = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$sql   = "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1 FOR UPDATE";
		$row   = $wpdb->get_row( $wpdb->prepare( $sql, self::JOB_OPTION ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Transactional fence row; prepared above.
		$job   = is_array( $row ) && array_key_exists( 'option_value', $row )
			? maybe_unserialize( $row['option_value'] )
			: array();
		$now   = time();
		if (
			! is_array( $job )
			|| ! $this->same_generation( $job, $claim )
			|| 'running' !== (string) ( $job['status'] ?? '' )
			|| ! hash_equals( (string) ( $job['owner_token'] ?? '' ), (string) ( $claim['owner_token'] ?? '' ) )
			|| ! hash_equals( (string) ( $job['fence_token'] ?? '' ), (string) ( $claim['fence_token'] ?? '' ) )
			|| (int) ( $job['fence'] ?? 0 ) !== (int) ( $claim['fence'] ?? 0 )
			|| ( ! $mark_effect_commit && (int) ( $job['lease_until'] ?? 0 ) < $now )
			|| (int) ( $job['deadline_at'] ?? 0 ) < $now
		) {
			return new WP_Error(
				'digitalogic_currency_async_claim_lost',
				'مالکیت یا مهلت کار قیمت پیش از ثبت از دست رفت.',
				array( 'blocking' => true )
			);
		}
		if ( ! empty( $job['cancel_requested'] ) ) {
			return new WP_Error(
				'digitalogic_currency_async_cancel_requested',
				'درخواست لغو پیش از ثبت اثر پذیرفته شد.',
				array( 'blocking' => false )
			);
		}
		if ( ! $mark_effect_commit ) {
			return true;
		}

		$state_revision = is_array( $transaction_result )
			? (string) ( $transaction_result['readback']['state_revision'] ?? '' )
			: '';
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $state_revision ) ) {
			return new WP_Error(
				'digitalogic_currency_async_commit_marker_invalid',
				'نشانگر نتیجهٔ نهایی worker معتبر نیست.',
				array( 'blocking' => true )
			);
		}
		$publication = is_array( $transaction_result['publication'] ?? null )
			? $transaction_result['publication']
			: array();
		if ( ! $publication ) {
			return new WP_Error(
				'digitalogic_currency_async_publication_marker_invalid',
				'اطلاعات بازیابی پس از ثبت worker معتبر نیست.',
				array( 'blocking' => true )
			);
		}
		$effect_id                    = 'sha256:' . hash(
			'sha256',
			(string) $job['job_id'] . "\0"
			. (string) $job['generation'] . "\0"
			. (string) $job['fence'] . "\0"
			. $state_revision
		);
		$publication['effect_id']     = $effect_id;
		$job['effect_id']             = $effect_id;
		$job['effect_state_revision'] = $state_revision;
		$job['effect_committed_at']   = $now;
		$job['effect_publication']    = array(
			'status'                   => 'pending',
			'payload'                  => $publication,
			'attempts'                 => 0,
			'dispatch_attempts'        => 0,
			'last_dispatch_at'         => 0,
			'schedule_failures'        => 0,
			'last_schedule_failure_at' => 0,
			'last_error'               => '',
			'next_attempt_at'          => $now,
			'published_at'             => 0,
		);
		$job['updated_at']            = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Same transaction and locked exact row; cache is invalidated below.
		$updated = $wpdb->update(
			$table,
			array( 'option_value' => maybe_serialize( $job ) ),
			array( 'option_name' => self::JOB_OPTION ),
			array( '%s' ),
			array( '%s' )
		);
		if ( 1 !== (int) $updated ) {
			return new WP_Error(
				'digitalogic_currency_async_commit_marker_store_failed',
				'ثبت نشانگر نهایی worker ممکن نشد.',
				array( 'blocking' => true )
			);
		}
		$this->invalidate_job_cache();

		return true;
	}

	/**
	 * Execute a callback while owning the bounded advisory job mutex.
	 *
	 * @param callable $callback     Critical section.
	 * @param int      $wait_seconds Bounded database wait in seconds.
	 * @return mixed|WP_Error
	 */
	private function with_job_lock( $callback, $wait_seconds = 1 ) {
		global $wpdb;

		$wait_seconds = max( 0, min( 1, (int) $wait_seconds ) );
		$prepared     = $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::JOB_LOCK_NAME, $wait_seconds );
		$acquired     = $wpdb->get_var( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Bounded advisory mutex; prepared above.
		if ( 1 !== (int) $acquired ) {
			return new WP_Error(
				'digitalogic_currency_async_job_lock_busy',
				'کارگر قیمت در حال تکمیل یک درخواست است؛ کمی بعد دوباره تلاش کنید.',
				array(
					'blocking'    => false,
					'retry_after' => 2,
				)
			);
		}

		try {
			return call_user_func( $callback );
		} finally {
			$release = $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::JOB_LOCK_NAME );
			$wpdb->get_var( $release ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Releases the exact advisory mutex; prepared above.
		}
	}

	/**
	 * Return the durable private job record.
	 *
	 * @return array
	 */
	private function raw_job() {
		$record = $this->raw_job_record();

		return $record['job'];
	}

	/**
	 * Return the durable job and its exact serialized compare-and-swap value.
	 *
	 * @return array{job:array,raw:string,exists:bool}
	 */
	private function raw_job_record() {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_row' ) && method_exists( $wpdb, 'prepare' ) ) {
			$table  = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
			$sql    = "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1";
			$row    = $wpdb->get_row( $wpdb->prepare( $sql, self::JOB_OPTION ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Durable worker identity must bypass caches; prepared above.
			$exists = is_array( $row ) && array_key_exists( 'option_value', $row );
			$raw    = $exists ? (string) $row['option_value'] : '';
			$job    = $exists ? maybe_unserialize( $row['option_value'] ) : array();
		} else {
			$sentinel = new stdClass();
			$job      = get_option( self::JOB_OPTION, $sentinel );
			$exists   = $sentinel !== $job;
			$raw      = $exists ? (string) maybe_serialize( $job ) : '';
		}

		$job = is_array( $job ) && ! empty( $job['job_id'] ) ? $job : array();

		return array(
			'job'    => $job,
			'raw'    => $raw,
			'exists' => $exists,
		);
	}

	/**
	 * Store and verify a durable job while the caller owns the job mutex.
	 *
	 * @param array $job      Exact desired private job record.
	 * @param array $expected Exact record read before mutation.
	 * @return true|WP_Error
	 */
	private function store_job_open_lock( array $job, array $expected ) {
		global $wpdb;

		$record  = $this->raw_job_record();
		$current = $record['job'];
		if ( $current !== $expected ) {
			return $this->job_cas_conflict();
		}
		$current_marker     = (string) ( $current['effect_state_revision'] ?? '' );
		$desired_marker     = (string) ( $job['effect_state_revision'] ?? '' );
		$terminal_successor = $this->is_terminal( $current )
			&& '' === $desired_marker
			&& '' !== (string) ( $job['job_id'] ?? '' )
			&& ! hash_equals( (string) ( $current['job_id'] ?? '' ), (string) $job['job_id'] )
			&& (int) ( $current['generation'] ?? 0 ) > 0
			&& (int) ( $job['generation'] ?? 0 ) === (int) $current['generation'] + 1
			&& in_array( (string) ( $job['status'] ?? '' ), array( 'queued', 'confirmed' ), true );
		if (
			'' !== $current_marker
			&& ! hash_equals( $current_marker, $desired_marker )
			&& ! $terminal_successor
		) {
			return $this->job_cas_conflict();
		}

		if (
			! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'query' )
			|| ! method_exists( $wpdb, 'prepare' )
		) {
			return new WP_Error(
				'digitalogic_currency_async_store_unavailable',
				'ذخیرهٔ اتمیک وضعیت کار پس‌زمینه در دسترس نیست.',
				array( 'blocking' => true )
			);
		}
		$table = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$raw   = (string) maybe_serialize( $job );
		if ( ! $record['exists'] ) {
			$inserted = $wpdb->insert(
				$table,
				array(
					'option_name'  => self::JOB_OPTION,
					'option_value' => $raw,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact non-autoloaded worker row under advisory mutex.
			if ( 1 !== (int) $inserted ) {
				return $this->job_cas_conflict();
			}
		} else {
			$sql     = "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s /* digitalogic_currency_job_cas */";
			$updated = $wpdb->query(
				$wpdb->prepare( $sql, $raw, self::JOB_OPTION, (string) $record['raw'] ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact statement is assembled above with a fixed table identifier and placeholders.
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Exact raw-byte CAS; prepared above.
			if ( 1 !== (int) $updated ) {
				$after = $this->raw_job_record();
				if ( $after['job'] !== $job ) {
					return $this->job_cas_conflict();
				}
			}
		}
		$this->invalidate_job_cache();
		$verified = $this->raw_job();
		if ( $verified !== $job ) {
			return new WP_Error(
				'digitalogic_currency_async_store_failed',
				'ثبت وضعیت کار پس‌زمینه ممکن نشد.',
				array( 'blocking' => true )
			);
		}

		return true;
	}

	/** Return one bounded compare-and-swap miss without overwriting newer work. */
	private function job_cas_conflict() {
		return new WP_Error(
			'digitalogic_currency_async_job_cas_conflict',
			'وضعیت کار قیمت هم‌زمان تغییر کرد؛ نتیجهٔ جدیدتر حفظ شد و کار دوباره بررسی می‌شود.',
			array(
				'blocking'    => false,
				'retry_after' => 1,
			)
		);
	}

	/**
	 * Mark one exact job terminal without overwriting a newer generation.
	 *
	 * @param array  $job     Current exact job.
	 * @param string $code    Machine-readable failure code.
	 * @param string $message Persian operator message.
	 * @return array|WP_Error
	 */
	private function fail_open_lock( array $job, $code, $message ) {
		$current = $this->raw_job();
		if ( ! $this->same_generation( $current, $job ) || $this->is_terminal( $current ) ) {
			return $this->public_job( $current );
		}

		$expected                   = $current;
		$current['status']          = 'failed';
		$current['error_code']      = sanitize_key( (string) $code );
		$current['message_fa']      = (string) $message;
		$current['completed_at']    = time();
		$current['updated_at']      = time();
		$current['owner_token']     = '';
		$current['fence_token']     = '';
		$current['lease_until']     = 0;
		$current['next_attempt_at'] = 0;
		$stored                     = $this->store_job_open_lock( $current, $expected );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$this->unschedule_job( $current );

		return new WP_Error(
			$current['error_code'],
			$current['message_fa'],
			array( 'blocking' => false )
		);
	}

	/**
	 * Fenced terminal failure for an owned worker claim.
	 *
	 * @param array  $claim   Exact private claim.
	 * @param string $code    Machine-readable failure code.
	 * @param string $message Persian operator message.
	 * @return array|WP_Error|null
	 */
	private function fail_claim_open_lock( array $claim, $code, $message ) {
		if ( ! $this->owns_claim_open_lock( $claim ) ) {
			return null;
		}

		return $this->fail_open_lock( $claim, $code, $message );
	}

	/**
	 * Whether the durable record is still the exact claimed generation and fence.
	 *
	 * @param array $claim Private claim.
	 * @return bool
	 */
	private function owns_claim_open_lock( array $claim ) {
		$current = $this->raw_job();

		return $this->same_generation( $current, $claim )
			&& 'running' === (string) ( $current['status'] ?? '' )
			&& '' !== (string) ( $claim['owner_token'] ?? '' )
			&& hash_equals( (string) ( $current['owner_token'] ?? '' ), (string) $claim['owner_token'] )
			&& '' !== (string) ( $claim['fence_token'] ?? '' )
			&& hash_equals( (string) ( $current['fence_token'] ?? '' ), (string) $claim['fence_token'] )
			&& (int) ( $current['fence'] ?? 0 ) === (int) ( $claim['fence'] ?? 0 );
	}

	/**
	 * Mark one exact uncommitted job cancelled while holding the job mutex.
	 *
	 * @param array  $job       Exact private job.
	 * @param string $request_id Optional request identity.
	 * @return array|WP_Error
	 */
	private function cancel_open_lock( array $job, $request_id = '' ) {
		$current = $this->raw_job();
		if ( ! $this->same_generation( $current, $job ) ) {
			return new WP_Error(
				'digitalogic_currency_async_job_cas_conflict',
				'وضعیت کار هم‌زمان تغییر کرد؛ نتیجهٔ جدیدتر حفظ شد.',
				array(
					'status'      => 409,
					'blocking'    => false,
					'retry_after' => 1,
				)
			);
		}
		if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $current['effect_state_revision'] ?? '' ) ) ) {
			return new WP_Error(
				'digitalogic_currency_async_cancel_too_late',
				'اثر نرخ ثبت شده است و قابل لغو نیست.',
				array(
					'status'   => 409,
					'blocking' => false,
				)
			);
		}

		$expected                       = $current;
		$current['status']              = 'cancelled';
		$current['cancel_requested']    = true;
		$current['cancel_requested_at'] = max( time(), (int) ( $current['cancel_requested_at'] ?? 0 ) );
		$current['completed_at']        = time();
		$current['updated_at']          = time();
		$current['next_attempt_at']     = 0;
		$current['owner_token']         = '';
		$current['fence_token']         = '';
		$current['lease_until']         = 0;
		$current['error_code']          = 'digitalogic_currency_async_cancelled';
		$current['message_fa']          = 'کار نرخ پیش از ثبت اثر لغو شد؛ مقدار تأییدشدهٔ قبلی حفظ شد.';
		$stored                         = $this->store_job_open_lock( $current, $expected );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$this->unschedule_job( $current );

		return $this->public_job_for_request( $current, $request_id );
	}

	/**
	 * Return an exact request replay before any live-state or precondition read.
	 *
	 * @param array  $job                 Current private job.
	 * @param string $request_id          Exact request identity.
	 * @param string $request_fingerprint Canonical request fingerprint.
	 * @return array|WP_Error|null
	 */
	private function replay_request_open_lock( array $job, $request_id, $request_fingerprint ) {
		if ( '' === $request_id || ! $job ) {
			return null;
		}
		$alias = (array) ( ( $job['request_aliases'] ?? array() )[ $request_id ] ?? array() );
		if ( ! $alias ) {
			return null;
		}
		if ( ! hash_equals( (string) ( $alias['fingerprint'] ?? '' ), (string) $request_fingerprint ) ) {
			return new WP_Error(
				'digitalogic_currency_async_request_id_conflict',
				'این شناسهٔ درخواست قبلاً برای تغییر دیگری استفاده شده است.',
				array(
					'status'   => 409,
					'blocking' => true,
				)
			);
		}
		if ( $this->alias_targets_job( $alias, $job ) ) {
			return $this->public_job_for_request( $job, $request_id, array( 'replayed' => true ) );
		}

		$result = is_array( $alias['result'] ?? null ) ? $alias['result'] : array();
		if ( ! $result ) {
			return new WP_Error(
				'digitalogic_currency_async_request_history_incomplete',
				'نتیجهٔ پایدار این درخواست در دسترس نیست.',
				array(
					'status'   => 409,
					'blocking' => true,
				)
			);
		}
		$result['request_id'] = $request_id;
		$result['replayed']   = true;

		return $result;
	}

	/**
	 * Attach one bounded request alias to the current generation.
	 *
	 * @param array  $job                 Current private job, updated by reference.
	 * @param string $request_id          Exact request identity.
	 * @param string $request_fingerprint Canonical request fingerprint.
	 * @return void
	 */
	private function attach_request_alias( array &$job, $request_id, $request_fingerprint ) {
		if ( '' === $request_id ) {
			return;
		}
		$aliases                = is_array( $job['request_aliases'] ?? null ) ? $job['request_aliases'] : array();
		$aliases[ $request_id ] = array(
			'fingerprint' => (string) $request_fingerprint,
			'job_id'      => (string) ( $job['job_id'] ?? '' ),
			'generation'  => (int) ( $job['generation'] ?? 0 ),
			'created_at'  => time(),
			'result'      => array(),
		);
		uasort(
			$aliases,
			static function ( $left, $right ) {
				return (int) ( $left['created_at'] ?? 0 ) <=> (int) ( $right['created_at'] ?? 0 );
			}
		);
		$remove_count = max( 0, count( $aliases ) - self::MAX_REQUEST_ALIASES );
		for ( $index = 0; $index < $remove_count; $index++ ) {
			array_shift( $aliases );
		}
		$job['request_aliases'] = $aliases;
		if ( '' === (string) ( $job['primary_request_id'] ?? '' ) ) {
			$job['primary_request_id'] = $request_id;
		}
	}

	/**
	 * Carry bounded immutable request results into one successor generation.
	 *
	 * @param array $existing Existing terminal job.
	 * @return array
	 */
	private function carry_request_aliases( array $existing ) {
		$aliases = is_array( $existing['request_aliases'] ?? null ) ? $existing['request_aliases'] : array();
		foreach ( $aliases as $request_id => &$alias ) {
			if ( $this->alias_targets_job( (array) $alias, $existing ) ) {
				$alias['result'] = $this->public_job_for_request(
					$existing,
					(string) $request_id,
					array(
						'replayed'    => true,
						'cancellable' => false,
					)
				);
			}
		}
		unset( $alias );
		uasort(
			$aliases,
			static function ( $left, $right ) {
				return (int) ( $left['created_at'] ?? 0 ) <=> (int) ( $right['created_at'] ?? 0 );
			}
		);
		$remove_count = max( 0, count( $aliases ) - self::MAX_REQUEST_ALIASES + 1 );
		for ( $index = 0; $index < $remove_count; $index++ ) {
			array_shift( $aliases );
		}

		return $aliases;
	}

	/**
	 * Whether one request alias names the exact durable generation.
	 *
	 * @param array $alias Request alias.
	 * @param array $job   Current private job.
	 * @return bool
	 */
	private function alias_targets_job( array $alias, array $job ) {
		return '' !== (string) ( $alias['job_id'] ?? '' )
			&& hash_equals( (string) ( $alias['job_id'] ?? '' ), (string) ( $job['job_id'] ?? '' ) )
			&& (int) ( $alias['generation'] ?? 0 ) === (int) ( $job['generation'] ?? 0 );
	}

	/**
	 * Add request-specific replay metadata without exposing the alias registry.
	 *
	 * @param array  $job        Private job.
	 * @param string $request_id Optional request identity.
	 * @param array  $extra      Additional public fields.
	 * @return array
	 */
	private function public_job_for_request( array $job, $request_id = '', array $extra = array() ) {
		$projection               = $this->public_job( $job );
		$projection['request_id'] = '' !== (string) $request_id
			? (string) $request_id
			: (string) ( $job['primary_request_id'] ?? '' );

		return array_merge( $projection, $extra );
	}

	/**
	 * Return a secret-free operator/client projection.
	 *
	 * @param array $job Private job record.
	 * @return array
	 */
	private function public_job( array $job ) {
		$status      = (string) ( $job['status'] ?? 'idle' );
		$publication = is_array( $job['effect_publication'] ?? null ) ? $job['effect_publication'] : array();
		if (
			'publication_failed' === $status
			|| 'failed' === (string) ( $publication['status'] ?? '' )
		) {
			$status = 'publication_failed';
		} elseif (
			'confirmed' !== $status
			&& 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $job['effect_state_revision'] ?? '' ) )
		) {
			$status = 'publishing';
		} elseif ( ! empty( $job['cancel_requested'] ) && 'running' === $status ) {
			$status = 'cancelling';
		}
		$progress = array(
			'idle'               => 0,
			'queued'             => 10,
			'running'            => 45,
			'cancelling'         => 60,
			'publishing'         => 90,
			'awaiting_excel'     => 90,
			'confirmed'          => 100,
			'failed'             => 100,
			'cancelled'          => 100,
			'publication_failed' => 100,
			'superseded'         => 100,
		);

		return array(
			'job_id'                       => (string) ( $job['job_id'] ?? '' ),
			'generation'                   => (int) ( $job['generation'] ?? 0 ),
			'mode'                         => (string) ( $job['mode'] ?? 'apply' ),
			'execution_mode'               => (string) ( $job['execution_mode'] ?? 'async' ),
			'status'                       => $status,
			'desired_currency'             => (array) ( $job['desired_currency'] ?? array() ),
			'confirmed_currency'           => (array) ( $job['confirmed_currency'] ?? array() ),
			'created_at'                   => (int) ( $job['created_at'] ?? 0 ),
			'updated_at'                   => (int) ( $job['updated_at'] ?? 0 ),
			'deadline_at'                  => (int) ( $job['deadline_at'] ?? 0 ),
			'completed_at'                 => (int) ( $job['completed_at'] ?? 0 ),
			'next_attempt_at'              => (int) ( $job['next_attempt_at'] ?? 0 ),
			'transaction_id'               => (string) ( $job['transaction_id'] ?? '' ),
			'error_code'                   => (string) ( $job['error_code'] ?? '' ),
			'message_fa'                   => 'publishing' === $status
				? 'قیمت ثبت شده است؛ انتشار وضعیت نهایی در حال تکمیل است.'
				: (string) ( $job['message_fa'] ?? 'آماده' ),
			'dispatch_attempts'            => (int) ( $job['dispatch_attempts'] ?? 0 ),
			'apply_attempts'               => (int) ( $job['apply_attempts'] ?? 0 ),
			'publication_attempts'         => (int) ( $publication['attempts'] ?? 0 ),
			'publication_max_attempts'     => self::MAX_PUBLICATION_ATTEMPTS,
			'publication_last_error'       => (string) ( $publication['last_error'] ?? '' ),
			'fence'                        => (int) ( $job['fence'] ?? 0 ),
			'lease_until'                  => (int) ( $job['lease_until'] ?? 0 ),
			'committed_state_revision'     => (string) ( $job['effect_state_revision'] ?? $job['committed_state_revision'] ?? '' ),
			'expected_state_revision'      => (string) ( $job['expected_state_revision'] ?? '' ),
			'superseded_by_state_revision' => (string) ( $job['superseded_by_state_revision'] ?? '' ),
			'progress'                     => (int) ( $progress[ $status ] ?? 0 ),
			'blocking'                     => false,
			'operator_action_required'     => 'publication_failed' === $status,
			'request_id'                   => (string) ( $job['primary_request_id'] ?? '' ),
			'cancel_requested'             => ! empty( $job['cancel_requested'] ),
			'cancel_requested_at'          => (int) ( $job['cancel_requested_at'] ?? 0 ),
			'cancellable'                  => in_array( $status, array( 'queued', 'running', 'cancelling' ), true )
				&& 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) ( $job['effect_state_revision'] ?? '' ) ),
		);
	}

	/**
	 * Whether a durable generation belongs exclusively to its WP-CLI caller.
	 *
	 * @param array $job Durable private job.
	 * @return bool
	 */
	private function is_cli_sync_job( array $job ) {
		return self::CLI_EXECUTION_MODE === (string) ( $job['execution_mode'] ?? '' );
	}

	/**
	 * Persist a bounded dispatch attempt while the caller owns the job mutex.
	 *
	 * @param array $job Current private job, updated by reference.
	 * @return bool Whether the caller should wake a runner after releasing the job mutex.
	 */
	private function wake_worker_open_lock( array &$job ) {
		if ( 'queued' !== (string) ( $job['status'] ?? '' ) ) {
			return false;
		}
		$attempts      = (int) ( $job['dispatch_attempts'] ?? 0 );
		$last_dispatch = (int) ( $job['last_dispatch_at'] ?? 0 );
		if ( $attempts >= self::MAX_DISPATCH_ATTEMPTS || time() - $last_dispatch < self::DISPATCH_RETRY_SECONDS ) {
			return false;
		}

		$expected                 = $job;
		$job['dispatch_attempts'] = $attempts + 1;
		$job['last_dispatch_at']  = time();
		$job['updated_at']        = time();
		if ( is_wp_error( $this->store_job_open_lock( $job, $expected ) ) ) {
			return false;
		}

		return true;
	}

	/** Ask WordPress core to wake due jobs with its own lock/token protocol. */
	private function wake_local_cron() {
		if ( ! function_exists( 'spawn_cron' ) ) {
			return;
		}
		try {
			spawn_cron();
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Schedule through Action Scheduler when available and retain an exact WP-Cron safety copy.
	 *
	 * @param string $hook      Exact hook.
	 * @param int    $timestamp Due Unix timestamp.
	 * @param array  $args      Exact action identity.
	 * @return bool
	 */
	private function schedule_action( $hook, $timestamp, array $args ) {
		$timestamp = max( time(), (int) $timestamp );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			try {
				as_schedule_single_action( $timestamp, $hook, $args, self::ACTION_GROUP, true );
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
		if ( false === wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args, true );
		}

		return false !== wp_next_scheduled( $hook, $args );
	}

	/**
	 * Ensure one exact action identity exists without duplicating its schedule.
	 *
	 * @param string    $hook        Exact hook.
	 * @param int       $timestamp   Due Unix timestamp.
	 * @param array     $args        Exact action identity.
	 * @param bool|null $created_now Whether this call created the schedule.
	 * @return bool
	 */
	private function ensure_action( $hook, $timestamp, array $args, &$created_now = null ) {
		$created_now = false;
		if ( $this->action_is_scheduled( $hook, $args ) ) {
			return true;
		}

		$created_now = $this->schedule_action( $hook, $timestamp, $args );

		return $created_now;
	}

	/**
	 * Whether one exact Action Scheduler or WP-Cron identity is already pending.
	 *
	 * @param string $hook Exact hook.
	 * @param array  $args Exact action identity.
	 * @return bool
	 */
	private function action_is_scheduled( $hook, array $args ) {
		return false !== wp_next_scheduled( $hook, $args );
	}

	/**
	 * Clear all exact apply/lease/deadline actions for a terminal job.
	 *
	 * @param array $job Current private job.
	 * @return void
	 */
	private function unschedule_job( array $job ) {
		$apply_args = $this->apply_args( $job );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			try {
				as_unschedule_all_actions( self::APPLY_HOOK, $apply_args, self::ACTION_GROUP );
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
		wp_clear_scheduled_hook( self::APPLY_HOOK, $apply_args );

		$state_revision = (string) ( $job['effect_state_revision'] ?? $job['committed_state_revision'] ?? '' );
		if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $state_revision ) ) {
			$finalize_args = array(
				(string) ( $job['job_id'] ?? '' ),
				(int) ( $job['generation'] ?? 0 ),
				(int) ( $job['fence'] ?? 0 ),
				$state_revision,
			);
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				try {
					as_unschedule_all_actions( self::FINALIZE_HOOK, $finalize_args, self::ACTION_GROUP );
				} catch ( Throwable $exception ) {
					unset( $exception );
				}
			}
			wp_clear_scheduled_hook( self::FINALIZE_HOOK, $finalize_args );
		}

		$fence = max( 0, (int) ( $job['fence'] ?? 0 ) );
		for ( $candidate = 0; $candidate <= $fence; $candidate++ ) {
			$args = $this->watchdog_args( $job, $candidate );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				try {
					as_unschedule_all_actions( self::WATCHDOG_HOOK, $args, self::ACTION_GROUP );
				} catch ( Throwable $exception ) {
					unset( $exception );
				}
			}
			wp_clear_scheduled_hook( self::WATCHDOG_HOOK, $args );
		}
	}

	/**
	 * Exact apply action identity.
	 *
	 * @param array $job Private job.
	 * @return array
	 */
	private function apply_args( array $job ) {
		return array( (string) ( $job['job_id'] ?? '' ), (int) ( $job['generation'] ?? 0 ) );
	}

	/**
	 * Exact watchdog action identity.
	 *
	 * @param array $job   Private job.
	 * @param int   $fence Expected fence; zero is the global deadline watchdog.
	 * @return array
	 */
	private function watchdog_args( array $job, $fence ) {
		return array(
			(string) ( $job['job_id'] ?? '' ),
			(int) ( $job['generation'] ?? 0 ),
			(int) $fence,
		);
	}

	/**
	 * Whether a raw action target still names the durable job.
	 *
	 * @param array  $job        Private job.
	 * @param string $job_id     Expected identifier.
	 * @param int    $generation Exact positive generation.
	 * @return bool
	 */
	private function matches_job( array $job, $job_id, $generation ) {
		return '' !== (string) ( $job['job_id'] ?? '' )
			&& hash_equals( (string) $job['job_id'], (string) $job_id )
			&& (int) $generation > 0
			&& (int) ( $job['generation'] ?? 0 ) === (int) $generation;
	}

	/**
	 * Whether two private records identify the same immutable generation.
	 *
	 * @param array $left  First record.
	 * @param array $right Second record.
	 * @return bool
	 */
	private function same_generation( array $left, array $right ) {
		return '' !== (string) ( $left['job_id'] ?? '' )
			&& hash_equals( (string) $left['job_id'], (string) ( $right['job_id'] ?? '' ) )
			&& (int) ( $left['generation'] ?? 0 ) === (int) ( $right['generation'] ?? 0 );
	}

	/**
	 * Whether a job is durably terminal.
	 *
	 * @param array $job Private job.
	 * @return bool
	 */
	private function is_terminal( array $job ) {
		return in_array( (string) ( $job['status'] ?? '' ), array( 'confirmed', 'failed', 'publication_failed', 'cancelled' ), true );
	}

	/**
	 * Normalize an optional explicit idempotency identity.
	 *
	 * @param mixed $request_id Candidate identity.
	 * @return string|WP_Error
	 */
	private function normalize_request_id( $request_id ) {
		$request_id = is_scalar( $request_id ) ? trim( (string) $request_id ) : '';
		if ( '' === $request_id ) {
			return '';
		}
		if ( 1 !== preg_match( '/\A[a-zA-Z0-9._:-]{8,128}\z/D', $request_id ) ) {
			return new WP_Error(
				'digitalogic_currency_async_request_id_invalid',
				'شناسهٔ درخواست معتبر نیست.',
				array(
					'status'   => 400,
					'blocking' => true,
				)
			);
		}

		return $request_id;
	}

	/**
	 * Return a strict random hexadecimal identity.
	 *
	 * @param int $bytes Entropy bytes.
	 * @return string
	 */
	private function random_token( $bytes ) {
		try {
			return bin2hex( random_bytes( max( 16, (int) $bytes ) ) );
		} catch ( Throwable $exception ) {
			unset( $exception );

			return hash( 'sha256', uniqid( 'digitalogic-currency-', true ) . wp_salt( 'nonce' ) );
		}
	}

	/**
	 * Preserve the canonical date when ACF has reinterpreted YYMMDD as epoch seconds.
	 *
	 * @param mixed $value Submitted ACF date value.
	 * @return mixed
	 */
	private function normalize_acf_effective_date( $value ) {
		$formatter = Digitalogic_Currency_Date_Formatter::instance();
		$raw       = $formatter->get_raw_update_date();
		if ( 1 !== preg_match( '/\A[0-9]{6}\z/D', $raw ) ) {
			return $value;
		}

		$stored            = $formatter->parse( $raw );
		$submitted_raw     = is_scalar( $value ) ? trim( (string) $value ) : '';
		$submitted         = $formatter->parse( str_replace( '/', '-', $submitted_raw ) );
		$submitted_compact = 1 === preg_match( '/\A[0-9]{8}\z/D', $submitted_raw )
			? $submitted_raw
			: ( null === $submitted ? '' : $submitted->format( 'Ymd' ) );
		$state             = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();
		if ( null === $stored || '' === $submitted_compact || is_wp_error( $state ) ) {
			return $value;
		}
		$canonical = (string) ( $state['settings']['cny_effective_date'] ?? '' );
		if (
			! hash_equals( $stored->format( 'Y-m-d' ), $canonical )
			|| ! hash_equals( gmdate( 'Ymd', (int) $raw ), $submitted_compact )
		) {
			return $value;
		}

		return $canonical;
	}

	/**
	 * Return the raw persisted ACF alias without invoking coordinated writes.
	 *
	 * @param mixed $field    Semantic currency field.
	 * @param mixed $fallback Original field value.
	 * @return mixed
	 */
	private function persisted_currency_value( $field, $fallback ) {
		$field = $this->currency_field( $field );
		if ( '' === $field ) {
			return $fallback;
		}
		if ( 'effective_date' === $field ) {
			$value = get_option( 'options_update_date', null );

			return null === $value ? get_option( 'update_date', $fallback ) : $value;
		}
		$value = get_option( 'options_' . $field, null );

		return null === $value ? get_option( $field, $fallback ) : $value;
	}

	/**
	 * Normalize one semantic ACF/option currency name.
	 *
	 * @param mixed $name Raw field or option name.
	 * @return string dollar_price, yuan_price, effective_date, or empty.
	 */
	private function currency_field( $name ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, 'options_' ) ) {
			$name = substr( $name, 8 );
		}
		if ( 'update_date' === $name ) {
			return 'effective_date';
		}
		if ( 'effective_date' === $name ) {
			return $name;
		}

		return in_array( $name, array( 'dollar_price', 'yuan_price' ), true ) ? $name : '';
	}

	/** Return the exact canonical revision carried by the current ACF request. */
	private function request_acf_expected_revision() {
		$revision = sanitize_text_field(
			wp_unslash( $_POST['digitalogic_pricing_state_revision'] ?? '' ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- ACF owns request authentication; token is only compared with current state.
		);
		if ( 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $revision ) ) {
			return $revision;
		}

		// Programmatic ACF calls have no stale browser form. Bind them to the
		// authoritative state observed in this request; real ACF form posts must
		// carry the server-rendered token or fail safely.
		if ( ! isset( $_POST['acf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence distinguishes a form from an internal API call.
			$state = Digitalogic_Excel_Pricing_Sync::instance()->current_canonical_state();

			return is_wp_error( $state ) ? '' : (string) $state['state_revision'];
		}

		return '';
	}

	/** Clear every WordPress option-cache location for the durable job row. */
	private function invalidate_job_cache() {
		wp_cache_delete( self::JOB_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Whether a transformed Patris catalog owns the pricing state.
	 *
	 * @return bool
	 */
	private function managed_pricing_active() {
		return class_exists( 'Digitalogic_Pricing_Coordinator' )
			&& Digitalogic_Pricing_Coordinator::instance()->has_managed_pricing_state();
	}

	/** Whether the current administrator may operate either currency surface. */
	private function can_manage_currency() {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability.
	}

	/**
	 * Publish a secret-free structured diagnostic without allowing it to fail a save.
	 *
	 * @param WP_Error $error   Exact error.
	 * @param string   $recovery Recovery surface.
	 * @return void
	 */
	private function report_issue( WP_Error $error, $recovery ) {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			set_transient(
				'digitalogic_currency_async_issue_' . $user_id,
				array(
					'code'       => sanitize_key( (string) $error->get_error_code() ),
					'message_fa' => (string) $error->get_error_message(),
				),
				120
			);
		}
		try {
			do_action(
				'digitalogic_currency_async_issue',
				array(
					'code'       => (string) $error->get_error_code(),
					'severity'   => 'warning',
					'blocking'   => false,
					'recovery'   => sanitize_key( (string) $recovery ),
					'message_fa' => (string) $error->get_error_message(),
				)
			);
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/** Show a durable enqueue failure after an ACF redirect instead of false success. */
	public function render_admin_issue() {
		if ( ! $this->can_manage_currency() ) {
			return;
		}
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id < 1 ) {
			return;
		}
		$key   = 'digitalogic_currency_async_issue_' . $user_id;
		$issue = get_transient( $key );
		if ( ! is_array( $issue ) || empty( $issue['message_fa'] ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( (string) $issue['message_fa'] )
		);
	}
}
