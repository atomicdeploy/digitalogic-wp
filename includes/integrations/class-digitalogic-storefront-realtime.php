<?php
/**
 * Public storefront Server-Sent Events and browser bootstrap data.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes a privacy-bounded projection of the durable panel event queue.
 */
final class Digitalogic_Storefront_Realtime {

	private const REST_ROUTE         = '/events/stream';
	private const STREAM_SECONDS     = 20;
	private const POLL_MICROSECONDS  = 500000;
	private const HEARTBEAT_SECONDS  = 8;
	private const MAX_CURSOR_DIGITS  = 20;
	private const PUBLIC_EVENT_NAMES = array(
		'currency.updated',
		'product.updated',
		'product.created',
		'product.deleted',
		'product.stock.changed',
	);

	/**
	 * Shared service instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Return the shared storefront real-time service.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Register the public read-only route and storefront client. */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_stream' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 110 );
	}

	/** Register the same-origin public SSE endpoint. */
	public function register_routes() {
		register_rest_route(
			'digitalogic/v1',
			self::REST_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stream_descriptor' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Return a marker response which rest_pre_serve_request turns into SSE.
	 *
	 * @return WP_REST_Response
	 */
	public function stream_descriptor() {
		return new WP_REST_Response( array( 'stream' => true ), 200 );
	}

	/**
	 * Stream public events instead of serializing the marker as JSON.
	 *
	 * @param bool             $served  Whether REST already served the response.
	 * @param WP_REST_Response $result Response object.
	 * @param WP_REST_Request  $request Request object.
	 * @param WP_REST_Server   $server REST server.
	 * @return bool
	 */
	public function maybe_serve_stream( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( $served || ! is_object( $request ) || '/digitalogic/v1' . self::REST_ROUTE !== $request->get_route() ) {
			return $served;
		}

		$this->serve_stream( $request );

		return true;
	}

	/** Enqueue the cross-tab coordinator on public storefront pages. */
	public function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}

		$product_id = 0;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product_id = absint( get_queried_object_id() );
		}

		wp_enqueue_script(
			'digitalogic-storefront-realtime',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/storefront-realtime.js',
			array(),
			DIGITALOGIC_VERSION,
			true
		);

		wp_localize_script(
			'digitalogic-storefront-realtime',
			'DigitalogicRealtime',
			array(
				'streamUrl'        => rest_url( 'digitalogic/v1' . self::REST_ROUTE ),
				'currentProductId' => $product_id,
				'initialEventId'   => Digitalogic_Panel::get_latest_event_id(),
				'currency'         => self::currency_snapshot(),
				'currencyTtlMs'    => 6 * HOUR_IN_SECONDS * 1000,
				'leaderTtlMs'      => 12000,
			)
		);
	}

	/**
	 * Project one durable internal event onto the nonsecret public contract.
	 *
	 * @param array $event Durable panel event.
	 * @return array|null
	 */
	public static function project_public_event( $event ) {
		if ( ! is_array( $event ) ) {
			return null;
		}

		$name = sanitize_text_field( (string) ( $event['name'] ?? '' ) );
		if ( ! in_array( $name, self::PUBLIC_EVENT_NAMES, true ) ) {
			return null;
		}

		$event_id = absint( $event['id'] ?? 0 );
		if ( $event_id <= 0 ) {
			return null;
		}

		$data = array();
		if ( 'currency.updated' === $name ) {
			$data = array(
				'scope'    => 'general',
				'currency' => self::currency_snapshot(),
			);
		} else {
			$source     = is_array( $event['data'] ?? null ) ? $event['data'] : array();
			$id         = absint( $source['id'] ?? 0 );
			$parent_id  = absint( $source['parent_id'] ?? 0 );
			$product_id = absint( $source['product_id'] ?? ( $parent_id > 0 ? $parent_id : $id ) );
			if ( $id <= 0 && $product_id <= 0 ) {
				return null;
			}
			$data = array(
				'scope'      => 'product',
				'product_id' => $product_id,
				'object_id'  => $id,
				'parent_id'  => $parent_id,
				'change'     => substr( $name, strlen( 'product.' ) ),
			);
		}

		return array(
			'id'   => $event_id,
			'name' => $name,
			'time' => sanitize_text_field( (string) ( $event['time'] ?? '' ) ),
			'data' => $data,
		);
	}

	/** Return the current public currency display snapshot. */
	private static function currency_snapshot() {
		$data      = Digitalogic_Command_Dispatcher::instance()->get_currency();
		$formatter = Digitalogic_Currency_Date_Formatter::instance();
		$usd_date  = sanitize_text_field( (string) ( $data['usd_effective_date'] ?? '' ) );
		$cny_date  = sanitize_text_field( (string) ( $data['cny_effective_date'] ?? '' ) );

		return array(
			'dollar_price'       => max( 0, (int) ( $data['dollar_price'] ?? 0 ) ),
			'yuan_price'         => max( 0, (int) ( $data['yuan_price'] ?? 0 ) ),
			'updated_at'         => sanitize_text_field( (string) ( $data['updated_at'] ?? '' ) ),
			'usd_effective_date' => $usd_date,
			'cny_effective_date' => $cny_date,
			'usd_display_date'   => '' === $usd_date ? '' : $formatter->format( $usd_date ),
			'cny_display_date'   => '' === $cny_date ? '' : $formatter->format( $cny_date ),
			'state_revision'     => sanitize_text_field( (string) ( $data['state_revision'] ?? '' ) ),
		);
	}

	/**
	 * Emit one bounded SSE response and let EventSource reconnect.
	 *
	 * @param WP_REST_Request $request Current SSE request.
	 */
	private function serve_stream( $request ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, must-revalidate' );
			header( 'X-Accel-Buffering: no' );
			header( 'Content-Encoding: identity' );
		}
		$this->disable_output_buffering();

		@set_time_limit( self::STREAM_SECONDS + 5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		ignore_user_abort( true );

		$cursor = $this->request_cursor( $request );
		$latest = Digitalogic_Panel::get_latest_event_id();
		if ( 0 === $cursor || $cursor > $latest ) {
			$cursor = $latest;
			$this->write_event(
				array(
					'id'   => $cursor,
					'name' => 'realtime.ready',
					'time' => current_time( 'mysql' ),
					'data' => array(
						'scope'    => 'general',
						'currency' => self::currency_snapshot(),
					),
				)
			);
		}

		echo "retry: 1500\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->flush_output();
		$deadline       = microtime( true ) + self::STREAM_SECONDS;
		$last_heartbeat = microtime( true );

		while ( ! connection_aborted() && microtime( true ) < $deadline ) {
			$events = Digitalogic_Panel::get_events_since( $cursor );
			foreach ( $events as $event ) {
				$cursor = max( $cursor, absint( $event['id'] ?? 0 ) );
				$public = self::project_public_event( $event );
				if ( null !== $public ) {
					$this->write_event( $public );
				}
			}

			if ( microtime( true ) - $last_heartbeat >= self::HEARTBEAT_SECONDS ) {
				echo ': heartbeat ' . time() . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$last_heartbeat = microtime( true );
			}

			$this->flush_output();
			usleep( self::POLL_MICROSECONDS );
		}
	}

	/**
	 * Read and strictly bound Last-Event-ID or the query fallback.
	 *
	 * @param WP_REST_Request $request Current SSE request.
	 * @return int
	 */
	private function request_cursor( $request ) {
		$cursor = (string) $request->get_header( 'last-event-id' );
		if ( '' === $cursor ) {
			$cursor = (string) $request->get_param( 'last_event_id' );
		}

		return preg_match( '/\A[0-9]{1,' . self::MAX_CURSOR_DIGITS . '}\z/D', $cursor ) ? absint( $cursor ) : 0;
	}

	/**
	 * Write one JSON event without allowing newline injection.
	 *
	 * @param array $event Public event envelope.
	 */
	private function write_event( $event ) {
		$payload = wp_json_encode( $event );
		if ( ! is_string( $payload ) ) {
			return;
		}

		echo 'id: ' . absint( $event['id'] ?? 0 ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'data: ' . str_replace( array( "\r", "\n" ), '', $payload ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Flush PHP and proxy buffers after each batch. */
	private function flush_output() {
		while ( function_exists( 'ob_get_level' ) && ob_get_level() > 0 ) {
			if ( ! @ob_end_flush() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}
		}
		flush();
	}

	/** Disable PHP and Apache compression before the first streaming byte. */
	private function disable_output_buffering() {
		if ( function_exists( 'apache_setenv' ) ) {
			// Required for a real-time response; the setting is request-scoped.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
			@apache_setenv( 'no-gzip', '1' );
		}
		if ( function_exists( 'ini_set' ) ) {
			// Required for a real-time response; the setting is request-scoped.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			@ini_set( 'zlib.output_compression', '0' );
		}
		$this->flush_output();
	}
}
