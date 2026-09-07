<?php
/**
 * Front-end search acceleration over the Digitalogic WebSocket transport.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digitalogic_Frontend_Search {

	private static $instance      = null;
	private $invalidation_pending = false;

	private $public_actions = array(
		'woodmart_ajax_search',
		'digitalogic_catalog_page',
	);

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 100 );
		add_filter( 'digitalogic_command_requires_auth', array( $this, 'allow_public_search_command' ), 10, 4 );
		add_filter( 'digitalogic_websocket_ajax_action_allowed', array( $this, 'allow_public_ajax_search_action' ), 10, 4 );
		add_action( 'wp_ajax_woodmart_ajax_search', array( $this, 'serve_search' ), 1 );
		add_action( 'wp_ajax_nopriv_woodmart_ajax_search', array( $this, 'serve_search' ), 1 );
		add_action( 'admin_menu', array( $this, 'cache_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'register_cache_settings' ) );
		add_action( 'digitalogic_report_projection_invalidated', array( $this, 'queue_invalidation' ) );
		add_action( 'digitalogic_excel_pricing_apply_committed', array( $this, 'queue_invalidation' ) );
		add_action( 'shutdown', array( $this, 'publish_invalidation' ) );
	}

	/** Coalesce committed changes into one public invalidation per request. */
	public function queue_invalidation() {
		$this->invalidation_pending = true;
	}

	/** Publish after all product saves in the request have completed. */
	public function publish_invalidation() {
		if ( $this->invalidation_pending ) {
			$this->invalidation_pending = false;
			Digitalogic_Panel::record_event( 'search.invalidated', array( 'scope' => 'search' ) );
		}
	}

	public function enqueue_scripts() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		wp_enqueue_script(
			'digitalogic-frontend-search-ws',
			DIGITALOGIC_PLUGIN_URL . 'assets/js/frontend-search-ws.js',
			array( 'jquery' ),
			filemtime( DIGITALOGIC_PLUGIN_DIR . 'assets/js/frontend-search-ws.js' ) ?: DIGITALOGIC_VERSION,
			true
		);

		wp_localize_script(
			'digitalogic-frontend-search-ws',
			'digitalogicFrontendSearchWs',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'websocket'      => Digitalogic_WebSocket::instance()->get_public_client_config(),
				'actions'        => apply_filters( 'digitalogic_frontend_search_websocket_actions', $this->public_actions ),
				'fallback_delay' => (int) apply_filters( 'digitalogic_frontend_search_websocket_fallback_delay', 1200 ),
				'cache'          => $this->cache_settings(),
			)
		);
	}

	public function cache_settings() {
		return $this->sanitize_cache_settings( get_option( 'digitalogic_search_cache', array() ) );
	}

	public function sanitize_cache_settings( $value ) {
		$value = is_array( $value ) ? $value : array();
		return array(
			'server'  => in_array( $value['server'] ?? 'object', array( 'off', 'object', 'transient' ), true ) ? ( $value['server'] ?? 'object' ) : 'object',
			'browser' => ! isset( $value['browser'] ) || ! empty( $value['browser'] ),
			'ttl'     => max( 5, min( 600, (int) ( $value['ttl'] ?? 60 ) ) ),
			'entries' => max( 1, min( 100, (int) ( $value['entries'] ?? 32 ) ) ),
		);
	}

	public function cache_menu() {
		add_submenu_page( 'digitalogic', __( 'Search cache', 'digitalogic' ), __( 'Search cache', 'digitalogic' ), 'manage_options', 'digitalogic-search-cache', array( $this, 'render_cache_settings' ) );
	}

	public function register_cache_settings() {
		register_setting( 'digitalogic_search_cache', 'digitalogic_search_cache', array( 'sanitize_callback' => array( $this, 'sanitize_cache_settings' ) ) );
	}

	public function render_cache_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->cache_settings();
		echo '<div class="wrap"><h1>' . esc_html__( 'Search cache', 'digitalogic' ) . '</h1><p>' . esc_html__( 'Changes to prices and products invalidate the cache. Every browser cache reuse is validated by the server; prices are verified against WooCommerce on every response. Unverified cached prices are never used on a failed request.', 'digitalogic' ) . '</p><form action="options.php" method="post">';
		settings_fields( 'digitalogic_search_cache' );
		echo '<p><label>' . esc_html__( 'Server storage', 'digitalogic' ) . ' <select name="digitalogic_search_cache[server]">';
		foreach ( array(
			'off'       => 'Disabled',
			'object'    => 'WordPress object cache (Redis when available)',
			'transient' => 'WordPress transients',
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $settings['server'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p><p><input type="hidden" name="digitalogic_search_cache[browser]" value="0"><label><input type="checkbox" name="digitalogic_search_cache[browser]" value="1" ' . checked( $settings['browser'], true, false ) . '> ' . esc_html__( 'Cache in browser memory (validated before reuse)', 'digitalogic' ) . '</label></p>';
		foreach ( array(
			'ttl'     => array( 'Retention in seconds', 5, 600 ),
			'entries' => array( 'Maximum browser entries', 1, 100 ),
		) as $key => $field ) {
			echo '<p><label>' . esc_html( $field[0] ) . ' <input type="number" name="digitalogic_search_cache[' . esc_attr( $key ) . ']" min="' . esc_attr( $field[1] ) . '" max="' . esc_attr( $field[2] ) . '" value="' . esc_attr( $settings[ $key ] ) . '"></label></p>';
		}
		submit_button();
		echo '</form></div>';
	}

	/** Cache search work, with an authoritative generation fence and fresh price readback. */
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only search; settings writes use the WordPress Settings API nonce and manage_options.
	public function serve_search() {
		if ( ! class_exists( 'XTS\\Modules\\Search\\Ajax_Search' ) ) {
			return;
		}
		nocache_headers();
		$settings   = $this->cache_settings();
		$engine     = Digitalogic_Report_Engine::instance();
		$generation = $engine->current_projection_generation();
		if ( is_wp_error( $generation ) ) {
			wp_send_json(
				array(
					'suggestions' => array(),
					'error'       => 'search_freshness_unavailable',
				),
				503
			);
			return;
		}
		$query = array();
		foreach ( array( 'query', 'post_type', 'number', 'product_cat', 'include_cat_search' ) as $field ) {
			$query[ $field ] = isset( $_REQUEST[ $field ] ) && is_scalar( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ) : '';
		}
		$query['number'] = max( 1, min( 50, (int) ( $query['number'] ?: 5 ) ) );
		if ( strlen( $query['query'] ) > 200 ) {
			wp_send_json(
				array(
					'suggestions' => array(),
					'error'       => 'search_query_too_long',
				),
				400
			);
			return;
		}
		// User/cookie context is hashed, never exposed or shared across sessions.
		$key  = 'dg_search_' . hash( 'sha256', wp_json_encode( array( $generation, $query, $settings, get_current_user_id(), $_COOKIE, get_locale(), get_woocommerce_currency() ) ) );
		$data = false;
		if ( 'object' === $settings['server'] ) {
			$data = wp_cache_get( $key, 'digitalogic_search' );
		} elseif ( 'transient' === $settings['server'] ) {
			$data = get_transient( $key );
		}
		$hit = is_array( $data );
		if ( ! $hit ) {
			$old_request = $_REQUEST;
			$_REQUEST    = array_merge( $_REQUEST, $query );
			try {
				$search      = \XTS\Modules\Search\Ajax_Search::get_instance();
				$suggestions = $search->build_suggestions( $search->get_main_suggestions(), $search->get_product_categories_suggestions(), $search->get_blog_suggestions() );
				$data        = array( 'suggestions' => $suggestions );
				foreach ( $data['suggestions'] as &$item ) {
					if ( isset( $item['price'], $item['permalink'] ) ) {
						$item['_dg_product_id'] = url_to_postid( $item['permalink'] );
						unset( $item['price'] );
					}
				}
				unset( $item );
			} finally {
				$_REQUEST = $old_request;
			}
		}
		// Keep price HTML out of the shared search cache. This also covers an
		// external pricing commit whose asynchronous invalidation is pending.
		$result = $data;
		foreach ( $result['suggestions'] as &$item ) {
			if ( isset( $item['_dg_product_id'] ) ) {
				$product       = wc_get_product( $item['_dg_product_id'] );
				$item['price'] = $product ? $product->get_price_html() : '';
				unset( $item['_dg_product_id'] );
			}
		}
		unset( $item );
		$after = $engine->current_projection_generation();
		if ( is_wp_error( $after ) || $generation !== $after ) {
			wp_send_json(
				array(
					'suggestions' => array(),
					'error'       => 'search_changed_during_read',
				),
				409
			);
			return;
		}
		if ( ! $hit && 'object' === $settings['server'] ) {
			wp_cache_set( $key, $data, 'digitalogic_search', $settings['ttl'] );
		} elseif ( ! $hit && 'transient' === $settings['server'] ) {
			set_transient( $key, $data, $settings['ttl'] );
		}
		$signature = hash( 'sha256', $key . wp_json_encode( $result ) );
		$known     = isset( $_REQUEST['dg_search_signature'] ) && is_string( $_REQUEST['dg_search_signature'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dg_search_signature'] ) ) : '';
		if ( $settings['browser'] && hash_equals( $signature, $known ) ) {
			$result = array( 'unchanged' => true );
		}
		$result['dg_cache'] = array(
			'signature' => $signature,
			'hit'       => $hit,
			'settings'  => $settings,
		);
		wp_send_json( $result );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function allow_public_search_command( $requires_auth, $command, $payload, $transport ) {
		if ( $transport === 'websocket' && in_array( $command, $this->public_actions, true ) ) {
			return false;
		}

		return $requires_auth;
	}

	public function allow_public_ajax_search_action( $allowed, $command, $payload, $transport ) {
		// WooCommerce and theme request-local caches cannot be reused by a daemon.
		// Existing clients already retry rejected commands through authenticated HTTP.
		if ( $transport === 'websocket' && $command === 'woodmart_ajax_search' ) {
			return false;
		}
		if ( $transport === 'websocket' && in_array( $command, $this->public_actions, true ) ) {
			return true;
		}

		return $allowed;
	}
}
