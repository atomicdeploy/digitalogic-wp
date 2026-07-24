<?php
/**
 * WP-CLI access to administrator-only product supplier links.
 *
 * @package Digitalogic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage private supplier links without placing seller URLs in process arguments.
 */
final class Digitalogic_Product_Supplier_Links_CLI {

	/**
	 * List private links for one exact parent product.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce parent product ID.
	 *
	 * [--sku=<sku>]
	 * : Exact WooCommerce parent product SKU.
	 *
	 * ## EXAMPLES
	 *
	 *     wp digitalogic supplier-links list --id=123 --user=administrator
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 * @when after_wp_load
	 */
	public function list_links( $args, $assoc_args ) {
		unset( $args );
		$product = $this->require_product( $assoc_args );
		if ( is_wp_error( $product ) ) {
			$this->output_error( $product );
			return;
		}

		$links = Digitalogic_Product_Supplier_Links::instance()->get_links( $product['woocommerce_id'] );
		if ( is_wp_error( $links ) ) {
			$this->output_error( $links );
			return;
		}

		WP_CLI::line(
			wp_json_encode(
				array(
					'product_id' => (string) $product['woocommerce_id'],
					'count'      => count( $links ),
					'links'      => $links,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Add one private link read as a JSON object from stdin.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce parent product ID.
	 *
	 * [--sku=<sku>]
	 * : Exact WooCommerce parent product SKU.
	 *
	 * ## EXAMPLES
	 *
	 *     printf '%s' "$SELLER_LINK_JSON" | wp digitalogic supplier-links add --id=123 --user=administrator
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 * @when after_wp_load
	 */
	public function add_link( $args, $assoc_args ) {
		unset( $args );
		$product = $this->require_product( $assoc_args );
		if ( is_wp_error( $product ) ) {
			$this->output_error( $product );
			return;
		}

		$input = $this->read_stdin_json( false );
		if ( is_wp_error( $input ) ) {
			$this->output_error( $input );
			return;
		}

		$result = Digitalogic_Product_Supplier_Links::instance()->add_link( $product['woocommerce_id'], $input );
		if ( is_wp_error( $result ) ) {
			$this->output_error( $result );
			return;
		}

		$this->output_mutation_summary( $product['woocommerce_id'], $result );
	}

	/**
	 * Replace all links with a JSON array read from stdin.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce parent product ID.
	 *
	 * [--sku=<sku>]
	 * : Exact WooCommerce parent product SKU.
	 *
	 * ## EXAMPLES
	 *
	 *     printf '%s' "$SELLER_LINKS_JSON" | wp digitalogic supplier-links replace --sku=MODULE-1 --user=administrator
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 * @when after_wp_load
	 */
	public function replace_links( $args, $assoc_args ) {
		unset( $args );
		$product = $this->require_product( $assoc_args );
		if ( is_wp_error( $product ) ) {
			$this->output_error( $product );
			return;
		}

		$input = $this->read_stdin_json( true );
		if ( is_wp_error( $input ) ) {
			$this->output_error( $input );
			return;
		}

		$result = Digitalogic_Product_Supplier_Links::instance()->replace_links( $product['woocommerce_id'], $input );
		if ( is_wp_error( $result ) ) {
			$this->output_error( $result );
			return;
		}

		$this->output_mutation_summary( $product['woocommerce_id'], $result );
	}

	/**
	 * Remove one private link by stable ID.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<product_id>]
	 * : Exact WooCommerce parent product ID.
	 *
	 * [--sku=<sku>]
	 * : Exact WooCommerce parent product SKU.
	 *
	 * --link-id=<link_id>
	 * : Stable ID returned by the list command.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 * @when after_wp_load
	 */
	public function remove_link( $args, $assoc_args ) {
		unset( $args );
		$product = $this->require_product( $assoc_args );
		if ( is_wp_error( $product ) ) {
			$this->output_error( $product );
			return;
		}

		$link_id = isset( $assoc_args['link-id'] ) ? sanitize_key( $assoc_args['link-id'] ) : '';
		if ( '' === $link_id ) {
			$this->output_error(
				new WP_Error(
					'digitalogic_supplier_link_id_required',
					'گزینه --link-id الزامی است.',
					array( 'status' => 400 )
				)
			);
			return;
		}

		$result = Digitalogic_Product_Supplier_Links::instance()->remove_link( $product['woocommerce_id'], $link_id );
		if ( is_wp_error( $result ) ) {
			$this->output_error( $result );
			return;
		}

		$this->output_mutation_summary( $product['woocommerce_id'], $result );
	}

	/**
	 * Decode a bounded JSON payload for tests and stdin handling.
	 *
	 * @param string $json Raw JSON.
	 * @param bool   $expect_list Whether the top level must be a list.
	 * @return array|WP_Error
	 */
	public static function decode_json_input( $json, $expect_list ) {
		if ( ! is_string( $json ) || '' === trim( $json ) || strlen( $json ) > Digitalogic_Product_Supplier_Links::MAX_INPUT_BYTES ) {
			return new WP_Error(
				'digitalogic_supplier_links_input_invalid',
				'ورودی JSON خالی یا بزرگ‌تر از حد مجاز است.',
				array( 'status' => 400 )
			);
		}

		try {
			$shape   = json_decode( $json, false, 64, JSON_THROW_ON_ERROR );
			$decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			return new WP_Error(
				'digitalogic_supplier_links_json_invalid',
				'ساختار JSON لینک‌های تأمین‌کننده معتبر نیست.',
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $decoded ) || ( $expect_list && ! is_array( $shape ) ) || ( ! $expect_list && ! is_object( $shape ) ) ) {
			return new WP_Error(
				'digitalogic_supplier_links_json_shape_invalid',
				$expect_list ? 'برای جایگزینی، یک آرایه JSON ارسال کنید.' : 'برای افزودن، یک شیء JSON ارسال کنید.',
				array( 'status' => 400 )
			);
		}

		return $decoded;
	}

	/**
	 * Require an exact parent product plus administrator capabilities.
	 *
	 * @param array $assoc_args Named CLI arguments.
	 * @return array|WP_Error
	 */
	private function require_product( $assoc_args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'digitalogic_supplier_links_admin_required',
				'این دستور باید با --user=<administrator> اجرا شود.',
				array( 'status' => 403 )
			);
		}

		$has_id  = isset( $assoc_args['id'] ) && '' !== trim( (string) $assoc_args['id'] );
		$has_sku = isset( $assoc_args['sku'] ) && '' !== trim( (string) $assoc_args['sku'] );
		if ( 1 !== (int) $has_id + (int) $has_sku ) {
			return new WP_Error(
				'digitalogic_supplier_links_selector_invalid',
				'دقیقاً یکی از گزینه‌های --id یا --sku را مشخص کنید.',
				array( 'status' => 400 )
			);
		}

		$identifiers = $has_id
			? array( 'woocommerce_id' => (string) $assoc_args['id'] )
			: array( 'sku' => (string) $assoc_args['sku'] );
		$product     = Digitalogic_Product_Identifier_Resolver::instance()->resolve( $identifiers );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		if ( 'product' !== $product['post_type'] ) {
			return new WP_Error(
				'digitalogic_supplier_links_parent_product_required',
				'شناسه باید متعلق به محصول اصلی باشد، نه تنوع محصول.',
				array( 'status' => 400 )
			);
		}
		if ( ! current_user_can( 'edit_post', (int) $product['woocommerce_id'] ) ) {
			return new WP_Error(
				'digitalogic_supplier_links_edit_forbidden',
				'کاربر انتخاب‌شده اجازه ویرایش این محصول را ندارد.',
				array( 'status' => 403 )
			);
		}

		return $product;
	}

	/**
	 * Read JSON from a non-interactive stdin stream.
	 *
	 * @param bool $expect_list Whether the top level must be a list.
	 * @return array|WP_Error
	 */
	private function read_stdin_json( $expect_list ) {
		if ( ! defined( 'STDIN' ) || ! is_resource( STDIN ) ) {
			return $this->stdin_error();
		}
		if ( function_exists( 'stream_isatty' ) && stream_isatty( STDIN ) ) {
			return $this->stdin_error();
		}

		$input = stream_get_contents( STDIN, Digitalogic_Product_Supplier_Links::MAX_INPUT_BYTES + 1 );
		if ( false === $input ) {
			return $this->stdin_error();
		}

		return self::decode_json_input( $input, $expect_list );
	}

	/**
	 * Print a mutation summary with no seller URL or private note.
	 *
	 * @param string|int $product_id Product ID.
	 * @param array      $links Persisted links.
	 * @return void
	 */
	private function output_mutation_summary( $product_id, $links ) {
		WP_CLI::line(
			wp_json_encode(
				array(
					'product_id' => (string) $product_id,
					'count'      => count( $links ),
					'link_ids'   => array_values( array_column( $links, 'id' ) ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Send one redacted WP-CLI error.
	 *
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	private function output_error( $error ) {
		WP_CLI::error( $error->get_error_message() );
	}

	/**
	 * Build the missing-pipe error.
	 *
	 * @return WP_Error
	 */
	private function stdin_error() {
		return new WP_Error(
			'digitalogic_supplier_links_stdin_required',
			'JSON لینک تأمین‌کننده را از ورودی استاندارد (stdin) ارسال کنید.',
			array( 'status' => 400 )
		);
	}
}

WP_CLI::add_command(
	'digitalogic supplier-links list',
	array( 'Digitalogic_Product_Supplier_Links_CLI', 'list_links' )
);
WP_CLI::add_command(
	'digitalogic supplier-links add',
	array( 'Digitalogic_Product_Supplier_Links_CLI', 'add_link' )
);
WP_CLI::add_command(
	'digitalogic supplier-links replace',
	array( 'Digitalogic_Product_Supplier_Links_CLI', 'replace_links' )
);
WP_CLI::add_command(
	'digitalogic supplier-links remove',
	array( 'Digitalogic_Product_Supplier_Links_CLI', 'remove_link' )
);
