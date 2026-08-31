<?php
/**
 * Safe, component-scoped ecosystem pricing-settings synchronization.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates trusted Digitalogic components with versioned WooCommerce
 * pricing inputs. The historical class name is retained for binary/API
 * compatibility; the remote contract is not owned by Microsoft Excel.
 */
final class Digitalogic_Excel_Pricing_Sync {

	public const REQUEST_SCHEMA  = 'digitalogic.pricing-sync-request/v1';
	public const STATE_SCHEMA    = 'digitalogic.pricing-sync-state/v1';
	public const PREVIEW_SCHEMA  = 'digitalogic.pricing-sync-preview/v1';
	public const APPLY_SCHEMA    = 'digitalogic.pricing-sync-apply/v1';
	public const SETTINGS_SCHEMA = 'digitalogic.pricing-settings/v1';

	public const LEGACY_REQUEST_SCHEMA = 'digitalogic.excel-pricing-sync-request/v1';
	public const LEGACY_STATE_SCHEMA   = 'digitalogic.excel-pricing-sync-state/v1';
	public const LEGACY_PREVIEW_SCHEMA = 'digitalogic.excel-pricing-sync-preview/v1';
	public const LEGACY_APPLY_SCHEMA   = 'digitalogic.excel-pricing-sync-apply/v1';

	public const SETTINGS_OPTION            = 'digitalogic_excel_pricing_sync_settings';
	public const AUDIT_OPTION               = 'digitalogic_excel_pricing_sync_audit';
	public const CONFIRMATION_SCHEMA        = 'digitalogic.pricing-confirmation/v1';
	public const ACK_SCHEMA                 = 'digitalogic.pricing-sync-ack/v1';
	public const CONFIRMATIONS_OPTION       = 'digitalogic_pricing_confirmation_transactions_v1';
	public const CONFIRMATION_OUTBOX_OPTION = 'digitalogic_pricing_confirmation_outbox_v1';

	private const LOCK_NAME                 = 'digitalogic_excel_pricing_sync_v1';
	private const LOCK_TIMEOUT_SECONDS      = 5;
	private const PREVIEW_TTL_SECONDS       = 600;
	private const APPLY_IDEMPOTENCY_SECONDS = 86400;
	private const CONFIRMATION_TIMEOUT_HOOK = 'digitalogic_pricing_confirmation_timeout_v1';
	private const ACK_TARGET_SECONDS        = 90;
	private const ACK_RECOVERY_SECONDS      = 180;
	private const ROLLBACK_LEASE_SECONDS    = 5;
	private const MAX_CONFIRMATIONS         = 50;
	private const REQUIRED_CONSUMER_ID      = 'digitalogic-price-calculator';
	private const REQUIRED_CONSUMER_CHANNEL = 'excel-workbook';
	private const MAX_AUDIT_ENTRIES         = 50;
	private const MAX_RATE                  = 1000000000;
	private const MAX_PROFIT_PERCENT        = '1000';
	private const MAX_PROFIT_SCALE          = 12;
	public const STALE_AFTER_DAYS           = 7;
	private const DRIFT_PERCENT             = 7.0;

	/**
	 * Shared service.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Nested advisory-lock depth.
	 *
	 * @var int
	 */
	private $lock_depth = 0;

	/**
	 * Whether this service owns an active transaction.
	 *
	 * @var bool
	 */
	private $transaction_active = false;

	/**
	 * Option names changed by the active transaction.
	 *
	 * @var array
	 */
	private $transaction_option_names = array();

	/**
	 * WordPress option hooks to dispatch after commit.
	 *
	 * @var array
	 */
	private $transaction_option_events = array();

	/**
	 * Suppress a second proposal while restoring a timed-out commit.
	 *
	 * @var int
	 */
	private $confirmation_rollback_depth = 0;

	/**
	 * Register durable confirmation recovery and timeout handling.
	 */
	private function __construct() {
		add_action( self::CONFIRMATION_TIMEOUT_HOOK, array( $this, 'run_confirmation_timeout' ), 10, 1 );
		add_action( 'init', array( $this, 'recover_pending_confirmation' ), 20 );
		add_action( 'shutdown', array( $this, 'publish_confirmation_outbox' ), 1002 );
	}

	/**
	 * Return the shared service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Return the complete canonical settings needed by the pricing coordinator.
	 *
	 * @param mixed|null $profit_fallback Exact margin used only when no stored margin exists.
	 * @return array|WP_Error
	 */
	public function current_canonical_settings( $profit_fallback = null ) {
		$state = $this->current_canonical_state( $profit_fallback );

		return is_wp_error( $state ) ? $state : $state['settings'];
	}

	/**
	 * Return canonical settings with revision and freshness metadata.
	 *
	 * This is the shared read contract for Excel, Google Sheets, WordPress, and
	 * any other trusted client that must compare before writing.
	 *
	 * @param mixed|null $profit_fallback Exact profit used only when no default exists.
	 * @return array|WP_Error
	 */
	public function current_canonical_state( $profit_fallback = null ) {
		$globals = $this->read_globals();
		if ( is_wp_error( $globals ) ) {
			return $globals;
		}
		if (
			null === $globals['currency']['dollar_price']
			|| null === $globals['currency']['yuan_price']
			|| null === $globals['currency']['usd_effective_date']
			|| null === $globals['currency']['cny_effective_date']
		) {
			return $this->error(
				'digitalogic_pricing_current_currency_invalid',
				'نرخ دلار، نرخ یوآن و تاریخ مؤثر فعلی باید پیش از هماهنگ‌سازی معتبر باشند.',
				409
			);
		}
		$profit = ! empty( $globals['default_markup']['configured'] )
			? $globals['default_markup']['profit_percent']
			: $profit_fallback;
		if ( null === $profit ) {
			return $this->error(
				'digitalogic_pricing_current_profit_required',
				'حاشیه سود مشترک فعلی برای بازتولید قیمت‌ها تنظیم نشده است.',
				409
			);
		}

		$settings = $this->normalize_settings(
			array(
				'dollar_price'              => $globals['currency']['dollar_price'],
				'yuan_price'                => $globals['currency']['yuan_price'],
				'effective_date'            => $globals['currency']['effective_date'],
				'usd_effective_date'        => $globals['currency']['usd_effective_date'],
				'cny_effective_date'        => $globals['currency']['cny_effective_date'],
				'profit_margin_percent'     => $profit,
				'price_rounding_digits'     => $globals['price_rounding']['rounding_digits'],
				'price_rounding_mode'       => $globals['price_rounding']['rounding_mode'],
				'air_express_price_per_kg'  => $globals['shipping']['price_per_kg'],
				'air_express_currency'      => $globals['shipping']['currency'],
				'shipping_catalog_revision' => $globals['shipping']['catalog_revision'],
			)
		);
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		return array(
			'schema'           => self::STATE_SCHEMA,
			'state_revision'   => $globals['state_revision'],
			'settings'         => $settings,
			'freshness'        => array(
				'effective_date'   => $globals['currency']['effective_date'],
				'age_days'         => $globals['currency']['age_days'],
				'stale'            => (bool) $globals['currency']['stale'],
				'stale_after'      => self::STALE_AFTER_DAYS,
				'stale_currencies' => $globals['currency']['stale_currencies'],
				'usd'              => $globals['currency']['freshness']['usd'],
				'cny'              => $globals['currency']['freshness']['cny'],
			),
			'rate_provenance'  => $globals['currency']['rate_provenance'],
			'profit_margin'    => array(
				'profit_margin_percent' => $settings['profit_margin_percent'],
				'updated_at'            => $globals['default_markup']['updated_at'],
			),
			'price_rounding'   => $globals['price_rounding'],
			'shipping'         => $globals['shipping'],
			'confirmation'     => $this->current_confirmation_projection(),
			'attribute_owners' => $this->attribute_owners(),
		);
	}

	/**
	 * Apply trusted WordPress-origin settings and derived prices atomically.
	 *
	 * Admin, REST, AJAX/command-dispatcher, and other internal surfaces use
	 * this path instead of mutating currency/profit-margin options directly.
	 *
	 * @param mixed         $settings          Complete settings.
	 * @param string        $source            Bounded internal source label.
	 * @param string|null   $expected_revision Optional optimistic state revision.
	 * @param callable|null $actuation_guard   Optional safety guard called before writes and immediately before commit.
	 * @return array|WP_Error
	 */
	public function apply_internal_settings( $settings, $source = 'wp', $expected_revision = null, $actuation_guard = null ) {
		$settings = $this->normalize_settings( $settings );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if (
			null !== $expected_revision
			&& (
				! is_string( $expected_revision )
				|| 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $expected_revision )
			)
		) {
			return $this->error(
				'digitalogic_pricing_expected_revision_invalid',
				'شناسه نسخهٔ مورد انتظار تنظیمات قیمت معتبر نیست.',
				400
			);
		}
		if ( null !== $actuation_guard && ! is_callable( $actuation_guard ) ) {
			return $this->error(
				'digitalogic_pricing_actuation_guard_invalid',
				'کنترل ایمنی اجرای قیمت معتبر نیست.',
				500
			);
		}
		$source          = sanitize_key( (string) $source );
		$source          = '' === $source ? 'wp' : substr( $source, 0, 64 );
		$source_identity = array(
			'id'       => 'digitalogic-wp',
			'dataset'  => 'pricing-settings',
			'revision' => $this->revision(
				array(
					'source'   => $source,
					'settings' => $settings,
				)
			),
		);
		$effect_id       = 'sha256:' . hash(
			'sha256',
			"internal-pricing-effect\0"
			. $source . "\0"
			. wp_generate_uuid4() . "\0"
			. sprintf( '%.6F', microtime( true ) )
		);

		return $this->with_lock(
			function () use ( $settings, $source, $source_identity, $expected_revision, $actuation_guard, $effect_id ) {
				$current = $this->read_globals();
				if ( is_wp_error( $current ) ) {
					return $current;
				}
				if (
					null !== $expected_revision
					&& ! hash_equals( $expected_revision, $current['state_revision'] )
				) {
					return $this->error(
						'digitalogic_pricing_state_revision_conflict',
						'تنظیمات قیمت پس از آخرین خواندن تغییر کرده است؛ ابتدا تازه‌سازی کنید.',
						409,
						array( 'current_state_revision' => $current['state_revision'] )
					);
				}
				$desired = $this->globals_from_settings( $settings );
				if ( is_wp_error( $desired ) ) {
					return $desired;
				}
				$changed = ! hash_equals( $current['state_revision'], $desired['state_revision'] );
				$context = array(
					'id'                 => $source_identity['id'],
					'dataset'            => $source_identity['dataset'],
					'submitted_revision' => $source_identity['revision'],
					'current_revision'   => $source_identity['revision'],
					'matches_current'    => true,
				);

				$transaction = $this->run_coordinated_pricing_transaction(
					function () use ( $settings, $source, $source_identity, $context, $current, $desired, $changed, $expected_revision, $actuation_guard, $effect_id ) {
						foreach (
							array(
								'dollar_price',
								'options_dollar_price',
								'yuan_price',
								'options_yuan_price',
								'update_date',
								'options_update_date',
								Digitalogic_Shipping_Method_Service::METHODS_OPTION,
								Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION,
								Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION,
								self::SETTINGS_OPTION,
								self::AUDIT_OPTION,
							) as $option_name
						) {
							$this->read_option_db( $option_name, true );
						}

						$locked_current = $this->read_globals();
						if ( is_wp_error( $locked_current ) ) {
							return $locked_current;
						}
						if (
							null !== $expected_revision
							&& ! hash_equals( $expected_revision, $locked_current['state_revision'] )
						) {
							return $this->error(
								'digitalogic_pricing_state_revision_conflict',
								'تنظیمات قیمت پس از آخرین خواندن تغییر کرده است؛ ابتدا تازه‌سازی کنید.',
								409,
								array( 'current_state_revision' => $locked_current['state_revision'] )
							);
						}
						if ( null !== $actuation_guard ) {
							$guarded = call_user_func( $actuation_guard, 'before_write' );
							if ( is_wp_error( $guarded ) ) {
								return $guarded;
							}
							if ( true !== $guarded ) {
								return $this->error(
									'digitalogic_pricing_actuation_guard_rejected',
									'مالکیت ایمن کار قیمت پیش از ثبت از دست رفت.',
									409
								);
							}
						}
						$superseded_confirmation_id = $this->supersede_active_confirmation_open_transaction(
							$source,
							(string) $desired['state_revision']
						);
						if ( is_wp_error( $superseded_confirmation_id ) ) {
							return $superseded_confirmation_id;
						}
						if ( $changed ) {
							$options = $this->desired_option_values(
								$source_identity,
								$settings,
								$locked_current,
								$desired
							);
							if ( is_wp_error( $options ) ) {
								return $options;
							}
							foreach ( $options as $name => $value ) {
								$stored = $this->store_option_verified( $name, $value );
								if ( is_wp_error( $stored ) ) {
									return $stored;
								}
							}

							$audit_key = 'internal:' . hash(
								'sha256',
								$source . '|' . $locked_current['state_revision'] . '|' . $desired['state_revision']
							);
							$audit     = $this->append_audit_entry(
								$source_identity,
								$context,
								$audit_key,
								'internal',
								$locked_current,
								$desired,
								$settings,
								array(
									'client_id'  => 'digitalogic-wp',
									'channel'    => $source,
									'request_id' => $audit_key,
								)
							);
							if ( is_wp_error( $audit ) ) {
								return $audit;
							}
						}

						$readback = $this->read_globals();
						$readback = $this->transaction_consistent_readback(
							$readback,
							$desired,
							$locked_current
						);
						if (
							is_wp_error( $readback )
							|| ! hash_equals( $desired['state_revision'], $readback['state_revision'] )
						) {
							return is_wp_error( $readback )
								? $readback
								: $this->error(
									'digitalogic_pricing_settings_readback_failed',
									'خواندن مجدد تنظیمات قیمت با مقدار درخواستی یکسان نیست.',
									500
								);
						}

						$repricing = Digitalogic_Pricing_Coordinator::instance()->reprice_open_transaction(
							$settings,
							$locked_current['shipping']['catalog_revision']
						);
						if ( is_wp_error( $repricing ) ) {
							return $repricing;
						}
						$cache_plan        = Digitalogic_Pricing_Coordinator::instance()->repricing_cache_plan();
						$response_settings = $this->settings_from_globals( $readback );
						return array(
							'readback'     => $readback,
							'repricing'    => $repricing,
							// WordPress-origin changes are terminal website commits. Only
							// explicit Excel apply() requests stage an ACK/rollback ledger.
							'confirmation' => null,
							'publication'  => array(
								'effect_id'         => $effect_id,
								'source_identity'   => $source_identity,
								'source'            => $source,
								'previous_revision' => (string) $current['state_revision'],
								'previous'          => $current,
								'readback'          => $readback,
								'settings'          => $response_settings,
								'repricing'         => $repricing,
								'cache_plan'        => $cache_plan,
								'settings_changed'  => (bool) $changed,
								'products_updated'  => (int) ( $repricing['updated_products'] ?? 0 ) > 0,
								'superseded_confirmation_id' => (string) $superseded_confirmation_id,
							),
						);
					},
					null === $actuation_guard
						? null
						: static function ( $transaction_result ) use ( $actuation_guard ) {
							return call_user_func( $actuation_guard, 'before_commit', $transaction_result );
						}
				);
				if ( is_wp_error( $transaction ) ) {
					// Rollback or an ambiguous post-COMMIT failure must clear any
					// request-local objects. A committed worker marker owns the
					// durable cache plan and will replay it in a fresh process.
					Digitalogic_Pricing_Coordinator::instance()->flush_repricing_caches();
					return $transaction;
				}

				$publication = is_array( $transaction['publication'] ?? null )
					? $transaction['publication']
					: array();
				if ( null !== $actuation_guard ) {
					// The worker's transaction marker owns durable publication. It
					// will replay this semantic payload after a process crash without
					// ever re-running pricing actuation.
					return $this->internal_publication_result( $publication );
				}

				return $this->publish_internal_settings_effect( $publication );
			}
		);
	}

	/**
	 * Publish one already-committed internal effect without re-running pricing.
	 *
	 * This method is intentionally replay-safe: callers provide a stable
	 * effect_id, webhooks reuse it, cache invalidation is monotonic, and durable
	 * snapshot event handoff is keyed by canonical state.
	 *
	 * @param array  $publication      Committed nonsecret semantic result.
	 * @param bool   $superseded       Whether a later legitimate revision already won.
	 * @param string $current_revision Current canonical revision when superseded.
	 * @return array|WP_Error
	 */
	public function publish_internal_settings_effect( $publication, $superseded = false, $current_revision = '' ) {
		if ( ! is_array( $publication ) ) {
			return $this->error(
				'digitalogic_pricing_publication_invalid',
				'اطلاعات انتشار نتیجهٔ قیمت معتبر نیست.',
				500
			);
		}
		$result = $this->internal_publication_result( $publication );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$superseded_confirmation_id = (string) ( $publication['superseded_confirmation_id'] ?? '' );
		if ( 1 === preg_match( '/\Aptx_[a-f0-9]{32}\z/D', $superseded_confirmation_id ) ) {
			wp_clear_scheduled_hook( self::CONFIRMATION_TIMEOUT_HOOK, array( $superseded_confirmation_id ) );
		}

		$settings_changed = ! empty( $publication['settings_changed'] );
		$products_updated = ! empty( $publication['products_updated'] );
		if ( $settings_changed || $products_updated || $superseded ) {
			$option_cache_names = is_array( $publication['option_cache_names'] ?? null )
				? $publication['option_cache_names']
				: array();
			$this->invalidate_option_caches(
				array_values(
					array_unique(
						array_merge(
							$option_cache_names,
							array(
								'dollar_price',
								'options_dollar_price',
								'yuan_price',
								'options_yuan_price',
								'update_date',
								'options_update_date',
								Digitalogic_Shipping_Method_Service::METHODS_OPTION,
								Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION,
								Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION,
								self::SETTINGS_OPTION,
								self::AUDIT_OPTION,
							)
						)
					)
				)
			);
			Digitalogic_Pricing_Coordinator::instance()->flush_repricing_caches(
				is_array( $publication['cache_plan'] ?? null ) ? $publication['cache_plan'] : array()
			);
		}

		$repricing    = is_array( $publication['repricing'] ?? null ) ? $publication['repricing'] : array();
		$source_event = Digitalogic_Pricing_Snapshot::instance()->ensure_source_lifecycle_event(
			(array) ( $repricing['source_state_before'] ?? array() ),
			(array) ( $repricing['source_state_after'] ?? array() )
		);
		if ( is_wp_error( $source_event ) ) {
			return $source_event;
		}
		$snapshot_result = $result;
		if (
			$superseded
			&& 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', (string) $current_revision )
		) {
			$snapshot_result['state_revision'] = (string) $current_revision;
		}
		$snapshot = Digitalogic_Pricing_Snapshot::instance()->invalidate_after_apply( $snapshot_result );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( $superseded ) {
			$result['effect_state_revision']        = $result['state_revision'];
			$result['state_revision']               = (string) $current_revision;
			$result['superseded_by_state_revision'] = (string) $current_revision;
			$result['status']                       = 'superseded';
			try {
				do_action( 'digitalogic_excel_pricing_apply_committed', $result );
			} catch ( Throwable $exception ) {
				unset( $exception );
			}

			return $result;
		}

		$repricing['effect_id'] = $result['effect_id'];
		Digitalogic_Pricing_Coordinator::instance()->publish_repricing_result( $repricing );
		if ( ! empty( $publication['settings_changed'] ) ) {
			$this->emit_after_apply(
				(array) $publication['source_identity'],
				(string) $publication['previous_revision'],
				(array) $publication['previous'],
				(array) $publication['readback'],
				(array) $publication['settings'],
				array( 'effect_id' => $result['effect_id'] )
			);
		}
		if ( ! empty( $publication['settings_changed'] ) || ! empty( $publication['products_updated'] ) ) {
			try {
				do_action( 'digitalogic_excel_pricing_apply_committed', $result );
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}

		return $result;
	}

	/**
	 * Build the established coordinator result for one committed internal publication.
	 *
	 * @param array $publication Committed semantic publication payload.
	 * @return array|WP_Error
	 */
	private function internal_publication_result( array $publication ) {
		$readback = is_array( $publication['readback'] ?? null ) ? $publication['readback'] : array();
		$revision = (string) ( $readback['state_revision'] ?? '' );
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $revision ) ) {
			return $this->error(
				'digitalogic_pricing_publication_revision_invalid',
				'شناسهٔ نتیجهٔ ثبت‌شدهٔ قیمت معتبر نیست.',
				500
			);
		}
		$effect_id = sanitize_text_field( (string) ( $publication['effect_id'] ?? '' ) );
		if ( 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $effect_id ) ) {
			$effect_id = 'sha256:' . hash(
				'sha256',
				(string) ( $publication['source'] ?? 'wp' ) . "\0"
				. (string) ( $publication['previous_revision'] ?? '' ) . "\0" . $revision
			);
		}

		return array(
			'schema'           => 'digitalogic.pricing-coordinator-result/v1',
			'effect_id'        => $effect_id,
			'status'           => ! empty( $publication['settings_changed'] ) ? 'applied' : 'reconciled',
			'source'           => sanitize_key( (string) ( $publication['source'] ?? 'wp' ) ),
			'state_revision'   => $revision,
			'settings'         => (array) ( $publication['settings'] ?? array() ),
			'pricing_results'  => (array) ( $publication['repricing'] ?? array() ),
			'settings_changed' => ! empty( $publication['settings_changed'] ),
			'confirmation'     => array( 'status' => 'clear' ),
		);
	}

	/**
	 * Authorize the exact Excel sync machine surface.
	 *
	 * The existing Patris product-sync secret is reused, but this surface is
	 * fail-closed until at least one exact source scope is configured. It never
	 * falls back to a WordPress session, WooCommerce key, or a workbook secret.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		$feed   = Digitalogic_Patris_Feed::instance();
		$scopes = $feed->get_product_sync_source_scopes();

		if ( empty( $scopes ) ) {
			return $this->error(
				'digitalogic_excel_sync_scope_required',
				'برای همگام‌سازی اکسل باید منبع Patris به‌صورت دقیق پیکربندی شده باشد.',
				403
			);
		}

		if ( ! $feed->verify_product_sync_request( $request ) ) {
			return $this->error(
				'digitalogic_excel_sync_unauthorized',
				'اعتبار یا محدودهٔ منبع همگام‌سازی معتبر نیست.',
				401
			);
		}

		return true;
	}

	/**
	 * Validate an exact, currently materialized source for immutable snapshots.
	 *
	 * Settings synchronization deliberately tolerates a newer submitted source
	 * revision. A snapshot cannot: its token must identify the exact Patris data
	 * that was reconciled with WooCommerce.
	 *
	 * @param mixed $source Raw source identity.
	 * @return array|WP_Error Normalized source and current context.
	 */
	public function validate_snapshot_source( $source ) {
		$normalized = $this->normalize_snapshot_source( $source );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$context = $this->validate_current_source( $normalized );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( empty( $context['revision_matches_current'] ) ) {
			return $this->error(
				'digitalogic_pricing_snapshot_source_revision_conflict',
				'The requested source revision is not the revision currently materialized in WordPress.',
				409,
				array(
					'retryable'                 => false,
					'submitted_source_revision' => $context['submitted_revision'],
					'current_source_revision'   => $context['current_revision'],
				)
			);
		}

		return array(
			'source'  => $normalized,
			'context' => $context,
		);
	}

	/**
	 * Normalize an immutable snapshot source without requiring it to be current.
	 *
	 * Token-addressed snapshot reads use this syntax-only surface so an older,
	 * already-built snapshot remains readable until its own expiry. New builds
	 * must continue through validate_snapshot_source() above.
	 *
	 * @param mixed $source Raw source identity.
	 * @return array|WP_Error
	 */
	public function normalize_snapshot_source( $source ) {
		return $this->normalize_source( $source );
	}

	/**
	 * Build one paged Persian state response.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error
	 */
	public function state( WP_REST_Request $request ) {
		$payload = $this->validate_request( $request, 'state' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$source_context = $this->validate_current_source( $payload['source'] );
		if ( is_wp_error( $source_context ) ) {
			return $source_context;
		}

		$globals = $this->read_globals();
		if ( is_wp_error( $globals ) ) {
			return $globals;
		}

		$projection = isset( $payload['projection'] ) ? (string) $payload['projection'] : 'catalog';
		$catalog    = null;
		if ( 'settings' !== $projection ) {
			$page  = isset( $payload['page'] ) ? absint( $payload['page'] ) : 1;
			$limit = isset( $payload['limit'] ) ? absint( $payload['limit'] ) : Digitalogic_Google_Sheets_Catalog::MAX_PAGE_SIZE;
			$page  = max( 1, $page );
			$limit = max( 1, min( Digitalogic_Google_Sheets_Catalog::MAX_PAGE_SIZE, $limit ) );

			$catalog = Digitalogic_Google_Sheets_Catalog::instance()->get_page(
				array(
					'dataset'        => 'reconciled_products',
					'locale'         => 'fa',
					'page'           => $page,
					'limit'          => $limit,
					'source_id'      => $source_context['id'],
					'source_dataset' => $source_context['dataset'],
				)
			);
			if ( is_wp_error( $catalog ) ) {
				return $catalog;
			}
		}

		$warnings       = array();
		$source_warning = $this->source_revision_warning( $source_context );
		if ( null !== $source_warning ) {
			$warnings[] = $source_warning;
		}

		$response = array(
			'schema'             => self::STATE_SCHEMA,
			'state_revision'     => $globals['state_revision'],
			'generated_at'       => $this->now_iso8601(),
			'source'             => $source_context,
			'client_id'          => $payload['client_id'],
			'channel'            => $payload['channel'],
			'request_id'         => $payload['request_id'],
			'warnings'           => $warnings,
			'confirmation'       => $this->current_confirmation_projection(),
			'settings'           => array(
				'dollar_price'              => $globals['currency']['dollar_price'],
				'yuan_price'                => $globals['currency']['yuan_price'],
				'effective_date'            => $globals['currency']['cny_effective_date'],
				'usd_effective_date'        => $globals['currency']['usd_effective_date'],
				'cny_effective_date'        => $globals['currency']['cny_effective_date'],
				'profit_margin_percent'     => $globals['default_markup']['profit_percent'],
				'price_rounding_digits'     => $globals['price_rounding']['rounding_digits'],
				'price_rounding_mode'       => $globals['price_rounding']['rounding_mode'],
				'air_express_price_per_kg'  => $globals['shipping']['price_per_kg'],
				'air_express_currency'      => $globals['shipping']['currency'],
				'shipping_catalog_revision' => $globals['shipping']['catalog_revision'],
			),
			'currency'           => $globals['currency'],
			'profit_margin'      => array(
				'configured'            => (bool) $globals['default_markup']['configured'],
				'profit_margin_percent' => $globals['default_markup']['profit_percent'],
				'revision'              => $globals['default_markup']['revision'],
				'updated_at'            => $globals['default_markup']['updated_at'],
			),
			'price_rounding'     => $globals['price_rounding'],
			'shipping'           => $globals['shipping'],
			'default_markup'     => array_merge(
				$globals['default_markup'],
				array(
					'deprecated'  => true,
					'replacement' => 'profit_margin',
				)
			),
			'deprecated_aliases' => array(
				'default_markup' => array(
					'replacement' => 'profit_margin',
					'equivalence' => 'default_markup.profit_percent == profit_margin.profit_margin_percent',
				),
			),
			'attribute_owners'   => $this->attribute_owners(),
		);
		if ( null !== $catalog ) {
			$response['catalog'] = $catalog;
		}

		return $response;
	}

	/**
	 * Validate and preview one complete global-settings document.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$payload = $this->validate_request( $request, 'preview' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$headers = $this->validate_mutation_headers( $request, $payload );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$settings = $this->normalize_settings( $payload['settings'] ?? null );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$request_hash = $this->revision(
			array(
				'mode'                    => 'preview',
				'source'                  => $payload['source'],
				'idempotency_key'         => $headers['idempotency_key'],
				'expected_state_revision' => $headers['expected_state_revision'],
				'settings'                => $settings,
				'client_id'               => $payload['client_id'],
				'channel'                 => $payload['channel'],
				'request_id'              => $payload['request_id'],
			)
		);

		return $this->with_lock(
			function () use ( $payload, $headers, $settings, $request_hash ) {
				$claim = $this->claim_idempotency(
					'preview',
					$headers['idempotency_key'],
					$request_hash,
					self::PREVIEW_TTL_SECONDS
				);
				if ( is_wp_error( $claim ) ) {
					return $claim;
				}
				if ( isset( $claim['response'] ) ) {
					$replayed           = $claim['response'];
					$replayed['status'] = 'replayed';
					return $replayed;
				}

				$result = $this->build_preview(
					$payload['source'],
					$headers['expected_state_revision'],
					$settings,
					$this->request_context( $payload )
				);
				if ( is_wp_error( $result ) ) {
					$this->release_idempotency( 'preview', $headers['idempotency_key'] );
					return $result;
				}

				$stored = $this->complete_idempotency(
					'preview',
					$headers['idempotency_key'],
					$request_hash,
					$result,
					self::PREVIEW_TTL_SECONDS
				);
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}

				return $result;
			}
		);
	}

	/**
	 * Apply one previously previewed complete global-settings document.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error
	 */
	public function apply( WP_REST_Request $request ) {
		$payload = $this->validate_request( $request, 'apply' );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$headers = $this->validate_mutation_headers( $request, $payload );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$confirmation = $this->confirmation_value( $payload );
		if ( is_wp_error( $confirmation ) ) {
			return $confirmation;
		}
		if ( 'APPLY' !== $confirmation ) {
			return $this->error(
				'digitalogic_excel_sync_confirmation_required',
				'برای اعمال تغییرات باید مقدار تأیید دقیقاً APPLY باشد.',
				422
			);
		}

		$settings = $this->normalize_settings( $payload['settings'] ?? null );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}

		$preview_digest = isset( $payload['preview_digest'] ) && is_string( $payload['preview_digest'] )
			? trim( $payload['preview_digest'] )
			: '';
		if ( ! $this->is_revision( $preview_digest ) ) {
			return $this->error(
				'digitalogic_excel_sync_preview_digest_invalid',
				'شناسهٔ پیش‌نمایش معتبر نیست.',
				400
			);
		}

		$request_hash = $this->revision(
			array(
				'mode'                    => 'apply',
				'source'                  => $payload['source'],
				'idempotency_key'         => $headers['idempotency_key'],
				'expected_state_revision' => $headers['expected_state_revision'],
				'settings'                => $settings,
				'preview_digest'          => $preview_digest,
				'confirmation'            => 'APPLY',
				'client_id'               => $payload['client_id'],
				'channel'                 => $payload['channel'],
				'request_id'              => $payload['request_id'],
			)
		);

		return $this->with_lock(
			function () use ( $payload, $headers, $settings, $preview_digest, $request_hash ) {
				$claim = $this->claim_idempotency(
					'apply',
					$headers['idempotency_key'],
					$request_hash,
					self::APPLY_IDEMPOTENCY_SECONDS
				);
				if ( is_wp_error( $claim ) ) {
					return $claim;
				}
				if ( isset( $claim['response'] ) ) {
					$replayed           = $claim['response'];
					$replayed['status'] = 'replayed';
					return $replayed;
				}

				$source_context = $this->validate_current_source( $payload['source'] );
				if ( is_wp_error( $source_context ) ) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $source_context;
				}

				$current = $this->read_globals();
				if ( is_wp_error( $current ) ) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $current;
				}
				if ( ! hash_equals( $current['state_revision'], $headers['expected_state_revision'] ) ) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $this->revision_conflict( $current['state_revision'] );
				}

				$preview = $this->read_preview( $preview_digest );
				if ( is_wp_error( $preview ) ) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $preview;
				}
				if (
					! hash_equals( $preview['expected_state_revision'], $headers['expected_state_revision'] )
					|| ! hash_equals(
						$this->revision( $preview['source'] ),
						$this->revision( $payload['source'] )
					)
					|| ! hash_equals(
						$this->revision( $preview['settings'] ),
						$this->revision( $settings )
					)
				) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $this->error(
						'digitalogic_excel_sync_preview_mismatch',
						'درخواست اعمال با پیش‌نمایش تأییدشده یکسان نیست.',
						409
					);
				}

				$result = $this->apply_locked(
					$payload['source'],
					$source_context,
					$headers['idempotency_key'],
					$headers['expected_state_revision'],
					$settings,
					$preview_digest,
					$preview,
					$this->request_context( $payload )
				);
				if ( is_wp_error( $result ) ) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $result;
				}
				$result['effect_id']         = 'sha256:' . hash(
					'sha256',
					"excel-pricing-apply\0" . $headers['idempotency_key'] . "\0" . $request_hash
				);
				$result['previous_revision'] = $headers['expected_state_revision'];
				try {
					$invalidated = Digitalogic_Pricing_Snapshot::instance()->invalidate_after_apply( $result );
				} catch ( Throwable $exception ) {
					$invalidated = $this->error(
						'digitalogic_excel_sync_projection_invalidation_exception',
						'تنظیمات ثبت شد اما انتشار تغییر projection به بازیابی محدود نیاز دارد.',
						500,
						array(
							'retry_scheduled' => false,
							'exception_class' => get_class( $exception ),
						)
					);
				}
				if ( is_wp_error( $invalidated ) ) {
					$details              = $invalidated->get_error_data();
					$details              = is_array( $details ) ? $details : array();
					$result['warnings'][] = $this->warning(
						'projection_invalidation_pending',
						'تنظیمات ثبت شد؛ تازه‌سازی projection در صف بازیابی محدود قرار گرفت.',
						'warning',
						array(
							'error_code'      => $invalidated->get_error_code(),
							'retry_scheduled' => (bool) ( $details['retry_scheduled'] ?? false ),
						)
					);
				}

				$stored = $this->complete_idempotency(
					'apply',
					$headers['idempotency_key'],
					$request_hash,
					$result,
					self::APPLY_IDEMPOTENCY_SECONDS
				);
				if ( is_wp_error( $stored ) ) {
					// The settings write is idempotent and already passed exact
					// readback. Return success with an explicit retry warning.
					$result['warnings'][] = $this->warning(
						'idempotency_result_not_cached',
						'ثبت نتیجهٔ تکرارپذیر کامل نشد؛ پیش از تلاش دوباره state را بخوانید.',
						'warning'
					);
				}

				// This action is emitted only for the original terminal apply. An
				// idempotent replay returns above and cannot invalidate projection
				// generations or create any new external effect.
				try {
					do_action( 'digitalogic_excel_pricing_apply_committed', $result );
				} catch ( Throwable $exception ) {
					unset( $exception );
				}

				return $result;
			}
		);
	}

	/**
	 * Acknowledge that the configured workbook applied the exact website commit.
	 *
	 * Authentication remains source scoped in authorize(); this method then
	 * binds the durable acknowledgement to the configured consumer identity,
	 * transaction, committed state revision, complete value, and value digest.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return array|WP_Error
	 */
	public function ack( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			return $this->error(
				'digitalogic_pricing_confirmation_ack_invalid',
				'The acknowledgement body must be a JSON object.',
				400
			);
		}
		$allowed = array(
			'schema',
			'schema_version',
			'operation',
			'transaction_id',
			'consumer_id',
			'channel',
			'source',
			'committed_state_revision',
			'confirmed_settings',
			'confirmed_settings_digest',
			'idempotency_key',
		);
		$unknown = array_diff( array_keys( $payload ), $allowed );
		if ( $unknown ) {
			return $this->error(
				'digitalogic_pricing_confirmation_ack_unknown_fields',
				'The acknowledgement contains unsupported fields.',
				400,
				array( 'fields' => array_values( $unknown ) )
			);
		}
		if (
			self::ACK_SCHEMA !== ( $payload['schema'] ?? null )
			|| ( isset( $payload['schema_version'] ) && 1 !== (int) $payload['schema_version'] )
			|| ( isset( $payload['operation'] ) && 'ack' !== $payload['operation'] )
		) {
			return $this->error(
				'digitalogic_pricing_confirmation_ack_schema_invalid',
				'The acknowledgement schema or operation is not supported.',
				422
			);
		}

		$transaction_id     = is_string( $payload['transaction_id'] ?? null )
			? trim( $payload['transaction_id'] )
			: '';
		$consumer_id        = is_string( $payload['consumer_id'] ?? null )
			? trim( $payload['consumer_id'] )
			: '';
		$channel            = is_string( $payload['channel'] ?? null )
			? trim( $payload['channel'] )
			: '';
		$committed_revision = is_string( $payload['committed_state_revision'] ?? null )
			? trim( $payload['committed_state_revision'] )
			: '';
		$submitted_digest   = is_string( $payload['confirmed_settings_digest'] ?? null )
			? trim( $payload['confirmed_settings_digest'] )
			: '';
		$idempotency_key    = is_string( $payload['idempotency_key'] ?? null )
			? trim( $payload['idempotency_key'] )
			: '';
		$header_key         = trim( (string) $request->get_header( 'idempotency-key' ) );
		if (
			1 !== preg_match( '/\Aptx_[a-f0-9]{32}\z/D', $transaction_id )
			|| ! $this->is_revision( $committed_revision )
			|| ! $this->is_revision( $submitted_digest )
			|| strlen( $idempotency_key ) < 8
			|| strlen( $idempotency_key ) > 128
			|| ( '' !== $header_key && ! hash_equals( $idempotency_key, $header_key ) )
		) {
			return $this->error(
				'digitalogic_pricing_confirmation_ack_invalid',
				'The acknowledgement identifiers are invalid.',
				400
			);
		}
		$source = $this->normalize_source( $payload['source'] ?? null );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$confirmed_settings = $this->normalize_settings( $payload['confirmed_settings'] ?? null );
		if ( is_wp_error( $confirmed_settings ) ) {
			return $confirmed_settings;
		}
		$computed_digest = $this->revision( $confirmed_settings );
		if ( ! hash_equals( $computed_digest, $submitted_digest ) ) {
			return $this->error(
				'digitalogic_pricing_confirmation_ack_digest_mismatch',
				'The acknowledged settings do not match their digest.',
				409
			);
		}

		$ack        = array(
			'transaction_id'            => $transaction_id,
			'consumer_id'               => $consumer_id,
			'channel'                   => $channel,
			'source'                    => $source,
			'committed_state_revision'  => $committed_revision,
			'confirmed_settings'        => $confirmed_settings,
			'confirmed_settings_digest' => $computed_digest,
			'idempotency_key'           => $idempotency_key,
		);
		$ack_digest = $this->revision( $ack );

		$result = $this->with_lock(
			function () use ( $ack, $ack_digest ) {
				return $this->run_transaction(
					function () use ( $ack, $ack_digest ) {
						$row          = $this->read_option_db( self::CONFIRMATIONS_OPTION, true );
						$ledger       = is_array( $row['value'] ?? null ) ? $row['value'] : array();
						$transactions = is_array( $ledger['transactions'] ?? null ) ? $ledger['transactions'] : array();
						$id           = $ack['transaction_id'];
						$record       = is_array( $transactions[ $id ] ?? null ) ? $transactions[ $id ] : null;
						if ( ! is_array( $record ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_not_found',
								'The pricing confirmation transaction does not exist.',
								404
							);
						}
						if ( 'acknowledged' === ( $record['status'] ?? '' ) ) {
							if (
								hash_equals( (string) ( $record['ack_idempotency_key'] ?? '' ), $ack['idempotency_key'] )
								&& hash_equals( (string) ( $record['ack_digest'] ?? '' ), $ack_digest )
							) {
								$projection           = $this->confirmation_projection( $record );
								$projection['status'] = 'replayed';
								return $projection;
							}

							return $this->error(
								'digitalogic_pricing_confirmation_ack_conflict',
								'This transaction was acknowledged with different content.',
								409
							);
						}
						if ( 'awaiting_ack' !== ( $record['status'] ?? '' ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_ack_closed',
								'This pricing confirmation can no longer be acknowledged.',
								409,
								array( 'status' => (string) ( $record['status'] ?? 'unknown' ) )
							);
						}
						if ( time() > (int) ( $record['ack_deadline'] ?? 0 ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_ack_expired',
								'The workbook acknowledgement deadline has expired.',
								409
							);
						}
						$consumer = is_array( $record['consumer'] ?? null ) ? $record['consumer'] : array();
						$matches  = self::REQUIRED_CONSUMER_ID === $ack['consumer_id']
							&& self::REQUIRED_CONSUMER_CHANNEL === $ack['channel']
							&& hash_equals( (string) ( $consumer['consumer_id'] ?? '' ), $ack['consumer_id'] )
							&& hash_equals( (string) ( $consumer['channel'] ?? '' ), $ack['channel'] )
							&& 'pricing_settings_ack' === (string) ( $consumer['capability'] ?? '' )
							&& hash_equals( (string) ( $consumer['source_id'] ?? '' ), $ack['source']['id'] )
							&& hash_equals( (string) ( $consumer['dataset'] ?? '' ), $ack['source']['dataset'] )
							&& hash_equals( (string) $record['committed_revision'], $ack['committed_state_revision'] )
							&& hash_equals( (string) $record['committed_settings_digest'], $ack['confirmed_settings_digest'] )
							&& hash_equals( $this->revision( $record['committed_settings'] ), $this->revision( $ack['confirmed_settings'] ) );
						if ( ! $matches ) {
							return $this->error(
								'digitalogic_pricing_confirmation_ack_mismatch',
								'The acknowledgement does not match the configured workbook or website commit.',
								409
							);
						}
						$current = $this->read_globals();
						if ( is_wp_error( $current ) ) {
							return $current;
						}
						if ( ! hash_equals( (string) $record['committed_revision'], $current['state_revision'] ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_state_conflict',
								'The website pricing state changed before acknowledgement.',
								409,
								array( 'current_state_revision' => $current['state_revision'] )
							);
						}

						$record['status']              = 'acknowledged';
						$record['acknowledged_at']     = time();
						$record['ack_idempotency_key'] = $ack['idempotency_key'];
						$record['ack_digest']          = $ack_digest;
						$transactions[ $id ]           = $record;
						$ledger['active']              = null;
						$ledger['transactions']        = $transactions;
						$stored                        = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
						if ( is_wp_error( $stored ) ) {
							return $stored;
						}

						return $this->confirmation_projection( $record );
					}
				);
			}
		);
		if ( is_wp_error( $result ) ) {
			if ( 'digitalogic_pricing_confirmation_ack_expired' === $result->get_error_code() ) {
				$this->schedule_confirmation_timeout(
					array(
						'transaction_id' => $transaction_id,
						'ack_deadline'   => time(),
					)
				);
			}
			return $result;
		}

		wp_clear_scheduled_hook( self::CONFIRMATION_TIMEOUT_HOOK, array( $transaction_id ) );
		try {
			do_action( 'digitalogic_pricing_confirmation_acknowledged', $result );
		} catch ( Throwable $exception ) {
			unset( $exception );
		}

		return $result;
	}

	/**
	 * Build a preview while holding the synchronization lock.
	 *
	 * @param array  $source                    Exact current source.
	 * @param string $expected_state_revision   Expected settings revision.
	 * @param array  $settings                  Proposed settings.
	 * @param array  $request_context           Nonsecret client trace context.
	 * @return array|WP_Error
	 */
	private function build_preview( $source, $expected_state_revision, $settings, $request_context ) {
		$source_context = $this->validate_current_source( $source );
		if ( is_wp_error( $source_context ) ) {
			return $source_context;
		}

		$current = $this->read_globals();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! hash_equals( $current['state_revision'], $expected_state_revision ) ) {
			return $this->revision_conflict( $current['state_revision'] );
		}
		$desired = $this->globals_from_settings( $settings );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}

		$warnings       = $this->comparison_warnings( $current, $settings );
		$source_warning = $this->source_revision_warning( $source_context );
		if ( null !== $source_warning ) {
			array_unshift( $warnings, $source_warning );
		}
		$expires  = time() + self::PREVIEW_TTL_SECONDS;
		$identity = array(
			'schema'                  => self::PREVIEW_SCHEMA,
			'source'                  => $source,
			'expected_state_revision' => $expected_state_revision,
			'settings'                => $settings,
			'request_context'         => $request_context,
			'expires_at'              => $expires,
		);
		$digest   = 'sha256:' . hash_hmac(
			'sha256',
			$this->canonical_json( $identity ),
			wp_salt( 'auth' )
		);
		$preview  = array_merge(
			$identity,
			array(
				'preview_digest' => $digest,
				'warnings'       => $warnings,
			)
		);

		if ( ! set_transient( $this->preview_key( $digest ), $preview, self::PREVIEW_TTL_SECONDS ) ) {
			return $this->error(
				'digitalogic_excel_sync_preview_store_failed',
				'ذخیرهٔ پیش‌نمایش ایمن انجام نشد.',
				503,
				array( 'retryable' => true )
			);
		}

		return array(
			'schema'          => self::PREVIEW_SCHEMA,
			'mode'            => 'preview',
			'status'          => empty( $warnings ) ? 'ready' : 'confirmation_required',
			'state_revision'  => $current['state_revision'],
			'source'          => $source_context,
			'client_id'       => $request_context['client_id'],
			'channel'         => $request_context['channel'],
			'request_id'      => $request_context['request_id'],
			'preview_digest'  => $digest,
			'expires_at'      => gmdate( 'c', $expires ),
			'warnings'        => $warnings,
			'product_results' => array(),
		);
	}

	/**
	 * Apply settings under the synchronization lock.
	 *
	 * @param array  $source                    Exact current source.
	 * @param array  $source_context             Submitted/current source revisions.
	 * @param string $idempotency_key           Apply idempotency key.
	 * @param string $expected_state_revision   Expected settings revision.
	 * @param array  $settings                  Canonical settings.
	 * @param string $preview_digest            Bound preview digest.
	 * @param array  $preview                   Stored preview.
	 * @param array  $request_context           Nonsecret client trace context.
	 * @return array|WP_Error
	 */
	private function apply_locked( $source, $source_context, $idempotency_key, $expected_state_revision, $settings, $preview_digest, $preview, $request_context ) {
		$current = $this->read_globals();
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$desired = $this->globals_from_settings( $settings );
		if ( is_wp_error( $desired ) ) {
			return $desired;
		}
		$changed              = ! hash_equals( $current['state_revision'], $desired['state_revision'] );
		$companion_completion = 'digitalogic-price-calculator' === $request_context['client_id']
			&& 'excel-workbook' === $request_context['channel'];
		$repricing_performed  = false;

		if ( $changed ) {
			$result = $this->run_coordinated_pricing_transaction(
				function () use ( $source, $source_context, $idempotency_key, $expected_state_revision, $settings, $preview_digest, $desired, $request_context ) {
					foreach (
						array(
							'dollar_price',
							'options_dollar_price',
							'yuan_price',
							'options_yuan_price',
							'update_date',
							'options_update_date',
							Digitalogic_Shipping_Method_Service::METHODS_OPTION,
							Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION,
							Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION,
							self::SETTINGS_OPTION,
							self::AUDIT_OPTION,
						) as $option_name
					) {
						$this->read_option_db( $option_name, true );
					}

					$locked_current = $this->read_globals();
					if ( is_wp_error( $locked_current ) ) {
						return $locked_current;
					}
					if ( ! hash_equals( $expected_state_revision, $locked_current['state_revision'] ) ) {
						return $this->revision_conflict( $locked_current['state_revision'] );
					}

					$options = $this->desired_option_values( $source, $settings, $locked_current, $desired );
					if ( is_wp_error( $options ) ) {
						return $options;
					}
					foreach ( $options as $name => $value ) {
						$stored = $this->store_option_verified( $name, $value );
						if ( is_wp_error( $stored ) ) {
							return $stored;
						}
					}

					$audit = $this->append_audit_entry(
						$source,
						$source_context,
						$idempotency_key,
						$preview_digest,
						$locked_current,
						$desired,
						$settings,
						$request_context
					);
					if ( is_wp_error( $audit ) ) {
						return $audit;
					}

					$readback = $this->read_globals();
					if ( is_wp_error( $readback ) ) {
						return $readback;
					}
					$readback = $this->transaction_consistent_readback(
						$readback,
						$desired,
						$locked_current
					);
					if ( ! hash_equals( $desired['state_revision'], $readback['state_revision'] ) ) {
						return $this->error(
							'digitalogic_excel_sync_readback_failed',
							'مقادیر ذخیره‌شده با درخواست همگام‌سازی یکسان نیست.',
							500,
							array(
								'expected_revision' => $desired['state_revision'],
								'actual_revision'   => $readback['state_revision'],
							)
						);
					}

					$repricing = Digitalogic_Pricing_Coordinator::instance()->reprice_open_transaction(
						$settings,
						$locked_current['shipping']['catalog_revision']
					);
					if ( is_wp_error( $repricing ) ) {
						return $repricing;
					}
					$confirmation = $this->stage_confirmation_open_transaction(
						$locked_current,
						$readback,
						$this->settings_from_globals( $locked_current ),
						$this->settings_from_globals( $readback ),
						$this->current_ack_consumer(),
						$request_context,
						$idempotency_key,
						$preview_digest
					);
					if ( is_wp_error( $confirmation ) ) {
						return $confirmation;
					}

					return array(
						'readback'     => $readback,
						'repricing'    => $repricing,
						'confirmation' => $confirmation,
					);
				}
			);
			Digitalogic_Pricing_Coordinator::instance()->flush_repricing_caches();
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$readback     = $result['readback'];
			$repricing    = $result['repricing'];
			$confirmation = $result['confirmation'] ?? null;

			$repricing_performed = true;
		} elseif ( $companion_completion ) {
			$readback     = $current;
			$confirmation = null;
			$repricing    = array(
				'updated_products' => 0,
				'deferred_missing' => 0,
				'warnings'         => array(),
				'sources'          => array(),
			);
		} else {
			$readback     = $current;
			$confirmation = null;
			$result       = $this->run_coordinated_pricing_transaction(
				function () use ( $settings, $current ) {
					return Digitalogic_Pricing_Coordinator::instance()->reprice_open_transaction(
						$settings,
						$current['shipping']['catalog_revision']
					);
				}
			);
			Digitalogic_Pricing_Coordinator::instance()->flush_repricing_caches();
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$repricing           = $result;
			$repricing_performed = true;
		}

		$warnings       = isset( $preview['warnings'] ) && is_array( $preview['warnings'] )
			? array_values( $preview['warnings'] )
			: array();
		$warnings       = array_values(
			array_filter(
				$warnings,
				static function ( $warning ) {
					return ! is_array( $warning )
						|| 'source_revision_out_of_sync' !== ( $warning['code'] ?? '' );
				}
			)
		);
		$source_warning = $this->source_revision_warning( $source_context );
		if ( null !== $source_warning ) {
			array_unshift( $warnings, $source_warning );
		}
		if ( $repricing_performed ) {
			$warnings[] = $this->warning(
				'pricing_reconciled',
				'تنظیمات و قیمت نهایی کالاهای موجود با خواندن مجدد ووکامرس هماهنگ شد.',
				'info',
				array(
					'updated_products' => (int) $repricing['updated_products'],
					'deferred_missing' => (int) $repricing['deferred_missing'],
				)
			);
		} else {
			$warnings[] = $this->warning(
				'settings_already_current',
				'تنظیمات از قبل به‌روز است؛ همگام‌سازی اجباری کالاها و بازخوانی نهایی ووکامرس توسط برنامهٔ همراه ادامه می‌یابد.',
				'info',
				array(
					'completion' => 'canonical_product_sync_and_storefront_readback',
				)
			);
		}
		foreach ( (array) ( $repricing['warnings'] ?? array() ) as $pricing_warning ) {
			if (
				! is_array( $pricing_warning )
				|| empty( $pricing_warning['code'] )
				|| empty( $pricing_warning['message'] )
			) {
				continue;
			}
			$warnings[] = $this->warning(
				(string) $pricing_warning['code'],
				(string) $pricing_warning['message'],
				'warning',
				array_intersect_key(
					$pricing_warning,
					array(
						'product_code'   => true,
						'woocommerce_id' => true,
					)
				)
			);
		}
		$source_event = Digitalogic_Pricing_Snapshot::instance()->ensure_source_lifecycle_event(
			(array) ( $repricing['source_state_before'] ?? array() ),
			(array) ( $repricing['source_state_after'] ?? array() )
		);
		if ( is_wp_error( $source_event ) ) {
			$warnings[] = $this->warning(
				'pricing_source_event_pending',
				'تغییر منبع قیمت ثبت شد؛ انتشار رویداد منبع در صف بازیابی باقی ماند.',
				'warning',
				array( 'error_code' => $source_event->get_error_code() )
			);
		}

		$response_settings = $this->settings_from_globals( $readback );
		$response          = array(
			'schema'          => self::APPLY_SCHEMA,
			'mode'            => 'apply',
			'status'          => $changed ? 'applied' : 'reconciled',
			'state_revision'  => $readback['state_revision'],
			'source'          => $source_context,
			'client_id'       => $request_context['client_id'],
			'channel'         => $request_context['channel'],
			'request_id'      => $request_context['request_id'],
			'settings'        => $response_settings,
			'preview_digest'  => $preview_digest,
			'expires_at'      => gmdate( 'c', (int) $preview['expires_at'] ),
			'warnings'        => $warnings,
			'product_results' => $repricing['sources'],
			'confirmation'    => $confirmation ?? $this->current_confirmation_projection(),
		);

		if ( $repricing_performed ) {
			Digitalogic_Pricing_Coordinator::instance()->publish_repricing_result( $repricing );
		}
		if ( $changed ) {
			$this->emit_after_apply( $source, $expected_state_revision, $current, $readback, $response_settings, $request_context );
			if ( ! empty( $confirmation ) ) {
				$this->schedule_confirmation_timeout( $confirmation );
				$this->publish_confirmation_outbox();
			}
		}

		return $response;
	}

	/**
	 * Validate one operation request envelope.
	 *
	 * @param WP_REST_Request $request   Current request.
	 * @param string          $operation state, preview, or apply.
	 * @return array|WP_Error
	 */
	private function validate_request( WP_REST_Request $request, $operation ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || array_is_list( $payload ) ) {
			return $this->error(
				'digitalogic_excel_sync_payload_invalid',
				'بدنهٔ درخواست باید یک شیء JSON باشد.',
				400
			);
		}

		$allowed = array(
			'schema',
			'schema_version',
			'source',
			'operation',
			'page',
			'limit',
			'locale',
			'projection',
			'client_id',
			'channel',
			'request_id',
			'idempotency_key',
			'expected_state_revision',
			'settings',
			'product_changes',
			'preview_digest',
			'confirmation',
			'confirm',
		);
		$unknown = array_diff( array_keys( $payload ), $allowed );
		if ( $unknown ) {
			return $this->error(
				'digitalogic_excel_sync_unknown_fields',
				'بدنهٔ درخواست دارای فیلد پشتیبانی‌نشده است.',
				400,
				array( 'fields' => array_values( $unknown ) )
			);
		}

		if (
			! isset( $payload['schema'] )
			|| ! in_array( $payload['schema'], array( self::REQUEST_SCHEMA, self::LEGACY_REQUEST_SCHEMA ), true )
		) {
			return $this->error(
				'digitalogic_excel_sync_schema_unsupported',
				'نسخهٔ قرارداد همگام‌سازی پشتیبانی نمی‌شود.',
				422
			);
		}
		$payload['request_schema_deprecated'] = self::LEGACY_REQUEST_SCHEMA === $payload['schema'];
		if ( isset( $payload['schema_version'] ) && 1 !== (int) $payload['schema_version'] ) {
			return $this->error(
				'digitalogic_excel_sync_schema_version_unsupported',
				'فقط schema_version برابر ۱ پشتیبانی می‌شود.',
				422
			);
		}
		if ( isset( $payload['operation'] ) && $operation !== $payload['operation'] ) {
			return $this->error(
				'digitalogic_excel_sync_operation_mismatch',
				'عملیات بدنه با مسیر درخواست یکسان نیست.',
				400
			);
		}

		$source = $this->normalize_source( $payload['source'] ?? null );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$payload['source'] = $source;

		$request_context = $this->normalize_request_context( $payload, $operation );
		if ( is_wp_error( $request_context ) ) {
			return $request_context;
		}
		$payload = array_merge( $payload, $request_context );

		if ( isset( $payload['locale'] ) && ! in_array( $payload['locale'], array( 'fa', 'fa_IR' ), true ) ) {
			return $this->error(
				'digitalogic_excel_sync_locale_invalid',
				'خروجی این قرارداد فقط به زبان فارسی ارائه می‌شود.',
				400
			);
		}
		if (
			isset( $payload['projection'] )
			&& (
				'state' !== $operation
				|| ! in_array( $payload['projection'], array( 'catalog', 'settings' ), true )
			)
		) {
			return $this->error(
				'digitalogic_excel_sync_projection_invalid',
				'نوع خروجی state معتبر نیست.',
				400
			);
		}

		if ( isset( $payload['product_changes'] ) && array() !== $payload['product_changes'] ) {
			return $this->error(
				'digitalogic_excel_sync_product_changes_forbidden',
				'قیمت محصول مشتق‌شده است و در این مسیر مستقیماً نوشته نمی‌شود.',
				422
			);
		}

		return $payload;
	}

	/**
	 * Validate bounded, nonsecret client provenance for audit attribution.
	 *
	 * Primary clients should send all three fields. Missing values remain
	 * explicit for backward compatibility instead of being guessed from auth.
	 *
	 * @param array  $payload   Request payload.
	 * @param string $operation state, preview, or apply.
	 * @return array|WP_Error
	 */
	private function normalize_request_context( $payload, $operation ) {
		$defaults = array(
			'client_id'  => 'unidentified-client',
			'channel'    => ! empty( $payload['request_schema_deprecated'] ) ? 'legacy' : 'api',
			'request_id' => isset( $payload['idempotency_key'] ) && is_string( $payload['idempotency_key'] )
				? $payload['idempotency_key']
				: $operation . '-not-provided',
		);
		$limits   = array(
			'client_id'  => array( 1, 64 ),
			'channel'    => array( 1, 32 ),
			'request_id' => array( 8, 128 ),
		);
		$result   = array();
		foreach ( $limits as $field => $bounds ) {
			$value = $payload[ $field ] ?? $defaults[ $field ];
			if (
				! is_string( $value )
				|| strlen( $value ) < $bounds[0]
				|| strlen( $value ) > $bounds[1]
				|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $value )
			) {
				return $this->error(
					'digitalogic_pricing_request_context_invalid',
					'Pricing request provenance must use bounded nonsecret identifiers.',
					400,
					array( 'field' => $field )
				);
			}
			$result[ $field ] = $value;
		}

		return $result;
	}

	/**
	 * Select the normalized client trace fields from a validated request.
	 *
	 * @param array $payload Validated request.
	 * @return array
	 */
	private function request_context( $payload ) {
		return array(
			'client_id'  => $payload['client_id'],
			'channel'    => $payload['channel'],
			'request_id' => $payload['request_id'],
		);
	}

	/**
	 * Validate idempotency and optimistic-concurrency headers.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param array           $payload Normalized payload.
	 * @return array|WP_Error
	 */
	private function validate_mutation_headers( WP_REST_Request $request, $payload ) {
		$key = $payload['idempotency_key'] ?? null;
		if (
			! is_string( $key )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,127}\z/D', $key )
		) {
			return $this->error(
				'digitalogic_excel_sync_idempotency_invalid',
				'کلید idempotency باید بین ۸ تا ۱۲۸ نویسهٔ مجاز داشته باشد.',
				400
			);
		}
		$header_key = $request->get_header( 'idempotency-key' );
		if ( ! is_string( $header_key ) || ! hash_equals( $key, $header_key ) ) {
			return $this->error(
				'digitalogic_excel_sync_idempotency_header_mismatch',
				'هدر Idempotency-Key باید دقیقاً با بدنه یکسان باشد.',
				400
			);
		}

		$revision = $payload['expected_state_revision'] ?? null;
		if ( ! is_string( $revision ) || ! $this->is_revision( $revision ) ) {
			return $this->error(
				'digitalogic_excel_sync_expected_revision_invalid',
				'expected_state_revision معتبر نیست.',
				400
			);
		}
		$if_match = $request->get_header( 'if-match' );
		if ( ! is_string( $if_match ) || '"' . $revision . '"' !== $if_match ) {
			return $this->error(
				'digitalogic_excel_sync_if_match_mismatch',
				'هدر If-Match باید revision بدنه را به‌صورت نقل‌قول‌شده تکرار کند.',
				400
			);
		}

		return array(
			'idempotency_key'         => $key,
			'expected_state_revision' => $revision,
		);
	}

	/**
	 * Normalize the exact current Patris source identity.
	 *
	 * @param mixed $source Raw source.
	 * @return array|WP_Error
	 */
	private function normalize_source( $source ) {
		if (
			! is_array( $source )
			|| array_is_list( $source )
			|| ! empty( array_diff( array( 'id', 'dataset', 'revision' ), array_keys( $source ) ) )
			|| ! empty( array_diff( array_keys( $source ), array( 'id', 'dataset', 'revision' ) ) )
		) {
			return $this->error(
				'digitalogic_excel_sync_source_invalid',
				'منبع باید دقیقاً شامل id، dataset و revision باشد.',
				400
			);
		}
		foreach ( array( 'id', 'dataset', 'revision' ) as $field ) {
			if ( ! is_string( $source[ $field ] ) || trim( $source[ $field ] ) !== $source[ $field ] ) {
				return $this->error(
					'digitalogic_excel_sync_source_invalid',
					'هویت منبع معتبر نیست.',
					400,
					array( 'field' => 'source.' . $field )
				);
			}
		}
		if (
			'' === $source['id']
			|| '' === $source['dataset']
			|| strlen( $source['id'] ) > 191
			|| strlen( $source['dataset'] ) > 191
			|| ! $this->is_revision( $source['revision'] )
		) {
			return $this->error(
				'digitalogic_excel_sync_source_invalid',
				'هویت یا revision منبع معتبر نیست.',
				400
			);
		}

		return $source;
	}

	/**
	 * Require the exact configured source identity materialized in WordPress.
	 *
	 * A valid submitted revision remains part of request, preview, idempotency,
	 * settings, and audit identities. It may be newer than the product-sync
	 * revision currently stored by WordPress, so revision drift is returned as
	 * non-blocking response metadata instead of an authorization failure.
	 *
	 * @param array $source Requested source.
	 * @return array|WP_Error
	 */
	private function validate_current_source( $source ) {
		$state   = Digitalogic_Product_Sync_Receiver::instance()
			->get_source_state( $source['id'], $source['dataset'] );
		$current = isset( $state['source'] ) && is_array( $state['source'] )
			? $state['source']
			: array();

		if (
			! isset( $current['id'], $current['dataset'], $current['revision'] )
			|| ! is_string( $current['id'] )
			|| ! is_string( $current['dataset'] )
			|| ! is_string( $current['revision'] )
			|| ! hash_equals( $current['id'], $source['id'] )
			|| ! hash_equals( $current['dataset'], $source['dataset'] )
			|| ! $this->is_revision( $current['revision'] )
		) {
			return $this->error(
				'digitalogic_excel_sync_source_scope_conflict',
				'شناسه یا مجموعهٔ منبع محلی با منبع ثبت‌شده در سایت یکسان نیست.',
				409,
				array(
					'current_source' => array(
						'id'       => isset( $current['id'] ) ? (string) $current['id'] : '',
						'dataset'  => isset( $current['dataset'] ) ? (string) $current['dataset'] : '',
						'revision' => isset( $current['revision'] ) ? (string) $current['revision'] : '',
					),
				)
			);
		}

		return array(
			'id'                       => $source['id'],
			'dataset'                  => $source['dataset'],
			'submitted_revision'       => $source['revision'],
			'current_revision'         => $current['revision'],
			'revision_matches_current' => hash_equals( $current['revision'], $source['revision'] ),
		);
	}

	/**
	 * Return a Persian operator warning when the valid submitted revision is
	 * not yet materialized by the WordPress product-sync receiver.
	 *
	 * @param array $source_context Submitted/current source metadata.
	 * @return array|null
	 */
	private function source_revision_warning( $source_context ) {
		if ( ! empty( $source_context['revision_matches_current'] ) ) {
			return null;
		}

		return $this->warning(
			'source_revision_out_of_sync',
			'نسخهٔ منبع محلی با آخرین نسخهٔ ثبت‌شده در سایت یکسان نیست؛ همگام‌سازی تنظیمات برای همین شناسه و مجموعه ادامه یافت.',
			'warning',
			array(
				'source_id'          => $source_context['id'],
				'dataset'            => $source_context['dataset'],
				'submitted_revision' => $source_context['submitted_revision'],
				'current_revision'   => $source_context['current_revision'],
			)
		);
	}

	/**
	 * Normalize a complete settings document.
	 *
	 * The legacy four-field document remains readable and inherits the current
	 * shipping and rounding dependencies without changing them. New clients
	 * submit the exact air_express rate, currency, catalog revision, and
	 * nearest-half-up rounding policy together with currency rates, dates, and
	 * the one shared profit margin.
	 *
	 * @param mixed      $settings Raw settings.
	 * @param array|null $current  Current globals used for legacy date mapping.
	 * @return array|WP_Error
	 */
	private function normalize_settings( $settings, $current = null ) {
		if ( ! is_array( $settings ) || array_is_list( $settings ) ) {
			return $this->error(
				'digitalogic_excel_sync_settings_invalid',
				'تنظیمات باید یک شیء JSON کامل باشد.',
				400
			);
		}

		$aliases = array(
			'usd_irt'                => 'dollar_price',
			'cny_irt'                => 'yuan_price',
			'profit_percent'         => 'profit_margin_percent',
			'default_profit_percent' => 'profit_margin_percent',
		);
		foreach ( $aliases as $alias => $canonical ) {
			if ( ! array_key_exists( $alias, $settings ) ) {
				continue;
			}
			if ( array_key_exists( $canonical, $settings ) && $settings[ $canonical ] !== $settings[ $alias ] ) {
				return $this->error(
					'digitalogic_excel_sync_settings_alias_conflict',
					'مقادیر نام‌های هم‌معنی تنظیمات با هم تعارض دارند.',
					400,
					array( 'field' => $canonical )
				);
			}
			$settings[ $canonical ] = $settings[ $alias ];
			unset( $settings[ $alias ] );
		}

		$required         = array( 'dollar_price', 'yuan_price', 'effective_date', 'profit_margin_percent' );
		$shipping_fields  = array( 'air_express_price_per_kg', 'air_express_currency', 'shipping_catalog_revision' );
		$shipping_present = array_values( array_intersect( $shipping_fields, array_keys( $settings ) ) );
		if ( $shipping_present && count( $shipping_present ) !== count( $shipping_fields ) ) {
			return $this->error(
				'digitalogic_pricing_shipping_settings_incomplete',
				'air_express price, currency, and shipping-catalog revision must be submitted together.',
				400,
				array( 'missing' => array_values( array_diff( $shipping_fields, $shipping_present ) ) )
			);
		}
		$rounding_fields  = array( 'price_rounding_digits', 'price_rounding_mode' );
		$rounding_present = array_values( array_intersect( $rounding_fields, array_keys( $settings ) ) );
		if ( $rounding_present && count( $rounding_present ) !== count( $rounding_fields ) ) {
			return $this->error(
				'digitalogic_pricing_rounding_settings_incomplete',
				'Price-rounding digits and mode must be submitted together.',
				400,
				array( 'missing' => array_values( array_diff( $rounding_fields, $rounding_present ) ) )
			);
		}
		$independent_dates = array( 'usd_effective_date', 'cny_effective_date' );
		$has_usd_date      = array_key_exists( 'usd_effective_date', $settings );
		$has_cny_date      = array_key_exists( 'cny_effective_date', $settings );
		if ( $has_usd_date !== $has_cny_date ) {
			return $this->error(
				'digitalogic_excel_sync_currency_dates_incomplete',
				'تاریخ مؤثر دلار و یوآن باید با هم ارسال شوند.',
				400
			);
		}
		$allowed = $has_usd_date ? array_merge( $required, $independent_dates ) : $required;
		if ( $shipping_present ) {
			$allowed = array_merge( $allowed, $shipping_fields );
		}
		if ( $rounding_present ) {
			$allowed = array_merge( $allowed, $rounding_fields );
		}
		if (
			! empty( array_diff( $required, array_keys( $settings ) ) )
			|| ! empty( array_diff( array_keys( $settings ), $allowed ) )
		) {
			$missing = array_values( array_diff( $required, array_keys( $settings ) ) );
			$unknown = array_values( array_diff( array_keys( $settings ), $allowed ) );
			return $this->error(
				'digitalogic_excel_sync_settings_shape_invalid',
				'سند تنظیمات باید نرخ‌ها، تاریخ‌ها، حاشیه سود و مجموعه کامل تنظیمات حمل را داشته باشد.',
				400,
				array(
					'missing' => $missing,
					'unknown' => $unknown,
				)
			);
		}

		$dollar = $this->canonical_rate( $settings['dollar_price'], 'dollar_price' );
		if ( is_wp_error( $dollar ) ) {
			return $dollar;
		}
		$yuan = $this->canonical_rate( $settings['yuan_price'], 'yuan_price' );
		if ( is_wp_error( $yuan ) ) {
			return $yuan;
		}
		$legacy_date = $this->canonical_date( $settings['effective_date'] );
		if ( is_wp_error( $legacy_date ) ) {
			return $legacy_date;
		}
		$profit = $this->canonical_profit( $settings['profit_margin_percent'] );
		if ( is_wp_error( $profit ) ) {
			return $profit;
		}

		if ( $has_usd_date ) {
			$usd_date = $this->canonical_date( $settings['usd_effective_date'] );
			if ( is_wp_error( $usd_date ) ) {
				return $usd_date;
			}
			$cny_date = $this->canonical_date( $settings['cny_effective_date'] );
			if ( is_wp_error( $cny_date ) ) {
				return $cny_date;
			}
			if ( $legacy_date !== $cny_date ) {
				return $this->error(
					'digitalogic_excel_sync_effective_date_conflict',
					'تاریخ قدیمی effective_date باید با تاریخ مؤثر یوآن یکسان باشد.',
					400
				);
			}
		} else {
			$current = is_array( $current ) ? $current : $this->read_globals();
			if ( is_wp_error( $current ) ) {
				return $current;
			}
			$usd_date = $current['currency']['usd_effective_date'] ?? $legacy_date;
			$cny_date = $current['currency']['cny_effective_date'] ?? $legacy_date;

			$dollar_changed = null === ( $current['currency']['dollar_price'] ?? null )
				|| $current['currency']['dollar_price'] !== $dollar;
			$yuan_changed   = null === ( $current['currency']['yuan_price'] ?? null )
				|| $current['currency']['yuan_price'] !== $yuan;
			if ( $dollar_changed ) {
				$usd_date = $legacy_date;
			}
			if ( $yuan_changed || ! $dollar_changed ) {
				$cny_date = $legacy_date;
			}
		}
		$current = is_array( $current ) ? $current : $this->read_globals();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( $shipping_present ) {
			$shipping_price = $this->canonical_shipping_price( $settings['air_express_price_per_kg'] );
			if ( is_wp_error( $shipping_price ) ) {
				return $shipping_price;
			}
			$shipping_currency = $settings['air_express_currency'];
			if ( ! is_string( $shipping_currency ) || ! in_array( $shipping_currency, array( 'CNY', 'IRR' ), true ) ) {
				return $this->error(
					'digitalogic_pricing_shipping_currency_invalid',
					'air_express currency must be CNY or IRR.',
					400,
					array( 'field' => 'settings.air_express_currency' )
				);
			}
			$shipping_revision = $settings['shipping_catalog_revision'];
			if ( ! is_string( $shipping_revision ) || ! $this->is_revision( $shipping_revision ) ) {
				return $this->error(
					'digitalogic_pricing_shipping_revision_invalid',
					'Shipping-catalog revision is invalid.',
					400,
					array( 'field' => 'settings.shipping_catalog_revision' )
				);
			}
		} else {
			$shipping_price    = $current['shipping']['price_per_kg'];
			$shipping_currency = $current['shipping']['currency'];
			$shipping_revision = $current['shipping']['catalog_revision'];
		}
		if ( $rounding_present ) {
			$rounding_digits = $this->canonical_rounding_digits( $settings['price_rounding_digits'] );
			if ( is_wp_error( $rounding_digits ) ) {
				return $rounding_digits;
			}
			if (
				! is_string( $settings['price_rounding_mode'] )
				|| Digitalogic_Shipping_Method_Service::ROUNDING_MODE !== $settings['price_rounding_mode']
			) {
				return $this->error(
					'digitalogic_pricing_rounding_mode_invalid',
					'Price-rounding mode must be nearest_half_up.',
					400,
					array( 'field' => 'settings.price_rounding_mode' )
				);
			}
			$rounding_mode = $settings['price_rounding_mode'];
		} else {
			$rounding_digits = $current['price_rounding']['rounding_digits'];
			$rounding_mode   = $current['price_rounding']['rounding_mode'];
		}

		return array(
			'dollar_price'              => $dollar,
			'yuan_price'                => $yuan,
			'effective_date'            => $cny_date,
			'usd_effective_date'        => $usd_date,
			'cny_effective_date'        => $cny_date,
			'profit_margin_percent'     => $profit,
			'price_rounding_digits'     => $rounding_digits,
			'price_rounding_mode'       => $rounding_mode,
			'air_express_price_per_kg'  => $shipping_price,
			'air_express_currency'      => $shipping_currency,
			'shipping_catalog_revision' => $shipping_revision,
		);
	}

	/**
	 * Canonicalize one positive integer IRT exchange rate.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $field Field name.
	 * @return int|WP_Error
	 */
	private function canonical_rate( $value, $field ) {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
			$text = sprintf( '%.0F', $value );
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
		} else {
			$text = '';
		}

		if ( 1 !== preg_match( '/\A[0-9]{1,10}\z/D', $text ) ) {
			return $this->error(
				'digitalogic_excel_sync_rate_invalid',
				'نرخ ارز باید یک عدد صحیح مثبت به تومان باشد.',
				400,
				array( 'field' => 'settings.' . $field )
			);
		}
		$value = (int) ltrim( $text, '0' );
		if ( $value < 1 || $value > self::MAX_RATE ) {
			return $this->error(
				'digitalogic_excel_sync_rate_out_of_range',
				'نرخ ارز خارج از محدودهٔ مجاز است.',
				400,
				array( 'field' => 'settings.' . $field )
			);
		}

		return $value;
	}

	/**
	 * Canonicalize the nearest-half-up trailing IRT digit count.
	 *
	 * @param mixed $value Raw value.
	 * @return int|WP_Error
	 */
	private function canonical_rounding_digits( $value ) {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
			$text = strtr(
				$text,
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
		} else {
			$text = '';
		}
		if ( 1 !== preg_match( '/\A[0-9]\z/D', $text ) ) {
			return $this->error(
				'digitalogic_pricing_rounding_digits_invalid',
				'Price-rounding digits must be an integer from 0 through 9.',
				400,
				array( 'field' => 'settings.price_rounding_digits' )
			);
		}

		return (int) $text;
	}

	/**
	 * Canonicalize the exact positive air_express price per kilogram.
	 *
	 * @param mixed $value Raw decimal value.
	 * @return string|WP_Error
	 */
	private function canonical_shipping_price( $value ) {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
		} else {
			$text = '';
		}
		if ( 1 !== preg_match( '/\A(0|[1-9][0-9]{0,17})(?:\.([0-9]{1,12}))?\z/D', $text, $matches ) ) {
			return $this->error(
				'digitalogic_pricing_shipping_price_invalid',
				'air_express price_per_kg must be a positive base-10 decimal with at most 12 fractional digits.',
				400,
				array( 'field' => 'settings.air_express_price_per_kg' )
			);
		}
		$fraction = isset( $matches[2] ) ? rtrim( $matches[2], '0' ) : '';
		$result   = $matches[1] . ( '' === $fraction ? '' : '.' . $fraction );
		if ( '0' === $result ) {
			return $this->error(
				'digitalogic_pricing_shipping_price_invalid',
				'air_express price_per_kg must be greater than zero.',
				400,
				array( 'field' => 'settings.air_express_price_per_kg' )
			);
		}

		return $result;
	}

	/**
	 * Canonicalize the global percentage markup.
	 *
	 * @param mixed $value Raw value.
	 * @return string|WP_Error
	 */
	private function canonical_profit( $value ) {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) && is_finite( $value ) ) {
			$text = wp_json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
		} else {
			$text = '';
		}
		if ( ! is_string( $text ) || strlen( $text ) > 64 || 1 !== preg_match( '/\A[+]?[0-9]+(?:\.[0-9]+)?\z/D', $text ) ) {
			return $this->error(
				'digitalogic_excel_sync_profit_invalid',
				'حاشیه سود باید یک عدد ده‌دهی نامنفی باشد.',
				400,
				array( 'field' => 'settings.profit_margin_percent' )
			);
		}

		$text     = ltrim( $text, '+' );
		$parts    = explode( '.', $text, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = isset( $parts[1] ) ? rtrim( $parts[1], '0' ) : '';
		if ( strlen( $fraction ) > self::MAX_PROFIT_SCALE ) {
			return $this->error(
				'digitalogic_excel_sync_profit_scale_invalid',
				'دقت اعشاری حاشیه سود بیش از حد مجاز است.',
				400
			);
		}
		if (
			strlen( $integer ) > strlen( self::MAX_PROFIT_PERCENT )
			|| (
				strlen( $integer ) === strlen( self::MAX_PROFIT_PERCENT )
				&& strcmp( $integer, self::MAX_PROFIT_PERCENT ) > 0
			)
			|| ( self::MAX_PROFIT_PERCENT === $integer && '' !== $fraction )
		) {
			return $this->error(
				'digitalogic_excel_sync_profit_out_of_range',
				'حاشیه سود باید بین صفر و ۱۰۰۰ درصد باشد.',
				400
			);
		}

		return '' === $fraction ? $integer : $integer . '.' . $fraction;
	}

	/**
	 * Canonicalize a strict Gregorian effective date.
	 *
	 * @param mixed $value Raw value.
	 * @return string|WP_Error
	 */
	private function canonical_date( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $matches ) ) {
			return $this->error(
				'digitalogic_excel_sync_effective_date_invalid',
				'تاریخ مؤثر باید به شکل YYYY-MM-DD باشد.',
				400
			);
		}
		if ( ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
			return $this->error(
				'digitalogic_excel_sync_effective_date_invalid',
				'تاریخ مؤثر معتبر نیست.',
				400
			);
		}

		return $value;
	}

	/**
	 * Read and version the installed global settings.
	 *
	 * @return array|WP_Error
	 */
	private function read_globals() {
		$for_update   = $this->transaction_active;
		$dollar_row   = $this->authoritative_currency_option(
			'options_dollar_price',
			'dollar_price',
			$for_update
		);
		$yuan_row     = $this->authoritative_currency_option(
			'options_yuan_price',
			'yuan_price',
			$for_update
		);
		$date_row     = $this->read_option_db( 'options_update_date', $for_update );
		$metadata_row = $this->read_option_db( self::SETTINGS_OPTION, $for_update );
		if ( ! $date_row['exists'] ) {
			$date_row = $this->read_option_db( 'update_date', $for_update );
		}
		$dollar = $this->installed_rate( $dollar_row['exists'] ? $dollar_row['value'] : null );
		$yuan   = $this->installed_rate( $yuan_row['exists'] ? $yuan_row['value'] : null );
		$date   = Digitalogic_Currency_Date_Formatter::instance()->parse(
			$date_row['exists'] ? $date_row['value'] : null
		);

		$legacy_effective_date = null;
		if ( $date instanceof DateTimeImmutable ) {
			$legacy_effective_date = $date->format( 'Y-m-d' );
		}

		$metadata         = $metadata_row['exists'] && is_array( $metadata_row['value'] )
			? $metadata_row['value']
			: array();
		$has_usd_metadata = array_key_exists( 'usd_effective_date', $metadata );
		$has_cny_metadata = array_key_exists( 'cny_effective_date', $metadata );
		if ( $has_usd_metadata !== $has_cny_metadata ) {
			return $this->error(
				'digitalogic_pricing_currency_date_metadata_invalid',
				'فرادادهٔ تاریخ ارز ناقص است؛ تاریخ دلار و یوآن باید با هم ثبت شوند.',
				500
			);
		}
		$usd_effective_date = $legacy_effective_date;
		$cny_effective_date = $legacy_effective_date;
		if ( $has_usd_metadata ) {
			$usd_effective_date = $this->canonical_date( $metadata['usd_effective_date'] );
			$cny_effective_date = $this->canonical_date( $metadata['cny_effective_date'] );
			if ( is_wp_error( $usd_effective_date ) || is_wp_error( $cny_effective_date ) ) {
				return $this->error(
					'digitalogic_pricing_currency_date_metadata_invalid',
					'فرادادهٔ تاریخ ارز معتبر نیست.',
					500
				);
			}
			if (
				isset( $metadata['effective_date'] )
				&& $metadata['effective_date'] !== $cny_effective_date
			) {
				return $this->error(
					'digitalogic_pricing_currency_date_metadata_invalid',
					'تاریخ قدیمی ارز با تاریخ مؤثر یوآن یکسان نیست.',
					500
				);
			}
			if (
				null !== $legacy_effective_date
				&& $legacy_effective_date !== $cny_effective_date
			) {
				return $this->error(
					'digitalogic_pricing_currency_date_metadata_invalid',
					'تاریخ مؤثر یوآن با تاریخ قدیمی ذخیره‌شده یکسان نیست.',
					500
				);
			}
		}

		$rate_provenance  = $this->installed_rate_provenance(
			$metadata,
			$has_usd_metadata ? 'metadata' : 'legacy_shared'
		);
		$usd_age_days     = null === $usd_effective_date ? null : $this->age_days( $usd_effective_date );
		$cny_age_days     = null === $cny_effective_date ? null : $this->age_days( $cny_effective_date );
		$usd_stale        = null === $dollar
			|| null === $usd_age_days
			|| $usd_age_days < 0
			|| $usd_age_days > self::STALE_AFTER_DAYS;
		$cny_stale        = null === $yuan
			|| null === $cny_age_days
			|| $cny_age_days < 0
			|| $cny_age_days > self::STALE_AFTER_DAYS;
		$stale_currencies = array();
		if ( $usd_stale ) {
			$stale_currencies[] = 'USD';
		}
		if ( $cny_stale ) {
			$stale_currencies[] = 'CNY';
		}
		$currency_freshness_revision = $this->revision(
			array(
				'schema'           => self::SETTINGS_SCHEMA . '/currency-freshness',
				'stale_currencies' => $stale_currencies,
			)
		);

		$markup = Digitalogic_Shipping_Method_Service::instance()->get_default_percentage_markup();
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}
		$price_rounding = Digitalogic_Shipping_Method_Service::instance()->get_price_rounding_policy();
		if ( is_wp_error( $price_rounding ) ) {
			return $price_rounding;
		}
		$shipping_catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		if ( is_wp_error( $shipping_catalog ) ) {
			return $shipping_catalog;
		}
		$shipping = $this->installed_air_express_shipping( $shipping_catalog );
		if ( is_wp_error( $shipping ) ) {
			return $shipping;
		}
		$markup_revision            = isset( $markup['revision'] ) && is_string( $markup['revision'] )
			? $markup['revision']
			: $this->revision( array( 'configured' => false ) );
		$profit                     = ! empty( $markup['configured'] ) && isset( $markup['profit_percent'] )
			? (string) $markup['profit_percent']
			: null;
		$rounding_revision          = $this->revision(
			array(
				'rounding_digits' => $price_rounding['rounding_digits'],
				'rounding_mode'   => $price_rounding['rounding_mode'],
			)
		);
		$price_rounding['revision'] = $rounding_revision;
		$currency_material          = array(
			'dollar_price'       => $dollar,
			'yuan_price'         => $yuan,
			'usd_effective_date' => $usd_effective_date,
			'cny_effective_date' => $cny_effective_date,
		);
		$currency_revision          = $this->revision(
			array_merge(
				array( 'schema' => self::SETTINGS_SCHEMA . '/currency' ),
				$currency_material
			)
		);

		$currency       = array(
			'dollar_price'       => $dollar,
			'yuan_price'         => $yuan,
			'effective_date'     => $cny_effective_date,
			'usd_effective_date' => $usd_effective_date,
			'cny_effective_date' => $cny_effective_date,
			'revision'           => $currency_revision,
			'age_days'           => $cny_age_days,
			'stale'              => $usd_stale || $cny_stale,
			'stale_currencies'   => $stale_currencies,
			'freshness'          => array(
				'usd' => array(
					'effective_date' => $usd_effective_date,
					'age_days'       => $usd_age_days,
					'stale'          => $usd_stale,
				),
				'cny' => array(
					'effective_date' => $cny_effective_date,
					'age_days'       => $cny_age_days,
					'stale'          => $cny_stale,
				),
			),
			'rate_provenance'    => $rate_provenance,
		);
		$default_markup = array(
			'configured'     => ! empty( $markup['configured'] ),
			'profit_percent' => $profit,
			'revision'       => $markup_revision,
			'updated_at'     => isset( $markup['updated_at'] ) && is_string( $markup['updated_at'] )
				? $markup['updated_at']
				: null,
		);

		return array(
			'state_revision' => $this->revision(
				array(
					'schema'                      => self::SETTINGS_SCHEMA,
					'currency_revision'           => $currency_revision,
					'currency_freshness_revision' => $currency_freshness_revision,
					'default_markup_revision'     => $markup_revision,
					'price_rounding_revision'     => $rounding_revision,
					'shipping_catalog_revision'   => $shipping['catalog_revision'],
				)
			),
			'currency'       => $currency,
			'default_markup' => $default_markup,
			'price_rounding' => $price_rounding,
			'shipping'       => $shipping,
		);
	}

	/**
	 * Project post-readback globals into the primary composite settings shape.
	 *
	 * @param array $globals Validated global pricing state.
	 * @return array
	 */
	private function settings_from_globals( $globals ) {
		return array(
			'dollar_price'              => $globals['currency']['dollar_price'],
			'yuan_price'                => $globals['currency']['yuan_price'],
			'effective_date'            => $globals['currency']['cny_effective_date'],
			'usd_effective_date'        => $globals['currency']['usd_effective_date'],
			'cny_effective_date'        => $globals['currency']['cny_effective_date'],
			'profit_margin_percent'     => $globals['default_markup']['profit_percent'],
			'price_rounding_digits'     => $globals['price_rounding']['rounding_digits'],
			'price_rounding_mode'       => $globals['price_rounding']['rounding_mode'],
			'air_express_price_per_kg'  => $globals['shipping']['price_per_kg'],
			'air_express_currency'      => $globals['shipping']['currency'],
			'shipping_catalog_revision' => $globals['shipping']['catalog_revision'],
		);
	}

	/**
	 * Read the canonical air_express pricing dependency from the catalog.
	 *
	 * @param array $catalog Integration catalog.
	 * @return array|WP_Error
	 */
	private function installed_air_express_shipping( $catalog ) {
		$revision = isset( $catalog['revision'] ) && is_string( $catalog['revision'] )
			? $catalog['revision']
			: '';
		if ( ! $this->is_revision( $revision ) ) {
			return $this->error(
				'digitalogic_pricing_shipping_catalog_invalid',
				'Shipping catalog revision is missing or invalid.',
				500
			);
		}
		$method = null;
		foreach ( (array) ( $catalog['shipping_methods'] ?? array() ) as $candidate ) {
			if ( is_array( $candidate ) && 'air_express' === (string) ( $candidate['id'] ?? '' ) ) {
				$method = $candidate;
				break;
			}
		}
		if ( ! is_array( $method ) || empty( $method['enabled'] ) ) {
			return $this->error(
				'digitalogic_pricing_air_express_required',
				'The enabled air_express shipping method is required for canonical pricing.',
				409
			);
		}
		$price = $this->canonical_shipping_price( $method['price_per_kg'] ?? null );
		if ( is_wp_error( $price ) ) {
			return $price;
		}
		$currency = $method['currency'] ?? null;
		if ( ! is_string( $currency ) || ! in_array( $currency, array( 'CNY', 'IRR' ), true ) ) {
			return $this->error(
				'digitalogic_pricing_shipping_currency_invalid',
				'air_express currency must be CNY or IRR.',
				500
			);
		}

		return array(
			'method_id'        => 'air_express',
			'price_per_kg'     => $price,
			'currency'         => $currency,
			'catalog_revision' => $revision,
		);
	}

	/**
	 * Build the exact globals projection expected after one settings write.
	 *
	 * @param array $settings Canonical settings.
	 * @return array
	 */
	private function globals_from_settings( $settings ) {
		$shipping = $this->shipping_projection_from_settings( $settings );
		if ( is_wp_error( $shipping ) ) {
			return $shipping;
		}
		$currency_material = array(
			'dollar_price'       => $settings['dollar_price'],
			'yuan_price'         => $settings['yuan_price'],
			'usd_effective_date' => $settings['usd_effective_date'],
			'cny_effective_date' => $settings['cny_effective_date'],
		);
		$currency_revision = $this->revision(
			array_merge(
				array( 'schema' => self::SETTINGS_SCHEMA . '/currency' ),
				$currency_material
			)
		);
		$markup_identity   = array(
			'schema'         => Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_SCHEMA,
			'configured'     => true,
			'type'           => 'percentage',
			'source'         => 'global_default',
			'profit_percent' => $settings['profit_margin_percent'],
		);
		$markup_revision   = $this->default_markup_revision( $markup_identity );
		$rounding_revision = $this->revision(
			array(
				'rounding_digits' => $settings['price_rounding_digits'],
				'rounding_mode'   => $settings['price_rounding_mode'],
			)
		);

		$usd_age_days                = $this->age_days( $settings['usd_effective_date'] );
		$cny_age_days                = $this->age_days( $settings['cny_effective_date'] );
		$usd_stale                   = null === $usd_age_days || $usd_age_days < 0 || $usd_age_days > self::STALE_AFTER_DAYS;
		$cny_stale                   = null === $cny_age_days || $cny_age_days < 0 || $cny_age_days > self::STALE_AFTER_DAYS;
		$stale_currencies            = array_values(
			array_filter(
				array(
					$usd_stale ? 'USD' : null,
					$cny_stale ? 'CNY' : null,
				)
			)
		);
		$currency_freshness_revision = $this->revision(
			array(
				'schema'           => self::SETTINGS_SCHEMA . '/currency-freshness',
				'stale_currencies' => $stale_currencies,
			)
		);
		$currency                    = array(
			'dollar_price'       => $settings['dollar_price'],
			'yuan_price'         => $settings['yuan_price'],
			'effective_date'     => $settings['cny_effective_date'],
			'usd_effective_date' => $settings['usd_effective_date'],
			'cny_effective_date' => $settings['cny_effective_date'],
			'revision'           => $currency_revision,
			'age_days'           => $cny_age_days,
			'stale'              => $usd_stale || $cny_stale,
			'stale_currencies'   => $stale_currencies,
			'freshness'          => array(
				'usd' => array(
					'effective_date' => $settings['usd_effective_date'],
					'age_days'       => $usd_age_days,
					'stale'          => $usd_stale,
				),
				'cny' => array(
					'effective_date' => $settings['cny_effective_date'],
					'age_days'       => $cny_age_days,
					'stale'          => $cny_stale,
				),
			),
		);

		return array(
			'state_revision'  => $this->revision(
				array(
					'schema'                      => self::SETTINGS_SCHEMA,
					'currency_revision'           => $currency_revision,
					'currency_freshness_revision' => $currency_freshness_revision,
					'default_markup_revision'     => $markup_revision,
					'price_rounding_revision'     => $rounding_revision,
					'shipping_catalog_revision'   => $shipping['catalog_revision'],
				)
			),
			'currency'        => $currency,
			'default_markup'  => array(
				'configured'     => true,
				'profit_percent' => $settings['profit_margin_percent'],
				'revision'       => $markup_revision,
				'updated_at'     => current_time( 'mysql', true ),
			),
			'price_rounding'  => array(
				'configured'      => true,
				'rounding_digits' => $settings['price_rounding_digits'],
				'rounding_mode'   => $settings['price_rounding_mode'],
				'revision'        => $rounding_revision,
				'bounds'          => array(
					'minimum' => 0,
					'maximum' => 9,
				),
				'warnings'        => array(),
			),
			'shipping'        => $shipping,
			'markup_identity' => $markup_identity,
		);
	}

	/**
	 * Resolve the one safe readback difference caused by WordPress option cache.
	 *
	 * Every option has already been written and verified directly against the
	 * transaction's database connection at this point. The shipping integration
	 * catalog, however, uses get_option() and can still expose the pre-transaction
	 * currency rate until commit invalidates that process cache. Accept that
	 * representation only when all independently read currency, markup, and
	 * rounding revisions match the desired state and shipping is exactly the
	 * locked pre-transaction revision. Any other difference remains blocking.
	 *
	 * @param array|WP_Error $readback      Transaction readback.
	 * @param array          $desired       Deterministic desired projection.
	 * @param array          $locked_current Pre-transaction projection.
	 * @return array|WP_Error
	 */
	private function transaction_consistent_readback( $readback, $desired, $locked_current ) {
		if ( is_wp_error( $readback ) || ! is_array( $readback ) ) {
			return $readback;
		}
		if (
			! hash_equals( (string) $desired['currency']['revision'], (string) $readback['currency']['revision'] )
			|| ! hash_equals( (string) $desired['default_markup']['revision'], (string) $readback['default_markup']['revision'] )
			|| ! hash_equals( (string) $desired['price_rounding']['revision'], (string) $readback['price_rounding']['revision'] )
		) {
			return $readback;
		}

		$actual_shipping  = (string) ( $readback['shipping']['catalog_revision'] ?? '' );
		$desired_shipping = (string) ( $desired['shipping']['catalog_revision'] ?? '' );
		$locked_shipping  = (string) ( $locked_current['shipping']['catalog_revision'] ?? '' );
		if ( hash_equals( $desired_shipping, $actual_shipping ) ) {
			return $readback;
		}
		if ( '' === $locked_shipping || ! hash_equals( $locked_shipping, $actual_shipping ) ) {
			return $readback;
		}

		$readback['shipping']       = $desired['shipping'];
		$readback['state_revision'] = $desired['state_revision'];
		return $readback;
	}

	/**
	 * Project the desired composite shipping catalog without writing it.
	 *
	 * @param array $settings Canonical composite settings.
	 * @return array|WP_Error
	 */
	private function shipping_projection_from_settings( $settings ) {
		$catalog = Digitalogic_Shipping_Method_Service::instance()->get_integration_catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		$current = $this->installed_air_express_shipping( $catalog );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! hash_equals( $current['catalog_revision'], $settings['shipping_catalog_revision'] ) ) {
			return $this->error(
				'digitalogic_pricing_shipping_revision_conflict',
				'Shipping catalog changed after it was read; refresh state before applying.',
				409,
				array( 'current_shipping_catalog_revision' => $current['catalog_revision'] )
			);
		}

		$methods = array_values( (array) ( $catalog['shipping_methods'] ?? array() ) );
		$found   = false;
		foreach ( $methods as &$method ) {
			if ( ! is_array( $method ) || 'air_express' !== (string) ( $method['id'] ?? '' ) ) {
				continue;
			}
			$method['price_per_kg'] = $settings['air_express_price_per_kg'];
			$method['currency']     = $settings['air_express_currency'];
			$found                  = true;
			break;
		}
		unset( $method );
		if ( ! $found ) {
			return $this->error(
				'digitalogic_pricing_air_express_required',
				'air_express is missing from the shipping catalog.',
				409
			);
		}

		$currency                 = is_array( $catalog['currency'] ?? null ) ? $catalog['currency'] : array();
		$currency['warnings']     = array_values(
			array_diff( (array) ( $currency['warnings'] ?? array() ), array( 'cny_to_local_missing_or_invalid' ) )
		);
		$currency['cny_to_local'] = $settings['yuan_price'];
		if ( 'IRT' === (string) ( $currency['local'] ?? '' ) ) {
			$currency['cny_to_irt'] = $settings['yuan_price'];
		} else {
			unset( $currency['cny_to_irt'] );
		}
		$currency['effective_date'] = $settings['cny_effective_date'];
		$pricing                    = is_array( $catalog['pricing'] ?? null ) ? $catalog['pricing'] : array();
		$pricing['rounding_digits'] = $settings['price_rounding_digits'];
		$pricing['rounding_mode']   = $settings['price_rounding_mode'];

		$identity = array(
			'schema'              => (string) ( $catalog['schema'] ?? Digitalogic_Shipping_Method_Service::CATALOG_SCHEMA ),
			'currency'            => $currency,
			'pricing'             => $pricing,
			'selected_warehouses' => is_array( $catalog['selected_warehouses'] ?? null )
				? $catalog['selected_warehouses']
				: array(),
			'shipping_methods'    => $methods,
		);

		return array(
			'method_id'        => 'air_express',
			'price_per_kg'     => $settings['air_express_price_per_kg'],
			'currency'         => $settings['air_express_currency'],
			'catalog_revision' => 'sha256:' . hash(
				'sha256',
				wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			),
		);
	}

	/**
	 * Build warning objects for current-versus-proposed settings.
	 *
	 * @param array $current  Current globals.
	 * @param array $settings Proposed settings.
	 * @return array
	 */
	private function comparison_warnings( $current, $settings ) {
		$warnings = array();
		if ( ! empty( $current['currency']['stale'] ) ) {
			$warnings[] = $this->warning(
				'current_currency_stale',
				'نرخ ارز فعلی سایت بیش از ۷ روز قدمت دارد یا تاریخ آن معتبر نیست.',
				'critical',
				array(
					'age_days'         => $current['currency']['age_days'],
					'stale_currencies' => $current['currency']['stale_currencies'],
				)
			);
		}

		$proposed_stale = array();
		foreach (
			array(
				'USD' => $settings['usd_effective_date'],
				'CNY' => $settings['cny_effective_date'],
			) as $currency_code => $effective_date
		) {
			$proposed_age = $this->age_days( $effective_date );
			if ( null === $proposed_age || $proposed_age < 0 || $proposed_age > self::STALE_AFTER_DAYS ) {
				$proposed_stale[] = $currency_code;
			}
		}
		if ( $proposed_stale ) {
			$warnings[] = $this->warning(
				'proposed_currency_stale',
				'تاریخ مؤثر نرخ پیشنهادی خارج از بازهٔ ۷ روزه است.',
				'critical',
				array( 'stale_currencies' => $proposed_stale )
			);
		}

		foreach (
			array(
				'dollar_price' => 'نرخ دلار',
				'yuan_price'   => 'نرخ یوآن',
			) as $field => $label
		) {
			$current_value = $current['currency'][ $field ];
			$proposed      = $settings[ $field ];
			if ( null === $current_value || $current_value !== $proposed ) {
				$drift      = null === $current_value ? null : $this->drift_percent( $current_value, $proposed );
				$warnings[] = $this->warning(
					null !== $drift && $drift > self::DRIFT_PERCENT
						? 'currency_drift_over_7_percent'
						: 'currency_value_changed',
					null !== $drift && $drift > self::DRIFT_PERCENT
						? $label . ' بیش از ۷٪ با سایت اختلاف دارد.'
						: $label . ' با مقدار فعلی سایت یکسان نیست.',
					null !== $drift && $drift > self::DRIFT_PERCENT ? 'critical' : 'warning',
					array(
						'field'             => $field,
						'current'           => $current_value,
						'proposed'          => $proposed,
						'drift_percent'     => $drift,
						'threshold_percent' => self::DRIFT_PERCENT,
					)
				);
			}
		}

		$current_profit  = $current['default_markup']['profit_percent'];
		$proposed_profit = $settings['profit_margin_percent'];
		if ( null === $current_profit || $current_profit !== $proposed_profit ) {
			$drift      = null === $current_profit
				? null
				: $this->drift_percent( (float) $current_profit, (float) $proposed_profit );
			$warnings[] = $this->warning(
				null !== $drift && $drift > self::DRIFT_PERCENT
					? 'profit_drift_over_7_percent'
					: 'profit_margin_changed',
				null === $current_profit
					? 'حاشیه سود مشترک در سایت تنظیم نشده است.'
					: ( null !== $drift && $drift > self::DRIFT_PERCENT
						? 'حاشیه سود پیشنهادی بیش از ۷٪ با سایت اختلاف دارد.'
						: 'حاشیه سود پیشنهادی با سایت یکسان نیست.' ),
				null === $current_profit || ( null !== $drift && $drift > self::DRIFT_PERCENT )
					? 'critical'
					: 'warning',
				array(
					'field'           => 'profit_margin_percent',
					'current'         => $current_profit,
					'proposed'        => $proposed_profit,
					'drift_percent'   => $drift,
					'deprecated_code' => 'default_profit_changed',
				)
			);
		}
		if (
			$current['price_rounding']['rounding_digits'] !== $settings['price_rounding_digits']
			|| $current['price_rounding']['rounding_mode'] !== $settings['price_rounding_mode']
		) {
			$warnings[] = $this->warning(
				'price_rounding_changed',
				'The final-price rounding policy differs from the current site setting.',
				'warning',
				array(
					'field'           => 'price_rounding_digits',
					'current_digits'  => $current['price_rounding']['rounding_digits'],
					'proposed_digits' => $settings['price_rounding_digits'],
					'current_mode'    => $current['price_rounding']['rounding_mode'],
					'proposed_mode'   => $settings['price_rounding_mode'],
				)
			);
		}

		$current_shipping  = $current['shipping'];
		$proposed_shipping = array(
			'price_per_kg' => $settings['air_express_price_per_kg'],
			'currency'     => $settings['air_express_currency'],
		);
		if ( $current_shipping['currency'] !== $proposed_shipping['currency'] ) {
			$warnings[] = $this->warning(
				'shipping_currency_changed',
				'واحد پول نرخ حمل هوایی سریع با مقدار فعلی سایت یکسان نیست.',
				'critical',
				array(
					'field'    => 'air_express_currency',
					'current'  => $current_shipping['currency'],
					'proposed' => $proposed_shipping['currency'],
				)
			);
		}
		if ( $current_shipping['price_per_kg'] !== $proposed_shipping['price_per_kg'] ) {
			$shipping_drift = $this->drift_percent(
				(float) $current_shipping['price_per_kg'],
				(float) $proposed_shipping['price_per_kg']
			);
			$warnings[]     = $this->warning(
				null !== $shipping_drift && $shipping_drift > self::DRIFT_PERCENT
					? 'shipping_drift_over_7_percent'
					: 'shipping_price_changed',
				null !== $shipping_drift && $shipping_drift > self::DRIFT_PERCENT
					? 'نرخ حمل هوایی سریع بیش از ۷٪ با سایت اختلاف دارد.'
					: 'نرخ حمل هوایی سریع با مقدار فعلی سایت یکسان نیست.',
				null !== $shipping_drift && $shipping_drift > self::DRIFT_PERCENT ? 'critical' : 'warning',
				array(
					'field'             => 'air_express_price_per_kg',
					'current'           => $current_shipping['price_per_kg'],
					'proposed'          => $proposed_shipping['price_per_kg'],
					'drift_percent'     => $shipping_drift,
					'threshold_percent' => self::DRIFT_PERCENT,
				)
			);
		}

		if ( $current['currency']['effective_date'] !== $settings['effective_date'] ) {
			$warnings[] = $this->warning(
				'effective_date_changed',
				'تاریخ مؤثر نرخ‌ها تغییر می‌کند.',
				'warning',
				array(
					'current'  => $current['currency']['effective_date'],
					'proposed' => $settings['effective_date'],
				)
			);
		}
		if ( $current['currency']['usd_effective_date'] !== $settings['usd_effective_date'] ) {
			$warnings[] = $this->warning(
				'usd_effective_date_changed',
				'تاریخ مؤثر نرخ دلار تغییر می‌کند.',
				'warning',
				array(
					'current'  => $current['currency']['usd_effective_date'],
					'proposed' => $settings['usd_effective_date'],
				)
			);
		}

		return $warnings;
	}

	/**
	 * Return the desired option rows for an atomic write.
	 *
	 * @param array $source   Exact source.
	 * @param array $settings Canonical settings.
	 * @param array $current  Current globals.
	 * @param array $desired  Desired globals.
	 * @return array|WP_Error
	 */
	private function desired_option_values( $source, $settings, $current, $desired ) {
		$shipping_methods = $this->desired_shipping_methods_option( $settings );
		if ( is_wp_error( $shipping_methods ) ) {
			return $shipping_methods;
		}
		$legacy_date = substr( $settings['cny_effective_date'], 2, 2 )
			. substr( $settings['cny_effective_date'], 5, 2 )
			. substr( $settings['cny_effective_date'], 8, 2 );
		$markup      = array_merge(
			$desired['markup_identity'],
			array(
				'revision'   => $desired['default_markup']['revision'],
				'updated_at' => $desired['default_markup']['updated_at'],
				'updated_by' => function_exists( 'get_current_user_id' ) ? absint( get_current_user_id() ) : 0,
			)
		);
		$previous    = $this->read_option_db( self::SETTINGS_OPTION, true );
		$generation  = is_array( $previous['value'] ?? null )
			? absint( $previous['value']['generation'] ?? 0 ) + 1
			: 1;
		$provenance  = $current['currency']['rate_provenance'];
		$recorded_at = $this->now_iso8601();
		foreach (
			array(
				'usd' => array( 'dollar_price', 'usd_effective_date' ),
				'cny' => array( 'yuan_price', 'cny_effective_date' ),
			) as $currency_code => $fields
		) {
			list( $rate_field, $date_field ) = $fields;
			if (
				$current['currency'][ $rate_field ] !== $settings[ $rate_field ]
				|| $current['currency'][ $date_field ] !== $settings[ $date_field ]
			) {
				$provenance[ $currency_code ] = array(
					'source'      => $source,
					'channel'     => isset( $source['dataset'] ) ? sanitize_key( $source['dataset'] ) : 'pricing_settings',
					'recorded_at' => $recorded_at,
					'date_basis'  => 'submitted',
				);
			}
		}
		$metadata = array(
			'schema'                    => self::SETTINGS_SCHEMA,
			'generation'                => $generation,
			'revision'                  => $desired['state_revision'],
			'currency_revision'         => $desired['currency']['revision'],
			'default_markup_revision'   => $desired['default_markup']['revision'],
			'price_rounding_revision'   => $desired['price_rounding']['revision'],
			'shipping_catalog_revision' => $desired['shipping']['catalog_revision'],
			'dollar_price'              => $settings['dollar_price'],
			'yuan_price'                => $settings['yuan_price'],
			'effective_date'            => $settings['cny_effective_date'],
			'usd_effective_date'        => $settings['usd_effective_date'],
			'cny_effective_date'        => $settings['cny_effective_date'],
			'rate_provenance'           => $provenance,
			'profit_margin_percent'     => $settings['profit_margin_percent'],
			'price_rounding_digits'     => $settings['price_rounding_digits'],
			'price_rounding_mode'       => $settings['price_rounding_mode'],
			'air_express_price_per_kg'  => $settings['air_express_price_per_kg'],
			'air_express_currency'      => $settings['air_express_currency'],
			'source'                    => $source,
			'updated_at'                => current_time( 'mysql', true ),
			'previous_revision'         => $current['state_revision'],
		);

		return array(
			'dollar_price'         => (string) $settings['dollar_price'],
			'options_dollar_price' => (string) $settings['dollar_price'],
			'yuan_price'           => (string) $settings['yuan_price'],
			'options_yuan_price'   => (string) $settings['yuan_price'],
			'update_date'          => $legacy_date,
			'options_update_date'  => $legacy_date,
			Digitalogic_Shipping_Method_Service::METHODS_OPTION => $shipping_methods,
			Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION => $markup,
			Digitalogic_Shipping_Method_Service::ROUNDING_DIGITS_OPTION => $settings['price_rounding_digits'],
			self::SETTINGS_OPTION  => $metadata,
		);
	}

	/**
	 * Return the exact shipping-method option with only air_express pricing changed.
	 *
	 * @param array $settings Canonical composite settings.
	 * @return array|WP_Error
	 */
	private function desired_shipping_methods_option( $settings ) {
		$stored  = $this->read_option_db( Digitalogic_Shipping_Method_Service::METHODS_OPTION, true );
		$methods = $stored['exists']
			? $stored['value']
			: Digitalogic_Shipping_Method_Service::instance()->list_methods( true );
		if ( ! is_array( $methods ) ) {
			return $this->error(
				'digitalogic_pricing_shipping_storage_invalid',
				'Shipping-method storage is invalid.',
				500
			);
		}

		if ( array_is_list( $methods ) ) {
			$keyed = array();
			foreach ( $methods as $method ) {
				if ( ! is_array( $method ) || ! isset( $method['id'] ) || ! is_string( $method['id'] ) ) {
					continue;
				}
				unset( $method['assigned_products'], $method['changed'], $method['delivery_warnings'] );
				$keyed[ $method['id'] ] = $method;
			}
			$methods = $keyed;
		}

		if (
			! isset( $methods['air_express'] )
			|| ! is_array( $methods['air_express'] )
			|| 'air_express' !== (string) ( $methods['air_express']['id'] ?? '' )
			|| empty( $methods['air_express']['enabled'] )
		) {
			return $this->error(
				'digitalogic_pricing_air_express_required',
				'The enabled air_express shipping method is required for canonical pricing.',
				409
			);
		}

		$methods['air_express']['price_per_kg'] = $settings['air_express_price_per_kg'];
		$methods['air_express']['currency']     = $settings['air_express_currency'];
		ksort( $methods, SORT_STRING );

		return $methods;
	}

	/**
	 * Append a bounded nonsecret audit entry inside the write transaction.
	 *
	 * @param array  $source          Exact source.
	 * @param array  $source_context   Submitted/current source revisions.
	 * @param string $idempotency_key Apply idempotency key.
	 * @param string $preview_digest  Preview digest.
	 * @param array  $before          Previous globals.
	 * @param array  $after           Desired globals.
	 * @param array  $settings        Applied settings.
	 * @param array  $request_context Nonsecret client trace context.
	 * @return true|WP_Error
	 */
	private function append_audit_entry( $source, $source_context, $idempotency_key, $preview_digest, $before, $after, $settings, $request_context = array() ) {
		$request_context = wp_parse_args(
			$request_context,
			array(
				'client_id'  => 'digitalogic-wp',
				'channel'    => 'internal',
				'request_id' => $idempotency_key,
			)
		);
		$row             = $this->read_option_db( self::AUDIT_OPTION, true );
		$entries         = $row['exists'] && is_array( $row['value'] ) ? array_values( $row['value'] ) : array();
		$entries[]       = array(
			'applied_at'              => current_time( 'mysql', true ),
			'source'                  => $source,
			'source_revision_context' => $source_context,
			'idempotency_key'         => $idempotency_key,
			'client_id'               => $request_context['client_id'],
			'channel'                 => $request_context['channel'],
			'request_id'              => $request_context['request_id'],
			'preview_digest'          => $preview_digest,
			'previous_revision'       => $before['state_revision'],
			'state_revision'          => $after['state_revision'],
			'settings'                => $settings,
		);
		$entries         = array_slice( $entries, -self::MAX_AUDIT_ENTRIES );

		$stored = $this->store_option_verified( self::AUDIT_OPTION, $entries );
		return is_wp_error( $stored ) ? $stored : true;
	}

	/**
	 * Read and validate one unexpired preview.
	 *
	 * @param string $digest Preview digest.
	 * @return array|WP_Error
	 */
	private function read_preview( $digest ) {
		$preview = get_transient( $this->preview_key( $digest ) );
		if (
			! is_array( $preview )
			|| ! isset( $preview['preview_digest'], $preview['expires_at'], $preview['source'], $preview['settings'], $preview['expected_state_revision'] )
			|| ! is_string( $preview['preview_digest'] )
			|| ! hash_equals( $digest, $preview['preview_digest'] )
			|| (int) $preview['expires_at'] < time()
		) {
			return $this->error(
				'digitalogic_excel_sync_preview_expired',
				'پیش‌نمایش وجود ندارد یا منقضی شده است؛ دوباره Preview بگیرید.',
				409
			);
		}

		return $preview;
	}

	/**
	 * Resolve the explicit apply confirmation, including one narrow alias.
	 *
	 * @param array $payload Request payload.
	 * @return string|WP_Error
	 */
	private function confirmation_value( $payload ) {
		$canonical = $payload['confirmation'] ?? null;
		$alias     = $payload['confirm'] ?? null;
		if ( null !== $canonical && null !== $alias && $canonical !== $alias ) {
			return $this->error(
				'digitalogic_excel_sync_confirmation_conflict',
				'مقادیر confirmation و confirm با هم تعارض دارند.',
				400
			);
		}
		$value = null !== $canonical ? $canonical : $alias;

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Claim an operation idempotency key.
	 *
	 * @param string $mode         preview or apply.
	 * @param string $key          Client key.
	 * @param string $request_hash Exact normalized request hash.
	 * @param int    $ttl          Retention seconds.
	 * @return array|WP_Error
	 */
	private function claim_idempotency( $mode, $key, $request_hash, $ttl ) {
		$transient = $this->idempotency_key( $mode, $key );
		$existing  = get_transient( $transient );
		if ( is_array( $existing ) ) {
			if ( ! isset( $existing['request_hash'] ) || ! is_string( $existing['request_hash'] ) || ! hash_equals( $existing['request_hash'], $request_hash ) ) {
				return $this->error(
					'digitalogic_excel_sync_idempotency_reused',
					'این Idempotency-Key قبلاً برای درخواست دیگری استفاده شده است.',
					409
				);
			}
			if ( 'complete' === ( $existing['status'] ?? '' ) && isset( $existing['response'] ) && is_array( $existing['response'] ) ) {
				return array( 'response' => $existing['response'] );
			}

			return $this->error(
				'digitalogic_excel_sync_idempotency_in_progress',
				'درخواستی با همین Idempotency-Key در حال اجرا است.',
				409,
				array( 'retryable' => true )
			);
		}

		$claimed = set_transient(
			$transient,
			array(
				'status'       => 'in_progress',
				'request_hash' => $request_hash,
				'started_at'   => time(),
			),
			$ttl
		);
		if ( ! $claimed ) {
			return $this->error(
				'digitalogic_excel_sync_idempotency_unavailable',
				'ثبت وضعیت تکرارپذیری درخواست ممکن نیست.',
				503,
				array( 'retryable' => true )
			);
		}

		return array( 'claimed' => true );
	}

	/**
	 * Store the completed idempotent response.
	 *
	 * @param string $mode         preview or apply.
	 * @param string $key          Client key.
	 * @param string $request_hash Exact request hash.
	 * @param array  $response     Completed response.
	 * @param int    $ttl          Retention seconds.
	 * @return true|WP_Error
	 */
	private function complete_idempotency( $mode, $key, $request_hash, $response, $ttl ) {
		$stored = set_transient(
			$this->idempotency_key( $mode, $key ),
			array(
				'status'       => 'complete',
				'request_hash' => $request_hash,
				'response'     => $response,
				'completed_at' => time(),
			),
			$ttl
		);

		return $stored
			? true
			: $this->error(
				'digitalogic_excel_sync_idempotency_store_failed',
				'ثبت نتیجهٔ تکرارپذیر درخواست ممکن نیست.',
				503,
				array( 'retryable' => true )
			);
	}

	/**
	 * Release a failed idempotency claim.
	 *
	 * @param string $mode preview or apply.
	 * @param string $key  Client key.
	 * @return void
	 */
	private function release_idempotency( $mode, $key ) {
		delete_transient( $this->idempotency_key( $mode, $key ) );
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory locks, one SQL transaction, and authoritative readback must use the same database connection and bypass object caches.

	/**
	 * Run one callback under a site-scoped advisory lock.
	 *
	 * @param callable $callback Callback.
	 * @return mixed|WP_Error
	 */
	private function with_lock( $callback ) {
		$acquired = $this->acquire_lock();
		if ( is_wp_error( $acquired ) ) {
			return $acquired;
		}

		try {
			return call_user_func( $callback );
		} catch ( Throwable $exception ) {
			if ( $this->transaction_active ) {
				$rollback = $this->rollback_transaction();
				if ( is_wp_error( $rollback ) ) {
					return $rollback;
				}
			}

			return $this->error(
				'digitalogic_excel_sync_unexpected_failure',
				'همگام‌سازی به‌دلیل خطای غیرمنتظره انجام نشد.',
				500,
				array( 'exception' => get_class( $exception ) )
			);
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquire the advisory lock.
	 *
	 * @return true|WP_Error
	 */
	private function acquire_lock() {
		if ( $this->lock_depth > 0 ) {
			++$this->lock_depth;
			return true;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return $this->error(
				'digitalogic_excel_sync_lock_unavailable',
				'سرویس قفل پایگاه‌داده در دسترس نیست.',
				503
			);
		}
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$name   = self::coordination_lock_name( $prefix );
		$locked = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT GET_LOCK(%s, %d)',
				$name,
				self::LOCK_TIMEOUT_SECONDS
			)
		);
		if ( '1' !== (string) $locked ) {
			return $this->error(
				'digitalogic_excel_sync_busy',
				'همگام‌سازی دیگری در حال اجرا است؛ دوباره تلاش کنید.',
				503,
				array( 'retryable' => true )
			);
		}

		$this->lock_depth = 1;
		return true;
	}

	/**
	 * Release the advisory lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		if ( $this->lock_depth <= 0 ) {
			return;
		}
		--$this->lock_depth;
		if ( $this->lock_depth > 0 ) {
			return;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return;
		}
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$name   = self::coordination_lock_name( $prefix );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Return the bounded MySQL advisory-lock identity for one table prefix.
	 *
	 * @param string $table_prefix WordPress table prefix.
	 * @return string
	 */
	public static function coordination_lock_name( $table_prefix ) {
		return substr( self::LOCK_NAME . '_' . md5( (string) $table_prefix ), 0, 64 );
	}

	/**
	 * Whether any connection currently owns the canonical pricing lock.
	 *
	 * @return bool
	 */
	public static function coordination_lock_is_held() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		$name   = self::coordination_lock_name( $prefix );
		$owner  = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) );

		return null !== $owner && (int) $owner > 0;
	}

	/**
	 * Keep the receiver lock held through this transaction's terminal query.
	 *
	 * @param callable      $callback         Transaction callback.
	 * @param callable|null $pre_commit_guard Optional final transaction guard.
	 * @return mixed|WP_Error
	 */
	private function run_coordinated_pricing_transaction( $callback, $pre_commit_guard = null ) {
		return Digitalogic_Pricing_Coordinator::instance()->with_repricing_lock(
			function () use ( $callback, $pre_commit_guard ) {
				return $this->run_transaction( $callback, $pre_commit_guard, true );
			}
		);
	}

	/**
	 * Run an atomic option transaction.
	 *
	 * @param callable      $callback         Transaction callback.
	 * @param callable|null $pre_commit_guard Optional final safety check.
	 * @param bool          $marker_owned_events Suppress raw option hooks in favor of one canonical effect.
	 * @return mixed|WP_Error
	 */
	private function run_transaction( $callback, $pre_commit_guard = null, $marker_owned_events = false ) {
		global $wpdb;
		if (
			$this->transaction_active
			|| ! is_object( $wpdb )
			|| ! method_exists( $wpdb, 'query' )
			|| false === $wpdb->query( 'START TRANSACTION' )
		) {
			return $this->error(
				'digitalogic_excel_sync_transaction_unavailable',
				'شروع تراکنش تنظیمات ممکن نیست.',
				503
			);
		}

		$this->transaction_active        = true;
		$this->transaction_option_names  = array();
		$this->transaction_option_events = array();

		try {
			$result = call_user_func( $callback );
		} catch ( Throwable $exception ) {
			$result = $this->error(
				'digitalogic_excel_sync_transaction_exception',
				'تراکنش تنظیمات بازگردانده شد.',
				500,
				array( 'exception' => get_class( $exception ) )
			);
		}

		if ( is_wp_error( $result ) ) {
			$rollback = $this->rollback_transaction();
			return is_wp_error( $rollback ) ? $rollback : $result;
		}
		if (
			$marker_owned_events
			&& is_array( $result )
			&& is_array( $result['publication'] ?? null )
		) {
			$result['publication']['option_cache_names'] = array_keys( $this->transaction_option_names );
		}
		if ( null !== $pre_commit_guard ) {
			try {
				$guarded = call_user_func( $pre_commit_guard, $result );
			} catch ( Throwable $exception ) {
				$guarded = $this->error(
					'digitalogic_pricing_actuation_guard_exception',
					'کنترل نهایی ایمنی قیمت اجرا نشد.',
					500,
					array( 'exception' => get_class( $exception ) )
				);
			}
			if ( true !== $guarded ) {
				if ( ! is_wp_error( $guarded ) ) {
					$guarded = $this->error(
						'digitalogic_pricing_actuation_guard_rejected',
						'مالکیت ایمن کار قیمت پیش از ثبت نهایی از دست رفت.',
						409
					);
				}
				$rollback = $this->rollback_transaction();

				return is_wp_error( $rollback ) ? $rollback : $guarded;
			}
		}
		$commit_exception = null;
		try {
			$commit = $wpdb->query( 'COMMIT' );
		} catch ( Throwable $exception ) {
			$commit           = false;
			$commit_exception = $exception;
		}
		if ( false === $commit ) {
			$rollback  = $this->rollback_transaction();
			$ambiguous = $commit_exception instanceof Throwable;
			return is_wp_error( $rollback )
				? $rollback
				: $this->error(
					$ambiguous ? 'digitalogic_excel_sync_commit_ambiguous' : 'digitalogic_excel_sync_commit_failed',
					$ambiguous
						? 'پاسخ ثبت نهایی تراکنش نامشخص بود؛ نتیجه از نشانگر اتمیک بازیابی می‌شود.'
						: 'ثبت نهایی تراکنش تنظیمات ممکن نیست.',
					500,
					array(
						'exception' => $commit_exception instanceof Throwable ? get_class( $commit_exception ) : '',
					)
				);
		}

		$names                           = $this->transaction_option_names;
		$events                          = $this->transaction_option_events;
		$this->transaction_active        = false;
		$this->transaction_option_names  = array();
		$this->transaction_option_events = array();
		$this->invalidate_option_caches( $names );
		if ( ! $marker_owned_events ) {
			$this->dispatch_option_events( $events );
		}

		return $result;
	}

	/**
	 * Roll back the active transaction.
	 *
	 * @return true|WP_Error
	 */
	private function rollback_transaction() {
		global $wpdb;
		$names  = $this->transaction_option_names;
		$failed = $this->transaction_active
			&& ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || false === $wpdb->query( 'ROLLBACK' ) );

		$this->transaction_active        = false;
		$this->transaction_option_names  = array();
		$this->transaction_option_events = array();
		$this->invalidate_option_caches( $names );

		return $failed
			? $this->error(
				'digitalogic_excel_sync_rollback_failed',
				'بازگردانی تراکنش تنظیمات ممکن نیست.',
				500
			)
			: true;
	}

	/**
	 * Read an option directly from the database.
	 *
	 * @param string $name       Option name.
	 * @param bool   $for_update Lock row in active transaction.
	 * @return array
	 */
	private function read_option_db( $name, $for_update = false ) {
		global $wpdb;
		$table = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$sql   = "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1";
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The table comes from $wpdb and the option name remains placeholder-bound.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $name ), ARRAY_A );

		return is_array( $row )
			? array(
				'exists' => true,
				'value'  => maybe_unserialize( $row['option_value'] ),
				'raw'    => (string) $row['option_value'],
			)
			: array(
				'exists' => false,
				'value'  => null,
				'raw'    => null,
			);
	}

	/**
	 * Store and verify one option inside the transaction.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Desired value.
	 * @return array|WP_Error
	 */
	private function store_option_verified( $name, $value ) {
		if ( ! $this->transaction_active ) {
			return $this->error(
				'digitalogic_excel_sync_transaction_required',
				'نوشتن تنظیمات به تراکنش فعال نیاز دارد.',
				500
			);
		}

		global $wpdb;
		$table = isset( $wpdb->options ) ? $wpdb->options : $wpdb->prefix . 'options';
		$old   = $this->read_option_db( $name, true );
		$raw   = (string) maybe_serialize( $value );
		if ( $old['exists'] && (string) $old['raw'] === $raw ) {
			return array( 'changed' => false );
		}

		$written = $old['exists']
			? $wpdb->update(
				$table,
				array( 'option_value' => $raw ),
				array( 'option_name' => $name ),
				array( '%s' ),
				array( '%s' )
			)
			: $wpdb->insert(
				$table,
				array(
					'option_name'  => $name,
					'option_value' => $raw,
					'autoload'     => 'no',
				),
				array( '%s', '%s', '%s' )
			);
		if ( false === $written ) {
			return $this->error(
				'digitalogic_excel_sync_option_write_failed',
				'ذخیرهٔ یکی از تنظیمات انجام نشد.',
				500,
				array( 'option' => $name )
			);
		}

		$stored = $this->read_option_db( $name, true );
		if ( ! $stored['exists'] || (string) $stored['raw'] !== $raw ) {
			return $this->error(
				'digitalogic_excel_sync_option_readback_failed',
				'خواندن مجدد یکی از تنظیمات با مقدار نوشته‌شده یکسان نیست.',
				500,
				array( 'option' => $name )
			);
		}

		$this->transaction_option_names[ $name ] = true;
		$this->transaction_option_events[]       = array(
			'name'      => $name,
			'existed'   => $old['exists'],
			'old_value' => $old['value'],
			'new_value' => $value,
		);

		return array( 'changed' => true );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Invalidate exact option caches.
	 *
	 * @param array $names Changed option names or name-keyed map.
	 * @return void
	 */
	private function invalidate_option_caches( $names ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		foreach ( $names as $key => $value ) {
			$name = is_int( $key ) ? (string) $value : (string) $key;
			if ( '' === $name ) {
				continue;
			}
			wp_cache_delete( $name, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Dispatch compatible WordPress option hooks after commit.
	 *
	 * @param array $events Changed option events.
	 * @return void
	 */
	private function dispatch_option_events( $events ) {
		foreach ( $events as $event ) {
			try {
				if ( ! empty( $event['existed'] ) ) {
					do_action(
						'updated_option_' . $event['name'],
						$event['old_value'],
						$event['new_value'],
						$event['name']
					);
					do_action(
						'updated_option',
						$event['name'],
						$event['old_value'],
						$event['new_value']
					);
				} else {
					do_action( 'added_option', $event['name'], $event['new_value'] );
				}
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
	}

	/** Return the one explicitly configured workbook consumer allowed to ACK. */
	private function current_ack_consumer() {
		$scopes = Digitalogic_Patris_Feed::instance()->get_product_sync_source_scopes();
		if ( 1 !== count( $scopes ) ) {
			return $this->error(
				'digitalogic_pricing_confirmation_consumer_ambiguous',
				'Exactly one source-scoped Excel pricing consumer must be configured.',
				409
			);
		}
		$scope = reset( $scopes );

		return array(
			'consumer_id' => self::REQUIRED_CONSUMER_ID,
			'channel'     => self::REQUIRED_CONSUMER_CHANNEL,
			'capability'  => 'pricing_settings_ack',
			'source_id'   => (string) $scope['id'],
			'dataset'     => (string) $scope['dataset'],
		);
	}

	/**
	 * Stage the durable commit/ACK transaction and event before SQL COMMIT.
	 *
	 * @param array  $previous           Previous canonical state.
	 * @param array  $committed          Committed canonical state.
	 * @param array  $previous_settings  Previous canonical settings.
	 * @param array  $committed_settings Committed canonical settings.
	 * @param array  $consumer           Required configured consumer.
	 * @param array  $request_context    Nonsecret mutation context.
	 * @param string $idempotency_key    Optional apply idempotency key.
	 * @param string $request_hash       Optional apply request digest.
	 * @return array|WP_Error
	 */
	private function stage_confirmation_open_transaction(
		$previous,
		$committed,
		$previous_settings,
		$committed_settings,
		$consumer,
		$request_context,
		$idempotency_key = '',
		$request_hash = ''
	) {
		if ( is_wp_error( $consumer ) ) {
			return $consumer;
		}
		if ( ! $this->transaction_active ) {
			return $this->error(
				'digitalogic_pricing_confirmation_transaction_required',
				'Pricing confirmation staging requires the active pricing transaction.',
				500
			);
		}
		$row          = $this->read_option_db( self::CONFIRMATIONS_OPTION, true );
		$ledger       = is_array( $row['value'] ?? null ) ? $row['value'] : array();
		$transactions = is_array( $ledger['transactions'] ?? null ) ? $ledger['transactions'] : array();
		$active_id    = is_string( $ledger['active'] ?? null ) ? $ledger['active'] : '';
		if ( '' !== $active_id && 'awaiting_ack' === ( $transactions[ $active_id ]['status'] ?? '' ) ) {
			$active = $transactions[ $active_id ];
			if (
				hash_equals( (string) $active['committed_revision'], (string) $committed['state_revision'] )
				&& '' !== (string) $idempotency_key
				&& hash_equals( (string) ( $active['apply_idempotency_key'] ?? '' ), (string) $idempotency_key )
			) {
				return $this->confirmation_projection( $active );
			}

			return $this->error(
				'digitalogic_pricing_confirmation_pending',
				'A prior website pricing commit is still awaiting the configured Excel consumer.',
				409,
				array( 'transaction_id' => $active_id )
			);
		}

		$now                             = time();
		$request_context                 = is_array( $request_context ) ? $request_context : array();
		$sequence                        = max( 1, (int) ( $ledger['next_sequence'] ?? 1 ) );
		$seed                            = implode(
			'|',
			array(
				(string) $previous['state_revision'],
				(string) $committed['state_revision'],
				(string) ( $request_context['request_id'] ?? 'internal' ),
				(string) $idempotency_key,
				(string) $sequence,
			)
		);
		$transaction_id                  = 'ptx_' . substr( hash( 'sha256', $seed ), 0, 32 );
		$record                          = array(
			'schema'                    => self::CONFIRMATION_SCHEMA,
			'transaction_id'            => $transaction_id,
			'status'                    => 'awaiting_ack',
			'previous_revision'         => (string) $previous['state_revision'],
			'committed_revision'        => (string) $committed['state_revision'],
			'previous_settings'         => $previous_settings,
			'committed_settings'        => $committed_settings,
			'previous_settings_digest'  => $this->revision( $previous_settings ),
			'committed_settings_digest' => $this->revision( $committed_settings ),
			'consumer'                  => $consumer,
			'origin'                    => array(
				'client_id'  => (string) ( $request_context['client_id'] ?? 'digitalogic-wp' ),
				'channel'    => (string) ( $request_context['channel'] ?? 'internal' ),
				'request_id' => (string) ( $request_context['request_id'] ?? 'internal' ),
			),
			'apply_idempotency_key'     => (string) $idempotency_key,
			'apply_request_hash'        => (string) $request_hash,
			'committed_at'              => $now,
			'ack_deadline'              => $now + self::ACK_TARGET_SECONDS,
			'recovery_deadline'         => $now + self::ACK_RECOVERY_SECONDS,
		);
		$transactions[ $transaction_id ] = $record;
		$transaction_count               = count( $transactions );
		while ( $transaction_count > self::MAX_CONFIRMATIONS ) {
			$oldest = array_key_first( $transactions );
			if ( $oldest === $transaction_id ) {
				break;
			}
			unset( $transactions[ $oldest ] );
			--$transaction_count;
		}
		$ledger = array(
			'schema'        => self::CONFIRMATION_SCHEMA,
			'active'        => $transaction_id,
			'next_sequence' => $sequence + 1,
			'transactions'  => $transactions,
		);
		$stored = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		$event  = $this->confirmation_event( 'committed', $record );
		$staged = $this->stage_confirmation_event_open_transaction( $event );
		if ( is_wp_error( $staged ) ) {
			return $staged;
		}

		return $this->confirmation_projection( $record );
	}

	/**
	 * Store one durable confirmation event in the active transaction.
	 *
	 * @param array $event Nonsecret companion event.
	 * @return array|WP_Error
	 */
	private function stage_confirmation_event_open_transaction( $event ) {
		$row                                   = $this->read_option_db( self::CONFIRMATION_OUTBOX_OPTION, true );
		$outbox                                = is_array( $row['value'] ?? null ) ? $row['value'] : array();
		$outbox[ (string) $event['event_id'] ] = $event;

		return $this->store_option_verified( self::CONFIRMATION_OUTBOX_OPTION, $outbox );
	}

	/**
	 * Build the exact companion event required to apply or restore the workbook.
	 *
	 * @param string $phase  Commit or rollback phase.
	 * @param array  $record Durable confirmation record.
	 * @return array
	 */
	private function confirmation_event( $phase, $record ) {
		$phase                     = 'rolled_back' === $phase ? 'rolled_back' : 'committed';
		$event_id                  = 'sha256:' . hash( 'sha256', $record['transaction_id'] . '|' . $phase );
		$confirmed_settings        = 'rolled_back' === $phase
			? ( $record['restored_settings'] ?? $record['previous_settings'] )
			: $record['committed_settings'];
		$confirmed_settings_digest = 'rolled_back' === $phase
			? ( $record['restored_settings_digest'] ?? $record['previous_settings_digest'] )
			: $record['committed_settings_digest'];

		return array(
			'schema'                    => 'digitalogic.pricing-confirmation-event/v1',
			'event_id'                  => $event_id,
			'event_type'                => 'pricing.settings.' . $phase,
			'transaction_id'            => (string) $record['transaction_id'],
			'previous_revision'         => (string) $record['previous_revision'],
			'committed_revision'        => (string) $record['committed_revision'],
			'current_revision'          => 'rolled_back' === $phase
				? (string) ( $record['restored_revision'] ?? $record['previous_revision'] )
				: (string) $record['committed_revision'],
			'confirmed_settings'        => $confirmed_settings,
			'confirmed_settings_digest' => (string) $confirmed_settings_digest,
			'ack_deadline'              => (int) $record['ack_deadline'],
			'ack_path'                  => '/wp-json/digitalogic/pricing/sync/ack',
			'consumer_id'               => (string) $record['consumer']['consumer_id'],
			'channel'                   => (string) $record['consumer']['channel'],
			'source'                    => array(
				'id'      => (string) $record['consumer']['source_id'],
				'dataset' => (string) $record['consumer']['dataset'],
			),
			'reason'                    => 'rolled_back' === $phase ? 'ack_timeout' : null,
		);
	}

	/** Return bounded confirmation state for APIs and callers. */
	private function current_confirmation_projection() {
		$ledger    = get_option( self::CONFIRMATIONS_OPTION, array() );
		$active_id = is_array( $ledger ) && is_string( $ledger['active'] ?? null ) ? $ledger['active'] : '';
		$record    = '' !== $active_id && is_array( $ledger['transactions'][ $active_id ] ?? null )
			? $ledger['transactions'][ $active_id ]
			: null;

		return is_array( $record )
			? $this->confirmation_projection( $record )
			: array(
				'schema' => self::CONFIRMATION_SCHEMA,
				'status' => 'clear',
			);
	}

	/**
	 * Strip private ledger data from one confirmation record.
	 *
	 * @param array $record Durable confirmation record.
	 * @return array
	 */
	private function confirmation_projection( $record ) {
		return array(
			'schema'                    => self::CONFIRMATION_SCHEMA,
			'status'                    => (string) ( $record['status'] ?? 'clear' ),
			'transaction_id'            => (string) ( $record['transaction_id'] ?? '' ),
			'previous_revision'         => (string) ( $record['previous_revision'] ?? '' ),
			'committed_revision'        => (string) ( $record['committed_revision'] ?? '' ),
			'current_revision'          => (string) ( $record['restored_revision'] ?? $record['committed_revision'] ?? '' ),
			'committed_settings_digest' => (string) ( $record['committed_settings_digest'] ?? '' ),
			'ack_deadline'              => (int) ( $record['ack_deadline'] ?? 0 ),
			'recovery_deadline'         => (int) ( $record['recovery_deadline'] ?? 0 ),
			'ack_path'                  => '/wp-json/digitalogic/pricing/sync/ack',
			'consumer_id'               => (string) ( $record['consumer']['consumer_id'] ?? '' ),
			'channel'                   => (string) ( $record['consumer']['channel'] ?? '' ),
		);
	}

	/**
	 * Atomically close an older Excel rollback lease before a legitimate WP commit.
	 *
	 * This runs inside the same settings transaction. Even if the process dies
	 * before its scheduled hook is cleared, the timeout observes a terminal
	 * superseded record and cannot write the older settings back.
	 *
	 * @param string $source           Current trusted WordPress source.
	 * @param string $state_revision   Exact revision that this transaction will own.
	 * @return string|WP_Error Superseded transaction id, empty string, or error.
	 */
	private function supersede_active_confirmation_open_transaction( $source, $state_revision ) {
		if ( 0 < $this->confirmation_rollback_depth ) {
			return '';
		}
		$row    = $this->read_option_db( self::CONFIRMATIONS_OPTION, true );
		$ledger = is_array( $row['value'] ?? null ) ? $row['value'] : array();
		$id     = (string) ( $ledger['active'] ?? '' );
		if ( 1 !== preg_match( '/\Aptx_[a-f0-9]{32}\z/D', $id ) ) {
			return '';
		}
		$transactions = is_array( $ledger['transactions'] ?? null ) ? $ledger['transactions'] : array();
		$record       = is_array( $transactions[ $id ] ?? null ) ? $transactions[ $id ] : array();
		$status       = (string) ( $record['status'] ?? '' );
		if ( in_array( $status, array( 'acknowledged', 'rolled_back', 'superseded' ), true ) ) {
			$ledger['active'] = null;
			$stored           = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			return $id;
		}
		if ( ! in_array( $status, array( 'awaiting_ack', 'rolling_back', 'rollback_pending', 'recovery_required' ), true ) ) {
			return '';
		}

		$record['status']                 = 'superseded';
		$record['superseded_at']          = time();
		$record['superseded_by_source']   = sanitize_key( (string) $source );
		$record['superseded_by_revision'] = (string) $state_revision;
		$record['rollback_owner']         = '';
		$record['rollback_lease_until']   = 0;
		$transactions[ $id ]              = $record;
		$ledger['active']                 = null;
		$ledger['transactions']           = $transactions;
		$stored                           = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $id;
	}

	/**
	 * Schedule the exact pending transaction once.
	 *
	 * @param array $confirmation Bounded confirmation projection.
	 * @return bool
	 */
	private function schedule_confirmation_timeout( $confirmation ) {
		$transaction_id = (string) ( $confirmation['transaction_id'] ?? '' );
		$deadline       = (int) ( $confirmation['ack_deadline'] ?? 0 );
		if ( '' === $transaction_id || $deadline <= 0 ) {
			return false;
		}
		$args = array( $transaction_id );
		if ( false !== wp_next_scheduled( self::CONFIRMATION_TIMEOUT_HOOK, $args ) ) {
			return true;
		}

		return true === wp_schedule_single_event(
			max( time() + 1, $deadline ),
			self::CONFIRMATION_TIMEOUT_HOOK,
			$args,
			true
		);
	}

	/** Repair a missing timeout schedule after process/plugin restart. */
	public function recover_pending_confirmation() {
		$confirmation = $this->current_confirmation_projection();
		if ( in_array( (string) ( $confirmation['status'] ?? '' ), array( 'awaiting_ack', 'rolling_back', 'rollback_pending', 'recovery_required' ), true ) ) {
			$this->schedule_confirmation_timeout( $confirmation );
		}
	}

	/**
	 * Roll back one unacknowledged website-first commit under durable CAS.
	 *
	 * @param string $transaction_id Durable transaction identifier.
	 * @return array|WP_Error
	 */
	public function run_confirmation_timeout( $transaction_id ) {
		$transaction_id = is_string( $transaction_id ) ? trim( $transaction_id ) : '';
		if ( 1 !== preg_match( '/\Aptx_[a-f0-9]{32}\z/D', $transaction_id ) ) {
			return $this->error(
				'digitalogic_pricing_confirmation_id_invalid',
				'The pricing confirmation transaction identifier is invalid.',
				400
			);
		}

		$claim = $this->claim_confirmation_rollback( $transaction_id );
		if ( is_wp_error( $claim ) ) {
			return $claim;
		}
		if ( empty( $claim['claimed'] ) ) {
			if ( ! empty( $claim['retry_at'] ) ) {
				$this->reschedule_confirmation_timeout( $transaction_id, (int) $claim['retry_at'] );
			}
			return $claim['confirmation'] ?? $claim;
		}

		$record           = $claim['record'];
		$owner            = (string) $claim['owner'];
		$current_settings = $this->current_canonical_settings();
		if ( is_wp_error( $current_settings ) ) {
			$this->mark_confirmation_rollback_pending( $transaction_id, $owner, $current_settings->get_error_code() );
			$this->reschedule_confirmation_timeout( $transaction_id, time() + 2 );
			return $current_settings;
		}
		$restore_settings                              = $record['previous_settings'];
		$restore_settings['shipping_catalog_revision'] = $current_settings['shipping_catalog_revision'];

		++$this->confirmation_rollback_depth;
		try {
			$restored = $this->apply_internal_settings(
				$restore_settings,
				'excel_ack_timeout_rollback',
				(string) $record['committed_revision']
			);
		} finally {
			--$this->confirmation_rollback_depth;
		}
		if ( is_wp_error( $restored ) ) {
			$this->mark_confirmation_rollback_pending( $transaction_id, $owner, $restored->get_error_code() );
			$this->reschedule_confirmation_timeout( $transaction_id, time() + 2 );
			return $restored;
		}

		$finished = $this->finalize_confirmation_rollback(
			$transaction_id,
			$owner,
			(string) $restored['state_revision'],
			$restored['settings'],
			$restored['pricing_results'] ?? array()
		);
		if ( is_wp_error( $finished ) ) {
			$this->reschedule_confirmation_timeout( $transaction_id, time() + 2 );
			return $finished;
		}

		wp_clear_scheduled_hook( self::CONFIRMATION_TIMEOUT_HOOK, array( $transaction_id ) );
		$this->publish_confirmation_outbox();
		return $finished;
	}

	/**
	 * Claim rollback ownership, or finish a crash-recovered rollback atomically.
	 *
	 * @param string $transaction_id Durable transaction identifier.
	 * @return array|WP_Error
	 */
	private function claim_confirmation_rollback( $transaction_id ) {
		return $this->with_lock(
			function () use ( $transaction_id ) {
				return $this->run_transaction(
					function () use ( $transaction_id ) {
						$row          = $this->read_option_db( self::CONFIRMATIONS_OPTION, true );
						$ledger       = is_array( $row['value'] ?? null ) ? $row['value'] : array();
						$transactions = is_array( $ledger['transactions'] ?? null ) ? $ledger['transactions'] : array();
						$record       = is_array( $transactions[ $transaction_id ] ?? null ) ? $transactions[ $transaction_id ] : null;
						if ( ! is_array( $record ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_not_found',
								'The pricing confirmation transaction does not exist.',
								404
							);
						}
						$status = (string) ( $record['status'] ?? '' );
						if ( in_array( $status, array( 'acknowledged', 'rolled_back', 'superseded' ), true ) ) {
							return array(
								'claimed'      => false,
								'confirmation' => $this->confirmation_projection( $record ),
							);
						}
						$now = time();
						if ( 'awaiting_ack' === $status && $now < (int) $record['ack_deadline'] ) {
							return array(
								'claimed'      => false,
								'retry_at'     => (int) $record['ack_deadline'],
								'confirmation' => $this->confirmation_projection( $record ),
							);
						}
						if (
							'rolling_back' === $status
							&& $now < (int) ( $record['rollback_lease_until'] ?? 0 )
						) {
							return array(
								'claimed'      => false,
								'retry_at'     => (int) $record['rollback_lease_until'],
								'confirmation' => $this->confirmation_projection( $record ),
							);
						}

						$current = $this->read_globals();
						if ( is_wp_error( $current ) ) {
							return $current;
						}
						if ( hash_equals( (string) $record['previous_revision'], $current['state_revision'] ) ) {
							$record['status']                   = 'rolled_back';
							$record['restored_revision']        = $current['state_revision'];
							$record['restored_settings']        = $this->settings_from_globals( $current );
							$record['restored_settings_digest'] = $this->revision( $record['restored_settings'] );
							$record['rolled_back_at']           = $now;
							$transactions[ $transaction_id ]    = $record;
							$ledger['active']                   = null;
							$ledger['transactions']             = $transactions;
							$stored                             = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
							if ( is_wp_error( $stored ) ) {
								return $stored;
							}
							$staged = $this->stage_confirmation_event_open_transaction( $this->confirmation_event( 'rolled_back', $record ) );
							if ( is_wp_error( $staged ) ) {
								return $staged;
							}

							return array(
								'claimed'      => false,
								'confirmation' => $this->confirmation_projection( $record ),
							);
						}
						if ( ! hash_equals( (string) $record['committed_revision'], $current['state_revision'] ) ) {
							$record['status']                = 'recovery_required';
							$record['last_error']            = 'website_state_cas_conflict';
							$transactions[ $transaction_id ] = $record;
							$ledger['transactions']          = $transactions;
							$stored                          = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
							if ( is_wp_error( $stored ) ) {
								return $stored;
							}

							return $this->error(
								'digitalogic_pricing_confirmation_state_conflict',
								'Rollback did not overwrite a website state owned by another revision.',
								409,
								array( 'current_state_revision' => $current['state_revision'] )
							);
						}

						$owner                           = 'rollback_' . substr( hash( 'sha256', $transaction_id . '|' . microtime( true ) . '|' . wp_salt( 'nonce' ) ), 0, 32 );
						$record['status']                = 'rolling_back';
						$record['rollback_owner']        = $owner;
						$record['rollback_started_at']   = $now;
						$record['rollback_lease_until']  = $now + self::ROLLBACK_LEASE_SECONDS;
						$record['rollback_attempts']     = (int) ( $record['rollback_attempts'] ?? 0 ) + 1;
						$transactions[ $transaction_id ] = $record;
						$ledger['transactions']          = $transactions;
						$stored                          = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
						if ( is_wp_error( $stored ) ) {
							return $stored;
						}

						return array(
							'claimed' => true,
							'owner'   => $owner,
							'record'  => $record,
						);
					}
				);
			}
		);
	}

	/**
	 * Finalize the rollback only for the owner that performed the CAS write.
	 *
	 * @param string $transaction_id    Durable transaction identifier.
	 * @param string $owner             Exact rollback lease owner.
	 * @param string $restored_revision Verified website revision after rollback.
	 * @param array  $restored_settings Verified canonical settings after rollback.
	 * @param array  $pricing_results   Terminal repricing result.
	 * @return array|WP_Error
	 */
	private function finalize_confirmation_rollback( $transaction_id, $owner, $restored_revision, $restored_settings, $pricing_results ) {
		return $this->with_lock(
			function () use ( $transaction_id, $owner, $restored_revision, $restored_settings, $pricing_results ) {
				return $this->run_transaction(
					function () use ( $transaction_id, $owner, $restored_revision, $restored_settings, $pricing_results ) {
						$row          = $this->read_option_db( self::CONFIRMATIONS_OPTION, true );
						$ledger       = is_array( $row['value'] ?? null ) ? $row['value'] : array();
						$transactions = is_array( $ledger['transactions'] ?? null ) ? $ledger['transactions'] : array();
						$record       = is_array( $transactions[ $transaction_id ] ?? null ) ? $transactions[ $transaction_id ] : null;
						if ( ! is_array( $record ) || ! hash_equals( (string) ( $record['rollback_owner'] ?? '' ), (string) $owner ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_rollback_owner_conflict',
								'Rollback ownership changed before finalization.',
								409
							);
						}
						$current = $this->read_globals();
						if ( is_wp_error( $current ) ) {
							return $current;
						}
						if ( ! hash_equals( $restored_revision, $current['state_revision'] ) ) {
							return $this->error(
								'digitalogic_pricing_confirmation_rollback_readback_failed',
								'Rollback state readback did not match the completed write.',
								500
							);
						}
						$record['status']                   = 'rolled_back';
						$record['restored_revision']        = $restored_revision;
						$record['restored_settings']        = $restored_settings;
						$record['restored_settings_digest'] = $this->revision( $restored_settings );
						$record['rollback_pricing_results'] = $pricing_results;
						$record['rolled_back_at']           = time();
						$transactions[ $transaction_id ]    = $record;
						$ledger['active']                   = null;
						$ledger['transactions']             = $transactions;
						$stored                             = $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
						if ( is_wp_error( $stored ) ) {
							return $stored;
						}
						$staged = $this->stage_confirmation_event_open_transaction( $this->confirmation_event( 'rolled_back', $record ) );
						if ( is_wp_error( $staged ) ) {
							return $staged;
						}

						return $this->confirmation_projection( $record );
					}
				);
			}
		);
	}

	/**
	 * Persist a failed attempt for bounded restart recovery without releasing CAS.
	 *
	 * @param string $transaction_id Durable transaction identifier.
	 * @param string $owner          Exact rollback lease owner.
	 * @param string $error_code     Bounded terminal error code.
	 * @return array|WP_Error|false
	 */
	private function mark_confirmation_rollback_pending( $transaction_id, $owner, $error_code ) {
		return $this->with_lock(
			function () use ( $transaction_id, $owner, $error_code ) {
				return $this->run_transaction(
					function () use ( $transaction_id, $owner, $error_code ) {
						$row          = $this->read_option_db( self::CONFIRMATIONS_OPTION, true );
						$ledger       = is_array( $row['value'] ?? null ) ? $row['value'] : array();
						$transactions = is_array( $ledger['transactions'] ?? null ) ? $ledger['transactions'] : array();
						$record       = is_array( $transactions[ $transaction_id ] ?? null ) ? $transactions[ $transaction_id ] : null;
						if ( ! is_array( $record ) || ! hash_equals( (string) ( $record['rollback_owner'] ?? '' ), (string) $owner ) ) {
							return false;
						}
						$record['status']                = 'rollback_pending';
						$record['last_error']            = sanitize_key( (string) $error_code );
						$record['last_error_at']         = time();
						$transactions[ $transaction_id ] = $record;
						$ledger['transactions']          = $transactions;

						return $this->store_option_verified( self::CONFIRMATIONS_OPTION, $ledger );
					}
				);
			}
		);
	}

	/**
	 * Replace one timeout schedule with a bounded recovery attempt.
	 *
	 * @param string $transaction_id Durable transaction identifier.
	 * @param int    $timestamp      Next attempt timestamp.
	 * @return bool
	 */
	private function reschedule_confirmation_timeout( $transaction_id, $timestamp ) {
		$args = array( $transaction_id );
		wp_clear_scheduled_hook( self::CONFIRMATION_TIMEOUT_HOOK, $args );

		return true === wp_schedule_single_event(
			max( time() + 1, (int) $timestamp ),
			self::CONFIRMATION_TIMEOUT_HOOK,
			$args,
			true
		);
	}

	/** Deliver staged commit/rollback events once; failures remain durable. */
	public function publish_confirmation_outbox() {
		$outbox = get_option( self::CONFIRMATION_OUTBOX_OPTION, array() );
		if ( ! is_array( $outbox ) || empty( $outbox ) ) {
			return;
		}
		foreach ( $outbox as $event_id => $event ) {
			try {
				do_action( 'digitalogic_pricing_confirmation_event', $event );
			} catch ( Throwable $exception ) {
				unset( $exception );
				break;
			}
			unset( $outbox[ $event_id ] );
			if ( ! update_option( self::CONFIRMATION_OUTBOX_OPTION, $outbox, false ) && get_option( self::CONFIRMATION_OUTBOX_OPTION, null ) !== $outbox ) {
				break;
			}
		}
	}

	/**
	 * Emit nonsecret post-commit audit/domain events.
	 *
	 * @param array  $source            Exact source.
	 * @param string $previous_revision Previous revision.
	 * @param array  $previous          Previous globals.
	 * @param array  $readback          Readback globals.
	 * @param array  $settings          Applied settings.
	 * @param array  $request_context   Nonsecret client trace context.
	 * @return void
	 */
	private function emit_after_apply( $source, $previous_revision, $previous, $readback, $settings, $request_context = array() ) {
		$result = array(
			'schema'            => self::SETTINGS_SCHEMA,
			'source'            => $source,
			'client_id'         => $request_context['client_id'] ?? 'digitalogic-wp',
			'channel'           => $request_context['channel'] ?? 'internal',
			'request_id'        => $request_context['request_id'] ?? 'internal-not-provided',
			'previous_revision' => $previous_revision,
			'state_revision'    => $readback['state_revision'],
			'settings'          => $settings,
		);
		if ( ! empty( $request_context['effect_id'] ) ) {
			$result['effect_id'] = (string) $request_context['effect_id'];
		}
		try {
			do_action( 'digitalogic_excel_pricing_settings_updated', $result );
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
		if ( ! hash_equals( $previous['default_markup']['revision'], $readback['default_markup']['revision'] ) ) {
			try {
				do_action(
					'digitalogic_shipping_default_markup_updated',
					array(
						'configured'     => true,
						'profit_percent' => $settings['profit_margin_percent'],
						'revision'       => $readback['default_markup']['revision'],
						'changed'        => true,
					)
				);
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
		if ( ! hash_equals( $previous['price_rounding']['revision'], $readback['price_rounding']['revision'] ) ) {
			try {
				do_action(
					'digitalogic_price_rounding_updated',
					array_merge(
						$readback['price_rounding'],
						array( 'changed' => true )
					),
					$previous['price_rounding']
				);
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}
		if ( ! hash_equals( $previous['shipping']['catalog_revision'], $readback['shipping']['catalog_revision'] ) ) {
			try {
				$method = Digitalogic_Shipping_Method_Service::instance()->get_method( 'air_express' );
				if ( is_wp_error( $method ) ) {
					$method = array(
						'id'           => 'air_express',
						'name'         => 'Air (Express)',
						'enabled'      => true,
						'price_per_kg' => $readback['shipping']['price_per_kg'],
						'currency'     => $readback['shipping']['currency'],
					);
				}
				$method['revision']   = $readback['shipping']['catalog_revision'];
				$method['changed']    = true;
				$method['client_id']  = $result['client_id'];
				$method['channel']    = $result['channel'];
				$method['request_id'] = $result['request_id'];
				do_action(
					'digitalogic_shipping_method_updated',
					$method
				);
			} catch ( Throwable $exception ) {
				unset( $exception );
			}
		}

		try {
			Digitalogic_Logger::instance()->log(
				'excel_pricing_sync',
				'option',
				null,
				array( 'revision' => $previous_revision ),
				array( 'revision' => $readback['state_revision'] ),
				'Excel pricing settings applied through the scoped Patris companion.'
			);
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Describe the single component that owns each pricing-domain attribute.
	 *
	 * This is an ownership map, not a precedence list. Excel and Google Sheets
	 * are interfaces over the contract and never become persistent authorities.
	 *
	 * @return array
	 */
	private function attribute_owners() {
		return array(
			'currency_rates'       => 'digitalogic_pricing_coordinator',
			'air_express_shipping' => 'digitalogic_pricing_coordinator',
			'profit_margin'        => 'digitalogic_pricing_coordinator',
			'price_rounding'       => 'digitalogic_pricing_coordinator',
			'product_inputs'       => 'patris_kala',
			'woocommerce_record'   => 'woocommerce',
			'selling_price'        => 'digitalogic_pricing_coordinator',
		);
	}

	/**
	 * Read a currency option using the same ACF precedence as the installed
	 * options service, with a compatibility fallback for partially migrated
	 * installations.
	 *
	 * @param string $acf_name   ACF-prefixed option name.
	 * @param string $plain_name Direct option name.
	 * @param bool   $for_update Lock rows in the active transaction.
	 * @return array
	 */
	private function authoritative_currency_option( $acf_name, $plain_name, $for_update ) {
		$primary_name  = function_exists( 'get_field' ) ? $acf_name : $plain_name;
		$fallback_name = $primary_name === $acf_name ? $plain_name : $acf_name;
		$row           = $this->read_option_db( $primary_name, $for_update );

		return $row['exists'] ? $row : $this->read_option_db( $fallback_name, $for_update );
	}

	/**
	 * Convert an installed float-compatible rate to a safe integer.
	 *
	 * @param mixed $value Installed value.
	 * @return int|null
	 */
	private function installed_rate( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < 1 || $number > self::MAX_RATE || floor( $number ) !== $number ) {
			return null;
		}

		return (int) $number;
	}

	/**
	 * Return bounded per-currency provenance from settings metadata.
	 *
	 * @param array  $metadata       Installed settings metadata.
	 * @param string $fallback_basis Basis used by legacy installations.
	 * @return array
	 */
	private function installed_rate_provenance( $metadata, $fallback_basis ) {
		$default = array(
			'source'      => null,
			'channel'     => 'legacy',
			'recorded_at' => null,
			'date_basis'  => $fallback_basis,
		);
		$result  = array(
			'usd' => $default,
			'cny' => $default,
		);
		$stored  = isset( $metadata['rate_provenance'] ) && is_array( $metadata['rate_provenance'] )
			? $metadata['rate_provenance']
			: array();

		foreach ( array( 'usd', 'cny' ) as $currency_code ) {
			if ( ! isset( $stored[ $currency_code ] ) || ! is_array( $stored[ $currency_code ] ) ) {
				continue;
			}
			$entry                    = $stored[ $currency_code ];
			$source                   = isset( $entry['source'] ) && is_array( $entry['source'] )
				? array_intersect_key(
					$entry['source'],
					array(
						'id'       => true,
						'dataset'  => true,
						'revision' => true,
					)
				)
				: null;
			$result[ $currency_code ] = array(
				'source'      => $source,
				'channel'     => isset( $entry['channel'] ) && is_string( $entry['channel'] )
					? substr( sanitize_key( $entry['channel'] ), 0, 64 )
					: $default['channel'],
				'recorded_at' => isset( $entry['recorded_at'] ) && is_string( $entry['recorded_at'] )
					? substr( $entry['recorded_at'], 0, 40 )
					: null,
				'date_basis'  => isset( $entry['date_basis'] ) && is_string( $entry['date_basis'] )
					? substr( sanitize_key( $entry['date_basis'] ), 0, 32 )
					: $fallback_basis,
			);
		}

		return $result;
	}

	/**
	 * Return whole signed days from an ISO date to today.
	 *
	 * @param string $date ISO date.
	 * @return int|null
	 */
	private function age_days( $date ) {
		$parsed = Digitalogic_Currency_Date_Formatter::instance()->parse( $date );
		if ( ! $parsed instanceof DateTimeImmutable ) {
			return null;
		}
		$today = new DateTimeImmutable( 'today', $parsed->getTimezone() );

		return (int) $parsed->setTime( 0, 0 )->diff( $today )->format( '%r%a' );
	}

	/**
	 * Return absolute percentage drift.
	 *
	 * @param float|int $current  Current value.
	 * @param float|int $proposed Proposed value.
	 * @return float|null
	 */
	private function drift_percent( $current, $proposed ) {
		$current = (float) $current;
		if ( 0.0 === $current ) {
			return null;
		}

		return round( abs( ( (float) $proposed - $current ) / $current ) * 100, 4 );
	}

	/**
	 * Build a warning object with a Persian operator message.
	 *
	 * @param string $code       Stable code.
	 * @param string $message_fa Persian message.
	 * @param string $severity   info, warning, or critical.
	 * @param array  $details    Bounded details.
	 * @return array
	 */
	private function warning( $code, $message_fa, $severity, $details = array() ) {
		$warning = array(
			'code'       => $code,
			'severity'   => $severity,
			'message_fa' => $message_fa,
		);
		if ( $details ) {
			$warning['details'] = $details;
		}

		return $warning;
	}

	/**
	 * Return a quoted-state revision conflict.
	 *
	 * @param string $current Current revision.
	 * @return WP_Error
	 */
	private function revision_conflict( $current ) {
		return $this->error(
			'digitalogic_excel_sync_state_revision_conflict',
			'تنظیمات سایت پس از آخرین خواندن تغییر کرده است؛ دوباره state را دریافت کنید.',
			412,
			array( 'current_state_revision' => $current )
		);
	}

	/**
	 * Build a stable SHA-256 revision.
	 *
	 * @param mixed $identity Revision identity.
	 * @return string
	 */
	private function revision( $identity ) {
		return 'sha256:' . hash( 'sha256', $this->canonical_json( $identity ) );
	}

	/**
	 * Match the shipping service's established default-markup revision.
	 *
	 * That existing contract intentionally hashes its insertion-ordered
	 * identity rather than the recursively sorted Excel-sync identity.
	 *
	 * @param array $identity Default-markup identity.
	 * @return string
	 */
	private function default_markup_revision( $identity ) {
		return 'sha256:' . hash(
			'sha256',
			wp_json_encode( $identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Canonically encode one hash identity.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function canonical_json( $value ) {
		return (string) wp_json_encode(
			$this->sort_for_hash( $value ),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/**
	 * Recursively sort associative hash material.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sort_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_for_hash( $item );
		}

		return $value;
	}

	/**
	 * Validate a revision token.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	private function is_revision( $value ) {
		return is_string( $value )
			&& 1 === preg_match( '/\Asha256:[a-f0-9]{64}\z/D', $value );
	}

	/**
	 * Build a preview transient key.
	 *
	 * @param string $digest Preview digest.
	 * @return string
	 */
	private function preview_key( $digest ) {
		return 'digitalogic_excel_sync_preview_' . hash( 'sha256', $digest );
	}

	/**
	 * Build an idempotency transient key.
	 *
	 * @param string $mode Operation.
	 * @param string $key  Client key.
	 * @return string
	 */
	private function idempotency_key( $mode, $key ) {
		return 'digitalogic_excel_sync_' . $mode . '_' . hash( 'sha256', $key );
	}

	/**
	 * Current UTC timestamp.
	 *
	 * @return string
	 */
	private function now_iso8601() {
		return gmdate( 'c' );
	}

	/**
	 * Create one bounded service error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Persian operator message.
	 * @param int    $status  HTTP status.
	 * @param array  $details Optional details.
	 * @return WP_Error
	 */
	private function error( $code, $message, $status, $details = array() ) {
		return new WP_Error(
			$code,
			$message,
			array_merge( array( 'status' => $status ), $details )
		);
	}
}
