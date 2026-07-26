<?php
/**
 * Safe, source-scoped Excel pricing-settings synchronization.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the local Patris companion with versioned WooCommerce pricing
 * inputs. Product prices remain derived and are never accepted by this API.
 */
final class Digitalogic_Excel_Pricing_Sync {

	public const REQUEST_SCHEMA  = 'digitalogic.excel-pricing-sync-request/v1';
	public const STATE_SCHEMA    = 'digitalogic.excel-pricing-sync-state/v1';
	public const PREVIEW_SCHEMA  = 'digitalogic.excel-pricing-sync-preview/v1';
	public const APPLY_SCHEMA    = 'digitalogic.excel-pricing-sync-apply/v1';
	public const SETTINGS_SCHEMA = 'digitalogic.excel-pricing-settings/v1';

	public const SETTINGS_OPTION = 'digitalogic_excel_pricing_sync_settings';
	public const AUDIT_OPTION    = 'digitalogic_excel_pricing_sync_audit';

	private const LOCK_NAME                 = 'digitalogic_excel_pricing_sync_v1';
	private const LOCK_TIMEOUT_SECONDS      = 5;
	private const PREVIEW_TTL_SECONDS       = 600;
	private const APPLY_IDEMPOTENCY_SECONDS = 86400;
	private const MAX_AUDIT_ENTRIES         = 50;
	private const MAX_RATE                  = 1000000000;
	private const MAX_PROFIT_PERCENT        = '1000';
	private const MAX_PROFIT_SCALE          = 12;
	private const STALE_AFTER_DAYS          = 7;
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

		$page  = isset( $payload['page'] ) ? absint( $payload['page'] ) : 1;
		$limit = isset( $payload['limit'] ) ? absint( $payload['limit'] ) : Digitalogic_Google_Sheets_Catalog::MAX_PAGE_SIZE;
		$page  = max( 1, $page );
		$limit = max( 1, min( Digitalogic_Google_Sheets_Catalog::MAX_PAGE_SIZE, $limit ) );

		$catalog = Digitalogic_Google_Sheets_Catalog::instance()->get_page(
			array(
				'dataset' => 'products',
				'locale'  => 'fa',
				'page'    => $page,
				'limit'   => $limit,
			)
		);
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$warnings       = array();
		$source_warning = $this->source_revision_warning( $source_context );
		if ( null !== $source_warning ) {
			$warnings[] = $source_warning;
		}

		return array(
			'schema'         => self::STATE_SCHEMA,
			'state_revision' => $globals['state_revision'],
			'generated_at'   => $this->now_iso8601(),
			'source'         => $source_context,
			'warnings'       => $warnings,
			'currency'       => $globals['currency'],
			'default_markup' => $globals['default_markup'],
			'catalog'        => $catalog,
		);
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
					$settings
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
					$preview
				);
				if ( is_wp_error( $result ) ) {
					$this->release_idempotency( 'apply', $headers['idempotency_key'] );
					return $result;
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

				return $result;
			}
		);
	}

	/**
	 * Build a preview while holding the synchronization lock.
	 *
	 * @param array  $source                    Exact current source.
	 * @param string $expected_state_revision   Expected settings revision.
	 * @param array  $settings                  Proposed settings.
	 * @return array|WP_Error
	 */
	private function build_preview( $source, $expected_state_revision, $settings ) {
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
	 * @return array|WP_Error
	 */
	private function apply_locked( $source, $source_context, $idempotency_key, $expected_state_revision, $settings, $preview_digest, $preview ) {
		$current = $this->read_globals();
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$desired = $this->globals_from_settings( $settings );
		$changed = ! hash_equals( $current['state_revision'], $desired['state_revision'] );

		if ( $changed ) {
			$result = $this->run_transaction(
				function () use ( $source, $source_context, $idempotency_key, $expected_state_revision, $settings, $preview_digest, $desired ) {
					foreach (
						array(
							'dollar_price',
							'options_dollar_price',
							'yuan_price',
							'options_yuan_price',
							'update_date',
							'options_update_date',
							Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION,
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
						$settings
					);
					if ( is_wp_error( $audit ) ) {
						return $audit;
					}

					$readback = $this->read_globals();
					if ( is_wp_error( $readback ) ) {
						return $readback;
					}
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

					return $readback;
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$readback = $result;
		} else {
			$readback = $current;
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
		$warnings[] = $this->warning(
			'patris_regeneration_required',
			'تنظیمات سراسری تأیید شد؛ companion باید قیمت‌های مشتق‌شده را بازتولید و ارسال کند.',
			'info'
		);

		$response = array(
			'schema'          => self::APPLY_SCHEMA,
			'mode'            => 'apply',
			'status'          => $changed ? 'applied' : 'unchanged',
			'state_revision'  => $readback['state_revision'],
			'source'          => $source_context,
			'preview_digest'  => $preview_digest,
			'expires_at'      => gmdate( 'c', (int) $preview['expires_at'] ),
			'warnings'        => $warnings,
			'product_results' => array(),
		);

		if ( $changed ) {
			$this->emit_after_apply( $source, $expected_state_revision, $current, $readback, $settings );
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

		if ( ! isset( $payload['schema'] ) || self::REQUEST_SCHEMA !== $payload['schema'] ) {
			return $this->error(
				'digitalogic_excel_sync_schema_unsupported',
				'نسخهٔ قرارداد همگام‌سازی پشتیبانی نمی‌شود.',
				422
			);
		}
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

		if ( isset( $payload['locale'] ) && ! in_array( $payload['locale'], array( 'fa', 'fa_IR' ), true ) ) {
			return $this->error(
				'digitalogic_excel_sync_locale_invalid',
				'خروجی این قرارداد فقط به زبان فارسی ارائه می‌شود.',
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
	 * Canonical keys are dollar_price, yuan_price, effective_date, and
	 * default_profit_percent. Narrow USD/CNY aliases are accepted so older
	 * companion builds can migrate without putting compatibility in VBA.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array|WP_Error
	 */
	private function normalize_settings( $settings ) {
		if ( ! is_array( $settings ) || array_is_list( $settings ) ) {
			return $this->error(
				'digitalogic_excel_sync_settings_invalid',
				'تنظیمات باید یک شیء JSON کامل باشد.',
				400
			);
		}

		$aliases = array(
			'usd_irt'        => 'dollar_price',
			'cny_irt'        => 'yuan_price',
			'profit_percent' => 'default_profit_percent',
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

		$required = array( 'dollar_price', 'yuan_price', 'effective_date', 'default_profit_percent' );
		if (
			! empty( array_diff( $required, array_keys( $settings ) ) )
			|| ! empty( array_diff( array_keys( $settings ), $required ) )
		) {
			$missing = array_values( array_diff( $required, array_keys( $settings ) ) );
			$unknown = array_values( array_diff( array_keys( $settings ), $required ) );
			return $this->error(
				'digitalogic_excel_sync_settings_shape_invalid',
				'سند تنظیمات باید هر چهار مقدار سراسری را دقیقاً یک‌بار داشته باشد.',
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
		$date = $this->canonical_date( $settings['effective_date'] );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$profit = $this->canonical_profit( $settings['default_profit_percent'] );
		if ( is_wp_error( $profit ) ) {
			return $profit;
		}

		return array(
			'dollar_price'           => $dollar,
			'yuan_price'             => $yuan,
			'effective_date'         => $date,
			'default_profit_percent' => $profit,
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
				'درصد سود باید یک عدد ده‌دهی نامنفی باشد.',
				400,
				array( 'field' => 'settings.default_profit_percent' )
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
				'دقت اعشاری درصد سود بیش از حد مجاز است.',
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
				'درصد سود باید بین صفر و ۱۰۰۰ باشد.',
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
		$for_update = $this->transaction_active;
		$dollar_row = $this->authoritative_currency_option(
			'options_dollar_price',
			'dollar_price',
			$for_update
		);
		$yuan_row   = $this->authoritative_currency_option(
			'options_yuan_price',
			'yuan_price',
			$for_update
		);
		$date_row   = $this->read_option_db( 'options_update_date', $for_update );
		if ( ! $date_row['exists'] ) {
			$date_row = $this->read_option_db( 'update_date', $for_update );
		}
		$dollar = $this->installed_rate( $dollar_row['exists'] ? $dollar_row['value'] : null );
		$yuan   = $this->installed_rate( $yuan_row['exists'] ? $yuan_row['value'] : null );
		$date   = Digitalogic_Currency_Date_Formatter::instance()->parse(
			$date_row['exists'] ? $date_row['value'] : null
		);

		$effective_date = null;
		$age_days       = null;
		$stale          = true;
		if ( $date instanceof DateTimeImmutable ) {
			$effective_date = $date->format( 'Y-m-d' );
			$today          = new DateTimeImmutable( 'today', $date->getTimezone() );
			$age_days       = (int) $date->setTime( 0, 0 )->diff( $today )->format( '%r%a' );
			$stale          = $age_days < 0 || $age_days > self::STALE_AFTER_DAYS;
		}
		if ( null === $dollar || null === $yuan ) {
			$stale = true;
		}

		$markup = Digitalogic_Shipping_Method_Service::instance()->get_default_percentage_markup();
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}
		$markup_revision   = isset( $markup['revision'] ) && is_string( $markup['revision'] )
			? $markup['revision']
			: $this->revision( array( 'configured' => false ) );
		$profit            = ! empty( $markup['configured'] ) && isset( $markup['profit_percent'] )
			? (string) $markup['profit_percent']
			: null;
		$currency          = array(
			'dollar_price'   => $dollar,
			'yuan_price'     => $yuan,
			'effective_date' => $effective_date,
		);
		$currency_revision = $this->revision(
			array_merge(
				array( 'schema' => self::SETTINGS_SCHEMA . '/currency' ),
				$currency
			)
		);

		$currency['revision'] = $currency_revision;
		$currency['age_days'] = $age_days;
		$currency['stale']    = $stale;
		$default_markup       = array(
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
					'schema'                  => self::SETTINGS_SCHEMA,
					'currency_revision'       => $currency_revision,
					'default_markup_revision' => $markup_revision,
				)
			),
			'currency'       => $currency,
			'default_markup' => $default_markup,
		);
	}

	/**
	 * Build the exact globals projection expected after one settings write.
	 *
	 * @param array $settings Canonical settings.
	 * @return array
	 */
	private function globals_from_settings( $settings ) {
		$currency          = array(
			'dollar_price'   => $settings['dollar_price'],
			'yuan_price'     => $settings['yuan_price'],
			'effective_date' => $settings['effective_date'],
		);
		$currency_revision = $this->revision(
			array_merge(
				array( 'schema' => self::SETTINGS_SCHEMA . '/currency' ),
				$currency
			)
		);
		$markup_identity   = array(
			'schema'         => Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_SCHEMA,
			'configured'     => true,
			'type'           => 'percentage',
			'source'         => 'global_default',
			'profit_percent' => $settings['default_profit_percent'],
		);
		$markup_revision   = $this->default_markup_revision( $markup_identity );

		$currency['revision'] = $currency_revision;
		$age_days             = $this->age_days( $settings['effective_date'] );
		$currency['age_days'] = $age_days;
		$currency['stale']    = null === $age_days || $age_days < 0 || $age_days > self::STALE_AFTER_DAYS;

		return array(
			'state_revision'  => $this->revision(
				array(
					'schema'                  => self::SETTINGS_SCHEMA,
					'currency_revision'       => $currency_revision,
					'default_markup_revision' => $markup_revision,
				)
			),
			'currency'        => $currency,
			'default_markup'  => array(
				'configured'     => true,
				'profit_percent' => $settings['default_profit_percent'],
				'revision'       => $markup_revision,
				'updated_at'     => current_time( 'mysql', true ),
			),
			'markup_identity' => $markup_identity,
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
				array( 'age_days' => $current['currency']['age_days'] )
			);
		}

		$proposed_age = $this->age_days( $settings['effective_date'] );
		if ( null === $proposed_age || $proposed_age < 0 || $proposed_age > self::STALE_AFTER_DAYS ) {
			$warnings[] = $this->warning(
				'proposed_currency_stale',
				'تاریخ مؤثر نرخ پیشنهادی خارج از بازهٔ ۷ روزه است.',
				'critical',
				array( 'age_days' => $proposed_age )
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
		$proposed_profit = $settings['default_profit_percent'];
		if ( null === $current_profit || $current_profit !== $proposed_profit ) {
			$drift      = null === $current_profit
				? null
				: $this->drift_percent( (float) $current_profit, (float) $proposed_profit );
			$warnings[] = $this->warning(
				null !== $drift && $drift > self::DRIFT_PERCENT
					? 'profit_drift_over_7_percent'
					: 'default_profit_changed',
				null === $current_profit
					? 'درصد سود پیش‌فرض در سایت تنظیم نشده است.'
					: ( null !== $drift && $drift > self::DRIFT_PERCENT
						? 'درصد سود پیشنهادی بیش از ۷٪ با سایت اختلاف دارد.'
						: 'درصد سود پیشنهادی با سایت یکسان نیست.' ),
				null === $current_profit || ( null !== $drift && $drift > self::DRIFT_PERCENT )
					? 'critical'
					: 'warning',
				array(
					'field'         => 'default_profit_percent',
					'current'       => $current_profit,
					'proposed'      => $proposed_profit,
					'drift_percent' => $drift,
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

		return $warnings;
	}

	/**
	 * Return the desired option rows for an atomic write.
	 *
	 * @param array $source   Exact source.
	 * @param array $settings Canonical settings.
	 * @param array $current  Current globals.
	 * @param array $desired  Desired globals.
	 * @return array
	 */
	private function desired_option_values( $source, $settings, $current, $desired ) {
		$legacy_date = substr( $settings['effective_date'], 2, 2 )
			. substr( $settings['effective_date'], 5, 2 )
			. substr( $settings['effective_date'], 8, 2 );
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
		$metadata    = array(
			'schema'                  => self::SETTINGS_SCHEMA,
			'generation'              => $generation,
			'revision'                => $desired['state_revision'],
			'currency_revision'       => $desired['currency']['revision'],
			'default_markup_revision' => $desired['default_markup']['revision'],
			'dollar_price'            => $settings['dollar_price'],
			'yuan_price'              => $settings['yuan_price'],
			'effective_date'          => $settings['effective_date'],
			'default_profit_percent'  => $settings['default_profit_percent'],
			'source'                  => $source,
			'updated_at'              => current_time( 'mysql', true ),
			'previous_revision'       => $current['state_revision'],
		);

		return array(
			'dollar_price'         => (string) $settings['dollar_price'],
			'options_dollar_price' => (string) $settings['dollar_price'],
			'yuan_price'           => (string) $settings['yuan_price'],
			'options_yuan_price'   => (string) $settings['yuan_price'],
			'update_date'          => $legacy_date,
			'options_update_date'  => $legacy_date,
			Digitalogic_Shipping_Method_Service::DEFAULT_MARKUP_OPTION => $markup,
			self::SETTINGS_OPTION  => $metadata,
		);
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
	 * @return true|WP_Error
	 */
	private function append_audit_entry( $source, $source_context, $idempotency_key, $preview_digest, $before, $after, $settings ) {
		$row       = $this->read_option_db( self::AUDIT_OPTION, true );
		$entries   = $row['exists'] && is_array( $row['value'] ) ? array_values( $row['value'] ) : array();
		$entries[] = array(
			'applied_at'              => current_time( 'mysql', true ),
			'source'                  => $source,
			'source_revision_context' => $source_context,
			'idempotency_key'         => $idempotency_key,
			'preview_digest'          => $preview_digest,
			'previous_revision'       => $before['state_revision'],
			'state_revision'          => $after['state_revision'],
			'settings'                => $settings,
		);
		$entries   = array_slice( $entries, -self::MAX_AUDIT_ENTRIES );

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
		$name   = substr( self::LOCK_NAME . '_' . md5( $prefix ), 0, 64 );
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
		$name   = substr( self::LOCK_NAME . '_' . md5( $prefix ), 0, 64 );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Run an atomic option transaction.
	 *
	 * @param callable $callback Transaction callback.
	 * @return mixed|WP_Error
	 */
	private function run_transaction( $callback ) {
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
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$rollback = $this->rollback_transaction();
			return is_wp_error( $rollback )
				? $rollback
				: $this->error(
					'digitalogic_excel_sync_commit_failed',
					'ثبت نهایی تراکنش تنظیمات ممکن نیست.',
					500
				);
		}

		$names                           = $this->transaction_option_names;
		$events                          = $this->transaction_option_events;
		$this->transaction_active        = false;
		$this->transaction_option_names  = array();
		$this->transaction_option_events = array();
		$this->invalidate_option_caches( $names );
		$this->dispatch_option_events( $events );

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
	 * @param array $names Changed option map.
	 * @return void
	 */
	private function invalidate_option_caches( $names ) {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		foreach ( array_keys( $names ) as $name ) {
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

	/**
	 * Emit nonsecret post-commit audit/domain events.
	 *
	 * @param array  $source            Exact source.
	 * @param string $previous_revision Previous revision.
	 * @param array  $previous          Previous globals.
	 * @param array  $readback          Readback globals.
	 * @param array  $settings          Applied settings.
	 * @return void
	 */
	private function emit_after_apply( $source, $previous_revision, $previous, $readback, $settings ) {
		$result = array(
			'schema'            => self::SETTINGS_SCHEMA,
			'source'            => $source,
			'previous_revision' => $previous_revision,
			'state_revision'    => $readback['state_revision'],
			'settings'          => $settings,
		);
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
						'profit_percent' => $settings['default_profit_percent'],
						'revision'       => $readback['default_markup']['revision'],
						'changed'        => true,
					)
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
